<?php
/**
 * Kurage 不登校の居場所ナビ（kibasho）— 名古屋市のフリースクール・不登校の居場所を、4種類の違いが分かる形で引く。
 *
 *   /kibasho.php/            トップ（全体・住所や現在地から近い順・補助金）
 *   /kibasho.php/<区>        区ごと（例 /kibasho.php/midori）
 *   /kibasho.php/about       出典とデータの作り方
 *   /kibasho.php/sitemap.xml /robots.txt /llms.txt
 *
 * データは同じ階層の kibasho_data.json（scripts/build_data.py が名古屋市の公表資料から作る）。
 * 連絡会の参加団体一覧の再掲は、名古屋市教育委員会 新しい学校づくり推進課の回答（2026-10-19）に沿う:
 * 出典を明示し、「市の推薦ではなく連絡会に参加した団体」と分かる形で使う。
 * 位置の検索は利用者のブラウザから国土地理院の住所検索を呼ぶ（このサーバーには送らない）。
 *
 * heteml に置くときは、その階層の .htaccess に `AddHandler php-script .php` が要る。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');

const NAME = 'Kurage 不登校の居場所ナビ';
const SITE = 'https://kurage.exbridge.jp';
const BASE = SITE . '/kibasho.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function u(string $p = '/'): string { return BASE . $p; }
function ours(): bool { return ($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp'; }

$D = json_decode((string)@file_get_contents(__DIR__ . '/kibasho_data.json'), true);
if (!is_array($D)) { http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); echo 'データがありません（kibasho_data.json）'; exit; }
$SLUG2KU = array_flip($D['ku_slug']);

$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '' && substr((string)($_SERVER['REQUEST_URI'] ?? ''), -1) !== '/' && strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '?') === false) {
    header('Location: ' . u('/'), true, 302); exit;
}
$path = '/' . trim($path, '/');

/** 2点の距離（km） */
function dist(?array $a, ?array $b): ?float
{
    if (!$a || !$b) { return null; }
    $r = 6371.0; $p1 = deg2rad($a['lat']); $p2 = deg2rad($b['lat']);
    $dp = $p2 - $p1; $dl = deg2rad($b['lon'] - $a['lon']);
    $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($x)));
}

/* ---------------- 部品 ---------------- */

const RENRAKU_NOTE = 'この一覧の団体は、名古屋市が推薦する団体ではありません。名古屋市教育委員会が開いた「不登校児童生徒を支援する民間団体（施設）連絡会」（令和7年8月20日）に参加した団体です。市の一覧にも「活動内容を保証したり、出席扱いを認定したりするものではございません」とあります。運営方針・活動内容・費用は、各団体に直接確かめてください。';

/** 連絡会の団体1件のカード。$from があれば距離を出す */
function org_card(array $it, ?array $from = null, ?string $onlyKu = null): string
{
    $locs = $it['locations'];
    $rows = '';
    foreach ($locs as $l) {
        if ($onlyKu && $l['ku'] !== $onlyKu) { continue; }
        $d = $from && !empty($l['geo']) ? dist($from, $l['geo']) : null;
        $rows .= '<p class="addr">' . ($l['label'] ? '<b>' . h($l['label']) . '</b>　' : '') . ($l['zip'] ? '〒' . h($l['zip']) . ' ' : '') . h($l['addr'])
              . (!empty($l['approx']) ? '<span class="tag">住所はおおよそ</span>' : '')
              . ($d !== null ? '<span class="km">約' . h(number_format($d, 1)) . 'km</span>' : '') . '</p>';
    }
    if (!$locs) { $rows = '<p class="addr">住所の記載なし</p>'; }
    $links = [];
    foreach ($it['tel'] as $t) { $links[] = '電話 ' . h($t); }
    if ($it['url']) { $links[] = '<a href="' . h($it['url']) . '" rel="nofollow noopener" target="_blank">ホームページ</a>'; }
    foreach ($it['mail'] as $m) { $links[] = strpos($m, 'http') === 0 ? '<a href="' . h(preg_replace('/（.*$/u', '', $m)) . '" rel="nofollow noopener" target="_blank">問い合わせフォーム</a>' : h($m); }
    $kus = array_values(array_unique(array_map(fn($l) => $l['ku'], $locs)));
    return '<div class="card" data-id="' . h($it['id']) . '"><div class="kind k1">連絡会の参加団体</div><b>' . h($it['name']) . '</b>'
         . '<span class="ku">' . h(implode('・', $kus ?: ['住所なし'])) . '</span>' . $rows . '<p class="ct">' . implode('<br>', $links) . '</p></div>';
}

