#!/usr/bin/env python3
"""
Fix LaTeX table cells in rapport .tex files.
Many comparison tables in chap_02.tex incorrectly use ` | ` (pipe with spaces)
as a cell separator inside rows that already use `&` -- this corrupts the
column alignment. This script replaces ` | ` with ` & ` ONLY on data rows
that already contain at least one `&` and end with ` \\` (table row terminator).
The tabular column-definition line (`{|L|L|L|...}`) is preserved.
"""
import re
import sys
from pathlib import Path

def fix_file(path: Path) -> int:
    text = path.read_text(encoding="utf-8")
    out_lines = []
    fixed_count = 0
    for line in text.splitlines(keepends=True):
        stripped = line.rstrip("\n")
        # Skip column-type definition lines like \begin{tabular}{|L|L|L|L|L|}
        if "begin{tabular}" in stripped or "end{tabular}" in stripped:
            out_lines.append(line)
            continue
        # Detect data row: ends with `\\` (table row terminator) and contains ` | `
        # AND is not the \begin{tabular}{|L|L|...} column-definition line.
        if (
            " | " in stripped
            and stripped.rstrip().endswith("\\\\")
            and "begin{tabular}" not in stripped
            and "end{tabular}" not in stripped
        ):
            # Replace every ` | ` with ` & `
            new_line = stripped.replace(" | ", " & ")
            out_lines.append(new_line + ("\n" if line.endswith("\n") else ""))
            if new_line != stripped:
                fixed_count += 1
                print(f"  FIXED {path.name}: {stripped[:80]}...")
        else:
            out_lines.append(line)
    path.write_text("".join(out_lines), encoding="utf-8")
    return fixed_count


def main():
    base = Path("/home/z/my-project/rapport")
    total = 0
    for tex in sorted(base.glob("*.tex")):
        n = fix_file(tex)
        if n:
            print(f"{tex.name}: {n} row(s) fixed")
            total += n
    print(f"\nTOTAL: {total} table rows fixed.")


if __name__ == "__main__":
    main()
