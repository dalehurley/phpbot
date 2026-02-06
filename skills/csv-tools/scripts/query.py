#!/usr/bin/env python3
"""
Query, filter, sort, and transform CSV/JSON data files.
Uses Python stdlib only (csv, json) -- no external dependencies.

Usage:
    query.py view <file> [--limit N]
    query.py filter <file> --column <col> --value <val>
    query.py sort <file> --column <col> [--order asc|desc]
    query.py stats <file> [--column <col>]
    query.py convert <file> --output <path>
"""

import sys
import os
import csv
import json
import argparse
from collections import defaultdict


def load_data(file_path):
    """Load CSV or JSON file and return list of dicts + headers."""
    ext = os.path.splitext(file_path)[1].lower()

    if ext == ".json":
        with open(file_path, "r", encoding="utf-8") as f:
            data = json.load(f)
        if isinstance(data, list) and len(data) > 0 and isinstance(data[0], dict):
            headers = list(data[0].keys())
            return data, headers
        elif isinstance(data, dict):
            # Single object -> list of one
            return [data], list(data.keys())
        else:
            print("Error: JSON must be an array of objects or a single object", file=sys.stderr)
            sys.exit(1)

    else:  # CSV (default)
        with open(file_path, "r", encoding="utf-8", newline="") as f:
            # Sniff delimiter
            sample = f.read(4096)
            f.seek(0)
            try:
                dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
            except csv.Error:
                dialect = csv.excel

            reader = csv.DictReader(f, dialect=dialect)
            data = list(reader)
            headers = reader.fieldnames or []
            return data, headers


def format_table(data, headers, limit=None):
    """Format data as a text table."""
    if limit:
        data = data[:limit]

    # Calculate column widths
    widths = {h: len(h) for h in headers}
    for row in data:
        for h in headers:
            val = str(row.get(h, ""))
            widths[h] = max(widths[h], min(len(val), 40))

    # Header
    header_line = "  ".join(h.ljust(widths[h]) for h in headers)
    separator = "  ".join("-" * widths[h] for h in headers)

    lines = [header_line, separator]
    for row in data:
        line = "  ".join(str(row.get(h, ""))[:40].ljust(widths[h]) for h in headers)
        lines.append(line)

    return "\n".join(lines)


def cmd_view(file_path, limit):
    """Pretty-print data as a table."""
    data, headers = load_data(file_path)
    total = len(data)

    print(f"File: {file_path} ({total} rows, {len(headers)} columns)")
    print()
    print(format_table(data, headers, limit))

    if limit and total > limit:
        print(f"\n... showing {limit} of {total} rows")


def parse_filter_value(value_str):
    """Parse filter value with optional operator."""
    operators = [">=", "<=", "!=", ">", "<", "="]
    for op in operators:
        if value_str.startswith(op):
            return op, value_str[len(op):]
    return "=", value_str


def matches_filter(cell_value, operator, filter_value):
    """Check if a cell value matches the filter condition."""
    # Try numeric comparison
    try:
        cell_num = float(cell_value)
        filter_num = float(filter_value)
        if operator == "=":
            return cell_num == filter_num
        elif operator == "!=":
            return cell_num != filter_num
        elif operator == ">":
            return cell_num > filter_num
        elif operator == "<":
            return cell_num < filter_num
        elif operator == ">=":
            return cell_num >= filter_num
        elif operator == "<=":
            return cell_num <= filter_num
    except (ValueError, TypeError):
        pass

    # String comparison
    cell_str = str(cell_value).lower()
    filter_str = str(filter_value).lower()
    if operator == "=":
        return cell_str == filter_str
    elif operator == "!=":
        return cell_str != filter_str
    elif operator == ">":
        return cell_str > filter_str
    elif operator == "<":
        return cell_str < filter_str
    elif operator == ">=":
        return cell_str >= filter_str
    elif operator == "<=":
        return cell_str <= filter_str
    return False


def cmd_filter(file_path, column, value):
    """Filter rows by column value."""
    data, headers = load_data(file_path)

    if column not in headers:
        print(f"Error: Column '{column}' not found. Available: {', '.join(headers)}", file=sys.stderr)
        sys.exit(1)

    operator, filter_val = parse_filter_value(value)
    filtered = [row for row in data if matches_filter(row.get(column, ""), operator, filter_val)]

    print(f"Filter: {column} {operator} {filter_val}")
    print(f"Results: {len(filtered)} of {len(data)} rows")
    print()
    print(format_table(filtered, headers))


def cmd_sort(file_path, column, order):
    """Sort data by a column."""
    data, headers = load_data(file_path)

    if column not in headers:
        print(f"Error: Column '{column}' not found. Available: {', '.join(headers)}", file=sys.stderr)
        sys.exit(1)

    def sort_key(row):
        val = row.get(column, "")
        try:
            return (0, float(val))
        except (ValueError, TypeError):
            return (1, str(val).lower())

    reverse = order == "desc"
    sorted_data = sorted(data, key=sort_key, reverse=reverse)

    print(f"Sorted by: {column} ({order})")
    print(f"Rows: {len(sorted_data)}")
    print()
    print(format_table(sorted_data, headers))