function friendly_card(array $f, ?array $from = null): string
{
    $d = $from ? dist($from, $f['geo'] ?? null) : null;
    return '<div class="card"><div class="kind k3">市の教育支援センター</div><b>' . h($f['name']) . '</b><span class="ku">' . h($f['ku']) . '</span>'
         . '<p class="addr">〒' . h($f['zip']) . ' ' . h($f['addr']) . ($d !== null ? '<span class="km">約' . h(number_format($d, 1)) . 'km</span>' : '') . '</p>'
         . (!empty($f['note']) ? '<p class="src">' . h($f['note']) . '</p>' : '')
         . '<p class="ct">見学予約 ' . h($f['tel']) . '</p></div>';
}

function four_kinds(): string
{
    return '<div class="kinds">'
        . '<div><span class="kind k1">連絡会の参加団体</span><p>市が開いた連絡会に参加した民間のフリースクールなど（40団体）。<b>市の推薦ではありません。</b></p></div>'
        . '<div><span class="kind k2">校内の居場所</span><p>学校の中の、教室以外の居場所（令和8年度は中学校111校・小学校5校）。在籍校で使います。</p></div>'
        . '<div><span class="kind k3">市の教育支援センター</span><p>なごやフレンドリーナウ（浄心・笠寺・鶴舞・大曽根の4か所）。見学のあと、学校を通じて申し込みます。</p></div>'
        . '<div><span class="kind k4">利用料補助の対象施設</span><p>市の利用料補助金の対象かどうかは、<b>通い始めて申請したあとの審査で決まります。</b>市は対象施設の一覧を出していません。</p></div>'
        . '</div><p class="warn">「連絡会に参加している」「補助金の対象」「学校で出席扱いになる」は、それぞれ別のことです。名古屋市の補助金のページにも「本補助金を受給することのみをもって『指導要録上の出席扱い等』となるものではありません」とあります。</p>';
}

function schools_html(array $D, ?string $onlyKu = null): string
{
    $s = $D['schools']['by_ku'];
    $o = '';
    if (!$onlyKu) {
        $el = [];
        foreach ($s as $ku => $names) { foreach ($names as $n) { if (substr($n, -3) === '小') { $el[] = $n . '学校（' . $ku . '）'; } } }
        $o .= '<p><b>小学校は5校：</b>' . h(implode('、', $el)) . '。中学校は111校です。</p>';
    }
    $o .= '<div class="tbl"><table><tr><th>区</th><th>実施校（' . h($D['schools']['year']) . '）</th></tr>';
    foreach ($s as $ku => $names) {
        if ($onlyKu && $ku !== $onlyKu) { continue; }
        $o .= '<tr><td>' . ($onlyKu ? h($ku) : '<a href="' . h(u('/' . $D['ku_slug'][$ku])) . '">' . h($ku) . '</a>') . '</td><td>'
            . h(implode('、', array_map(fn($n) => $n . (substr($n, -3) === '小' ? '学校' : '学校'), $names))) . '</td></tr>';
    }
    return $o . '</table></div><p class="src">出典：名古屋市「<a href="' . h($D['schools']['page']) . '" rel="noopener">校内の教室以外の居場所づくり</a>」の実施校一覧（' . h($D['schools']['year']) . '）。利用できるのは在籍している学校の居場所で、校長が利用を認めた児童生徒が対象です。</p>';
}

