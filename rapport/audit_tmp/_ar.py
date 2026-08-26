import re
from PIL import Image

tex = {}
for f in ['chap_01.tex', 'chap_02.tex', 'chap_03.tex', 'chap_04.tex']:
    s = open(f, encoding='utf-8').read()
    for m in re.finditer(r'width=([\d.]+)\\textwidth\]\{img/(fig_[\w.]+)\}', s):
        tex[m.group(2)] = float(m.group(1))

for name, w in sorted(tex.items()):
    im = Image.open('img/' + name)
    ar = im.size[1] / im.size[0]
    h = w * 396 * ar
    flag = '  <== TOO TALL' if h > 480 else ''
    print(f'{name}: {im.size[0]}x{im.size[1]} ar={ar:.2f} width={w} renders ~{h:.0f}pt tall{flag}')
