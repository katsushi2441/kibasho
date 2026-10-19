#!/usr/bin/env python3
"""Kurage 不登校の居場所ナビ（kibasho）のデータを作る。
  /usr/bin/python3 scripts/build_data.py   → php/kibasho_data.json

材料（どれも名古屋市の公表資料。data/src/ に取得したものを置く）:
  - data/renrakukai.json … 連絡会の参加団体一覧PDF（令和7年度・40団体）を人が目で起こしたもの（parse_renrakukai.py の出力を直した）
  - data/src/konai-ibasho-r8.pdf … 校内の教室以外の居場所 実施校一覧（令和8年度）
  - data/src/fs_about.html … フリースクール等利用料補助金ポータルの事業概要（補助率・上限・要件）
  - なごやフレンドリーナウ4か所は市のページ（data/src/friendly.html）の住所・電話を下に書き写した
位置は国土地理院の住所検索で引き、data/geocode_cache.json に置く（同じ住所は二度と引かない）。
再掲の条件（市の回答 2026-10-19）: 出典を明示し、「市の推薦ではなく連絡会に参加した団体」と分かる形で使う。
"""
import html, json, os, re, subprocess, time, urllib.parse, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
D = os.path.join(ROOT, "data")
CACHE = os.path.join(D, "geocode_cache.json")
KU = ["千種区", "東区", "北区", "西区", "中村区", "中区", "昭和区", "瑞穂区", "熱田区", "中川区", "港区", "南区", "守山区", "緑区", "名東区", "天白区"]
KU_SLUG = {"千種区": "chikusa", "東区": "higashi", "北区": "kita", "西区": "nishi", "中村区": "nakamura", "中区": "naka", "昭和区": "showa",
           "瑞穂区": "mizuho", "熱田区": "atsuta", "中川区": "nakagawa", "港区": "minato", "南区": "minami", "守山区": "moriyama",
           "緑区": "midori", "名東区": "meito", "天白区": "tempaku"}

FRIENDLY = [
    {"name": "なごやフレンドリーナウ（浄心）", "ku": "西区", "zip": "451-0031", "addr": "名古屋市西区城西三丁目20番30号", "tel": "052-521-9640"},
    {"name": "なごやフレンドリーナウ 笠寺サテライト", "ku": "南区", "zip": "457-0833", "addr": "名古屋市南区東又兵ヱ町5丁目1番地の16",
     "tel": "052-521-9640（問合せ・見学申込は浄心へ）", "note": "教育相談部はスポーツ振興会館5階、子ども支援部は南生涯学習センター1階（東又兵ヱ町5丁目1番地の10）"},
    {"name": "なごやフレンドリーナウ 鶴舞サテライト", "ku": "中区", "zip": "460-0012", "addr": "名古屋市中区千代田五丁目18番24号", "tel": "052-262-2320"},
    {"name": "なごやフレンドリーナウ 大曽根サテライト", "ku": "北区", "zip": "462-0819", "addr": "名古屋市北区平安二丁目21番61号", "tel": "052-750-8971"},
]


def geocode(addr, cache):
    q = re.sub(r"（.*?）|\s.*$", "", addr).replace("付近", "").strip()
    if q in cache:
        return cache[q]
    u = "https://msearch.gsi.go.jp/address-search/AddressSearch?q=" + urllib.parse.quote(q)
    try:
        r = json.load(urllib.request.urlopen(u, timeout=30))
    except Exception:
        r = []
    time.sleep(0.5)
    hit = None
    if r:
        lon, lat = r[0]["geometry"]["coordinates"]
        hit = {"lat": round(lat, 6), "lon": round(lon, 6), "matched": r[0]["properties"].get("title", "")}
    cache[q] = hit
    return hit


SCHOOLS = {   # 実施校一覧PDF（令和8年度・1枚）を書き写したもの。PDF の本文に全校名があるかは schools() で照合する
    "千種区": "今池中、城山中、千種台中、振甫中、若水中、千種中、東星中、宮根小",
    "東区": "あずま中、冨士中、桜丘中、矢田中",
    "北区": "若葉中、志賀中、北陵中、大曽根中、八王子中、楠中、北中",
    "西区": "浄心中、菊井中、名塚中、天神山中、山田中、山田東中、平田中",
    "中村区": "豊国中、笹島中、笈瀬中、御田中、豊正中、黄金中、日比津中",
    "中区": "前津中、丸の内中、伊勢山中、白山中",
    "昭和区": "桜山中、北山中、川名中、円上中、駒方中",
    "瑞穂区": "田光中、瑞穂ヶ丘中、萩山中、汐路中、津賀田中",
    "熱田区": "沢上中、宮中、日比野中",
    "中川区": "一色中、長良中、山王中、一柳中、八幡中、昭和橋中、富田中、はとり中、助光中、供米田中、高杉中",
    "港区": "港南中、港北中、東港中、南陽中、宝神中、当知中、港明中、南陽東中、大手小",
    "南区": "本城中、新郊中、桜田中、大江中、明豊中、名南中、南光中",
    "守山区": "守山中、守山東中、守山西中、志段味中、大森中、守山北中、森孝中、吉根中、上志段味中、天子田小",
    "緑区": "鳴海中、大高中、有松中、鳴子台中、東陵中、千鳥丘中、神沢中、扇台中、滝ノ水中、左京山中、鎌倉台中、神の倉中、小坂小",
    "名東区": "猪高中、神丘中、高針台中、猪子石中、藤森中、牧の池中、上社中、香流中",
    "天白区": "天白中、御幸山中、久方中、平針中、南天白中、植田中、原中、原小",
}


