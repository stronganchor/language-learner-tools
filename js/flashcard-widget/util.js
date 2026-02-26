(function (root) {
    'use strict';
    const Util = {
        randomlySort(arr) { return Array.isArray(arr) ? [...arr].sort(() => 0.5 - Math.random()) : arr; },
        randomInt(min, max) { return Math.floor((Math.random() * (max - min + 1)) + min); },
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
