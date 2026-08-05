function isExpectedCloudflareRumAbort(details, errorText) {
  return !!details
    && details.method === 'POST'
    && details.pathname === '/cdn-cgi/rum'
    && errorText === 'net::ERR_ABORTED';
}

module.exports = {
  isExpectedCloudflareRumAbort
};
