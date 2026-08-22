#!/usr/bin/env python3
"""
Render all HTML diagrams to PNG via Playwright at 2x device scale factor.

Each HTML file declares its own canvas size via @page { size: WxH }.
We read that size, set the viewport accordingly, and screenshot at
device_scale_factor=2 for crisp 300dpi-equivalent print quality.

Output: /home/z/my-project/rapport/img/*.png
"""
import re
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

DIAGRAMS_DIR = Path("/home/z/my-project/scripts/diagrams")
OUTPUT_DIR   = Path("/home/z/my-project/rapport/img")

# (html_filename, png_filename)
DIAGRAMS = [
    ("erd.html",                "erd.png"),
    ("sequence_scan.html",      "sequence_scan.png"),
    ("component_diagram.html",  "component_diagram.png"),
    ("data_flow.html",          "data_flow.png"),
    ("state_machine.html",      "state_machine.png"),
    ("devsecops_pipeline.html", "devsecops_pipeline.png"),
]


def read_canvas_size(html_path: Path) -> tuple[int, int]:
    """Extract canvas dimensions from the @page { size: WxH } rule."""
    text = html_path.read_text(encoding="utf-8")
    m = re.search(r"@page\s*\{\s*size:\s*(\d+)px\s+(\d+)px", text)
    if not m:
        raise ValueError(f"Could not find @page size in {html_path}")
    return int(m.group(1)), int(m.group(2))


def render_one(page, html_path: Path, png_path: Path) -> tuple[int, int, int]:
    w, h = read_canvas_size(html_path)
    url = html_path.resolve().as_uri()
    page.set_viewport_size({"width": w, "height": h})
    page.goto(url, wait_until="networkidle")
    # Small delay to ensure fonts/layout settle
    page.wait_for_timeout(150)
    page.screenshot(
        path=str(png_path),
        clip={"x": 0, "y": 0, "width": w, "height": h},
        omit_background=False,
    )
    size_bytes = png_path.stat().st_size
    return w, h, size_bytes


def human_size(n: int) -> str:
    for unit in ("B", "KB", "MB"):
        if n < 1024:
            return f"{n:.0f} {unit}"
        n /= 1024
    return f"{n:.1f} MB"


def main() -> int:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    print(f"Output directory: {OUTPUT_DIR}")
    print(f"Source directory: {DIAGRAMS_DIR}")
    print("-" * 72)

    results = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(
            device_scale_factor=2,
            viewport={"width": 1600, "height": 1000},
        )
        page = context.new_page()

        for html_name, png_name in DIAGRAMS:
            html_path = DIAGRAMS_DIR / html_name
            png_path  = OUTPUT_DIR / png_name
            if not html_path.exists():
                print(f"  [MISS] {html_name} — file not found")
                results.append((html_name, png_name, 0, 0, 0, False))
                continue
            try:
                w, h, sz = render_one(page, html_path, png_path)
                ok = sz > 50_000
                status = "OK  " if ok else "SMALL"
                print(f"  [{status}] {html_name:30s} -> {png_name:30s} "
                      f"{w}x{h} @2x  {human_size(sz):>10s}")
                results.append((html_name, png_name, w, h, sz, ok))
            except Exception as e:
                print(f"  [ERR ] {html_name}: {e}")
                results.append((html_name, png_name, 0, 0, 0, False))

        context.close()
        browser.close()

    print("-" * 72)
    n_ok = sum(1 for r in results if r[5])
    print(f"Done: {n_ok}/{len(results)} diagrams rendered successfully (>50KB).")
    if n_ok != len(results):
        print("WARNING: some diagrams are missing or too small.")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
