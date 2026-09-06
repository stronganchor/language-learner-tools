function isExpectedCloudflareRumAbort(details, errorText) {
  return !!details
    && details.method === 'POST'
    && details.pathname === '/cdn-cgi/rum'
    && errorText === 'net::ERR_ABORTED';
}

function isExpectedPopupMediaCleanupAbort(details, errorText, expectedUrls) {
  return !!details
    && details.method === 'GET'
    && errorText === 'net::ERR_ABORTED'
    && expectedUrls instanceof Set
    && expectedUrls.has(details.url);
}

function isExpectedCategorySearchWarmingResponse(details, status) {
  return !!details
    && details.method === 'POST'
    && /\/wp-admin\/admin-ajax\.php$/i.test(details.pathname || '')
    && details.adminAjaxAction === 'll_tools_wordset_page_category_search'
    && Number(status) === 503;
}

function isPotentialCategorySearchWarmingConsoleError(messageText, locationUrl, siteOrigin) {
  if (!/failed to load resource.*(?:status (?:code )?of )?503/i.test(String(messageText || ''))) {
    return false;
  }
  if (!locationUrl) {
    return true;
  }

  try {
    const location = new URL(locationUrl);
    return location.origin === siteOrigin
      && /\/wp-admin\/admin-ajax\.php$/i.test(location.pathname);
  } catch (_) {
    return false;
  }
}

function isExpectedFlashcardPayloadWarmingResponse(details, status, payload) {
  const data = payload && typeof payload === 'object' ? payload.data : null;
  return !!details
    && details.method === 'POST'
    && /\/wp-admin\/admin-ajax\.php$/i.test(details.pathname || '')
    && details.adminAjaxAction === 'll_get_flashcard_payload_page'
    && Number(status) === 429
    && !!payload
    && payload.success === false
    && !!data
    && data.code === 'cache_warming';
}

function isPotentialFlashcardWarmingConsoleError(messageText, locationUrl, siteOrigin) {
  if (!/failed to load resource.*(?:status (?:code )?of )?429/i.test(String(messageText || ''))) {
    return false;
  }
  if (!locationUrl) {
    return true;
  }

  try {
    const location = new URL(locationUrl);
    return location.origin === siteOrigin
      && /\/wp-admin\/admin-ajax\.php$/i.test(location.pathname);
  } catch (_) {
    return false;
  }
}

module.exports = {
  isExpectedCloudflareRumAbort,
  isExpectedPopupMediaCleanupAbort,
  isExpectedCategorySearchWarmingResponse,
  isPotentialCategorySearchWarmingConsoleError,
  isExpectedFlashcardPayloadWarmingResponse,
  isPotentialFlashcardWarmingConsoleError
};
