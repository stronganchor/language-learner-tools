(function (root) {
    'use strict';

    if (root.LLToolsOptionConflicts) {
        return;
    }

    function normalizeWordId(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function extractMaskedImageAttachmentId(rawUrl) {
        const url = String(rawUrl || '').trim();
        if (!url) {
            return 0;
        }

        const directMatch = url.match(/[?&]lltools-img=(\d+)/i);
        if (directMatch && directMatch[1]) {
            return normalizeWordId(directMatch[1]);
        }

        if (typeof URL === 'function') {
            try {
                const baseHref = (root.location && root.location.href) ? root.location.href : 'http://localhost/';
                const parsed = new URL(url, baseHref);
                return normalizeWordId(parsed.searchParams.get('lltools-img'));
            } catch (_) {
                return 0;
            }
        }

        return 0;
    }

    function getWordImageIdentity(word) {
        if (!word || typeof word !== 'object' || !word.image) {
            return '';
        }

        const raw = String(word.image).trim();
        if (!raw) {
            return '';
        }

        const attachmentId = extractMaskedImageAttachmentId(raw);
        if (attachmentId > 0) {
            return 'attachment:' + String(attachmentId);
        }

        return 'url:' + raw.split('#')[0];
    }

    function normalizeTextForComparison(text) {
        const base = (text === null || text === undefined) ? '' : String(text).trim();
        if (!base) {
            return '';
        }

        const prepared = base.replace(/[I\u0130]/g, function (ch) {
            return ch === 'I' ? '\u0131' : 'i';
        });

        let lowered = prepared;
        try {
            lowered = prepared.toLocaleLowerCase('tr');
        } catch (_) {
            lowered = prepared.toLowerCase();
        }

        return lowered.replace(/\u0307/g, '');
    }

    function normalizeRecordingTypeKey(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9-]/g, '');
    }

    function getNormalizedWordTitle(word) {
        if (!word || typeof word !== 'object') {
            return '';
        }

        return normalizeTextForComparison(word.title);
    }

    function getNormalizedWordTranslation(word) {
        if (!word || typeof word !== 'object') {
            return '';
        }

        return normalizeTextForComparison(word.translation);
    }

    function getNormalizedRecordingTextForType(word, recordingType) {
        const typeKey = normalizeRecordingTypeKey(recordingType);
        if (!word || typeof word !== 'object' || !typeKey) {
            return '';
        }

        const textMap = (word.recording_texts_by_type && typeof word.recording_texts_by_type === 'object')
            ? word.recording_texts_by_type
            : null;
        if (textMap) {
            const mapKeys = Object.keys(textMap);
            for (let i = 0; i < mapKeys.length; i += 1) {
                if (normalizeRecordingTypeKey(mapKeys[i]) !== typeKey) {
                    continue;
                }
                return normalizeTextForComparison(textMap[mapKeys[i]]);
            }
        }

        const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
        for (let i = 0; i < audioFiles.length; i += 1) {
            const entry = audioFiles[i] || {};
            if (normalizeRecordingTypeKey(entry.recording_type) !== typeKey) {
                continue;
            }
            const normalized = normalizeTextForComparison(entry.recording_text || '');
            if (normalized) {
                return normalized;
            }
        }

        return '';
    }

    function getPromptRecordingTypeBlockedIds(word, recordingType) {
        const typeKey = normalizeRecordingTypeKey(recordingType);
        const map = (word && typeof word === 'object' && word.option_blocked_ids_by_recording_type && typeof word.option_blocked_ids_by_recording_type === 'object')
            ? word.option_blocked_ids_by_recording_type
            : null;
        if (!typeKey || !map) {
            return [];
        }

        const keys = Object.keys(map);
        for (let i = 0; i < keys.length; i += 1) {
            if (normalizeRecordingTypeKey(keys[i]) !== typeKey) {
                continue;
            }
            return Array.isArray(map[keys[i]]) ? map[keys[i]] : [];
        }

        return [];
    }

    function wordHasBlockedId(word, otherId) {
        if (!word || !otherId || !Array.isArray(word.option_blocked_ids)) {
            return false;
        }

        return word.option_blocked_ids.some(function (id) {
            return normalizeWordId(id) === otherId;
        });
    }

    function wordHasPromptRecordingTypeBlockedId(word, otherId, recordingType) {
        if (!word || !otherId) {
            return false;
        }

        return getPromptRecordingTypeBlockedIds(word, recordingType).some(function (id) {
            return normalizeWordId(id) === otherId;
        });
    }

    function wordsConflictForOptions(leftWord, rightWord, context) {
        const leftId = normalizeWordId(leftWord && leftWord.id);
        const rightId = normalizeWordId(rightWord && rightWord.id);
        if (!leftId || !rightId || leftId === rightId) {
            return false;
        }

        if (wordHasBlockedId(leftWord, rightId) || wordHasBlockedId(rightWord, leftId)) {
            return true;
        }

        const leftTitle = getNormalizedWordTitle(leftWord);
        const rightTitle = getNormalizedWordTitle(rightWord);
        if (leftTitle && leftTitle === rightTitle) {
            return true;
        }

        const leftTranslation = getNormalizedWordTranslation(leftWord);
        const rightTranslation = getNormalizedWordTranslation(rightWord);
        if (leftTranslation && leftTranslation === rightTranslation) {
            return true;
        }

        const promptRecordingType = normalizeRecordingTypeKey(context && context.promptRecordingType);
        if (promptRecordingType) {
            if (
                wordHasPromptRecordingTypeBlockedId(leftWord, rightId, promptRecordingType)
                || wordHasPromptRecordingTypeBlockedId(rightWord, leftId, promptRecordingType)
            ) {
                return true;
            }

            const leftPromptText = getNormalizedRecordingTextForType(leftWord, promptRecordingType);
            const rightPromptText = getNormalizedRecordingTextForType(rightWord, promptRecordingType);
            if (leftPromptText && leftPromptText === rightPromptText) {
                return true;
            }
        }

        const leftImage = getWordImageIdentity(leftWord);
        const rightImage = getWordImageIdentity(rightWord);
        return !!leftImage && leftImage === rightImage;
    }

    root.LLToolsOptionConflicts = {
        normalizeWordId: normalizeWordId,
        extractMaskedImageAttachmentId: extractMaskedImageAttachmentId,
        getWordImageIdentity: getWordImageIdentity,
        normalizeTextForComparison: normalizeTextForComparison,
        normalizeRecordingTypeKey: normalizeRecordingTypeKey,
        getNormalizedRecordingTextForType: getNormalizedRecordingTextForType,
        wordHasBlockedId: wordHasBlockedId,
        wordHasPromptRecordingTypeBlockedId: wordHasPromptRecordingTypeBlockedId,
        wordsConflictForOptions: wordsConflictForOptions
    };
})(window);
