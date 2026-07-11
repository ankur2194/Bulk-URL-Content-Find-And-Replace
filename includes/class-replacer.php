<?php
/**
 * Core replacement engine.
 *
 * @package Replacely
 */

namespace Replacely;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs find & replace against a batch of URLs/paths.
 */
class Replacer {

	/**
	 * Exact search string. Never trimmed — the caller decides.
	 *
	 * @var string
	 */
	private $search;

	/**
	 * Exact replacement string.
	 *
	 * @var string
	 */
	private $replace;

	/**
	 * Whether to operate in dry-run (preview-only) mode.
	 *
	 * @var bool
	 */
	private $dry_run;

	/**
	 * Whether at least one Elementor page's data was rewritten during this run.
	 * Used to trigger a single Elementor cache regeneration at the end.
	 *
	 * @var bool
	 */
	private $elementor_dirty = false;

	/**
	 * Build a new replacer.
	 *
	 * @param string $search  Exact search text.
	 * @param string $replace Exact replacement text.
	 * @param bool   $dry_run Whether to preview only.
	 */
	public function __construct( $search, $replace, $dry_run = false ) {
		$this->search  = (string) $search;
		$this->replace = (string) $replace;
		$this->dry_run = (bool) $dry_run;
	}

	/**
	 * Process a list of URLs/paths and return a structured result set.
	 *
	 * @param string[] $lines List of raw URL/path lines.
	 * @return array{rows: array, summary: array}
	 */
	public function process( array $lines ) {
		$rows          = array();
		$seen_post_ids = array();
		$summary       = array(
			'total'                  => count( $lines ),
			'updated'                => 0,
			'previewed'              => 0,
			'no_match'               => 0,
			'invalid'                => 0,
			'failed'                 => 0,
			'duplicate'              => 0,
			'skipped'                => 0,
			'total_replacements'     => 0,
			'content_replacements'   => 0,
			'elementor_replacements' => 0,
			'started_at'             => microtime( true ),
			'duration'               => 0.0,
			'timestamp'              => current_time( 'mysql' ),
			'dry_run'                => $this->dry_run,
		);

		if ( '' === $this->search ) {
			$summary['duration'] = 0.0;
			return array(
				'rows'    => $rows,
				'summary' => $summary,
			);
		}

		foreach ( $lines as $line ) {
			$rows[] = $this->process_single_line( $line, $seen_post_ids, $summary );
		}

		// If any Elementor page data was rewritten, regenerate Elementor's cached
		// CSS once so the front end reflects the new content. Guarded so it is a
		// harmless no-op when Elementor is not active.
		if ( $this->elementor_dirty && class_exists( '\\Elementor\\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		$summary['duration'] = microtime( true ) - $summary['started_at'];

		return array(
			'rows'    => $rows,
			'summary' => $summary,
		);
	}

	/**
	 * Process a single input line and update the rolling summary.
	 *
	 * @param string $line          Raw URL/path line.
	 * @param array  $seen_post_ids Reference to running list of processed post IDs.
	 * @param array  $summary       Reference to running summary totals.
	 * @return array Result row.
	 */
	private function process_single_line( $line, array &$seen_post_ids, array &$summary ) {
		$row = array(
			'input'                  => $line,
			'resolved_url'           => '',
			'post_id'                => 0,
			'post_type'              => '',
			'post_title'             => '',
			'status'                 => 'failed',
			'replacements'           => 0,
			'content_replacements'   => 0,
			'elementor_replacements' => 0,
			'message'                => '',
			'edit_link'              => '',
			'view_link'              => '',
		);

		$normalized = Helper::normalize_to_url( $line );
		if ( null === $normalized ) {
			$row['status']  = 'invalid_url';
			$row['message'] = __( 'Could not parse this line as a URL or path.', 'replacely' );
			++$summary['invalid'];
			return $row;
		}

		$row['resolved_url'] = $normalized;

		$post_id = url_to_postid( $normalized );
		if ( ! $post_id ) {
			$row['status']  = 'invalid_url';
			$row['message'] = __( 'No matching post, page, or CPT was found for this URL.', 'replacely' );
			++$summary['invalid'];
			return $row;
		}

		if ( in_array( (int) $post_id, $seen_post_ids, true ) ) {
			$row['post_id'] = (int) $post_id;
			$row['status']  = 'duplicate';
			$row['message'] = __( 'This post was already processed earlier in the list.', 'replacely' );
			$post           = get_post( $post_id );
			if ( $post ) {
				$row['post_type']  = $post->post_type;
				$row['post_title'] = $post->post_title;
			}
			++$summary['duplicate'];
			return $row;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			$row['status']  = 'failed';
			$row['message'] = __( 'Post could not be loaded.', 'replacely' );
			++$summary['failed'];
			return $row;
		}

		// Skip non-editable statuses.
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			$row['post_id']    = (int) $post->ID;
			$row['post_type']  = $post->post_type;
			$row['post_title'] = $post->post_title;
			$row['status']     = 'skipped';
			$row['message']    = sprintf(
				/* translators: %s: post status. */
				__( 'Post status "%s" is not eligible for editing.', 'replacely' ),
				$post->post_status
			);
			++$summary['skipped'];
			return $row;
		}

		if ( 'revision' === $post->post_type ) {
			$row['post_id']    = (int) $post->ID;
			$row['post_type']  = $post->post_type;
			$row['post_title'] = $post->post_title;
			$row['status']     = 'skipped';
			$row['message']    = __( 'Revisions are not edited directly.', 'replacely' );
			++$summary['skipped'];
			return $row;
		}

		// Capability check at the post level.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$row['post_id']    = (int) $post->ID;
			$row['post_type']  = $post->post_type;
			$row['post_title'] = $post->post_title;
			$row['status']     = 'not_supported';
			$row['message']    = __( 'You do not have permission to edit this post.', 'replacely' );
			++$summary['failed'];
			return $row;
		}

		$row['post_id']    = (int) $post->ID;
		$row['post_type']  = $post->post_type;
		$row['post_title'] = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'replacely' );
		$row['edit_link']  = (string) get_edit_post_link( $post->ID, 'raw' );
		$row['view_link']  = (string) get_permalink( $post->ID );

