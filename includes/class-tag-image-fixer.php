<?php
/**
 * Finds image links where the displayed image is using the large/full-size
 * original and changes the displayed image to a WordPress-generated
 * intermediate size while preserving the full-size click-through link.
 *
 * Checks both Posts and Pages.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Image_Link_Fixer
 */
class Image_Link_Fixer {

	/**
	 * Preferred display size.
	 *
	 * WordPress normally generates medium_large at approximately 768px wide.
	 *
	 * @var string
	 */
	const DISPLAY_SIZE = 'medium_large';

	/**
	 * Scan both Posts and Pages.
	 *
	 * @return array
	 */
	public function scan() {
		$items = array();

		$post_types = array(
			'post',
			'page',
		);

		$posts = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$posts_scanned = 0;
		$pages_scanned = 0;

		foreach ( $posts as $post ) {
			if ( 'post' === $post->post_type ) {
				++$posts_scanned;
			} elseif ( 'page' === $post->post_type ) {
				++$pages_scanned;
			}

			$found = $this->find_matches_in_content( $post->post_content );

			foreach ( $found as $match ) {
				$match['post_id']    = (int) $post->ID;
				$match['post_title'] = get_the_title( $post->ID );
				$match['post_type']  = $post->post_type;

				$items[] = $match;
			}
		}

		return array(
			'scanned_at'   => current_time( 'mysql' ),
			'posts_scanned' => $posts_scanned,
			'pages_scanned' => $pages_scanned,
			'match_count'   => count( $items ),
			'items'         => $items,
		);
	}

