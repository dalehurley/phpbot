#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Execute API call
# =============================================================================
# HTTP Method:  GET
# Generated:    2026-02-08
# =============================================================================

# --- Output formatting ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

# --- Retry logic for transient failures ---
MAX_RETRIES=${MAX_RETRIES:-3}
RETRY_DELAY=${RETRY_DELAY:-2}

retry() {
  local attempt=1
  local max_attempts="${MAX_RETRIES}"
  local delay="${RETRY_DELAY}"
  local exit_code=0

  while [[ ${attempt} -le ${max_attempts} ]]; do
    if "$@"; then
      return 0
    fi
    exit_code=$?

    if [[ ${attempt} -lt ${max_attempts} ]]; then
      echo -e "${YELLOW}Attempt ${attempt}/${max_attempts} failed (exit code: ${exit_code}). Retrying in ${delay}s...${NC}" >&2
      sleep "${delay}"
      delay=$((delay * 2))
    fi

    attempt=$((attempt + 1))
  done

  echo -e "${RED}All ${max_attempts} attempts failed.${NC}" >&2
  return ${exit_code}
}

# --- Execute API call ---
RESPONSE_FILE="$(mktemp)"
trap 'rm -f "${RESPONSE_FILE}"' EXIT

echo -e "${YELLOW}Calling API...${NC}" >&2

FULL_RESPONSE="$(retry curl -s wttr.in/Sydney -w "\n%{http_code}")"

HTTP_CODE="$(echo "${FULL_RESPONSE}" | tail -1)"
RESPONSE_BODY="$(echo "${FULL_RESPONSE}" | sed '$d')"

# --- Validate response ---
if [[ "${HTTP_CODE}" =~ ^2[0-9]{2}$ ]]; then
  echo -e "${GREEN}Success${NC} (HTTP ${HTTP_CODE})" >&2
else
  echo -e "${RED}Failed${NC} (HTTP ${HTTP_CODE})" >&2
  echo "${RESPONSE_BODY}" >&2
  exit 1
fi

# --- Format output ---
if command -v jq &>/dev/null; then
  echo "${RESPONSE_BODY}" | jq .
else
  echo "${RESPONSE_BODY}"
fi
