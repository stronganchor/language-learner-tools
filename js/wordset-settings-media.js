(function () {
    function getMediaConfig() {
        if (typeof window.llWordsetSettingsMediaData === 'object' && window.llWordsetSettingsMediaData) {
            return window.llWordsetSettingsMediaData;
        }

        return {};
    }

    function getAttachmentPreviewUrl(attachment) {
        if (!attachment || typeof attachment !== 'object') {
            return '';
        }

        if (attachment.sizes && attachment.sizes.medium_large && attachment.sizes.medium_large.url) {
            return String(attachment.sizes.medium_large.url);
        }

        if (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) {
            return String(attachment.sizes.medium.url);
        }

        if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
            return String(attachment.sizes.thumbnail.url);
        }

        return attachment.url ? String(attachment.url) : '';
    }

    function initWordsetButtonImagePicker(root) {
        if (!root) {
            return;
        }

        var input = root.querySelector('[data-ll-wordset-button-image-input]');
        var frameWrap = root.querySelector('[data-ll-wordset-button-image-frame]');
        var preview = root.querySelector('[data-ll-wordset-button-image-preview]');
        var emptyState = root.querySelector('[data-ll-wordset-button-image-empty]');
        var selectedLabel = root.querySelector('[data-ll-wordset-button-image-selected]');
        var chooseButton = root.querySelector('[data-ll-wordset-button-image-choose]');
        var clearButton = root.querySelector('[data-ll-wordset-button-image-clear]');

        if (!input || !chooseButton || !clearButton) {
            return;
        }

        var mediaFrame = null;
        var state = {
            attachmentId: parseInt(input.value || '0', 10) || 0,
            previewUrl: preview && preview.getAttribute('src') ? String(preview.getAttribute('src')) : '',
            label: selectedLabel ? String(selectedLabel.textContent || '').trim() : ''
        };

        function renderState() {
            var hasImage = state.attachmentId > 0 && state.previewUrl !== '';
            input.value = state.attachmentId > 0 ? String(state.attachmentId) : '';

            if (frameWrap) {
                frameWrap.hidden = !hasImage;
            }

            if (preview && hasImage) {
                preview.setAttribute('src', state.previewUrl);
            }

            if (emptyState) {
                emptyState.hidden = hasImage;
            }

            if (selectedLabel) {
                selectedLabel.textContent = state.label;
                selectedLabel.hidden = state.label === '';
            }

            clearButton.disabled = state.attachmentId <= 0;
        }

        chooseButton.addEventListener('click', function () {
            var config = getMediaConfig();
            if (!window.wp || !wp.media) {
                return;
            }

            if (!mediaFrame) {
                mediaFrame = wp.media({
                    title: String(config.chooseTitle || ''),
                    button: {
                        text: String(config.chooseButton || '')
                    },
                    multiple: false,
                    library: {
                        type: 'image'
                    }
                });

                mediaFrame.on('select', function () {
                    var selection = mediaFrame.state().get('selection');
                    var attachment = selection && selection.first ? selection.first().toJSON() : null;
                    if (!attachment || !attachment.id) {
                        return;
                    }

                    state.attachmentId = parseInt(attachment.id, 10) || 0;
                    state.previewUrl = getAttachmentPreviewUrl(attachment);
                    state.label = String(attachment.title || attachment.filename || '');
                    renderState();
                });
            }

            mediaFrame.open();
        });

        clearButton.addEventListener('click', function () {
            state.attachmentId = 0;
            state.previewUrl = '';
            state.label = '';
            renderState();
        });

        renderState();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var pickers = document.querySelectorAll('[data-ll-wordset-button-image-picker]');
        if (!pickers.length) {
            return;
        }

        pickers.forEach(initWordsetButtonImagePicker);
    });
})();
