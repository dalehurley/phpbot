#!/usr/bin/env bash
set -euo pipefail

# --- Arguments ---
# $2: Message content to send

MESSAGE_BODY="${2:-}"

if [[ -z "$MESSAGE_BODY" ]]; then
  echo "Error: Message content to send is required" >&2
  echo "Usage: $0 <message_body>" >&2
  exit 1
fi

# --- Credentials (from environment or key store) ---
# These should be set via: get_keys tool, environment variables, or .env file
: "${{TWILIO_ACCOUNT_SID:?'TWILIO_ACCOUNT_SID is required. Use get_keys to retrieve twilio_account_sid from the key store.'}"
: "${{TWILIO_AUTH_TOKEN:?'TWILIO_AUTH_TOKEN is required. Use get_keys to retrieve twilio_auth_token from the key store.'}"
: "${{TO_PHONE_NUMBER:?'TO_PHONE_NUMBER is required. Use get_keys to retrieve to_phone_number from the key store.'}"
: "${{FROM_PHONE_NUMBER:?'FROM_PHONE_NUMBER is required. Use get_keys to retrieve twilio_phone_number from the key store.'}"

# --- Execute API call ---
curl -X POST "https://api.twilio.com/2010-04-01/Accounts/${TWILIO_ACCOUNT_SID}/Messages.json" \
--data-urlencode "To=${TO_PHONE_NUMBER}" \
--data-urlencode "From=${FROM_PHONE_NUMBER}" \
--data-urlencode "Body=${MESSAGE_BODY}" \
-u ${TWILIO_ACCOUNT_SID}:${TWILIO_AUTH_TOKEN}
