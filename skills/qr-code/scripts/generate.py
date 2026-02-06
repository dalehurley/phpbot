#!/usr/bin/env python3
"""
Generate QR codes as PNG images.

Usage:
    generate.py <content> [--output path] [--size pixels] [--color color] [--bg color]
"""

import sys
import os
import subprocess
import argparse


def ensure_dependencies():
    """Install qrcode and Pillow if not available."""
    missing = []
    try:
        import qrcode  # noqa: F401
    except ImportError:
        missing.append("qrcode")
    try:
        from PIL import Image  # noqa: F401
    except ImportError:
        missing.append("Pillow")

    if missing:
        print(f"Installing {', '.join(missing)}...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install"] + missing + ["-q"],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install dependencies: {result.stderr}", file=sys.stderr)
            return False
        print("Dependencies installed.", file=sys.stderr)
    return True


def generate_qr(content, output_path, box_size=10, fill_color="black", back_color="white"):
    """Generate a QR code and save as PNG."""
    import qrcode

    qr = qrcode.QRCode(
        version=None,  # Auto-determine
        error_correction=qrcode.constants.ERROR_CORRECT_H,
        box_size=box_size,
        border=4,
    )
    qr.add_data(content)
    qr.make(fit=True)

    img = qr.make_image(fill_color=fill_color, back_color=back_color)

    os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)
    img.save(output_path)

    size = os.path.getsize(output_path)
    width, height = img.size
    print(f"QR code saved: {output_path} ({width}x{height}px, {size:,} bytes)")
    print(f"Content: {content[:100]}{'...' if len(content) > 100 else ''}")


def main():
    parser = argparse.ArgumentParser(description="Generate QR codes as PNG images")
    parser.add_argument("content", help="Text, URL, or data to encode")
    parser.add_argument("--output", "-o", default="./qrcode.png", help="Output PNG path (default: ./qrcode.png)")
    parser.add_argument("--size", "-s", type=int, default=10, help="Box size in pixels (default: 10)")
    parser.add_argument("--color", "-c", default="black", help="QR code color (default: black)")
    parser.add_argument("--bg", default="white", help="Background color (default: white)")

    args = parser.parse_args()

    if not ensure_dependencies():
        sys.exit(1)

    output_path = os.path.expanduser(args.output)
    output_path = os.path.abspath(output_path)

    try:
        generate_qr(args.content, output_path, args.size, args.color, args.bg)
    except Exception as e:
        print(f"Error generating QR code: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
