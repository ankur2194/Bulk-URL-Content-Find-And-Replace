<?php
/**
 * Core plugin orchestrator.
 *
 * @package Replacely
 */

namespace Replacely;

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
	 *
	 * @throws \RuntimeException When unserialization is attempted.
	 * @return void
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
		// Translations are loaded automatically: WordPress.org serves them for the plugin
		// slug, and WP's just-in-time loader picks up any bundled .mo files in /languages
		// (the Domain Path) since the text domain matches the slug. A manual
		// load_plugin_textdomain() call has been discouraged since WordPress 4.6.
		if ( is_admin() ) {
			$this->admin_page = new Admin_Page();
			$this->admin_page->register();
		}
	}
}
