(function ($) {
	'use strict';

	var $scanStatus = $('#rd3-pic-scan-status');
	var $cleanupStatus = $('#rd3-pic-cleanup-status');
	var $largeStatus = $('#rd3-pic-large-status');
	var $resultsCard = $('#rd3-pic-results-card');
	var $results = $('#rd3-pic-results');
	var $scanBtn = $('#rd3-pic-scan-btn');
	var $clearBtn = $('#rd3-pic-clear-btn');
	var $cleanupBtn = $('#rd3-pic-cleanup-btn');
	var $cleanupSummary = $('#rd3-pic-cleanup-summary');
	var $log = $('#rd3-pic-log');
	var $clearLogBtn = $('#rd3-pic-clear-log-btn');
	var $scanLargeBtn = $('#rd3-pic-scan-large-btn');
	var $downsizeBtn = $('#rd3-pic-downsize-btn');
	var $largeResults = $('#rd3-pic-large-results');

	function setStatus($el, msg, type) {
		$el
			.removeClass('is-busy is-success is-error')
			.addClass(type ? 'is-' + type : '')
			.text(msg || '');
	}

	$scanBtn.on('click', function () {
		if ($scanBtn.prop('disabled')) {
			return;
		}
		$scanBtn.prop('disabled', true);
		$cleanupBtn.prop('disabled', true);
		setStatus($scanStatus, rd3Pic.i18n.scanning, 'busy');

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_scan',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($scanStatus, res.data.message || rd3Pic.i18n.scanDone, 'success');
					$results.html(res.data.html || '');
					$resultsCard.show();
					if (res.data.has_groups) {
						$cleanupBtn.prop('disabled', false);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.scanError;
					setStatus($scanStatus, msg, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.scanError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($scanStatus, msg, 'error');
			})
			.always(function () {
				$scanBtn.prop('disabled', false);
			});
	});

	$clearBtn.on('click', function () {
		if (!window.confirm(rd3Pic.i18n.confirmClear)) {
			return;
		}
		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_clear_results',
			nonce: rd3Pic.nonce
		}).done(function (res) {
			if (res && res.success) {
				$results.empty();
				$resultsCard.hide();
				$cleanupBtn.prop('disabled', true);
				$cleanupSummary.hide().empty();
				setStatus($scanStatus, res.data.message || '', 'success');
			}
		});
	});

	$cleanupBtn.on('click', function () {
		if ($cleanupBtn.prop('disabled')) {
			return;
		}
		if (!window.confirm(rd3Pic.i18n.confirmCleanup)) {
			return;
		}

		$cleanupBtn.prop('disabled', true);
		$scanBtn.prop('disabled', true);
		setStatus($cleanupStatus, rd3Pic.i18n.cleaning, 'busy');
		$cleanupSummary.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_cleanup',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($cleanupStatus, res.data.message || rd3Pic.i18n.cleanupDone, 'success');
					if (res.data.summaryHtml) {
						$cleanupSummary.html(res.data.summaryHtml).show();
					}
					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
					$results.empty();
					$resultsCard.hide();
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.cleanupError;
					setStatus($cleanupStatus, msg, 'error');
					if (res && res.data && res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.cleanupError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($cleanupStatus, msg, 'error');
			})
			.always(function () {
				$scanBtn.prop('disabled', false);
				$cleanupBtn.prop('disabled', true);
			});
	});

	$scanLargeBtn.on('click', function () {
		if ($scanLargeBtn.prop('disabled')) {
			return;
		}
		$scanLargeBtn.prop('disabled', true);
		$downsizeBtn.prop('disabled', true);
		setStatus($largeStatus, rd3Pic.i18n.scanningLarge, 'busy');
		$largeResults.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_scan_large',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($largeStatus, res.data.message || rd3Pic.i18n.scanLargeDone, 'success');
					$largeResults.html(res.data.html || '').show();
					if (res.data.largeCount > 0) {
						$downsizeBtn.prop('disabled', false);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.scanError;
					setStatus($largeStatus, msg, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.scanError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($largeStatus, msg, 'error');
			})
			.always(function () {
				$scanLargeBtn.prop('disabled', false);
			});
	});

	$downsizeBtn.on('click', function () {
		if ($downsizeBtn.prop('disabled')) {
			return;
		}
		if (!window.confirm(rd3Pic.i18n.confirmDownsize)) {
			return;
		}
		$downsizeBtn.prop('disabled', true);
		$scanLargeBtn.prop('disabled', true);
		setStatus($largeStatus, rd3Pic.i18n.downsizing, 'busy');

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_downsize',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($largeStatus, res.data.message || rd3Pic.i18n.downsizeDone, 'success');
					if (res.data.summaryHtml) {
						$largeResults.html(res.data.summaryHtml).show();
					}
					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.downsizeError;
					setStatus($largeStatus, msg, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.downsizeError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($largeStatus, msg, 'error');
			})
			.always(function () {
				$scanLargeBtn.prop('disabled', false);
				$downsizeBtn.prop('disabled', true);
			});
	});


	var $namedInput = $('#rd3-pic-named-input');
	var $scanNamedBtn = $('#rd3-pic-scan-named-btn');
	var $downsizeNamedBtn = $('#rd3-pic-downsize-named-btn');
	var $namedStatus = $('#rd3-pic-named-status');
	var $namedResults = $('#rd3-pic-named-results');
	var lastNamedFilename = '';

	$scanNamedBtn.on('click', function () {
		var name = $.trim($namedInput.val() || '');
		if (!name) {
			setStatus($namedStatus, rd3Pic.i18n.namedNeedName, 'error');
			return;
		}
		$scanNamedBtn.prop('disabled', true);
		$downsizeNamedBtn.prop('disabled', true);
		setStatus($namedStatus, rd3Pic.i18n.namedScanning, 'busy');
		$namedResults.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_scan_named',
			nonce: rd3Pic.nonce,
			filename: name
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($namedStatus, res.data.message || rd3Pic.i18n.namedScanDone, 'success');
					$namedResults.html(res.data.html || '').show();
					lastNamedFilename = res.data.filename || name;
					if (res.data.canRun) {
						$downsizeNamedBtn.prop('disabled', false);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.scanError;
					setStatus($namedStatus, msg, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.scanError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($namedStatus, msg, 'error');
			})
			.always(function () {
				$scanNamedBtn.prop('disabled', false);
			});
	});

	$downsizeNamedBtn.on('click', function () {
		if ($downsizeNamedBtn.prop('disabled')) {
			return;
		}
		var name = lastNamedFilename || $.trim($namedInput.val() || '');
		if (!name) {
			setStatus($namedStatus, rd3Pic.i18n.namedNeedName, 'error');
			return;
		}
		if (!window.confirm(rd3Pic.i18n.namedConfirm)) {
			return;
		}
		$downsizeNamedBtn.prop('disabled', true);
		$scanNamedBtn.prop('disabled', true);
		setStatus($namedStatus, rd3Pic.i18n.namedWorking, 'busy');

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_downsize_named',
			nonce: rd3Pic.nonce,
			filename: name
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($namedStatus, res.data.message || rd3Pic.i18n.namedDone, 'success');
					if (res.data.summaryHtml) {
						$namedResults.html(res.data.summaryHtml).show();
					}
					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.downsizeError;
					setStatus($namedStatus, msg, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.downsizeError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($namedStatus, msg, 'error');
			})
			.always(function () {
				$scanNamedBtn.prop('disabled', false);
				$downsizeNamedBtn.prop('disabled', true);
			});
	});

	$namedInput.on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$scanNamedBtn.trigger('click');
		}
	});


	var $mergeKeep = $('#rd3-pic-merge-keep');
	var $mergeRemove = $('#rd3-pic-merge-remove');
	var $mergePreviewBtn = $('#rd3-pic-merge-preview-btn');
	var $mergeRunBtn = $('#rd3-pic-merge-run-btn');
	var $mergeStatus = $('#rd3-pic-merge-status');
	var $mergeResults = $('#rd3-pic-merge-results');
	var lastMergeKeep = '';
	var lastMergeRemove = '';

	$mergePreviewBtn.on('click', function () {
		var keep = $.trim($mergeKeep.val() || '');
		var remove = $.trim($mergeRemove.val() || '');
		if (!keep || !remove) {
			setStatus($mergeStatus, rd3Pic.i18n.mergeNeedNames, 'error');
			return;
		}
		$mergePreviewBtn.prop('disabled', true);
		$mergeRunBtn.prop('disabled', true);
		setStatus($mergeStatus, rd3Pic.i18n.mergePreviewing, 'busy');
		$mergeResults.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_merge_preview',
			nonce: rd3Pic.nonce,
			keep: keep,
			remove: remove
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($mergeStatus, res.data.message || rd3Pic.i18n.mergePreviewDone, 'success');
					$mergeResults.html(res.data.html || '').show();
					lastMergeKeep = res.data.keep || keep;
					lastMergeRemove = res.data.remove || remove;
					if (res.data.canRun) {
						$mergeRunBtn.prop('disabled', false);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.mergeError;
					setStatus($mergeStatus, msg, 'error');
					if (res && res.data && res.data.html) {
						$mergeResults.html(res.data.html).show();
					}
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.mergeError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($mergeStatus, msg, 'error');
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.html) {
					$mergeResults.html(xhr.responseJSON.data.html).show();
				}
			})
			.always(function () {
				$mergePreviewBtn.prop('disabled', false);
			});
	});

	$mergeRunBtn.on('click', function () {
		if ($mergeRunBtn.prop('disabled')) {
			return;
		}
		var keep = lastMergeKeep || $.trim($mergeKeep.val() || '');
		var remove = lastMergeRemove || $.trim($mergeRemove.val() || '');
		if (!keep || !remove) {
			setStatus($mergeStatus, rd3Pic.i18n.mergeNeedNames, 'error');
			return;
		}
		if (!window.confirm(rd3Pic.i18n.mergeConfirm)) {
			return;
		}
		$mergeRunBtn.prop('disabled', true);
		$mergePreviewBtn.prop('disabled', true);
		setStatus($mergeStatus, rd3Pic.i18n.mergeWorking, 'busy');

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_merge_run',
			nonce: rd3Pic.nonce,
			keep: keep,
			remove: remove
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus($mergeStatus, res.data.message || rd3Pic.i18n.mergeDone, 'success');
					if (res.data.summaryHtml) {
						$mergeResults.html(res.data.summaryHtml).show();
					}
					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : rd3Pic.i18n.mergeError;
					setStatus($mergeStatus, msg, 'error');
					if (res && res.data && res.data.summaryHtml) {
						$mergeResults.html(res.data.summaryHtml).show();
					}
					if (res && res.data && res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				}
			})
			.fail(function (xhr) {
				var msg = rd3Pic.i18n.mergeError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatus($mergeStatus, msg, 'error');
			})
			.always(function () {
				$mergePreviewBtn.prop('disabled', false);
				$mergeRunBtn.prop('disabled', true);
			});
	});

	$clearLogBtn.on('click', function () {
		if (!window.confirm(rd3Pic.i18n.confirmClearLog)) {
			return;
		}
		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_clear_log',
			nonce: rd3Pic.nonce
		}).done(function (res) {
			if (res && res.success && res.data.logHtml) {
				$log.html(res.data.logHtml);
			}
		});
	});
})(jQuery);
