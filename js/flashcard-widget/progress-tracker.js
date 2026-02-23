(function (root, $) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    if (root.LLFlashcards.ProgressTracker) {
        return;
    }

    const MAX_QUEUE = 400;
    const MAX_BATCH_SIZE = 200;
    const ALLOWED_TYPES = {
        word_outcome: true,
        word_exposure: true,
        category_study: true,
        mode_session_complete: true
    };
    const MODE_ORDER = ['learning', 'practice', 'listening', 'gender', 'self-check'];

    let queue = [];
    let flushTimer = null;
    let inFlight = false;
    let context = {
        mode: 'practice',
        wordsetId: 0,
        categoryIds: []
    };

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function toIntList(list) {
        const seen = {};
        return (Array.isArray(list) ? list : [])
            .map(function (item) { return toInt(item); })
            .filter(function (id) {
                if (!id || seen[id]) { return false; }
                seen[id] = true;
                return true;
            });
    }

    function parseBool(value, fallback) {
        if (typeof value === 'boolean') { return value; }
        if (typeof value === 'number') { return value > 0; }
        if (typeof value === 'string') {
            const lowered = value.trim().toLowerCase();
            if (['1', 'true', 'yes', 'on'].indexOf(lowered) !== -1) { return true; }
            if (['0', 'false', 'no', 'off', ''].indexOf(lowered) !== -1) { return false; }
        }
        return !!fallback;
    }

    function normalizeMode(mode) {
        let val = String(mode || '').trim().toLowerCase();
        if (val === 'self_check') {
            val = 'self-check';
        }
        return MODE_ORDER.indexOf(val) !== -1 ? val : 'practice';
    }

    function sanitizeString(value) {
        if (value === null || value === undefined) { return ''; }
        return String(value).trim();
    }

    function buildUuid() {
        const ts = Date.now().toString(36);
        const rand = Math.random().toString(36).slice(2, 10);
        return 'llp-' + ts + '-' + rand;
    }

    function getFlashData() {
        return (root && root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData === 'object')
            ? root.llToolsFlashcardsData
            : {};
    }

    function getStudyData() {
        return (root && root.llToolsStudyData && typeof root.llToolsStudyData === 'object')
            ? root.llToolsStudyData
            : {};
    }

    function getAjaxUrl() {
        const flash = getFlashData();
        const study = getStudyData();
        return sanitizeString(flash.ajaxurl || study.ajaxUrl || '');
    }

    function getNonce() {
        const flash = getFlashData();
        const study = getStudyData();
        return sanitizeString(flash.userStudyNonce || study.nonce || '');
    }

    function isUserLoggedIn() {
        const flash = getFlashData();
        if (typeof flash.isUserLoggedIn !== 'undefined') {
            return parseBool(flash.isUserLoggedIn, false);
        }
        return !!getNonce();
    }

    function categoryNameToId(name) {
        const target = sanitizeString(name);
        if (!target) {
            return 0;
        }
        const targetLower = target.toLowerCase();
        const categories = Array.isArray(getFlashData().categories) ? getFlashData().categories : [];
        for (let i = 0; i < categories.length; i++) {
            const cat = categories[i] || {};
            const catName = sanitizeString(cat.name);
            if (catName && catName.toLowerCase() === targetLower) {
                return toInt(cat.id);
            }
            const catSlug = sanitizeString(cat.slug);
            if (catSlug && catSlug.toLowerCase() === targetLower) {
                return toInt(cat.id);
            }
        }
        return 0;
    }

    function categoryIdForWord(word) {
        if (!word || typeof word !== 'object') {
            return 0;
        }
        const explicit = toInt(word.category_id || word.categoryId || 0);
        if (explicit > 0) {
            return explicit;
        }
        const byName = categoryNameToId(word.__categoryName || word.category_name || word.categoryName || '');
        if (byName > 0) {
            return byName;
        }
        const allCategories = Array.isArray(word.all_categories) ? word.all_categories : [];
        if (allCategories.length > 0) {
            return categoryNameToId(allCategories[0]);
        }
        return 0;
    }

    function resolveWordsetId(raw) {
        const explicit = toInt(raw);
        if (explicit > 0) {
            return explicit;
        }
        const active = toInt(context.wordsetId);
        if (active > 0) {
            return active;
        }
        const flash = getFlashData();
        const userState = flash.userStudyState || {};
        const fromState = toInt(userState.wordset_id);
        if (fromState > 0) {
            return fromState;
        }
        const ids = toIntList(flash.wordsetIds || []);
        return ids.length ? ids[0] : 0;
    }

    function resolveCategoryIds(raw) {
        const explicit = toIntList(raw);
        if (explicit.length) {
            return explicit;
        }
        if (context.categoryIds.length) {
            return context.categoryIds.slice();
        }
        const flash = getFlashData();
        const userState = flash.userStudyState || {};
        const fromState = toIntList(userState.category_ids || []);
        if (fromState.length) {
            return fromState;
        }
        return [];
    }

    function normalizeEvent(rawEvent) {
        const eventType = sanitizeString(rawEvent.event_type || rawEvent.type).toLowerCase();
        if (!ALLOWED_TYPES[eventType]) {
            return null;
        }

        const mode = normalizeMode(rawEvent.mode || context.mode || 'practice');
        const wordId = toInt(rawEvent.word_id || rawEvent.wordId);
        const categoryId = toInt(rawEvent.category_id || rawEvent.categoryId || categoryNameToId(rawEvent.category_name || rawEvent.categoryName));
        const wordsetId = resolveWordsetId(rawEvent.wordset_id || rawEvent.wordsetId);
        const categoryName = sanitizeString(rawEvent.category_name || rawEvent.categoryName || '');

        const event = {
            event_uuid: sanitizeString(rawEvent.event_uuid || rawEvent.uuid || buildUuid()).slice(0, 64),
            event_type: eventType,
            mode: mode,
            word_id: wordId,
            category_id: categoryId,
            category_name: categoryName,
            wordset_id: wordsetId,
            payload: (rawEvent.payload && typeof rawEvent.payload === 'object') ? rawEvent.payload : {}
        };

        if (eventType === 'word_outcome') {
            if (typeof rawEvent.is_correct !== 'undefined') {
                event.is_correct = parseBool(rawEvent.is_correct, false);
            } else if (typeof rawEvent.isCorrect !== 'undefined') {
                event.is_correct = parseBool(rawEvent.isCorrect, false);
            } else {
                return null;
            }
            event.had_wrong_before = parseBool(rawEvent.had_wrong_before ?? rawEvent.hadWrongBefore, false);
        }

        if (eventType === 'word_outcome' || eventType === 'word_exposure') {
            if (!event.word_id) {
                return null;
            }
        }

        if (eventType === 'category_study' && !event.category_id && !event.category_name) {
            return null;
        }

        return event;
    }

    function enqueue(eventLike) {
        const normalized = normalizeEvent(eventLike || {});
        if (!normalized) {
            return null;
        }

        queue.push(normalized);
        if (queue.length > MAX_QUEUE) {
            queue = queue.slice(queue.length - MAX_QUEUE);
        }
        return normalized.event_uuid;
    }

    function scheduleFlush(delay) {
        const ms = Math.max(30, parseInt(delay, 10) || 1200);
        if (flushTimer) {
            clearTimeout(flushTimer);
        }
        flushTimer = setTimeout(function () {
            flushTimer = null;
            flush();
        }, ms);
    }

    function flush(options) {
        const opts = options || {};
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
        if (!queue.length) {
            return Promise.resolve({ queued: 0 });
        }
        if (inFlight) {
            return Promise.resolve({ queued: queue.length, in_flight: true });
        }

        const ajaxUrl = getAjaxUrl();
        const nonce = getNonce();
        if (!ajaxUrl || !nonce || !isUserLoggedIn() || !$ || typeof $.post !== 'function') {
            queue = [];
            return Promise.resolve({ queued: 0, skipped: true });
        }

        inFlight = true;
        const batch = queue.slice(0, MAX_BATCH_SIZE);
        queue = queue.slice(batch.length);

        const payload = {
            action: 'll_user_study_progress_batch',
            nonce: nonce,
            events: JSON.stringify(batch),
            wordset_id: resolveWordsetId(opts.wordsetId || opts.wordset_id),
            category_ids: resolveCategoryIds(opts.categoryIds || opts.category_ids)
        };

        const request = $.post(ajaxUrl, payload);
        return request.done(function (res) {
            if (res && res.success && res.data) {
                try {
                    $(document).trigger('lltools:progress-updated', [res.data]);
                } catch (_) { /* no-op */ }
            }
        }).fail(function () {
            // Put events back so transient network errors do not lose progress.
            if (batch.length) {
                queue = batch.concat(queue);
                if (queue.length > MAX_QUEUE) {
                    queue = queue.slice(queue.length - MAX_QUEUE);
                }
            }
        }).always(function () {
            inFlight = false;
            if (queue.length) {
                scheduleFlush(50);
            }
        }).then(function () {
            return { queued: queue.length };
        }, function () {
            return { queued: queue.length, failed: true };
        });
    }

    function setContext(nextContext) {
        const next = (nextContext && typeof nextContext === 'object') ? nextContext : {};
        if (typeof next.mode !== 'undefined') {
            context.mode = normalizeMode(next.mode);
        }
        if (typeof next.wordsetId !== 'undefined' || typeof next.wordset_id !== 'undefined') {
            context.wordsetId = resolveWordsetId(next.wordsetId || next.wordset_id);
        }
        if (typeof next.categoryIds !== 'undefined' || typeof next.category_ids !== 'undefined') {
            context.categoryIds = resolveCategoryIds(next.categoryIds || next.category_ids);
        }
        return {
            mode: context.mode,
            wordset_id: context.wordsetId,
            category_ids: context.categoryIds.slice()
        };
    }

    function trackWordExposure(raw) {
        const entry = raw || {};
        const word = (entry.word && typeof entry.word === 'object') ? entry.word : null;
        const wordId = toInt(entry.wordId || entry.word_id || (word && word.id));
        if (!wordId) {
            return null;
        }
        const categoryId = toInt(entry.categoryId || entry.category_id || (word && categoryIdForWord(word)));
        const categoryName = sanitizeString(entry.categoryName || entry.category_name || (word && word.__categoryName) || '');

        const eventId = enqueue({
            event_type: 'word_exposure',
            mode: entry.mode || context.mode,
            word_id: wordId,
            category_id: categoryId,
            category_name: categoryName,
            wordset_id: entry.wordsetId || entry.wordset_id,
            payload: entry.payload || {}
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 1400);
        }
        return eventId;
    }

    function trackWordOutcome(raw) {
        const entry = raw || {};
        const word = (entry.word && typeof entry.word === 'object') ? entry.word : null;
        const wordId = toInt(entry.wordId || entry.word_id || (word && word.id));
        if (!wordId) {
            return null;
        }
        const categoryId = toInt(entry.categoryId || entry.category_id || (word && categoryIdForWord(word)));
        const categoryName = sanitizeString(entry.categoryName || entry.category_name || (word && word.__categoryName) || '');
        const hasCorrect = (typeof entry.isCorrect !== 'undefined' || typeof entry.is_correct !== 'undefined');
        if (!hasCorrect) {
            return null;
        }
        const isCorrect = parseBool((typeof entry.isCorrect !== 'undefined') ? entry.isCorrect : entry.is_correct, false);
        const hadWrongBefore = parseBool(entry.hadWrongBefore ?? entry.had_wrong_before, false);

        const eventId = enqueue({
            event_type: 'word_outcome',
            mode: entry.mode || context.mode,
            word_id: wordId,
            category_id: categoryId,
            category_name: categoryName,
            wordset_id: entry.wordsetId || entry.wordset_id,
            is_correct: isCorrect,
            had_wrong_before: hadWrongBefore,
            payload: entry.payload || {}
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 900);
            try {
                if ($ && typeof $.fn !== 'undefined') {
                    $(document).trigger('lltools:flashcard-word-outcome-queued', [{
                        event_id: eventId,
                        mode: normalizeMode(entry.mode || context.mode),
                        word_id: wordId,
                        category_id: categoryId,
                        category_name: categoryName,
                        wordset_id: resolveWordsetId(entry.wordsetId || entry.wordset_id),
                        is_correct: isCorrect,
                        had_wrong_before: hadWrongBefore
                    }]);
                }
            } catch (_) { /* no-op */ }
        }
        return eventId;
    }

    function trackCategoryStudy(raw) {
        const entry = raw || {};
        const categoryId = toInt(entry.categoryId || entry.category_id || categoryNameToId(entry.categoryName || entry.category_name));
        const categoryName = sanitizeString(entry.categoryName || entry.category_name || '');
        if (!categoryId && !categoryName) {
            return null;
        }
        const payload = (entry.payload && typeof entry.payload === 'object') ? entry.payload : {};
        if (typeof payload.units === 'undefined' && typeof entry.units !== 'undefined') {
            payload.units = Math.max(1, parseInt(entry.units, 10) || 1);
        }

        const eventId = enqueue({
            event_type: 'category_study',
            mode: entry.mode || context.mode || 'listening',
            category_id: categoryId,
            category_name: categoryName,
            wordset_id: entry.wordsetId || entry.wordset_id,
            payload: payload
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 1400);
        }
        return eventId;
    }

    function trackModeSessionComplete(raw) {
        const entry = raw || {};
        const payload = (entry.payload && typeof entry.payload === 'object') ? entry.payload : {};
        const categories = resolveCategoryIds(entry.categoryIds || entry.category_ids);
        if (categories.length) {
            payload.category_ids = categories.slice();
        }

        const eventId = enqueue({
            event_type: 'mode_session_complete',
            mode: entry.mode || context.mode,
            category_id: toInt(entry.categoryId || entry.category_id),
            category_name: sanitizeString(entry.categoryName || entry.category_name || ''),
            wordset_id: entry.wordsetId || entry.wordset_id,
            payload: payload
        });
        if (eventId) {
            scheduleFlush(entry.flushDelay || 800);
        }
        return eventId;
    }

    function clearQueue() {
        queue = [];
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
    }

    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                flush();
            }
        });
    }

    root.LLFlashcards.ProgressTracker = {
        setContext: setContext,
        flush: flush,
        flushSoon: scheduleFlush,
        clearQueue: clearQueue,
        getQueueSize: function () { return queue.length; },
        normalizeMode: normalizeMode,
        categoryNameToId: categoryNameToId,
        categoryIdForWord: categoryIdForWord,
        trackWordExposure: trackWordExposure,
        trackWordOutcome: trackWordOutcome,
        trackCategoryStudy: trackCategoryStudy,
        trackModeSessionComplete: trackModeSessionComplete
    };
})(window, window.jQuery);
