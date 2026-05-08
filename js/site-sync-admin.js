(function () {
  'use strict';

  var config = window.llToolsSiteSyncAdmin || {};
  var strings = config.strings || {};
  var passwordStoreKey = 'llToolsSiteSyncRemotePassword';

  function t(key) {
    return strings[key] || '';
  }

  function getPasswordFields() {
    return Array.prototype.slice.call(document.querySelectorAll('input[name="ll_site_sync_remote_password"]'));
  }

  function storePassword(value) {
    try {
      if (value) {
        window.sessionStorage.setItem(passwordStoreKey, value);
      }
    } catch (error) {
      // Session storage can be disabled; the form still works without reuse.
    }
  }

  function getStoredPassword() {
    try {
      return window.sessionStorage.getItem(passwordStoreKey) || '';
    } catch (error) {
      return '';
    }
  }

  function hydratePasswordFields() {
    var stored = getStoredPassword();
    getPasswordFields().forEach(function (field) {
      if (stored && !field.value) {
        field.value = stored;
      }
      field.addEventListener('input', function () {
        storePassword(field.value);
      });
    });

    document.addEventListener('submit', function (event) {
      var field = event.target && event.target.querySelector
        ? event.target.querySelector('input[name="ll_site_sync_remote_password"]')
        : null;
      if (field && field.value) {
        storePassword(field.value);
      }
    }, true);
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
      label.textContent = t('loadingOverview');
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
    state.textContent = message || t('overviewFailed');

    var retry = container.querySelector('.ll-site-sync-retry-overview');
    if (!retry) {
      retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'button ll-site-sync-retry-overview';
      retry.textContent = t('retry');
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
    body.set('page', new URLSearchParams(window.location.search).get('ll_site_sync_local_page') || '1');

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
          throw new Error(message || t('overviewFailed'));
        }
        container.outerHTML = payload.data.html;
      })
      .catch(function (error) {
        setError(container, error && error.message ? error.message : '');
      });
  }

  function initOverview() {
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
  }

  function formToBody(form) {
    var formData = new window.FormData(form);
    var body = new URLSearchParams();
    formData.forEach(function (value, key) {
      body.append(key, value);
    });
    body.set('action', 'll_tools_site_sync_apply_push_batch');
    body.set('nonce', config.applyPushNonce || '');
    body.set('ll_site_sync_action', 'apply_push');
    return body;
  }

  function setApplyStatus(form, message, active) {
    var progress = form.querySelector('[data-ll-site-sync-apply-progress]');
    var status = form.querySelector('[data-ll-site-sync-apply-status]');
    var spinner = form.querySelector('.ll-site-sync-apply-progress .spinner');
    if (progress) {
      progress.hidden = false;
    }
    if (status) {
      status.textContent = message;
    }
    if (spinner) {
      spinner.classList.toggle('is-active', !!active);
    }
  }

  function setApplyMeter(form, processed, remaining, done) {
    var meter = form.querySelector('[data-ll-site-sync-apply-meter]');
    if (!meter) {
      return;
    }

    if (done) {
      meter.max = 100;
      meter.value = 100;
      return;
    }

    var total = Math.max(1, processed + remaining);
    meter.max = total;
    meter.value = Math.min(processed, total);
  }

  function initApplyPush() {
    var form = document.querySelector('[data-ll-site-sync-apply-form]');
    if (!form || !config.ajaxUrl || !config.applyPushNonce) {
      return;
    }

    var button = form.querySelector('[data-ll-site-sync-apply-button]');
    var processed = 0;
    var running = false;

    function runBatch() {
      var body = formToBody(form);

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
          if (!payload || !payload.success) {
            var message = payload && payload.data && payload.data.message ? payload.data.message : '';
            throw new Error(message || t('applyFailed'));
          }

          var data = payload.data || {};
          var progress = data.progress || {};
          var sent = parseInt(progress.sent_remote_updates, 10) || 0;
          var sentReview = parseInt(progress.sent_conflict_review_updates, 10) || 0;
          var remaining = parseInt(progress.next_remote_updates, 10) || 0;
          var remainingReview = parseInt(progress.next_conflict_review_updates, 10) || 0;
          var done = !!progress.done;
          processed += sent + sentReview;

          setApplyStatus(form, data.message || (done ? t('applyDone') : t('applyRunning')), !done);
          setApplyMeter(form, processed, remaining + remainingReview, done);

          if (!done) {
            window.setTimeout(runBatch, 800);
            return;
          }

          running = false;
          if (button) {
            button.disabled = true;
          }
        })
        .catch(function (error) {
          running = false;
          if (button) {
            button.disabled = false;
          }
          setApplyStatus(form, error && error.message ? error.message : t('applyFailed'), false);
        });
    }

    form.addEventListener('submit', function (event) {
      var submitter = event.submitter || document.activeElement;
      if (submitter && submitter.name === 'll_site_sync_action' && submitter.value !== 'apply_push') {
        return;
      }

      event.preventDefault();
      if (running) {
        return;
      }

      var passwordField = form.querySelector('input[name="ll_site_sync_remote_password"]');
      if (passwordField && !passwordField.value) {
        passwordField.value = getStoredPassword();
      }
      if (!passwordField || !passwordField.value) {
        setApplyStatus(form, t('applyPasswordRequired'), false);
        if (passwordField) {
          passwordField.focus();
        }
        return;
      }

      storePassword(passwordField.value);
      running = true;
      processed = 0;
      if (button) {
        button.disabled = true;
      }
      setApplyStatus(form, t('applyStarting'), true);
      setApplyMeter(form, 0, 1, false);
      runBatch();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    hydratePasswordFields();
    initOverview();
    initApplyPush();
  });
}());
