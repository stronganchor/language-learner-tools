(function () {
    var dropdownResizeFrame = 0;
    var dropdownViewportGutter = 12;
    var dropdownMinimumHeight = 160;

    function getSwitcher(element) {
        return element ? element.closest('[data-ll-language-switcher]') : null;
    }

    function getModal(switcher) {
        return switcher ? switcher.querySelector('[data-ll-language-switcher-modal]') : null;
    }

    function setFloatingListHeight(list) {
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        if (!list || viewportHeight <= 0) {
            return;
        }

        var rect = list.getBoundingClientRect();
        var availableHeight = Math.floor(viewportHeight - rect.top - dropdownViewportGutter);
        if (availableHeight > 0) {
            list.style.setProperty(
                '--ll-lang-switcher-dropdown-max-height',
                Math.max(dropdownMinimumHeight, availableHeight) + 'px'
            );
        }
    }

    function setExpanded(switcher, expanded) {
        var trigger = switcher ? switcher.querySelector('[data-ll-language-switcher-open]') : null;
        if (trigger) {
            trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    function updateDropdownHeight(switcher) {
        var details = switcher ? switcher.querySelector('.ll-lang-switcher__details') : null;
        var list = switcher ? switcher.querySelector('.ll-lang-switcher__list') : null;
        if (!switcher) {
            return;
        }

        if (details && list) {
            if (!details.hasAttribute('open')) {
                list.style.removeProperty('--ll-lang-switcher-dropdown-max-height');
            }

            if (details.hasAttribute('open')) {
                setFloatingListHeight(list);
            }
        }

        switcher.querySelectorAll('.ll-lang-switcher__secondary-list').forEach(function (secondaryList) {
            if (secondaryList.closest('.ll-lang-switcher__secondary[open]')) {
                setFloatingListHeight(secondaryList);
            } else {
                secondaryList.style.removeProperty('--ll-lang-switcher-dropdown-max-height');
            }
        });
    }

    function updateOpenDropdownHeights() {
        document.querySelectorAll('[data-ll-language-switcher]').forEach(function (switcher) {
            updateDropdownHeight(switcher);
        });
    }

    function scheduleOpenDropdownHeightUpdate() {
        if (dropdownResizeFrame) {
            return;
        }

        dropdownResizeFrame = window.requestAnimationFrame(function () {
            dropdownResizeFrame = 0;
            updateOpenDropdownHeights();
        });
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
            return;
        }

        if (
            event.target.closest('.ll-lang-switcher__details > .ll-lang-switcher__summary')
            || event.target.closest('.ll-lang-switcher__secondary > .ll-lang-switcher__secondary-summary')
        ) {
            scheduleOpenDropdownHeightUpdate();
        }
    });

    document.addEventListener('toggle', function (event) {
        if (
            !event.target
            || !event.target.classList
            || (
                !event.target.classList.contains('ll-lang-switcher__details')
                && !event.target.classList.contains('ll-lang-switcher__secondary')
            )
        ) {
            return;
        }

        scheduleOpenDropdownHeightUpdate();
    }, true);

    window.addEventListener('resize', scheduleOpenDropdownHeightUpdate);
    window.addEventListener('orientationchange', scheduleOpenDropdownHeightUpdate);

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            if (
                (event.key === 'Enter' || event.key === ' ')
                && event.target
                && event.target.closest
                && (
                    event.target.closest('.ll-lang-switcher__details > .ll-lang-switcher__summary')
                    || event.target.closest('.ll-lang-switcher__secondary > .ll-lang-switcher__secondary-summary')
                )
            ) {
                scheduleOpenDropdownHeightUpdate();
            }
            return;
        }

        document.querySelectorAll('[data-ll-language-switcher-modal]:not([hidden])').forEach(function (modal) {
            closeModal(getSwitcher(modal));
        });
    });
}());
