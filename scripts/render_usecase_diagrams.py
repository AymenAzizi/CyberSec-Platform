"""Render the 3 use case HTML diagrams to PNG at 2x DPI."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

DIAGRAMS = [
    ("usecase_global",  1480, 1000),
    ("usecase_analyst", 1280, 880),
    ("usecase_admin",   1280, 880),
]

OUT_DIR = Path("/home/z/my-project/rapport/img")
SRC_DIR = Path("/home/z/my-project/scripts/diagrams")

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        for name, w, h in DIAGRAMS:
            ctx = await browser.new_context(
                viewport={"width": w, "height": h},
                device_scale_factor=2,
            )
            page = await ctx.new_page()
            src = SRC_DIR / f"{name}.html"
            out = OUT_DIR / f"{name}.png"
            await page.goto(f"file://{src}")
            await page.wait_for_load_state("networkidle")
            await page.screenshot(path=str(out), full_page=False, omit_background=False)
            print(f"OK: {out} ({w}x{h})")
            await ctx.close()
        await browser.close()

asyncio.run(main())
