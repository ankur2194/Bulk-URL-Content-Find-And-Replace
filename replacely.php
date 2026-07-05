<?php
/**
 * Plugin Name:       Replacely – Bulk Content Find & Replace by URLs
 * Description:       Bulk find and replace exact text inside post, page, custom post type, and page-builder content (Elementor, Beaver Builder, Oxygen, Bricks) by providing a list of URLs or paths. Includes a safe dry-run preview, detailed results dashboard, and CSV export.
 * Version:           1.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Ankur Patel
 * Author URI:        https://ankurpatel.in/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       replacely
 * Domain Path:       /languages
 *
 * @package Replacely
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'REPLACELY_VERSION' ) ) {
	define( 'REPLACELY_VERSION', '1.1.0' );
}

if ( ! defined( 'REPLACELY_PLUGIN_FILE' ) ) {
	define( 'REPLACELY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'REPLACELY_PLUGIN_DIR' ) ) {
	define( 'REPLACELY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'REPLACELY_PLUGIN_URL' ) ) {
	define( 'REPLACELY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'REPLACELY_PLUGIN_BASENAME' ) ) {
	define( 'REPLACELY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'REPLACELY_TEXT_DOMAIN' ) ) {
	define( 'REPLACELY_TEXT_DOMAIN', 'replacely' );
}

// Load core class files.
require_once REPLACELY_PLUGIN_DIR . 'includes/class-helper.php';
require_once REPLACELY_PLUGIN_DIR . 'includes/class-replacer.php';
require_once REPLACELY_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once REPLACELY_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Activation callback. Performs minimum-version and capability sanity checks.
 *
 * @return void
 */
function replacely_activate_plugin() {
	if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
		deactivate_plugins( REPLACELY_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Replacely requires PHP 7.2 or higher. Please upgrade PHP before activating this plugin.', 'replacely' ),
			esc_html__( 'Plugin activation failed', 'replacely' ),
			array( 'back_link' => true )
		);
	}

	global $wp_version;
	if ( version_compare( $wp_version, '5.6', '<' ) ) {
		deactivate_plugins( REPLACELY_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Replacely requires WordPress 5.6 or higher.', 'replacely' ),
			esc_html__( 'Plugin activation failed', 'replacely' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'replacely_activate_plugin' );

/**
 * Bootstrap the plugin once all plugins are loaded.
 *
 * @return void
 */
function replacely_bootstrap() {
	\Replacely\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'replacely_bootstrap' );
