#!/usr/bin/env python3
"""
Image processing: resize, compress, convert, crop, info, and rotate.
Uses Pillow (auto-installed if missing).

Usage:
    process.py resize <input> --width W [--height H] --output <path>
    process.py compress <input> --quality Q --output <path>
    process.py convert <input> --format fmt --output <path>
    process.py crop <input> --box left,top,right,bottom --output <path>
    process.py info <input>
    process.py rotate <input> --angle degrees --output <path>
"""

import sys
import os
import subprocess
import argparse


def ensure_pillow():
    """Install Pillow if not available."""
    try:
        from PIL import Image  # noqa: F401
        return True
    except ImportError:
        print("Installing Pillow...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install", "Pillow", "-q"],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install Pillow: {result.stderr}", file=sys.stderr)
            return False
        print("Pillow installed.", file=sys.stderr)
        return True


def save_image(img, output_path, quality=85):
    """Save image with appropriate settings for the format."""
    os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)
    ext = os.path.splitext(output_path)[1].lower()

    save_kwargs = {}
    if ext in (".jpg", ".jpeg"):
        if img.mode in ("RGBA", "P"):
            img = img.convert("RGB")
        save_kwargs["quality"] = quality
        save_kwargs["optimize"] = True
    elif ext == ".webp":
        save_kwargs["quality"] = quality
    elif ext == ".png":
        save_kwargs["optimize"] = True

    img.save(output_path, **save_kwargs)
    size = os.path.getsize(output_path)
    print(f"Saved: {output_path} ({img.width}x{img.height}, {size:,} bytes)")


def cmd_resize(args):
    """Resize an image."""
    from PIL import Image

    img = Image.open(args.input)
    orig_w, orig_h = img.size

    width = args.width
    height = args.height

    if width and not height:
        ratio = width / orig_w
        height = int(orig_h * ratio)
    elif height and not width:
        ratio = height / orig_h
        width = int(orig_w * ratio)
    elif not width and not height:
        print("Error: Provide --width and/or --height", file=sys.stderr)
        sys.exit(1)

    resized = img.resize((width, height), Image.LANCZOS)
    output = args.output or f"resized_{os.path.basename(args.input)}"
    save_image(resized, output)
    print(f"Resized: {orig_w}x{orig_h} -> {width}x{height}")


def cmd_compress(args):
    """Compress an image (reduce quality/file size)."""
    from PIL import Image

    img = Image.open(args.input)
    orig_size = os.path.getsize(args.input)
    quality = args.quality or 80
    output = args.output or f"compressed_{os.path.basename(args.input)}"

    save_image(img, output, quality=quality)
    new_size = os.path.getsize(output)
    reduction = ((orig_size - new_size) / orig_size) * 100 if orig_size > 0 else 0
    print(f"Compressed: {orig_size:,} -> {new_size:,} bytes ({reduction:.1f}% reduction, quality={quality})")


def cmd_convert(args):
    """Convert image format."""
    from PIL import Image

    img = Image.open(args.input)
    fmt = args.format.lower()

    # Determine output path
    if args.output:
        output = args.output
    else:
        base = os.path.splitext(args.input)[0]
        ext_map = {"jpeg": ".jpg", "jpg": ".jpg", "png": ".png", "webp": ".webp",
                    "gif": ".gif", "bmp": ".bmp", "tiff": ".tiff"}
        ext = ext_map.get(fmt, f".{fmt}")
        output = f"{base}{ext}"

    save_image(img, output)
    print(f"Converted: {args.input} -> {output} (format: {fmt})")


def cmd_crop(args):
    """Crop an image."""
    from PIL import Image

    img = Image.open(args.input)

    try:
        parts = [int(x.strip()) for x in args.box.split(",")]
        if len(parts) != 4:
            raise ValueError
        left, top, right, bottom = parts
    except (ValueError, AttributeError):
        print("Error: --box must be four integers: left,top,right,bottom", file=sys.stderr)
        sys.exit(1)

    if right > img.width or bottom > img.height:
        print(f"Warning: Crop box exceeds image dimensions ({img.width}x{img.height})", file=sys.stderr)

    cropped = img.crop((left, top, right, bottom))
    output = args.output or f"cropped_{os.path.basename(args.input)}"
    save_image(cropped, output)
    print(f"Cropped: ({left},{top}) to ({right},{bottom}) = {cropped.width}x{cropped.height}")


