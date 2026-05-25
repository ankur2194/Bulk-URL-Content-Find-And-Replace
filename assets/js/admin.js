/**
 * Replacely — admin enhancements.
 *
 * Progressive enhancement only: every interaction here has a server-side
 * equivalent so the plugin remains fully functional without JavaScript.
 */
(function ($) {
	'use strict';

	var i18n = window.REPLACELY_I18N || {};

	function initCounters() {
		// Character counters (data-replacely-counter -> element id of the counter).
		$('[data-replacely-counter]').each(function () {
			var $field   = $(this);
			var counter  = $field.data('replacely-counter');
			var $counter = $('#' + counter);
			if (!$counter.length) {
				return;
			}

			var update = function () {
				var length = ($field.val() || '').length;
				$counter.text(
					length.toLocaleString() + ' ' + (i18n.charsLabel || 'characters')
				);
			};
			update();
			$field.on('input keyup change paste', update);
		});

		// Line counters for the URLs field.
		$('[data-replacely-lines]').each(function () {
			var $field   = $(this);
			var counter  = $field.data('replacely-lines');
			var $counter = $('#' + counter);
			if (!$counter.length) {
				return;
			}

			var update = function () {
				var raw = $field.val() || '';
				if (!raw.trim()) {
					$counter.text('0 ' + (i18n.linesLabel || 'lines'));
					return;
				}
				var lines = raw.split(/\r\n|\r|\n/).filter(function (line) {
					return line.trim().length > 0;
				});
				$counter.text(
					lines.length.toLocaleString() + ' ' + (i18n.linesLabel || 'lines')
				);
			};
			update();
			$field.on('input keyup change paste', update);
		});
	}

	function initSubmit() {
		var $form    = $('#replacely-form');
		var $submit  = $('#replacely-submit');
		var $dryRun  = $('#replacely_dry_run');
		var $label   = $submit.find('.replacely-submit__label');

		if (!$form.length || !$submit.length) {
			return;
		}

		// Keep the submit button label synced with the dry-run state.
		var syncLabel = function () {
			if ($dryRun.is(':checked')) {
				$label.text(i18n.previewLabel || 'Run Dry-Run Preview');
			} else {
				$label.text(i18n.run || 'Run Find & Replace');
			}
		};
		syncLabel();
		$dryRun.on('change', syncLabel);

		$form.on('submit', function (event) {
			// Only confirm before live (non-dry-run) replacements.
			if (!$dryRun.is(':checked')) {
				var ok = window.confirm(
					i18n.confirmReplace ||
						'You are about to update post content. Continue?'
				);
				if (!ok) {
					event.preventDefault();
					return false;
				}
			}

			// Loading state.
			$submit.prop('disabled', true).addClass('is-loading');
			$label.text(i18n.processing || 'Processing…');
		});
	}

	function initCopyResults() {
		$(document).on('click', '[data-replacely-copy]', function (event) {
			event.preventDefault();
			var $btn    = $(this);
			var $table  = $('#replacely-results-table');
			var $status = $('.replacely-results__status').first();

			if (!$table.length) {
				return;
			}

			var rows = [];

			// Header row.
			var headers = [];
			$table.find('thead th').each(function () {
				headers.push($(this).text().trim());
			});
			rows.push(headers.join('\t'));

			// Body rows.
			$table.find('tbody tr').each(function () {
				var cells = [];
				$(this).find('td').each(function () {
					cells.push(
						$(this)
							.text()
							.replace(/\s+/g, ' ')
							.trim()
					);
				});
				rows.push(cells.join('\t'));
			});

			var text = rows.join('\n');

			var done = function (success) {
				if (!$status.length) {
					return;
				}
				$status
					.removeClass('is-success is-error')
					.addClass(success ? 'is-success' : 'is-error')
					.text(
						success
							? i18n.copied || 'Copied.'
							: i18n.copyFailed || 'Copy failed.'
					);
				window.setTimeout(function () {
					$status.removeClass('is-success is-error').text('');
				}, 3500);
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard
					.writeText(text)
					.then(function () { done(true); })
					.catch(function () { fallbackCopy(text, done); });
			} else {
				fallbackCopy(text, done);
			}
		});
	}

	function fallbackCopy(text, done) {
		var $temp = $('<textarea>')
			.css({ position: 'fixed', top: 0, left: 0, opacity: 0 })
			.val(text)
			.appendTo('body');
		$temp[0].select();
		try {
			var ok = document.execCommand('copy');
			done(ok);
		} catch (e) {
			done(false);
		}
		$temp.remove();
	}

	$(function () {
		initCounters();
		initSubmit();
		initCopyResults();
	});
})(jQuery);
