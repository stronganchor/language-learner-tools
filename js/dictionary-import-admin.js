jQuery(function ($) {
    'use strict';

    var config = window.llDictionaryImportAdmin || {};
    var $runtime = $('#ll-dictionary-import-runtime');
    var $summaryArea = $('#ll-dictionary-import-summary-area');
    var $statusTitle = $runtime.find('.ll-dictionary-import-admin__status-title');
    var $statusSubtitle = $runtime.find('.ll-dictionary-import-admin__status-subtitle');
    var $statusPill = $runtime.find('.ll-dictionary-import-admin__status-pill');
    var $progressBar = $runtime.find('.ll-dictionary-import-admin__progress-bar');
    var $progressText = $runtime.find('.ll-dictionary-import-admin__progress-text');
    var $detailText = $runtime.find('.ll-dictionary-import-admin__detail-text');
    var $adviceTitle = $runtime.find('.ll-dictionary-import-admin__advice-title');
    var $adviceText = $runtime.find('.ll-dictionary-import-admin__advice-text');
    var $errorBox = $runtime.find('.ll-dictionary-import-admin__error');
    var $errorText = $runtime.find('.ll-dictionary-import-admin__error-text');

    var currentJob = null;
    var processing = false;
    var requestFailureCount = 0;
    var maxFailureRetries = 4;

    function setBusy(isBusy) {
        $('[data-ll-dictionary-job-form] :submit').prop('disabled', !!isBusy);
    }

    function showRuntime() {
        $runtime.prop('hidden', false);
    }

    function clearError() {
        $errorText.text('');
        $errorBox.prop('hidden', true);
    }

    function renderError(message) {
        if (!message) {
            clearError();
            return;
        }
        $errorText.text(message);
        $errorBox.prop('hidden', false);
    }

    function renderSummary(job) {
        if (job && job.summary_html) {
            $summaryArea.html(job.summary_html);
            return;
        }
        $summaryArea.empty();
    }

    function scheduleRecoveryAttempt(message) {
        if (!currentJob || !currentJob.id || !currentJob.has_more || requestFailureCount >= maxFailureRetries) {
            return false;
        }

        requestFailureCount += 1;
        renderError(message || (config.strings && config.strings.retrying ? config.strings.retrying : 'Checking import status...'));
        setBusy(true);
        window.setTimeout(loadCurrentJob, Math.min(4000, 750 * requestFailureCount));

        return true;
    }

    function renderJob(job, errorMessage) {
        if (!job) {
            return;
        }

        currentJob = job;
        showRuntime();

        var subtitle = job.original_filename ? job.original_filename : '';
        $statusTitle.text(job.title || '');
        $statusSubtitle.text(subtitle);
        $statusPill
            .text(job.status_label || '')
            .attr('data-state', job.status || '');
        $progressBar.css('width', String(job.progress_percent || 0) + '%');
        $progressText.text(job.progress_text || '');
        $detailText.text(job.detail_text || '');
        $adviceTitle.text(job.advice_title || '');
        $adviceText.text(job.advice_text || '');

        renderSummary(job);
        renderError(errorMessage || job.error_message || '');
    }

    function ajaxRequest(ajaxData, options) {
        var requestOptions = $.extend({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            cache: false
        }, options || {});

        if (requestOptions.data instanceof window.FormData) {
            requestOptions.processData = false;
            requestOptions.contentType = false;
        } else {
            requestOptions.data = $.extend({}, ajaxData, requestOptions.data || {});
        }

        if (!(requestOptions.data instanceof window.FormData)) {
            requestOptions.data.nonce = config.nonce;
        }

        return $.ajax(requestOptions);
    }

    function processNextStep() {
        if (!currentJob || !currentJob.id || !currentJob.has_more || processing) {
            setBusy(false);
            return;
        }

        processing = true;
        ajaxRequest({
            action: config.processAction,
            job_id: currentJob.id,
            nonce: config.nonce
        }).done(function (response) {
            var job = response && response.success && response.data ? response.data.job : null;
            requestFailureCount = 0;
            if (job) {
                renderJob(job);
            }
            processing = false;

            if (job && job.has_more) {
                window.setTimeout(processNextStep, response && response.data && response.data.locked ? 350 : 80);
                return;
            }

            setBusy(false);
        }).fail(function (xhr) {
            processing = false;
            var message = config.strings && config.strings.failed ? config.strings.failed : 'Import failed.';
            var response = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
            if (response && response.job) {
                renderJob(response.job, response.message || message);
                if (response.job.has_more && scheduleRecoveryAttempt(config.strings && config.strings.retrying ? config.strings.retrying : message)) {
                    return;
                }
            } else {
                renderError(response && response.message ? response.message : message);
                if (scheduleRecoveryAttempt(config.strings && config.strings.retrying ? config.strings.retrying : message)) {
                    return;
                }
            }
            setBusy(false);
        });
    }

    function startJobFromForm(formElement) {
        var formData = new window.FormData(formElement);
        formData.append('action', config.startAction);
        formData.append('nonce', config.nonce);

        currentJob = null;
        processing = false;
        requestFailureCount = 0;
        setBusy(true);
        showRuntime();
        renderSummary(null);
        clearError();
        $statusTitle.text(config.strings && config.strings.starting ? config.strings.starting : '');
        $statusSubtitle.text('');
        $statusPill.text('').attr('data-state', 'starting');
        $progressBar.css('width', '0%');
        $progressText.text('');
        $detailText.text('');
        $adviceTitle.text('');
        $adviceText.text('');

        ajaxRequest({}, {
            method: 'POST',
            data: formData
        }).done(function (response) {
            var job = response && response.success && response.data ? response.data.job : null;
            requestFailureCount = 0;
            if (!job) {
                renderError(config.strings && config.strings.failed ? config.strings.failed : 'Import failed.');
                setBusy(false);
                return;
            }

            renderJob(job);
            if (job.has_more) {
                window.setTimeout(processNextStep, 80);
                return;
            }

            setBusy(false);
        }).fail(function (xhr) {
            var response = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
            if (response && response.job) {
                renderJob(response.job, response.message || '');
                if (response.job.has_more) {
                    window.setTimeout(processNextStep, 80);
                    return;
                }
            } else {
                renderError(response && response.message ? response.message : (config.strings && config.strings.failed ? config.strings.failed : 'Import failed.'));
            }
            setBusy(false);
        });
    }

    function loadCurrentJob() {
        ajaxRequest({
            action: config.statusAction,
            nonce: config.nonce
        }).done(function (response) {
            var job = response && response.success && response.data ? response.data.job : null;
            if (!job) {
                setBusy(false);
                return;
            }
            requestFailureCount = 0;
            renderJob(job);
            if (job.has_more) {
                setBusy(true);
                window.setTimeout(processNextStep, 150);
            }
        }).fail(function () {
            if (scheduleRecoveryAttempt(config.strings && config.strings.retrying ? config.strings.retrying : 'Checking import status...')) {
                return;
            }
            setBusy(false);
        });
    }

    $('[data-ll-dictionary-job-form]').on('submit', function (event) {
        event.preventDefault();
        startJobFromForm(this);
    });

    loadCurrentJob();
});
