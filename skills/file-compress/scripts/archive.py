#!/usr/bin/env python3
"""
Create, extract, and list ZIP and tar.gz archives.
Uses Python stdlib only -- no external dependencies.

Usage:
    archive.py create <archive_path> [--format zip|tar.gz] <files...>
    archive.py extract <archive_path> [--target dir]
    archive.py list <archive_path>
"""

import sys
import os
import argparse
import zipfile
import tarfile


def create_archive(archive_path, files, fmt):
    """Create a ZIP or tar.gz archive from the given files/directories."""
    if not files:
        print("Error: No files specified to archive", file=sys.stderr)
        sys.exit(1)

    # Verify all input files exist
    for f in files:
        if not os.path.exists(f):
            print(f"Error: File not found: {f}", file=sys.stderr)
            sys.exit(1)

    total_files = 0

    if fmt == "zip":
        with zipfile.ZipFile(archive_path, "w", zipfile.ZIP_DEFLATED) as zf:
            for path in files:
                if os.path.isdir(path):
                    for root, dirs, filenames in os.walk(path):
                        for filename in filenames:
                            filepath = os.path.join(root, filename)
                            arcname = os.path.relpath(filepath, os.path.dirname(path))
                            zf.write(filepath, arcname)
                            total_files += 1
                else:
                    zf.write(path, os.path.basename(path))
                    total_files += 1
    elif fmt == "tar.gz":
        with tarfile.open(archive_path, "w:gz") as tf:
            for path in files:
                if os.path.isdir(path):
                    tf.add(path, arcname=os.path.basename(path))
                    for root, dirs, filenames in os.walk(path):
                        total_files += len(filenames)
                else:
                    tf.add(path, arcname=os.path.basename(path))
                    total_files += 1
    else:
        print(f"Error: Unknown format '{fmt}'. Use 'zip' or 'tar.gz'.", file=sys.stderr)
        sys.exit(1)

    size = os.path.getsize(archive_path)
    print(f"Created {archive_path} ({total_files} files, {size:,} bytes)")


def extract_archive(archive_path, target_dir):
    """Extract an archive to the target directory."""
    if not os.path.exists(archive_path):
        print(f"Error: Archive not found: {archive_path}", file=sys.stderr)
        sys.exit(1)

    os.makedirs(target_dir, exist_ok=True)

    if zipfile.is_zipfile(archive_path):
        with zipfile.ZipFile(archive_path, "r") as zf:
            zf.extractall(target_dir)
            count = len(zf.namelist())
        print(f"Extracted {count} items from {archive_path} to {target_dir}")

    elif tarfile.is_tarfile(archive_path):
        with tarfile.open(archive_path, "r:*") as tf:
            tf.extractall(target_dir, filter="data")
            count = len(tf.getmembers())
        print(f"Extracted {count} items from {archive_path} to {target_dir}")

    else:
        print(f"Error: Unrecognized archive format: {archive_path}", file=sys.stderr)
        sys.exit(1)


def list_archive(archive_path):
    """List the contents of an archive."""
    if not os.path.exists(archive_path):
        print(f"Error: Archive not found: {archive_path}", file=sys.stderr)
        sys.exit(1)

    if zipfile.is_zipfile(archive_path):
        with zipfile.ZipFile(archive_path, "r") as zf:
            print(f"Contents of {archive_path} (ZIP):")
            print(f"{'Size':>10}  {'Compressed':>10}  Name")
            print("-" * 60)
            total_size = 0
            total_compressed = 0
            for info in zf.infolist():
                print(f"{info.file_size:>10,}  {info.compress_size:>10,}  {info.filename}")
                total_size += info.file_size
                total_compressed += info.compress_size
            print("-" * 60)
            print(f"{total_size:>10,}  {total_compressed:>10,}  ({len(zf.infolist())} items)")

    elif tarfile.is_tarfile(archive_path):
        with tarfile.open(archive_path, "r:*") as tf:
            print(f"Contents of {archive_path} (tar):")
            print(f"{'Size':>10}  {'Type':>6}  Name")
            print("-" * 60)
            total_size = 0
            members = tf.getmembers()
            for member in members:
                kind = "dir" if member.isdir() else "file"
                print(f"{member.size:>10,}  {kind:>6}  {member.name}")
                total_size += member.size
            print("-" * 60)
            print(f"{total_size:>10,}          ({len(members)} items)")

    else:
        print(f"Error: Unrecognized archive format: {archive_path}", file=sys.stderr)
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser(description="Create, extract, and list archives")
    subparsers = parser.add_subparsers(dest="action", required=True)

    # create
    create_parser = subparsers.add_parser("create", help="Create an archive")
    create_parser.add_argument("archive_path", help="Output archive file path")
    create_parser.add_argument("files", nargs="+", help="Files and directories to archive")
    create_parser.add_argument(
        "--format", "-f", default=None,
        choices=["zip", "tar.gz"],
        help="Archive format (auto-detected from extension if omitted)"
    )

    # extract
    extract_parser = subparsers.add_parser("extract", help="Extract an archive")
    extract_parser.add_argument("archive_path", help="Archive file to extract")
    extract_parser.add_argument(
        "--target", "-t", default=".",
        help="Target directory (default: current directory)"
    )

    # list
    list_parser = subparsers.add_parser("list", help="List archive contents")
    list_parser.add_argument("archive_path", help="Archive file to list")

    args = parser.parse_args()

    if args.action == "create":
        # Auto-detect format from extension
        fmt = args.format
        if fmt is None:
            if args.archive_path.endswith(".tar.gz") or args.archive_path.endswith(".tgz"):
                fmt = "tar.gz"
            else:
                fmt = "zip"
        create_archive(args.archive_path, args.files, fmt)

    elif args.action == "extract":
        extract_archive(args.archive_path, args.target)

    elif args.action == "list":
        list_archive(args.archive_path)


if __name__ == "__main__":
    main()
