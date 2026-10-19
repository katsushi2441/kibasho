# Kurage 不登校の居場所ナビ（kibasho）

名古屋市のフリースクール・不登校の居場所を、4種類の違いが分かる形で引くページ。
https://kurage.exbridge.jp/kibasho.php/

- 連絡会の参加団体（令和7年度・40団体）… 市の推薦ではない。再掲は市（教育委員会 新しい学校づくり推進課）の回答（2026-10-19）に沿い、出典を明示して「連絡会に参加した団体」と分かる形で使う
- 校内の教室以外の居場所（令和8年度・中学校111校・小学校5校）
- なごやフレンドリーナウ（市の教育支援センター）4か所
- 名古屋市フリースクール等利用料補助金（補助率・上限・要件）。補助の対象かは通い始めて申請したあとの審査で決まる

## 作り方

    /usr/bin/python3 scripts/fetch_sources.py   # 名古屋市の元資料を data/src/ に取る
    /usr/bin/python3 scripts/build_data.py      # → php/kibasho_data.json（位置は国土地理院の住所検索）
    /usr/bin/python3 scripts/make_ogp.py        # outputs/kibasho_ogp.png（images/ogp/kibasho.png に置く）

置くもの: php/kibasho.php と php/kibasho_data.json を同じ階層に。PHP 8.1 以上。データベースは要りません。
定数 SITE・BASE（kibasho.php の先頭）を置き先の URL に書き換えてください。

## データの再掲について

連絡会の参加団体の一覧は、名古屋市教育委員会 新しい学校づくり推進課に確認のうえ再掲しています（2026-10-19 回答）。
条件は、出典を明示すること、掲載団体が「名古屋市が推薦する団体ではなく、連絡会に参加した団体」だと分かる形で使うことです。
このページはどちらもページ内に表示しています。改変して使うときも、この2つは消さないでください。

連絡会の一覧は PDF を目で書き写した data/renrakukai.json が正（parse_renrakukai.py は下書き）。
校内の居場所の実施校は build_data.py の SCHOOLS に書き写し、PDF の本文に全校名があるかを照合する。
市の資料が更新されたら data/src/ を取り直して作り直す。