function subsidy_html(array $D): string
{
    $S = $D['subsidy'];
    $o = '<div class="tbl"><table><tr><th>区分</th><th>補助率</th><th>上限</th></tr>';
    foreach ($S['rates'] as $r) { $o .= '<tr><td>' . h($r['who']) . '</td><td>' . h($r['rate']) . '</td><td>' . h($r['cap']) . '</td></tr>'; }
    $o .= '</table></div><p class="src">' . h($S['note']) . '</p>';
    $o .= '<div class="note"><b>通い始める前には、その施設が補助の対象かどうか分かりません。</b>名古屋市の説明では、補助の対象かどうかは、保護者が交付申請をしたあとに、提出書類・施設へのヒアリング・現地確認で審査します。通所の前に確かめる窓口や手順はありません。対象外だった場合、利用料は全額自己負担になります。施設の要件を下に書き写したので、気になる施設には、これらに当てはまるかを直接たずねてください。</div>';
    $li = fn($a) => '<ul>' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $a)) . '</ul>';
    $o .= '<details><summary>施設の要件（市のポータルの事業概要から）</summary>' . $li($S['facility']) . '</details>';
    $o .= '<details><summary>申請する保護者の要件</summary>' . $li($S['applicant']) . '</details>';
    $o .= '<details><summary>子どもの要件</summary>' . $li($S['child']) . '</details>';
    return $o . '<p class="src">出典：名古屋市「<a href="https://fs-subsidy.city.nagoya.jp/r8/about" rel="noopener">名古屋市フリースクール等利用料補助金ポータルサイト（令和8年度）</a>」。問い合わせは補助金事務局 052-766-7519（平日9時〜18時）。</p>';
}

function locate_box(string $hint = ''): string
{
    return '<div class="panel" id="near"><b>住所か現在地から、近い順に並べる</b>'
         . '<div class="row"><input id="addr" type="text" placeholder="例：名古屋市緑区鳴海町" value="' . h($hint) . '" aria-label="住所"><button class="btn" id="go" type="button">近い順に並べる</button><button class="btn sub" id="gps" type="button">現在地から</button></div>'
         . '<p class="src">住所は国土地理院の住所検索で位置に直します。入れた住所や現在地は、このサイトのサーバーには送りません。距離は直線距離です。</p><p id="msg" class="src"></p></div>';
}

const FAQ = [
    ['名古屋市にフリースクールはいくつありますか？', '名古屋市教育委員会が開いた「不登校児童生徒を支援する民間団体（施設）連絡会」（令和7年8月20日）には40団体が参加しました。そのうち名古屋市内に住所があるのは29団体、市外が8団体、オンラインが1団体、住所の記載がないのが2団体です。連絡会に参加していない施設もあるので、市内のフリースクールの総数ではありません。'],
    ['連絡会に参加している団体は、名古屋市の補助金の対象ですか？', '別のことです。補助金の対象かどうかは、保護者が交付申請をしたあとの審査で決まります。市は対象施設の一覧を公表していません。'],
    ['フリースクールに通うと、学校の出席扱いになりますか？', '在籍校の校長が判断します。名古屋市の補助金のページにも、補助金を受けることだけで「指導要録上の出席扱い等」になるものではないと書かれています。まず在籍校に相談してください。'],
    ['名古屋市のフリースクールの利用料補助はいくらですか？', '令和8年度は、就学援助を受けている世帯などは利用料の2分の1（子ども1人あたり月22,000円まで）、それ以外は4分の1（月11,000円まで）です。対象は平日日中の通所の利用料（授業料）で、入学費や交通費などは入りません。予算の範囲内での交付です。'],
    ['校内の教室以外の居場所は、どの学校にありますか？', '令和8年度は中学校111校と、小学校5校（宮根小・大手小・天子田小・小坂小・原小）です。区ごとの学校名をこのページに載せています。'],
];

