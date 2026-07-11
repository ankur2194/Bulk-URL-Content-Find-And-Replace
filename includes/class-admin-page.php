<?php
/**
 * Admin page controller, view layer, and request handler.
 *
 * @package Replacely
 */

namespace Replacely;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Tools → Replacely page and handles form submissions.
 */
class Admin_Page {

	/**
	 * Hook suffix returned by add_management_page().
	 *
	 * @var string|false
	 */
	private $hook_suffix = false;

	/**
	 * Latest form submission state, kept between handle_post() and render().
	 *
	 * @var array{
	 *     submitted: bool,
	 *     dry_run: bool,
	 *     search: string,
	 *     replace: string,
	 *     urls: string,
	 *     errors: string[],
	 *     results: array|null,
	 * }
	 */
	private $state = array(
		'submitted' => false,
		'dry_run'   => false,
		'search'    => '',
		'replace'   => '',
		'urls'      => '',
		'errors'    => array(),
		'results'   => null,
	);

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_replacely_run', array( $this, 'handle_post' ) );
		add_action( 'admin_post_replacely_export_csv', array( $this, 'handle_csv_export' ) );
		add_action( 'admin_post_replacely_clear_log', array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Register the menu item under Tools.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook_suffix = add_management_page(
			__( 'Replacely', 'replacely' ),
			__( 'Replacely', 'replacely' ),
			Helper::CAPABILITY,
			Helper::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue CSS/JS on our screen only.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'replacely-admin',
			REPLACELY_PLUGIN_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			REPLACELY_VERSION
		);

		wp_enqueue_script(
			'replacely-admin',
			REPLACELY_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			REPLACELY_VERSION,
			true
		);

		wp_localize_script(
			'replacely-admin',
			'REPLACELY_I18N',
			array(
				'confirmReplace' => __( "You are about to update post content in the database. This action cannot be undone automatically.\n\nMake sure you have a recent backup. Continue?", 'replacely' ),
				'processing'     => __( 'Processing…', 'replacely' ),
				'run'            => __( 'Run Find & Replace', 'replacely' ),
				'previewLabel'   => __( 'Run Dry-Run Preview', 'replacely' ),
				'copied'         => __( 'Results copied to clipboard.', 'replacely' ),
				'copyFailed'     => __( 'Could not copy to clipboard.', 'replacely' ),
				'charsLabel'     => __( 'characters', 'replacely' ),
				'linesLabel'     => __( 'lines', 'replacely' ),
			)
		);
	}

	/**
	 * Handle the form POST for running a replacement (live or dry-run).
	 *
	 * Implements the POST-Redirect-GET pattern: process the submission, stash the
	 * payload in a short-lived per-user transient, then redirect back to the admin
	 * page so the user sees a clean URL and a refresh does not re-submit the form.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( ! current_user_can( Helper::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'replacely' ),
				esc_html__( 'Permission denied', 'replacely' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( Helper::NONCE_ACTION, Helper::NONCE_FIELD );

		// wp_unslash before any processing — WP slashes everything in $_POST.
		//
		// Search/replace are intentionally NOT passed through a sanitizer: this tool performs
		// exact, byte-for-byte find/replace and must preserve the user's literal input verbatim,
		// including HTML markup, whitespace, and multi-line text. Sanitizing would corrupt the
		// match. Access is gated by Helper::CAPABILITY + nonce above, and every value is escaped
		// on output (esc_textarea / esc_attr).
		$search_raw  = isset( $_POST['replacely_search'] ) ? (string) wp_unslash( $_POST['replacely_search'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$replace_raw = isset( $_POST['replacely_replace'] ) ? (string) wp_unslash( $_POST['replacely_replace'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$urls_raw    = isset( $_POST['replacely_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['replacely_urls'] ) ) : '';
		$dry_run     = isset( $_POST['replacely_dry_run'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['replacely_dry_run'] ) );

		$errors = $this->validate( $search_raw, $urls_raw );

		$payload = array(
			'submitted' => true,
			'dry_run'   => $dry_run,
			'search'    => $search_raw,
			'replace'   => $replace_raw,
			'urls'      => $urls_raw,
			'errors'    => $errors,
			'results'   => null,
		);

		if ( empty( $errors ) ) {
			$lines    = Helper::split_lines( $urls_raw );
			$replacer = new Replacer( $search_raw, $replace_raw, $dry_run );
			$results  = $replacer->process( $lines );

			$payload['results'] = $results;
			$this->store_results_for_export( $results );

			// Persist a log entry for every page where the content was actually
			// changed. Dry-run rows are intentionally excluded — nothing was
			// written to the database, so nothing belongs in the change log.
			if ( ! $dry_run && ! empty( $results['rows'] ) ) {
				$this->append_updated_rows_to_log( $results['rows'] );
			}
		}

		$this->store_state( $payload );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => Helper::PAGE_SLUG,
					'replacely_run_done' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Persist the most recent form state for the current user (15-minute TTL).
	 *
	 * @param array $payload Submitted state including results and errors.
	 * @return void
	 */
	private function store_state( array $payload ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient( 'replacely_last_state_' . $user_id, $payload, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Pull and clear the most recent form state for the current user.
	 *
	 * @return array|null
	 */
	private function consume_state() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		$key   = 'replacely_last_state_' . $user_id;
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			return null;
		}

		// One-shot read: clear so a refresh shows the empty form.
		delete_transient( $key );

		return wp_parse_args(
			$state,
			array(
				'submitted' => false,
				'dry_run'   => false,
				'search'    => '',
				'replace'   => '',
				'urls'      => '',
				'errors'    => array(),
				'results'   => null,
			)
		);
	}

