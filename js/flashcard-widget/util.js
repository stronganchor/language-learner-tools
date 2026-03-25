(function (root) {
    'use strict';
    const Util = {
        randomlySort(arr) { return Array.isArray(arr) ? [...arr].sort(() => 0.5 - Math.random()) : arr; },
        randomInt(min, max) { return Math.floor((Math.random() * (max - min + 1)) + min); },
        normalizePromptType(value) {
            return String(value || '').trim().toLowerCase() || 'audio';
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
