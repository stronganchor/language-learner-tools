/* LL Tools – Quiz Pages (robust popup)
   - Delegated click (no inline JS)
   - Uses data-url if present; otherwise falls back to /quiz?category=...
   - Provides its own modal/iframe if no global llOpenFlashcardForCategory exists
*/
(function () {
    'use strict';

    // -------------------------
    // Minimal modal infrastructure
    // -------------------------
    var overlayEl, modalEl, iframeEl, lastFocus;

    function ensureModal() {
        if (overlayEl) return;

        overlayEl = document.createElement('div');
        overlayEl.className = 'll-quiz-overlay';
        overlayEl.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:999999;';

        modalEl = document.createElement('div');
        modalEl.className = 'll-quiz-modal';
        modalEl.style.cssText = 'background:#111;color:#eee;width:min(1200px,95vw);height:min(800px,90vh);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;border:1px solid rgba(255,255,255,0.1);box-shadow:0 10px 40px rgba(0,0,0,.4);';

        var header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.1);';
        var title = document.createElement('div');
        title.id = 'll-quiz-modal-title';
        title.textContent = 'Quiz';
        title.style.cssText = 'font-weight:600';
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.textContent = '✕';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.style.cssText = 'border:0;background:transparent;color:#eee;font-size:18px;cursor:pointer;padding:6px 8px;';
        closeBtn.addEventListener('click', closeModal);
        header.appendChild(title);
        header.appendChild(closeBtn);

        iframeEl = document.createElement('iframe');
        iframeEl.className = 'll-quiz-iframe';
        iframeEl.setAttribute('loading', 'eager');
        iframeEl.setAttribute('title', 'Quiz Content');
        iframeEl.style.cssText = 'flex:1;width:100%;border:0;background:#000;';

        modalEl.appendChild(header);
        modalEl.appendChild(iframeEl);
        overlayEl.appendChild(modalEl);

        overlayEl.addEventListener('click', function (e) {
            if (e.target === overlayEl) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlayEl && overlayEl.parentNode) closeModal();
        });
    }

    function openModal(url, titleTxt) {
        ensureModal();
        lastFocus = document.activeElement;
        iframeEl.src = url;
        var titleEl = modalEl.querySelector('#ll-quiz-modal-title');
        if (titleEl) titleEl.textContent = titleTxt || 'Quiz';
        document.body.appendChild(overlayEl);
        modalEl.setAttribute('tabindex', '-1');
        modalEl.focus({ preventScroll: true });
    }

    function closeModal() {
        if (!overlayEl) return;
        if (overlayEl.parentNode) overlayEl.parentNode.removeChild(overlayEl);
        if (iframeEl) iframeEl.src = 'about:blank';
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    // -------------------------
    // URL building fallback
    // -------------------------
    function buildFallbackUrl(catName) {
        var basePath = '/quiz';
        var sep = basePath.indexOf('?') === -1 ? '?' : '&';
        return basePath + sep + 'category=' + encodeURIComponent(catName || '');
    }

    // -------------------------
    // Public API (global) — if absent
    // -------------------------
    if (typeof window.llOpenFlashcardForCategory !== 'function') {
        window.llOpenFlashcardForCategory = function (catName, opts) {
            // If a URL is provided via opts, prefer it; otherwise construct a fallback.
            var url = (opts && opts.url) ? String(opts.url) : buildFallbackUrl(catName);
            openModal(url, catName || 'Quiz');
        };
    }

    // -------------------------
    // Delegated click handler
    // -------------------------
    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest('.ll-quiz-page-trigger,[data-ll-open-cat],[data-category]');
        if (!trigger) return;

        // Determine if this is meant to be a popup trigger:
        // - has class ll-quiz-page-trigger, or
        // - href is "#" / empty / javascript:
        var href = (trigger.getAttribute('href') || '').trim();
        var explicitPopup = trigger.classList.contains('ll-quiz-page-trigger');
        var looksPopup = explicitPopup || !href || href === '#' || href.toLowerCase().startsWith('javascript:');
        if (!looksPopup) return; // allow normal navigation for non-popup links

        ev.preventDefault();

        var cat = trigger.getAttribute('data-ll-open-cat') || trigger.getAttribute('data-category') || '';
        var url = trigger.getAttribute('data-url'); // NEW: prefer real permalink if provided
        var title = trigger.querySelector('.ll-quiz-page-name')?.textContent?.trim() || cat || 'Quiz';

        try {
            // If some other script replaced the global with a custom one, still call it:
            window.llOpenFlashcardForCategory(cat, { url: url });
        } catch (e) {
            // Ultimate fallback: navigate
            window.location.href = url || buildFallbackUrl(cat);
        }
    }, false);

})();