function page(string $title, string $desc, string $url, string $body, array $crumbs = [], bool $faq = false): void
{
    $ld = [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'description' => $desc, 'url' => $url, 'inLanguage' => 'ja',
            'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']]];
    if ($crumbs) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => NAME, 'item' => u('/')]];
        foreach ($crumbs as $i => [$n, $l]) { $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $n, 'item' => $l]; }
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    if ($faq) {
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
                 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], FAQ)];
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '"><link rel="canonical" href="' . h($url) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h(NAME) . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . SITE . '/images/ogp/kibasho.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image"><meta name="color-scheme" content="light">';
    echo '<link rel="icon" href="https://exbridge.jp/images/logo-mark-128.png">';
    foreach ($ld as $j) { echo '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    echo '<style>
:root{--navy:#14213d;--teal:#0a726b;--teal-l:#e6f4f2;--ink:#1f2933;--sub:#5b6676;--line:#dde3ea;--bg:#f6f8fb;--amber:#9a6700;--amber-l:#fff6e0;--rose:#b4235a;--rose-l:#fde8f0;--blue:#1d4ed8;--blue-l:#eaf0fe;--violet:#6d28d9;--violet-l:#f1eafe}
*{box-sizing:border-box}html{color-scheme:light}body{margin:0;background:#fff;color:var(--ink);font:15.5px/1.75 "Noto Sans JP","Hiragino Sans","Yu Gothic",system-ui,sans-serif}
a{color:var(--teal);overflow-wrap:anywhere}.wrap{max-width:980px;margin:0 auto;padding:0 16px;min-width:0}
header.top{border-bottom:1px solid var(--line);background:#fff}header.top .wrap{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;min-height:58px;padding-top:6px;padding-bottom:6px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);text-decoration:none;font-weight:800;font-size:16px;line-height:1.3}.brand img{width:34px;height:34px;object-fit:contain;flex:none}.brand small{display:block;color:var(--teal);font-size:11.5px;font-weight:700}
nav.menu{display:flex;flex-wrap:wrap;gap:2px 14px;font-size:14px;font-weight:700}nav.menu a{color:var(--sub);text-decoration:none}
.promo{margin-left:auto;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:13px;font-weight:800}.promo a{color:#b45309;text-decoration:none}
main{padding:10px 0 40px}h1{font-size:clamp(22px,4vw,30px);line-height:1.4;color:var(--navy);margin:18px 0 8px;text-wrap:balance}
h2{font-size:19px;color:var(--navy);margin:30px 0 10px;border-left:5px solid var(--teal);padding-left:10px}h3{font-size:16px;color:var(--navy);margin:18px 0 6px}
.lead{color:var(--sub);margin:0 0 14px}.panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin:12px 0;min-width:0}
.note{background:var(--amber-l);border-left:4px solid var(--amber);border-radius:8px;padding:10px 12px;font-size:14.5px;margin:12px 0}.warn{background:var(--rose-l);border-left:4px solid var(--rose);border-radius:8px;padding:10px 12px;font-size:14.5px;margin:12px 0}
.src{font-size:12.5px;color:var(--sub)}
.kinds{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:10px;margin:12px 0}.kinds div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;min-width:0}.kinds p{margin:6px 0 0;font-size:14px}
.kind{display:inline-block;font-size:12px;font-weight:800;border-radius:999px;padding:2px 10px}.k1{background:var(--teal-l);color:var(--teal)}.k2{background:var(--blue-l);color:var(--blue)}.k3{background:var(--violet-l);color:var(--violet)}.k4{background:var(--amber-l);color:var(--amber)}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,290px),1fr));gap:10px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;min-width:0}
.card b{display:block;color:var(--navy);margin-top:4px}.card .ku{font-size:12.5px;color:var(--sub);font-weight:700}.card p{margin:4px 0;font-size:14px}.card .ct{color:var(--sub)}
.tag{display:inline-block;margin-left:6px;font-size:11.5px;background:var(--amber-l);color:var(--amber);border-radius:6px;padding:0 6px}.km{display:inline-block;margin-left:6px;font-size:12.5px;font-weight:800;color:var(--teal)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,140px),1fr));gap:8px}.grid a{display:block;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 10px;text-decoration:none;color:var(--ink)}.grid a b{color:var(--navy)}.grid a small{display:block;color:var(--sub);font-size:12px}
.row{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}input{font:inherit;font-size:16px;padding:9px 11px;border:2px solid var(--line);border-radius:10px;flex:1 1 220px;min-width:0;background:#fff}
.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:700;text-decoration:none;cursor:pointer;font-size:15px}.btn.sub{background:#fff;color:var(--teal);border:2px solid var(--teal)}
.tbl{overflow-x:auto;-webkit-overflow-scrolling:touch}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}th{color:var(--sub);white-space:nowrap}
details{border-bottom:1px solid var(--line);padding:8px 0}summary{cursor:pointer;font-weight:700}
footer{margin-top:40px;border-top:1px solid var(--line);padding:18px 0;font-size:13px;color:var(--sub)}footer p{margin:6px 0}
.xshare{display:inline-flex;align-items:center;gap:8px;background:#0f1419;color:#fff;text-decoration:none;font-weight:800;font-size:14px;border-radius:999px;padding:8px 16px}
</style></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . u('/') . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="34" height="34" alt="株式会社エクスブリッジ"><span>' . h(NAME) . '<small>名古屋市のフリースクール・不登校の居場所</small></span></a>';
    echo '<nav class="menu"><a href="' . u('/') . '#list">一覧</a><a href="' . u('/') . '#ku">区で探す</a><a href="' . u('/') . '#hojo">補助金</a><a href="' . u('/about') . '">出典</a></nav>';
    if (ours()) {
        echo '<!--kurage-only--><span class="promo"><a href="https://exbridge.jp/ai-it-komon.html#ref=kibasho-head-komon" target="_blank" rel="noopener">AI-IT顧問</a><a href="https://kurage.exbridge.jp/reseller.html#ref=kibasho-head-reseller" target="_blank" rel="noopener">販売代理店募集</a></span><!--/kurage-only-->';
    }
    echo '</div></header><main class="wrap">' . $body . '</main>';
    echo '<footer><div class="wrap">';
    echo '<p>出典：名古屋市教育委員会「<a href="https://www.city.nagoya.jp/kodomo/schools/1016972/1016976.html" rel="noopener">不登校児童生徒を支援する民間団体（施設）連絡会</a>」の参加団体一覧（令和7年8月20日実施）、「<a href="https://www.city.nagoya.jp/kodomo/schools/1016972/1016975.html" rel="noopener">校内の教室以外の居場所づくり</a>」、「<a href="https://www.city.nagoya.jp/kodomo/schools/1015850/1017055.html" rel="noopener">なごやフレンドリーナウ</a>」、「<a href="https://fs-subsidy.city.nagoya.jp/r8/about" rel="noopener">名古屋市フリースクール等利用料補助金ポータルサイト</a>」。団体名・住所・電話番号・ホームページ・メールアドレスは、市の一覧のとおり（各団体の申し出に基づく表記）。' . h(NAME) . 'は株式会社エクスブリッジが作っています。名古屋市の公式のページではありません。</p>';
    echo '<p><a href="https://exbridge.jp/#ref=kibasho">株式会社エクスブリッジ</a>（名古屋・業務システム開発）・<a href="https://xb4g.com/giin/tracker/futoko#ref=kibasho">国会トラッカー 不登校</a>・<a href="' . SITE . '/kseido.php/#ref=kibasho">Kurage 制度ナビ</a></p>';
    if (ours()) { echo '<p><a href="https://kappstore.exbridge.jp/app.php?id=a2f1706ca3edff6b#ref=kibasho-foot" rel="noopener">このページの一式を自社や団体のサイトに置く（商品ページ・ソースコード付き）</a></p>'; }
    echo '</div></footer>';
    if (ours()) {
        echo '<img src="' . SITE . '/simpletrack.php?t=img&url=' . rawurlencode($url) . '" width="1" height="1" alt="" aria-hidden="true" style="position:absolute;left:-9999px">';
        echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    }
    echo '</body></html>';
}

