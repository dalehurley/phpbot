#!/usr/bin/env python3
"""
Fetch a URL and extract clean readable content.
Uses trafilatura for article extraction with requests as HTTP client.

Usage:
    scrape.py <url> [--format markdown|text|html] [--output path] [--metadata]
"""

import sys
import os
import subprocess
import argparse


def ensure_dependencies():
    """Install trafilatura and requests if not available."""
    missing = []
    try:
        import trafilatura  # noqa: F401
    except ImportError:
        missing.append("trafilatura")
    try:
        import requests  # noqa: F401
    except ImportError:
        missing.append("requests")

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


def fetch_url(url):
    """Fetch URL content using requests."""
    import requests

    headers = {
        "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
                       "AppleWebKit/537.36 (KHTML, like Gecko) "
                       "Chrome/120.0.0.0 Safari/537.36"
    }

    response = requests.get(url, headers=headers, timeout=30)
    response.raise_for_status()
    return response.text


def extract_content(html, url, output_format="markdown", include_metadata=False):
    """Extract clean content from HTML using trafilatura."""
    import trafilatura

    # Configure extraction options
    kwargs = {
        "include_links": True,
        "include_images": False,
        "include_tables": True,
        "no_fallback": False,
        "url": url,
    }

    if output_format == "markdown":
        kwargs["output_format"] = "markdown"
    elif output_format == "html":
        kwargs["output_format"] = "xml"
    else:
        kwargs["output_format"] = "txt"

    content = trafilatura.extract(html, **kwargs)

    if not content:
        # Fallback: try with different settings
        content = trafilatura.extract(html, no_fallback=False, url=url)

    if not content:
        return None, None

    metadata = None
    if include_metadata:
        meta = trafilatura.extract(html, url=url, output_format="json", no_fallback=False)
        if meta:
            import json
            try:
                metadata = json.loads(meta)
            except (json.JSONDecodeError, TypeError):
                pass

    return content, metadata


def main():
    parser = argparse.ArgumentParser(description="Fetch URL and extract clean content")
    parser.add_argument("url", help="URL to scrape")
    parser.add_argument(
        "--format", "-f", default="markdown",
        choices=["markdown", "text", "html"],
        help="Output format (default: markdown)"
    )
    parser.add_argument("--output", "-o", help="Save output to file")
    parser.add_argument("--metadata", "-m", action="store_true", help="Include metadata (title, author, date)")

    args = parser.parse_args()

    if not ensure_dependencies():
        sys.exit(1)

    # Fetch the page
    try:
        print(f"Fetching: {args.url}", file=sys.stderr)
        html = fetch_url(args.url)
    except Exception as e:
        print(f"Error fetching URL: {e}", file=sys.stderr)
        sys.exit(1)

    # Extract content
    try:
        content, metadata = extract_content(html, args.url, args.format, args.metadata)
    except Exception as e:
        print(f"Error extracting content: {e}", file=sys.stderr)
        sys.exit(1)

    if not content:
        print("Error: Could not extract content from the page", file=sys.stderr)
        sys.exit(1)

    # Build output
    output_parts = []

    if metadata and args.metadata:
        if metadata.get("title"):
            output_parts.append(f"# {metadata['title']}")
        meta_fields = []
        if metadata.get("author"):
            meta_fields.append(f"Author: {metadata['author']}")
        if metadata.get("date"):
            meta_fields.append(f"Date: {metadata['date']}")
        if metadata.get("sitename"):
            meta_fields.append(f"Source: {metadata['sitename']}")
        if meta_fields:
            output_parts.append("\n".join(meta_fields))
        output_parts.append("---")

    output_parts.append(content)
    full_output = "\n\n".join(output_parts)

    # Save or print
    if args.output:
        output_path = os.path.expanduser(args.output)
        output_path = os.path.abspath(output_path)
        os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)
        with open(output_path, "w", encoding="utf-8") as f:
            f.write(full_output)
        print(f"Content saved: {output_path} ({len(full_output):,} chars)", file=sys.stderr)
    else:
        print(full_output)


if __name__ == "__main__":
    main()
