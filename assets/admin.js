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

	/**
	 * Generic status helper.
	 */
	function setStatus($el, msg, type) {
		$el
			.removeClass('is-busy is-success is-error')
			.addClass(type ? 'is-' + type : '')
			.text(msg || '');
	}

	/**
	 * Return an AJAX error message.
	 */
	function getAjaxError(xhr, fallback) {
		if (
			xhr &&
			xhr.responseJSON &&
			xhr.responseJSON.data &&
			xhr.responseJSON.data.message
		) {
			return xhr.responseJSON.data.message;
		}

		return fallback;
	}

	/*
	 * ------------------------------------------------------------
	 * Duplicate scan
	 * ------------------------------------------------------------
	 */

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
					setStatus(
						$scanStatus,
						res.data.message || rd3Pic.i18n.scanDone,
						'success'
					);

					$results.html(res.data.html || '');
					$resultsCard.show();

					if (res.data.has_groups) {
						$cleanupBtn.prop('disabled', false);
					}
				} else {
					setStatus(
						$scanStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.scanError,
						'error'
					);
				}
			})
			.fail(function (xhr) {
				setStatus(
					$scanStatus,
					getAjaxError(xhr, rd3Pic.i18n.scanError),
					'error'
				);
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

				setStatus(
					$scanStatus,
					res.data.message || '',
					'success'
				);
			}
		});
	});

	/*
	 * ------------------------------------------------------------
	 * Duplicate cleanup
	 * ------------------------------------------------------------
	 */

	$cleanupBtn.on('click', function () {
		if ($cleanupBtn.prop('disabled')) {
			return;
		}

		if (!window.confirm(rd3Pic.i18n.confirmCleanup)) {
			return;
		}

		$cleanupBtn.prop('disabled', true);
		$scanBtn.prop('disabled', true);

		setStatus(
			$cleanupStatus,
			rd3Pic.i18n.cleaning,
			'busy'
		);

		$cleanupSummary.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_cleanup',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$cleanupStatus,
						res.data.message || rd3Pic.i18n.cleanupDone,
						'success'
					);

					if (res.data.summaryHtml) {
						$cleanupSummary
							.html(res.data.summaryHtml)
							.show();
					}

					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}

					$results.empty();
					$resultsCard.hide();
				} else {
					setStatus(
						$cleanupStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.cleanupError,
						'error'
					);

					if (
						res &&
						res.data &&
						res.data.logHtml
					) {
						$log.html(res.data.logHtml);
					}
				}
			})
			.fail(function (xhr) {
				setStatus(
					$cleanupStatus,
					getAjaxError(xhr, rd3Pic.i18n.cleanupError),
					'error'
				);
			})
			.always(function () {
				$scanBtn.prop('disabled', false);
				$cleanupBtn.prop('disabled', true);
			});
	});

	/*
	 * ------------------------------------------------------------
	 * Large image scanner
	 * ------------------------------------------------------------
	 */

	$scanLargeBtn.on('click', function () {
		if ($scanLargeBtn.prop('disabled')) {
			return;
		}

		$scanLargeBtn.prop('disabled', true);
		$downsizeBtn.prop('disabled', true);

		setStatus(
			$largeStatus,
			rd3Pic.i18n.scanningLarge,
			'busy'
		);

		$largeResults.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_scan_large',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$largeStatus,
						res.data.message || rd3Pic.i18n.scanLargeDone,
						'success'
					);

					$largeResults
						.html(res.data.html || '')
						.show();

					if (res.data.largeCount > 0) {
						$downsizeBtn.prop('disabled', false);
					}
				} else {
					setStatus(
						$largeStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.scanError,
						'error'
					);
				}
			})
			.fail(function (xhr) {
				setStatus(
					$largeStatus,
					getAjaxError(xhr, rd3Pic.i18n.scanError),
					'error'
				);
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

		setStatus(
			$largeStatus,
			rd3Pic.i18n.downsizing,
			'busy'
		);

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_downsize',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$largeStatus,
						res.data.message || rd3Pic.i18n.downsizeDone,
						'success'
					);

					if (res.data.summaryHtml) {
						$largeResults
							.html(res.data.summaryHtml)
							.show();
					}

					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					setStatus(
						$largeStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.downsizeError,
						'error'
					);
				}
			})
			.fail(function (xhr) {
				setStatus(
					$largeStatus,
					getAjaxError(xhr, rd3Pic.i18n.downsizeError),
					'error'
				);
			})
			.always(function () {
				$scanLargeBtn.prop('disabled', false);
				$downsizeBtn.prop('disabled', true);
			});
	});

	/*
	 * ------------------------------------------------------------
	 * Image links in Posts & Pages
	 * ------------------------------------------------------------
	 */

	var $imageLinksStatus = $('#rd3-pic-image-links-status');
	var $scanImageLinksBtn = $('#rd3-pic-scan-image-links-btn');
	var $fixImageLinksBtn = $('#rd3-pic-fix-image-links-btn');
	var $imageLinksResults = $('#rd3-pic-image-links-results');

	$scanImageLinksBtn.on('click', function () {
		if ($scanImageLinksBtn.prop('disabled')) {
			return;
		}

		$scanImageLinksBtn.prop('disabled', true);
		$fixImageLinksBtn.prop('disabled', true);

		setStatus(
			$imageLinksStatus,
			rd3Pic.i18n.imageLinksScanning,
			'busy'
		);

		$imageLinksResults
			.hide()
			.empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_scan_image_links',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$imageLinksStatus,
						res.data.message || rd3Pic.i18n.imageLinksScanDone,
						'success'
					);

					$imageLinksResults
						.html(res.data.html || '')
						.show();

					if (res.data.canRun) {
						$fixImageLinksBtn.prop('disabled', false);
					}
				} else {
					setStatus(
						$imageLinksStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.imageLinksScanError,
						'error'
					);

					if (
						res &&
						res.data &&
						res.data.html
					) {
						$imageLinksResults
							.html(res.data.html)
							.show();
					}
				}
			})
			.fail(function (xhr) {
				setStatus(
					$imageLinksStatus,
					getAjaxError(
						xhr,
						rd3Pic.i18n.imageLinksScanError
					),
					'error'
				);
			})
			.always(function () {
				$scanImageLinksBtn.prop('disabled', false);
			});
	});

	$fixImageLinksBtn.on('click', function () {
		if ($fixImageLinksBtn.prop('disabled')) {
			return;
		}

		if (!window.confirm(rd3Pic.i18n.imageLinksConfirm)) {
			return;
		}

		$fixImageLinksBtn.prop('disabled', true);
		$scanImageLinksBtn.prop('disabled', true);

		setStatus(
			$imageLinksStatus,
			rd3Pic.i18n.imageLinksFixing,
			'busy'
		);

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_fix_image_links',
			nonce: rd3Pic.nonce
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$imageLinksStatus,
						res.data.message || rd3Pic.i18n.imageLinksFixed,
						'success'
					);

					if (res.data.summaryHtml) {
						$imageLinksResults
							.html(res.data.summaryHtml)
							.show();
					}

					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					setStatus(
						$imageLinksStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.imageLinksFixError,
						'error'
					);

					if (
						res &&
						res.data &&
						res.data.summaryHtml
					) {
						$imageLinksResults
							.html(res.data.summaryHtml)
							.show();
					}

					if (
						res &&
						res.data &&
						res.data.logHtml
					) {
						$log.html(res.data.logHtml);
					}
				}
			})
			.fail(function (xhr) {
				setStatus(
					$imageLinksStatus,
					getAjaxError(
						xhr,
						rd3Pic.i18n.imageLinksFixError
					),
					'error'
				);
			})
			.always(function () {
				$scanImageLinksBtn.prop('disabled', false);
				$fixImageLinksBtn.prop('disabled', true);
			});
	});

	/*
	 * ------------------------------------------------------------
	 * Named image tool
	 * ------------------------------------------------------------
	 */

	var $namedInput = $('#rd3-pic-named-input');
	var $scanNamedBtn = $('#rd3-pic-scan-named-btn');
	var $downsizeNamedBtn = $('#rd3-pic-downsize-named-btn');
	var $namedStatus = $('#rd3-pic-named-status');
	var $namedResults = $('#rd3-pic-named-results');
	var lastNamedFilename = '';

	$scanNamedBtn.on('click', function () {
		var name = $.trim($namedInput.val() || '');

		if (!name) {
			setStatus(
				$namedStatus,
				rd3Pic.i18n.namedNeedName,
				'error'
			);
			return;
		}

		$scanNamedBtn.prop('disabled', true);
		$downsizeNamedBtn.prop('disabled', true);

		setStatus(
			$namedStatus,
			rd3Pic.i18n.namedScanning,
			'busy'
		);

		$namedResults.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_scan_named',
			nonce: rd3Pic.nonce,
			filename: name
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$namedStatus,
						res.data.message || rd3Pic.i18n.namedScanDone,
						'success'
					);

					$namedResults
						.html(res.data.html || '')
						.show();

					lastNamedFilename =
						res.data.filename || name;

					if (res.data.canRun) {
						$downsizeNamedBtn.prop('disabled', false);
					}
				} else {
					setStatus(
						$namedStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.scanError,
						'error'
					);
				}
			})
			.fail(function (xhr) {
				setStatus(
					$namedStatus,
					getAjaxError(
						xhr,
						rd3Pic.i18n.scanError
					),
					'error'
				);
			})
			.always(function () {
				$scanNamedBtn.prop('disabled', false);
			});
	});

	$downsizeNamedBtn.on('click', function () {
		if ($downsizeNamedBtn.prop('disabled')) {
			return;
		}

		var name =
			lastNamedFilename ||
			$.trim($namedInput.val() || '');

		if (!name) {
			setStatus(
				$namedStatus,
				rd3Pic.i18n.namedNeedName,
				'error'
			);
			return;
		}

		if (!window.confirm(rd3Pic.i18n.namedConfirm)) {
			return;
		}

		$downsizeNamedBtn.prop('disabled', true);
		$scanNamedBtn.prop('disabled', true);

		setStatus(
			$namedStatus,
			rd3Pic.i18n.namedWorking,
			'busy'
		);

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_downsize_named',
			nonce: rd3Pic.nonce,
			filename: name
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$namedStatus,
						res.data.message || rd3Pic.i18n.namedDone,
						'success'
					);

					if (res.data.summaryHtml) {
						$namedResults
							.html(res.data.summaryHtml)
							.show();
					}

					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					setStatus(
						$namedStatus,
						(res && res.data && res.data.message)
							? res.data.message
							: rd3Pic.i18n.downsizeError,
						'error'
					);
				}
			})
			.fail(function (xhr) {
				setStatus(
					$namedStatus,
					getAjaxError(
						xhr,
						rd3Pic.i18n.downsizeError
					),
					'error'
				);
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

	/*
	 * ------------------------------------------------------------
	 * Manual merge
	 * ------------------------------------------------------------
	 */

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
			setStatus(
				$mergeStatus,
				rd3Pic.i18n.mergeNeedNames,
				'error'
			);
			return;
		}

		$mergePreviewBtn.prop('disabled', true);
		$mergeRunBtn.prop('disabled', true);

		setStatus(
			$mergeStatus,
			rd3Pic.i18n.mergePreviewing,
			'busy'
		);

		$mergeResults.hide().empty();

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_merge_preview',
			nonce: rd3Pic.nonce,
			keep: keep,
			remove: remove
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$mergeStatus,
						res.data.message || rd3Pic.i18n.mergePreviewDone,
						'success'
					);

					$mergeResults
						.html(res.data.html || '')
						.show();

					lastMergeKeep =
						res.data.keep || keep;

					lastMergeRemove =
						res.data.remove || remove;

					if (res.data.canRun) {
						$mergeRunBtn.prop('disabled', false);
					}
				} else {
					var msg =
						(res &&
							res.data &&
							res.data.message)
							? res.data.message
							: rd3Pic.i18n.mergeError;

					setStatus(
						$mergeStatus,
						msg,
						'error'
					);

					if (
						res &&
						res.data &&
						res.data.html
					) {
						$mergeResults
							.html(res.data.html)
							.show();
					}
				}
			})
			.fail(function (xhr) {
				setStatus(
					$mergeStatus,
					getAjaxError(
						xhr,
						rd3Pic.i18n.mergeError
					),
					'error'
				);
			})
			.always(function () {
				$mergePreviewBtn.prop('disabled', false);
			});
	});

	$mergeRunBtn.on('click', function () {
		if ($mergeRunBtn.prop('disabled')) {
			return;
		}

		var keep =
			lastMergeKeep ||
			$.trim($mergeKeep.val() || '');

		var remove =
			lastMergeRemove ||
			$.trim($mergeRemove.val() || '');

		if (!keep || !remove) {
			setStatus(
				$mergeStatus,
				rd3Pic.i18n.mergeNeedNames,
				'error'
			);
			return;
		}

		if (!window.confirm(rd3Pic.i18n.mergeConfirm)) {
			return;
		}

		$mergeRunBtn.prop('disabled', true);
		$mergePreviewBtn.prop('disabled', true);

		setStatus(
			$mergeStatus,
			rd3Pic.i18n.mergeWorking,
			'busy'
		);

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_merge_run',
			nonce: rd3Pic.nonce,
			keep: keep,
			remove: remove
		})
			.done(function (res) {
				if (res && res.success) {
					setStatus(
						$mergeStatus,
						res.data.message || rd3Pic.i18n.mergeDone,
						'success'
					);

					if (res.data.summaryHtml) {
						$mergeResults
							.html(res.data.summaryHtml)
							.show();
					}

					if (res.data.logHtml) {
						$log.html(res.data.logHtml);
					}
				} else {
					setStatus(
						$mergeStatus,
						(res &&
							res.data &&
							res.data.message)
							? res.data.message
							: rd3Pic.i18n.mergeError,
						'error'
					);

					if (
						res &&
						res.data &&
						res.data.summaryHtml
					) {
						$mergeResults
							.html(res.data.summaryHtml)
							.show();
					}

					if (
						res &&
						res.data &&
						res.data.logHtml
					) {
						$log.html(res.data.logHtml);
					}
				}
			})
			.fail(function (xhr) {
				setStatus(
					$mergeStatus,
					getAjaxError(
						xhr,
						rd3Pic.i18n.mergeError
					),
					'error'
				);
			})
			.always(function () {
				$mergePreviewBtn.prop('disabled', false);
				$mergeRunBtn.prop('disabled', true);
			});
	});

	/*
	 * ------------------------------------------------------------
	 * Clear log
	 * ------------------------------------------------------------
	 */

	$clearLogBtn.on('click', function () {
		if (!window.confirm(rd3Pic.i18n.confirmClearLog)) {
			return;
		}

		$.post(rd3Pic.ajaxUrl, {
			action: 'rd3_pic_clear_log',
			nonce: rd3Pic.nonce
		}).done(function (res) {
			if (
				res &&
				res.success &&
				res.data.logHtml
			) {
				$log.html(res.data.logHtml);
			}
		});
	});

})(jQuery);