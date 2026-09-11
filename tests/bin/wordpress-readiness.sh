#!/usr/bin/env bash
# Source this helper; only the anonymous readiness GET may be retried.
ll_tools_warm_wordpress() {
    local readiness_url="$1" readiness_timeout="${2:-180}"
    local authority="${readiness_url#*://}"
    authority="${authority%%/*}"
    if [[ "$authority" == *@* ]]; then
        echo "WordPress readiness requires a URL without credentials." >&2
        return 1
    fi
    if ! [[ "$readiness_timeout" =~ ^[1-9][0-9]*$ ]] || (( ${#readiness_timeout} > 3 || readiness_timeout > 600 )); then
        echo "LL_TOOLS_E2E_READINESS_TIMEOUT_SECONDS must be an integer from 1 to 600." >&2
        return 1
    fi
    if ! command -v curl >/dev/null 2>&1; then
        echo "curl is required for the Playwright WordPress readiness check." >&2
        return 1
    fi

    local deadline=$((SECONDS + readiness_timeout)) attempt remaining http_status curl_status
    echo "Warming WordPress at ${readiness_url} (timeout ${readiness_timeout}s)"
    for attempt in 1 2 3; do
        remaining=$((deadline - SECONDS))
        (( remaining > 0 )) || break
        # Ignore .curlrc so credentials or implicit retries cannot change this
        # idempotent GET. Each attempt receives only the shared time remaining.
        if http_status="$(curl --disable --fail --insecure --location --silent --show-error \
            --output /dev/null --write-out '%{http_code}' --max-time "$remaining" "$readiness_url")"; then
            return 0
        else
            curl_status=$?
        fi
        case "$curl_status:$http_status" in
            22:502|22:503|22:504|7:000|56:000) ;;
            *) break ;;
        esac
        # A Local PHP-CGI recycle can briefly close/refuse the connection.
        # Authentication, configuration errors and exhausted requests fail now.
        (( attempt < 3 && deadline - SECONDS > 1 )) || break
        echo "Transient WordPress readiness failure (HTTP ${http_status}, curl ${curl_status}); retrying." >&2
        sleep 1
    done
    echo "WordPress did not become ready at ${readiness_url}." >&2
    return 1
}
