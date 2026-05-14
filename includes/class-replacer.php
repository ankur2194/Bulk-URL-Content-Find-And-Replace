<?php
/**
 * Core replacement engine.
 *
 * @package BulkUrlContentFindReplace
 */

namespace BUCFR;

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
		$rows = array();
		$seen_post_ids   = array();
		$summary = array(
			'total'              => count( $lines ),
			'updated'            => 0,
			'previewed'          => 0,
			'no_match'           => 0,
			'invalid'            => 0,
			'failed'             => 0,
			'duplicate'          => 0,
			'skipped'            => 0,
			'total_replacements' => 0,
			'started_at'         => microtime( true ),
			'duration'           => 0.0,
			'timestamp'          => current_time( 'mysql' ),
			'dry_run'            => $this->dry_run,
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
			'input'         => $line,
			'resolved_url'  => '',
			'post_id'       => 0,
			'post_type'     => '',
			'post_title'    => '',
			'status'        => 'failed',
			'replacements'  => 0,
			'message'       => '',
			'edit_link'     => '',
			'view_link'     => '',
		);

		$normalized = Helper::normalize_to_url( $line );
		if ( null === $normalized ) {
			$row['status']  = 'invalid_url';
			$row['message'] = __( 'Could not parse this line as a URL or path.', 'bulk-url-content-find-replace' );
			$summary['invalid']++;
			return $row;
		}

		$row['resolved_url'] = $normalized;

		$post_id = url_to_postid( $normalized );
		if ( ! $post_id ) {
			$row['status']  = 'invalid_url';
			$row['message'] = __( 'No matching post, page, or CPT was found for this URL.', 'bulk-url-content-find-replace' );
			$summary['invalid']++;
			return $row;
		}

		if ( in_array( (int) $post_id, $seen_post_ids, true ) ) {
			$row['post_id']     = (int) $post_id;
			$row['status']      = 'duplicate';
			$row['message']     = __( 'This post was already processed earlier in the list.', 'bulk-url-content-find-replace' );
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
			$row['message'] = __( 'Post could not be loaded.', 'bulk-url-content-find-replace' );
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
				__( 'Post status "%s" is not eligible for editing.', 'bulk-url-content-find-replace' ),
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
			$row['message']    = __( 'Revisions are not edited directly.', 'bulk-url-content-find-replace' );
			$summary['skipped']++;
			return $row;
		}

		// Capability check at the post level.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$row['post_id']    = (int) $post->ID;
			$row['post_type']  = $post->post_type;
			$row['post_title'] = $post->post_title;
			$row['status']     = 'not_supported';
			$row['message']    = __( 'You do not have permission to edit this post.', 'bulk-url-content-find-replace' );
			$summary['failed']++;
			return $row;
		}

		$row['post_id']    = (int) $post->ID;
		$row['post_type']  = $post->post_type;
		$row['post_title'] = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'bulk-url-content-find-replace' );
		$row['edit_link']  = (string) get_edit_post_link( $post->ID, 'raw' );
		$row['view_link']  = (string) get_permalink( $post->ID );

		$content = (string) $post->post_content;

		$count = 0;
		// substr_count uses strict, exact matching — perfect for "exact text match only".
		if ( '' !== $this->search ) {
			$count = substr_count( $content, $this->search );
		}

		if ( 0 === $count ) {
			$row['status']  = 'no_match';
			$row['message'] = __( 'The search text was not found in this post.', 'bulk-url-content-find-replace' );
			$summary['no_match']++;
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		$row['replacements']           = $count;
		$summary['total_replacements'] += $count;

		if ( $this->dry_run ) {
			$row['status']  = 'preview';
			$row['message'] = sprintf(
				/* translators: %d: number of replacements that would be performed. */
				_n(
					'%d replacement would be made (dry run).',
					'%d replacements would be made (dry run).',
					$count,
					'bulk-url-content-find-replace'
				),
				$count
			);
			$summary['previewed']++;
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		// Perform the actual update.
		$new_content = str_replace( $this->search, $this->replace, $content );

		// Skip the database write if nothing actually changed (defence in depth).
		if ( $new_content === $content ) {
			$row['status']  = 'no_match';
			$row['message'] = __( 'Content did not change after replacement.', 'bulk-url-content-find-replace' );
			$summary['no_match']++;
			$seen_post_ids[] = (int) $post->ID;
			return $row;
		}

		// Suspend revisions during this single update for performance and DB hygiene.
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

		$row['status']  = 'updated';
		$row['message'] = sprintf(
			/* translators: %d: number of replacements made. */
			_n(
				'%d replacement made successfully.',
				'%d replacements made successfully.',
				$count,
				'bulk-url-content-find-replace'
			),
			$count
		);
		$summary['updated']++;
		$seen_post_ids[] = (int) $post->ID;

		return $row;
	}
}
