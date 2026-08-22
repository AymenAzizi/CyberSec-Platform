#!/bin/bash
# Rebuild the PFE report PDF using Tectonic
set -e

cd /home/z/my-project/rapport

echo "=== Building with Tectonic (auto-runs bibtex passes) ==="
tectonic -X compile main.tex --keep-logs --keep-intermediates 2>&1 | tail -30

echo ""
echo "=== Result ==="
ls -lah main.pdf
pdfinfo main.pdf | grep -E "Pages|File size"

echo ""
echo "=== Copy to download/ ==="
cp main.pdf /home/z/my-project/download/rapport_pfe.pdf
ls -lah /home/z/my-project/download/rapport_pfe.pdf
echo "DONE"
