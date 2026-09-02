<?php
/**
 * Fix image click-links so sized files (e.g. name-768x512.jpg) link to the full original (name.jpg).
 * Separate runs for posts and pages. Does not change the displayed src size.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Fixer
 */
class Link_Fixer {

	/**
	 * Scan content of a post type for images that need a full-size href.
	 *
	 * @param string $post_type 'post' or 'page'.
	 * @return array
	 */
	public function scan( $post_type = 'post' ) {
		$post_type = ( 'page' === $post_type ) ? 'page' : 'post';
		$items     = array();
		$scanned   = 0;

		$query_args = array(
			'post_type'              => $post_type,
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
				if ( $post->post_type !== $post_type ) {
					continue;
				}
				++$scanned;
				foreach ( $this->find_link_issues( $post ) as $row ) {
					$items[] = $row;
				}
			}
			wp_reset_postdata();
		}

		return array(
			'post_type'     => $post_type,
			'posts_scanned' => $scanned,
			'issue_count'   => count( $items ),
			'items'         => $items,
			'scanned_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Find cases where display uses a sized file and link is missing/wrong.
	 *
	 * @param \WP_Post $post Post/page.
	 * @return array
	 */
	private function find_link_issues( $post ) {
		$content = $post->post_content;
		if ( ! $content ) {
			return array();
		}

		$issues = array();
		$seen   = array();

		if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $imgs, PREG_OFFSET_CAPTURE ) ) {
			// Still check bare anchors below.
			$imgs = array( array() );
		}

