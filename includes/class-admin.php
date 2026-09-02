<?php
/**
 * Admin UI and AJAX handlers for RD3 Post Image Cleanup.
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Capability required.
	 *
	 * @var string
	 */
	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_rd3_pic_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_rd3_pic_clear_results', array( $this, 'ajax_clear_results' ) );
		add_action( 'wp_ajax_rd3_pic_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_rd3_pic_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_rd3_pic_scan_large', array( $this, 'ajax_scan_large' ) );
		add_action( 'wp_ajax_rd3_pic_downsize', array( $this, 'ajax_downsize' ) );
		add_action( 'wp_ajax_rd3_pic_scan_named', array( $this, 'ajax_scan_named' ) );
		add_action( 'wp_ajax_rd3_pic_downsize_named', array( $this, 'ajax_downsize_named' ) );
		add_action( 'wp_ajax_rd3_pic_merge_preview', array( $this, 'ajax_merge_preview' ) );
		add_action( 'wp_ajax_rd3_pic_merge_run', array( $this, 'ajax_merge_run' ) );
		add_action( 'wp_ajax_rd3_pic_scan_links', array( $this, 'ajax_scan_links' ) );
		add_action( 'wp_ajax_rd3_pic_fix_links', array( $this, 'ajax_fix_links' ) );
	}

	/**
	 * Add Tools submenu.
	 */
	public function add_menu() {
		add_management_page(
			__( 'RD3 Post Image Cleanup', 'rd3-post-image-cleanup' ),
			__( 'RD3 Post Image Cleanup', 'rd3-post-image-cleanup' ),
			self::CAP,
			'rd3-post-image-cleanup',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_rd3-post-image-cleanup' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'rd3-pic-admin',
			RD3_PIC_PLUGIN_URL . 'assets/admin.css',
			array(),
			RD3_PIC_VERSION
		);

		wp_enqueue_script(
			'rd3-pic-admin',
			RD3_PIC_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			RD3_PIC_VERSION,
			true
		);

		wp_localize_script(
			'rd3-pic-admin',
			'rd3Pic',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rd3_pic_admin' ),
				'i18n'    => array(
					'scanning'         => __( 'Scanning posts… this may take a while.', 'rd3-post-image-cleanup' ),
					'scanDone'         => __( 'Scan complete.', 'rd3-post-image-cleanup' ),
					'scanError'        => __( 'Scan failed.', 'rd3-post-image-cleanup' ),
					'confirmClear'     => __( 'Clear stored scan results?', 'rd3-post-image-cleanup' ),
					'confirmCleanup'   => __( "This will update post image references and move duplicate files.\nNo files will be deleted.\n\nContinue?", 'rd3-post-image-cleanup' ),
					'cleaning'         => __( 'Running cleanup… please wait.', 'rd3-post-image-cleanup' ),
					'cleanupDone'      => __( 'Cleanup complete.', 'rd3-post-image-cleanup' ),
					'cleanupError'     => __( 'Cleanup failed.', 'rd3-post-image-cleanup' ),
					'confirmClearLog'  => __( 'Clear the cleanup log?', 'rd3-post-image-cleanup' ),
					'scanningLarge'    => __( 'Scanning for large full-size images in posts…', 'rd3-post-image-cleanup' ),
					'scanLargeDone'    => __( 'Large-image scan complete.', 'rd3-post-image-cleanup' ),
					'confirmDownsize'  => __( "This will rewrite large full-size images in posts to a ~768px display size.\nClick-through links will still open the full original.\nNo files will be deleted.\n\nContinue?", 'rd3-post-image-cleanup' ),
					'downsizing'       => __( 'Downsizing large images…', 'rd3-post-image-cleanup' ),
					'downsizeDone'     => __( 'Downsize complete.', 'rd3-post-image-cleanup' ),
					'downsizeError'    => __( 'Downsize failed.', 'rd3-post-image-cleanup' ),
					'namedNeedName'    => __( 'Enter an image filename first (e.g. 887197071069906.jpg).', 'rd3-post-image-cleanup' ),
					'namedScanning'    => __( 'Scanning posts for that image…', 'rd3-post-image-cleanup' ),
					'namedScanDone'    => __( 'Named-image scan complete.', 'rd3-post-image-cleanup' ),
					'namedConfirm'     => __( "Downsize this image in all matching posts?\nDisplay will use ~768px; links keep the full original.\nNo files will be deleted.", 'rd3-post-image-cleanup' ),
					'namedWorking'     => __( 'Replacing large image in posts…', 'rd3-post-image-cleanup' ),
					'namedDone'        => __( 'Named image update complete.', 'rd3-post-image-cleanup' ),
					'mergeNeedNames'   => __( 'Enter both image filenames (keep and remove).', 'rd3-post-image-cleanup' ),
					'mergePreviewing'  => __( 'Looking up both images and posts…', 'rd3-post-image-cleanup' ),
					'mergePreviewDone' => __( 'Preview ready. Review, then confirm merge.', 'rd3-post-image-cleanup' ),
					'mergeConfirm'     => __( "This will:\n• Update posts to use the KEEP image\n• Move the REMOVE image (and all its sizes) to uploads/duplicate-images/\n• Never delete files\n\nContinue?", 'rd3-post-image-cleanup' ),
					'mergeWorking'     => __( 'Merging… updating posts and moving files…', 'rd3-post-image-cleanup' ),
					'mergeDone'        => __( 'Merge complete.', 'rd3-post-image-cleanup' ),
					'mergeError'       => __( 'Merge failed.', 'rd3-post-image-cleanup' ),
					'linkScanning'     => __( 'Scanning for sized images that should link to the full original…', 'rd3-post-image-cleanup' ),
					'linkScanDone'     => __( 'Link scan complete.', 'rd3-post-image-cleanup' ),
					'linkConfirm'      => __( "Update click links so sized images open the full original file?\nDisplayed image size is NOT changed.\nNo files will be deleted.\n\nContinue?", 'rd3-post-image-cleanup' ),
					'linkWorking'      => __( 'Fixing image links to full originals…', 'rd3-post-image-cleanup' ),
					'linkDone'         => __( 'Image link fix complete.', 'rd3-post-image-cleanup' ),
					'linkError'        => __( 'Image link fix failed.', 'rd3-post-image-cleanup' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rd3-post-image-cleanup' ) );
		}

		$results     = get_transient( 'rd3_pic_scan_results' );
		$log         = Logger::get();
		$has_results = ! empty( $results ) && ! empty( $results['groups'] );

		?>
		<div class="wrap rd3-pic-wrap">
			<h1><?php echo esc_html__( 'RD3 Post Image Cleanup', 'rd3-post-image-cleanup' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Version 0.3 — Visual duplicate review (SHA-256), optional cleanup, and a separate large-image downsize tool. Posts only. Files are never deleted.', 'rd3-post-image-cleanup' ); ?>
			</p>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Stage 1 — Scan / Dry Run', 'rd3-post-image-cleanup' ); ?></h2>
				<p><?php echo esc_html__( 'This scan is completely read-only. It will not edit posts, move files, or delete anything.', 'rd3-post-image-cleanup' ); ?></p>
				<p>
					<button type="button" id="rd3-pic-scan-btn" class="button button-primary">
						<?php echo esc_html__( 'Scan Posts', 'rd3-post-image-cleanup' ); ?>
					</button>
					<button type="button" id="rd3-pic-clear-btn" class="button">
						<?php echo esc_html__( 'Clear Results', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-scan-status" class="rd3-pic-status" aria-live="polite"></div>
			</div>

			<div class="rd3-pic-card" id="rd3-pic-results-card" <?php echo empty( $results ) ? 'style="display:none;"' : ''; ?>>
				<h2><?php echo esc_html__( 'Scan Results', 'rd3-post-image-cleanup' ); ?></h2>
				<div id="rd3-pic-results">
					<?php
					if ( ! empty( $results ) ) {
						$this->render_results( $results );
					}
					?>
				</div>
			</div>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Large images in posts (separate tool)', 'rd3-post-image-cleanup' ); ?></h2>
				<p>
					<?php echo esc_html__( 'Finds full-size images embedded in posts (typically Facebook imports) and shows them so you can review. Then optionally rewrites the display src to ~768px while the click link stays on the full original.', 'rd3-post-image-cleanup' ); ?>
				</p>
				<p>
					<button type="button" id="rd3-pic-scan-large-btn" class="button button-primary">
						<?php echo esc_html__( 'Scan Large Images', 'rd3-post-image-cleanup' ); ?>
					</button>
					<button type="button" id="rd3-pic-downsize-btn" class="button" disabled>
						<?php echo esc_html__( 'Downsize Large Images', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-large-status" class="rd3-pic-status" aria-live="polite"></div>
				<div id="rd3-pic-large-results" style="display:none;"></div>
			</div>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Merge two duplicate images (manual)', 'rd3-post-image-cleanup' ); ?></h2>
				<p>
					<?php echo esc_html__( 'Enter two filenames that are the same picture. Choose which one to KEEP. The other (and all its WordPress sizes) is moved to uploads/duplicate-images/. Every post using the removed file is updated to the kept image. Files are never deleted.', 'rd3-post-image-cleanup' ); ?>
				</p>
				<p>
					<label for="rd3-pic-merge-keep"><strong><?php echo esc_html__( 'KEEP (master) filename', 'rd3-post-image-cleanup' ); ?></strong></label><br />
					<input type="text" id="rd3-pic-merge-keep" class="regular-text" placeholder="887197071069906.jpg" />
				</p>
				<p>
					<label for="rd3-pic-merge-remove"><strong><?php echo esc_html__( 'REMOVE (duplicate) filename', 'rd3-post-image-cleanup' ); ?></strong></label><br />
					<input type="text" id="rd3-pic-merge-remove" class="regular-text" placeholder="734424723013809.jpg" />
				</p>
				<p>
					<button type="button" id="rd3-pic-merge-preview-btn" class="button button-primary">
						<?php echo esc_html__( 'Preview Merge', 'rd3-post-image-cleanup' ); ?>
					</button>
					<button type="button" id="rd3-pic-merge-run-btn" class="button" disabled>
						<?php echo esc_html__( 'Run Merge', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-merge-status" class="rd3-pic-status" aria-live="polite"></div>
				<div id="rd3-pic-merge-results" style="display:none;"></div>
			</div>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Link sized images to full original', 'rd3-post-image-cleanup' ); ?></h2>
				<p>
					<?php echo esc_html__( 'When a post/page shows a sized file (e.g. 104300966026191-768x512.jpg), the click link should open the full file (104300966026191.jpg). Display size is left unchanged. Run posts and pages separately.', 'rd3-post-image-cleanup' ); ?>
				</p>
				<p>
					<strong><?php echo esc_html__( 'Posts', 'rd3-post-image-cleanup' ); ?></strong>
					<button type="button" id="rd3-pic-scan-links-posts-btn" class="button button-primary" data-type="post">
						<?php echo esc_html__( 'Scan Posts', 'rd3-post-image-cleanup' ); ?>
					</button>
					<button type="button" id="rd3-pic-fix-links-posts-btn" class="button" data-type="post" disabled>
						<?php echo esc_html__( 'Fix Post Links', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<p>
					<strong><?php echo esc_html__( 'Pages', 'rd3-post-image-cleanup' ); ?></strong>
					<button type="button" id="rd3-pic-scan-links-pages-btn" class="button button-primary" data-type="page">
						<?php echo esc_html__( 'Scan Pages', 'rd3-post-image-cleanup' ); ?>
					</button>
					<button type="button" id="rd3-pic-fix-links-pages-btn" class="button" data-type="page" disabled>
						<?php echo esc_html__( 'Fix Page Links', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-links-status" class="rd3-pic-status" aria-live="polite"></div>
				<div id="rd3-pic-links-results" style="display:none;"></div>
			</div>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Target one image by filename', 'rd3-post-image-cleanup' ); ?></h2>
				<p>
					<?php echo esc_html__( 'Enter the exact image filename (as in the media library). Scan finds which posts use it. Then replace large full-size embeds with a ~768px display image, while the click link still opens the full original.', 'rd3-post-image-cleanup' ); ?>
				</p>
				<p>
					<label for="rd3-pic-named-input">
						<strong><?php echo esc_html__( 'Filename', 'rd3-post-image-cleanup' ); ?></strong>
					</label>
					<input type="text" id="rd3-pic-named-input" class="regular-text" placeholder="887197071069906.jpg" />
				</p>
				<p>
					<button type="button" id="rd3-pic-scan-named-btn" class="button button-primary">
						<?php echo esc_html__( 'Scan This Image', 'rd3-post-image-cleanup' ); ?>
					</button>
					<button type="button" id="rd3-pic-downsize-named-btn" class="button" disabled>
						<?php echo esc_html__( 'Downsize &amp; Link Full', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-named-status" class="rd3-pic-status" aria-live="polite"></div>
				<div id="rd3-pic-named-results" style="display:none;"></div>
			</div>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Stage 2 — Duplicate Cleanup', 'rd3-post-image-cleanup' ); ?></h2>
				<p>
					<?php echo esc_html__( 'For every duplicate group from the latest scan: update post content and featured images to use the MASTER, verify the change, then move the duplicate image set (original + all WordPress sizes) into', 'rd3-post-image-cleanup' ); ?>
					<code>wp-content/uploads/duplicate-images/</code>.
				</p>
				<p><strong><?php echo esc_html__( 'No files will be deleted.', 'rd3-post-image-cleanup' ); ?></strong></p>
				<p>
					<button type="button" id="rd3-pic-cleanup-btn" class="button button-primary" <?php echo $has_results ? '' : 'disabled'; ?>>
						<?php echo esc_html__( 'Run Cleanup', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-cleanup-status" class="rd3-pic-status" aria-live="polite"></div>
				<div id="rd3-pic-cleanup-summary" style="display:none;"></div>
			</div>

			<div class="rd3-pic-card">
				<h2><?php echo esc_html__( 'Cleanup Log', 'rd3-post-image-cleanup' ); ?></h2>
				<p>
					<button type="button" id="rd3-pic-clear-log-btn" class="button">
						<?php echo esc_html__( 'Clear Log', 'rd3-post-image-cleanup' ); ?>
					</button>
				</p>
				<div id="rd3-pic-log">
					<?php $this->render_log( $log ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render scan results HTML.
	 *
	 * @param array $results Scan results.
	 */
	public function render_results( array $results ) {
		$summary = $results['summary'] ?? array();
		$groups  = $results['groups'] ?? array();
		?>
		<div class="rd3-pic-summary">
			<ul>
				<li><strong><?php echo esc_html__( 'Posts scanned:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $summary['posts_scanned'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Images found:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $summary['images_found'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Unique original images:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $summary['unique_images'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Duplicate groups:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $summary['duplicate_groups'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Duplicate files:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $summary['duplicate_files'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Posts affected:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $summary['posts_affected'] ?? 0 ) ); ?></li>
			</ul>
			<?php if ( ! empty( $results['scanned_at'] ) ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Scanned at: %s', 'rd3-post-image-cleanup' ), $results['scanned_at'] ) ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( empty( $groups ) ) : ?>
			<p><em><?php echo esc_html__( 'No exact duplicate original images found among posts.', 'rd3-post-image-cleanup' ); ?></em></p>
			<?php
			return;
		endif;

		$group_num = 0;
		foreach ( $groups as $hash => $group ) {
			++$group_num;
			$master     = $group['master'] ?? array();
			$duplicates = $group['duplicates'] ?? array();
			$posts      = $group['posts'] ?? array();
			?>
			<div class="rd3-pic-group">
				<h3><?php echo esc_html( sprintf( __( 'Duplicate Group #%d', 'rd3-post-image-cleanup' ), $group_num ) ); ?></h3>
				<p><strong><?php echo esc_html__( 'SHA-256:', 'rd3-post-image-cleanup' ); ?></strong> <code><?php echo esc_html( $hash ); ?></code></p>
				<p><strong><?php echo esc_html__( 'Copies:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( 1 + count( $duplicates ) ) ); ?></p>

				<div class="rd3-pic-master">
					<h4><?php echo esc_html__( 'MASTER (proposed) — look at the image', 'rd3-post-image-cleanup' ); ?></h4>
					<?php
					$master_id = (int) ( $master['attachment_id'] ?? 0 );
					$master_img = $master_id ? wp_get_attachment_image_url( $master_id, 'medium' ) : '';
					if ( ! $master_img ) {
						$master_img = $master['url'] ?? '';
					}
					if ( $master_img ) :
						?>
						<p class="rd3-pic-preview">
							<a href="<?php echo esc_url( $master['url'] ?? $master_img ); ?>" target="_blank" rel="noopener">
								<img src="<?php echo esc_url( $master_img ); ?>" alt="" />
							</a>
						</p>
					<?php endif; ?>
					<table class="widefat">
						<tbody>
							<tr><th><?php echo esc_html__( 'Attachment ID', 'rd3-post-image-cleanup' ); ?></th><td><?php echo esc_html( (string) ( $master['attachment_id'] ?? '' ) ); ?></td></tr>
							<tr><th><?php echo esc_html__( 'Filename', 'rd3-post-image-cleanup' ); ?></th><td><?php echo esc_html( $master['filename'] ?? '' ); ?></td></tr>
							<tr><th><?php echo esc_html__( 'File path', 'rd3-post-image-cleanup' ); ?></th><td><code><?php echo esc_html( $master['path'] ?? '' ); ?></code></td></tr>
							<tr><th><?php echo esc_html__( 'File size', 'rd3-post-image-cleanup' ); ?></th><td><?php echo esc_html( size_format( $master['filesize'] ?? 0 ) ); ?></td></tr>
							<tr><th><?php echo esc_html__( 'Dimensions', 'rd3-post-image-cleanup' ); ?></th><td><?php echo esc_html( ( $master['width'] ?? '?' ) . ' × ' . ( $master['height'] ?? '?' ) ); ?></td></tr>
							<tr><th><?php echo esc_html__( 'Upload date', 'rd3-post-image-cleanup' ); ?></th><td><?php echo esc_html( $master['upload_date'] ?? '' ); ?></td></tr>
							<tr><th><?php echo esc_html__( 'Posts using it', 'rd3-post-image-cleanup' ); ?></th><td><?php echo esc_html( (string) ( $master['post_count'] ?? 0 ) ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<?php if ( ! empty( $duplicates ) ) : ?>
					<div class="rd3-pic-duplicates">
						<h4><?php echo esc_html__( 'DUPLICATES', 'rd3-post-image-cleanup' ); ?></h4>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Preview', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Attachment ID', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Filename', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'File size', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Dimensions', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Posts using it', 'rd3-post-image-cleanup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $duplicates as $dup ) : ?>
									<?php
									$dup_id  = (int) ( $dup['attachment_id'] ?? 0 );
									$dup_img = $dup_id ? wp_get_attachment_image_url( $dup_id, 'thumbnail' ) : '';
									if ( ! $dup_img ) {
										$dup_img = $dup['url'] ?? '';
									}
									?>
									<tr>
										<td class="rd3-pic-td-preview">
											<?php if ( $dup_img ) : ?>
												<a href="<?php echo esc_url( $dup['url'] ?? $dup_img ); ?>" target="_blank" rel="noopener">
													<img src="<?php echo esc_url( $dup_img ); ?>" alt="" />
												</a>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( (string) $dup_id ); ?></td>
										<td><?php echo esc_html( $dup['filename'] ?? '' ); ?></td>
										<td><?php echo esc_html( size_format( $dup['filesize'] ?? 0 ) ); ?></td>
										<td><?php echo esc_html( ( $dup['width'] ?? '?' ) . ' × ' . ( $dup['height'] ?? '?' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $dup['post_count'] ?? 0 ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $posts ) ) : ?>
					<div class="rd3-pic-posts">
						<h4><?php echo esc_html__( 'POSTS USING THIS GROUP', 'rd3-post-image-cleanup' ); ?></h4>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Post ID', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Post title', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Role', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Attachment ID', 'rd3-post-image-cleanup' ); ?></th>
									<th><?php echo esc_html__( 'Current image URL', 'rd3-post-image-cleanup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $posts as $p ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $p['post_id'] ?? '' ) ); ?></td>
										<td>
											<a href="<?php echo esc_url( get_edit_post_link( $p['post_id'] ?? 0 ) ); ?>" target="_blank" rel="noopener">
												<?php echo esc_html( $p['post_title'] ?? '' ); ?>
											</a>
										</td>
										<td><?php echo esc_html( $p['role'] ?? '' ); ?></td>
										<td><?php echo esc_html( (string) ( $p['attachment_id'] ?? '' ) ); ?></td>
										<td><code class="rd3-pic-url"><?php echo esc_html( $p['url'] ?? '' ); ?></code></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Render cleanup log table.
	 *
	 * @param array $log Log entries.
	 */
	public function render_log( array $log ) {
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No cleanup actions yet.', 'rd3-post-image-cleanup' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped rd3-pic-log-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Date/Time', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Post', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Old → New', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Action', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Result', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Error', 'rd3-post-image-cleanup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $log ) as $entry ) : ?>
					<tr class="<?php echo ( 'failed' === ( $entry['result'] ?? '' ) || 'skipped' === ( $entry['result'] ?? '' ) ) ? 'rd3-pic-row-warn' : ''; ?>">
						<td><?php echo esc_html( $entry['datetime'] ?? '' ); ?></td>
						<td>
							<?php
							$pid = $entry['post_id'] ?? 0;
							if ( $pid ) {
								echo esc_html( $pid . ' — ' . ( $entry['post_title'] ?? '' ) );
							} else {
								echo '—';
							}
							?>
						</td>
						<td>
							<?php
							$old = $entry['old_attachment'] ?? '';
							$new = $entry['new_attachment'] ?? '';
							if ( $old || $new ) {
								echo esc_html( $old . ( $new ? ' → ' . $new : '' ) );
							} else {
								echo '—';
							}
							?>
						</td>
						<td><?php echo esc_html( $entry['action'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['result'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['error'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * AJAX: run scan.
	 */
	public function ajax_scan() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		try {
			$scanner  = new Scanner();
			$detector = new Duplicate_Detector();
			$raw      = $scanner->scan();
			$results  = $detector->process( $raw );

			set_transient( 'rd3_pic_scan_results', $results, DAY_IN_SECONDS );

			ob_start();
			$this->render_results( $results );
			$html = ob_get_clean();

			$has_groups = ! empty( $results['groups'] );

			wp_send_json_success(
				array(
					'message'    => __( 'Scan complete.', 'rd3-post-image-cleanup' ),
					'html'       => $html,
					'summary'    => $results['summary'] ?? array(),
					'has_groups' => $has_groups,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: clear stored results.
	 */
	public function ajax_clear_results() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		delete_transient( 'rd3_pic_scan_results' );
		wp_send_json_success( array( 'message' => __( 'Results cleared.', 'rd3-post-image-cleanup' ) ) );
	}

	/**
	 * AJAX: run cleanup.
	 */
	public function ajax_cleanup() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 600 );

		try {
			$runner = new Cleanup_Runner();
			$result = $runner->run();

			ob_start();
			$this->render_log( Logger::get() );
			$log_html = ob_get_clean();

			if ( empty( $result['success'] ) ) {
				wp_send_json_error(
					array(
						'message' => $result['message'] ?? __( 'Cleanup failed.', 'rd3-post-image-cleanup' ),
						'logHtml' => $log_html,
					)
				);
			}

			$stats = $result['stats'] ?? array();
			$summary_html = sprintf(
				'<ul class="rd3-pic-cleanup-stats"><li>%s</li><li>%s</li><li>%s</li><li>%s</li><li>%s</li><li>%s</li></ul>',
				esc_html( sprintf( __( 'Groups processed: %d', 'rd3-post-image-cleanup' ), $stats['groups_processed'] ?? 0 ) ),
				esc_html( sprintf( __( 'Posts updated: %d', 'rd3-post-image-cleanup' ), $stats['posts_updated'] ?? 0 ) ),
				esc_html( sprintf( __( 'Featured images updated: %d', 'rd3-post-image-cleanup' ), $stats['featured_updated'] ?? 0 ) ),
				esc_html( sprintf( __( 'Duplicates moved: %d', 'rd3-post-image-cleanup' ), $stats['duplicates_moved'] ?? 0 ) ),
				esc_html( sprintf( __( 'Duplicates skipped: %d', 'rd3-post-image-cleanup' ), $stats['duplicates_skipped'] ?? 0 ) ),
				esc_html( sprintf( __( 'Errors: %d', 'rd3-post-image-cleanup' ), $stats['errors'] ?? 0 ) )
			);

			wp_send_json_success(
				array(
					'message'     => $result['message'] ?? __( 'Cleanup complete.', 'rd3-post-image-cleanup' ),
					'stats'       => $stats,
					'summaryHtml' => $summary_html,
					'logHtml'     => $log_html,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: clear cleanup log.
	 */
	public function ajax_clear_log() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		Logger::clear();
		ob_start();
		$this->render_log( array() );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'message' => __( 'Log cleared.', 'rd3-post-image-cleanup' ),
				'logHtml' => $html,
			)
		);
	}

	/**
	 * AJAX: scan for large full-size images in posts.
	 */
	public function ajax_scan_large() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		try {
			$downsizer = new Image_Downsizer();
			$report    = $downsizer->scan_large();
			set_transient( 'rd3_pic_large_scan', $report, DAY_IN_SECONDS );

			ob_start();
			$this->render_large_results( $report );
			$html = ob_get_clean();

			wp_send_json_success(
				array(
					'message'    => __( 'Large-image scan complete.', 'rd3-post-image-cleanup' ),
					'html'       => $html,
					'largeCount' => (int) ( $report['large_count'] ?? 0 ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: downsize large images in posts.
	 */
	public function ajax_downsize() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 600 );

		try {
			$downsizer = new Image_Downsizer();
			$stats     = $downsizer->run_downsize();

			ob_start();
			$this->render_log( Logger::get() );
			$log_html = ob_get_clean();

			$summary = sprintf(
				'<ul class="rd3-pic-cleanup-stats"><li>%s</li><li>%s</li><li>%s</li></ul>',
				esc_html( sprintf( __( 'Posts updated: %d', 'rd3-post-image-cleanup' ), $stats['posts_touched'] ?? 0 ) ),
				esc_html( sprintf( __( 'Image refs rewritten: %d', 'rd3-post-image-cleanup' ), $stats['images_changed'] ?? 0 ) ),
				esc_html( sprintf( __( 'Errors: %d', 'rd3-post-image-cleanup' ), $stats['errors'] ?? 0 ) )
			);

			wp_send_json_success(
				array(
					'message'     => __( 'Downsize complete.', 'rd3-post-image-cleanup' ),
					'summaryHtml' => $summary,
					'logHtml'     => $log_html,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Render large-image scan results with previews.
	 *
	 * @param array $report Report from Image_Downsizer::scan_large().
	 */
	public function render_large_results( array $report ) {
		$items = $report['items'] ?? array();
		?>
		<div class="rd3-pic-summary">
			<ul>
				<li><strong><?php echo esc_html__( 'Posts scanned:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $report['posts_scanned'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Large full-size embeds found:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $report['large_count'] ?? 0 ) ); ?></li>
			</ul>
			<?php if ( ! empty( $report['scanned_at'] ) ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Scanned at: %s', 'rd3-post-image-cleanup' ), $report['scanned_at'] ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( empty( $items ) ) : ?>
			<p><em><?php echo esc_html__( 'No large full-size image embeds found in posts.', 'rd3-post-image-cleanup' ); ?></em></p>
			<?php
			return;
		endif;
		?>
		<p class="description"><?php echo esc_html__( 'Review each image below. Proposed change: display a ~768px version; clicking still opens the full original.', 'rd3-post-image-cleanup' ); ?></p>
		<div class="rd3-pic-large-grid">
			<?php foreach ( $items as $item ) : ?>
				<div class="rd3-pic-large-card">
					<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="" />
					</a>
					<div class="rd3-pic-large-meta">
						<p><strong><?php echo esc_html( $item['filename'] ); ?></strong></p>
						<p>
							<?php
							echo esc_html(
								sprintf(
									'%d × %d · %s',
									(int) $item['width'],
									(int) $item['height'],
									size_format( (int) $item['filesize'] )
								)
							);
							?>
						</p>
						<p>
							<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $item['post_id'] . ' — ' . $item['post_title'] ); ?>
							</a>
						</p>
						<p class="description">
							<strong><?php echo esc_html__( 'shows:', 'rd3-post-image-cleanup' ); ?></strong>
							<code><?php echo esc_html( $item['filename'] ?? '' ); ?></code><br />
							<strong><?php echo esc_html__( 'link →', 'rd3-post-image-cleanup' ); ?></strong>
							<code><?php echo esc_html( $item['full_name'] ?? '' ); ?></code><br />
							<em><?php echo esc_html( $item['reason'] ?? '' ); ?></em>
						</p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}


	/**
	 * AJAX: scan posts for a named image file.
	 */
	public function ajax_scan_named() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		$filename = wp_basename( $filename );
		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Enter an image filename.', 'rd3-post-image-cleanup' ) ) );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		try {
			$downsizer = new Image_Downsizer();
			$report    = $downsizer->scan_by_filename( $filename );
			set_transient( 'rd3_pic_named_scan', $report, HOUR_IN_SECONDS );

			ob_start();
			$this->render_named_results( $report );
			$html = ob_get_clean();

			$can_run = ! empty( $report['attachments'] ) && ! empty( $report['usages'] );

			wp_send_json_success(
				array(
					'message'  => $report['message'] ? $report['message'] : __( 'Named-image scan complete.', 'rd3-post-image-cleanup' ),
					'html'     => $html,
					'canRun'   => $can_run,
					'filename' => $filename,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: downsize a named image across posts.
	 */
	public function ajax_downsize_named() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		$filename = wp_basename( $filename );
		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Enter an image filename.', 'rd3-post-image-cleanup' ) ) );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		try {
			$downsizer = new Image_Downsizer();
			$stats     = $downsizer->downsize_by_filename( $filename );

			ob_start();
			$this->render_log( Logger::get() );
			$log_html = ob_get_clean();

			wp_send_json_success(
				array(
					'message'     => $stats['message'] ?? __( 'Named image update complete.', 'rd3-post-image-cleanup' ),
					'summaryHtml' => '<ul class="rd3-pic-cleanup-stats">'
						. '<li>' . esc_html( sprintf( __( 'Posts updated: %d', 'rd3-post-image-cleanup' ), $stats['posts_touched'] ?? 0 ) ) . '</li>'
						. '<li>' . esc_html( sprintf( __( 'Image refs rewritten: %d', 'rd3-post-image-cleanup' ), $stats['images_changed'] ?? 0 ) ) . '</li>'
						. '<li>' . esc_html( sprintf( __( 'Errors: %d', 'rd3-post-image-cleanup' ), $stats['errors'] ?? 0 ) ) . '</li>'
						. '</ul>',
					'logHtml'     => $log_html,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Render named-image scan results.
	 *
	 * @param array $report Report.
	 */
	public function render_named_results( array $report ) {
		$attachments = $report['attachments'] ?? array();
		$usages      = $report['usages'] ?? array();
		?>
		<div class="rd3-pic-summary">
			<ul>
				<li><strong><?php echo esc_html__( 'Filename:', 'rd3-post-image-cleanup' ); ?></strong> <code><?php echo esc_html( $report['filename'] ?? '' ); ?></code></li>
				<li><strong><?php echo esc_html__( 'Attachments matched:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) count( $attachments ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Posts using it:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $report['posts_count'] ?? 0 ) ); ?></li>
			</ul>
		</div>
		<?php if ( empty( $attachments ) ) : ?>
			<p><em><?php echo esc_html__( 'No media attachment matched that filename.', 'rd3-post-image-cleanup' ); ?></em></p>
			<?php
			return;
		endif;
		?>
		<h4><?php echo esc_html__( 'Matched media', 'rd3-post-image-cleanup' ); ?></h4>
		<div class="rd3-pic-large-grid">
			<?php foreach ( $attachments as $att ) : ?>
				<div class="rd3-pic-large-card">
					<a href="<?php echo esc_url( $att['url'] ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $att['thumb_url'] ); ?>" alt="" />
					</a>
					<div class="rd3-pic-large-meta">
						<p><strong><?php echo esc_html( $att['filename'] ); ?></strong> (#<?php echo esc_html( (string) $att['attachment_id'] ); ?>)</p>
						<p><?php echo esc_html( (int) $att['width'] . ' × ' . (int) $att['height'] . ' · ' . size_format( (int) $att['filesize'] ) ); ?></p>
						<p class="description">
							<strong><?php echo esc_html__( 'src →', 'rd3-post-image-cleanup' ); ?></strong>
							<code><?php echo esc_html( basename( $att['proposed_src'] ) ); ?></code><br />
							<strong><?php echo esc_html__( 'href →', 'rd3-post-image-cleanup' ); ?></strong>
							<code><?php echo esc_html( basename( $att['proposed_href'] ) ); ?></code>
						</p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( empty( $usages ) ) : ?>
			<p><em><?php echo esc_html__( 'This file is in the media library but was not found in any post content or featured image.', 'rd3-post-image-cleanup' ); ?></em></p>
			<?php
			return;
		endif;
		?>
		<h4><?php echo esc_html__( 'Posts using this image', 'rd3-post-image-cleanup' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Preview', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Post', 'rd3-post-image-cleanup' ); ?></th>
					<th><?php echo esc_html__( 'Role', 'rd3-post-image-cleanup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $usages as $u ) : ?>
					<tr>
						<td class="rd3-pic-td-preview">
							<?php if ( ! empty( $u['thumb_url'] ) ) : ?>
								<img src="<?php echo esc_url( $u['thumb_url'] ); ?>" alt="" />
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( $u['edit_link'] ?? get_edit_post_link( $u['post_id'] ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $u['post_id'] . ' — ' . $u['post_title'] ); ?>
							</a>
						</td>
						<td><?php echo esc_html( implode( ', ', $u['roles'] ?? array() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}


	/**
	 * AJAX: preview manual two-image merge.
	 */
	public function ajax_merge_preview() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		$keep   = isset( $_POST['keep'] ) ? sanitize_text_field( wp_unslash( $_POST['keep'] ) ) : '';
		$remove = isset( $_POST['remove'] ) ? sanitize_text_field( wp_unslash( $_POST['remove'] ) ) : '';
		$keep   = wp_basename( $keep );
		$remove = wp_basename( $remove );

		if ( '' === $keep || '' === $remove ) {
			wp_send_json_error( array( 'message' => __( 'Enter both filenames.', 'rd3-post-image-cleanup' ) ) );
		}

		$merger = new Manual_Merger();
		$preview = $merger->preview( $keep, $remove );

		ob_start();
		$this->render_merge_preview( $preview );
		$html = ob_get_clean();

		if ( empty( $preview['ok'] ) ) {
			wp_send_json_error(
				array(
					'message' => $preview['message'] ?? __( 'Preview failed.', 'rd3-post-image-cleanup' ),
					'html'    => $html,
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => $preview['message'],
				'html'    => $html,
				'canRun'  => true,
				'keep'    => $keep,
				'remove'  => $remove,
			)
		);
	}

	/**
	 * AJAX: run manual merge.
	 */
	public function ajax_merge_run() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}

		$keep   = isset( $_POST['keep'] ) ? sanitize_text_field( wp_unslash( $_POST['keep'] ) ) : '';
		$remove = isset( $_POST['remove'] ) ? sanitize_text_field( wp_unslash( $_POST['remove'] ) ) : '';
		$keep   = wp_basename( $keep );
		$remove = wp_basename( $remove );

		if ( '' === $keep || '' === $remove ) {
			wp_send_json_error( array( 'message' => __( 'Enter both filenames.', 'rd3-post-image-cleanup' ) ) );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		$merger = new Manual_Merger();
		$result = $merger->merge( $keep, $remove );

		ob_start();
		$this->render_log( Logger::get() );
		$log_html = ob_get_clean();

		$stats = $result['stats'] ?? array();
		$summary = '<ul class="rd3-pic-cleanup-stats">'
			. '<li>' . esc_html( sprintf( __( 'Posts updated: %d', 'rd3-post-image-cleanup' ), $stats['posts_updated'] ?? 0 ) ) . '</li>'
			. '<li>' . esc_html( sprintf( __( 'Featured images updated: %d', 'rd3-post-image-cleanup' ), $stats['featured_updated'] ?? 0 ) ) . '</li>'
			. '<li>' . esc_html( sprintf( __( 'Files moved: %s', 'rd3-post-image-cleanup' ), ! empty( $stats['moved'] ) ? __( 'yes', 'rd3-post-image-cleanup' ) : __( 'no', 'rd3-post-image-cleanup' ) ) ) . '</li>'
			. '<li>' . esc_html( sprintf( __( 'Errors: %d', 'rd3-post-image-cleanup' ), $stats['errors'] ?? 0 ) ) . '</li>'
			. '</ul>';

		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message'     => $result['message'] ?? __( 'Merge failed.', 'rd3-post-image-cleanup' ),
					'summaryHtml' => $summary,
					'logHtml'     => $log_html,
				)
			);
		}

		wp_send_json_success(
			array(
				'message'     => $result['message'] ?? __( 'Merge complete.', 'rd3-post-image-cleanup' ),
				'summaryHtml' => $summary,
				'logHtml'     => $log_html,
			)
		);
	}

	/**
	 * Render merge preview HTML.
	 *
	 * @param array $preview Preview data.
	 */
	public function render_merge_preview( array $preview ) {
		$keep   = $preview['keep'] ?? null;
		$remove = $preview['remove'] ?? null;
		$posts  = $preview['posts'] ?? array();
		?>
		<?php if ( ! empty( $preview['message'] ) ) : ?>
			<p><strong><?php echo esc_html( $preview['message'] ); ?></strong></p>
		<?php endif; ?>

		<div class="rd3-pic-merge-pair">
			<?php if ( $keep ) : ?>
				<div class="rd3-pic-large-card rd3-pic-keep-card">
					<p class="rd3-pic-badge rd3-pic-badge-keep"><?php echo esc_html__( 'KEEP', 'rd3-post-image-cleanup' ); ?></p>
					<a href="<?php echo esc_url( $keep['url'] ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $keep['thumb_url'] ); ?>" alt="" />
					</a>
					<div class="rd3-pic-large-meta">
						<p><strong><?php echo esc_html( $keep['filename'] ); ?></strong> (#<?php echo esc_html( (string) $keep['attachment_id'] ); ?>)</p>
						<p><?php echo esc_html( (int) $keep['width'] . ' × ' . (int) $keep['height'] . ' · ' . size_format( (int) $keep['filesize'] ) ); ?></p>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $remove ) : ?>
				<div class="rd3-pic-large-card rd3-pic-remove-card">
					<p class="rd3-pic-badge rd3-pic-badge-remove"><?php echo esc_html__( 'REMOVE → duplicate-images/', 'rd3-post-image-cleanup' ); ?></p>
					<a href="<?php echo esc_url( $remove['url'] ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $remove['thumb_url'] ); ?>" alt="" />
					</a>
					<div class="rd3-pic-large-meta">
						<p><strong><?php echo esc_html( $remove['filename'] ); ?></strong> (#<?php echo esc_html( (string) $remove['attachment_id'] ); ?>)</p>
						<p><?php echo esc_html( (int) $remove['width'] . ' × ' . (int) $remove['height'] . ' · ' . size_format( (int) $remove['filesize'] ) ); ?></p>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $posts ) ) : ?>
			<h4><?php echo esc_html__( 'Posts that will be updated (currently use REMOVE image)', 'rd3-post-image-cleanup' ); ?></h4>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Post', 'rd3-post-image-cleanup' ); ?></th>
						<th><?php echo esc_html__( 'Role', 'rd3-post-image-cleanup' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $posts as $p ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $p['edit_link'] ?? get_edit_post_link( $p['post_id'] ) ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $p['post_id'] . ' — ' . $p['post_title'] ); ?>
								</a>
							</td>
							<td><?php echo esc_html( implode( ', ', $p['roles'] ?? array() ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php elseif ( ! empty( $preview['ok'] ) ) : ?>
			<p><em><?php echo esc_html__( 'No posts currently reference the REMOVE image (it may still be moved if you run merge).', 'rd3-post-image-cleanup' ); ?></em></p>
		<?php endif; ?>
		<?php
	}


	/**
	 * AJAX: scan posts/pages for large image links.
	 */
	public function ajax_scan_links() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 300 );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		if ( 'page' !== $post_type ) {
			$post_type = 'post';
		}

		try {
			$fixer  = new Link_Fixer();
			$report = $fixer->scan( $post_type );
			set_transient( 'rd3_pic_link_scan_' . $post_type, $report, DAY_IN_SECONDS );

			ob_start();
			$this->render_link_results( $report );
			$html = ob_get_clean();

			wp_send_json_success(
				array(
					'message'    => __( 'Link scan complete.', 'rd3-post-image-cleanup' ),
					'html'       => $html,
					'issueCount' => (int) ( $report['issue_count'] ?? 0 ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: fix large image links.
	 */
	public function ajax_fix_links() {
		check_ajax_referer( 'rd3_pic_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd3-post-image-cleanup' ) ), 403 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 600 );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		if ( 'page' !== $post_type ) {
			$post_type = 'post';
		}

		try {
			$fixer = new Link_Fixer();
			$stats = $fixer->run_fix( $post_type );

			ob_start();
			$this->render_log( Logger::get() );
			$log_html = ob_get_clean();

			$summary = '<ul class="rd3-pic-cleanup-stats">'
				. '<li>' . esc_html( sprintf( __( 'Posts/pages updated: %d', 'rd3-post-image-cleanup' ), $stats['posts_touched'] ?? 0 ) ) . '</li>'
				. '<li>' . esc_html( sprintf( __( 'Changes applied: %d', 'rd3-post-image-cleanup' ), $stats['changes'] ?? 0 ) ) . '</li>'
				. '<li>' . esc_html( sprintf( __( 'Errors: %d', 'rd3-post-image-cleanup' ), $stats['errors'] ?? 0 ) ) . '</li>'
				. '</ul>';

			wp_send_json_success(
				array(
					'message'     => __( 'Image link fix complete.', 'rd3-post-image-cleanup' ),
					'summaryHtml' => $summary,
					'logHtml'     => $log_html,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Render link-scan results.
	 *
	 * @param array $report Report.
	 */
	public function render_link_results( array $report ) {
		$items = $report['items'] ?? array();
		?>
		<div class="rd3-pic-summary">
			<ul>
				<li><strong><?php echo esc_html__( 'Type:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( $report['post_type'] ?? 'post' ); ?></li>
				<li><strong><?php echo esc_html__( 'Items scanned:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $report['posts_scanned'] ?? 0 ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Links to fix:', 'rd3-post-image-cleanup' ); ?></strong> <?php echo esc_html( (string) ( $report['issue_count'] ?? 0 ) ); ?></li>
			</ul>
		</div>
		<?php if ( empty( $items ) ) : ?>
			<p><em><?php echo esc_html__( 'No large full-size image tags/links found.', 'rd3-post-image-cleanup' ); ?></em></p>
			<?php
			return;
		endif;
		?>
		<p class="description">
			<?php echo esc_html__( 'Shown image stays the sized file. Click link will open the full original (suffix -WxH / -scaled stripped).', 'rd3-post-image-cleanup' ); ?>
		</p>
		<div class="rd3-pic-large-grid">
			<?php foreach ( $items as $item ) : ?>
				<div class="rd3-pic-large-card">
					<a href="<?php echo esc_url( $item['full_url'] ?? $item['src'] ?? '' ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $item['thumb_url'] ?? $item['src'] ?? '' ); ?>" alt="" />
					</a>
					<div class="rd3-pic-large-meta">
						<p><strong><?php echo esc_html( $item['filename'] ?? '' ); ?></strong></p>
						<p>
							<?php
							echo esc_html(
								sprintf(
									'%s · %s',
									$item['post_type'] ?? '',
									$item['reason'] ?? ''
								)
							);
							?>
						</p>
						<p>
							<a href="<?php echo esc_url( $item['edit_link'] ?? get_edit_post_link( $item['post_id'] ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $item['post_id'] . ' — ' . $item['post_title'] ); ?>
							</a>
						</p>
						<p class="description">
							<strong><?php echo esc_html__( 'shows:', 'rd3-post-image-cleanup' ); ?></strong>
							<code><?php echo esc_html( $item['filename'] ?? '' ); ?></code><br />
							<strong><?php echo esc_html__( 'link →', 'rd3-post-image-cleanup' ); ?></strong>
							<code><?php echo esc_html( $item['full_name'] ?? '' ); ?></code><br />
							<em><?php echo esc_html( $item['reason'] ?? '' ); ?></em>
						</p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

}