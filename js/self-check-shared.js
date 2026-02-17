(function (root) {
    'use strict';

    const $ = root.jQuery;
    if (!$) { return; }
    const RECORDING_ICONS = {
        isolation: '\ud83d\udd0d',
        introduction: '\ud83d\udcac'
    };

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
        const preferredSpeaker = parseInt(word.preferred_speaker_user_id, 10) || 0;
        if (preferredSpeaker) {
            const speakerMatch = files.find(function (file) {
                return file && file.url && parseInt(file.speaker_user_id, 10) === preferredSpeaker;
            });
            if (speakerMatch && speakerMatch.url) {
                return String(speakerMatch.url);
            }
        }
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (file && file.url) {
                return String(file.url);
            }
        }
        return '';
    }

    function normalizeRecordingType(value) {
        return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function normalizeRecordingTypes(types) {
        const source = Array.isArray(types) ? types : [types];
        return source.map(normalizeRecordingType).filter(Boolean);
    }

    function selectRecordingUrl(word, preferredTypes, options) {
        if (!word || typeof word !== 'object') { return ''; }
        const opts = options || {};
        const typeList = normalizeRecordingTypes(preferredTypes);
        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        const preferredSpeaker = parseInt(opts.preferredSpeakerUserId, 10) || parseInt(word.preferred_speaker_user_id, 10) || 0;
        let match = null;

        const typeMatches = function (file) {
            if (!file || !file.url) { return false; }
            if (!typeList.length) { return true; }
            return typeList.indexOf(normalizeRecordingType(file.recording_type)) !== -1;
        };

        if (preferredSpeaker) {
            match = files.find(function (file) {
                return typeMatches(file) && parseInt(file.speaker_user_id, 10) === preferredSpeaker;
            });
        }
        if (!match) {
            match = files.find(typeMatches);
        }

        if (match && match.url) {
            return String(match.url);
        }
        return opts.fallbackToAnyAudio ? getWordAudioUrl(word) : '';
    }

    function getIsolationAudioUrl(word, options) {
        const opts = options || {};
        return selectRecordingUrl(word, ['isolation'], {
            preferredSpeakerUserId: opts.preferredSpeakerUserId,
            fallbackToAnyAudio: !!opts.fallbackToAnyAudio
        });
    }

    function getIntroductionAudioUrl(word, options) {
        if (!word || typeof word !== 'object') { return ''; }
        if (word.introduction_audio) {
            return String(word.introduction_audio);
        }
        if (word.introduction_audio_url) {
            return String(word.introduction_audio_url);
        }

        const opts = options || {};
        return selectRecordingUrl(word, ['introduction', 'in sentence'], {
            preferredSpeakerUserId: opts.preferredSpeakerUserId,
            fallbackToAnyAudio: !!opts.fallbackToAnyAudio
        });
    }

    function getRecordingIcon(recordingType) {
        const type = normalizeRecordingType(recordingType);
        return RECORDING_ICONS[type] || '\u25B6';
    }

    function getRecordingLabel(messages, recordingType) {
        const msgs = (messages && typeof messages === 'object') ? messages : {};
        const type = normalizeRecordingType(recordingType);
        if (type === 'isolation') {
            return msgs.recordingIsolation || 'Isolation';
        }
        if (type === 'introduction' || type === 'in sentence') {
            return msgs.recordingIntroduction || 'Introduction';
        }
        return type || '';
    }

    function formatRecordingLabel(messages, recordingType, fallbackPlayAudioLabel) {
        const msgs = (messages && typeof messages === 'object') ? messages : {};
        const label = getRecordingLabel(msgs, recordingType);
        const template = String(msgs.playAudioType || '').trim();
        if (template.indexOf('%s') !== -1) {
            return template.replace('%s', label);
        }
        if (template) {
            return template + ' ' + label;
        }
        return String(fallbackPlayAudioLabel || msgs.selfCheckPlayAudio || 'Play audio') + ' ' + label;
    }

    function getSelfCheckRecordingEntries(word, options) {
        const opts = options || {};
        const recordingTypes = normalizeRecordingTypes(opts.recordingTypes && opts.recordingTypes.length ? opts.recordingTypes : ['isolation', 'introduction']);
        const entries = [];
        const seen = {};

        recordingTypes.forEach(function (type) {
            if (seen[type]) { return; }
            seen[type] = true;

            let audioUrl = '';
            if (type === 'isolation') {
                audioUrl = getIsolationAudioUrl(word, {
                    preferredSpeakerUserId: opts.preferredSpeakerUserId,
                    fallbackToAnyAudio: !!opts.isolationFallbackToAnyAudio
                });
            } else if (type === 'introduction' || type === 'in sentence') {
                audioUrl = getIntroductionAudioUrl(word, {
                    preferredSpeakerUserId: opts.preferredSpeakerUserId,
                    fallbackToAnyAudio: !!opts.introductionFallbackToAnyAudio
                });
            } else {
                audioUrl = selectRecordingUrl(word, [type], {
                    preferredSpeakerUserId: opts.preferredSpeakerUserId,
                    fallbackToAnyAudio: !!opts.fallbackToAnyAudio
                });
            }

            if (!audioUrl) { return; }
            entries.push({
                type: type === 'in sentence' ? 'introduction' : type,
                audioUrl: String(audioUrl)
            });
        });

        return entries;
    }

    function buildAudioButton(options) {
        const opts = options || {};
        const label = (typeof opts.label === 'string' && opts.label) ? opts.label : 'Play audio';
        const audioUrl = (typeof opts.audioUrl === 'string') ? opts.audioUrl : '';
        const recordingTypeRaw = (typeof opts.recordingType === 'string') ? opts.recordingType : '';
        const normalizedRecordingType = recordingTypeRaw
            ? recordingTypeRaw.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-')
            : '';
        const recordingType = normalizedRecordingType || 'prompt';
        const buttonClass = (typeof opts.className === 'string' && opts.className)
            ? (' ' + opts.className.trim())
            : '';
        const icon = (typeof opts.icon === 'string' && opts.icon) ? opts.icon : '\u25B6';

        const $btn = $('<button>', {
            type: 'button',
            class: 'll-study-recording-btn ll-study-recording-btn--' + recordingType + buttonClass,
            'data-audio-url': audioUrl,
            'data-recording-type': recordingType,
            'aria-label': label,
            title: label
        });

        $('<span>', {
            class: 'll-study-recording-icon',
            'aria-hidden': 'true',
            'data-emoji': icon
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

    function appendRecordingButtons($container, word, options) {
        if (!$container || !$container.length) { return []; }
        const opts = options || {};
        const messages = (opts.messages && typeof opts.messages === 'object') ? opts.messages : {};
        const playAudioLabel = (typeof opts.playAudioLabel === 'string' && opts.playAudioLabel)
            ? opts.playAudioLabel
            : (messages.selfCheckPlayAudio || 'Play audio');
        const recordingsLabel = (typeof opts.recordingsLabel === 'string' && opts.recordingsLabel)
            ? opts.recordingsLabel
            : (messages.recordingsLabel || 'Recordings');
        const onPlayAudio = (typeof opts.onPlayAudio === 'function') ? opts.onPlayAudio : null;
        const entries = getSelfCheckRecordingEntries(word, opts);

        if (!entries.length) {
            return [];
        }

        let $inner = $container.find('.ll-study-check-prompt-inner').first();
        if (!$inner.length) {
            $inner = $('<div>', { class: 'll-study-check-prompt-inner' }).appendTo($container);
        }

        $inner.find('.ll-study-check-recordings').remove();

        const $recordings = $('<div>', {
            class: 'll-study-check-recordings',
            'aria-label': recordingsLabel
        });

        entries.forEach(function (entry) {
            const label = formatRecordingLabel(messages, entry.type, playAudioLabel);
            $recordings.append(buildAudioButton({
                audioUrl: entry.audioUrl,
                label: label,
                icon: getRecordingIcon(entry.type),
                recordingType: entry.type,
                onActivate: onPlayAudio
            }));
        });

        if ($recordings.children().length) {
            $inner.append($recordings);
        }

        return entries;
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

    function renderSelfCheckPromptDisplay($container, word, options) {
        const opts = options || {};
        const mode = String(opts.displayType || 'image');
        return renderPromptDisplay($container, mode, word, {
            emptyLabel: opts.emptyLabel,
            playAudioLabel: opts.playAudioLabel,
            onPlayAudio: opts.onPlayAudio
        });
    }

    function renderSelfCheckAnswerDisplay($container, word, options) {
        const opts = options || {};
        const mode = String(opts.displayType || 'image');
        renderPromptDisplay($container, mode, word, {
            emptyLabel: opts.emptyLabel,
            playAudioLabel: opts.playAudioLabel,
            onPlayAudio: opts.onPlayAudio
        });
        return appendRecordingButtons($container, word, opts);
    }

    root.LLToolsSelfCheckShared = Object.assign({}, root.LLToolsSelfCheckShared || {}, {
        getWordText: getWordText,
        isTextOptionType: isTextOptionType,
        isAudioOptionType: isAudioOptionType,
        normalizeRecordingType: normalizeRecordingType,
        selectRecordingUrl: selectRecordingUrl,
        getIsolationAudioUrl: getIsolationAudioUrl,
        getIntroductionAudioUrl: getIntroductionAudioUrl,
        getRecordingLabel: getRecordingLabel,
        formatRecordingLabel: formatRecordingLabel,
        getRecordingIcon: getRecordingIcon,
        getSelfCheckRecordingEntries: getSelfCheckRecordingEntries,
        getWordAudioUrl: getWordAudioUrl,
        buildAudioButton: buildAudioButton,
        appendRecordingButtons: appendRecordingButtons,
        renderPromptDisplay: renderPromptDisplay,
        renderSelfCheckPromptDisplay: renderSelfCheckPromptDisplay,
        renderSelfCheckAnswerDisplay: renderSelfCheckAnswerDisplay
    });
})(window);
