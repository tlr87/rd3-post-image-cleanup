<?php
/**
 * Detect exact duplicates among original image files via SHA-256.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Duplicate_Detector
 */
class Duplicate_Detector {

	/**
	 * Process raw scanner output into grouped duplicates + report data.
	 *
	 * @param array $raw Scanner output.
	 * @return array Structured results for the admin report.
	 */
	public function process( array $raw ) {
		$attachments_raw = $raw['attachments'] ?? array();
		$post_usages     = $raw['post_usages'] ?? array();
		$posts_scanned   = (int) ( $raw['posts_scanned'] ?? 0 );

		// Resolve each attachment to its ORIGINAL file path and metadata.
		$originals = array(); // attachment_id => original info

		foreach ( $attachments_raw as $aid => $info ) {
			$aid  = (int) $aid;
			$meta = $this->get_original_file_info( $aid );
			if ( ! $meta ) {
				continue;
			}
			$meta['posts'] = array_keys( $info['posts'] ?? array() );
			$meta['roles'] = $info['roles'] ?? array();
			$originals[ $aid ] = $meta;
		}

		// Hash original files one-by-one (never load all into memory).
		$hash_map = array(); // hash => list of attachment_ids

		foreach ( $originals as $aid => $meta ) {
			$path = $meta['path'];
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}
			$hash = $this->hash_file( $path );
			if ( ! $hash ) {
				continue;
			}
			$originals[ $aid ]['hash'] = $hash;
			if ( ! isset( $hash_map[ $hash ] ) ) {
				$hash_map[ $hash ] = array();
			}
			$hash_map[ $hash ][] = $aid;
		}

		// Build duplicate groups (only hashes with >1 original).
		$groups          = array();
		$duplicate_files = 0;
		$posts_affected  = array();

		foreach ( $hash_map as $hash => $aids ) {
			if ( count( $aids ) < 2 ) {
				continue;
			}

			// Sort aids by master preference: oldest upload date, then lowest ID.
			usort(
				$aids,
				function ( $a, $b ) use ( $originals ) {
					$da = $originals[ $a ]['upload_timestamp'] ?? 0;
					$db = $originals[ $b ]['upload_timestamp'] ?? 0;
					if ( $da !== $db ) {
						return $da <=> $db;
					}
					return $a <=> $b;
				}
			);

			$master_id  = $aids[0];
			$dup_ids    = array_slice( $aids, 1 );
			$master     = $this->format_attachment_row( $originals[ $master_id ] );
			$duplicates = array();

			foreach ( $dup_ids as $did ) {
				$duplicates[] = $this->format_attachment_row( $originals[ $did ] );
				++$duplicate_files;
			}

			// Collect posts that use any of these attachments.
			$group_posts = array();
			$seen_post   = array();

			foreach ( $aids as $aid ) {
				foreach ( $originals[ $aid ]['posts'] as $pid ) {
					$posts_affected[ $pid ] = true;
				}
			}

			// Detailed post rows from post_usages, filtered to this group.
			$aid_set = array_flip( $aids );
			foreach ( $post_usages as $usage ) {
				$uaid = (int) ( $usage['attachment_id'] ?? 0 );
				if ( ! isset( $aid_set[ $uaid ] ) ) {
					continue;
				}
				$key = $usage['post_id'] . '-' . $uaid . '-' . ( $usage['role'] ?? '' );
				if ( isset( $seen_post[ $key ] ) ) {
					continue;
				}
				$seen_post[ $key ] = true;

				$is_master = ( $uaid === $master_id );
				$group_posts[] = array(
					'post_id'       => (int) $usage['post_id'],
					'post_title'    => $usage['post_title'] ?? '',
					'role'          => ( $is_master ? 'master / ' : 'duplicate / ' ) . ( $usage['role'] ?? '' ),
					'attachment_id' => $uaid,
					'url'           => $usage['url'] ?? '',
				);
			}

			$groups[ $hash ] = array(
				'master'     => $master,
				'duplicates' => $duplicates,
				'posts'      => $group_posts,
			);
		}

		$summary = array(
			'posts_scanned'    => $posts_scanned,
			'images_found'     => count( $originals ),
			'unique_images'    => count( $hash_map ),
			'duplicate_groups' => count( $groups ),
			'duplicate_files'  => $duplicate_files,
			'posts_affected'   => count( $posts_affected ),
		);

		return array(
			'summary'    => $summary,
			'groups'     => $groups,
			'scanned_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Get original (full-size) file path and metadata for an attachment.
	 * Ignores intermediate sizes; always resolves to the main file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|false
	 */
	private function get_original_file_info( $attachment_id ) {
		$file = get_attached_file( $attachment_id, true ); // true = unfiltered
		if ( ! $file ) {
			return false;
		}

		// If the stored file is an intermediate, try to recover original via metadata.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['file'] ) ) {
			$uploads = wp_upload_dir();
			$basedir = trailingslashit( $uploads['basedir'] );
			$candidate = $basedir . $meta['file'];
			if ( is_readable( $candidate ) ) {
				$file = $candidate;
			}
		}

		// Prefer original_image if present (WP 5.3+).
		if ( ! empty( $meta['original_image'] ) ) {
			$dir = trailingslashit( dirname( $file ) );
			$orig = $dir . $meta['original_image'];
			if ( is_readable( $orig ) ) {
				$file = $orig;
			}
		}

		if ( ! is_readable( $file ) ) {
			return false;
		}

		$filename = basename( $file );
		$filesize = filesize( $file );
		$width    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height   = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		// If dimensions missing, try getimagesize (lightweight).
		if ( ( ! $width || ! $height ) && function_exists( 'getimagesize' ) ) {
			$size = @getimagesize( $file );
			if ( $size ) {
				$width  = (int) $size[0];
				$height = (int) $size[1];
			}
		}

		$post = get_post( $attachment_id );
		$upload_date = $post ? $post->post_date : '';
		$upload_ts   = $post ? strtotime( $post->post_date_gmt ? $post->post_date_gmt . ' UTC' : $post->post_date ) : 0;

		return array(
			'attachment_id'     => $attachment_id,
			'path'              => $file,
			'filename'          => $filename,
			'filesize'          => $filesize ? (int) $filesize : 0,
			'width'             => $width,
			'height'            => $height,
			'upload_date'       => $upload_date,
			'upload_timestamp'  => $upload_ts,
			'url'               => wp_get_attachment_url( $attachment_id ),
			'posts'             => array(),
			'roles'             => array(),
		);
	}

	/**
	 * SHA-256 hash of a file (streamed).
	 *
	 * @param string $path Absolute path.
	 * @return string|false Hex hash or false.
	 */
	private function hash_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		// hash_file streams; does not load entire file into memory.
		return hash_file( 'sha256', $path );
	}

	/**
	 * Format a row for the report.
	 *
	 * @param array $info Original info.
	 * @return array
	 */
	private function format_attachment_row( array $info ) {
		return array(
			'attachment_id' => $info['attachment_id'],
			'filename'      => $info['filename'],
			'path'          => $info['path'],
			'filesize'      => $info['filesize'],
			'width'         => $info['width'],
			'height'        => $info['height'],
			'upload_date'   => $info['upload_date'],
			'url'           => $info['url'] ?? '',
			'post_count'    => count( $info['posts'] ?? array() ),
			'hash'          => $info['hash'] ?? '',
		);
	}
}