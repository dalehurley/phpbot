#!/usr/bin/env python3
"""
Download video or extract audio from YouTube and other sites using yt-dlp.
Auto-installs yt-dlp if not available.

Usage:
    download.py <url> [--format video|audio] [--quality best|720|480|360] [--output dir] [--info]
"""

import sys
import os
import subprocess
import argparse
import json


def ensure_ytdlp():
    """Install yt-dlp if not available."""
    try:
        import yt_dlp  # noqa: F401
        return True
    except ImportError:
        print("Installing yt-dlp...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install", "yt-dlp", "-q"],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install yt-dlp: {result.stderr}", file=sys.stderr)
            return False
        print("yt-dlp installed.", file=sys.stderr)
        return True


def show_info(url):
    """Show video info without downloading."""
    import yt_dlp

    opts = {
        "quiet": True,
        "no_warnings": True,
    }

    with yt_dlp.YoutubeDL(opts) as ydl:
        info = ydl.extract_info(url, download=False)

    print(f"Title: {info.get('title', 'N/A')}")
    print(f"Duration: {info.get('duration_string', 'N/A')}")
    print(f"Uploader: {info.get('uploader', 'N/A')}")
    print(f"Upload date: {info.get('upload_date', 'N/A')}")
    print(f"View count: {info.get('view_count', 'N/A')}")
    print(f"Description: {(info.get('description', '') or '')[:200]}")

    # Available formats
    formats = info.get("formats", [])
    if formats:
        print("\nAvailable qualities:")
        seen = set()
        for f in formats:
            height = f.get("height")
            if height and height not in seen:
                seen.add(height)
                ext = f.get("ext", "?")
                print(f"  {height}p ({ext})")


def download(url, fmt="video", quality="best", output_dir=None):
    """Download video or audio."""
    import yt_dlp

    if output_dir:
        output_dir = os.path.expanduser(output_dir)
        os.makedirs(output_dir, exist_ok=True)
        outtmpl = os.path.join(output_dir, "%(title)s.%(ext)s")
    else:
        outtmpl = "%(title)s.%(ext)s"

    opts = {
        "outtmpl": outtmpl,
        "quiet": False,
        "no_warnings": False,
        "progress_hooks": [_progress_hook],
    }

    if fmt == "audio":
        opts["format"] = "bestaudio/best"
        opts["postprocessors"] = [{
            "key": "FFmpegExtractAudio",
            "preferredcodec": "mp3",
            "preferredquality": "192",
        }]
    else:
        # Video
        if quality == "best":
            opts["format"] = "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best"
        else:
            height = quality.rstrip("p")
            opts["format"] = (
                f"bestvideo[height<={height}][ext=mp4]+bestaudio[ext=m4a]/"
                f"best[height<={height}][ext=mp4]/"
                f"best[height<={height}]/best"
            )

    with yt_dlp.YoutubeDL(opts) as ydl:
        info = ydl.extract_info(url, download=True)

    title = info.get("title", "Unknown")
    duration = info.get("duration_string", "N/A")

    # Find the downloaded file
    if fmt == "audio":
        # After post-processing, extension changes to mp3
        filename = ydl.prepare_filename(info)
        base = os.path.splitext(filename)[0]
        actual_file = f"{base}.mp3"
        if not os.path.exists(actual_file):
            actual_file = filename  # Fallback
    else:
        actual_file = ydl.prepare_filename(info)

    if os.path.exists(actual_file):
        size = os.path.getsize(actual_file)
        print(f"\nDownloaded: {actual_file}")
        print(f"Title: {title}")
        print(f"Duration: {duration}")
        print(f"Size: {size:,} bytes ({size / 1024 / 1024:.1f} MB)")
    else:
        print(f"\nDownload completed: {title} ({duration})")


def _progress_hook(d):
    """Progress callback for yt-dlp."""
    if d["status"] == "downloading":
        pct = d.get("_percent_str", "?%")
        speed = d.get("_speed_str", "?")
        eta = d.get("_eta_str", "?")
        print(f"\r  Downloading: {pct} at {speed} ETA {eta}", end="", file=sys.stderr)
    elif d["status"] == "finished":
        print(f"\n  Download complete, processing...", file=sys.stderr)


def main():
    parser = argparse.ArgumentParser(description="Download video/audio using yt-dlp")
    parser.add_argument("url", help="Video/audio URL")
    parser.add_argument(
        "--format", "-f", default="video",
        choices=["video", "audio"],
        help="Download format (default: video)"
    )
    parser.add_argument(
        "--quality", "-q", default="best",
        help="Video quality: best, 720, 480, 360 (default: best)"
    )
    parser.add_argument("--output", "-o", help="Output directory")
    parser.add_argument("--info", "-i", action="store_true", help="Show info without downloading")

    args = parser.parse_args()

    if not ensure_ytdlp():
        sys.exit(1)

    try:
        if args.info:
            show_info(args.url)
        else:
            download(args.url, args.format, args.quality, args.output)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
