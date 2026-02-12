(function () {
    'use strict';

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

    document.addEventListener('DOMContentLoaded', function () {
        initImportWordsetModeUi();
        initAutoPreviewOnZipUpload();
    });
})();
