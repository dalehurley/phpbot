#!/usr/bin/env python3
"""
Manage comments in a Word document (.docx).

Operations: list, add, remove, export.

Usage:
    python3 comments.py list document.docx
    python3 comments.py add document.docx -t "search text" -c "Comment body" [-a "Author"] [-o output.docx]
    python3 comments.py remove document.docx [-o output.docx] [--index N]
    python3 comments.py export document.docx [-o comments.json] [--format json|csv]
"""

import sys
import os
import subprocess
import argparse
import json
import csv
import copy
import io
from datetime import datetime, timezone


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
W15_NS = 'http://schemas.microsoft.com/office/word/2012/wordml'
NSMAP = {'w': W_NS, 'w15': W15_NS}


def get_comments_part(doc):
    """Get the comments XML part from the document."""
    for rel in doc.part.rels.values():
        if 'comments' in rel.reltype and 'Extended' not in rel.reltype and 'Ids' not in rel.reltype:
            return rel.target_part
    return None


def parse_comments(doc):
    """Parse all comments from the document."""
    from lxml import etree

    comments_part = get_comments_part(doc)
    if comments_part is None:
        return []

    root = etree.fromstring(comments_part.blob)
    comments = []

    for comment_elem in root.findall('.//w:comment', NSMAP):
        comment_id = comment_elem.get(f'{{{W_NS}}}id')
        author = comment_elem.get(f'{{{W_NS}}}author', 'Unknown')
        date = comment_elem.get(f'{{{W_NS}}}date', '')
        initials = comment_elem.get(f'{{{W_NS}}}initials', '')

        text_parts = []
        for p in comment_elem.findall('.//w:p', NSMAP):
            p_texts = []
            for r in p.findall('.//w:r', NSMAP):
                for t in r.findall('.//w:t', NSMAP):
                    if t.text:
                        p_texts.append(t.text)
            text_parts.append("".join(p_texts))

        # Find the commented text range in the document body
        commented_text = find_commented_text(doc, comment_id)

        comments.append({
            "id": comment_id,
            "author": author,
            "date": date,
            "initials": initials,
            "text": "\n".join(text_parts),
            "commented_text": commented_text,
        })

    return comments


def find_commented_text(doc, comment_id):
    """Find the text that a comment references in the document body."""
    body = doc.element.body
    collecting = False
    text_parts = []

    for elem in body.iter():
        tag = elem.tag.split('}')[-1] if '}' in elem.tag else elem.tag

        if tag == 'commentRangeStart':
            elem_id = elem.get(f'{{{W_NS}}}id')
            if elem_id == comment_id:
                collecting = True
                continue

        if tag == 'commentRangeEnd':
            elem_id = elem.get(f'{{{W_NS}}}id')
            if elem_id == comment_id:
                collecting = False
                break

        if collecting and tag == 't':
            if elem.text:
                text_parts.append(elem.text)

    return "".join(text_parts) if text_parts else ""


def list_comments(doc):
    """List all comments in the document."""
    comments = parse_comments(doc)
    if not comments:
        print("No comments found in the document.")
        return

    for i, comment in enumerate(comments):
        print(f"\n--- Comment {i} (ID: {comment['id']}) ---")
        print(f"  Author:    {comment['author']}")
        print(f"  Date:      {comment['date']}")
        if comment['commented_text']:
            # Truncate long commented text
            ct = comment['commented_text']
            if len(ct) > 80:
                ct = ct[:77] + "..."
            print(f"  On text:   \"{ct}\"")
        print(f"  Comment:   {comment['text']}")

    print(f"\nTotal: {len(comments)} comment(s)")


