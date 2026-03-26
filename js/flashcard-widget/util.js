(function (root) {
    'use strict';
    const Util = {
        randomlySort(arr) { return Array.isArray(arr) ? [...arr].sort(() => 0.5 - Math.random()) : arr; },
        randomInt(min, max) { return Math.floor((Math.random() * (max - min + 1)) + min); },
        normalizePromptType(value) {
            return String(value || '').trim().toLowerCase() || 'audio';
        },
        normalizeOptionType(value) {
            return String(value || '').trim().toLowerCase();
        },
        normalizeRecordingTypeKey(value) {
            return String(value || '')
                .trim()
                .toLowerCase()
                .replace(/[\s_]+/g, '-')
                .replace(/[^a-z0-9-]/g, '');
        },
        getPromptTextType(promptType) {
            const normalized = Util.normalizePromptType(promptType);
            if (normalized === 'text_translation' || normalized === 'audio_text_translation' || normalized === 'image_text_translation') {
                return 'text_translation';
            }
            if (normalized === 'text_title' || normalized === 'audio_text_title' || normalized === 'image_text_title') {
                return 'text_title';
            }
            return '';
        },
        promptTypeHasText(promptType) {
            return Util.getPromptTextType(promptType) !== '';
        },
        promptTypeHasAudio(promptType) {
            const normalized = Util.normalizePromptType(promptType);
            return normalized === 'audio' || normalized === 'audio_text_translation' || normalized === 'audio_text_title';
        },
        promptTypeHasImage(promptType) {
            const normalized = Util.normalizePromptType(promptType);
            return normalized === 'image' || normalized === 'image_text_translation' || normalized === 'image_text_title';
        },
        isPlainTextOptionType(optionType) {
            const normalized = Util.normalizeOptionType(optionType);
            return normalized === 'text' || normalized === 'text_title' || normalized === 'text_translation';
        },
        isTextToTextQuizPresentation(promptType, optionType) {
            return Util.promptTypeHasText(promptType)
                && !Util.promptTypeHasImage(promptType)
                && Util.isPlainTextOptionType(optionType);
        },
        getRecordingTranslationForType(word, recordingType) {
            const typeKey = Util.normalizeRecordingTypeKey(recordingType);
            if (!word || typeof word !== 'object' || !typeKey) {
                return '';
            }

            const translationMap = (word.recording_translations_by_type && typeof word.recording_translations_by_type === 'object')
                ? word.recording_translations_by_type
                : null;
            if (translationMap) {
                const mapKeys = Object.keys(translationMap);
                for (let i = 0; i < mapKeys.length; i += 1) {
                    if (Util.normalizeRecordingTypeKey(mapKeys[i]) !== typeKey) {
                        continue;
                    }
                    const mappedValue = String(translationMap[mapKeys[i]] || '').trim();
                    if (mappedValue) {
                        return mappedValue;
                    }
                }
            }

            const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
            for (let i = 0; i < audioFiles.length; i += 1) {
                const entry = audioFiles[i] || {};
                if (Util.normalizeRecordingTypeKey(entry.recording_type) !== typeKey) {
                    continue;
                }
                const entryValue = String(entry.recording_translation || '').trim();
                if (entryValue) {
                    return entryValue;
                }
            }

            return '';
        },
        getEffectiveOptionLabel(word, optionType, promptType, options) {
            if (!word || typeof word !== 'object') {
                return '';
            }

            const normalizedOptionType = Util.normalizeOptionType(optionType);
            const opts = (options && typeof options === 'object') ? options : {};
            const promptRecordingType = Util.normalizeRecordingTypeKey(
                opts.promptRecordingType
                    || word.__activeOptionRecordingType
                    || word.__promptRecordingType
                    || word.__practiceRecordingType
            );

            if (
                Util.promptTypeHasAudio(promptType)
                && (normalizedOptionType === 'text_translation' || normalizedOptionType === 'text_audio')
                && promptRecordingType
            ) {
                const recordingTranslation = Util.getRecordingTranslationForType(word, promptRecordingType);
                if (recordingTranslation) {
                    return recordingTranslation;
                }
            }

            const label = String(word.label || '').trim();
            if (label) {
                return label;
            }

            if (normalizedOptionType === 'text_translation' || normalizedOptionType === 'text_audio') {
                const translation = String(word.translation || '').trim();
                if (translation) {
                    return translation;
                }
            }

            return String(word.title || '').trim();
        },
        protectMaqafNoBreak(value) {
            const text = (value === null || value === undefined) ? '' : String(value);
            if (!text) return '';
            if (text.indexOf('\u05BE') === -1 && text.indexOf('\u2060') === -1) return text;
            return text.replace(/\u2060*\u05BE\u2060*/gu, '\u2060\u05BE\u2060');
        },
        measureTextWidth: (function () {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            return function (text, cssFont) { ctx.font = cssFont; return ctx.measureText(text).width; };
        })(),
    };
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Util = Util;
})(window);
