function isExpectedCloudflareRumAbort(details, errorText) {
  return !!details
    && details.method === 'POST'
    && details.pathname === '/cdn-cgi/rum'
    && errorText === 'net::ERR_ABORTED';
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

module.exports = {
  isExpectedCloudflareRumAbort,
  isExpectedCategorySearchWarmingResponse,
  isPotentialCategorySearchWarmingConsoleError
};
