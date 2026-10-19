#!/usr/bin/env python3
"""連絡会の参加団体一覧PDF（名古屋市公表）を団体ごとに起こす。
  /usr/bin/python3 scripts/parse_renrakukai.py data/src/renrakukai-r7.pdf > data/renrakukai_raw.json
pdftotext -layout の桁位置で3列（団体名／住所・URL／電話・メール）に分ける。
複数の校舎があるもの・名前が折り返すものは、出力を人が見て data/renrakukai_fix.json で直す。
"""
import json, re, subprocess, sys

txt = subprocess.run(["pdftotext", "-layout", sys.argv[1], "-"], capture_output=True, text=True).stdout
blocks = re.split(r"\n\s*団体・施設名\s+住所\s+電話番号\s*\n", txt)[1:]
out = []
for b in blocks:
    b = b.split("\f")[0]
    name, mid, right = [], [], []
    for ln in b.split("\n"):
        if not ln.strip() or "ホームページ等URL" in ln:
            continue
        # 桁位置: 団体名 〜18桁、住所・URL 19〜66桁、電話・メール 67桁〜
        n, m, r = ln[:19].strip(), ln[19:67].strip(), ln[67:].strip()
        if n: name.append(n)
        if m: mid.append(m)
        if r: right.append(r)
    urls = [x for x in mid if re.match(r"https?://", x)]
    addr = [x for x in mid if x not in urls]
    mails = [x for x in right if "@" in x]
    tels = [x for x in right if x not in mails]
    out.append({"name": "".join(name), "addr": addr, "tel": tels, "url": urls, "mail": mails})
json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
