#!/usr/bin/env python3
"""
Manage tracked changes in a Word document (.docx).

Operations: list, accept, reject, summary.

Usage:
    python3 track_changes.py list document.docx
    python3 track_changes.py accept document.docx -o accepted.docx [--author "Jane Doe"]
    python3 track_changes.py reject document.docx -o reverted.docx [--author "Jane Doe"]
    python3 track_changes.py summary document.docx
"""

import sys
import os
import subprocess
import argparse
import json
import copy
from collections import defaultdict


def ensure_dependencies():
    """Install python-docx and lxml if not available."""
    try:
        import docx  # noqa: F401
        from lxml import etree  # noqa: F401
        return True
    except ImportError:
        print("Installing python-docx and lxml...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install", "python-docx", "lxml", "-q"],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install dependencies: {result.stderr}", file=sys.stderr)
            return False
        return True


W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
NSMAP = {'w': W_NS}


def collect_changes(doc):
    """Collect all tracked changes from the document body."""
    body = doc.element.body
    changes = []

    # Insertions
    for ins in body.iter(f'{{{W_NS}}}ins'):
        change_id = ins.get(f'{{{W_NS}}}id', '')
        author = ins.get(f'{{{W_NS}}}author', 'Unknown')
        date = ins.get(f'{{{W_NS}}}date', '')

        text_parts = []
        for t in ins.iter(f'{{{W_NS}}}t'):
            if t.text:
                text_parts.append(t.text)

        if text_parts:
            changes.append({
                "type": "insertion",
                "id": change_id,
                "author": author,
                "date": date,
                "text": "".join(text_parts),
                "element": ins,
            })

    # Deletions
    for dele in body.iter(f'{{{W_NS}}}del'):
        change_id = dele.get(f'{{{W_NS}}}id', '')
        author = dele.get(f'{{{W_NS}}}author', 'Unknown')
        date = dele.get(f'{{{W_NS}}}date', '')

        text_parts = []
        for dt in dele.iter(f'{{{W_NS}}}delText'):
            if dt.text:
                text_parts.append(dt.text)

        if text_parts:
            changes.append({
                "type": "deletion",
                "id": change_id,
                "author": author,
                "date": date,
                "text": "".join(text_parts),
                "element": dele,
            })

    # Format changes (rPrChange)
    for rpc in body.iter(f'{{{W_NS}}}rPrChange'):
        change_id = rpc.get(f'{{{W_NS}}}id', '')
        author = rpc.get(f'{{{W_NS}}}author', 'Unknown')
        date = rpc.get(f'{{{W_NS}}}date', '')

        parent_rpr = rpc.getparent()
        parent_run = parent_rpr.getparent() if parent_rpr is not None else None
        text_parts = []
        if parent_run is not None:
            for t in parent_run.iter(f'{{{W_NS}}}t'):
                if t.text:
                    text_parts.append(t.text)

        changes.append({
            "type": "format_change",
            "id": change_id,
            "author": author,
            "date": date,
            "text": "".join(text_parts) if text_parts else "(formatting only)",
            "element": rpc,
        })

    # Paragraph property changes (pPrChange)
    for ppc in body.iter(f'{{{W_NS}}}pPrChange'):
        change_id = ppc.get(f'{{{W_NS}}}id', '')
        author = ppc.get(f'{{{W_NS}}}author', 'Unknown')
        date = ppc.get(f'{{{W_NS}}}date', '')

        changes.append({
            "type": "paragraph_change",
            "id": change_id,
            "author": author,
            "date": date,
            "text": "(paragraph formatting change)",
            "element": ppc,
        })

    # Section property changes (sectPrChange)
    for spc in body.iter(f'{{{W_NS}}}sectPrChange'):
        change_id = spc.get(f'{{{W_NS}}}id', '')
        author = spc.get(f'{{{W_NS}}}author', 'Unknown')
        date = spc.get(f'{{{W_NS}}}date', '')

        changes.append({
            "type": "section_change",
            "id": change_id,
            "author": author,
            "date": date,
            "text": "(section formatting change)",
            "element": spc,
        })

    return changes


