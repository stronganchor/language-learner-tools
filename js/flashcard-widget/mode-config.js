(function (root) {
    'use strict';

    // Inline SVG defaults to avoid theme/webfont emoji differences
    const ICON_SIZE = 26;
    const SVG_COLOR = 'currentColor';
    const ICONS = {
        practice: `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 24 24" fill="${SVG_COLOR}" aria-hidden="true"><path d="M11 18h2v2h-2zM12 2C7.59 2 4 5.59 4 10h2a6 6 0 1112 0c0 2.76-2.24 5-5 5h-1v3h2v-1.83c3.39-.49 6-3.39 6-6.92C20 5.03 16.42 2 12 2z"/></svg>`,
        learning: `<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 24 24" fill="${SVG_COLOR}" aria-hidden="true"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/><path d="M7 12.5V17l5 2.73L17 17v-4.5l-5 2.73-5-2.73z"/></svg>`,
        listening:`<svg width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 24 24" fill="${SVG_COLOR}" aria-hidden="true"><path d="M12 3a7 7 0 00-7 7v7a4 4 0 004 4h1v-2H9a2 2 0 01-2-2v-7a5 5 0 1110 0v7a2 2 0 01-2 2h-1v2h1a4 4 0 004-4v-7a7 7 0 00-7-7z"/></svg>`
    };

    const DEFAULTS = {
        practice: {
            icon: '❓',
            svg: ICONS.practice,
            className: 'practice-mode',
            switchLabel: 'Switch to Practice Mode',
            resultsButtonText: 'Practice Mode',
        },
        learning: {
            icon: '🎓',
            svg: ICONS.learning,
            className: 'learning-mode',
            switchLabel: 'Switch to Learning Mode',
            resultsButtonText: 'Learning Mode',
        },
        listening: {
            icon: '🎧',
            svg: ICONS.listening,
            className: 'listening-mode',
            switchLabel: 'Switch to Listening Mode',
            resultsButtonText: 'Replay Listening',
        },
    };

    const localized = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.modeUi) || {};
    const merged = {};

    Object.keys(DEFAULTS).forEach(mode => {
        merged[mode] = Object.assign({}, DEFAULTS[mode], localized[mode] || {});
    });

    Object.keys(localized).forEach(mode => {
        if (!merged[mode]) {
            merged[mode] = Object.assign({
                icon: '',
                className: `${mode}-mode`,
                switchLabel: '',
                resultsButtonText: '',
            }, localized[mode]);
        }
    });

    const switchConfig = {};
    Object.keys(merged).forEach(mode => {
        const cfg = merged[mode] || {};
        const derivedLabel = cfg.switchLabel || `Switch to ${cfg.resultsButtonText || (mode.charAt(0).toUpperCase() + mode.slice(1))}`;
        const className = cfg.className || `${mode}-mode`;

        switchConfig[mode] = {
            label: derivedLabel,
            icon: cfg.icon || '',
            svg: cfg.svg || '',
            className,
        };
    });

    const ModeConfig = {
        getAll() {
            return merged;
        },
        get(mode) {
            return merged[mode] || null;
        },
        getSwitchConfig() {
            return switchConfig;
        },
    };

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.ModeConfig = ModeConfig;
})(window);