		foreach ( $imgs[0] as $match ) {
			$tag    = $match[0];
			$offset = $match[1];

			if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $sm ) ) {
				continue;
			}
			$src = $sm[1];

			if ( ! $this->url_has_size_suffix( $src ) ) {
				continue;
			}

			$full = $this->to_full_size_url( $src );
			if ( ! $full || $full === $src ) {
				continue;
			}

			$before = substr( $content, max( 0, $offset - 500 ), min( 500, $offset ) );
			$href   = null;
			if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $before, $am ) ) {
				$href = end( $am[1] );
			}

			$needs_fix = false;
			$reason     = '';
			if ( null === $href ) {
				$needs_fix = true;
				$reason     = 'no-link';
			} elseif ( $this->normalize_url( $href ) !== $this->normalize_url( $full ) ) {
				if ( $this->url_has_size_suffix( $href ) || $this->same_image_family( $href, $src ) ) {
					$needs_fix = true;
					$reason     = 'href-not-full';
				}
			}

			if ( ! $needs_fix ) {
				continue;
			}

			$key = $post->ID . '|' . $this->normalize_url( $src );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$issues[] = array(
				'post_id'      => (int) $post->ID,
				'post_title'   => $post->post_title,
				'post_type'    => $post->post_type,
				'src'          => $src,
				'current_href' => $href,
				'full_url'     => $full,
				'reason'       => $reason,
				'filename'     => wp_basename( $src ),
				'full_name'    => wp_basename( $full ),
				'thumb_url'    => $src,
				'edit_link'    => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $am ) ) {
			foreach ( $am[1] as $href ) {
				if ( ! $this->url_has_size_suffix( $href ) ) {
					continue;
				}
				$full = $this->to_full_size_url( $href );
				if ( ! $full || $this->normalize_url( $full ) === $this->normalize_url( $href ) ) {
					continue;
				}
				$key = $post->ID . '|a|' . $this->normalize_url( $href );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$issues[]     = array(
					'post_id'      => (int) $post->ID,
					'post_title'   => $post->post_title,
					'post_type'    => $post->post_type,
					'src'          => $href,
					'current_href' => $href,
					'full_url'     => $full,
					'reason'       => 'anchor-sized',
					'filename'     => wp_basename( $href ),
					'full_name'    => wp_basename( $full ),
					'thumb_url'    => $href,
					'edit_link'    => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}

		return $issues;
	}

	/**
	 * Whether URL filename has a WP size suffix.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_has_size_suffix( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			$path = $url;
		}
		$base = wp_basename( $path );
		if ( preg_match( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', $base ) ) {
			return true;
		}
		if ( preg_match( '/-scaled(?:-e\d+)?(?=\.[a-zA-Z0-9]+$)/', $base ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Convert sized/scaled URL to full original URL.
	 * .../104300966026191-768x512.jpg → .../104300966026191.jpg
	 *
	 * @param string $url Sized URL.
	 * @return string|null
	 */
	public function to_full_size_url( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : $url;
		$dir   = trailingslashit( dirname( $path ) );
		$base  = wp_basename( $path );

		$full_base = $base;
		$full_base = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $full_base );
		$full_base = preg_replace( '/-scaled(?:-e\d+)?(?=\.[a-zA-Z0-9]+$)/', '', $full_base );
		$full_base = preg_replace( '/-scaled(?:-e\d+)?(?=\.[a-zA-Z0-9]+$)/', '', $full_base );

		if ( $full_base === $base ) {
			return null;
		}

		$new_path = $dir . $full_base;

		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			$out  = $parts['scheme'] . '://' . $parts['host'] . $port . $new_path;
			if ( ! empty( $parts['query'] ) ) {
				$out .= '?' . $parts['query'];
			}
			return $out;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			return '//' . ltrim( $new_path, '/' );
		}
		return $new_path;
	}

	/**
	 * Same image family.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	private function same_image_family( $a, $b ) {
		$fa = $this->to_full_size_url( $a );
		$fb = $this->to_full_size_url( $b );
		if ( $fa && $fb ) {
			return $this->normalize_url( $fa ) === $this->normalize_url( $fb );
		}
		return false;
	}

	/**
	 * Normalize for comparison.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = strtok( (string) $url, '?' );
		return untrailingslashit( $url );
	}

	/**
	 * Apply fixes for a post type.
	 *
	 * @param string $post_type post|page.
	 * @return array
	 */
	public function run_fix( $post_type = 'post' ) {
		$post_type = ( 'page' === $post_type ) ? 'page' : 'post';
		$key       = 'rd3_pic_link_scan_' . $post_type;
		$report    = get_transient( $key );
		if ( empty( $report ) || empty( $report['items'] ) || ( $report['post_type'] ?? '' ) !== $post_type ) {
			$report = $this->scan( $post_type );
			set_transient( $key, $report, DAY_IN_SECONDS );
		}

		$stats = array(
			'posts_touched' => 0,
			'links_fixed'   => 0,
			'errors'        => 0,
		);

		$by_post = array();
		foreach ( $report['items'] as $item ) {
			$by_post[ (int) $item['post_id'] ][] = $item;
		}

		foreach ( $by_post as $post_id => $items ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== $post_type ) {
				continue;
			}

			$content     = $post->post_content;
			$new_content = $this->fix_content( $content );

			if ( $new_content === $content ) {
				continue;
			}

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
						'action'     => 'fix-full-link-' . $post_type,
						'result'     => 'failed',
						'error'      => $result->get_error_message(),
					)
				);
				continue;
			}

			++$stats['posts_touched'];
			$stats['links_fixed'] += count( $items );

			Logger::log(
				array(
					'post_id'    => $post_id,
					'post_title' => $post->post_title,
					'action'     => 'fix-full-link-' . $post_type,
					'result'     => 'ok',
					'error'      => sprintf( '%d image link(s) → full original', count( $items ) ),
				)
			);
		}

		delete_transient( $key );
		return $stats;
	}

	/**
	 * Rewrite content: sized href → full; wrap unlinked sized imgs in <a href=full>.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function fix_content( $content ) {
		if ( '' === $content ) {
			return $content;
		}

		$content = preg_replace_callback(
			'/(<a\b[^>]*\bhref\s*=\s*["\'])([^"\']+)(["\'])/i',
			function ( $m ) {
				$href = $m[2];
				if ( ! $this->url_has_size_suffix( $href ) ) {
					return $m[0];
				}
				$full = $this->to_full_size_url( $href );
				if ( ! $full ) {
					return $m[0];
				}
				return $m[1] . $full . $m[3];
			},
			$content
		);

		$content = preg_replace_callback(
			'/"href"\s*:\s*"([^"]+)"/',
			function ( $m ) {
				$href = stripcslashes( $m[1] );
				if ( ! $this->url_has_size_suffix( $href ) ) {
					return $m[0];
				}
				$full = $this->to_full_size_url( $href );
				if ( ! $full ) {
					return $m[0];
				}
				return '"href":"' . $full . '"';
			},
			$content
		);

		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $imgs, PREG_OFFSET_CAPTURE ) ) {
			$pieces = $imgs[0];
			for ( $i = count( $pieces ) - 1; $i >= 0; $i-- ) {
				$tag    = $pieces[ $i ][0];
				$offset = $pieces[ $i ][1];
				$len    = strlen( $tag );

				if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $sm ) ) {
					continue;
				}
				$src = $sm[1];
				if ( ! $this->url_has_size_suffix( $src ) ) {
					continue;
				}
				$full = $this->to_full_size_url( $src );
				if ( ! $full ) {
					continue;
				}

				$before = substr( $content, max( 0, $offset - 500 ), min( 500, $offset ) );
				if ( preg_match( '/<a\b[^>]*\bhref\s*=\s*["\'][^"\']+["\'][^>]*>\s*$/i', $before ) ) {
					continue;
				}

				$wrapped = '<a href="' . esc_url( $full ) . '">' . $tag . '</a>';
				$content = substr( $content, 0, $offset ) . $wrapped . substr( $content, $offset + $len );
			}
		}

		return $content;
	}
}
