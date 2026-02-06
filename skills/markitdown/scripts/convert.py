#!/usr/bin/env python3
"""
Convert any document to Markdown using Microsoft's MarkItDown library.
https://github.com/microsoft/markitdown

Supports: PDF, DOCX, PPTX, XLSX, HTML, Images, Audio, CSV, JSON, XML, ZIP, EPub, and more.

Usage:
    python3 convert.py <file_path> [-o output_path] [-v]
"""

import sys
import os
import subprocess
import argparse


def ensure_markitdown():
    """Install markitdown if not already available."""
    try:
        import markitdown  # noqa: F401
        return True
    except ImportError:
        print("Installing markitdown[all]...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install", "markitdown[all]", "-q"],
            capture_output=True,
            text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install markitdown: {result.stderr}", file=sys.stderr)
            return False
        print("markitdown installed successfully.", file=sys.stderr)
        return True


def convert_file(file_path: str, verbose: bool = False) -> str:
    """Convert a file to Markdown and return the text content."""
    from markitdown import MarkItDown

    if not os.path.exists(file_path):
        raise FileNotFoundError(f"File not found: {file_path}")

    md = MarkItDown(enable_plugins=False)
    result = md.convert(file_path)

    output_parts = []

    if verbose:
        output_parts.append(f"<!-- Source: {os.path.basename(file_path)} -->")
        output_parts.append(f"<!-- Size: {os.path.getsize(file_path)} bytes -->")
        output_parts.append("")

    output_parts.append(result.text_content)

    return "\n".join(output_parts)


def main():
    parser = argparse.ArgumentParser(
        description="Convert any document to Markdown using MarkItDown"
    )
    parser.add_argument("file_path", help="Path to the file to convert")
    parser.add_argument(
        "-o", "--output", help="Output file path (default: stdout)", default=None
    )
    parser.add_argument(
        "-v", "--verbose", action="store_true", help="Include metadata in output"
    )

    args = parser.parse_args()

    # Resolve file path
    file_path = os.path.expanduser(args.file_path)
    file_path = os.path.abspath(file_path)

    if not os.path.exists(file_path):
        print(f"Error: File not found: {file_path}", file=sys.stderr)
        sys.exit(1)

    # Ensure markitdown is installed
    if not ensure_markitdown():
        sys.exit(1)

    try:
        markdown_content = convert_file(file_path, verbose=args.verbose)
    except Exception as e:
        print(f"Error converting file: {e}", file=sys.stderr)
        sys.exit(1)

    # Output
    if args.output:
        output_path = os.path.expanduser(args.output)
        output_path = os.path.abspath(output_path)
        os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)
        with open(output_path, "w", encoding="utf-8") as f:
            f.write(markdown_content)
        print(f"Markdown written to: {output_path}", file=sys.stderr)
    else:
        print(markdown_content)


if __name__ == "__main__":
    main()
