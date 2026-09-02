<?php
/**
 * Manual two-image merge: keep one filename, move the other set to duplicate-images/, update posts.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Manual_Merger
 */
class Manual_Merger {

	/**
	 * Resolve a user filename to an attachment row.
	 *
	 * @param string $filename Filename.
	 * @return array|null
	 */
	public function resolve_filename( $filename ) {
		$filename = sanitize_file_name( wp_basename( trim( (string) $filename ) ) );
		if ( '' === $filename ) {
			return null;
		}

		$downsizer = new Image_Downsizer();
		$matches   = $downsizer->find_attachments_by_filename( $filename );
		if ( empty( $matches ) ) {
			return null;
		}

		// Prefer exact basename match.
		foreach ( $matches as $m ) {
			if ( isset( $m['filename'] ) && $m['filename'] === $filename ) {
				return $m;
			}
		}
		return $matches[0];
	}

	/**
	 * Preview what a merge would do (read-only).
	 *
	 * @param string $keep_name   Filename to keep (master).
	 * @param string $remove_name Filename to move (duplicate).
	 * @return array
	 */
	public function preview( $keep_name, $remove_name ) {
		$keep   = $this->resolve_filename( $keep_name );
		$remove = $this->resolve_filename( $remove_name );

		$result = array(
			'ok'           => false,
			'message'      => '',
			'keep'         => $keep,
			'remove'       => $remove,
			'posts'        => array(),
			'posts_count'  => 0,
		);

		if ( ! $keep ) {
			$result['message'] = sprintf( 'Could not find media for keep filename: %s', $keep_name );
			return $result;
		}
		if ( ! $remove ) {
			$result['message'] = sprintf( 'Could not find media for remove filename: %s', $remove_name );
			return $result;
		}
		if ( (int) $keep['attachment_id'] === (int) $remove['attachment_id'] ) {
			$result['message'] = 'Both names resolved to the same attachment. Nothing to merge.';
			return $result;
		}

		$posts = $this->find_posts_using_attachment( (int) $remove['attachment_id'], $remove );
		$result['posts']       = $posts;
		$result['posts_count'] = count( $posts );
		$result['ok']          = true;
		$result['message']     = sprintf(
			'Ready: keep #%d (%s), move #%d (%s). %d post(s) reference the duplicate.',
			(int) $keep['attachment_id'],
			$keep['filename'],
			(int) $remove['attachment_id'],
			$remove['filename'],
			count( $posts )
		);
		return $result;
	}

