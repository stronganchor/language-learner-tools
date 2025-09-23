(function (root) {
    'use strict';
    const Util = {
        randomlySort(arr) { return Array.isArray(arr) ? [...arr].sort(() => 0.5 - Math.random()) : arr; },
        randomInt(min, max) { return Math.floor((Math.random() * (max - min + 1)) + min); },
        measureTextWidth: (function () {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            return function (text, cssFont) { ctx.font = cssFont; return ctx.measureText(text).width; };
        })(),
    };
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Util = Util;
})(window);
