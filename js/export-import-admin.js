(function () {
    'use strict';

    function getAdminUiConfig() {
        var cfg = window.llToolsImportUi || {};
        return {
            ajaxUrl: typeof cfg.ajaxUrl === 'string' ? cfg.ajaxUrl : '',
            exportPageUrl: typeof cfg.exportPageUrl === 'string' ? cfg.exportPageUrl : '',
            importPageUrl: typeof cfg.importPageUrl === 'string' ? cfg.importPageUrl : '',
            processingTitle: typeof cfg.processingTitle === 'string' ? cfg.processingTitle : '',
            processingMessageKeepOpen: typeof cfg.processingMessageKeepOpen === 'string' ? cfg.processingMessageKeepOpen : '',
            processingMessageBackground: typeof cfg.processingMessageBackground === 'string' ? cfg.processingMessageBackground : '',
            processingProgressLabel: typeof cfg.processingProgressLabel === 'string' ? cfg.processingProgressLabel : '',
            processingDone: typeof cfg.processingDone === 'string' ? cfg.processingDone : '',
            processingFailed: typeof cfg.processingFailed === 'string' ? cfg.processingFailed : '',
            processingReload: typeof cfg.processingReload === 'string' ? cfg.processingReload : '',
            exportProcessingTitle: typeof cfg.exportProcessingTitle === 'string' ? cfg.exportProcessingTitle : '',
            exportProcessingMessageKeepOpen: typeof cfg.exportProcessingMessageKeepOpen === 'string' ? cfg.exportProcessingMessageKeepOpen : '',
            exportProcessingMessageBackground: typeof cfg.exportProcessingMessageBackground === 'string' ? cfg.exportProcessingMessageBackground : '',
            exportProcessingProgressLabel: typeof cfg.exportProcessingProgressLabel === 'string' ? cfg.exportProcessingProgressLabel : '',
            exportProcessingDone: typeof cfg.exportProcessingDone === 'string' ? cfg.exportProcessingDone : '',
            exportProcessingFailed: typeof cfg.exportProcessingFailed === 'string' ? cfg.exportProcessingFailed : '',
            exportProcessingReload: typeof cfg.exportProcessingReload === 'string' ? cfg.exportProcessingReload : '',
            copyButtonCopied: typeof cfg.copyButtonCopied === 'string' ? cfg.copyButtonCopied : 'Copied',
            copyButtonFailed: typeof cfg.copyButtonFailed === 'string' ? cfg.copyButtonFailed : 'Copy failed'
        };
    }

    function getProcessingScreenConfig(config, mode) {
        if (mode === 'export') {
            return {
                pageUrl: config.exportPageUrl,
                processingTitle: config.exportProcessingTitle,
                processingMessageKeepOpen: config.exportProcessingMessageKeepOpen,
                processingMessageBackground: config.exportProcessingMessageBackground,
                processingProgressLabel: config.exportProcessingProgressLabel,
                processingDone: config.exportProcessingDone,
                processingFailed: config.exportProcessingFailed,
                processingReload: config.exportProcessingReload
            };
        }

        return {
            pageUrl: config.importPageUrl,
            processingTitle: config.processingTitle,
            processingMessageKeepOpen: config.processingMessageKeepOpen,
            processingMessageBackground: config.processingMessageBackground,
            processingProgressLabel: config.processingProgressLabel,
            processingDone: config.processingDone,
            processingFailed: config.processingFailed,
            processingReload: config.processingReload
        };
    }

    function initReferenceCopyButtons() {
        var buttons = document.querySelectorAll('.ll-tools-copy-reference-button[data-ll-copy-target]');
        if (!buttons.length) {
            return;
        }

        var config = getAdminUiConfig();

        function copyText(text) {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                return navigator.clipboard.writeText(text);
            }

            return new Promise(function (resolve, reject) {
                var helper = document.createElement('textarea');
                helper.value = text;
                helper.setAttribute('readonly', 'readonly');
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                helper.style.pointerEvents = 'none';
                document.body.appendChild(helper);
                helper.focus();
                helper.select();

                try {
                    if (document.execCommand('copy')) {
                        resolve();
                    } else {
                        reject(new Error('copy_failed'));
                    }
                } catch (error) {
                    reject(error);
                } finally {
                    document.body.removeChild(helper);
                }
            });
        }

        for (var i = 0; i < buttons.length; i++) {
            (function (button) {
                var defaultLabel = button.textContent;
                button.addEventListener('click', function () {
                    var targetId = button.getAttribute('data-ll-copy-target');
                    if (!targetId) {
                        return;
                    }

                    var target = document.getElementById(targetId);
                    if (!target) {
                        return;
                    }

                    button.disabled = true;
                    copyText(target.value || target.textContent || '').then(function () {
                        button.textContent = config.copyButtonCopied;
                        window.setTimeout(function () {
                            button.textContent = defaultLabel;
                            button.disabled = false;
                        }, 1500);
                    }).catch(function () {
                        button.textContent = config.copyButtonFailed;
                        window.setTimeout(function () {
                            button.textContent = defaultLabel;
                            button.disabled = false;
                        }, 1800);
                    });
                });
            })(buttons[i]);
        }
    }

    function ensureProcessingScreen(config) {
        var existing = document.getElementById('ll-tools-import-processing-screen');
        if (existing) {
            return {
                root: existing,
                progressWrap: existing.querySelector('.ll-tools-import-processing-progress'),
                progressBar: existing.querySelector('.ll-tools-import-processing-progress-bar'),
                status: existing.querySelector('.ll-tools-import-processing-status'),
                error: existing.querySelector('.ll-tools-import-processing-error'),
                reloadButton: existing.querySelector('.ll-tools-import-processing-reload')
            };
        }

        var root = document.createElement('div');
        root.id = 'll-tools-import-processing-screen';
        root.className = 'll-tools-import-processing-screen';

        var card = document.createElement('div');
        card.className = 'll-tools-import-processing-card';

        var title = document.createElement('h2');
        title.className = 'll-tools-import-processing-title';
        title.textContent = config.processingTitle;
        card.appendChild(title);

        var noteKeepOpen = document.createElement('p');
        noteKeepOpen.className = 'll-tools-import-processing-note';
        noteKeepOpen.textContent = config.processingMessageKeepOpen;
        card.appendChild(noteKeepOpen);

        if (config.processingMessageBackground) {
            var noteBackground = document.createElement('p');
            noteBackground.className = 'll-tools-import-processing-note';
            noteBackground.textContent = config.processingMessageBackground;
            card.appendChild(noteBackground);
        }

        var progressWrap = document.createElement('div');
        progressWrap.className = 'll-tools-import-processing-progress';
        var progressBar = document.createElement('span');
        progressBar.className = 'll-tools-import-processing-progress-bar';
        progressWrap.appendChild(progressBar);
        card.appendChild(progressWrap);

        var status = document.createElement('p');
        status.className = 'll-tools-import-processing-status';
        status.textContent = config.processingProgressLabel;
        card.appendChild(status);

        var error = document.createElement('p');
        error.className = 'll-tools-import-processing-error';
        error.hidden = true;
        card.appendChild(error);

        var reloadButton = document.createElement('button');
        reloadButton.type = 'button';
        reloadButton.className = 'button button-secondary ll-tools-import-processing-reload';
        reloadButton.textContent = config.processingReload;
        reloadButton.hidden = true;
        reloadButton.addEventListener('click', function () {
            var target = config.pageUrl || window.location.href;
            window.location.assign(target);
        });
        card.appendChild(reloadButton);

        root.appendChild(card);
        document.body.appendChild(root);

        return {
            root: root,
            progressWrap: progressWrap,
            progressBar: progressBar,
            status: status,
            error: error,
            reloadButton: reloadButton
        };
    }

    function updateProcessingProgress(screen, progressRatio) {
        if (!screen || !screen.progressWrap || !screen.progressBar) {
            return;
        }

        if (typeof progressRatio !== 'number' || !isFinite(progressRatio) || progressRatio <= 0 || progressRatio >= 1) {
            screen.progressWrap.classList.remove('is-determinate');
            screen.progressBar.style.width = '';
            return;
        }

        screen.progressWrap.classList.add('is-determinate');
        screen.progressBar.style.width = Math.max(4, Math.round(progressRatio * 100)) + '%';
    }

    function resetProcessingScreen(screen, config) {
        if (!screen) {
            return;
        }

        if (screen.error) {
            screen.error.hidden = true;
            screen.error.textContent = '';
        }
        if (screen.reloadButton) {
            screen.reloadButton.hidden = true;
        }
        if (screen.status) {
            screen.status.textContent = config.processingProgressLabel;
        }
        updateProcessingProgress(screen, NaN);
    }

    function readJsonResponse(response) {
        return response.text().then(function (body) {
            var payload = {};

            if (body) {
                try {
                    payload = JSON.parse(body);
                } catch (error) {
                    payload = {};
                }
            }

            if (!response.ok || !payload || payload.success !== true) {
                var message = '';
                if (payload && payload.data && typeof payload.data.message === 'string') {
                    message = payload.data.message;
                }
                throw new Error(message || ('request_failed_' + response.status));
            }

            return payload.data || {};
        });
    }

    function initImportConfirmProgressUi() {
        var forms = document.querySelectorAll('form');
        if (!forms.length) {
            return;
        }

        var adminConfig = getAdminUiConfig();
        var config = getProcessingScreenConfig(adminConfig, 'import');

        for (var i = 0; i < forms.length; i++) {
            (function (form) {
                var actionInput = form.querySelector('input[name="action"]');
                if (!actionInput || actionInput.value !== 'll_tools_import_bundle') {
                    return;
                }

                form.addEventListener('submit', function (event) {
                    if (!window.fetch || !window.FormData) {
                        return;
                    }

                    if (form.getAttribute('data-ll-tools-import-submitting') === '1') {
                        event.preventDefault();
                        return;
                    }

                    event.preventDefault();
                    form.setAttribute('data-ll-tools-import-submitting', '1');

                    var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                    for (var btnIdx = 0; btnIdx < submitButtons.length; btnIdx++) {
                        submitButtons[btnIdx].disabled = true;
                    }

                    var screen = ensureProcessingScreen(config);
                    resetProcessingScreen(screen, config);
                    document.documentElement.classList.add('ll-tools-import-processing');

                    var requestUrl = form.getAttribute('action') || window.location.href;
                    fetch(requestUrl, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin'
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('request_failed_' + response.status);
                        }

                        if (screen.status) {
                            screen.status.textContent = config.processingDone;
                        }
                        updateProcessingProgress(screen, 1);

                        var redirectTarget = response.url || config.pageUrl || window.location.href;
                        window.setTimeout(function () {
                            window.location.assign(redirectTarget);
                        }, 250);
                    }).catch(function () {
                        form.removeAttribute('data-ll-tools-import-submitting');
                        for (var idx = 0; idx < submitButtons.length; idx++) {
                            submitButtons[idx].disabled = false;
                        }
                        if (screen.error) {
                            screen.error.hidden = false;
                            screen.error.textContent = config.processingFailed;
                        }
                        if (screen.reloadButton) {
                            screen.reloadButton.hidden = false;
                        }
                    });
                });
            })(forms[i]);
        }
    }

    function initExportBatchProgressUi() {
        var forms = document.querySelectorAll('form');
        if (!forms.length || !window.fetch || !window.FormData) {
            return;
        }

        var adminConfig = getAdminUiConfig();
        var config = getProcessingScreenConfig(adminConfig, 'export');
        if (!adminConfig.ajaxUrl) {
            return;
        }

        function setFailureState(form, submitButtons, screen, message) {
            form.removeAttribute('data-ll-tools-export-submitting');
            for (var idx = 0; idx < submitButtons.length; idx++) {
                submitButtons[idx].disabled = false;
            }

            if (screen.error) {
                screen.error.hidden = false;
                screen.error.textContent = message || config.processingFailed;
            }
            if (screen.reloadButton) {
                screen.reloadButton.hidden = false;
            }
            updateProcessingProgress(screen, NaN);
        }

        for (var i = 0; i < forms.length; i++) {
            (function (form) {
                var actionInput = form.querySelector('input[name="action"]');
                if (!actionInput || actionInput.value !== 'll_tools_export_bundle') {
                    return;
                }

                form.addEventListener('submit', function (event) {
                    if (form.getAttribute('data-ll-tools-export-submitting') === '1') {
                        event.preventDefault();
                        return;
                    }

                    event.preventDefault();
                    form.setAttribute('data-ll-tools-export-submitting', '1');

                    var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                    for (var btnIdx = 0; btnIdx < submitButtons.length; btnIdx++) {
                        submitButtons[btnIdx].disabled = true;
                    }

                    var screen = ensureProcessingScreen(config);
                    resetProcessingScreen(screen, config);
                    document.documentElement.classList.add('ll-tools-import-processing');

                    var startData = new FormData(form);
                    startData.set('action', 'll_tools_start_export_bundle');

                    var runBatch = function (token, batchNonce) {
                        var batchData = new FormData();
                        batchData.set('action', 'll_tools_run_export_bundle_batch');
                        batchData.set('ll_export_token', token);
                        batchData.set('_wpnonce', batchNonce);

                        return fetch(adminConfig.ajaxUrl, {
                            method: 'POST',
                            body: batchData,
                            credentials: 'same-origin'
                        }).then(readJsonResponse).then(function (payload) {
                            if (screen.status && typeof payload.statusText === 'string' && payload.statusText) {
                                screen.status.textContent = payload.statusText;
                            }
                            updateProcessingProgress(screen, Number(payload.progressRatio));

                            if (payload.status === 'completed' && typeof payload.downloadUrl === 'string' && payload.downloadUrl) {
                                if (screen.status && config.processingDone) {
                                    screen.status.textContent = config.processingDone;
                                }
                                updateProcessingProgress(screen, 1);
                                window.setTimeout(function () {
                                    window.location.assign(payload.downloadUrl);
                                }, 250);
                                return;
                            }

                            window.setTimeout(function () {
                                runBatch(token, batchNonce).catch(function (error) {
                                    setFailureState(form, submitButtons, screen, error && error.message ? error.message : config.processingFailed);
                                });
                            }, 40);
                        });
                    };

                    fetch(adminConfig.ajaxUrl, {
                        method: 'POST',
                        body: startData,
                        credentials: 'same-origin'
                    }).then(readJsonResponse).then(function (payload) {
                        var token = typeof payload.token === 'string' ? payload.token : '';
                        var batchNonce = typeof payload.batchNonce === 'string' ? payload.batchNonce : '';

                        if (screen.status && typeof payload.statusText === 'string' && payload.statusText) {
                            screen.status.textContent = payload.statusText;
                        }
                        updateProcessingProgress(screen, Number(payload.progressRatio));

                        if (!token || !batchNonce) {
                            throw new Error(config.processingFailed);
                        }

                        return runBatch(token, batchNonce);
                    }).catch(function (error) {
                        setFailureState(form, submitButtons, screen, error && error.message ? error.message : config.processingFailed);
                    });
                });
            })(forms[i]);
        }
    }

    function getSelectedMode(radioNodes) {
        for (var i = 0; i < radioNodes.length; i++) {
            if (radioNodes[i].checked) {
                return radioNodes[i].value;
            }
        }
        return '';
    }

    function initImportWordsetModeUi() {
        var modeRadios = document.querySelectorAll('input[name="ll_import_wordset_mode"]');
        if (!modeRadios.length) {
            return;
        }

        var targetSelect = document.getElementById('ll_import_confirm_target_wordset');
        var nameOverridesWrap = document.getElementById('ll-tools-import-wordset-name-overrides');
        var nameInputs = nameOverridesWrap
            ? nameOverridesWrap.querySelectorAll('input[name^="ll_import_wordset_names["]')
            : [];

        function syncUi() {
            var mode = getSelectedMode(modeRadios);
            var assignExisting = (mode === 'assign_existing');

            if (targetSelect) {
                var noWordsets = targetSelect.getAttribute('data-no-wordsets') === '1';
                targetSelect.disabled = noWordsets || !assignExisting;
            }

            if (nameOverridesWrap) {
                nameOverridesWrap.hidden = assignExisting;
                for (var i = 0; i < nameInputs.length; i++) {
                    nameInputs[i].disabled = assignExisting;
                }
            }
        }

        for (var i = 0; i < modeRadios.length; i++) {
            modeRadios[i].addEventListener('change', syncUi);
        }

        syncUi();
    }

    function initAutoPreviewOnZipUpload() {
        var fileInput = document.getElementById('ll_import_file');
        if (!fileInput || !fileInput.form) {
            return;
        }

        var form = fileInput.form;
        var actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || actionInput.value !== 'll_tools_preview_import_bundle') {
            return;
        }

        var hasSubmitted = false;

        fileInput.addEventListener('change', function () {
            var hasFile = !!(fileInput.files && fileInput.files.length);
            if (!hasFile || hasSubmitted) {
                return;
            }

            hasSubmitted = true;

            var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            for (var i = 0; i < submitButtons.length; i++) {
                submitButtons[i].disabled = true;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        });
    }

    function initImportPreviewAudioButtons() {
        var buttons = document.querySelectorAll('.ll-tools-import-sample-grid .ll-study-recording-btn[data-ll-import-preview-audio]');
        if (!buttons.length) {
            return;
        }

        var audio = new Audio();
        audio.preload = 'none';
        var activeButton = null;

        function clearActiveButton() {
            if (!activeButton) {
                return;
            }
            activeButton.classList.remove('is-playing');
            activeButton.setAttribute('aria-pressed', 'false');
            activeButton = null;
        }

        audio.addEventListener('ended', function () {
            clearActiveButton();
        });

        for (var i = 0; i < buttons.length; i++) {
            (function (button) {
                button.setAttribute('aria-pressed', 'false');
                button.addEventListener('click', function () {
                    var src = button.getAttribute('data-ll-import-preview-audio');
                    if (!src) {
                        return;
                    }

                    if (activeButton === button && !audio.paused) {
                        audio.pause();
                        clearActiveButton();
                        return;
                    }

                    if (activeButton && activeButton !== button) {
                        activeButton.classList.remove('is-playing');
                        activeButton.setAttribute('aria-pressed', 'false');
                    }

                    activeButton = button;
                    button.classList.add('is-playing');
                    button.setAttribute('aria-pressed', 'true');

                    audio.pause();
                    audio.currentTime = 0;
                    audio.src = src;

                    var playPromise = audio.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(function () {
                            clearActiveButton();
                        });
                    }
                });
            })(buttons[i]);
        }
    }

    function initExportFullBundleCategoryUi() {
        var includeFull = document.getElementById('ll_export_include_full');
        var multiCategorySelect = document.getElementById('ll_full_export_category_ids');
        var fullWordsetSelect = document.getElementById('ll_full_export_wordset_id');
        var exportTemplate = document.getElementById('ll_export_wordset_template');
        var templateWordsetSelect = document.getElementById('ll_template_export_wordset_id');
        if (!includeFull || !multiCategorySelect) {
            return;
        }

        function syncUi() {
            var templateEnabled = !!(exportTemplate && exportTemplate.checked);
            var noCategories = multiCategorySelect.getAttribute('data-no-categories') === '1';
            if (exportTemplate) {
                includeFull.disabled = templateEnabled;
                if (templateEnabled) {
                    includeFull.checked = false;
                }
            }

            if (templateWordsetSelect) {
                var noWordsetsForTemplate = templateWordsetSelect.getAttribute('data-no-wordsets') === '1';
                templateWordsetSelect.disabled = noWordsetsForTemplate || !templateEnabled;
            }

            if (fullWordsetSelect) {
                var noWordsetsForFull = fullWordsetSelect.getAttribute('data-no-wordsets') === '1';
                fullWordsetSelect.disabled = noWordsetsForFull || !includeFull.checked || templateEnabled;
            }

            multiCategorySelect.disabled = noCategories || !includeFull.checked || templateEnabled;
        }

        includeFull.addEventListener('change', syncUi);
        if (exportTemplate) {
            exportTemplate.addEventListener('change', syncUi);
        }
        syncUi();
    }

    function initExportWordTextDialectSync() {
        var wordsetSelect = document.getElementById('ll_export_wordset');
        var dialectInput = document.getElementById('ll_export_dialect');
        if (!wordsetSelect || !dialectInput) {
            return;
        }

        function getSelectedWordsetName() {
            if (wordsetSelect.selectedIndex < 0) {
                return '';
            }

            var option = wordsetSelect.options[wordsetSelect.selectedIndex];
            return option && typeof option.text === 'string' ? option.text.trim() : '';
        }

        var lastSyncedDialect = getSelectedWordsetName();
        var initialDialect = typeof dialectInput.value === 'string' ? dialectInput.value.trim() : '';

        if (initialDialect === '' && lastSyncedDialect !== '') {
            dialectInput.value = lastSyncedDialect;
            initialDialect = lastSyncedDialect;
        }

        dialectInput.setAttribute(
            'data-ll-dialect-sync-mode',
            (initialDialect === '' || initialDialect === lastSyncedDialect) ? 'auto' : 'manual'
        );

        dialectInput.addEventListener('input', function () {
            var currentDialect = typeof dialectInput.value === 'string' ? dialectInput.value.trim() : '';
            dialectInput.setAttribute(
                'data-ll-dialect-sync-mode',
                (currentDialect === '' || currentDialect === lastSyncedDialect) ? 'auto' : 'manual'
            );
        });

        wordsetSelect.addEventListener('change', function () {
            var currentDialect = typeof dialectInput.value === 'string' ? dialectInput.value.trim() : '';
            var nextDialect = getSelectedWordsetName();
            var syncMode = dialectInput.getAttribute('data-ll-dialect-sync-mode');

            if (syncMode === 'auto' || currentDialect === '' || currentDialect === lastSyncedDialect) {
                dialectInput.value = nextDialect;
                dialectInput.setAttribute('data-ll-dialect-sync-mode', 'auto');
            }

            lastSyncedDialect = nextDialect;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initExportFullBundleCategoryUi();
        initExportBatchProgressUi();
        initExportWordTextDialectSync();
        initImportWordsetModeUi();
        initAutoPreviewOnZipUpload();
        initImportConfirmProgressUi();
        initImportPreviewAudioButtons();
        initReferenceCopyButtons();
    });
})();
