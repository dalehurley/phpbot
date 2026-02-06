#!/usr/bin/env bash
set -euo pipefail

# --- Screenshot capture utility ---
# Usage:
#   capture.sh [--mode fullscreen|window|region] [--output path] [--delay seconds]

MODE="fullscreen"
OUTPUT=""
DELAY=""

# Parse arguments
while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode)
      MODE="${2:-fullscreen}"
      shift 2
      ;;
    --output|-o)
      OUTPUT="${2:-}"
      shift 2
      ;;
    --delay|-d)
      DELAY="${2:-}"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      echo "Usage: $0 [--mode fullscreen|window|region] [--output path] [--delay seconds]" >&2
      exit 1
      ;;
  esac
done

# Default output path
if [[ -z "$OUTPUT" ]]; then
  TIMESTAMP=$(date +%Y%m%d-%H%M%S)
  OUTPUT="$HOME/Desktop/screenshot-${TIMESTAMP}.png"
fi

# Ensure output directory exists
mkdir -p "$(dirname "$OUTPUT")"

# --- macOS: screencapture ---
if command -v screencapture &>/dev/null; then
  ARGS=()

  # Delay
  if [[ -n "$DELAY" ]]; then
    ARGS+=("-T" "$DELAY")
  fi

  case "$MODE" in
    fullscreen)
      # Default behavior
      ;;
    window)
      ARGS+=("-w")  # Interactive window selection
      ;;
    region)
      ARGS+=("-s")  # Interactive region selection
      ;;
    *)
      echo "Error: Unknown mode '$MODE'. Use fullscreen, window, or region." >&2
      exit 1
      ;;
  esac

  ARGS+=("$OUTPUT")
  screencapture "${ARGS[@]}"

# --- Linux: various tools ---
elif command -v gnome-screenshot &>/dev/null; then
  ARGS=("-f" "$OUTPUT")
  if [[ -n "$DELAY" ]]; then
    ARGS+=("-d" "$DELAY")
  fi
  case "$MODE" in
    fullscreen) ;;
    window) ARGS+=("-w") ;;
    region) ARGS+=("-a") ;;
  esac
  gnome-screenshot "${ARGS[@]}"

elif command -v scrot &>/dev/null; then
  ARGS=("$OUTPUT")
  if [[ -n "$DELAY" ]]; then
    ARGS+=("-d" "$DELAY")
  fi
  case "$MODE" in
    fullscreen) ;;
    window) ARGS+=("-u") ;;
    region) ARGS+=("-s") ;;
  esac
  scrot "${ARGS[@]}"

elif command -v import &>/dev/null; then
  # ImageMagick import
  if [[ -n "$DELAY" ]]; then
    sleep "$DELAY"
  fi
  case "$MODE" in
    fullscreen) import -window root "$OUTPUT" ;;
    window|region) import "$OUTPUT" ;;
  esac

else
  echo "Error: No screenshot tool found (screencapture, gnome-screenshot, scrot, import)" >&2
  exit 1
fi

# Verify output
if [[ -f "$OUTPUT" ]]; then
  SIZE=$(stat -f%z "$OUTPUT" 2>/dev/null || stat -c%s "$OUTPUT" 2>/dev/null || echo "unknown")
  echo "Screenshot saved: $OUTPUT ($SIZE bytes)"
else
  echo "Error: Screenshot failed - no output file created" >&2
  exit 1
fi
