(function () {
    'use strict';

    function getImportUiConfig() {
        var cfg = window.llToolsImportUi || {};
        return {
            importPageUrl: typeof cfg.importPageUrl === 'string' ? cfg.importPageUrl : '',
            processingTitle: typeof cfg.processingTitle === 'string' ? cfg.processingTitle : '',
            processingMessageKeepOpen: typeof cfg.processingMessageKeepOpen === 'string' ? cfg.processingMessageKeepOpen : '',
            processingMessageBackground: typeof cfg.processingMessageBackground === 'string' ? cfg.processingMessageBackground : '',
            processingProgressLabel: typeof cfg.processingProgressLabel === 'string' ? cfg.processingProgressLabel : '',
            processingDone: typeof cfg.processingDone === 'string' ? cfg.processingDone : '',
            processingFailed: typeof cfg.processingFailed === 'string' ? cfg.processingFailed : '',
            processingReload: typeof cfg.processingReload === 'string' ? cfg.processingReload : ''
        };
    }

    function ensureImportProcessingScreen(config) {
        var existing = document.getElementById('ll-tools-import-processing-screen');
        if (existing) {
            return {
                root: existing,
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
            var target = config.importPageUrl || window.location.href;
            window.location.assign(target);
        });
        card.appendChild(reloadButton);

        root.appendChild(card);
        document.body.appendChild(root);

        return {
            root: root,
            status: status,
            error: error,
            reloadButton: reloadButton
        };
    }

    function initImportConfirmProgressUi() {
        var forms = document.querySelectorAll('form');
        if (!forms.length) {
            return;
        }

        var config = getImportUiConfig();

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

                    var screen = ensureImportProcessingScreen(config);
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

                        var redirectTarget = response.url || config.importPageUrl || window.location.href;
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
        if (!includeFull || !multiCategorySelect) {
            return;
        }

        function syncUi() {
            var noCategories = multiCategorySelect.getAttribute('data-no-categories') === '1';
            multiCategorySelect.disabled = noCategories || !includeFull.checked;
        }

        includeFull.addEventListener('change', syncUi);
        syncUi();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initExportFullBundleCategoryUi();
        initImportWordsetModeUi();
        initAutoPreviewOnZipUpload();
        initImportConfirmProgressUi();
        initImportPreviewAudioButtons();
    });
})();
