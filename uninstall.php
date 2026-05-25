<?php
/**
 * Uninstall handler for Replacely – Bulk Content Find & Replace by URLs.
 *
 * Runs only when the plugin is deleted through the WordPress admin. It removes
 * every database footprint the plugin creates:
 *
 *   1. The persistent activity-log option (`replacely_activity_log`).
 *   2. The per-user result/state transients (`replacely_last_state_*`,
 *      `replacely_last_results_*`), including the orphaned rows left behind by
 *      users that have since been deleted.
 *
 * The actual post-content changes made by the tool are deliberately left in
 * place — those are real edits to the site, not plugin metadata.
 *
 * Note: the plugin classes are not loaded during uninstall, so the option and
 * transient key names are duplicated here as literals. They mirror the
 * constants/keys in includes/class-helper.php and includes/class-admin-page.php
 * and must be kept in sync if those ever change.
 *
 * @package Replacely
 */

// Exit if this file is accessed directly rather than through WP's uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin data from the current site.
 *
 * @return void
 */
function replacely_uninstall_cleanup() {
	global $wpdb;

	// 1. The persistent, non-autoloaded activity-log option.
	delete_option( 'replacely_activity_log' );

	// 2. Per-user transients. Delete through the API so an external object cache
	// (Redis/Memcached) — where the values may live instead of the DB — is
	// cleared as well, not just the options table.
	$user_ids = get_users( array( 'fields' => 'ID' ) );
	foreach ( $user_ids as $user_id ) {
		delete_transient( 'replacely_last_state_' . $user_id );
		delete_transient( 'replacely_last_results_' . $user_id );
	}

	// 3. Safety net: sweep any orphaned transient rows still in the options
	// table (e.g. from users deleted before this uninstall). Each transient is
	// two rows — the value and its `_timeout_` companion.
	$patterns = array(
		$wpdb->esc_like( '_transient_replacely_last_state_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_replacely_last_state_' ) . '%',
		$wpdb->esc_like( '_transient_replacely_last_results_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_replacely_last_results_' ) . '%',
	);

	foreach ( $patterns as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);
	}
}

// On multisite, clean every site in the network; otherwise just this site.
if ( is_multisite() ) {
	$replacely_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $replacely_site_ids as $replacely_site_id ) {
		switch_to_blog( $replacely_site_id );
		replacely_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	replacely_uninstall_cleanup();
}