def list_changes(doc):
    """List all tracked changes."""
    changes = collect_changes(doc)

    if not changes:
        print("No tracked changes found in the document.")
        return

    for i, change in enumerate(changes):
        print(f"\n--- Change {i} (ID: {change['id']}, Type: {change['type']}) ---")
        print(f"  Author:  {change['author']}")
        print(f"  Date:    {change['date']}")
        text = change['text']
        if len(text) > 100:
            text = text[:97] + "..."
        print(f"  Text:    \"{text}\"")

    # Summary counts
    type_counts = defaultdict(int)
    for c in changes:
        type_counts[c['type']] += 1

    print(f"\nTotal: {len(changes)} change(s)")
    for ctype, count in sorted(type_counts.items()):
        print(f"  {ctype}: {count}")


def accept_changes(doc, output_path, author_filter=None):
    """Accept tracked changes: keep insertions, remove deletions."""
    from lxml import etree

    body = doc.element.body
    accepted_count = 0

    # Accept insertions: unwrap content (keep the runs, remove the <w:ins> wrapper)
    for ins in list(body.iter(f'{{{W_NS}}}ins')):
        ins_author = ins.get(f'{{{W_NS}}}author', '')
        if author_filter and ins_author != author_filter:
            continue

        parent = ins.getparent()
        if parent is None:
            continue

        # Move children of <w:ins> to parent, before the <w:ins> element
        idx = list(parent).index(ins)
        children = list(ins)
        for i, child in enumerate(children):
            ins.remove(child)
            parent.insert(idx + i, child)
        parent.remove(ins)
        accepted_count += 1

    # Accept deletions: remove the entire <w:del> element and its content
    for dele in list(body.iter(f'{{{W_NS}}}del')):
        del_author = dele.get(f'{{{W_NS}}}author', '')
        if author_filter and del_author != author_filter:
            continue

        parent = dele.getparent()
        if parent is not None:
            parent.remove(dele)
            accepted_count += 1

    # Accept paragraph mark deletions (w:del inside w:rPr inside w:pPr)
    for ppr in list(body.iter(f'{{{W_NS}}}pPr')):
        rpr = ppr.find(f'{{{W_NS}}}rPr')
        if rpr is not None:
            del_elem = rpr.find(f'{{{W_NS}}}del')
            if del_elem is not None:
                del_author = del_elem.get(f'{{{W_NS}}}author', '')
                if author_filter and del_author != author_filter:
                    continue
                rpr.remove(del_elem)
                # If rPr is now empty, remove it too
                if len(rpr) == 0:
                    ppr.remove(rpr)
                accepted_count += 1

    # Accept format changes: remove the rPrChange element (keep new formatting)
    for rpc in list(body.iter(f'{{{W_NS}}}rPrChange')):
        rpc_author = rpc.get(f'{{{W_NS}}}author', '')
        if author_filter and rpc_author != author_filter:
            continue

        parent = rpc.getparent()
        if parent is not None:
            parent.remove(rpc)
            accepted_count += 1

    # Accept paragraph property changes
    for ppc in list(body.iter(f'{{{W_NS}}}pPrChange')):
        ppc_author = ppc.get(f'{{{W_NS}}}author', '')
        if author_filter and ppc_author != author_filter:
            continue

        parent = ppc.getparent()
        if parent is not None:
            parent.remove(ppc)
            accepted_count += 1

    # Accept section property changes
    for spc in list(body.iter(f'{{{W_NS}}}sectPrChange')):
        spc_author = spc.get(f'{{{W_NS}}}author', '')
        if author_filter and spc_author != author_filter:
            continue

        parent = spc.getparent()
        if parent is not None:
            parent.remove(spc)
            accepted_count += 1

    doc.save(output_path)
    filter_msg = f" by {author_filter}" if author_filter else ""
    print(f"Accepted {accepted_count} change(s){filter_msg}. Saved to: {output_path}")


