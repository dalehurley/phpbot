#!/usr/bin/env python3
"""
Mouse control: move, click, double-click, right-click, drag, scroll.
Auto-installs pyautogui if not available.

Usage:
    mouse.py move --x X --y Y
    mouse.py click --x X --y Y [--button left|right|middle]
    mouse.py doubleclick --x X --y Y
    mouse.py rightclick --x X --y Y
    mouse.py drag --x X --y Y --to-x TX --to-y TY
    mouse.py scroll --amount N [--x X --y Y]
    mouse.py position
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


def cmd_move(args):
    """Move mouse to position."""
    import pyautogui
    pyautogui.moveTo(args.x, args.y, duration=0.3)
    print(f"Mouse moved to ({args.x}, {args.y})")


def cmd_click(args):
    """Click at position."""
    import pyautogui
    button = args.button or "left"
    if args.x is not None and args.y is not None:
        pyautogui.click(args.x, args.y, button=button)
        print(f"Clicked ({button}) at ({args.x}, {args.y})")
    else:
        pyautogui.click(button=button)
        pos = pyautogui.position()
        print(f"Clicked ({button}) at current position ({pos.x}, {pos.y})")


def cmd_doubleclick(args):
    """Double-click at position."""
    import pyautogui
    if args.x is not None and args.y is not None:
        pyautogui.doubleClick(args.x, args.y)
        print(f"Double-clicked at ({args.x}, {args.y})")
    else:
        pyautogui.doubleClick()
        pos = pyautogui.position()
        print(f"Double-clicked at current position ({pos.x}, {pos.y})")


def cmd_rightclick(args):
    """Right-click at position."""
    import pyautogui
    if args.x is not None and args.y is not None:
        pyautogui.rightClick(args.x, args.y)
        print(f"Right-clicked at ({args.x}, {args.y})")
    else:
        pyautogui.rightClick()
        pos = pyautogui.position()
        print(f"Right-clicked at current position ({pos.x}, {pos.y})")


def cmd_drag(args):
    """Drag from one position to another."""
    import pyautogui
    pyautogui.moveTo(args.x, args.y)
    pyautogui.drag(args.to_x - args.x, args.to_y - args.y, duration=0.5)
    print(f"Dragged from ({args.x}, {args.y}) to ({args.to_x}, {args.to_y})")


def cmd_scroll(args):
    """Scroll at position."""
    import pyautogui
    if args.x is not None and args.y is not None:
        pyautogui.moveTo(args.x, args.y)
    pyautogui.scroll(args.amount)
    direction = "up" if args.amount > 0 else "down"
    print(f"Scrolled {direction} by {abs(args.amount)} clicks")


def cmd_position(args):
    """Get current mouse position."""
    import pyautogui
    pos = pyautogui.position()
    screen = pyautogui.size()
    print(f"Mouse position: ({pos.x}, {pos.y})")
    print(f"Screen size: {screen.width}x{screen.height}")


def main():
    parser = argparse.ArgumentParser(description="Mouse control")
    subparsers = parser.add_subparsers(dest="action", required=True)

    # move
    p = subparsers.add_parser("move", help="Move mouse")
    p.add_argument("--x", type=int, required=True, help="X coordinate")
    p.add_argument("--y", type=int, required=True, help="Y coordinate")

    # click
    p = subparsers.add_parser("click", help="Click")
    p.add_argument("--x", type=int, help="X coordinate")
    p.add_argument("--y", type=int, help="Y coordinate")
    p.add_argument("--button", choices=["left", "right", "middle"], default="left")

    # doubleclick
    p = subparsers.add_parser("doubleclick", help="Double click")
    p.add_argument("--x", type=int, help="X coordinate")
    p.add_argument("--y", type=int, help="Y coordinate")

    # rightclick
    p = subparsers.add_parser("rightclick", help="Right click")
    p.add_argument("--x", type=int, help="X coordinate")
    p.add_argument("--y", type=int, help="Y coordinate")

    # drag
    p = subparsers.add_parser("drag", help="Drag")
    p.add_argument("--x", type=int, required=True, help="Start X")
    p.add_argument("--y", type=int, required=True, help="Start Y")
    p.add_argument("--to-x", type=int, required=True, help="End X")
    p.add_argument("--to-y", type=int, required=True, help="End Y")

    # scroll
    p = subparsers.add_parser("scroll", help="Scroll")
    p.add_argument("--amount", type=int, required=True, help="Scroll amount (+up, -down)")
    p.add_argument("--x", type=int, help="X coordinate")
    p.add_argument("--y", type=int, help="Y coordinate")

    # position
    subparsers.add_parser("position", help="Get mouse position")

    args = parser.parse_args()

    if not ensure_pyautogui():
        sys.exit(1)

    # Disable pyautogui fail-safe for automated use (move mouse to corner to abort)
    import pyautogui
    pyautogui.FAILSAFE = True  # Keep failsafe on: move mouse to top-left corner to abort
    pyautogui.PAUSE = 0.1

    actions = {
        "move": cmd_move,
        "click": cmd_click,
        "doubleclick": cmd_doubleclick,
        "rightclick": cmd_rightclick,
        "drag": cmd_drag,
        "scroll": cmd_scroll,
        "position": cmd_position,
    }

    try:
        actions[args.action](args)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
