(function ($) {
    if (!window.llToolsImport || !window.llToolsImport.jobId) {
        return;
    }

    var config = window.llToolsImport;
    var $progress = $('#ll-import-progress');
    var $fill = $('#ll-import-progress-fill');
    var $status = $('#ll-import-status-text');
    var $text = $('#ll-import-progress-text');
    var $error = $('#ll-import-error');
    var stopped = false;

    function setStatus(message) {
        if ($status.length) {
            $status.text(message || '');
        }
    }

    function setProgress(processed, total) {
        var percent = total ? Math.round((processed / total) * 100) : 0;
        if ($fill.length) {
            $fill.css('width', percent + '%');
        }
        if ($text.length) {
            if (config.strings && config.strings.progress) {
                $text.text(
                    config.strings.progress
                        .replace('%1$d', processed)
                        .replace('%2$d', total)
                );
            } else {
                $text.text('Processed ' + processed + ' of ' + total);
            }
        }
    }

    function showError(message) {
        stopped = true;
        if ($error.length) {
            $error.text(message || '');
            $error.show();
        }
        setStatus('');
    }

    function requestBatch() {
        if (stopped) {
            return;
        }

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'll_tools_import_batch',
                nonce: config.nonce,
                job_id: config.jobId,
                limit: config.batchSize
            }
        })
            .done(function (response) {
                if (!response || response.success !== true) {
                    var fallback = config.strings && config.strings.error ? config.strings.error : 'Import failed.';
                    var message = fallback;
                    if (response && response.data && response.data.message) {
                        message = response.data.message;
                    }
                    showError(message);
                    return;
                }

                var data = response.data || {};
                var processed = data.processed || 0;
                var total = data.total || 0;

                setProgress(processed, total);

                if (data.done) {
                    setStatus(config.strings && config.strings.done ? config.strings.done : 'Finishing...');
                    if (data.redirect) {
                        window.location = data.redirect;
                    }
                    return;
                }

                setStatus(config.strings && config.strings.processing ? config.strings.processing : 'Importing batch...');
                window.setTimeout(requestBatch, config.delay || 200);
            })
            .fail(function (xhr) {
                var fallback = config.strings && config.strings.error ? config.strings.error : 'Import failed.';
                var message = fallback;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                showError(message);
            });
    }

    $progress.show();
    setStatus(config.strings && config.strings.starting ? config.strings.starting : 'Starting import...');
    requestBatch();
})(jQuery);
