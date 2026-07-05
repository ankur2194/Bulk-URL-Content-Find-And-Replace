<?php
/**
 * Generic helper utilities shared across the plugin.
 *
 * @package Replacely
 */

namespace Replacely;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless helper class.
 */
class Helper {

	/**
	 * Required capability for all plugin operations.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action identifier.
	 */
	const NONCE_ACTION = 'replacely_run_replacement';

	/**
	 * Nonce request field name.
	 */
	const NONCE_FIELD = 'replacely_nonce';

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'replacely';

	/**
	 * Option key for the persistent activity log.
	 */
	const LOG_OPTION = 'replacely_activity_log';

	/**
	 * Maximum number of entries kept in the activity log.
	 */
	const LOG_LIMIT = 200;

	/**
	 * Canonical list of supported page builders that store their content outside
	 * the classic `post_content`. Keyed by an internal slug used throughout the
	 * results, CSV export, and activity log; the value is the human-readable
	 * label shown in the UI.
	 *
	 * Classic `post_content` (Gutenberg, Classic Editor, and shortcode-based
	 * builders such as WPBakery, Divi, and Avada/Fusion) is handled separately
	 * and is intentionally not listed here.
	 *
	 * @return array<string,string> Map of builder slug => translated label.
	 */
	public static function builders() {
		return array(
			'elementor' => __( 'Elementor', 'replacely' ),
			'beaver'    => __( 'Beaver Builder', 'replacely' ),
			'oxygen'    => __( 'Oxygen', 'replacely' ),
			'bricks'    => __( 'Bricks', 'replacely' ),
		);
	}

	/**
	 * Translate a builder slug into its human-readable label.
	 *
	 * @param string $slug Builder slug (e.g. "elementor").
	 * @return string Translated label, or a title-cased fallback for unknown slugs.
	 */
	public static function builder_label( $slug ) {
		$builders = self::builders();
		return isset( $builders[ $slug ] ) ? $builders[ $slug ] : ucfirst( str_replace( '_', ' ', (string) $slug ) );
	}

	/**
	 * Dashicon used to represent a page builder in the replacement breakdown.
	 *
	 * @param string $slug Builder slug.
	 * @return string Dashicon name (without the leading "dashicons-").
	 */
	public static function builder_dashicon( $slug ) {
		switch ( $slug ) {
			case 'elementor':
				return 'layout';
			case 'beaver':
				return 'screenoptions';
			case 'oxygen':
				return 'admin-customizer';
			case 'bricks':
				return 'grid-view';
			default:
				return 'layout';
		}
	}

	/**
	 * Prepend successful-update entries to the persistent activity log.
	 *
	 * Newest entries appear first. The log is hard-capped at LOG_LIMIT entries
	 * so it can never grow unbounded.
	 *
	 * @param array $entries List of normalised log entries.
	 * @return void
	 */
	public static function append_log( array $entries ) {
		if ( empty( $entries ) ) {
			return;
		}

		$existing = self::get_log();
		$combined = array_merge( $entries, $existing );

		if ( count( $combined ) > self::LOG_LIMIT ) {
			$combined = array_slice( $combined, 0, self::LOG_LIMIT );
		}

		update_option( self::LOG_OPTION, $combined, false );
	}

	/**
	 * Fetch the persistent activity log.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empty the persistent activity log.
	 *
	 * @return void
	 */
	public static function clear_log() {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Split a raw textarea value into an ordered list of trimmed, non-empty lines.
	 *
	 * @param string $raw_input Raw textarea value.
	 * @return string[] Clean list of lines.
	 */
	public static function split_lines( $raw_input ) {
		if ( ! is_string( $raw_input ) || '' === $raw_input ) {
			return array();
		}

		// Normalise line endings, then split.
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw_input );
		$lines      = explode( "\n", $normalized );

		$clean = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$clean[] = $line;
		}

		// Preserve order and deduplicate while keeping the first occurrence.
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Detect whether a value is an absolute URL (has a scheme + host).
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_absolute_url( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$parsed = wp_parse_url( $value );
		if ( ! is_array( $parsed ) ) {
			return false;
		}

		return ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] );
	}

	/**
	 * Normalise an input line into an absolute URL on the current site.
	 * Returns null if the value cannot be turned into a plausible URL.
	 *
	 * @param string $line Raw URL or path.
	 * @return string|null
	 */
	public static function normalize_to_url( $line ) {
		if ( ! is_string( $line ) ) {
			return null;
		}

		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}

		if ( self::is_absolute_url( $line ) ) {
			return esc_url_raw( $line );
		}

		// Treat anything else as a relative path on this site.
		$path = '/' . ltrim( $line, '/' );
		return esc_url_raw( home_url( $path ) );
	}

	/**
	 * Format microseconds into a friendly elapsed string.
	 *
	 * @param float $seconds Elapsed seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0.0, (float) $seconds );

		if ( $seconds < 1 ) {
			return sprintf(
				/* translators: %s: milliseconds */
				__( '%s ms', 'replacely' ),
				number_format_i18n( $seconds * 1000, 0 )
			);
		}

		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %s: seconds */
				__( '%s s', 'replacely' ),
				number_format_i18n( $seconds, 2 )
			);
		}

		$minutes = floor( $seconds / 60 );
		$rest    = $seconds - ( $minutes * 60 );

		return sprintf(
			/* translators: 1: minutes, 2: seconds */
			__( '%1$d min %2$s s', 'replacely' ),
			(int) $minutes,
			number_format_i18n( $rest, 1 )
		);
	}

	/**
	 * Map an internal status code to a human-readable label.
	 *
	 * @param string $status Status code.
	 * @return string Translated label.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'updated'        => __( 'Updated', 'replacely' ),
			'preview'        => __( 'Preview', 'replacely' ),
			'no_match'       => __( 'No match', 'replacely' ),
			'invalid_url'    => __( 'Invalid URL', 'replacely' ),
			'skipped'        => __( 'Skipped', 'replacely' ),
			'failed'         => __( 'Failed', 'replacely' ),
			'duplicate'      => __( 'Duplicate', 'replacely' ),
			'not_supported'  => __( 'Unsupported', 'replacely' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Return the CSS tone (success/warning/error/info) for a given status.
	 *
	 * @param string $status Status code.
	 * @return string
	 */
	public static function status_tone( $status ) {
		switch ( $status ) {
			case 'updated':
				return 'success';
			case 'preview':
				return 'info';
			case 'no_match':
			case 'duplicate':
			case 'skipped':
				return 'warning';
			case 'invalid_url':
			case 'failed':
			case 'not_supported':
				return 'error';
			default:
				return 'info';
		}
	}

	/**
	 * Dashicon name corresponding to a status.
	 *
	 * @param string $status Status code.
	 * @return string Dashicon class.
	 */
	public static function status_dashicon( $status ) {
		switch ( $status ) {
			case 'updated':
				return 'yes-alt';
			case 'preview':
				return 'visibility';
			case 'no_match':
				return 'minus';
			case 'invalid_url':
				return 'dismiss';
			case 'failed':
				return 'warning';
			case 'duplicate':
				return 'admin-page';
			case 'skipped':
				return 'controls-pause';
			case 'not_supported':
				return 'lock';
			default:
				return 'info';
		}
	}
}
