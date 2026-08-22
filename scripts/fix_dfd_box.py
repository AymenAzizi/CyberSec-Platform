import os
from PIL import Image, ImageDraw, ImageFont

img_dir = r"c:\wamp64\www\cybersec-workspace-full\cybersec-workspace\rapport\img"
df_path = os.path.join(img_dir, "data_flow.png")
df = Image.open(df_path)

font_bold = ImageFont.truetype(r"C:\Windows\Fonts\segoeuib.ttf", 26)
font_reg = ImageFont.truetype(r"C:\Windows\Fonts\segoeui.ttf", 22)
font_pid = ImageFont.truetype(r"C:\Windows\Fonts\segoeuib.ttf", 21)

draw = ImageDraw.Draw(df)

# Clear area around box 6.0 and store D7 (x=2900..3399, y=40..450)
draw.rectangle((2900, 40, 3399, 450), fill=(255, 255, 255))

# 1. Draw Data Store D7: PDF Report (x=2940..3340, y=50..160)
# Border top and bottom #8B5CF6 = (139, 92, 246), background #F3E8FF = (243, 232, 255)
draw.rectangle((2940, 50, 3340, 160), fill=(243, 232, 255))
draw.line([(2940, 50), (3340, 50)], fill=(139, 92, 246), width=4)
draw.line([(2940, 160), (3340, 160)], fill=(139, 92, 246), width=4)
draw.text((2955, 90), "D7", fill=(109, 40, 217), font=font_bold)
draw.text((3010, 68), "PDF Report", fill=(76, 29, 149), font=font_bold)
draw.text((3010, 108), "Cosign-signed artifact", fill=(91, 33, 182), font=font_reg)

# 2. Draw Process 6.0: Generate Report (x=2940..3340, y=240..400)
# Rounded rect, border #10B981 = (16, 185, 129), fill #F0FDF4 = (240, 253, 244)
draw.rounded_rectangle((2940, 240, 3340, 400), radius=76, fill=(240, 253, 244), outline=(16, 185, 129), width=4)
draw.text((2965, 248), "6.0", fill=(107, 114, 128), font=font_pid)

t1_bbox = draw.textbbox((0, 0), "Generate Report", font=font_bold)
t1_w = t1_bbox[2] - t1_bbox[0]
draw.text((2940 + (400 - t1_w) // 2, 280), "Generate Report", fill=(4, 120, 87), font=font_bold)

t2_bbox = draw.textbbox((0, 0), "PDF + Cosign sign", font=font_reg)
t2_w = t2_bbox[2] - t2_bbox[0]
draw.text((2940 + (400 - t2_w) // 2, 325), "PDF + Cosign sign", fill=(55, 65, 81), font=font_reg)

# 3. Connect Arrow from P5 (left) to P6 (x=2800..2938, y=320)
draw.line([(2800, 320), (2938, 320)], fill=(55, 65, 81), width=3)
draw.polygon([(2938, 320), (2922, 313), (2922, 327)], fill=(55, 65, 81))

# 4. Connect Arrow from P6 up to D7 (x=3140, y=238 down to y=162)
draw.line([(3140, 238), (3140, 164)], fill=(139, 92, 246), width=3)
draw.polygon([(3140, 164), (3133, 180), (3147, 180)], fill=(139, 92, 246))

df.save(df_path)
print("Successfully fixed data_flow.png: Box 6.0 and Store D7 completely rendered and uncropped!")