/** 住所・現在地から近い順（ブラウザで並べ替える。データはページに埋めた位置だけ） */
function near_script(array $D): string
{
    $pts = [];
    foreach ($D['renrakukai']['items'] as $it) { foreach ($it['locations'] as $l) { if (!empty($l['geo'])) { $pts[] = [$it['id'], $l['geo']['lat'], $l['geo']['lon']]; } } }
    return '<script>(function(){var P=' . json_encode($pts) . ';
function km(a,b,c,d){var r=6371,p=Math.PI/180,x=Math.sin((c-a)*p/2)**2+Math.cos(a*p)*Math.cos(c*p)*Math.sin((d-b)*p/2)**2;return 2*r*Math.asin(Math.min(1,Math.sqrt(x)));}
function sortBy(lat,lon,label){var best={};P.forEach(function(q){var d=km(lat,lon,q[1],q[2]);if(!(q[0] in best)||d<best[q[0]])best[q[0]]=d;});
var box=document.getElementById("orgs");if(!box)return;var cs=[].slice.call(box.querySelectorAll(".card"));
cs.sort(function(a,b){var x=best[a.dataset.id],y=best[b.dataset.id];return (x===undefined?1e9:x)-(y===undefined?1e9:y);});
cs.forEach(function(c){var d=best[c.dataset.id];var s=c.querySelector(".kmx");if(!s){s=document.createElement("span");s.className="km kmx";c.querySelector("b").after(s);}s.textContent=d===undefined?"（位置なし）":"約"+d.toFixed(1)+"km";box.appendChild(c);});
document.getElementById("msg").textContent=label+"から近い順に並べました（直線距離）。";location.hash="list";}
document.getElementById("go").onclick=function(){var q=document.getElementById("addr").value.trim();if(!q)return;document.getElementById("msg").textContent="位置を調べています…";
fetch("https://msearch.gsi.go.jp/address-search/AddressSearch?q="+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(j){if(!j.length){document.getElementById("msg").textContent="住所が見つかりませんでした。区や町名から入れてみてください。";return;}var c=j[0].geometry.coordinates;sortBy(c[1],c[0],j[0].properties.title);}).catch(function(){document.getElementById("msg").textContent="住所の検索に失敗しました。時間をおいてお試しください。";});};
document.getElementById("gps").onclick=function(){if(!navigator.geolocation){document.getElementById("msg").textContent="この端末では現在地が使えません。";return;}navigator.geolocation.getCurrentPosition(function(p){sortBy(p.coords.latitude,p.coords.longitude,"現在地");},function(){document.getElementById("msg").textContent="現在地を取得できませんでした。";});};
})();</script>';
}