		$content = (string) $post->post_content;

		// Count matches in classic post_content. substr_count uses strict, exact
		// matching — perfect for "exact text match only".
		$content_count = ( '' !== $this->search ) ? substr_count( $content, $this->search ) : 0;

		// Elementor stores the real page content as JSON in the _elementor_data
		// post meta, not in post_content. Decode it, replace inside every string
		// leaf, and re-encode so the change survives the round-trip exactly.
		$elementor_count = 0;
		$new_elementor   = null;
		$elementor_raw   = get_post_meta( $post->ID, '_elementor_data', true );
		if ( '' !== $this->search && is_string( $elementor_raw ) && '' !== $elementor_raw ) {
			$decoded = json_decode( $elementor_raw, true );
			if ( is_array( $decoded ) ) {
				$replaced = $this->replace_in_structure( $decoded, $elementor_count );
				if ( $elementor_count > 0 ) {
					// wp_json_encode re-escapes forward slashes, matching the exact
					// format Elementor writes to the database.
					$new_elementor = wp_json_encode( $replaced );
				}
			}
		}

		// If a replacement was found in the Elementor data but the result could
		// not be safely re-encoded, refuse to touch this post rather than risk
		// writing a corrupt (empty) value to _elementor_data.
		if ( $elementor_count > 0 && ! is_string( $new_elementor ) ) {
			$row['status']  = 'failed';
			$row['message'] = __( 'Elementor data could not be safely re-encoded; this post was left unchanged.', 'replacely' );
			++$summary['failed'];
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		$count = $content_count + $elementor_count;

		if ( 0 === $count ) {
			$row['status']  = 'no_match';
			$row['message'] = __( 'The search text was not found in this post.', 'replacely' );
			++$summary['no_match'];
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		$row['replacements']                = $count;
		$row['content_replacements']        = $content_count;
		$row['elementor_replacements']      = $elementor_count;
		$summary['total_replacements']     += $count;
		$summary['content_replacements']   += $content_count;
		$summary['elementor_replacements'] += $elementor_count;

		if ( $this->dry_run ) {
			$row['status']  = 'preview';
			$row['message'] = sprintf(
				/* translators: %d: number of replacements that would be performed. */
				_n(
					'%d replacement would be made (dry run).',
					'%d replacements would be made (dry run).',
					$count,
					'replacely'
				),
				$count
			);
			++$summary['previewed'];
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		// Perform the actual update(s). A single post can have both classic
		// post_content and Elementor data; update whichever actually changed.
		$something_changed = false;

		// 1) Classic post_content.
		if ( $content_count > 0 ) {
			$new_content = str_replace( $this->search, $this->replace, $content );

			if ( $new_content !== $content ) {
				$update = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $new_content,
					),
					true
				);

				if ( is_wp_error( $update ) ) {
					$row['status']  = 'failed';
					$row['message'] = $update->get_error_message();
					++$summary['failed'];
					$seen_post_ids[] = (int) $post->ID;
					return $row;
				}

				$something_changed = true;
			}
		}

		// 2) Elementor page-builder data (_elementor_data).
		if ( $elementor_count > 0 && null !== $new_elementor ) {
			// wp_slash counteracts the wp_unslash that update_metadata() applies,
			// so the JSON is stored exactly as Elementor expects it.
			update_post_meta( $post->ID, '_elementor_data', wp_slash( $new_elementor ) );
			// Drop the stale per-post inline CSS cache; a full regeneration runs
			// once at the end of the batch (see process()).
			delete_post_meta( $post->ID, '_elementor_css' );
			$this->elementor_dirty = true;
			$something_changed     = true;
		}

		// Defence in depth: if for some reason nothing actually changed, report it.
		if ( ! $something_changed ) {
			$row['status']  = 'no_match';
			$row['message'] = __( 'Content did not change after replacement.', 'replacely' );
			++$summary['no_match'];
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		$row['status']  = 'updated';
		$row['message'] = sprintf(
			/* translators: %d: number of replacements made. */
			_n(
				'%d replacement made successfully.',
				'%d replacements made successfully.',
				$count,
				'replacely'
			),
			$count
		);
		++$summary['updated'];
		$seen_post_ids[] = (int) $post->ID;

		return $row;
	}

	/**
	 * Recursively walk a decoded Elementor data structure and run the exact
	 * find & replace against every string leaf, accumulating the match count.
	 *
	 * Non-string scalars (ints, floats, bools, null) are returned untouched so
	 * Elementor's setting types are preserved exactly.
	 *
	 * @param mixed $node  Current node (array or scalar) from the decoded JSON.
	 * @param int   $count Reference to the running replacement count.
	 * @return mixed The node with replacements applied to its string leaves.
	 */
	private function replace_in_structure( $node, &$count ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $value ) {
				$node[ $key ] = $this->replace_in_structure( $value, $count );
			}
			return $node;
		}

		if ( is_string( $node ) ) {
			$local  = 0;
			$node   = str_replace( $this->search, $this->replace, $node, $local );
			$count += $local;
		}

		return $node;
	}
}
