#!/usr/bin/env bash
set -euo pipefail

# --- Clipboard read/write utility ---
# Usage:
#   clip.sh read              - Read clipboard contents to stdout
#   clip.sh write "text"      - Write text to clipboard
#   echo "text" | clip.sh write  - Write piped text to clipboard

ACTION="${1:-}"

if [[ -z "$ACTION" ]]; then
  echo "Error: Action required (read or write)" >&2
  echo "Usage: $0 read | write [text]" >&2
  exit 1
fi

case "$ACTION" in
  read)
    if command -v pbpaste &>/dev/null; then
      pbpaste
    elif command -v xclip &>/dev/null; then
      xclip -selection clipboard -o
    elif command -v xsel &>/dev/null; then
      xsel --clipboard --output
    elif command -v wl-paste &>/dev/null; then
      wl-paste
    else
      echo "Error: No clipboard utility found (pbpaste, xclip, xsel, wl-paste)" >&2
      exit 1
    fi
    ;;

  write)
    TEXT="${2:-}"

    # If no argument, read from stdin
    if [[ -z "$TEXT" ]]; then
      if [[ -t 0 ]]; then
        echo "Error: No text provided. Pass as argument or pipe via stdin." >&2
        echo "Usage: $0 write \"text\" OR echo \"text\" | $0 write" >&2
        exit 1
      fi
      TEXT=$(cat)
    fi

    if command -v pbcopy &>/dev/null; then
      printf '%s' "$TEXT" | pbcopy
    elif command -v xclip &>/dev/null; then
      printf '%s' "$TEXT" | xclip -selection clipboard
    elif command -v xsel &>/dev/null; then
      printf '%s' "$TEXT" | xsel --clipboard --input
    elif command -v wl-copy &>/dev/null; then
      printf '%s' "$TEXT" | wl-copy
    else
      echo "Error: No clipboard utility found (pbcopy, xclip, xsel, wl-copy)" >&2
      exit 1
    fi

    echo "Copied to clipboard (${#TEXT} characters)"
    ;;

  *)
    echo "Error: Unknown action '$ACTION'. Use 'read' or 'write'." >&2
    exit 1
    ;;
esac
