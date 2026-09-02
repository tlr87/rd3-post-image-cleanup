<?php
/**
 * Plugin Name: RD3 Post Image Cleanup
 * Plugin URI:  https://rd3.local/
 * Description: Find exact duplicate images used in WordPress posts (post type only) via SHA-256, replace references with a master image, and move duplicate files to uploads/duplicate-images/. Never deletes files.
 * Version:     0.3.2
 * Author:      RD3
 * Author URI:  https://rd3.local/
 * License:     GPL-2.0-or-later
 * Text Domain: rd3-post-image-cleanup
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'RD3_PIC_VERSION', '0.3.2' );
define( 'RD3_PIC_PLUGIN_FILE', __FILE__ );
define( 'RD3_PIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RD3_PIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RD3_PIC_DUPLICATE_DIR', 'duplicate-images' );

/**
 * Autoload plugin classes.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'RD3\\PostImageCleanup\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = strtolower( str_replace( array( $prefix, '_' ), array( '', '-' ), $class ) );
		$file     = RD3_PIC_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Bootstrap the plugin.
 */
function rd3_pic_init() {
	if ( is_admin() ) {
		$admin = new \RD3\PostImageCleanup\Admin();
		$admin->hooks();
	}
}
add_action( 'plugins_loaded', 'rd3_pic_init' );

/**
 * Activation.
 */
function rd3_pic_activate() {
	update_option( 'rd3_pic_version', RD3_PIC_VERSION );
}
register_activation_hook( __FILE__, 'rd3_pic_activate' );