	/**
	 * Persist the latest result rows in a short-lived transient keyed to the current user,
	 * so the CSV export endpoint can fetch the same data without re-processing.
	 *
	 * @param array $results Results array returned by the replacer.
	 * @return void
	 */
	private function store_results_for_export( $results ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient( 'replacely_last_results_' . $user_id, $results, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Take the result rows from a live run and append the successfully-updated
	 * ones to the persistent activity log, normalised to a compact shape.
	 *
	 * @param array $rows Result rows from {@see Replacer::process()}.
	 * @return void
	 */
	private function append_updated_rows_to_log( array $rows ) {
		$entries   = array();
		$user_id   = get_current_user_id();
		$user      = $user_id > 0 ? get_userdata( $user_id ) : false;
		$user_name = $user instanceof \WP_User ? $user->display_name : '';
		$timestamp = current_time( 'mysql' );

		foreach ( $rows as $row ) {
			if ( empty( $row['status'] ) || 'updated' !== $row['status'] ) {
				continue;
			}

			$entries[] = array(
				'timestamp'              => $timestamp,
				'user_id'                => (int) $user_id,
				'user_name'              => $user_name,
				'post_id'                => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
				'post_type'              => isset( $row['post_type'] ) ? (string) $row['post_type'] : '',
				'post_title'             => isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
				'input'                  => isset( $row['input'] ) ? (string) $row['input'] : '',
				'resolved_url'           => isset( $row['resolved_url'] ) ? (string) $row['resolved_url'] : '',
				'edit_link'              => isset( $row['edit_link'] ) ? (string) $row['edit_link'] : '',
				'view_link'              => isset( $row['view_link'] ) ? (string) $row['view_link'] : '',
				'replacements'           => isset( $row['replacements'] ) ? (int) $row['replacements'] : 0,
				'content_replacements'   => isset( $row['content_replacements'] ) ? (int) $row['content_replacements'] : 0,
				'elementor_replacements' => isset( $row['elementor_replacements'] ) ? (int) $row['elementor_replacements'] : 0,
			);
		}

		Helper::append_log( $entries );
	}

	/**
	 * Handle the "Clear log" action.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( Helper::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'replacely' ),
				esc_html__( 'Permission denied', 'replacely' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'replacely_clear_log' );

		Helper::clear_log();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => Helper::PAGE_SLUG,
					'replacely_log_cleared' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Validate form fields, returning a list of human-readable error messages.
	 *
	 * @param string $search Raw search text.
	 * @param string $urls   Raw URL list.
	 * @return string[]
	 */
	private function validate( $search, $urls ) {
		$errors = array();

		if ( '' === $search ) {
			$errors[] = __( 'Please enter the text you want to search for.', 'replacely' );
		}

		$lines = Helper::split_lines( $urls );
		if ( empty( $lines ) ) {
			$errors[] = __( 'Please enter at least one URL or path (one per line).', 'replacely' );
		}

		return $errors;
	}

	/**
	 * Stream the most recent results as a CSV download.
	 *
	 * @return void
	 */
	public function handle_csv_export() {
		if ( ! current_user_can( Helper::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'replacely' ),
				esc_html__( 'Permission denied', 'replacely' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'replacely_export_csv' );

		$user_id = get_current_user_id();
		$results = $user_id > 0 ? get_transient( 'replacely_last_results_' . $user_id ) : false;

		if ( ! is_array( $results ) || empty( $results['rows'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                    => Helper::PAGE_SLUG,
						'replacely_export_failed' => '1',
					),
					admin_url( 'tools.php' )
				)
			);
			exit;
		}

		$filename = sprintf( 'replacely-results-%s.csv', gmdate( 'Y-m-d_H-i-s' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// UTF-8 BOM so Excel renders the CSV with the correct encoding. Written straight to
		// the PHP output stream (the same destination fputcsv targets below).
		echo "\xEF\xBB\xBF";

		fputcsv(
			$out,
			array(
				__( 'Input', 'replacely' ),
				__( 'Resolved URL', 'replacely' ),
				__( 'Post ID', 'replacely' ),
				__( 'Post Type', 'replacely' ),
				__( 'Post Title', 'replacely' ),
				__( 'Status', 'replacely' ),
				__( 'Replacements', 'replacely' ),
				__( 'Content Replacements', 'replacely' ),
				__( 'Elementor Replacements', 'replacely' ),
				__( 'Message', 'replacely' ),
			)
		);

		foreach ( $results['rows'] as $row ) {
			fputcsv(
				$out,
				array_map(
					array( $this, 'csv_escape_formula' ),
					array(
						isset( $row['input'] ) ? $row['input'] : '',
						isset( $row['resolved_url'] ) ? $row['resolved_url'] : '',
						isset( $row['post_id'] ) ? $row['post_id'] : '',
						isset( $row['post_type'] ) ? $row['post_type'] : '',
						isset( $row['post_title'] ) ? $row['post_title'] : '',
						isset( $row['status'] ) ? Helper::status_label( $row['status'] ) : '',
						isset( $row['replacements'] ) ? $row['replacements'] : 0,
						isset( $row['content_replacements'] ) ? $row['content_replacements'] : 0,
						isset( $row['elementor_replacements'] ) ? $row['elementor_replacements'] : 0,
						isset( $row['message'] ) ? $row['message'] : '',
					)
				)
			);
		}

		// The php://output stream is flushed and closed automatically when the request ends.
		exit;
	}

	/**
	 * Neutralize CSV/spreadsheet formula injection in a cell value.
	 *
	 * Excel, LibreOffice Calc, and Google Sheets execute a cell whose text
	 * begins with =, +, -, @, or a tab/CR control character. Prefixing such
	 * values with a single quote forces them to be treated as plain text.
	 * Non-string values (e.g. integer counts) are returned unchanged.
	 *
	 * @param mixed $value Raw cell value.
	 * @return mixed
	 */
	private function csv_escape_formula( $value ) {
		if ( is_string( $value ) && '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Render the admin screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Helper::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'replacely' ) );
		}

		// If the user just came back from a submission, hydrate the form with the
		// stored state so they see results / errors / their original input.
		$stored = $this->consume_state();
		$state  = is_array( $stored ) ? $stored : $this->state;
		?>
		<div class="wrap replacely-wrap">
			<div class="replacely-header">
				<div class="replacely-header__title">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'Replacely', 'replacely' ); ?></h1>
						<p class="replacely-header__subtitle">
							<?php esc_html_e( 'Replace exact text inside any post, page, or custom post type — including pages built with Elementor — by listing the URLs or paths to process.', 'replacely' ); ?>
						</p>
					</div>
				</div>
				<div class="replacely-header__badges">
					<span class="replacely-badge replacely-badge--info">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php esc_html_e( 'Admin only', 'replacely' ); ?>
					</span>
					<span class="replacely-badge replacely-badge--info">
						<span class="dashicons dashicons-database-view" aria-hidden="true"></span>
						<?php esc_html_e( 'Exact match', 'replacely' ); ?>
					</span>
				</div>
			</div>

			<?php $this->render_notices( $state ); ?>

			<div class="replacely-grid">
				<div class="replacely-col-main">
					<?php $this->render_form( $state ); ?>
				</div>
				<aside class="replacely-col-side">
					<?php $this->render_sidebar(); ?>
				</aside>
			</div>

			<?php if ( $state['submitted'] && empty( $state['errors'] ) && is_array( $state['results'] ) ) : ?>
				<?php $this->render_results( $state['results'] ); ?>
			<?php endif; ?>

			<?php $this->render_activity_log(); ?>
		</div>
		<?php
	}

	/**
	 * Render top-of-screen notices (errors + backup recommendation + export failure).
	 *
	 * @param array $state Current render state.
	 * @return void
	 */
	private function render_notices( $state ) {
		if ( ! empty( $state['errors'] ) ) {
			echo '<div class="notice notice-error replacely-notice"><ul>';
			foreach ( $state['errors'] as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( isset( $_GET['replacely_export_failed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-warning replacely-notice"><p>' . esc_html__( 'No recent results to export. Run a find & replace first.', 'replacely' ) . '</p></div>';
		}

		if ( isset( $_GET['replacely_log_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success replacely-notice is-dismissible"><p>' . esc_html__( 'Activity log cleared.', 'replacely' ) . '</p></div>';
		}

		?>
		<div class="notice notice-warning replacely-notice replacely-notice--backup">
			<p>
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Backup first.', 'replacely' ); ?></strong>
				<?php esc_html_e( 'Bulk replacements directly modify post content in the database and cannot be undone automatically. Take a fresh backup before running a live replacement, or use Dry Run to preview changes safely.', 'replacely' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the main form card.
	 *
	 * @param array $state Current render state.
	 * @return void
	 */
	private function render_form( $state ) {
		$action_url = admin_url( 'admin-post.php' );
		?>
		<form class="replacely-card replacely-form" method="post" action="<?php echo esc_url( $action_url ); ?>" id="replacely-form" novalidate>
			<input type="hidden" name="action" value="replacely_run" />
			<?php wp_nonce_field( Helper::NONCE_ACTION, Helper::NONCE_FIELD ); ?>

			<div class="replacely-card__head">
				<h2>
					<span class="dashicons dashicons-edit" aria-hidden="true"></span>
					<?php esc_html_e( 'Find &amp; Replace Configuration', 'replacely' ); ?>
				</h2>
				<p class="replacely-card__subtitle">
					<?php esc_html_e( 'Matching is case-sensitive and exact. Spaces, line breaks, and HTML are preserved exactly.', 'replacely' ); ?>
				</p>
			</div>

			<div class="replacely-card__body">
				<div class="replacely-field">
					<label for="replacely_search">
						<?php esc_html_e( 'Search Text', 'replacely' ); ?>
						<span class="replacely-required" aria-hidden="true">*</span>
					</label>
					<textarea
						id="replacely_search"
						name="replacely_search"
						class="replacely-textarea replacely-textarea--medium large-text code"
						rows="6"
						required
						aria-required="true"
						placeholder="<?php esc_attr_e( 'Paste the exact text to find…', 'replacely' ); ?>"
						data-replacely-counter="replacely_search_counter"
					><?php echo esc_textarea( $state['search'] ); ?></textarea>
					<div class="replacely-field__meta">
						<p class="description"><?php esc_html_e( 'Exact, case-sensitive match only. No regex or wildcards.', 'replacely' ); ?></p>
						<span class="replacely-counter" id="replacely_search_counter" aria-live="polite">0</span>
					</div>
				</div>

				<div class="replacely-field">
					<label for="replacely_replace">
						<?php esc_html_e( 'Replace Text', 'replacely' ); ?>
						<span class="replacely-required" aria-hidden="true">*</span>
					</label>
					<textarea
						id="replacely_replace"
						name="replacely_replace"
						class="replacely-textarea replacely-textarea--medium large-text code"
						rows="6"
						placeholder="<?php esc_attr_e( 'Paste the exact replacement text…', 'replacely' ); ?>"
						data-replacely-counter="replacely_replace_counter"
					><?php echo esc_textarea( $state['replace'] ); ?></textarea>
					<div class="replacely-field__meta">
						<p class="description"><?php esc_html_e( 'The replacement is inserted exactly as entered. Leave empty to delete every match.', 'replacely' ); ?></p>
						<span class="replacely-counter" id="replacely_replace_counter" aria-live="polite">0</span>
					</div>
				</div>

				<div class="replacely-field">
					<label for="replacely_urls">
						<?php esc_html_e( 'URLs / Paths', 'replacely' ); ?>
						<span class="replacely-required" aria-hidden="true">*</span>
					</label>
					<textarea
						id="replacely_urls"
						name="replacely_urls"
						class="replacely-textarea replacely-textarea--tall large-text code"
						rows="10"
						required
						aria-required="true"
						placeholder="
						<?php
							echo esc_attr(
								"https://example.com/sample-page/\n/another-page/\n/blog/my-post/"
							);
						?>
						"
						data-replacely-lines="replacely_urls_counter"
					><?php echo esc_textarea( $state['urls'] ); ?></textarea>
					<div class="replacely-field__meta">
						<p class="description">
							<?php esc_html_e( 'One URL or path per line. Empty lines are ignored. Full URLs and relative paths are both supported.', 'replacely' ); ?>
						</p>
						<span class="replacely-counter" id="replacely_urls_counter" aria-live="polite">0</span>
					</div>
				</div>

				<div class="replacely-field replacely-field--inline">
					<label class="replacely-toggle" for="replacely_dry_run">
						<input
							type="checkbox"
							id="replacely_dry_run"
							name="replacely_dry_run"
							value="1"
							<?php checked( true, ! empty( $state['dry_run'] ) ); ?>
						/>
						<span class="replacely-toggle__slider" aria-hidden="true"></span>
						<span class="replacely-toggle__label">
							<strong><?php esc_html_e( 'Dry Run (Preview Only)', 'replacely' ); ?></strong>
							<span class="replacely-toggle__hint"><?php esc_html_e( 'Show what would change without writing to the database.', 'replacely' ); ?></span>
						</span>
					</label>
				</div>
			</div>

			<div class="replacely-actionbar">
				<div class="replacely-actionbar__left">
					<span class="replacely-actionbar__hint">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<?php esc_html_e( 'Tip: run a Dry Run first to verify your matches.', 'replacely' ); ?>
					</span>
				</div>
				<div class="replacely-actionbar__right">
					<button
						type="submit"
						class="button button-primary button-hero replacely-submit"
						id="replacely-submit"
						data-confirm-on-live="1"
					>
						<span class="replacely-submit__label">
							<?php
							if ( ! empty( $state['dry_run'] ) ) {
								esc_html_e( 'Run Dry-Run Preview', 'replacely' );
							} else {
								esc_html_e( 'Run Find &amp; Replace', 'replacely' );
							}
							?>
						</span>
						<span class="replacely-submit__spinner" aria-hidden="true"></span>
					</button>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the right-hand sidebar with help text.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		?>
		<div class="replacely-card replacely-card--side">
			<div class="replacely-card__head">
				<h2>
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<?php esc_html_e( 'How it works', 'replacely' ); ?>
				</h2>
			</div>
			<div class="replacely-card__body">
				<ol class="replacely-steps">
					<li><?php esc_html_e( 'Paste the exact text to find and the exact text to replace it with.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'List the URLs or relative paths of the posts you want to update — one per line.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Enable Dry Run to preview the changes, then disable it to apply them.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Review the results dashboard and export a CSV for your records.', 'replacely' ); ?></li>
				</ol>
			</div>
		</div>

		<div class="replacely-card replacely-card--side">
			<div class="replacely-card__head">
				<h2>
					<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'Safety', 'replacely' ); ?>
				</h2>
			</div>
			<div class="replacely-card__body">
				<ul class="replacely-bullets">
					<li><?php esc_html_e( 'Capability-gated to administrators only.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Nonce-verified form submission.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Skips revisions, auto-drafts, and trashed posts.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Elementor page content is updated too, and its CSS cache is refreshed automatically.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Duplicate URLs are processed only once.', 'replacely' ); ?></li>
					<li><?php esc_html_e( 'Exact, case-sensitive matching — no regex surprises.', 'replacely' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the results dashboard + table.
	 *
	 * @param array $results Results array from the replacer.
	 * @return void
	 */
	private function render_results( array $results ) {
		$summary = isset( $results['summary'] ) ? $results['summary'] : array();
		$rows    = isset( $results['rows'] ) ? $results['rows'] : array();

		$is_dry = ! empty( $summary['dry_run'] );

		$export_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'replacely_export_csv' ),
				admin_url( 'admin-post.php' )
			),
			'replacely_export_csv'
		);

		$processed_at = ! empty( $summary['timestamp'] )
			? mysql2date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				$summary['timestamp']
			)
			: '';

		?>
		<section class="replacely-card replacely-results" id="replacely-results">
			<div class="replacely-card__head replacely-results__head">
				<h2>
					<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
					<?php
					if ( $is_dry ) {
						esc_html_e( 'Dry Run Preview Results', 'replacely' );
					} else {
						esc_html_e( 'Replacement Results', 'replacely' );
					}
					?>
				</h2>
				<div class="replacely-results__meta">
					<?php if ( $processed_at ) : ?>
						<span class="replacely-meta">
							<span class="dashicons dashicons-clock" aria-hidden="true"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: formatted date and time. */
									__( 'Processed at %s', 'replacely' ),
									$processed_at
								)
							);
							?>
						</span>
					<?php endif; ?>
					<span class="replacely-meta">
						<span class="dashicons dashicons-performance" aria-hidden="true"></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: formatted duration. */
								__( 'Took %s', 'replacely' ),
								Helper::format_duration( isset( $summary['duration'] ) ? (float) $summary['duration'] : 0 )
							)
						);
						?>
					</span>
				</div>
			</div>

			<?php $this->render_summary_tiles( $summary ); ?>

			<?php $this->render_updated_pages_panel( $rows, $is_dry ); ?>

			<div class="replacely-results__toolbar">
				<button type="button" class="button replacely-copy" data-replacely-copy>
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<?php esc_html_e( 'Copy Results', 'replacely' ); ?>
				</button>
				<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php esc_html_e( 'Export CSV', 'replacely' ); ?>
				</a>
				<span class="replacely-results__status" aria-live="polite"></span>
			</div>

			<?php $this->render_results_table( $rows ); ?>
		</section>
		<?php
	}

	/**
	 * Summary tile grid.
	 *
	 * @param array $summary Summary stats.
	 * @return void
	 */
	private function render_summary_tiles( array $summary ) {
		$tiles = array(
			array(
				'tone'  => 'neutral',
				'icon'  => 'list-view',
				'label' => __( 'URLs processed', 'replacely' ),
				'value' => isset( $summary['total'] ) ? (int) $summary['total'] : 0,
			),
			array(
				'tone'  => 'success',
				'icon'  => 'yes-alt',
				'label' => __( 'Successful updates', 'replacely' ),
				'value' => isset( $summary['updated'] ) ? (int) $summary['updated'] : 0,
			),
			array(
				'tone'  => 'info',
				'icon'  => 'visibility',
				'label' => __( 'Preview matches', 'replacely' ),
				'value' => isset( $summary['previewed'] ) ? (int) $summary['previewed'] : 0,
			),
			array(
				'tone'  => 'warning',
				'icon'  => 'minus',
				'label' => __( 'No matches', 'replacely' ),
				'value' => isset( $summary['no_match'] ) ? (int) $summary['no_match'] : 0,
			),
			array(
				'tone'  => 'error',
				'icon'  => 'dismiss',
				'label' => __( 'Invalid URLs', 'replacely' ),
				'value' => isset( $summary['invalid'] ) ? (int) $summary['invalid'] : 0,
			),
			array(
				'tone'  => 'error',
				'icon'  => 'warning',
				'label' => __( 'Failed', 'replacely' ),
				'value' => isset( $summary['failed'] ) ? (int) $summary['failed'] : 0,
			),
			array(
				'tone'  => 'neutral',
				'icon'  => 'controls-repeat',
				'label' => __( 'Total replacements', 'replacely' ),
				'value' => isset( $summary['total_replacements'] ) ? (int) $summary['total_replacements'] : 0,
			),
			array(
				'tone'  => 'info',
				'icon'  => 'media-text',
				'label' => __( 'Content replacements', 'replacely' ),
				'value' => isset( $summary['content_replacements'] ) ? (int) $summary['content_replacements'] : 0,
			),
			array(
				'tone'  => 'info',
				'icon'  => 'layout',
				'label' => __( 'Elementor replacements', 'replacely' ),
				'value' => isset( $summary['elementor_replacements'] ) ? (int) $summary['elementor_replacements'] : 0,
			),
		);

		echo '<div class="replacely-tiles">';
		foreach ( $tiles as $tile ) {
			?>
			<div class="replacely-tile replacely-tile--<?php echo esc_attr( $tile['tone'] ); ?>">
				<span class="replacely-tile__icon dashicons dashicons-<?php echo esc_attr( $tile['icon'] ); ?>" aria-hidden="true"></span>
				<div class="replacely-tile__body">
					<div class="replacely-tile__value"><?php echo esc_html( number_format_i18n( $tile['value'] ) ); ?></div>
					<div class="replacely-tile__label"><?php echo esc_html( $tile['label'] ); ?></div>
				</div>
			</div>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Build the per-source replacement breakdown markup (classic content vs.
	 * Elementor). Used by the results table, the updated-pages panel, and the
	 * activity log so the user can always see where the replacements happened.
	 *
	 * Outputs nothing when neither source recorded a replacement — e.g. legacy
	 * activity-log entries written before this split existed — so callers
	 * gracefully fall back to showing just the total.
	 *
	 * @param int $content_count   Replacements made in classic post_content.
	 * @param int $elementor_count Replacements made in Elementor (_elementor_data).
	 * @return void
	 */
	private function render_replacement_breakdown( $content_count, $elementor_count ) {
		$content_count   = (int) $content_count;
		$elementor_count = (int) $elementor_count;

		if ( $content_count <= 0 && $elementor_count <= 0 ) {
			return;
		}
		?>
		<span class="replacely-rep-breakdown">
			<?php if ( $content_count > 0 ) : ?>
				<span class="replacely-rep-chip replacely-rep-chip--content">
					<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of replacements made in classic post content. */
							__( 'Content: %s', 'replacely' ),
							number_format_i18n( $content_count )
						)
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( $elementor_count > 0 ) : ?>
				<span class="replacely-rep-chip replacely-rep-chip--elementor">
					<span class="dashicons dashicons-layout" aria-hidden="true"></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of replacements made in Elementor content. */
							__( 'Elementor: %s', 'replacely' ),
							number_format_i18n( $elementor_count )
						)
					);
					?>
				</span>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Render a focused panel that lists only the pages where content actually
	 * changed in this run (or would change, in dry-run mode), each with View /
	 * Edit links. This sits above the full results table so the user always
	 * sees the impactful rows first.
	 *
	 * @param array $rows   Result rows.
	 * @param bool  $is_dry Whether this run was a dry-run.
	 * @return void
	 */
	private function render_updated_pages_panel( array $rows, $is_dry ) {
		$target_status = $is_dry ? 'preview' : 'updated';

		$matched = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['status'] ) && $row['status'] === $target_status ) {
				$matched[] = $row;
			}
		}

		if ( empty( $matched ) ) {
			return;
		}

		$heading = $is_dry
			? __( 'Pages that would be updated', 'replacely' )
			: __( 'Pages updated in this run', 'replacely' );

		$intro = $is_dry
			? __( 'Dry run — nothing was written to the database. Disable Dry Run and re-submit to apply these changes.', 'replacely' )
			: __( 'These pages were modified in the database. Use View / Edit to verify each change.', 'replacely' );

		?>
		<div class="replacely-updated-panel">
			<div class="replacely-updated-panel__head">
				<h3>
					<span class="dashicons dashicons-edit-page" aria-hidden="true"></span>
					<?php echo esc_html( $heading ); ?>
					<span class="replacely-updated-panel__count">
						<?php echo esc_html( number_format_i18n( count( $matched ) ) ); ?>
					</span>
				</h3>
				<p class="replacely-updated-panel__intro"><?php echo esc_html( $intro ); ?></p>
			</div>
			<ul class="replacely-updated-list">
				<?php foreach ( $matched as $row ) : ?>
					<?php
					$title          = ! empty( $row['post_title'] ) ? $row['post_title'] : __( '(no title)', 'replacely' );
					$post_id        = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
					$post_type      = isset( $row['post_type'] ) ? $row['post_type'] : '';
					$reps           = isset( $row['replacements'] ) ? (int) $row['replacements'] : 0;
					$content_reps   = isset( $row['content_replacements'] ) ? (int) $row['content_replacements'] : 0;
					$elementor_reps = isset( $row['elementor_replacements'] ) ? (int) $row['elementor_replacements'] : 0;
					$edit_url       = ! empty( $row['edit_link'] ) ? $row['edit_link'] : '';
					$view_url       = ! empty( $row['view_link'] ) ? $row['view_link'] : '';
					?>
					<li class="replacely-updated-item">
						<div class="replacely-updated-item__main">
							<div class="replacely-updated-item__title">
								<span class="dashicons dashicons-<?php echo esc_attr( $is_dry ? 'visibility' : 'yes-alt' ); ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $title ); ?></strong>
							</div>
							<div class="replacely-updated-item__meta">
								<?php if ( $post_type ) : ?>
									<code class="replacely-pill"><?php echo esc_html( $post_type ); ?></code>
								<?php endif; ?>
								<?php if ( $post_id ) : ?>
									<span><?php echo esc_html( sprintf( /* translators: %d: post ID. */ __( 'ID: %d', 'replacely' ), $post_id ) ); ?></span>
								<?php endif; ?>
								<span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: number of replacements. */
											_n( '%s replacement', '%s replacements', $reps, 'replacely' ),
											number_format_i18n( $reps )
										)
									);
									?>
								</span>
								<?php $this->render_replacement_breakdown( $content_reps, $elementor_reps ); ?>
								<?php if ( ! empty( $row['resolved_url'] ) ) : ?>
									<code class="replacely-updated-item__url"><?php echo esc_html( $row['resolved_url'] ); ?></code>
								<?php endif; ?>
							</div>
						</div>
						<div class="replacely-updated-item__actions">
							<?php if ( $view_url ) : ?>
								<a class="button button-secondary" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer">
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<?php esc_html_e( 'View', 'replacely' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $edit_url ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer">
									<span class="dashicons dashicons-edit" aria-hidden="true"></span>
									<?php esc_html_e( 'Edit', 'replacely' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the persistent activity log card.
	 *
	 * The log records every page where post content was actually changed
	 * (live runs only), with a View and Edit link per entry. Capped at
	 * {@see Helper::LOG_LIMIT} entries.
	 *
	 * @return void
	 */
	private function render_activity_log() {
		$entries = Helper::get_log();

		$clear_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'replacely_clear_log' ),
				admin_url( 'admin-post.php' )
			),
			'replacely_clear_log'
		);

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<section class="replacely-card replacely-log" id="replacely-activity-log">
			<div class="replacely-card__head replacely-log__head">
				<h2>
					<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
					<?php esc_html_e( 'Activity Log — Recently Updated Pages', 'replacely' ); ?>
					<?php if ( ! empty( $entries ) ) : ?>
						<span class="replacely-log__count">
							<?php echo esc_html( number_format_i18n( count( $entries ) ) ); ?>
						</span>
					<?php endif; ?>
				</h2>
				<?php if ( ! empty( $entries ) ) : ?>
					<a
						class="button button-link-delete replacely-log__clear"
						href="<?php echo esc_url( $clear_url ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Clear the activity log? This only deletes the log itself, not the post changes.', 'replacely' ) ); ?>');"
					>
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Clear log', 'replacely' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<div class="replacely-empty">
					<span class="dashicons dashicons-archive" aria-hidden="true"></span>
					<p><?php esc_html_e( 'No pages have been updated yet. Run a live replacement to start the log.', 'replacely' ); ?></p>
				</div>
			<?php else : ?>
				<div class="replacely-table-wrap">
					<table class="widefat striped replacely-table replacely-log__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'replacely' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Page', 'replacely' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'replacely' ); ?></th>
								<th scope="col" class="replacely-num"><?php esc_html_e( 'Replacements', 'replacely' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'replacely' ); ?></th>
								<th scope="col" class="replacely-log__actions-col"><?php esc_html_e( 'Actions', 'replacely' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<?php
								$title          = ! empty( $entry['post_title'] ) ? $entry['post_title'] : __( '(no title)', 'replacely' );
								$when           = ! empty( $entry['timestamp'] ) ? mysql2date( $date_format, $entry['timestamp'] ) : '';
								$post_id        = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
								$post_type      = isset( $entry['post_type'] ) ? $entry['post_type'] : '';
								$reps           = isset( $entry['replacements'] ) ? (int) $entry['replacements'] : 0;
								$content_reps   = isset( $entry['content_replacements'] ) ? (int) $entry['content_replacements'] : 0;
								$elementor_reps = isset( $entry['elementor_replacements'] ) ? (int) $entry['elementor_replacements'] : 0;
								$edit_url       = ! empty( $entry['edit_link'] ) ? $entry['edit_link'] : '';
								$view_url       = ! empty( $entry['view_link'] ) ? $entry['view_link'] : '';
								$user_name      = ! empty( $entry['user_name'] ) ? $entry['user_name'] : __( '—', 'replacely' );
								?>
								<tr>
									<td>
										<span class="replacely-log__when"><?php echo esc_html( $when ); ?></span>
									</td>
									<td>
										<strong><?php echo esc_html( $title ); ?></strong>
										<div class="replacely-cell-post__meta">
											<?php if ( $post_id ) : ?>
												<span><?php echo esc_html( sprintf( /* translators: %d: post ID. */ __( 'ID: %d', 'replacely' ), $post_id ) ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $entry['resolved_url'] ) ) : ?>
												<code><?php echo esc_html( $entry['resolved_url'] ); ?></code>
											<?php endif; ?>
										</div>
									</td>
									<td>
										<?php if ( $post_type ) : ?>
											<code class="replacely-pill"><?php echo esc_html( $post_type ); ?></code>
										<?php else : ?>
											<span class="replacely-muted">—</span>
										<?php endif; ?>
									</td>
									<td class="replacely-num">
										<?php echo esc_html( number_format_i18n( $reps ) ); ?>
										<?php $this->render_replacement_breakdown( $content_reps, $elementor_reps ); ?>
									</td>
									<td><?php echo esc_html( $user_name ); ?></td>
									<td class="replacely-log__actions">
										<?php if ( $view_url ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer">
												<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
												<?php esc_html_e( 'View', 'replacely' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( $edit_url ) : ?>
											<a class="button button-small button-primary" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer">
												<span class="dashicons dashicons-edit" aria-hidden="true"></span>
												<?php esc_html_e( 'Edit', 'replacely' ); ?>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="replacely-log__footnote">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: log cap. */
							__( 'The log keeps the %d most recent updates. Older entries are pruned automatically.', 'replacely' ),
							(int) Helper::LOG_LIMIT
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Detailed results table.
	 *
	 * @param array $rows Result rows.
	 * @return void
	 */
	private function render_results_table( array $rows ) {
		if ( empty( $rows ) ) {
			?>
			<div class="replacely-empty">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<p><?php esc_html_e( 'No rows to display.', 'replacely' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<div class="replacely-table-wrap">
			<table class="widefat striped replacely-table" id="replacely-results-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '#', 'replacely' ); ?></th>
						<th scope="col"><?php esc_html_e( 'URL / Path', 'replacely' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Post', 'replacely' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'replacely' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'replacely' ); ?></th>
						<th scope="col" class="replacely-num"><?php esc_html_e( 'Replacements', 'replacely' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'replacely' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $index => $row ) : ?>
						<?php
						$status   = isset( $row['status'] ) ? $row['status'] : 'failed';
						$tone     = Helper::status_tone( $status );
						$icon     = Helper::status_dashicon( $status );
						$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
						$edit_url = ! empty( $row['edit_link'] ) ? $row['edit_link'] : '';
						$view_url = ! empty( $row['view_link'] ) ? $row['view_link'] : '';
						$title    = isset( $row['post_title'] ) && '' !== $row['post_title']
							? $row['post_title']
							: __( '—', 'replacely' );
						?>
						<tr class="replacely-row replacely-row--<?php echo esc_attr( $tone ); ?>">
							<td class="replacely-num"><?php echo esc_html( number_format_i18n( $index + 1 ) ); ?></td>
							<td class="replacely-cell-url">
								<code class="replacely-code"><?php echo esc_html( isset( $row['input'] ) ? $row['input'] : '' ); ?></code>
								<?php if ( ! empty( $row['resolved_url'] ) && ( isset( $row['input'] ) ? $row['input'] : '' ) !== $row['resolved_url'] ) : ?>
									<div class="replacely-cell-url__resolved">
										<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
										<code><?php echo esc_html( $row['resolved_url'] ); ?></code>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $post_id > 0 ) : ?>
									<div class="replacely-cell-post">
										<strong><?php echo esc_html( $title ); ?></strong>
										<div class="replacely-cell-post__meta">
											<span><?php echo esc_html( sprintf( /* translators: %d: post ID. */ __( 'ID: %d', 'replacely' ), $post_id ) ); ?></span>
											<?php if ( $edit_url ) : ?>
												<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer">
													<?php esc_html_e( 'Edit', 'replacely' ); ?>
												</a>
											<?php endif; ?>
											<?php if ( $view_url ) : ?>
												<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer">
													<?php esc_html_e( 'View', 'replacely' ); ?>
												</a>
											<?php endif; ?>
										</div>
									</div>
								<?php else : ?>
									<span class="replacely-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $row['post_type'] ) ) : ?>
									<code class="replacely-pill"><?php echo esc_html( $row['post_type'] ); ?></code>
								<?php else : ?>
									<span class="replacely-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<span class="replacely-status replacely-status--<?php echo esc_attr( $tone ); ?>">
									<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
									<?php echo esc_html( Helper::status_label( $status ) ); ?>
								</span>
							</td>
							<td class="replacely-num">
								<?php echo esc_html( number_format_i18n( isset( $row['replacements'] ) ? (int) $row['replacements'] : 0 ) ); ?>
								<?php
								$this->render_replacement_breakdown(
									isset( $row['content_replacements'] ) ? (int) $row['content_replacements'] : 0,
									isset( $row['elementor_replacements'] ) ? (int) $row['elementor_replacements'] : 0
								);
								?>
							</td>
							<td class="replacely-cell-message">
								<?php echo esc_html( isset( $row['message'] ) ? $row['message'] : '' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
