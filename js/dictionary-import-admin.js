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
            if (job) {
                renderJob(job);
            }
            processing = false;

            if (job && job.has_more) {
                window.setTimeout(processNextStep, 80);
                return;
            }

            setBusy(false);
        }).fail(function (xhr) {
            processing = false;
            var message = config.strings && config.strings.failed ? config.strings.failed : 'Import failed.';
            var response = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
            if (response && response.job) {
                renderJob(response.job, response.message || message);
            } else {
                renderError(response && response.message ? response.message : message);
            }
            setBusy(false);
        });
    }

    function startJobFromForm(formElement) {
        var formData = new window.FormData(formElement);
        formData.append('action', config.startAction);
        formData.append('nonce', config.nonce);

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

        ajaxRequest({}, {
            method: 'POST',
            data: formData
        }).done(function (response) {
            var job = response && response.success && response.data ? response.data.job : null;
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
                return;
            }
            renderJob(job);
            if (job.has_more) {
                setBusy(true);
                window.setTimeout(processNextStep, 150);
            }
        });
    }

    $('[data-ll-dictionary-job-form]').on('submit', function (event) {
        event.preventDefault();
        var $form = $(this);
        if ($form.attr('id') === 'll-dictionary-legacy-form') {
            $form.find('input[name="ll_dictionary_wordset_id"]').val($('#ll-dictionary-wordset').val() || '0');
            $form.find('input[name="ll_dictionary_entry_lang"]').val($('#ll-dictionary-entry-lang').val() || '');
            $form.find('input[name="ll_dictionary_def_lang"]').val($('#ll-dictionary-def-lang').val() || '');
            $form.find('input[name="ll_dictionary_skip_review_rows"]').val($('input[name="ll_dictionary_skip_review_rows"]').is(':checked') ? '1' : '0');
            $form.find('input[name="ll_dictionary_replace_existing_senses"]').val($('input[name="ll_dictionary_replace_existing_senses"]').is(':checked') ? '1' : '0');
        }
        startJobFromForm(this);
    });

    loadCurrentJob();
});
