<?php
/**
 * Orchestrates Stage 2 cleanup: replace → verify → move.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cleanup_Runner
 */
class Cleanup_Runner {

	/**
	 * Run cleanup against stored scan results.
	 *
	 * @return array Summary of the run.
	 */
	public function run() {
		$results = get_transient( 'rd3_pic_scan_results' );
		if ( empty( $results ) || empty( $results['groups'] ) ) {
			return array(
				'success' => false,
				'message' => 'No scan results available. Run a scan first.',
				'stats'   => array(),
			);
		}

		$replacer = new Post_Replacer();
		$files    = new File_Manager();

		$stats = array(
			'groups_processed'   => 0,
			'posts_updated'      => 0,
			'featured_updated'   => 0,
			'duplicates_moved'   => 0,
			'duplicates_skipped' => 0,
			'errors'             => 0,
		);

		foreach ( $results['groups'] as $hash => $group ) {
			$master     = $group['master'] ?? array();
			$duplicates = $group['duplicates'] ?? array();
			$posts      = $group['posts'] ?? array();

			$master_id = (int) ( $master['attachment_id'] ?? 0 );
			if ( $master_id <= 0 || empty( $duplicates ) ) {
				continue;
			}

			// Ensure the master has intermediate sizes so replacements can use them
			// instead of the multi-megapixel original (common with Facebook imports).
			$this->ensure_attachment_sizes( $master_id );

			++$stats['groups_processed'];

			foreach ( $duplicates as $dup ) {
				$dup_id = (int) ( $dup['attachment_id'] ?? 0 );
				if ( $dup_id <= 0 || $dup_id === $master_id ) {
					continue;
				}

				// Collect unique posts that use this duplicate.
				$posts_for_dup = array();
				foreach ( $posts as $p ) {
					if ( (int) ( $p['attachment_id'] ?? 0 ) === $dup_id ) {
						$posts_for_dup[ (int) $p['post_id'] ] = $p;
					}
				}

				// Also check all posts that might use it via featured (scan may list them).
				// Safety: only operate on post type "post".
				$all_clean = true;
				$post_results = array();

				foreach ( $posts_for_dup as $pid => $pinfo ) {
					$post = get_post( $pid );
					if ( ! $post || 'post' !== $post->post_type ) {
						continue;
					}

					$old_url = $pinfo['url'] ?? wp_get_attachment_url( $dup_id );
					$result  = $replacer->replace_in_post( $pid, $dup_id, $master_id );

					$log_entry = array(
						'post_id'        => $pid,
						'post_title'     => $post->post_title,
						'old_attachment' => $dup_id,
						'old_filename'   => $dup['filename'] ?? '',
						'old_url'        => $old_url,
						'new_attachment' => $master_id,
						'new_filename'   => $master['filename'] ?? '',
						'new_url'        => $master['url'] ?? wp_get_attachment_url( $master_id ),
						'sha256'         => $hash,
						'action'         => 'replace',
						'result'         => $result['success'] ? 'ok' : 'failed',
						'error'          => $result['success'] ? '' : ( $result['message'] ?? '' ),
					);
					Logger::log( $log_entry );

					if ( $result['success'] ) {
						if ( $result['changes'] > 0 ) {
							++$stats['posts_updated'];
						}
						if ( ! empty( $result['featured'] ) ) {
							++$stats['featured_updated'];
						}
					} else {
						$all_clean = false;
						++$stats['errors'];
					}

					// Verify after replace.
					$clean = $replacer->verify_post_clean( $pid, $dup_id );
					if ( ! $clean ) {
						$all_clean = false;
						Logger::log(
							array(
								'post_id'        => $pid,
								'post_title'     => $post->post_title,
								'old_attachment' => $dup_id,
								'new_attachment' => $master_id,
								'sha256'         => $hash,
								'action'         => 'verify',
								'result'         => 'failed',
								'error'          => 'Post still references duplicate after replace.',
							)
						);
						++$stats['errors'];
					}

					$post_results[ $pid ] = array(
						'replace' => $result,
						'clean'   => $clean,
					);
				}

				// Only move if every affected post for this duplicate is clean
				// (or there were no posts — still allow move of orphaned dup).
				if ( ! $all_clean ) {
					Logger::log(
						array(
							'old_attachment' => $dup_id,
							'old_filename'   => $dup['filename'] ?? '',
							'sha256'         => $hash,
							'action'         => 'move',
							'result'         => 'skipped',
							'error'          => 'One or more posts still reference this duplicate; file not moved.',
						)
					);
					++$stats['duplicates_skipped'];
					continue;
				}

				// Extra global safety: any other post still using this attachment as featured?
				$still_used = $this->attachment_still_used_by_posts( $dup_id );
				if ( $still_used ) {
					Logger::log(
						array(
							'old_attachment' => $dup_id,
							'old_filename'   => $dup['filename'] ?? '',
							'sha256'         => $hash,
							'action'         => 'move',
							'result'         => 'skipped',
							'error'          => 'Attachment still used by post(s): ' . implode( ',', $still_used ),
						)
					);
					++$stats['duplicates_skipped'];
					++$stats['errors'];
					continue;
				}

				$move = $files->move_duplicate_set( $dup_id );
				Logger::log(
					array(
						'old_attachment' => $dup_id,
						'old_filename'   => $dup['filename'] ?? '',
						'sha256'         => $hash,
						'action'         => 'move',
						'result'         => $move['success'] ? 'ok' : 'failed',
						'error'          => $move['success'] ? '' : ( $move['message'] ?? '' ),
					)
				);

				if ( $move['success'] ) {
					++$stats['duplicates_moved'];
				} else {
					++$stats['duplicates_skipped'];
					++$stats['errors'];
				}
			}
		}

		// Invalidate scan results so a fresh scan is needed.
		delete_transient( 'rd3_pic_scan_results' );

		return array(
			'success' => true,
			'message' => 'Cleanup finished.',
			'stats'   => $stats,
		);
	}


	/**
	 * Generate missing intermediate sizes for an attachment (best-effort).
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function ensure_attachment_sizes( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$has_sizes = ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && count( $meta['sizes'] ) > 0;

		// If large/medium_large already present, skip regeneration.
		if ( $has_sizes && ( ! empty( $meta['sizes']['large'] ) || ! empty( $meta['sizes']['medium_large'] ) ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( ! empty( $new_meta ) && is_array( $new_meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}
	}

	/**
	 * Check whether any post (type=post) still uses this attachment as featured image.
	 * Content references should already have been verified per-post.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array List of post IDs still using it as featured.
	 */
	private function attachment_still_used_by_posts( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$query = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'any',
				'posts_per_page'         => 50,
				'fields'                 => 'ids',
				'meta_key'               => '_thumbnail_id',
				'meta_value'             => $attachment_id,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return is_array( $query->posts ) ? $query->posts : array();
	}
}