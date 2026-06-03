(function ($) {
    'use strict';

    const cfg = window.llToolsWordEditModalData || {};
    const ajaxUrl = (cfg.ajaxUrl || '').toString();
    const nonce = (cfg.nonce || '').toString();
    const i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};
    const hostSelector = '[data-ll-word-edit-modal-host]';
    const gridSelector = '[data-ll-word-edit-modal-grid]';

    function t(key, fallback) {
        const value = i18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function readAjaxMessage(response, fallback) {
        if (response && response.data) {
            if (typeof response.data === 'string' && response.data) {
                return response.data;
            }
            if (typeof response.data.message === 'string' && response.data.message) {
                return response.data.message;
            }
        }
        if (response && typeof response.message === 'string' && response.message) {
            return response.message;
        }
        return fallback;
    }

    function getHost() {
        return $(hostSelector).first();
    }

    function getHostGrid() {
        return getHost().find(gridSelector).first();
    }

    function syncElementAttributes(source, target) {
        Array.from(target.attributes).forEach(function (attr) {
            target.removeAttribute(attr.name);
        });
        Array.from(source.attributes).forEach(function (attr) {
            target.setAttribute(attr.name, attr.value);
        });
    }

    function applyWordGridConfig(config) {
        if (!config || typeof config !== 'object') {
            return;
        }
        if (
            window.LLToolsWordGrid
            && typeof window.LLToolsWordGrid.applyConfig === 'function'
        ) {
            window.LLToolsWordGrid.applyConfig(config);
        }
    }

    function renderGridMarkup(html) {
        const $targetGrid = getHostGrid();
        if (!$targetGrid.length) {
            throw new Error(t('missingHost', 'Word editor modal is not available on this page.'));
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = (html || '').toString();
        const sourceGrid = wrapper.querySelector('[data-ll-word-grid]');
        if (!sourceGrid) {
            throw new Error(t('renderError', 'Unable to open the word editor.'));
        }

        const targetGrid = $targetGrid.get(0);
        syncElementAttributes(sourceGrid, targetGrid);
        targetGrid.setAttribute('data-ll-word-grid', '');
        targetGrid.setAttribute('data-ll-word-edit-modal-grid', '1');
        $targetGrid.addClass('word-grid ll-word-grid');
        targetGrid.innerHTML = sourceGrid.innerHTML;

        const $item = $targetGrid.find('.word-item[data-word-id]').first();
        if (!$item.length) {
            throw new Error(t('renderError', 'Unable to open the word editor.'));
        }

        $(document).trigger('lltools:word-grid-rendered', [{ scope: $item }]);
        return $item;
    }

    function focusRecording($item, recordingId) {
        const id = parseInt(recordingId, 10) || 0;
        if (!id) {
            return;
        }

        const $recording = $item.find('.ll-word-edit-recording[data-recording-id="' + id + '"]').first();
        if (!$recording.length) {
            return;
        }

        $recording.addClass('ll-word-edit-recording--target');
        window.setTimeout(function () {
            const recording = $recording.get(0);
            if (recording && typeof recording.scrollIntoView === 'function') {
                recording.scrollIntoView({ block: 'center', inline: 'nearest' });
            }
            const $input = $recording.find('[data-ll-recording-input="ipa"], [data-ll-recording-input="text"], input, textarea, select')
                .filter(':enabled:visible')
                .first();
            if ($input.length) {
                $input.trigger('focus');
            }
        }, 80);
    }

    function openWordEditor(options) {
        const settings = (options && typeof options === 'object') ? options : {};
        const wordId = parseInt(settings.wordId || settings.word_id, 10) || 0;
        const wordsetId = parseInt(settings.wordsetId || settings.wordset_id, 10) || 0;
        const recordingId = parseInt(settings.recordingId || settings.recording_id, 10) || 0;
        const categoryId = parseInt(settings.categoryId || settings.category_id, 10) || 0;
        const $host = getHost();

        if (!wordId || !wordsetId || !ajaxUrl || !nonce) {
            return Promise.reject(new Error(t('openError', 'Unable to open the word editor.')));
        }
        if (!$host.length || !getHostGrid().length) {
            return Promise.reject(new Error(t('missingHost', 'Word editor modal is not available on this page.')));
        }

        $host.attr('aria-busy', 'true').attr('data-ll-word-edit-modal-loading', '1');
        $(document).trigger('lltools:word-edit-modal-loading', [{
            wordId: wordId,
            wordsetId: wordsetId,
            recordingId: recordingId
        }]);

        return new Promise(function (resolve, reject) {
            $.post(ajaxUrl, {
                action: 'll_tools_get_word_edit_modal_grid',
                nonce: nonce,
                word_id: wordId,
                wordset_id: wordsetId,
                category_id: categoryId
            }).done(function (response) {
                if (!response || response.success !== true || !response.data) {
                    reject(new Error(readAjaxMessage(response, t('openError', 'Unable to open the word editor.'))));
                    return;
                }

                try {
                    const data = response.data || {};
                    applyWordGridConfig(data.config || null);
                    const $item = renderGridMarkup(data.html || '');
                    const $toggle = $item.find('[data-ll-word-edit-toggle]').first();
                    if (!$toggle.length) {
                        throw new Error(t('renderError', 'Unable to open the word editor.'));
                    }
                    $toggle.trigger('click');
                    focusRecording($item, recordingId);
                    $(document).trigger('lltools:word-edit-modal-opened', [{
                        wordId: wordId,
                        wordsetId: wordsetId,
                        recordingId: recordingId,
                        item: $item.get(0)
                    }]);
                    resolve({
                        wordId: wordId,
                        wordsetId: wordsetId,
                        recordingId: recordingId,
                        item: $item.get(0)
                    });
                } catch (error) {
                    reject(error);
                }
            }).fail(function (jqXHR) {
                const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
                reject(new Error(readAjaxMessage(response, t('openError', 'Unable to open the word editor.'))));
            }).always(function () {
                $host.removeAttr('aria-busy').removeAttr('data-ll-word-edit-modal-loading');
            });
        }).catch(function (error) {
            $(document).trigger('lltools:word-edit-modal-error', [{
                wordId: wordId,
                wordsetId: wordsetId,
                recordingId: recordingId,
                message: error && error.message ? error.message : t('openError', 'Unable to open the word editor.')
            }]);
            throw error;
        });
    }

    window.LLToolsWordEditModal = window.LLToolsWordEditModal || {};
    window.LLToolsWordEditModal.open = openWordEditor;
})(jQuery);
