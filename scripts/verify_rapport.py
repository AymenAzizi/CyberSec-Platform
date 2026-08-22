#!/usr/bin/env python3
"""
Render sample pages of the rebuilt PFE rapport PDF to verify
the NEW platform screenshots appear consistently throughout.
Focus on chap_04 (Implementation) and chap_05 (Testing) where
screenshots are most prominent.
"""
import fitz
from pathlib import Path

PDF = "/home/z/my-project/download/PFE_Rapport_Aymen_Azizi.pdf"
OUT_DIR = Path("/home/z/my-project/download/rapport_verify")
OUT_DIR.mkdir(parents=True, exist_ok=True)

# Sample pages: cover, content, screenshots in chap_04 and chap_05
SAMPLE_PAGES = [
    (0,   "cover"),       # Cover
    (44,  "chap04_p1"),   # Beginning of chap 4
    (50,  "chap04_login"),  # Login screenshot area
    (53,  "chap04_newproj"),  # New project area
    (56,  "chap04_launch"),  # Launch scan area
    (60,  "chap04_sandbox"),  # Sandbox area
    (70,  "chap05_p1"),   # Beginning of chap 5
    (75,  "chap05_alerts"),  # Alerts screenshot
    (80,  "chap05_kg"),   # Knowledge graph
    (85,  "chap05_osint"),  # OSINT
]

doc = fitz.open(PDF)
print(f"PDF has {len(doc)} pages, rendering samples...")

for page_num, name in SAMPLE_PAGES:
    if page_num >= len(doc):
        continue
    page = doc.load_page(page_num)
    pix = page.get_pixmap(dpi=130)
    out_path = OUT_DIR / f"page_{page_num:03d}_{name}.png"
    pix.save(out_path)
    print(f"  [OK] page {page_num} → {out_path.name} ({pix.width}x{pix.height})")

doc.close()
print(f"\nAll sample pages saved to {OUT_DIR}")
