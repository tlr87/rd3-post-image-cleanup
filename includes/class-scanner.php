<?php
/**
 * Read-only scanner: collect images used by posts (post type only).
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Scanner
 */
class Scanner {

	/**
	 * Upload base directory and URL.
	 *
	 * @var string
	 */
	private $upload_dir;

	/**
	 * Upload base URL.
	 *
	 * @var string
	 */
	private $upload_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$uploads         = wp_upload_dir();
		$this->upload_dir = trailingslashit( $uploads['basedir'] );
		$this->upload_url = trailingslashit( $uploads['baseurl'] );
	}

	/**
	 * Scan all published/draft/private posts of type "post".
	 *
	 * @return array {
	 *     @type int   $posts_scanned
	 *     @type array $attachments  Map of attachment_id => usage info
	 *     @type array $post_usages  List of post-level image references
	 * }
	 */
	public function scan() {
		$posts_scanned = 0;
		$attachments   = array(); // attachment_id => info
		$post_usages   = array(); // individual references for reporting

		$query = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 100,
				'paged'                  => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		$total_pages = (int) $query->max_num_pages;

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			if ( $page > 1 ) {
				$query = new \WP_Query(
					array(
						'post_type'              => 'post',
						'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'posts_per_page'         => 100,
						'paged'                  => $page,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => true,
						'update_post_term_cache' => false,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
					)
				);
			}

			foreach ( $query->posts as $post_id ) {
				$post_id = (int) $post_id;
				++$posts_scanned;

				$post = get_post( $post_id );
				if ( ! $post || 'post' !== $post->post_type ) {
					continue;
				}

				// Featured image.
				$thumb_id = (int) get_post_thumbnail_id( $post_id );
				if ( $thumb_id > 0 ) {
					$this->register_attachment_usage(
						$attachments,
						$post_usages,
						$thumb_id,
						$post_id,
						$post->post_title,
						'featured',
						wp_get_attachment_url( $thumb_id )
					);
				}

				// Content images.
				$content = $post->post_content;
				if ( $content ) {
					$this->extract_content_images( $content, $post_id, $post->post_title, $attachments, $post_usages );
				}
			}

			// Free memory between pages.
			wp_reset_postdata();
		}

		return array(
			'posts_scanned' => $posts_scanned,
			'attachments'   => $attachments,
			'post_usages'   => $post_usages,
		);
	}

	/**
	 * Extract image references from post content (HTML + blocks).
	 *
	 * @param string $content      Post content.
	 * @param int    $post_id      Post ID.
	 * @param string $post_title   Post title.
	 * @param array  $attachments  Accumulator.
	 * @param array  $post_usages  Accumulator.
	 */
	private function extract_content_images( $content, $post_id, $post_title, &$attachments, &$post_usages ) {
		// 1. Gutenberg blocks via parse_blocks if available.
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $content );
			$this->walk_blocks( $blocks, $post_id, $post_title, $attachments, $post_usages );
		}

		// 2. Regex for <img> tags (covers classic editor and residual HTML).
		if ( preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$url = $m[1];
				$this->resolve_url_to_attachment( $url, $post_id, $post_title, 'content-img', $attachments, $post_usages );
			}
		}

		// 3. Linked images: <a href="...image...">
		if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+\.(?:jpe?g|png|gif|webp|avif)(?:\?[^"\']*)?)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$url = $m[1];
				$this->resolve_url_to_attachment( $url, $post_id, $post_title, 'content-link', $attachments, $post_usages );
			}
		}

		// 4. srcset attributes.
		if ( preg_match_all( '/\bsrcset\s*=\s*["\']([^"\']+)["\']/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$parts = preg_split( '/\s*,\s*/', $m[1] );
				foreach ( $parts as $part ) {
					$part = trim( $part );
					if ( preg_match( '/^(\S+)/', $part, $um ) ) {
						$this->resolve_url_to_attachment( $um[1], $post_id, $post_title, 'content-srcset', $attachments, $post_usages );
					}
				}
			}
		}

		// 5. wp-image-ID class fallback.
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $aid ) {
				$aid = (int) $aid;
				if ( $aid > 0 ) {
					$this->register_attachment_usage(
						$attachments,
						$post_usages,
						$aid,
						$post_id,
						$post_title,
						'content-class',
						wp_get_attachment_url( $aid )
					);
				}
			}
		}
	}

	/**
	 * Recursively walk Gutenberg blocks for image data.
	 *
	 * @param array  $blocks       Blocks.
	 * @param int    $post_id      Post ID.
	 * @param string $post_title   Title.
	 * @param array  $attachments  Accumulator.
	 * @param array  $post_usages  Accumulator.
	 */
	private function walk_blocks( $blocks, $post_id, $post_title, &$attachments, &$post_usages ) {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? array();

			if ( 'core/image' === $name ) {
				$id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				$url = $attrs['url'] ?? '';
				if ( $id > 0 ) {
					$this->register_attachment_usage( $attachments, $post_usages, $id, $post_id, $post_title, 'block-image', $url ?: wp_get_attachment_url( $id ) );
				} elseif ( $url ) {
					$this->resolve_url_to_attachment( $url, $post_id, $post_title, 'block-image-url', $attachments, $post_usages );
				}
			} elseif ( 'core/gallery' === $name ) {
				// Gallery may have ids array or nested image blocks.
				if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
					foreach ( $attrs['ids'] as $id ) {
						$id = (int) $id;
						if ( $id > 0 ) {
							$this->register_attachment_usage( $attachments, $post_usages, $id, $post_id, $post_title, 'block-gallery', wp_get_attachment_url( $id ) );
						}
					}
				}
			} elseif ( 'core/media-text' === $name ) {
				$id = isset( $attrs['mediaId'] ) ? (int) $attrs['mediaId'] : 0;
				if ( $id > 0 ) {
					$this->register_attachment_usage( $attachments, $post_usages, $id, $post_id, $post_title, 'block-media-text', wp_get_attachment_url( $id ) );
				}
			} elseif ( 'core/cover' === $name ) {
				$id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				if ( $id > 0 ) {
					$this->register_attachment_usage( $attachments, $post_usages, $id, $post_id, $post_title, 'block-cover', wp_get_attachment_url( $id ) );
				}
			}

			// Inner blocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $post_id, $post_title, $attachments, $post_usages );
			}
		}
	}

	/**
	 * Resolve a URL to an attachment ID and register usage.
	 *
	 * @param string $url          Image URL.
	 * @param int    $post_id      Post ID.
	 * @param string $post_title   Title.
	 * @param string $role         Usage role.
	 * @param array  $attachments  Accumulator.
	 * @param array  $post_usages  Accumulator.
	 */
	private function resolve_url_to_attachment( $url, $post_id, $post_title, $role, &$attachments, &$post_usages ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return;
		}

		// Strip size suffix from filename to find original attachment more reliably.
		$attachment_id = $this->url_to_attachment_id( $url );
		if ( $attachment_id > 0 ) {
			$this->register_attachment_usage( $attachments, $post_usages, $attachment_id, $post_id, $post_title, $role, $url );
		}
	}

	/**
	 * Convert image URL to attachment ID.
	 * Handles intermediate sizes by stripping -WxH / -scaled.
	 *
	 * @param string $url Image URL.
	 * @return int Attachment ID or 0.
	 */
	private function url_to_attachment_id( $url ) {
		// Prefer core helper when available.
		if ( function_exists( 'attachment_url_to_postid' ) ) {
			$id = attachment_url_to_postid( $url );
			if ( $id > 0 ) {
				return (int) $id;
			}
		}

		// Manual fallback: strip size and try again.
		$cleaned = $this->strip_image_size_from_url( $url );
		if ( $cleaned !== $url && function_exists( 'attachment_url_to_postid' ) ) {
			$id = attachment_url_to_postid( $cleaned );
			if ( $id > 0 ) {
				return (int) $id;
			}
		}

		// Last resort: match against _wp_attached_file.
		$path = $this->url_to_relative_path( $cleaned ?: $url );
		if ( $path ) {
			global $wpdb;
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$path
				)
			);
			if ( $id > 0 ) {
				return $id;
			}
			// Try without size in the stored path as well.
			$stripped_path = $this->strip_image_size_from_path( $path );
			if ( $stripped_path !== $path ) {
				$id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
						$stripped_path
					)
				);
				if ( $id > 0 ) {
					return $id;
				}
			}
		}

		return 0;
	}

	/**
	 * Strip WordPress size suffix from a URL filename.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function strip_image_size_from_url( $url ) {
		return preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+(?:\?|$))/', '', $url );
	}

	/**
	 * Strip size from relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function strip_image_size_from_path( $path ) {
		return preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $path );
	}

	/**
	 * Convert full URL to relative path under uploads.
	 *
	 * @param string $url URL.
	 * @return string|false
	 */
	private function url_to_relative_path( $url ) {
		if ( 0 === strpos( $url, $this->upload_url ) ) {
			return ltrim( substr( $url, strlen( $this->upload_url ) ), '/' );
		}
		// Protocol-relative or different scheme.
		$parsed = wp_parse_url( $url );
		$base   = wp_parse_url( $this->upload_url );
		if ( ! empty( $parsed['path'] ) && ! empty( $base['path'] ) && 0 === strpos( $parsed['path'], $base['path'] ) ) {
			return ltrim( substr( $parsed['path'], strlen( $base['path'] ) ), '/' );
		}
		return false;
	}

	/**
	 * Register that an attachment is used by a post.
	 *
	 * @param array  $attachments Accumulator.
	 * @param array  $post_usages Accumulator.
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $post_id       Post ID.
	 * @param string $post_title    Title.
	 * @param string $role          Usage role.
	 * @param string $url           Observed URL.
	 */
	private function register_attachment_usage( &$attachments, &$post_usages, $attachment_id, $post_id, $post_title, $role, $url ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Only media attachments.
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		if ( ! isset( $attachments[ $attachment_id ] ) ) {
			$attachments[ $attachment_id ] = array(
				'attachment_id' => $attachment_id,
				'posts'         => array(),
				'roles'         => array(),
			);
		}

		$attachments[ $attachment_id ]['posts'][ $post_id ] = true;
		$attachments[ $attachment_id ]['roles'][]           = $role;

		$post_usages[] = array(
			'post_id'       => $post_id,
			'post_title'    => $post_title,
			'attachment_id' => $attachment_id,
			'role'          => $role,
			'url'           => $url,
		);
	}
}