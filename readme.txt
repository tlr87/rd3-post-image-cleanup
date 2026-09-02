=== RD3 Post Image Cleanup ===
Contributors: rd3
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later

Find exact duplicate images used in WordPress posts (post type only) via SHA-256, replace references with a master, and move duplicate files. Never deletes files.

== Description ==

RD3 Post Image Cleanup is designed for sites that imported Facebook posts or otherwise accumulated many duplicate images attached to posts.

**Scope (strict):**
* Only the `post` post type
* Does not touch pages, custom post types, themes, plugins, logos, WooCommerce, or media not associated with a post

**Stage 1 — Scan (read-only)**
* Scans posts for images in content (Gutenberg blocks + classic HTML) and featured images
* Resolves to original attachment files (ignores intermediate sizes as separate originals)
* Computes SHA-256 of each original file
* Groups exact duplicates
* Proposes a deterministic master (oldest upload date, then lowest attachment ID)
* Produces a detailed admin report under Tools → RD3 Post Image Cleanup

**Stage 2 — Cleanup**
* Replaces duplicate references in post_content (blocks + HTML: src, srcset, href, wp-image-ID) with the master
* Size-aware: prefers an appropriate WordPress-generated size when the original markup used a smaller size
* Updates featured images to the master attachment ID
* Verifies each post no longer references the duplicate
* Moves the complete duplicate image set (original + all derivatives) to `uploads/duplicate-images/` preserving year/month structure
* **Never deletes files**
* Logs every action

== Installation ==

1. Copy the `rd3-post-image-cleanup` folder into `wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Go to Tools → RD3 Post Image Cleanup
4. Click "Scan Posts", review the report, then "Run Cleanup" if appropriate

== Frequently Asked Questions ==

= Does it delete anything? =
No. Duplicates are moved to `wp-content/uploads/duplicate-images/` and remain recoverable.

= Does it work on pages? =
No. Only the `post` post type.

= How are duplicates detected? =
SHA-256 hash of the original image file. Filenames and intermediate sizes are ignored for identity.

== Changelog ==

= 0.2.0 =
* Stage 2: replace references (content + featured), verify, move duplicate sets
* Size-aware URL replacement
* Full cleanup log
* Confirmation dialog before cleanup

= 0.1.0 =
* Initial read-only scanner and admin report.
