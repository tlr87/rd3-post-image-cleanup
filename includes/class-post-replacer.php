<?php
/**
 * Safely replace duplicate image references with the master in posts.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Replacer
 */
class Post_Replacer {

	/**
	 * Master attachment ID.
	 *
	 * @var int
	 */
	private $master_id;

	/**
	 * Duplicate attachment ID being replaced.
	 *
	 * @var int
	 */
	private $duplicate_id;

	/**
	 * Master file metadata (sizes, file, etc.).
	 *
	 * @var array
	 */
	private $master_meta;

	/**
	 * Master base URL (full-size).
	 *
	 * @var string
	 */
	private $master_url;

	/**
	 * Map of size name => URL for master.
	 *
	 * @var array
	 */
	private $master_size_urls = array();

	/**
	 * Duplicate full-size URL and known size URLs (for matching).
	 *
	 * @var array
	 */
	private $dup_urls = array();

	/**
	 * Replace all references to $duplicate_id with $master_id in a single post.
	 *
	 * @param int $post_id      Post ID (must be type "post").
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $master_id    Master attachment ID.
	 * @return array{success:bool,message:string,changes:int,featured:bool}
	 */
	public function replace_in_post( $post_id, $duplicate_id, $master_id ) {
		$post_id      = (int) $post_id;
		$duplicate_id = (int) $duplicate_id;
		$master_id    = (int) $master_id;

		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return array(
				'success'  => false,
				'message'  => 'Not a post (post type restricted).',
				'changes'  => 0,
				'featured' => false,
			);
		}

		if ( $duplicate_id === $master_id || $duplicate_id <= 0 || $master_id <= 0 ) {
			return array(
				'success'  => false,
				'message'  => 'Invalid attachment IDs.',
				'changes'  => 0,
				'featured' => false,
			);
		}

		$this->master_id    = $master_id;
		$this->duplicate_id = $duplicate_id;
		$this->prepare_url_maps();

		$changes  = 0;
		$featured = false;
		$errors   = array();

