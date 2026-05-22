(function () {
    function getSwitcher(element) {
        return element ? element.closest('[data-ll-language-switcher]') : null;
    }

    function getModal(switcher) {
        return switcher ? switcher.querySelector('[data-ll-language-switcher-modal]') : null;
    }

    function setExpanded(switcher, expanded) {
        var trigger = switcher ? switcher.querySelector('[data-ll-language-switcher-open]') : null;
        if (trigger) {
            trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    function openModal(trigger) {
        var switcher = getSwitcher(trigger);
        var modal = getModal(switcher);
        if (!switcher || !modal) {
            return;
        }

        switcher.llLanguageSwitcherReturnFocus = trigger;
        modal.hidden = false;
        document.documentElement.classList.add('ll-lang-switcher-modal-open');
        setExpanded(switcher, true);

        var focusTarget = modal.querySelector('[aria-current="true"], a, button');
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }
    }

    function closeModal(switcher) {
        var modal = getModal(switcher);
        if (!switcher || !modal || modal.hidden) {
            return;
        }

        modal.hidden = true;
        setExpanded(switcher, false);

        if (!document.querySelector('[data-ll-language-switcher-modal]:not([hidden])')) {
            document.documentElement.classList.remove('ll-lang-switcher-modal-open');
        }

        var returnFocus = switcher.llLanguageSwitcherReturnFocus;
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

    document.addEventListener('click', function (event) {
        if (!event.target || typeof event.target.closest !== 'function') {
            return;
        }

        var openTrigger = event.target.closest('[data-ll-language-switcher-open]');
        if (openTrigger) {
            event.preventDefault();
            openModal(openTrigger);
            return;
        }

        var closeTrigger = event.target.closest('[data-ll-language-switcher-close]');
        if (closeTrigger) {
            event.preventDefault();
            closeModal(getSwitcher(closeTrigger));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('[data-ll-language-switcher-modal]:not([hidden])').forEach(function (modal) {
            closeModal(getSwitcher(modal));
        });
    });
}());
