(function (root) {
    'use strict';

    const DEFAULTS = {
        practice: {
            icon: '❓',
            className: 'practice-mode',
            switchLabel: 'Switch to Practice Mode',
            resultsButtonText: 'Practice Mode',
        },
        learning: {
            icon: '🎓',
            className: 'learning-mode',
            switchLabel: 'Switch to Learning Mode',
            resultsButtonText: 'Learning Mode',
        },
        'self-check': {
            icon: '',
            className: 'self-check-mode',
            switchLabel: 'Open Self Check',
            resultsButtonText: 'Self Check',
        },
        listening: {
            icon: '🎧',
            className: 'listening-mode',
            switchLabel: 'Switch to Listening Mode',
            resultsButtonText: 'Listen',
        },
        gender: {
            icon: '',
            className: 'gender-mode',
            switchLabel: 'Switch to Gender',
            resultsButtonText: 'Gender',
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