	/**
	 * Fix all matching image links from the stored scan.
	 *
	 * @return array
	 */
	public function fix() {
		$report = get_transient( 'rd3_pic_image_link_scan' );

		if ( empty( $report ) || empty( $report['items'] ) ) {
			$report = $this->scan();
		}

		$stats = array(
			'posts_updated'  => 0,
			'pages_updated'  => 0,
			'images_changed' => 0,
			'skipped'        => 0,
			'errors'         => 0,
		);

		$items_by_post = array();

		foreach ( $report['items'] as $item ) {
			$post_id = (int) ( $item['post_id'] ?? 0 );

			if ( ! $post_id ) {
				++$stats['skipped'];
				continue;
			}

			$items_by_post[ $post_id ][] = $item;
		}

		foreach ( $items_by_post as $post_id => $items ) {
			$post = get_post( $post_id );

			if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
				++$stats['skipped'];
				continue;
			}

			$original_content = $post->post_content;
			$content          = $original_content;
			$changed           = 0;

			foreach ( $items as $item ) {
				$result = $this->replace_match( $content, $item );

				if ( ! empty( $result['changed'] ) ) {
					$content = $result['content'];
					++$changed;
				} elseif ( ! empty( $result['skipped'] ) ) {
					++$stats['skipped'];
				}
			}

			if ( $changed < 1 || $content === $original_content ) {
				continue;
			}

			$update = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				),
				true
			);

			if ( is_wp_error( $update ) ) {
				++$stats['errors'];

				Logger::log(
					array(
						'post_id'        => $post_id,
						'post_title'     => get_the_title( $post_id ),
						'action'         => 'image_link_fix',
						'result'         => 'failed',
						'error'          => $update->get_error_message(),
					)
				);

				continue;
			}

			if ( 'post' === $post->post_type ) {
				++$stats['posts_updated'];
			} elseif ( 'page' === $post->post_type ) {
				++$stats['pages_updated'];
			}

			$stats['images_changed'] += $changed;

			Logger::log(
				array(
					'post_id'    => $post_id,
					'post_title' => get_the_title( $post_id ),
					'action'     => 'image_link_fix',
					'result'     => 'success',
					'error'      => '',
				)
			);
		}

		delete_transient( 'rd3_pic_image_link_scan' );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: number of image links. */
				__( '%d image link(s) fixed.', 'rd3-post-image-cleanup' ),
				(int) $stats['images_changed']
			),
			'stats'   => $stats,
		);
	}

	/**
	 * Find matching image links in content.
	 *
	 * The target structure is effectively:
	 *
	 * <a href="full-image.jpg">
	 *     <img src="full-image.jpg">
	 * </a>
	 *
	 * The href is preserved. The img src is changed to an intermediate
	 * WordPress image size.
	 *
	 * @param string $content Post content.
	 *
	 * @return array
	 */
	private function find_matches_in_content( $content ) {
		if ( '' === trim( $content ) ) {
			return array();
		}

		$matches = array();

		if ( ! class_exists( '\DOMDocument' ) ) {
			return $this->find_matches_with_regex( $content );
		}

		$dom = new \DOMDocument();

		$previous = libxml_use_internal_errors( true );

		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $content,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->find_matches_with_regex( $content );
		}

		$anchors = $dom->getElementsByTagName( 'a' );

		foreach ( $anchors as $anchor ) {
			$images = $anchor->getElementsByTagName( 'img' );

			if ( 1 !== $images->length ) {
				continue;
			}

			$image = $images->item( 0 );

			$href = trim( (string) $anchor->getAttribute( 'href' ) );
			$src  = trim( (string) $image->getAttribute( 'src' ) );

			if ( '' === $href || '' === $src ) {
				continue;
			}

			$attachment_id = $this->resolve_attachment_id( $href );

			if ( ! $attachment_id ) {
				$attachment_id = $this->resolve_attachment_id( $src );
			}

			if ( ! $attachment_id ) {
				continue;
			}

			$original_url = wp_get_attachment_url( $attachment_id );

			if ( ! $original_url ) {
				continue;
			}

			$full_size_url = $this->get_full_size_url( $attachment_id );

			if ( ! $this->is_full_or_large_image( $src, $attachment_id ) ) {
				continue;
			}

			$display = $this->get_display_image_data( $attachment_id );

			if ( empty( $display['url'] ) ) {
				continue;
			}

			if ( $this->urls_are_same( $src, $display['url'] ) ) {
				continue;
			}

			$matches[] = array(
				'attachment_id' => $attachment_id,
				'current_src'   => $src,
				'href'          => $href,
				'original_url'  => $original_url,
				'full_size_url' => $full_size_url,
				'proposed_src'  => $display['url'],
				'width'         => (int) $display['width'],
				'height'        => (int) $display['height'],
			);
		}

		return $matches;
	}

	/**
	 * Replace one scanned image link.
	 *
	 * Uses DOM where possible and matches by attachment URL/current src.
	 *
	 * @param string $content Current content.
	 * @param array  $item    Scan item.
	 *
	 * @return array
	 */
	private function replace_match( $content, array $item ) {
		$attachment_id = (int) ( $item['attachment_id'] ?? 0 );

		if ( ! $attachment_id ) {
			return array(
				'changed' => false,
				'skipped' => true,
				'content' => $content,
			);
		}

		$display = $this->get_display_image_data( $attachment_id );

		if ( empty( $display['url'] ) ) {
			return array(
				'changed' => false,
				'skipped' => true,
				'content' => $content,
			);
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return $this->replace_match_with_regex( $content, $item, $display );
		}

		$dom = new \DOMDocument();

		$previous = libxml_use_internal_errors( true );

		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $content,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array(
				'changed' => false,
				'skipped' => true,
				'content' => $content,
			);
		}

		$changed = false;

		$anchors = $dom->getElementsByTagName( 'a' );

		foreach ( $anchors as $anchor ) {
			$images = $anchor->getElementsByTagName( 'img' );

			if ( 1 !== $images->length ) {
				continue;
			}

			$image = $images->item( 0 );

			$current_src = trim( (string) $image->getAttribute( 'src' ) );
			$href        = trim( (string) $anchor->getAttribute( 'href' ) );

			if ( ! $this->urls_match_item( $current_src, $href, $item ) ) {
				continue;
			}

			if ( ! $this->is_full_or_large_image( $current_src, $attachment_id ) ) {
				continue;
			}

			$image->setAttribute( 'src', $display['url'] );

			$srcset = wp_get_attachment_image_srcset(
				$attachment_id,
				self::DISPLAY_SIZE
			);

			if ( $srcset ) {
				$image->setAttribute( 'srcset', $srcset );

				$sizes = wp_calculate_image_sizes(
					array(
						(int) $display['width'],
						(int) $display['height'],
					),
					$display['url'],
					null,
					$attachment_id
				);

				if ( $sizes ) {
					$image->setAttribute( 'sizes', $sizes );
				}
			}

			$image->setAttribute( 'width', (string) (int) $display['width'] );
			$image->setAttribute( 'height', (string) (int) $display['height'] );

			$changed = true;
			break;
		}

		if ( ! $changed ) {
			return array(
				'changed' => false,
				'skipped' => true,
				'content' => $content,
			);
		}

		$new_content = $this->save_dom_fragment( $dom );

		if ( '' === $new_content ) {
			return array(
				'changed' => false,
				'skipped' => true,
				'content' => $content,
			);
		}

		return array(
			'changed' => true,
			'skipped' => false,
			'content' => $new_content,
		);
	}

	/**
	 * Determine whether an image URL is the original/full-size image
	 * or a large generated size.
	 *
	 * @param string $src           Image URL.
	 * @param int    $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	private function is_full_or_large_image( $src, $attachment_id ) {
		$original = wp_get_attachment_url( $attachment_id );

		if ( $original && $this->urls_are_same( $src, $original ) ) {
			return true;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata ) || empty( $metadata['sizes'] ) ) {
			return false;
		}

		$src_filename = wp_basename( wp_parse_url( $src, PHP_URL_PATH ) );

		if ( '' === $src_filename ) {
			return false;
		}

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) || $size['file'] !== $src_filename ) {
				continue;
			}

			$width = (int) ( $size['width'] ?? 0 );

			/*
			 * We only want to repair images that are still relatively large.
			 * A 768px or smaller image is already suitable for the intended
			 * display purpose.
			 */
			return $width > 768;
		}

		return false;
	}

	/**
	 * Get WordPress full-size URL.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string
	 */
	private function get_full_size_url( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );

		return $url ? $url : '';
	}

	/**
	 * Get preferred display image data.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array
	 */
	private function get_display_image_data( $attachment_id ) {
		$image = wp_get_attachment_image_src(
			$attachment_id,
			self::DISPLAY_SIZE
		);

		if ( ! $image || empty( $image[0] ) ) {
			$image = wp_get_attachment_image_src(
				$attachment_id,
				'large'
			);
		}

		if ( ! $image || empty( $image[0] ) ) {
			return array();
		}

		return array(
			'url'    => $image[0],
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
		);
	}

	/**
	 * Resolve an attachment ID from an image URL.
	 *
	 * Handles original URLs and generated WordPress image-size URLs.
	 *
	 * @param string $url Image URL.
	 *
	 * @return int
	 */
	private function resolve_attachment_id( $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return 0;
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			return (int) $attachment_id;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! $path ) {
			return 0;
		}

		$filename = wp_basename( $path );

		if ( '' === $filename ) {
			return 0;
		}

		/*
		 * Remove common WordPress generated suffixes:
		 *
		 * image-150x150.jpg
		 * image-300x200.jpg
		 * image-768x512.jpg
		 * image-1024x683.jpg
		 * image-scaled.jpg
		 */
		$base_filename = preg_replace(
			'/(?:-\d+x\d+|-scaled)(?=\.[^.]+$)/i',
			'',
			$filename
		);

		if ( ! $base_filename || $base_filename === $filename ) {
			return 0;
		}

		$uploads = wp_upload_dir();

		$relative = ltrim(
			str_replace(
				trailingslashit( $uploads['baseurl'] ),
				'',
				$url
			),
			'/'
		);

		$relative_path = dirname( $relative );

		if ( '.' === $relative_path ) {
			$relative_path = '';
		}

		$original_relative = ltrim(
			( $relative_path ? trailingslashit( $relative_path ) : '' ) . $base_filename,
			'/'
		);

		$original_url = trailingslashit( $uploads['baseurl'] ) . $original_relative;

		$attachment_id = attachment_url_to_postid( $original_url );

		if ( $attachment_id ) {
			return (int) $attachment_id;
		}

		/*
		 * Final fallback: compare attached file metadata.
		 */
		$attachment_id = $this->find_attachment_by_filename( $base_filename );

		return $attachment_id ? (int) $attachment_id : 0;
	}

	/**
	 * Find attachment by original filename.
	 *
	 * @param string $filename Filename.
	 *
	 * @return int
	 */
	private function find_attachment_by_filename( $filename ) {
		global $wpdb;

		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			return 0;
		}

		$like = '%/' . $wpdb->esc_like( $filename );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				AND meta_value LIKE %s
				ORDER BY post_id ASC
				LIMIT 1
				",
				$like
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Compare two URLs while ignoring query strings/fragments.
	 *
	 * @param string $url_a URL A.
	 * @param string $url_b URL B.
	 *
	 * @return bool
	 */
	private function urls_are_same( $url_a, $url_b ) {
		$path_a = wp_parse_url( $url_a, PHP_URL_PATH );
		$path_b = wp_parse_url( $url_b, PHP_URL_PATH );

		if ( ! $path_a || ! $path_b ) {
			return false;
		}

		return untrailingslashit( $path_a ) === untrailingslashit( $path_b );
	}

	/**
	 * Determine whether a URL belongs to the scanned item.
	 *
	 * @param string $src  Current src.
	 * @param string $href Link href.
	 * @param array  $item Scan item.
	 *
	 * @return bool
	 */
	private function urls_match_item( $src, $href, array $item ) {
		$current_src = $item['current_src'] ?? '';
		$item_href   = $item['href'] ?? '';

		if ( $current_src && $this->urls_are_same( $src, $current_src ) ) {
			return true;
		}

		if ( $item_href && $this->urls_are_same( $href, $item_href ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Save DOM fragment without introducing a complete HTML document.
	 *
	 * @param \DOMDocument $dom DOM document.
	 *
	 * @return string
	 */
	private function save_dom_fragment( \DOMDocument $dom ) {
		$html = $dom->saveHTML();

		$html = preg_replace(
			'/^<\?xml[^>]+>\s*/i',
			'',
			$html
		);

		return trim( $html );
	}

	/**
	 * Regex fallback scanner.
	 *
	 * @param string $content Content.
	 *
	 * @return array
	 */
	private function find_matches_with_regex( $content ) {
		$matches = array();

		$pattern = '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>\s*<img\b[^>]*src=(["\'])(.*?)\3[^>]*>\s*</a>#is';

		if ( ! preg_match_all( $pattern, $content, $found, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $found as $match ) {
			$href = trim( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) );
			$src  = trim( html_entity_decode( $match[4], ENT_QUOTES, 'UTF-8' ) );

			$attachment_id = $this->resolve_attachment_id( $href );

			if ( ! $attachment_id ) {
				$attachment_id = $this->resolve_attachment_id( $src );
			}

			if ( ! $attachment_id || ! $this->is_full_or_large_image( $src, $attachment_id ) ) {
				continue;
			}

			$display = $this->get_display_image_data( $attachment_id );

			if ( empty( $display['url'] ) || $this->urls_are_same( $src, $display['url'] ) ) {
				continue;
			}

			$matches[] = array(
				'attachment_id' => $attachment_id,
				'current_src'   => $src,
				'href'          => $href,
				'original_url'  => wp_get_attachment_url( $attachment_id ),
				'full_size_url' => wp_get_attachment_url( $attachment_id ),
				'proposed_src'  => $display['url'],
				'width'         => $display['width'],
				'height'        => $display['height'],
			);
		}

		return $matches;
	}

	/**
	 * Regex fallback replacement.
	 *
	 * @param string $content Content.
	 * @param array  $item    Scan item.
	 * @param array  $display Display image.
	 *
	 * @return array
	 */
	private function replace_match_with_regex( $content, array $item, array $display ) {
		$current_src = preg_quote( $item['current_src'], '#' );

		$pattern = '#(<a\b[^>]*href=(["\'])(.*?)\2[^>]*>\s*<img\b[^>]*src=(["\'])'
			. $current_src
			. '\4)([^>]*)(>)#is';

		$replacement = function ( $match ) use ( $display, $item ) {
			$attributes = $match[5];

			$attributes = preg_replace(
				'/\s+srcset=(["\']).*?\1/i',
				'',
				$attributes
			);

			$attributes = preg_replace(
				'/\s+sizes=(["\']).*?\1/i',
				'',
				$attributes
			);

			$attributes = preg_replace(
				'/\s+width=(["\']).*?\1/i',
				'',
				$attributes
			);

			$attributes = preg_replace(
				'/\s+height=(["\']).*?\1/i',
				'',
				$attributes
			);

			$srcset = wp_get_attachment_image_srcset(
				(int) $item['attachment_id'],
				self::DISPLAY_SIZE
			);

			$sizes = wp_calculate_image_sizes(
				array(
					(int) $display['width'],
					(int) $display['height'],
				),
				$display['url'],
				null,
				(int) $item['attachment_id']
			);

			$attributes .= ' width="' . esc_attr( (int) $display['width'] ) . '"';
			$attributes .= ' height="' . esc_attr( (int) $display['height'] ) . '"';

			if ( $srcset ) {
				$attributes .= ' srcset="' . esc_attr( $srcset ) . '"';
			}

			if ( $sizes ) {
				$attributes .= ' sizes="' . esc_attr( $sizes ) . '"';
			}

			return $match[1]
				? preg_replace(
					'/src=(["\']).*?\1/i',
					'src="' . esc_url( $display['url'] ) . '"',
					$match[0]
				)
				: $match[0];
		};

		$new_content = preg_replace_callback(
			$pattern,
			$replacement,
			$content,
			1
		);

		if ( null === $new_content || $new_content === $content ) {
			return array(
				'changed' => false,
				'skipped' => true,
				'content' => $content,
			);
		}

		return array(
			'changed' => true,
			'skipped' => false,
			'content' => $new_content,
		);
	}
}