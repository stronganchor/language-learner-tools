// Global, lazy stub so inline popup code can call initFlashcardWidget even if the real scripts
// haven’t been loaded yet on the host page. It patiently waits, then forwards the call.
(function () {
    function startWidget(selectedCategories) {
        (function wait() {
            try {
                if (window.LLFlashcards && window.LLFlashcards.Main && typeof window.LLFlashcards.Main.initFlashcardWidget === 'function') {
                    window.LLFlashcards.Main.initFlashcardWidget(selectedCategories);
                    return;
                }
                if (typeof window.initFlashcardWidget_real === 'function') {
                    window.initFlashcardWidget_real(selectedCategories);
                    return;
                }
            } catch (e) { }
            setTimeout(wait, 30);
        })();
    }
    if (typeof window.initFlashcardWidget !== 'function' ||
        String(window.initFlashcardWidget).indexOf('startWidget') === -1) {
        window.initFlashcardWidget = startWidget;
    }
})();

document.addEventListener('DOMContentLoaded', function () {
    var wrappers = document.querySelectorAll('.ll-tools-quiz-iframe-wrapper');
    wrappers.forEach(function (wrapper) {
        var iframe = wrapper.querySelector('.ll-tools-quiz-iframe');
        var loader = wrapper.querySelector('.ll-tools-iframe-loading');
        if (!iframe || !loader) return;

        function hide() { loader.style.display = 'none'; }
        iframe.addEventListener('load', hide);
        iframe.addEventListener('error', hide);
        setTimeout(hide, 3000);

        try {
            var vh = (window.llQuizPages && parseInt(llQuizPages.vh, 10)) || 95;
            iframe.style.height = vh + 'vh';
            iframe.style.minHeight = vh + 'vh';
            wrapper.style.minHeight = vh + 'vh';
        } catch (e) { }
    });
});
// Fallback global in case the footer helper isn't printed (popup mode off)
window.llOpenFlashcardForCategory = window.llOpenFlashcardForCategory || function (catName) {
    if (!catName) return;
    try {
        // ensure overlay is visible if the shell exists
        var container = document.getElementById('ll-tools-flashcard-container');
        if (container) {
            container.style.display = 'block';
            var p1 = document.getElementById('ll-tools-flashcard-popup');
            var p2 = document.getElementById('ll-tools-flashcard-quiz-popup');
            if (p1) p1.style.display = 'block';
            if (p2) p2.style.display = 'block';
            document.body.classList.add('ll-tools-flashcard-open');
        }
        // new or legacy init
        if (window.LLFlashcards?.Main?.initFlashcardWidget) {
            LLFlashcards.Main.initFlashcardWidget([catName]);
        } else if (typeof window.initFlashcardWidget === 'function') {
            window.initFlashcardWidget([catName]);
        }
    } catch (e) { console.error('llOpenFlashcardForCategory failed', e); }
};
