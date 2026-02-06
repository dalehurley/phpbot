#!/usr/bin/env python3
"""
Convert documents between formats using pandoc.

Supports: Markdown <-> DOCX, DOCX -> PDF, HTML <-> DOCX, and more.

Usage:
    python3 convert.py input.md -f md -t docx -o output.docx
    python3 convert.py input.docx -f docx -t md -o output.md
    python3 convert.py input.docx -f docx -t pdf -o output.pdf
    python3 convert.py input.html -f html -t docx -o output.docx
    python3 convert.py input.docx -f docx -t html -o output.html
    python3 convert.py input.md -f md -t docx -o output.docx --reference template.docx
    python3 convert.py input.docx -f docx -t md --track-changes all
"""

import sys
import os
import subprocess
import argparse
import shutil


FORMAT_EXTENSIONS = {
    'md': '.md',
    'markdown': '.md',
    'docx': '.docx',
    'pdf': '.pdf',
    'html': '.html',
    'htm': '.html',
    'txt': '.txt',
    'rst': '.rst',
    'latex': '.tex',
    'tex': '.tex',
    'rtf': '.rtf',
    'odt': '.odt',
    'epub': '.epub',
}

# Map short names to pandoc format names
PANDOC_FORMAT_MAP = {
    'md': 'markdown',
    'markdown': 'markdown',
    'docx': 'docx',
    'pdf': 'pdf',
    'html': 'html',
    'htm': 'html',
    'txt': 'plain',
    'rst': 'rst',
    'latex': 'latex',
    'tex': 'latex',
    'rtf': 'rtf',
    'odt': 'odt',
    'epub': 'epub',
}


def check_pandoc():
    """Verify pandoc is installed and return its version."""
    try:
        result = subprocess.run(
            ['pandoc', '--version'],
            capture_output=True, text=True,
        )
        if result.returncode == 0:
            version_line = result.stdout.split('\n')[0]
            return version_line
        return None
    except FileNotFoundError:
        return None


def detect_format(file_path):
    """Detect format from file extension."""
    _, ext = os.path.splitext(file_path)
    ext = ext.lower().lstrip('.')

    for fmt, fext in FORMAT_EXTENSIONS.items():
        if fext.lstrip('.') == ext:
            return fmt

    return None


def convert(input_path, from_fmt, to_fmt, output_path, reference_doc=None,
            track_changes=None, standalone=True, extra_args=None):
    """Convert a file using pandoc."""
    pandoc_from = PANDOC_FORMAT_MAP.get(from_fmt, from_fmt)
    pandoc_to = PANDOC_FORMAT_MAP.get(to_fmt, to_fmt)

    cmd = ['pandoc']

    # Input format
    cmd.extend(['-f', pandoc_from])

    # Output format
    cmd.extend(['-t', pandoc_to])

    # Standalone for full documents
    if standalone and pandoc_to not in ('plain',):
        cmd.append('-s')

    # Reference document for DOCX styling
    if reference_doc and pandoc_to == 'docx':
        cmd.extend(['--reference-doc', reference_doc])

    # Track changes handling for DOCX input
    if track_changes and pandoc_from == 'docx':
        cmd.extend(['--track-changes', track_changes])

    # PDF-specific options
    if pandoc_to == 'pdf':
        # Try to use a good PDF engine
        for engine in ['xelatex', 'pdflatex', 'wkhtmltopdf', 'weasyprint']:
            if shutil.which(engine):
                cmd.extend(['--pdf-engine', engine])
                break

    # Output file
    if output_path:
        cmd.extend(['-o', output_path])

    # Extra arguments
    if extra_args:
        cmd.extend(extra_args)

    # Input file
    cmd.append(input_path)

    print(f"Running: {' '.join(cmd)}", file=sys.stderr)

    result = subprocess.run(cmd, capture_output=True, text=True)

    if result.returncode != 0:
        print(f"Error: pandoc conversion failed:\n{result.stderr}", file=sys.stderr)
        return False

    if result.stderr:
        print(f"Warnings: {result.stderr}", file=sys.stderr)

    if not output_path:
        # Print to stdout
        print(result.stdout)

    return True


def main():
    parser = argparse.ArgumentParser(
        description="Convert documents between formats using pandoc"
    )
    parser.add_argument("input_path", help="Input file path")
    parser.add_argument("-f", "--from", dest="from_fmt",
                        help="Source format (auto-detected from extension if omitted)")
    parser.add_argument("-t", "--to", dest="to_fmt",
                        help="Target format (auto-detected from output extension if omitted)")
    parser.add_argument("-o", "--output", default=None,
                        help="Output file path (default: stdout)")
    parser.add_argument("--reference", default=None,
                        help="Reference DOCX for styling (only for DOCX output)")
    parser.add_argument("--track-changes",
                        choices=["accept", "reject", "all"],
                        default=None,
                        help="How to handle track changes in DOCX input")
    parser.add_argument("--no-standalone", action="store_true",
                        help="Don't produce a standalone document")
    parser.add_argument("--extra-args", nargs=argparse.REMAINDER, default=None,
                        help="Additional arguments to pass to pandoc")

    args = parser.parse_args()

    # Check pandoc
    pandoc_version = check_pandoc()
    if not pandoc_version:
        print("Error: pandoc is not installed. Install via: brew install pandoc", file=sys.stderr)
        sys.exit(1)
    print(f"Using {pandoc_version}", file=sys.stderr)

    input_path = os.path.expanduser(args.input_path)
    input_path = os.path.abspath(input_path)

    if not os.path.exists(input_path):
        print(f"Error: File not found: {input_path}", file=sys.stderr)
        sys.exit(1)

    # Detect formats
    from_fmt = args.from_fmt
    if not from_fmt:
        from_fmt = detect_format(input_path)
        if not from_fmt:
            print("Error: Could not detect input format. Use -f to specify.", file=sys.stderr)
            sys.exit(1)
        print(f"Detected input format: {from_fmt}", file=sys.stderr)

    to_fmt = args.to_fmt
    if not to_fmt and args.output:
        to_fmt = detect_format(args.output)
    if not to_fmt:
        print("Error: Could not detect output format. Use -t to specify or provide -o with extension.",
              file=sys.stderr)
        sys.exit(1)

    # Resolve output path
    output_path = None
    if args.output:
        output_path = os.path.expanduser(args.output)
        output_path = os.path.abspath(output_path)
        os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)

    # Resolve reference doc
    reference_doc = None
    if args.reference:
        reference_doc = os.path.expanduser(args.reference)
        reference_doc = os.path.abspath(reference_doc)
        if not os.path.exists(reference_doc):
            print(f"Error: Reference document not found: {reference_doc}", file=sys.stderr)
            sys.exit(1)

    standalone = not args.no_standalone

    success = convert(
        input_path=input_path,
        from_fmt=from_fmt,
        to_fmt=to_fmt,
        output_path=output_path,
        reference_doc=reference_doc,
        track_changes=args.track_changes,
        standalone=standalone,
        extra_args=args.extra_args,
    )

    if success:
        if output_path:
            file_size = os.path.getsize(output_path)
            print(f"Converted {from_fmt} -> {to_fmt}: {output_path} ({file_size:,} bytes)", file=sys.stderr)
    else:
        sys.exit(1)


if __name__ == "__main__":
    main()
