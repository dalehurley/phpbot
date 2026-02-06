#!/usr/bin/env bash
set -euo pipefail

# Homebrew helper script for the homebrew skill.
# Usage: bash brew.sh <action> [args...]
#
# Actions:
#   install <pkg> [pkg...]       Install formulae
#   install_cask <pkg> [pkg...]  Install cask (GUI) apps
#   uninstall <pkg> [pkg...]     Remove packages
#   search <query>               Search for packages
#   info <pkg> [pkg...]          Show package info
#   list                         List installed formulae
#   list_casks                   List installed casks
#   update                       Update Homebrew
#   upgrade [pkg...]             Upgrade packages (all if none specified)
#   doctor                       Diagnose Homebrew issues
#   tap <repo>                   Tap a repository
#   outdated                     Show outdated packages

ACTION="${1:-}"

if [[ -z "$ACTION" ]]; then
  echo "Error: No action specified." >&2
  echo "Usage: $0 <action> [args...]" >&2
  echo "Actions: install, install_cask, uninstall, search, info, list, list_casks, update, upgrade, doctor, tap, outdated" >&2
  exit 1
fi

shift

# --- Locate brew ---
if command -v brew &>/dev/null; then
  BREW="brew"
elif [[ -x /opt/homebrew/bin/brew ]]; then
  BREW="/opt/homebrew/bin/brew"
elif [[ -x /usr/local/bin/brew ]]; then
  BREW="/usr/local/bin/brew"
else
  echo "Error: Homebrew is not installed." >&2
  echo "Install with: /bin/bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\"" >&2
  exit 1
fi

case "$ACTION" in
  install)
    [[ $# -eq 0 ]] && { echo "Error: No packages specified." >&2; exit 1; }
    $BREW install "$@"
    ;;
  install_cask)
    [[ $# -eq 0 ]] && { echo "Error: No packages specified." >&2; exit 1; }
    $BREW install --cask "$@"
    ;;
  uninstall)
    [[ $# -eq 0 ]] && { echo "Error: No packages specified." >&2; exit 1; }
    $BREW uninstall "$@"
    ;;
  search)
    [[ $# -eq 0 ]] && { echo "Error: No search query specified." >&2; exit 1; }
    $BREW search "$@"
    ;;
  info)
    [[ $# -eq 0 ]] && { echo "Error: No packages specified." >&2; exit 1; }
    $BREW info "$@"
    ;;
  list)
    $BREW list
    ;;
  list_casks)
    $BREW list --cask
    ;;
  update)
    $BREW update
    ;;
  upgrade)
    if [[ $# -gt 0 ]]; then
      $BREW upgrade "$@"
    else
      $BREW upgrade
    fi
    ;;
  doctor)
    $BREW doctor || true
    ;;
  tap)
    [[ $# -eq 0 ]] && { echo "Error: No tap name specified." >&2; exit 1; }
    $BREW tap "$@"
    ;;
  outdated)
    $BREW outdated --verbose
    ;;
  *)
    echo "Error: Unknown action '$ACTION'." >&2
    echo "Valid actions: install, install_cask, uninstall, search, info, list, list_casks, update, upgrade, doctor, tap, outdated" >&2
    exit 1
    ;;
esac