function share(string $text, string $url, string $ref): string
{
    return '<p><a class="xshare" href="https://x.com/intent/post?text=' . rawurlencode($text) . '&url=' . rawurlencode($url . '#ref=x-share-' . $ref) . '" target="_blank" rel="noopener">Xで共有</a></p>';
}

/* ---------------- ページ ---------------- */

if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: " . u('/sitemap.xml') . "\n"; exit; }
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $lm = h($D['built']);
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (array_merge(['/', '/about'], array_map(fn($s) => '/' . $s, array_values($D['ku_slug']))) as $p) { echo '<url><loc>' . h(u($p)) . '</loc><lastmod>' . $lm . '</lastmod></url>'; }
    echo '</urlset>'; exit;
}
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo '# ' . NAME . "\n\n> 名古屋市のフリースクール・不登校の居場所を、4種類（連絡会の参加団体40団体／校内の教室以外の居場所 中学校111校・小学校5校／市の教育支援センター なごやフレンドリーナウ4か所／利用料補助の対象施設）の違いが分かる形でまとめたページ。名古屋市の公表資料から作成。連絡会の参加団体は市の推薦ではない。\n\n";
    echo '- [トップ](' . u('/') . ")\n";
    foreach ($D['ku_slug'] as $ku => $s) { echo '- [名古屋市' . $ku . 'のフリースクール・不登校の居場所](' . u('/' . $s) . ")\n"; }
    echo '- [出典とデータの作り方](' . u('/about') . ")\n"; exit;
}

$items = $D['renrakukai']['items'];
$byKu = [];
foreach ($items as $it) { foreach (array_unique(array_map(fn($l) => $l['ku'], $it['locations'])) as $k) { $byKu[$k][] = $it; } }
$inCity = count(array_filter($items, fn($it) => array_filter($it['locations'], fn($l) => in_array($l['ku'], $D['ku'], true))));

