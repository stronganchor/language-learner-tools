// Global, lazy stub so inline popup code can call initFlashcardWidget even if the real scripts
// haven’t been loaded yet on the host page. It patiently waits, then forwards the call.
(function () {
    function startWidget(selectedCategories) {
        (function wait() {
            try {
                if (
                    window.LLFlashcards &&
                    window.LLFlashcards.Main &&
                    typeof window.LLFlashcards.Main.initFlashcardWidget === "function"
                ) {
                    window.LLFlashcards.Main.initFlashcardWidget(selectedCategories);
                    return;
                }
                if (typeof window.initFlashcardWidget_real === "function") {
                    window.initFlashcardWidget_real(selectedCategories);
                    return;
                }
            } catch (e) { }
            setTimeout(wait, 30);
        })();
    }

    // Only install our forwarder if a different implementation isn't already present
    if (
        typeof window.initFlashcardWidget !== "function" ||
        String(window.initFlashcardWidget).indexOf("startWidget") === -1
    ) {
        window.initFlashcardWidget = startWidget;
    }
})();

// Small helper to read categories localized by PHP for the popup grid
function llSelectedCategoriesFromLocalization() {
    var d = window.llToolsFlashcardsData;
    return d && Array.isArray(d.categories)
        ? d.categories.map(function (c) {
            return c.name;
        })
        : [];
}

// Non-popup (iframe) quality-of-life: manage loader + ensure VH
document.addEventListener("DOMContentLoaded", function () {
    var wrappers = document.querySelectorAll(".ll-tools-quiz-iframe-wrapper");
    wrappers.forEach(function (wrapper) {
        var iframe = wrapper.querySelector(".ll-tools-quiz-iframe");
        var loader = wrapper.querySelector(".ll-tools-iframe-loading");
        if (!iframe || !loader) return;

        function hide() {
            loader.style.display = "none";
        }
        iframe.addEventListener("load", hide);
        iframe.addEventListener("error", hide);
        setTimeout(hide, 3000);

        try {
            var vh = (window.llQuizPages && parseInt(llQuizPages.vh, 10)) || 95;
            iframe.style.height = vh + "vh";
            iframe.style.minHeight = vh + "vh";
            wrapper.style.minHeight = vh + "vh";
        } catch (e) { }
    });
});

// Popup entry point used by the quiz-pages grid
// Exposed globally because the grid’s category tiles call it directly.
window.llOpenFlashcardForCategory =
    window.llOpenFlashcardForCategory ||
    function (catName) {
        if (!catName) return;

        try {
            // Ensure overlay DOM is visible if present
            var container = document.getElementById("ll-tools-flashcard-container");
            if (container) {
                container.style.display = "block";
            }
            var popup = document.getElementById("ll-tools-flashcard-popup");
            if (popup) {
                popup.style.display = "block";
            }
            var quizPopup = document.getElementById("ll-tools-flashcard-quiz-popup");
            if (quizPopup) {
                quizPopup.style.display = "block";
            }

            // Some templates default the header to display:none; make sure it shows
            var header = document.getElementById("ll-tools-flashcard-header");
            if (header) header.style.display = "";

            // Body class toggle for background scroll lock, etc.
            if (document.body && document.body.classList) {
                document.body.classList.add("ll-tools-flashcard-open");
            }

            // Kick off the new or legacy widget with the requested category
            var hasNew =
                window.LLFlashcards &&
                window.LLFlashcards.Main &&
                typeof window.LLFlashcards.Main.initFlashcardWidget === "function";
            var hasLegacy = typeof window.initFlashcardWidget === "function";

            // Prepare the categories array; fall back to whatever is localized
            var cats = [catName];
            if (!catName && hasNew) {
                cats = llSelectedCategoriesFromLocalization();
            }

            if (hasNew) {
                window.LLFlashcards.Main.initFlashcardWidget(cats);
            } else if (hasLegacy) {
                window.initFlashcardWidget(cats);
            }

            // Optional: temporarily lock clicks until audio has started a bit
            try {
                var $ = window.jQuery;
                if ($) {
                    $("#ll-tools-flashcard").css("pointer-events", "none");
                    var obs = new MutationObserver(function (_, observer) {
                        var audio = document.querySelector("#ll-tools-flashcard audio");
                        if (!audio) return;
                        observer.disconnect();
                        function onTU() {
                            if (this.currentTime > 0.4) {
                                $("#ll-tools-flashcard").css("pointer-events", "auto");
                                audio.removeEventListener("timeupdate", onTU);
                            }
                        }
                        audio.addEventListener("timeupdate", onTU);
                    });
                    obs.observe(document.getElementById("ll-tools-flashcard"), {
                        childList: true,
                        subtree: true,
                    });
                }
            } catch (_) { }
        } catch (e) {
            // Swallow errors to avoid breaking the page UI in case of theme conflicts
            // but still log for debugging.
            try {
                console.error("llOpenFlashcardForCategory error:", e);
            } catch (_) { }
        }
    };
