#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REST_HELPER="${SCRIPT_DIR}/ll-rest-local.sh"

usage() {
  cat <<'USAGE'
Usage:
  bash bin/ll-word-category-terms-local.sh WORDSET create --name NAME [--slug SLUG] [--prereq-ids IDS] [--apply] -u USER:PASS
  bash bin/ll-word-category-terms-local.sh WORDSET rename --category-id ID --expected-name OLD --new-name NEW [--slug SLUG] [--apply] -u USER:PASS
  bash bin/ll-word-category-terms-local.sh WORDSET delete --category-id ID [--expected-name NAME] [--apply] -u USER:PASS
  bash bin/ll-word-category-terms-local.sh WORDSET prerequisites --category-id ID --prereq-ids IDS [--expected-prereq-ids IDS] [--apply] -u USER:PASS

Commands:
  create          Create a word-category term for the wordset.
  rename          Rename a word-category term and optionally change its slug.
  delete          Delete a word-category term only if it is empty.
  prerequisites   Replace one category's prerequisite category IDs.

Options:
  --apply                         Run a dry run first, then apply the same payload with dry_run=false.
  --purge-public-static-cache     Ask the route to purge public static caches after a successful write.
  -u, --user, --auth USER:PASS    Pass Basic auth through to curl.exe.
  --                              Pass any remaining arguments through to curl.exe.

ID lists are comma-separated, such as "8093,8158". Omit --apply for a dry run only.
USAGE
}

die() {
  echo "ll-word-category-terms-local.sh: $*" >&2
  exit 1
}

json_escape() {
  local value="${1}"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "${value}"
}

json_string() {
  printf '"%s"' "$(json_escape "$1")"
}

require_value() {
  local option="${1}"
  local value="${2:-}"
  if [[ -z "${value}" ]]; then
    die "${option} requires a value"
  fi
}

validate_id() {
  local label="${1}"
  local value="${2}"
  if ! [[ "${value}" =~ ^[0-9]+$ ]]; then
    die "${label} must be a positive integer; got '${value}'"
  fi
  if [[ "${value}" == "0" ]]; then
    die "${label} must be greater than zero"
  fi
}

id_list_json() {
  local raw="${1:-}"
  raw="${raw// /}"
  raw="${raw//$'\t'/}"
  if [[ -z "${raw}" ]]; then
    printf '[]'
    return
  fi

  local output="["
  local separator=""
  local id
  IFS=',' read -r -a ids <<< "${raw}"
  for id in "${ids[@]}"; do
    require_value "ID list entry" "${id}"
    validate_id "ID list entry" "${id}"
    output+="${separator}${id}"
    separator=","
  done
  output+="]"
  printf '%s' "${output}"
}

append_action_field() {
  local current="${1}"
  local name="${2}"
  local value="${3}"
  printf '%s,"%s":%s' "${current}" "${name}" "${value}"
}

build_payload() {
  local dry_run_value="${1}"
  printf '{"dry_run":%s,"purge_public_static_cache":%s,"actions":[%s]}' \
    "${dry_run_value}" \
    "${purge_public_static_cache}" \
    "${action_json}"
}

post_payload() {
  local label="${1}"
  local payload="${2}"
  local response status body marker_line

  echo "${label} ${route}" >&2
  response="$(
    bash "${REST_HELPER}" "${route}" \
      -X POST \
      -H "Content-Type: application/json" \
      "${curl_args[@]}" \
      -d "${payload}" \
      -w $'\nLL_TOOLS_HTTP_STATUS:%{http_code}\n'
  )"
  status="${response##*LL_TOOLS_HTTP_STATUS:}"
  status="${status%%$'\n'*}"
  status="${status//$'\r'/}"
  marker_line=$'\nLL_TOOLS_HTTP_STATUS:'
  body="${response%${marker_line}*}"

  printf '%s\n' "${body}"
  if ! [[ "${status}" =~ ^2[0-9][0-9]$ ]]; then
    die "${label} failed with HTTP ${status:-unknown}; not continuing"
  fi
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

wordset="${1:-}"
command="${2:-}"
if [[ -z "${wordset}" || -z "${command}" ]]; then
  usage >&2
  exit 1
fi
shift 2

apply=false
purge_public_static_cache=false
curl_args=()

