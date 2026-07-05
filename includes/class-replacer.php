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
	 * Definitions for the page builders that keep their content outside the
	 * classic `post_content`. Each entry describes how the builder stores its
	 * data so the engine can read, replace, and write it back losslessly.
	 *
	 * Keys:
	 * - format:     'json' (JSON string in meta), 'php' (already-unserialized
	 *               array/object meta), or 'string' (plain string in meta).
	 * - count_keys: meta keys whose matches are reported to the user. These are
	 *               the front-end source of truth (e.g. the published layout, or
	 *               each distinct Bricks region).
	 * - apply_keys: meta keys that actually get rewritten when replacing. This is
	 *               a superset of count_keys so mirror copies (such as Beaver's
	 *               draft) stay in sync without being double-counted.
	 *
	 * @return array<string,array>
	 */
	private function builder_definitions() {
		return array(
			// Elementor stores page content as JSON in a single meta key.
			'elementor' => array(
				'format'     => 'json',
				'count_keys' => array( '_elementor_data' ),
				'apply_keys' => array( '_elementor_data' ),
			),
			// Beaver Builder stores a serialized array of node objects. The
			// published layout (`_fl_builder_data`) is what renders; the draft
			// mirror is updated too but not counted, to avoid double counting.
			'beaver'    => array(
				'format'     => 'php',
				'count_keys' => array( '_fl_builder_data' ),
				'apply_keys' => array( '_fl_builder_data', '_fl_builder_draft' ),
			),
			// Oxygen stores a plain shortcode string; visible text lives directly
			// inside it, so it is treated like classic content.
			'oxygen'    => array(
				'format'     => 'string',
				'count_keys' => array( 'ct_builder_shortcodes' ),
				'apply_keys' => array( 'ct_builder_shortcodes' ),
			),
			// Bricks stores a serialized element tree per template region. Each
			// region is distinct content, so all three are counted and applied.
			'bricks'    => array(
				'format'     => 'php',
				'count_keys' => array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ),
				'apply_keys' => array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ),
			),
		);
	}

	/**
	 * Process a list of URLs/paths and return a structured result set.
	 *
	 * @param string[] $lines List of raw URL/path lines.
	 * @return array{rows: array, summary: array}
	 */
	public function process( array $lines ) {
		$rows = array();
		$seen_post_ids   = array();
		$summary = array(
			'total'                => count( $lines ),
			'updated'              => 0,
			'previewed'            => 0,
			'no_match'             => 0,
			'invalid'              => 0,
			'failed'               => 0,
			'duplicate'            => 0,
			'skipped'              => 0,
			'total_replacements'   => 0,
			'content_replacements' => 0,
			// Aggregated builder breakdown, keyed by builder slug (only populated
			// for builders that actually recorded a replacement this run).
			'builder_replacements' => array(),
			'started_at'           => microtime( true ),
			'duration'             => 0.0,
			'timestamp'            => current_time( 'mysql' ),
			'dry_run'              => $this->dry_run,
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
			'input'                => $line,
			'resolved_url'         => '',
			'post_id'              => 0,
			'post_type'            => '',
			'post_title'           => '',
			'status'               => 'failed',
			'replacements'         => 0,
			'content_replacements' => 0,
			'builder_replacements' => array(),
			'message'              => '',
			'edit_link'            => '',
			'view_link'            => '',
		);

		$normalized = Helper::normalize_to_url( $line );
		if ( null === $normalized ) {
			$row['status']  = 'invalid_url';
			$row['message'] = __( 'Could not parse this line as a URL or path.', 'replacely' );
			$summary['invalid']++;
			return $row;
		}

		$row['resolved_url'] = $normalized;

		$post_id = url_to_postid( $normalized );
		if ( ! $post_id ) {
			$row['status']  = 'invalid_url';
			$row['message'] = __( 'No matching post, page, or CPT was found for this URL.', 'replacely' );
			$summary['invalid']++;
			return $row;
		}

		if ( in_array( (int) $post_id, $seen_post_ids, true ) ) {
			$row['post_id']     = (int) $post_id;
			$row['status']      = 'duplicate';
			$row['message']     = __( 'This post was already processed earlier in the list.', 'replacely' );
			$post              = get_post( $post_id );
			if ( $post ) {
				$row['post_type']  = $post->post_type;
				$row['post_title'] = $post->post_title;
			}
			$summary['duplicate']++;
			return $row;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			$row['status']  = 'failed';
			$row['message'] = __( 'Post could not be loaded.', 'replacely' );
			$summary['failed']++;
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
			$summary['skipped']++;
			return $row;
		}

		if ( 'revision' === $post->post_type ) {
			$row['post_id']    = (int) $post->ID;
			$row['post_type']  = $post->post_type;
			$row['post_title'] = $post->post_title;
			$row['status']     = 'skipped';
			$row['message']    = __( 'Revisions are not edited directly.', 'replacely' );
			$summary['skipped']++;
			return $row;
		}

		// Capability check at the post level.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$row['post_id']    = (int) $post->ID;
			$row['post_type']  = $post->post_type;
			$row['post_title'] = $post->post_title;
			$row['status']     = 'not_supported';
			$row['message']    = __( 'You do not have permission to edit this post.', 'replacely' );
			$summary['failed']++;
			return $row;
		}

		$row['post_id']    = (int) $post->ID;
		$row['post_type']  = $post->post_type;
		$row['post_title'] = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'replacely' );
		$row['edit_link']  = (string) get_edit_post_link( $post->ID, 'raw' );
		$row['view_link']  = (string) get_permalink( $post->ID );

		$content = (string) $post->post_content;

		// Count matches in classic post_content. substr_count uses strict, exact
		// matching — perfect for "exact text match only". Shortcode-based builders
		// (WPBakery, Divi, Avada/Fusion) keep their content here, so they are
		// covered by this single classic-content pass.
		$content_count = substr_count( $content, $this->search );

		// Scan every supported page builder that stores its content in post meta
		// (Elementor, Beaver, Oxygen, Bricks). Each returns a planned set of
		// writes plus a per-builder match count.
		$builder_plan = $this->scan_builders( $post->ID );

		// If a replacement was found in a builder's data but the result could not
		// be safely re-encoded, refuse to touch this post rather than risk writing
		// a corrupt value to the meta.
		if ( ! empty( $builder_plan['encode_failed'] ) ) {
			$row['status']   = 'failed';
			$row['message']  = sprintf(
				/* translators: %s: page builder name. */
				__( '%s data could not be safely re-encoded; this post was left unchanged.', 'replacely' ),
				Helper::builder_label( $builder_plan['encode_failed'] )
			);
			$summary['failed']++;
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		$builder_counts = $builder_plan['counts'];
		$count          = $content_count + (int) array_sum( $builder_counts );

		if ( 0 === $count ) {
			$row['status']  = 'no_match';
			$row['message'] = __( 'The search text was not found in this post.', 'replacely' );
			$summary['no_match']++;
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		$row['replacements']         = $count;
		$row['content_replacements'] = $content_count;
		$row['builder_replacements'] = $builder_counts;
		$summary['total_replacements']   += $count;
		$summary['content_replacements'] += $content_count;
		foreach ( $builder_counts as $slug => $slug_count ) {
			if ( ! isset( $summary['builder_replacements'][ $slug ] ) ) {
				$summary['builder_replacements'][ $slug ] = 0;
			}
			$summary['builder_replacements'][ $slug ] += $slug_count;
		}

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
			$summary['previewed']++;
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		// Perform the actual update(s). A single post can have both classic
		// post_content and page-builder data; update whichever actually changed.
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
					$summary['failed']++;
					$seen_post_ids[] = (int) $post->ID;
					return $row;
				}

				$something_changed = true;
			}
		}

		// 2) Page-builder data stored in post meta.
		if ( $this->apply_builder_writes( $post->ID, $builder_plan['writes'] ) ) {
			$something_changed = true;
		}

		// Defence in depth: if for some reason nothing actually changed, report it.
		if ( ! $something_changed ) {
			$row['status']  = 'no_match';
			$row['message'] = __( 'Content did not change after replacement.', 'replacely' );
			$summary['no_match']++;
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
		$summary['updated']++;
		$seen_post_ids[] = (int) $post->ID;

		return $row;
	}

	/**
	 * Inspect every supported builder's meta for the given post and build a plan
	 * describing what would change.
	 *
	 * @param int $post_id Post ID.
	 * @return array{
	 *     counts: array<string,int>,
	 *     writes: array<int,array{slug:string,key:string,format:string,value:mixed}>,
	 *     encode_failed: string|false
	 * }
	 */
	private function scan_builders( $post_id ) {
		$counts = array();
		$writes = array();

		foreach ( $this->builder_definitions() as $slug => $def ) {
			$count_keys    = $def['count_keys'];
			$builder_count = 0;
			$builder_writes = array();

			foreach ( $def['apply_keys'] as $key ) {
				$raw = get_post_meta( $post_id, $key, true );

				$result = $this->replace_in_meta( $raw, $def['format'] );
				if ( false !== $result['encode_failed'] && $result['count'] > 0 ) {
					// Abort the whole post: surface which builder failed.
					return array(
						'counts'        => array(),
						'writes'        => array(),
						'encode_failed' => $slug,
					);
				}

				if ( $result['count'] <= 0 ) {
					continue;
				}

				$builder_writes[] = array(
					'slug'   => $slug,
					'key'    => $key,
					'format' => $def['format'],
					'value'  => $result['value'],
				);

				// Only meta keys flagged as "count keys" contribute to the
				// user-facing number (mirror copies such as Beaver's draft do not).
				if ( in_array( $key, $count_keys, true ) ) {
					$builder_count += $result['count'];
				}
			}

			// Only act on a builder whose front-end (counted) content matched.
			// This avoids editing stale mirror copies for builders whose primary
			// data has no match.
			if ( $builder_count > 0 ) {
				$counts[ $slug ] = $builder_count;
				$writes          = array_merge( $writes, $builder_writes );
			}
		}

		return array(
			'counts'        => $counts,
			'writes'        => $writes,
			'encode_failed' => false,
		);
	}

	/**
	 * Apply a planned set of builder meta writes to a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $writes  Planned writes from {@see Replacer::scan_builders()}.
	 * @return bool Whether anything was written.
	 */
	private function apply_builder_writes( $post_id, array $writes ) {
		if ( empty( $writes ) ) {
			return false;
		}

		$changed       = false;
		$beaver_dirty  = false;

		foreach ( $writes as $write ) {
			// wp_slash counteracts the wp_unslash that update_metadata() applies,
			// so the value is stored exactly as the builder expects it. wp_slash
			// recurses into arrays and objects, so it is safe for every format.
			update_post_meta( $post_id, $write['key'], wp_slash( $write['value'] ) );
			$changed = true;

			switch ( $write['slug'] ) {
				case 'elementor':
					// Drop the stale per-post inline CSS cache; a full regeneration
					// runs once at the end of the batch (see process()).
					delete_post_meta( $post_id, '_elementor_css' );
					$this->elementor_dirty = true;
					break;
				case 'beaver':
					$beaver_dirty = true;
					break;
			}
		}

		// Beaver Builder caches the rendered layout CSS/JS per post, so clear it
		// for this post or the front end keeps serving the old text. Guarded so it
		// is a no-op when Beaver Builder is not active.
		if ( $beaver_dirty && class_exists( '\\FLBuilderModel' ) && method_exists( '\\FLBuilderModel', 'delete_asset_cache' ) ) {
			\FLBuilderModel::delete_asset_cache( $post_id );
		}

		return $changed;
	}

	/**
	 * Run the find & replace against a single meta value according to its storage
	 * format, returning the match count and the rewritten value.
	 *
	 * @param mixed  $raw    Raw meta value (string for json/string formats, an
	 *                       already-unserialized array/object for the php format).
	 * @param string $format One of 'json', 'php', or 'string'.
	 * @return array{count:int,value:mixed,encode_failed:string|bool}
	 */
	private function replace_in_meta( $raw, $format ) {
		$count = 0;

		if ( 'string' === $format ) {
			if ( ! is_string( $raw ) || '' === $raw ) {
				return array(
					'count'         => 0,
					'value'         => null,
					'encode_failed' => false,
				);
			}
			$value = str_replace( $this->search, $this->replace, $raw, $count );
			return array(
				'count'         => $count,
				'value'         => $value,
				'encode_failed' => false,
			);
		}

		if ( 'json' === $format ) {
			if ( ! is_string( $raw ) || '' === $raw ) {
				return array(
					'count'         => 0,
					'value'         => null,
					'encode_failed' => false,
				);
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array(
					'count'         => 0,
					'value'         => null,
					'encode_failed' => false,
				);
			}
			$replaced = $this->replace_in_structure( $decoded, $count );
			$value    = null;
			if ( $count > 0 ) {
				// wp_json_encode re-escapes forward slashes, matching the exact
				// format builders like Elementor write to the database.
				$value = wp_json_encode( $replaced );
			}
			return array(
				'count'         => $count,
				'value'         => $value,
				'encode_failed' => ( $count > 0 && ! is_string( $value ) ),
			);
		}

		// 'php' format: get_post_meta() already returned an unserialized
		// array/object structure. Walk it and let update_post_meta() re-serialize.
		if ( ! is_array( $raw ) && ! is_object( $raw ) ) {
			return array(
				'count'         => 0,
				'value'         => null,
				'encode_failed' => false,
			);
		}
		$replaced = $this->replace_in_structure( $raw, $count );
		return array(
			'count'         => $count,
			'value'         => ( $count > 0 ) ? $replaced : null,
			'encode_failed' => false,
		);
	}

	/**
	 * Recursively walk a decoded data structure and run the exact find & replace
	 * against every string leaf, accumulating the match count.
	 *
	 * Handles arrays and objects (e.g. Beaver Builder's stdClass nodes). Non-string
	 * scalars (ints, floats, bools, null) are returned untouched so each builder's
	 * setting types are preserved exactly.
	 *
	 * @param mixed $node  Current node (array, object, or scalar) from the data.
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

		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $key => $value ) {
				$node->$key = $this->replace_in_structure( $value, $count );
			}
			return $node;
		}

		if ( is_string( $node ) ) {
			$local = 0;
			$node  = str_replace( $this->search, $this->replace, $node, $local );
			$count += $local;
		}

		return $node;
	}
}
