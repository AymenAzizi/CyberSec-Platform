import pymupdf

doc = pymupdf.open('audit_tmp/main.pdf')
page_w = doc[0].rect.width
page_h = doc[0].rect.height
margin_left = 56.69
margin_right = page_w - 56.69
issues = []
for i, page in enumerate(doc):
    for b in page.get_text('dict')['blocks']:
        if 'lines' in b:
            for line in b['lines']:
                for span in line['spans']:
                    if span['bbox'][0] < margin_left - 2:
                        issues.append(f'p{i+1} LEFT overflow: x={span["bbox"][0]:.1f} "{span["text"][:40]}"')
                    if span['bbox'][2] > margin_right + 5:
                        issues.append(f'p{i+1} RIGHT overflow: x={span["bbox"][2]:.1f} "{span["text"][:40]}"')
        if b.get('type') == 1:  # image block
            x0, y0, x1, y1 = b['bbox']
            if y1 - y0 > 600:
                issues.append(f'p{i+1} TALL IMAGE: {y1-y0:.0f}pt (page {page_h:.0f}pt)')
            if x1 > margin_right + 5 or x0 < margin_left - 10:
                issues.append(f'p{i+1} IMAGE H-OVERFLOW: x0={x0:.0f} x1={x1:.0f}')
print(f'pages: {len(doc)}')
if issues:
    for iss in issues:
        print(iss)
    print(f'total: {len(issues)}')
else:
    print('clean')
