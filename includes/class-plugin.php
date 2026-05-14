<?php
/**
 * Core plugin orchestrator.
 *
 * @package BulkUrlContentFindReplace
 */

namespace BUCFR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton container that wires together all plugin components.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin page handler.
	 *
	 * @var Admin_Page
	 */
	private $admin_page;

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Disallow cloning.
	 */
	private function __clone() {}

	/**
	 * Disallow unserialization.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Unserialization is not allowed.' );
	}

	/**
	 * Return the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks and component listeners.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->admin_page = new Admin_Page();
			$this->admin_page->register();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'bulk-url-content-find-replace',
			false,
			dirname( BUCFR_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
