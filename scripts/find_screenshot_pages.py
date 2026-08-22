#!/usr/bin/env python3
"""Find pages with screenshots in the rebuilt rapport PDF."""
import fitz
from pathlib import Path

PDF = "/home/z/my-project/download/PFE_Rapport_Aymen_Azizi.pdf"
OUT_DIR = Path("/home/z/my-project/download/rapport_verify")
OUT_DIR.mkdir(parents=True, exist_ok=True)

doc = fitz.open(PDF)
print(f"PDF has {len(doc)} pages")

# Render all pages that have images, focusing on chap_04 (impl) and chap_05 (testing)
# chap_04 typically starts around page 55-65, chap_05 around 70-85
for i in range(55, 90):
    page = doc.load_page(i)
    images = page.get_images()
    text = page.get_text()[:200].lower()
    if images or any(kw in text for kw in ['login', 'register', 'new project', 'launch scan', 'sandbox', 'screenshot', 'figure 4', 'figure 5']):
        pix = page.get_pixmap(dpi=130)
        out_path = OUT_DIR / f"all_page_{i:03d}.png"
        pix.save(out_path)
        print(f"  page {i}: {len(images)} images, {len(text)} chars text")

doc.close()