category_id=""
expected_name=""
new_name=""
name=""
slug=""
prereq_ids=""
expected_prereq_ids=""
prereq_ids_set=false
expected_prereq_ids_set=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply)
      apply=true
      shift
      ;;
    --purge-public-static-cache)
      purge_public_static_cache=true
      shift
      ;;
    -u|--user|--auth)
      require_value "$1" "${2:-}"
      curl_args+=("-u" "$2")
      shift 2
      ;;
    --category-id|--id)
      require_value "$1" "${2:-}"
      validate_id "$1" "$2"
      category_id="$2"
      shift 2
      ;;
    --expected-name)
      require_value "$1" "${2:-}"
      expected_name="$2"
      shift 2
      ;;
    --new-name)
      require_value "$1" "${2:-}"
      new_name="$2"
      shift 2
      ;;
    --name)
      require_value "$1" "${2:-}"
      name="$2"
      shift 2
      ;;
    --slug)
      require_value "$1" "${2:-}"
      slug="$2"
      shift 2
      ;;
    --prereq-ids|--prerequisite-ids)
      require_value "$1" "${2+x}"
      prereq_ids="$2"
      prereq_ids_set=true
      shift 2
      ;;
    --expected-prereq-ids|--expected-prerequisite-ids)
      require_value "$1" "${2+x}"
      expected_prereq_ids="$2"
      expected_prereq_ids_set=true
      shift 2
      ;;
    --)
      shift
      curl_args+=("$@")
      break
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "Unknown option: $1"
      ;;
  esac
done

case "${command}" in
  create|create-category|create_category)
    require_value "--name" "${name}"
    action_json='{"action":"create_category"'
    action_json="$(append_action_field "${action_json}" "name" "$(json_string "${name}")")"
    if [[ -n "${slug}" ]]; then
      action_json="$(append_action_field "${action_json}" "slug" "$(json_string "${slug}")")"
    fi
    if [[ "${prereq_ids_set}" == true ]]; then
      action_json="$(append_action_field "${action_json}" "prereq_ids" "$(id_list_json "${prereq_ids}")")"
    fi
    action_json+='}'
    ;;
  rename|rename-category|rename_category)
    require_value "--category-id" "${category_id}"
    require_value "--new-name" "${new_name}"
    action_json='{"action":"rename_category"'
    action_json="$(append_action_field "${action_json}" "category_id" "${category_id}")"
    if [[ -n "${expected_name}" ]]; then
      action_json="$(append_action_field "${action_json}" "expected_name" "$(json_string "${expected_name}")")"
    fi
    action_json="$(append_action_field "${action_json}" "new_name" "$(json_string "${new_name}")")"
    if [[ -n "${slug}" ]]; then
      action_json="$(append_action_field "${action_json}" "slug" "$(json_string "${slug}")")"
    fi
    action_json+='}'
    ;;
  delete|delete-empty|delete_empty|delete-empty-category|delete_empty_category)
    require_value "--category-id" "${category_id}"
    action_json='{"action":"delete_empty_category"'
    action_json="$(append_action_field "${action_json}" "category_id" "${category_id}")"
    if [[ -n "${expected_name}" ]]; then
      action_json="$(append_action_field "${action_json}" "expected_name" "$(json_string "${expected_name}")")"
    fi
    action_json+='}'
    ;;
  prerequisites|set-prerequisites|set_prerequisites|prereqs)
    require_value "--category-id" "${category_id}"
    if [[ "${prereq_ids_set}" != true ]]; then
      die "--prereq-ids is required; pass an empty string to clear prerequisites"
    fi
    action_json='{"action":"set_prerequisites"'
    action_json="$(append_action_field "${action_json}" "category_id" "${category_id}")"
    action_json="$(append_action_field "${action_json}" "prereq_ids" "$(id_list_json "${prereq_ids}")")"
    if [[ "${expected_prereq_ids_set}" == true ]]; then
      action_json="$(append_action_field "${action_json}" "expected_prereq_ids" "$(id_list_json "${expected_prereq_ids}")")"
    fi
    action_json+='}'
    ;;
  *)
    die "Unknown command: ${command}"
    ;;
esac

route="/wp-json/ll-tools/v1/wordsets/${wordset}/word-category-terms"
dry_run_payload="$(build_payload true)"
post_payload "Dry run:" "${dry_run_payload}"

if [[ "${apply}" == true ]]; then
  apply_payload="$(build_payload false)"
  post_payload "Apply:" "${apply_payload}"
else
  echo "Dry run only. Re-run the same command with --apply after reviewing the response." >&2
fi
