#!/usr/bin/env python3
"""名古屋市の元資料を data/src/ に取る（build_data.py の材料）。  /usr/bin/python3 scripts/fetch_sources.py
連絡会の一覧PDFが新しい年度に変わったら、data/renrakukai.json を書き写し直す（parse_renrakukai.py の出力は下書き）。"""
import os, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = {
    "renrakukai.html": "https://www.city.nagoya.jp/kodomo/schools/1016972/1016976.html",
    "renrakukai-r7.pdf": "https://www.city.nagoya.jp/_res/projects/default_project/_page_/001/016/976/sankadanntai3.pdf",
    "konai-ibasho-r8.pdf": "https://www.city.nagoya.jp/_res/projects/default_project/_page_/001/016/975/8nenndozissikou.pdf",
    "friendly.html": "https://www.city.nagoya.jp/kodomo/schools/1015850/1017055.html",
    "fs_about.html": "https://fs-subsidy.city.nagoya.jp/r8/about",
}
os.makedirs(os.path.join(ROOT, "data", "src"), exist_ok=True)
for name, url in SRC.items():
    b = urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"}), timeout=60).read()
    open(os.path.join(ROOT, "data", "src", name), "wb").write(b)
    print(f"{len(b):>8} {name}  {url}")