def add_comment(doc, search_text, comment_text, author, output_path):
    """Add a comment to the document at the location of search_text."""
    from lxml import etree
    from docx.opc.part import Part
    from docx.opc.constants import RELATIONSHIP_TYPE as RT
    import re

    body = doc.element.body

    # Find the paragraph containing the search text
    target_para = None
    target_run_idx = None
    for para in doc.paragraphs:
        if search_text in para.text:
            target_para = para
            break

    if target_para is None:
        print(f"Error: Could not find text \"{search_text}\" in the document.", file=sys.stderr)
        sys.exit(1)

    # Determine next comment ID
    existing_comments = parse_comments(doc)
    next_id = 0
    for c in existing_comments:
        try:
            cid = int(c['id'])
            if cid >= next_id:
                next_id = cid + 1
        except (ValueError, TypeError):
            pass

    comment_id = str(next_id)
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    # Get or create comments part
    comments_part = get_comments_part(doc)
    if comments_part is None:
        # Create a new comments part
        comments_xml = (
            f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            f'<w:comments xmlns:w="{W_NS}" '
            f'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            f'</w:comments>'
        )
        from docx.opc.part import Part
        from docx.opc.packuri import PackURI

        comments_part_uri = PackURI('/word/comments.xml')
        content_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml'

        comments_part = Part(
            comments_part_uri,
            content_type,
            comments_xml.encode('utf-8'),
            doc.part.package
        )
        doc.part.relate_to(
            comments_part,
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments'
        )

    # Add comment to comments XML
    root = etree.fromstring(comments_part.blob)

    # Build initials from author name
    initials = "".join(word[0].upper() for word in author.split() if word)

    comment_elem = etree.SubElement(root, f'{{{W_NS}}}comment')
    comment_elem.set(f'{{{W_NS}}}id', comment_id)
    comment_elem.set(f'{{{W_NS}}}author', author)
    comment_elem.set(f'{{{W_NS}}}date', now)
    comment_elem.set(f'{{{W_NS}}}initials', initials)

    # Add comment paragraph with text
    p_elem = etree.SubElement(comment_elem, f'{{{W_NS}}}p')
    r_elem = etree.SubElement(p_elem, f'{{{W_NS}}}r')
    # Add comment reference run properties
    rpr = etree.SubElement(r_elem, f'{{{W_NS}}}rPr')
    rstyle = etree.SubElement(rpr, f'{{{W_NS}}}rStyle')
    rstyle.set(f'{{{W_NS}}}val', 'CommentReference')
    anno = etree.SubElement(r_elem, f'{{{W_NS}}}annotationRef')

    # Add actual text run
    r_text = etree.SubElement(p_elem, f'{{{W_NS}}}r')
    t_elem = etree.SubElement(r_text, f'{{{W_NS}}}t')
    t_elem.text = comment_text
    t_elem.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')

    comments_part._blob = etree.tostring(root, xml_declaration=True, encoding='UTF-8', standalone=True)

    # Add comment range markers to the document body
    para_elem = target_para._element

    # Find the run that contains the search text and insert markers
    range_start = etree.Element(f'{{{W_NS}}}commentRangeStart')
    range_start.set(f'{{{W_NS}}}id', comment_id)

    range_end = etree.Element(f'{{{W_NS}}}commentRangeEnd')
    range_end.set(f'{{{W_NS}}}id', comment_id)

    # Comment reference run
    ref_run = etree.Element(f'{{{W_NS}}}r')
    ref_rpr = etree.SubElement(ref_run, f'{{{W_NS}}}rPr')
    ref_rstyle = etree.SubElement(ref_rpr, f'{{{W_NS}}}rStyle')
    ref_rstyle.set(f'{{{W_NS}}}val', 'CommentReference')
    ref_cr = etree.SubElement(ref_run, f'{{{W_NS}}}commentReference')
    ref_cr.set(f'{{{W_NS}}}id', comment_id)

    # Insert markers: start before first run, end after last run, then reference
    runs = para_elem.findall(f'{{{W_NS}}}r')
    if runs:
        # Find the run(s) containing the search text
        found_start = False
        for run in runs:
            run_text = ""
            for t in run.findall(f'{{{W_NS}}}t'):
                if t.text:
                    run_text += t.text
            if search_text in run_text or (not found_start and run_text and run_text in search_text):
                if not found_start:
                    run.addprevious(range_start)
                    found_start = True
                # Keep going to find the end of the matching text
                run.addnext(range_end)

        if not found_start:
            # Fallback: wrap entire paragraph
            runs[0].addprevious(range_start)
            runs[-1].addnext(range_end)

        range_end.addnext(ref_run)
    else:
        # No runs, insert at paragraph level
        para_elem.append(range_start)
        para_elem.append(range_end)
        para_elem.append(ref_run)

    # Update content types if needed
    _ensure_content_type(doc, 'comments')

    doc.save(output_path)
    print(f"Comment added (ID: {comment_id}) by {author} on text containing \"{search_text}\"")
    print(f"Saved to: {output_path}")


def _ensure_content_type(doc, part_name):
    """Ensure content type exists for a given part."""
    # python-docx handles this automatically when saving in most cases
    pass