		// --- Featured image ---
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id === $duplicate_id ) {
			$ok = set_post_thumbnail( $post_id, $master_id );
			if ( $ok ) {
				$featured = true;
				++$changes;
			} else {
				$errors[] = 'Failed to update featured image.';
			}
		}

		// --- post_content ---
		$content     = $post->post_content;
		$new_content = $this->replace_in_content( $content );

		if ( $new_content !== $content ) {
			if ( $this->content_still_references_duplicate( $new_content ) ) {
				return array(
					'success'  => false,
					'message'  => 'Content still references duplicate after attempted replace; aborting save.',
					'changes'  => $changes,
					'featured' => $featured,
				);
			}

			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				$errors[] = $updated->get_error_message();
			} else {
				++$changes;
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'success'  => false,
				'message'  => implode( ' ', $errors ),
				'changes'  => $changes,
				'featured' => $featured,
			);
		}

		return array(
			'success'  => true,
			'message'  => $changes > 0 ? 'Updated.' : 'No references found in this post.',
			'changes'  => $changes,
			'featured' => $featured,
		);
	}

	/**
	 * Build URL maps for master and duplicate (full + intermediate sizes).
	 */
	private function prepare_url_maps() {
		$this->master_meta = wp_get_attachment_metadata( $this->master_id );
		if ( ! is_array( $this->master_meta ) ) {
			$this->master_meta = array();
		}

		$this->master_url       = (string) wp_get_attachment_url( $this->master_id );
		$this->master_size_urls = array(
			'full' => $this->master_url,
		);

		if ( ! empty( $this->master_meta['sizes'] ) && is_array( $this->master_meta['sizes'] ) ) {
			foreach ( $this->master_meta['sizes'] as $size => $data ) {
				$url = wp_get_attachment_image_url( $this->master_id, $size );
				if ( $url ) {
					$this->master_size_urls[ $size ] = $url;
				}
			}
		}

		$dup_url        = (string) wp_get_attachment_url( $this->duplicate_id );
		$this->dup_urls = array();
		if ( $dup_url ) {
			$this->dup_urls[] = $dup_url;
		}

		$dup_meta = wp_get_attachment_metadata( $this->duplicate_id );
		if ( ! empty( $dup_meta['sizes'] ) && is_array( $dup_meta['sizes'] ) ) {
			foreach ( $dup_meta['sizes'] as $size => $data ) {
				$url = wp_get_attachment_image_url( $this->duplicate_id, $size );
				if ( $url ) {
					$this->dup_urls[] = $url;
				}
			}
		}

		$dup_file = get_attached_file( $this->duplicate_id );
		if ( $dup_file ) {
			$scaled = preg_replace( '/(\.[a-zA-Z0-9]+)$/', '-scaled$1', $dup_file );
			if ( $scaled && is_readable( $scaled ) ) {
				$uploads          = wp_upload_dir();
				$rel              = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $scaled ), '/' );
				$this->dup_urls[] = trailingslashit( $uploads['baseurl'] ) . $rel;
			}
		}

		$this->dup_urls = array_unique( array_filter( $this->dup_urls ) );
	}

	/**
	 * Replace image references inside post_content.
	 *
	 * @param string $content Original content.
	 * @return string Modified content.
	 */
	private function replace_in_content( $content ) {
		if ( '' === $content || empty( $this->dup_urls ) ) {
			return $content;
		}

		$content = $this->replace_in_blocks( $content );

		$content = preg_replace(
			'/\bwp-image-' . preg_quote( (string) $this->duplicate_id, '/' ) . '\b/',
			'wp-image-' . $this->master_id,
			$content
		);

		$content = preg_replace(
			'/(data-(?:id|attachment-id)\s*=\s*["\'])' . preg_quote( (string) $this->duplicate_id, '/' ) . '(["\'])/',
			'${1}' . $this->master_id . '${2}',
			$content
		);

		$content = $this->replace_urls_in_html( $content );

		return $content;
	}

	/**
	 * Walk and rewrite Gutenberg blocks that reference the duplicate.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function replace_in_blocks( $content ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $content;
		}

		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		$blocks = $this->walk_and_replace_blocks( $blocks );
		return serialize_blocks( $blocks );
	}

	/**
	 * Recursive block walker.
	 *
	 * @param array $blocks Blocks.
	 * @return array
	 */
	private function walk_and_replace_blocks( array $blocks ) {
		foreach ( $blocks as &$block ) {
			$name  = $block['blockName'] ?? '';
			$attrs = &$block['attrs'];

			if ( ! is_array( $attrs ) ) {
				$attrs = array();
			}

			if ( 'core/image' === $name ) {
				$id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				if ( $id === $this->duplicate_id || ( ! empty( $attrs['url'] ) && $this->url_belongs_to_duplicate( $attrs['url'] ) ) ) {
					$attrs['id'] = $this->master_id;
					// Display URL: mid-size (e.g. -768x405).
					if ( ! empty( $attrs['url'] ) ) {
						$attrs['url'] = $this->map_dup_url_to_master( $attrs['url'], 768, 0 );
					} else {
						$attrs['url'] = $this->map_dup_url_to_master( $this->master_url, 768, 0 );
					}
					// Link URL: always full original when the image is linked to media/file.
					if ( ! empty( $attrs['href'] ) && $this->url_belongs_to_duplicate( $attrs['href'] ) ) {
						$attrs['href'] = $this->master_full_url();
					} elseif ( ! empty( $attrs['linkDestination'] ) && in_array( $attrs['linkDestination'], array( 'media', 'attachment', 'file' ), true ) ) {
						$attrs['href'] = $this->master_full_url();
					}
					// Prefer a sensible sizeSlug for the block.
					if ( empty( $attrs['sizeSlug'] ) || 'full' === $attrs['sizeSlug'] ) {
						$attrs['sizeSlug'] = $this->best_size_slug( 768 );
					}
				}
			} elseif ( 'core/gallery' === $name ) {
				if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
					foreach ( $attrs['ids'] as $i => $gid ) {
						if ( (int) $gid === $this->duplicate_id ) {
							$attrs['ids'][ $i ] = $this->master_id;
						}
					}
				}
			} elseif ( 'core/media-text' === $name ) {
				if ( isset( $attrs['mediaId'] ) && (int) $attrs['mediaId'] === $this->duplicate_id ) {
					$attrs['mediaId'] = $this->master_id;
					if ( ! empty( $attrs['url'] ) ) {
						$attrs['url'] = $this->map_dup_url_to_master( $attrs['url'], 768, 0 );
					}
					// mediaLink is the click target → full size.
					if ( ! empty( $attrs['mediaLink'] ) ) {
						$attrs['mediaLink'] = $this->master_full_url();
					}
				}
			} elseif ( 'core/cover' === $name ) {
				if ( isset( $attrs['id'] ) && (int) $attrs['id'] === $this->duplicate_id ) {
					$attrs['id'] = $this->master_id;
					if ( ! empty( $attrs['url'] ) ) {
						// Cover backgrounds often need a larger crop; still avoid multi-megapixel when possible.
						$attrs['url'] = $this->map_dup_url_to_master( $attrs['url'], 1536, 0 );
					}
				}
			}

			if ( ! empty( $block['innerHTML'] ) ) {
				$block['innerHTML'] = $this->replace_urls_in_html( $block['innerHTML'] );
				$block['innerHTML'] = preg_replace(
					'/\bwp-image-' . preg_quote( (string) $this->duplicate_id, '/' ) . '\b/',
					'wp-image-' . $this->master_id,
					$block['innerHTML']
				);
			}
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $k => $chunk ) {
					if ( is_string( $chunk ) && '' !== $chunk ) {
						$chunk = $this->replace_urls_in_html( $chunk );
						$chunk = preg_replace(
							'/\bwp-image-' . preg_quote( (string) $this->duplicate_id, '/' ) . '\b/',
							'wp-image-' . $this->master_id,
							$chunk
						);
						$block['innerContent'][ $k ] = $chunk;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->walk_and_replace_blocks( $block['innerBlocks'] );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Replace duplicate image URLs in HTML attributes (src, srcset, href).
	 * Uses full <img> context so width/height attributes can guide size choice.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function replace_urls_in_html( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		// Full <img ...> tags: use width/height attrs when present.
		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $m ) {
				$tag = $m[0];
				if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $sm ) ) {
					return $tag;
				}
				$url = $sm[1];
				if ( ! $this->url_belongs_to_duplicate( $url ) ) {
					return $tag;
				}

				$tw = 0;
				$th = 0;
				if ( preg_match( '/\bwidth\s*=\s*["\']?(\d+)/i', $tag, $wm ) ) {
					$tw = (int) $wm[1];
				}
				if ( preg_match( '/\bheight\s*=\s*["\']?(\d+)/i', $tag, $hm ) ) {
					$th = (int) $hm[1];
				}
				// style="width:NNpx"
				if ( ! $tw && preg_match( '/\bwidth\s*:\s*(\d+)px/i', $tag, $wm ) ) {
					$tw = (int) $wm[1];
				}

				$new_url = $this->map_dup_url_to_master( $url, $tw, $th );
				$tag     = preg_replace(
					'/(\bsrc\s*=\s*["\'])([^"\']+)(["\'])/i',
					'${1}' . $new_url . '${3}',
					$tag,
					1
				);
				return $tag;
			},
			$html
		);

		$html = preg_replace_callback(
			'/(<a\b[^>]*\bhref\s*=\s*["\'])([^"\']+)(["\'])/i',
			function ( $m ) {
				$url = $m[2];
				if ( $this->url_belongs_to_duplicate( $url ) ) {
					// Click-through must open the full original, e.g.:
					//   src  → 887197071069906-768x405.jpg
					//   href → 887197071069906.jpg
					return $m[1] . $this->master_full_url() . $m[3];
				}
				return $m[0];
			},
			$html
		);

		$html = preg_replace_callback(
			'/(\bsrcset\s*=\s*["\'])([^"\']+)(["\'])/i',
			function ( $m ) {
				$parts = preg_split( '/\s*,\s*/', $m[2] );
				$out   = array();
				foreach ( $parts as $part ) {
					$part = trim( $part );
					if ( preg_match( '/^(\S+)(\s+.*)?$/', $part, $pm ) ) {
						$url  = $pm[1];
						$rest = isset( $pm[2] ) ? $pm[2] : '';
						$hint_w = 0;
						if ( preg_match( '/\s+(\d+)w/', $rest, $wm ) ) {
							$hint_w = (int) $wm[1];
						}
						if ( $this->url_belongs_to_duplicate( $url ) ) {
							$url = $this->map_dup_url_to_master( $url, $hint_w, 0 );
						}
						$out[] = $url . $rest;
					} else {
						$out[] = $part;
					}
				}
				return $m[1] . implode( ', ', $out ) . $m[3];
			},
			$html
		);

		return $html;
	}

	/**
	 * Whether a URL points at the duplicate attachment (any size).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_belongs_to_duplicate( $url ) {
		$url = $this->normalize_url( $url );
		foreach ( $this->dup_urls as $dup ) {
			if ( $this->normalize_url( $dup ) === $url ) {
				return true;
			}
		}
		$dup_base  = $this->basename_without_size( basename( (string) wp_get_attachment_url( $this->duplicate_id ) ) );
		$candidate = $this->basename_without_size( basename( $url ) );
		if ( $dup_base && $candidate && $dup_base === $candidate ) {
			$uploads = wp_upload_dir();
			if ( false !== strpos( $url, $uploads['baseurl'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map a duplicate URL to the best matching master URL (prefer similar size).
	 *
	 * Facebook imports often embed the full-size original with no -WxH suffix.
	 * In that case we prefer a content-friendly size (large / medium_large / ~1024px)
	 * instead of the multi-megapixel original.
	 *
	 * @param string $url             Observed URL (may be a size).
	 * @param int    $attr_w          Optional width from HTML attributes.
	 * @param int    $attr_h          Optional height from HTML attributes.
	 * @param bool   $prefer_fullish  If true (e.g. lightbox href), allow larger sizes.
	 * @return string Replacement URL.
	 */
	private function map_dup_url_to_master( $url, $attr_w = 0, $attr_h = 0, $prefer_fullish = false ) {
		if ( ! $this->url_belongs_to_duplicate( $url ) ) {
			return $url;
		}

		$target_w = (int) $attr_w;
		$target_h = (int) $attr_h;

		if ( preg_match( '/-(\d+)x(\d+)(?=\.[a-zA-Z0-9]+(?:\?|$))/', $url, $m ) ) {
			$target_w = $target_w ?: (int) $m[1];
			$target_h = $target_h ?: (int) $m[2];
		} elseif ( preg_match( '/-scaled(?=\.[a-zA-Z0-9]+(?:\?|$))/', $url ) ) {
			// Scaled is already a constrained large image.
			return $this->prefer_content_size( 2560, $prefer_fullish );
		}

		// No size cue at all (typical Facebook full-size embed): use content default.
		if ( $target_w <= 0 ) {
			// Default display width ~768px (common WP intermediate); links use master_full_url() separately.
			$target_w = $prefer_fullish ? 2048 : 768;
		}

		$best = $this->find_best_size( $target_w, $target_h );
		if ( $best && ! empty( $this->master_size_urls[ $best ] ) ) {
			return $this->master_size_urls[ $best ];
		}

		return $this->prefer_content_size( $target_w, $prefer_fullish );
	}


	/**
	 * Full-size master URL (for click-through links).
	 *
	 * @return string
	 */
	private function master_full_url() {
		return $this->master_url ? $this->master_url : (string) wp_get_attachment_url( $this->master_id );
	}

	/**
	 * Pick a sizeSlug name closest to the target width.
	 *
	 * @param int $target_w Target width.
	 * @return string
	 */
	private function best_size_slug( $target_w = 768 ) {
		$best = $this->find_best_size( (int) $target_w, 0 );
		if ( $best ) {
			return $best;
		}
		foreach ( array( 'medium_large', 'large', 'medium' ) as $name ) {
			if ( ! empty( $this->master_size_urls[ $name ] ) ) {
				return $name;
			}
		}
		return 'large';
	}

	/**
	 * Prefer a registered content size over the full original.
	 *
	 * @param int  $target_w        Desired width.
	 * @param bool $prefer_fullish  Allow larger.
	 * @return string URL.
	 */
	private function prefer_content_size( $target_w, $prefer_fullish = false ) {
		// Prefer ~768-wide sizes for in-post display when available.
		$order = $prefer_fullish
			? array( '1536x1536', '2048x2048', 'large', 'medium_large', 'medium' )
			: array( 'medium_large', 'large', '1536x1536', 'medium', '2048x2048' );

		foreach ( $order as $name ) {
			if ( ! empty( $this->master_size_urls[ $name ] ) ) {
				// Skip sizes that are wildly smaller than target when we know target.
				$meta_w = 0;
				if ( ! empty( $this->master_meta['sizes'][ $name ]['width'] ) ) {
					$meta_w = (int) $this->master_meta['sizes'][ $name ]['width'];
				}
				if ( $target_w > 0 && $meta_w > 0 && $meta_w < (int) ( $target_w * 0.5 ) ) {
					continue;
				}
				return $this->master_size_urls[ $name ];
			}
		}

		// Last resort: closest registered size, else full.
		$best = $this->find_best_size( $target_w ?: 1024, 0 );
		if ( $best && ! empty( $this->master_size_urls[ $best ] ) ) {
			return $this->master_size_urls[ $best ];
		}

		return $this->master_url;
	}

	/**
	 * Find the closest available registered size on the master.
	 *
	 * @param int $target_w Target width.
	 * @param int $target_h Target height.
	 * @return string|null Size name.
	 */
	private function find_best_size( $target_w, $target_h ) {
		$sizes = $this->master_meta['sizes'] ?? array();
		if ( empty( $sizes ) || $target_w <= 0 ) {
			return null;
		}

		$best      = null;
		$best_diff = PHP_INT_MAX;

		foreach ( $sizes as $name => $data ) {
			$w = isset( $data['width'] ) ? (int) $data['width'] : 0;
			if ( $w <= 0 ) {
				continue;
			}
			$diff = abs( $w - $target_w );
			// Prefer sizes >= target (avoid upscaling) but not excessively larger.
			if ( $w < $target_w ) {
				$diff += ( $target_w - $w ); // extra penalty for smaller
			} elseif ( $w > $target_w * 1.5 ) {
				$diff += (int) ( ( $w - $target_w ) * 0.25 );
			}
			if ( $diff < $best_diff ) {
				$best_diff = $diff;
				$best      = $name;
			}
		}

		return $best;
	}

	/**
	 * Check whether content still clearly references the duplicate attachment.
	 *
	 * @param string $content Content after replacement.
	 * @return bool True if still references duplicate.
	 */
	private function content_still_references_duplicate( $content ) {
		if ( false !== strpos( $content, 'wp-image-' . $this->duplicate_id ) ) {
			return true;
		}
		foreach ( $this->dup_urls as $dup ) {
			if ( false !== strpos( $content, $dup ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize URL for comparison.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = strtok( $url, '?' );
		return untrailingslashit( (string) $url );
	}

	/**
	 * Basename without -WxH / -scaled suffix.
	 *
	 * @param string $basename Filename.
	 * @return string
	 */
	private function basename_without_size( $basename ) {
		$basename = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $basename );
		$basename = preg_replace( '/-scaled(?=\.[a-zA-Z0-9]+$)/', '', $basename );
		return $basename;
	}

	/**
	 * Verify a post no longer references the duplicate (content + featured).
	 *
	 * @param int $post_id      Post ID.
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @return bool True if clean.
	 */
	public function verify_post_clean( $post_id, $duplicate_id ) {
		$post_id      = (int) $post_id;
		$duplicate_id = (int) $duplicate_id;

		if ( (int) get_post_thumbnail_id( $post_id ) === $duplicate_id ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return true;
		}

		$this->duplicate_id = $duplicate_id;
		$this->master_id    = 0;
		$this->prepare_url_maps_for_verify( $duplicate_id );

		return ! $this->content_still_references_duplicate( $post->post_content );
	}

	/**
	 * Lightweight URL map for verification only.
	 *
	 * @param int $duplicate_id Duplicate ID.
	 */
	private function prepare_url_maps_for_verify( $duplicate_id ) {
		$this->dup_urls = array();
		$url            = wp_get_attachment_url( $duplicate_id );
		if ( $url ) {
			$this->dup_urls[] = $url;
		}
		$meta = wp_get_attachment_metadata( $duplicate_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $data ) {
				$u = wp_get_attachment_image_url( $duplicate_id, $size );
				if ( $u ) {
					$this->dup_urls[] = $u;
				}
			}
		}
		$this->dup_urls = array_unique( array_filter( $this->dup_urls ) );
	}
}