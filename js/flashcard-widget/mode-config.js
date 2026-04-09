(function (root) {
    'use strict';
    const Util = (root.LLFlashcards && root.LLFlashcards.Util) || {};

    function getMessage(key, fallback) {
        if (Util && typeof Util.getMessage === 'function') {
            return Util.getMessage(key, fallback);
        }
        return String(fallback || '').trim();
    }

    const DEFAULTS = {
        practice: {
            icon: '❓',
            className: 'practice-mode',
            switchLabel: getMessage('practiceSwitchLabel'),
            resultsButtonText: getMessage('practiceModeText'),
            modeLabel: getMessage('practiceModeShort'),
        },
        learning: {
            icon: '🎓',
            className: 'learning-mode',
            switchLabel: getMessage('learningSwitchLabel'),
            resultsButtonText: getMessage('learningModeText'),
            modeLabel: getMessage('learningModeShort'),
        },
        'self-check': {
            icon: '',
            className: 'self-check-mode',
            switchLabel: getMessage('selfCheckSwitchLabel'),
            resultsButtonText: getMessage('selfCheckModeText'),
            modeLabel: getMessage('selfCheckModeShort'),
        },
        listening: {
            icon: '🎧',
            className: 'listening-mode',
            switchLabel: getMessage('listeningSwitchLabel'),
            resultsButtonText: getMessage('listeningModeText'),
            modeLabel: getMessage('listeningModeShort'),
        },
        gender: {
            icon: '',
            className: 'gender-mode',
            switchLabel: getMessage('genderSwitchLabel'),
            resultsButtonText: getMessage('genderModeText'),
            modeLabel: getMessage('genderModeShort'),
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
        const derivedLabel = cfg.switchLabel || cfg.resultsButtonText || cfg.modeLabel || '';
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