def cmd_info(args):
    """Show image information."""
    from PIL import Image
    from PIL.ExifTags import TAGS

    img = Image.open(args.input)
    file_size = os.path.getsize(args.input)

    print(f"File: {args.input}")
    print(f"Format: {img.format}")
    print(f"Mode: {img.mode}")
    print(f"Size: {img.width}x{img.height} pixels")
    print(f"File size: {file_size:,} bytes ({file_size / 1024:.1f} KB)")

    if hasattr(img, "n_frames"):
        print(f"Frames: {img.n_frames}")

    # EXIF data
    try:
        exif = img._getexif()
        if exif:
            print("\nEXIF data:")
            for tag_id, value in exif.items():
                tag = TAGS.get(tag_id, tag_id)
                if isinstance(value, bytes):
                    value = f"<{len(value)} bytes>"
                elif isinstance(value, str) and len(value) > 100:
                    value = value[:100] + "..."
                print(f"  {tag}: {value}")
    except (AttributeError, Exception):
        pass


def cmd_rotate(args):
    """Rotate an image."""
    from PIL import Image

    img = Image.open(args.input)
    angle = args.angle or 90

    rotated = img.rotate(angle, expand=True)
    output = args.output or f"rotated_{os.path.basename(args.input)}"
    save_image(rotated, output)
    print(f"Rotated: {angle} degrees")


def main():
    parser = argparse.ArgumentParser(description="Image processing tools")
    subparsers = parser.add_subparsers(dest="action", required=True)

    # resize
    p = subparsers.add_parser("resize", help="Resize an image")
    p.add_argument("input", help="Input image path")
    p.add_argument("--width", "-W", type=int, help="Target width")
    p.add_argument("--height", "-H", type=int, help="Target height")
    p.add_argument("--output", "-o", help="Output path")

    # compress
    p = subparsers.add_parser("compress", help="Compress an image")
    p.add_argument("input", help="Input image path")
    p.add_argument("--quality", "-q", type=int, default=80, help="Quality 1-100 (default: 80)")
    p.add_argument("--output", "-o", help="Output path")

    # convert
    p = subparsers.add_parser("convert", help="Convert image format")
    p.add_argument("input", help="Input image path")
    p.add_argument("--format", "-f", required=True, help="Target format (jpg, png, webp, gif, bmp, tiff)")
    p.add_argument("--output", "-o", help="Output path")

    # crop
    p = subparsers.add_parser("crop", help="Crop an image")
    p.add_argument("input", help="Input image path")
    p.add_argument("--box", "-b", required=True, help="Crop box: left,top,right,bottom")
    p.add_argument("--output", "-o", help="Output path")

    # info
    p = subparsers.add_parser("info", help="Show image info")
    p.add_argument("input", help="Input image path")

    # rotate
    p = subparsers.add_parser("rotate", help="Rotate an image")
    p.add_argument("input", help="Input image path")
    p.add_argument("--angle", "-a", type=float, default=90, help="Rotation angle in degrees (default: 90)")
    p.add_argument("--output", "-o", help="Output path")

    args = parser.parse_args()

    # Validate input file exists (except for info which gives a better error)
    input_path = os.path.expanduser(args.input)
    args.input = os.path.abspath(input_path)
    if not os.path.exists(args.input):
        print(f"Error: File not found: {args.input}", file=sys.stderr)
        sys.exit(1)

    if not ensure_pillow():
        sys.exit(1)

    actions = {
        "resize": cmd_resize,
        "compress": cmd_compress,
        "convert": cmd_convert,
        "crop": cmd_crop,
        "info": cmd_info,
        "rotate": cmd_rotate,
    }
    actions[args.action](args)


if __name__ == "__main__":
    main()
