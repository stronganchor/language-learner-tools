#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$TESTS_DIR/.." && pwd)"
SITE_ROOT="$(cd "$PROJECT_ROOT/../../.." && pwd -P)"

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 is required for setup-local-http-env.sh" >&2
    exit 1
fi

python3 - "$SITE_ROOT" <<'PY'
import glob
import pathlib
import re
import sys


def shell_quote(value: str) -> str:
    return "'" + value.replace("'", "'\"'\"'") + "'"


site_root = pathlib.Path(sys.argv[1]).resolve()
site_conf_paths = sorted(
    glob.glob("/mnt/c/Users/*/AppData/Roaming/Local/run/*/conf/nginx/site.conf")
)

for conf_path in site_conf_paths:
    conf_file = pathlib.Path(conf_path)
    try:
        text = conf_file.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        continue

    root_match = re.search(r'^\s*root\s+"([^"]+)";', text, re.MULTILINE)
    port_match = re.search(r'^\s*listen\s+127\.0\.0\.1:(\d+);', text, re.MULTILINE)
    if not root_match or not port_match:
        continue

    root_win = root_match.group(1).replace("\\", "/")
    if re.match(r"^[A-Za-z]:/", root_win):
        drive = root_win[0].lower()
        root_unix = "/mnt/" + drive + "/" + root_win[3:]
    else:
        root_unix = root_win

    root_path = pathlib.Path(root_unix).resolve()
    if root_path != site_root:
        continue

    port = port_match.group(1)
    base_url = f"http://127.0.0.1:{port}"
    print(f"export LL_E2E_BASE_URL={shell_quote(base_url)}")
    print("export LL_E2E_LEARN_PATH='/learn/'")
    print(f"export LL_E2E_NGINX_CONF={shell_quote(str(conf_file))}")
    sys.exit(0)

print(
    "Could not detect a Local nginx site.conf matching this plugin's site root.",
    file=sys.stderr,
)
sys.exit(1)
PY
