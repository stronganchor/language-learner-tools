#!/usr/bin/env bash
# Shared by the local regression wrappers; never source test credentials here.
ll_tools_tests_need_local() {
    local kind="$1" arg positional=0 only_filesystem=1 option_value=0
    shift
    for arg in "$@"; do
        case "$arg" in
            --help|-h|--version|-V) return 1 ;;
            --list|--list-*)
                # PHPUnit loads its WordPress/database bootstrap before listing
                # suites or tests; only Playwright discovery is runtime-free.
                [[ "$kind" != "http" ]] || return 1
                ;;
        esac
        if [[ "$option_value" == "1" ]]; then
            option_value=0
            continue
        fi
        case "$arg" in
            --reporter|--project|--workers|--retries|--timeout|--grep|-g|--grep-invert|--output|--config|-c|--shard|--repeat-each|--max-failures)
                option_value=1
                continue
                ;;
        esac
        if [[ "$arg" != -* ]]; then
            positional=1
            case "$arg" in
                *maintenance-doc-contracts.spec.js|*local-site-startup.spec.js) ;;
                *) only_filesystem=0 ;;
            esac
        fi
    done
    if [[ "$kind" == "http" && "$positional" == "1" && "$only_filesystem" == "1" ]]; then
        return 1
    fi
    return 0
}

ll_tools_ensure_local_site() {
    local mode="$1" target="${2:-}"
    [[ "${LL_TOOLS_LOCAL_AUTOSTART:-1}" != "0" ]] || return 0
    if ! command -v node >/dev/null 2>&1; then
        local probe_dir="${LL_TOOLS_LOCAL_SITE_ROOT:-$SCRIPT_DIR}" local_checkout=0
        while [[ "$probe_dir" != "/" ]]; do
            if [[ -f "$probe_dir/local-site.json" ]]; then
                local_checkout=1
                break
            fi
            probe_dir="$(dirname "$probe_dir")"
        done
        [[ "$local_checkout" == "1" ]] || return 0
        echo "Node is required for automatic Local startup. Open Local manually and set LL_TOOLS_LOCAL_AUTOSTART=0, or install Node." >&2
        return 1
    fi
    LL_TOOLS_LOCAL_AUTOSTART="${LL_TOOLS_LOCAL_AUTOSTART:-1}" \
    LL_TOOLS_LOCAL_START_TIMEOUT_SECONDS="${LL_TOOLS_LOCAL_START_TIMEOUT_SECONDS:-120}" \
        node "$SCRIPT_DIR/ensure-local-site.cjs" "$mode" "$target"
}