def reject_changes(doc, output_path, author_filter=None):
    """Reject tracked changes: remove insertions, keep deletions as normal text."""
    from lxml import etree

    body = doc.element.body
    rejected_count = 0

    # Reject insertions: remove the <w:ins> element entirely
    for ins in list(body.iter(f'{{{W_NS}}}ins')):
        ins_author = ins.get(f'{{{W_NS}}}author', '')
        if author_filter and ins_author != author_filter:
            continue

        parent = ins.getparent()
        if parent is not None:
            parent.remove(ins)
            rejected_count += 1

    # Reject deletions: unwrap content (convert delText back to t, remove <w:del> wrapper)
    for dele in list(body.iter(f'{{{W_NS}}}del')):
        del_author = dele.get(f'{{{W_NS}}}author', '')
        if author_filter and del_author != author_filter:
            continue

        parent = dele.getparent()
        if parent is None:
            continue

        idx = list(parent).index(dele)
        children = list(dele)

        for i, child in enumerate(children):
            # Convert <w:delText> to <w:t> in runs
            for del_text in child.iter(f'{{{W_NS}}}delText'):
                del_text.tag = f'{{{W_NS}}}t'
            dele.remove(child)
            parent.insert(idx + i, child)

        parent.remove(dele)
        rejected_count += 1

    # Reject paragraph mark deletions: remove the del marker (keep paragraph mark)
    for ppr in list(body.iter(f'{{{W_NS}}}pPr')):
        rpr = ppr.find(f'{{{W_NS}}}rPr')
        if rpr is not None:
            del_elem = rpr.find(f'{{{W_NS}}}del')
            if del_elem is not None:
                del_author = del_elem.get(f'{{{W_NS}}}author', '')
                if author_filter and del_author != author_filter:
                    continue
                rpr.remove(del_elem)
                if len(rpr) == 0:
                    ppr.remove(rpr)
                rejected_count += 1

    # Reject format changes: restore old formatting from rPrChange
    for rpc in list(body.iter(f'{{{W_NS}}}rPrChange')):
        rpc_author = rpc.get(f'{{{W_NS}}}author', '')
        if author_filter and rpc_author != author_filter:
            continue

        parent_rpr = rpc.getparent()
        if parent_rpr is not None:
            # The old formatting is stored in the children of rPrChange
            old_rpr_children = list(rpc)
            # Clear current rPr and replace with old formatting
            for child in list(parent_rpr):
                parent_rpr.remove(child)
            for child in old_rpr_children:
                rpc.remove(child)
                parent_rpr.append(child)
            rejected_count += 1

    # Reject paragraph property changes
    for ppc in list(body.iter(f'{{{W_NS}}}pPrChange')):
        ppc_author = ppc.get(f'{{{W_NS}}}author', '')
        if author_filter and ppc_author != author_filter:
            continue

        parent_ppr = ppc.getparent()
        if parent_ppr is not None:
            old_children = list(ppc)
            for child in list(parent_ppr):
                parent_ppr.remove(child)
            for child in old_children:
                ppc.remove(child)
                parent_ppr.append(child)
            rejected_count += 1

    doc.save(output_path)
    filter_msg = f" by {author_filter}" if author_filter else ""
    print(f"Rejected {rejected_count} change(s){filter_msg}. Saved to: {output_path}")


