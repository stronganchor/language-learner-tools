(function () {
  function localUsernameSuggestion(email) {
    var localPart = String(email || "").trim().toLowerCase().split("@")[0] || "";
    var suggestion = localPart.replace(/[^a-z0-9]+/g, "");

    return suggestion.slice(0, 50);
  }

  function generateStrongPassword(length) {
    var size = Math.max(16, length || 18);
    var charset = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+[]{}";
    var password = "";

    if (window.crypto && typeof window.crypto.getRandomValues === "function") {
      var values = new Uint32Array(size);
      window.crypto.getRandomValues(values);
      for (var i = 0; i < size; i += 1) {
        password += charset.charAt(values[i] % charset.length);
      }
      return password;
    }

    while (password.length < size) {
      password += charset.charAt(Math.floor(Math.random() * charset.length));
    }

    return password;
  }

  function shouldFocusWindow() {
    try {
      var params = new URLSearchParams(window.location.search);
      return params.has("ll_tools_auth") || params.has("ll_tools_auth_feedback") || window.location.hash === "#ll-tools-auth-window";
    } catch (error) {
      return window.location.hash === "#ll-tools-auth-window";
    }
  }

  function initPasswordField(root) {
    var passwordInput = root.querySelector("[data-ll-register-password]");
    var toggleButton = root.querySelector("[data-ll-register-password-toggle]");

    if (!passwordInput) {
      return;
    }

    if (!passwordInput.value) {
      passwordInput.value = generateStrongPassword(18);
    }

    if (!toggleButton) {
      return;
    }

    function syncPasswordToggle() {
      var isMasked = passwordInput.getAttribute("type") === "password";
      var showLabel = toggleButton.getAttribute("data-show-label") || "Show";
      var hideLabel = toggleButton.getAttribute("data-hide-label") || "Hide";
      var label = isMasked ? showLabel : hideLabel;

      toggleButton.textContent = label;
      toggleButton.setAttribute("aria-label", label);
      toggleButton.setAttribute("aria-pressed", isMasked ? "false" : "true");
    }

    toggleButton.addEventListener("click", function () {
      var isMasked = passwordInput.getAttribute("type") === "password";

      passwordInput.setAttribute("type", isMasked ? "text" : "password");
      syncPasswordToggle();
      passwordInput.focus();
    });

    syncPasswordToggle();
  }

  function initUsernameSuggestion(root) {
    var emailInput = root.querySelector("[data-ll-register-email]");
    var usernameInput = root.querySelector("[data-ll-register-username]");
    var customFlagInput = root.querySelector("[data-ll-register-username-custom]");
    var ajaxConfig = window.llToolsLoginWindow || {};
    var debounceTimer = 0;
    var requestId = 0;
    var lastSuggested = String(usernameInput && usernameInput.value ? usernameInput.value : "").trim();

    if (!emailInput || !usernameInput || !customFlagInput) {
      return;
    }

    function isCustomUsername() {
      return customFlagInput.value === "1";
    }

    function setCustomUsername(value) {
      customFlagInput.value = value ? "1" : "0";
    }

    function setSuggestedUsername(value) {
      lastSuggested = String(value || "").trim();
      usernameInput.value = lastSuggested;
      setCustomUsername(false);
    }

    function requestServerSuggestion() {
      var email = String(emailInput.value || "").trim();
      var currentRequest;
      var body;

      if (isCustomUsername() || !ajaxConfig.ajaxUrl || !ajaxConfig.suggestUsernameNonce || !/.+@.+\..+/.test(email)) {
        return;
      }

      currentRequest = requestId + 1;
      requestId = currentRequest;
      body = new URLSearchParams();
      body.set("action", "ll_tools_suggest_learner_username");
      body.set("nonce", ajaxConfig.suggestUsernameNonce);
      body.set("email", email);

      fetch(ajaxConfig.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
        },
        body: body.toString()
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (payload) {
          if (currentRequest !== requestId || isCustomUsername()) {
            return;
          }

          if (payload && payload.success && payload.data && typeof payload.data.username === "string") {
            setSuggestedUsername(payload.data.username);
          }
        })
        .catch(function () {
          return null;
        });
    }

    function updateFromEmail() {
      var localSuggestion;

      if (isCustomUsername()) {
        return;
      }

      localSuggestion = localUsernameSuggestion(emailInput.value);
      setSuggestedUsername(localSuggestion);

      window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(requestServerSuggestion, 250);
    }

    emailInput.addEventListener("input", updateFromEmail);
    emailInput.addEventListener("change", updateFromEmail);

    usernameInput.addEventListener("input", function () {
      var value = String(usernameInput.value || "").trim();
      if (!value) {
        setCustomUsername(false);
        lastSuggested = "";
        updateFromEmail();
        return;
      }

      setCustomUsername(value !== lastSuggested);
    });

    if (!isCustomUsername()) {
      updateFromEmail();
    }
  }

  function initFocus(root) {
    var focusMode;
    var focusTarget;

    if (!shouldFocusWindow()) {
      return;
    }

    focusMode = root.getAttribute("data-ll-auth-focus") || "login";
    if (focusMode === "register") {
      focusTarget = root.querySelector("[data-ll-register-email]");
    } else {
      focusTarget = root.querySelector('input[name="log"]');
    }

    if (focusTarget && typeof focusTarget.focus === "function") {
      window.setTimeout(function () {
        focusTarget.focus();
      }, 30);
    }
  }

  function initLoginWindow(root) {
    initPasswordField(root);
    initUsernameSuggestion(root);
    initFocus(root);
  }

  document.addEventListener("DOMContentLoaded", function () {
    var roots = document.querySelectorAll("[data-ll-tools-login-window]");
    for (var i = 0; i < roots.length; i += 1) {
      initLoginWindow(roots[i]);
    }
  });
})();
