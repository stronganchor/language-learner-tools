(function () {
  'use strict';

  var config = window.llToolsSiteSyncAdmin || {};
  var strings = config.strings || {};

  function t(key, fallback) {
    return strings[key] || fallback;
  }

  function setLoading(container) {
    container.setAttribute('aria-busy', 'true');
    container.classList.remove('ll-site-sync-local-overview--error');
    var retry = container.querySelector('.ll-site-sync-retry-overview');
    if (retry) {
      retry.hidden = true;
    }
    var state = container.querySelector('[data-ll-site-sync-overview-state]');
    if (!state) {
      state = document.createElement('div');
      state.className = 'll-site-sync-loading';
      state.setAttribute('role', 'status');
      state.setAttribute('aria-live', 'polite');
      state.setAttribute('data-ll-site-sync-overview-state', '1');
      container.appendChild(state);
    }
    state.className = 'll-site-sync-loading';
    state.innerHTML = '<span class="spinner is-active" aria-hidden="true"></span><span></span>';
    var label = state.querySelector('span:last-child');
    if (label) {
      label.textContent = t('loadingOverview', 'Checking local changes in the background...');
    }
  }

  function setError(container, message) {
    container.setAttribute('aria-busy', 'false');
    container.classList.add('ll-site-sync-local-overview--error');
    var state = container.querySelector('[data-ll-site-sync-overview-state]');
    if (!state) {
      state = document.createElement('div');
      state.setAttribute('data-ll-site-sync-overview-state', '1');
      container.appendChild(state);
    }
    state.className = 'll-site-sync-error-state';
    state.textContent = message || t('overviewFailed', 'Local change overview could not load.');

    var retry = container.querySelector('.ll-site-sync-retry-overview');
    if (!retry) {
      retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'button ll-site-sync-retry-overview';
      retry.textContent = t('retry', 'Retry');
      container.appendChild(retry);
    }
    retry.hidden = false;
  }

  function loadOverview(container) {
    if (!container || !config.ajaxUrl || !config.localOverviewNonce) {
      return;
    }

    setLoading(container);

    var body = new URLSearchParams();
    body.set('action', 'll_tools_site_sync_local_overview');
    body.set('nonce', config.localOverviewNonce);

    window.fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success || !payload.data || !payload.data.html) {
          var message = payload && payload.data && payload.data.message ? payload.data.message : '';
          throw new Error(message || t('overviewFailed', 'Local change overview could not load.'));
        }
        container.outerHTML = payload.data.html;
      })
      .catch(function (error) {
        setError(container, error && error.message ? error.message : '');
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var container = document.querySelector('[data-ll-site-sync-local-overview]');
    if (!container) {
      return;
    }

    container.addEventListener('click', function (event) {
      if (event.target && event.target.closest('.ll-site-sync-retry-overview')) {
        loadOverview(container);
      }
    });

    loadOverview(container);
  });
}());
