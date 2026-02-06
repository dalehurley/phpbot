#!/usr/bin/env python3
"""
Screen reading: get info, capture screenshots, read accessibility tree.
Uses pyautogui for screen info/capture and AppleScript for accessibility.

Usage:
    screen.py info
    screen.py capture [--output path] [--x X --y Y --width W --height H]
    screen.py read-ui [--depth N]
"""

import sys
import os
import subprocess
import argparse
import json


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


def cmd_info(args):
    """Get screen size and mouse position."""
    import pyautogui
    screen = pyautogui.size()
    pos = pyautogui.position()
    print(f"Screen size: {screen.width}x{screen.height}")
    print(f"Mouse position: ({pos.x}, {pos.y})")

    # Get frontmost app info via AppleScript
    try:
        script = '''
        tell application "System Events"
            set frontApp to first application process whose frontmost is true
            set appName to name of frontApp
            try
                set winName to name of front window of frontApp
            on error
                set winName to "N/A"
            end try
            return appName & "|" & winName
        end tell
        '''
        result = subprocess.run(
            ["osascript", "-e", script],
            capture_output=True, text=True, timeout=5,
        )
        if result.returncode == 0:
            parts = result.stdout.strip().split("|", 1)
            print(f"Frontmost app: {parts[0]}")
            if len(parts) > 1:
                print(f"Window title: {parts[1]}")
    except Exception:
        pass


def cmd_capture(args):
    """Capture a screenshot."""
    import pyautogui

    output = args.output
    if not output:
        from datetime import datetime
        ts = datetime.now().strftime("%Y%m%d-%H%M%S")
        output = os.path.expanduser(f"~/Desktop/screenshot-{ts}.png")

    output = os.path.abspath(os.path.expanduser(output))
    os.makedirs(os.path.dirname(output) or ".", exist_ok=True)

    if args.x is not None and args.y is not None and args.width and args.height:
        # Region capture
        img = pyautogui.screenshot(region=(args.x, args.y, args.width, args.height))
    else:
        img = pyautogui.screenshot()

    img.save(output)
    size = os.path.getsize(output)
    print(f"Screenshot saved: {output} ({img.width}x{img.height}, {size:,} bytes)")


def cmd_read_ui(args):
    """Read the accessibility tree of the frontmost application using AppleScript."""
    depth = args.depth or 5

    # AppleScript to read UI hierarchy
    script = f'''
    on getUIElements(element, currentDepth, maxDepth, indentStr)
        if currentDepth > maxDepth then return ""

        set output to ""

        try
            set elemRole to role of element
        on error
            set elemRole to "unknown"
        end try

        try
            set elemTitle to title of element
        on error
            set elemTitle to ""
        end try

        try
            set elemName to name of element
        on error
            set elemName to ""
        end try

        try
            set elemValue to value of element
        on error
            set elemValue to ""
        end try

        try
            set elemDesc to description of element
        on error
            set elemDesc to ""
        end try

        -- Build label from available attributes
        set label to ""
        if elemName is not "" and elemName is not missing value then
            set label to elemName
        else if elemTitle is not "" and elemTitle is not missing value then
            set label to elemTitle
        else if elemDesc is not "" and elemDesc is not missing value then
            set label to elemDesc
        end if

        set valStr to ""
        if elemValue is not "" and elemValue is not missing value then
            set valStr to " = \\"" & (elemValue as text) & "\\""
        end if

        -- Only output elements with meaningful info
        if label is not "" or valStr is not "" then
            set output to output & indentStr & "[" & elemRole & "] " & label & valStr & "\\n"
        else if elemRole is not "unknown" then
            set output to output & indentStr & "[" & elemRole & "]\\n"
        end if

        -- Recurse into children
        try
            set childElements to UI elements of element
            repeat with child in childElements
                set output to output & getUIElements(child, currentDepth + 1, maxDepth, indentStr & "  ")
            end repeat
        end try

        return output
    end getUIElements

    tell application "System Events"
        set frontApp to first application process whose frontmost is true
        set appName to name of frontApp

        set output to "Application: " & appName & "\\n"

        try
            set wins to windows of frontApp
            repeat with win in wins
                try
                    set winName to name of win
                on error
                    set winName to "Untitled"
                end try
                set output to output & "\\nWindow: " & winName & "\\n"
                set output to output & getUIElements(win, 1, {depth}, "  ")
            end repeat
        end try

        return output
    end tell
    '''

    try:
        result = subprocess.run(
            ["osascript", "-e", script],
            capture_output=True, text=True, timeout=15,
        )

        if result.returncode != 0:
            # Check if it's a permissions issue
            if "not allowed" in result.stderr.lower() or "accessibility" in result.stderr.lower():
                print(
                    "Error: Accessibility permission required.\n"
                    "Go to System Settings > Privacy & Security > Accessibility\n"
                    "and enable your terminal application.",
                    file=sys.stderr,
                )
            else:
                print(f"Error reading UI: {result.stderr}", file=sys.stderr)
            sys.exit(1)

        output = result.stdout.strip()
        if output:
            print(output)
        else:
            print("No UI elements found (window may be minimized or empty)")

    except subprocess.TimeoutExpired:
        print("Error: Timed out reading accessibility tree (try a smaller --depth)", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser(description="Screen reading and capture")
    subparsers = parser.add_subparsers(dest="action", required=True)

    # info
    subparsers.add_parser("info", help="Screen size and mouse position")

    # capture
    p = subparsers.add_parser("capture", help="Screenshot")
    p.add_argument("--output", "-o", help="Output file path")
    p.add_argument("--x", type=int, help="Region X")
    p.add_argument("--y", type=int, help="Region Y")
    p.add_argument("--width", type=int, help="Region width")
    p.add_argument("--height", type=int, help="Region height")

    # read-ui
    p = subparsers.add_parser("read-ui", help="Read accessibility tree")
    p.add_argument("--depth", "-d", type=int, default=5, help="Max tree depth (default: 5)")

    args = parser.parse_args()

    if args.action != "read-ui":
        if not ensure_pyautogui():
            sys.exit(1)

    actions = {
        "info": cmd_info,
        "capture": cmd_capture,
        "read-ui": cmd_read_ui,
    }

    try:
        actions[args.action](args)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
