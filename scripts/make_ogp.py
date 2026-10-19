#!/usr/bin/env python3
"""kibasho の OGP（1200×630）。数字は年度で変わるものを焼き込まない。  /usr/bin/python3 scripts/make_ogp.py"""
import os
from PIL import Image, ImageDraw, ImageFont
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "outputs", "kibasho_ogp.png")
B = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"; K = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"; N = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
NAVY, TEAL, SUB = (20, 33, 61), (10, 114, 107), (91, 102, 118)
im = Image.new("RGB", (1200, 630), (246, 248, 251)); d = ImageDraw.Draw(im)
d.rectangle((0, 0, 1200, 12), fill=TEAL)
logo = Image.open("/home/kojima/work/exbridge_jp/images/logo-mark-128.png").convert("RGBA").resize((56, 56))
im.paste(logo, (60, 48), logo)
d.text((130, 56), "Kurage 不登校の居場所ナビ", font=ImageFont.truetype(B, 28), fill=TEAL)
for k, t in enumerate(("名古屋市の", "フリースクール・", "不登校の居場所")):
    d.text((60, 140 + k * 76), t, font=ImageFont.truetype(K, 60), fill=NAVY)
kinds = [("連絡会の参加団体", (230, 244, 242), TEAL), ("校内の居場所", (234, 240, 254), (29, 78, 216)),
         ("市の教育支援センター", (241, 234, 254), (109, 40, 217)), ("利用料の補助金", (255, 246, 224), (154, 103, 0))]
for i, (t, bg, fg) in enumerate(kinds):
    y = 140 + i * 84
    d.rounded_rectangle((690, y, 1140, y + 64), radius=16, fill=bg)
    d.text((716, y + 13), t, font=ImageFont.truetype(B, 30), fill=fg)
d.text((60, 392), "4種類の違いが分かる形で、", font=ImageFont.truetype(N, 26), fill=(60, 72, 88))
d.text((60, 428), "住所から近い順・区ごとに探せる", font=ImageFont.truetype(N, 26), fill=(60, 72, 88))
d.text((690, 482), "出典：名古屋市の公表資料", font=ImageFont.truetype(N, 22), fill=SUB)
m = Image.open("/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png").convert("RGBA")
m = m.resize((96, int(96 * m.height / m.width)))
im.paste(m, (60, 630 - m.height - 14), m)
d.text((172, 578), "株式会社エクスブリッジ", font=ImageFont.truetype(N, 22), fill=SUB)
os.makedirs(os.path.dirname(OUT), exist_ok=True); im.save(OUT, optimize=True); print(OUT)
