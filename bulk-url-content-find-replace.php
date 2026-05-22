<?php
/**
 * Plugin Name:       Bulk URL Content Find & Replace
 * Plugin URI:        https://ankurpatel.in/
 * Description:       Bulk find and replace exact text inside post, page, and custom post type content by providing a list of URLs or paths. Includes a safe dry-run preview, detailed results dashboard, and CSV export.
 * Version:           1.0.3
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Ankur Patel
 * Author URI:        https://ankurpatel.in/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bulk-url-content-find-replace
 * Domain Path:       /languages
 *
 * @package BulkUrlContentFindReplace
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'BUCFR_VERSION' ) ) {
	define( 'BUCFR_VERSION', '1.0.3' );
}

if ( ! defined( 'BUCFR_PLUGIN_FILE' ) ) {
	define( 'BUCFR_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'BUCFR_PLUGIN_DIR' ) ) {
	define( 'BUCFR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'BUCFR_PLUGIN_URL' ) ) {
	define( 'BUCFR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'BUCFR_PLUGIN_BASENAME' ) ) {
	define( 'BUCFR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'BUCFR_TEXT_DOMAIN' ) ) {
	define( 'BUCFR_TEXT_DOMAIN', 'bulk-url-content-find-replace' );
}

// Load core class files.
require_once BUCFR_PLUGIN_DIR . 'includes/class-helper.php';
require_once BUCFR_PLUGIN_DIR . 'includes/class-replacer.php';
require_once BUCFR_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once BUCFR_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Activation callback. Performs minimum-version and capability sanity checks.
 *
 * @return void
 */
function bucfr_activate_plugin() {
	if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
		deactivate_plugins( BUCFR_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Bulk URL Content Find & Replace requires PHP 7.2 or higher. Please upgrade PHP before activating this plugin.', 'bulk-url-content-find-replace' ),
			esc_html__( 'Plugin activation failed', 'bulk-url-content-find-replace' ),
			array( 'back_link' => true )
		);
	}

	global $wp_version;
	if ( version_compare( $wp_version, '5.6', '<' ) ) {
		deactivate_plugins( BUCFR_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Bulk URL Content Find & Replace requires WordPress 5.6 or higher.', 'bulk-url-content-find-replace' ),
			esc_html__( 'Plugin activation failed', 'bulk-url-content-find-replace' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'bucfr_activate_plugin' );

/**
 * Bootstrap the plugin once all plugins are loaded.
 *
 * @return void
 */
function bucfr_bootstrap() {
	\BUCFR\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'bucfr_bootstrap' );
