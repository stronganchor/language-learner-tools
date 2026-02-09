(function (root) {
    'use strict';

    const $ = root.jQuery;
    if (!$) { return; }

    function getWordText(word) {
        if (!word || typeof word !== 'object') { return ''; }
        const label = (typeof word.label === 'string') ? word.label.trim() : '';
        if (label) { return label; }
        const title = (typeof word.title === 'string') ? word.title.trim() : '';
        if (title) { return title; }
        return '';
    }

    function isTextOptionType(mode) {
        const normalized = String(mode || '').toLowerCase();
        return normalized === 'text' || normalized === 'text_title' || normalized === 'text_translation' || normalized === 'text_audio';
    }

    function isAudioOptionType(mode) {
        const normalized = String(mode || '').toLowerCase();
        return normalized === 'audio' || normalized === 'text_audio';
    }

    function getWordAudioUrl(word) {
        if (!word || typeof word !== 'object') { return ''; }
        if (word.audio) {
            return String(word.audio);
        }
        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (file && file.url) {
                return String(file.url);
            }
        }
        return '';
    }

    function buildAudioButton(options) {
        const opts = options || {};
        const label = (typeof opts.label === 'string' && opts.label) ? opts.label : 'Play audio';
        const audioUrl = (typeof opts.audioUrl === 'string') ? opts.audioUrl : '';

        const $btn = $('<button>', {
            type: 'button',
            class: 'll-study-recording-btn ll-study-recording-btn--prompt',
            'data-audio-url': audioUrl,
            'data-recording-type': 'prompt',
            'aria-label': label,
            title: label
        });

        $('<span>', {
            class: 'll-study-recording-icon',
            'aria-hidden': 'true',
            'data-emoji': '\u25B6'
        }).appendTo($btn);

        const $viz = $('<span>', {
            class: 'll-study-recording-visualizer',
            'aria-hidden': 'true'
        });
        for (let i = 0; i < 6; i++) {
            $('<span>', { class: 'bar' }).appendTo($viz);
        }
        $btn.append($viz);

        if (typeof opts.onActivate === 'function') {
            $btn.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                opts.onActivate(audioUrl, this, e);
            });
        }

        return $btn;
    }

    function renderPromptDisplay($container, displayType, word, options) {
        if (!$container || !$container.length) { return false; }

        const opts = options || {};
        const emptyLabel = (typeof opts.emptyLabel === 'string' && opts.emptyLabel)
            ? opts.emptyLabel
            : 'No content available.';
        const playAudioLabel = (typeof opts.playAudioLabel === 'string' && opts.playAudioLabel)
            ? opts.playAudioLabel
            : 'Play audio';

        $container.empty();

        const mode = String(displayType || '');
        const showImage = mode === 'image';
        const showText = isTextOptionType(mode);
        const showAudio = isAudioOptionType(mode);
        const text = getWordText(word);
        const audioUrl = showAudio ? getWordAudioUrl(word) : '';

        const $inner = $('<div>', { class: 'll-study-check-prompt-inner' });
        let hasContent = false;

        if (showImage && word && word.image) {
            const $imgWrap = $('<div>', { class: 'll-study-check-image' });
            $('<img>', {
                src: word.image,
                alt: text || '',
                draggable: false,
                loading: 'lazy'
            }).appendTo($imgWrap);
            $inner.append($imgWrap);
            hasContent = true;
        }

        if (showText && text) {
            $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
            hasContent = true;
        }

        if (showAudio && audioUrl) {
            $inner.append(buildAudioButton({
                audioUrl: audioUrl,
                label: playAudioLabel,
                onActivate: opts.onPlayAudio
            }));
            hasContent = true;
        }

        if (!$inner.children().length) {
            if (text) {
                $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
                hasContent = true;
            } else {
                $('<div>', { class: 'll-study-check-empty', text: emptyLabel }).appendTo($inner);
            }
        }

        $container.append($inner);
        return hasContent;
    }

    root.LLToolsSelfCheckShared = Object.assign({}, root.LLToolsSelfCheckShared || {}, {
        getWordText: getWordText,
        isTextOptionType: isTextOptionType,
        isAudioOptionType: isAudioOptionType,
        getWordAudioUrl: getWordAudioUrl,
        buildAudioButton: buildAudioButton,
        renderPromptDisplay: renderPromptDisplay
    });
})(window);
