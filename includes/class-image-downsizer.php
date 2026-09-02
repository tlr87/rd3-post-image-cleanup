<?php
/**
 * Find large/full-size images in posts and rewrite display src to a mid-size
 * while keeping the click-through link on the full original.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Image_Downsizer
 */
class Image_Downsizer {

	/**
	 * Preferred display width for in-post images.
	 *
	 * @var int
	 */
	const DISPLAY_WIDTH = 768;

	/**
	 * Treat an embedded image as "large" if its file width is at or above this.
	 *
	 * @var int
	 */
	const LARGE_THRESHOLD = 1200;

	/**
	 * Scan posts for large full-size image embeds (read-only report).
	 *
	 * @return array
	 */
	public function scan_large() {
		$items         = array();
		$posts_scanned = 0;

		$query_args = array(
			'post_type'              => 'post',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => 100,
			'paged'                  => 1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
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
				++$posts_scanned;

				$found = $this->find_large_in_content( $post );
				foreach ( $found as $row ) {
					$items[] = $row;
				}
			}
			wp_reset_postdata();
		}

		return array(
			'posts_scanned' => $posts_scanned,
			'large_count'   => count( $items ),
			'items'         => $items,
			'scanned_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Find large full-size image references in one post's content.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private function find_large_in_content( $post ) {
		$content = $post->post_content;
		if ( ! $content ) {
			return array();
		}

		$found = array();
		$seen  = array();

		// Collect candidate URLs from img src.
		$urls = array();
		if ( preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$urls[] = $url;
			}
		}

		// Block urls.
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, '<!-- wp:' ) ) {
			$this->collect_block_urls( parse_blocks( $content ), $urls );
		}

		foreach ( array_unique( $urls ) as $url ) {
			$info = $this->classify_url( $url );
			if ( ! $info || ! $info['is_large'] ) {
				continue;
			}
			$key = $post->ID . '|' . $info['attachment_id'] . '|' . $info['url'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$thumb = wp_get_attachment_image_url( $info['attachment_id'], 'medium' );
			if ( ! $thumb ) {
				$thumb = $info['url'];
			}

			$found[] = array(
				'post_id'         => (int) $post->ID,
				'post_title'      => $post->post_title,
				'attachment_id'   => $info['attachment_id'],
				'filename'        => $info['filename'],
				'url'             => $info['url'],
				'width'           => $info['width'],
				'height'          => $info['height'],
				'filesize'        => $info['filesize'],
				'is_full_size'    => $info['is_full_size'],
				'thumb_url'       => $thumb,
				'proposed_src'    => $info['proposed_src'],
				'proposed_href'   => $info['proposed_href'],
			);
		}

		return $found;
	}

	/**
	 * Collect image URLs from blocks.
	 *
	 * @param array $blocks Blocks.
	 * @param array $urls   Accumulator.
	 */
	private function collect_block_urls( $blocks, &$urls ) {
		foreach ( $blocks as $block ) {
			$attrs = $block['attrs'] ?? array();
			$name  = $block['blockName'] ?? '';
			if ( in_array( $name, array( 'core/image', 'core/cover', 'core/media-text' ), true ) ) {
				if ( ! empty( $attrs['url'] ) ) {
					$urls[] = $attrs['url'];
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_block_urls( $block['innerBlocks'], $urls );
			}
		}
	}

	/**
	 * Classify a URL: attachment, dimensions, whether large/full.
	 *
	 * @param string $url Image URL.
	 * @return array|null
	 */
	private function classify_url( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return null;
		}

		// Skip if already a sized intermediate (-WxH).
		$already_sized = (bool) preg_match( '/-\d+x\d+(?=\.[a-zA-Z0-9]+(?:\?|$))/', $url );

		$attachment_id = 0;
		if ( function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = (int) attachment_url_to_postid( $url );
			if ( ! $attachment_id ) {
				$stripped = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+(?:\?|$))/', '', $url );
				$stripped = preg_replace( '/-scaled(?=\.[a-zA-Z0-9]+(?:\?|$))/', '', $stripped );
				$attachment_id = (int) attachment_url_to_postid( $stripped );
			}
		}
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$file = get_attached_file( $attachment_id );
		$fs   = ( $file && is_readable( $file ) ) ? (int) filesize( $file ) : 0;

		$is_full_size = ! $already_sized;
		$is_large     = $is_full_size && ( $w >= self::LARGE_THRESHOLD || $fs >= 400000 );

		// Proposed display size ~768.
		$proposed_src  = $this->pick_display_url( $attachment_id, $meta );
		$proposed_href = (string) wp_get_attachment_url( $attachment_id );

		return array(
			'attachment_id' => $attachment_id,
			'filename'      => $file ? basename( $file ) : basename( $url ),
			'url'           => $url,
			'width'         => $w,
			'height'        => $h,
			'filesize'      => $fs,
			'is_full_size'  => $is_full_size,
			'is_large'      => $is_large,
			'proposed_src'  => $proposed_src,
			'proposed_href' => $proposed_href,
		);
	}

	/**
	 * Pick a ~768px display URL for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $meta          Metadata.
	 * @return string
	 */
	private function pick_display_url( $attachment_id, $meta ) {
		$target = self::DISPLAY_WIDTH;
		$best   = null;
		$best_d = PHP_INT_MAX;

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $data ) {
				$sw = isset( $data['width'] ) ? (int) $data['width'] : 0;
				if ( $sw <= 0 ) {
					continue;
				}
				$d = abs( $sw - $target );
				if ( $sw < $target ) {
					$d += ( $target - $sw );
				}
				if ( $d < $best_d ) {
					$best_d = $d;
					$best   = $name;
				}
			}
		}

		// Prefer named sizes if close.
		foreach ( array( 'medium_large', 'large', 'medium' ) as $name ) {
			$url = wp_get_attachment_image_url( $attachment_id, $name );
			if ( $url ) {
				if ( $best && $name === $best ) {
					return $url;
				}
			}
		}

		if ( $best ) {
			$url = wp_get_attachment_image_url( $attachment_id, $best );
			if ( $url ) {
				return $url;
			}
		}

		foreach ( array( 'medium_large', 'large', 'medium' ) as $name ) {
			$url = wp_get_attachment_image_url( $attachment_id, $name );
			if ( $url ) {
				return $url;
			}
		}

		return (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Ensure intermediate sizes exist, then rewrite large full-size embeds in posts.
	 *
	 * @return array Stats.
	 */
	public function run_downsize() {
		$report = get_transient( 'rd3_pic_large_scan' );
		if ( empty( $report ) || empty( $report['items'] ) ) {
			// Fresh scan.
			$report = $this->scan_large();
			set_transient( 'rd3_pic_large_scan', $report, DAY_IN_SECONDS );
		}

		$stats = array(
			'posts_touched'  => 0,
			'images_changed' => 0,
			'errors'         => 0,
		);

		// Group by post.
		$by_post = array();
		foreach ( $report['items'] as $item ) {
			$by_post[ $item['post_id'] ][] = $item;
		}

		$replacer_helper = new Post_Replacer();

		foreach ( $by_post as $post_id => $items ) {
			$post = get_post( $post_id );
			if ( ! $post || 'post' !== $post->post_type ) {
				continue;
			}

			// Ensure sizes for each attachment.
			$aids = array();
			foreach ( $items as $item ) {
				$aids[ (int) $item['attachment_id'] ] = true;
			}
			foreach ( array_keys( $aids ) as $aid ) {
				$this->ensure_sizes( $aid );
			}

			$content     = $post->post_content;
			$new_content = $content;
			$changed     = 0;

			foreach ( $items as $item ) {
				$aid  = (int) $item['attachment_id'];
				$meta = wp_get_attachment_metadata( $aid );
				$src  = $this->pick_display_url( $aid, is_array( $meta ) ? $meta : array() );
				$href = (string) wp_get_attachment_url( $aid );
				$old  = $item['url'];

				if ( ! $src || ! $old || $src === $old ) {
					// Still try: old might be full and src resolved same if no sizes — skip.
					if ( $src === $old ) {
						continue;
					}
				}

				// Replace img src occurrences of this exact URL.
				$count = 0;
				$new_content = preg_replace(
					'/(<img\b[^>]*\bsrc\s*=\s*["\'])' . preg_quote( $old, '/' ) . '(["\'])/i',
					'${1}' . $src . '${2}',
					$new_content,
					-1,
					$count
				);
				$changed += $count;

				// If img is wrapped in <a href="same full url"> keep/set href to full.
				$new_content = preg_replace(
					'/(<a\b[^>]*\bhref\s*=\s*["\'])' . preg_quote( $old, '/' ) . '(["\'])/i',
					'${1}' . $href . '${2}',
					$new_content
				);

				// Gutenberg block attrs: "url":"old"
				$new_content = str_replace(
					'"url":"' . $old . '"',
					'"url":"' . $src . '"',
					$new_content
				);
				// link href in block JSON if present.
				$new_content = str_replace(
					'"href":"' . $old . '"',
					'"href":"' . $href . '"',
					$new_content
				);

				Logger::log(
					array(
						'post_id'        => $post_id,
						'post_title'     => $post->post_title,
						'old_attachment' => $aid,
						'old_filename'   => $item['filename'],
						'old_url'        => $old,
						'new_attachment' => $aid,
						'new_filename'   => basename( $src ),
						'new_url'        => $src,
						'action'         => 'downsize',
						'result'         => $count > 0 ? 'ok' : 'noop',
						'error'          => '',
					)
				);
			}

			if ( $new_content !== $content ) {
				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					++$stats['errors'];
					Logger::log(
						array(
							'post_id'    => $post_id,
							'post_title' => $post->post_title,
							'action'     => 'downsize',
							'result'     => 'failed',
							'error'      => $result->get_error_message(),
						)
					);
				} else {
					++$stats['posts_touched'];
					$stats['images_changed'] += $changed;
				}
			}
		}

		delete_transient( 'rd3_pic_large_scan' );

		return $stats;
	}

	/**
	 * Generate intermediate sizes if missing.
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

	/**
	 * Find attachment(s) by filename (basename, with or without size suffix).
	 *
	 * @param string $filename User-supplied name, e.g. 887197071069906.jpg
	 * @return array List of attachment rows.
	 */
	public function find_attachments_by_filename( $filename ) {
		$filename = sanitize_file_name( wp_basename( trim( $filename ) ) );
		if ( '' === $filename ) {
			return array();
		}

		// Strip size suffix for matching the original.
		$stem = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $filename );
		$stem = preg_replace( '/-scaled(?=\.[a-zA-Z0-9]+$)/', '', $stem );

		global $wpdb;
		$like = '%' . $wpdb->esc_like( $stem ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
				 LIMIT 50",
				$like
			)
		);

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$aid  = (int) $row->post_id;
				$post = get_post( $aid );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					continue;
				}
				$base = basename( $row->meta_value );
				// Accept exact basename or stem match.
				$base_stem = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $base );
				$base_stem = preg_replace( '/-scaled(?=\.[a-zA-Z0-9]+$)/', '', $base_stem );
				if ( $base !== $filename && $base_stem !== $stem && $base !== $stem ) {
					// Also allow if the stored path ends with the requested name.
					if ( substr( $row->meta_value, -strlen( $filename ) ) !== $filename
						&& substr( $row->meta_value, -strlen( $stem ) ) !== $stem ) {
						continue;
					}
				}
				$meta = wp_get_attachment_metadata( $aid );
				$file = get_attached_file( $aid );
				$out[] = array(
					'attachment_id' => $aid,
					'filename'      => $base,
					'path'          => $file ? $file : '',
					'url'           => (string) wp_get_attachment_url( $aid ),
					'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
					'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
					'filesize'      => ( $file && is_readable( $file ) ) ? (int) filesize( $file ) : 0,
					'thumb_url'     => wp_get_attachment_image_url( $aid, 'medium' ) ?: (string) wp_get_attachment_url( $aid ),
					'proposed_src'  => $this->pick_display_url( $aid, is_array( $meta ) ? $meta : array() ),
					'proposed_href' => (string) wp_get_attachment_url( $aid ),
				);
			}
		}
		return $out;
	}

	/**
	 * Scan posts for uses of a specific attachment / filename.
	 *
	 * @param string $filename Filename from user.
	 * @return array
	 */
	public function scan_by_filename( $filename ) {
		$attachments = $this->find_attachments_by_filename( $filename );
		$usages      = array();
		$posts_hit   = array();

		if ( empty( $attachments ) ) {
			return array(
				'filename'    => $filename,
				'attachments' => array(),
				'usages'      => array(),
				'posts_count' => 0,
				'scanned_at'  => current_time( 'mysql' ),
				'message'     => 'No media library attachment matched that filename.',
			);
		}

		$aid_set = array();
		$url_bits = array();
		foreach ( $attachments as $att ) {
			$aid_set[ $att['attachment_id'] ] = true;
			$url_bits[] = $att['url'];
			$url_bits[] = $att['filename'];
			$stem = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $att['filename'] );
			if ( $stem ) {
				$url_bits[] = $stem;
			}
		}
		$url_bits = array_unique( array_filter( $url_bits ) );

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
				$hit = false;
				$roles = array();

				// Featured.
				$thumb = (int) get_post_thumbnail_id( $post->ID );
				if ( $thumb && isset( $aid_set[ $thumb ] ) ) {
					$hit = true;
					$roles[] = 'featured';
				}

				$content = $post->post_content;
				if ( $content ) {
					foreach ( $url_bits as $bit ) {
						if ( $bit && false !== strpos( $content, $bit ) ) {
							$hit = true;
							$roles[] = 'content';
							break;
						}
					}
					foreach ( array_keys( $aid_set ) as $aid ) {
						if ( false !== strpos( $content, 'wp-image-' . $aid ) ) {
							$hit = true;
							$roles[] = 'content-class';
						}
					}
				}

				if ( $hit ) {
					$posts_hit[ $post->ID ] = true;
					$thumb_url = '';
					$aid_used  = $thumb && isset( $aid_set[ $thumb ] ) ? $thumb : (int) array_key_first( $aid_set );
					$thumb_url = wp_get_attachment_image_url( $aid_used, 'medium' ) ?: '';
					$usages[]  = array(
						'post_id'       => (int) $post->ID,
						'post_title'    => $post->post_title,
						'roles'         => array_values( array_unique( $roles ) ),
						'attachment_id' => $aid_used,
						'thumb_url'     => $thumb_url,
						'edit_link'     => get_edit_post_link( $post->ID, 'raw' ),
					);
				}
			}
			wp_reset_postdata();
		}

		return array(
			'filename'    => $filename,
			'attachments' => $attachments,
			'usages'      => $usages,
			'posts_count' => count( $posts_hit ),
			'scanned_at'  => current_time( 'mysql' ),
			'message'     => '',
		);
	}

	/**
	 * Downsize a specific filename's embeds in all matching posts.
	 * Display → ~768px (or closest). Link/href → full original.
	 *
	 * @param string $filename Filename.
	 * @return array Stats.
	 */
	public function downsize_by_filename( $filename ) {
		$scan = $this->scan_by_filename( $filename );
		$stats = array(
			'posts_touched'  => 0,
			'images_changed' => 0,
			'errors'         => 0,
			'attachments'    => count( $scan['attachments'] ?? array() ),
			'posts_found'    => (int) ( $scan['posts_count'] ?? 0 ),
		);

		if ( empty( $scan['attachments'] ) ) {
			$stats['message'] = 'No attachment found for that filename.';
			return $stats;
		}
		if ( empty( $scan['usages'] ) ) {
			$stats['message'] = 'Attachment found, but it is not used in any post content/featured image.';
			return $stats;
		}

		// Ensure sizes on each attachment.
		foreach ( $scan['attachments'] as $att ) {
			$this->ensure_sizes( (int) $att['attachment_id'] );
		}

		// Build old URL variants to replace per attachment.
		$replacements = array(); // old_url => array( src, href, aid, filename )
		foreach ( $scan['attachments'] as $att ) {
			$aid  = (int) $att['attachment_id'];
			$meta = wp_get_attachment_metadata( $aid );
			$src  = $this->pick_display_url( $aid, is_array( $meta ) ? $meta : array() );
			$href = (string) wp_get_attachment_url( $aid );

			$candidates = array( $href );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( array_keys( $meta['sizes'] ) as $size ) {
					$u = wp_get_attachment_image_url( $aid, $size );
					if ( $u ) {
						$candidates[] = $u;
					}
				}
			}
			// Only treat FULL (no -WxH) as needing downsize for src; still map any known URL.
			foreach ( array_unique( $candidates ) as $old ) {
				$replacements[ $old ] = array(
					'src'      => $src,
					'href'     => $href,
					'aid'      => $aid,
					'filename' => $att['filename'],
				);
			}
		}

		$post_ids = array();
		foreach ( $scan['usages'] as $u ) {
			$post_ids[ (int) $u['post_id'] ] = true;
		}

		foreach ( array_keys( $post_ids ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'post' !== $post->post_type ) {
				continue;
			}

			$content     = $post->post_content;
			$new_content = $content;
			$changed     = 0;

			foreach ( $replacements as $old => $map ) {
				// img src: always use mid-size display.
				$count = 0;
				$new_content = preg_replace(
					'/(<img\b[^>]*\bsrc\s*=\s*["\'])' . preg_quote( $old, '/' ) . '(["\'])/i',
					'${1}' . $map['src'] . '${2}',
					$new_content,
					-1,
					$count
				);
				$changed += $count;

				// a href: full original.
				$new_content = preg_replace(
					'/(<a\b[^>]*\bhref\s*=\s*["\'])' . preg_quote( $old, '/' ) . '(["\'])/i',
					'${1}' . $map['href'] . '${2}',
					$new_content
				);

				// Block JSON url / href.
				if ( false !== strpos( $new_content, $old ) ) {
					$new_content = str_replace( '"url":"' . $old . '"', '"url":"' . $map['src'] . '"', $new_content );
					$new_content = str_replace( '"href":"' . $old . '"', '"href":"' . $map['href'] . '"', $new_content );
				}

				Logger::log(
					array(
						'post_id'        => $post_id,
						'post_title'     => $post->post_title,
						'old_attachment' => $map['aid'],
						'old_filename'   => $map['filename'],
						'old_url'        => $old,
						'new_attachment' => $map['aid'],
						'new_filename'   => basename( $map['src'] ),
						'new_url'        => $map['src'],
						'action'         => 'downsize-named',
						'result'         => $count > 0 ? 'ok' : 'noop',
					)
				);
			}

			if ( $new_content !== $content ) {
				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					++$stats['errors'];
				} else {
					++$stats['posts_touched'];
					$stats['images_changed'] += max( 1, $changed );
				}
			}
		}

		$stats['message'] = sprintf(
			'Done. Posts updated: %d. Image refs rewritten: %d. Errors: %d.',
			$stats['posts_touched'],
			$stats['images_changed'],
			$stats['errors']
		);
		return $stats;
	}

}