if ($path === '/' || $path === '') {
    $title = '名古屋市のフリースクール・不登校の居場所一覧（連絡会参加40団体・校内の居場所・フレンドリーナウ）';
    $desc = '名古屋市のフリースクール・不登校の居場所を、連絡会に参加した40団体、校内の教室以外の居場所（中学校111校・小学校5校）、市の教育支援センター（なごやフレンドリーナウ4か所）、利用料補助金の4種類に分けて、違いが分かる形でまとめました。住所から近い順・区ごとに探せます。出典は名古屋市の公表資料。';
    $b = '<h1>名古屋市のフリースクール・不登校の居場所</h1>'
       . '<p class="lead">名古屋市が公表している4種類の居場所を、1つのページにまとめました。住所や現在地から近い順に並べたり、区ごとに見たりできます。</p>'
       . '<h2>まず知っておきたい、4種類の居場所の違い</h2>' . four_kinds()
       . locate_box()
       . '<h2 id="ku">区で探す</h2><div class="grid">';
    foreach ($D['ku'] as $ku) {
        $n = count($byKu[$ku] ?? []); $sc = count($D['schools']['by_ku'][$ku] ?? []);
        $b .= '<a href="' . h(u('/' . $D['ku_slug'][$ku])) . '"><b>' . h($ku) . '</b><small>連絡会の団体 ' . $n . '・校内の居場所 ' . $sc . '校</small></a>';
    }
    $b .= '</div>';
    $b .= '<h2 id="list">連絡会に参加した団体（40団体）</h2><p class="warn">' . h(RENRAKU_NOTE) . '</p>'
        . '<p class="src">名古屋市内に住所があるのは' . $inCity . '団体です。ほかは市外（日進市・蟹江町・稲沢市・北名古屋市・春日井市・岡崎市）、オンライン、住所の記載なしです。並びは市の一覧と同じ五十音順で、住所や現在地を入れると近い順に変わります。</p><div class="cards" id="orgs">';
    foreach ($items as $it) { $b .= org_card($it); }
    $b .= '</div>';
    $b .= '<h2 id="konai">校内の教室以外の居場所（中学校111校・小学校5校）</h2>' . schools_html($D);
    $b .= '<h2 id="friendly">市の教育支援センター（なごやフレンドリーナウ）</h2><p>心理的な理由で登校していない名古屋市在住の小中学生とその保護者が対象です。通所を申し込む前に、本人と保護者で施設を見学します（電話で予約）。見学のあと、学校から申込書を受け取って学校に出します。</p><div class="cards">';
    foreach ($D['friendly'] as $f) { $b .= friendly_card($f); }
    $b .= '</div><p class="src">出典：名古屋市「なごやフレンドリーナウ（不登校の支援機関）」。電話相談は行っていないため、電話相談だけを希望する場合は「ハートフレンドなごや」052-683-8222 へ、と市のページにあります。</p>';
    $b .= '<h2 id="hojo">名古屋市フリースクール等利用料補助金（令和8年度）</h2>' . subsidy_html($D);
    $b .= '<h2>よくある質問</h2>';
    foreach (FAQ as [$q, $a]) { $b .= '<details><summary>' . h($q) . '</summary><p>' . h($a) . '</p></details>'; }
    $b .= share('名古屋市のフリースクール・不登校の居場所一覧（連絡会参加40団体・校内の居場所・フレンドリーナウ・補助金）', u('/'), 'kibasho');
    $b .= near_script($D);
    page($title, $desc, u('/'), $b, [], true);
    exit;
}

if ($path === '/about') {
    $b = '<h1>出典とデータの作り方</h1>'
       . '<p>このページの内容は、すべて名古屋市の公表資料から作っています。</p>'
       . '<ul><li>連絡会の参加団体40団体：名古屋市教育委員会「<a href="' . h($D['renrakukai']['source']['page']) . '" rel="noopener">不登校児童生徒を支援する民間団体（施設）連絡会</a>」の<a href="' . h($D['renrakukai']['source']['pdf']) . '" rel="noopener">参加団体一覧（PDF）</a>を、1件ずつ書き写しました（' . h($D['renrakukai']['source']['fetched']) . '取得）。団体名・住所・電話番号・ホームページ・メールアドレスは一覧のとおりです。計測用の文字列が付いたホームページのURLだけ、その部分を外しています。</li>'
       . '<li>校内の教室以外の居場所：「<a href="' . h($D['schools']['page']) . '" rel="noopener">校内の教室以外の居場所づくり</a>」の<a href="' . h($D['schools']['pdf']) . '" rel="noopener">実施校一覧（' . h($D['schools']['year']) . '）</a>。</li>'
       . '<li>なごやフレンドリーナウ：市の案内ページの住所・電話番号。</li>'
       . '<li>補助金：「名古屋市フリースクール等利用料補助金ポータルサイト」の事業概要（補助率・上限・要件）。</li></ul>'
       . '<p>連絡会の参加団体の一覧は、名古屋市教育委員会 新しい学校づくり推進課に確認のうえ、出典を明示して再掲しています。掲載している団体は名古屋市が推薦する団体ではなく、連絡会に参加した団体です。</p>'
       . '<p>地図上の位置は、住所を国土地理院の住所検索で引いたもので、建物の位置とずれることがあります。住所が区や付近までしか書かれていない団体は「住所はおおよそ」と表示しています。</p>'
       . '<p>市の資料が更新されたら、このページも作り直します（データの作成日 ' . h($D['built']) . '）。誤りに気づいたら、<a href="https://exbridge.jp/contact.php?subject=' . rawurlencode('不登校の居場所ナビの掲載について') . '#ref=kibasho-about">お問い合わせ</a>からお知らせください。</p>';
    page('出典とデータの作り方｜' . NAME, NAME . 'の出典（名古屋市の公表資料）と、データの作り方。', u('/about'), $b, [['出典', u('/about')]]);
    exit;
}

