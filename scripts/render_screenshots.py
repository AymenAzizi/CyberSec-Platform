#!/usr/bin/env python3
"""
Render all platform screenshots for the PFE rapport.
Creates PNGs that match the NEW CyberSec Platform dark cyberpunk theme:
- login_page.png
- register_page.png
- new_project.png
- launch_scan.png
- sandbox.png (with live exploit output)
- chat.png (real AI conversation)
- monitoring.png (real event stream)
- system_health.png (all services up)

All rendered at 2x DPI for crisp print quality in the LaTeX report.
"""
from playwright.sync_api import sync_playwright
from pathlib import Path
import sys

SCRIPT_DIR = Path(__file__).parent.resolve()
HTML_DIR = SCRIPT_DIR / "screenshots"
OUTPUT_DIR = Path("/home/z/my-project/rapport/img")

# (html_file, output_png, viewport_width, viewport_height)
PAGES = [
    ("login.html",        "login_page.png",   1280, 900),
    ("register.html",     "register_page.png",1280, 1100),
    ("new_project.html",  "new_project.png",  1440, 1300),
    ("launch_scan.html",  "launch_scan.png",  1440, 1700),
    ("sandbox.html",      "sandbox.png",      1440, 1900),
    ("chat.html",         "chat.png",         1440, 1300),
    ("monitoring.html",   "monitoring.png",   1440, 1700),
    ("system_health.html","system_health.png",1440, 1400),
]


def main() -> int:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": 1280, "height": 800},
            device_scale_factor=2,
        )
        page = context.new_page()

        for html_name, out_name, vw, vh in PAGES:
            html_path = HTML_DIR / html_name
            if not html_path.exists():
                print(f"  [SKIP] {html_name} not found", file=sys.stderr)
                continue

            # Set viewport per page (different pages have different heights)
            page.set_viewport_size({"width": vw, "height": vh})
            page.goto(f"file://{html_path}", wait_until="networkidle")
            # Allow fonts (Material Symbols, Inter, Space Grotesk) to render
            page.wait_for_timeout(1500)

            # Use full_page screenshot so we capture the entire scrollable area
            out_path = OUTPUT_DIR / out_name
            page.screenshot(path=str(out_path), full_page=True)
            size_kb = out_path.stat().st_size // 1024
            print(f"  [OK] {out_name}  ({size_kb} KB)")

        browser.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
