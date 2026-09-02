<?php
/**
 * Move duplicate image sets into uploads/duplicate-images/ (never delete data).
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class File_Manager
 */
class File_Manager {

	/**
	 * Absolute path to the duplicate-images base directory.
	 *
	 * @var string
	 */
	private $dup_base_dir;

	/**
	 * WordPress uploads basedir.
	 *
	 * @var string
	 */
	private $upload_basedir;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$uploads              = wp_upload_dir();
		$this->upload_basedir = trailingslashit( $uploads['basedir'] );
		$this->dup_base_dir   = $this->upload_basedir . RD3_PIC_DUPLICATE_DIR . '/';
	}

	/**
	 * Ensure the duplicate-images directory exists.
	 *
	 * @return bool
	 */
	public function ensure_duplicate_dir() {
		if ( is_dir( $this->dup_base_dir ) ) {
			return true;
		}
		return wp_mkdir_p( $this->dup_base_dir );
	}

	/**
	 * Move the complete image set for a duplicate attachment into
	 * uploads/duplicate-images/{year}/{month}/... preserving structure.
	 *
	 * Does NOT delete the image data. Does NOT move the master.
	 * Updates attachment metadata so the record remains recoverable.
	 *
	 * @param int $attachment_id Duplicate attachment ID.
	 * @return array{success:bool,message:string,moved:array,failed:array}
	 */
	public function move_duplicate_set( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$moved         = array();
		$failed        = array();

		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'Invalid attachment ID.',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => 'Not an attachment.',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		if ( ! $this->ensure_duplicate_dir() ) {
			return array(
				'success' => false,
				'message' => 'Could not create duplicate-images directory.',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		$main_file = get_attached_file( $attachment_id, true );
		if ( ! $main_file || ! file_exists( $main_file ) ) {
			return array(
				'success' => true,
				'message' => 'Main file already absent (possibly moved previously).',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		$norm_main = wp_normalize_path( $main_file );
		$norm_base = wp_normalize_path( $this->upload_basedir );
		$norm_dup  = wp_normalize_path( $this->dup_base_dir );

		if ( 0 !== strpos( $norm_main, $norm_base ) ) {
			return array(
				'success' => false,
				'message' => 'File is outside the uploads directory.',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		if ( false !== strpos( $norm_main, $norm_dup ) ) {
			return array(
				'success' => true,
				'message' => 'Already under duplicate-images.',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		$files_to_move = $this->collect_image_set( $attachment_id, $main_file );
		if ( empty( $files_to_move ) ) {
			return array(
				'success' => false,
				'message' => 'No files found to move.',
				'moved'   => $moved,
				'failed'  => $failed,
			);
		}

		foreach ( $files_to_move as $src ) {
			$rel  = ltrim( str_replace( $norm_base, '', wp_normalize_path( $src ) ), '/' );
			$dest = $this->dup_base_dir . $rel;

			$dest_dir = dirname( $dest );
			if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
				$failed[] = array(
					'src'   => $src,
					'dest'  => $dest,
					'error' => 'Could not create destination directory.',
				);
				continue;
			}

			if ( file_exists( $dest ) ) {
				$failed[] = array(
					'src'   => $src,
					'dest'  => $dest,
					'error' => 'Destination already exists; left source in place.',
				);
				continue;
			}

			$ok = @rename( $src, $dest );
			if ( ! $ok ) {
				if ( @copy( $src, $dest ) ) {
					if ( filesize( $src ) === filesize( $dest ) ) {
						@unlink( $src );
						$ok = true;
					} else {
						@unlink( $dest );
						$ok = false;
					}
				}
			}

			if ( $ok ) {
				$moved[] = array(
					'src'  => $src,
					'dest' => $dest,
				);
			} else {
				$failed[] = array(
					'src'   => $src,
					'dest'  => $dest,
					'error' => 'Move/copy failed.',
				);
			}
		}

		if ( ! empty( $moved ) ) {
			$this->update_attachment_paths( $attachment_id, $main_file, $moved );
		}

		$success = empty( $failed );
		$message = $success
			? sprintf( 'Moved %d file(s).', count( $moved ) )
			: sprintf( 'Moved %d file(s), %d failed.', count( $moved ), count( $failed ) );

		return array(
			'success' => $success,
			'message' => $message,
			'moved'   => $moved,
			'failed'  => $failed,
		);
	}

	/**
	 * Collect main file + all intermediate sizes + scaled/original variants.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $main_file     Absolute path of main file.
	 * @return array List of absolute paths that exist.
	 */
	private function collect_image_set( $attachment_id, $main_file ) {
		$files = array();
		$dir   = trailingslashit( dirname( $main_file ) );
		$base  = basename( $main_file );

		$candidates = array( $main_file );

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$candidates[] = $dir . $size['file'];
				}
			}
		}

		if ( ! empty( $meta['original_image'] ) ) {
			$candidates[] = $dir . $meta['original_image'];
		}

		$scaled = preg_replace( '/(\.[a-zA-Z0-9]+)$/', '-scaled$1', $main_file );
		if ( $scaled ) {
			$candidates[] = $scaled;
		}

		$stem = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $base );
		$stem = preg_replace( '/-\d+x\d+$/', '', $stem );
		$stem = preg_replace( '/-scaled$/', '', $stem );
		if ( $stem && is_dir( $dir ) ) {
			$pattern = $dir . $stem . '*.{jpg,jpeg,png,gif,webp,avif,JPG,JPEG,PNG,GIF,WEBP,AVIF}';
			$globbed = glob( $pattern, GLOB_BRACE );
			if ( is_array( $globbed ) ) {
				foreach ( $globbed as $g ) {
					$candidates[] = $g;
				}
			}
		}

		foreach ( array_unique( $candidates ) as $path ) {
			$path = wp_normalize_path( $path );
			if ( $path && is_file( $path ) && is_readable( $path ) ) {
				$files[] = $path;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Update _wp_attached_file and metadata paths after a move.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $old_main      Previous main file path.
	 * @param array  $moved         Successfully moved src/dest pairs.
	 */
	private function update_attachment_paths( $attachment_id, $old_main, array $moved ) {
		$src_to_dest = array();
		foreach ( $moved as $pair ) {
			$src_to_dest[ wp_normalize_path( $pair['src'] ) ] = $pair['dest'];
		}

		$new_main = isset( $src_to_dest[ wp_normalize_path( $old_main ) ] )
			? $src_to_dest[ wp_normalize_path( $old_main ) ]
			: $old_main;

		$rel = ltrim( str_replace( wp_normalize_path( $this->upload_basedir ), '', wp_normalize_path( $new_main ) ), '/' );

		update_attached_file( $attachment_id, $new_main );

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['file'] = $rel;
		wp_update_attachment_metadata( $attachment_id, $meta );
		clean_post_cache( $attachment_id );
	}

	/**
	 * Get the public base path for reporting.
	 *
	 * @return string
	 */
	public function get_duplicate_dir() {
		return $this->dup_base_dir;
	}
}