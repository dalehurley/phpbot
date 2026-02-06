#!/usr/bin/env python3
"""
Keyboard control: type text, press keys, execute hotkeys.
Auto-installs pyautogui if not available.

Usage:
    keyboard.py type --text "Hello World" [--interval 0.02]
    keyboard.py press --key enter
    keyboard.py hotkey --keys "command+c"
"""

import sys
import subprocess
import argparse


def ensure_pyautogui():
    """Install pyautogui if not available."""
    try:
        import pyautogui  # noqa: F401
        return True
    except ImportError:
        print("Installing pyautogui...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install", "pyautogui", "-q"],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install pyautogui: {result.stderr}", file=sys.stderr)
            return False
        print("pyautogui installed.", file=sys.stderr)
        return True


# Map common key aliases to pyautogui key names
KEY_ALIASES = {
    "cmd": "command",
    "meta": "command",
    "super": "command",
    "ctrl": "ctrl",
    "control": "ctrl",
    "opt": "alt",
    "option": "alt",
    "return": "enter",
    "esc": "escape",
    "del": "delete",
    "bs": "backspace",
    "pgup": "pageup",
    "pgdn": "pagedown",
    "pgdown": "pagedown",
    "arrowup": "up",
    "arrowdown": "down",
    "arrowleft": "left",
    "arrowright": "right",
}


def resolve_key(key_name):
    """Resolve key aliases to pyautogui key names."""
    k = key_name.lower().strip()
    return KEY_ALIASES.get(k, k)


def cmd_type(args):
    """Type text character by character."""
    import pyautogui
    interval = args.interval or 0.02
    pyautogui.typewrite(args.text, interval=interval) if args.text.isascii() else _type_unicode(args.text, interval)
    print(f"Typed: {args.text[:100]}{'...' if len(args.text) > 100 else ''} ({len(args.text)} chars)")


def _type_unicode(text, interval):
    """Type Unicode text using pyperclip + paste (pyautogui.typewrite only supports ASCII)."""
    import pyautogui
    import time

    for char in text:
        if char.isascii():
            pyautogui.typewrite(char, interval=0)
        else:
            # Use clipboard paste for non-ASCII characters
            try:
                import subprocess
                subprocess.run(["pbcopy"], input=char.encode("utf-8"), check=True)
                pyautogui.hotkey("command", "v")
            except Exception:
                # Fallback: skip non-ASCII
                pass
        time.sleep(interval)


def cmd_press(args):
    """Press a single key."""
    import pyautogui
    key = resolve_key(args.key)
    pyautogui.press(key)
    print(f"Pressed: {key}")


def cmd_hotkey(args):
    """Press a key combination."""
    import pyautogui
    keys = [resolve_key(k) for k in args.keys.split("+")]
    pyautogui.hotkey(*keys)
    print(f"Hotkey: {'+'.join(keys)}")


def main():
    parser = argparse.ArgumentParser(description="Keyboard control")
    subparsers = parser.add_subparsers(dest="action", required=True)

    # type
    p = subparsers.add_parser("type", help="Type text")
    p.add_argument("--text", "-t", required=True, help="Text to type")
    p.add_argument("--interval", "-i", type=float, default=0.02, help="Delay between keys (default: 0.02s)")

    # press
    p = subparsers.add_parser("press", help="Press a key")
    p.add_argument("--key", "-k", required=True, help="Key to press")

    # hotkey
    p = subparsers.add_parser("hotkey", help="Key combination")
    p.add_argument("--keys", "-k", required=True, help="Keys separated by + (e.g., command+c)")

    args = parser.parse_args()

    if not ensure_pyautogui():
        sys.exit(1)

    import pyautogui
    pyautogui.FAILSAFE = True
    pyautogui.PAUSE = 0.1

    actions = {
        "type": cmd_type,
        "press": cmd_press,
        "hotkey": cmd_hotkey,
    }

    try:
        actions[args.action](args)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
