<?php
// Dunkles Layout und Formular-Helfer für das Admin-Panel

function ui_nav(): array {
    return [['dashboard', 'Übersicht'], ['movies', 'Filme'], ['series', 'Serien'], ['live', 'Live-TV'],
            ['cats', 'Kategorien'], ['users', 'Benutzer'], ['downloads', 'Downloads'], ['settings', 'Einstellungen']];
}

function ui_css(): string {
    return <<<'CSS'
:root{color-scheme:dark;--bg:#0d1117;--side:#10151d;--p:#151b25;--p2:#1c2431;--line:#263145;--tx:#e8ebf1;--mute:#8996ab;--acc:#e9a23b;--acc-ink:#1b1204;--ok:#4cc38a;--bad:#ef6461;--info:#5b9bf0}
*{box-sizing:border-box}
html{background:var(--bg)}
body{margin:0;color:var(--tx);font:14px/1.55 "Segoe UI",system-ui,-apple-system,Roboto,"Helvetica Neue",sans-serif}
a{color:var(--acc);text-decoration:none}a:hover{text-decoration:underline}
h1{font-size:22px;margin:0 0 18px;font-weight:650;letter-spacing:-.01em}
h2{font-size:16px;margin:0 0 12px;font-weight:650}
.side{position:fixed;inset:0 auto 0 0;width:210px;background:var(--side);border-right:1px solid var(--line);padding:20px 12px;display:flex;flex-direction:column;gap:2px;overflow:auto}
.brand{padding:4px 12px 18px;font-weight:700;font-size:16px;line-height:1.2}
.brand small{display:block;color:var(--mute);font-weight:400;font-size:12px;margin-top:2px}
.side a.nav{color:var(--mute);padding:8px 12px;border-radius:8px;border-left:3px solid transparent}
.side a.nav:hover{background:var(--p);color:var(--tx);text-decoration:none}
.side a.nav.on{background:var(--p2);color:var(--tx);border-left-color:var(--acc)}
.side .foot{margin-top:auto;padding:12px;font-size:12px;color:var(--mute)}
main{margin-left:210px;padding:28px 34px 60px;max-width:1280px}
.card{background:var(--p);border:1px solid var(--line);border-radius:12px;padding:18px 20px;margin-bottom:18px}
.grid{display:grid;gap:14px}
.stats{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
.stat{background:var(--p);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
.stat b{display:block;font-size:24px;font-weight:650}
.stat span{color:var(--mute);font-size:13px}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.cols3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
table{width:100%;border-collapse:collapse}
th{text-align:left;color:var(--mute);font-weight:500;font-size:13px;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
tbody tr:hover td{background:#18202c}
td.r,th.r{text-align:right}
input,select,textarea{background:#0e131b;color:var(--tx);border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit;max-width:100%}
input:focus,select:focus,textarea:focus,button:focus-visible,.btn:focus-visible{outline:2px solid var(--acc);outline-offset:1px;border-color:transparent}
input[type=checkbox]{width:16px;height:16px;padding:0;accent-color:var(--acc)}
input[type=file]{padding:6px}
textarea{resize:vertical;min-height:90px}
button,.btn{background:var(--acc);color:var(--acc-ink);border:0;border-radius:8px;padding:8px 14px;font:inherit;font-weight:650;cursor:pointer;display:inline-block;line-height:1.4}
button:hover,.btn:hover{filter:brightness(1.08);text-decoration:none}
.btn.sec,button.sec{background:var(--p2);color:var(--tx);border:1px solid var(--line)}
.btn.bad,button.bad{background:#3a1d22;color:#ff9a97;border:1px solid #5a2a31}
.btn.sm,button.sm{padding:3px 10px;font-size:12.5px;font-weight:600}
.field{margin-bottom:12px}
.field>label{display:block;color:var(--mute);font-size:13px;margin-bottom:4px}
.field input:not([type=checkbox]),.field select,.field textarea{width:100%}
.field small{display:block;color:var(--mute);margin-top:3px;font-size:12.5px}
.check{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.row.sp{justify-content:space-between}
.mute{color:var(--mute)}
.badge{display:inline-block;padding:1px 9px;border-radius:99px;font-size:12px;background:var(--p2);border:1px solid var(--line)}
.badge.ok{color:var(--ok);border-color:#24503f}.badge.bad{color:var(--bad);border-color:#5a2a31}.badge.warn{color:var(--acc);border-color:#5b4420}
.flash{padding:10px 14px;border-radius:10px;margin-bottom:16px;border:1px solid var(--line);background:var(--p2)}
.flash.ok{border-color:#24503f;background:#13241e}.flash.bad{border-color:#5a2a31;background:#2a171b}
.thumb{width:38px;height:56px;object-fit:cover;border-radius:5px;background:#0b0f15;display:block}
.wall{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:14px}
.poster{position:relative;background:var(--p);border:1px solid var(--line);border-radius:10px;overflow:hidden}
.poster:hover{border-color:var(--acc)}
.poster img,.poster .ph{width:100%;aspect-ratio:2/3;object-fit:cover;display:block;background:#0b0f15}
.poster .ph{display:grid;place-items:center;color:var(--mute);font-size:30px;font-weight:700;background:linear-gradient(160deg,#1c2431,#10151d)}
.poster .t{padding:8px 10px 10px;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:3.1em}
.poster .t small{display:block;color:var(--mute);font-weight:400}
.poster .sel{position:absolute;left:8px;top:8px;width:20px;height:20px}
.poster .rt{position:absolute;right:8px;top:8px;background:rgba(13,17,23,.85);border-radius:6px;padding:0 7px;font-size:12px;font-weight:650;color:var(--acc)}
.poster a.go{position:absolute;inset:0}
.poster .sel{z-index:2}
.preview{width:100%;max-width:240px;aspect-ratio:2/3;object-fit:cover;border-radius:10px;border:1px solid var(--line);background:#0b0f15}
.edit{display:grid;grid-template-columns:260px 1fr;gap:26px;align-items:start}
.pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:16px}
.pager a,.pager span{padding:4px 11px;border-radius:8px;border:1px solid var(--line);background:var(--p)}
.pager span{background:var(--acc);color:var(--acc-ink);border-color:var(--acc);font-weight:650}
.tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.tools input[type=search]{min-width:220px}
details{margin-bottom:12px}summary{cursor:pointer;color:var(--mute);margin-bottom:8px}
code{background:#0e131b;border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:13px}
.empty{padding:34px;text-align:center;color:var(--mute)}
.bar{height:8px;background:#0e131b;border-radius:99px;overflow:hidden;border:1px solid var(--line)}
.bar>span{display:block;height:100%;background:var(--acc);border-radius:99px}
.bar.warn>span{background:var(--bad)}
.sizerow{display:grid;grid-template-columns:minmax(120px,1.3fr) 2fr 84px;gap:12px;align-items:center;padding:6px 0}
.sizerow .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sizerow .v{text-align:right;color:var(--mute);white-space:nowrap}
.login{max-width:360px;margin:12vh auto;padding:0 16px}
@media(max-width:860px){.side{position:static;width:auto;flex-direction:row;flex-wrap:wrap;border-right:0;border-bottom:1px solid var(--line);padding:10px}.brand,.side .foot{display:none}.side a.nav{border-left:0}main{margin-left:0;padding:18px 14px 50px}.cols,.cols3,.edit{grid-template-columns:1fr}.preview{max-width:160px}}
@media(prefers-reduced-motion:no-preference){button,.btn,.poster{transition:filter .12s,border-color .12s}}
CSS;
}

// Favicons: alle Bilder im Ordner logos/ (neben admin.php) werden automatisch eingebunden. Der Ordner ist fest, nicht im Panel einstellbar.
const FAVICON_DIR = 'logos';

function favicon_source(): array {   // [relativer Ordner, Dateinamen, absoluter Ordner]
    $abs = __DIR__ . '/' . FAVICON_DIR;
    $files = is_dir($abs) ? array_values(array_filter(scandir($abs) ?: [], fn($f) => preg_match('/\.(png|ico|svg|webp|gif)$/i', $f))) : [];
    sort($files);
    return [FAVICON_DIR, $files, $abs];
}

function favicon_links(): string {
    [$rel, $files, $dir] = favicon_source();
    $prefix = implode('/', array_map('rawurlencode', explode('/', $rel)));
    $o = ''; $square = [];
    foreach ($files as $name) {
        $f = $dir . '/' . $name;
        $href = h($prefix . '/' . rawurlencode($name) . '?v=' . filemtime($f));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'svg') { $o .= '<link rel="icon" type="image/svg+xml" href="' . $href . '">'; continue; }
        if ($ext === 'ico') { $o .= '<link rel="icon" sizes="any" href="' . $href . '">'; continue; }
        $info = @getimagesize($f);
        if (!$info) continue;
        $o .= '<link rel="icon" type="' . h($info['mime']) . '" sizes="' . (int)$info[0] . 'x' . (int)$info[1] . '" href="' . $href . '">';
        if ($info[0] === $info[1] && $info[0] >= 120 && $info[0] <= 512) $square[$info[0]] = $href;
    }
    if ($square) {   // iPhone-Startbildschirm: kleinstes Bild ab 180 Pixel (wird verkleinert), sonst das größte darunter
        $big = array_filter(array_keys($square), fn($k) => $k >= 180);
        $pick = $big ? min($big) : max(array_keys($square));
        $o .= '<link rel="apple-touch-icon" href="' . $square[$pick] . '">';
    }
    return $o;
}

function ui_head(string $title, string $active = ''): void {
    $name = h(setting('server_name'));
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . $name . '</title>' . favicon_links() . '<style>' . ui_css() . '</style></head><body>';
    if ($active !== '') {
        echo '<nav class="side"><div class="brand">' . $name . '<small>Xtream-Panel ' . APP_VERSION . '</small></div>';
        foreach (ui_nav() as [$k, $label]) echo '<a class="nav' . ($k === $active ? ' on' : '') . '" href="admin.php?p=' . $k . '">' . h($label) . '</a>';
        echo '<div class="foot"><a href="admin.php?do=logout">Abmelden</a></div></nav><main>';
    } else {
        echo '<main class="login">';
    }
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) echo '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
    unset($_SESSION['flash']);
}

function ui_foot(): void {
    echo <<<'HTML'
</main>
<script>
document.addEventListener('change',function(e){
  var t=e.target;
  if(t.matches('[data-selall]')){document.querySelectorAll('input[name="ids[]"]').forEach(function(c){c.checked=t.checked;});}
  if(t.matches('[data-preview]')){var im=document.getElementById(t.dataset.preview);if(im&&t.value){im.src=t.value;}}
});
document.addEventListener('submit',function(e){
  var m=e.submitter&&e.submitter.dataset.confirm||e.target.dataset.confirm;
  if(m&&!confirm(m)){e.preventDefault();}
});
document.addEventListener('click',function(e){
  var b=e.target.closest('[data-copy]');
  if(!b)return;
  var el=document.getElementById(b.dataset.copy);
  if(!el)return;
  el.select();
  try{document.execCommand('copy');var o=b.textContent;b.textContent='Kopiert';setTimeout(function(){b.textContent=o;},1200);}catch(x){}
});
</script></body></html>
HTML;
}

// ---------- Formular-Helfer ----------
function f_text(string $label, string $name, $val = '', array $o = []): string {
    return '<div class="field"><label>' . h($label) . '</label><input type="' . h($o['type'] ?? 'text') . '" name="' . h($name) . '" value="' . h($val) . '" placeholder="' . h($o['ph'] ?? '') . '" ' . ($o['attr'] ?? '') . '>'
         . (!empty($o['hint']) ? '<small>' . h($o['hint']) . '</small>' : '') . '</div>';
}
function f_area(string $label, string $name, $val = '', array $o = []): string {
    return '<div class="field"><label>' . h($label) . '</label><textarea name="' . h($name) . '" rows="' . (int)($o['rows'] ?? 4) . '">' . h($val) . '</textarea>'
         . (!empty($o['hint']) ? '<small>' . h($o['hint']) . '</small>' : '') . '</div>';
}
function f_select(string $label, string $name, array $opts, $sel = '', array $o = []): string {
    $s = '<div class="field"><label>' . h($label) . '</label><select name="' . h($name) . '">';
    foreach ($opts as $v => $t) $s .= '<option value="' . h($v) . '"' . ((string)$v === (string)$sel ? ' selected' : '') . '>' . h($t) . '</option>';
    return $s . '</select>' . (!empty($o['hint']) ? '<small>' . h($o['hint']) . '</small>' : '') . '</div>';
}
function f_check(string $label, string $name, bool $on = false): string {
    return '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($on ? ' checked' : '') . '> ' . h($label) . '</label>';
}

function img_src(string $v): string { return $v; }   // lokale Cover sind relativ (covers/..), URLs absolut

function poster_html(string $cover, string $title): string {
    if ($cover !== '') return '<img loading="lazy" src="' . h(img_src($cover)) . '" alt="">';
    $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $title);
    $ini = preg_match('/^.{1,2}/su', (string)$clean, $m) ? $m[0] : '?';
    return '<div class="ph">' . h(strtoupper($ini)) . '</div>';
}