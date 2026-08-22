import os
from PIL import Image, ImageDraw, ImageFont

img_dir = r"c:\wamp64\www\cybersec-workspace-full\cybersec-workspace\rapport\img"
font_path_bold = r"C:\Windows\Fonts\segoeuib.ttf"
font_path_reg = r"C:\Windows\Fonts\segoeui.ttf"

font_title = ImageFont.truetype(font_path_bold, 44)
font_sub = ImageFont.truetype(font_path_reg, 25)

updates = {
    "component_diagram.png": {
        "clear_box": (0, 0, 3400, 140),
        "title": "Figure 3.4 — Architecture globale en microservices (12 conteneurs Docker)",
        "subtitle": "Réseaux : cybersec-external (Nginx seul) / cybersec-internal (reste) — tous non-root, readOnlyRootfs, cap_drop ALL",
        "title_y": 30,
        "sub_y": 90,
    },
    "erd.png": {
        "clear_box": (0, 0, 3200, 160),
        "title": "Figure 3.6 — Diagramme Entité-Association (14 tables)",
        "subtitle": "PostgreSQL 16 + Apache AGE — coloration par domaine fonctionnel",
        "title_y": 30,
        "sub_y": 90,
    },
    "sequence_scan.png": {
        "clear_box": (0, 0, 3640, 160),
        "title": "Figure 3.8 — Diagramme de séquence : orchestration asynchrone d'un scan",
        "subtitle": "Redis Streams (XREADGROUP, consumer group) — retries ×3, dégradation gracieuse, broadcast temps réel",
        "title_y": 30,
        "sub_y": 90,
    },
    "state_machine.png": {
        "clear_box": (0, 0, 3000, 140),
        "title": "Figure 3.9 — Machine à états : cycle de vie d'une tâche de scan",
        "subtitle": "États : pending → queued → running → completed | failed | cancelled — retries ×3 sur erreur",
        "title_y": 30,
        "sub_y": 90,
    },
    "data_flow.png": {
        "clear_box": (0, 0, 3400, 140),
        "title": "Figure 3.10 — Diagramme de flux de données (DFD) de la plateforme",
        "subtitle": "Processus numérotés, magasins de données (D1-D8), entités externes — format des données étiqueté sur chaque flux",
        "title_y": 30,
        "sub_y": 90,
    },
}

for fname, cfg in updates.items():
    fpath = os.path.join(img_dir, fname)
    im = Image.open(fpath)
    draw = ImageDraw.Draw(im)

    # clear header box to pure white
    draw.rectangle(cfg["clear_box"], fill=(255, 255, 255))

    # draw centered title
    t_bbox = draw.textbbox((0, 0), cfg["title"], font=font_title)
    t_w = t_bbox[2] - t_bbox[0]
    t_x = (im.width - t_w) // 2
    draw.text((t_x, cfg["title_y"]), cfg["title"], fill=(17, 24, 39), font=font_title)

    # draw centered subtitle
    s_bbox = draw.textbbox((0, 0), cfg["subtitle"], font=font_sub)
    s_w = s_bbox[2] - s_bbox[0]
    s_x = (im.width - s_w) // 2
    draw.text((s_x, cfg["sub_y"]), cfg["subtitle"], fill=(107, 114, 128), font=font_sub)

    im.save(fpath)
    print("Updated", fname, "->", cfg["title"])
