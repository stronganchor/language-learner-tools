(function () {
    'use strict';

    var config = window.llWordsetOfflineExportData || {};
    var root = document.querySelector('[data-ll-wordset-offline-export]');
    if (!root || !window.fetch || !window.FormData || !config.ajaxUrl) {
        return;
    }

    var form = root.querySelector('[data-ll-wordset-offline-export-form]');
    var submitButton = root.querySelector('[data-ll-wordset-offline-export-submit]');
    var jobPanel = root.querySelector('[data-ll-wordset-offline-export-job]');
    var jobPhase = root.querySelector('[data-ll-wordset-offline-export-phase]');
    var jobStatus = root.querySelector('[data-ll-wordset-offline-export-status]');
    var jobProgress = root.querySelector('[data-ll-wordset-offline-export-progress]');
    var jobError = root.querySelector('[data-ll-wordset-offline-export-error]');
    var resumeButton = root.querySelector('[data-ll-wordset-offline-export-resume]');
    var downloadLink = root.querySelector('[data-ll-wordset-offline-export-download]');
    var strings = config.strings || {};
    var activeToken = '';
    var running = false;
    var activeJobTerminal = true;
    var submitInitiallyDisabled = !!(submitButton && submitButton.disabled);

    if (!form || !submitButton || !jobPanel || !jobPhase || !jobStatus || !jobProgress || !jobError || !resumeButton || !downloadLink) {
        return;
    }

    function setSubmitState() {
        submitButton.disabled = submitInitiallyDisabled || running || (activeToken !== '' && !activeJobTerminal);
        submitButton.setAttribute('aria-busy', running ? 'true' : 'false');
    }

    function rememberJobToken(token) {
        token = String(token || '').trim();
        if (!token) {
            return;
        }
        activeToken = token;
        if (!window.history || !window.history.replaceState) {
            return;
        }

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('ll_offline_job', token);
            window.history.replaceState({}, '', url.toString());
        } catch (error) {
            // The persisted server job remains resumable even when this URL cannot be rewritten.
        }
    }

    function renderJob(job) {
        var status = String((job && job.status) || 'failed');
        var progress = Math.max(0, Math.min(100, Number((job && job.progress) || 0)));
        var downloadUrl = String((job && job.downloadUrl) || '');

        if (job && job.token) {
            rememberJobToken(job.token);
        }

        jobPanel.hidden = false;
        jobPanel.classList.toggle('is-failed', status === 'failed');
        jobPanel.classList.toggle('is-complete', status === 'completed');
        jobPhase.textContent = String((job && job.phaseLabel) || '');
        jobStatus.textContent = String((job && job.statusText) || '');
        jobProgress.value = progress;
        jobProgress.textContent = progress + '%';
        jobError.hidden = status !== 'failed';
        jobError.textContent = status === 'failed'
            ? String((job && job.error) || strings.requestFailed || '')
            : '';
        downloadLink.hidden = status !== 'completed' || !downloadUrl;
        if (!downloadLink.hidden) {
            downloadLink.href = downloadUrl;
        }
        resumeButton.hidden = true;
        running = status === 'queued' || status === 'processing';
        activeJobTerminal = status === 'completed' || status === 'failed';
        setSubmitState();
    }

    function renderRequestError(error) {
        running = false;
        activeJobTerminal = activeToken === '';
        jobPanel.hidden = false;
        jobPanel.classList.add('is-failed');
        jobPanel.classList.remove('is-complete');
        jobPhase.textContent = String(strings.paused || '');
        jobStatus.textContent = '';
        jobError.hidden = false;
        jobError.textContent = error && error.message
            ? String(error.message)
            : String(strings.requestFailed || '');
        resumeButton.hidden = activeToken === '';
        downloadLink.hidden = true;
        setSubmitState();
    }

    function parseResponse(response) {
        return response.json().then(function (payload) {
            if (!response.ok || !payload || !payload.success || !payload.data) {
                var message = payload && payload.data && payload.data.message
                    ? String(payload.data.message)
                    : String(strings.requestFailed || '');
                throw new Error(message);
            }
            return payload.data;
        });
    }

    function requestStep(token) {
        token = String(token || activeToken || '').trim();
        if (!running || !token) {
            return;
        }

        var payload = new FormData();
        payload.set('action', String(config.stepAction || ''));
        payload.set('nonce', String(config.nonce || ''));
        payload.set('token', token);

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin'
        }).then(parseResponse).then(function (job) {
            renderJob(job);
            if (running) {
                window.setTimeout(function () {
                    requestStep(String(job.token || token));
                }, 150);
            }
        }).catch(renderRequestError);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (running || submitInitiallyDisabled) {
            return;
        }

        running = true;
        activeToken = '';
        activeJobTerminal = false;
        setSubmitState();

        var payload = new FormData(form);
        payload.set('action', String(config.startAction || ''));
        payload.set('nonce', String(config.nonce || ''));

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin'
        }).then(parseResponse).then(function (job) {
            renderJob(job);
            if (running) {
                requestStep(String(job.token || ''));
            }
        }).catch(renderRequestError);
    });

    resumeButton.addEventListener('click', function () {
        if (running || !activeToken) {
            return;
        }
        running = true;
        resumeButton.hidden = true;
        jobError.hidden = true;
        jobPanel.classList.remove('is-failed');
        setSubmitState();
        requestStep(activeToken);
    });

    if (config.currentJob && config.currentJob.token) {
        renderJob(config.currentJob);
        if (running) {
            requestStep(String(config.currentJob.token));
        }
    } else {
        setSubmitState();
    }
})();
