(function (root) {
    'use strict';
    const { Util, State } = root.LLFlashcards;
    let genderFitResizeBound = false;

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function parseBooleanFlag(raw) {
        if (typeof raw === 'boolean') return raw;
        if (typeof raw === 'number') return raw > 0;
        if (typeof raw === 'string') {
            const normalized = raw.trim().toLowerCase();
            if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') return true;
            if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off' || normalized === '') return false;
        }
        return !!raw;
    }

    function getRawCategoryConfig(name) {
        const base = {
            prompt_type: 'audio',
            option_type: State.DEFAULT_DISPLAY_MODE,
            learning_supported: true,
        };
        if (!name) return base;
        const cats = (root.llToolsFlashcardsData && Array.isArray(root.llToolsFlashcardsData.categories))
            ? root.llToolsFlashcardsData.categories
            : [];
        const found = cats.find(c => c && c.name === name);
        return Object.assign({}, base, found || {});
    }

    function getCategoryConfig(name) {
        return getRawCategoryConfig(name);
    }

    function stripGenderVariation(value) {
        return (value === null || value === undefined)
            ? ''
            : String(value).replace(/[\uFE0E\uFE0F]/g, '');
    }

    function formatGenderDisplayLabel(value) {
        const cleaned = stripGenderVariation(value).trim();
        if (cleaned === '♂' || cleaned === '♀') {
            return cleaned + '\uFE0E';
        }
        return cleaned || String(value || '');
    }

    function getGenderOptions() {
        const data = root.llToolsFlashcardsData || {};
        const raw = Array.isArray(data.genderOptions) ? data.genderOptions : [];
        const options = [];
        const seen = {};
        raw.forEach(function (opt) {
            const val = stripGenderVariation((opt === null || opt === undefined) ? '' : String(opt)).trim();
            if (!val) return;
            const key = val.toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            options.push(val);
        });
        return options;
    }

    function isGenderModeEnabled() {
        const data = root.llToolsFlashcardsData || {};
        return !!data.genderEnabled && getGenderOptions().length >= 2;
    }

    function normalizeGenderValue(value, options) {
        const opts = Array.isArray(options) ? options : getGenderOptions();
        const base = stripGenderVariation((value === null || value === undefined) ? '' : String(value)).trim();
        if (!base) return '';
        const lowered = base.toLowerCase();
        for (let i = 0; i < opts.length; i++) {
            const opt = stripGenderVariation((opts[i] === null || opts[i] === undefined) ? '' : String(opts[i])).trim();
            if (!opt) continue;
            if (opt.toLowerCase() === lowered) return opt;
        }

        let desiredRole = '';
        const aliases = getGenderRoleAliases();
        const roleNames = Object.keys(aliases);
        for (let i = 0; i < roleNames.length; i++) {
            const role = roleNames[i];
            const variants = aliases[role] || [];
            for (let j = 0; j < variants.length; j++) {
                if (normalizeGenderLookupKey(variants[j]) === lowered) {
                    desiredRole = role;
                    break;
                }
            }
            if (desiredRole) break;
        }

        if (desiredRole) {
            for (let i = 0; i < opts.length; i++) {
                const opt = stripGenderVariation((opts[i] === null || opts[i] === undefined) ? '' : String(opts[i])).trim();
                if (!opt) continue;
                const inferred = inferGenderRoleFromValue(opt, i);
                if (inferred === desiredRole) {
                    return opt;
                }
            }
        }

        return '';
    }

    function normalizeGenderLookupKey(value) {
        const cleaned = stripGenderVariation((value === null || value === undefined) ? '' : String(value)).trim();
        return cleaned ? cleaned.toLowerCase() : '';
    }

    function escapeHtml(raw) {
        return String(raw === null || raw === undefined ? '' : raw)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getGenderRoleAliases() {
        return {
            masculine: ['masculine', 'masc', 'male', 'm', '♂'],
            feminine: ['feminine', 'fem', 'female', 'f', '♀']
        };
    }

    function normalizeGenderRole(role) {
        const cleaned = String(role || '').trim().toLowerCase();
        if (cleaned === 'masculine' || cleaned === 'feminine') return cleaned;
        return 'other';
    }

    function inferGenderRoleFromValue(value, index) {
        const key = normalizeGenderLookupKey(value);
        if (key) {
            const aliases = getGenderRoleAliases();
            const roleNames = Object.keys(aliases);
            for (let i = 0; i < roleNames.length; i++) {
                const role = roleNames[i];
                const variants = aliases[role] || [];
                for (let j = 0; j < variants.length; j++) {
                    if (normalizeGenderLookupKey(variants[j]) === key) {
                        return role;
                    }
                }
            }
        }
        if (index === 0) return 'masculine';
        if (index === 1) return 'feminine';
        return 'other';
    }

    function getGenderVisualConfig() {
        const data = root.llToolsFlashcardsData || {};
        const raw = data.genderVisualConfig;
        return (raw && typeof raw === 'object') ? raw : {};
    }

    function findGenderVisualOption(configOptions, value) {
        const options = Array.isArray(configOptions) ? configOptions : [];
        const needle = normalizeGenderLookupKey(value);
        if (!needle) return null;
        for (let i = 0; i < options.length; i++) {
            const entry = options[i];
            if (!entry || typeof entry !== 'object') continue;
            const keys = [entry.normalized, entry.value, entry.label];
            for (let j = 0; j < keys.length; j++) {
                if (normalizeGenderLookupKey(keys[j]) === needle) {
                    return entry;
                }
            }
        }
        return null;
    }

    function hexToRgb(hex) {
        let cleaned = String(hex || '').trim().replace(/^#/, '');
        if (cleaned.length === 3) {
            cleaned = cleaned[0] + cleaned[0] + cleaned[1] + cleaned[1] + cleaned[2] + cleaned[2];
        }
        if (!/^[a-f0-9]{6}$/i.test(cleaned)) {
            return [107, 114, 128];
        }
        return [
            parseInt(cleaned.slice(0, 2), 16),
            parseInt(cleaned.slice(2, 4), 16),
            parseInt(cleaned.slice(4, 6), 16)
        ];
    }

    function buildGenderStyleString(color) {
        const fallback = '#6B7280';
        const normalized = /^#[a-f0-9]{3,6}$/i.test(String(color || '').trim())
            ? String(color || '').trim().toUpperCase()
            : fallback;
        const rgb = hexToRgb(normalized);
        const bg = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',0.14)';
        const border = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',0.38)';
        return '--ll-gender-accent:' + normalized + ';--ll-gender-bg:' + bg + ';--ll-gender-border:' + border + ';';
    }

    function applyGenderStyleVariables($el, styleText) {
        if (!$el || !$el.length || !$el[0] || !$el[0].style) return;
        const node = $el[0];
        const props = ['--ll-gender-accent', '--ll-gender-bg', '--ll-gender-border'];
        props.forEach(function (prop) {
            try { node.style.removeProperty(prop); } catch (_) { /* no-op */ }
        });

        const raw = String(styleText || '').trim();
        if (!raw) return;
        raw.split(';').forEach(function (chunk) {
            const trimmed = String(chunk || '').trim();
            if (!trimmed) return;
            const idx = trimmed.indexOf(':');
            if (idx < 1) return;
            const prop = trimmed.slice(0, idx).trim();
            const value = trimmed.slice(idx + 1).trim();
            if (!prop || !value || prop.indexOf('--ll-gender-') !== 0) return;
            try { node.style.setProperty(prop, value); } catch (_) { /* no-op */ }
        });
    }

    function getGenderVisualForOption(value, index, options) {
        const genderOptions = Array.isArray(options) ? options : getGenderOptions();
        const normalizedValue = normalizeGenderValue(value, genderOptions) || stripGenderVariation(String(value || '')).trim();
        const displayLabel = formatGenderDisplayLabel(normalizedValue || value || '');

        const visualConfig = getGenderVisualConfig();
        const configOptions = Array.isArray(visualConfig.options) ? visualConfig.options : [];
        let optionVisual = findGenderVisualOption(configOptions, normalizedValue) || findGenderVisualOption(configOptions, value);
        if (!optionVisual && index >= 0 && index < configOptions.length) {
            optionVisual = configOptions[index];
        }

        const defaultColors = { masculine: '#2563EB', feminine: '#EC4899', other: '#6B7280' };
        const colors = Object.assign({}, defaultColors, (visualConfig.colors && typeof visualConfig.colors === 'object') ? visualConfig.colors : {});
        const role = normalizeGenderRole((optionVisual && optionVisual.role) || inferGenderRoleFromValue(normalizedValue || value, index));

        let color = String((optionVisual && optionVisual.color) || colors[role] || colors.other || defaultColors.other).trim();
        if (!/^#[a-f0-9]{3,6}$/i.test(color)) {
            color = colors[role] || colors.other || defaultColors.other;
        }
        let style = String((optionVisual && optionVisual.style) || '').trim();
        if (!style) {
            style = buildGenderStyleString(color);
        }

        let symbol = (optionVisual && optionVisual.symbol && typeof optionVisual.symbol === 'object')
            ? optionVisual.symbol
            : null;
        if (!symbol && (role === 'masculine' || role === 'feminine')) {
            const symbols = (visualConfig.symbols && typeof visualConfig.symbols === 'object') ? visualConfig.symbols : {};
            if (symbols[role] && typeof symbols[role] === 'object') {
                symbol = symbols[role];
            }
        }

        let symbolType = symbol && symbol.type === 'svg' ? 'svg' : 'text';
        let symbolValue = String((symbol && symbol.value) || '').trim();
        if (symbolType === 'svg') {
            const lower = symbolValue.toLowerCase();
            if (!(lower.indexOf('<svg') !== -1 && lower.indexOf('</svg>') !== -1)) {
                symbolType = 'text';
                symbolValue = displayLabel || '?';
            }
        }
        if (symbolType !== 'svg') {
            symbolValue = formatGenderDisplayLabel(symbolValue || displayLabel || '?');
        }

        return {
            value: normalizedValue,
            label: displayLabel,
            role: role,
            color: color,
            style: style,
            symbol: {
                type: symbolType,
                value: symbolValue
            }
        };
    }

    function buildGenderSymbolMarkup(visual, fallbackLabel) {
        const meta = (visual && visual.symbol && typeof visual.symbol === 'object') ? visual.symbol : {};
        const symbolType = meta.type === 'svg' ? 'svg' : 'text';
        const symbolValue = String(meta.value || '').trim();
        if (symbolType === 'svg') {
            const lowered = symbolValue.toLowerCase();
            if (lowered.indexOf('<svg') !== -1 && lowered.indexOf('</svg>') !== -1) {
                return '<span class="ll-gender-symbol ll-gender-symbol--svg" aria-hidden="true">' + symbolValue + '</span>';
            }
        }
        const text = formatGenderDisplayLabel(symbolValue || fallbackLabel || '?');
        return '<span class="ll-gender-symbol" aria-hidden="true">' + escapeHtml(text) + '</span>';
    }

    function shouldShowGenderOptionLabel(visual) {
        const labelKey = normalizeGenderLookupKey(visual && visual.label);
        if (!labelKey) return false;
        const symbol = (visual && visual.symbol && typeof visual.symbol === 'object') ? visual.symbol : {};
        const symbolType = symbol.type === 'svg' ? 'svg' : 'text';
        if (symbolType === 'svg' && (labelKey === '♂' || labelKey === '♀')) {
            return false;
        }
        if (symbolType === 'text') {
            const symbolKey = normalizeGenderLookupKey(symbol.value || '');
            if (symbolKey && symbolKey === labelKey) {
                return false;
            }
        }
        return true;
    }

    function applyGenderVisualToOptionCard($card, visual) {
        if (!$card || !$card.length) return;
        const role = normalizeGenderRole(visual && visual.role);
        $card.removeClass('ll-gender-option--masculine ll-gender-option--feminine ll-gender-option--other');
        $card.addClass('ll-gender-option--' + role).attr('data-ll-gender-role', role);
        applyGenderStyleVariables($card, visual && visual.style ? visual.style : '');

        const displayLabel = String((visual && visual.label) || '').trim();
        const symbolHtml = buildGenderSymbolMarkup(visual, displayLabel);
        const showTextLabel = shouldShowGenderOptionLabel(visual);
        const labelHtml = showTextLabel
            ? '<span class="ll-gender-option-label" aria-hidden="true">' + escapeHtml(displayLabel) + '</span>'
            : '';
        const srText = '<span class="screen-reader-text">' + escapeHtml(displayLabel) + '</span>';

        const $label = $card.find('.quiz-text').first();
        if ($label.length) {
            $label.html('<span class="ll-gender-option-inner">' + symbolHtml + labelHtml + srText + '</span>');
        }
    }

    function cardGenderLabelOverflows(cardEl) {
        if (!cardEl || typeof cardEl.querySelector !== 'function') return false;
        const textEl = cardEl.querySelector('.quiz-text');
        const innerEl = cardEl.querySelector('.ll-gender-option-inner');
        if (!textEl || !innerEl) return false;
        const available = Math.max(0, Math.floor(textEl.clientWidth) - 2);
        if (!available) return false;
        const needed = Math.ceil(innerEl.scrollWidth);
        return needed > available;
    }

    function fitGenderOptionTextScale() {
        const $ = root.jQuery;
        if (!$) return;
        const $allCards = $('#ll-tools-flashcard .ll-gender-option');
        if (!$allCards.length) return;
        const $cards = $allCards.not('.ll-gender-option--unknown');
        const $measureCards = $cards.length ? $cards : $allCards;

        const MIN_SCALE = 0.56;
        const SCALE_STEP = 0.03;
        const MAX_PASSES = 16;

        const applyScale = function (scale) {
            const normalized = Math.max(MIN_SCALE, Math.min(1, scale));
            const value = normalized.toFixed(3).replace(/\.?0+$/, '');
            $allCards.each(function () {
                if (this && this.style) {
                    this.style.setProperty('--ll-gender-option-scale', value);
                }
            });
            return normalized;
        };

        let scale = applyScale(1);
        for (let pass = 0; pass < MAX_PASSES; pass++) {
            let hasOverflow = false;
            $measureCards.each(function () {
                if (cardGenderLabelOverflows(this)) {
                    hasOverflow = true;
                    return false;
                }
            });
            if (!hasOverflow || scale <= MIN_SCALE) break;
            scale = applyScale(scale - SCALE_STEP);
        }
    }

    function syncGenderUnknownTextSize() {
        const $ = root.jQuery;
        if (!$) return;
        const $unknownText = $('#ll-tools-flashcard .ll-gender-option--unknown .quiz-text').first();
        if (!$unknownText.length) return;

        let measuredFontSize = '';
        const labelEl = $('#ll-tools-flashcard .ll-gender-option:not(.ll-gender-option--unknown) .ll-gender-option-label').get(0);
        if (labelEl && typeof root.getComputedStyle === 'function') {
            measuredFontSize = String(root.getComputedStyle(labelEl).fontSize || '').trim();
        }
        if (!measuredFontSize) {
            const fallbackEl = $('#ll-tools-flashcard .ll-gender-option:not(.ll-gender-option--unknown) .quiz-text').get(0);
            if (fallbackEl && typeof root.getComputedStyle === 'function') {
                measuredFontSize = String(root.getComputedStyle(fallbackEl).fontSize || '').trim();
            }
        }
        if (!measuredFontSize) return;
        try {
            $unknownText[0].style.setProperty('font-size', measuredFontSize);
            $unknownText[0].style.setProperty('line-height', '1.06');
        } catch (_) { /* no-op */ }
    }

    function getGenderSafeBottomInset() {
        const viewportWidth = Math.max(0, parseInt(root.innerWidth, 10) || 0);
        const viewportHeight = Math.max(0, parseInt(root.innerHeight, 10) || 0);
        if (viewportWidth > 420 && viewportHeight > 620) return 0;
        const doc = root.document;
        if (!doc) return 0;
        const wrap = doc.getElementById('ll-tools-mode-switcher-wrap');
        if (!wrap || typeof wrap.getBoundingClientRect !== 'function') return 0;
        if (typeof root.getComputedStyle === 'function') {
            const computed = root.getComputedStyle(wrap);
            if (!computed || computed.display === 'none' || computed.visibility === 'hidden' || computed.opacity === '0') {
                return 0;
            }
        }
        const rect = wrap.getBoundingClientRect();
        if (!rect || rect.top >= viewportHeight || rect.bottom <= 0) return 0;
        return Math.max(0, Math.ceil(viewportHeight - rect.top + 8));
    }

    function fitGenderLayoutToViewport() {
        const $ = root.jQuery;
        if (!$) return;
        const $content = $('#ll-tools-flashcard-content.ll-gender-options-mode');
        const $container = $('#ll-tools-flashcard.ll-gender-options-layout');
        if (!$content.length || !$container.length) return;
        const contentEl = $content[0];
        const containerEl = $container[0];
        if (!contentEl || !containerEl || !contentEl.style || !containerEl.style) return;

        const safeBottom = getGenderSafeBottomInset();
        contentEl.style.setProperty('--ll-gender-safe-bottom', safeBottom + 'px');

        const MIN_LAYOUT_SCALE = 0.5;
        const SCALE_STEP = 0.04;
        const MAX_PASSES = 16;

        const applyLayoutScale = function (scale) {
            const normalized = Math.max(MIN_LAYOUT_SCALE, Math.min(1, scale));
            const value = normalized.toFixed(3).replace(/\.?0+$/, '');
            contentEl.style.setProperty('--ll-gender-layout-scale', value);
            containerEl.style.setProperty('--ll-gender-layout-scale', value);
            return normalized;
        };

        let layoutScale = applyLayoutScale(1);
        fitGenderOptionTextScale();
        syncGenderUnknownTextSize();

        for (let pass = 0; pass < MAX_PASSES; pass++) {
            const allowedHeight = Math.max(0, contentEl.clientHeight || 0);
            const usedHeight = Math.max(contentEl.scrollHeight || 0, containerEl.scrollHeight || 0);
            const widthLimit = Math.max(0, (contentEl.clientWidth || 0) - 1);
            const horizontalOverflow = (containerEl.scrollWidth || 0) > widthLimit;
            const verticalOverflow = usedHeight > (allowedHeight + 1);

            if (!horizontalOverflow && !verticalOverflow) break;
            if (layoutScale <= MIN_LAYOUT_SCALE) break;
            layoutScale = applyLayoutScale(layoutScale - SCALE_STEP);
            fitGenderOptionTextScale();
            syncGenderUnknownTextSize();
        }

        if (contentEl.classList && typeof contentEl.classList.toggle === 'function') {
            contentEl.classList.toggle('ll-gender-layout-compact', layoutScale < 0.999);
        }
    }

    function scheduleGenderOptionTextScaleFit() {
        if (typeof root.requestAnimationFrame === 'function') {
            root.requestAnimationFrame(function () {
                fitGenderLayoutToViewport();
                root.requestAnimationFrame(fitGenderLayoutToViewport);
            });
            return;
        }
        setTimeout(fitGenderLayoutToViewport, 0);
        setTimeout(fitGenderLayoutToViewport, 120);
    }

    function ensureGenderOptionFitResizeHandler() {
        if (genderFitResizeBound || !root || typeof root.addEventListener !== 'function') return;
        root.addEventListener('resize', function () {
            if (!State || !State.isGenderMode) return;
            scheduleGenderOptionTextScaleFit();
        }, { passive: true });
        genderFitResizeBound = true;
    }

    function getGenderAssetRequirements(categoryName) {
        const cfg = getRawCategoryConfig(categoryName);
        const opt = cfg.option_type || cfg.mode || State.DEFAULT_DISPLAY_MODE;
        const promptType = cfg.prompt_type || 'audio';
        const requiresAudio = (promptType === 'audio') || opt === 'audio' || opt === 'text_audio';
        const requiresImage = (promptType === 'image') || opt === 'image';
        return { requiresAudio, requiresImage };
    }

    function isGenderEligibleWord(word, options, categoryName) {
        if (!word || !options || !options.length) return false;
        const posRaw = word.part_of_speech;
        const pos = Array.isArray(posRaw) ? posRaw : (posRaw ? [posRaw] : []);
        const isNoun = pos.some(function (p) { return String(p).toLowerCase() === 'noun'; });
        if (!isNoun) return false;

        const genderLabel = normalizeGenderValue(word.grammatical_gender, options);
        if (!genderLabel) return false;

        const requirements = getGenderAssetRequirements(categoryName || getTargetCategoryName(word));
        const hasImage = !!(word.image || word.has_image);
        const hasAudio = !!(word.audio || word.has_audio);
        if (requirements.requiresImage && !hasImage) return false;
        if (requirements.requiresAudio && !hasAudio) return false;

        word.__gender_label = genderLabel;
        return true;
    }

    function getGenderWordsByCategory() {
        const source = State.wordsByCategory || {};
        const options = getGenderOptions();
        const out = {};
        Object.keys(source).forEach(function (name) {
            const list = Array.isArray(source[name]) ? source[name] : [];
            out[name] = list.filter(function (word) { return isGenderEligibleWord(word, options, name); });
        });
        return out;
    }

    function getActiveWordsByCategory() {
        if (State && State.isGenderMode && isGenderModeEnabled()) {
            return getGenderWordsByCategory();
        }
        return State.wordsByCategory || {};
    }

    function getOptionWordsByCategory() {
        const source = root.optionWordsByCategory;
        if (source && typeof source === 'object') {
            return source;
        }
        return getActiveWordsByCategory();
    }

    function getCategoryDisplayMode(name) {
        if (!name) return State.DEFAULT_DISPLAY_MODE;
        const cfg = getCategoryConfig(name);
        const opt = cfg.option_type || cfg.mode || State.DEFAULT_DISPLAY_MODE;
        if (opt === 'text_title' || opt === 'text_translation') return 'text';
        return opt;
    }
    function getCurrentDisplayMode() {
        if (State && State.isGenderMode && isGenderModeEnabled()) {
            return 'text';
        }
        return getCategoryDisplayMode(State.currentCategoryName);
    }
    function getCategoryPromptType(name) {
        const cfg = getCategoryConfig(name);
        return cfg.prompt_type || 'audio';
    }

    function weightedChoice(items, weightFn) {
        if (!Array.isArray(items) || !items.length) return null;
        let total = 0;
        const weights = items.map(function (item) {
            const w = Math.max(0, weightFn(item) || 0);
            total += w;
            return w;
        });
        if (total <= 0) return items[Math.floor(Math.random() * items.length)];
        let r = Math.random() * total;
        for (let i = 0; i < items.length; i++) {
            r -= weights[i];
            if (r <= 0) return items[i];
        }
        return items[items.length - 1];
    }

    function getStarredLookup() {
        const prefs = root.llToolsStudyPrefs || {};
        const ids = Array.isArray(prefs.starredWordIds) ? prefs.starredWordIds : [];
        const map = {};
        ids.forEach(function (id) {
            const n = parseInt(id, 10);
            if (n > 0) { map[n] = true; }
        });
        return map;
    }

    function getStarMode() {
        if (State && State.starModeOverride) {
            return normalizeStarMode(State.starModeOverride);
        }
        const prefs = root.llToolsStudyPrefs || {};
        const modeFromPrefs = prefs.starMode || prefs.star_mode;
        const modeFromFlash = (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || null;
        const mode = modeFromPrefs || modeFromFlash || 'normal';
        return normalizeStarMode(mode);
    }

    function getStarPlayCounts() {
        State.starPlayCounts = State.starPlayCounts || {};
        return State.starPlayCounts;
    }

    function canPlayWord(wordId, starredLookup, starMode) {
        if (!wordId) return false;
        const counts = getStarPlayCounts();
        const maxUses = (starMode === 'weighted' && starredLookup[wordId]) ? 2 : 1;
        const plays = counts[wordId] || 0;
        return plays < maxUses;
    }

    function recordPlay(wordId, starredLookup, starMode) {
        if (!wordId) return;
        const counts = getStarPlayCounts();
        const maxUses = (starMode === 'weighted' && starredLookup[wordId]) ? 2 : 1;
        counts[wordId] = (counts[wordId] || 0) + 1;
        if (counts[wordId] >= maxUses && !State.usedWordIDs.includes(wordId)) {
            State.usedWordIDs.push(wordId);
        }
    }

    function pruneCompletedCategories() {
        if (!Array.isArray(State.categoryNames)) return;
        State.completedCategories = State.completedCategories || {};
        const completed = State.completedCategories || {};
        State.categoryNames = State.categoryNames.filter(function (name) {
            return !completed[name];
        });
    }

    function shouldCompleteCategoryBeforeSwitch() {
        if (!State) return false;
        if (State.isLearningMode || State.isListeningMode) return false;
        return true;
    }

    function getAvailableUnusedWords(name, starredLookup, starMode) {
        const list = getActiveWordsByCategory()[name] || [];
        if (!Array.isArray(list) || !list.length) return [];
        const counts = getStarPlayCounts();
        const filtered = list.filter(function (w) {
            const usedCount = counts[w.id] || 0;
            const alreadyUsed = State.usedWordIDs.includes(w.id);
            const maxUses = (starMode === 'weighted' && starredLookup[w.id]) ? 2 : 1;
            const plays = Math.max(usedCount, alreadyUsed ? 1 : 0);
            if (plays >= maxUses) return false;
            if (starMode === 'only' && !starredLookup[w.id]) return false;
            return true;
        });
        if (filtered.length) return filtered;

        return [];
    }

    function getTargetCategoryName(word) {
        if (!word) return State.currentCategoryName;
        if (word.__categoryName) return word.__categoryName;
        if (Array.isArray(word.all_categories) && Array.isArray(State.categoryNames)) {
            const match = State.categoryNames.find(function (name) {
                return word.all_categories.includes(name);
            });
            if (match) return match;
        }
        return State.currentCategoryName;
    }
    function categoryRequiresAudio(nameOrConfig) {
        const cfg = typeof nameOrConfig === 'object' ? (nameOrConfig || {}) : getCategoryConfig(nameOrConfig);
        const opt = cfg.option_type || cfg.mode;
        return (cfg.prompt_type === 'audio') || opt === 'audio' || opt === 'text_audio';
    }

    function normalizeTextForComparison(text) {
        const base = (text === null || text === undefined) ? '' : String(text).trim();
        if (base === '') return '';
        const prepared = base.replace(/[I\u0130]/g, function (ch) { return ch === 'I' ? '\u0131' : 'i'; });
        let lowered = prepared;
        try { lowered = prepared.toLocaleLowerCase('tr'); } catch (_) { lowered = prepared.toLowerCase(); }
        return lowered.replace(/\u0307/g, '');
    }

    function getNormalizedOptionText(word) {
        if (!word || typeof word !== 'object') return '';
        const val = (typeof word.label === 'string' && word.label !== '') ? word.label : word.title;
        return normalizeTextForComparison(val);
    }

    function normalizeWordId(value) {
        const id = parseInt(value, 10);
        return id > 0 ? id : 0;
    }

    function extractMaskedImageAttachmentId(rawUrl) {
        if (!rawUrl || typeof rawUrl !== 'string') return 0;
        const url = rawUrl.trim();
        if (!url) return 0;

        const directMatch = url.match(/[?&]lltools-img=(\d+)/);
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
        if (!word || typeof word !== 'object' || !word.image) return '';
        const raw = String(word.image).trim();
        if (!raw) return '';

        const attachmentId = extractMaskedImageAttachmentId(raw);
        if (attachmentId > 0) {
            return 'attachment:' + String(attachmentId);
        }

        return 'url:' + raw.split('#')[0];
    }

    function wordHasBlockedId(word, otherId) {
        if (!word || !otherId || !Array.isArray(word.option_blocked_ids)) return false;
        return word.option_blocked_ids.some(function (id) {
            return normalizeWordId(id) === otherId;
        });
    }

    function wordsConflictForOptions(leftWord, rightWord) {
        const leftId = normalizeWordId(leftWord && leftWord.id);
        const rightId = normalizeWordId(rightWord && rightWord.id);
        if (!leftId || !rightId || leftId === rightId) return false;

        if (wordHasBlockedId(leftWord, rightId) || wordHasBlockedId(rightWord, leftId)) {
            return true;
        }

        const leftImage = getWordImageIdentity(leftWord);
        const rightImage = getWordImageIdentity(rightWord);
        return !!leftImage && leftImage === rightImage;
    }

    function isLearningSupportedForCategories(categoryNames) {
        try {
            const names = Array.isArray(categoryNames) && categoryNames.length ? categoryNames : State.categoryNames;
            if (!Array.isArray(names) || !names.length) return true;
            return names.every(function (name) {
                const cfg = getCategoryConfig(name);
                return cfg.learning_supported !== false;
            });
        } catch (_) {
            return true;
        }
    }

    function isGenderSupportedForCategories(categoryNames) {
        if (!isGenderModeEnabled()) return false;
        try {
            const names = Array.isArray(categoryNames) && categoryNames.length ? categoryNames : State.categoryNames;
            if (!Array.isArray(names) || !names.length) return false;
            const minCount = parseInt((root.llToolsFlashcardsData && root.llToolsFlashcardsData.genderMinCount) || '', 10) || 2;
            return names.some(function (name) {
                const cfg = getCategoryConfig(name);
                if (Object.prototype.hasOwnProperty.call(cfg, 'gender_supported')) {
                    return parseBooleanFlag(cfg.gender_supported);
                }
                const list = getGenderWordsByCategory()[name] || [];
                return list.length >= minCount;
            });
        } catch (_) {
            return false;
        }
    }

    function renderPrompt(targetWord, cfg) {
        const promptConfig = cfg || getCategoryConfig(State.currentCategoryName);
        const categoryName = getTargetCategoryName(targetWord) || State.currentCategoryName;
        const rawConfig = (State && State.isGenderMode && isGenderModeEnabled())
            ? getRawCategoryConfig(categoryName)
            : promptConfig;
        const promptType = promptConfig.prompt_type || 'audio';
        const optionType = rawConfig.option_type || rawConfig.mode || State.DEFAULT_DISPLAY_MODE;
        const isGender = (State && State.isGenderMode && isGenderModeEnabled());
        const isTextOption = (optionType === 'text' || optionType === 'text_title' || optionType === 'text_translation' || optionType === 'text_audio');
        const requirements = getGenderAssetRequirements(categoryName || State.currentCategoryName);
        const showImage = (promptType === 'image') || (isGender && optionType === 'image');
        const showText = isGender && isTextOption;
        const showAudio = isGender && requirements.requiresAudio && promptType !== 'audio';
        const $ = root.jQuery;
        if (!$) return;
        let $prompt = $('#ll-tools-prompt');
        if (!$prompt.length) {
            $prompt = $('<div>', { id: 'll-tools-prompt', class: 'll-tools-prompt', style: 'display:none;' });
            $('#ll-tools-flashcard-content').prepend($prompt);
        }
        if (!targetWord) {
            $prompt.hide().empty();
            return;
        }
        const labelText = showText ? (targetWord.label || targetWord.title || '') : '';
        const hasImage = showImage && !!targetWord.image;
        const hasText = showText && !!labelText;
        const hasAudio = showAudio && !!targetWord.audio;
        if (!hasImage && !hasText && !hasAudio) {
            $prompt.hide().empty();
            return;
        }
        $prompt.show().empty();
        const $stack = $('<div>', { class: 'll-prompt-stack' });
        if (hasImage) {
            const $wrap = $('<div>', { class: 'll-prompt-image-wrap' });
            $('<img>', { src: targetWord.image, alt: '', 'aria-hidden': 'true' }).appendTo($wrap);
            $stack.append($wrap);
        }
        if (hasText) {
            $('<div>', { class: 'll-prompt-text', text: labelText }).appendTo($stack);
        }
        if (hasAudio) {
            const $btn = $('<button>', {
                type: 'button',
                class: 'll-prompt-audio-button',
                'aria-label': 'Play word audio'
            });
            const $ui = $('<span>', { class: 'll-repeat-audio-ui' });
            const $iconWrap = $('<span>', { class: 'll-repeat-icon-wrap', 'aria-hidden': 'true' });
            $('<span>', { class: 'll-audio-play-icon', 'aria-hidden': 'true', text: '▶' }).appendTo($iconWrap);
            const $viz = $('<div>', { class: 'll-audio-mini-visualizer', 'aria-hidden': 'true' });
            for (let i = 0; i < 6; i++) {
                $('<span>', { class: 'bar', 'data-bar': i + 1 }).appendTo($viz);
            }
            $ui.append($iconWrap, $viz);
            $btn.append($ui);
            $btn.on('click', function (e) {
                e.stopPropagation();
                if (root.LLFlashcards && root.LLFlashcards.Cards && typeof root.LLFlashcards.Cards.playOptionAudio === 'function') {
                    root.LLFlashcards.Cards.playOptionAudio(targetWord, $btn);
                }
            });
            $stack.append($btn);
        }
        $prompt.append($stack);
    }

    function selectTargetWord(candidateCategory, candidateCategoryName) {
        if (!candidateCategory || !candidateCategory.length) {
            State.completedCategories = State.completedCategories || {};
            State.completedCategories[candidateCategoryName] = true;
            return null;
        }
        let target = null;
        let didRecordPlay = false;
        const queue = State.categoryRepetitionQueues[candidateCategoryName];
        const starredLookup = getStarredLookup();
        const starMode = getStarMode();
        const stayInCategory = shouldCompleteCategoryBeforeSwitch();

        // First, try to find a word from the repetition queue that's ready to reappear
        // and ISN'T the same as the last word shown
        if (queue && queue.length) {
            for (let i = 0; i < queue.length; i++) {
                const queuedItem = queue[i];
                const queuedWord = queuedItem.wordData;
                const allowOverflow = !!queuedItem.forceReplay; // forceReplay allows wrong answers to bypass max-play caps
                const playable = queuedWord && (allowOverflow || canPlayWord(queuedWord.id, starredLookup, starMode));

                if (!playable) {
                    queue.splice(i, 1);
                    i--;
                    continue;
                }
                if (queue[i].reappearRound <= (State.categoryRoundCount[candidateCategoryName] || 0)) {
                    // Skip if this is the same word we just showed
                    if (queue[i].wordData.id !== State.lastWordShownId) {
                        target = queue[i].wordData;
                        if (queuedItem.forceReplay && State.practiceForcedReplays) {
                            const key = String(queuedWord.id);
                            const val = State.practiceForcedReplays[key];
                            if (val) State.practiceForcedReplays[key] = Math.max(0, val - 1);
                        }
                        queue.splice(i, 1);
                        recordPlay(target.id, starredLookup, starMode);
                        didRecordPlay = true;
                        break;
                    }
                }
            }
        }

        // If no target from queue, try to find an unused word (and not the last shown)
        if (!target) {
            State.completedCategories = State.completedCategories || {};
            const unused = getAvailableUnusedWords(candidateCategoryName, starredLookup, starMode)
                .filter(function (w) { return w.id !== State.lastWordShownId; })
                .filter(function (w) { return canPlayWord(w.id, starredLookup, starMode); });

            // If no unused words and no queue, mark this category done
            if ((!unused || !unused.length) && (!queue || !queue.length)) {
                State.completedCategories[candidateCategoryName] = true;
                return null;
            }

            if (unused && unused.length) {
                if (starMode === 'weighted') {
                    const starredPool = unused.filter(function (w) { return !!starredLookup[w.id]; });
                    const regularPool = unused.filter(function (w) { return !starredLookup[w.id]; });
                    let pool = unused;
                    if (starredPool.length && regularPool.length) {
                        // 2:1 bias toward starred, but not front-loading
                        const pickStar = Math.random() < 0.66;
                        pool = pickStar ? starredPool : regularPool;
                    } else if (starredPool.length) {
                        pool = starredPool;
                    } else if (regularPool.length) {
                        pool = regularPool;
                    }
                    target = pool[Math.floor(Math.random() * pool.length)];
                } else {
                    target = unused[Math.floor(Math.random() * unused.length)];
                }
                if (target) {
                    recordPlay(target.id, starredLookup, starMode);
                    didRecordPlay = true;
                }
            } else {
                // Only queued items remain. If multiple categories are in rotation,
                // let another category run while this queue "matures". If this is the
                // only category, fall through to the queue fallback below to avoid
                // stalling on tiny word sets.
                const multipleCategories = Array.isArray(State.categoryNames) && State.categoryNames.length > 1;
                if (multipleCategories && !stayInCategory) {
                    return null;
                }
            }
        }

        // Fallback: if still no target and queue exists, pick from queue but avoid last shown
        if (!target && queue && queue.length) {
            // Try to find a word that isn't the last shown
            let queueCandidate = queue.find(item => {
                if (!item || !item.wordData) return false;
                if (item.wordData.id === State.lastWordShownId) return false;
                return item.forceReplay || canPlayWord(item.wordData.id, starredLookup, starMode);
            });

            if (!queueCandidate && queue.length > 0) {
                // All queue items are the last shown word, or only one word in queue
                // Try to find any other word from the category
                const others = candidateCategory.filter(w => w.id !== State.lastWordShownId && canPlayWord(w.id, starredLookup, starMode));
                if (others.length) {
                    target = others[Math.floor(Math.random() * others.length)];
                } else {
                    // No choice but to use the queue item (only happens with very small word sets)
                    queueCandidate = queue[0];
                }
            }

            if (queueCandidate) {
                target = queueCandidate.wordData;
                const qi = queue.findIndex(it => it.wordData.id === target.id);
                if (queueCandidate.forceReplay && State.practiceForcedReplays) {
                    const key = String(target.id);
                    const val = State.practiceForcedReplays[key];
                    if (val) State.practiceForcedReplays[key] = Math.max(0, val - 1);
                }
                if (qi !== -1) queue.splice(qi, 1);
            }
        }

        if (target) {
            if (!didRecordPlay) {
                recordPlay(target.id, starredLookup, starMode);
                didRecordPlay = true;
            }
            try { target.__categoryName = candidateCategoryName; } catch (_) { /* no-op */ }
            // Update last shown word ID to prevent consecutive duplicates
            State.lastWordShownId = target.id;

            if (State.currentCategoryName !== candidateCategoryName) {
                State.currentCategoryName = candidateCategoryName;
                State.currentCategoryRoundCount = 0;
                root.FlashcardLoader.preloadNextCategories && root.FlashcardLoader.preloadNextCategories();
                root.LLFlashcards.Dom.updateCategoryNameDisplay(State.currentCategoryName);
            }
            State.currentCategory = getActiveWordsByCategory()[candidateCategoryName];
            State.categoryRoundCount[candidateCategoryName] = (State.categoryRoundCount[candidateCategoryName] || 0) + 1;
            State.currentCategoryRoundCount++;
        }
        return target;
    }

    function selectWordFromNextCategory() {
        pruneCompletedCategories();
        let found = null;
        for (let name of State.categoryNames) {
            const w = selectTargetWord(getActiveWordsByCategory()[name], name);
            // Age the round count even when not selected, so queued items can mature
            State.categoryRoundCount[name] = (State.categoryRoundCount[name] || 0) + 1;
            if (w) { found = w; break; }
        }
        if (!found && Array.isArray(State.categoryNames) && State.categoryNames.length) {
            // Nothing left to serve; mark remaining as completed to allow results.
            State.completedCategories = State.completedCategories || {};
            State.categoryNames.forEach(function (name) { State.completedCategories[name] = true; });
            pruneCompletedCategories();
        } else {
            pruneCompletedCategories();
        }
        return found;
    }

    function selectTargetWordAndCategory() {
        let target = null;
        const stayInCategory = shouldCompleteCategoryBeforeSwitch();
        if (!Array.isArray(State.categoryNames) || State.categoryNames.length === 0) {
            return null;
        }
        pruneCompletedCategories();
        if (!Array.isArray(State.categoryNames) || State.categoryNames.length === 0) {
            return null;
        }
        if (State.isFirstRound) {
            if (!State.firstCategoryName) {
                State.firstCategoryName = State.categoryNames[Math.floor(Math.random() * State.categoryNames.length)];
            }
            target = selectTargetWord(getActiveWordsByCategory()[State.firstCategoryName], State.firstCategoryName);
            State.currentCategoryName = State.firstCategoryName;
            State.currentCategory = getActiveWordsByCategory()[State.currentCategoryName];
        } else {
            const queue = State.categoryRepetitionQueues[State.currentCategoryName];
            const hasReadyFromQueue = Array.isArray(queue) && queue.some(function (item) {
                return item.reappearRound <= (State.categoryRoundCount[State.currentCategoryName] || 0);
            });
            const hasPendingQueue = Array.isArray(queue) && queue.length > 0;
            const starredLookup = getStarredLookup();
            const starMode = getStarMode();
            const hasUnusedInCurrent = getAvailableUnusedWords(State.currentCategoryName, starredLookup, starMode).length > 0;
            const multipleCategories = Array.isArray(State.categoryNames) && State.categoryNames.length > 1;
            if (hasReadyFromQueue || !multipleCategories || stayInCategory || State.currentCategoryRoundCount <= State.ROUNDS_PER_CATEGORY) {
                target = selectTargetWord(State.currentCategory, State.currentCategoryName);
            } else if (hasPendingQueue || hasUnusedInCurrent) {
                // Move this category to the end to let others run, but keep it in rotation for queued wrong answers or unused words.
                const i = State.categoryNames.indexOf(State.currentCategoryName);
                if (i > -1) {
                    State.categoryNames.splice(i, 1);
                    State.categoryNames.push(State.currentCategoryName);
                }
                State.categoryRoundCount[State.currentCategoryName] = (State.categoryRoundCount[State.currentCategoryName] || 0) + 1;
                State.currentCategoryRoundCount = (State.currentCategoryRoundCount || 0) + 1;
            } else {
                const i = State.categoryNames.indexOf(State.currentCategoryName);
                if (i > -1) {
                    State.categoryNames.splice(i, 1);
                }
                State.categoryRoundCount[State.currentCategoryName] = 0;
                State.currentCategoryRoundCount = 0;
                if (!State.categoryNames.length) {
                    return null;
                }
                const nextName = State.categoryNames[0];
                target = selectTargetWord(getActiveWordsByCategory()[nextName], nextName);
            }
        }
        if (!target) {
            // Age the current category so queued items can become ready.
            const cname = State.currentCategoryName;
            if (cname) {
                State.categoryRoundCount[cname] = (State.categoryRoundCount[cname] || 0) + 1;
                State.currentCategoryRoundCount = (State.currentCategoryRoundCount || 0) + 1;
                const queue = State.categoryRepetitionQueues[cname];
                const starredLookup = getStarredLookup();
                const starMode = getStarMode();
                const hasUnused = getAvailableUnusedWords(cname, starredLookup, starMode).length > 0;
                const hasQueue = queue && queue.length;
                if (!hasUnused && !hasQueue) {
                    State.completedCategories[cname] = true;
                }
            }
            pruneCompletedCategories();
            target = selectWordFromNextCategory();
        }
        pruneCompletedCategories();
        if (!target) {
            // No target anywhere; mark all remaining categories as done so results can show.
            if (Array.isArray(State.categoryNames)) {
                State.completedCategories = State.completedCategories || {};
                State.categoryNames.forEach(function (name) { State.completedCategories[name] = true; });
            }
            pruneCompletedCategories();
            return null;
        }
        return target;
    }

    /**
     * Learning Mode: Select next word to introduce or quiz
     */
    function selectLearningModeWord() {
        // Delegates to module to avoid mixing mode-specific logic here
        return window.LLFlashcards && window.LLFlashcards.Modes && window.LLFlashcards.Modes.Learning
            ? window.LLFlashcards.Modes.Learning.selectTargetWord()
            : null;
    }

    /**
     * Initialize learning mode word list
     */
    function initializeLearningMode() {
        return window.LLFlashcards && window.LLFlashcards.Modes && window.LLFlashcards.Modes.Learning
            ? window.LLFlashcards.Modes.Learning.initialize()
            : false;
    }

    function fillGenderQuizOptions(targetWord, config, targetCategoryName) {
        const $ = root.jQuery;
        if (!$ || !targetWord) return false;
        if (!isGenderModeEnabled()) return false;

        const genderOptions = getGenderOptions();
        const genderLabel = normalizeGenderValue(targetWord.grammatical_gender, genderOptions) || targetWord.__gender_label || '';
        if (!genderLabel || genderOptions.length < 2) return false;
        const msgs = root.llToolsFlashcardsMessages || {};
        const dontKnowLabel = String(msgs.genderDontKnow || "I don't know");

        const promptType = getCategoryPromptType(targetCategoryName);
        State.currentOptionType = 'text';
        State.currentPromptType = promptType;
        renderPrompt(targetWord, config);

        const $container = $('#ll-tools-flashcard');
        const $content = $('#ll-tools-flashcard-content');
        $container.removeClass('audio-line-layout').addClass('ll-gender-options-layout');
        $content.removeClass('audio-line-mode').addClass('ll-gender-options-mode');
        if ($container.length && $container[0] && $container[0].style) {
            try { $container[0].style.setProperty('--ll-gender-layout-scale', '1'); } catch (_) { /* no-op */ }
        }
        if ($content.length && $content[0] && $content[0].style) {
            try { $content[0].style.setProperty('--ll-gender-layout-scale', '1'); } catch (_) { /* no-op */ }
            try { $content[0].style.setProperty('--ll-gender-safe-bottom', '0px'); } catch (_) { /* no-op */ }
        }
        $('#ll-tools-prompt img').off('load.llGenderFit').on('load.llGenderFit', function () {
            scheduleGenderOptionTextScaleFit();
        });

        let optionIndex = 0;
        genderOptions.forEach(function (label) {
            const visual = getGenderVisualForOption(label, optionIndex, genderOptions);
            const normalized = normalizeGenderValue(label, genderOptions) || visual.value;
            const isCorrect = normalized && normalized === genderLabel;
            const optionId = isCorrect ? targetWord.id : (String(targetWord.id) + '-gender-' + optionIndex);
            const optionWord = {
                id: optionId,
                title: visual.label || formatGenderDisplayLabel(label),
                label: visual.label || formatGenderDisplayLabel(label)
            };
            const $card = root.LLFlashcards.Cards.appendWordToContainer(optionWord, 'text', promptType, true);
            $card.addClass('ll-gender-option');
            $card.attr('data-ll-gender-choice', normalized || '')
                .attr('data-ll-gender-correct', isCorrect ? '1' : '0')
                .attr('data-ll-gender-unknown', '0')
                .attr('aria-label', visual.label || '')
                .attr('title', visual.label || '');
            applyGenderVisualToOptionCard($card, visual);
            root.LLFlashcards.Cards.addClickEventToCard($card, optionIndex, targetWord, 'text', promptType);
            optionIndex += 1;
        });

        const unknownWord = {
            id: String(targetWord.id) + '-gender-unknown',
            title: dontKnowLabel,
            label: dontKnowLabel
        };
        const $unknownCard = root.LLFlashcards.Cards.appendWordToContainer(unknownWord, 'text', promptType, true);
        $unknownCard.addClass('ll-gender-option ll-gender-option--unknown')
            .attr('data-ll-gender-choice', '')
            .attr('data-ll-gender-correct', '0')
            .attr('data-ll-gender-unknown', '1')
            .attr('data-ll-gender-role', 'unknown');
        root.LLFlashcards.Cards.addClickEventToCard($unknownCard, optionIndex, targetWord, 'text', promptType);

        ensureGenderOptionFitResizeHandler();
        scheduleGenderOptionTextScaleFit();
        $(document).trigger('ll-tools-options-ready');
        return true;
    }

    function fillQuizOptions(targetWord) {
        let chosen = [];
        const $layoutContainer = jQuery('#ll-tools-flashcard');
        const $layoutContent = jQuery('#ll-tools-flashcard-content');
        $layoutContainer.removeClass('ll-gender-options-layout');
        $layoutContent.removeClass('ll-gender-options-mode ll-gender-layout-compact');
        if ($layoutContainer.length && $layoutContainer[0] && $layoutContainer[0].style) {
            try { $layoutContainer[0].style.removeProperty('--ll-gender-layout-scale'); } catch (_) { /* no-op */ }
        }
        if ($layoutContent.length && $layoutContent[0] && $layoutContent[0].style) {
            try { $layoutContent[0].style.removeProperty('--ll-gender-layout-scale'); } catch (_) { /* no-op */ }
            try { $layoutContent[0].style.removeProperty('--ll-gender-safe-bottom'); } catch (_) { /* no-op */ }
        }

        const targetCategoryName = getTargetCategoryName(targetWord) || State.currentCategoryName;
        if (targetCategoryName && targetCategoryName !== State.currentCategoryName) {
            State.currentCategoryName = targetCategoryName;
            State.currentCategory = getActiveWordsByCategory()[targetCategoryName] || State.currentCategory;
            try { root.LLFlashcards.Dom.updateCategoryNameDisplay(targetCategoryName); } catch (_) { /* no-op */ }
        }

        const config = getCategoryConfig(targetCategoryName);
        if (State.isGenderMode && fillGenderQuizOptions(targetWord, config, targetCategoryName)) {
            return;
        }
        const mode = config.option_type || getCategoryDisplayMode(targetCategoryName);
        const promptType = getCategoryPromptType(targetCategoryName);
        State.currentOptionType = mode;
        State.currentPromptType = promptType;
        const isTextOptionMode = (mode === 'text' || mode === 'text_title' || mode === 'text_translation' || mode === 'text_audio');
        const seenOptionTexts = isTextOptionMode ? new Set() : null;
        renderPrompt(targetWord, config);

        const isAudioLineLayout = (promptType === 'image') && (mode === 'audio' || mode === 'text_audio');
        const $container = jQuery('#ll-tools-flashcard');
        const $content = jQuery('#ll-tools-flashcard-content');
        $container.toggleClass('audio-line-layout', isAudioLineLayout);
        $content.toggleClass('audio-line-mode', isAudioLineLayout);

        // In learning mode, only select from introduced words.
        // In other modes, keep target selection chunked but allow distractors from the
        // full category pool (including non-starred) so option rules still hold.
        const activeWordsByCategory = getActiveWordsByCategory();
        const optionWordsByCategory = getOptionWordsByCategory();
        let availableWords = [];
        const supplementalWords = [];
        const supplementalLookup = {};
        const appendSupplementalWords = function (list) {
            if (!Array.isArray(list)) return;
            list.forEach(function (word) {
                const wid = parseInt(word && word.id, 10) || 0;
                if (!wid || supplementalLookup[wid]) return;
                supplementalLookup[wid] = true;
                supplementalWords.push(word);
            });
        };

        if (State.isLearningMode) {
            // Collect all introduced words from all categories
            State.categoryNames.forEach(catName => {
                const catWords = activeWordsByCategory[catName] || [];
                catWords.forEach(word => {
                    if (State.introducedWordIDs.includes(word.id)) {
                        availableWords.push(word);
                    }
                });
            });
        } else {
            // Keep same-category distractors first, but don't limit to the session chunk.
            const categoryPool = optionWordsByCategory[targetCategoryName] || activeWordsByCategory[targetCategoryName] || [];
            availableWords.push(...categoryPool);

            // If the current category cannot satisfy minimum options, backfill from
            // other loaded categories as a fallback.
            const selectedNames = Array.isArray(State.categoryNames) ? State.categoryNames.slice() : [];
            selectedNames.forEach(function (name) {
                if (!name || name === targetCategoryName) return;
                appendSupplementalWords(optionWordsByCategory[name] || activeWordsByCategory[name] || []);
            });
            Object.keys(optionWordsByCategory || {}).forEach(function (name) {
                if (!name || name === targetCategoryName) return;
                appendSupplementalWords(optionWordsByCategory[name]);
            });
        }

        const targetGroups = new Set();
        if (targetWord && Array.isArray(targetWord.option_groups)) {
            targetWord.option_groups.forEach(function (grp) {
                const val = (grp !== undefined && grp !== null) ? String(grp).trim() : '';
                if (val) {
                    targetGroups.add(val);
                }
            });
        }

        // Shuffle available words
        availableWords = Util.randomlySort(availableWords);

        if (targetGroups.size) {
            const grouped = [];
            const others = [];
            availableWords.forEach(function (w) {
                const groups = Array.isArray(w && w.option_groups) ? w.option_groups : [];
                let sharesGroup = false;
                for (let i = 0; i < groups.length; i++) {
                    const val = (groups[i] !== undefined && groups[i] !== null) ? String(groups[i]).trim() : '';
                    if (val && targetGroups.has(val)) {
                        sharesGroup = true;
                        break;
                    }
                }
                if (sharesGroup) {
                    grouped.push(w);
                } else {
                    others.push(w);
                }
            });
            if (grouped.length) {
                availableWords = Util.randomlySort(grouped).concat(Util.randomlySort(others));
            }
        }

        // Add target word first
        root.FlashcardLoader.loadResourcesForWord(targetWord, mode, targetCategoryName, config);
        chosen.push(targetWord);
        root.LLFlashcards.Cards.appendWordToContainer(targetWord, mode, promptType);
        if (isTextOptionMode && targetWord) {
            seenOptionTexts.add(getNormalizedOptionText(targetWord));
        }

        // Determine how many options to show
        let targetCount = State.isLearningMode ?
            root.LLFlashcards.LearningMode.getChoiceCount() :
            root.FlashcardOptions.categoryOptionsCount[targetCategoryName];
        if (!State.isLearningMode && (!targetCount || !isFinite(targetCount))) {
            const fallback = (root.FlashcardOptions && typeof root.FlashcardOptions.checkMinMax === 'function')
                ? root.FlashcardOptions.checkMinMax(2, targetCategoryName)
                : 2;
            targetCount = fallback;
            if (root.FlashcardOptions && root.FlashcardOptions.categoryOptionsCount) {
                root.FlashcardOptions.categoryOptionsCount[targetCategoryName] = fallback;
            }
        }

        const MIN_OPTIONS = 2;
        const desiredCount = Math.max(MIN_OPTIONS, parseInt(targetCount, 10) || MIN_OPTIONS);
        const addCandidate = function (candidate, rules) {
            if (!candidate || typeof candidate !== 'object') return false;
            const candidateId = String(candidate.id || '');
            if (!candidateId) return false;

            const enforceSimilarity = !rules || rules.enforceSimilarity !== false;
            const enforceTextUniqueness = !rules || rules.enforceTextUniqueness !== false;
            const enforceConflict = !rules || rules.enforceConflict !== false;

            const isDup = chosen.some(function (word) {
                return String(word && word.id) === candidateId;
            });
            if (isDup) return false;

            if (enforceSimilarity) {
                const isSim = chosen.some(function (word) {
                    const chosenId = String(word && word.id);
                    return String(word && word.similar_word_id) === candidateId
                        || String(candidate.similar_word_id) === chosenId;
                });
                if (isSim) return false;
            }

            const normalizedText = isTextOptionMode ? getNormalizedOptionText(candidate) : '';
            if (isTextOptionMode && enforceTextUniqueness && seenOptionTexts.has(normalizedText)) {
                return false;
            }

            if (enforceConflict) {
                const hasOptionConflict = chosen.some(function (existingWord) {
                    return wordsConflictForOptions(existingWord, candidate);
                });
                if (hasOptionConflict) return false;
            }

            chosen.push(candidate);
            if (isTextOptionMode) {
                seenOptionTexts.add(normalizedText);
            }
            root.FlashcardLoader.loadResourcesForWord(candidate, mode, targetCategoryName, config);
            root.LLFlashcards.Cards.appendWordToContainer(candidate, mode, promptType);
            return true;
        };

        // Fill remaining options from primary category candidates with strict checks.
        for (let candidate of availableWords) {
            if (chosen.length >= desiredCount) break;
            if (!root.FlashcardOptions.canAddMoreCards()) break;
            addCandidate(candidate, {
                enforceSimilarity: true,
                enforceTextUniqueness: true,
                enforceConflict: true
            });
        }

        // If needed, backfill from same-category + cross-category candidates while
        // preserving duplicate/similarity/text safeguards but relaxing pair conflicts.
        if (chosen.length < desiredCount) {
            const relaxedPool = Util.randomlySort(availableWords.concat(supplementalWords));
            for (let candidate of relaxedPool) {
                if (chosen.length >= desiredCount) break;
                if (!root.FlashcardOptions.canAddMoreCards()) break;
                addCandidate(candidate, {
                    enforceSimilarity: true,
                    enforceTextUniqueness: true,
                    enforceConflict: false
                });
            }
        }

        // Hard fallback to guarantee minimum option count when possible.
        if (chosen.length < MIN_OPTIONS) {
            const hardPool = Util.randomlySort(availableWords.concat(supplementalWords));
            for (let candidate of hardPool) {
                if (chosen.length >= MIN_OPTIONS) break;
                if (!root.FlashcardOptions.canAddMoreCards()) break;
                addCandidate(candidate, {
                    enforceSimilarity: false,
                    enforceTextUniqueness: false,
                    enforceConflict: false
                });
            }
        }

        jQuery('.flashcard-container').each(function (idx) {
            root.LLFlashcards.Cards.addClickEventToCard(jQuery(this), idx, targetWord, mode, promptType);
        });

        const publishOptionsReady = function () {
            jQuery(document).trigger('ll-tools-options-ready');
        };

        const alignAudioLineWidths = function () {
            const $cards = jQuery('.flashcard-container.audio-option.audio-line-option.text-audio-option');
            if (State.currentPromptType !== 'image' || State.currentOptionType !== 'text_audio' || !$cards.length) {
                $cards.css('width', '');
                return 0;
            }
            let maxWidth = 0;
            $cards.each(function () {
                const $card = jQuery(this);
                $card.css('width', '');
                const rect = this.getBoundingClientRect();
                maxWidth = Math.max(maxWidth, Math.ceil(rect.width));
            });
            const minWidth = 240;
            const vwCap = Math.max(minWidth, Math.floor((typeof window !== 'undefined' ? window.innerWidth : 0) * 0.95) || minWidth);
            const hardCap = Math.min(vwCap, 720);
            maxWidth = Math.max(minWidth, Math.min(maxWidth, hardCap));
            if (maxWidth > 0) {
                $cards.css('width', maxWidth + 'px');
            }
            return maxWidth;
        };

        const shrinkAudioLineText = function () {
            const $labels = jQuery('.flashcard-container.audio-option.audio-line-option.text-audio-option .ll-audio-option-label');
            if (State.currentPromptType !== 'image' || State.currentOptionType !== 'text_audio' || !$labels.length) {
                $labels.css('font-size', '');
                return;
            }
            const MIN_FS = 12;
            $labels.each(function () {
                const $label = jQuery(this);
                $label.css('font-size', '');
                const base = parseFloat((window.getComputedStyle && window.getComputedStyle(this).fontSize) || '') || 17;
                let fs = base;
                for (let i = 0; i < 8 && fs > MIN_FS && this.scrollWidth > this.clientWidth; i++) {
                    fs -= 1;
                    $label.css('font-size', fs + 'px');
                }
            });
        };

        const revealOptions = function () {
            const isAudioLineTextAudio = (State.currentPromptType === 'image' && State.currentOptionType === 'text_audio');
            const $all = jQuery('.flashcard-container');
            if (!isAudioLineTextAudio) {
                $all.hide().fadeIn(600, publishOptionsReady);
                return;
            }
            const $wrap = jQuery('#ll-tools-flashcard');
            $wrap.css({ visibility: 'hidden', opacity: 0 });
            $all.css({ display: '', opacity: 1, visibility: 'visible' });
            alignAudioLineWidths();
            shrinkAudioLineText();
            // Allow layout to settle before reveal
            const show = function () {
                $wrap.css('visibility', 'visible').fadeTo(200, 1, publishOptionsReady);
            };
            if (typeof requestAnimationFrame === 'function') requestAnimationFrame(show);
            else setTimeout(show, 0);
        };

        revealOptions();
    }

    window.LLFlashcards = window.LLFlashcards || {};
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Selection = {
        getCategoryConfig, getCategoryDisplayMode, getCurrentDisplayMode, getCategoryPromptType, getTargetCategoryName, categoryRequiresAudio, isLearningSupportedForCategories, isGenderSupportedForCategories,
        selectTargetWordAndCategory, fillQuizOptions, wordsConflictForOptions,
        getGenderOptions, normalizeGenderValue, getGenderVisualForOption, buildGenderSymbolMarkup, applyGenderStyleVariables,
        selectLearningModeWord, initializeLearningMode, renderPrompt
    };

    // legacy exports
    root.getCategoryDisplayMode = getCategoryDisplayMode;
    root.getCurrentDisplayMode = getCurrentDisplayMode;
    root.getCategoryConfig = getCategoryConfig;
    root.getCategoryPromptType = getCategoryPromptType;
})(window);