def cmd_stats(file_path, column=None):
    """Show summary statistics."""
    data, headers = load_data(file_path)

    print(f"File: {file_path}")
    print(f"Rows: {len(data)}")
    print(f"Columns: {len(headers)}")
    print()

    target_cols = [column] if column else headers

    for col in target_cols:
        if col not in headers:
            print(f"Warning: Column '{col}' not found, skipping", file=sys.stderr)
            continue

        values = [row.get(col, "") for row in data]
        non_empty = [v for v in values if v != "" and v is not None]

        print(f"--- {col} ---")
        print(f"  Non-empty: {len(non_empty)} / {len(values)}")
        unique = len(set(values))
        print(f"  Unique values: {unique}")

        # Try numeric stats
        numeric = []
        for v in non_empty:
            try:
                numeric.append(float(v))
            except (ValueError, TypeError):
                pass

        if numeric:
            print(f"  Min: {min(numeric)}")
            print(f"  Max: {max(numeric)}")
            print(f"  Mean: {sum(numeric) / len(numeric):.2f}")
            print(f"  Sum: {sum(numeric):.2f}")
        else:
            # Show top values for non-numeric
            counts = defaultdict(int)
            for v in non_empty:
                counts[v] += 1
            top = sorted(counts.items(), key=lambda x: -x[1])[:5]
            print(f"  Top values: {', '.join(f'{k} ({v})' for k, v in top)}")

        print()


def cmd_convert(file_path, output_path):
    """Convert between CSV and JSON."""
    data, headers = load_data(file_path)
    out_ext = os.path.splitext(output_path)[1].lower()

    os.makedirs(os.path.dirname(output_path) or ".", exist_ok=True)

    if out_ext == ".json":
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
    elif out_ext in (".csv", ".tsv"):
        delimiter = "\t" if out_ext == ".tsv" else ","
        with open(output_path, "w", encoding="utf-8", newline="") as f:
            writer = csv.DictWriter(f, fieldnames=headers, delimiter=delimiter)
            writer.writeheader()
            writer.writerows(data)
    else:
        print(f"Error: Unsupported output format '{out_ext}'. Use .json, .csv, or .tsv", file=sys.stderr)
        sys.exit(1)

    size = os.path.getsize(output_path)
    print(f"Converted {len(data)} rows: {file_path} -> {output_path} ({size:,} bytes)")


def main():
    parser = argparse.ArgumentParser(description="Query, filter, sort, and transform data files")
    subparsers = parser.add_subparsers(dest="action", required=True)

    # view
    view_p = subparsers.add_parser("view", help="Pretty-print data")
    view_p.add_argument("file", help="CSV or JSON file")
    view_p.add_argument("--limit", "-l", type=int, default=50, help="Max rows (default: 50)")

    # filter
    filter_p = subparsers.add_parser("filter", help="Filter rows")
    filter_p.add_argument("file", help="CSV or JSON file")
    filter_p.add_argument("--column", "-c", required=True, help="Column to filter on")
    filter_p.add_argument("--value", "-v", required=True, help="Value to match (supports >, <, >=, <=, !=, =)")

    # sort
    sort_p = subparsers.add_parser("sort", help="Sort by column")
    sort_p.add_argument("file", help="CSV or JSON file")
    sort_p.add_argument("--column", "-c", required=True, help="Column to sort by")
    sort_p.add_argument("--order", "-o", default="asc", choices=["asc", "desc"], help="Sort order (default: asc)")

    # stats
    stats_p = subparsers.add_parser("stats", help="Summary statistics")
    stats_p.add_argument("file", help="CSV or JSON file")
    stats_p.add_argument("--column", "-c", default=None, help="Specific column (default: all)")

    # convert
    conv_p = subparsers.add_parser("convert", help="Convert between formats")
    conv_p.add_argument("file", help="Input CSV or JSON file")
    conv_p.add_argument("--output", "-o", required=True, help="Output file path (.json, .csv, .tsv)")

    args = parser.parse_args()

    file_path = os.path.expanduser(args.file)
    if not os.path.exists(file_path):
        print(f"Error: File not found: {file_path}", file=sys.stderr)
        sys.exit(1)

    if args.action == "view":
        cmd_view(file_path, args.limit)
    elif args.action == "filter":
        cmd_filter(file_path, args.column, args.value)
    elif args.action == "sort":
        cmd_sort(file_path, args.column, args.order)
    elif args.action == "stats":
        cmd_stats(file_path, args.column)
    elif args.action == "convert":
        output_path = os.path.expanduser(args.output)
        cmd_convert(file_path, os.path.abspath(output_path))


if __name__ == "__main__":
    main()
