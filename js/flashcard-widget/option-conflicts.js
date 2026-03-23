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

    function wordHasBlockedId(word, otherId) {
        if (!word || !otherId || !Array.isArray(word.option_blocked_ids)) {
            return false;
        }

        return word.option_blocked_ids.some(function (id) {
            return normalizeWordId(id) === otherId;
        });
    }

    function wordsConflictForOptions(leftWord, rightWord) {
        const leftId = normalizeWordId(leftWord && leftWord.id);
        const rightId = normalizeWordId(rightWord && rightWord.id);
        if (!leftId || !rightId || leftId === rightId) {
            return false;
        }

        if (wordHasBlockedId(leftWord, rightId) || wordHasBlockedId(rightWord, leftId)) {
            return true;
        }

        const leftImage = getWordImageIdentity(leftWord);
        const rightImage = getWordImageIdentity(rightWord);
        return !!leftImage && leftImage === rightImage;
    }

    root.LLToolsOptionConflicts = {
        normalizeWordId: normalizeWordId,
        extractMaskedImageAttachmentId: extractMaskedImageAttachmentId,
        getWordImageIdentity: getWordImageIdentity,
        wordHasBlockedId: wordHasBlockedId,
        wordsConflictForOptions: wordsConflictForOptions
    };
})(window);
