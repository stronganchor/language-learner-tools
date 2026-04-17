(function (root, $) {
    'use strict';

    const api = root.LLWordsetGames = root.LLWordsetGames || {};
    const DEFAULT_GAME_SLUG = 'space-shooter';
    const BUBBLE_POP_GAME_SLUG = 'bubble-pop';
    const UNSCRAMBLE_GAME_SLUG = 'unscramble';
    const LINEUP_GAME_SLUG = 'line-up';
    const SPEAKING_PRACTICE_GAME_SLUG = 'speaking-practice';
    const SPEAKING_STACK_GAME_SLUG = 'speaking-stack';
    const GAME_LENGTH_ALL = 'all';
    const MODULE_NS = '.llWordsetGames';
    const UNSCRAMBLE_DRAG_NS = '.llWordsetGamesUnscrambleDrag';
    const GAME_PROMPT_RECORDING_TYPES = ['question', 'isolation', 'introduction'];
    const CARD_RATIO_MIN = 0.55;
    const CARD_RATIO_MAX = 2.5;
    const CARD_RATIO_DEFAULT = 1;
    const ASSET_PRELOAD_TIMEOUT_MS = 8000;
    const SHORT_PROMPT_AUTO_REPLAY_MIN_MS = 1400;
    const POST_SAFE_LINE_REPLAY_BUFFER_MS = 80;
    const INITIAL_LOADING_OVERLAY_MIN_MS = 600;
    const INACTIVITY_ROUND_PAUSE_LIMIT = 3;
    const RESUME_ACTION_NEXT_PROMPT = 'next-prompt';
    const PAUSE_REASON_INACTIVITY = 'inactivity';
    const BUBBLE_ACTIVE_MAX_SPEED = 84;
    const BUBBLE_RELEASE_MAX_SPEED = 116;
    const BUBBLE_CORRECT_RELEASE_MAX_SPEED = 1040;
    const BUBBLE_DECORATIVE_MAX_SPEED = 52;
    const MATCHER_COMPETITION_DISTRACTOR_LIMIT = 10;
    const MATCHER_VOWEL_CHAR_PATTERN = /[aeiouyıöüâêîôûáàäãåæéèëíìïóòôöõøœúùüəɛɜɞɐɑɒʌɨɯʉʊɪɔɤ]/i;
    const matcherWindowCache = {};
    const gameInteractionGuard = {
        active: false,
        historyActive: false,
        historyToken: 0,
        suppressNextPopstate: false,
        suppressResetTimer: null,
        pinchDistance: 0
    };

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function clamp(value, min, max) {
        const num = Number(value) || 0;
        return Math.max(min, Math.min(max, num));
    }

    function randomBetween(min, max) {
        const start = Number(min) || 0;
        const end = Number(max);
        if (!isFinite(end) || end <= start) {
            return start;
        }
        return start + (Math.random() * (end - start));
    }

    function lerp(start, end, progress) {
        return Number(start || 0) + ((Number(end || 0) - Number(start || 0)) * clamp(progress, 0, 1));
    }

    function clampVectorMagnitude(x, y, maxMagnitude) {
        const velocityX = Number(x) || 0;
        const velocityY = Number(y) || 0;
        const limit = Math.max(0, Number(maxMagnitude) || 0);
        const magnitude = Math.sqrt((velocityX * velocityX) + (velocityY * velocityY));

        if (!limit || !isFinite(magnitude) || magnitude <= limit || magnitude < 0.001) {
            return {
                x: velocityX,
                y: velocityY
            };
        }

        const scale = limit / magnitude;
        return {
            x: velocityX * scale,
            y: velocityY * scale
        };
    }

    function uniqueIntList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : [])
            .map(toInt)
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
    }

    function normalizeRecordingType(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9-]/g, '');
    }

    function normalizeGameSlug(value) {
        const slug = normalizeRecordingType(value);
        return slug || DEFAULT_GAME_SLUG;
    }

    function normalizeRoundOptionValue(value) {
        const rawValue = String(value || '').trim().toLowerCase();
        if (rawValue === GAME_LENGTH_ALL) {
            return GAME_LENGTH_ALL;
        }

        const count = toInt(value);
        return count > 0 ? String(count) : '';
    }

    function normalizeRoundOptionList(values, fallbackValue) {
        const seen = {};
        const options = (Array.isArray(values) ? values : [])
            .map(normalizeRoundOptionValue)
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
        const normalizedFallback = normalizeRoundOptionValue(fallbackValue);

        if (normalizedFallback && options.indexOf(normalizedFallback) === -1) {
            options.push(normalizedFallback);
        }

        if (!options.length) {
            return ['20', '50', '100', GAME_LENGTH_ALL];
        }

        return options;
    }

    function isEditableEventTarget(target) {
        if (!target || typeof target.closest !== 'function') {
            return false;
        }

        if (target.isContentEditable) {
            return true;
        }

        const contentEditable = target.closest('[contenteditable=""], [contenteditable="true"], [contenteditable="plaintext-only"], textarea');
        if (contentEditable) {
            return true;
        }

        const input = target.closest('input');
        if (!input) {
            return false;
        }

        if (input.disabled || input.readOnly) {
            return false;
        }

        const type = String(input.type || 'text').toLowerCase();
        return ['button', 'submit', 'reset', 'checkbox', 'radio', 'range', 'color', 'file', 'image', 'hidden'].indexOf(type) === -1;
    }

    function uniqueStringList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : [])
            .map(function (value) {
                return normalizeRecordingType(value);
            })
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
    }

    function normalizeUrlList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : [values])
            .map(function (value) {
                return String(value || '').trim();
            })
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
    }

    function formatMessage(template, values) {
        const source = String(template || '');
        const args = Array.isArray(values) ? values.slice() : [];
        let sequential = 0;
        return source
            .replace(/%(\d+)\$[sd]/g, function (_match, rawIndex) {
                const index = Math.max(0, parseInt(rawIndex, 10) - 1);
                return (typeof args[index] !== 'undefined') ? String(args[index]) : '';
            })
            .replace(/%[sd]/g, function () {
                const value = (typeof args[sequential] !== 'undefined') ? args[sequential] : '';
                sequential += 1;
                return String(value);
            });
    }

    function clampAspectRatio(value) {
        const num = Number(value);
        if (!num || !isFinite(num) || num <= 0) {
            return null;
        }
        return Math.min(CARD_RATIO_MAX, Math.max(CARD_RATIO_MIN, num));
    }

    function easeOutCubic(progress) {
        const clamped = clamp(progress, 0, 1);
        return 1 - Math.pow(1 - clamped, 3);
    }

    function shuffle(list) {
        const copy = Array.isArray(list) ? list.slice() : [];
        for (let index = copy.length - 1; index > 0; index -= 1) {
            const swapIndex = Math.floor(Math.random() * (index + 1));
            const temp = copy[index];
            copy[index] = copy[swapIndex];
            copy[swapIndex] = temp;
        }
        return copy;
    }

    function getOrderedIdSignature(list) {
        return (Array.isArray(list) ? list : []).map(function (entry) {
            return toInt(entry && entry.id);
        }).join(',');
    }

    function shuffleAvoidingSignatures(list, forbiddenSignatures, maxAttempts) {
        const source = Array.isArray(list) ? list.slice() : [];
        if (source.length < 2) {
            return source;
        }

        const blocked = {};
        const signatures = Array.isArray(forbiddenSignatures) ? forbiddenSignatures : [forbiddenSignatures];
        signatures.forEach(function (signature) {
            const normalized = String(signature || '');
            if (normalized !== '') {
                blocked[normalized] = true;
            }
        });

        let candidate = source.slice();
        let attempts = Math.max(1, toInt(maxAttempts) || 12);

        while (attempts > 0) {
            candidate = shuffle(source);
            if (!blocked[getOrderedIdSignature(candidate)]) {
                return candidate;
            }
            attempts -= 1;
        }

        return candidate;
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getRecordIconSvgMarkup(className) {
        const svgClass = String(className || '').trim();
        const classAttr = svgClass ? ' class="' + escapeHtml(svgClass) + '"' : '';
        return '' +
            '<svg' + classAttr + ' viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true" focusable="false">' +
                '<circle cx="12" cy="12" r="8"></circle>' +
            '</svg>';
    }

    function getSpeakingStackListeningStatusMarkup(label) {
        const accessibleLabel = String(label || '').trim();
        return '' +
            '<span class="ll-wordset-speaking-stack-stage__status-icon" aria-hidden="true">' +
                getRecordIconSvgMarkup('ll-wordset-speaking-stack-stage__status-icon-svg') +
            '</span>' +
            (accessibleLabel
                ? ('<span class="screen-reader-text">' + escapeHtml(accessibleLabel) + '</span>')
                : '');
    }

    function getCatalogCardIconMarkup(slug) {
        const normalizedSlug = normalizeGameSlug(slug);
        if (normalizedSlug === BUBBLE_POP_GAME_SLUG) {
            return '' +
                '<svg class="ll-wordset-game-card__icon-svg" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">' +
                    '<circle cx="10.8" cy="12" r="5.8" fill="currentColor" fill-opacity="0.18" stroke="currentColor" stroke-width="1.5"></circle>' +
                    '<circle cx="8.8" cy="9.9" r="1.4" fill="currentColor" fill-opacity="0.72"></circle>' +
                    '<circle cx="17.2" cy="7" r="2.6" fill="currentColor" fill-opacity="0.16" stroke="currentColor" stroke-width="1.3"></circle>' +
                    '<circle cx="16.1" cy="6.1" r="0.8" fill="currentColor" fill-opacity="0.64"></circle>' +
                    '<circle cx="6.5" cy="6.4" r="1.8" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.1"></circle>' +
                '</svg>';
        }
        if (normalizedSlug === LINEUP_GAME_SLUG) {
            return '' +
                '<svg class="ll-wordset-game-card__icon-svg" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">' +
                    '<rect x="3.5" y="6.1" width="4.1" height="11.8" rx="1.2" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.4"></rect>' +
                    '<rect x="9.95" y="6.1" width="4.1" height="11.8" rx="1.2" fill="currentColor" fill-opacity="0.22" stroke="currentColor" stroke-width="1.4"></rect>' +
                    '<rect x="16.4" y="6.1" width="4.1" height="11.8" rx="1.2" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.4"></rect>' +
                    '<path d="M5.55 4.2H18.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"></path>' +
                    '<path d="M17.15 3L18.85 4.2L17.15 5.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"></path>' +
                '</svg>';
        }
        if (normalizedSlug === UNSCRAMBLE_GAME_SLUG) {
            return '' +
                '<svg class="ll-wordset-game-card__icon-svg" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">' +
                    '<rect x="3.6" y="5.1" width="5.6" height="5.6" rx="1.4" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.4"></rect>' +
                    '<rect x="10.4" y="12.7" width="5.6" height="5.6" rx="1.4" fill="currentColor" fill-opacity="0.22" stroke="currentColor" stroke-width="1.4"></rect>' +
                    '<rect x="17.1" y="5.1" width="3.3" height="3.3" rx="1.05" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.2"></rect>' +
                    '<path d="M9.7 7.9H14.2" stroke="currentColor" stroke-width="1.35" stroke-linecap="round"></path>' +
                    '<path d="M13 6.7L14.45 7.9L13 9.1" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"></path>' +
                '</svg>';
        }
        if (normalizedSlug === SPEAKING_PRACTICE_GAME_SLUG) {
            return '' +
                '<svg class="ll-wordset-game-card__icon-svg" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">' +
                    '<path d="M12 3.5c-1.93 0-3.5 1.57-3.5 3.5v4.15c0 1.93 1.57 3.5 3.5 3.5s3.5-1.57 3.5-3.5V7c0-1.93-1.57-3.5-3.5-3.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"></path>' +
                    '<path d="M8.4 11.3c0 1.96 1.64 3.55 3.6 3.55s3.6-1.59 3.6-3.55" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>' +
                    '<path d="M12 15.1v3.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>' +
                    '<path d="M9.1 18.5h5.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>' +
                '</svg>';
        }
        if (normalizedSlug === SPEAKING_STACK_GAME_SLUG) {
            return '' +
                '<svg class="ll-wordset-game-card__icon-svg" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">' +
                    '<rect x="4.1" y="4.4" width="6.4" height="6" rx="1.35" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.5"></rect>' +
                    '<rect x="13.5" y="4.4" width="6.4" height="6" rx="1.35" fill="currentColor" fill-opacity="0.2" stroke="currentColor" stroke-width="1.5"></rect>' +
                    '<rect x="4.1" y="13.6" width="6.4" height="6" rx="1.35" fill="currentColor" fill-opacity="0.2" stroke="currentColor" stroke-width="1.5"></rect>' +
                    '<rect x="13.5" y="13.6" width="6.4" height="6" rx="1.35" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.5"></rect>' +
                '</svg>';
        }

        return '' +
            '<svg class="ll-wordset-game-card__icon-svg" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">' +
                '<path d="M12 3.2L17.95 17.9L12 14.65L6.05 17.9L12 3.2Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"></path>' +
                '<path d="M12 6.55L14.15 11.9H9.85L12 6.55Z" fill="currentColor"></path>' +
                '<path d="M9.65 15.1C9.86 16.44 10.74 17.45 12 17.45C13.26 17.45 14.14 16.44 14.35 15.1H9.65Z" fill="currentColor"></path>' +
                '<path d="M9.25 14.1H14.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>' +
                '<path d="M8.15 18.05L6.35 20.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>' +
                '<path d="M15.85 18.05L17.65 20.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>' +
            '</svg>';
    }

    function buildCatalogCardMarkup(slug, entry, cfg) {
        const normalizedSlug = normalizeGameSlug(slug);
        const data = (entry && typeof entry === 'object') ? entry : {};
        const options = (cfg && typeof cfg === 'object') ? cfg : {};
        const hiddenAttr = (normalizedSlug === SPEAKING_PRACTICE_GAME_SLUG || normalizedSlug === SPEAKING_STACK_GAME_SLUG) ? ' hidden' : '';
        const title = escapeHtml(String(data.title || ''));
        const description = escapeHtml(String(data.description || ''));
        const statusText = escapeHtml(String(options.statusText || ''));
        const buttonText = escapeHtml(String(options.buttonText || ''));
        const countLabel = escapeHtml(String(options.countLabel || 'Eligible words'));

        return '' +
            '<article class="ll-wordset-game-card" data-ll-wordset-game-card data-game-slug="' + escapeHtml(normalizedSlug) + '"' + hiddenAttr + '>' +
                '<div class="ll-wordset-game-card__icon" aria-hidden="true">' +
                    getCatalogCardIconMarkup(normalizedSlug) +
                '</div>' +
                '<div class="ll-wordset-game-card__body">' +
                    '<h2 class="ll-wordset-game-card__title">' + title + '</h2>' +
                    '<p class="ll-wordset-game-card__description">' + description + '</p>' +
                    '<p class="ll-wordset-game-card__status" data-ll-wordset-game-status>' + statusText + '</p>' +
                '</div>' +
                '<div class="ll-wordset-game-card__actions">' +
                    '<span class="ll-wordset-game-card__count" data-ll-wordset-game-count aria-label="' + countLabel + '">&#8212;</span>' +
                    '<button type="button" class="ll-wordset-game-card__launch" data-ll-wordset-game-launch disabled>' + buttonText + '</button>' +
                '</div>' +
            '</article>';
    }

    function ensureCatalogCardsExist($gamesRoot, gamesCfg, options) {
        const $catalog = $gamesRoot.find('[data-ll-wordset-games-catalog]').first();
        if (!$catalog.length) {
            return;
        }

        const catalog = (gamesCfg && gamesCfg.catalog && typeof gamesCfg.catalog === 'object')
            ? gamesCfg.catalog
            : {};
        const existing = {};
        $catalog.find('[data-ll-wordset-game-card]').each(function () {
            const slug = normalizeGameSlug($(this).attr('data-game-slug') || '');
            if (slug) {
                existing[slug] = true;
            }
        });

        Object.keys(catalog).forEach(function (slug) {
            const normalizedSlug = normalizeGameSlug(slug);
            if (!normalizedSlug || existing[normalizedSlug]) {
                return;
            }

            $catalog.append(buildCatalogCardMarkup(normalizedSlug, catalog[slug], options));
            existing[normalizedSlug] = true;
        });
    }

    function catalogEntryHasStaticData(entry) {
        if (!entry || typeof entry !== 'object') {
            return false;
        }

        return Array.isArray(entry.words)
            || Array.isArray(entry.sequences)
            || Array.isArray(entry.playableTargets)
            || Object.prototype.hasOwnProperty.call(entry, 'available_word_count')
            || Object.prototype.hasOwnProperty.call(entry, 'launchable')
            || Object.prototype.hasOwnProperty.call(entry, 'provider')
            || Object.prototype.hasOwnProperty.call(entry, 'offline_stt');
    }

    function resolveStaticCatalog(gamesCfg, offlineMode) {
        const catalog = (gamesCfg && gamesCfg.catalog && typeof gamesCfg.catalog === 'object')
            ? gamesCfg.catalog
            : null;
        if (!catalog) {
            return null;
        }
        if (offlineMode) {
            return catalog;
        }

        const slugs = Object.keys(catalog);
        if (!slugs.length) {
            return null;
        }

        const hasStaticData = slugs.some(function (slug) {
            return catalogEntryHasStaticData(catalog[slug]);
        });

        return hasStaticData ? catalog : null;
    }

    function segmentDiffGraphemes(text) {
        const source = String(text || '');
        if (source === '') {
            return [];
        }
        if (root.Intl && typeof root.Intl.Segmenter === 'function') {
            const segmenter = new root.Intl.Segmenter(undefined, { granularity: 'grapheme' });
            return Array.from(segmenter.segment(source), function (part) {
                return String(part && part.segment || '');
            });
        }
        return Array.from(source);
    }

    function buildSpeakingDiffMarkup(sourceText, targetText, variant) {
        const sourceSegments = segmentDiffGraphemes(sourceText);
        const targetSegments = segmentDiffGraphemes(targetText);
        if (!sourceSegments.length) {
            return '';
        }

        const rows = sourceSegments.length + 1;
        const cols = targetSegments.length + 1;
        const matrix = new Array(rows);
        for (let rowIndex = 0; rowIndex < rows; rowIndex += 1) {
            matrix[rowIndex] = new Array(cols).fill(0);
        }

        for (let sourceIndex = sourceSegments.length - 1; sourceIndex >= 0; sourceIndex -= 1) {
            for (let targetIndex = targetSegments.length - 1; targetIndex >= 0; targetIndex -= 1) {
                matrix[sourceIndex][targetIndex] = sourceSegments[sourceIndex] === targetSegments[targetIndex]
                    ? matrix[sourceIndex + 1][targetIndex + 1] + 1
                    : Math.max(matrix[sourceIndex + 1][targetIndex], matrix[sourceIndex][targetIndex + 1]);
            }
        }

        const changed = new Array(sourceSegments.length).fill(false);
        let sourceCursor = 0;
        let targetCursor = 0;
        while (sourceCursor < sourceSegments.length && targetCursor < targetSegments.length) {
            if (sourceSegments[sourceCursor] === targetSegments[targetCursor]) {
                sourceCursor += 1;
                targetCursor += 1;
                continue;
            }

            if (matrix[sourceCursor + 1][targetCursor] >= matrix[sourceCursor][targetCursor + 1]) {
                changed[sourceCursor] = true;
                sourceCursor += 1;
            } else {
                targetCursor += 1;
            }
        }
        while (sourceCursor < sourceSegments.length) {
            changed[sourceCursor] = true;
            sourceCursor += 1;
        }

        let html = '';
        let buffer = '';
        let isDifferent = false;
        function flush() {
            if (buffer === '') {
                return;
            }
            const escaped = escapeHtml(buffer);
            html += isDifferent
                ? '<span class="ll-wordset-speaking-stage__diff-fragment ll-wordset-speaking-stage__diff-fragment--' + escapeHtml(variant) + '">' + escaped + '</span>'
                : escaped;
            buffer = '';
        }

        sourceSegments.forEach(function (segment, index) {
            const nextDifferent = !!changed[index];
            if (buffer !== '' && nextDifferent !== isDifferent) {
                flush();
            }
            isDifferent = nextDifferent;
            buffer += segment;
        });
        flush();

        return html;
    }

    function renderSpeakingComparedText(sourceText, targetText, score, variant) {
        const displayText = String(sourceText || '');
        if (displayText === '') {
            return '';
        }
        if (Math.round(clamp(Number(score) || 0, 0, 100)) >= 100 || String(targetText || '') === '') {
            return escapeHtml(displayText);
        }
        return buildSpeakingDiffMarkup(displayText, targetText, variant);
    }

    function buildSpeakingPromptDeck(words, recentWordIds) {
        const shuffled = shuffle(words);
        const recentIds = Array.isArray(recentWordIds) ? recentWordIds.map(toInt).filter(Boolean) : [];
        if (shuffled.length <= 1 || !recentIds.length) {
            return shuffled;
        }

        const recentLookup = {};
        recentIds.forEach(function (wordId) {
            if (wordId > 0) {
                recentLookup[wordId] = true;
            }
        });

        if (!recentLookup[toInt(shuffled[0] && shuffled[0].id)]) {
            return shuffled;
        }

        const replacementIndex = shuffled.findIndex(function (word) {
            return !recentLookup[toInt(word && word.id)];
        });
        if (replacementIndex <= 0) {
            return shuffled;
        }

        const replacement = shuffled.splice(replacementIndex, 1)[0];
        shuffled.unshift(replacement);
        return shuffled;
    }

    function rememberRecentSpeakingLaunch(state, wordId) {
        if (!state) {
            return;
        }

        const normalizedId = toInt(wordId);
        if (!normalizedId) {
            return;
        }

        const prior = Array.isArray(state.recentLaunchWordIds) ? state.recentLaunchWordIds : [];
        const nextRecent = prior.filter(function (entryId) {
            return toInt(entryId) !== normalizedId;
        });
        nextRecent.unshift(normalizedId);
        state.recentLaunchWordIds = nextRecent.slice(0, 4);
    }

    function getWordCategoryKey(word) {
        const categoryId = toInt(word && word.category_id)
            || toInt(Array.isArray(word && word.category_ids) ? word.category_ids[0] : 0);
        return categoryId > 0 ? String(categoryId) : 'default';
    }

    function getWordCategoryIds(word) {
        const categoryIds = uniqueIntList(word && word.category_ids);
        const primaryCategoryId = toInt(word && word.category_id);
        if (primaryCategoryId > 0 && categoryIds.indexOf(primaryCategoryId) === -1) {
            categoryIds.unshift(primaryCategoryId);
        }
        return categoryIds;
    }

    function limitLaunchWords(words, wordCap) {
        const list = Array.isArray(words) ? words.slice() : [];
        const cap = Math.max(1, toInt(wordCap) || list.length || 1);
        if (list.length <= cap) {
            return list;
        }

        const groups = {};
        const groupOrder = [];
        list.forEach(function (word) {
            const key = getWordCategoryKey(word);
            if (!groups[key]) {
                groups[key] = [];
                groupOrder.push(key);
            }
            groups[key].push(word);
        });

        const selected = [];
        const selectedIds = {};
        while (selected.length < cap) {
            let added = false;
            groupOrder.forEach(function (key) {
                if (selected.length >= cap || !groups[key] || !groups[key].length) {
                    return;
                }

                const word = groups[key].shift();
                const wordId = toInt(word && word.id);
                if (wordId && selectedIds[wordId]) {
                    return;
                }

                selected.push(word);
                if (wordId) {
                    selectedIds[wordId] = true;
                }
                added = true;
            });

            if (!added) {
                break;
            }
        }

        if (selected.length >= cap) {
            return selected.slice(0, cap);
        }

        list.forEach(function (word) {
            if (selected.length >= cap) {
                return;
            }
            const wordId = toInt(word && word.id);
            if (wordId && selectedIds[wordId]) {
                return;
            }
            selected.push(word);
            if (wordId) {
                selectedIds[wordId] = true;
            }
        });

        return selected.slice(0, cap);
    }

    function getOptionConflicts() {
        return (root.LLToolsOptionConflicts && typeof root.LLToolsOptionConflicts === 'object')
            ? root.LLToolsOptionConflicts
            : {};
    }

    function wordsShareSimilarLink(leftWord, rightWord) {
        const leftId = toInt(leftWord && leftWord.id);
        const rightId = toInt(rightWord && rightWord.id);
        if (!leftId || !rightId || leftId === rightId) {
            return false;
        }
        return String(leftWord && leftWord.similar_word_id) === String(rightId)
            || String(rightWord && rightWord.similar_word_id) === String(leftId);
    }

    function wordsCanShareRound(leftWord, rightWord) {
        const helper = getOptionConflicts();
        const hasConflict = (typeof helper.wordsConflictForOptions === 'function')
            ? helper.wordsConflictForOptions(leftWord, rightWord)
            : false;
        if (hasConflict) {
            return false;
        }
        return !wordsShareSimilarLink(leftWord, rightWord);
    }

    function wordsShareCategory(leftWord, rightWord) {
        const leftCategories = getWordCategoryIds(leftWord);
        const rightCategories = getWordCategoryIds(rightWord);
        if (!leftCategories.length || !rightCategories.length) {
            return false;
        }

        return leftCategories.some(function (categoryId) {
            return rightCategories.indexOf(categoryId) !== -1;
        });
    }

    function normalizeAudioFiles(word) {
        return (Array.isArray(word && word.audio_files) ? word.audio_files : [])
            .filter(function (file) {
                return file && typeof file === 'object' && String(file.url || '').trim() !== '';
            })
            .map(function (file) {
                return {
                    url: String(file.url || ''),
                    recording_type: normalizeRecordingType(file.recording_type || ''),
                    speaker_user_id: toInt(file.speaker_user_id),
                    recording_text: String(file.recording_text || '')
                };
            });
    }

    function normalizeBlockedIdsByRecordingType(rawMap) {
        const map = (rawMap && typeof rawMap === 'object') ? rawMap : {};
        const out = {};

        Object.keys(map).forEach(function (recordingType) {
            const normalizedType = normalizeRecordingType(recordingType || '');
            if (!normalizedType) {
                return;
            }

            const blockedIds = uniqueIntList(map[recordingType] || []);
            if (blockedIds.length) {
                out[normalizedType] = blockedIds;
            }
        });

        return out;
    }

    function normalizeUnscrambleUnits(rawUnits) {
        return (Array.isArray(rawUnits) ? rawUnits : [])
            .map(function (unit, index) {
                const row = (unit && typeof unit === 'object') ? unit : {};
                return {
                    id: toInt(row.id) || (index + 1),
                    text: String(row.text || ''),
                    movable: !!row.movable,
                    target_position: Math.max(0, toInt(row.target_position) || index)
                };
            })
            .filter(function (unit) {
                return unit.text !== '';
            });
    }

    function normalizeWord(rawWord) {
        const word = (rawWord && typeof rawWord === 'object') ? rawWord : {};
        const categoryIds = uniqueIntList(word.category_ids || []);
        const categoryId = toInt(word.category_id) || (categoryIds.length ? categoryIds[0] : 0);
        return {
            id: toInt(word.id),
            title: String(word.title || ''),
            label: String(word.label || word.title || ''),
            prompt_label: String(word.prompt_label || word.title || ''),
            translation: String(word.translation || ''),
            image: String(word.image || ''),
            audio: String(word.audio || ''),
            audio_files: normalizeAudioFiles(word),
            practice_recording_types: Array.isArray(word.practice_recording_types)
                ? uniqueStringList(word.practice_recording_types)
                : [],
            preferred_speaker_user_id: toInt(word.preferred_speaker_user_id),
            option_blocked_ids: uniqueIntList(word.option_blocked_ids || []),
            option_blocked_ids_by_recording_type: normalizeBlockedIdsByRecordingType(word.option_blocked_ids_by_recording_type),
            category_id: categoryId,
            category_ids: categoryIds,
            category_name: String(word.category_name || ''),
            category_names: Array.isArray(word.category_names)
                ? word.category_names.map(function (entry) { return String(entry || ''); }).filter(Boolean)
                : [],
            similar_word_id: String(word.similar_word_id || ''),
            recording_text: String(word.recording_text || ''),
            recording_ipa: String(word.recording_ipa || ''),
            practice_correct_recording_types: Array.isArray(word.practice_correct_recording_types)
                ? uniqueStringList(word.practice_correct_recording_types)
                : [],
            practice_exposure_count: toInt(word.practice_exposure_count),
            game_prompt_recording_types: Array.isArray(word.game_prompt_recording_types)
                ? uniqueStringList(word.game_prompt_recording_types)
                : [],
            lineup_position: Math.max(0, toInt(word.lineup_position)),
            speaking_target_field: String(word.speaking_target_field || ''),
            speaking_target_label: String(word.speaking_target_label || ''),
            speaking_target_text: String(word.speaking_target_text || ''),
            speaking_prompt_text: String(word.speaking_prompt_text || ''),
            speaking_prompt_type: String(word.speaking_prompt_type || ''),
            speaking_display_texts: (word.speaking_display_texts && typeof word.speaking_display_texts === 'object')
                ? {
                    title: String(word.speaking_display_texts.title || ''),
                    ipa: String(word.speaking_display_texts.ipa || ''),
                    target_text: String(word.speaking_display_texts.target_text || ''),
                    target_field: String(word.speaking_display_texts.target_field || ''),
                    target_label: String(word.speaking_display_texts.target_label || '')
                }
                : null,
            speaking_best_correct_audio_url: String(word.speaking_best_correct_audio_url || ''),
            unscramble_answer_text: String(word.unscramble_answer_text || ''),
            unscramble_prompt_type: String(word.unscramble_prompt_type || ''),
            unscramble_prompt_text: String(word.unscramble_prompt_text || ''),
            unscramble_prompt_image: String(word.unscramble_prompt_image || ''),
            unscramble_direction: normalizeLineupDirection(word.unscramble_direction || ''),
            unscramble_movable_unit_count: Math.max(0, toInt(word.unscramble_movable_unit_count)),
            unscramble_units: normalizeUnscrambleUnits(word.unscramble_units)
        };
    }

    function normalizeLineupDirection(value) {
        const direction = String(value || '').trim().toLowerCase();
        return (direction === 'rtl' || direction === 'ltr') ? direction : 'ltr';
    }

    function normalizeLineupSequence(rawSequence) {
        const sequence = (rawSequence && typeof rawSequence === 'object') ? rawSequence : {};
        const words = (Array.isArray(sequence.words) ? sequence.words : [])
            .map(normalizeWord)
            .filter(function (word) {
                return word.id > 0 && String(word.title || word.label || '').trim() !== '';
            });

        return {
            category_id: toInt(sequence.category_id),
            category_name: String(sequence.category_name || ''),
            category_slug: String(sequence.category_slug || ''),
            direction: normalizeLineupDirection(sequence.direction),
            word_count: Math.max(words.length, toInt(sequence.word_count)),
            words: words
        };
    }

    function shuffleUntilDifferent(list, maxAttempts) {
        const source = Array.isArray(list) ? list.slice() : [];
        if (source.length < 2) {
            return source;
        }

        const candidate = shuffleAvoidingSignatures(source, getOrderedIdSignature(source), maxAttempts);
        if (getOrderedIdSignature(candidate) !== getOrderedIdSignature(source)) {
            return candidate;
        }

        const rotated = source.slice(1).concat(source.slice(0, 1));
        return rotated.length === source.length ? rotated : source;
    }

    function getDefaultCatalogSlug(ctx) {
        if (ctx && ctx.defaultCatalogSlug) {
            return normalizeGameSlug(ctx.defaultCatalogSlug);
        }
        return DEFAULT_GAME_SLUG;
    }

    function getRoundPreferenceStorageKey(ctx) {
        const wordsetId = toInt(ctx && ctx.wordsetId);
        return 'll-wordset-games-round-option:' + String(wordsetId || 'default');
    }

    function readStoredRoundOption(ctx) {
        try {
            const storage = root.localStorage;
            if (!storage) {
                return '';
            }
            return normalizeRoundOptionValue(storage.getItem(getRoundPreferenceStorageKey(ctx)));
        } catch (_) {
            return '';
        }
    }

    function writeStoredRoundOption(ctx, value) {
        try {
            const storage = root.localStorage;
            if (!storage) {
                return;
            }
            storage.setItem(getRoundPreferenceStorageKey(ctx), normalizeRoundOptionValue(value));
        } catch (_) {
            /* no-op */
        }
    }

    function getSelectedRoundOption(ctx) {
        const selected = normalizeRoundOptionValue(ctx && ctx.selectedRoundOption);
        if (selected) {
            return selected;
        }

        const defaultOption = normalizeRoundOptionValue(ctx && ctx.defaultRoundOption);
        if (defaultOption) {
            return defaultOption;
        }

        return '50';
    }

    function updateRoundOptionUi(ctx) {
        if (!(ctx && ctx.$roundOptions && ctx.$roundOptions.length)) {
            return;
        }

        const selected = getSelectedRoundOption(ctx);
        ctx.$roundOptions.each(function () {
            const $option = $(this);
            const value = normalizeRoundOptionValue($option.attr('data-word-count') || '');
            const isActive = value === selected;
            $option
                .toggleClass('is-active', isActive)
                .attr('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function setSelectedRoundOption(ctx, value, persist) {
        if (!ctx) {
            return '';
        }

        const normalizedValue = normalizeRoundOptionValue(value);
        const fallback = normalizeRoundOptionValue(ctx.defaultRoundOption) || '50';
        const allowedOptions = Array.isArray(ctx.roundOptions) ? ctx.roundOptions : [];
        ctx.selectedRoundOption = (normalizedValue && allowedOptions.indexOf(normalizedValue) !== -1)
            ? normalizedValue
            : fallback;

        updateRoundOptionUi(ctx);

        if (persist !== false) {
            writeStoredRoundOption(ctx, ctx.selectedRoundOption);
        }

        return ctx.selectedRoundOption;
    }

    function resolveRoundGoalCount(roundOption, maxCount) {
        const normalizedOption = normalizeRoundOptionValue(roundOption);
        const availableCount = Math.max(0, toInt(maxCount));
        if (availableCount <= 0) {
            return 0;
        }
        if (normalizedOption === GAME_LENGTH_ALL) {
            return availableCount;
        }

        const desiredCount = toInt(normalizedOption);
        return desiredCount > 0 ? Math.min(availableCount, desiredCount) : Math.min(availableCount, 50);
    }

    function getEntryRoundGoalCount(ctx, entry) {
        const preparedEntry = (entry && typeof entry === 'object') ? entry : {};
        const gameSlug = normalizeGameSlug(preparedEntry.slug || getCurrentGameSlug(ctx));
        const maxCount = (
            gameSlug === SPEAKING_PRACTICE_GAME_SLUG
            || gameSlug === SPEAKING_STACK_GAME_SLUG
        )
            ? (Array.isArray(preparedEntry.words) ? preparedEntry.words.length : toInt(preparedEntry.available_word_count))
            : (gameSlug === LINEUP_GAME_SLUG)
                ? (Array.isArray(preparedEntry.sequences) ? preparedEntry.sequences.length : toInt(preparedEntry.available_sequence_count || preparedEntry.available_word_count))
            : (Array.isArray(preparedEntry.playableTargets) ? preparedEntry.playableTargets.length : toInt(preparedEntry.available_word_count));

        return resolveRoundGoalCount(getSelectedRoundOption(ctx), maxCount);
    }

    function getRunTotalRounds(run) {
        return Math.max(0, toInt(run && run.totalRounds));
    }

    function runReachedGoal(run) {
        const totalRounds = getRunTotalRounds(run);
        return totalRounds > 0 && toInt(run && run.promptsResolved) >= totalRounds;
    }

    function getGameConfig(ctx, slugOrRun) {
        const requestedSlug = normalizeGameSlug(
            slugOrRun && typeof slugOrRun === 'object'
                ? slugOrRun.slug
                : slugOrRun
        );
        const gameConfigs = (ctx && ctx.gameConfigs && typeof ctx.gameConfigs === 'object')
            ? ctx.gameConfigs
            : {};
        if (gameConfigs[requestedSlug]) {
            return gameConfigs[requestedSlug];
        }

        const fallbackSlug = getDefaultCatalogSlug(ctx);
        if (gameConfigs[fallbackSlug]) {
            return gameConfigs[fallbackSlug];
        }

        return null;
    }

    function isBubblePopRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG;
    }

    function isSpeakingPracticeRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === SPEAKING_PRACTICE_GAME_SLUG;
    }

    function isLineupRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === LINEUP_GAME_SLUG;
    }

    function isUnscrambleRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === UNSCRAMBLE_GAME_SLUG;
    }

    function isSpeakingStackRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === SPEAKING_STACK_GAME_SLUG;
    }

    function isSpaceShooterRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === DEFAULT_GAME_SLUG;
    }

    function getAssetPreloadTimeoutMs(ctx, runOrSlug) {
        const gameConfig = getGameConfig(ctx, runOrSlug);
        return Math.max(1000, toInt(gameConfig && gameConfig.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS);
    }

    function getCurrentGameSlug(ctx) {
        if (ctx && ctx.run) {
            return normalizeGameSlug(ctx.run.slug);
        }
        if (ctx && ctx.activeGameSlug) {
            return normalizeGameSlug(ctx.activeGameSlug);
        }
        return getDefaultCatalogSlug(ctx);
    }

    function getBoardLabel(ctx, slugOrRun) {
        const requestedSlug = normalizeGameSlug(
            slugOrRun && typeof slugOrRun === 'object'
                ? slugOrRun.slug
                : slugOrRun
        );
        if (requestedSlug === BUBBLE_POP_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelBubblePop || 'Bubble Pop game board');
        }
        if (requestedSlug === LINEUP_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelLineup || 'Line-Up sequence board');
        }
        if (requestedSlug === UNSCRAMBLE_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelUnscramble || 'Unscramble game board');
        }
        if (requestedSlug === SPEAKING_PRACTICE_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelSpeakingPractice || 'Speaking practice panel');
        }
        if (requestedSlug === SPEAKING_STACK_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelSpeakingStack || 'Word Stack game board');
        }
        if (requestedSlug === DEFAULT_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelSpaceShooter || 'Space Shooter game board');
        }
        return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelDefault || 'Wordset game board');
    }

    function getGamePromptRecordingTypes(word) {
        const explicit = uniqueStringList(word && word.game_prompt_recording_types);
        const availableAudioTypes = uniqueStringList((Array.isArray(word && word.audio_files) ? word.audio_files : []).map(function (file) {
            return file && file.recording_type;
        }));
        const practiceTypes = uniqueStringList(word && word.practice_recording_types);
        const source = explicit.length ? explicit : practiceTypes.concat(availableAudioTypes);

        return GAME_PROMPT_RECORDING_TYPES.filter(function (type) {
            return source.indexOf(type) !== -1 && availableAudioTypes.indexOf(type) !== -1;
        });
    }

    function selectPromptAudio(word, preferredTypes) {
        const preferred = uniqueStringList(preferredTypes);
        const availableTypes = getGamePromptRecordingTypes(word);
        const orderedTypes = preferred.concat(availableTypes, GAME_PROMPT_RECORDING_TYPES).filter(function (type, index, list) {
            return type && list.indexOf(type) === index;
        });

        if (!availableTypes.length) {
            return {
                url: '',
                recordingType: ''
            };
        }

        if (root.FlashcardAudio && typeof root.FlashcardAudio.selectBestAudio === 'function') {
            for (let index = 0; index < orderedTypes.length; index += 1) {
                const type = orderedTypes[index];
                const selected = root.FlashcardAudio.selectBestAudio(word, [type]);
                if (selected) {
                    return {
                        url: String(selected),
                        recordingType: type
                    };
                }
            }
        }

        const files = Array.isArray(word && word.audio_files) ? word.audio_files : [];
        for (let index = 0; index < orderedTypes.length; index += 1) {
            const type = orderedTypes[index];
            for (let fileIndex = 0; fileIndex < files.length; fileIndex += 1) {
                const entry = files[fileIndex] || {};
                if (normalizeRecordingType(entry.recording_type || '') === type && entry.url) {
                    return {
                        url: String(entry.url),
                        recordingType: type
                    };
                }
            }
        }

            return {
                url: String(word && word.audio || ''),
                recordingType: orderedTypes[0] || ''
            };
    }

    function buildStageMetrics(run) {
        const width = Math.max(280, run.width || 720);
        const height = Math.max(360, run.height || 720);
        const laneCount = run.cardCount || 4;
        const gameSlug = normalizeGameSlug(run && run.slug);
        const laneWidth = width / laneCount;
        const defaultCardWidth = clamp(width * 0.185, 84, 132);
        const useExpandedImageCards = (
            (gameSlug === DEFAULT_GAME_SLUG || gameSlug === BUBBLE_POP_GAME_SLUG)
            && laneCount <= 3
        );
        const cardWidth = useExpandedImageCards
            ? clamp(laneWidth * 0.88, defaultCardWidth, 188)
            : defaultCardWidth;
        const cardHeight = useExpandedImageCards
            ? clamp(cardWidth * 1.28, 140, 236)
            : clamp(cardWidth * 1.28, 112, 180);
        const shipWidth = clamp(width * 0.14, 54, 90);
        const shipHeight = shipWidth * 0.58;
        return {
            width: width,
            height: height,
            laneCount: laneCount,
            laneWidth: laneWidth,
            cardWidth: cardWidth,
            cardHeight: cardHeight,
            shipWidth: shipWidth,
            shipHeight: shipHeight,
            shipY: height - Math.max(44, shipHeight * 0.8),
            bulletSpeed: clamp(height * 1.2, 640, 1020),
            shipSpeed: clamp(width * 0.78, 320, 580)
        };
    }

    function laneCenterX(run, laneIndex) {
        const metrics = run.metrics;
        return (metrics.laneWidth * laneIndex) + (metrics.laneWidth / 2);
    }

    function createStageStars(run) {
        const stars = [];
        for (let index = 0; index < 46; index += 1) {
            stars.push({
                x: Math.random() * run.width,
                y: Math.random() * run.height,
                radius: (Math.random() * 1.7) + 0.4,
                alpha: (Math.random() * 0.45) + 0.18
            });
        }
        return stars;
    }

    function scaleBubbleRunLayout(run, widthRatio, heightRatio) {
        if (!run || !isFinite(widthRatio) || !isFinite(heightRatio)) {
            return;
        }

        const decorativeBubbles = Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles : [];
        if (!Array.isArray(run.decorativeBubbles)) {
            run.decorativeBubbles = decorativeBubbles;
        }

        run.cards.forEach(function (card) {
            [
                'bubbleVisibleX',
                'bubbleBaseX',
                'entryVisibleX',
                'entryStartX',
                'x'
            ].forEach(function (key) {
                if (isFinite(Number(card && card[key]))) {
                    card[key] = Number(card[key]) * widthRatio;
                }
            });
            [
                'bubbleVisibleY',
                'bubbleBaseY',
                'entryVisibleY',
                'entryStartY',
                'y'
            ].forEach(function (key) {
                if (isFinite(Number(card && card[key]))) {
                    card[key] = Number(card[key]) * heightRatio;
                }
            });
        });

        decorativeBubbles.forEach(function (bubble) {
            [
                'baseX',
                'x'
            ].forEach(function (key) {
                if (isFinite(Number(bubble && bubble[key]))) {
                    bubble[key] = Number(bubble[key]) * widthRatio;
                }
            });
            [
                'baseY',
                'y'
            ].forEach(function (key) {
                if (isFinite(Number(bubble && bubble[key]))) {
                    bubble[key] = Number(bubble[key]) * heightRatio;
                }
            });
        });
    }

    function scaleSpeakingStackRunLayout(run, widthRatio, heightRatio) {
        if (!run || !isFinite(widthRatio) || !isFinite(heightRatio)) {
            return;
        }

        run.cards.forEach(function (card) {
            [
                'x',
                'stackTargetX',
                'entryStartX',
                'lastSettledX'
            ].forEach(function (key) {
                if (isFinite(Number(card && card[key]))) {
                    card[key] = Number(card[key]) * widthRatio;
                }
            });
            [
                'y',
                'stackTargetY',
                'entryStartY',
                'lastSettledY'
            ].forEach(function (key) {
                if (isFinite(Number(card && card[key]))) {
                    card[key] = Number(card[key]) * heightRatio;
                }
            });
        });

        if (isFinite(Number(run.lastPlacementX)) && Number(run.lastPlacementX) > 0) {
            run.lastPlacementX = Number(run.lastPlacementX) * widthRatio;
        }
    }

    function syncCanvasSize(ctx) {
        if (!ctx || !ctx.canvas || !ctx.canvas.getContext) {
            return;
        }

        const run = ctx.run;
        const previousWidth = run ? Math.max(1, Number(run.width) || 1) : 1;
        const previousHeight = run ? Math.max(1, Number(run.height) || 1) : 1;
        const wrapWidth = Math.max(280, Math.round(ctx.$canvasWrap.innerWidth() || ctx.$stage.innerWidth() || 720));
        const runModalDialogHeight = isRunModalVisible(ctx) && ctx.$runModalDialog && ctx.$runModalDialog.length
            ? Math.round(ctx.$runModalDialog.innerHeight() || 0)
            : 0;
        const stage = ctx.$stage && ctx.$stage.length ? ctx.$stage.get(0) : null;
        const stageStyles = stage && root.getComputedStyle ? root.getComputedStyle(stage) : null;
        const stageVerticalPadding = stageStyles
            ? (parseFloat(stageStyles.paddingTop || '0') + parseFloat(stageStyles.paddingBottom || '0'))
            : 32;
        const hudHeight = ctx.$hud && ctx.$hud.length && !ctx.$hud.prop('hidden')
            ? Math.ceil(ctx.$hud.outerHeight(true) || 0)
            : 0;
        const controlsHeight = ctx.$controlsWrap && ctx.$controlsWrap.length && !ctx.$controlsWrap.prop('hidden')
            ? Math.ceil(ctx.$controlsWrap.outerHeight(true) || 0)
            : 0;
        const speakingHeight = ctx.$speakingStage && ctx.$speakingStage.length && !ctx.$speakingStage.prop('hidden')
            ? Math.ceil(ctx.$speakingStage.outerHeight(true) || 0)
            : 0;
        const reservedHeight = Math.ceil(stageVerticalPadding + hudHeight + controlsHeight + speakingHeight + 22);
        const viewportCap = runModalDialogHeight > 0
            ? Math.max(220, runModalDialogHeight - reservedHeight)
            : (root.innerHeight ? Math.max(220, Math.round(root.innerHeight * 0.68)) : 820);
        const minCanvasHeight = Math.max(220, Math.min(430, viewportCap));
        const cssHeight = clamp(Math.round(wrapWidth * 1.18), minCanvasHeight, Math.max(minCanvasHeight, viewportCap));
        const dpr = clamp(root.devicePixelRatio || 1, 1, 2);

        ctx.canvas.width = Math.round(wrapWidth * dpr);
        ctx.canvas.height = Math.round(cssHeight * dpr);
        ctx.canvas.style.width = wrapWidth + 'px';
        ctx.canvas.style.height = cssHeight + 'px';

        ctx.canvasContext = ctx.canvas.getContext('2d');
        if (!ctx.canvasContext) {
            return;
        }
        ctx.canvasContext.setTransform(dpr, 0, 0, dpr, 0, 0);

        if (!run) {
            return;
        }

        run.width = wrapWidth;
        run.height = cssHeight;
        run.dpr = dpr;
        run.metrics = buildStageMetrics(run);
        run.shipY = run.metrics.shipY;
        run.shipX = clamp(run.shipX || (run.width / 2), run.metrics.shipWidth / 2, run.width - (run.metrics.shipWidth / 2));
        run.stars = createStageStars(run);

        const cards = Array.isArray(run.cards) ? run.cards : [];
        const bullets = Array.isArray(run.bullets) ? run.bullets : [];
        const explosions = Array.isArray(run.explosions) ? run.explosions : [];

        cards.forEach(function (card) {
            applyCardDimensions(ctx, run, card);
        });
        if (isBubblePopRun(ctx, run)) {
            scaleBubbleRunLayout(run, run.width / previousWidth, run.height / previousHeight);
            seedDecorativeBubbles(run);
            refreshBubblePromptCardPositions(run, currentTimestamp());
        } else if (isSpeakingStackRun(ctx, run)) {
            scaleSpeakingStackRunLayout(run, run.width / previousWidth, run.height / previousHeight);
            relayoutSpeakingStackCards(ctx, run, {
                instant: true
            });
        }
        bullets.forEach(function (bullet) {
            bullet.x = clamp(bullet.x, 0, run.width);
            bullet.y = clamp(bullet.y, -20, run.height + 20);
        });
        explosions.forEach(function (explosion) {
            explosion.x = clamp(explosion.x, 0, run.width);
            explosion.y = clamp(explosion.y, 0, run.height);
        });
    }

    function getTracker() {
        return (root.LLFlashcards && root.LLFlashcards.ProgressTracker && typeof root.LLFlashcards.ProgressTracker.setContext === 'function')
            ? root.LLFlashcards.ProgressTracker
            : null;
    }

    function setTrackerContext(ctx) {
        const tracker = getTracker();
        if (!tracker) {
            return null;
        }

        tracker.setContext({
            mode: 'practice',
            wordsetId: ctx.wordsetId,
            categoryIds: ctx.visibleCategoryIds
        });
        return tracker;
    }

    function buildProgressPayload(ctx, prompt, extra) {
        const word = prompt && prompt.target ? prompt.target : null;
        return $.extend({}, extra || {}, {
            recording_type: normalizeRecordingType(prompt && prompt.recordingType || ''),
            available_recording_types: Array.isArray(word && word.practice_recording_types)
                ? word.practice_recording_types.slice()
                : [],
            game_slug: normalizeGameSlug(prompt && prompt.gameSlug || getCurrentGameSlug(ctx))
        });
    }

    function queueExposureOnce(ctx, prompt, extraPayload) {
        const tracker = setTrackerContext(ctx);
        if (!tracker || !prompt || prompt.exposureTracked || !prompt.target) {
            return null;
        }

        prompt.exposureTracked = true;
        return tracker.trackWordExposure({
            mode: 'practice',
            word: prompt.target,
            wordsetId: ctx.wordsetId,
            categoryId: prompt.target.category_id,
            categoryName: prompt.target.category_name,
            payload: buildProgressPayload(ctx, prompt, extraPayload || {})
        });
    }

    function queueOutcome(ctx, prompt, isCorrect, hadWrongBefore, extraPayload) {
        const tracker = setTrackerContext(ctx);
        if (!tracker || !prompt || !prompt.target) {
            return null;
        }

        return tracker.trackWordOutcome({
            mode: 'practice',
            word: prompt.target,
            wordsetId: ctx.wordsetId,
            categoryId: prompt.target.category_id,
            categoryName: prompt.target.category_name,
            isCorrect: !!isCorrect,
            hadWrongBefore: !!hadWrongBefore,
            payload: buildProgressPayload(ctx, prompt, extraPayload || {})
        });
    }

    function flushProgress(ctx) {
        const tracker = getTracker();
        if (!tracker || typeof tracker.flush !== 'function') {
            return null;
        }
        return tracker.flush({ immediate: true });
    }

    function updateProgressGlobals(ctx) {
        root.llToolsStudyData = $.extend({}, root.llToolsStudyData || {}, {
            ajaxUrl: ctx.ajaxUrl,
            nonce: ctx.nonce,
            isLoggedIn: ctx.isLoggedIn
        });
    }

    function drawRoundedRect(context, x, y, width, height, radius) {
        const r = Math.max(4, Math.min(radius, width / 2, height / 2));
        context.beginPath();
        context.moveTo(x + r, y);
        context.lineTo(x + width - r, y);
        context.quadraticCurveTo(x + width, y, x + width, y + r);
        context.lineTo(x + width, y + height - r);
        context.quadraticCurveTo(x + width, y + height, x + width - r, y + height);
        context.lineTo(x + r, y + height);
        context.quadraticCurveTo(x, y + height, x, y + height - r);
        context.lineTo(x, y + r);
        context.quadraticCurveTo(x, y, x + r, y);
        context.closePath();
    }

    function getCardRadius(card) {
        return Math.max(10, Math.min(18, Math.min(card.width, card.height) * 0.18));
    }

    function getCardAmbientMotion(card, now) {
        const motionTime = Math.max(0, Number(now) || 0) / 1000;
        const revealMs = Math.max(0, toInt(card && card.entryRevealMs));
        const revealProgress = revealMs > 0
            ? clamp((Math.max(0, Number(now) - Number(card.entryStartedAt || 0))) / revealMs, 0, 1)
            : 1;
        const motionStrength = 0.3 + (0.7 * revealProgress);
        const bobPrimary = Math.sin((motionTime * Number(card && card.motionBobHzPrimary || 0.18) * Math.PI * 2) + Number(card && card.motionBobPhasePrimary || 0))
            * Number(card && card.motionBobAmplitudePrimary || 0);
        const bobSecondary = Math.sin((motionTime * Number(card && card.motionBobHzSecondary || 0.27) * Math.PI * 2) + Number(card && card.motionBobPhaseSecondary || 0))
            * Number(card && card.motionBobAmplitudeSecondary || 0);
        const tiltPrimary = Math.sin((motionTime * Number(card && card.motionTiltHzPrimary || 0.12) * Math.PI * 2) + Number(card && card.motionTiltPhasePrimary || 0))
            * Number(card && card.motionTiltAmplitudePrimary || 0);
        const tiltSecondary = Math.sin((motionTime * Number(card && card.motionTiltHzSecondary || 0.19) * Math.PI * 2) + Number(card && card.motionTiltPhaseSecondary || 0))
            * Number(card && card.motionTiltAmplitudeSecondary || 0);

        return {
            bobY: (bobPrimary + bobSecondary) * motionStrength,
            tilt: (tiltPrimary + tiltSecondary) * motionStrength
        };
    }

    function getCardDimensions(run, aspectRatio) {
        const ratio = clampAspectRatio(aspectRatio) || CARD_RATIO_DEFAULT;
        const maxWidth = Math.max(1, Number(run && run.metrics && run.metrics.cardWidth) || 1);
        const maxHeight = Math.max(1, Number(run && run.metrics && run.metrics.cardHeight) || 1);

        let width = maxWidth;
        let height = width / ratio;
        if (height > maxHeight) {
            height = maxHeight;
            width = height * ratio;
        }

        return {
            width: Math.round(width * 100) / 100,
            height: Math.round(height * 100) / 100,
            aspectRatio: ratio
        };
    }

    function getWordImageAspectRatio(ctx, word) {
        const image = loadWordImage(ctx, word);
        if (!image || !image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0) {
            return null;
        }
        return clampAspectRatio(image.naturalWidth / image.naturalHeight);
    }

    function applyCardDimensions(ctx, run, card) {
        if (!run || !card) {
            return;
        }

        const dimensions = getCardDimensions(run, getWordImageAspectRatio(ctx, card.word));
        card.width = dimensions.width;
        card.height = dimensions.height;
        card.aspectRatio = dimensions.aspectRatio;
        if (isBubblePopRun(ctx, run)) {
            if (isFinite(Number(card.entryStartX))) {
                card.x = Number(card.entryStartX);
            } else if (isFinite(Number(card.bubbleVisibleX))) {
                card.x = Number(card.bubbleVisibleX);
            }
            return;
        }
        card.x = laneCenterX(run, card.laneIndex);
    }

    function getImageResource(ctx, word) {
        const url = String(word && word.image || '');
        if (!url) {
            return null;
        }
        if (ctx.imageCache[url]) {
            return ctx.imageCache[url];
        }

        const image = new Image();
        const resource = {
            image: image,
            status: 'loading',
            promise: null
        };

        image.decoding = 'async';
        resource.promise = new Promise(function (resolve) {
            let settled = false;
            let timeoutId = 0;

            const cleanup = function () {
                image.removeEventListener('load', onLoad);
                image.removeEventListener('error', onError);
                if (timeoutId) {
                    root.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
            };

            const finish = function (isLoaded) {
                if (settled) {
                    return;
                }
                settled = true;
                resource.status = isLoaded ? 'loaded' : 'error';
                cleanup();
                resolve(!!isLoaded);
            };

            const onLoad = function () {
                const run = ctx && ctx.run;
                let speakingStackLayoutChanged = false;
                if (run && Array.isArray(run.cards)) {
                    run.cards.forEach(function (card) {
                        if (toInt(card && card.word && card.word.id) === toInt(word && word.id)) {
                            applyCardDimensions(ctx, run, card);
                            speakingStackLayoutChanged = speakingStackLayoutChanged || isSpeakingStackRun(ctx, run);
                        }
                    });
                    if (speakingStackLayoutChanged) {
                        relayoutSpeakingStackCards(ctx, run);
                    }
                }
                finish(image.naturalWidth > 0 && image.naturalHeight > 0);
            };

            const onError = function () {
                finish(false);
            };

            image.addEventListener('load', onLoad);
            image.addEventListener('error', onError);
            timeoutId = root.setTimeout(function () {
                finish(false);
            }, getAssetPreloadTimeoutMs(ctx));

            try {
                image.src = url;
                if (image.complete) {
                    onLoad();
                }
            } catch (_) {
                onError();
            }
        });

        ctx.imageCache[url] = resource;
        return resource;
    }

    function loadWordImage(ctx, word) {
        const resource = getImageResource(ctx, word);
        return resource ? resource.image : null;
    }

    function ensureWordImageLoaded(ctx, word) {
        const resource = getImageResource(ctx, word);
        return resource ? resource.promise : Promise.resolve(false);
    }

    function getAudioPreloadResource(ctx, url) {
        const source = String(url || '');
        if (!source) {
            return null;
        }
        if (ctx.audioPreloadCache[source]) {
            return ctx.audioPreloadCache[source];
        }

        const audio = new Audio();
        const resource = {
            audio: audio,
            status: 'loading',
            promise: null
        };

        audio.preload = 'auto';
        resource.promise = new Promise(function (resolve) {
            let settled = false;
            let timeoutId = 0;

            const cleanup = function () {
                audio.removeEventListener('canplaythrough', onReady);
                audio.removeEventListener('canplay', onReady);
                audio.removeEventListener('loadeddata', onReady);
                audio.removeEventListener('error', onError);
                if (timeoutId) {
                    root.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
            };

            const finish = function (isLoaded) {
                if (settled) {
                    return;
                }
                settled = true;
                resource.status = isLoaded ? 'loaded' : 'error';
                cleanup();
                resolve(!!isLoaded);
            };

            const onReady = function () {
                finish(true);
            };

            const onError = function () {
                finish(false);
            };

            audio.addEventListener('canplaythrough', onReady);
            audio.addEventListener('canplay', onReady);
            audio.addEventListener('loadeddata', onReady);
            audio.addEventListener('error', onError);
            timeoutId = root.setTimeout(function () {
                finish(false);
            }, getAssetPreloadTimeoutMs(ctx));

            try {
                audio.src = source;
                if (audio.readyState >= 2) {
                    onReady();
                    return;
                }
                if (typeof audio.load === 'function') {
                    audio.load();
                }
            } catch (_) {
                onError();
            }
        });

        ctx.audioPreloadCache[source] = resource;
        return resource;
    }

    function ensureAudioLoaded(ctx, url) {
        const resource = getAudioPreloadResource(ctx, url);
        return resource ? resource.promise : Promise.resolve(false);
    }

    function getLoadedAudioDurationMs(ctx, url) {
        const resource = getAudioPreloadResource(ctx, url);
        const durationSeconds = Number(resource && resource.audio && resource.audio.duration);
        if (!durationSeconds || !isFinite(durationSeconds) || durationSeconds <= 0) {
            return 0;
        }
        return durationSeconds * 1000;
    }

    function renderBackground(ctx, run) {
        const context = ctx.canvasContext;
        context.clearRect(0, 0, run.width, run.height);

        if (isSpeakingStackRun(ctx, run)) {
            const sky = context.createLinearGradient(0, 0, 0, run.height);
            sky.addColorStop(0, '#7BC9FF');
            sky.addColorStop(0.58, '#AEE2FF');
            sky.addColorStop(1, '#DFF4FF');
            context.fillStyle = sky;
            context.fillRect(0, 0, run.width, run.height);

            const sunGlow = context.createRadialGradient(run.width * 0.82, run.height * 0.14, 0, run.width * 0.82, run.height * 0.14, run.width * 0.24);
            sunGlow.addColorStop(0, 'rgba(255, 243, 176, 0.92)');
            sunGlow.addColorStop(0.34, 'rgba(255, 230, 128, 0.34)');
            sunGlow.addColorStop(1, 'rgba(255, 230, 128, 0)');
            context.fillStyle = sunGlow;
            context.fillRect(0, 0, run.width, run.height);

            context.save();
            context.translate(run.width * 0.82, run.height * 0.14);
            context.fillStyle = '#FFF6B4';
            context.beginPath();
            context.arc(0, 0, Math.max(26, run.width * 0.035), 0, Math.PI * 2);
            context.fill();
            context.restore();

            run.stars.slice(0, 10).forEach(function (star, index) {
                const cloudX = Number(star.x || 0);
                const cloudY = clamp((Number(star.y || 0) * 0.38) + (index % 2 === 0 ? 6 : 0), 30, run.height * 0.48);
                const cloudWidth = 46 + (index % 4) * 14;
                const cloudHeight = 18 + (index % 3) * 6;

                context.save();
                context.globalAlpha = 0.12 + ((Number(star.alpha) || 0.2) * 0.46);
                context.fillStyle = '#FFFFFF';
                [
                    { x: -cloudWidth * 0.18, y: cloudHeight * 0.08, r: cloudHeight * 0.7 },
                    { x: cloudWidth * 0.08, y: -cloudHeight * 0.12, r: cloudHeight * 0.88 },
                    { x: cloudWidth * 0.34, y: cloudHeight * 0.1, r: cloudHeight * 0.66 }
                ].forEach(function (puff) {
                    context.beginPath();
                    context.arc(cloudX + puff.x, cloudY + puff.y, puff.r, 0, Math.PI * 2);
                    context.fill();
                });
                context.restore();
            });

            const groundTop = getSpeakingStackGroundTop(ctx, run);
            const ground = context.createLinearGradient(0, groundTop, 0, run.height);
            ground.addColorStop(0, '#76BE3A');
            ground.addColorStop(0.52, '#5EA52E');
            ground.addColorStop(1, '#417B1F');
            context.fillStyle = ground;
            context.fillRect(0, groundTop, run.width, run.height - groundTop);

            context.fillStyle = 'rgba(247, 255, 214, 0.44)';
            context.fillRect(0, groundTop, run.width, 5);

            context.fillStyle = 'rgba(102, 166, 44, 0.26)';
            context.beginPath();
            context.moveTo(0, groundTop + 6);
            context.quadraticCurveTo(run.width * 0.18, groundTop - 18, run.width * 0.38, groundTop + 8);
            context.quadraticCurveTo(run.width * 0.58, groundTop + 24, run.width * 0.82, groundTop - 10);
            context.quadraticCurveTo(run.width * 0.92, groundTop - 18, run.width, groundTop + 2);
            context.lineTo(run.width, run.height);
            context.lineTo(0, run.height);
            context.closePath();
            context.fill();
            return;
        }

        if (isBubblePopRun(ctx, run)) {
            const bubbleGradient = context.createLinearGradient(0, 0, 0, run.height);
            bubbleGradient.addColorStop(0, '#A9E7FF');
            bubbleGradient.addColorStop(0.46, '#8AD8FC');
            bubbleGradient.addColorStop(1, '#6ABBE9');
            context.fillStyle = bubbleGradient;
            context.fillRect(0, 0, run.width, run.height);

            const glow = context.createRadialGradient(run.width * 0.36, run.height * 0.12, 0, run.width * 0.36, run.height * 0.12, run.width * 0.58);
            glow.addColorStop(0, 'rgba(255, 255, 255, 0.34)');
            glow.addColorStop(1, 'rgba(255, 255, 255, 0)');
            context.fillStyle = glow;
            context.fillRect(0, 0, run.width, run.height);

            const lowerGlow = context.createRadialGradient(run.width * 0.78, run.height * 0.82, 0, run.width * 0.78, run.height * 0.82, run.width * 0.48);
            lowerGlow.addColorStop(0, 'rgba(225, 245, 255, 0.24)');
            lowerGlow.addColorStop(1, 'rgba(225, 245, 255, 0)');
            context.fillStyle = lowerGlow;
            context.fillRect(0, 0, run.width, run.height);

            run.stars.forEach(function (star, index) {
                const radius = star.radius * (index % 3 === 0 ? 4.8 : 3.2);
                context.globalAlpha = Math.min(0.24, star.alpha * 0.48);
                context.fillStyle = index % 2 === 0 ? '#F8FDFF' : '#D7F0FF';
                context.beginPath();
                context.arc(star.x, star.y, radius, 0, Math.PI * 2);
                context.fill();
            });
            context.globalAlpha = 1;
            return;
        }

        const gradient = context.createLinearGradient(0, 0, 0, run.height);
        gradient.addColorStop(0, '#0F172A');
        gradient.addColorStop(0.48, '#132848');
        gradient.addColorStop(1, '#181B2B');
        context.fillStyle = gradient;
        context.fillRect(0, 0, run.width, run.height);

        const glow = context.createRadialGradient(run.width * 0.5, 36, 0, run.width * 0.5, 36, run.width * 0.45);
        glow.addColorStop(0, 'rgba(56, 189, 248, 0.18)');
        glow.addColorStop(1, 'rgba(56, 189, 248, 0)');
        context.fillStyle = glow;
        context.fillRect(0, 0, run.width, run.height);

        run.stars.forEach(function (star) {
            context.globalAlpha = star.alpha;
            context.fillStyle = '#F8FAFC';
            context.beginPath();
            context.arc(star.x, star.y, star.radius, 0, Math.PI * 2);
            context.fill();
        });
        context.globalAlpha = 1;

        context.strokeStyle = 'rgba(255,255,255,0.06)';
        context.lineWidth = 1;
        for (let laneIndex = 1; laneIndex < run.cardCount; laneIndex += 1) {
            const x = laneCenterX(run, laneIndex) - (run.metrics.laneWidth / 2);
            context.beginPath();
            context.moveTo(x, 0);
            context.lineTo(x, run.height);
            context.stroke();
        }
    }

    function renderShip(ctx, run) {
        if (!isSpaceShooterRun(ctx, run)) {
            return;
        }
        const context = ctx.canvasContext;
        const width = run.metrics.shipWidth;
        const height = run.metrics.shipHeight;
        const x = run.shipX;
        const y = run.metrics.shipY;

        context.save();
        context.translate(x, y);
        context.fillStyle = '#E2E8F0';
        context.beginPath();
        context.moveTo(0, -height);
        context.lineTo(width * 0.48, height * 0.72);
        context.lineTo(0, height * 0.3);
        context.lineTo(-width * 0.48, height * 0.72);
        context.closePath();
        context.fill();

        context.fillStyle = '#38BDF8';
        context.beginPath();
        context.moveTo(0, -height * 0.6);
        context.lineTo(width * 0.18, height * 0.34);
        context.lineTo(-width * 0.18, height * 0.34);
        context.closePath();
        context.fill();

        context.fillStyle = 'rgba(15, 118, 110, 0.92)';
        context.beginPath();
        context.arc(0, height * 0.38, width * 0.14, 0, Math.PI, false);
        context.fill();
        context.restore();
    }

    function renderBullets(ctx, run) {
        if (!isSpaceShooterRun(ctx, run)) {
            return;
        }
        const context = ctx.canvasContext;
        context.fillStyle = '#67E8F9';
        run.bullets.forEach(function (bullet) {
            context.beginPath();
            context.arc(bullet.x, bullet.y, bullet.radius, 0, Math.PI * 2);
            context.fill();
        });
    }

    function drawImageContain(context, image, x, y, width, height) {
        if (!image || !image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0) {
            return false;
        }

        const containScale = Math.min(
            width / Math.max(1, image.naturalWidth),
            height / Math.max(1, image.naturalHeight)
        );
        const drawWidth = image.naturalWidth * containScale;
        const drawHeight = image.naturalHeight * containScale;
        const drawX = x + ((width - drawWidth) / 2);
        const drawY = y + ((height - drawHeight) / 2);

        context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
        return true;
    }

    function getBubbleRadius(card) {
        return Math.max(28, Math.min(card.width, card.height) * 0.5);
    }

    function getFloatingBodyRadius(body) {
        const explicitRadius = Number(body && body.radius);
        if (isFinite(explicitRadius) && explicitRadius > 0) {
            return explicitRadius;
        }
        return getBubbleRadius(body);
    }

    function getBubbleMovementBounds(run, radius) {
        const bubbleRadius = Math.max(10, Number(radius) || 10);
        const sidePadding = Math.max(20, run.width * 0.04);
        const topPadding = Math.max(12, run.height * 0.02);
        const bottomPadding = Math.max(72, run.height * 0.08);
        return {
            minX: bubbleRadius + sidePadding,
            maxX: run.width - bubbleRadius - sidePadding,
            minY: bubbleRadius + topPadding,
            maxY: run.height - bubbleRadius - bottomPadding
        };
    }

    function setFloatingBodyPosition(run, body, x, y, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const bounds = getBubbleMovementBounds(run, getFloatingBodyRadius(body));
        body.x = opts.clampX === false
            ? (Number(x) || 0)
            : clamp(Number(x) || 0, bounds.minX, bounds.maxX);
        body.y = opts.clampY === false
            ? (Number(y) || 0)
            : clamp(Number(y) || 0, bounds.minY, bounds.maxY);
    }

    function getBubbleFloatOffsets(card, now, strength) {
        const time = Math.max(0, Number(now) || 0) / 1000;
        const offsetX = (
            Math.sin((time * Number(card && card.bubbleFloatHzXPrimary || 0.14) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseXPrimary || 0))
                * Number(card && card.bubbleFloatAmplitudeXPrimary || 0)
        ) + (
            Math.sin((time * Number(card && card.bubbleFloatHzXSecondary || 0.26) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseXSecondary || 0))
                * Number(card && card.bubbleFloatAmplitudeXSecondary || 0)
        );
        const offsetY = (
            Math.sin((time * Number(card && card.bubbleFloatHzYPrimary || 0.18) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseYPrimary || 0))
                * Number(card && card.bubbleFloatAmplitudeYPrimary || 0)
        ) + (
            Math.sin((time * Number(card && card.bubbleFloatHzYSecondary || 0.31) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseYSecondary || 0))
                * Number(card && card.bubbleFloatAmplitudeYSecondary || 0)
        );
        return {
            x: offsetX,
            y: offsetY
        };
    }

    function ensureBubbleFloatReference(card, now) {
        if (!card) {
            return { x: 0, y: 0 };
        }

        const rawOffsets = getBubbleFloatOffsets(card, now);
        if (!isFinite(Number(card.bubbleFloatReferenceX)) || !isFinite(Number(card.bubbleFloatReferenceY))) {
            card.bubbleFloatReferenceX = rawOffsets.x;
            card.bubbleFloatReferenceY = rawOffsets.y;
        }
        return rawOffsets;
    }

    function getRelativeBubbleFloatOffsets(card, now, strength) {
        const scale = isFinite(Number(strength)) ? Number(strength) : 1;
        const rawOffsets = ensureBubbleFloatReference(card, now);
        return {
            x: (rawOffsets.x - Number(card.bubbleFloatReferenceX || 0)) * scale,
            y: (rawOffsets.y - Number(card.bubbleFloatReferenceY || 0)) * scale
        };
    }

    function persistFloatingBodyBasePosition(body, offsets, xKey, yKey) {
        if (!body) {
            return;
        }

        const nextXKey = String(xKey || '');
        const nextYKey = String(yKey || '');
        if (!nextXKey || !nextYKey) {
            return;
        }

        const offsetX = Number(offsets && offsets.x) || 0;
        const offsetY = Number(offsets && offsets.y) || 0;
        body[nextXKey] = Number(body.x || 0) - offsetX;
        body[nextYKey] = Number(body.y || 0) - offsetY;
    }

    function addFloatingBodyImpulse(body, impulseX, impulseY) {
        if (!body) {
            return;
        }

        body.bubbleImpulseVelocityX = clamp((Number(body.bubbleImpulseVelocityX) || 0) + (Number(impulseX) || 0), -160, 160);
        body.bubbleImpulseVelocityY = clamp((Number(body.bubbleImpulseVelocityY) || 0) + (Number(impulseY) || 0), -160, 160);
    }

    function applyBubbleBlastImpulse(run, sourceX, sourceY, sourceRadius, excludedBody) {
        if (!run) {
            return;
        }

        const originX = Number(sourceX) || 0;
        const originY = Number(sourceY) || 0;
        const radius = Math.max(24, Number(sourceRadius) || 24);
        const effectRadius = Math.max(170, radius * 4.2);
        const maxImpulse = Math.max(150, radius * 4);
        const bodies = []
            .concat(Array.isArray(run.cards) ? run.cards : [])
            .concat(Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles : []);

        bodies.forEach(function (body) {
            if (!body || body === excludedBody || body.exploding) {
                return;
            }
            if (body.word && body.entryRevealMs > 0) {
                return;
            }

            const dx = Number(body.x) - originX;
            const dy = Number(body.y) - originY;
            const distance = Math.sqrt((dx * dx) + (dy * dy));
            if (distance > effectRadius) {
                return;
            }

            const normalizedDistance = clamp(distance / effectRadius, 0, 1);
            const strength = Math.pow(1 - normalizedDistance, 1.45);
            if (strength <= 0) {
                return;
            }

            const directionX = distance > 0.001 ? (dx / distance) : (Math.random() < 0.5 ? -1 : 1);
            const directionY = distance > 0.001 ? (dy / distance) : -0.25;
            const impulse = maxImpulse * strength;
            addFloatingBodyImpulse(body, directionX * impulse, directionY * impulse);
        });
    }

    function resolveFloatingBubbleOverlaps(run, bodies, gap) {
        const list = Array.isArray(bodies) ? bodies.filter(Boolean) : [];
        const padding = Math.max(0, Number(gap) || 0);
        for (let pass = 0; pass < 3; pass += 1) {
            let moved = false;
            for (let leftIndex = 0; leftIndex < list.length; leftIndex += 1) {
                for (let rightIndex = leftIndex + 1; rightIndex < list.length; rightIndex += 1) {
                    const left = list[leftIndex];
                    const right = list[rightIndex];
                    const dx = Number(right.x) - Number(left.x);
                    const dy = Number(right.y) - Number(left.y);
                    const distance = Math.sqrt((dx * dx) + (dy * dy)) || 0.001;
                    const minimumDistance = getFloatingBodyRadius(left) + getFloatingBodyRadius(right) + padding;
                    if (distance >= minimumDistance) {
                        continue;
                    }

                    const overlap = minimumDistance - distance;
                    const normalX = dx / distance;
                    const normalY = dy / distance;
                    setFloatingBodyPosition(run, left, Number(left.x) - (normalX * overlap * 0.5), Number(left.y) - (normalY * overlap * 0.5), {
                        clampY: false
                    });
                    setFloatingBodyPosition(run, right, Number(right.x) + (normalX * overlap * 0.5), Number(right.y) + (normalY * overlap * 0.5), {
                        clampY: false
                    });
                    moved = true;
                }
            }
            if (!moved) {
                break;
            }
        }
    }

    function placeBubblePromptCards(run, cards) {
        const promptCards = Array.isArray(cards) ? cards.slice() : [];
        const orderedCards = promptCards.sort(function (left, right) {
            return getBubbleRadius(right) - getBubbleRadius(left);
        });
        const bandCenters = shuffle([0.61, 0.68, 0.74, 0.8, 0.65, 0.77]);
        const placed = [];

        orderedCards.forEach(function (card, index) {
            const radius = getBubbleRadius(card);
            const bounds = getBubbleMovementBounds(run, radius);
            const bandCenter = bandCenters[index % bandCenters.length];
            const bandMin = clamp((run.height * bandCenter) - (run.height * 0.03), bounds.minY, bounds.maxY);
            const bandMax = clamp((run.height * bandCenter) + (run.height * 0.03), bandMin, bounds.maxY);
            let bestCandidate = null;
            let bestClearance = -Infinity;

            for (let attempt = 0; attempt < 90; attempt += 1) {
                const candidate = {
                    x: randomBetween(bounds.minX, bounds.maxX),
                    y: randomBetween(bandMin, bandMax)
                };
                let minimumClearance = placed.length ? Infinity : 9999;
                let overlapping = false;

                placed.forEach(function (existing) {
                    const distance = Math.sqrt(Math.pow(candidate.x - existing.x, 2) + Math.pow(candidate.y - existing.y, 2));
                    const clearance = distance - (radius + existing.radius + 18);
                    minimumClearance = Math.min(minimumClearance, clearance);
                    if (clearance < 0) {
                        overlapping = true;
                    }
                });

                if (minimumClearance > bestClearance) {
                    bestCandidate = candidate;
                    bestClearance = minimumClearance;
                }
                if (!overlapping) {
                    bestCandidate = candidate;
                    break;
                }
            }

            const chosen = bestCandidate || {
                x: (bounds.minX + bounds.maxX) / 2,
                y: clamp(run.height * bandCenter, bounds.minY, bounds.maxY)
            };
            card.bubbleVisibleX = chosen.x;
            card.bubbleVisibleY = chosen.y;
            card.bubbleBaseX = chosen.x;
            card.bubbleBaseY = chosen.y;
            card.entryVisibleX = chosen.x;
            card.entryVisibleY = chosen.y;
            card.entryStartX = chosen.x;
            card.bubbleWanderVelocityX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(4, 11);
            card.bubbleFloatAmplitudeXPrimary = randomBetween(8, 18);
            card.bubbleFloatAmplitudeXSecondary = randomBetween(3, 8);
            card.bubbleFloatAmplitudeYPrimary = randomBetween(6, 13);
            card.bubbleFloatAmplitudeYSecondary = randomBetween(2, 6);
            card.bubbleFloatHzXPrimary = randomBetween(0.08, 0.17);
            card.bubbleFloatHzXSecondary = randomBetween(0.16, 0.28);
            card.bubbleFloatHzYPrimary = randomBetween(0.1, 0.2);
            card.bubbleFloatHzYSecondary = randomBetween(0.18, 0.32);
            card.bubbleFloatPhaseXPrimary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatPhaseXSecondary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatPhaseYPrimary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatPhaseYSecondary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatReferenceX = null;
            card.bubbleFloatReferenceY = null;
            card.releaseDriftX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(12, 28);
            setFloatingBodyPosition(run, card, chosen.x, chosen.y);

            placed.push({
                x: chosen.x,
                y: chosen.y,
                radius: radius
            });
        });

        resolveFloatingBubbleOverlaps(run, orderedCards, 16);
        orderedCards.forEach(function (card) {
            if (!card) {
                return;
            }

            card.bubbleVisibleX = Number(card.x || card.bubbleVisibleX || 0);
            card.bubbleVisibleY = Number(card.y || card.bubbleVisibleY || 0);
            card.bubbleBaseX = Number(card.x || card.bubbleBaseX || 0);
            card.bubbleBaseY = Number(card.y || card.bubbleBaseY || 0);
            card.entryVisibleX = Number(card.x || card.entryVisibleX || 0);
            card.entryVisibleY = Number(card.y || card.entryVisibleY || 0);
            card.entryStartX = Number(card.x || card.entryStartX || 0);
        });
    }

    function refreshBubblePromptCardPositions(run, now) {
        const activeCards = [];
        const positionedCards = [];
        run.cards.forEach(function (card) {
            if (!card || card.exploding || card.entryRevealMs > 0) {
                return;
            }

            const motionStrength = card.resolvedFalling ? 0.32 : 1;
            const offsets = getRelativeBubbleFloatOffsets(card, now, motionStrength);
            card._bubbleFrameOffsets = offsets;
            setFloatingBodyPosition(
                run,
                card,
                Number(card.bubbleBaseX || card.entryVisibleX || card.x) + offsets.x,
                Number(card.bubbleBaseY || card.entryVisibleY || card.y) + offsets.y,
                {
                    clampX: !card.resolvedFalling,
                    clampY: false
                }
            );

            if (!card.resolvedFalling) {
                activeCards.push(card);
            }
            positionedCards.push(card);
        });

        resolveFloatingBubbleOverlaps(run, activeCards, 12);
        positionedCards.forEach(function (card) {
            persistFloatingBodyBasePosition(card, card._bubbleFrameOffsets, 'bubbleBaseX', 'bubbleBaseY');
            delete card._bubbleFrameOffsets;
        });
    }

    function getDecorativeBubbleTargetCount(run) {
        return run.width < 480 ? 9 : 13;
    }

    function createDecorativeBubble(run, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const radius = randomBetween(10, 23);
        const bounds = getBubbleMovementBounds(run, radius);
        const bubble = {
            id: toInt(run.decorativeBubbleIdCounter) + 1,
            radius: radius,
            baseX: clamp(Number(opts.baseX) || randomBetween(bounds.minX, bounds.maxX), bounds.minX, bounds.maxX),
            baseY: isFinite(Number(opts.baseY)) ? Number(opts.baseY) : randomBetween(bounds.minY, run.height + radius),
            x: 0,
            y: 0,
            speed: randomBetween(6, 15),
            wanderVelocityX: (Math.random() < 0.5 ? -1 : 1) * randomBetween(1.5, 4.5),
            driftXAmplitudePrimary: randomBetween(2.5, 7),
            driftXAmplitudeSecondary: randomBetween(1, 4),
            driftYAmplitudePrimary: randomBetween(1.5, 5.5),
            driftYAmplitudeSecondary: randomBetween(0.8, 3),
            driftHzXPrimary: randomBetween(0.06, 0.14),
            driftHzXSecondary: randomBetween(0.14, 0.24),
            driftHzYPrimary: randomBetween(0.08, 0.18),
            driftHzYSecondary: randomBetween(0.16, 0.28),
            driftPhaseXPrimary: randomBetween(0, Math.PI * 2),
            driftPhaseXSecondary: randomBetween(0, Math.PI * 2),
            driftPhaseYPrimary: randomBetween(0, Math.PI * 2),
            driftPhaseYSecondary: randomBetween(0, Math.PI * 2),
            bubbleImpulseVelocityX: 0,
            bubbleImpulseVelocityY: 0,
            alpha: randomBetween(0.14, 0.28),
            exploding: false,
            removeAt: 0
        };
        run.decorativeBubbleIdCounter = bubble.id;
        setFloatingBodyPosition(run, bubble, bubble.baseX, bubble.baseY, {
            clampY: false
        });
        return bubble;
    }

    function seedDecorativeBubbles(run) {
        if (!run) {
            return;
        }
        if (!Array.isArray(run.decorativeBubbles)) {
            run.decorativeBubbles = [];
        }
        const targetCount = getDecorativeBubbleTargetCount(run);
        while (run.decorativeBubbles.length < targetCount) {
            run.decorativeBubbles.push(createDecorativeBubble(run));
        }
    }

    function updateDecorativeBubblePositions(run, now, dt) {
        if (!run) {
            return;
        }

        run.decorativeBubbles.forEach(function (bubble) {
            if (!bubble || bubble.exploding) {
                return;
            }

            const bounds = getBubbleMovementBounds(run, bubble.radius);
            const impulseDamping = Math.max(0, 1 - (5.2 * dt));
            bubble.bubbleImpulseVelocityX = (Number(bubble.bubbleImpulseVelocityX) || 0) * impulseDamping;
            bubble.bubbleImpulseVelocityY = (Number(bubble.bubbleImpulseVelocityY) || 0) * impulseDamping;
            const velocity = clampVectorMagnitude(
                Number(bubble.wanderVelocityX) + (Number(bubble.bubbleImpulseVelocityX) || 0),
                -bubble.speed + (Number(bubble.bubbleImpulseVelocityY) || 0),
                BUBBLE_DECORATIVE_MAX_SPEED
            );
            bubble.baseY += (velocity.y * dt);
            bubble.baseX += (velocity.x * dt);
            if (bubble.baseX <= bounds.minX || bubble.baseX >= bounds.maxX) {
                bubble.wanderVelocityX *= -1;
                bubble.baseX = clamp(bubble.baseX, bounds.minX, bounds.maxX);
            }

            const offsets = {
                x: (
                    Math.sin((now / 1000 * bubble.driftHzXPrimary * Math.PI * 2) + bubble.driftPhaseXPrimary) * bubble.driftXAmplitudePrimary
                ) + (
                    Math.sin((now / 1000 * bubble.driftHzXSecondary * Math.PI * 2) + bubble.driftPhaseXSecondary) * bubble.driftXAmplitudeSecondary
                ),
                y: (
                    Math.sin((now / 1000 * bubble.driftHzYPrimary * Math.PI * 2) + bubble.driftPhaseYPrimary) * bubble.driftYAmplitudePrimary
                ) + (
                    Math.sin((now / 1000 * bubble.driftHzYSecondary * Math.PI * 2) + bubble.driftPhaseYSecondary) * bubble.driftYAmplitudeSecondary
                )
            };

            bubble._driftFrameOffsets = offsets;
            setFloatingBodyPosition(run, bubble, bubble.baseX + offsets.x, bubble.baseY + offsets.y, {
                clampY: false
            });
        });

        const floatingBubbles = run.decorativeBubbles.filter(function (bubble) {
            return bubble && !bubble.exploding;
        });
        resolveFloatingBubbleOverlaps(run, floatingBubbles, 6);
        floatingBubbles.forEach(function (bubble) {
            persistFloatingBodyBasePosition(bubble, bubble._driftFrameOffsets, 'baseX', 'baseY');
            delete bubble._driftFrameOffsets;
        });

        run.decorativeBubbles = run.decorativeBubbles.filter(function (bubble) {
            if (!bubble) {
                return false;
            }
            if (bubble.exploding) {
                return now < bubble.removeAt;
            }
            return (bubble.y + bubble.radius) > (-bubble.radius * 1.8);
        });
        seedDecorativeBubbles(run);
    }

    function renderDecorativeBubbles(ctx, run, now) {
        const context = ctx.canvasContext;
        run.decorativeBubbles.forEach(function (bubble) {
            if (!bubble) {
                return;
            }

            const exploding = !!bubble.exploding && now < bubble.removeAt;
            const duration = Math.max(140, toInt(bubble.explosionDuration) || 180);
            const progress = exploding ? clamp(1 - ((bubble.removeAt - now) / duration), 0, 1) : 0;

            context.save();
            context.translate(Number(bubble.x) || 0, Number(bubble.y) || 0);
            context.globalAlpha = Math.max(0, Number(bubble.alpha) || 0.18) * (exploding ? (1 - progress) : 1);
            context.scale(1 + (progress * 0.28), 1 + (progress * 0.28));

            const gradient = context.createRadialGradient(-bubble.radius * 0.32, -bubble.radius * 0.36, bubble.radius * 0.08, 0, 0, bubble.radius);
            gradient.addColorStop(0, 'rgba(255,255,255,0.72)');
            gradient.addColorStop(0.34, 'rgba(255,255,255,0.26)');
            gradient.addColorStop(1, 'rgba(255,255,255,0.04)');
            context.fillStyle = gradient;
            context.beginPath();
            context.arc(0, 0, bubble.radius, 0, Math.PI * 2);
            context.fill();

            context.strokeStyle = 'rgba(255,255,255,0.42)';
            context.lineWidth = 1.2;
            context.beginPath();
            context.arc(0, 0, bubble.radius, 0, Math.PI * 2);
            context.stroke();

            context.strokeStyle = 'rgba(255,255,255,0.34)';
            context.lineWidth = 0.9;
            context.beginPath();
            context.arc(-bubble.radius * 0.2, -bubble.radius * 0.24, bubble.radius * 0.24, Math.PI * 1.08, Math.PI * 1.86);
            context.stroke();
            context.restore();
        });
    }

    function popDecorativeBubble(run, bubble) {
        if (!run || !bubble || bubble.exploding) {
            return false;
        }

        const now = currentTimestamp();
        bubble.exploding = true;
        bubble.explosionDuration = 180;
        bubble.removeAt = now + 180;
        spawnExplosion(run, {
            x: bubble.x,
            y: bubble.y,
            radius: bubble.radius * 2.1,
            primaryColor: 'rgba(233, 248, 255, 0.88)',
            secondaryColor: 'rgba(154, 221, 255, 0.78)',
            duration: 220,
            style: 'bubble-pop',
            rayCount: 8
        });
        applyBubbleBlastImpulse(run, bubble.x, bubble.y, bubble.radius, bubble);
        return true;
    }

    function renderBubbleCards(ctx, run, now) {
        const context = ctx.canvasContext;
        run.cards.forEach(function (card) {
            const ambientMotion = (!card.exploding && !card.resolvedFalling)
                ? getCardAmbientMotion(card, now)
                : { bobY: 0, tilt: 0 };
            const exploding = !!card.exploding && now < card.removeAt;
            const explosionDuration = Math.max(180, toInt(card.explosionDuration) || 240);
            const progress = exploding ? clamp(1 - ((card.removeAt - now) / explosionDuration), 0, 1) : 0;
            const radius = getBubbleRadius(card);
            const renderX = card.x;
            const renderY = card.y;
            const image = loadWordImage(ctx, card.word);
            const isCorrectPop = card.explosionStyle === 'bubble-correct';
            const isWrongBurst = card.explosionStyle === 'bubble-wrong';

            context.save();
            context.translate(renderX, renderY);

            if (exploding) {
                context.globalAlpha = clamp(1 - progress, 0, 1);
            }
            if (isWrongBurst) {
                context.rotate((Math.sin(progress * 24) * 0.08) + (progress * 0.24));
                context.scale(1 + (progress * 0.52), 1 + (progress * 0.52));
            } else if (isCorrectPop) {
                context.scale(1 + (progress * 0.18), 1 + (progress * 0.18));
            } else if (!card.resolvedFalling) {
                context.rotate(ambientMotion.tilt * 0.32);
            }

            context.shadowColor = 'rgba(15, 23, 42, 0.22)';
            context.shadowBlur = 18;
            context.shadowOffsetY = 10;

            const bubbleGradient = context.createRadialGradient(-radius * 0.34, -radius * 0.4, radius * 0.12, 0, 0, radius);
            bubbleGradient.addColorStop(0, 'rgba(255,255,255,0.92)');
            bubbleGradient.addColorStop(0.22, 'rgba(244, 252, 255, 0.76)');
            bubbleGradient.addColorStop(0.68, 'rgba(191, 231, 255, 0.28)');
            bubbleGradient.addColorStop(1, 'rgba(125, 211, 252, 0.14)');
            context.fillStyle = bubbleGradient;
            context.beginPath();
            context.arc(0, 0, radius, 0, Math.PI * 2);
            context.fill();

            context.shadowBlur = 0;
            context.shadowOffsetY = 0;

            context.save();
            context.beginPath();
            context.arc(0, 0, radius * 0.84, 0, Math.PI * 2);
            context.clip();
            // Non-square images letterbox inside the bubble; white keeps common photo
            // backgrounds looking like a natural extension of the source image.
            context.fillStyle = '#FFFFFF';
            context.fillRect(-radius, -radius, radius * 2, radius * 2);
            if (!drawImageContain(context, image, -radius * 0.84, -radius * 0.84, radius * 1.68, radius * 1.68)) {
                const placeholder = context.createLinearGradient(-radius, -radius, radius, radius);
                placeholder.addColorStop(0, '#F5FCFF');
                placeholder.addColorStop(1, '#DDEFFD');
                context.fillStyle = placeholder;
                context.fillRect(-radius, -radius, radius * 2, radius * 2);
                context.fillStyle = 'rgba(15, 23, 42, 0.48)';
                context.font = '700 28px Georgia, serif';
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.fillText('●', 0, 0);
            }
            context.restore();

            context.strokeStyle = 'rgba(255,255,255,0.82)';
            context.lineWidth = 2;
            context.beginPath();
            context.arc(0, 0, radius, 0, Math.PI * 2);
            context.stroke();

            context.strokeStyle = 'rgba(191, 219, 254, 0.7)';
            context.lineWidth = 1.2;
            context.beginPath();
            context.arc(-radius * 0.22, -radius * 0.26, radius * 0.26, Math.PI * 1.08, Math.PI * 1.86);
            context.stroke();

            context.fillStyle = 'rgba(255,255,255,0.54)';
            context.beginPath();
            context.arc(-radius * 0.34, -radius * 0.38, radius * 0.12, 0, Math.PI * 2);
            context.fill();

            if (exploding && isCorrectPop) {
                context.strokeStyle = 'rgba(154, 221, 255, 0.96)';
                context.lineWidth = 3.8;
                context.beginPath();
                context.arc(0, 0, radius * (0.68 + (progress * 0.92)), 0, Math.PI * 2);
                context.stroke();

                context.strokeStyle = 'rgba(255,255,255,0.88)';
                context.lineWidth = 2;
                context.beginPath();
                context.arc(0, 0, radius * (0.42 + (progress * 0.62)), 0, Math.PI * 2);
                context.stroke();
            } else if (exploding && isWrongBurst) {
                const rayCount = 12;
                context.strokeStyle = 'rgba(255, 134, 105, 0.96)';
                context.lineWidth = 3.4;
                for (let index = 0; index < rayCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / rayCount;
                    const innerRadius = radius * 0.42;
                    const outerRadius = radius * (0.92 + (progress * 1.18) + ((index % 2) * 0.14));
                    context.beginPath();
                    context.moveTo(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius);
                    context.lineTo(Math.cos(angle) * outerRadius, Math.sin(angle) * outerRadius);
                    context.stroke();
                }

                context.strokeStyle = 'rgba(255, 241, 236, 0.84)';
                context.lineWidth = 2.2;
                context.beginPath();
                context.arc(0, 0, radius * (0.58 + (progress * 0.9)), 0, Math.PI * 2);
                context.stroke();
            }
            context.restore();
        });
    }

    function renderCards(ctx, run, now) {
        if (isBubblePopRun(ctx, run)) {
            renderBubbleCards(ctx, run, now);
            return;
        }
        const context = ctx.canvasContext;
        run.cards.forEach(function (card) {
            const isSpeakingStackCard = isSpeakingStackRun(ctx, run);
            const ambientMotion = (!isSpeakingStackCard && !card.exploding && !card.resolvedFalling)
                ? getCardAmbientMotion(card, now)
                : { bobY: 0, tilt: 0 };
            const renderX = card.x;
            const renderY = card.y + ambientMotion.bobY;
            const left = renderX - (card.width / 2);
            const top = renderY - (card.height / 2);
            const exploding = !!card.exploding && now < card.removeAt;
            const explosionDuration = Math.max(180, toInt(card.explosionDuration) || (card.explosionStyle === 'dramatic' ? 320 : 220));
            const progress = exploding ? clamp(1 - ((card.removeAt - now) / explosionDuration), 0, 1) : 0;
            const dramatic = card.explosionStyle === 'dramatic';

            context.save();
            if (exploding) {
                context.globalAlpha = clamp(1 - progress, 0, 1);
            }
            if (dramatic) {
                const wobble = Math.sin(progress * 34) * (1 - progress) * 12;
                context.translate(renderX, renderY);
                context.rotate((Math.sin(progress * 28) * 0.08) + (progress * 0.28));
                context.scale(1 + (progress * 0.42), 1 + (progress * 0.36));
                context.translate(-renderX + wobble, -renderY);
            } else if (isSpeakingStackCard) {
                context.translate(renderX, renderY);
                context.rotate(Number(card.stackRotation) || 0);
                context.translate(-renderX, -renderY);
            } else if (!card.resolvedFalling) {
                context.translate(renderX, renderY);
                context.rotate(ambientMotion.tilt);
                context.translate(-renderX, -renderY);
            } else if (card.resolvedFalling) {
                context.translate(renderX, renderY);
                context.rotate((card.laneIndex % 2 === 0 ? -1 : 1) * 0.045);
                context.translate(-renderX, -renderY);
            }
            context.shadowColor = 'rgba(15, 23, 42, 0.22)';
            context.shadowBlur = 16;
            context.shadowOffsetY = 10;
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.fillStyle = '#FFFFFF';
            context.fill();
            context.shadowBlur = 0;
            context.shadowOffsetY = 0;

            const image = loadWordImage(ctx, card.word);
            context.save();
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.clip();
            if (!drawImageContain(context, image, left, top, card.width, card.height)) {
                const placeholder = context.createLinearGradient(left, top, left + card.width, top + card.height);
                placeholder.addColorStop(0, '#DFF7FF');
                placeholder.addColorStop(1, '#C7F0EB');
                context.fillStyle = placeholder;
                context.fillRect(left, top, card.width, card.height);
                context.fillStyle = 'rgba(15, 23, 42, 0.42)';
                context.font = '700 28px Georgia, serif';
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.fillText('✦', renderX, renderY);
            }
            context.restore();

            context.strokeStyle = 'rgba(15, 23, 42, 0.08)';
            context.lineWidth = 1.5;
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.stroke();

            if (dramatic) {
                const flash = context.createRadialGradient(renderX, renderY, card.width * 0.08, renderX, renderY, card.width * 0.7);
                flash.addColorStop(0, 'rgba(255,255,255,0.92)');
                flash.addColorStop(0.38, 'rgba(251, 191, 36, 0.78)');
                flash.addColorStop(1, 'rgba(248, 113, 113, 0)');
                context.globalCompositeOperation = 'screen';
                context.fillStyle = flash;
                context.fillRect(left, top, card.width, card.height);
                context.globalCompositeOperation = 'source-over';

                context.fillStyle = 'rgba(127, 29, 29, 0.18)';
                drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
                context.fill();

                context.strokeStyle = 'rgba(255, 241, 242, 0.82)';
                context.lineWidth = 2.4;
                context.beginPath();
                context.moveTo(left + (card.width * 0.18), top + (card.height * 0.16));
                context.lineTo(left + (card.width * 0.72), top + (card.height * 0.38));
                context.lineTo(left + (card.width * 0.46), top + (card.height * 0.8));
                context.stroke();
            }

            if (exploding) {
                context.strokeStyle = card.isTarget ? 'rgba(16, 185, 129, 0.9)' : 'rgba(248, 113, 113, 0.92)';
                context.lineWidth = dramatic ? 4 : 3;
                if (dramatic) {
                    context.beginPath();
                    context.arc(renderX, renderY, (Math.max(card.width, card.height) * 0.26) + (progress * Math.max(card.width, card.height) * 1.02), 0, Math.PI * 2);
                    context.stroke();

                    context.strokeStyle = 'rgba(255, 228, 230, 0.7)';
                    context.lineWidth = 2.2;
                    context.beginPath();
                    context.arc(renderX, renderY, (Math.max(card.width, card.height) * 0.12) + (progress * Math.max(card.width, card.height) * 0.68), 0, Math.PI * 2);
                    context.stroke();
                } else {
                    context.beginPath();
                    context.arc(renderX, renderY, (Math.max(card.width, card.height) * 0.2) + (progress * Math.max(card.width, card.height) * 0.55), 0, Math.PI * 2);
                    context.stroke();
                }
            }
            context.restore();
        });
    }

    function renderExplosions(ctx, run, now) {
        const context = ctx.canvasContext;
        run.explosions.forEach(function (explosion) {
            const progress = clamp((now - explosion.startedAt) / explosion.duration, 0, 1);
            const radius = explosion.radius * (0.28 + (progress * 0.9));
            context.save();
            context.translate(explosion.x, explosion.y);
            context.rotate(progress * (Number(explosion.spin) || 0) * Math.PI * 6);
            context.globalAlpha = 1 - progress;

            if (explosion.style === 'bubble-pop') {
                context.strokeStyle = String(explosion.primaryColor || 'rgba(154, 221, 255, 0.94)');
                context.lineWidth = 3.6 - (progress * 1.4);
                context.beginPath();
                context.arc(0, 0, radius * 0.92, 0, Math.PI * 2);
                context.stroke();

                context.strokeStyle = String(explosion.secondaryColor || 'rgba(255,255,255,0.88)');
                context.lineWidth = 1.8;
                context.beginPath();
                context.arc(0, 0, radius * 0.56, 0, Math.PI * 2);
                context.stroke();

                context.fillStyle = String(explosion.secondaryColor || 'rgba(255,255,255,0.82)');
                const dropletCount = Math.max(6, toInt(explosion.rayCount) || 8);
                for (let index = 0; index < dropletCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / dropletCount;
                    const dotRadius = Math.max(1.6, radius * 0.08 * (1 - (progress * 0.3)));
                    const offset = radius * (0.46 + (progress * 0.62));
                    context.beginPath();
                    context.arc(Math.cos(angle) * offset, Math.sin(angle) * offset, dotRadius, 0, Math.PI * 2);
                    context.fill();
                }
            } else if (explosion.style === 'bubble-ray-burst') {
                const rayCount = Math.max(10, toInt(explosion.rayCount) || 14);
                context.strokeStyle = String(explosion.primaryColor || 'rgba(255, 134, 105, 0.96)');
                context.lineWidth = 2.8 + ((1 - progress) * 3.4);
                for (let index = 0; index < rayCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / rayCount;
                    const innerRadius = radius * 0.14;
                    const outerRadius = radius * (1 + ((index % 2) * 0.18));
                    context.beginPath();
                    context.moveTo(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius);
                    context.lineTo(Math.cos(angle) * outerRadius, Math.sin(angle) * outerRadius);
                    context.stroke();
                }

                context.strokeStyle = String(explosion.secondaryColor || 'rgba(255, 241, 236, 0.84)');
                context.lineWidth = 2.2 + ((1 - progress) * 1.4);
                context.beginPath();
                context.arc(0, 0, radius * 0.72, 0, Math.PI * 2);
                context.stroke();
            } else if (explosion.style === 'burst') {
                const rayCount = Math.max(6, toInt(explosion.rayCount) || 10);
                context.strokeStyle = String(explosion.primaryColor || 'rgba(248, 113, 113, 0.96)');
                context.lineWidth = 2.4 + ((1 - progress) * 3.8);
                for (let index = 0; index < rayCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / rayCount;
                    const innerRadius = radius * 0.18;
                    const outerRadius = radius * (1.02 + ((index % 2) * 0.18));
                    context.beginPath();
                    context.moveTo(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius);
                    context.lineTo(Math.cos(angle) * outerRadius, Math.sin(angle) * outerRadius);
                    context.stroke();
                }

                context.fillStyle = String(explosion.secondaryColor || 'rgba(251, 191, 36, 0.88)');
                context.beginPath();
                context.arc(0, 0, radius * 0.24, 0, Math.PI * 2);
                context.fill();

                context.strokeStyle = String(explosion.secondaryColor || 'rgba(255,255,255,0.85)');
                context.lineWidth = 2 + ((1 - progress) * 2.4);
                context.beginPath();
                context.arc(0, 0, radius * 0.62, 0, Math.PI * 2);
                context.stroke();
            }

            if (explosion.style !== 'bubble-pop' && explosion.style !== 'bubble-ray-burst') {
                context.strokeStyle = String(explosion.primaryColor || explosion.color || 'rgba(255,255,255,0.9)');
                context.lineWidth = 2 + ((1 - progress) * (explosion.style === 'burst' ? 3.4 : 4));
                context.beginPath();
                context.arc(0, 0, radius, 0, Math.PI * 2);
                context.stroke();
            }
            context.restore();
        });
    }

    function renderRun(ctx, now) {
        const run = ctx.run;
        if (!run || !ctx.canvasContext) {
            return;
        }
        renderBackground(ctx, run);
        if (isBubblePopRun(ctx, run)) {
            renderDecorativeBubbles(ctx, run, now);
        }
        renderCards(ctx, run, now);
        renderBullets(ctx, run);
        renderExplosions(ctx, run, now);
        renderShip(ctx, run);
    }

    function spawnExplosion(run, options, y, color, radius) {
        const config = (options && typeof options === 'object')
            ? options
            : {
                x: options,
                y: y,
                primaryColor: color,
                radius: radius
            };
        run.explosions.push({
            x: Number(config.x) || 0,
            y: Number(config.y) || 0,
            color: String(config.primaryColor || config.color || 'rgba(255,255,255,0.9)'),
            primaryColor: String(config.primaryColor || config.color || 'rgba(255,255,255,0.9)'),
            secondaryColor: String(config.secondaryColor || ''),
            radius: Math.max(8, Number(config.radius) || 24),
            startedAt: currentTimestamp(),
            duration: Math.max(160, toInt(config.duration) || 280),
            style: String(config.style || 'ring'),
            rayCount: Math.max(0, toInt(config.rayCount) || 0),
            spin: Number(config.spin) || 0
        });
    }

    function createCard(run, word, laneIndex, isTarget, offsetFactor, promptId) {
        const baseOffset = Math.max(0, Number(offsetFactor) || 0);
        const dimensions = getCardDimensions(run, null);
        return {
            word: word,
            promptId: toInt(promptId),
            laneIndex: laneIndex,
            x: laneCenterX(run, laneIndex),
            y: -dimensions.height,
            width: dimensions.width,
            height: dimensions.height,
            aspectRatio: dimensions.aspectRatio,
            entryOffsetFactor: baseOffset,
            entryDepthJitter: Math.random() * 0.08,
            entryRevealMs: 0,
            entryStartedAt: 0,
            entryStartX: laneCenterX(run, laneIndex),
            entryStartY: -dimensions.height,
            entryVisibleX: laneCenterX(run, laneIndex),
            entryVisibleY: dimensions.height / 2,
            bubbleVisibleX: null,
            bubbleVisibleY: null,
            bubbleBaseX: null,
            bubbleBaseY: null,
            bubbleWanderVelocityX: 0,
            bubbleFloatAmplitudeXPrimary: 0,
            bubbleFloatAmplitudeXSecondary: 0,
            bubbleFloatAmplitudeYPrimary: 0,
            bubbleFloatAmplitudeYSecondary: 0,
            bubbleFloatHzXPrimary: 0,
            bubbleFloatHzXSecondary: 0,
            bubbleFloatHzYPrimary: 0,
            bubbleFloatHzYSecondary: 0,
            bubbleFloatPhaseXPrimary: 0,
            bubbleFloatPhaseXSecondary: 0,
            bubbleFloatPhaseYPrimary: 0,
            bubbleFloatPhaseYSecondary: 0,
            bubbleFloatReferenceX: null,
            bubbleFloatReferenceY: null,
            bubbleImpulseVelocityX: 0,
            bubbleImpulseVelocityY: 0,
            releaseDriftX: 0,
            fallSpeedFactor: isTarget ? 1 : (0.9 + (Math.random() * 0.2)),
            speed: run.cardSpeed,
            motionBobAmplitudePrimary: 3.2 + (Math.random() * 2.4),
            motionBobAmplitudeSecondary: 1.4 + (Math.random() * 1.8),
            motionBobHzPrimary: 0.11 + (Math.random() * 0.08),
            motionBobHzSecondary: 0.17 + (Math.random() * 0.11),
            motionBobPhasePrimary: Math.random() * Math.PI * 2,
            motionBobPhaseSecondary: Math.random() * Math.PI * 2,
            motionTiltAmplitudePrimary: 0.01 + (Math.random() * 0.015),
            motionTiltAmplitudeSecondary: 0.004 + (Math.random() * 0.01),
            motionTiltHzPrimary: 0.08 + (Math.random() * 0.06),
            motionTiltHzSecondary: 0.13 + (Math.random() * 0.08),
            motionTiltPhasePrimary: Math.random() * Math.PI * 2,
            motionTiltPhaseSecondary: Math.random() * Math.PI * 2,
            isTarget: !!isTarget,
            resolvedFalling: false,
            exploding: false,
            explosionStyle: '',
            removeAt: 0
        };
    }

    function getSpeakingStackGroundTop(ctx, run) {
        const gameConfig = getGameConfig(ctx, run) || {};
        return Math.max(80, run.height - Math.max(12, toInt(gameConfig.groundPaddingPx) || 34));
    }

    function getSpeakingStackTopDangerY(ctx, run) {
        const gameConfig = getGameConfig(ctx, run) || {};
        return Math.max(0, toInt(gameConfig.topDangerPaddingPx) || 14);
    }

    function getSpeakingStackPlacedCards(run) {
        return (Array.isArray(run && run.cards) ? run.cards : [])
            .filter(function (card) {
                return !!card
                    && !card.removedFromStack
                    && !card.exploding
                    && isFinite(Number(card.stackTargetX))
                    && isFinite(Number(card.stackTargetY));
            });
    }

    function getSpeakingStackHorizontalBounds(run, cardWidth) {
        const width = Math.max(1, Number(cardWidth) || Number(run && run.metrics && run.metrics.cardWidth) || 96);
        const halfWidth = width / 2;
        const margin = Math.max(18, (Number(run && run.width) || 0) * 0.045);
        return {
            minX: margin + halfWidth,
            maxX: Math.max(margin + halfWidth, (Number(run && run.width) || 0) - margin - halfWidth)
        };
    }

    function getSpeakingStackReferenceY(card) {
        if (isFinite(Number(card && card.stackTargetY))) {
            return Number(card.stackTargetY);
        }
        if (isFinite(Number(card && card.lastSettledY))) {
            return Number(card.lastSettledY);
        }
        return Number(card && card.y) || 0;
    }

    function getSpeakingStackCardSortValue(card) {
        return getSpeakingStackReferenceY(card);
    }

    function getSpeakingStackIndex(value) {
        const parsed = parseInt(value, 10);
        return parsed >= 0 ? parsed : -1;
    }

    function sortSpeakingStackCardsBottomFirst(left, right) {
        const rightY = getSpeakingStackCardSortValue(right);
        const leftY = getSpeakingStackCardSortValue(left);
        if (Math.abs(rightY - leftY) > 0.5) {
            return rightY - leftY;
        }
        return toInt(left && left.promptId) - toInt(right && right.promptId);
    }

    function getSpeakingStackSlotSpacing(run, cardWidth) {
        const width = Math.max(1, Number(cardWidth) || Number(run && run.metrics && run.metrics.cardWidth) || 96);
        return width + Math.max(14, Math.round(width * 0.12));
    }

    function getSpeakingStackSlotLayout(run) {
        const baseCardWidth = Math.max(1, Number(run && run.metrics && run.metrics.cardWidth) || 96);
        const bounds = getSpeakingStackHorizontalBounds(run, baseCardWidth);
        const preferredSlotCount = Math.max(2, toInt(run && run.cardCount) || 4);
        const slotSpacing = getSpeakingStackSlotSpacing(run, baseCardWidth);
        const usableWidth = Math.max(0, bounds.maxX - bounds.minX);
        const maxSlotCount = usableWidth > 0
            ? Math.max(1, Math.floor(usableWidth / Math.max(1, slotSpacing)) + 1)
            : 1;
        const slotCount = Math.max(1, Math.min(preferredSlotCount, maxSlotCount));
        const slotCenters = [];

        if (slotCount === 1) {
            slotCenters.push((bounds.minX + bounds.maxX) / 2);
        } else {
            const step = usableWidth / Math.max(1, slotCount - 1);
            for (let index = 0; index < slotCount; index += 1) {
                slotCenters.push(bounds.minX + (step * index));
            }
        }

        return {
            bounds: bounds,
            slotCount: slotCount,
            slotCenters: slotCenters,
            slotSpacing: slotCount > 1 ? (usableWidth / Math.max(1, slotCount - 1)) : usableWidth,
            centerIndex: (slotCount - 1) / 2
        };
    }

    function getSpeakingStackSlotIndexForX(layout, x) {
        const centers = Array.isArray(layout && layout.slotCenters) ? layout.slotCenters : [];
        if (!centers.length) {
            return 0;
        }

        const targetX = Number(x);
        if (!isFinite(targetX)) {
            return Math.max(0, Math.min(centers.length - 1, Math.round(Number(layout && layout.centerIndex) || 0)));
        }

        let bestIndex = 0;
        let bestDistance = Math.abs(targetX - Number(centers[0] || 0));
        for (let index = 1; index < centers.length; index += 1) {
            const nextDistance = Math.abs(targetX - Number(centers[index] || 0));
            if (nextDistance < bestDistance) {
                bestDistance = nextDistance;
                bestIndex = index;
            }
        }
        return bestIndex;
    }

    function getSpeakingStackResolvedSlotIndex(run, card, layout) {
        const explicitIndex = getSpeakingStackIndex(card && card.stackSlotIndex);
        if (explicitIndex >= 0 && explicitIndex < Math.max(1, Number(layout && layout.slotCount) || 0)) {
            return explicitIndex;
        }

        const referenceX = isFinite(Number(card && card.stackTargetX))
            ? Number(card.stackTargetX)
            : (isFinite(Number(card && card.lastSettledX))
                ? Number(card.lastSettledX)
                : Number(card && card.x));

        return getSpeakingStackSlotIndexForX(layout, referenceX);
    }

    function createSpeakingStackSlotStates(ctx, run, layout) {
        const groundTop = getSpeakingStackGroundTop(ctx, run);
        const slotCenters = Array.isArray(layout && layout.slotCenters) ? layout.slotCenters : [];

        return slotCenters.map(function (centerX, index) {
            return {
                index: index,
                x: Number(centerX) || 0,
                cardCount: 0,
                nextSurfaceY: groundTop,
                stackHeight: 0
            };
        });
    }

    function occupySpeakingStackSlot(ctx, run, slotState, card) {
        const gameConfig = getGameConfig(ctx, run) || {};
        const gap = Math.max(0, toInt(gameConfig.stackGapPx) || 12);
        const groundTop = getSpeakingStackGroundTop(ctx, run);
        const cardHeight = Math.max(1, Number(card && card.height) || 1);
        const targetY = (Number(slotState && slotState.nextSurfaceY) || groundTop) - (cardHeight / 2);

        slotState.cardCount += 1;
        slotState.nextSurfaceY = targetY - (cardHeight / 2) - gap;
        slotState.stackHeight = Math.max(0, groundTop - slotState.nextSurfaceY);

        return targetY;
    }

    function buildSpeakingStackSlotStates(ctx, run, cards, layout) {
        const slotLayout = layout || getSpeakingStackSlotLayout(run);
        const slotStates = createSpeakingStackSlotStates(ctx, run, slotLayout);
        const orderedCards = (Array.isArray(cards) ? cards : []).slice().sort(sortSpeakingStackCardsBottomFirst);

        orderedCards.forEach(function (card) {
            if (!card) {
                return;
            }

            const slotIndex = getSpeakingStackResolvedSlotIndex(run, card, slotLayout);
            const slotState = slotStates[slotIndex] || slotStates[0];
            if (!slotState) {
                return;
            }

            card.stackSlotIndex = slotIndex;
            occupySpeakingStackSlot(ctx, run, slotState, card);
        });

        return slotStates;
    }

    function relayoutSpeakingStackCards(ctx, run, options) {
        if (!run) {
            return;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const layout = getSpeakingStackSlotLayout(run);
        const slotStates = createSpeakingStackSlotStates(ctx, run, layout);
        const activeCards = getSpeakingStackActiveCards(run).slice().sort(sortSpeakingStackCardsBottomFirst);

        activeCards.forEach(function (card) {
            if (!card) {
                return;
            }

            const slotIndex = getSpeakingStackResolvedSlotIndex(run, card, layout);
            const slotState = slotStates[slotIndex] || slotStates[0];
            if (!slotState) {
                return;
            }

            const targetX = Number(slotState.x) || 0;
            const previousTargetX = isFinite(Number(card.stackTargetX))
                ? Number(card.stackTargetX)
                : (Number(card.x) || targetX);
            const previousTargetY = isFinite(Number(card.stackTargetY))
                ? Number(card.stackTargetY)
                : getSpeakingStackReferenceY(card);
            const nextTargetY = occupySpeakingStackSlot(ctx, run, slotState, card);

            card.stackSlotIndex = slotIndex;
            card.stackTargetX = targetX;
            card.stackTargetY = nextTargetY;
            if (!isFinite(Number(card.stackRotation))) {
                card.stackRotation = 0;
            }

            if (opts.instant) {
                card.x = targetX;
                card.y = nextTargetY;
                card.lastSettledX = targetX;
                card.lastSettledY = nextTargetY;
            } else if (
                Math.abs(targetX - previousTargetX) > 0.5
                || Math.abs(nextTargetY - previousTargetY) > 0.5
            ) {
                card.entryStartX = Number(card.x) || previousTargetX;
                card.entryStartY = Number(card.y) || previousTargetY;
            }
        });
    }

    function chooseSpeakingStackPlacement(ctx, run, card) {
        const layout = getSpeakingStackSlotLayout(run);
        const slotStates = buildSpeakingStackSlotStates(ctx, run, getSpeakingStackPlacedCards(run), layout);
        const previousSlotIndex = getSpeakingStackIndex(run && run.lastPlacementSlotIndex);
        const preferredSlotIndex = previousSlotIndex >= 0 && previousSlotIndex < Math.max(1, Number(layout && layout.slotCount) || 0)
            ? previousSlotIndex
            : Math.max(0, Math.min(Math.max(0, (Number(layout && layout.slotCount) || 1) - 1), Math.round(Number(layout && layout.centerIndex) || 0)));
        const emptySlots = slotStates.filter(function (slotState) {
            return (Number(slotState && slotState.cardCount) || 0) === 0;
        });
        const candidateSlots = emptySlots.length ? emptySlots : slotStates.slice();

        candidateSlots.sort(function (left, right) {
            if (!emptySlots.length && Math.abs(Number(left && left.stackHeight) - Number(right && right.stackHeight)) > 0.5) {
                return Number(left && left.stackHeight) - Number(right && right.stackHeight);
            }
            if (!emptySlots.length && (Number(left && left.cardCount) || 0) !== (Number(right && right.cardCount) || 0)) {
                return (Number(left && left.cardCount) || 0) - (Number(right && right.cardCount) || 0);
            }

            const leftPreferredDistance = Math.abs(getSpeakingStackIndex(left && left.index) - preferredSlotIndex);
            const rightPreferredDistance = Math.abs(getSpeakingStackIndex(right && right.index) - preferredSlotIndex);
            if (leftPreferredDistance !== rightPreferredDistance) {
                return leftPreferredDistance - rightPreferredDistance;
            }

            const leftCenterDistance = Math.abs(Number(left && left.index) - Number(layout && layout.centerIndex));
            const rightCenterDistance = Math.abs(Number(right && right.index) - Number(layout && layout.centerIndex));
            if (Math.abs(leftCenterDistance - rightCenterDistance) > 0.01) {
                return leftCenterDistance - rightCenterDistance;
            }

            return getSpeakingStackIndex(left && left.index) - getSpeakingStackIndex(right && right.index);
        });

        const best = candidateSlots[0] || slotStates[0];
        if (!best) {
            const fallbackX = Number(run && run.width) / 2;
            const fallbackY = getSpeakingStackGroundTop(ctx, run) - ((Number(card && card.height) || 0) / 2);
            return {
                slotIndex: 0,
                x: fallbackX,
                y: fallbackY,
                rotation: 0
            };
        }

        return {
            slotIndex: getSpeakingStackIndex(best.index),
            x: Number(best.x) || 0,
            y: occupySpeakingStackSlot(ctx, run, $.extend({}, best), card),
            rotation: randomBetween(-0.018, 0.018)
        };
    }

    function getSpeakingStackProgressRatio(run) {
        if (!run) {
            return 0;
        }

        return clamp(
            toInt(run.clearedCount) / Math.max(1, toInt(run.totalWordCount) || 1),
            0,
            1
        );
    }

    function getSpeakingStackThinkPaddingMs(ctx, run) {
        const gameConfig = getGameConfig(ctx, run) || {};
        return Math.round(lerp(
            Math.max(900, toInt(gameConfig.thinkPaddingStartMs) || 1900),
            Math.max(700, toInt(gameConfig.thinkPaddingEndMs) || 1200),
            getSpeakingStackProgressRatio(run)
        ));
    }

    function getSpeakingStackBaseFallSpeed(ctx, run) {
        return Math.max(80, toInt((getGameConfig(ctx, run) || {}).fallSpeed) || 176);
    }

    function getSpeakingStackPreFirstAttemptFallSpeed(ctx, run) {
        const baseSpeed = getSpeakingStackBaseFallSpeed(ctx, run);
        const configuredSpeed = Math.max(
            28,
            toInt((getGameConfig(ctx, run) || {}).preFirstAttemptFallSpeed) || 64
        );

        return Math.min(baseSpeed, configuredSpeed);
    }

    function getSpeakingStackCurrentFallSpeed(ctx, run) {
        if (run && run.hasStartedFirstTranscriptionAttempt) {
            return getSpeakingStackBaseFallSpeed(ctx, run);
        }

        return getSpeakingStackPreFirstAttemptFallSpeed(ctx, run);
    }

    function syncSpeakingStackCardSpeeds(ctx, run) {
        if (!run || !Array.isArray(run.cards)) {
            return;
        }

        const fallSpeed = getSpeakingStackCurrentFallSpeed(ctx, run);
        run.cards.forEach(function (card) {
            if (!card || card.exploding) {
                return;
            }

            const speedFactor = isFinite(Number(card.stackFallSpeedFactor))
                ? Number(card.stackFallSpeedFactor)
                : 1;
            card.speed = fallSpeed * speedFactor;
        });
    }

    function getSpeakingStackSpawnGapMs(ctx, run, options) {
        const gameConfig = getGameConfig(ctx, run) || {};
        const opts = (options && typeof options === 'object') ? options : {};
        const baseGapMs = Math.max(4000, toInt(gameConfig.spawnGapMs) || 4500);
        const cycleGapMs = Math.max(
            baseGapMs,
            Math.max(0, Number(run && run.lastRecordingDurationMs) || 0)
                + Math.max(0, Number(run && run.lastTranscribeDurationMs) || 0)
                + Math.max(0, Number(run && run.lastCorrectAudioDurationMs) || 0)
                + getSpeakingStackThinkPaddingMs(ctx, run)
        );
        if (opts.initial) {
            return Math.max(cycleGapMs, Math.max(0, toInt(gameConfig.initialSpawnDelayMs) || 5200));
        }
        return cycleGapMs;
    }

    function isSpeakingStackSpawnBlocked(ctx, run, now) {
        const state = speakingState(ctx);
        if (Number(run && run.spawnHoldUntil) > Number(now || 0)) {
            return true;
        }
        if (!state) {
            return false;
        }

        return !!(
            state.transcribing
            || (
                state.mediaRecorder
                && state.mediaRecorder.state !== 'inactive'
                && state.speechDetected
            )
        );
    }

    function scheduleNextSpeakingStackSpawn(ctx, run, now, options) {
        if (!run || run.allWordsQueued) {
            return;
        }

        run.nextSpawnAt = Number(now || currentTimestamp()) + getSpeakingStackSpawnGapMs(ctx, run, options);
    }

    function spawnSpeakingStackCard(ctx, run, now) {
        if (!run || run.allWordsQueued || !Array.isArray(run.wordQueue) || !run.wordQueue.length) {
            if (run && (!Array.isArray(run.wordQueue) || !run.wordQueue.length)) {
                run.allWordsQueued = true;
                if (!run.finalSpawnedAt) {
                    run.finalSpawnedAt = Number(now) || currentTimestamp();
                }
            }
            return null;
        }

        const nextWord = run.wordQueue.shift();
        if (!nextWord) {
            run.allWordsQueued = true;
            run.finalSpawnedAt = Number(now) || currentTimestamp();
            return null;
        }

        const card = createCard(run, nextWord, 0, true, 0, 0);
        const placement = chooseSpeakingStackPlacement(ctx, run, card);
        const layout = getSpeakingStackSlotLayout(run);
        const bounds = layout.bounds;
        const maxEntryDrift = Math.min(
            Math.max(10, (Number(layout && layout.slotSpacing) || Number(card && card.width) || 96) * 0.18),
            Math.max(14, (Number(card && card.width) || 96) * 0.26)
        );
        const startX = clamp(
            placement.x + randomBetween(-maxEntryDrift, maxEntryDrift),
            bounds.minX,
            bounds.maxX
        );
        const startY = -Math.max(card.height, run.metrics && run.metrics.cardHeight ? run.metrics.cardHeight : card.height);
        card.promptId = toInt(run.spawnedWordCount) + 1;
        card.stackSlotIndex = getSpeakingStackIndex(placement.slotIndex);
        card.stackTargetX = placement.x;
        card.stackTargetY = placement.y;
        card.stackRotation = placement.rotation;
        card.removedFromStack = false;
        card.stackFallSpeedFactor = randomBetween(0.94, 1.07);
        card.speed = getSpeakingStackCurrentFallSpeed(ctx, run) * card.stackFallSpeedFactor;
        card.entryStartX = startX;
        card.entryStartY = startY;
        card.x = startX;
        card.y = startY;
        card.lastSettledX = placement.x;
        card.lastSettledY = placement.y;

        run.cards.push(card);
        run.spawnedWordCount += 1;
        run.lastSpawnedAt = Number(now) || currentTimestamp();
        run.lastPlacementX = placement.x;
        run.lastPlacementSlotIndex = getSpeakingStackIndex(placement.slotIndex);

        ensureAudioLoaded(ctx, String(nextWord && nextWord.speaking_best_correct_audio_url || '')).catch(function () {});

        queueExposureOnce(ctx, {
            target: nextWord,
            recordingType: 'isolation',
            gameSlug: SPEAKING_STACK_GAME_SLUG
        }, {
            event_source: 'speaking_stack',
            speaking_target_field: String(run.targetField || '')
        });

        if (!run.wordQueue.length) {
            run.allWordsQueued = true;
            run.finalSpawnedAt = run.lastSpawnedAt;
        }

        return card;
    }

    function getCardEntryRevealMs(ctx, card) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        const maxRevealMs = Math.max(220, toInt(gameConfig && gameConfig.cardEntryRevealMs) || 560);
        const baseOffset = Math.max(0, Number(card && card.entryOffsetFactor) || 0);
        return Math.min(maxRevealMs, 380 + Math.round(baseOffset * 150));
    }

    function getCardEntryVisibleY(ctx, run, card) {
        if (isBubblePopRun(ctx, run) && isFinite(Number(card && card.bubbleVisibleY))) {
            return Number(card.bubbleVisibleY);
        }

        const cardHeight = Math.max(1, Number(card && card.height) || 1);
        const metricsCardHeight = Math.max(cardHeight, Number(run && run.metrics && run.metrics.cardHeight) || cardHeight);
        const offsetFactor = Math.max(0, Number(card && card.entryOffsetFactor) || 0);
        const jitter = Number(card && card.entryDepthJitter || 0);
        const depthRatio = clamp(0.06 + (offsetFactor * 0.28) + jitter, 0.05, 0.38);

        if (isBubblePopRun(ctx, run)) {
            const liftDepth = Math.min(Math.max(48, run.height * 0.24), metricsCardHeight * 1.18);
            return run.height - (cardHeight / 2) - (liftDepth * depthRatio);
        }

        const maxDepth = Math.min(Math.max(40, run.height * 0.24), metricsCardHeight * 0.95);
        return (cardHeight / 2) + (maxDepth * depthRatio);
    }

    function getCardSafeLineCrossDelayMs(ctx, run, card) {
        if (!run || !card) {
            return 0;
        }

        const speed = Number(card.speed) || 0;
        if (!isFinite(speed) || speed <= 0) {
            return 0;
        }

        const gameConfig = getGameConfig(ctx, run);
        const safeLineRatio = clamp(Number(gameConfig && gameConfig.audioSafeLineRatio) || 0.6, 0.35, 0.7);
        const safeLineY = isBubblePopRun(ctx, run)
            ? Math.max(0, Number(run.height) || 0) * (1 - safeLineRatio)
            : Math.max(0, Number(run.height) || 0) * safeLineRatio;
        const visibleY = Number(card.entryVisibleY);
        const entryRevealMs = Math.max(0, toInt(card.entryRevealMs));

        if (!isFinite(visibleY)) {
            return entryRevealMs;
        }

        if (isBubblePopRun(ctx, run)) {
            if (safeLineY >= visibleY) {
                return entryRevealMs;
            }
            return entryRevealMs + Math.round(((visibleY - safeLineY) / speed) * 1000);
        }

        if (safeLineY <= visibleY) {
            return entryRevealMs;
        }

        return entryRevealMs + Math.round(((safeLineY - visibleY) / speed) * 1000);
    }

    function getPromptAutoReplayTiming(ctx, run, promptDurationMs, targetCard) {
        const gameConfig = getGameConfig(ctx, run);
        const replayGapMs = Math.max(220, toInt(gameConfig && gameConfig.promptAutoReplayGapMs) || 420);
        const baseDelayMs = Math.max(0, toInt(promptDurationMs)) + replayGapMs;
        const shortPromptLimitMs = Math.max(SHORT_PROMPT_AUTO_REPLAY_MIN_MS, replayGapMs * 3);
        const safeLineCrossDelayMs = getCardSafeLineCrossDelayMs(ctx, run, targetCard);
        const safeLineReplayDelayMs = safeLineCrossDelayMs > 0
            ? safeLineCrossDelayMs + POST_SAFE_LINE_REPLAY_BUFFER_MS
            : 0;
        const gatedBySafeLine = promptDurationMs > 0
            && promptDurationMs <= shortPromptLimitMs
            && safeLineReplayDelayMs > baseDelayMs;

        return {
            baseDelayMs: baseDelayMs,
            delayMs: gatedBySafeLine ? safeLineReplayDelayMs : baseDelayMs,
            safeLineCrossDelayMs: safeLineCrossDelayMs,
            gatedBySafeLine: gatedBySafeLine
        };
    }

    function buildCompatiblePromptWordSet(targetWord, pool, requiredCount) {
        const selected = [targetWord];

        for (let index = 0; index < pool.length; index += 1) {
            const candidate = pool[index];
            const compatible = selected.every(function (existingWord) {
                return wordsCanShareRound(existingWord, candidate);
            });
            if (!compatible) {
                continue;
            }
            selected.push(candidate);
            if (selected.length >= requiredCount) {
                break;
            }
        }

        if (selected.length < requiredCount) {
            return null;
        }

        return selected;
    }

    function selectCompatiblePromptWords(targetWord, words, requiredCount, strategy) {
        const distractorStrategy = String(strategy || 'mixed');
        const allDistractors = shuffle((Array.isArray(words) ? words : []).filter(function (word) {
            return toInt(word && word.id) !== toInt(targetWord && targetWord.id);
        }));
        let pool = allDistractors;

        if (distractorStrategy === 'same-category') {
            const sameCategory = [];
            const otherCategories = [];

            allDistractors.forEach(function (candidate) {
                if (wordsShareCategory(targetWord, candidate)) {
                    sameCategory.push(candidate);
                    return;
                }
                otherCategories.push(candidate);
            });
            pool = sameCategory.concat(otherCategories);
        }

        return buildCompatiblePromptWordSet(targetWord, pool, requiredCount);
    }

    function hasCompatibleSameCategoryDistractor(targetWord, words) {
        return (Array.isArray(words) ? words : []).some(function (candidate) {
            return toInt(candidate && candidate.id) !== toInt(targetWord && targetWord.id)
                && wordsShareCategory(targetWord, candidate)
                && wordsCanShareRound(targetWord, candidate);
        });
    }

    function selectDistractorStrategy(run, targetWord, words) {
        const sameCategoryAvailable = hasCompatibleSameCategoryDistractor(targetWord, words);
        const shouldPreferSameCategory = !!(run && run.useSameCategoryDistractorsNext);

        if (!run) {
            return sameCategoryAvailable ? 'same-category' : 'mixed';
        }

        if (!sameCategoryAvailable) {
            run.useSameCategoryDistractorsNext = true;
            return 'mixed';
        }

        run.useSameCategoryDistractorsNext = !shouldPreferSameCategory;
        return shouldPreferSameCategory ? 'same-category' : 'mixed';
    }

    function buildPromptCards(ctx, run, targetWord, words, promptId, distractorStrategy) {
        const selected = selectCompatiblePromptWords(targetWord, words, run.cardCount, distractorStrategy);
        if (!selected) {
            return null;
        }

        const shuffledWords = shuffle(selected);
        const cards = shuffledWords.map(function (word, index) {
            const card = createCard(
                run,
                word,
                index,
                toInt(word.id) === toInt(targetWord.id),
                0,
                promptId
            );
            applyCardDimensions(ctx, run, card);
            return card;
        });

        assignPromptCardDepths(cards);

        if (isBubblePopRun(ctx, run)) {
            placeBubblePromptCards(run, cards);
        }

        return cards;
    }

    function assignPromptCardDepths(cards) {
        const promptDepths = [0.1, 0.38, 0.72, 1.02];
        const depthJitterByIndex = [0.006, 0.02, 0.038, 0.056];
        const ordered = (Array.isArray(cards) ? cards.slice() : []).sort(function (left, right) {
            const leftHeight = Number(left && left.height) || 0;
            const rightHeight = Number(right && right.height) || 0;
            if (leftHeight !== rightHeight) {
                return leftHeight - rightHeight;
            }

            return toInt(left && left.word && left.word.id) - toInt(right && right.word && right.word.id);
        });

        ordered.forEach(function (card, index) {
            if (!card) {
                return;
            }

            const normalizedIndex = Math.min(index, promptDepths.length - 1);
            card.entryOffsetFactor = promptDepths[normalizedIndex];
            card.entryDepthJitter = depthJitterByIndex[normalizedIndex];
        });
    }

    function findPlayableTargets(words, cardCount) {
        return (Array.isArray(words) ? words : []).filter(function (word) {
            return !!selectCompatiblePromptWords(word, words, cardCount);
        });
    }

    function buildPreparedEntry(ctx, slug, rawEntry) {
        const entry = $.extend({}, rawEntry || {});
        const normalizedSlug = normalizeGameSlug(entry.slug || slug);
        const gameConfig = getGameConfig(ctx, slug);
        if (normalizedSlug === UNSCRAMBLE_GAME_SLUG) {
            const minimumCount = Math.max(1, toInt(entry.minimum_word_count) || ctx.minimumWordCount);
            const minTileCount = Math.max(2, toInt(entry.minimum_tile_count) || toInt(gameConfig && gameConfig.minTileCount) || 3);
            const maxTileCount = Math.max(minTileCount, toInt(entry.maximum_tile_count) || toInt(gameConfig && gameConfig.maxTileCount) || 18);
            const maxLoadedWords = Math.max(
                minimumCount,
                toInt(entry.launch_word_cap)
                    || toInt(gameConfig && gameConfig.maxLoadedWords)
                    || minimumCount
            );
            const eligibleWords = (Array.isArray(entry.words) ? entry.words : [])
                .map(normalizeWord)
                .filter(function (word) {
                    const movableCount = Math.max(
                        0,
                        toInt(word.unscramble_movable_unit_count)
                            || (Array.isArray(word.unscramble_units)
                                ? word.unscramble_units.filter(function (unit) { return !!unit.movable; }).length
                                : 0)
                    );
                    return word.id > 0
                        && String(word.unscramble_answer_text || '').trim() !== ''
                        && (String(word.unscramble_prompt_text || '').trim() !== '' || String(word.unscramble_prompt_image || '').trim() !== '')
                        && Array.isArray(word.unscramble_units)
                        && word.unscramble_units.length > 0
                        && movableCount >= minTileCount
                        && movableCount <= maxTileCount;
                });
            const words = limitLaunchWords(eligibleWords, maxLoadedWords);

            return $.extend({}, entry, {
                slug: normalizedSlug,
                words: words,
                playableTargets: words.slice(),
                available_word_count: toInt(entry.available_word_count) || eligibleWords.length,
                launch_word_cap: maxLoadedWords,
                launch_word_count: words.length,
                launchable: !!entry.launchable && words.length >= minimumCount,
                minimum_word_count: minimumCount,
                minimum_tile_count: minTileCount,
                maximum_tile_count: maxTileCount,
                category_ids: uniqueIntList(entry.category_ids || [])
            });
        }
        if (normalizedSlug === LINEUP_GAME_SLUG) {
            const minimumSequenceCount = Math.max(1, toInt(entry.minimum_sequence_count) || 1);
            const minimumSequenceLength = Math.max(
                2,
                toInt(entry.minimum_sequence_length)
                    || toInt(gameConfig && gameConfig.minimumSequenceLength)
                    || 3
            );
            const eligibleSequences = (Array.isArray(entry.sequences) ? entry.sequences : [])
                .map(normalizeLineupSequence)
                .filter(function (sequence) {
                    return Array.isArray(sequence.words) && sequence.words.length >= minimumSequenceLength;
                });
            const maxLoadedSequences = Math.max(
                minimumSequenceCount,
                toInt(entry.launch_sequence_cap)
                    || toInt(entry.launch_word_cap)
                    || toInt(gameConfig && gameConfig.maxLoadedSequences)
                    || eligibleSequences.length
                    || minimumSequenceCount
            );
            const sequences = eligibleSequences.slice(0, maxLoadedSequences);

            return $.extend({}, entry, {
                slug: normalizedSlug,
                words: [],
                playableTargets: [],
                sequences: sequences,
                available_sequence_count: toInt(entry.available_sequence_count) || eligibleSequences.length,
                available_word_count: toInt(entry.available_word_count) || eligibleSequences.length,
                launch_word_cap: maxLoadedSequences,
                launch_sequence_cap: maxLoadedSequences,
                launch_word_count: sequences.length,
                launchable: !!entry.launchable && sequences.length >= minimumSequenceCount,
                minimum_word_count: 1,
                minimum_sequence_count: minimumSequenceCount,
                minimum_sequence_length: minimumSequenceLength,
                category_ids: uniqueIntList(entry.category_ids || [])
            });
        }
        const minimumCount = Math.max(1, toInt(entry.minimum_word_count) || ctx.minimumWordCount);
        const maxLoadedWords = Math.max(
            minimumCount,
            toInt(entry.launch_word_cap)
                || toInt(gameConfig && gameConfig.maxLoadedWords)
                || minimumCount
        );
        const eligibleWords = (Array.isArray(entry.words) ? entry.words : [])
            .map(normalizeWord)
            .filter(function (word) {
                if (normalizedSlug === SPEAKING_PRACTICE_GAME_SLUG) {
                    return word.id > 0
                        && String(word.speaking_target_text || '').trim() !== ''
                        && String(word.speaking_best_correct_audio_url || '').trim() !== '';
                }
                if (normalizedSlug === SPEAKING_STACK_GAME_SLUG) {
                    return word.id > 0
                        && String(word.image || '').trim() !== ''
                        && String(word.speaking_target_text || '').trim() !== ''
                        && String(word.speaking_best_correct_audio_url || '').trim() !== '';
                }

                const audio = selectPromptAudio(word);
                return word.id > 0 && word.image !== '' && audio.url !== '';
            });
        const words = limitLaunchWords(eligibleWords, maxLoadedWords);
        const playableTargets = (normalizedSlug === SPEAKING_PRACTICE_GAME_SLUG || normalizedSlug === SPEAKING_STACK_GAME_SLUG)
            ? words.slice()
            : findPlayableTargets(words, Math.max(2, toInt(gameConfig && gameConfig.cardCount) || 4));
        const prepared = $.extend({}, entry, {
            slug: normalizedSlug,
            words: words,
            playableTargets: playableTargets,
            available_word_count: toInt(entry.available_word_count) || eligibleWords.length,
            launch_word_cap: maxLoadedWords,
            launch_word_count: words.length,
            launchable: !!entry.launchable && words.length >= minimumCount && playableTargets.length > 0,
            minimum_word_count: minimumCount,
            category_ids: uniqueIntList(entry.category_ids || [])
        });

        if (prepared.words.length >= minimumCount && !prepared.playableTargets.length) {
            prepared.launchable = false;
            prepared.reason_code = 'not_enough_compatible_words';
        }

        return prepared;
    }

    function getCardStatusText(ctx, entry) {
        if (!(ctx && (ctx.isLoggedIn || ctx.offlineMode))) {
            return String(ctx.i18n.gamesLoginRequired || 'Sign in to play with your in-progress words.');
        }
        if (!entry) {
            return String(ctx.i18n.gamesLoadError || 'Unable to load games right now.');
        }
        if (
            [SPEAKING_PRACTICE_GAME_SLUG, SPEAKING_STACK_GAME_SLUG].indexOf(normalizeGameSlug(entry.slug)) !== -1
            && String(entry.reason_code || '') === 'speaking_api_unavailable'
        ) {
            return String(ctx.i18n.gamesSpeakingApiUnavailable || 'Speaking practice is unavailable on this device right now.');
        }
        if (normalizeGameSlug(entry.slug) === LINEUP_GAME_SLUG) {
            if (entry.launchable) {
                return formatMessage(ctx.i18n.gamesReadySequences || '%d sequences ready', [
                    toInt(entry.available_sequence_count) || toInt(entry.available_word_count)
                ]);
            }
            if (String(entry.reason_code || '') === 'lineup_not_configured') {
                return formatMessage(
                    ctx.i18n.gamesLineupNeedItems || 'Each Line-Up sequence needs at least %d cards.',
                    [Math.max(2, toInt(entry.minimum_sequence_length) || 3)]
                );
            }
        }
        if (entry.launchable) {
            return formatMessage(ctx.i18n.gamesReadyCount || '%d words ready', [entry.available_word_count || 0]);
        }
        if (String(entry.reason_code || '') === 'not_enough_compatible_words') {
            return String(ctx.i18n.gamesNeedCompatibleWords || 'This word set does not have a playable mix of picture cards yet.');
        }
        if (String(entry.reason_code || '') === 'not_enough_learned_words') {
            const missingLearned = Math.max(0, (entry.minimum_word_count || ctx.minimumWordCount) - (entry.available_word_count || 0));
            return formatMessage(ctx.i18n.gamesNeedLearnedWords || 'Need %d more learned words to unlock this game.', [missingLearned]);
        }
        const missing = Math.max(0, (entry.minimum_word_count || ctx.minimumWordCount) - (entry.available_word_count || 0));
        return formatMessage(ctx.i18n.gamesNeedWords || 'Need %d more words to unlock this game.', [missing]);
    }

    function renderCatalogCard(ctx, slug, entry, isLoading) {
        const normalizedSlug = normalizeGameSlug(slug);
        const card = ctx && ctx.catalogCards ? ctx.catalogCards[normalizedSlug] : null;
        if (!card) {
            return;
        }
        const shouldHide = (
            normalizedSlug === SPEAKING_PRACTICE_GAME_SLUG
            || normalizedSlug === SPEAKING_STACK_GAME_SLUG
        ) && (isLoading || !entry || entry.hidden);
        if (shouldHide) {
            card.$card.attr('hidden', 'hidden');
        } else {
            card.$card.removeAttr('hidden');
        }
        if (shouldHide) {
            return;
        }
        const buttonLabel = (entry && entry.launchable)
            ? String(ctx.i18n.gamesPlay || 'Play')
            : String(ctx.i18n.gamesLocked || 'Locked');

        const loadingText = normalizedSlug === SPEAKING_PRACTICE_GAME_SLUG
            ? String(ctx.i18n.gamesSpeakingCheckingApi || ctx.i18n.gamesLoading || 'Checking game availability...')
            : String(ctx.i18n.gamesLoading || 'Checking game availability...');
        card.$status.text(isLoading ? loadingText : getCardStatusText(ctx, entry));
        card.$count.text(entry ? String(
            normalizedSlug === LINEUP_GAME_SLUG
                ? (toInt(entry.available_sequence_count) || toInt(entry.available_word_count))
                : toInt(entry.available_word_count)
        ) : '\u2014');
        card.$launchButton.text((ctx.isLoggedIn || ctx.offlineMode) ? buttonLabel : String(ctx.i18n.gamesLocked || 'Locked'));
        card.$launchButton.prop('disabled', isLoading || !(ctx.isLoggedIn || ctx.offlineMode) || !(entry && entry.launchable));
        card.$card.toggleClass('is-launchable', !!(entry && entry.launchable));
        card.$card.toggleClass('is-loading', !!isLoading);
    }

    function normalizeSpeakingNotice(data) {
        const source = (data && typeof data === 'object') ? data : {};
        const message = String(source.message || '').trim();

        return {
            show: !!source.show && message !== '',
            reasonCode: String(source.reason_code || source.reasonCode || '').trim(),
            message: message,
            settingsUrl: String(source.settings_url || source.settingsUrl || '').trim(),
            settingsLabel: String(source.settings_label || source.settingsLabel || '').trim()
        };
    }

    function getHiddenSpeakingNotice(ctx, entries, isLoading) {
        if (!ctx || !ctx.canManageSettings) {
            return null;
        }

        if (!!isLoading) {
            return (ctx.speakingHiddenNotice && ctx.speakingHiddenNotice.show)
                ? ctx.speakingHiddenNotice
                : null;
        }

        const catalogEntries = (entries && typeof entries === 'object') ? entries : {};
        const practiceEntry = catalogEntries[SPEAKING_PRACTICE_GAME_SLUG] || null;
        const stackEntry = catalogEntries[SPEAKING_STACK_GAME_SLUG] || null;
        const practiceVisible = !!(practiceEntry && !practiceEntry.hidden);
        const stackVisible = !!(stackEntry && !stackEntry.hidden);

        if (practiceVisible || stackVisible) {
            return null;
        }

        const hiddenEntries = [practiceEntry, stackEntry].filter(function (entry) {
            return !!(entry && entry.hidden);
        });
        const unavailableEntry = hiddenEntries.find(function (entry) {
            return String(entry.reason_code || '') === 'speaking_api_unavailable';
        });
        if (unavailableEntry) {
            return {
                show: true,
                reasonCode: 'speaking_api_unavailable',
                message: String(
                    ctx.i18n.gamesSpeakingHiddenConnection
                    || 'Speaking games are hidden because the speaking service for this word set is not responding on this device.'
                ),
                settingsUrl: String(ctx.speakingSettingsUrl || '').trim(),
                settingsLabel: String(
                    ctx.i18n.gamesSpeakingOpenSettings
                    || 'Open speaking settings'
                )
            };
        }

        if (ctx.speakingHiddenNotice && ctx.speakingHiddenNotice.show) {
            return ctx.speakingHiddenNotice;
        }

        if (!practiceEntry && !stackEntry) {
            return {
                show: true,
                reasonCode: 'speaking_hidden',
                message: String(
                    ctx.i18n.gamesSpeakingHiddenGeneric
                    || 'Speaking games are hidden because this word set speaking setup is not available right now.'
                ),
                settingsUrl: String(ctx.speakingSettingsUrl || '').trim(),
                settingsLabel: String(
                    ctx.i18n.gamesSpeakingOpenSettings
                    || 'Open speaking settings'
                )
            };
        }

        return null;
    }

    function renderSpeakingNotice(ctx, entries, isLoading) {
        if (!(ctx && ctx.$speakingNotice && ctx.$speakingNotice.length)) {
            return;
        }

        const notice = getHiddenSpeakingNotice(ctx, entries, isLoading);
        if (!notice || !notice.show || !String(notice.message || '').trim()) {
            ctx.$speakingNotice.attr('hidden', 'hidden');
            if (ctx.$speakingNoticeText && ctx.$speakingNoticeText.length) {
                ctx.$speakingNoticeText.text('');
            }
            if (ctx.$speakingNoticeLink && ctx.$speakingNoticeLink.length) {
                ctx.$speakingNoticeLink.attr('hidden', 'hidden');
                ctx.$speakingNoticeLink.attr('href', '#');
                ctx.$speakingNoticeLink.text('');
            }
            return;
        }

        if (ctx.$speakingNoticeText && ctx.$speakingNoticeText.length) {
            ctx.$speakingNoticeText.text(notice.message);
        }

        if (ctx.$speakingNoticeLink && ctx.$speakingNoticeLink.length) {
            const settingsUrl = String(notice.settingsUrl || ctx.speakingSettingsUrl || '').trim();
            const settingsLabel = String(
                notice.settingsLabel
                || ctx.i18n.gamesSpeakingOpenSettings
                || 'Open speaking settings'
            ).trim();

            if (settingsUrl) {
                ctx.$speakingNoticeLink.attr('href', settingsUrl);
                ctx.$speakingNoticeLink.text(settingsLabel);
                ctx.$speakingNoticeLink.removeAttr('hidden');
            } else {
                ctx.$speakingNoticeLink.attr('hidden', 'hidden');
                ctx.$speakingNoticeLink.attr('href', '#');
                ctx.$speakingNoticeLink.text('');
            }
        }

        ctx.$speakingNotice.removeAttr('hidden');
    }

    function renderAllCatalogCards(ctx, entries, isLoading) {
        const catalogEntries = (entries && typeof entries === 'object') ? entries : {};
        (Array.isArray(ctx.catalogOrder) ? ctx.catalogOrder : []).forEach(function (slug) {
            renderCatalogCard(ctx, slug, catalogEntries[slug] || null, !!isLoading);
        });
        renderSpeakingNotice(ctx, catalogEntries, isLoading);
    }

    function getSpeakingGameProbeUrl(endpoint) {
        const url = String(endpoint || '').trim();
        if (!url) {
            return '';
        }

        try {
            const parsed = new root.URL(url, root.location && root.location.href ? root.location.href : undefined);
            if (/\/transcribe\/?$/i.test(parsed.pathname)) {
                parsed.pathname = parsed.pathname.replace(/\/transcribe\/?$/i, '/health');
            }
            return parsed.toString();
        } catch (_) {
            return url.replace(/\/transcribe\/?$/i, '/health');
        }
    }

    function checkSpeakingGameEndpoint(ctx, endpoint, slug) {
        const url = getSpeakingGameProbeUrl(endpoint);
        if (!url || typeof root.fetch !== 'function') {
            return Promise.resolve(false);
        }
        if (Object.prototype.hasOwnProperty.call(ctx.speaking.availabilityChecks, url)) {
            return ctx.speaking.availabilityChecks[url];
        }

        const gameConfig = getGameConfig(ctx, slug);
        const timeoutMs = Math.max(500, toInt(gameConfig && gameConfig.apiCheckTimeoutMs) || 1500);
        const controller = (typeof root.AbortController === 'function') ? new root.AbortController() : null;
        let timeoutId = 0;
        const requestOptions = {
            mode: 'cors',
            cache: 'no-store',
        };
        const performGetProbe = function () {
            return root.fetch(url, $.extend({}, requestOptions, {
                method: 'GET',
            })).then(function (response) {
                return !!(response && response.ok);
            }).catch(function () {
                return false;
            });
        };
        const request = root.fetch(url, $.extend({}, requestOptions, {
            method: 'HEAD',
            signal: controller ? controller.signal : undefined
        })).then(function (response) {
            if (response && response.ok) {
                return true;
            }
            return performGetProbe();
        }).catch(function () {
            return performGetProbe();
        }).finally(function () {
            if (timeoutId) {
                root.clearTimeout(timeoutId);
            }
        });

        if (controller) {
            timeoutId = root.setTimeout(function () {
                try {
                    controller.abort();
                } catch (_) { /* no-op */ }
            }, timeoutMs);
        }

        ctx.speaking.availabilityChecks[url] = request;
        return request;
    }

    function getOfflineSpeakingBridge(ctx) {
        return (ctx && ctx.offlineBridge && typeof ctx.offlineBridge === 'object')
            ? ctx.offlineBridge
            : null;
    }

    function resolveCatalogEntryAvailability(ctx, slug, entry) {
        const normalizedSlug = normalizeGameSlug(slug);
        if (!entry || [SPEAKING_PRACTICE_GAME_SLUG, SPEAKING_STACK_GAME_SLUG].indexOf(normalizedSlug) === -1) {
            return Promise.resolve(entry);
        }

        if (ctx && ctx.offlineMode) {
            if (['embedded_model', 'offline_packaged'].indexOf(String(entry.provider || '')) === -1) {
                return Promise.resolve($.extend({}, entry, {
                    hidden: true,
                    launchable: false,
                    reason_code: 'speaking_api_unavailable'
                }));
            }

            const bridge = getOfflineSpeakingBridge(ctx);
            if (!bridge || typeof bridge.checkSpeakingAvailability !== 'function') {
                return Promise.resolve($.extend({}, entry, {
                    hidden: true,
                    launchable: false,
                    reason_code: 'speaking_api_unavailable'
                }));
            }

            return Promise.resolve(bridge.checkSpeakingAvailability(entry, ctx)).then(function (isAvailable) {
                if (isAvailable) {
                    return entry;
                }

                return $.extend({}, entry, {
                    hidden: true,
                    launchable: false,
                    reason_code: 'speaking_api_unavailable'
                });
            }).catch(function () {
                return $.extend({}, entry, {
                    hidden: true,
                    launchable: false,
                    reason_code: 'speaking_api_unavailable'
                });
            });
        }

        if (String(entry.provider || '') !== 'local_browser') {
            return Promise.resolve(entry);
        }

        return checkSpeakingGameEndpoint(ctx, entry.local_endpoint, normalizedSlug).then(function (isAvailable) {
            if (isAvailable) {
                return entry;
            }

            return $.extend({}, entry, {
                hidden: true,
                launchable: false,
                reason_code: 'speaking_api_unavailable'
            });
        });
    }

    function getPreparedCatalogEntry(ctx, slug) {
        const normalizedSlug = normalizeGameSlug(slug || getDefaultCatalogSlug(ctx));
        return ctx.catalogEntries[normalizedSlug]
            || (normalizedSlug === getDefaultCatalogSlug(ctx) ? ctx.catalogEntry : null);
    }

    function fetchLaunchEntry(ctx, slug) {
        const normalizedSlug = normalizeGameSlug(slug || getDefaultCatalogSlug(ctx));
        const localEntry = getPreparedCatalogEntry(ctx, normalizedSlug);
        if (!ctx || ctx.offlineMode || !ctx.isLoggedIn || !ctx.ajaxUrl || !ctx.launchAction || !ctx.wordsetId) {
            return Promise.resolve(localEntry);
        }

        if (ctx.launchEntryCache && ctx.launchEntryCache[normalizedSlug]) {
            return Promise.resolve(ctx.launchEntryCache[normalizedSlug]);
        }
        if (ctx.launchEntryRequests && ctx.launchEntryRequests[normalizedSlug]) {
            return ctx.launchEntryRequests[normalizedSlug];
        }

        ctx.launchEntryRequests = ctx.launchEntryRequests || {};
        ctx.launchEntryCache = ctx.launchEntryCache || {};

        ctx.launchEntryRequests[normalizedSlug] = new Promise(function (resolve, reject) {
            $.post(ctx.ajaxUrl, {
                action: ctx.launchAction,
                nonce: ctx.nonce,
                wordset_id: ctx.wordsetId,
                game_slug: normalizedSlug
            }).done(function (response) {
                const entry = response && response.success && response.data && response.data.game && typeof response.data.game === 'object'
                    ? buildPreparedEntry(ctx, normalizedSlug, response.data.game)
                    : null;
                if (!entry) {
                    reject(new Error('missing_launch_entry'));
                    return;
                }
                ctx.launchEntryCache[normalizedSlug] = entry;
                resolve(entry);
            }).fail(function () {
                reject(new Error('launch_request_failed'));
            });
        }).finally(function () {
            if (ctx.launchEntryRequests) {
                delete ctx.launchEntryRequests[normalizedSlug];
            }
        });

        return ctx.launchEntryRequests[normalizedSlug];
    }

    function launchGame(ctx, slug) {
        const normalizedSlug = normalizeGameSlug(slug || getDefaultCatalogSlug(ctx));
        const fallbackEntry = getPreparedCatalogEntry(ctx, normalizedSlug);
        if (!fallbackEntry || !fallbackEntry.launchable) {
            return Promise.resolve(null);
        }

        return fetchLaunchEntry(ctx, normalizedSlug).catch(function () {
            return fallbackEntry;
        }).then(function (entry) {
            if (!entry || !entry.launchable) {
                return null;
            }

            startRun(ctx, entry);
            return entry;
        });
    }

    function updateAudioButtonUi($button, isPlaying) {
        if (!$button || !$button.length) {
            return;
        }
        $button.toggleClass('playing', !!isPlaying);
        $button.find('.ll-audio-mini-visualizer').toggleClass('active', !!isPlaying);
    }

    function updateReplayAudioUi(ctx, isPlaying) {
        if (!ctx || !ctx.$replayAudioButton || !ctx.$replayAudioButton.length) {
            return;
        }
        updateAudioButtonUi(ctx.$replayAudioButton, isPlaying);
    }

    function ensurePromptAudio(ctx) {
        if (ctx.promptAudio) {
            return ctx.promptAudio;
        }

        ctx.promptAudio = new Audio();
        ctx.promptAudio.preload = 'auto';

        ['playing', 'play'].forEach(function (eventName) {
            ctx.promptAudio.addEventListener(eventName, function () {
                updateReplayAudioUi(ctx, true);
            });
        });
        ['pause', 'ended', 'error'].forEach(function (eventName) {
            ctx.promptAudio.addEventListener(eventName, function () {
                updateReplayAudioUi(ctx, false);
            });
        });

        return ctx.promptAudio;
    }

    function bindAudioElementButtonUi(audioEl, $button) {
        if (!audioEl || !$button || !$button.length || audioEl.__llWordsetButtonUiBound) {
            return;
        }

        ['playing', 'play'].forEach(function (eventName) {
            audioEl.addEventListener(eventName, function () {
                updateAudioButtonUi($button, true);
            });
        });
        ['pause', 'ended', 'error'].forEach(function (eventName) {
            audioEl.addEventListener(eventName, function () {
                updateAudioButtonUi($button, false);
            });
        });
        audioEl.__llWordsetButtonUiBound = true;
    }

    function clearPromptReplayTimer(ctx) {
        if (!ctx || !ctx.promptReplayTimer) {
            return;
        }

        root.clearTimeout(ctx.promptReplayTimer);
        ctx.promptReplayTimer = 0;
    }

    function cancelQueuedPromptPlayback(ctx) {
        clearPromptReplayTimer(ctx);
        ctx.promptPlaybackRequestId = toInt(ctx.promptPlaybackRequestId) + 1;
    }

    function pausePromptAudio(ctx, options) {
        const opts = options || {};
        if (opts.cancelQueued !== false) {
            cancelQueuedPromptPlayback(ctx);
        }
        if (ctx.promptAudio && typeof ctx.promptAudio.pause === 'function') {
            try {
                ctx.promptAudio.pause();
            } catch (_) { /* no-op */ }
        }
        updateReplayAudioUi(ctx, false);
    }

    function ensureFeedbackAudio(ctx) {
        if (ctx.feedbackAudio) {
            return ctx.feedbackAudio;
        }

        ctx.feedbackAudio = new Audio();
        ctx.feedbackAudio.preload = 'auto';
        return ctx.feedbackAudio;
    }

    function getFeedbackAudioSources(ctx, type, slugOrRun) {
        const gameConfig = getGameConfig(ctx, slugOrRun || (ctx && ctx.run));
        return type === 'correct'
            ? normalizeUrlList(gameConfig && gameConfig.correctHitAudioSources)
            : normalizeUrlList(gameConfig && gameConfig.wrongHitAudioSources);
    }

    function getFeedbackAudioVolume(ctx, type) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        const configured = type === 'correct'
            ? Number(gameConfig && gameConfig.correctHitVolume)
            : Number(gameConfig && gameConfig.wrongHitVolume);
        return clamp(configured, 0.05, 1);
    }

    function getPromptAudioVolume(ctx, slugOrRun) {
        const gameConfig = getGameConfig(ctx, slugOrRun || (ctx && ctx.run));
        return clamp(Number(gameConfig && gameConfig.promptAudioVolume) || 1, 0.05, 1);
    }

    function getFeedbackAudioCacheKey(ctx, type, slugOrRun) {
        const soundType = (type === 'correct') ? 'correct' : 'wrong';
        const gameConfig = getGameConfig(ctx, slugOrRun || (ctx && ctx.run));
        const gameSlug = normalizeGameSlug(
            gameConfig && gameConfig.slug
                ? gameConfig.slug
                : getCurrentGameSlug(ctx)
        );
        return 'feedback:' + gameSlug + ':' + soundType;
    }

    function waitForFeedbackQueue(ctx) {
        return ctx && ctx.feedbackQueue && typeof ctx.feedbackQueue.then === 'function'
            ? ctx.feedbackQueue.catch(function () {})
            : Promise.resolve();
    }

    function stopTransientAudio(ctx) {
        if (!ctx || !Array.isArray(ctx.transientAudioInstances)) {
            return;
        }

        ctx.transientAudioInstances.forEach(function (audio) {
            if (!audio || typeof audio.pause !== 'function') {
                return;
            }
            try {
                audio.pause();
            } catch (_) { /* no-op */ }
        });
        ctx.transientAudioInstances.length = 0;
    }

    function registerTransientAudioInstance(ctx, audio) {
        if (!ctx || !audio) {
            return;
        }
        if (!Array.isArray(ctx.transientAudioInstances)) {
            ctx.transientAudioInstances = [];
        }
        ctx.transientAudioInstances.push(audio);

        const release = function () {
            if (!Array.isArray(ctx.transientAudioInstances)) {
                return;
            }
            const index = ctx.transientAudioInstances.indexOf(audio);
            if (index !== -1) {
                ctx.transientAudioInstances.splice(index, 1);
            }
            audio.removeEventListener('ended', release);
            audio.removeEventListener('error', release);
            audio.removeEventListener('pause', release);
        };

        audio.addEventListener('ended', release);
        audio.addEventListener('error', release);
        audio.addEventListener('pause', release);
    }

    function playTransientEffectSound(ctx, sources, cacheKey, volume) {
        const sourceList = normalizeUrlList(sources);
        if (!sourceList.length) {
            return Promise.resolve(false);
        }

        return resolveReadyAudioSource(ctx, sourceList, cacheKey).then(function (source) {
            if (!source) {
                return false;
            }

            const audio = new Audio();
            audio.preload = 'auto';
            audio.volume = clamp(Number(volume) || 0.2, 0.05, 1);
            audio.src = source;
            registerTransientAudioInstance(ctx, audio);

            return Promise.resolve(audio.play()).catch(function () {
                try {
                    audio.pause();
                } catch (_) { /* no-op */ }
                return false;
            }).then(function () {
                return true;
            });
        }).catch(function () {
            return false;
        });
    }

    function playDecorativeBubblePopSound(ctx) {
        const gameConfig = getGameConfig(ctx, BUBBLE_POP_GAME_SLUG);
        return playTransientEffectSound(
            ctx,
            normalizeUrlList(gameConfig && gameConfig.correctHitAudioSources),
            'bubble-pop-ambient',
            (Number(gameConfig && gameConfig.correctHitVolume) || 0.28) * 0.92
        );
    }

    function stopFeedbackAudio(ctx) {
        if (!ctx) {
            return;
        }

        ctx.feedbackQueueVersion = toInt(ctx.feedbackQueueVersion) + 1;
        ctx.feedbackPlaying = false;
        ctx.feedbackQueue = Promise.resolve();

        if (ctx.feedbackAudio && typeof ctx.feedbackAudio.pause === 'function') {
            try {
                ctx.feedbackAudio.pause();
            } catch (_) { /* no-op */ }
        }
        if (ctx.feedbackAudio) {
            try {
                ctx.feedbackAudio.currentTime = 0;
            } catch (_) { /* no-op */ }
        }
    }

    function resolveReadyAudioSource(ctx, sources, cacheKey) {
        const sourceList = normalizeUrlList(sources);
        if (!sourceList.length) {
            return Promise.resolve('');
        }

        if (ctx.feedbackAudioSourceCache[cacheKey]) {
            return Promise.resolve(String(ctx.feedbackAudioSourceCache[cacheKey]));
        }

        let chain = Promise.resolve('');
        sourceList.forEach(function (source) {
            chain = chain.then(function (resolvedSource) {
                if (resolvedSource) {
                    return resolvedSource;
                }

                return ensureAudioLoaded(ctx, source).then(function (loaded) {
                    if (loaded) {
                        ctx.feedbackAudioSourceCache[cacheKey] = source;
                        return source;
                    }
                    return '';
                }).catch(function () {
                    return '';
                });
            });
        });

        return chain;
    }

    function warmFeedbackAudioSources(ctx, slugOrRun) {
        ['correct', 'wrong'].forEach(function (soundType) {
            const sources = getFeedbackAudioSources(ctx, soundType, slugOrRun);
            const cacheKey = getFeedbackAudioCacheKey(ctx, soundType, slugOrRun);
            if (!sources.length || !cacheKey) {
                return;
            }
            resolveReadyAudioSource(ctx, sources, cacheKey).catch(function () {});
        });
    }

    function waitForFeedbackAudioToFinish(ctx, audio, sequenceVersion, fallbackMs) {
        return new Promise(function (resolve) {
            let settled = false;
            let timeoutId = 0;

            const cleanup = function () {
                if (timeoutId) {
                    root.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
                audio.removeEventListener('ended', onDone);
                audio.removeEventListener('error', onDone);
                audio.removeEventListener('pause', onPause);
            };

            const finish = function () {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                resolve();
            };

            const onDone = function () {
                finish();
            };
            const onPause = function () {
                if ((ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                    finish();
                }
            };

            if ((ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                finish();
                return;
            }

            audio.addEventListener('ended', onDone);
            audio.addEventListener('error', onDone);
            audio.addEventListener('pause', onPause);
            timeoutId = root.setTimeout(onDone, Math.max(140, toInt(fallbackMs) || 0) + 80);
        });
    }

    function playQueuedAudioSources(ctx, sources, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const sourceList = normalizeUrlList(sources);
        const cacheKey = String(opts.cacheKey || '');
        const volume = clamp(Number(opts.volume), 0.05, 1);
        const shouldPausePrompt = opts.pausePrompt !== false;

        if (!sourceList.length || !cacheKey) {
            return waitForFeedbackQueue(ctx);
        }

        if (shouldPausePrompt) {
            pausePromptAudio(ctx);
        }

        const sequenceVersion = toInt(ctx.feedbackQueueVersion);
        const queue = waitForFeedbackQueue(ctx);
        ctx.feedbackQueue = queue.then(function () {
            if ((ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                return;
            }

            return resolveReadyAudioSource(ctx, sourceList, cacheKey).then(function (source) {
                if (!source || (ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                    return;
                }

                const feedbackAudio = ensureFeedbackAudio(ctx);
                const feedbackDurationMs = getLoadedAudioDurationMs(ctx, source);

                try {
                    feedbackAudio.pause();
                } catch (_) { /* no-op */ }
                try {
                    feedbackAudio.currentTime = 0;
                } catch (_) { /* no-op */ }

                feedbackAudio.volume = volume;
                if (feedbackAudio.src !== source) {
                    feedbackAudio.src = source;
                }

                ctx.feedbackPlaying = true;
                return Promise.resolve(feedbackAudio.play()).catch(function () {
                    return false;
                }).then(function () {
                    return waitForFeedbackAudioToFinish(ctx, feedbackAudio, sequenceVersion, feedbackDurationMs);
                }).finally(function () {
                    if ((ctx.feedbackQueueVersion || 0) === sequenceVersion) {
                        ctx.feedbackPlaying = false;
                    }
                });
            });
        }).catch(function () {});

        return ctx.feedbackQueue;
    }

    function playFeedbackSound(ctx, type) {
        const soundType = (type === 'correct') ? 'correct' : 'wrong';
        const sources = getFeedbackAudioSources(ctx, soundType);
        const cacheKey = getFeedbackAudioCacheKey(ctx, soundType);
        if (!sources.length) {
            return waitForFeedbackQueue(ctx);
        }

        return playQueuedAudioSources(ctx, sources, {
            cacheKey: cacheKey,
            volume: getFeedbackAudioVolume(ctx, soundType),
            pausePrompt: true
        });
    }

    function schedulePromptAutoReplay(ctx, run, source, requestId) {
        const promptDurationMs = Math.max(0, toInt(run && run.prompt && run.prompt.audioDurationMs) || getLoadedAudioDurationMs(ctx, source));
        const replayDelayMs = Math.max(0, toInt(run && run.prompt && run.prompt.autoReplayDelayMs));
        if (promptDurationMs <= 0 || replayDelayMs <= 0) {
            return;
        }

        clearPromptReplayTimer(ctx);
        ctx.promptReplayTimer = root.setTimeout(function () {
            ctx.promptReplayTimer = 0;
            if ((ctx.promptPlaybackRequestId || 0) !== requestId) {
                return;
            }
            if (!ctx.run || ctx.run !== run || run.paused || run.ended || !run.prompt || run.prompt.resolved) {
                return;
            }
            if (String(run.prompt.audioUrl || '') !== source) {
                return;
            }

            playPromptAudio(ctx, {
                allowAutoReplay: false
            });
        }, replayDelayMs);
    }

    function playPromptAudio(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const run = ctx.run;
        if (!run || run.paused || !run.prompt || !run.prompt.target) {
            return Promise.resolve(false);
        }

        const source = String(run.prompt.audioUrl || '');
        if (!source) {
            return Promise.resolve(false);
        }

        const requestId = toInt(ctx.promptPlaybackRequestId) + 1;
        ctx.promptPlaybackRequestId = requestId;
        clearPromptReplayTimer(ctx);

        const playbackGate = opts.ignoreFeedbackQueue ? Promise.resolve() : waitForFeedbackQueue(ctx);
        return playbackGate.then(function () {
            if ((ctx.promptPlaybackRequestId || 0) !== requestId) {
                return false;
            }
            if (!ctx.run || ctx.run !== run || run.paused || !run.prompt || !run.prompt.target || String(run.prompt.audioUrl || '') !== source) {
                return false;
            }

            const promptAudio = ensurePromptAudio(ctx);

            try {
                promptAudio.pause();
            } catch (_) { /* no-op */ }

            try {
                promptAudio.currentTime = 0;
            } catch (_) { /* no-op */ }

            const gameConfig = getGameConfig(ctx, run);
            promptAudio.volume = clamp(Number(gameConfig && gameConfig.promptAudioVolume) || 1, 0.05, 1);
            if (promptAudio.src !== source) {
                promptAudio.src = source;
            }

            updateReplayAudioUi(ctx, false);
            const playAttempt = promptAudio.play();
            if (playAttempt && typeof playAttempt.catch === 'function') {
                return playAttempt.then(function () {
                    updateReplayAudioUi(ctx, true);
                    if (opts.allowAutoReplay) {
                        schedulePromptAutoReplay(ctx, run, source, requestId);
                    }
                    return true;
                }).catch(function () {
                    updateReplayAudioUi(ctx, false);
                    return false;
                });
            }

            updateReplayAudioUi(ctx, true);
            if (opts.allowAutoReplay) {
                schedulePromptAutoReplay(ctx, run, source, requestId);
            }
            return true;
        });
    }

    function updateHud(ctx) {
        const run = ctx.run;
        if (!run) {
            return;
        }
        ctx.$coins.text(String(run.coins));
        ctx.$lives.text(String(run.lives));
    }

    function updatePauseUi(ctx) {
        const run = ctx.run;
        const isPaused = !!(run && run.paused);
        const pauseLabel = String(ctx.i18n.gamesPauseRun || 'Pause run');
        const resumeLabel = String(ctx.i18n.gamesResumeRun || 'Resume');

        if (ctx.$pauseButton && ctx.$pauseButton.length) {
            ctx.$pauseButton
                .toggleClass('is-paused', isPaused)
                .attr('aria-label', isPaused ? resumeLabel : pauseLabel);
        }
        if (ctx.$pauseIcon && ctx.$pauseIcon.length) {
            ctx.$pauseIcon.html(isPaused ? '&#9654;' : '&#10074;&#10074;');
        }
    }

    function setGameGuardPageClass(enabled) {
        if (!root.document) {
            return;
        }

        const method = enabled ? 'add' : 'remove';
        const html = root.document.documentElement;
        const body = root.document.body;

        if (html && html.classList) {
            html.classList[method]('ll-tools-wordset-game-guard-active');
        }
        if (body && body.classList) {
            body.classList[method]('ll-tools-wordset-game-guard-active');
        }
    }

    function clearGamePopstateSuppression() {
        if (gameInteractionGuard.suppressResetTimer) {
            root.clearTimeout(gameInteractionGuard.suppressResetTimer);
            gameInteractionGuard.suppressResetTimer = null;
        }
        gameInteractionGuard.suppressNextPopstate = false;
    }

    function scheduleGamePopstateSuppressionReset() {
        if (gameInteractionGuard.suppressResetTimer) {
            root.clearTimeout(gameInteractionGuard.suppressResetTimer);
        }
        gameInteractionGuard.suppressResetTimer = root.setTimeout(function () {
            gameInteractionGuard.suppressResetTimer = null;
            gameInteractionGuard.suppressNextPopstate = false;
        }, 600);
    }

    function getGameCloseConfirmMessage(ctx) {
        return String((ctx && ctx.i18n && ctx.i18n.gamesCloseConfirm) || 'Leave this game? Your current run will be lost.');
    }

    function pushGameHistoryState() {
        if (!root.history || typeof root.history.pushState !== 'function') {
            return false;
        }

        gameInteractionGuard.historyToken += 1;

        try {
            const currentState = (root.history.state && typeof root.history.state === 'object')
                ? Object.assign({}, root.history.state)
                : {};
            currentState.llWordsetGameGuard = gameInteractionGuard.historyToken;
            root.history.pushState(currentState, root.document ? root.document.title : '', root.location.href);
            gameInteractionGuard.historyActive = true;
            return true;
        } catch (_) {
            return false;
        }
    }

    function consumeGameHistoryState() {
        if (!gameInteractionGuard.historyActive || !root.history || typeof root.history.back !== 'function') {
            gameInteractionGuard.historyActive = false;
            clearGamePopstateSuppression();
            return false;
        }

        gameInteractionGuard.historyActive = false;
        gameInteractionGuard.suppressNextPopstate = true;
        scheduleGamePopstateSuppressionReset();

        try {
            root.history.back();
            return true;
        } catch (_) {
            clearGamePopstateSuppression();
            return false;
        }
    }

    function getGameGuardViewportScale() {
        if (root.visualViewport && typeof root.visualViewport.scale === 'number' && isFinite(root.visualViewport.scale) && root.visualViewport.scale > 0) {
            return root.visualViewport.scale;
        }

        return 1;
    }

    function gameGuardViewportIsZoomed() {
        return getGameGuardViewportScale() > 1.01;
    }

    function getGameGuardTouchDistance(touches) {
        if (!touches || touches.length < 2) {
            return 0;
        }

        const first = touches[0];
        const second = touches[1];
        const dx = (Number(second.clientX) || 0) - (Number(first.clientX) || 0);
        const dy = (Number(second.clientY) || 0) - (Number(first.clientY) || 0);
        return Math.sqrt((dx * dx) + (dy * dy));
    }

    function resetGameGuardPinchDistance() {
        gameInteractionGuard.pinchDistance = 0;
    }

    function isRunModalVisible(ctx) {
        return !!(ctx && ctx.$runModal && ctx.$runModal.length && !ctx.$runModal.prop('hidden'));
    }

    function isGameGuardActive(ctx) {
        return !!gameInteractionGuard.active && isRunModalVisible(ctx);
    }

    function activateGameInteractionGuard() {
        if (gameInteractionGuard.active) {
            return;
        }

        gameInteractionGuard.active = true;
        setGameGuardPageClass(true);
        if (!gameInteractionGuard.historyActive) {
            pushGameHistoryState();
        }
    }

    function deactivateGameInteractionGuard(options) {
        const opts = (options && typeof options === 'object') ? options : {};

        gameInteractionGuard.active = false;
        resetGameGuardPinchDistance();
        setGameGuardPageClass(false);

        if (opts.historyAlreadyHandled) {
            gameInteractionGuard.historyActive = false;
            clearGamePopstateSuppression();
            return;
        }

        consumeGameHistoryState();
    }

    function confirmAndCloseGameFromGuard(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        let shouldClose = true;

        try {
            shouldClose = root.confirm(getGameCloseConfirmMessage(ctx));
        } catch (_) {
            shouldClose = true;
        }

        if (shouldClose) {
            showCatalog(ctx, {
                historyAlreadyHandled: !!opts.historyAlreadyHandled
            });
            return true;
        }

        if (opts.rearmHistory) {
            pushGameHistoryState();
        }

        return false;
    }

    function requestCloseGame(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const run = ctx && ctx.run;
        const shouldConfirm = !!(run && !run.ended && isGameGuardActive(ctx));

        if (shouldConfirm) {
            return confirmAndCloseGameFromGuard(ctx, opts);
        }

        showCatalog(ctx, {
            historyAlreadyHandled: !!opts.historyAlreadyHandled
        });
        return true;
    }

    function toggleRunModalPageLock(isLocked) {
        if (!root.document) {
            return;
        }

        const html = root.document.documentElement;
        const body = root.document.body;
        if (html && html.classList) {
            html.classList.toggle('ll-wordset-game-run-modal-open', !!isLocked);
        }
        if (body && body.classList) {
            body.classList.toggle('ll-wordset-game-run-modal-open', !!isLocked);
        }
    }

    function setRunModalOpen(ctx, isOpen) {
        if (!ctx || !ctx.$runModal || !ctx.$runModal.length) {
            return false;
        }

        ctx.$runModal.prop('hidden', !isOpen);
        toggleRunModalPageLock(!!isOpen);
        if (isOpen) {
            root.requestAnimationFrame(function () {
                syncCanvasSize(ctx);
            });
        }
        return true;
    }

    function resetRunControls(run) {
        if (!run || !run.controls || typeof run.controls !== 'object') {
            return;
        }
        run.controls.left = false;
        run.controls.right = false;
        run.controls.fire = false;
    }

    function clearControlUi(ctx) {
        ctx.$controls.removeClass('is-active');
    }

    function scrollStageIntoView(ctx) {
        const stage = ctx && ctx.$stage ? ctx.$stage.get(0) : null;
        if (!stage) {
            return;
        }

        if (isRunModalVisible(ctx)) {
            return;
        }

        const performScroll = function () {
            const currentScroll = Number(root.pageYOffset || root.scrollY || 0);
            const targetTop = Math.max(0, Math.round(stage.getBoundingClientRect().top + currentScroll - 16));

            if (typeof root.scrollTo === 'function') {
                try {
                    root.scrollTo({
                        top: targetTop,
                        behavior: 'smooth'
                    });
                    return;
                } catch (_) { /* no-op */ }

                try {
                    root.scrollTo(0, targetTop);
                    return;
                } catch (_) { /* no-op */ }
            }

            if (typeof stage.scrollIntoView === 'function') {
                try {
                    stage.scrollIntoView({
                        block: 'start',
                        inline: 'nearest',
                        behavior: 'smooth'
                    });
                    return;
                } catch (_) { /* no-op */ }

                stage.scrollIntoView(true);
            }
        };

        if (typeof root.requestAnimationFrame === 'function') {
            root.requestAnimationFrame(performScroll);
            return;
        }

        root.setTimeout(performScroll, 0);
    }

    function updateStageGameUi(ctx, slugOrRun) {
        const rawSlug = slugOrRun && typeof slugOrRun === 'object'
            ? slugOrRun.slug
            : slugOrRun;
        const gameSlug = String(rawSlug || '').trim() === ''
            ? ''
            : normalizeGameSlug(rawSlug);
        const isSpeaking = gameSlug === SPEAKING_PRACTICE_GAME_SLUG;
        const isSpeakingStack = gameSlug === SPEAKING_STACK_GAME_SLUG;
        const isBubble = gameSlug === BUBBLE_POP_GAME_SLUG;
        const isLineup = gameSlug === LINEUP_GAME_SLUG;
        const isUnscramble = gameSlug === UNSCRAMBLE_GAME_SLUG;

        if (ctx && ctx.$stage && ctx.$stage.length) {
            ctx.$stage.attr('data-ll-wordset-active-game', gameSlug || '');
        }
        if (ctx && ctx.$hud && ctx.$hud.length) {
            ctx.$hud.prop('hidden', isSpeaking || isLineup || isUnscramble);
        }
        if (ctx && ctx.$controlsWrap && ctx.$controlsWrap.length) {
            ctx.$controlsWrap.prop('hidden', isBubble || isSpeaking || isSpeakingStack || isLineup || isUnscramble);
        }
        if (ctx && ctx.$canvasWrap && ctx.$canvasWrap.length) {
            ctx.$canvasWrap.prop('hidden', isSpeaking);
        }
        if (ctx && ctx.$canvas && ctx.$canvas.length) {
            ctx.$canvas.prop('hidden', isLineup || isUnscramble);
        }
        if (ctx && ctx.$replayAudioButton && ctx.$replayAudioButton.length) {
            ctx.$replayAudioButton.prop('hidden', isSpeaking || isSpeakingStack || isLineup || isUnscramble);
        }
        if (ctx && ctx.$pauseButton && ctx.$pauseButton.length) {
            ctx.$pauseButton.prop('hidden', isSpeaking || isLineup || isUnscramble);
        }
        if (ctx && ctx.$coins && ctx.$coins.length) {
            ctx.$coins.closest('.ll-wordset-game-stage__stat').prop('hidden', isSpeaking || isSpeakingStack || isLineup || isUnscramble);
        }
        if (ctx && ctx.$lives && ctx.$lives.length) {
            ctx.$lives.closest('.ll-wordset-game-stage__stat').prop('hidden', isSpeaking || isSpeakingStack || isLineup || isUnscramble);
        }
        if (ctx && ctx.$speakingStage && ctx.$speakingStage.length) {
            ctx.$speakingStage.prop('hidden', !isSpeaking);
        }
        if (ctx && ctx.$speakingStackStage && ctx.$speakingStackStage.length) {
            ctx.$speakingStackStage.prop('hidden', !isSpeakingStack);
        }
        if (ctx && ctx.$lineupStage && ctx.$lineupStage.length) {
            ctx.$lineupStage.prop('hidden', !(isLineup || isUnscramble));
            ctx.$lineupStage.attr('data-lineup-mode', isUnscramble ? 'unscramble' : (isLineup ? 'line-up' : ''));
        }
        if (ctx && ctx.canvas && typeof ctx.canvas.setAttribute === 'function') {
            ctx.canvas.setAttribute('aria-label', gameSlug ? getBoardLabel(ctx, gameSlug) : String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelDefault || 'Wordset game board'));
        }
    }

    function resetGamesSurface(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        stopRun(ctx, { flush: true });
        hideOverlay(ctx);
        ctx.activeGameSlug = '';
        updateStageGameUi(ctx, '');
        ctx.$stage.prop('hidden', true);
        if (!opts.keepModalOpen) {
            setRunModalOpen(ctx, false);
            deactivateGameInteractionGuard({
                historyAlreadyHandled: !!opts.historyAlreadyHandled
            });
        }
    }

    function setControlState(ctx, control, isActive) {
        const run = ctx.run;
        if (!run || run.paused || !run.controls || !Object.prototype.hasOwnProperty.call(run.controls, control)) {
            return;
        }
        if (isBubblePopRun(ctx, run) || isSpeakingStackRun(ctx, run)) {
            return;
        }
        run.controls[control] = !!isActive;
        ctx.$controls.filter('[data-ll-wordset-game-control="' + control + '"]').toggleClass('is-active', !!isActive);
    }

    function clearPromptTimer(run, preserveRemaining) {
        if (!run) {
            return;
        }

        if (run.promptTimer) {
            root.clearTimeout(run.promptTimer);
            run.promptTimer = 0;
        }

        if (preserveRemaining) {
            run.promptTimerRemainingMs = Math.max(
                0,
                run.promptTimerReadyAt > 0 ? run.promptTimerReadyAt - currentTimestamp() : run.promptTimerRemainingMs || 0
            );
            run.promptTimerReadyAt = 0;
            return;
        }

        run.promptTimerReadyAt = 0;
        run.promptTimerRemainingMs = 0;
    }

    function schedulePrompt(ctx, delayMs) {
        const run = ctx.run;
        if (!run) {
            return;
        }

        clearPromptTimer(run, false);
        run.promptTimerRemainingMs = Math.max(0, delayMs || 0);
        run.promptTimerReadyAt = currentTimestamp() + run.promptTimerRemainingMs;
        run.promptTimer = root.setTimeout(function () {
            run.promptTimer = 0;
            run.promptTimerReadyAt = 0;
            run.promptTimerRemainingMs = 0;
            startNextPrompt(ctx);
        }, run.promptTimerRemainingMs);
    }

    function selectNextTarget(run) {
        if (!run.promptDeck.length) {
            run.promptDeck = shuffle(run.playableTargets);
        }
        while (run.promptDeck.length) {
            const candidate = run.promptDeck.shift();
            if (candidate) {
                return candidate;
            }
        }
        return null;
    }

    function refreshRunPlayableTargets(run) {
        if (!run) {
            return;
        }
        run.playableTargets = findPlayableTargets(run.words, run.cardCount);
        const playableLookup = {};
        run.playableTargets.forEach(function (word) {
            const wordId = toInt(word && word.id);
            if (wordId) {
                playableLookup[wordId] = true;
            }
        });
        run.promptDeck = run.promptDeck.filter(function (word) {
            return !!playableLookup[toInt(word && word.id)];
        });
        if (run.nextPreparedPrompt && !playableLookup[toInt(run.nextPreparedPrompt.target && run.nextPreparedPrompt.target.id)]) {
            run.nextPreparedPrompt = null;
        }
    }

    function removeWordFromRun(run, wordId) {
        const targetId = toInt(wordId);
        if (!run || !targetId) {
            return;
        }

        run.words = run.words.filter(function (word) {
            return toInt(word && word.id) !== targetId;
        });
        run.promptDeck = run.promptDeck.filter(function (word) {
            return toInt(word && word.id) !== targetId;
        });
        refreshRunPlayableTargets(run);
    }

    function buildPromptCandidate(ctx, run, targetWord) {
        const promptAudio = selectPromptAudio(targetWord, shuffle(getGamePromptRecordingTypes(targetWord)));
        if (!promptAudio.url) {
            return null;
        }

        const promptId = run.promptIdCounter + 1;
        const distractorMode = selectDistractorStrategy(run, targetWord, run.words);
        const cards = buildPromptCards(ctx, run, targetWord, run.words, promptId, distractorMode);
        if (!cards) {
            return null;
        }

        return {
            target: targetWord,
            promptId: promptId,
            audioUrl: promptAudio.url,
            recordingType: promptAudio.recordingType,
            cards: cards,
            distractorMode: distractorMode
        };
    }

    function preloadPromptCandidate(ctx, candidate) {
        if (!candidate || !candidate.target || !candidate.audioUrl) {
            return Promise.resolve({
                ready: false,
                failedWordIds: [],
                failedAudio: false
            });
        }

        const imageWords = candidate.cards.map(function (card) {
            return card.word;
        });

        return Promise.all([
            ensureAudioLoaded(ctx, candidate.audioUrl),
            Promise.all(imageWords.map(function (word) {
                return ensureWordImageLoaded(ctx, word).then(function (loaded) {
                    return {
                        wordId: toInt(word && word.id),
                        loaded: !!loaded
                    };
                });
            }))
        ]).then(function (results) {
            const audioReady = !!results[0];
            const imageResults = Array.isArray(results[1]) ? results[1] : [];
            const failedWordIds = imageResults.filter(function (entry) {
                return !entry.loaded && entry.wordId > 0;
            }).map(function (entry) {
                return entry.wordId;
            });

            return {
                ready: audioReady && failedWordIds.length === 0,
                failedWordIds: failedWordIds,
                failedAudio: !audioReady
            };
        });
    }

    function preparePromptCandidate(ctx, run) {
        const maxAttempts = Math.max(1, run.playableTargets.length);
        let chain = Promise.resolve(null);

        for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
            chain = chain.then(function (candidate) {
                if (candidate || !ctx.run || ctx.run !== run || run.ended) {
                    return candidate;
                }

                const targetWord = selectNextTarget(run);
                if (!targetWord) {
                    return null;
                }

                const nextCandidate = buildPromptCandidate(ctx, run, targetWord);
                if (!nextCandidate) {
                    return null;
                }

                return preloadPromptCandidate(ctx, nextCandidate).then(function (preload) {
                    if (preload.ready) {
                        return nextCandidate;
                    }

                    preload.failedWordIds.forEach(function (wordId) {
                        removeWordFromRun(run, wordId);
                    });
                    if (preload.failedAudio) {
                        removeWordFromRun(run, toInt(targetWord && targetWord.id));
                    }
                    return null;
                });
            });
        }

        return chain;
    }

    function queueNextPreparedPrompt(ctx, run) {
        if (!run || run.ended || run.nextPreparedPrompt || run.nextPromptPromise) {
            return null;
        }

        run.nextPromptPromise = preparePromptCandidate(ctx, run).then(function (candidate) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return null;
            }
            run.nextPreparedPrompt = candidate;
            return candidate;
        }).finally(function () {
            if (ctx.run === run) {
                run.nextPromptPromise = null;
            }
        });

        return run.nextPromptPromise;
    }

    function applyPreparedPrompt(ctx, run, candidate) {
        if (!run || !candidate || !candidate.target) {
            return false;
        }

        const gameConfig = getGameConfig(ctx, run);
        const isBubbleGame = isBubblePopRun(ctx, run);
        if (isBubbleGame) {
            placeBubblePromptCards(run, candidate.cards);
        }
        const targetCard = candidate.cards.find(function (card) {
            return !!card && card.isTarget;
        });
        const promptDurationMs = getLoadedAudioDurationMs(ctx, candidate.audioUrl);
        const safeLineRatio = clamp(Number(gameConfig && gameConfig.audioSafeLineRatio) || 0.6, 0.35, 0.7);
        const safeLineBufferMs = Math.max(0, toInt(gameConfig && gameConfig.audioSafeLineBufferMs) || 180);
        const safeLineY = isBubbleGame
            ? run.height * (1 - safeLineRatio)
            : run.height * safeLineRatio;
        let promptCardSpeed = isBubbleGame ? (run.cardSpeed * 0.9) : run.cardSpeed;
        const bubbleEntryRevealMs = isBubbleGame
            ? Math.max(220, toInt(gameConfig && gameConfig.cardEntryRevealMs) || 520)
            : 0;
        const targetRevealMs = targetCard
            ? (isBubbleGame ? bubbleEntryRevealMs : getCardEntryRevealMs(ctx, targetCard))
            : 0;
        const targetVisibleY = targetCard ? getCardEntryVisibleY(ctx, run, targetCard) : 0;
        const bubbleEntryHiddenDistance = isBubbleGame
            ? candidate.cards.reduce(function (maxDistance, card) {
                return Math.max(
                    maxDistance,
                    Math.max(card.height * 0.9, promptCardSpeed * (bubbleEntryRevealMs / 1000))
                );
            }, 0)
            : 0;

        if (targetCard && promptDurationMs > 0) {
            const distanceToSafeLine = isBubbleGame
                ? Math.max(48, targetVisibleY - safeLineY)
                : Math.max(48, safeLineY - targetVisibleY);
            const minTravelSeconds = Math.max(0.1, ((promptDurationMs + safeLineBufferMs - targetRevealMs) / 1000));
            const maxSafeSpeed = distanceToSafeLine / minTravelSeconds;
            if (isFinite(maxSafeSpeed) && maxSafeSpeed > 0) {
                promptCardSpeed = Math.min(run.cardSpeed, maxSafeSpeed);
            }
        }

        const promptStartedAt = currentTimestamp();
        candidate.cards.forEach(function (card) {
            const entryRevealMs = isBubbleGame ? bubbleEntryRevealMs : getCardEntryRevealMs(ctx, card);
            const entryVisibleY = getCardEntryVisibleY(ctx, run, card);
            const hiddenDistance = isBubbleGame
                ? bubbleEntryHiddenDistance
                : Math.max(card.height * 0.9, promptCardSpeed * (entryRevealMs / 1000));
            const availableTravelSeconds = Math.max(0.1, ((promptDurationMs + safeLineBufferMs - entryRevealMs) / 1000));
            const maxSafeSpeed = isBubbleGame
                ? Math.max(48, entryVisibleY - safeLineY) / availableTravelSeconds
                : Math.max(48, safeLineY - entryVisibleY) / availableTravelSeconds;
            const variedCardSpeed = promptCardSpeed * Math.max(0.86, Number(card.fallSpeedFactor) || 1);
            card.entryRevealMs = entryRevealMs;
            card.entryStartedAt = promptStartedAt;
            card.entryVisibleX = isBubbleGame
                ? Number(card.bubbleVisibleX || card.entryVisibleX || card.x)
                : laneCenterX(run, card.laneIndex);
            card.entryStartX = isBubbleGame
                ? clamp(
                    Number(card.entryVisibleX || card.entryStartX || card.x),
                    getBubbleMovementBounds(run, getBubbleRadius(card)).minX,
                    getBubbleMovementBounds(run, getBubbleRadius(card)).maxX
                )
                : card.entryVisibleX;
            card.entryStartY = isBubbleGame
                ? entryVisibleY + hiddenDistance
                : entryVisibleY - hiddenDistance;
            card.entryVisibleY = entryVisibleY;
            if (isBubbleGame) {
                card.bubbleBaseX = card.entryVisibleX;
                card.bubbleBaseY = card.entryVisibleY;
                card.x = card.entryStartX;
            }
            card.y = card.entryStartY;
            card.speed = Math.min(variedCardSpeed, maxSafeSpeed);
        });
        const replayTiming = getPromptAutoReplayTiming(ctx, run, promptDurationMs, targetCard);

        run.promptIdCounter = candidate.promptId;
        run.prompt = {
            target: candidate.target,
            promptId: candidate.promptId,
            audioUrl: candidate.audioUrl,
            recordingType: candidate.recordingType,
            distractorMode: String(candidate.distractorMode || 'mixed'),
            cardSpeed: promptCardSpeed,
            audioDurationMs: promptDurationMs,
            autoReplayDelayMs: replayTiming.delayMs,
            autoReplayBaseDelayMs: replayTiming.baseDelayMs,
            safeLineCrossDelayMs: replayTiming.safeLineCrossDelayMs,
            autoReplaySafeLineGated: replayTiming.gatedBySafeLine,
            gameSlug: normalizeGameSlug(run.slug),
            hadWrongBefore: false,
            wrongCount: 0,
            wrongHitRecoveryUntil: 0,
            lifePenaltyApplied: false,
            exposureTracked: false,
            hadUserActivity: false,
            activityFinalized: false,
            resolved: false
        };
        run.cards = run.cards.concat(candidate.cards);
        if (isBubbleGame) {
            refreshBubblePromptCardPositions(run, promptStartedAt);
        }
        playPromptAudio(ctx, {
            allowAutoReplay: true
        });
        queueNextPreparedPrompt(ctx, run);
        return true;
    }

    function activePromptId(run) {
        return toInt(run && run.prompt && run.prompt.promptId);
    }

    function isWrongHitRecoveryActive(run, now) {
        return !!(
            run
            && run.prompt
            && !run.prompt.resolved
            && Number(run.prompt.wrongHitRecoveryUntil || 0) > Number(now || 0)
        );
    }

    function isActivePromptCard(run, card) {
        return !!card && activePromptId(run) > 0 && toInt(card.promptId) === activePromptId(run);
    }

    function startNextPrompt(ctx) {
        const run = ctx.run;
        if (!run || run.ended || run.awaitingPrompt) {
            return;
        }
        if (runReachedGoal(run)) {
            endRun(ctx, 'win');
            return;
        }

        run.awaitingPrompt = true;
        removeResolvedObjects(run, currentTimestamp());
        const preparedPromptPromise = run.nextPreparedPrompt
            ? Promise.resolve(run.nextPreparedPrompt)
            : (run.nextPromptPromise || preparePromptCandidate(ctx, run));

        preparedPromptPromise.then(function (candidate) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }

            const applyCandidate = function () {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }

                run.awaitingPrompt = false;
                run.nextPreparedPrompt = null;

                if (applyPreparedPrompt(ctx, run, candidate)) {
                    hideOverlay(ctx);
                    return;
                }

                endRun(ctx);
            };

            if (!run.prompt && run.promptsResolved <= 0 && !isBubblePopRun(ctx, run)) {
                applyInitialPromptWhenReady(ctx, run, applyCandidate);
                return;
            }

            applyCandidate();
        }).catch(function () {
            if (!ctx.run || ctx.run !== run) {
                return;
            }
            run.awaitingPrompt = false;
            run.nextPreparedPrompt = null;
            endRun(ctx);
        });
    }

    function currentTimestamp() {
        return (root.performance && typeof root.performance.now === 'function') ? root.performance.now() : Date.now();
    }

    function getWrongHitRecoveryMs(ctx) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        const fireIntervalMs = Math.max(80, toInt(gameConfig && gameConfig.fireIntervalMs) || 165);
        return Math.max(180, fireIntervalMs + 40);
    }

    function getRunEventSource(run) {
        return normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG
            ? 'bubble_pop'
            : 'space_shooter';
    }

    function markRunActivity(ctx) {
        const run = ctx && ctx.run;
        if (!run || run.paused || run.ended || !run.prompt || run.prompt.resolved) {
            return false;
        }

        run.prompt.hadUserActivity = true;
        return true;
    }

    function applyPromptLifePenalty(run, penalty) {
        const normalizedPenalty = Math.max(0, toInt(penalty) || 0);
        if (!run || !run.prompt || run.prompt.resolved || run.prompt.lifePenaltyApplied || normalizedPenalty <= 0) {
            return false;
        }

        run.lives = Math.max(0, run.lives - normalizedPenalty);
        run.prompt.lifePenaltyApplied = true;
        return true;
    }

    function finalizePromptActivity(run) {
        if (!run || !run.prompt || run.prompt.activityFinalized) {
            return Math.max(0, toInt(run && run.inactiveRounds));
        }

        const hadUserActivity = !!run.prompt.hadUserActivity;
        run.inactiveRounds = hadUserActivity
            ? 0
            : Math.max(0, toInt(run.inactiveRounds)) + 1;
        run.lastResolvedPromptHadUserActivity = hadUserActivity;
        run.prompt.activityFinalized = true;
        return run.inactiveRounds;
    }

    function maybePauseForInactivity(ctx) {
        const run = ctx && ctx.run;
        if (!run || !run.prompt || !run.prompt.resolved || run.ended) {
            return false;
        }

        const inactiveRounds = finalizePromptActivity(run);
        if (inactiveRounds < INACTIVITY_ROUND_PAUSE_LIMIT) {
            return false;
        }

        pauseRun(ctx, {
            reason: PAUSE_REASON_INACTIVITY,
            resumeAction: RESUME_ACTION_NEXT_PROMPT,
            summary: formatMessage(
                ctx.i18n.gamesInactivePauseSummary || 'Paused after %d rounds without input.',
                [INACTIVITY_ROUND_PAUSE_LIMIT]
            )
        });
        return true;
    }

    function removeResolvedObjects(run, now) {
        run.cards = run.cards.filter(function (card) {
            if (card.resolvedFalling) {
                if (normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG) {
                    const halfWidth = Math.max(1, Number(card.width) || 0) / 2;
                    const halfHeight = Math.max(1, Number(card.height) || 0) / 2;
                    if ((card.x + halfWidth) < 0) {
                        return false;
                    }
                    if ((card.x - halfWidth) > run.width) {
                        return false;
                    }
                    if ((card.y + halfHeight) < 0) {
                        return false;
                    }
                    if ((card.y - halfHeight) > run.height) {
                        return false;
                    }
                } else if ((card.y - (card.height / 2)) > (run.height + card.height)) {
                    return false;
                }
            }
            if (card.exploding && now >= card.removeAt) {
                return false;
            }
            return true;
        });
        run.explosions = run.explosions.filter(function (explosion) {
            return now < (explosion.startedAt + explosion.duration);
        });
    }

    function fireBullet(run) {
        if (normalizeGameSlug(run && run.slug) !== DEFAULT_GAME_SLUG) {
            return;
        }
        run.bullets.push({
            x: run.shipX,
            y: run.metrics.shipY - (run.metrics.shipHeight * 0.8),
            radius: 3,
            speed: run.metrics.bulletSpeed
        });
    }

    function getSteadyStateCardSpeed(run, promptsResolved) {
        const resolvedCount = Math.max(0, toInt(promptsResolved));
        const rampTurns = Math.max(1, toInt(run && run.speedRampTurns) || 10);
        const resolvedBeyondRamp = Math.max(0, resolvedCount - rampTurns);
        return clamp(
            86 + (Math.floor(resolvedBeyondRamp / 3) * 10),
            86,
            run.width < 480 ? 148 : 178
        );
    }

    function getRunCardSpeed(run) {
        const steadyStateSpeed = getSteadyStateCardSpeed(run, run && run.promptsResolved);
        const rampTurns = Math.max(1, toInt(run && run.speedRampTurns) || 10);
        const rampProgress = clamp((run && run.promptsResolved) / rampTurns, 0, 1);
        const introFactor = clamp(Number(run && run.speedRampStartFactor) || 0.5, 0.25, 0.95);
        const introSpeed = steadyStateSpeed * introFactor;

        if (rampProgress >= 1) {
            return steadyStateSpeed;
        }

        return introSpeed + ((steadyStateSpeed - introSpeed) * rampProgress);
    }

    function markPromptResolved(run) {
        if (!run.prompt || run.prompt.resolved) {
            return false;
        }
        run.prompt.resolved = true;
        run.promptsResolved += 1;
        run.cardSpeed = getRunCardSpeed(run);
        return true;
    }

    function releaseResolvedPromptCards(run, targetCard) {
        const targetPromptId = toInt(targetCard && targetCard.promptId);
        run.cards.forEach(function (entry) {
            if (!entry || entry === targetCard || entry.exploding || toInt(entry.promptId) !== targetPromptId) {
                return;
            }
            entry.resolvedFalling = true;
            entry.speed = Math.max(entry.speed * 3.3, run.cardSpeed * 3.6);
            if (normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG) {
                entry.releaseDriftX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(18, 44);
            }
        });
    }

    function boostBubbleResolvedPromptExit(run, promptId, sourceX, sourceY) {
        const targetPromptId = toInt(promptId);
        if (!run || normalizeGameSlug(run && run.slug) !== BUBBLE_POP_GAME_SLUG || targetPromptId <= 0) {
            return;
        }

        const originX = Number(sourceX) || 0;
        const originY = Number(sourceY) || 0;
        run.cards.forEach(function (entry) {
            if (!entry || entry.exploding || !entry.resolvedFalling || toInt(entry.promptId) !== targetPromptId) {
                return;
            }

            const dx = Number(entry.x) - originX;
            const dy = Number(entry.y) - originY;
            const distance = Math.sqrt((dx * dx) + (dy * dy));
            const directionX = distance > 0.001 ? (dx / distance) : randomBetween(-1, 1);
            const directionY = distance > 0.001 ? (dy / distance) : -1;
            const exitSpeed = Math.max(run.cardSpeed * 18, 920);

            entry.releaseMaxSpeed = Math.max(BUBBLE_CORRECT_RELEASE_MAX_SPEED, exitSpeed);
            entry.speed = Math.max(Number(entry.speed) || 0, exitSpeed);
            entry.releaseDriftX = directionX * exitSpeed;
            entry.releaseDriftY = directionY * exitSpeed;
            entry.bubbleImpulseVelocityX = clamp(directionX * (exitSpeed * 0.24), -220, 220);
            entry.bubbleImpulseVelocityY = clamp(directionY * (exitSpeed * 0.24), -220, 220);
        });
    }

    function releaseTimedOutPromptCards(run) {
        const promptId = activePromptId(run);
        if (!run || promptId <= 0) {
            return;
        }

        run.cards.forEach(function (entry) {
            if (!entry || entry.exploding || toInt(entry.promptId) !== promptId) {
                return;
            }
            entry.resolvedFalling = true;
            entry.speed = Math.max((Number(entry.speed) || 0) * 1.28, run.cardSpeed * 1.18);
            if (normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG) {
                entry.releaseDriftX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(14, 34);
            }
        });
    }

    function handleCorrectHit(ctx, card) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }
        const gameConfig = getGameConfig(ctx, run);
        const isBubbleGame = isBubblePopRun(ctx, run);
        const feedbackPlayback = playFeedbackSound(ctx, 'correct');

        queueExposureOnce(ctx, run.prompt);
        queueOutcome(ctx, run.prompt, true, !!run.prompt.hadWrongBefore, { event_source: getRunEventSource(run) });

        run.coins += Math.max(1, toInt(gameConfig && gameConfig.correctCoinReward) || 1);
        updateHud(ctx);
        card.exploding = true;
        card.explosionStyle = isBubbleGame ? 'bubble-correct' : 'correct';
        card.explosionDuration = isBubbleGame ? 280 : 220;
        card.removeAt = currentTimestamp() + card.explosionDuration;
        spawnExplosion(run, {
            x: card.x,
            y: card.y,
            radius: isBubbleGame ? (card.width * 0.98) : (card.width * 0.72),
            primaryColor: isBubbleGame ? 'rgba(154, 221, 255, 0.98)' : 'rgba(16, 185, 129, 0.96)',
            secondaryColor: isBubbleGame ? 'rgba(255, 255, 255, 0.9)' : 'rgba(103, 232, 249, 0.78)',
            duration: isBubbleGame ? 340 : 300,
            style: isBubbleGame ? 'bubble-pop' : 'ring',
            rayCount: isBubbleGame ? 10 : 0
        });

        markPromptResolved(run);
        releaseResolvedPromptCards(run, card);
        if (isBubbleGame) {
            applyBubbleBlastImpulse(run, card.x, card.y, getBubbleRadius(card), card);
            boostBubbleResolvedPromptExit(run, card.promptId, card.x, card.y);
        }
        feedbackPlayback.finally(function () {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            if (maybePauseForInactivity(ctx)) {
                return;
            }
            startNextPrompt(ctx);
        });
    }

    function handleWrongHit(ctx, card) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }
        const gameConfig = getGameConfig(ctx, run);
        const isBubbleGame = isBubblePopRun(ctx, run);
        const now = currentTimestamp();
        if (isWrongHitRecoveryActive(run, now)) {
            return;
        }
        const feedbackPlayback = playFeedbackSound(ctx, 'wrong');

        queueExposureOnce(ctx, run.prompt);
        queueOutcome(ctx, run.prompt, false, false, { event_source: getRunEventSource(run), wrong_hit: true });
        run.prompt.hadWrongBefore = true;
        run.prompt.wrongCount += 1;
        run.prompt.wrongHitRecoveryUntil = now + getWrongHitRecoveryMs(ctx);

        applyPromptLifePenalty(run, Math.max(1, toInt(gameConfig && gameConfig.wrongHitLifePenalty) || 1));
        run.coins = Math.max(0, run.coins - Math.max(0, toInt(gameConfig && gameConfig.wrongHitCoinPenalty)));
        run.bullets.length = 0;
        run.lastFireAt = now;
        setControlState(ctx, 'fire', false);
        updateHud(ctx);

        card.exploding = true;
        card.explosionStyle = isBubbleGame ? 'bubble-wrong' : 'dramatic';
        card.explosionDuration = isBubbleGame ? 520 : 420;
        card.removeAt = now + card.explosionDuration;
        if (isBubbleGame) {
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.52,
                primaryColor: 'rgba(255, 134, 105, 0.98)',
                secondaryColor: 'rgba(255, 241, 236, 0.88)',
                duration: 560,
                style: 'bubble-ray-burst',
                rayCount: 18,
                spin: 0.16
            });
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.9,
                primaryColor: 'rgba(255, 173, 153, 0.62)',
                secondaryColor: 'rgba(255, 255, 255, 0.54)',
                duration: 420,
                style: 'bubble-ray-burst',
                rayCount: 10,
                spin: -0.12
            });
            applyBubbleBlastImpulse(run, card.x, card.y, Math.max(card.width, card.height) * 0.92, card);
        } else {
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.12,
                primaryColor: 'rgba(248, 113, 113, 0.98)',
                secondaryColor: 'rgba(251, 191, 36, 0.92)',
                duration: 520,
                style: 'burst',
                rayCount: 18,
                spin: 0.18
            });
            spawnExplosion(run, {
                x: card.x + ((Math.random() * 18) - 9),
                y: card.y + ((Math.random() * 18) - 9),
                radius: Math.max(card.width, card.height) * 0.82,
                primaryColor: 'rgba(255, 255, 255, 0.94)',
                secondaryColor: 'rgba(249, 115, 22, 0.9)',
                duration: 360,
                style: 'burst',
                rayCount: 12,
                spin: -0.22
            });
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.34,
                primaryColor: 'rgba(255, 214, 10, 0.7)',
                secondaryColor: 'rgba(248, 113, 113, 0.55)',
                duration: 420,
                style: 'ring'
            });
        }

        if (run.lives <= 0) {
            markPromptResolved(run);
            resetRunControls(run);
            clearControlUi(ctx);
            feedbackPlayback.finally(function () {
                if (ctx.run === run) {
                    endRun(ctx);
                }
            });
            return;
        }

        root.setTimeout(function () {
            if (!ctx.run || ctx.run !== run || run.paused || run.ended || !run.prompt || run.prompt.resolved) {
                return;
            }
            if (activePromptId(run) !== toInt(card && card.promptId)) {
                return;
            }
            playPromptAudio(ctx, {
                allowAutoReplay: true,
                ignoreFeedbackQueue: true
            });
        }, 72);
    }

    function handlePromptTimeout(ctx) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }
        const gameConfig = getGameConfig(ctx, run);
        pausePromptAudio(ctx);
        const untouchedTimeout = !run.prompt.hadWrongBefore;

        if (untouchedTimeout) {
            queueExposureOnce(ctx, run.prompt);
            queueOutcome(ctx, run.prompt, false, false, {
                event_source: getRunEventSource(run),
                timeout: true
            });
        }

        run.coins = Math.max(0, run.coins - Math.max(0, toInt(gameConfig && gameConfig.timeoutCoinPenalty) || 1));
        updateHud(ctx);
        markPromptResolved(run);
        if (isBubblePopRun(ctx, run)) {
            releaseTimedOutPromptCards(run);
        } else {
            run.cards = run.cards.filter(function (card) {
                return !isActivePromptCard(run, card);
            });
        }
        if (run.lives <= 0) {
            run.ended = true;
            root.setTimeout(function () {
                endRun(ctx);
            }, 220);
            return;
        }
        if (maybePauseForInactivity(ctx)) {
            return;
        }
        schedulePrompt(ctx, 220);
    }

    function findTargetCard(run) {
        for (let index = 0; index < run.cards.length; index += 1) {
            if (run.cards[index] && run.cards[index].isTarget && !run.cards[index].exploding && isActivePromptCard(run, run.cards[index])) {
                return run.cards[index];
            }
        }
        return null;
    }

    function getCanvasPoint(ctx, event) {
        if (!ctx || !ctx.canvas || !ctx.run) {
            return null;
        }

        const rect = ctx.canvas.getBoundingClientRect();
        if (!rect || rect.width <= 0 || rect.height <= 0) {
            return null;
        }

        const originalEvent = event && event.originalEvent ? event.originalEvent : event;
        const touch = originalEvent && originalEvent.touches && originalEvent.touches.length
            ? originalEvent.touches[0]
            : (originalEvent && originalEvent.changedTouches && originalEvent.changedTouches.length
                ? originalEvent.changedTouches[0]
                : originalEvent);
        if (!touch) {
            return null;
        }

        return {
            x: (Number(touch.clientX || 0) - rect.left) * (ctx.run.width / rect.width),
            y: (Number(touch.clientY || 0) - rect.top) * (ctx.run.height / rect.height)
        };
    }

    function findBubbleCardAtPoint(run, point) {
        if (!run || !point) {
            return null;
        }

        for (let index = run.cards.length - 1; index >= 0; index -= 1) {
            const card = run.cards[index];
            if (!card || card.exploding || card.resolvedFalling || !isActivePromptCard(run, card)) {
                continue;
            }

            const radius = getBubbleRadius(card) * 1.04;
            const dx = Number(point.x) - Number(card.x);
            const dy = Number(point.y) - Number(card.y);
            if ((dx * dx) + (dy * dy) <= radius * radius) {
                return card;
            }
        }

        return null;
    }

    function findDecorativeBubbleAtPoint(run, point) {
        if (!run || !point) {
            return null;
        }

        for (let index = run.decorativeBubbles.length - 1; index >= 0; index -= 1) {
            const bubble = run.decorativeBubbles[index];
            if (!bubble || bubble.exploding) {
                continue;
            }

            const radius = getFloatingBodyRadius(bubble) * 1.08;
            const dx = Number(point.x) - Number(bubble.x);
            const dy = Number(point.y) - Number(bubble.y);
            if ((dx * dx) + (dy * dy) <= radius * radius) {
                return bubble;
            }
        }

        return null;
    }

    function handleCanvasPress(ctx, event) {
        const run = ctx.run;
        if (!run || run.paused || !isBubblePopRun(ctx, run)) {
            return false;
        }

        const point = getCanvasPoint(ctx, event);
        if (!point) {
            return false;
        }

        if (run.prompt && !run.prompt.resolved) {
            const card = findBubbleCardAtPoint(run, point);
            if (card) {
                if (card.isTarget) {
                    handleCorrectHit(ctx, card);
                } else {
                    handleWrongHit(ctx, card);
                }
                markRunActivity(ctx);
                return true;
            }
        }

        const decorativeBubble = findDecorativeBubbleAtPoint(run, point);
        if (decorativeBubble) {
            popDecorativeBubble(run, decorativeBubble);
            playDecorativeBubblePopSound(ctx);
            markRunActivity(ctx);
            return true;
        }

        return false;
    }

    function finishSpeakingStackRun(ctx, reason) {
        const run = ctx.run;
        if (!run || run.ended) {
            return;
        }

        run.ended = true;
        teardownSpeaking(ctx);
        flushProgress(ctx);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        stopTransientAudio(ctx);
        updatePauseUi(ctx);

        const isWin = reason === 'win';
        const remainingCards = getSpeakingStackActiveCards(run);
        if (!isWin) {
            remainingCards.forEach(function (card) {
                queueOutcome(ctx, {
                    target: card.word,
                    recordingType: 'isolation',
                    gameSlug: SPEAKING_STACK_GAME_SLUG
                }, false, false, {
                    event_source: 'speaking_stack',
                    stack_end_reason: String(reason || 'stacked')
                });
            });
        }

        showOverlay(
            ctx,
            isWin
                ? String(ctx.i18n.gamesSpeakingStackWinTitle || 'You cleared the stack')
                : String(
                    reason === 'silence'
                        ? (ctx.i18n.gamesSpeakingStackLoseSilenceTitle || 'Time ran out')
                        : (ctx.i18n.gamesSpeakingStackLoseStackedTitle || 'The stack reached the top')
                ),
            formatMessage(ctx.i18n.gamesSpeakingStackSummary || 'Cleared: %1$d of %2$d', [
                toInt(run.clearedCount),
                toInt(run.totalWordCount)
            ]),
            {
                mode: 'game-over',
                primaryLabel: String(isWin
                    ? (ctx.i18n.gamesNewGame || 'New game')
                    : (ctx.i18n.gamesReplayRun || 'Replay')),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function stepSpeakingStackRun(ctx, now, dtMs) {
        const run = ctx.run;
        if (!run || run.ended) {
            return;
        }

        const simulationWindowMs = Math.min(160, Math.max(0, dtMs || 0));
        if (!run.allWordsQueued && now >= Number(run.nextSpawnAt || 0) && !isSpeakingStackSpawnBlocked(ctx, run, now)) {
            spawnSpeakingStackCard(ctx, run, now);
            scheduleNextSpeakingStackSpawn(ctx, run, now);
            setSpeakingStackProgressFromRun(ctx, run);
        }

        // Catch up through short main-thread stalls without turning a dropped frame into slow-motion falling.
        let remainingMs = simulationWindowMs;
        while (remainingMs > 0.01) {
            const dt = Math.min(40, remainingMs) / 1000;
            run.cards.forEach(function (card) {
                if (!card || card.exploding) {
                    return;
                }

                const targetX = isFinite(Number(card.stackTargetX)) ? Number(card.stackTargetX) : Number(card.x || 0);
                const targetY = isFinite(Number(card.stackTargetY)) ? Number(card.stackTargetY) : Number(card.y || 0);
                if (Number(card.y) < targetY) {
                    const nextY = Math.min(targetY, Number(card.y || 0) + ((Number(card.speed) || 0) * dt));
                    const startY = isFinite(Number(card.entryStartY)) ? Number(card.entryStartY) : nextY;
                    const startX = isFinite(Number(card.entryStartX)) ? Number(card.entryStartX) : targetX;
                    const fallProgress = clamp((nextY - startY) / Math.max(1, targetY - startY), 0, 1);

                    card.y = nextY;
                    card.x = lerp(startX, targetX, easeOutCubic(fallProgress));
                } else {
                    card.y = targetY;
                    card.x = targetX;
                    card.lastSettledX = targetX;
                    card.lastSettledY = targetY;
                }
            });
            remainingMs -= 40;
        }

        if (run.allWordsQueued && !getSpeakingStackActiveCards(run).length && !run.cards.some(function (card) { return !!(card && card.exploding); })) {
            finishSpeakingStackRun(ctx, 'win');
            removeResolvedObjects(run, now);
            return;
        }

        const topDangerY = getSpeakingStackTopDangerY(ctx, run);
        const reachedTop = getSpeakingStackActiveCards(run).some(function (card) {
            const occupiedY = Number(card.y || 0);
            const targetY = Number(card.stackTargetY || occupiedY);
            const hasLanded = Math.abs(occupiedY - targetY) <= 0.5;
            return hasLanded && (occupiedY - (Number(card.height || 0) / 2)) <= topDangerY;
        });
        if (reachedTop) {
            finishSpeakingStackRun(ctx, 'stacked');
            removeResolvedObjects(run, now);
            return;
        }

        if (
            run.allWordsQueued
            && getSpeakingStackActiveCards(run).length
            && (now - Math.max(Number(run.finalSpawnedAt || 0), Number(run.lastSpeechAt || 0))) >= Math.max(1000, toInt((getGameConfig(ctx, run) || {}).finalSilenceMs) || 10000)
        ) {
            finishSpeakingStackRun(ctx, 'silence');
            removeResolvedObjects(run, now);
            return;
        }

        removeResolvedObjects(run, now);
    }

    function stepRun(ctx, now, dtMs) {
        const run = ctx.run;
        if (!run || !run.prompt) {
            if (run && isSpeakingStackRun(ctx, run)) {
                stepSpeakingStackRun(ctx, now, dtMs);
            }
            return;
        }
        if (isSpeakingStackRun(ctx, run)) {
            stepSpeakingStackRun(ctx, now, dtMs);
            return;
        }

        const dt = Math.min(40, Math.max(0, dtMs || 0)) / 1000;
        if (isBubblePopRun(ctx, run)) {
            run.cards.forEach(function (card) {
                if (!card || card.exploding) {
                    return;
                }

                if (!card.resolvedFalling && card.entryRevealMs > 0) {
                    const elapsedMs = Math.max(0, now - Number(card.entryStartedAt || 0));
                    if (elapsedMs < card.entryRevealMs) {
                        const easedProgress = easeOutCubic(elapsedMs / card.entryRevealMs);
                        setFloatingBodyPosition(run, card, lerp(card.entryStartX, card.entryVisibleX, easedProgress), lerp(card.entryStartY, card.entryVisibleY, easedProgress), {
                            clampY: false
                        });
                        return;
                    }

                    card.entryRevealMs = 0;
                    card.bubbleBaseX = Number(card.entryVisibleX);
                    card.bubbleBaseY = Number(card.entryVisibleY);
                    const floatReference = ensureBubbleFloatReference(card, now);
                    card.bubbleFloatReferenceX = floatReference.x;
                    card.bubbleFloatReferenceY = floatReference.y;
                    card.x = Number(card.entryVisibleX);
                    card.y = Number(card.entryVisibleY);
                }

                const impulseDamping = Math.max(0, 1 - (5.2 * dt));
                card.bubbleImpulseVelocityX = (Number(card.bubbleImpulseVelocityX) || 0) * impulseDamping;
                card.bubbleImpulseVelocityY = (Number(card.bubbleImpulseVelocityY) || 0) * impulseDamping;
                if (card.resolvedFalling) {
                    const releaseMaxSpeed = Math.max(
                        BUBBLE_RELEASE_MAX_SPEED,
                        Number(card.releaseMaxSpeed) || 0
                    );
                    const releaseDriftY = isFinite(Number(card.releaseDriftY))
                        ? Number(card.releaseDriftY)
                        : -card.speed;
                    const releaseVelocity = clampVectorMagnitude(
                        Number(card.releaseDriftX) + (Number(card.bubbleImpulseVelocityX) || 0),
                        releaseDriftY + (Number(card.bubbleImpulseVelocityY) || 0),
                        releaseMaxSpeed
                    );
                    card.bubbleBaseY = Number(card.bubbleBaseY || card.y) + (releaseVelocity.y * dt);
                    card.bubbleBaseX = Number(card.bubbleBaseX || card.x) + (releaseVelocity.x * dt);
                    return;
                }

                const bounds = getBubbleMovementBounds(run, getBubbleRadius(card));
                const activeVelocity = clampVectorMagnitude(
                    Number(card.bubbleWanderVelocityX) + (Number(card.bubbleImpulseVelocityX) || 0),
                    -card.speed + (Number(card.bubbleImpulseVelocityY) || 0),
                    BUBBLE_ACTIVE_MAX_SPEED
                );
                card.bubbleBaseY = Number(card.bubbleBaseY || card.entryVisibleY || card.y) + (activeVelocity.y * dt);
                card.bubbleBaseX = Number(card.bubbleBaseX || card.entryVisibleX || card.x) + (activeVelocity.x * dt);
                if (card.bubbleBaseX <= bounds.minX || card.bubbleBaseX >= bounds.maxX) {
                    card.bubbleWanderVelocityX *= -1;
                    card.bubbleBaseX = clamp(card.bubbleBaseX, bounds.minX, bounds.maxX);
                }
            });

            refreshBubblePromptCardPositions(run, now);
            updateDecorativeBubblePositions(run, now, dt);
        } else {
            const gameConfig = getGameConfig(ctx, run);
            const direction = (run.controls.right ? 1 : 0) - (run.controls.left ? 1 : 0);
            if (direction !== 0) {
                run.shipX = clamp(
                    run.shipX + (direction * run.metrics.shipSpeed * dt),
                    run.metrics.shipWidth / 2,
                    run.width - (run.metrics.shipWidth / 2)
                );
            }

            if (run.controls.fire && (now - run.lastFireAt) >= Math.max(80, toInt(gameConfig && gameConfig.fireIntervalMs) || 165)) {
                fireBullet(run);
                run.lastFireAt = now;
            }

            run.bullets.forEach(function (bullet) {
                bullet.y -= bullet.speed * dt;
            });
            run.bullets = run.bullets.filter(function (bullet) {
                return bullet.y > -16;
            });

            run.cards.forEach(function (card) {
                if (!card.exploding) {
                    if (!card.resolvedFalling && card.entryRevealMs > 0) {
                        const elapsedMs = Math.max(0, now - Number(card.entryStartedAt || 0));
                        if (elapsedMs < card.entryRevealMs) {
                            const easedProgress = easeOutCubic(elapsedMs / card.entryRevealMs);
                            card.y = card.entryStartY + ((card.entryVisibleY - card.entryStartY) * easedProgress);
                            return;
                        }
                        card.entryRevealMs = 0;
                        card.y = card.entryVisibleY;
                    }
                    card.y += card.speed * dt;
                }
            });
        }

        let collisionHandled = false;
        if (!run.prompt.resolved && isSpaceShooterRun(ctx, run)) {
            outerLoop:
            for (let bulletIndex = run.bullets.length - 1; bulletIndex >= 0; bulletIndex -= 1) {
                const bullet = run.bullets[bulletIndex];
                for (let cardIndex = 0; cardIndex < run.cards.length; cardIndex += 1) {
                    const card = run.cards[cardIndex];
                    if (!card || card.exploding || card.resolvedFalling || !isActivePromptCard(run, card)) {
                        continue;
                    }
                    const hit = bullet.x >= (card.x - (card.width / 2))
                        && bullet.x <= (card.x + (card.width / 2))
                        && bullet.y >= (card.y - (card.height / 2))
                        && bullet.y <= (card.y + (card.height / 2));
                    if (!hit) {
                        continue;
                    }

                    run.bullets.splice(bulletIndex, 1);
                    if (card.isTarget) {
                        handleCorrectHit(ctx, card);
                    } else {
                        handleWrongHit(ctx, card);
                    }
                    markRunActivity(ctx);
                    collisionHandled = true;
                    break outerLoop;
                }
            }
        }

        if (collisionHandled) {
            removeResolvedObjects(run, now);
            return;
        }

        const targetCard = findTargetCard(run);
        if (!run.prompt.resolved && !isWrongHitRecoveryActive(run, now)) {
            if (!targetCard) {
                handlePromptTimeout(ctx);
            } else if (
                (isBubblePopRun(ctx, run) && (targetCard.y + (targetCard.height / 2)) < 0)
                || (isSpaceShooterRun(ctx, run) && (targetCard.y - (targetCard.height / 2)) > run.height)
            ) {
                handlePromptTimeout(ctx);
            }
        }

        removeResolvedObjects(run, now);
    }

    function runLoop(ctx, timestamp) {
        const run = ctx.run;
        if (!run) {
            return;
        }

        if (!run.lastFrameAt) {
            run.lastFrameAt = timestamp;
        }

        if (run.paused) {
            renderRun(ctx, timestamp);
            run.lastFrameAt = timestamp;
            run.rafId = root.requestAnimationFrame(function (nextFrameAt) {
                runLoop(ctx, nextFrameAt);
            });
            return;
        }

        stepRun(ctx, timestamp, timestamp - run.lastFrameAt);
        renderRun(ctx, timestamp);
        run.lastFrameAt = timestamp;
        if (run.ended) {
            run.rafId = 0;
            return;
        }
        run.rafId = root.requestAnimationFrame(function (nextFrameAt) {
            runLoop(ctx, nextFrameAt);
        });
    }

    function showOverlay(ctx, title, summary, options) {
        const opts = options || {};
        const summaryText = String(summary || '');
        const primaryLabel = Object.prototype.hasOwnProperty.call(opts, 'primaryLabel')
            ? String(opts.primaryLabel || '')
            : String(ctx.i18n.gamesReplayRun || 'Replay');
        const secondaryLabel = Object.prototype.hasOwnProperty.call(opts, 'secondaryLabel')
            ? String(opts.secondaryLabel || '')
            : String(ctx.i18n.gamesBackToCatalog || 'Back to games');
        const isLoadingMode = String(opts.mode || '') === 'loading';

        ctx.overlayMode = String(opts.mode || '');
        ctx.$overlayTitle.text(title);
        ctx.$overlaySummary.text(summaryText).prop('hidden', summaryText === '');
        if (ctx.$overlayPrimary && ctx.$overlayPrimary.length) {
            ctx.$overlayPrimary.text(primaryLabel).prop('hidden', primaryLabel === '');
        }
        if (ctx.$overlaySecondary && ctx.$overlaySecondary.length) {
            ctx.$overlaySecondary.text(secondaryLabel).prop('hidden', secondaryLabel === '');
        }
        if (ctx.$overlayCard && ctx.$overlayCard.length) {
            ctx.$overlayCard.prop('hidden', isLoadingMode);
        }
        if (ctx.$overlayLoading && ctx.$overlayLoading.length) {
            ctx.$overlayLoading.prop('hidden', !isLoadingMode);
        }
        if (ctx.$overlayLoadingText && ctx.$overlayLoadingText.length) {
            ctx.$overlayLoadingText.text(title);
        }
        ctx.$overlay
            .attr('data-ll-wordset-game-overlay-mode', ctx.overlayMode || '')
            .attr('aria-busy', isLoadingMode ? 'true' : 'false')
            .prop('hidden', false);
    }

    function hideOverlay(ctx) {
        ctx.overlayMode = '';
        if (ctx.$overlayCard && ctx.$overlayCard.length) {
            ctx.$overlayCard.prop('hidden', false);
        }
        if (ctx.$overlayLoading && ctx.$overlayLoading.length) {
            ctx.$overlayLoading.prop('hidden', true);
        }
        if (ctx.$overlayPrimary && ctx.$overlayPrimary.length) {
            ctx.$overlayPrimary.prop('hidden', false);
        }
        if (ctx.$overlaySecondary && ctx.$overlaySecondary.length) {
            ctx.$overlaySecondary.prop('hidden', false);
        }
        ctx.$overlay
            .attr('data-ll-wordset-game-overlay-mode', '')
            .removeAttr('aria-busy')
            .prop('hidden', true);
    }

    function applyInitialPromptWhenReady(ctx, run, onReady) {
        if (!ctx || !run || !ctx.run || ctx.run !== run) {
            return;
        }

        if (run.loadingHideTimer) {
            root.clearTimeout(run.loadingHideTimer);
            run.loadingHideTimer = 0;
        }

        const shownAt = Number(run.loadingShownAt) || 0;
        const remainingMs = Math.max(0, INITIAL_LOADING_OVERLAY_MIN_MS - Math.max(0, currentTimestamp() - shownAt));
        if (remainingMs <= 0) {
            onReady();
            return;
        }

        run.loadingHideTimer = root.setTimeout(function () {
            run.loadingHideTimer = 0;
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            onReady();
        }, remainingMs);
    }

    function pauseRun(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const run = ctx.run;
        if (!run || run.ended || run.paused) {
            return;
        }

        run.paused = true;
        clearPromptTimer(run, opts.preservePromptTimer !== false);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        run.resumeAction = String(opts.resumeAction || '');
        if (!run.resumeAction && (!run.prompt || run.prompt.resolved)) {
            run.resumeAction = RESUME_ACTION_NEXT_PROMPT;
        }
        run.pauseReason = String(opts.reason || 'manual');
        if (isSpeakingStackRun(ctx, run)) {
            teardownSpeaking(ctx);
            setSpeakingStatus(ctx, String(ctx.i18n.gamesPaused || 'Paused'));
        }
        updatePauseUi(ctx);
        showOverlay(
            ctx,
            Object.prototype.hasOwnProperty.call(opts, 'title')
                ? String(opts.title || '')
                : String(ctx.i18n.gamesPaused || 'Paused'),
            Object.prototype.hasOwnProperty.call(opts, 'summary')
                ? String(opts.summary || '')
                : '',
            {
                mode: 'paused',
                primaryLabel: String(ctx.i18n.gamesResumeRun || 'Resume'),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function resumeRun(ctx) {
        const run = ctx.run;
        if (!run || !run.paused) {
            return;
        }

        const resumeAction = String(run.resumeAction || '');
        const pauseReason = String(run.pauseReason || '');
        run.paused = false;
        run.lastFrameAt = 0;
        run.resumeAction = '';
        run.pauseReason = '';
        if (pauseReason === PAUSE_REASON_INACTIVITY) {
            run.inactiveRounds = 0;
        }
        hideOverlay(ctx);
        updatePauseUi(ctx);

        if (isSpeakingStackRun(ctx, run)) {
            setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackReady || 'Mic ready'));
            queueSpeakingStackCaptureRestart(ctx, 40);
            return;
        }

        if (resumeAction === RESUME_ACTION_NEXT_PROMPT) {
            startNextPrompt(ctx);
            return;
        }

        if (run.promptTimerRemainingMs > 0) {
            schedulePrompt(ctx, run.promptTimerRemainingMs);
            return;
        }

        if (run.prompt && !run.prompt.resolved) {
            playPromptAudio(ctx, {
                allowAutoReplay: false
            });
        }
    }

    function endRun(ctx, reason) {
        const run = ctx.run;
        if (!run) {
            return;
        }
        const isWin = String(reason || '') === 'win';

        run.ended = true;
        run.awaitingPrompt = false;
        run.nextPreparedPrompt = null;
        run.nextPromptPromise = null;
        if (run.rafId) {
            root.cancelAnimationFrame(run.rafId);
            run.rafId = 0;
        }
        if (run.loadingHideTimer) {
            root.clearTimeout(run.loadingHideTimer);
            run.loadingHideTimer = 0;
        }
        run.paused = false;
        run.resumeAction = '';
        run.pauseReason = '';
        clearPromptTimer(run, false);
        resetRunControls(run);
        clearControlUi(ctx);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        stopTransientAudio(ctx);
        updatePauseUi(ctx);
        flushProgress(ctx);

        const summary = isWin
            ? formatMessage(ctx.i18n.gamesWinSummary || 'Completed: %1$d of %2$d · Coins: %3$d', [
                toInt(run.promptsResolved),
                Math.max(toInt(run.promptsResolved), getRunTotalRounds(run)),
                toInt(run.coins)
            ])
            : formatMessage(ctx.i18n.gamesSummary || 'Coins: %1$d · Prompts: %2$d', [
                run.coins,
                run.promptsResolved
            ]);
        showOverlay(
            ctx,
            String(isWin
                ? (ctx.i18n.gamesWinTitle || 'You win')
                : (ctx.i18n.gamesGameOver || 'Run Complete')),
            summary,
            {
                mode: 'game-over',
                primaryLabel: String(isWin
                    ? (ctx.i18n.gamesNewGame || 'New game')
                    : (ctx.i18n.gamesReplayRun || 'Replay')),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function stopRun(ctx, options) {
        const opts = options || {};
        const run = ctx.run;
        teardownSpeaking(ctx);
        if (!run) {
            return;
        }

        if (run.rafId) {
            root.cancelAnimationFrame(run.rafId);
            run.rafId = 0;
        }
        run.paused = false;
        run.awaitingPrompt = false;
        run.nextPreparedPrompt = null;
        run.nextPromptPromise = null;
        run.resumeAction = '';
        run.pauseReason = '';
        clearPromptTimer(run, false);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        stopTransientAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        if (opts.flush !== false) {
            flushProgress(ctx);
        }
        resetLineupStage(ctx);
        ctx.run = null;
        updatePauseUi(ctx);
    }

    function showCatalog(ctx, options) {
        resetGamesSurface(ctx, options);
        syncCanvasSize(ctx);
    }

    function selectRoundWords(entry, roundGoal) {
        const words = Array.isArray(entry && entry.words) ? entry.words.slice() : [];
        const desiredCount = Math.max(0, toInt(roundGoal));
        if (!desiredCount || words.length <= desiredCount) {
            return words;
        }

        return limitLaunchWords(words, desiredCount);
    }

    function selectShuffledRoundWords(ctx, gameSlug, entry, roundGoal) {
        const words = selectRoundWords(entry, roundGoal);
        if (words.length < 2) {
            return words;
        }

        ctx.runWordOrderHistory = (ctx.runWordOrderHistory && typeof ctx.runWordOrderHistory === 'object')
            ? ctx.runWordOrderHistory
            : {};

        const historyKey = normalizeGameSlug(gameSlug || '');
        const previousSignature = String(ctx.runWordOrderHistory[historyKey] || '');
        const fallbackSignature = previousSignature || getOrderedIdSignature(words);
        let shuffledWords = shuffleAvoidingSignatures(words, fallbackSignature, 18);
        let shuffledSignature = getOrderedIdSignature(shuffledWords);

        if (shuffledSignature === fallbackSignature) {
            const baseSignature = getOrderedIdSignature(words);
            if (baseSignature !== fallbackSignature) {
                shuffledWords = words.slice();
                shuffledSignature = baseSignature;
            } else {
                const rotatedWords = words.slice(1).concat(words.slice(0, 1));
                shuffledWords = rotatedWords.length === words.length ? rotatedWords : words.slice();
                shuffledSignature = getOrderedIdSignature(shuffledWords);
            }
        }

        ctx.runWordOrderHistory[historyKey] = shuffledSignature;
        return shuffledWords;
    }

    function selectRoundTargets(entry, roundGoal) {
        const targets = Array.isArray(entry && entry.playableTargets) ? entry.playableTargets.slice() : [];
        const desiredCount = Math.max(0, toInt(roundGoal));
        if (!desiredCount || targets.length <= desiredCount) {
            return targets;
        }

        return limitLaunchWords(targets, desiredCount);
    }

    function selectRoundSequences(entry, roundGoal) {
        const sequences = Array.isArray(entry && entry.sequences) ? entry.sequences.slice() : [];
        const desiredCount = Math.max(0, toInt(roundGoal));
        if (!desiredCount || sequences.length <= desiredCount) {
            return sequences;
        }

        return sequences.slice(0, desiredCount);
    }

    function getLineupMoveArrow(direction, move) {
        const isRtl = normalizeLineupDirection(direction) === 'rtl';
        if (move === 'earlier') {
            return isRtl ? '\u2192' : '\u2190';
        }
        return isRtl ? '\u2190' : '\u2192';
    }

    function resetLineupStage(ctx) {
        if (!ctx) {
            return;
        }
        teardownUnscrambleDrag(ctx);
        if (ctx.$lineupProgress && ctx.$lineupProgress.length) {
            ctx.$lineupProgress.text('');
        }
        if (ctx.$lineupCategory && ctx.$lineupCategory.length) {
            ctx.$lineupCategory.text('');
        }
        if (ctx.$lineupInstruction && ctx.$lineupInstruction.length) {
            ctx.$lineupInstruction.text(String(ctx.i18n.gamesLineupInstruction || 'Put the cards in the correct order.'));
        }
        if (ctx.$lineupPrompt && ctx.$lineupPrompt.length) {
            ctx.$lineupPrompt.prop('hidden', true);
        }
        if (ctx.$lineupPromptImageWrap && ctx.$lineupPromptImageWrap.length) {
            ctx.$lineupPromptImageWrap.prop('hidden', true);
        }
        if (ctx.$lineupPromptImage && ctx.$lineupPromptImage.length) {
            ctx.$lineupPromptImage.attr('src', '').attr('alt', '');
        }
        if (ctx.$lineupPromptTextWrap && ctx.$lineupPromptTextWrap.length) {
            ctx.$lineupPromptTextWrap.prop('hidden', true);
        }
        if (ctx.$lineupPromptText && ctx.$lineupPromptText.length) {
            ctx.$lineupPromptText.text('');
        }
        if (ctx.$lineupPromptLabel && ctx.$lineupPromptLabel.length) {
            ctx.$lineupPromptLabel.text(String(ctx.i18n.gamesUnscramblePromptLabel || 'Clue'));
        }
        if (ctx.$lineupStatus && ctx.$lineupStatus.length) {
            ctx.$lineupStatus.text('').attr('data-lineup-status-kind', '').prop('hidden', true);
        }
        if (ctx.$lineupCards && ctx.$lineupCards.length) {
            ctx.$lineupCards.empty().attr('dir', 'ltr');
        }
        if (ctx.$lineupShuffle && ctx.$lineupShuffle.length) {
            ctx.$lineupShuffle.prop('disabled', true);
        }
        if (ctx.$lineupCheck && ctx.$lineupCheck.length) {
            ctx.$lineupCheck
                .text(String(ctx.i18n.gamesLineupCheck || 'Check'))
                .removeClass('ll-wordset-lineup-stage__action--ghost ll-wordset-lineup-stage__action--skip')
                .addClass('ll-wordset-lineup-stage__action--primary')
                .prop('hidden', false)
                .prop('disabled', true);
        }
        if (ctx.$lineupNext && ctx.$lineupNext.length) {
            ctx.$lineupNext.prop('hidden', true).prop('disabled', true);
        }
    }

    function setLineupStatus(ctx, text, kind) {
        if (!(ctx && ctx.$lineupStatus && ctx.$lineupStatus.length)) {
            return;
        }

        const message = String(text || '').trim();
        ctx.$lineupStatus
            .text(message)
            .attr('data-lineup-status-kind', String(kind || ''))
            .prop('hidden', message === '');
    }

    function clearLineupCheck(run) {
        if (!run) {
            return;
        }
        run.lineupCheck = null;
    }

    function setLineupPrompt(ctx, prompt) {
        const data = (prompt && typeof prompt === 'object') ? prompt : {};
        const promptType = String(data.type || '').trim().toLowerCase();
        const promptText = String(data.text || '').trim();
        const promptImage = String(data.image || '').trim();
        const showPrompt = promptType === 'image'
            ? promptImage !== ''
            : promptText !== '';

        if (ctx.$lineupPrompt && ctx.$lineupPrompt.length) {
            ctx.$lineupPrompt.prop('hidden', !showPrompt);
        }
        if (ctx.$lineupPromptImageWrap && ctx.$lineupPromptImageWrap.length) {
            ctx.$lineupPromptImageWrap.prop('hidden', !(showPrompt && promptType === 'image' && promptImage !== ''));
        }
        if (ctx.$lineupPromptImage && ctx.$lineupPromptImage.length) {
            ctx.$lineupPromptImage
                .attr('src', promptType === 'image' ? promptImage : '')
                .attr('alt', promptType === 'image'
                    ? String(ctx.i18n.gamesUnscramblePromptLabel || 'Clue')
                    : '');
        }
        if (ctx.$lineupPromptTextWrap && ctx.$lineupPromptTextWrap.length) {
            ctx.$lineupPromptTextWrap.prop('hidden', !(showPrompt && promptType !== 'image' && promptText !== ''));
        }
        if (ctx.$lineupPromptText && ctx.$lineupPromptText.length) {
            ctx.$lineupPromptText.text(promptType === 'image' ? '' : promptText);
        }
        if (ctx.$lineupPromptLabel && ctx.$lineupPromptLabel.length) {
            ctx.$lineupPromptLabel.text(String(ctx.i18n.gamesUnscramblePromptLabel || 'Clue'));
        }
    }

    function cloneUnscrambleUnits(units) {
        return (Array.isArray(units) ? units : []).map(function (unit, index) {
            const row = (unit && typeof unit === 'object') ? unit : {};
            return {
                id: toInt(row.id) || (index + 1),
                text: String(row.text || ''),
                movable: !!row.movable,
                target_position: Math.max(0, toInt(row.target_position) || index)
            };
        });
    }

    function getUnscrambleMovableSlotIndices(units) {
        const slots = [];
        (Array.isArray(units) ? units : []).forEach(function (unit, index) {
            if (unit && unit.movable) {
                slots.push(index);
            }
        });
        return slots;
    }

    function applyUnscrambleMovableUnitOrder(units, movableUnits) {
        const sourceUnits = cloneUnscrambleUnits(units);
        const sourceMovableUnits = Array.isArray(movableUnits) ? movableUnits : [];
        let movableIndex = 0;

        return sourceUnits.map(function (unit) {
            if (!unit.movable) {
                return unit;
            }

            const nextUnit = sourceMovableUnits[movableIndex] || unit;
            movableIndex += 1;
            return {
                id: toInt(nextUnit.id) || unit.id,
                text: String(nextUnit.text || ''),
                movable: true,
                target_position: unit.target_position
            };
        });
    }

    function buildUnscrambleOrder(units, maxAttempts) {
        const sourceUnits = cloneUnscrambleUnits(units);
        const movableUnits = sourceUnits.filter(function (unit) {
            return !!unit.movable;
        });
        if (movableUnits.length < 2) {
            return sourceUnits;
        }

        const signature = movableUnits.map(function (unit) { return String(unit.id); }).join(',');
        const attempts = Math.max(1, toInt(maxAttempts) || 6);
        let shuffledMovable = movableUnits.slice();
        let remaining = attempts;

        while (remaining > 0) {
            shuffledMovable = shuffle(movableUnits);
            if (shuffledMovable.map(function (unit) { return String(unit.id); }).join(',') !== signature) {
                break;
            }
            remaining -= 1;
        }

        return applyUnscrambleMovableUnitOrder(sourceUnits, shuffledMovable);
    }

    function findUnscrambleSwapIndex(units, currentIndex, moveDirection) {
        const direction = moveDirection === 'earlier' ? -1 : 1;
        let pointer = currentIndex + direction;
        while (pointer >= 0 && pointer < units.length) {
            if (units[pointer] && units[pointer].movable) {
                return pointer;
            }
            pointer += direction;
        }
        return -1;
    }

    function reorderUnscrambleUnits(units, currentIndex, targetIndex) {
        const sourceUnits = cloneUnscrambleUnits(units);
        const movableSlotIndices = getUnscrambleMovableSlotIndices(sourceUnits);
        const sourceSlot = movableSlotIndices.indexOf(currentIndex);
        const targetSlot = movableSlotIndices.indexOf(targetIndex);

        if (sourceSlot < 0 || targetSlot < 0 || sourceSlot === targetSlot) {
            return null;
        }

        const movableUnits = movableSlotIndices.map(function (slotIndex) {
            return sourceUnits[slotIndex];
        });
        const movedUnit = movableUnits.splice(sourceSlot, 1)[0];
        movableUnits.splice(targetSlot, 0, movedUnit);

        return applyUnscrambleMovableUnitOrder(sourceUnits, movableUnits);
    }

    function evaluateUnscrambleOrder(run) {
        const targetWord = run && run.currentWord;
        const currentUnits = Array.isArray(run && run.currentOrder) ? run.currentOrder : [];
        const targetUnits = Array.isArray(targetWord && targetWord.unscramble_units) ? targetWord.unscramble_units : [];
        const totalCount = Math.min(currentUnits.length, targetUnits.length);
        const correctUnitIds = {};
        const incorrectUnitIds = {};
        let matchedCount = 0;
        let movableCount = 0;

        for (let index = 0; index < totalCount; index += 1) {
            const currentUnit = currentUnits[index] || null;
            const targetUnit = targetUnits[index] || null;
            const currentId = toInt(currentUnit && currentUnit.id);
            const targetId = toInt(targetUnit && targetUnit.id);
            const currentMovable = !!(currentUnit && currentUnit.movable);

            if (!currentMovable) {
                continue;
            }

            movableCount += 1;
            if (currentId > 0 && currentId === targetId) {
                correctUnitIds[currentId] = true;
                matchedCount += 1;
            } else if (currentId > 0) {
                incorrectUnitIds[currentId] = true;
            }
        }

        return {
            matchedCount: matchedCount,
            totalCount: movableCount,
            correctUnitIds: correctUnitIds,
            incorrectUnitIds: incorrectUnitIds
        };
    }

    function getLineupPromptState(run, word, gameSlug) {
        if (!run || !word) {
            return null;
        }

        const wordId = toInt(word.id);
        if (!wordId) {
            return null;
        }

        run.lineupPromptState = (run.lineupPromptState && typeof run.lineupPromptState === 'object')
            ? run.lineupPromptState
            : {};

        if (!run.lineupPromptState[wordId]) {
            run.lineupPromptState[wordId] = {
                target: word,
                recordingType: '',
                gameSlug: normalizeGameSlug(gameSlug || LINEUP_GAME_SLUG),
                exposureTracked: false,
                hadWrongBefore: false
            };
        }

        return run.lineupPromptState[wordId];
    }

    function renderLineupSequence(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isLineupRun(ctx, run) || !run.currentSequence) {
            resetLineupStage(ctx);
            return;
        }

        const sequence = run.currentSequence;
        const direction = normalizeLineupDirection(sequence.direction);
        const words = Array.isArray(run.currentOrder) ? run.currentOrder.slice() : [];
        const checkState = (run.lineupCheck && typeof run.lineupCheck === 'object') ? run.lineupCheck : null;

        if (ctx.$lineupProgress && ctx.$lineupProgress.length) {
            ctx.$lineupProgress.text(formatMessage(
                ctx.i18n.gamesLineupProgress || 'Sequence %1$d of %2$d',
                [Math.max(1, toInt(run.currentSequenceIndex) + 1), Math.max(1, getRunTotalRounds(run))]
            ));
        }
        if (ctx.$lineupCategory && ctx.$lineupCategory.length) {
            ctx.$lineupCategory.text(String(sequence.category_name || ''));
        }
        if (ctx.$lineupInstruction && ctx.$lineupInstruction.length) {
            ctx.$lineupInstruction.text(String(ctx.i18n.gamesLineupInstruction || 'Put the cards in the correct order.'));
        }
        setLineupPrompt(ctx, null);

        if (ctx.$lineupCards && ctx.$lineupCards.length) {
            const markup = words.map(function (word, index) {
                const wordId = toInt(word && word.id);
                const isCorrect = !!(checkState && checkState.correctWordIds && checkState.correctWordIds[wordId]);
                const isIncorrect = !!(checkState && checkState.incorrectWordIds && checkState.incorrectWordIds[wordId]);
                const canMoveEarlier = !run.sequenceLocked && index > 0;
                const canMoveLater = !run.sequenceLocked && index < (words.length - 1);

                return '' +
                    '<li class="ll-wordset-lineup-stage__card'
                        + (isCorrect ? ' is-correct' : '')
                        + (isIncorrect ? ' is-incorrect' : '')
                        + '" data-ll-wordset-lineup-card data-lineup-index="' + escapeHtml(String(index + 1)) + '" data-word-id="' + escapeHtml(String(wordId)) + '">' +
                        '<div class="ll-wordset-lineup-stage__card-order" aria-hidden="true">' + escapeHtml(String(index + 1)) + '</div>' +
                        '<div class="ll-wordset-lineup-stage__card-body">' +
                            '<p class="ll-wordset-lineup-stage__card-text" dir="auto">' + escapeHtml(String(word.title || word.label || '')) + '</p>' +
                            '<div class="ll-wordset-lineup-stage__card-actions">' +
                                '<button type="button" class="ll-wordset-lineup-stage__move" data-ll-wordset-lineup-move="earlier"' + (canMoveEarlier ? '' : ' disabled') + '>' +
                                    '<span aria-hidden="true">' + escapeHtml(getLineupMoveArrow(direction, 'earlier')) + '</span>' +
                                    '<span class="screen-reader-text">' + escapeHtml(String(ctx.i18n.gamesLineupMoveEarlier || 'Move earlier')) + '</span>' +
                                '</button>' +
                                '<button type="button" class="ll-wordset-lineup-stage__move" data-ll-wordset-lineup-move="later"' + (canMoveLater ? '' : ' disabled') + '>' +
                                    '<span aria-hidden="true">' + escapeHtml(getLineupMoveArrow(direction, 'later')) + '</span>' +
                                    '<span class="screen-reader-text">' + escapeHtml(String(ctx.i18n.gamesLineupMoveLater || 'Move later')) + '</span>' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                    '</li>';
            }).join('');

            ctx.$lineupCards.html(markup).attr('dir', direction);
        }

        if (ctx.$lineupShuffle && ctx.$lineupShuffle.length) {
            ctx.$lineupShuffle.prop('disabled', !!run.sequenceLocked || words.length < 2);
        }
        if (ctx.$lineupCheck && ctx.$lineupCheck.length) {
            ctx.$lineupCheck
                .text(String(ctx.i18n.gamesLineupCheck || 'Check'))
                .removeClass('ll-wordset-lineup-stage__action--ghost ll-wordset-lineup-stage__action--skip')
                .addClass('ll-wordset-lineup-stage__action--primary');
            ctx.$lineupCheck.prop('hidden', false);
            ctx.$lineupCheck.prop('disabled', !!run.sequenceLocked || words.length < 2);
        }
        if (ctx.$lineupNext && ctx.$lineupNext.length) {
            const isLastSequence = (toInt(run.currentSequenceIndex) + 1) >= getRunTotalRounds(run);
            ctx.$lineupNext
                .text(String(isLastSequence
                    ? (ctx.i18n.gamesLineupFinish || 'Finish')
                    : (ctx.i18n.gamesLineupNext || 'Next')))
                .prop('hidden', !run.sequenceLocked)
                .prop('disabled', !run.sequenceLocked);
        }
    }

    function moveLineupCard(ctx, index, direction) {
        const run = ctx && ctx.run;
        if (!run || !isLineupRun(ctx, run) || run.sequenceLocked) {
            return false;
        }

        const currentOrder = Array.isArray(run.currentOrder) ? run.currentOrder.slice() : [];
        const currentIndex = parseInt(index, 10);
        if (!currentOrder.length || !isFinite(currentIndex) || currentIndex < 0 || currentIndex >= currentOrder.length) {
            return false;
        }

        let targetIndex = currentIndex;
        if (direction === 'earlier') {
            targetIndex -= 1;
        } else if (direction === 'later') {
            targetIndex += 1;
        }
        if (targetIndex < 0 || targetIndex >= currentOrder.length || targetIndex === currentIndex) {
            return false;
        }

        const moved = currentOrder.splice(currentIndex, 1)[0];
        currentOrder.splice(targetIndex, 0, moved);
        run.currentOrder = currentOrder;
        clearLineupCheck(run);
        setLineupStatus(ctx, '', '');
        renderLineupSequence(ctx);
        return true;
    }

    function shuffleLineupCards(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isLineupRun(ctx, run) || run.sequenceLocked || !run.currentSequence) {
            return false;
        }

        const sourceWords = Array.isArray(run.currentSequence.words) ? run.currentSequence.words : [];
        if (sourceWords.length < 2) {
            return false;
        }

        const gameConfig = getGameConfig(ctx, run) || {};
        run.currentOrder = shuffleUntilDifferent(sourceWords, toInt(gameConfig.shuffleRetries) || 6);
        clearLineupCheck(run);
        setLineupStatus(ctx, '', '');
        renderLineupSequence(ctx);
        return true;
    }

    function markLineupIncorrectWords(run) {
        if (!run || !run.currentSequence) {
            return {
                matchedCount: 0,
                totalCount: 0,
                correctWordIds: {},
                incorrectWordIds: {}
            };
        }

        const currentWords = Array.isArray(run.currentOrder) ? run.currentOrder : [];
        const targetWords = Array.isArray(run.currentSequence.words) ? run.currentSequence.words : [];
        const totalCount = Math.min(currentWords.length, targetWords.length);
        const correctWordIds = {};
        const incorrectWordIds = {};
        let matchedCount = 0;

        for (let index = 0; index < totalCount; index += 1) {
            const currentWord = currentWords[index];
            const targetWord = targetWords[index];
            const currentWordId = toInt(currentWord && currentWord.id);
            const targetWordId = toInt(targetWord && targetWord.id);

            if (currentWordId > 0 && currentWordId === targetWordId) {
                correctWordIds[currentWordId] = true;
                matchedCount += 1;
                continue;
            }

            if (currentWordId > 0) {
                incorrectWordIds[currentWordId] = true;
                const currentPrompt = getLineupPromptState(run, currentWord);
                if (currentPrompt) {
                    currentPrompt.hadWrongBefore = true;
                }
            }

            if (targetWordId > 0) {
                incorrectWordIds[targetWordId] = true;
                const targetPrompt = getLineupPromptState(run, targetWord);
                if (targetPrompt) {
                    targetPrompt.hadWrongBefore = true;
                }
            }
        }

        return {
            matchedCount: matchedCount,
            totalCount: totalCount,
            correctWordIds: correctWordIds,
            incorrectWordIds: incorrectWordIds
        };
    }

    function showLineupSequence(ctx, index) {
        const run = ctx && ctx.run;
        if (!run || !isLineupRun(ctx, run)) {
            return;
        }

        const targetIndex = Math.max(0, toInt(index));
        if (targetIndex >= getRunTotalRounds(run)) {
            finishLineupRun(ctx);
            return;
        }

        const sequence = run.sequences[targetIndex];
        if (!sequence || !Array.isArray(sequence.words) || !sequence.words.length) {
            finishLineupRun(ctx);
            return;
        }

        run.currentSequenceIndex = targetIndex;
        run.currentSequence = sequence;
        run.currentOrder = shuffleUntilDifferent(sequence.words, toInt((getGameConfig(ctx, run) || {}).shuffleRetries) || 6);
        run.currentAttemptCount = 0;
        run.sequenceLocked = false;
        run.lineupCheck = null;
        run.lineupPromptState = {};
        sequence.words.forEach(function (word) {
            getLineupPromptState(run, word);
        });
        setLineupStatus(ctx, '', '');
        renderLineupSequence(ctx);
    }

    function finishLineupRun(ctx) {
        const run = ctx && ctx.run;
        if (!run || run.ended) {
            return;
        }

        run.ended = true;
        run.sequenceLocked = true;
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        stopTransientAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        updatePauseUi(ctx);
        flushProgress(ctx);

        showOverlay(
            ctx,
            String(ctx.i18n.gamesLineupDoneTitle || 'Line-Up complete'),
            formatMessage(ctx.i18n.gamesLineupSummary || 'Perfect: %1$d of %2$d · Retries: %3$d', [
                toInt(run.perfectSequenceCount),
                Math.max(1, getRunTotalRounds(run)),
                Math.max(0, toInt(run.retryCount))
            ]),
            {
                mode: 'game-over',
                primaryLabel: String(ctx.i18n.gamesNewGame || 'New game'),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function advanceLineupSequence(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isLineupRun(ctx, run) || run.ended) {
            return;
        }

        const nextIndex = toInt(run.currentSequenceIndex) + 1;
        if (nextIndex >= getRunTotalRounds(run)) {
            finishLineupRun(ctx);
            return;
        }

        showLineupSequence(ctx, nextIndex);
    }

    function checkLineupSequence(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isLineupRun(ctx, run) || run.ended || run.sequenceLocked || !run.currentSequence) {
            return;
        }

        run.currentAttemptCount = Math.max(0, toInt(run.currentAttemptCount)) + 1;
        const check = markLineupIncorrectWords(run);
        run.lineupCheck = check;

        if (check.matchedCount < check.totalCount) {
            run.retryCount = Math.max(0, toInt(run.retryCount)) + 1;
            setLineupStatus(
                ctx,
                formatMessage(
                    ctx.i18n.gamesLineupTryAgain || 'Not quite yet. %1$d of %2$d are in the right place.',
                    [check.matchedCount, Math.max(1, check.totalCount)]
                ),
                'incorrect'
            );
            renderLineupSequence(ctx);
            return;
        }

        run.sequenceLocked = true;
        run.promptsResolved = Math.max(0, toInt(run.promptsResolved)) + 1;
        if (toInt(run.currentAttemptCount) <= 1) {
            run.perfectSequenceCount = Math.max(0, toInt(run.perfectSequenceCount)) + 1;
        }

        (Array.isArray(run.currentSequence.words) ? run.currentSequence.words : []).forEach(function (word) {
            const promptState = getLineupPromptState(run, word);
            if (!promptState) {
                return;
            }

            queueExposureOnce(ctx, promptState, {
                event_source: 'lineup',
                sequence_direction: normalizeLineupDirection(run.currentSequence.direction),
                sequence_length: Math.max(0, toInt(run.currentSequence.word_count) || (run.currentSequence.words || []).length)
            });
            queueOutcome(ctx, promptState, true, !!promptState.hadWrongBefore, {
                event_source: 'lineup',
                sequence_category_id: toInt(run.currentSequence.category_id),
                sequence_category_name: String(run.currentSequence.category_name || ''),
                sequence_direction: normalizeLineupDirection(run.currentSequence.direction),
                sequence_length: Math.max(0, toInt(run.currentSequence.word_count) || (run.currentSequence.words || []).length),
                sequence_attempts: Math.max(1, toInt(run.currentAttemptCount)),
                needed_retry: promptState.hadWrongBefore ? 1 : 0
            });
        });

        setLineupStatus(ctx, String(ctx.i18n.gamesLineupCorrect || 'Correct order.'), 'correct');
        renderLineupSequence(ctx);
    }

    function renderUnscrambleWord(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || !run.currentWord) {
            resetLineupStage(ctx);
            return;
        }

        const word = run.currentWord;
        const units = Array.isArray(run.currentOrder) ? run.currentOrder.slice() : [];
        const checkState = (run.lineupCheck && typeof run.lineupCheck === 'object') ? run.lineupCheck : null;

        if (ctx.$lineupProgress && ctx.$lineupProgress.length) {
            ctx.$lineupProgress.text(formatMessage(
                ctx.i18n.gamesUnscrambleProgress || 'Word %1$d of %2$d',
                [Math.max(1, toInt(run.currentWordIndex) + 1), Math.max(1, getRunTotalRounds(run))]
            ));
        }
        if (ctx.$lineupCategory && ctx.$lineupCategory.length) {
            ctx.$lineupCategory.text(String(word.category_name || ''));
        }
        if (ctx.$lineupInstruction && ctx.$lineupInstruction.length) {
            ctx.$lineupInstruction.text(String(ctx.i18n.gamesUnscrambleInstruction || 'Put the tiles back in the right order.'));
        }
        setLineupPrompt(ctx, {
            type: String(word.unscramble_prompt_type || ''),
            text: String(word.unscramble_prompt_text || ''),
            image: String(word.unscramble_prompt_image || '')
        });

        if (ctx.$lineupCards && ctx.$lineupCards.length) {
            const markup = units.map(function (unit, index) {
                const unitId = toInt(unit && unit.id);
                const isMovable = !!(unit && unit.movable);
                const isCorrect = !!(checkState && checkState.correctUnitIds && checkState.correctUnitIds[unitId]);
                const isIncorrect = !!(checkState && checkState.incorrectUnitIds && checkState.incorrectUnitIds[unitId]);
                const tileText = String(unit && unit.text || '');
                const tileAriaLabel = tileText.trim() !== ''
                    ? tileText
                    : String(ctx.i18n.gamesUnscrambleStaticTile || 'Fixed tile');

                return '' +
                    '<li class="ll-wordset-lineup-stage__card ll-wordset-lineup-stage__card--tile'
                        + (isMovable ? '' : ' is-static')
                        + (isCorrect ? ' is-correct' : '')
                        + (isIncorrect ? ' is-incorrect' : '')
                        + '" data-ll-wordset-lineup-card'
                        + (isMovable ? ' data-ll-wordset-unscramble-tile="1" tabindex="0"' : '')
                        + ' data-lineup-index="' + escapeHtml(String(index + 1)) + '" data-unit-id="' + escapeHtml(String(unitId)) + '"'
                        + (isMovable ? ' aria-label="' + escapeHtml(tileAriaLabel) + '"' : ' aria-hidden="true"') + '>' +
                        '<div class="ll-wordset-lineup-stage__card-body">' +
                            '<p class="ll-wordset-lineup-stage__card-text ll-wordset-lineup-stage__card-text--tile" dir="auto">' + escapeHtml(tileText || '\u00A0') + '</p>' +
                        '</div>' +
                    '</li>';
            }).join('');

            ctx.$lineupCards.html(markup).attr('dir', direction);
        }

        if (ctx.$lineupShuffle && ctx.$lineupShuffle.length) {
            ctx.$lineupShuffle.prop('disabled', !!run.sequenceLocked || Math.max(0, toInt(word.unscramble_movable_unit_count)) < 2);
        }
        if (ctx.$lineupCheck && ctx.$lineupCheck.length) {
            ctx.$lineupCheck
                .text(String(ctx.i18n.gamesUnscrambleSkip || 'Skip'))
                .removeClass('ll-wordset-lineup-stage__action--primary')
                .addClass('ll-wordset-lineup-stage__action--ghost ll-wordset-lineup-stage__action--skip')
                .prop('hidden', !!run.sequenceLocked)
                .prop('disabled', !!run.sequenceLocked);
        }
        if (ctx.$lineupNext && ctx.$lineupNext.length) {
            const isLastWord = (toInt(run.currentWordIndex) + 1) >= getRunTotalRounds(run);
            ctx.$lineupNext
                .text(String(isLastWord
                    ? (ctx.i18n.gamesLineupFinish || 'Finish')
                    : (ctx.i18n.gamesLineupNext || 'Next')))
                .prop('hidden', !run.sequenceLocked)
                .prop('disabled', !run.sequenceLocked);
        }
    }

    function syncUnscrambleStatus(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || !run.currentWord) {
            return;
        }

        const check = evaluateUnscrambleOrder(run);
        run.lineupCheck = check;

        if (check.totalCount > 0 && check.matchedCount >= check.totalCount) {
            if (!run.sequenceLocked) {
                const promptState = getLineupPromptState(run, run.currentWord, UNSCRAMBLE_GAME_SLUG);
                run.sequenceLocked = true;
                run.promptsResolved = Math.max(0, toInt(run.promptsResolved)) + 1;
                run.solvedWordCount = Math.max(0, toInt(run.solvedWordCount)) + 1;

                if (promptState) {
                    const neededRetry = Math.max(0, toInt(run.currentMoveCount)) > 0;
                    queueExposureOnce(ctx, promptState, {
                        event_source: 'unscramble',
                        prompt_type: String(run.currentWord.unscramble_prompt_type || ''),
                        tile_count: check.totalCount
                    });
                    queueOutcome(ctx, promptState, true, neededRetry, {
                        event_source: 'unscramble',
                        prompt_type: String(run.currentWord.unscramble_prompt_type || ''),
                        tile_count: check.totalCount,
                        moves: Math.max(0, toInt(run.currentMoveCount)),
                        needed_retry: neededRetry ? 1 : 0
                    });
                }
            }

            setLineupStatus(ctx, String(ctx.i18n.gamesUnscrambleCorrect || 'Solved.'), 'correct');
            renderUnscrambleWord(ctx);
            return;
        }

        setLineupStatus(
            ctx,
            formatMessage(
                ctx.i18n.gamesUnscrambleStatus || '%1$d of %2$d letters are in the right place.',
                [Math.max(0, check.matchedCount), Math.max(1, check.totalCount)]
            ),
            'progress'
        );
        renderUnscrambleWord(ctx);
    }

    function moveUnscrambleTile(ctx, index, direction) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || run.sequenceLocked) {
            return false;
        }

        const currentOrder = cloneUnscrambleUnits(run.currentOrder);
        const currentIndex = parseInt(index, 10);
        if (!currentOrder.length || !isFinite(currentIndex) || currentIndex < 0 || currentIndex >= currentOrder.length) {
            return false;
        }
        if (!currentOrder[currentIndex] || !currentOrder[currentIndex].movable) {
            return false;
        }

        const targetIndex = findUnscrambleSwapIndex(currentOrder, currentIndex, direction);
        if (targetIndex < 0 || targetIndex === currentIndex) {
            return false;
        }

        return moveUnscrambleTileToIndex(ctx, currentIndex, targetIndex);
    }

    function moveUnscrambleTileToIndex(ctx, currentIndex, targetIndex) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || run.sequenceLocked) {
            return false;
        }

        const reorderedUnits = reorderUnscrambleUnits(run.currentOrder, parseInt(currentIndex, 10), parseInt(targetIndex, 10));
        if (!reorderedUnits) {
            return false;
        }

        run.currentOrder = reorderedUnits;
        run.currentMoveCount = Math.max(0, toInt(run.currentMoveCount)) + 1;
        run.moveCount = Math.max(0, toInt(run.moveCount)) + 1;
        syncUnscrambleStatus(ctx);
        return true;
    }

    function getChangedTouchByIdentifier(touchList, touchId) {
        if (!touchList || typeof touchList.length !== 'number' || touchList.length < 1) {
            return null;
        }

        if (touchId === null || typeof touchId === 'undefined') {
            return touchList[0] || null;
        }

        for (let index = 0; index < touchList.length; index += 1) {
            if (touchList[index] && touchList[index].identifier === touchId) {
                return touchList[index];
            }
        }

        return null;
    }

    function getEventClientPoint(event, touchId) {
        if (!event || typeof event !== 'object') {
            return null;
        }

        const activeTouch = getChangedTouchByIdentifier(event.touches, touchId)
            || getChangedTouchByIdentifier(event.changedTouches, touchId);
        if (activeTouch) {
            return {
                x: Number(activeTouch.clientX) || 0,
                y: Number(activeTouch.clientY) || 0
            };
        }

        if (typeof event.clientX === 'number' || typeof event.clientY === 'number') {
            return {
                x: Number(event.clientX) || 0,
                y: Number(event.clientY) || 0
            };
        }

        return null;
    }

    function focusUnscrambleTile(ctx, unitId) {
        const targetUnitId = toInt(unitId);
        if (!ctx || !targetUnitId || !ctx.$lineupCards || !ctx.$lineupCards.length) {
            return;
        }

        const focusTile = function () {
            const $tile = ctx.$lineupCards.find('[data-ll-wordset-unscramble-tile][data-unit-id="' + String(targetUnitId) + '"]').first();
            if ($tile.length) {
                $tile.trigger('focus');
            }
        };

        if (typeof root.requestAnimationFrame === 'function') {
            root.requestAnimationFrame(focusTile);
            return;
        }

        focusTile();
    }

    function getUnscrambleKeyboardMoveDirection(direction, event) {
        const isRtl = normalizeLineupDirection(direction) === 'rtl';
        if (matchesKey(event, ['arrowleft', 'a'], ['arrowleft', 'keya'])) {
            return isRtl ? 'later' : 'earlier';
        }
        if (matchesKey(event, ['arrowright', 'd'], ['arrowright', 'keyd'])) {
            return isRtl ? 'earlier' : 'later';
        }
        return '';
    }

    function clearUnscrambleDragUi(ctx) {
        const drag = ctx && ctx.unscrambleDrag;
        if (!drag) {
            return;
        }

        if (ctx.$lineupStage && ctx.$lineupStage.length) {
            ctx.$lineupStage.removeAttr('data-unscramble-dragging');
        }
        if (drag.$tile && drag.$tile.length) {
            drag.$tile
                .removeClass('is-dragging')
                .css('--ll-unscramble-drag-x', '0px')
                .css('--ll-unscramble-drag-y', '0px');
        }
        if (drag.$targetTile && drag.$targetTile.length) {
            drag.$targetTile.removeClass('is-drop-target');
        }
    }

    function teardownUnscrambleDrag(ctx) {
        const drag = ctx && ctx.unscrambleDrag;
        if (!drag) {
            return;
        }

        clearUnscrambleDragUi(ctx);
        if (drag.pointerId !== null && drag.tile && typeof drag.tile.releasePointerCapture === 'function') {
            try {
                drag.tile.releasePointerCapture(drag.pointerId);
            } catch (error) {
                // Ignore stale pointer capture release attempts.
            }
        }
        ctx.unscrambleDrag = null;
    }

    function setUnscrambleDragTarget(ctx, target) {
        const drag = ctx && ctx.unscrambleDrag;
        if (!drag) {
            return;
        }

        if (drag.$targetTile && drag.$targetTile.length) {
            drag.$targetTile.removeClass('is-drop-target');
        }

        drag.targetIndex = target ? target.index : drag.sourceIndex;
        drag.$targetTile = target ? $(target.element) : null;

        if (drag.$targetTile && drag.$targetTile.length) {
            drag.$targetTile.addClass('is-drop-target');
        }
    }

    function getUnscrambleDragTarget(ctx, drag, point) {
        if (!ctx || !drag || !point || !ctx.$lineupCards || !ctx.$lineupCards.length) {
            return null;
        }

        const cardsElement = ctx.$lineupCards.get(0);
        const tileSelector = '[data-ll-wordset-unscramble-tile]';
        let targetElement = null;

        if (root.document && typeof root.document.elementFromPoint === 'function') {
            const hitTarget = root.document.elementFromPoint(point.x, point.y);
            if (hitTarget && typeof hitTarget.closest === 'function') {
                targetElement = hitTarget.closest(tileSelector);
            }
        }

        if (!targetElement || targetElement === drag.tile) {
            const candidateTiles = cardsElement.querySelectorAll ? cardsElement.querySelectorAll(tileSelector) : [];
            let closestDistance = Infinity;
            Array.prototype.forEach.call(candidateTiles, function (candidate) {
                if (!candidate || candidate === drag.tile || typeof candidate.getBoundingClientRect !== 'function') {
                    return;
                }

                const rect = candidate.getBoundingClientRect();
                const centerX = rect.left + (rect.width / 2);
                const centerY = rect.top + (rect.height / 2);
                const dx = centerX - point.x;
                const dy = centerY - point.y;
                const distance = Math.sqrt((dx * dx) + (dy * dy));

                if (distance < closestDistance) {
                    closestDistance = distance;
                    targetElement = candidate;
                }
            });
        }

        if (!targetElement || targetElement === drag.tile) {
            return null;
        }

        const targetIndex = toInt(targetElement.getAttribute('data-lineup-index')) - 1;
        if (targetIndex < 0 || targetIndex === drag.sourceIndex) {
            return null;
        }

        return {
            element: targetElement,
            index: targetIndex
        };
    }

    function startUnscrambleTileDrag(ctx, event, tileElement) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || run.ended || run.sequenceLocked || !tileElement) {
            return;
        }
        if (shouldIgnoreSyntheticMouseFromTouch(ctx, event, 'unscramble-drag')) {
            return;
        }
        if (typeof event.button === 'number' && event.button !== 0) {
            return;
        }

        const $tile = $(tileElement);
        const sourceIndex = toInt($tile.attr('data-lineup-index')) - 1;
        const unitId = toInt($tile.attr('data-unit-id'));
        const point = getEventClientPoint(event);

        if (sourceIndex < 0 || !unitId || !point) {
            return;
        }

        if (ctx.unscrambleDrag) {
            teardownUnscrambleDrag(ctx);
        }

        ctx.unscrambleDrag = {
            sourceIndex: sourceIndex,
            targetIndex: sourceIndex,
            unitId: unitId,
            tile: tileElement,
            $tile: $tile,
            $targetTile: null,
            pointerId: (typeof event.pointerId === 'number') ? event.pointerId : null,
            touchId: event.changedTouches && event.changedTouches.length ? event.changedTouches[0].identifier : null,
            startX: point.x,
            startY: point.y,
            hasMoved: false
        };

        if (ctx.unscrambleDrag.pointerId !== null && typeof tileElement.setPointerCapture === 'function') {
            try {
                tileElement.setPointerCapture(ctx.unscrambleDrag.pointerId);
            } catch (error) {
                // Ignore pointer capture failures and continue with document listeners.
            }
        }

        if (event.cancelable !== false) {
            event.preventDefault();
        }
    }

    function updateUnscrambleTileDrag(ctx, event) {
        const drag = ctx && ctx.unscrambleDrag;
        if (!drag) {
            return;
        }
        if (drag.pointerId !== null && typeof event.pointerId === 'number' && event.pointerId !== drag.pointerId) {
            return;
        }
        if (drag.touchId !== null && event.changedTouches && !getChangedTouchByIdentifier(event.changedTouches, drag.touchId) && !getChangedTouchByIdentifier(event.touches, drag.touchId)) {
            return;
        }

        const point = getEventClientPoint(event, drag.touchId);
        if (!point) {
            return;
        }

        const offsetX = point.x - drag.startX;
        const offsetY = point.y - drag.startY;
        if (!drag.hasMoved && Math.sqrt((offsetX * offsetX) + (offsetY * offsetY)) < 6) {
            return;
        }

        if (!drag.hasMoved) {
            drag.hasMoved = true;
            if (ctx.$lineupStage && ctx.$lineupStage.length) {
                ctx.$lineupStage.attr('data-unscramble-dragging', '1');
            }
            drag.$tile.addClass('is-dragging');
        }

        if (event.cancelable !== false) {
            event.preventDefault();
        }

        drag.$tile
            .css('--ll-unscramble-drag-x', offsetX + 'px')
            .css('--ll-unscramble-drag-y', offsetY + 'px');
        setUnscrambleDragTarget(ctx, getUnscrambleDragTarget(ctx, drag, point));
    }

    function finishUnscrambleTileDrag(ctx, event) {
        const drag = ctx && ctx.unscrambleDrag;
        if (!drag) {
            return;
        }
        if (event && drag.pointerId !== null && typeof event.pointerId === 'number' && event.pointerId !== drag.pointerId) {
            return;
        }
        if (event && drag.touchId !== null && event.changedTouches && !getChangedTouchByIdentifier(event.changedTouches, drag.touchId)) {
            return;
        }

        const sourceIndex = drag.sourceIndex;
        const targetIndex = drag.targetIndex;
        const unitId = drag.unitId;
        const shouldMove = !!drag.hasMoved && targetIndex >= 0 && targetIndex !== sourceIndex;

        teardownUnscrambleDrag(ctx);

        if (shouldMove && moveUnscrambleTileToIndex(ctx, sourceIndex, targetIndex)) {
            markRunActivity(ctx);
            focusUnscrambleTile(ctx, unitId);
        }
    }

    function skipUnscrambleWord(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || run.ended || run.sequenceLocked) {
            return false;
        }

        const nextIndex = toInt(run.currentWordIndex) + 1;
        if (nextIndex >= getRunTotalRounds(run)) {
            finishUnscrambleRun(ctx);
            return true;
        }

        showUnscrambleWord(ctx, nextIndex);
        return true;
    }

    function shuffleUnscrambleTiles(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || run.sequenceLocked || !run.currentWord) {
            return false;
        }

        const sourceUnits = Array.isArray(run.currentWord.unscramble_units) ? run.currentWord.unscramble_units : [];
        if (!sourceUnits.length || Math.max(0, toInt(run.currentWord.unscramble_movable_unit_count)) < 2) {
            return false;
        }

        const gameConfig = getGameConfig(ctx, run) || {};
        run.currentOrder = buildUnscrambleOrder(sourceUnits, toInt(gameConfig.shuffleRetries) || 6);
        run.currentMoveCount = Math.max(0, toInt(run.currentMoveCount)) + 1;
        run.moveCount = Math.max(0, toInt(run.moveCount)) + 1;
        syncUnscrambleStatus(ctx);
        return true;
    }

    function showUnscrambleWord(ctx, index) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run)) {
            return;
        }

        const targetIndex = Math.max(0, toInt(index));
        if (targetIndex >= getRunTotalRounds(run)) {
            finishUnscrambleRun(ctx);
            return;
        }

        const word = run.words[targetIndex];
        if (!word || !Array.isArray(word.unscramble_units) || !word.unscramble_units.length) {
            finishUnscrambleRun(ctx);
            return;
        }

        teardownUnscrambleDrag(ctx);
        run.currentWordIndex = targetIndex;
        run.currentWord = word;
        run.currentOrder = buildUnscrambleOrder(word.unscramble_units, toInt((getGameConfig(ctx, run) || {}).shuffleRetries) || 6);
        run.currentMoveCount = 0;
        run.sequenceLocked = false;
        run.lineupCheck = null;
        getLineupPromptState(run, word, UNSCRAMBLE_GAME_SLUG);
        syncUnscrambleStatus(ctx);
    }

    function finishUnscrambleRun(ctx) {
        const run = ctx && ctx.run;
        if (!run || run.ended) {
            return;
        }

        run.ended = true;
        run.sequenceLocked = true;
        teardownUnscrambleDrag(ctx);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        stopTransientAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        updatePauseUi(ctx);
        flushProgress(ctx);

        showOverlay(
            ctx,
            String(ctx.i18n.gamesUnscrambleDoneTitle || 'Unscramble complete'),
            formatMessage(ctx.i18n.gamesUnscrambleSummary || 'Solved: %1$d of %2$d · Moves: %3$d', [
                Math.max(0, toInt(run.solvedWordCount)),
                Math.max(1, getRunTotalRounds(run)),
                Math.max(0, toInt(run.moveCount))
            ]),
            {
                mode: 'game-over',
                primaryLabel: String(ctx.i18n.gamesNewGame || 'New game'),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function advanceUnscrambleWord(ctx) {
        const run = ctx && ctx.run;
        if (!run || !isUnscrambleRun(ctx, run) || run.ended) {
            return;
        }

        const nextIndex = toInt(run.currentWordIndex) + 1;
        if (nextIndex >= getRunTotalRounds(run)) {
            finishUnscrambleRun(ctx);
            return;
        }

        showUnscrambleWord(ctx, nextIndex);
    }

    function startUnscrambleRun(ctx, entry) {
        const gameSlug = normalizeGameSlug(entry && entry.slug);
        const keepModalOpen = isRunModalVisible(ctx);
        const selectedWords = selectShuffledRoundWords(ctx, gameSlug, entry, getEntryRoundGoalCount(ctx, entry));
        if (!selectedWords.length) {
            return;
        }

        resetGamesSurface(ctx, {
            keepModalOpen: keepModalOpen
        });
        ctx.$stage.prop('hidden', false);
        ctx.activeGameSlug = gameSlug;
        updateStageGameUi(ctx, entry);
        setRunModalOpen(ctx, true);
        activateGameInteractionGuard();
        showOverlay(ctx, String(ctx.i18n.gamesPreparingRun || 'Preparing game...'), '', {
            mode: 'loading',
            primaryLabel: '',
            secondaryLabel: ''
        });

        ctx.run = {
            slug: gameSlug,
            words: selectedWords.slice(),
            currentWordIndex: 0,
            currentWord: null,
            currentOrder: [],
            currentMoveCount: 0,
            moveCount: 0,
            solvedWordCount: 0,
            lineupPromptState: {},
            lineupCheck: null,
            sequenceLocked: false,
            controls: {
                left: false,
                right: false,
                fire: false
            },
            coins: 0,
            lives: 1,
            promptsResolved: 0,
            totalRounds: selectedWords.length,
            loadingShownAt: currentTimestamp(),
            loadingHideTimer: 0,
            paused: false,
            ended: false,
            rafId: 0
        };

        setTrackerContext(ctx);
        updatePauseUi(ctx);
        resetLineupStage(ctx);

        const run = ctx.run;
        applyInitialPromptWhenReady(ctx, run, function () {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            hideOverlay(ctx);
            showUnscrambleWord(ctx, 0);
        });
    }

    function startLineupRun(ctx, entry) {
        const gameSlug = normalizeGameSlug(entry && entry.slug);
        const gameConfig = getGameConfig(ctx, gameSlug) || {};
        const keepModalOpen = isRunModalVisible(ctx);
        const selectedSequences = selectRoundSequences(entry, getEntryRoundGoalCount(ctx, entry));
        if (!selectedSequences.length) {
            return;
        }

        resetGamesSurface(ctx, {
            keepModalOpen: keepModalOpen
        });
        ctx.$stage.prop('hidden', false);
        ctx.activeGameSlug = gameSlug;
        updateStageGameUi(ctx, entry);
        setRunModalOpen(ctx, true);
        activateGameInteractionGuard();
        showOverlay(ctx, String(ctx.i18n.gamesPreparingRun || 'Preparing game...'), '', {
            mode: 'loading',
            primaryLabel: '',
            secondaryLabel: ''
        });

        ctx.run = {
            slug: gameSlug,
            sequences: selectedSequences.slice(),
            currentSequenceIndex: 0,
            currentSequence: null,
            currentOrder: [],
            currentAttemptCount: 0,
            lineupPromptState: {},
            lineupCheck: null,
            sequenceLocked: false,
            controls: {
                left: false,
                right: false,
                fire: false
            },
            coins: 0,
            lives: 1,
            promptsResolved: 0,
            totalRounds: selectedSequences.length,
            retryCount: 0,
            perfectSequenceCount: 0,
            loadingShownAt: currentTimestamp(),
            loadingHideTimer: 0,
            paused: false,
            ended: false,
            rafId: 0,
            lineupConfig: gameConfig
        };

        setTrackerContext(ctx);
        updatePauseUi(ctx);
        resetLineupStage(ctx);

        const run = ctx.run;
        applyInitialPromptWhenReady(ctx, run, function () {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            hideOverlay(ctx);
            showLineupSequence(ctx, 0);
        });
    }

    function startRun(ctx, entry) {
        const gameSlug = normalizeGameSlug(entry && entry.slug);
        if (gameSlug === UNSCRAMBLE_GAME_SLUG) {
            startUnscrambleRun(ctx, entry);
            return;
        }
        if (gameSlug === LINEUP_GAME_SLUG) {
            startLineupRun(ctx, entry);
            return;
        }
        if (gameSlug === SPEAKING_PRACTICE_GAME_SLUG) {
            startSpeakingRun(ctx, entry);
            return;
        }
        if (gameSlug === SPEAKING_STACK_GAME_SLUG) {
            startSpeakingStackRun(ctx, entry);
            return;
        }
        const gameConfig = getGameConfig(ctx, gameSlug);
        if (!gameConfig) {
            return;
        }
        const keepModalOpen = isRunModalVisible(ctx);
        resetGamesSurface(ctx, {
            keepModalOpen: keepModalOpen
        });
        ctx.$stage.prop('hidden', false);
        ctx.activeGameSlug = gameSlug;
        updateStageGameUi(ctx, entry);
        setRunModalOpen(ctx, true);
        activateGameInteractionGuard();
        showOverlay(ctx, String(ctx.i18n.gamesPreparingRun || 'Preparing game...'), '', {
            mode: 'loading',
            primaryLabel: '',
            secondaryLabel: ''
        });

        const selectedTargets = selectRoundTargets(entry, getEntryRoundGoalCount(ctx, entry));

        ctx.run = {
            slug: gameSlug,
            words: entry.words.slice(),
            playableTargets: shuffle(selectedTargets.slice()),
            promptDeck: [],
            prompt: null,
            cards: [],
            bullets: [],
            explosions: [],
            decorativeBubbles: [],
            controls: {
                left: false,
                right: false,
                fire: false
            },
            coins: 0,
            lives: gameConfig.lives,
            promptsResolved: 0,
            lastFireAt: 0,
            lastFrameAt: 0,
            shipX: 0,
            shipY: 0,
            width: 720,
            height: 960,
            dpr: 1,
            metrics: null,
            stars: [],
            cardCount: gameConfig.cardCount,
            cardSpeed: 86,
            promptIdCounter: 0,
            totalRounds: selectedTargets.length,
            promptTimer: 0,
            promptTimerReadyAt: 0,
            promptTimerRemainingMs: 0,
            decorativeBubbleIdCounter: 0,
            loadingShownAt: currentTimestamp(),
            loadingHideTimer: 0,
            speedRampTurns: gameConfig.introRampTurns,
            speedRampStartFactor: gameConfig.introRampStartFactor,
            useSameCategoryDistractorsNext: false,
            awaitingPrompt: false,
            nextPreparedPrompt: null,
            nextPromptPromise: null,
            inactiveRounds: 0,
            lastResolvedPromptHadUserActivity: false,
            resumeAction: '',
            pauseReason: '',
            paused: false,
            ended: false,
            rafId: 0
        };

        syncCanvasSize(ctx);
        ctx.run.cardSpeed = getRunCardSpeed(ctx.run);
        ctx.run.shipX = ctx.run.width / 2;
        ctx.run.shipY = ctx.run.metrics.shipY;
        ctx.run.stars = createStageStars(ctx.run);
        updateHud(ctx);
        updatePauseUi(ctx);
        setTrackerContext(ctx);
        scrollStageIntoView(ctx);
        warmFeedbackAudioSources(ctx, gameSlug);
        startNextPrompt(ctx);
        ctx.run.rafId = root.requestAnimationFrame(function (timestamp) {
            runLoop(ctx, timestamp);
        });
    }

    function bootstrapCatalog(ctx) {
        renderAllCatalogCards(ctx, null, true);
        if (ctx.staticCatalog && typeof ctx.staticCatalog === 'object') {
            const entryPromises = Object.keys(ctx.catalogCards || {}).map(function (slug) {
                if (
                    !ctx.staticCatalog[slug]
                    && [SPEAKING_PRACTICE_GAME_SLUG, SPEAKING_STACK_GAME_SLUG].indexOf(normalizeGameSlug(slug)) !== -1
                ) {
                    return Promise.resolve({
                        slug: slug,
                        entry: null
                    });
                }
                const prepared = buildPreparedEntry(ctx, slug, ctx.staticCatalog[slug] || {});
                return resolveCatalogEntryAvailability(ctx, slug, prepared).then(function (resolvedEntry) {
                    return {
                        slug: slug,
                        entry: resolvedEntry
                    };
                });
            });

            Promise.all(entryPromises).then(function (results) {
                const nextEntries = {};
                results.forEach(function (item) {
                    if (!item || !item.entry) {
                        return;
                    }
                    nextEntries[item.slug] = item.entry;
                });
                ctx.catalogEntries = nextEntries;
                ctx.catalogEntry = nextEntries[getDefaultCatalogSlug(ctx)] || null;
                renderAllCatalogCards(ctx, nextEntries, false);
            }).catch(function () {
                renderAllCatalogCards(ctx, null, false);
            });
            return;
        }

        if (!(ctx.isLoggedIn || ctx.offlineMode) || !ctx.ajaxUrl || !ctx.wordsetId || !ctx.bootstrapAction) {
            renderAllCatalogCards(ctx, null, false);
            return;
        }

        if (ctx.bootstrapRequest && typeof ctx.bootstrapRequest.abort === 'function') {
            ctx.bootstrapRequest.abort();
        }

        ctx.bootstrapRequest = $.post(ctx.ajaxUrl, {
            action: ctx.bootstrapAction,
            nonce: ctx.nonce,
            wordset_id: ctx.wordsetId
        }).done(function (response) {
            const payload = response && response.success && response.data && typeof response.data === 'object'
                ? response.data
                : null;
            if (!payload || !payload.games || typeof payload.games !== 'object') {
                renderAllCatalogCards(ctx, null, false);
                return;
            }
            if (payload.speaking_hidden_notice && typeof payload.speaking_hidden_notice === 'object') {
                ctx.speakingHiddenNotice = normalizeSpeakingNotice(payload.speaking_hidden_notice);
            }

            const entryPromises = Object.keys(ctx.catalogCards || {}).map(function (slug) {
                if (
                    !payload.games[slug]
                    && [SPEAKING_PRACTICE_GAME_SLUG, SPEAKING_STACK_GAME_SLUG].indexOf(normalizeGameSlug(slug)) !== -1
                ) {
                    return Promise.resolve({
                        slug: slug,
                        entry: null
                    });
                }
                const prepared = buildPreparedEntry(ctx, slug, payload.games[slug] || {});
                return resolveCatalogEntryAvailability(ctx, slug, prepared).then(function (resolvedEntry) {
                    return {
                        slug: slug,
                        entry: resolvedEntry
                    };
                });
            });

            Promise.all(entryPromises).then(function (results) {
                const nextEntries = {};
                results.forEach(function (item) {
                    if (!item || !item.entry) {
                        return;
                    }
                    nextEntries[item.slug] = item.entry;
                });
                ctx.catalogEntries = nextEntries;
                ctx.catalogEntry = nextEntries[getDefaultCatalogSlug(ctx)] || null;
                renderAllCatalogCards(ctx, nextEntries, false);
            }).catch(function () {
                renderAllCatalogCards(ctx, null, false);
            });
        }).fail(function () {
            renderAllCatalogCards(ctx, null, false);
        });
    }

    function speakingState(ctx) {
        return (ctx && ctx.speaking && typeof ctx.speaking === 'object') ? ctx.speaking : null;
    }

    function clearSpeakingTimeout(state, key) {
        if (!state || !key || !state[key]) {
            return;
        }
        root.clearTimeout(state[key]);
        state[key] = 0;
    }

    function clearSpeakingInterval(state, key) {
        if (!state || !key || !state[key]) {
            return;
        }
        root.clearInterval(state[key]);
        state[key] = 0;
    }

    function scheduleSpeakingAttemptUrlRevoke(state, url) {
        if (!state || !url || !root.URL || typeof root.URL.revokeObjectURL !== 'function') {
            return;
        }

        clearSpeakingTimeout(state, 'attemptAudioRevokeTimer');
        state.attemptAudioRevokeTimer = root.setTimeout(function () {
            state.attemptAudioRevokeTimer = 0;
            if (state.attemptAudioUrl === url) {
                return;
            }
            try {
                root.URL.revokeObjectURL(url);
            } catch (_) { /* no-op */ }
        }, 800);
    }

    function getActiveSpeakingMeterBars(ctx) {
        const run = ctx && ctx.run;
        if (isSpeakingStackRun(ctx, run)) {
            return ctx && ctx.$speakingStackMeterBars ? ctx.$speakingStackMeterBars : $();
        }
        return ctx && ctx.$speakingMeterBars ? ctx.$speakingMeterBars : $();
    }

    function setSpeakingStatus(ctx, text, options) {
        const run = ctx && ctx.run;
        const opts = (options && typeof options === 'object') ? options : {};
        if (isSpeakingStackRun(ctx, run)) {
            if (ctx && ctx.$speakingStackStatus && ctx.$speakingStackStatus.length) {
                if (opts.icon === 'record') {
                    ctx.$speakingStackStatus
                        .attr('data-speaking-stack-status-kind', 'record')
                        .attr('aria-label', String(text || ''))
                        .html(getSpeakingStackListeningStatusMarkup(text));
                } else {
                    ctx.$speakingStackStatus
                        .attr('data-speaking-stack-status-kind', 'text')
                        .removeAttr('aria-label')
                        .text(String(text || ''));
                }
            }
            return;
        }
        if (ctx && ctx.$speakingStatus && ctx.$speakingStatus.length) {
            ctx.$speakingStatus.text(String(text || ''));
        }
    }

    function setSpeakingStackProgress(ctx, text) {
        if (!ctx || !ctx.$speakingStackProgress || !ctx.$speakingStackProgress.length) {
            return;
        }
        ctx.$speakingStackProgress.text(String(text || ''));
    }

    function setSpeakingStackHeard(ctx, text, options) {
        if (!ctx || !ctx.$speakingStackHeard || !ctx.$speakingStackHeard.length) {
            return;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const heardText = String(text || '').trim();
        if (ctx.$speakingStackHeardRow && ctx.$speakingStackHeardRow.length) {
            ctx.$speakingStackHeardRow.prop('hidden', heardText === '');
        }
        if (!heardText) {
            ctx.$speakingStackHeard.empty();
            return;
        }

        ctx.$speakingStackHeard.html(renderSpeakingComparedText(
            heardText,
            String(opts.targetText || ''),
            clamp(Number(opts.score) || 0, 0, 100),
            'heard'
        ));
    }

    function resetSpeakingMeter(ctx) {
        const bars = getActiveSpeakingMeterBars(ctx);
        if (!bars.length) {
            return;
        }
        bars.each(function (index, element) {
            $(element).css('--ll-speaking-meter-level', '0.08');
        });
    }

    function updateSpeakingMeter(ctx, level) {
        const bars = getActiveSpeakingMeterBars(ctx);
        if (!bars.length) {
            return;
        }

        const normalized = clamp(Number(level) || 0, 0, 1);
        const activeBars = Math.max(1, Math.round(normalized * bars.length));
        bars.each(function (index, element) {
            const isActive = index < activeBars;
            const baseLevel = isActive
                ? clamp(0.28 + (normalized * 0.9) - (index * 0.025), 0.2, 1)
                : 0.08;
            $(element).css('--ll-speaking-meter-level', String(baseLevel));
        });
    }

    function setSpeakingRecordButton(ctx, label, disabled, state) {
        if (!ctx || !ctx.$speakingRecord || !ctx.$speakingRecord.length) {
            return;
        }
        const text = String(label || ctx.i18n.gamesSpeakingStartButton || 'Start');
        const visualState = String(state || '').trim() || (!!disabled ? 'processing' : 'idle');
        ctx.$speakingRecord
            .prop('disabled', !!disabled)
            .attr('aria-label', text)
            .attr('title', text)
            .attr('data-speaking-state', visualState);
        if (ctx.$speakingRecordLabel && ctx.$speakingRecordLabel.length) {
            ctx.$speakingRecordLabel.text(text);
        }
    }

    function showSpeakingResult(ctx, isVisible) {
        if (!ctx || !ctx.$speakingResult || !ctx.$speakingResult.length) {
            return;
        }
        ctx.$speakingResult.prop('hidden', !isVisible);
        if (ctx.$speakingActions && ctx.$speakingActions.length) {
            ctx.$speakingActions.prop('hidden', !!isVisible);
        }
    }

    function scrollSpeakingElementIntoView(ctx, $element) {
        const element = $element && $element.length ? $element.get(0) : null;
        if (!element || typeof element.scrollIntoView !== 'function') {
            return;
        }
        root.requestAnimationFrame(function () {
            try {
                element.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest',
                    behavior: 'smooth'
                });
            } catch (_) {
                element.scrollIntoView(false);
            }
        });
    }

    function setSpeakingReferenceAudioSource(ctx, audioUrl) {
        if (!ctx || !ctx.speakingCorrectAudio) {
            return;
        }
        const source = String(audioUrl || '').trim();
        try {
            ctx.speakingCorrectAudio.pause();
            ctx.speakingCorrectAudio.currentTime = 0;
            if (source) {
                ctx.speakingCorrectAudio.src = source;
            } else {
                ctx.speakingCorrectAudio.removeAttribute('src');
            }
        } catch (_) { /* no-op */ }
    }

    function clearSpeakingAttemptAudioSource(ctx) {
        const state = speakingState(ctx);
        const previousAttemptUrl = state && state.attemptAudioUrl ? String(state.attemptAudioUrl) : '';
        if (!ctx || !ctx.speakingAttemptAudio) {
            if (state) {
                state.attemptAudioUrl = '';
                scheduleSpeakingAttemptUrlRevoke(state, previousAttemptUrl);
            }
            return;
        }
        try {
            ctx.speakingAttemptAudio.pause();
            ctx.speakingAttemptAudio.currentTime = 0;
            ctx.speakingAttemptAudio.removeAttribute('src');
            if (typeof ctx.speakingAttemptAudio.load === 'function') {
                ctx.speakingAttemptAudio.load();
            }
        } catch (_) { /* no-op */ }
        if (state) {
            state.attemptAudioUrl = '';
            scheduleSpeakingAttemptUrlRevoke(state, previousAttemptUrl);
        }
        if (ctx.$speakingPlayAttempt && ctx.$speakingPlayAttempt.length) {
            ctx.$speakingPlayAttempt.prop('hidden', true);
            updateAudioButtonUi(ctx.$speakingPlayAttempt, false);
        }
    }

    function setSpeakingAttemptAudioSource(ctx, blob) {
        clearSpeakingAttemptAudioSource(ctx);
        const state = speakingState(ctx);
        if (!ctx || !ctx.speakingAttemptAudio || !state || !blob || !root.URL || typeof root.URL.createObjectURL !== 'function') {
            return '';
        }

        try {
            state.attemptAudioUrl = root.URL.createObjectURL(blob);
            ctx.speakingAttemptAudio.src = state.attemptAudioUrl;
            if (ctx.$speakingPlayAttempt && ctx.$speakingPlayAttempt.length) {
                ctx.$speakingPlayAttempt.prop('hidden', false);
            }
            return state.attemptAudioUrl;
        } catch (_) {
            state.attemptAudioUrl = '';
            return '';
        }
    }

    function resetSpeakingResultUi(ctx) {
        showSpeakingResult(ctx, false);
        if (ctx && ctx.$speakingPromptCard && ctx.$speakingPromptCard.length) {
            ctx.$speakingPromptCard.removeAttr('data-speaking-result').removeClass('has-image has-text');
        }
        if (ctx && ctx.$speakingBucket && ctx.$speakingBucket.length) {
            ctx.$speakingBucket.text('');
        }
        if (ctx && ctx.$speakingScore && ctx.$speakingScore.length) {
            ctx.$speakingScore.text('');
        }
        if (ctx && ctx.$speakingBar && ctx.$speakingBar.length) {
            ctx.$speakingBar.css('width', '0%').removeClass('is-right is-wrong');
        }
        if (ctx && ctx.$speakingTranscript && ctx.$speakingTranscript.length) {
            ctx.$speakingTranscript.text('');
        }
        if (ctx && ctx.$speakingTargetRow && ctx.$speakingTargetRow.length) {
            ctx.$speakingTargetRow.prop('hidden', true);
        }
        if (ctx && ctx.$speakingTargetLabel && ctx.$speakingTargetLabel.length) {
            ctx.$speakingTargetLabel.text(String(ctx.i18n.gamesSpeakingTargetLabel || 'Target'));
        }
        if (ctx && ctx.$speakingTarget && ctx.$speakingTarget.length) {
            ctx.$speakingTarget.text('');
        }
        if (ctx && ctx.$speakingTitle && ctx.$speakingTitle.length) {
            ctx.$speakingTitle.text('');
        }
        if (ctx && ctx.$speakingIpa && ctx.$speakingIpa.length) {
            ctx.$speakingIpa.text('');
        }
        if (ctx && ctx.$speakingTitleRow && ctx.$speakingTitleRow.length) {
            ctx.$speakingTitleRow.prop('hidden', true);
        }
        if (ctx && ctx.$speakingIpaRow && ctx.$speakingIpaRow.length) {
            ctx.$speakingIpaRow.prop('hidden', true);
        }
        if (ctx && ctx.$speakingPlayCorrect && ctx.$speakingPlayCorrect.length) {
            ctx.$speakingPlayCorrect.prop('hidden', true);
            updateAudioButtonUi(ctx.$speakingPlayCorrect, false);
        }
        if (ctx && ctx.$speakingPlayAttempt && ctx.$speakingPlayAttempt.length) {
            ctx.$speakingPlayAttempt.prop('hidden', true);
            updateAudioButtonUi(ctx.$speakingPlayAttempt, false);
        }
        setSpeakingReferenceAudioSource(ctx, '');
        clearSpeakingAttemptAudioSource(ctx);
    }

    function getSpeakingPromptText(word) {
        const source = (word && typeof word === 'object') ? word : {};
        if (String(source.image || '').trim() === '') {
            const translation = String(source.translation || '').trim();
            if (translation) {
                return translation;
            }
        }
        return String(source.speaking_prompt_text || source.translation || source.prompt_label || source.label || source.title || '').trim();
    }

    function resetSpeakingStackUi(ctx) {
        setSpeakingStackProgress(ctx, '');
        setSpeakingStatus(ctx, '');
        setSpeakingStackHeard(ctx, '');
        resetSpeakingMeter(ctx);
    }

    function setSpeakingStackProgressFromRun(ctx, run) {
        if (!run) {
            setSpeakingStackProgress(ctx, '');
            return;
        }

        setSpeakingStackProgress(ctx, formatMessage(
            ctx.i18n.gamesSpeakingStackProgress || '%1$d left',
            [Math.max(0, toInt(run.totalWordCount) - toInt(run.clearedCount))]
        ));
    }

    function renderSpeakingPrompt(ctx, run, word) {
        const promptText = getSpeakingPromptText(word);
        const hasImage = String(word && word.image || '').trim() !== '';
        if (ctx.$speakingRound && ctx.$speakingRound.length) {
            ctx.$speakingRound.text(formatMessage(
                ctx.i18n.gamesSpeakingRound || 'Word %1$d of %2$d',
                [Math.min(run.promptsResolved + 1, run.words.length), run.words.length]
            ));
        }
        if (ctx.$speakingImageWrap && ctx.$speakingImageWrap.length) {
            ctx.$speakingImageWrap.prop('hidden', !hasImage);
        }
        if (ctx.$speakingTextWrap && ctx.$speakingTextWrap.length) {
            ctx.$speakingTextWrap.prop('hidden', hasImage);
        }
        if (ctx.$speakingImage && ctx.$speakingImage.length) {
            ctx.$speakingImage.attr('src', hasImage ? String(word.image || '') : '').attr('alt', promptText);
        }
        if (ctx.$speakingText && ctx.$speakingText.length) {
            ctx.$speakingText.text(promptText);
        }
        if (ctx.$speakingPromptCard && ctx.$speakingPromptCard.length) {
            const promptCard = ctx.$speakingPromptCard.get(0);
            ctx.$speakingPromptCard
                .removeClass('is-entering')
                .toggleClass('has-image', hasImage)
                .toggleClass('has-text', !hasImage);
            if (promptCard) {
                void promptCard.offsetWidth;
            }
            root.requestAnimationFrame(function () {
                ctx.$speakingPromptCard.addClass('is-entering');
            });
        }
        setSpeakingReferenceAudioSource(ctx, String(word && word.speaking_best_correct_audio_url || ''));
        scrollSpeakingElementIntoView(ctx, ctx.$speakingPromptCard);
    }

    function stopSpeakingMeterMonitoring(ctx) {
        const state = speakingState(ctx);
        if (!state) {
            return;
        }
        clearSpeakingInterval(state, 'meterTimer');
        if (state.micSource && typeof state.micSource.disconnect === 'function') {
            try {
                state.micSource.disconnect();
            } catch (_) { /* no-op */ }
        }
        state.micSource = null;
        state.analyser = null;
    }

    function stopSpeakingAudioContext(ctx) {
        const state = speakingState(ctx);
        if (!state || !state.audioContext) {
            return;
        }
        try {
            state.audioContext.close();
        } catch (_) { /* no-op */ }
        state.audioContext = null;
    }

    function stopSpeakingStream(ctx) {
        const state = speakingState(ctx);
        if (!state || !state.mediaStream) {
            return;
        }
        try {
            state.mediaStream.getTracks().forEach(function (track) {
                if (track && typeof track.stop === 'function') {
                    track.stop();
                }
            });
        } catch (_) { /* no-op */ }
        state.mediaStream = null;
    }

    function clearSpeakingAutoStart(ctx) {
        clearSpeakingTimeout(speakingState(ctx), 'autoStartTimer');
    }

    function teardownSpeaking(ctx) {
        const state = speakingState(ctx);
        if (!state) {
            return;
        }
        clearSpeakingAutoStart(ctx);
        clearSpeakingTimeout(state, 'restartTimer');
        clearSpeakingTimeout(state, 'maxStopTimer');
        stopSpeakingMeterMonitoring(ctx);
        if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
            try {
                state.mediaRecorder.stop();
            } catch (_) { /* no-op */ }
        }
        state.mediaRecorder = null;
        state.audioChunks = [];
        state.currentBlob = null;
        clearSpeakingAttemptAudioSource(ctx);
        state.stopPromise = null;
        state.transcribing = false;
        state.speechDetected = false;
        state.speechStartedAt = 0;
        state.silenceStartedAt = 0;
        stopSpeakingStream(ctx);
        stopSpeakingAudioContext(ctx);
        resetSpeakingMeter(ctx);
        setSpeakingStackHeard(ctx, '');
    }

    function ensureSpeakingSupported() {
        return !!(
            root.navigator
            && root.navigator.mediaDevices
            && typeof root.navigator.mediaDevices.getUserMedia === 'function'
            && typeof root.MediaRecorder === 'function'
        );
    }

    function ensureSpeakingStream(ctx) {
        const state = speakingState(ctx);
        if (!state) {
            return Promise.reject(new Error('missing_state'));
        }
        if (state.mediaStream) {
            return Promise.resolve(state.mediaStream);
        }
        return root.navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            state.mediaStream = stream;
            return stream;
        });
    }

    function chooseRecordingMimeType() {
        if (!root.MediaRecorder || typeof root.MediaRecorder.isTypeSupported !== 'function') {
            return '';
        }
        const preferred = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/mp4',
            'audio/ogg;codecs=opus'
        ];
        for (let index = 0; index < preferred.length; index += 1) {
            if (root.MediaRecorder.isTypeSupported(preferred[index])) {
                return preferred[index];
            }
        }
        return '';
    }

    function stopSpeakingCapture(ctx) {
        const state = speakingState(ctx);
        if (!state) {
            return Promise.resolve(null);
        }
        clearSpeakingTimeout(state, 'maxStopTimer');
        clearSpeakingAutoStart(ctx);

        if (!state.mediaRecorder) {
            return Promise.resolve(state.currentBlob);
        }
        if (state.mediaRecorder.state === 'inactive') {
            return Promise.resolve(state.currentBlob);
        }
        if (state.stopPromise) {
            return state.stopPromise;
        }

        state.stopPromise = new Promise(function (resolve) {
            const recorder = state.mediaRecorder;
            const finalize = function () {
                stopSpeakingMeterMonitoring(ctx);
                const blob = state.audioChunks.length
                    ? new Blob(state.audioChunks.slice(), { type: recorder.mimeType || 'audio/webm' })
                    : null;
                state.currentBlob = blob;
                state.stopPromise = null;
                state.mediaRecorder = null;
                resolve(blob);
            };

            recorder.addEventListener('stop', finalize, { once: true });
            try {
                recorder.stop();
            } catch (_) {
                finalize();
            }
        });

        return state.stopPromise;
    }

    function startSpeakingMeterMonitoring(ctx) {
        const state = speakingState(ctx);
        if (!state || !state.mediaStream) {
            return;
        }

        stopSpeakingMeterMonitoring(ctx);
        try {
            state.audioContext = state.audioContext || new (root.AudioContext || root.webkitAudioContext)();
            state.analyser = state.audioContext.createAnalyser();
            state.analyser.fftSize = 2048;
            state.micSource = state.audioContext.createMediaStreamSource(state.mediaStream);
            state.micSource.connect(state.analyser);
        } catch (_) {
            state.analyser = null;
            state.micSource = null;
            return;
        }

        const run = ctx.run;
        const gameConfig = getGameConfig(ctx, run) || {};
        const dataArray = new Uint8Array(state.analyser.fftSize);
        state.meterTimer = root.setInterval(function () {
            if (!ctx.run || ctx.run !== run || !state.analyser) {
                stopSpeakingMeterMonitoring(ctx);
                return;
            }

            state.analyser.getByteTimeDomainData(dataArray);
            let sumSquares = 0;
            for (let index = 0; index < dataArray.length; index += 1) {
                const normalized = (dataArray[index] - 128) / 128;
                sumSquares += normalized * normalized;
            }
            const rms = Math.sqrt(sumSquares / dataArray.length);
            const now = currentTimestamp();
            updateSpeakingMeter(ctx, rms * 12);

            if (!state.speechDetected && rms >= Number(gameConfig.speechStartThreshold || 0.06)) {
                state.speechDetected = true;
                state.speechStartedAt = now;
                state.silenceStartedAt = 0;
                if (isSpeakingStackRun(ctx, run)) {
                    run.lastSpeechAt = now;
                }
            } else if (state.speechDetected) {
                if (rms >= Number(gameConfig.silenceThreshold || 0.034)) {
                    state.silenceStartedAt = 0;
                    if (isSpeakingStackRun(ctx, run)) {
                        run.lastSpeechAt = now;
                    }
                } else if (!state.silenceStartedAt) {
                    state.silenceStartedAt = now;
                } else if (
                    (now - state.silenceStartedAt) >= Math.max(400, toInt(gameConfig.silenceWindowMs) || 1050)
                    && (now - state.speechStartedAt) >= Math.max(100, toInt(gameConfig.minSpeechMs) || 160)
                ) {
                    stopSpeakingCapture(ctx).then(function (blob) {
                        if (blob) {
                            processSpeakingAttempt(ctx, blob);
                        }
                    });
                }
            }
        }, 70);
    }

    function startSpeakingCapture(ctx) {
        const run = ctx.run;
        const state = speakingState(ctx);
        if (!run || !state) {
            return Promise.reject(new Error('missing_state'));
        }
        if (!ensureSpeakingSupported()) {
            return Promise.reject(new Error(String(ctx.i18n.gamesSpeakingNotSupported || 'This browser cannot record audio for speaking practice.')));
        }
        if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
            return Promise.resolve();
        }

        if (isSpeakingPracticeRun(ctx, run)) {
            resetSpeakingResultUi(ctx);
            setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingListening || 'Listening...'), true, 'listening');
        } else if (isSpeakingStackRun(ctx, run)) {
            setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackListening || 'Listening for the next word...'), {
                icon: 'record'
            });
        }

        return ensureSpeakingStream(ctx).then(function (stream) {
            state.audioChunks = [];
            state.currentBlob = null;
            state.stopPromise = null;
            state.speechDetected = false;
            state.speechStartedAt = 0;
            state.silenceStartedAt = 0;

            const mimeType = chooseRecordingMimeType();
            state.mediaRecorder = mimeType
                ? new root.MediaRecorder(stream, { mimeType: mimeType })
                : new root.MediaRecorder(stream);
            state.mediaRecorder.addEventListener('dataavailable', function (event) {
                if (event && event.data && event.data.size > 0) {
                    state.audioChunks.push(event.data);
                }
            });
            state.mediaRecorder.start();
            startSpeakingMeterMonitoring(ctx);
            clearSpeakingTimeout(state, 'maxStopTimer');
            state.maxStopTimer = root.setTimeout(function () {
                stopSpeakingCapture(ctx).then(function (blob) {
                    if (blob) {
                        processSpeakingAttempt(ctx, blob);
                    }
                });
            }, Math.max(1500, toInt((getGameConfig(ctx, run) || {}).maxRecordingMs) || 8000));
        });
    }

    function fetchJsonForm(url, formData, options) {
        return root.fetch(url, $.extend({
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }, (options && typeof options === 'object') ? options : {})).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    throw new Error(String(payload && payload.data && payload.data.message || payload && payload.message || response.statusText || 'request_failed'));
                }
                return payload;
            });
        });
    }

    function extractTranscriptFromLocalPayload(payload) {
        const source = (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object')
            ? payload.data
            : payload;
        const keys = ['predicted_ipa', 'ipa', 'transcript', 'text'];
        for (let index = 0; index < keys.length; index += 1) {
            const value = String(source && source[keys[index]] || '').trim();
            if (value) {
                return value;
            }
        }
        return '';
    }

    function isAudioMatcherProvider(provider) {
        return String(provider || '') === 'audio_matcher';
    }

    function speakingScoreBucket(score, provider) {
        const normalized = clamp(Number(score) || 0, 0, 100);
        const isMatcher = isAudioMatcherProvider(provider);
        const rightThreshold = isMatcher ? 78 : 90;
        const closeThreshold = isMatcher ? 52 : 65;
        if (normalized >= rightThreshold) {
            return 'right';
        }
        if (normalized >= closeThreshold) {
            return 'close';
        }
        return 'wrong';
    }

    function getSpeakingMatcherState(ctx) {
        const state = speakingState(ctx);
        if (!state) {
            return null;
        }
        if (!state.matcher || typeof state.matcher !== 'object') {
            state.matcher = {
                audioContext: null,
                referenceFeatureCache: {},
                referenceFeaturePromises: {}
            };
        }
        return state.matcher;
    }

    function getSpeakingMatcherAudioContext(ctx) {
        const matcher = getSpeakingMatcherState(ctx);
        if (!matcher) {
            return null;
        }

        if (!matcher.audioContext) {
            const AudioContextCtor = root.AudioContext || root.webkitAudioContext;
            if (!AudioContextCtor) {
                return null;
            }
            matcher.audioContext = new AudioContextCtor();
        }

        if (matcher.audioContext && matcher.audioContext.state === 'suspended') {
            matcher.audioContext.resume().catch(function () {});
        }

        return matcher.audioContext;
    }

    function decodeAudioArrayBuffer(ctx, arrayBuffer) {
        const audioContext = getSpeakingMatcherAudioContext(ctx);
        if (!audioContext) {
            return Promise.reject(new Error('audio_context_unavailable'));
        }
        const bufferCopy = arrayBuffer.slice(0);

        try {
            const decoded = audioContext.decodeAudioData(bufferCopy);
            if (decoded && typeof decoded.then === 'function') {
                return decoded;
            }
        } catch (_) {
            // Fall through to callback style.
        }

        return new Promise(function (resolve, reject) {
            try {
                audioContext.decodeAudioData(
                    bufferCopy,
                    function (result) {
                        resolve(result);
                    },
                    function (error) {
                        reject(error || new Error('decode_failed'));
                    }
                );
            } catch (error) {
                reject(error);
            }
        });
    }

    function audioBufferToMonoSamples(audioBuffer) {
        if (!audioBuffer || !audioBuffer.numberOfChannels || !audioBuffer.length) {
            return new Float32Array(0);
        }

        const channels = Math.max(1, toInt(audioBuffer.numberOfChannels));
        const length = toInt(audioBuffer.length);
        if (channels === 1) {
            const source = audioBuffer.getChannelData(0);
            return source ? new Float32Array(source) : new Float32Array(0);
        }

        const mono = new Float32Array(length);
        for (let channelIndex = 0; channelIndex < channels; channelIndex += 1) {
            const channelData = audioBuffer.getChannelData(channelIndex);
            if (!channelData) {
                continue;
            }
            for (let sampleIndex = 0; sampleIndex < length; sampleIndex += 1) {
                mono[sampleIndex] += channelData[sampleIndex];
            }
        }
        for (let sampleIndex = 0; sampleIndex < length; sampleIndex += 1) {
            mono[sampleIndex] /= channels;
        }
        return mono;
    }

    function trimMatcherSilence(samples, sampleRate) {
        if (!(samples instanceof Float32Array) || samples.length <= 0) {
            return new Float32Array(0);
        }

        let peak = 0;
        for (let index = 0; index < samples.length; index += 1) {
            const value = Math.abs(samples[index]);
            if (value > peak) {
                peak = value;
            }
        }
        if (peak <= 0) {
            return new Float32Array(0);
        }

        const threshold = Math.max(0.008, peak * 0.06);
        let start = -1;
        let end = -1;
        for (let index = 0; index < samples.length; index += 1) {
            if (Math.abs(samples[index]) >= threshold) {
                start = index;
                break;
            }
        }
        for (let index = samples.length - 1; index >= 0; index -= 1) {
            if (Math.abs(samples[index]) >= threshold) {
                end = index;
                break;
            }
        }

        if (start < 0 || end < start) {
            return new Float32Array(0);
        }

        const pad = Math.max(0, Math.round(Math.max(8000, sampleRate || 0) * 0.012));
        const from = Math.max(0, start - pad);
        const to = Math.min(samples.length, end + pad + 1);

        return samples.slice(from, to);
    }

    function normalizeSamplesByPeak(samples) {
        if (!(samples instanceof Float32Array) || samples.length <= 0) {
            return new Float32Array(0);
        }

        let peak = 0;
        for (let index = 0; index < samples.length; index += 1) {
            const value = Math.abs(samples[index]);
            if (value > peak) {
                peak = value;
            }
        }
        if (peak <= 0) {
            return new Float32Array(samples.length);
        }

        const normalized = new Float32Array(samples.length);
        for (let index = 0; index < samples.length; index += 1) {
            normalized[index] = samples[index] / peak;
        }
        return normalized;
    }

    function resampleSeries(values, targetLength) {
        const target = Math.max(1, toInt(targetLength));
        const source = Array.isArray(values)
            ? values
            : (values instanceof Float32Array || values instanceof Uint8Array ? Array.from(values) : []);
        if (!source.length) {
            return new Array(target).fill(0);
        }
        if (source.length === target) {
            return source.slice();
        }
        if (source.length === 1) {
            return new Array(target).fill(Number(source[0]) || 0);
        }

        const result = new Array(target);
        const maxSourceIndex = source.length - 1;
        for (let targetIndex = 0; targetIndex < target; targetIndex += 1) {
            const position = (targetIndex / Math.max(1, target - 1)) * maxSourceIndex;
            const left = Math.floor(position);
            const right = Math.min(maxSourceIndex, left + 1);
            const ratio = position - left;
            const leftValue = Number(source[left]) || 0;
            const rightValue = Number(source[right]) || 0;
            result[targetIndex] = leftValue + ((rightValue - leftValue) * ratio);
        }
        return result;
    }

    function normalizeSeriesToUnit(values) {
        const source = Array.isArray(values) ? values : [];
        if (!source.length) {
            return [];
        }
        let min = source[0];
        let max = source[0];
        source.forEach(function (value) {
            const numeric = Number(value) || 0;
            if (numeric < min) {
                min = numeric;
            }
            if (numeric > max) {
                max = numeric;
            }
        });
        const range = max - min;
        if (range <= 1e-6) {
            return source.map(function () {
                return 0;
            });
        }
        return source.map(function (value) {
            return clamp(((Number(value) || 0) - min) / range, 0, 1);
        });
    }

    function cosineSimilarity(left, right) {
        if (!Array.isArray(left) || !Array.isArray(right) || !left.length || !right.length) {
            return 0;
        }
        const length = Math.min(left.length, right.length);
        let dot = 0;
        let leftNorm = 0;
        let rightNorm = 0;
        for (let index = 0; index < length; index += 1) {
            const a = Number(left[index]) || 0;
            const b = Number(right[index]) || 0;
            dot += a * b;
            leftNorm += a * a;
            rightNorm += b * b;
        }
        if (leftNorm <= 0 || rightNorm <= 0) {
            return 0;
        }
        return clamp(dot / (Math.sqrt(leftNorm) * Math.sqrt(rightNorm)), -1, 1);
    }

    function meanAbsoluteDifference(left, right) {
        if (!Array.isArray(left) || !Array.isArray(right) || !left.length || !right.length) {
            return 1;
        }
        const length = Math.min(left.length, right.length);
        let total = 0;
        for (let index = 0; index < length; index += 1) {
            const delta = (Number(left[index]) || 0) - (Number(right[index]) || 0);
            total += Math.abs(delta);
        }
        return total / Math.max(1, length);
    }

    function getMatcherWindow(size) {
        const key = Math.max(1, toInt(size));
        if (matcherWindowCache[key]) {
            return matcherWindowCache[key];
        }

        const windowValues = new Float32Array(key);
        for (let index = 0; index < key; index += 1) {
            windowValues[index] = 0.5 - (0.5 * Math.cos((2 * Math.PI * index) / Math.max(1, key - 1)));
        }
        matcherWindowCache[key] = windowValues;
        return windowValues;
    }

    function buildSpectralProfile(samples, sampleRate, frameSize, hopSize, bandCount) {
        if (!(samples instanceof Float32Array) || samples.length < frameSize) {
            return null;
        }

        const fftSize = Math.max(64, toInt(frameSize));
        const hop = Math.max(32, toInt(hopSize));
        const bands = Math.max(4, toInt(bandCount));
        const windowValues = getMatcherWindow(fftSize);
        const halfBins = Math.floor(fftSize / 2);
        const bandFrames = [];
        const centroidFrames = [];
        const rolloffFrames = [];

        for (let frameStart = 0; frameStart + fftSize <= samples.length; frameStart += hop) {
            const windowed = new Float32Array(fftSize);
            for (let index = 0; index < fftSize; index += 1) {
                windowed[index] = (samples[frameStart + index] || 0) * windowValues[index];
            }

            const magnitudes = new Array(halfBins).fill(0);
            let totalMagnitude = 0;
            for (let bin = 0; bin < halfBins; bin += 1) {
                let real = 0;
                let imag = 0;
                const angularStep = (2 * Math.PI * bin) / fftSize;
                for (let sampleIndex = 0; sampleIndex < fftSize; sampleIndex += 1) {
                    const phase = angularStep * sampleIndex;
                    const sample = windowed[sampleIndex] || 0;
                    real += sample * Math.cos(phase);
                    imag -= sample * Math.sin(phase);
                }
                const magnitude = Math.sqrt((real * real) + (imag * imag));
                magnitudes[bin] = magnitude;
                totalMagnitude += magnitude;
            }

            if (totalMagnitude <= 1e-6) {
                continue;
            }

            const bandEnergies = new Array(bands).fill(0);
            let centroidNumerator = 0;
            let cumulativeMagnitude = 0;
            let rolloffFrequency = 0;
            const rolloffTarget = totalMagnitude * 0.85;
            for (let bin = 0; bin < halfBins; bin += 1) {
                const magnitude = magnitudes[bin];
                const normalizedBin = bin / Math.max(1, halfBins - 1);
                const bandIndex = Math.min(
                    bands - 1,
                    Math.floor(Math.pow(normalizedBin, 0.7) * bands)
                );
                const safeBandIndex = isFinite(bandIndex) ? Math.max(0, bandIndex) : 0;
                bandEnergies[safeBandIndex] += magnitude;
                centroidNumerator += normalizedBin * magnitude;
                cumulativeMagnitude += magnitude;
                if (!rolloffFrequency && cumulativeMagnitude >= rolloffTarget) {
                    rolloffFrequency = normalizedBin;
                }
            }

            const loggedBands = bandEnergies.map(function (value) {
                return Math.log(1 + Math.max(0, value));
            });
            const bandTotal = loggedBands.reduce(function (sum, value) {
                return sum + value;
            }, 0);
            const normalizedBands = bandTotal > 0
                ? loggedBands.map(function (value) {
                    return value / bandTotal;
                })
                : loggedBands.map(function () {
                    return 0;
                });

            bandFrames.push(normalizedBands);
            centroidFrames.push(centroidNumerator / totalMagnitude);
            rolloffFrames.push(rolloffFrequency || 0);
        }

        if (!bandFrames.length) {
            return null;
        }

        const resampledFrameCount = 40;
        const flattenedBands = [];
        for (let bandIndex = 0; bandIndex < bands; bandIndex += 1) {
            const series = bandFrames.map(function (frame) {
                return Number(frame[bandIndex]) || 0;
            });
            const normalizedSeries = normalizeSeriesToUnit(series);
            const resampled = resampleSeries(normalizedSeries, resampledFrameCount);
            for (let sampleIndex = 0; sampleIndex < resampled.length; sampleIndex += 1) {
                flattenedBands.push(Number(resampled[sampleIndex]) || 0);
            }
        }

        return {
            bands: flattenedBands,
            centroid: resampleSeries(normalizeSeriesToUnit(centroidFrames), resampledFrameCount),
            rolloff: resampleSeries(normalizeSeriesToUnit(rolloffFrames), resampledFrameCount),
            rawBandFrames: bandFrames,
            rawCentroidFrames: centroidFrames,
            rawRolloffFrames: rolloffFrames
        };
    }

    function averageFrameVectors(frames, indices) {
        const rows = Array.isArray(frames) ? frames : [];
        const selectedIndices = Array.isArray(indices) ? indices : [];
        if (!rows.length || !selectedIndices.length || !Array.isArray(rows[0])) {
            return [];
        }

        const vectorLength = rows[0].length;
        const sums = new Array(vectorLength).fill(0);
        let count = 0;
        selectedIndices.forEach(function (frameIndex) {
            const row = rows[toInt(frameIndex)];
            if (!Array.isArray(row) || row.length !== vectorLength) {
                return;
            }
            for (let index = 0; index < vectorLength; index += 1) {
                sums[index] += Number(row[index]) || 0;
            }
            count += 1;
        });

        if (!count) {
            return [];
        }

        return sums.map(function (value) {
            return value / count;
        });
    }

    function buildFrameWindowIndices(frameCount, startRatio, endRatio) {
        const totalFrames = Math.max(0, toInt(frameCount));
        if (!totalFrames) {
            return [];
        }

        const start = clamp(startRatio, 0, 1);
        const end = clamp(endRatio, start, 1);
        const from = Math.max(0, Math.floor(totalFrames * start));
        const to = Math.min(totalFrames, Math.ceil(totalFrames * end));
        const indices = [];
        for (let index = from; index < to; index += 1) {
            indices.push(index);
        }
        return indices;
    }

    function sampleSequenceIndices(indices, maxItems) {
        const source = Array.isArray(indices) ? indices.slice() : [];
        const limit = Math.max(1, toInt(maxItems));
        if (source.length <= limit) {
            return source;
        }

        const sampled = [];
        for (let index = 0; index < limit; index += 1) {
            const sourcePosition = Math.round((index / Math.max(1, limit - 1)) * (source.length - 1));
            sampled.push(source[sourcePosition]);
        }
        return sampled;
    }

    function detectNucleusFrames(envelopeFrames, zcrFrames, frameDurationSec) {
        const envelope = normalizeSeriesToUnit(envelopeFrames);
        const zcr = normalizeSeriesToUnit(zcrFrames);
        if (!envelope.length) {
            return [];
        }

        const minGapFrames = Math.max(1, Math.round(0.09 / Math.max(0.004, Number(frameDurationSec) || 0.01)));
        const peaks = [];
        let lastAcceptedIndex = -minGapFrames;

        for (let index = 1; index < envelope.length - 1; index += 1) {
            const energy = Number(envelope[index]) || 0;
            const voicedPenalty = (Number(zcr[index]) || 0) * 0.52;
            const score = energy * (1 - voicedPenalty);
            const isLocalPeak = energy >= (Number(envelope[index - 1]) || 0) && energy >= (Number(envelope[index + 1]) || 0);
            if (!isLocalPeak || energy < 0.22 || score < 0.3) {
                continue;
            }

            if ((index - lastAcceptedIndex) < minGapFrames && peaks.length) {
                if (score > peaks[peaks.length - 1].score) {
                    peaks[peaks.length - 1] = {
                        index: index,
                        score: score
                    };
                    lastAcceptedIndex = index;
                }
                continue;
            }

            peaks.push({
                index: index,
                score: score
            });
            lastAcceptedIndex = index;
        }

        if (!peaks.length) {
            let bestIndex = 0;
            let bestScore = 0;
            envelope.forEach(function (value, index) {
                const score = (Number(value) || 0) * (1 - ((Number(zcr[index]) || 0) * 0.45));
                if (score > bestScore) {
                    bestScore = score;
                    bestIndex = index;
                }
            });
            if (bestScore >= 0.14) {
                return [bestIndex];
            }
            return [];
        }

        return peaks.map(function (peak) {
            return peak.index;
        });
    }

    function buildNucleusSequence(frames, centroidFrames, rolloffFrames, nucleusIndices, maxItems) {
        const rows = Array.isArray(frames) ? frames : [];
        const indices = sampleSequenceIndices(nucleusIndices, maxItems);
        if (!rows.length || !indices.length || !Array.isArray(rows[0])) {
            return [];
        }

        const flattened = [];
        indices.forEach(function (frameIndex) {
            const row = rows[toInt(frameIndex)];
            if (!Array.isArray(row)) {
                return;
            }
            row.forEach(function (value) {
                flattened.push(Number(value) || 0);
            });
            flattened.push(Number((Array.isArray(centroidFrames) ? centroidFrames[frameIndex] : 0)) || 0);
            flattened.push(Number((Array.isArray(rolloffFrames) ? rolloffFrames[frameIndex] : 0)) || 0);
        });

        return flattened;
    }

    function extractMatcherFeatures(samples, sampleRate) {
        const trimmed = trimMatcherSilence(samples, sampleRate);
        if (!(trimmed instanceof Float32Array) || trimmed.length <= 0) {
            return null;
        }
        const normalized = normalizeSamplesByPeak(trimmed);
        const effectiveSampleRate = Math.max(8000, toInt(sampleRate) || 16000);
        const minSamples = Math.max(240, Math.round(effectiveSampleRate * 0.08));
        if (normalized.length < minSamples) {
            return null;
        }

        const frameSize = Math.max(64, Math.round(effectiveSampleRate * 0.012));
        const hopSize = Math.max(32, Math.round(frameSize * 0.5));
        const envelopeFrames = [];
        const zcrFrames = [];
        let cursor = 0;
        while (cursor < normalized.length) {
            const end = Math.min(normalized.length, cursor + frameSize);
            const count = Math.max(1, end - cursor);
            let rmsSum = 0;
            let zeroCrossings = 0;
            let previous = normalized[cursor] || 0;
            for (let index = cursor; index < end; index += 1) {
                const value = normalized[index] || 0;
                rmsSum += value * value;
                if (index > cursor) {
                    const currentSign = value >= 0;
                    const previousSign = previous >= 0;
                    if (currentSign !== previousSign) {
                        zeroCrossings += 1;
                    }
                }
                previous = value;
            }
            envelopeFrames.push(Math.sqrt(rmsSum / count));
            zcrFrames.push(zeroCrossings / count);
            cursor += hopSize;
        }

        const envelope = resampleSeries(normalizeSeriesToUnit(envelopeFrames), 96);
        const zcr = resampleSeries(normalizeSeriesToUnit(zcrFrames), 96);
        const waveform = resampleSeries(Array.from(normalized), 512);
        const spectral = buildSpectralProfile(
            normalized,
            effectiveSampleRate,
            frameSize,
            hopSize,
            8
        );
        const frameDurationSec = hopSize / effectiveSampleRate;
        const nucleusIndices = spectral
            ? detectNucleusFrames(envelopeFrames, zcrFrames, frameDurationSec)
            : [];
        const onsetIndices = spectral ? buildFrameWindowIndices(spectral.rawBandFrames.length, 0, 0.18) : [];
        const codaIndices = spectral ? buildFrameWindowIndices(spectral.rawBandFrames.length, 0.82, 1) : [];
        const absoluteEnergy = normalized.reduce(function (sum, value) {
            return sum + Math.abs(value || 0);
        }, 0) / Math.max(1, normalized.length);

        return {
            durationSec: normalized.length / effectiveSampleRate,
            envelope: envelope,
            zcr: zcr,
            waveform: waveform,
            spectralBands: spectral ? spectral.bands : [],
            spectralCentroid: spectral ? spectral.centroid : [],
            spectralRolloff: spectral ? spectral.rolloff : [],
            nucleusCount: nucleusIndices.length,
            nucleusBandProfile: spectral ? averageFrameVectors(spectral.rawBandFrames, nucleusIndices) : [],
            nucleusSequence: spectral
                ? buildNucleusSequence(
                    spectral.rawBandFrames,
                    spectral.rawCentroidFrames,
                    spectral.rawRolloffFrames,
                    nucleusIndices,
                    4
                )
                : [],
            onsetBandProfile: spectral ? averageFrameVectors(spectral.rawBandFrames, onsetIndices) : [],
            codaBandProfile: spectral ? averageFrameVectors(spectral.rawBandFrames, codaIndices) : [],
            absoluteEnergy: absoluteEnergy
        };
    }

    function matcherSimilarityScore(leftFeatures, rightFeatures) {
        if (!leftFeatures || !rightFeatures) {
            return 0;
        }

        const durationRatio = (leftFeatures.durationSec > 0 && rightFeatures.durationSec > 0)
            ? Math.min(leftFeatures.durationSec, rightFeatures.durationSec) / Math.max(leftFeatures.durationSec, rightFeatures.durationSec)
            : 0;
        const energyRatio = (leftFeatures.absoluteEnergy > 0 && rightFeatures.absoluteEnergy > 0)
            ? Math.min(leftFeatures.absoluteEnergy, rightFeatures.absoluteEnergy) / Math.max(leftFeatures.absoluteEnergy, rightFeatures.absoluteEnergy)
            : 0;
        const envelopeCosine = (cosineSimilarity(leftFeatures.envelope, rightFeatures.envelope) + 1) * 0.5;
        const envelopeMae = 1 - meanAbsoluteDifference(leftFeatures.envelope, rightFeatures.envelope);
        const waveformCosine = (cosineSimilarity(leftFeatures.waveform, rightFeatures.waveform) + 1) * 0.5;
        const waveformMae = 1 - (meanAbsoluteDifference(leftFeatures.waveform, rightFeatures.waveform) * 0.5);
        const zcrCosine = (cosineSimilarity(leftFeatures.zcr, rightFeatures.zcr) + 1) * 0.5;
        const spectralBandCosine = (cosineSimilarity(leftFeatures.spectralBands, rightFeatures.spectralBands) + 1) * 0.5;
        const spectralBandMae = 1 - meanAbsoluteDifference(leftFeatures.spectralBands, rightFeatures.spectralBands);
        const spectralCentroidScore = 1 - meanAbsoluteDifference(leftFeatures.spectralCentroid, rightFeatures.spectralCentroid);
        const spectralRolloffScore = 1 - meanAbsoluteDifference(leftFeatures.spectralRolloff, rightFeatures.spectralRolloff);
        const nucleusCountRatio = (Number(leftFeatures.nucleusCount) > 0 && Number(rightFeatures.nucleusCount) > 0)
            ? Math.min(Number(leftFeatures.nucleusCount), Number(rightFeatures.nucleusCount))
                / Math.max(Number(leftFeatures.nucleusCount), Number(rightFeatures.nucleusCount))
            : 0;
        const nucleusProfileCosine = (cosineSimilarity(leftFeatures.nucleusBandProfile, rightFeatures.nucleusBandProfile) + 1) * 0.5;
        const nucleusProfileMae = 1 - meanAbsoluteDifference(leftFeatures.nucleusBandProfile, rightFeatures.nucleusBandProfile);
        const nucleusSequenceCosine = (cosineSimilarity(leftFeatures.nucleusSequence, rightFeatures.nucleusSequence) + 1) * 0.5;
        const nucleusSequenceMae = 1 - meanAbsoluteDifference(leftFeatures.nucleusSequence, rightFeatures.nucleusSequence);
        const onsetCosine = (cosineSimilarity(leftFeatures.onsetBandProfile, rightFeatures.onsetBandProfile) + 1) * 0.5;
        const onsetMae = 1 - meanAbsoluteDifference(leftFeatures.onsetBandProfile, rightFeatures.onsetBandProfile);
        const codaCosine = (cosineSimilarity(leftFeatures.codaBandProfile, rightFeatures.codaBandProfile) + 1) * 0.5;
        const codaMae = 1 - meanAbsoluteDifference(leftFeatures.codaBandProfile, rightFeatures.codaBandProfile);

        const envelopeScore = clamp((envelopeCosine * 0.6) + (clamp(envelopeMae, 0, 1) * 0.4), 0, 1);
        const waveformScore = clamp((waveformCosine * 0.5) + (clamp(waveformMae, 0, 1) * 0.5), 0, 1);
        const zcrScore = clamp(zcrCosine, 0, 1);
        const spectralBandScore = clamp((spectralBandCosine * 0.62) + (clamp(spectralBandMae, 0, 1) * 0.38), 0, 1);
        const nucleusProfileScore = clamp((nucleusProfileCosine * 0.62) + (clamp(nucleusProfileMae, 0, 1) * 0.38), 0, 1);
        const nucleusSequenceScore = clamp((nucleusSequenceCosine * 0.58) + (clamp(nucleusSequenceMae, 0, 1) * 0.42), 0, 1);
        const onsetScore = clamp((onsetCosine * 0.56) + (clamp(onsetMae, 0, 1) * 0.44), 0, 1);
        const codaScore = clamp((codaCosine * 0.56) + (clamp(codaMae, 0, 1) * 0.44), 0, 1);
        const spectralShapeScore = clamp(
            (spectralBandScore * 0.7)
            + (clamp(spectralCentroidScore, 0, 1) * 0.18)
            + (clamp(spectralRolloffScore, 0, 1) * 0.12),
            0,
            1
        );
        const vowelCoreScore = clamp((nucleusProfileScore * 0.52) + (nucleusSequenceScore * 0.48), 0, 1);
        const edgeScore = clamp((onsetScore * 0.54) + (codaScore * 0.46), 0, 1);

        const baseScore = (
            (envelopeScore * 0.14)
            + (waveformScore * 0.08)
            + (zcrScore * 0.08)
            + (spectralShapeScore * 0.24)
            + (vowelCoreScore * 0.25)
            + (edgeScore * 0.09)
            + (clamp(durationRatio, 0, 1) * 0.07)
            + (clamp(energyRatio, 0, 1) * 0.05)
        ) * 100;

        const durationPenalty = Math.pow(1 - clamp(durationRatio, 0, 1), 1.18) * 42;
        const spectralPenalty = Math.pow(1 - spectralShapeScore, 1.24) * 22;
        const envelopePenalty = Math.pow(1 - envelopeScore, 1.1) * 8;
        const vowelPenalty = Math.pow(1 - vowelCoreScore, 1.18) * 16;
        const edgePenalty = Math.pow(1 - edgeScore, 1.1) * 9;
        const nucleusCountPenalty = Math.pow(1 - clamp(nucleusCountRatio, 0, 1), 1.12) * 28;
        const calibrated = clamp(
            baseScore - durationPenalty - spectralPenalty - envelopePenalty - vowelPenalty - edgePenalty - nucleusCountPenalty,
            0,
            100
        );

        return Math.round(calibrated * 100) / 100;
    }

    function loadSourceArrayBuffer(source, options) {
        if (source instanceof Blob) {
            return source.arrayBuffer();
        }
        const url = String(source || '').trim();
        if (!url) {
            return Promise.reject(new Error('missing_audio_source'));
        }
        return root.fetch(url, $.extend({
            method: 'GET',
            credentials: 'same-origin',
            cache: 'force-cache'
        }, (options && typeof options === 'object') ? options : {})).then(function (response) {
            if (!response || !response.ok) {
                throw new Error('audio_fetch_failed');
            }
            return response.arrayBuffer();
        });
    }

    function getMatcherFeaturesFromSource(ctx, source, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const cacheKey = opts.cacheKey ? String(opts.cacheKey) : '';
        const matcher = getSpeakingMatcherState(ctx);
        if (!matcher) {
            return Promise.reject(new Error('missing_matcher_state'));
        }

        if (cacheKey && Object.prototype.hasOwnProperty.call(matcher.referenceFeatureCache, cacheKey)) {
            return Promise.resolve(matcher.referenceFeatureCache[cacheKey]);
        }
        if (cacheKey && Object.prototype.hasOwnProperty.call(matcher.referenceFeaturePromises, cacheKey)) {
            return matcher.referenceFeaturePromises[cacheKey];
        }

        const request = loadSourceArrayBuffer(source, opts.fetchOptions).then(function (arrayBuffer) {
            return decodeAudioArrayBuffer(ctx, arrayBuffer);
        }).then(function (audioBuffer) {
            const samples = audioBufferToMonoSamples(audioBuffer);
            const features = extractMatcherFeatures(samples, Number(audioBuffer && audioBuffer.sampleRate) || 16000);
            if (!features) {
                throw new Error('audio_too_short');
            }
            if (cacheKey) {
                matcher.referenceFeatureCache[cacheKey] = features;
            }
            return features;
        }).finally(function () {
            if (cacheKey) {
                delete matcher.referenceFeaturePromises[cacheKey];
            }
        });

        if (cacheKey) {
            matcher.referenceFeaturePromises[cacheKey] = request;
        }
        return request;
    }

    function getWordSpeakingDisplayData(word, run) {
        const displayTexts = (word && word.speaking_display_texts && typeof word.speaking_display_texts === 'object')
            ? $.extend({}, word.speaking_display_texts)
            : {};
        const targetText = String(
            word && word.speaking_target_text
            || displayTexts.target_text
            || word && word.title
            || ''
        ).trim();
        const targetLabel = String(
            word && word.speaking_target_label
            || displayTexts.target_label
            || ''
        ).trim();
        const targetField = String(
            run && run.targetField
            || word && word.speaking_target_field
            || displayTexts.target_field
            || ''
        ).trim();

        if (!displayTexts.target_text) {
            displayTexts.target_text = targetText;
        }
        if (!displayTexts.target_label) {
            displayTexts.target_label = targetLabel;
        }
        if (!displayTexts.target_field) {
            displayTexts.target_field = targetField;
        }
        if (!displayTexts.title) {
            displayTexts.title = String(word && word.title || '');
        }
        if (!displayTexts.ipa) {
            displayTexts.ipa = String(word && word.recording_ipa || '');
        }

        return {
            targetText: targetText,
            targetLabel: targetLabel,
            targetField: targetField,
            displayTexts: displayTexts
        };
    }

    function normalizeMatcherText(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[\s'’`ˈˌ.\-_/\\]+/g, '')
            .trim();
    }

    function extractMatcherTextProfile(word, run) {
        const displayData = getWordSpeakingDisplayData(word, run);
        const normalizedText = normalizeMatcherText(displayData.targetText || word && word.title || '');
        const graphemes = segmentDiffGraphemes(normalizedText);
        let vowelCount = 0;
        let priorWasVowel = false;

        graphemes.forEach(function (grapheme) {
            const isVowel = MATCHER_VOWEL_CHAR_PATTERN.test(String(grapheme || ''));
            if (isVowel && !priorWasVowel) {
                vowelCount += 1;
            }
            priorWasVowel = isVowel;
        });

        return {
            normalizedText: normalizedText,
            graphemeCount: graphemes.length,
            vowelCount: vowelCount,
            leadingChar: graphemes.length ? String(graphemes[0] || '') : '',
            trailingChar: graphemes.length ? String(graphemes[graphemes.length - 1] || '') : ''
        };
    }

    function buildMatcherPracticeCandidatePool(run, targetWord, options) {
        const sourceWords = Array.isArray(run && run.words) ? run.words : [];
        const targetId = toInt(targetWord && targetWord.id);
        const opts = (options && typeof options === 'object') ? options : {};
        const limit = Math.max(0, toInt(opts.limit) || MATCHER_COMPETITION_DISTRACTOR_LIMIT);
        if (!targetId || !limit) {
            return targetWord ? [targetWord] : [];
        }

        const targetProfile = extractMatcherTextProfile(targetWord, run);
        const candidates = sourceWords.filter(function (word) {
            return !!word
                && toInt(word.id) !== targetId
                && String(word.speaking_best_correct_audio_url || '').trim() !== '';
        }).map(function (word, index) {
            const profile = extractMatcherTextProfile(word, run);
            const graphemeDelta = Math.abs(profile.graphemeCount - targetProfile.graphemeCount);
            const vowelDelta = Math.abs(profile.vowelCount - targetProfile.vowelCount);
            const sameStart = targetProfile.leadingChar !== '' && profile.leadingChar === targetProfile.leadingChar;
            const sameEnd = targetProfile.trailingChar !== '' && profile.trailingChar === targetProfile.trailingChar;
            const overlap = targetProfile.normalizedText !== '' && profile.normalizedText !== ''
                ? (targetProfile.normalizedText.indexOf(profile.leadingChar) !== -1 ? 1 : 0)
                    + (targetProfile.normalizedText.indexOf(profile.trailingChar) !== -1 ? 1 : 0)
                : 0;

            return {
                word: word,
                index: index,
                hardScore: (graphemeDelta * 1.3) + (vowelDelta * 2.2) - (sameStart ? 0.8 : 0) - (sameEnd ? 0.55 : 0) - (overlap * 0.18)
            };
        });

        if (!candidates.length) {
            return [targetWord];
        }

        const hardCount = Math.min(limit, Math.max(4, Math.ceil(limit * 0.6)));
        const easyCount = Math.max(0, limit - hardCount);
        const hardMatches = candidates
            .slice()
            .sort(function (left, right) {
                if (left.hardScore === right.hardScore) {
                    return left.index - right.index;
                }
                return left.hardScore - right.hardScore;
            })
            .slice(0, hardCount)
            .map(function (row) {
                return row.word;
            });

        const selectedIds = {};
        selectedIds[targetId] = true;
        hardMatches.forEach(function (word) {
            selectedIds[toInt(word && word.id)] = true;
        });

        const easyMatches = candidates
            .slice()
            .sort(function (left, right) {
                if (left.hardScore === right.hardScore) {
                    return left.index - right.index;
                }
                return right.hardScore - left.hardScore;
            })
            .map(function (row) {
                return row.word;
            })
            .filter(function (word) {
                return !selectedIds[toInt(word && word.id)];
            })
            .slice(0, easyCount);

        return [targetWord].concat(hardMatches, easyMatches);
    }

    function applyMatcherCompetitionAdjustments(rankedRows, options) {
        const rows = (Array.isArray(rankedRows) ? rankedRows : []).filter(function (row) {
            return !!row && !!row.word;
        });
        if (!rows.length) {
            return null;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const expectedWordId = toInt(opts.expectedWordId);
        const focusRow = expectedWordId
            ? (rows.find(function (row) {
                return toInt(row.word && row.word.id) === expectedWordId;
            }) || null)
            : rows[0];
        if (!focusRow) {
            return null;
        }

        const competitors = rows.filter(function (row) {
            return toInt(row.word && row.word.id) !== toInt(focusRow.word && focusRow.word.id);
        });
        const sortedCompetitors = competitors
            .slice()
            .sort(function (left, right) {
                return (Number(right.score) || 0) - (Number(left.score) || 0);
            });
        const topCompetitorScore = Number(sortedCompetitors[0] && sortedCompetitors[0].score) || 0;
        const topThree = sortedCompetitors.slice(0, 3);
        const meanTopCompetitorScore = topThree.length
            ? topThree.reduce(function (sum, row) {
                return sum + (Number(row && row.score) || 0);
            }, 0) / topThree.length
            : 0;
        const closeCompetitorCount = sortedCompetitors.filter(function (row) {
            const competitorScore = Number(row && row.score) || 0;
            return competitorScore >= Math.max(34, (Number(focusRow.score) || 0) - 12);
        }).length;
        const margin = (Number(focusRow.score) || 0) - topCompetitorScore;

        let adjustedScore = Number(focusRow.score) || 0;
        if (expectedWordId) {
            if (margin < 0) {
                adjustedScore -= 36 + Math.min(24, Math.abs(margin) * 1.35);
            } else if (margin < 4) {
                adjustedScore -= 20 + ((4 - margin) * 2.8);
            } else if (margin < 9) {
                adjustedScore -= (9 - margin) * 1.65;
            }
        } else {
            if (margin < 2.5) {
                adjustedScore -= 18 + ((2.5 - margin) * 4.2);
            } else if (margin < 6) {
                adjustedScore -= (6 - margin) * 1.8;
            }
        }

        const genericPenalty = Math.max(0, meanTopCompetitorScore - 20) * 0.82;
        const crowdPenalty = Math.max(0, closeCompetitorCount - 1) * 4.6;
        adjustedScore -= genericPenalty + crowdPenalty;

        if (margin > 14 && meanTopCompetitorScore < 26) {
            adjustedScore += Math.min(5, (margin - 14) * 0.28);
        }

        return {
            focusRow: focusRow,
            adjustedScore: clamp(adjustedScore, 0, 100),
            rawScore: clamp(Number(focusRow.score) || 0, 0, 100),
            margin: margin,
            topCompetitorScore: topCompetitorScore,
            meanTopCompetitorScore: meanTopCompetitorScore,
            closeCompetitorCount: closeCompetitorCount,
            matchedWordIsExpected: expectedWordId > 0 ? toInt(rows[0] && rows[0].word && rows[0].word.id) === expectedWordId : true
        };
    }

    function buildAudioMatcherResult(ctx, run, word, score, transcriptText, meta) {
        const resultMeta = (meta && typeof meta === 'object') ? meta : {};
        const roundedScore = Math.round(clamp(Number(score) || 0, 0, 100) * 100) / 100;
        const bucket = speakingScoreBucket(roundedScore, String(run && run.provider || ''));
        const displayData = getWordSpeakingDisplayData(word, run);
        const transcript = String(transcriptText || '').trim();

        return {
            wordset_id: toInt(ctx && ctx.wordsetId),
            word_id: toInt(word && word.id),
            target_field: displayData.targetField,
            target_label: displayData.targetLabel,
            target_text: displayData.targetText,
            normalized_target_text: displayData.targetText,
            normalized_transcript_text: transcript,
            score: roundedScore,
            raw_score: Math.round(clamp(Number(resultMeta.rawScore) || roundedScore, 0, 100) * 100) / 100,
            bucket: bucket,
            competition_margin: Math.round((Number(resultMeta.margin) || 0) * 100) / 100,
            top_competitor_score: Math.round((Number(resultMeta.topCompetitorScore) || 0) * 100) / 100,
            mean_top_competitor_score: Math.round((Number(resultMeta.meanTopCompetitorScore) || 0) * 100) / 100,
            close_competitor_count: toInt(resultMeta.closeCompetitorCount),
            display_texts: displayData.displayTexts,
            best_correct_audio_url: String(word && word.speaking_best_correct_audio_url || ''),
            transcript_text: transcript
        };
    }

    function scoreSpeakingAudioMatcher(ctx, run, blob, candidateWords, options) {
        const words = (Array.isArray(candidateWords) ? candidateWords : []).filter(function (word) {
            return !!word && String(word.speaking_best_correct_audio_url || '').trim() !== '';
        });
        if (!words.length) {
            return Promise.reject(new Error('no_match_candidates'));
        }

        const opts = (options && typeof options === 'object') ? options : {};
        return getMatcherFeaturesFromSource(ctx, blob).then(function (attemptFeatures) {
            return Promise.all(words.map(function (word) {
                const audioUrl = String(word && word.speaking_best_correct_audio_url || '').trim();
                if (!audioUrl) {
                    return null;
                }

                return getMatcherFeaturesFromSource(ctx, audioUrl, {
                    cacheKey: audioUrl
                }).then(function (referenceFeatures) {
                    return {
                        word: word,
                        score: matcherSimilarityScore(referenceFeatures, attemptFeatures)
                    };
                }).catch(function () {
                    return null;
                });
            })).then(function (rows) {
                const ranked = rows.filter(function (row) {
                    return !!row && !!row.word;
                }).sort(function (left, right) {
                    return (Number(right.score) || 0) - (Number(left.score) || 0);
                });
                if (!ranked.length) {
                    throw new Error('no_match_candidates');
                }

                const competition = applyMatcherCompetitionAdjustments(ranked, {
                    expectedWordId: toInt(opts.expectedWordId)
                });
                if (!competition || !competition.focusRow || !competition.focusRow.word) {
                    throw new Error('no_match_candidates');
                }

                const best = competition.focusRow;
                const bestResult = buildAudioMatcherResult(
                    ctx,
                    run,
                    best.word,
                    competition.adjustedScore,
                    (opts.fallbackTranscriptText !== undefined)
                        ? String(opts.fallbackTranscriptText || '')
                        : '',
                    competition
                );
                bestResult.matched = bestResult.bucket !== 'wrong'
                    && (!opts.expectedWordId || !!competition.matchedWordIsExpected);
                return bestResult;
            });
        });
    }

    function resolveSpeakingMatcherErrorMessage(ctx, error, fallbackMessage) {
        const rawMessage = String(error && error.message || '').trim();
        if (rawMessage !== '' && rawMessage.indexOf('_') === -1 && rawMessage.length <= 140) {
            return rawMessage;
        }
        return String(fallbackMessage || ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.');
    }

    function transcribeSpeakingBlob(ctx, run, blob) {
        if (!blob || !run) {
            return Promise.reject(new Error('missing_blob'));
        }

        if (ctx && ctx.offlineMode) {
            const bridge = getOfflineSpeakingBridge(ctx);
            if (!bridge || typeof bridge.transcribeSpeakingAttempt !== 'function') {
                return Promise.reject(new Error(String(ctx.i18n.gamesSpeakingApiUnavailable || 'Speaking practice is unavailable on this device right now.')));
            }
            return Promise.resolve(bridge.transcribeSpeakingAttempt(blob, run, ctx)).then(function (payload) {
                const transcript = typeof payload === 'string'
                    ? String(payload).trim()
                    : extractTranscriptFromLocalPayload(payload);
                if (!transcript) {
                    throw new Error(String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'));
                }
                return transcript;
            });
        }

        if (String(run.provider || '') === 'assemblyai' || String(run.provider || '') === 'hosted_api') {
            const formData = new FormData();
            formData.append('action', ctx.transcribeAttemptAction);
            formData.append('nonce', ctx.nonce);
            formData.append('wordset_id', String(ctx.wordsetId));
            formData.append('target_field', String(run.targetField || ''));
            if (run && run.prompt && run.prompt.target && run.prompt.target.id) {
                formData.append('word_id', String(run.prompt.target.id));
            }
            if (run && run.prompt && run.prompt.target && run.prompt.target.title) {
                formData.append('word_title', String(run.prompt.target.title));
            }
            formData.append('audio', blob, 'speaking-attempt.webm');
            return fetchJsonForm(ctx.ajaxUrl, formData).then(function (payload) {
                if (!payload || !payload.success || !payload.data) {
                    throw new Error(String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'));
                }
                return String(payload.data.transcript || payload.data.text || '').trim();
            });
        }

        const localFormData = new FormData();
        localFormData.append('audio', blob, 'speaking-attempt.webm');
        localFormData.append('target_field', String(run.targetField || ''));
        if (run && run.prompt && run.prompt.target && run.prompt.target.id) {
            localFormData.append('word_id', String(run.prompt.target.id));
        }
        if (run && run.prompt && run.prompt.target && run.prompt.target.title) {
            localFormData.append('word_title', String(run.prompt.target.title));
        }
        return fetchJsonForm(String(run.localEndpoint || ''), localFormData, {
            credentials: 'omit',
            mode: 'cors'
        }).then(function (payload) {
            const transcript = extractTranscriptFromLocalPayload(payload);
            if (!transcript) {
                throw new Error(String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'));
            }
            return transcript;
        });
    }

    function scoreSpeakingTranscript(ctx, run, transcript) {
        if (ctx && ctx.offlineMode) {
            const bridge = getOfflineSpeakingBridge(ctx);
            if (!bridge || typeof bridge.scoreSpeakingAttempt !== 'function') {
                return Promise.reject(new Error(String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.')));
            }
            return Promise.resolve(bridge.scoreSpeakingAttempt(run, transcript, ctx));
        }

        const formData = new FormData();
        formData.append('action', ctx.scoreAttemptAction);
        formData.append('nonce', ctx.nonce);
        formData.append('wordset_id', String(ctx.wordsetId));
        formData.append('word_id', String(toInt(run && run.prompt && run.prompt.target && run.prompt.target.id)));
        formData.append('target_field', String(run && run.targetField || ''));
        formData.append('transcript', String(transcript || ''));
        return fetchJsonForm(ctx.ajaxUrl, formData).then(function (payload) {
            if (!payload || !payload.success || !payload.data) {
                throw new Error(String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'));
            }
            return payload.data;
        });
    }

    function getSpeakingStackActiveCards(run) {
        return (Array.isArray(run && run.cards) ? run.cards : []).filter(function (card) {
            return !!card && !card.exploding && !card.removedFromStack && toInt(card.word && card.word.id) > 0;
        });
    }

    function pickBestSpeakingStackMatch(results) {
        const bucketRank = {
            wrong: 0,
            close: 1,
            right: 2
        };
        let best = null;

        (Array.isArray(results) ? results : []).forEach(function (result) {
            if (!result || typeof result !== 'object') {
                return;
            }

            const nextScore = clamp(Number(result.score) || 0, 0, 100);
            const nextRank = bucketRank[String(result.bucket || 'wrong')] || 0;
            if (!best) {
                best = $.extend({}, result, {
                    score: nextScore
                });
                return;
            }

            const bestScore = clamp(Number(best.score) || 0, 0, 100);
            const bestRank = bucketRank[String(best.bucket || 'wrong')] || 0;
            if (nextScore > bestScore || (nextScore === bestScore && nextRank > bestRank)) {
                best = $.extend({}, result, {
                    score: nextScore
                });
            }
        });

        if (!best) {
            return {
                matched: false,
                word_id: 0,
                score: 0,
                bucket: 'wrong'
            };
        }

        return $.extend({
            matched: String(best.bucket || 'wrong') !== 'wrong'
        }, best);
    }

    function scoreSpeakingStackTranscript(ctx, run, transcript) {
        const activeCards = getSpeakingStackActiveCards(run);
        const activeWordIds = uniqueIntList(activeCards.map(function (card) {
            return toInt(card.word && card.word.id);
        }));
        if (!activeWordIds.length) {
            return Promise.resolve({
                matched: false,
                word_id: 0,
                score: 0,
                bucket: 'wrong'
            });
        }

        if (ctx && ctx.offlineMode) {
            const bridge = getOfflineSpeakingBridge(ctx);
            if (bridge && typeof bridge.scoreSpeakingMatchAttempt === 'function') {
                return Promise.resolve(bridge.scoreSpeakingMatchAttempt(run, transcript, activeWordIds, ctx));
            }
            if (!bridge || typeof bridge.scoreSpeakingAttempt !== 'function') {
                return Promise.reject(new Error(String(ctx.i18n.gamesSpeakingApiUnavailable || 'Speaking practice is unavailable on this device right now.')));
            }

            return Promise.all(activeCards.map(function (card) {
                const candidateRun = $.extend({}, run, {
                    prompt: {
                        target: card.word
                    }
                });
                return Promise.resolve(bridge.scoreSpeakingAttempt(candidateRun, transcript, ctx)).then(function (result) {
                    if (!result || typeof result !== 'object') {
                        return null;
                    }
                    return $.extend({}, result, {
                        word_id: toInt(result.word_id) || toInt(card.word && card.word.id)
                    });
                }).catch(function () {
                    return null;
                });
            })).then(function (results) {
                return pickBestSpeakingStackMatch(results);
            });
        }

        const formData = new FormData();
        formData.append('action', ctx.matchAttemptAction);
        formData.append('nonce', ctx.nonce);
        formData.append('wordset_id', String(ctx.wordsetId));
        formData.append('target_field', String(run && run.targetField || ''));
        formData.append('transcript', String(transcript || ''));
        activeWordIds.forEach(function (wordId) {
            formData.append('word_ids[]', String(wordId));
        });

        return fetchJsonForm(ctx.ajaxUrl, formData).then(function (payload) {
            if (!payload || !payload.success || !payload.data) {
                throw new Error(String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'));
            }
            return payload.data;
        });
    }

    function playSpeakingCorrectAudio(ctx) {
        if (!ctx || !ctx.speakingCorrectAudio || !ctx.speakingCorrectAudio.getAttribute('src')) {
            return Promise.resolve(false);
        }
        try {
            ctx.speakingCorrectAudio.currentTime = 0;
        } catch (_) { /* no-op */ }
        const playAttempt = ctx.speakingCorrectAudio.play();
        if (playAttempt && typeof playAttempt.then === 'function') {
            return playAttempt.then(function () {
                return true;
            }).catch(function () {
                return false;
            });
        }
        return Promise.resolve(true);
    }

    function playSpeakingAttemptAudio(ctx) {
        if (!ctx || !ctx.speakingAttemptAudio || !ctx.speakingAttemptAudio.getAttribute('src')) {
            return Promise.resolve(false);
        }
        try {
            ctx.speakingAttemptAudio.currentTime = 0;
        } catch (_) { /* no-op */ }
        const playAttempt = ctx.speakingAttemptAudio.play();
        if (playAttempt && typeof playAttempt.then === 'function') {
            return playAttempt.then(function () {
                return true;
            }).catch(function () {
                return false;
            });
        }
        return Promise.resolve(true);
    }

    function applySpeakingResultUi(ctx, result, transcript) {
        const bucket = String(result && result.bucket || 'wrong');
        const score = clamp(Number(result && result.score) || 0, 0, 100);
        const displayTexts = (result && result.display_texts && typeof result.display_texts === 'object')
            ? result.display_texts
            : {};
        const title = String(displayTexts.title || '');
        const ipa = String(displayTexts.ipa || '');
        const targetLabel = String(result && result.target_label || displayTexts.target_label || ctx.i18n.gamesSpeakingTargetLabel || 'Target');
        const targetText = String(result && result.target_text || displayTexts.target_text || '');
        const correctAudioUrl = String(result && result.best_correct_audio_url || '');
        const transcriptText = String(transcript || result && result.normalized_transcript_text || '');
        const showTitle = title !== '' && title !== targetText;
        const showIpa = ipa !== '' && ipa !== targetText;
        const bucketLabelMap = {
            right: String(ctx.i18n.gamesSpeakingResultRight || 'Correct'),
            close: String(ctx.i18n.gamesSpeakingResultClose || 'Close'),
            wrong: String(ctx.i18n.gamesSpeakingResultWrong || 'Try again')
        };

        showSpeakingResult(ctx, true);
        if (ctx.$speakingPromptCard && ctx.$speakingPromptCard.length) {
            ctx.$speakingPromptCard.attr('data-speaking-result', bucket);
        }
        if (ctx.$speakingBucket && ctx.$speakingBucket.length) {
            ctx.$speakingBucket.text(bucketLabelMap[bucket] || bucketLabelMap.wrong);
        }
        if (ctx.$speakingScore && ctx.$speakingScore.length) {
            ctx.$speakingScore.text(formatMessage(ctx.i18n.gamesSpeakingScoreLabel || 'Similarity', []) + ' ' + Math.round(score) + '%');
        }
        if (ctx.$speakingBar && ctx.$speakingBar.length) {
            ctx.$speakingBar
                .css('width', score + '%')
                .toggleClass('is-right', bucket === 'right')
                .toggleClass('is-wrong', bucket === 'wrong');
        }
        if (ctx.$speakingTranscript && ctx.$speakingTranscript.length) {
            ctx.$speakingTranscript.html(renderSpeakingComparedText(transcriptText, targetText, score, 'heard'));
        }
        if (ctx.$speakingTargetRow && ctx.$speakingTargetRow.length) {
            ctx.$speakingTargetRow.prop('hidden', targetText === '' && title === '' && ipa === '');
        }
        if (ctx.$speakingTargetLabel && ctx.$speakingTargetLabel.length) {
            ctx.$speakingTargetLabel.text(targetLabel);
        }
        if (ctx.$speakingTarget && ctx.$speakingTarget.length) {
            ctx.$speakingTarget.html(renderSpeakingComparedText(targetText, transcriptText, score, 'target'));
        }
        if (ctx.$speakingTitleRow && ctx.$speakingTitleRow.length) {
            ctx.$speakingTitleRow.prop('hidden', !showTitle);
        }
        if (ctx.$speakingTitle && ctx.$speakingTitle.length) {
            ctx.$speakingTitle.text(title);
        }
        if (ctx.$speakingIpaRow && ctx.$speakingIpaRow.length) {
            ctx.$speakingIpaRow.prop('hidden', !showIpa);
        }
        if (ctx.$speakingIpa && ctx.$speakingIpa.length) {
            ctx.$speakingIpa.text(ipa);
        }
        setSpeakingReferenceAudioSource(ctx, correctAudioUrl);
        if (ctx.$speakingPlayCorrect && ctx.$speakingPlayCorrect.length) {
            ctx.$speakingPlayCorrect.prop('hidden', correctAudioUrl === '');
        }
        if (ctx.$speakingPlayCorrect && ctx.$speakingPlayCorrect.length) {
            updateAudioButtonUi(ctx.$speakingPlayCorrect, false);
        }
        if (ctx.$speakingPlayAttempt && ctx.$speakingPlayAttempt.length) {
            ctx.$speakingPlayAttempt.prop('hidden', !ctx.speakingAttemptAudio || !ctx.speakingAttemptAudio.getAttribute('src'));
            updateAudioButtonUi(ctx.$speakingPlayAttempt, false);
        }
        scrollSpeakingElementIntoView(ctx, ctx.$speakingResult);
    }

    function handleSpeakingScoredAttempt(ctx, result, transcript) {
        const run = ctx.run;
        if (!run || !run.prompt) {
            return;
        }

        const bucket = String(result && result.bucket || 'wrong');
        run.summary[bucket] = (run.summary[bucket] || 0) + 1;
        queueOutcome(ctx, run.prompt, bucket !== 'wrong', bucket === 'close' || !!run.prompt.hadWrongBefore, {
            event_source: 'speaking_practice',
            speaking_game_bucket: bucket,
            speaking_score: clamp(Number(result && result.score) || 0, 0, 100),
            stt_provider: String(run.provider || ''),
            speaking_target_field: String(run.targetField || '')
        });
        if (bucket !== 'right') {
            run.prompt.hadWrongBefore = true;
        }
        applySpeakingResultUi(ctx, result, transcript);
        setSpeakingStatus(ctx, String({
            right: ctx.i18n.gamesSpeakingResultRight || 'Correct',
            close: ctx.i18n.gamesSpeakingResultClose || 'Close',
            wrong: ctx.i18n.gamesSpeakingResultWrong || 'Try again'
        }[bucket] || ctx.i18n.gamesSpeakingResultWrong || 'Try again'));
        setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingRetry || 'Retry'), false, 'retry');

        const feedbackPromise = bucket === 'close'
            ? Promise.resolve()
            : playFeedbackSound(ctx, bucket === 'right' ? 'correct' : 'wrong');
        feedbackPromise.finally(function () {
            if (String(result && result.best_correct_audio_url || '') !== '') {
                playSpeakingCorrectAudio(ctx);
            }
        });
    }

    function findSpeakingStackCardByWordId(run, wordId) {
        const targetId = toInt(wordId);
        if (!targetId) {
            return null;
        }

        for (let index = 0; index < run.cards.length; index += 1) {
            const card = run.cards[index];
            if (!card || card.exploding || card.removedFromStack) {
                continue;
            }
            if (toInt(card.word && card.word.id) === targetId) {
                return card;
            }
        }

        return null;
    }

    function queueSpeakingStackCaptureRestart(ctx, delayMs, error) {
        const run = ctx && ctx.run;
        const state = speakingState(ctx);
        if (!state) {
            return;
        }

        clearSpeakingTimeout(state, 'restartTimer');
        if (!run || run.ended || run.paused || !isSpeakingStackRun(ctx, run)) {
            return;
        }

        state.restartTimer = root.setTimeout(function () {
            if (!ctx.run || ctx.run !== run || run.ended || run.paused) {
                return;
            }
            startSpeakingCapture(ctx).catch(function (captureError) {
                setSpeakingStatus(ctx, String(
                    captureError && captureError.message
                        || error && error.message
                        || ctx.i18n.gamesSpeakingStackMicError
                        || 'Microphone access failed.'
                ));
            });
        }, Math.max(0, toInt(delayMs) || 0));
    }

    function handleSpeakingStackMatch(ctx, run, result, transcript) {
        const card = findSpeakingStackCardByWordId(run, toInt(result && result.word_id));
        if (!card) {
            setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackNoMatch || 'No match yet.'));
            return false;
        }

        const bucket = String(result && result.bucket || 'right');
        const score = clamp(Number(result && result.score) || 0, 0, 100);
        const now = currentTimestamp();
        const correctAudioUrl = String(result && result.best_correct_audio_url || card.word && card.word.speaking_best_correct_audio_url || '');
        run.clearedCount += 1;
        run.lastCorrectAudioDurationMs = correctAudioUrl ? getLoadedAudioDurationMs(ctx, correctAudioUrl) : 0;
        card.removedFromStack = true;
        card.exploding = true;
        card.explosionStyle = 'correct';
        card.explosionDuration = 220;
        card.removeAt = now + card.explosionDuration;

        spawnExplosion(run, {
            x: card.x,
            y: card.y,
            radius: Math.max(card.width, card.height) * 0.74,
            primaryColor: 'rgba(16, 185, 129, 0.96)',
            secondaryColor: 'rgba(103, 232, 249, 0.78)',
            duration: 300,
            style: 'ring'
        });
        relayoutSpeakingStackCards(ctx, run);
        setSpeakingStackProgressFromRun(ctx, run);
        setSpeakingStatus(ctx, String({
            right: ctx.i18n.gamesSpeakingResultRight || 'Correct',
            close: ctx.i18n.gamesSpeakingResultClose || 'Close'
        }[bucket] || ctx.i18n.gamesSpeakingResultRight || 'Correct'));
        setSpeakingStackHeard(ctx, String(transcript || ''), {
            targetText: String(result && result.target_text || result && result.normalized_target_text || ''),
            score: score
        });

        queueOutcome(ctx, {
            target: card.word,
            recordingType: 'isolation',
            gameSlug: SPEAKING_STACK_GAME_SLUG
        }, true, bucket === 'close', {
            event_source: 'speaking_stack',
            speaking_game_bucket: bucket,
            speaking_score: score,
            stt_provider: String(run.provider || ''),
            speaking_target_field: String(run.targetField || '')
        });

        if (correctAudioUrl) {
            playQueuedAudioSources(ctx, [correctAudioUrl], {
                cacheKey: 'speaking-stack-word:' + String(toInt(card.word && card.word.id)),
                volume: getPromptAudioVolume(ctx, run),
                pausePrompt: false
            });
        }
        return true;
    }

    function processSpeakingStackAttempt(ctx, blob) {
        const run = ctx.run;
        const state = speakingState(ctx);
        if (!run || !blob || !state) {
            return;
        }

        if (!run.hasStartedFirstTranscriptionAttempt) {
            run.hasStartedFirstTranscriptionAttempt = true;
            syncSpeakingStackCardSpeeds(ctx, run);
        }

        const processingStartedAt = currentTimestamp();
        const transcribeStartedAt = processingStartedAt;
        run.lastCorrectAudioDurationMs = 0;
        if (state.speechDetected) {
            run.lastRecordingDurationMs = Math.max(
                0,
                processingStartedAt - Math.max(0, Number(state.speechStartedAt) || processingStartedAt)
            );
        }
        state.transcribing = true;
        setSpeakingStackHeard(ctx, '');

        if (!state.speechDetected) {
            state.transcribing = false;
            setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackTooQuiet || 'No clear word detected.'));
            queueSpeakingStackCaptureRestart(ctx, 120);
            return;
        }

        const stackProcessingLabel = isAudioMatcherProvider(run.provider)
            ? String(ctx.i18n.gamesSpeakingStackMatching || 'Matching your audio...')
            : String(ctx.i18n.gamesSpeakingStackProcessing || 'Checking your word...');
        setSpeakingStatus(ctx, stackProcessingLabel);
        if (isAudioMatcherProvider(run.provider)) {
            const activeCards = getSpeakingStackActiveCards(run);
            const candidateWords = activeCards.map(function (card) {
                return card && card.word ? card.word : null;
            }).filter(Boolean);

            scoreSpeakingAudioMatcher(ctx, run, blob, candidateWords).then(function (result) {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }
                run.lastTranscribeDurationMs = Math.max(0, currentTimestamp() - transcribeStartedAt);

                setSpeakingStackHeard(ctx, String(result && result.transcript_text || ''), {
                    targetText: String(result && result.target_text || result && result.normalized_target_text || ''),
                    score: clamp(Number(result && result.score) || 0, 0, 100)
                });
                const matched = !!(result && result.matched && String(result.bucket || 'wrong') !== 'wrong');
                if (!matched) {
                    setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackNoMatch || 'No match yet.'));
                    return;
                }
                handleSpeakingStackMatch(ctx, run, result, String(result && result.transcript_text || ''));
            }).catch(function (error) {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }
                run.lastTranscribeDurationMs = Math.max(0, currentTimestamp() - transcribeStartedAt);
                setSpeakingStatus(
                    ctx,
                    resolveSpeakingMatcherErrorMessage(
                        ctx,
                        error,
                        String(ctx.i18n.gamesSpeakingStackMicError || ctx.i18n.gamesSpeakingSttError || 'Microphone access failed.')
                    )
                );
            }).finally(function () {
                const completedAt = currentTimestamp();
                const playbackCooldownMs = Math.max(0, Number(run.lastCorrectAudioDurationMs) || 0);
                const restartDelayMs = Math.max(120, playbackCooldownMs + (playbackCooldownMs > 0 ? 180 : 120));
                run.lastSpeechAt = completedAt;
                run.spawnHoldUntil = Math.max(
                    Number(run.spawnHoldUntil) || 0,
                    completedAt + getSpeakingStackThinkPaddingMs(ctx, run) + playbackCooldownMs
                );
                state.transcribing = false;
                if (ctx.run === run && !run.ended && !run.paused) {
                    queueSpeakingStackCaptureRestart(ctx, restartDelayMs);
                }
            });
            return;
        }

        transcribeSpeakingBlob(ctx, run, blob).then(function (transcript) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return null;
            }
            run.lastTranscribeDurationMs = Math.max(0, currentTimestamp() - transcribeStartedAt);

            const transcriptText = String(transcript || '').trim();
            if (!transcriptText) {
                throw new Error(String(ctx.i18n.gamesSpeakingStackTooQuiet || 'No clear word detected.'));
            }
            return scoreSpeakingStackTranscript(ctx, run, transcriptText).then(function (result) {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }

                setSpeakingStackHeard(ctx, transcriptText, {
                    targetText: String(result && result.target_text || result && result.normalized_target_text || ''),
                    score: clamp(Number(result && result.score) || 0, 0, 100)
                });
                const matched = !!(result && result.matched && String(result.bucket || 'wrong') !== 'wrong');
                if (!matched) {
                    setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackNoMatch || 'No match yet.'));
                    return;
                }
                handleSpeakingStackMatch(ctx, run, result, transcriptText);
            });
        }).catch(function (error) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            run.lastTranscribeDurationMs = Math.max(0, currentTimestamp() - transcribeStartedAt);
            setSpeakingStatus(ctx, String(
                error && error.message
                    || ctx.i18n.gamesSpeakingStackMicError
                    || ctx.i18n.gamesSpeakingSttError
                    || 'Microphone access failed.'
            ));
        }).finally(function () {
            const completedAt = currentTimestamp();
            const playbackCooldownMs = Math.max(0, Number(run.lastCorrectAudioDurationMs) || 0);
            const restartDelayMs = Math.max(120, playbackCooldownMs + (playbackCooldownMs > 0 ? 180 : 120));
            run.lastSpeechAt = completedAt;
            run.spawnHoldUntil = Math.max(
                Number(run.spawnHoldUntil) || 0,
                completedAt + getSpeakingStackThinkPaddingMs(ctx, run) + playbackCooldownMs
            );
            state.transcribing = false;
            if (ctx.run === run && !run.ended && !run.paused) {
                queueSpeakingStackCaptureRestart(ctx, restartDelayMs);
            }
        });
    }

    function processSpeakingAttempt(ctx, blob) {
        const run = ctx.run;
        const state = speakingState(ctx);
        if (!run || !run.prompt || !blob || !state) {
            if (run && isSpeakingStackRun(ctx, run) && blob && state) {
                processSpeakingStackAttempt(ctx, blob);
            }
            return;
        }
        if (isSpeakingStackRun(ctx, run)) {
            processSpeakingStackAttempt(ctx, blob);
            return;
        }
        if (!state.speechDetected) {
            setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingTooQuiet || 'That was too quiet. Try again.'));
            setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingRetry || 'Retry'), false, 'retry');
            return;
        }

        const speakingProcessingLabel = isAudioMatcherProvider(run.provider)
            ? String(ctx.i18n.gamesSpeakingMatching || 'Matching your audio...')
            : String(ctx.i18n.gamesSpeakingProcessing || 'Transcribing...');
        setSpeakingStatus(ctx, speakingProcessingLabel);
        setSpeakingRecordButton(ctx, speakingProcessingLabel, true, 'processing');
        setSpeakingAttemptAudioSource(ctx, blob);
        if (isAudioMatcherProvider(run.provider)) {
            const practiceCandidates = buildMatcherPracticeCandidatePool(run, run.prompt.target, {
                limit: MATCHER_COMPETITION_DISTRACTOR_LIMIT
            });
            scoreSpeakingAudioMatcher(ctx, run, blob, practiceCandidates, {
                expectedWordId: toInt(run.prompt && run.prompt.target && run.prompt.target.id)
            }).then(function (result) {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }
                handleSpeakingScoredAttempt(ctx, result, String(result && result.transcript_text || ''));
            }).catch(function (error) {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }
                setSpeakingStatus(
                    ctx,
                    resolveSpeakingMatcherErrorMessage(ctx, error, String(ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'))
                );
                setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingRetry || 'Retry'), false, 'retry');
            });
            return;
        }

        transcribeSpeakingBlob(ctx, run, blob).then(function (transcript) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return null;
            }
            if (!String(transcript || '').trim()) {
                throw new Error(String(ctx.i18n.gamesSpeakingTooQuiet || 'That was too quiet. Try again.'));
            }
            return scoreSpeakingTranscript(ctx, run, transcript).then(function (result) {
                if (!ctx.run || ctx.run !== run || run.ended) {
                    return;
                }
                handleSpeakingScoredAttempt(ctx, result, transcript);
            });
        }).catch(function (error) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            setSpeakingStatus(ctx, String(error && error.message || ctx.i18n.gamesSpeakingSttError || 'Transcription failed. Try again.'));
            setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingRetry || 'Retry'), false, 'retry');
        });
    }

    function queueSpeakingPromptAutoStart(ctx) {
        const run = ctx.run;
        const state = speakingState(ctx);
        if (!run || !state) {
            return;
        }
        clearSpeakingAutoStart(ctx);
        state.autoStartTimer = root.setTimeout(function () {
            startSpeakingCapture(ctx).catch(function (error) {
                setSpeakingStatus(ctx, String(error && error.message || ctx.i18n.gamesSpeakingMicError || 'Microphone access failed.'));
                setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingRetry || 'Retry'), false, 'retry');
            });
        }, Math.max(0, toInt(ctx.speakingPractice && ctx.speakingPractice.autoStartDelayMs) || 280));
    }

    function advanceSpeakingPrompt(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const run = ctx.run;
        if (!run || run.ended) {
            return;
        }

        resetSpeakingResultUi(ctx);
        clearSpeakingAutoStart(ctx);
        setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingReady || 'Get ready...'));
        setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingStartButton || 'Start'), false, 'idle');

        if (!opts.retryCurrent) {
            if (run.prompt && run.prompt.target) {
                run.promptsResolved += 1;
            }
            const nextWord = run.promptDeck.shift();
            if (!nextWord) {
                endSpeakingRun(ctx);
                return;
            }
            run.prompt = {
                target: nextWord,
                promptId: toInt(run.promptIdCounter) + 1,
                recordingType: 'isolation',
                audioUrl: String(nextWord && nextWord.speaking_best_correct_audio_url || ''),
                gameSlug: SPEAKING_PRACTICE_GAME_SLUG,
                hadWrongBefore: false,
                exposureTracked: false
            };
            run.promptIdCounter = run.prompt.promptId;
            rememberRecentSpeakingLaunch(speakingState(ctx), toInt(run.prompt.target && run.prompt.target.id));
            queueExposureOnce(ctx, run.prompt, {
                event_source: 'speaking_practice',
                speaking_target_field: String(run.targetField || '')
            });
        }

        renderSpeakingPrompt(ctx, run, run.prompt.target);
        setSpeakingStatus(ctx, String(
            run.prompt.target && run.prompt.target.image
                ? (ctx.i18n.gamesSpeakingPromptImage || 'Say the word for this picture.')
                : (ctx.i18n.gamesSpeakingPromptText || 'Say the word for this prompt.')
        ));
        queueSpeakingPromptAutoStart(ctx);
    }

    function endSpeakingRun(ctx) {
        const run = ctx.run;
        if (!run) {
            return;
        }
        run.ended = true;
        teardownSpeaking(ctx);
        flushProgress(ctx);
        showOverlay(
            ctx,
            String(ctx.i18n.gamesSpeakingDoneTitle || 'Speaking round complete'),
            formatMessage(ctx.i18n.gamesSpeakingDoneSummary || 'Right: %1$d · Close: %2$d · Wrong: %3$d', [
                toInt(run.summary.right),
                toInt(run.summary.close),
                toInt(run.summary.wrong)
            ]),
            {
                mode: 'game-over',
                primaryLabel: String(ctx.i18n.gamesNewGame || 'New game'),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function startSpeakingStackRun(ctx, entry) {
        const keepModalOpen = isRunModalVisible(ctx);
        const selectedWords = selectRoundWords(entry, getEntryRoundGoalCount(ctx, entry));
        const shuffledWords = shuffle(selectedWords.slice());
        const launchedAt = currentTimestamp();
        const speakingStackConfig = getGameConfig(ctx, SPEAKING_STACK_GAME_SLUG) || {};
        const initialSpawnCount = Math.max(1, toInt(speakingStackConfig.initialSpawnCount) || 3);

        resetGamesSurface(ctx, {
            keepModalOpen: keepModalOpen
        });
        ctx.$stage.prop('hidden', false);
        ctx.activeGameSlug = SPEAKING_STACK_GAME_SLUG;
        updateStageGameUi(ctx, entry);
        setRunModalOpen(ctx, true);
        activateGameInteractionGuard();
        hideOverlay(ctx);

        ctx.run = {
            slug: SPEAKING_STACK_GAME_SLUG,
            words: shuffledWords.slice(),
            wordQueue: shuffledWords.slice(),
            totalWordCount: shuffledWords.length,
            clearedCount: 0,
            spawnedWordCount: 0,
            promptDeck: [],
            prompt: null,
            cards: [],
            bullets: [],
            explosions: [],
            decorativeBubbles: [],
            controls: {
                left: false,
                right: false,
                fire: false
            },
            coins: 0,
            lives: 1,
            promptsResolved: 0,
            lastFireAt: 0,
            lastFrameAt: 0,
            shipX: 0,
            shipY: 0,
            width: 720,
            height: 960,
            dpr: 1,
            metrics: null,
            stars: [],
            cardCount: Math.max(2, toInt((getGameConfig(ctx, SPEAKING_STACK_GAME_SLUG) || {}).cardCount) || 4),
            cardSpeed: Math.max(80, toInt((getGameConfig(ctx, SPEAKING_STACK_GAME_SLUG) || {}).fallSpeed) || 176),
            promptIdCounter: 0,
            promptTimer: 0,
            promptTimerReadyAt: 0,
            promptTimerRemainingMs: 0,
            decorativeBubbleIdCounter: 0,
            speedRampTurns: 0,
            speedRampStartFactor: 1,
            useSameCategoryDistractorsNext: false,
            awaitingPrompt: false,
            nextPreparedPrompt: null,
            nextPromptPromise: null,
            inactiveRounds: 0,
            lastResolvedPromptHadUserActivity: false,
            resumeAction: '',
            pauseReason: '',
            paused: false,
            ended: false,
            rafId: 0,
            provider: String(entry.provider || ''),
            localEndpoint: String(entry.local_endpoint || ''),
            embeddedModel: ((entry.embedded_model && typeof entry.embedded_model === 'object')
                ? $.extend({}, entry.embedded_model)
                : ((entry.offline_stt && typeof entry.offline_stt === 'object') ? $.extend({}, entry.offline_stt) : null)),
            targetField: String(entry.target_field || ''),
            initialSpawnCount: initialSpawnCount,
            lastSpeechAt: launchedAt,
            lastSpawnedAt: 0,
            lastRecordingDurationMs: 0,
            lastTranscribeDurationMs: 0,
            lastCorrectAudioDurationMs: 0,
            finalSpawnedAt: 0,
            allWordsQueued: false,
            nextSpawnAt: launchedAt,
            spawnHoldUntil: launchedAt,
            hasStartedFirstTranscriptionAttempt: false,
            lastPlacementX: null,
            lastPlacementSlotIndex: -1
        };

        syncCanvasSize(ctx);
        ctx.run.shipX = ctx.run.width / 2;
        ctx.run.shipY = ctx.run.metrics.shipY;
        ctx.run.stars = createStageStars(ctx.run);
        for (let spawnIndex = 0; spawnIndex < initialSpawnCount; spawnIndex += 1) {
            if (ctx.run.allWordsQueued) {
                break;
            }
            spawnSpeakingStackCard(ctx, ctx.run, launchedAt);
        }
        scheduleNextSpeakingStackSpawn(ctx, ctx.run, launchedAt, {
            initial: true
        });
        updateHud(ctx);
        updatePauseUi(ctx);
        setTrackerContext(ctx);
        setSpeakingStackProgressFromRun(ctx, ctx.run);
        setSpeakingStatus(ctx, String(ctx.i18n.gamesSpeakingStackReady || 'Mic ready'));
        setSpeakingStackHeard(ctx, '');
        scrollStageIntoView(ctx);
        queueSpeakingStackCaptureRestart(ctx, 40);
        ctx.run.rafId = root.requestAnimationFrame(function (timestamp) {
            runLoop(ctx, timestamp);
        });
    }

    function startSpeakingRun(ctx, entry) {
        const keepModalOpen = isRunModalVisible(ctx);
        const speaking = speakingState(ctx);
        const selectedWords = selectRoundWords(entry, getEntryRoundGoalCount(ctx, entry));
        const shuffledWords = shuffle(selectedWords.slice());
        const promptDeck = buildSpeakingPromptDeck(shuffledWords, speaking && speaking.recentLaunchWordIds);
        resetGamesSurface(ctx, {
            keepModalOpen: keepModalOpen
        });
        ctx.$stage.prop('hidden', false);
        ctx.activeGameSlug = SPEAKING_PRACTICE_GAME_SLUG;
        updateStageGameUi(ctx, entry);
        setRunModalOpen(ctx, true);
        activateGameInteractionGuard();
        hideOverlay(ctx);
        ctx.run = {
            slug: SPEAKING_PRACTICE_GAME_SLUG,
            words: shuffledWords.slice(),
            promptDeck: promptDeck,
            prompt: null,
            cards: [],
            bullets: [],
            explosions: [],
            decorativeBubbles: [],
            controls: {
                left: false,
                right: false,
                fire: false
            },
            promptIdCounter: 0,
            promptsResolved: 0,
            coins: 0,
            lives: 1,
            paused: false,
            ended: false,
            provider: String(entry.provider || ''),
            localEndpoint: String(entry.local_endpoint || ''),
            embeddedModel: ((entry.embedded_model && typeof entry.embedded_model === 'object')
                ? $.extend({}, entry.embedded_model)
                : ((entry.offline_stt && typeof entry.offline_stt === 'object') ? $.extend({}, entry.offline_stt) : null)),
            targetField: String(entry.target_field || ''),
            summary: {
                right: 0,
                close: 0,
                wrong: 0
            }
        };
        scrollStageIntoView(ctx);
        advanceSpeakingPrompt(ctx);
    }

    function bindLifecycle(ctx) {
        if (ctx.boundLifecycle) {
            return;
        }

        function matchesKey(event, keys, codes) {
            const key = String(event && event.key || '').toLowerCase();
            const code = String(event && event.code || '').toLowerCase();
            return keys.indexOf(key) !== -1 || codes.indexOf(code) !== -1;
        }

        ctx.onVisibilityChange = function () {
            if (root.document && root.document.visibilityState === 'hidden') {
                flushProgress(ctx);
            }
        };
        ctx.onPopState = function () {
            if (gameInteractionGuard.suppressNextPopstate) {
                clearGamePopstateSuppression();
                return;
            }

            if (!isGameGuardActive(ctx)) {
                gameInteractionGuard.historyActive = false;
                return;
            }

            confirmAndCloseGameFromGuard(ctx, {
                historyAlreadyHandled: true,
                rearmHistory: true
            });
        };
        ctx.onPageHide = function () {
            flushProgress(ctx);
        };
        ctx.onResize = function () {
            syncCanvasSize(ctx);
        };
        ctx.onKeyDown = function (event) {
            if (matchesKey(event, ['backspace'], ['backspace']) && isGameGuardActive(ctx) && !event.defaultPrevented) {
                if (isEditableEventTarget(event.target)) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                confirmAndCloseGameFromGuard(ctx);
                return;
            }

            if (matchesKey(event, ['escape'], ['escape']) && isRunModalVisible(ctx)) {
                event.preventDefault();
                requestCloseGame(ctx);
                return;
            }
            if (!ctx.run || ctx.run.paused || ctx.$stage.prop('hidden') || isBubblePopRun(ctx, ctx.run) || isSpeakingStackRun(ctx, ctx.run)) {
                return;
            }
            if (matchesKey(event, ['arrowleft', 'a'], ['arrowleft', 'keya'])) {
                event.preventDefault();
                if (!event.repeat) {
                    markRunActivity(ctx);
                }
                setControlState(ctx, 'left', true);
            } else if (matchesKey(event, ['arrowright', 'd'], ['arrowright', 'keyd'])) {
                event.preventDefault();
                if (!event.repeat) {
                    markRunActivity(ctx);
                }
                setControlState(ctx, 'right', true);
            } else if (matchesKey(event, [' ', 'space', 'spacebar'], ['space'])) {
                event.preventDefault();
                if (!event.repeat) {
                    markRunActivity(ctx);
                }
                setControlState(ctx, 'fire', true);
            }
        };
        ctx.onKeyUp = function (event) {
            if (!ctx.run || ctx.run.paused || isBubblePopRun(ctx, ctx.run) || isSpeakingStackRun(ctx, ctx.run)) {
                return;
            }
            if (matchesKey(event, ['arrowleft', 'a'], ['arrowleft', 'keya'])) {
                setControlState(ctx, 'left', false);
            } else if (matchesKey(event, ['arrowright', 'd'], ['arrowright', 'keyd'])) {
                setControlState(ctx, 'right', false);
            } else if (matchesKey(event, [' ', 'space', 'spacebar'], ['space'])) {
                setControlState(ctx, 'fire', false);
            }
        };
        ctx.onWheel = function (event) {
            if (!isGameGuardActive(ctx) || !event.ctrlKey) {
                return;
            }

            event.preventDefault();
        };
        ctx.onTouchStart = function (event) {
            if (!isGameGuardActive(ctx) || !event.touches || event.touches.length < 2) {
                resetGameGuardPinchDistance();
                return;
            }

            gameInteractionGuard.pinchDistance = getGameGuardTouchDistance(event.touches);
            if (!gameGuardViewportIsZoomed()) {
                event.preventDefault();
            }
        };
        ctx.onTouchMove = function (event) {
            if (!isGameGuardActive(ctx) || !event.touches || event.touches.length < 2) {
                resetGameGuardPinchDistance();
                return;
            }

            const currentDistance = getGameGuardTouchDistance(event.touches);
            const previousDistance = gameInteractionGuard.pinchDistance;
            gameInteractionGuard.pinchDistance = currentDistance;

            if (!gameGuardViewportIsZoomed()) {
                event.preventDefault();
                return;
            }

            if (previousDistance > 0 && currentDistance > (previousDistance + 4)) {
                event.preventDefault();
            }
        };
        ctx.onTouchEnd = function (event) {
            if (!isGameGuardActive(ctx) || !event.touches || event.touches.length < 2) {
                resetGameGuardPinchDistance();
                return;
            }

            gameInteractionGuard.pinchDistance = getGameGuardTouchDistance(event.touches);
        };
        ctx.onGesture = function (event) {
            if (!isGameGuardActive(ctx)) {
                return;
            }

            if (!gameGuardViewportIsZoomed()) {
                event.preventDefault();
                return;
            }

            if (event.type === 'gesturechange' && typeof event.scale === 'number' && isFinite(event.scale) && event.scale >= 1) {
                event.preventDefault();
            }
        };
        ctx.onDoubleClick = function (event) {
            const target = event && event.target;
            if (!isGameGuardActive(ctx) || !target || typeof target.closest !== 'function' || !target.closest('[data-ll-wordset-game-run-modal]')) {
                return;
            }

            event.preventDefault();
        };

        if (root.document && root.document.addEventListener) {
            root.document.addEventListener('visibilitychange', ctx.onVisibilityChange);
            root.document.addEventListener('wheel', ctx.onWheel, { passive: false, capture: true });
            root.document.addEventListener('touchstart', ctx.onTouchStart, { passive: false, capture: true });
            root.document.addEventListener('touchmove', ctx.onTouchMove, { passive: false, capture: true });
            root.document.addEventListener('touchend', ctx.onTouchEnd, true);
            root.document.addEventListener('touchcancel', ctx.onTouchEnd, true);
            root.document.addEventListener('gesturestart', ctx.onGesture, true);
            root.document.addEventListener('gesturechange', ctx.onGesture, true);
            root.document.addEventListener('gestureend', ctx.onGesture, true);
            root.document.addEventListener('dblclick', ctx.onDoubleClick, true);
        }
        if (root.addEventListener) {
            root.addEventListener('popstate', ctx.onPopState);
            root.addEventListener('pagehide', ctx.onPageHide);
            root.addEventListener('resize', ctx.onResize);
            root.addEventListener('keydown', ctx.onKeyDown);
            root.addEventListener('keyup', ctx.onKeyUp);
        }
        ctx.boundLifecycle = true;
    }

    function unbindLifecycle(ctx) {
        if (!ctx || !ctx.boundLifecycle) {
            return;
        }
        if (root.document && root.document.removeEventListener && ctx.onVisibilityChange) {
            root.document.removeEventListener('visibilitychange', ctx.onVisibilityChange);
            if (ctx.onWheel) {
                root.document.removeEventListener('wheel', ctx.onWheel, true);
            }
            if (ctx.onTouchStart) {
                root.document.removeEventListener('touchstart', ctx.onTouchStart, true);
            }
            if (ctx.onTouchMove) {
                root.document.removeEventListener('touchmove', ctx.onTouchMove, true);
            }
            if (ctx.onTouchEnd) {
                root.document.removeEventListener('touchend', ctx.onTouchEnd, true);
                root.document.removeEventListener('touchcancel', ctx.onTouchEnd, true);
            }
            if (ctx.onGesture) {
                root.document.removeEventListener('gesturestart', ctx.onGesture, true);
                root.document.removeEventListener('gesturechange', ctx.onGesture, true);
                root.document.removeEventListener('gestureend', ctx.onGesture, true);
            }
            if (ctx.onDoubleClick) {
                root.document.removeEventListener('dblclick', ctx.onDoubleClick, true);
            }
        }
        if (root.removeEventListener) {
            if (ctx.onPopState) {
                root.removeEventListener('popstate', ctx.onPopState);
            }
            if (ctx.onPageHide) {
                root.removeEventListener('pagehide', ctx.onPageHide);
            }
            if (ctx.onResize) {
                root.removeEventListener('resize', ctx.onResize);
            }
            if (ctx.onKeyDown) {
                root.removeEventListener('keydown', ctx.onKeyDown);
            }
            if (ctx.onKeyUp) {
                root.removeEventListener('keyup', ctx.onKeyUp);
            }
        }
        ctx.boundLifecycle = false;
    }

    function getPrimaryPressStartEvents() {
        if (root.PointerEvent) {
            return 'pointerdown' + MODULE_NS;
        }
        return 'touchstart' + MODULE_NS + ' mousedown' + MODULE_NS;
    }

    function getPrimaryPressReleaseEvents() {
        if (root.PointerEvent) {
            return 'pointerup' + MODULE_NS + ' pointercancel' + MODULE_NS + ' pointerleave' + MODULE_NS;
        }
        return 'mouseup' + MODULE_NS + ' mouseleave' + MODULE_NS + ' touchend' + MODULE_NS + ' touchcancel' + MODULE_NS;
    }

    function getDocumentPressReleaseEvents() {
        if (root.PointerEvent) {
            return 'pointerup' + MODULE_NS + ' pointercancel' + MODULE_NS;
        }
        return 'mouseup' + MODULE_NS + ' touchend' + MODULE_NS + ' touchcancel' + MODULE_NS;
    }

    function shouldIgnoreSyntheticMouseFromTouch(ctx, event, channel) {
        const eventType = String(event && event.type || '');
        if (!ctx || (eventType !== 'touchstart' && eventType !== 'mousedown')) {
            return false;
        }

        if (!ctx.syntheticMouseTouchGuards || typeof ctx.syntheticMouseTouchGuards !== 'object') {
            ctx.syntheticMouseTouchGuards = {};
        }

        const guardKey = String(channel || 'default');
        const guard = ctx.syntheticMouseTouchGuards[guardKey] || { lastTouchAt: 0 };
        ctx.syntheticMouseTouchGuards[guardKey] = guard;

        if (eventType === 'touchstart') {
            guard.lastTouchAt = currentTimestamp();
            return false;
        }

        return guard.lastTouchAt > 0 && (currentTimestamp() - guard.lastTouchAt) < 450;
    }

    function bindDom(ctx) {
        ctx.$page.off(MODULE_NS);
        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-launch]', function (event) {
            event.preventDefault();
            const slug = normalizeGameSlug($(this).closest('[data-ll-wordset-game-card]').attr('data-game-slug') || '');
            const entry = getPreparedCatalogEntry(ctx, slug);
            if (!entry || !entry.launchable) {
                return;
            }
            launchGame(ctx, slug);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-length-option]', function (event) {
            event.preventDefault();
            setSelectedRoundOption(ctx, $(this).attr('data-word-count') || '', true);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-run-dismiss]', function (event) {
            event.preventDefault();
            requestCloseGame(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-run-dialog]', function (event) {
            if (event.target !== this) {
                return;
            }
            event.preventDefault();
            requestCloseGame(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-return]', function (event) {
            event.preventDefault();
            if (ctx.run && !ctx.run.ended && ctx.overlayMode !== 'game-over') {
                requestCloseGame(ctx);
                return;
            }
            showCatalog(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-replay]', function (event) {
            event.preventDefault();
            if (ctx.overlayMode === 'paused') {
                resumeRun(ctx);
                return;
            }
            const replaySlug = normalizeGameSlug(ctx.activeGameSlug || getDefaultCatalogSlug(ctx));
            const entry = getPreparedCatalogEntry(ctx, replaySlug);
            if (!entry || !entry.launchable) {
                return;
            }
            stopRun(ctx, { flush: true });
            launchGame(ctx, replaySlug);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-replay-audio]', function (event) {
            event.preventDefault();
            if (ctx.run && isSpeakingPracticeRun(ctx, ctx.run)) {
                playPromptAudio(ctx, {
                    allowAutoReplay: false,
                    ignoreFeedbackQueue: true
                });
                return;
            }
            if (ctx.run && !ctx.run.paused) {
                markRunActivity(ctx);
            }
            playPromptAudio(ctx, {
                allowAutoReplay: false
            });
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-pause-toggle]', function (event) {
            event.preventDefault();
            if (!ctx.run) {
                return;
            }
            if (isSpeakingPracticeRun(ctx, ctx.run)) {
                showCatalog(ctx);
                return;
            }
            if (ctx.run.paused) {
                resumeRun(ctx);
                return;
            }
            pauseRun(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-speaking-record]', function (event) {
            event.preventDefault();
            if (!ctx.run || !isSpeakingPracticeRun(ctx, ctx.run) || ctx.run.ended) {
                return;
            }
            startSpeakingCapture(ctx).catch(function (error) {
                setSpeakingStatus(ctx, String(error && error.message || ctx.i18n.gamesSpeakingMicError || 'Microphone access failed.'));
                setSpeakingRecordButton(ctx, String(ctx.i18n.gamesSpeakingRetry || 'Retry'), false, 'retry');
            });
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-speaking-retry]', function (event) {
            event.preventDefault();
            if (!ctx.run || !isSpeakingPracticeRun(ctx, ctx.run) || ctx.run.ended) {
                return;
            }
            advanceSpeakingPrompt(ctx, {
                retryCurrent: true
            });
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-speaking-next]', function (event) {
            event.preventDefault();
            if (!ctx.run || !isSpeakingPracticeRun(ctx, ctx.run) || ctx.run.ended) {
                return;
            }
            advanceSpeakingPrompt(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-speaking-play-attempt]', function (event) {
            event.preventDefault();
            playSpeakingAttemptAudio(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-speaking-play-correct]', function (event) {
            event.preventDefault();
            playSpeakingCorrectAudio(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-lineup-move]', function (event) {
            event.preventDefault();
            if (!ctx.run || !isLineupRun(ctx, ctx.run) || ctx.run.ended || ctx.run.sequenceLocked) {
                return;
            }
            const direction = String($(this).attr('data-ll-wordset-lineup-move') || '');
            const index = toInt($(this).closest('[data-ll-wordset-lineup-card]').attr('data-lineup-index')) - 1;
            if (moveLineupCard(ctx, index, direction)) {
                markRunActivity(ctx);
            }
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-lineup-check]', function (event) {
            event.preventDefault();
            if (!ctx.run || ctx.run.ended) {
                return;
            }
            if (isLineupRun(ctx, ctx.run)) {
                if (ctx.run.sequenceLocked) {
                    return;
                }
                markRunActivity(ctx);
                checkLineupSequence(ctx);
                return;
            }
            if (isUnscrambleRun(ctx, ctx.run) && skipUnscrambleWord(ctx)) {
                markRunActivity(ctx);
            }
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-lineup-next]', function (event) {
            event.preventDefault();
            if (!ctx.run || ctx.run.ended || !ctx.run.sequenceLocked) {
                return;
            }
            if (isLineupRun(ctx, ctx.run)) {
                advanceLineupSequence(ctx);
                return;
            }
            if (isUnscrambleRun(ctx, ctx.run)) {
                advanceUnscrambleWord(ctx);
            }
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-lineup-shuffle]', function (event) {
            event.preventDefault();
            if (!ctx.run || ctx.run.ended || ctx.run.sequenceLocked) {
                return;
            }
            const shuffled = isLineupRun(ctx, ctx.run)
                ? shuffleLineupCards(ctx)
                : (isUnscrambleRun(ctx, ctx.run) ? shuffleUnscrambleTiles(ctx) : false);
            if (shuffled) {
                markRunActivity(ctx);
            }
        });

        ctx.$page.on('keydown' + MODULE_NS, '[data-ll-wordset-unscramble-tile]', function (event) {
            if (!ctx.run || !isUnscrambleRun(ctx, ctx.run) || ctx.run.ended || ctx.run.sequenceLocked) {
                return;
            }

            const direction = getUnscrambleKeyboardMoveDirection(
                ctx.run.currentWord ? ctx.run.currentWord.unscramble_direction : 'ltr',
                event
            );
            if (!direction) {
                return;
            }

            const index = toInt($(this).attr('data-lineup-index')) - 1;
            const unitId = toInt($(this).attr('data-unit-id'));
            event.preventDefault();
            event.stopPropagation();

            if (moveUnscrambleTile(ctx, index, direction)) {
                markRunActivity(ctx);
                focusUnscrambleTile(ctx, unitId);
            }
        });

        const pointerControls = getPrimaryPressStartEvents();
        const pointerRelease = getPrimaryPressReleaseEvents();
        const documentPointerRelease = getDocumentPressReleaseEvents();
        const dragMoveEvents = root.PointerEvent
            ? 'pointermove' + UNSCRAMBLE_DRAG_NS
            : 'mousemove' + UNSCRAMBLE_DRAG_NS + ' touchmove' + UNSCRAMBLE_DRAG_NS;
        const dragEndEvents = root.PointerEvent
            ? 'pointerup' + UNSCRAMBLE_DRAG_NS + ' pointercancel' + UNSCRAMBLE_DRAG_NS
            : 'mouseup' + UNSCRAMBLE_DRAG_NS + ' touchend' + UNSCRAMBLE_DRAG_NS + ' touchcancel' + UNSCRAMBLE_DRAG_NS;

        ctx.$page.on(pointerControls, '[data-ll-wordset-unscramble-tile]', function (event) {
            if (!ctx.run || !isUnscrambleRun(ctx, ctx.run) || ctx.run.ended || ctx.run.sequenceLocked) {
                return;
            }
            startUnscrambleTileDrag(ctx, event.originalEvent || event, this);
        });

        ctx.$page.on(pointerControls, '[data-ll-wordset-game-control]', function (event) {
            event.preventDefault();
            if (shouldIgnoreSyntheticMouseFromTouch(ctx, event, 'control')) {
                return;
            }
            if (!ctx.run || ctx.run.paused) {
                return;
            }
            markRunActivity(ctx);
            const control = String($(this).attr('data-ll-wordset-game-control') || '');
            setControlState(ctx, control, true);
        });
        ctx.$page.on(pointerRelease, '[data-ll-wordset-game-control]', function (event) {
            event.preventDefault();
            if (!ctx.run || ctx.run.paused) {
                return;
            }
            const control = String($(this).attr('data-ll-wordset-game-control') || '');
            setControlState(ctx, control, false);
        });

        ctx.$page.on(pointerControls, '[data-ll-wordset-game-canvas]', function (event) {
            if (shouldIgnoreSyntheticMouseFromTouch(ctx, event, 'canvas')) {
                event.preventDefault();
                return;
            }
            if (!ctx.run || ctx.run.paused || !isBubblePopRun(ctx, ctx.run)) {
                return;
            }
            markRunActivity(ctx);
            if (handleCanvasPress(ctx, event)) {
                event.preventDefault();
            }
        });

        $(root.document).off(documentPointerRelease).on(
            documentPointerRelease,
            function () {
                if (!ctx.run) {
                    return;
                }
                setControlState(ctx, 'left', false);
                setControlState(ctx, 'right', false);
                setControlState(ctx, 'fire', false);
            }
        );

        $(root.document).off(UNSCRAMBLE_DRAG_NS);
        $(root.document).on(dragMoveEvents, function (event) {
            updateUnscrambleTileDrag(ctx, event.originalEvent || event);
        });
        $(root.document).on(dragEndEvents, function (event) {
            finishUnscrambleTileDrag(ctx, event.originalEvent || event);
        });
    }

    function buildGameConfig(rawConfig, defaults) {
        const cfg = (rawConfig && typeof rawConfig === 'object') ? rawConfig : {};
        const fallback = (defaults && typeof defaults === 'object') ? defaults : {};
        return {
            slug: normalizeGameSlug(cfg.slug || fallback.slug),
            lives: Math.max(1, toInt(cfg.lives) || toInt(fallback.lives) || 3),
            cardCount: Math.max(2, toInt(cfg.cardCount) || toInt(fallback.cardCount) || 4),
            maxLoadedWords: Math.max(5, toInt(cfg.maxLoadedWords) || toInt(fallback.maxLoadedWords) || 60),
            fireIntervalMs: Math.max(0, toInt(cfg.fireIntervalMs) || toInt(fallback.fireIntervalMs)),
            introRampTurns: Math.max(1, toInt(cfg.introRampTurns) || toInt(fallback.introRampTurns) || 10),
            introRampStartFactor: clamp(Number(cfg.introRampStartFactor) || Number(fallback.introRampStartFactor) || 0.5, 0.25, 0.95),
            audioSafeLineRatio: clamp(Number(cfg.audioSafeLineRatio) || Number(fallback.audioSafeLineRatio) || 0.6, 0.2, 0.8),
            audioSafeLineBufferMs: Math.max(0, toInt(cfg.audioSafeLineBufferMs) || toInt(fallback.audioSafeLineBufferMs) || 180),
            correctCoinReward: Math.max(1, toInt(cfg.correctCoinReward) || toInt(fallback.correctCoinReward) || 1),
            wrongHitCoinPenalty: Math.max(0, toInt(cfg.wrongHitCoinPenalty) || toInt(fallback.wrongHitCoinPenalty)),
            wrongHitLifePenalty: Math.max(1, toInt(cfg.wrongHitLifePenalty) || toInt(fallback.wrongHitLifePenalty) || 1),
            timeoutCoinPenalty: Math.max(0, toInt(cfg.timeoutCoinPenalty) || toInt(fallback.timeoutCoinPenalty) || 1),
            timeoutLifePenalty: Math.max(0, toInt(cfg.timeoutLifePenalty) || toInt(fallback.timeoutLifePenalty) || 1),
            assetPreloadTimeoutMs: Math.max(1500, toInt(cfg.assetPreloadTimeoutMs) || toInt(fallback.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS),
            cardEntryRevealMs: Math.max(220, toInt(cfg.cardEntryRevealMs) || toInt(fallback.cardEntryRevealMs) || 560),
            promptAutoReplayGapMs: Math.max(220, toInt(cfg.promptAutoReplayGapMs) || toInt(fallback.promptAutoReplayGapMs) || 420),
            promptAudioVolume: clamp(Number(cfg.promptAudioVolume) || Number(fallback.promptAudioVolume) || 1, 0.05, 1),
            correctHitVolume: clamp(Number(cfg.correctHitVolume) || Number(fallback.correctHitVolume) || 0.28, 0.05, 1),
            wrongHitVolume: clamp(Number(cfg.wrongHitVolume) || Number(fallback.wrongHitVolume) || 0.2, 0.05, 1),
            correctHitAudioSources: normalizeUrlList(cfg.correctHitAudioSources || cfg.correctHitAudioUrl || fallback.correctHitAudioSources || fallback.correctHitAudioUrl),
            wrongHitAudioSources: normalizeUrlList(cfg.wrongHitAudioSources || cfg.wrongHitAudioUrl || fallback.wrongHitAudioSources || fallback.wrongHitAudioUrl)
        };
    }

    function createContext(rootEl, cfg) {
        const $page = $(rootEl);
        const $gamesRoot = $page.find('[data-ll-wordset-games-root]').first();
        if (!$gamesRoot.length) {
            return null;
        }

        const gamesCfg = (cfg.games && typeof cfg.games === 'object') ? cfg.games : {};
        const configuredRoundOptions = normalizeRoundOptionList(gamesCfg.roundOptions, gamesCfg.defaultRoundOption || 50);
        const defaultRoundOption = normalizeRoundOptionValue(gamesCfg.defaultRoundOption || 50) || '50';
        const storedRoundOption = readStoredRoundOption({
            wordsetId: toInt(cfg.wordsetId)
        });
        const selectedRoundOption = configuredRoundOptions.indexOf(storedRoundOption) !== -1
            ? storedRoundOption
            : (configuredRoundOptions.indexOf(defaultRoundOption) !== -1 ? defaultRoundOption : configuredRoundOptions[0]);
        const spaceShooter = (gamesCfg.spaceShooter && typeof gamesCfg.spaceShooter === 'object')
            ? gamesCfg.spaceShooter
            : {};
        const bubblePop = (gamesCfg.bubblePop && typeof gamesCfg.bubblePop === 'object')
            ? gamesCfg.bubblePop
            : {};
        const unscramble = (gamesCfg.unscramble && typeof gamesCfg.unscramble === 'object')
            ? gamesCfg.unscramble
            : {};
        const lineUp = (gamesCfg.lineUp && typeof gamesCfg.lineUp === 'object')
            ? gamesCfg.lineUp
            : {};
        const speakingPractice = (gamesCfg.speakingPractice && typeof gamesCfg.speakingPractice === 'object')
            ? gamesCfg.speakingPractice
            : {};
        const speakingStack = (gamesCfg.speakingStack && typeof gamesCfg.speakingStack === 'object')
            ? gamesCfg.speakingStack
            : {};
        const speakingStackFallSpeed = Math.max(80, toInt(speakingStack.fallSpeed) || 176);
        const speakingStackPreFirstAttemptFallSpeed = Math.min(
            speakingStackFallSpeed,
            Math.max(28, toInt(speakingStack.preFirstAttemptFallSpeed) || 64)
        );
        const runtimeMode = String(cfg.runtimeMode || gamesCfg.runtimeMode || '').trim().toLowerCase();
        const offlineMode = runtimeMode === 'offline';
        const staticCatalog = resolveStaticCatalog(gamesCfg, offlineMode);
        ensureCatalogCardsExist($gamesRoot, gamesCfg, {
            statusText: (cfg.isLoggedIn || offlineMode)
                ? String(((cfg.i18n && cfg.i18n.gamesLoading) || 'Checking game availability...'))
                : String(((cfg.i18n && cfg.i18n.gamesLoginRequired) || 'Sign in to play with your in-progress words.')),
            buttonText: (cfg.isLoggedIn || offlineMode)
                ? String(((cfg.i18n && cfg.i18n.gamesPlay) || 'Play'))
                : String(((cfg.i18n && cfg.i18n.gamesLocked) || 'Locked')),
            countLabel: String(((cfg.i18n && cfg.i18n.gamesEligibleWords) || 'Eligible words'))
        });
        const catalogCards = {};
        const catalogOrder = [];
        const $allCards = $gamesRoot.find('[data-ll-wordset-game-card]');
        $allCards.each(function () {
            const $card = $(this);
            const slug = normalizeGameSlug($card.attr('data-game-slug') || '');
            if (!slug || catalogCards[slug]) {
                return;
            }
            catalogOrder.push(slug);
            catalogCards[slug] = {
                slug: slug,
                $card: $card,
                title: String($card.find('.ll-wordset-game-card__title').first().text() || ''),
                $status: $card.find('[data-ll-wordset-game-status]').first(),
                $count: $card.find('[data-ll-wordset-game-count]').first(),
                $launchButton: $card.find('[data-ll-wordset-game-launch]').first()
            };
        });
        const defaultCatalogSlug = catalogCards[DEFAULT_GAME_SLUG]
            ? DEFAULT_GAME_SLUG
            : (catalogOrder[0] || DEFAULT_GAME_SLUG);
        const defaultCard = catalogCards[defaultCatalogSlug] || null;
        const $canvas = $gamesRoot.find('[data-ll-wordset-game-canvas]').first();
        const gameConfigs = {};
        gameConfigs[DEFAULT_GAME_SLUG] = buildGameConfig(spaceShooter, {
            slug: DEFAULT_GAME_SLUG,
            lives: 3,
            cardCount: 4,
            maxLoadedWords: 60,
            fireIntervalMs: 165,
            introRampTurns: 10,
            introRampStartFactor: 0.5,
            audioSafeLineRatio: 0.6,
            audioSafeLineBufferMs: 180,
            correctCoinReward: 1,
            wrongHitCoinPenalty: 0,
            wrongHitLifePenalty: 1,
            timeoutCoinPenalty: 1,
            timeoutLifePenalty: 1,
            assetPreloadTimeoutMs: ASSET_PRELOAD_TIMEOUT_MS,
            cardEntryRevealMs: 560,
            promptAutoReplayGapMs: 420,
            promptAudioVolume: 1,
            correctHitVolume: 0.28,
            wrongHitVolume: 0.2
        });
        gameConfigs[BUBBLE_POP_GAME_SLUG] = buildGameConfig(bubblePop, {
            slug: BUBBLE_POP_GAME_SLUG,
            lives: 3,
            cardCount: 4,
            maxLoadedWords: 60,
            introRampTurns: 10,
            introRampStartFactor: 0.5,
            audioSafeLineRatio: 0.58,
            audioSafeLineBufferMs: 180,
            correctCoinReward: 1,
            wrongHitCoinPenalty: 0,
            wrongHitLifePenalty: 1,
            timeoutCoinPenalty: 1,
            timeoutLifePenalty: 1,
            assetPreloadTimeoutMs: ASSET_PRELOAD_TIMEOUT_MS,
            cardEntryRevealMs: 520,
            promptAutoReplayGapMs: 420,
            promptAudioVolume: 1,
            correctHitVolume: 0.28,
            wrongHitVolume: 0.2
        });
        gameConfigs[LINEUP_GAME_SLUG] = {
            slug: LINEUP_GAME_SLUG,
            minimumSequenceLength: Math.max(2, toInt(lineUp.minimumSequenceLength) || 3),
            maxLoadedSequences: Math.max(1, toInt(lineUp.maxLoadedSequences) || 60),
            shuffleRetries: Math.max(1, toInt(lineUp.shuffleRetries) || 6)
        };
        gameConfigs[UNSCRAMBLE_GAME_SLUG] = {
            slug: UNSCRAMBLE_GAME_SLUG,
            minTileCount: Math.max(2, toInt(unscramble.minTileCount) || 3),
            maxTileCount: Math.max(2, toInt(unscramble.maxTileCount) || 18),
            maxLoadedWords: Math.max(1, toInt(unscramble.maxLoadedWords) || 60),
            shuffleRetries: Math.max(1, toInt(unscramble.shuffleRetries) || 6)
        };
        gameConfigs[SPEAKING_PRACTICE_GAME_SLUG] = $.extend({}, buildGameConfig(speakingPractice, {
            slug: SPEAKING_PRACTICE_GAME_SLUG,
            lives: 1,
            cardCount: 1,
            maxLoadedWords: 60,
            correctCoinReward: 0,
            wrongHitLifePenalty: 0,
            timeoutCoinPenalty: 0,
            timeoutLifePenalty: 0,
            promptAudioVolume: 1,
            correctHitVolume: 0.28,
            wrongHitVolume: 0.2,
            correctHitAudioSources: spaceShooter.correctHitAudioSources || [],
            wrongHitAudioSources: spaceShooter.wrongHitAudioSources || []
        }), {
            autoStartDelayMs: Math.max(0, toInt(speakingPractice.autoStartDelayMs) || 280),
            maxRecordingMs: Math.max(1500, toInt(speakingPractice.maxRecordingMs) || 8000),
            silenceWindowMs: Math.max(400, toInt(speakingPractice.silenceWindowMs) || 1050),
            silenceThreshold: clamp(Number(speakingPractice.silenceThreshold) || 0.034, 0.005, 0.2),
            speechStartThreshold: clamp(Number(speakingPractice.speechStartThreshold) || 0.06, 0.005, 0.3),
            minSpeechMs: Math.max(100, toInt(speakingPractice.minSpeechMs) || 160),
            apiCheckTimeoutMs: Math.max(500, toInt(speakingPractice.apiCheckTimeoutMs) || 1500)
        });
        gameConfigs[SPEAKING_STACK_GAME_SLUG] = $.extend({}, buildGameConfig(speakingStack, {
            slug: SPEAKING_STACK_GAME_SLUG,
            lives: 1,
            cardCount: 4,
            maxLoadedWords: 60,
            correctCoinReward: 0,
            wrongHitLifePenalty: 0,
            timeoutCoinPenalty: 0,
            timeoutLifePenalty: 0,
            promptAudioVolume: 1,
            correctHitVolume: 0.28,
            wrongHitVolume: 0.2,
            correctHitAudioSources: spaceShooter.correctHitAudioSources || [],
            wrongHitAudioSources: spaceShooter.wrongHitAudioSources || []
        }), {
            initialSpawnCount: Math.max(1, toInt(speakingStack.initialSpawnCount) || 3),
            initialSpawnDelayMs: Math.max(3500, toInt(speakingStack.initialSpawnDelayMs) || 5200),
            spawnGapMs: Math.max(4000, toInt(speakingStack.spawnGapMs) || 4500),
            fallSpeed: speakingStackFallSpeed,
            preFirstAttemptFallSpeed: speakingStackPreFirstAttemptFallSpeed,
            stackGapPx: Math.max(0, toInt(speakingStack.stackGapPx) || 12),
            groundPaddingPx: Math.max(12, toInt(speakingStack.groundPaddingPx) || 34),
            topDangerPaddingPx: Math.max(0, toInt(speakingStack.topDangerPaddingPx) || 14),
            finalSilenceMs: Math.max(1000, toInt(speakingStack.finalSilenceMs) || 10000),
            matchThresholdScore: clamp(Number(speakingStack.matchThresholdScore) || 65, 1, 100),
            maxRecordingMs: Math.max(1500, toInt(speakingStack.maxRecordingMs) || 6000),
            silenceWindowMs: Math.max(400, toInt(speakingStack.silenceWindowMs) || 820),
            silenceThreshold: clamp(Number(speakingStack.silenceThreshold) || 0.03, 0.005, 0.2),
            speechStartThreshold: clamp(Number(speakingStack.speechStartThreshold) || 0.055, 0.005, 0.3),
            minSpeechMs: Math.max(100, toInt(speakingStack.minSpeechMs) || 120),
            apiCheckTimeoutMs: Math.max(500, toInt(speakingStack.apiCheckTimeoutMs) || 1500),
            thinkPaddingStartMs: Math.max(900, toInt(speakingStack.thinkPaddingStartMs) || 1900),
            thinkPaddingEndMs: Math.max(700, toInt(speakingStack.thinkPaddingEndMs) || 1200)
        });

        return {
            rootEl: rootEl,
            $page: $page,
            $gamesRoot: $gamesRoot,
            $runModal: $gamesRoot.find('[data-ll-wordset-game-run-modal]').first(),
            $runModalDialog: $gamesRoot.find('[data-ll-wordset-game-run-dialog]').first(),
            $catalogBackLink: $page.find('[data-ll-wordset-games-back]').first(),
            $catalogBackLabel: $page.find('[data-ll-wordset-games-back-label]').first(),
            $pageTitle: $page.find('[data-ll-wordset-games-page-title]').first(),
            $catalog: $gamesRoot.find('[data-ll-wordset-games-catalog]').first(),
            $speakingNotice: $gamesRoot.find('[data-ll-wordset-games-speaking-notice]').first(),
            $speakingNoticeText: $gamesRoot.find('[data-ll-wordset-games-speaking-notice-text]').first(),
            $speakingNoticeLink: $gamesRoot.find('[data-ll-wordset-games-speaking-notice-link]').first(),
            catalogCards: catalogCards,
            catalogOrder: catalogOrder,
            catalogBackDefaultLabel: String($page.find('[data-ll-wordset-games-back-label]').first().text() || ''),
            catalogBackDefaultAriaLabel: String($page.find('[data-ll-wordset-games-back]').first().attr('aria-label') || ''),
            catalogPageDefaultTitle: String($page.find('[data-ll-wordset-games-page-title]').first().text() || ''),
            defaultCatalogSlug: defaultCatalogSlug,
            catalogEntries: {},
            activeGameSlug: '',
            $card: defaultCard ? defaultCard.$card : $(),
            $cardStatus: defaultCard ? defaultCard.$status : $(),
            $cardCount: defaultCard ? defaultCard.$count : $(),
            $launchButton: defaultCard ? defaultCard.$launchButton : $(),
            $roundOptions: $gamesRoot.find('[data-ll-wordset-game-length-option]'),
            $stage: $gamesRoot.find('[data-ll-wordset-game-stage]').first(),
            $hud: $gamesRoot.find('.ll-wordset-game-stage__hud').first(),
            $canvasWrap: $gamesRoot.find('.ll-wordset-game-stage__canvas-wrap').first(),
            $controlsWrap: $gamesRoot.find('[data-ll-wordset-game-controls]').first(),
            $canvas: $canvas,
            canvas: $canvas.get(0) || null,
            canvasContext: null,
            $speakingStage: $gamesRoot.find('[data-ll-wordset-speaking-stage]').first(),
            $speakingRound: $gamesRoot.find('[data-ll-wordset-speaking-round]').first(),
            $speakingStatus: $gamesRoot.find('[data-ll-wordset-speaking-status]').first(),
            $speakingPromptCard: $gamesRoot.find('[data-ll-wordset-speaking-prompt-card]').first(),
            $speakingImageWrap: $gamesRoot.find('[data-ll-wordset-speaking-image-wrap]').first(),
            $speakingImage: $gamesRoot.find('[data-ll-wordset-speaking-image]').first(),
            $speakingTextWrap: $gamesRoot.find('[data-ll-wordset-speaking-text-wrap]').first(),
            $speakingText: $gamesRoot.find('[data-ll-wordset-speaking-text]').first(),
            $speakingMeter: $gamesRoot.find('[data-ll-wordset-speaking-meter]').first(),
            $speakingMeterBars: $gamesRoot.find('.ll-wordset-speaking-stage__meter-bar'),
            $speakingActions: $gamesRoot.find('[data-ll-wordset-speaking-actions]').first(),
            $speakingRecord: $gamesRoot.find('[data-ll-wordset-speaking-record]').first(),
            $speakingRecordLabel: $gamesRoot.find('[data-ll-wordset-speaking-record-label]').first(),
            $speakingResult: $gamesRoot.find('[data-ll-wordset-speaking-result]').first(),
            $speakingBucket: $gamesRoot.find('[data-ll-wordset-speaking-bucket]').first(),
            $speakingScore: $gamesRoot.find('[data-ll-wordset-speaking-score]').first(),
            $speakingBar: $gamesRoot.find('[data-ll-wordset-speaking-bar]').first(),
            $speakingTranscript: $gamesRoot.find('[data-ll-wordset-speaking-transcript]').first(),
            $speakingTargetRow: $gamesRoot.find('[data-ll-wordset-speaking-target-row]').first(),
            $speakingTargetLabel: $gamesRoot.find('[data-ll-wordset-speaking-target-label]').first(),
            $speakingTarget: $gamesRoot.find('[data-ll-wordset-speaking-target]').first(),
            $speakingTitleRow: $gamesRoot.find('[data-ll-wordset-speaking-title-row]').first(),
            $speakingTitle: $gamesRoot.find('[data-ll-wordset-speaking-title]').first(),
            $speakingIpaRow: $gamesRoot.find('[data-ll-wordset-speaking-ipa-row]').first(),
            $speakingIpa: $gamesRoot.find('[data-ll-wordset-speaking-ipa]').first(),
            $speakingPlayAttempt: $gamesRoot.find('[data-ll-wordset-speaking-play-attempt]').first(),
            $speakingPlayCorrect: $gamesRoot.find('[data-ll-wordset-speaking-play-correct]').first(),
            $speakingRetry: $gamesRoot.find('[data-ll-wordset-speaking-retry]').first(),
            $speakingNext: $gamesRoot.find('[data-ll-wordset-speaking-next]').first(),
            $speakingStackStage: $gamesRoot.find('[data-ll-wordset-speaking-stack-stage]').first(),
            $speakingStackProgress: $gamesRoot.find('[data-ll-wordset-speaking-stack-progress]').first(),
            $speakingStackStatus: $gamesRoot.find('[data-ll-wordset-speaking-stack-status]').first(),
            $speakingStackHeardRow: $gamesRoot.find('[data-ll-wordset-speaking-stack-heard-row]').first(),
            $speakingStackHeard: $gamesRoot.find('[data-ll-wordset-speaking-stack-heard]').first(),
            $speakingStackMeter: $gamesRoot.find('[data-ll-wordset-speaking-stack-meter]').first(),
            $speakingStackMeterBars: $gamesRoot.find('.ll-wordset-speaking-stack-stage__meter-bar'),
            $lineupStage: $gamesRoot.find('[data-ll-wordset-lineup-stage]').first(),
            $lineupProgress: $gamesRoot.find('[data-ll-wordset-lineup-progress]').first(),
            $lineupCategory: $gamesRoot.find('[data-ll-wordset-lineup-category]').first(),
            $lineupInstruction: $gamesRoot.find('[data-ll-wordset-lineup-instruction]').first(),
            $lineupPrompt: $gamesRoot.find('[data-ll-wordset-lineup-prompt]').first(),
            $lineupPromptLabel: $gamesRoot.find('[data-ll-wordset-lineup-prompt-label]').first(),
            $lineupPromptImageWrap: $gamesRoot.find('[data-ll-wordset-lineup-prompt-image-wrap]').first(),
            $lineupPromptImage: $gamesRoot.find('[data-ll-wordset-lineup-prompt-image]').first(),
            $lineupPromptTextWrap: $gamesRoot.find('[data-ll-wordset-lineup-prompt-text-wrap]').first(),
            $lineupPromptText: $gamesRoot.find('[data-ll-wordset-lineup-prompt-text]').first(),
            $lineupStatus: $gamesRoot.find('[data-ll-wordset-lineup-status]').first(),
            $lineupCards: $gamesRoot.find('[data-ll-wordset-lineup-cards]').first(),
            $lineupShuffle: $gamesRoot.find('[data-ll-wordset-lineup-shuffle]').first(),
            $lineupCheck: $gamesRoot.find('[data-ll-wordset-lineup-check]').first(),
            $lineupNext: $gamesRoot.find('[data-ll-wordset-lineup-next]').first(),
            speakingAttemptAudio: $gamesRoot.find('[data-ll-wordset-speaking-attempt-audio]').get(0) || null,
            speakingCorrectAudio: $gamesRoot.find('[data-ll-wordset-speaking-correct-audio]').get(0) || null,
            $overlay: $gamesRoot.find('[data-ll-wordset-game-overlay]').first(),
            $overlayCard: $gamesRoot.find('[data-ll-wordset-game-overlay-card]').first(),
            $overlayLoading: $gamesRoot.find('[data-ll-wordset-game-loading]').first(),
            $overlayLoadingText: $gamesRoot.find('[data-ll-wordset-game-loading-text]').first(),
            $overlayTitle: $gamesRoot.find('[data-ll-wordset-game-overlay-title]').first(),
            $overlaySummary: $gamesRoot.find('[data-ll-wordset-game-overlay-summary]').first(),
            $overlayPrimary: $gamesRoot.find('[data-ll-wordset-game-replay]').first(),
            $overlaySecondary: $gamesRoot.find('[data-ll-wordset-game-return]').first(),
            $coins: $gamesRoot.find('[data-ll-wordset-game-coins]').first(),
            $lives: $gamesRoot.find('[data-ll-wordset-game-lives]').first(),
            $controls: $gamesRoot.find('[data-ll-wordset-game-control]'),
            $replayAudioButton: $gamesRoot.find('[data-ll-wordset-game-replay-audio]').first(),
            $pauseButton: $gamesRoot.find('[data-ll-wordset-game-pause-toggle]').first(),
            $pauseIcon: $gamesRoot.find('[data-ll-wordset-game-pause-icon]').first(),
            ajaxUrl: String(cfg.ajaxUrl || ''),
            nonce: String(cfg.nonce || ''),
            isLoggedIn: !!cfg.isLoggedIn || offlineMode,
            wordsetId: toInt(cfg.wordsetId),
            visibleCategoryIds: uniqueIntList(cfg.visibleCategoryIds || []),
            i18n: (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {},
            bootstrapAction: String(gamesCfg.bootstrapAction || ''),
            launchAction: String(gamesCfg.launchAction || ''),
            transcribeAttemptAction: String(gamesCfg.transcribeAttemptAction || ''),
            scoreAttemptAction: String(gamesCfg.scoreAttemptAction || ''),
            matchAttemptAction: String(gamesCfg.matchAttemptAction || ''),
            minimumWordCount: Math.max(1, toInt(gamesCfg.minimumWordCount) || 5),
            roundOptions: configuredRoundOptions,
            defaultRoundOption: defaultRoundOption,
            selectedRoundOption: selectedRoundOption,
            canManageSettings: !!gamesCfg.canManageSettings,
            speakingSettingsUrl: String(gamesCfg.speakingSettingsUrl || (((cfg.links || {}).settings) || '') || ''),
            speakingHiddenNotice: normalizeSpeakingNotice(gamesCfg.speakingHiddenNotice || null),
            runtimeMode: runtimeMode,
            offlineMode: offlineMode,
            staticCatalog: staticCatalog,
            offlineBridge: (cfg.offlineBridge && typeof cfg.offlineBridge === 'object')
                ? cfg.offlineBridge
                : ((gamesCfg.offlineBridge && typeof gamesCfg.offlineBridge === 'object') ? gamesCfg.offlineBridge : null),
            catalogEntry: null,
            imageCache: {},
            audioPreloadCache: {},
            promptAudio: null,
            feedbackAudio: null,
            feedbackQueue: Promise.resolve(),
            feedbackQueueVersion: 0,
            feedbackPlaying: false,
            feedbackAudioSourceCache: {},
            transientAudioInstances: [],
            promptPlaybackRequestId: 0,
            promptReplayTimer: 0,
            bootstrapRequest: null,
            launchEntryCache: {},
            launchEntryRequests: {},
            run: null,
            overlayMode: '',
            boundLifecycle: false,
            gameConfigs: gameConfigs,
            spaceShooter: gameConfigs[DEFAULT_GAME_SLUG],
            bubblePop: gameConfigs[BUBBLE_POP_GAME_SLUG],
            unscramble: gameConfigs[UNSCRAMBLE_GAME_SLUG],
            lineUp: gameConfigs[LINEUP_GAME_SLUG],
            speakingPractice: gameConfigs[SPEAKING_PRACTICE_GAME_SLUG],
            speakingStack: gameConfigs[SPEAKING_STACK_GAME_SLUG],
            speaking: {
                availabilityChecks: {},
                mediaStream: null,
                mediaRecorder: null,
                audioChunks: [],
                audioContext: null,
                analyser: null,
                micSource: null,
                meterTimer: 0,
                speechDetected: false,
                speechStartedAt: 0,
                silenceStartedAt: 0,
                maxStopTimer: 0,
                autoStartTimer: 0,
                restartTimer: 0,
                stopPromise: null,
                currentBlob: null,
                attemptAudioUrl: '',
                attemptAudioRevokeTimer: 0,
                recentLaunchWordIds: [],
                transcribing: false
            }
        };
    }

    api.init = function (rootEl, cfg) {
        if (!rootEl) {
            return null;
        }

        if (api.__ctx) {
            resetGamesSurface(api.__ctx);
            unbindLifecycle(api.__ctx);
        }

        const ctx = createContext(rootEl, cfg || {});
        if (!ctx) {
            return null;
        }

        api.__ctx = ctx;
        updateProgressGlobals(ctx);
        bindAudioElementButtonUi(ctx.speakingAttemptAudio, ctx.$speakingPlayAttempt);
        bindAudioElementButtonUi(ctx.speakingCorrectAudio, ctx.$speakingPlayCorrect);
        bindLifecycle(ctx);
        bindDom(ctx);
        updateRoundOptionUi(ctx);
        toggleRunModalPageLock(false);
        setGameGuardPageClass(false);
        syncCanvasSize(ctx);
        updateStageGameUi(ctx, '');
        renderAllCatalogCards(ctx, null, !!(ctx.isLoggedIn || ctx.offlineMode));

        if (ctx.isLoggedIn || ctx.offlineMode || ctx.staticCatalog) {
            bootstrapCatalog(ctx);
        } else {
            renderAllCatalogCards(ctx, null, false);
        }

        return ctx;
    };

    api.__debug = {
        getContext: function () {
            const ctx = api.__ctx;
            if (!ctx) {
                return null;
            }
            const activeEntry = ctx.catalogEntries[getDefaultCatalogSlug(ctx)] || ctx.catalogEntry || null;
            return {
                hasCatalogEntry: !!activeEntry,
                launchable: !!(activeEntry && activeEntry.launchable),
                availableWordCount: activeEntry ? toInt(activeEntry.available_word_count) : 0,
                launchWordCount: activeEntry ? toInt(activeEntry.launch_word_count) : 0,
                launchWordCap: activeEntry ? toInt(activeEntry.launch_word_cap) : 0,
                selectedRoundOption: getSelectedRoundOption(ctx),
                stageHidden: !!ctx.$stage.prop('hidden'),
                gameRunning: !!ctx.run,
                catalogEntries: Object.keys(ctx.catalogEntries || {})
            };
        },
        getRunState: function () {
            const ctx = api.__ctx;
            const run = ctx && ctx.run;
            if (!run) {
                return null;
            }
            const cards = Array.isArray(run.cards) ? run.cards : [];
            return {
                slug: normalizeGameSlug(run.slug),
                coins: run.coins,
                lives: run.lives,
                promptsResolved: run.promptsResolved,
                totalRounds: getRunTotalRounds(run),
                inactiveRounds: Math.max(0, toInt(run.inactiveRounds)),
                paused: !!run.paused,
                pauseReason: String(run.pauseReason || ''),
                resumeAction: String(run.resumeAction || ''),
                awaitingPrompt: !!run.awaitingPrompt,
                currentPromptHadUserActivity: !!(run.prompt && run.prompt.hadUserActivity),
                lastResolvedPromptHadUserActivity: !!run.lastResolvedPromptHadUserActivity,
                cardSpeed: Math.round(Number(run.cardSpeed) || 0),
                promptCardSpeed: Math.round(Number(run.prompt && run.prompt.cardSpeed ? run.prompt.cardSpeed : run.cardSpeed) || 0),
                promptAudioDurationMs: Math.round(Number(run.prompt && run.prompt.audioDurationMs) || 0),
                promptAutoReplayDelayMs: Math.round(Number(run.prompt && run.prompt.autoReplayDelayMs) || 0),
                promptAutoReplayBaseDelayMs: Math.round(Number(run.prompt && run.prompt.autoReplayBaseDelayMs) || 0),
                promptSafeLineCrossDelayMs: Math.round(Number(run.prompt && run.prompt.safeLineCrossDelayMs) || 0),
                promptAutoReplaySafeLineGated: !!(run.prompt && run.prompt.autoReplaySafeLineGated),
                promptDistractorMode: run.prompt ? String(run.prompt.distractorMode || '') : '',
                currentSequenceIndex: toInt(run.currentSequenceIndex),
                currentSequenceCategoryId: toInt(run.currentSequence && run.currentSequence.category_id),
                currentSequenceDirection: String(run.currentSequence && run.currentSequence.direction || ''),
                lineupOrderWordIds: Array.isArray(run.currentOrder)
                    ? run.currentOrder.map(function (word) { return toInt(word && word.id); })
                    : [],
                lineupSequenceWordIds: Array.isArray(run.currentSequence && run.currentSequence.words)
                    ? run.currentSequence.words.map(function (word) { return toInt(word && word.id); })
                    : [],
                lineupSequenceLocked: !!run.sequenceLocked,
                cardWordIds: cards.map(function (card) { return toInt(card.word && card.word.id); }),
                targetWordId: run.prompt && run.prompt.target ? toInt(run.prompt.target.id) : 0,
                promptId: activePromptId(run),
                promptRecordingType: run.prompt ? String(run.prompt.recordingType || '') : '',
                metricCardWidth: Math.round(Number(run.metrics && run.metrics.cardWidth) || 0),
                metricCardHeight: Math.round(Number(run.metrics && run.metrics.cardHeight) || 0),
                activeCardCount: cards.filter(function (card) {
                    return isActivePromptCard(run, card) && !card.exploding;
                }).length,
                sameCategoryDistractorCount: run.prompt && run.prompt.target
                    ? cards.filter(function (card) {
                        return isActivePromptCard(run, card)
                            && !card.exploding
                            && !card.isTarget
                            && wordsShareCategory(card.word, run.prompt.target);
                    }).length
                    : 0,
                decorativeBubbleCount: Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles.length : 0,
                cardSnapshot: cards.map(function (card) {
                    return {
                        wordId: toInt(card.word && card.word.id),
                        promptId: toInt(card.promptId),
                        categoryId: toInt(card.word && card.word.category_id),
                        stackSlotIndex: getSpeakingStackIndex(card.stackSlotIndex),
                        stackTargetX: Math.round(Number(card.stackTargetX) || 0),
                        stackTargetY: Math.round(Number(card.stackTargetY) || 0),
                        x: Math.round(Number(card.x) || 0),
                        y: Math.round(Number(card.y) || 0),
                        speed: Math.round((Number(card.speed) || 0) * 100) / 100,
                        width: Math.round(Number(card.width) || 0),
                        height: Math.round(Number(card.height) || 0),
                        exploding: !!card.exploding,
                        removedFromStack: !!card.removedFromStack,
                        resolvedFalling: !!card.resolvedFalling,
                        isTarget: !!card.isTarget
                    };
                }),
                decorativeBubbleSnapshot: (Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles : []).map(function (bubble) {
                    return {
                        id: toInt(bubble && bubble.id),
                        x: Math.round(Number(bubble && bubble.x) || 0),
                        y: Math.round(Number(bubble && bubble.y) || 0),
                        radius: Math.round((Number(bubble && bubble.radius) || 0) * 100) / 100,
                        exploding: !!(bubble && bubble.exploding)
                    };
                })
            };
        },
        launch: function (slug) {
            if (!api.__ctx) {
                return;
            }
            const requestedSlug = normalizeGameSlug(slug || getDefaultCatalogSlug(api.__ctx));
            const entry = api.__ctx.catalogEntries[requestedSlug]
                || (requestedSlug === getDefaultCatalogSlug(api.__ctx) ? api.__ctx.catalogEntry : null);
            if (entry && entry.launchable) {
                launchGame(api.__ctx, requestedSlug);
            }
        },
        togglePause: function () {
            if (!api.__ctx || !api.__ctx.run) {
                return false;
            }
            if (api.__ctx.run.paused) {
                resumeRun(api.__ctx);
                return true;
            }
            pauseRun(api.__ctx);
            return true;
        },
        resolvePrompt: function (type) {
            const ctx = api.__ctx;
            const run = ctx && ctx.run;
            if (!run || !run.prompt) {
                return false;
            }
            if (type === 'timeout') {
                handlePromptTimeout(ctx);
                return true;
            }
            const targetCard = findTargetCard(run);
            if (type === 'correct' && targetCard) {
                markRunActivity(ctx);
                handleCorrectHit(ctx, targetCard);
                return true;
            }
            if (type === 'wrong') {
                markRunActivity(ctx);
                for (let index = 0; index < run.cards.length; index += 1) {
                    if (!run.cards[index].isTarget && !run.cards[index].exploding && isActivePromptCard(run, run.cards[index])) {
                        handleWrongHit(ctx, run.cards[index]);
                        return true;
                    }
                }
            }
            return false;
        },
        flushProgress: function () {
            if (api.__ctx) {
                return flushProgress(api.__ctx);
            }
            return null;
        }
    };
})(window, window.jQuery);