def remove_comments(doc, output_path, index=None):
    """Remove comments from the document."""
    from lxml import etree

    comments_part = get_comments_part(doc)
    if comments_part is None:
        print("No comments found in the document.")
        doc.save(output_path)
        return

    root = etree.fromstring(comments_part.blob)
    comment_elems = root.findall('.//w:comment', NSMAP)

    if index is not None:
        if index < 0 or index >= len(comment_elems):
            print(f"Error: Comment index {index} out of range (0-{len(comment_elems)-1})", file=sys.stderr)
            sys.exit(1)
        ids_to_remove = {comment_elems[index].get(f'{{{W_NS}}}id')}
        root.remove(comment_elems[index])
    else:
        ids_to_remove = set()
        for ce in comment_elems:
            ids_to_remove.add(ce.get(f'{{{W_NS}}}id'))
            root.remove(ce)

    comments_part._blob = etree.tostring(root, xml_declaration=True, encoding='UTF-8', standalone=True)

    # Remove comment range markers and references from document body
    body = doc.element.body
    elements_to_remove = []

    for elem in body.iter():
        tag = elem.tag.split('}')[-1] if '}' in elem.tag else elem.tag
        if tag in ('commentRangeStart', 'commentRangeEnd', 'commentReference'):
            elem_id = elem.get(f'{{{W_NS}}}id')
            if elem_id in ids_to_remove:
                elements_to_remove.append(elem)
                # Also remove the parent run if it only contains the reference
                if tag == 'commentReference':
                    parent = elem.getparent()
                    if parent is not None and parent.tag == f'{{{W_NS}}}r':
                        # Check if the run only has rPr and commentReference
                        children = [c for c in parent if c.tag != f'{{{W_NS}}}rPr']
                        if len(children) == 1:
                            elements_to_remove.append(parent)

    for elem in elements_to_remove:
        parent = elem.getparent()
        if parent is not None:
            parent.remove(elem)

    doc.save(output_path)
    count = len(ids_to_remove)
    print(f"Removed {count} comment(s). Saved to: {output_path}")


def export_comments(doc, output_path, fmt="json"):
    """Export comments to JSON or CSV."""
    comments = parse_comments(doc)

    if not comments:
        print("No comments found in the document.")
        return

    if fmt == "csv":
        output = io.StringIO()
        writer = csv.DictWriter(output, fieldnames=["id", "author", "date", "text", "commented_text"])
        writer.writeheader()
        for c in comments:
            writer.writerow({
                "id": c["id"],
                "author": c["author"],
                "date": c["date"],
                "text": c["text"],
                "commented_text": c.get("commented_text", ""),
            })
        result = output.getvalue()
    else:
        result = json.dumps(comments, indent=2, ensure_ascii=False)

    if output_path:
        with open(output_path, "w", encoding="utf-8") as f:
            f.write(result)
        print(f"Exported {len(comments)} comment(s) to: {output_path}")
    else:
        print(result)


def main():
    parser = argparse.ArgumentParser(description="Manage comments in a Word document")
    subparsers = parser.add_subparsers(dest="command", help="Command to run")

    # list
    list_parser = subparsers.add_parser("list", help="List all comments")
    list_parser.add_argument("file_path", help="Path to .docx file")

    # add
    add_parser = subparsers.add_parser("add", help="Add a comment")
    add_parser.add_argument("file_path", help="Path to .docx file")
    add_parser.add_argument("-t", "--text", required=True, help="Text to attach comment to (search string)")
    add_parser.add_argument("-c", "--comment", required=True, help="Comment body")
    add_parser.add_argument("-a", "--author", default="Review Bot", help="Comment author (default: Review Bot)")
    add_parser.add_argument("-o", "--output", required=True, help="Output file path")

    # remove
    remove_parser = subparsers.add_parser("remove", help="Remove comments")
    remove_parser.add_argument("file_path", help="Path to .docx file")
    remove_parser.add_argument("-o", "--output", required=True, help="Output file path")
    remove_parser.add_argument("--index", type=int, default=None, help="Remove only this comment index (0-based)")

    # export
    export_parser = subparsers.add_parser("export", help="Export comments")
    export_parser.add_argument("file_path", help="Path to .docx file")
    export_parser.add_argument("-o", "--output", default=None, help="Output file path")
    export_parser.add_argument("--format", choices=["json", "csv"], default="json", help="Export format")

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
        list_comments(doc)
    elif args.command == "add":
        output_path = os.path.expanduser(args.output)
        add_comment(doc, args.text, args.comment, args.author, output_path)
    elif args.command == "remove":
        output_path = os.path.expanduser(args.output)
        remove_comments(doc, output_path, index=args.index)
    elif args.command == "export":
        output_path = os.path.expanduser(args.output) if args.output else None
        export_comments(doc, output_path, fmt=args.format)


if __name__ == "__main__":
    main()