	/**
	 * Execute merge: replace refs in posts, then move duplicate set.
	 *
	 * @param string $keep_name   Filename to keep.
	 * @param string $remove_name Filename to move.
	 * @return array
	 */
	public function merge( $keep_name, $remove_name ) {
		$preview = $this->preview( $keep_name, $remove_name );
		if ( empty( $preview['ok'] ) || empty( $preview['keep'] ) || empty( $preview['remove'] ) ) {
			return array(
				'success' => false,
				'message' => $preview['message'] ?? 'Preview failed.',
				'stats'   => array(),
			);
		}

		$keep_id   = (int) $preview['keep']['attachment_id'];
		$remove_id = (int) $preview['remove']['attachment_id'];

		$replacer = new Post_Replacer();
		$files    = new File_Manager();

		// Ensure master has intermediate sizes for sensible display URLs.
		$this->ensure_sizes( $keep_id );

		$stats = array(
			'posts_updated'    => 0,
			'featured_updated' => 0,
			'errors'           => 0,
			'moved'            => false,
			'move_message'     => '',
		);

		$all_clean = true;
		$posts     = $preview['posts'];

		// Also catch any posts that use remove as featured but weren't in content scan.
		$extra = $this->find_posts_with_featured( $remove_id );
		foreach ( $extra as $pid ) {
			$found = false;
			foreach ( $posts as $p ) {
				if ( (int) $p['post_id'] === (int) $pid ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$post = get_post( $pid );
				$posts[] = array(
					'post_id'    => (int) $pid,
					'post_title' => $post ? $post->post_title : '',
					'roles'      => array( 'featured' ),
				);
			}
		}

		foreach ( $posts as $p ) {
			$pid  = (int) $p['post_id'];
			$post = get_post( $pid );
			if ( ! $post || 'post' !== $post->post_type ) {
				continue;
			}

			$result = $replacer->replace_in_post( $pid, $remove_id, $keep_id );

			Logger::log(
				array(
					'post_id'        => $pid,
					'post_title'     => $post->post_title,
					'old_attachment' => $remove_id,
					'old_filename'   => $preview['remove']['filename'],
					'old_url'        => $preview['remove']['url'] ?? '',
					'new_attachment' => $keep_id,
					'new_filename'   => $preview['keep']['filename'],
					'new_url'        => $preview['keep']['url'] ?? '',
					'action'         => 'manual-merge-replace',
					'result'         => ! empty( $result['success'] ) ? 'ok' : 'failed',
					'error'          => empty( $result['success'] ) ? ( $result['message'] ?? '' ) : '',
				)
			);

			if ( empty( $result['success'] ) ) {
				$all_clean = false;
				++$stats['errors'];
				continue;
			}

			if ( ! empty( $result['changes'] ) ) {
				++$stats['posts_updated'];
			}
			if ( ! empty( $result['featured'] ) ) {
				++$stats['featured_updated'];
			}

			if ( ! $replacer->verify_post_clean( $pid, $remove_id ) ) {
				$all_clean = false;
				++$stats['errors'];
				Logger::log(
					array(
						'post_id'        => $pid,
						'post_title'     => $post->post_title,
						'old_attachment' => $remove_id,
						'new_attachment' => $keep_id,
						'action'         => 'manual-merge-verify',
						'result'         => 'failed',
						'error'          => 'Post still references duplicate after replace.',
					)
				);
			}
		}

		// Safety: any remaining featured usage?
		$still = $this->find_posts_with_featured( $remove_id );
		if ( ! empty( $still ) ) {
			$all_clean = false;
			Logger::log(
				array(
					'old_attachment' => $remove_id,
					'action'         => 'manual-merge-move',
					'result'         => 'skipped',
					'error'          => 'Still used as featured on post(s): ' . implode( ',', $still ),
				)
			);
		}

		if ( ! $all_clean ) {
			return array(
				'success' => false,
				'message' => 'Some posts could not be updated/verified. Duplicate files were NOT moved. Check the log.',
				'stats'   => $stats,
			);
		}

		$move = $files->move_duplicate_set( $remove_id );
		$stats['moved']        = ! empty( $move['success'] );
		$stats['move_message'] = $move['message'] ?? '';

		Logger::log(
			array(
				'old_attachment' => $remove_id,
				'old_filename'   => $preview['remove']['filename'],
				'new_attachment' => $keep_id,
				'new_filename'   => $preview['keep']['filename'],
				'action'         => 'manual-merge-move',
				'result'         => ! empty( $move['success'] ) ? 'ok' : 'failed',
				'error'          => empty( $move['success'] ) ? ( $move['message'] ?? '' ) : '',
			)
		);

		if ( empty( $move['success'] ) ) {
			return array(
				'success' => false,
				'message' => 'Posts updated, but file move failed: ' . ( $move['message'] ?? 'unknown' ),
				'stats'   => $stats,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				'Merged. Kept %s (#%d). Moved %s (#%d) to duplicate-images/. Posts updated: %d.',
				$preview['keep']['filename'],
				$keep_id,
				$preview['remove']['filename'],
				$remove_id,
				$stats['posts_updated']
			),
			'stats'   => $stats,
		);
	}

	/**
	 * Find posts (type=post) that reference an attachment in content or featured.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $att_row       Attachment info with url/filename.
	 * @return array
	 */
	private function find_posts_using_attachment( $attachment_id, array $att_row ) {
		$attachment_id = (int) $attachment_id;
		$bits          = array_filter(
			array(
				$att_row['url'] ?? '',
				$att_row['filename'] ?? '',
				'wp-image-' . $attachment_id,
			)
		);

		$found = array();
		$query_args = array(
			'post_type'              => 'post',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => 100,
			'paged'                  => 1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		$query       = new \WP_Query( $query_args );
		$total_pages = (int) $query->max_num_pages;

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			if ( $page > 1 ) {
				$query_args['paged'] = $page;
				$query               = new \WP_Query( $query_args );
			}
			foreach ( $query->posts as $post ) {
				if ( 'post' !== $post->post_type ) {
					continue;
				}
				$roles = array();
				if ( (int) get_post_thumbnail_id( $post->ID ) === $attachment_id ) {
					$roles[] = 'featured';
				}
				$content = $post->post_content;
				if ( $content ) {
					foreach ( $bits as $bit ) {
						if ( $bit && false !== strpos( $content, $bit ) ) {
							$roles[] = 'content';
							break;
						}
					}
				}
				if ( ! empty( $roles ) ) {
					$found[] = array(
						'post_id'    => (int) $post->ID,
						'post_title' => $post->post_title,
						'roles'      => array_values( array_unique( $roles ) ),
						'thumb_url'  => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: '',
						'edit_link'  => get_edit_post_link( $post->ID, 'raw' ),
					);
				}
			}
			wp_reset_postdata();
		}

		return $found;
	}

	/**
	 * Posts with this attachment as featured image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Post IDs.
	 */
	private function find_posts_with_featured( $attachment_id ) {
		$query = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'any',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'meta_key'               => '_thumbnail_id',
				'meta_value'             => (int) $attachment_id,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Ensure intermediate sizes exist.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function ensure_sizes( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes']['large'] ) || ! empty( $meta['sizes']['medium_large'] ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( ! empty( $new_meta ) && is_array( $new_meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}
	}
}