def summarize_changes(doc):
    """Generate a human-readable summary of all tracked changes."""
    changes = collect_changes(doc)

    if not changes:
        print("No tracked changes found in the document.")
        return

    # Group by author
    by_author = defaultdict(list)
    for c in changes:
        by_author[c['author']].append(c)

    print("=" * 60)
    print("TRACKED CHANGES SUMMARY")
    print("=" * 60)

    total_insertions = sum(1 for c in changes if c['type'] == 'insertion')
    total_deletions = sum(1 for c in changes if c['type'] == 'deletion')
    total_format = sum(1 for c in changes if c['type'] == 'format_change')
    total_para = sum(1 for c in changes if c['type'] == 'paragraph_change')
    total_section = sum(1 for c in changes if c['type'] == 'section_change')

    print(f"\nTotal changes: {len(changes)}")
    print(f"  Insertions:           {total_insertions}")
    print(f"  Deletions:            {total_deletions}")
    print(f"  Format changes:       {total_format}")
    print(f"  Paragraph changes:    {total_para}")
    print(f"  Section changes:      {total_section}")
    print(f"  Authors:              {len(by_author)}")

    for author, author_changes in sorted(by_author.items()):
        print(f"\n--- {author} ({len(author_changes)} changes) ---")

        a_ins = [c for c in author_changes if c['type'] == 'insertion']
        a_del = [c for c in author_changes if c['type'] == 'deletion']
        a_fmt = [c for c in author_changes if c['type'] in ('format_change', 'paragraph_change', 'section_change')]

        if a_ins:
            print(f"\n  Insertions ({len(a_ins)}):")
            for c in a_ins[:10]:  # Show first 10
                text = c['text']
                if len(text) > 60:
                    text = text[:57] + "..."
                print(f"    + \"{text}\"")
            if len(a_ins) > 10:
                print(f"    ... and {len(a_ins) - 10} more")

        if a_del:
            print(f"\n  Deletions ({len(a_del)}):")
            for c in a_del[:10]:
                text = c['text']
                if len(text) > 60:
                    text = text[:57] + "..."
                print(f"    - \"{text}\"")
            if len(a_del) > 10:
                print(f"    ... and {len(a_del) - 10} more")

        if a_fmt:
            print(f"\n  Formatting changes ({len(a_fmt)}):")
            for c in a_fmt[:5]:
                print(f"    ~ {c['text']}")
            if len(a_fmt) > 5:
                print(f"    ... and {len(a_fmt) - 5} more")

    # Date range
    dates = [c['date'] for c in changes if c['date']]
    if dates:
        dates.sort()
        print(f"\nDate range: {dates[0]} to {dates[-1]}")


def main():
    parser = argparse.ArgumentParser(description="Manage tracked changes in a Word document")
    subparsers = parser.add_subparsers(dest="command", help="Command to run")

    # list
    list_parser = subparsers.add_parser("list", help="List all tracked changes")
    list_parser.add_argument("file_path", help="Path to .docx file")

    # accept
    accept_parser = subparsers.add_parser("accept", help="Accept tracked changes")
    accept_parser.add_argument("file_path", help="Path to .docx file")
    accept_parser.add_argument("-o", "--output", required=True, help="Output file path")
    accept_parser.add_argument("--author", default=None, help="Only accept changes by this author")

    # reject
    reject_parser = subparsers.add_parser("reject", help="Reject tracked changes")
    reject_parser.add_argument("file_path", help="Path to .docx file")
    reject_parser.add_argument("-o", "--output", required=True, help="Output file path")
    reject_parser.add_argument("--author", default=None, help="Only reject changes by this author")

    # summary
    summary_parser = subparsers.add_parser("summary", help="Summarize tracked changes")
    summary_parser.add_argument("file_path", help="Path to .docx file")

    args = parser.parse_args()

    if not args.command:
        parser.print_help()
        sys.exit(1)

    file_path = os.path.expanduser(args.file_path)
    file_path = os.path.abspath(file_path)

    if not os.path.exists(file_path):
        print(f"Error: File not found: {file_path}", file=sys.stderr)
        sys.exit(1)

    if not ensure_dependencies():
        sys.exit(1)

    from docx import Document

    try:
        doc = Document(file_path)
    except Exception as e:
        print(f"Error: Could not open document: {e}", file=sys.stderr)
        sys.exit(1)

    if args.command == "list":
        list_changes(doc)
    elif args.command == "accept":
        output_path = os.path.expanduser(args.output)
        accept_changes(doc, output_path, author_filter=args.author)
    elif args.command == "reject":
        output_path = os.path.expanduser(args.output)
        reject_changes(doc, output_path, author_filter=args.author)
    elif args.command == "summary":
        summarize_changes(doc)


if __name__ == "__main__":
    main()