if (preg_match('#^/([a-z]+)$#', $path, $m) && isset($SLUG2KU[$m[1]])) {
    $ku = $SLUG2KU[$m[1]];
    $c = $D['ku_geo'][$ku] ?? null;
    $here = $byKu[$ku] ?? [];
    $sc = $D['schools']['by_ku'][$ku] ?? [];
    $title = '名古屋市' . $ku . 'のフリースクール・不登校の居場所（連絡会の団体' . count($here) . '・校内の居場所' . count($sc) . '校）';
    $desc = '名古屋市' . $ku . 'にある、不登校の子どもの居場所。連絡会に参加した民間団体' . count($here) . '、校内の教室以外の居場所' . count($sc) . '校、近くのなごやフレンドリーナウと、近くの区の団体を、名古屋市の公表資料からまとめました。';
    $b = '<p class="src"><a href="' . h(u('/')) . '">名古屋市のフリースクール・不登校の居場所</a> › ' . h($ku) . '</p>'
       . '<h1>名古屋市' . h($ku) . 'のフリースクール・不登校の居場所</h1>'
       . '<p class="lead">' . h($ku) . 'にある連絡会の参加団体' . count($here) . '、校内の教室以外の居場所' . count($sc) . '校と、近くの区の団体・なごやフレンドリーナウをまとめました。</p>'
       . four_kinds();
    $b .= '<h2>' . h($ku) . 'の連絡会の参加団体（' . count($here) . '）</h2>';
    if ($here) {
        $b .= '<p class="warn">' . h(RENRAKU_NOTE) . '</p><div class="cards">';
        foreach ($here as $it) { $b .= org_card($it, null, $ku); }
        $b .= '</div>';
    } else {
        $b .= '<p>市の一覧に、' . h($ku) . 'に住所がある団体はありません。下の「近くの区の団体」を見てください。</p>';
    }
    $b .= '<h2>' . h($ku) . 'の校内の教室以外の居場所（' . count($sc) . '校）</h2>' . schools_html($D, $ku);
    // 近くの区の団体（区の中心から近い順に6）
    $near = [];
    foreach ($items as $it) {
        if (in_array($it, $here, true)) { continue; }
        $best = null;
        foreach ($it['locations'] as $l) { if (in_array($l['ku'], $D['ku'], true)) { $d = dist($c, $l['geo'] ?? null); if ($d !== null && ($best === null || $d < $best)) { $best = $d; } } }
        if ($best !== null) { $near[] = [$best, $it]; }
    }
    usort($near, fn($a, $b) => $a[0] <=> $b[0]);
    $b .= '<h2>近くの区の団体</h2><p class="src">' . h($ku) . 'の中心から近い順に6団体（直線距離）。</p><div class="cards">';
    foreach (array_slice($near, 0, 6) as [$d, $it]) { $b .= org_card($it, $c); }
    $b .= '</div>';
    $fs = $D['friendly'];
    usort($fs, fn($a, $b) => (dist($c, $a['geo']) ?? 0) <=> (dist($c, $b['geo']) ?? 0));
    $b .= '<h2>なごやフレンドリーナウ（近い順）</h2><div class="cards">';
    foreach ($fs as $f) { $b .= friendly_card($f, $c); }
    $b .= '</div><p class="src">距離は' . h($ku) . 'の中心からの直線距離です。</p>';
    $b .= '<h2>利用料補助金</h2><p>名古屋市フリースクール等利用料補助金は、就学援助を受けている世帯などで利用料の2分の1（月22,000円まで）、それ以外は4分の1（月11,000円まで）です。<b>補助の対象かどうかは、通い始めて申請したあとの審査で決まります。</b><a href="' . h(u('/')) . '#hojo">補助金の要件をくわしく見る</a></p>';
    $b .= '<h2>ほかの区</h2><div class="grid">';
    foreach ($D['ku'] as $k2) { if ($k2 !== $ku) { $b .= '<a href="' . h(u('/' . $D['ku_slug'][$k2])) . '"><b>' . h($k2) . '</b><small>連絡会の団体 ' . count($byKu[$k2] ?? []) . '</small></a>'; } }
    $b .= '</div>' . share('名古屋市' . $ku . 'のフリースクール・不登校の居場所', u('/' . $m[1]), 'kibasho');
    page($title, $desc, u('/' . $m[1]), $b, [[$ku, u('/' . $m[1])]]);
    exit;
}

http_response_code(404);
page('ページが見つかりません｜' . NAME, '', u('/'), '<h1>ページが見つかりません</h1><p><a href="' . h(u('/')) . '">トップへ</a></p>');