def schools():
    t = re.sub(r"\s+", "", subprocess.run(["pdftotext", os.path.join(D, "src", "konai-ibasho-r8.pdf"), "-"], capture_output=True, text=True).stdout)
    out = {k: v.split("、") for k, v in SCHOOLS.items()}
    missing = [n for v in out.values() for n in v if n not in t]
    assert not missing, f"PDF に無い校名: {missing}"
    return out


def subsidy():
    t = open(os.path.join(D, "src", "fs_about.html"), encoding="utf-8", errors="replace").read()
    t = re.sub(r"[\s|]+", "|", html.unescape(re.sub(r"<[^>]+>", "|", t)))
    i2 = t.find("|交付対象者|"); j2 = t.find("|申請児童生徒|", i2); j3 = t.find("|フリースクール等|愛知県内"); j4 = t.find("|Support|", j3)
    sp = lambda s, skip: [x for x in s.split("|") if x and x != skip]
    assert "22,000円" in t and "11,000円" in t and "2分の1" in t and "4分の1" in t, "補助率・上限の表記が変わった。書き写し直す"
    return {"applicant": sp(t[i2:j2], "交付対象者"), "child": sp(t[j2:j3], "申請児童生徒"), "facility": sp(t[j3:j4], "フリースクール等"),
            "rates": [{"who": "名古屋市の就学援助を認定されている方、または特別支援学校に在籍する申請児童生徒の保護者で就学援助を受ける者と同程度に経済的に困窮している方", "rate": "2分の1", "cap": "児童生徒1人あたり月額22,000円"},
                      {"who": "上記以外の方", "rate": "4分の1", "cap": "児童生徒1人あたり月額11,000円"}],
            "note": "百円未満の端数は切り捨て。予算の範囲内で交付。対象は平日日中の通所にかかる利用料（授業料）で、入学費・体験利用料・施設整備費・教材費・行事参加費・交通費・弁当代等は対象外。"}


def main():
    cache = json.load(open(CACHE, encoding="utf-8")) if os.path.exists(CACHE) else {}
    rk = json.load(open(os.path.join(D, "renrakukai.json"), encoding="utf-8"))
    for it in rk["items"]:
        for l in it["locations"]:
            if l["addr"] != "オンライン":
                l["geo"] = geocode(l["addr"], cache)
    for f in FRIENDLY:
        f["geo"] = geocode(f["addr"], cache)
    ku_geo = {k: geocode("名古屋市" + k, cache) for k in KU}   # 区のページで「近い区の団体」を並べるための目安
    json.dump(cache, open(CACHE, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    sc = schools()
    n_j = sum(1 for v in sc.values() for x in v if x.endswith("中")); n_e = sum(1 for v in sc.values() for x in v if x.endswith("小"))
    assert (n_j, n_e) == (111, 5), f"実施校の数が合わない（中{n_j}・小{n_e}）。PDFの読み取りを見直す"
    out = {"built": time.strftime("%Y-%m-%d"), "ku": KU, "ku_slug": KU_SLUG, "ku_geo": ku_geo, "renrakukai": rk, "friendly": FRIENDLY,
           "schools": {"year": "令和8年度", "by_ku": sc, "page": "https://www.city.nagoya.jp/kodomo/schools/1016972/1016975.html",
                       "pdf": "https://www.city.nagoya.jp/_res/projects/default_project/_page_/001/016/975/8nenndozissikou.pdf"},
           "subsidy": subsidy()}
    os.makedirs(os.path.join(ROOT, "php"), exist_ok=True)
    json.dump(out, open(os.path.join(ROOT, "php", "kibasho_data.json"), "w", encoding="utf-8"), ensure_ascii=False)
    miss = [l["addr"] for it in rk["items"] for l in it["locations"] if l["addr"] != "オンライン" and not l.get("geo")]
    print(f"団体{len(rk['items'])} 校内 中{n_j}・小{n_e} フレンドリー{len(FRIENDLY)} 位置が引けない住所: {miss}")


if __name__ == "__main__":
    main()
