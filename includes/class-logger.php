<?php
/**
 * Simple cleanup log (prepared for version 0.2).
 *
 * @package RD3\PostImageCleanup
 */

namespace RD3\PostImageCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
class Logger {

	const OPTION = 'rd3_pic_cleanup_log';
	const MAX_ENTRIES = 500;

	/**
	 * Append a log entry.
	 *
	 * @param array $entry Entry data.
	 */
	public static function log( array $entry ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$entry = array_merge(
			array(
				'datetime'        => current_time( 'mysql' ),
				'post_id'         => 0,
				'post_title'      => '',
				'old_attachment'  => 0,
				'old_filename'    => '',
				'old_url'         => '',
				'new_attachment'  => 0,
				'new_filename'    => '',
				'new_url'         => '',
				'sha256'          => '',
				'action'          => '',
				'result'          => '',
				'error'           => '',
			),
			$entry
		);

		$log[] = $entry;

		// Cap size.
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Get log entries.
	 *
	 * @return array
	 */
	public static function get() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}