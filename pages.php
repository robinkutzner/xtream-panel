<?php
// Seiten des Admin-Panels (page_<name>)

function opt_html(array $opts, $sel = ''): string {
    $s = '';
    foreach ($opts as $v => $t) $s .= '<option value="' . h($v) . '"' . ((string)$v === (string)$sel ? ' selected' : '') . '>' . h($t) . '</option>';
    return $s;
}

function cat_options(string $type, bool $none = true): array {
    $o = $none ? [0 => '(ohne Kategorie)'] : [];
    $st = db()->prepare('SELECT id, name FROM categories WHERE type = ? ORDER BY sort, name COLLATE NOCASE');
    $st->execute([$type]);
    foreach ($st->fetchAll() as $c) $o[$c['id']] = $c['name'];
    return $o;
}

function pager(int $total, int $page, int $per): string {
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $o = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === $page) $o .= '<span>' . $i . '</span>';
        elseif ($i === 1 || $i === $pages || abs($i - $page) <= 2) $o .= '<a href="admin.php?' . h(qs(['page' => $i])) . '">' . $i . '</a>';
        elseif (abs($i - $page) === 3) $o .= '<i style="font-style:normal;color:var(--mute)">...</i>';
    }
    return $o . '</div>';
}

function post_btn(string $do, array $fields, string $label, string $cls = 'sec sm', string $confirm = ''): string {
    $s = '<form method="post" style="display:inline" ' . ($confirm !== '' ? 'data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="do" value="' . h($do) . '">';
    foreach ($fields as $k => $v) $s .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return $s . '<input type="hidden" name="back" value="' . h(qs()) . '"><button class="' . h($cls) . '">' . h($label) . '</button></form>';
}

function dur_label(int $s): string { return $s > 0 ? intdiv($s, 60) . ' min' : ''; }

// ---------- Übersicht ----------
function page_dashboard(): void {
    $pdo = db();
    $n = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
    $act = $n('SELECT COUNT(*) FROM sessions WHERE last_seen > ' . (time() - 30));
    $used = $n('SELECT COALESCE(SUM(size), 0) FROM streams') + $n('SELECT COALESCE(SUM(size), 0) FROM episodes');
    $stats = [['Filme', $n("SELECT COUNT(*) FROM streams WHERE type='movie'")], ['Serien', $n('SELECT COUNT(*) FROM series')],
              ['Folgen', $n('SELECT COUNT(*) FROM episodes')], ['Live-Kanäle', $n("SELECT COUNT(*) FROM streams WHERE type='live'")],
              ['Benutzer', $n('SELECT COUNT(*) FROM users')], ['Speicher belegt', human_bytes($used)], ['Läuft gerade', $act]];
    ui_head('Übersicht', 'dashboard');
    echo '<h1>Übersicht</h1><div class="grid stats" style="margin-bottom:18px">';
    foreach ($stats as [$l, $v]) echo '<div class="stat"><b>' . $v . '</b><span>' . h($l) . '</span></div>';
    echo '</div>';

    $sess = $pdo->query("SELECT se.*, u.username, s.name AS sname, e.title AS etitle FROM sessions se LEFT JOIN users u ON u.id = se.user_id
        LEFT JOIN streams s ON se.kind != 'series' AND s.id = se.item_id AND s.type = se.kind
        LEFT JOIN episodes e ON se.kind = 'series' AND e.id = se.item_id
        WHERE se.last_seen > " . (time() - 30) . ' ORDER BY se.started DESC')->fetchAll();
    echo '<div class="card"><h2>Wer schaut gerade</h2>';
    if (!$sess) echo '<div class="empty">Im Moment läuft kein Stream.</div>';
    else {
        echo '<table><thead><tr><th>Benutzer</th><th>Titel</th><th>Typ</th><th>IP-Adresse</th><th>Seit</th></tr></thead><tbody>';
        foreach ($sess as $s) echo '<tr><td>' . h($s['username']) . '</td><td>' . h($s['sname'] ?: $s['etitle'] ?: '#' . $s['item_id']) . '</td><td>' . h($s['kind']) . '</td><td>' . h($s['ip']) . '</td><td>' . date('H:i', $s['started']) . '</td></tr>';
        echo '</tbody></table>';
    }
    echo '</div>';

    // Speicherplatz
    echo '<div class="card"><h2>Speicherplatz</h2>';
    $disks = disk_info();
    if (!$disks) echo '<p class="mute">Die Videoordner wurden nicht gefunden (Einstellungen prüfen).</p>';
    foreach ($disks as [$label, $dir, $free, $total]) {
        $pct = (int)round(($total - $free) / $total * 100);
        echo '<div style="margin-bottom:14px"><div class="row sp"><span>' . h($label) . ' <span class="mute">(' . h($dir) . ')</span></span><span class="mute">' . human_bytes($total - $free) . ' belegt, ' . human_bytes($free) . ' frei von ' . human_bytes($total) . '</span></div>'
           . '<div class="bar' . ($pct >= 90 ? ' warn' : '') . '" style="margin-top:6px"><span style="width:' . $pct . '%"></span></div></div>';
    }
    echo '<p class="mute" style="margin:0">Die Mediathek selbst belegt ' . human_bytes($used) . '.</p></div>';
    $topS = $pdo->query('SELECT s.id, s.name, COUNT(e.id) AS eps, COALESCE(SUM(e.size), 0) AS bytes FROM series s LEFT JOIN episodes e ON e.series_id = s.id GROUP BY s.id HAVING bytes > 0 ORDER BY bytes DESC LIMIT 8')->fetchAll();
    $topM = $pdo->query("SELECT id, name, size AS bytes FROM streams WHERE type = 'movie' AND size > 0 ORDER BY size DESC LIMIT 8")->fetchAll();
    $sizeList = function (array $rows, string $page, string $sub) {
        if (!$rows) return '<p class="mute">Noch keine Größen bekannt. Klicke auf "Mediathek scannen".</p>';
        $max = max(1, (int)$rows[0]['bytes']); $o = '';
        foreach ($rows as $r) {
            $o .= '<div class="sizerow"><a class="n" href="admin.php?p=' . $page . '&id=' . $r['id'] . '" title="' . h($r['name']) . '">' . h($r['name']) . '</a>'
                . '<div class="bar"><span style="width:' . max(2, (int)round($r['bytes'] / $max * 100)) . '%"></span></div><span class="v">' . human_bytes($r['bytes']) . (isset($r['eps']) ? ', ' . $r['eps'] . ' Fo.' : '') . '</span></div>';
        }
        return $o . '<p style="margin:10px 0 0"><a href="admin.php?p=' . $sub . '&sort=size&view=list">Alle nach Größe sortiert</a></p>';
    };
    echo '<div class="cols"><div class="card"><h2>Größte Serien</h2>' . $sizeList($topS, 'series_edit', 'series') . '</div><div class="card"><h2>Größte Filme</h2>' . $sizeList($topM, 'movie_edit', 'movies') . '</div></div>';

    echo '<div class="cols"><div class="card"><h2>Mediathek</h2><p class="mute">Neue Dateien in ' . h(setting('vod_dir')) . ' und ' . h(setting('series_dir')) . ' werden beim Scannen aufgenommen.</p><div class="row">'
       . post_btn('scan', [], 'Mediathek scannen', '')
       . post_btn('cleanup', [], 'Fehlende Dateien entfernen', 'sec', 'Einträge löschen, deren Datei nicht mehr existiert?')
       . post_btn('sizes', [], 'Größen neu berechnen', 'sec')
       . post_btn('tmdb_bulk', ['kind' => 'movie'], 'Filme: Infos von TMDb', 'sec')
       . post_btn('tmdb_bulk', ['kind' => 'series'], 'Serien: Infos von TMDb', 'sec')
       . '</div></div>';

    $ok = fn($c) => $c ? '<span class="badge ok">in Ordnung</span>' : '<span class="badge bad">Problem</span>';
    $warn = fn($c) => $c ? '<span class="badge ok">vorhanden</span>' : '<span class="badge warn">fehlt</span>';
    $vod = setting('vod_dir'); $ser = setting('series_dir');
    $checks = [
        ['PHP-Version ' . PHP_VERSION, $ok(version_compare(PHP_VERSION, '8.1', '>='))],
        ['Ordner data/ beschreibbar', $ok(is_writable(DATA_DIR))],
        ['Ordner covers/ beschreibbar', $ok(is_writable(COVER_DIR))],
        ['Filme-Ordner lesbar (' . $vod . ')', $ok(is_dir($vod) && is_readable($vod))],
        ['Filme-Ordner beschreibbar (für Downloads)', $warn(is_dir($vod) && is_writable($vod))],
        ['Serien-Ordner lesbar (' . $ser . ')', $ok(is_dir($ser) && is_readable($ser))],
        ['Serien-Ordner beschreibbar (für Downloads)', $warn(is_dir($ser) && is_writable($ser))],
        ['curl (für Downloads)', $warn(have_bin('curl'))],
        ['ffprobe (Laufzeit auslesen)', $warn(have_bin('ffprobe'))],
        ['ffmpeg (Cover aus Video)', $warn(have_bin('ffmpeg'))],
        ['TMDb-Schlüssel eingetragen', $warn(trim(setting('tmdb_key')) !== '')],
    ];
    [$frel, $fav, $fdir] = favicon_source();
    $favBad = array_filter($fav, fn($f) => !is_readable($fdir . '/' . $f));
    if (!is_dir($fdir)) $checks[] = ['Favicons: Ordner logos/ fehlt (neben admin.php anlegen)', $warn(false)];
    elseif (!$fav) $checks[] = ['Favicons: Ordner ' . $frel . '/ enthält keine Bilder (png, ico, svg, webp)', $warn(false)];
    elseif ($favBad) $checks[] = ['Favicons: ' . count($favBad) . ' von ' . count($fav) . ' Bildern in ' . $frel . '/ nicht lesbar (Abhilfe: chmod -R a+rX ' . $fdir . ')', $ok(false)];
    else $checks[] = ['Favicons: ' . count($fav) . ' Bilder aus ' . $frel . '/ gefunden und eingebunden', $ok(true)];
    $bad = unreadable_files(5);
    array_splice($checks, 3, 0, [[$bad ? 'Videodateien lesbar (nicht lesbar z. B. ' . basename($bad[0]) . ', Abhilfe: chmod -R a+rX ' . $vod . ' ' . $ser . ')' : 'Videodateien für den Webserver lesbar', $ok(!$bad)]]);
    echo '<div class="card"><h2>Systemcheck</h2><table><tbody>';
    foreach ($checks as [$l, $b]) echo '<tr><td>' . h($l) . '</td><td class="r">' . $b . '</td></tr>';
    echo '</tbody></table></div></div>';
    ui_foot();
}

// ---------- Listen ----------
function list_toolbar(string $p, array $cats, bool $withMiss = true): void {
    echo '<form class="tools" method="get"><input type="hidden" name="p" value="' . h($p) . '">'
       . '<input type="search" name="q" value="' . h($_GET['q'] ?? '') . '" placeholder="Suchen">'
       . '<select name="cat"><option value="0">Alle Kategorien</option>' . opt_html(array_diff_key($cats, [0 => 1]), $_GET['cat'] ?? 0) . '</select>';
    if ($withMiss) echo '<select name="miss">' . opt_html(['' => 'Alle Einträge', 'cover' => 'Ohne Cover', 'plot' => 'Ohne Beschreibung'], $_GET['miss'] ?? '') . '</select>'
                      . '<select name="view">' . opt_html(['grid' => 'Posterwand', 'list' => 'Liste'], $_GET['view'] ?? 'grid') . '</select>'
                      . '<select name="sort">' . opt_html(['' => 'Nach Name', 'size' => 'Größte zuerst'], $_GET['sort'] ?? '') . '</select>';
    echo '<button class="sec">Filtern</button></form>';
}

function bulk_open(string $kind, array $cats, bool $tmdb): void {
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="bulk"><input type="hidden" name="kind" value="' . h($kind) . '"><input type="hidden" name="back" value="' . h(qs()) . '">'
       . '<div class="tools"><label class="check" style="margin:0"><input type="checkbox" data-selall> Alle auswählen</label>'
       . '<select name="category_id">' . opt_html($cats) . '</select><button class="sec sm" name="op" value="move">Kategorie setzen</button>'
       . ($tmdb ? '<button class="sec sm" name="op" value="tmdb">Infos von TMDb</button>' : '')
       . '<button class="bad sm" name="op" value="delete" data-confirm="Ausgewählte Einträge aus dem Panel löschen? Videodateien bleiben auf der Platte.">Löschen</button></div>';
}

function page_movies(): void {
    $pdo = db(); $q = trim((string)($_GET['q'] ?? '')); $cat = (int)($_GET['cat'] ?? 0); $miss = (string)($_GET['miss'] ?? '');
    $view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid'; $page = max(1, (int)($_GET['page'] ?? 1)); $per = $view === 'grid' ? 48 : 50;
    $where = ["s.type = 'movie'"]; $args = [];
    if ($q !== '') { $where[] = 's.name LIKE ?'; $args[] = "%$q%"; }
    if ($cat > 0) { $where[] = 's.category_id = ?'; $args[] = $cat; }
    if ($miss === 'cover') $where[] = "s.icon = ''";
    if ($miss === 'plot') $where[] = "s.plot = ''";
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM streams s WHERE $w"); $st->execute($args); $total = (int)$st->fetchColumn();
    $order = ($_GET['sort'] ?? '') === 'size' ? 's.size DESC, s.name COLLATE NOCASE' : 's.name COLLATE NOCASE';
    $st = $pdo->prepare("SELECT s.*, c.name AS cat FROM streams s LEFT JOIN categories c ON c.id = s.category_id WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args); $rows = $st->fetchAll();
    $cats = cat_options('movie');
    ui_head('Filme', 'movies');
    echo '<div class="row sp"><h1>Filme <span class="mute" style="font-size:15px;font-weight:400">' . $total . '</span></h1><div class="row">'
       . '<a class="btn sec" href="admin.php?p=movie_edit&id=0">Film per URL hinzufügen</a>' . post_btn('scan', [], 'Mediathek scannen', '') . '</div></div>';
    list_toolbar('movies', $cats);
    bulk_open('movie', $cats, true);
    if (!$rows) echo '<div class="card empty">Keine Filme gefunden. Lege Dateien in ' . h(setting('vod_dir')) . ' ab und scanne die Mediathek.</div>';
    elseif ($view === 'grid') {
        echo '<div class="wall">';
        foreach ($rows as $r) {
            $yr = substr((string)$r['releasedate'], 0, 4);
            echo '<div class="poster"><input class="sel" type="checkbox" name="ids[]" value="' . $r['id'] . '"><a class="go" href="admin.php?p=movie_edit&id=' . $r['id'] . '" aria-label="' . h($r['name']) . ' bearbeiten"></a>'
               . poster_html($r['icon'], $r['name']) . ($r['rating'] > 0 ? '<span class="rt">' . h($r['rating']) . '</span>' : '')
               . '<div class="t">' . h($r['name']) . '<small>' . h(trim($yr . ($yr && $r['cat'] ? ', ' : '') . $r['cat'])) . '</small>' . ($r['size'] > 0 ? '<small>' . human_bytes($r['size']) . '</small>' : '') . '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="card" style="padding:6px 8px"><table><thead><tr><th></th><th></th><th>Titel</th><th>Kategorie</th><th>Jahr</th><th>Bewertung</th><th>Laufzeit</th><th>Größe</th><th>Quelle</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><input type="checkbox" name="ids[]" value="' . $r['id'] . '"></td><td>' . ($r['icon'] !== '' ? '<img class="thumb" loading="lazy" src="' . h($r['icon']) . '" alt="">' : '<div class="thumb"></div>') . '</td>'
               . '<td><a href="admin.php?p=movie_edit&id=' . $r['id'] . '">' . h($r['name']) . '</a></td><td>' . h($r['cat']) . '</td><td>' . h(substr((string)$r['releasedate'], 0, 4)) . '</td><td>'
               . ($r['rating'] > 0 ? h($r['rating']) : '') . '</td><td>' . dur_label((int)$r['duration']) . '</td><td>' . ($r['size'] > 0 ? human_bytes($r['size']) : '') . '</td><td><span class="badge">' . (strncmp($r['source'], 'local:', 6) === 0 ? 'lokal' : 'URL') . '</span></td>'
               . '<td class="r"><a class="btn sec sm" href="admin.php?p=movie_edit&id=' . $r['id'] . '">Bearbeiten</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</form>' . pager($total, $page, $per);
    ui_foot();
}

function page_series(): void {
    $pdo = db(); $q = trim((string)($_GET['q'] ?? '')); $cat = (int)($_GET['cat'] ?? 0); $miss = (string)($_GET['miss'] ?? '');
    $view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid'; $page = max(1, (int)($_GET['page'] ?? 1)); $per = $view === 'grid' ? 48 : 50;
    $order = ($_GET['sort'] ?? '') === 'size' ? 'bytes DESC, s.name COLLATE NOCASE' : 's.name COLLATE NOCASE';
    $where = ['1 = 1']; $args = [];
    if ($q !== '') { $where[] = 's.name LIKE ?'; $args[] = "%$q%"; }
    if ($cat > 0) { $where[] = 's.category_id = ?'; $args[] = $cat; }
    if ($miss === 'cover') $where[] = "s.cover = ''";
    if ($miss === 'plot') $where[] = "s.plot = ''";
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM series s WHERE $w"); $st->execute($args); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT s.*, c.name AS cat, (SELECT COUNT(*) FROM episodes e WHERE e.series_id = s.id) AS eps, (SELECT COALESCE(SUM(e.size), 0) FROM episodes e WHERE e.series_id = s.id) AS bytes FROM series s LEFT JOIN categories c ON c.id = s.category_id WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args); $rows = $st->fetchAll();
    $cats = cat_options('series');
    ui_head('Serien', 'series');
    echo '<div class="row sp"><h1>Serien <span class="mute" style="font-size:15px;font-weight:400">' . $total . '</span></h1><div class="row">'
       . '<a class="btn sec" href="admin.php?p=series_edit&id=0">Serie anlegen</a>' . post_btn('scan', [], 'Mediathek scannen', '') . '</div></div>';
    list_toolbar('series', $cats);
    bulk_open('series', $cats, true);
    if (!$rows) echo '<div class="card empty">Keine Serien gefunden. Lege Ordner in ' . h(setting('series_dir')) . ' an und scanne die Mediathek.</div>';
    elseif ($view === 'grid') {
        echo '<div class="wall">';
        foreach ($rows as $r) {
            echo '<div class="poster"><input class="sel" type="checkbox" name="ids[]" value="' . $r['id'] . '"><a class="go" href="admin.php?p=series_edit&id=' . $r['id'] . '" aria-label="' . h($r['name']) . ' bearbeiten"></a>'
               . poster_html($r['cover'], $r['name']) . ($r['rating'] > 0 ? '<span class="rt">' . h($r['rating']) . '</span>' : '')
               . '<div class="t">' . h($r['name']) . '<small>' . $r['eps'] . ' Folgen' . ($r['bytes'] > 0 ? ', ' . human_bytes($r['bytes']) : '') . '</small></div></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="card" style="padding:6px 8px"><table><thead><tr><th></th><th></th><th>Titel</th><th>Kategorie</th><th>Folgen</th><th>Speicher</th><th>Bewertung</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><input type="checkbox" name="ids[]" value="' . $r['id'] . '"></td><td>' . ($r['cover'] !== '' ? '<img class="thumb" loading="lazy" src="' . h($r['cover']) . '" alt="">' : '<div class="thumb"></div>') . '</td>'
               . '<td><a href="admin.php?p=series_edit&id=' . $r['id'] . '">' . h($r['name']) . '</a></td><td>' . h($r['cat']) . '</td><td>' . $r['eps'] . '</td><td>' . ($r['bytes'] > 0 ? human_bytes($r['bytes']) : '') . '</td><td>' . ($r['rating'] > 0 ? h($r['rating']) : '') . '</td>'
               . '<td class="r"><a class="btn sec sm" href="admin.php?p=series_edit&id=' . $r['id'] . '">Bearbeiten</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</form>' . pager($total, $page, $per);
    ui_foot();
}

function page_live(): void {
    $pdo = db(); $q = trim((string)($_GET['q'] ?? '')); $cat = (int)($_GET['cat'] ?? 0); $page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
    $where = ["s.type = 'live'"]; $args = [];
    if ($q !== '') { $where[] = 's.name LIKE ?'; $args[] = "%$q%"; }
    if ($cat > 0) { $where[] = 's.category_id = ?'; $args[] = $cat; }
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM streams s WHERE $w"); $st->execute($args); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT s.*, c.name AS cat FROM streams s LEFT JOIN categories c ON c.id = s.category_id WHERE $w ORDER BY c.name, s.name COLLATE NOCASE LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args); $rows = $st->fetchAll();
    $cats = cat_options('live');
    ui_head('Live-TV', 'live');
    echo '<div class="row sp"><h1>Live-TV <span class="mute" style="font-size:15px;font-weight:400">' . $total . '</span></h1><a class="btn sec" href="admin.php?p=live_edit&id=0">Kanal hinzufügen</a></div>';
    list_toolbar('live', $cats, false);
    bulk_open('live', $cats, false);
    if (!$rows) echo '<div class="card empty">Noch keine Kanäle. Füge einen hinzu oder importiere unten eine M3U-Liste.</div>';
    else {
        echo '<div class="card" style="padding:6px 8px"><table><thead><tr><th></th><th></th><th>Kanal</th><th>Kategorie</th><th>EPG-ID</th><th>Quelle</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><input type="checkbox" name="ids[]" value="' . $r['id'] . '"></td><td>' . ($r['icon'] !== '' ? '<img class="thumb" style="height:38px;object-fit:contain" loading="lazy" src="' . h($r['icon']) . '" alt="">' : '<div class="thumb" style="height:38px"></div>') . '</td>'
               . '<td><a href="admin.php?p=live_edit&id=' . $r['id'] . '">' . h($r['name']) . '</a></td><td>' . h($r['cat']) . '</td><td>' . h($r['epg_id']) . '</td><td class="mute">' . h(parse_url($r['source'], PHP_URL_HOST)) . '</td>'
               . '<td class="r"><a class="btn sec sm" href="admin.php?p=live_edit&id=' . $r['id'] . '">Bearbeiten</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</form>' . pager($total, $page, $per);
    echo '<div class="card"><h2>M3U-Liste importieren</h2><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="live_import"><div class="cols">'
       . '<div>' . f_text('M3U-URL', 'm3u_url', '', ['ph' => 'https://...']) . f_text('Oder M3U-Datei hochladen', 'm3u', '', ['type' => 'file']) . '</div>'
       . '<div>' . f_area('Oder M3U-Text einfügen', 'm3u_text', '', ['rows' => 5]) . '</div></div>'
       . '<button>Importieren</button></form></div>';
    ui_foot();
}

// ---------- Bearbeiten ----------
function metadata_card(string $kind, array $m): string {
    return '<div class="card"><h2>Infos von TMDb holen</h2><p class="mute">Füllt Cover, Beschreibung, Bewertung, Besetzung und Trailer automatisch. Normalerweise werden nur leere Felder ergänzt.</p>'
         . '<div class="cols">' . f_text('Suchbegriff (leer = Titel)', 'tmdb_q', '', ['hint' => 'Ein Jahr im Suchbegriff, z. B. "Titel 1999", macht den Treffer genauer.'])
         . f_text('TMDb-ID (optional)', 'tmdb_id_in', !empty($m['tmdb_id']) ? $m['tmdb_id'] : '', ['hint' => 'Nur setzen, wenn der automatische Treffer falsch war.']) . '</div>'
         . f_check('Vorhandene Felder überschreiben', 'overwrite') . '<button class="sec" name="act" value="tmdb">Speichern und von TMDb laden</button></div>';
}

function page_movie_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $m = $id ? row('streams', $id) : ['id' => 0, 'type' => 'movie', 'name' => '', 'category_id' => 0, 'plot' => '', 'rating' => 0, 'releasedate' => '', 'genre' => '', 'actors' => '',
        'director' => '', 'duration' => 0, 'trailer' => '', 'icon' => '', 'backdrop' => '', 'container' => 'mp4', 'source' => '', 'tmdb_id' => 0];
    if ($id && (!$m || $m['type'] !== 'movie')) { flash('Film nicht gefunden.', 'bad'); go('p=movies'); }
    $local = strncmp($m['source'], 'local:', 6) === 0;
    ui_head($id ? 'Film bearbeiten' : 'Film hinzufügen', 'movies');
    echo '<div class="row sp"><h1>' . ($id ? h($m['name']) : 'Film per URL hinzufügen') . '</h1><a class="btn sec" href="admin.php?p=movies">Zur Filmliste</a></div>';
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="movie_save"><input type="hidden" name="id" value="' . $id . '"><button type="submit" name="act" value="save" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0"></button>';
    echo '<div class="edit"><div class="card"><img id="pv" class="preview" alt="Cover" src="' . h($m['icon'] !== '' ? $m['icon'] : 'data:image/gif;base64,R0lGODlhAQABAAAAACw=') . '" style="margin-bottom:14px">'
       . f_text('Cover-URL', 'icon', $m['icon'], ['attr' => 'data-preview="pv"', 'ph' => 'https://... oder leer'])
       . f_text('Oder Cover hochladen', 'cover_file', '', ['type' => 'file', 'attr' => 'accept="image/*"'])
       . f_text('Hintergrundbild-URL', 'backdrop', $m['backdrop'])
       . ($local ? '<button class="sec sm" name="act" value="frame">Bild aus dem Video erzeugen</button>' : '') . '</div>';
    echo '<div class="card"><div class="cols">' . f_text('Titel', 'name', $m['name'], ['attr' => 'required'])
       . f_select('Kategorie', 'category_id', cat_options('movie'), $m['category_id']) . '</div><div class="cols3">'
       . f_text('Erscheinungsdatum', 'releasedate', $m['releasedate'], ['ph' => '2019-05-24'])
       . f_text('Bewertung (0 bis 10)', 'rating', $m['rating'] ?: '') . f_text('Laufzeit in Minuten', 'duration_min', $m['duration'] ? round($m['duration'] / 60) : '', ['type' => 'number'])
       . '</div><div class="cols">' . f_text('Genre', 'genre', $m['genre'], ['ph' => 'Action, Drama']) . f_text('Regie', 'director', $m['director']) . '</div>'
       . f_text('Besetzung', 'actors', $m['actors']) . f_area('Beschreibung', 'plot', $m['plot'], ['rows' => 6])
       . f_text('Trailer (YouTube-ID oder Link)', 'trailer', $m['trailer']);
    if ($local) echo '<div class="field"><label>Quelle</label><code>' . h(substr($m['source'], 6)) . '</code>' . (!empty($m['size']) ? ' <span class="mute">' . human_bytes($m['size']) . '</span>' : '') . '</div>';
    else echo f_text('Quell-URL', 'source', $m['source'], ['attr' => $id ? '' : 'required', 'ph' => 'https://.../film.mkv']);
    echo f_text('Dateiendung für den Player', 'container', $m['container'], ['hint' => 'z. B. mkv oder mp4']);
    echo '<div class="row"><button name="act" value="save">Speichern</button>' . ($id ? '<button class="bad" name="act" value="delete" data-confirm="Diesen Film aus dem Panel löschen? Die Videodatei bleibt auf der Platte.">Löschen</button>' : '') . '</div></div></div>';
    if ($id) echo metadata_card('movie', $m);
    echo '</form>';
    ui_foot();
}

function page_series_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $s = $id ? row('series', $id) : ['id' => 0, 'name' => '', 'category_id' => 0, 'plot' => '', 'rating' => 0, 'releasedate' => '', 'genre' => '', 'actors' => '', 'director' => '',
        'episode_run_time' => 0, 'trailer' => '', 'cover' => '', 'backdrop' => '', 'tmdb_id' => 0];
    if ($id && !$s) { flash('Serie nicht gefunden.', 'bad'); go('p=series'); }
    $sizeHtml = '';
    if ($id) {
        $st = db()->prepare('SELECT season, COUNT(*) AS n, COALESCE(SUM(size), 0) AS b FROM episodes WHERE series_id = ? GROUP BY season ORDER BY season');
        $st->execute([$id]);
        $seasons = $st->fetchAll(); $tn = 0; $tb = 0;
        foreach ($seasons as $x) { $tn += $x['n']; $tb += $x['b']; }
        if ($tn) {
            $sizeHtml = '<div class="field" style="margin-top:14px"><label>Speicherplatz</label><b style="font-size:20px">' . ($tb > 0 ? human_bytes($tb) : 'unbekannt') . '</b> <span class="mute">in ' . $tn . ' Folgen</span>';
            if ($tb == 0) $sizeHtml .= '<div style="margin-top:8px"><button class="sec sm" name="act" value="sizes">Größen jetzt berechnen</button></div>';
            if ($tb > 0 && count($seasons) > 1) { $sizeHtml .= '<table style="margin-top:8px"><tbody>'; foreach ($seasons as $x) $sizeHtml .= '<tr><td>Staffel ' . h($x['season']) . '</td><td class="mute">' . $x['n'] . ' Fo.</td><td class="r">' . human_bytes($x['b']) . '</td></tr>'; $sizeHtml .= '</tbody></table>'; }
            $sizeHtml .= '</div>';
        }
    }
    ui_head($id ? 'Serie bearbeiten' : 'Serie anlegen', 'series');
    echo '<div class="row sp"><h1>' . ($id ? h($s['name']) : 'Serie anlegen') . '</h1><a class="btn sec" href="admin.php?p=series">Zur Serienliste</a></div>';
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="series_save"><input type="hidden" name="id" value="' . $id . '"><button type="submit" name="act" value="save" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0"></button>';
    echo '<div class="edit"><div class="card"><img id="pv" class="preview" alt="Cover" src="' . h($s['cover'] !== '' ? $s['cover'] : 'data:image/gif;base64,R0lGODlhAQABAAAAACw=') . '" style="margin-bottom:14px">'
       . f_text('Cover-URL', 'cover', $s['cover'], ['attr' => 'data-preview="pv"', 'ph' => 'https://... oder leer'])
       . f_text('Oder Cover hochladen', 'cover_file', '', ['type' => 'file', 'attr' => 'accept="image/*"'])
       . f_text('Hintergrundbild-URL', 'backdrop', $s['backdrop']) . $sizeHtml . '</div>';
    echo '<div class="card"><div class="cols">' . f_text('Titel', 'name', $s['name'], ['attr' => 'required']) . f_select('Kategorie', 'category_id', cat_options('series'), $s['category_id']) . '</div><div class="cols3">'
       . f_text('Erstausstrahlung', 'releasedate', $s['releasedate'], ['ph' => '2016-07-15']) . f_text('Bewertung (0 bis 10)', 'rating', $s['rating'] ?: '')
       . f_text('Minuten pro Folge', 'run_min', $s['episode_run_time'] ?: '', ['type' => 'number']) . '</div><div class="cols">'
       . f_text('Genre', 'genre', $s['genre']) . f_text('Idee / Regie', 'director', $s['director']) . '</div>'
       . f_text('Besetzung', 'actors', $s['actors']) . f_area('Beschreibung', 'plot', $s['plot'], ['rows' => 5]) . f_text('Trailer (YouTube-ID oder Link)', 'trailer', $s['trailer'])
       . '<div class="row"><button name="act" value="save">Speichern</button>' . ($id ? '<button class="bad" name="act" value="delete" data-confirm="Serie samt Folgen aus dem Panel löschen? Die Videodateien bleiben auf der Platte.">Löschen</button>' : '') . '</div></div></div>';
    if ($id) echo metadata_card('series', $s);

    echo '<div class="card"><div class="row sp"><h2>Folgen</h2>' . ($id ? '<button class="sec sm" name="act" value="tmdb_eps">Folgen-Infos von TMDb laden</button>' : '') . '</div>'
       . '<table><thead><tr><th>Staffel</th><th>Folge</th><th>Titel</th><th>Beschreibung</th><th>Bild-URL</th><th>Quelle</th><th>Größe</th><th>Löschen</th></tr></thead><tbody>';
    if ($id) {
        $st = db()->prepare('SELECT * FROM episodes WHERE series_id = ? ORDER BY season, episode_num');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $e) {
            $i = $e['id']; $loc = strncmp($e['source'], 'local:', 6) === 0;
            echo '<tr><td><input type="number" style="width:66px" name="ep[' . $i . '][season]" value="' . h($e['season']) . '"></td><td><input type="number" style="width:66px" name="ep[' . $i . '][num]" value="' . h($e['episode_num']) . '"></td>'
               . '<td><input style="width:100%;min-width:150px" name="ep[' . $i . '][title]" value="' . h($e['title']) . '"></td><td><input style="width:100%;min-width:180px" name="ep[' . $i . '][plot]" value="' . h($e['plot']) . '"></td>'
               . '<td><input style="width:100%;min-width:120px" name="ep[' . $i . '][cover]" value="' . h($e['cover']) . '"></td>'
               . '<td>' . ($loc ? '<span class="mute" title="' . h(substr($e['source'], 6)) . '">lokal: ' . h(basename($e['source'])) . '</span>' : '<input style="width:100%;min-width:140px" name="ep[' . $i . '][source]" value="' . h($e['source']) . '">') . '</td>'
               . '<td class="mute" style="white-space:nowrap">' . ($e['size'] > 0 ? human_bytes($e['size']) : '') . '</td>'
               . '<td><input type="checkbox" name="epdel[' . $i . ']" value="1"></td></tr>';
        }
    }
    for ($n = 0; $n < 2; $n++) {
        echo '<tr><td><input type="number" style="width:66px" name="nep[' . $n . '][season]" value="1"></td><td><input type="number" style="width:66px" name="nep[' . $n . '][num]" value=""></td>'
           . '<td><input style="width:100%" name="nep[' . $n . '][title]" placeholder="Neue Folge, Titel"></td><td></td><td></td><td colspan="3"><input style="width:100%" name="nep[' . $n . '][source]" placeholder="Quell-URL der neuen Folge"></td></tr>';
    }
    echo '</tbody></table><p class="mute" style="margin-bottom:0">Neue Folgen zählen nur mit Quell-URL. Lokale Dateien nimmt der Scan automatisch auf. Änderungen speicherst du mit "Speichern" oben.</p></div></form>';
    ui_foot();
}

function page_live_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $c = $id ? row('streams', $id) : ['id' => 0, 'type' => 'live', 'name' => '', 'category_id' => 0, 'source' => '', 'icon' => '', 'epg_id' => ''];
    if ($id && (!$c || $c['type'] !== 'live')) { flash('Kanal nicht gefunden.', 'bad'); go('p=live'); }
    ui_head($id ? 'Kanal bearbeiten' : 'Kanal hinzufügen', 'live');
    echo '<div class="row sp"><h1>' . ($id ? h($c['name']) : 'Kanal hinzufügen') . '</h1><a class="btn sec" href="admin.php?p=live">Zur Kanalliste</a></div>';
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="live_save"><input type="hidden" name="id" value="' . $id . '"><div class="card" style="max-width:720px">'
       . f_text('Name', 'name', $c['name'], ['attr' => 'required']) . f_select('Kategorie', 'category_id', cat_options('live'), $c['category_id'])
       . f_text('Stream-URL', 'source', $c['source'], ['attr' => 'required', 'ph' => 'https://...']) . f_text('EPG-ID (tvg-id)', 'epg_id', $c['epg_id'])
       . f_text('Logo-URL', 'icon', $c['icon']) . f_text('Oder Logo hochladen', 'cover_file', '', ['type' => 'file', 'attr' => 'accept="image/*"'])
       . '<div class="row"><button name="act" value="save">Speichern</button>' . ($id ? '<button class="bad" name="act" value="delete" data-confirm="Kanal löschen?">Löschen</button>' : '') . '</div></div></form>';
    ui_foot();
}

// ---------- Kategorien ----------
function page_cats(): void {
    ui_head('Kategorien', 'cats');
    echo '<h1>Kategorien</h1><p class="mute">Die Reihenfolge bestimmt, wie die Kategorien im Player erscheinen (kleine Zahl zuerst). Beim Löschen wandern die Einträge nach "Unkategorisiert".</p><div class="cols3">';
    foreach (['movie' => 'Filme', 'series' => 'Serien', 'live' => 'Live-TV'] as $type => $label) {
        $table = $type === 'series' ? 'series' : 'streams';
        $st = db()->prepare("SELECT c.*, (SELECT COUNT(*) FROM $table t WHERE t.category_id = c.id" . ($type === 'series' ? '' : " AND t.type = '$type'") . ') AS n FROM categories c WHERE c.type = ? ORDER BY c.sort, c.name COLLATE NOCASE');
        $st->execute([$type]);
        echo '<div class="card"><h2>' . h($label) . '</h2><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="cats_save"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="back" value="p=cats">';
        $rows = $st->fetchAll();
        if ($rows) {
            echo '<table><thead><tr><th>Name</th><th>Pos.</th><th>Einträge</th><th>Löschen</th></tr></thead><tbody>';
            foreach ($rows as $c) echo '<tr><td><input style="width:100%" name="n[' . $c['id'] . ']" value="' . h($c['name']) . '"></td><td><input type="number" style="width:60px" name="s[' . $c['id'] . ']" value="' . (int)$c['sort'] . '"></td><td class="mute">' . $c['n'] . '</td><td><input type="checkbox" name="d[' . $c['id'] . ']" value="1"></td></tr>';
            echo '</tbody></table>';
        } else echo '<p class="mute">Noch keine Kategorien.</p>';
        echo '<div class="field" style="margin-top:14px"><label>Neue Kategorie</label><input name="new_name" placeholder="Name"></div><button>Speichern</button></form></div>';
    }
    echo '</div>';
    ui_foot();
}

// ---------- Benutzer ----------
function page_users(): void {
    $pdo = db();
    $users = $pdo->query('SELECT u.*, (SELECT COUNT(DISTINCT se.ip || se.kind || se.item_id) FROM sessions se WHERE se.user_id = u.id AND se.last_seen > ' . (time() - 30) . ') AS live FROM users u ORDER BY u.id DESC')->fetchAll();
    ui_head('Benutzer', 'users');
    echo '<h1>Benutzer</h1><div class="card"><h2>Neuer Zugang</h2><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="user_create"><div class="row" style="align-items:flex-end">'
       . '<div class="field"><label>Benutzername</label><input name="username" placeholder="leer = zufällig"></div>'
       . '<div class="field"><label>Passwort</label><input name="password" placeholder="leer = zufällig"></div>'
       . '<div class="field"><label>Laufzeit (Tage)</label><input type="number" name="days" value="30" style="width:100px" title="0 = unbegrenzt"></div>'
       . '<div class="field"><label>Verbindungen</label><input type="number" name="max" value="1" min="1" style="width:100px"></div>'
       . '<div class="field"><label>Anzahl</label><input type="number" name="count" value="1" min="1" max="100" style="width:80px" title="Mehrere Zugänge mit Zufallsdaten auf einmal"></div>'
       . '<div class="field"><label>Notiz</label><input name="note" placeholder="z. B. Name"></div>'
       . '<div class="field"><button>Anlegen</button></div></div>' . f_check('Testzugang', 'trial') . '<p class="mute" style="margin:0">Laufzeit 0 bedeutet unbegrenzt. Bei Anzahl über 1 werden Benutzername und Passwort zufällig vergeben.</p></form></div>';
    echo '<div class="card" style="padding:6px 8px">';
    if (!$users) echo '<div class="empty">Noch kein Benutzer angelegt.</div>';
    else {
        echo '<table><thead><tr><th>Benutzername</th><th>Passwort</th><th>Status</th><th>Läuft ab</th><th>Verb.</th><th>Notiz</th><th></th></tr></thead><tbody>';
        foreach ($users as $u) {
            $st = !$u['enabled'] ? '<span class="badge bad">gesperrt</span>' : (user_active($u) ? '<span class="badge ok">aktiv</span>' : '<span class="badge warn">abgelaufen</span>');
            echo '<tr><td><a href="admin.php?p=user_edit&id=' . $u['id'] . '"><code>' . h($u['username']) . '</code></a>' . ($u['is_trial'] ? ' <span class="badge">Test</span>' : '') . '</td><td><code>' . h($u['password']) . '</code></td><td>' . $st
               . ($u['live'] ? ' <span class="badge ok">' . $u['live'] . ' schaut</span>' : '') . '</td><td>' . ($u['exp_date'] ? date('d.m.Y', $u['exp_date']) : 'nie') . '</td><td>' . $u['max_connections'] . '</td><td class="mute">' . h($u['note']) . '</td>'
               . '<td class="r"><div class="row" style="justify-content:flex-end;gap:6px"><a class="btn sec sm" href="admin.php?p=user_edit&id=' . $u['id'] . '">Bearbeiten</a>'
               . ($u['exp_date'] ? post_btn('user_extend', ['id' => $u['id'], 'days' => 30], '+30 Tage') : '')
               . post_btn('user_toggle', ['id' => $u['id']], $u['enabled'] ? 'Sperren' : 'Entsperren')
               . post_btn('user_delete', ['id' => $u['id']], 'Löschen', 'bad sm', 'Benutzer "' . $u['username'] . '" löschen?') . '</div></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    ui_foot();
}

function copy_line(string $id, string $label, string $val): string {
    return '<div class="field"><label>' . h($label) . '</label><div class="row" style="flex-wrap:nowrap"><input id="' . h($id) . '" readonly value="' . h($val) . '" style="flex:1"><button type="button" class="sec" data-copy="' . h($id) . '">Kopieren</button></div></div>';
}

function page_user_edit(): void {
    $u = row('users', (int)($_GET['id'] ?? 0));
    if (!$u) { flash('Benutzer nicht gefunden.', 'bad'); go('p=users'); }
    $b = base_url()[3]; $q = 'username=' . rawurlencode($u['username']) . '&password=' . rawurlencode($u['password']);
    ui_head('Benutzer bearbeiten', 'users');
    echo '<div class="row sp"><h1>' . h($u['username']) . '</h1><a class="btn sec" href="admin.php?p=users">Zur Benutzerliste</a></div><div class="cols"><div class="card">'
       . '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="user_save"><input type="hidden" name="id" value="' . $u['id'] . '">'
       . f_text('Benutzername', 'username', $u['username'], ['attr' => 'required']) . f_text('Passwort', 'password', $u['password'], ['attr' => 'required'])
       . f_text('Läuft ab am', 'exp', $u['exp_date'] ? date('Y-m-d', $u['exp_date']) : '', ['type' => 'date', 'hint' => 'Leer lassen für unbegrenzt.'])
       . f_text('Gleichzeitige Verbindungen', 'max', $u['max_connections'], ['type' => 'number', 'attr' => 'min="1"', 'hint' => 'Wird nur durchgesetzt, wenn es unter Einstellungen aktiviert ist.'])
       . f_text('Notiz', 'note', $u['note']) . f_check('Zugang aktiv', 'enabled', (bool)$u['enabled']) . f_check('Testzugang', 'trial', (bool)$u['is_trial'])
       . '<button>Speichern</button></form></div><div class="card"><h2>Zugangsdaten für den Player</h2>'
       . copy_line('c1', 'Server-URL', $b) . copy_line('c2', 'Benutzername', $u['username']) . copy_line('c3', 'Passwort', $u['password'])
       . copy_line('c4', 'M3U-Link (Live, Filme, Serien)', $b . '/get.php?' . $q . '&type=m3u_plus&output=ts') . copy_line('c5', 'API-Link', $b . '/player_api.php?' . $q) . '</div></div>';
    ui_foot();
}

// ---------- Downloads ----------
function dl_table_html(): string {
    $jobs = dl_jobs();
    if (array_filter($jobs, fn($j) => !empty($j['new']))) scan_library();   // fertige Downloads aufnehmen
    if (!$jobs) return '<div class="empty">Noch keine Downloads.</div>';
    $running = false;
    $o = '<table><thead><tr><th>Datei</th><th>Status</th><th>Größe</th><th></th></tr></thead><tbody>';
    foreach ($jobs as $j) {
        $url = (string)($j['url'] ?? '');
        $url = strlen($url) > 80 ? substr($url, 0, 80) . '...' : $url;
        if ($j['status'] === 'ok') $label = '<span class="badge ok">fertig</span>';
        elseif ($j['status'] === 'fail') { $e = strlen($j['err']) > 120 ? substr($j['err'], 0, 120) . '...' : $j['err']; $label = '<span class="badge bad">Fehler</span> <span class="mute">' . h($e) . '</span>'; }
        else { $label = '<span class="badge warn">läuft</span>'; $running = true; }
        $o .= '<tr><td>' . h($j['name'] ?? '?') . '<br><small class="mute">' . h($url) . '</small></td><td>' . $label . '</td><td>' . human_bytes($j['size']) . '</td><td class="r">'
            . ($j['status'] === 'running' ? post_btn('dl_cancel', ['job' => $j['id']], 'Abbrechen', 'bad sm') : '') . '</td></tr>';
    }
    return $o . '</tbody></table>' . ($running ? '<span data-running></span>' : '');
}

function page_dl_table(): void { echo dl_table_html(); exit; }

function page_downloads(): void {
    ui_head('Downloads', 'downloads');
    echo '<h1>Downloads</h1><div class="card"><h2>Video per URL laden</h2><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="dl_add"><input type="hidden" name="back" value="p=downloads">'
       . '<div class="cols">' . f_select('Ziel', 'type', ['movie' => 'Film (' . setting('vod_dir') . ')', 'series' => 'Serie (' . setting('series_dir') . ')'], 'movie')
       . f_text('Unterordner', 'sub', '', ['hint' => 'Film: Kategorie (optional). Serie: Name der Serie (Pflicht).']) . '</div><div class="cols">'
       . f_text('Video-URL', 'url', '', ['ph' => 'https://.../datei.mkv', 'attr' => 'required']) . f_text('Dateiname', 'fname', '', ['hint' => 'Leer = Name aus der URL. Bei Serien am besten mit S01E01 im Namen.']) . '</div>'
       . '<button>Herunterladen</button></form></div>';
    $dl = dl_table_html();
    echo '<div class="card"><div class="row sp"><h2>Verlauf</h2>' . post_btn('dl_clear', [], 'Erledigte entfernen') . '</div><div id="dl-list"' . (strpos($dl, 'data-running') !== false ? ' data-running="1"' : '') . '>' . $dl . '</div>'
       . '<p class="mute" style="margin-bottom:0">Fertige Downloads werden automatisch in die Mediathek aufgenommen.</p></div>';
    echo '<script>var box=document.getElementById("dl-list");setInterval(function(){if(!box.dataset.running)return;fetch("admin.php?p=dl_table",{credentials:"same-origin"}).then(function(r){return r.text();}).then(function(t){box.innerHTML=t;box.dataset.running=box.querySelector("[data-running]")?"1":"";});},4000);</script>';
    ui_foot();
}

// ---------- Einstellungen ----------
function page_settings(): void {
    $vod = setting('vod_dir'); $ser = setting('series_dir');
    $dirBadge = fn($d) => !is_dir($d) ? '<span class="badge bad">nicht gefunden</span>' : (is_writable($d) ? '<span class="badge ok">lesen und schreiben</span>' : '<span class="badge warn">nur lesen (Downloads gehen nicht)</span>');
    $tz = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
    ui_head('Einstellungen', 'settings');
    echo '<h1>Einstellungen</h1><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="settings_save"><div class="cols"><div>'
       . '<div class="card"><h2>Allgemein</h2>' . f_text('Servername', 'server_name', setting('server_name'))
       . f_text('Öffentliche Adresse (optional)', 'base_url', setting('base_url'), ['ph' => 'https://tv.example.org', 'hint' => 'Nur nötig, wenn der Server hinter einem Proxy oder unter anderer Adresse erreichbar ist. Sonst leer lassen.'])
       . f_select('Zeitzone', 'timezone', $tz, setting('timezone')) . f_check('Verbindungslimit pro Benutzer durchsetzen', 'enforce_cons', setting('enforce_cons') === '1') . '</div>'
       . '<div class="card"><h2>Ordner mit Videos</h2>' . f_text('Filme', 'vod_dir', $vod) . '<p style="margin-top:-6px">' . $dirBadge($vod) . '</p>'
       . f_text('Serien', 'series_dir', $ser) . '<p style="margin-top:-6px">' . $dirBadge($ser) . '</p></div></div><div>'
       . '<div class="card"><h2>TMDb (Cover und Beschreibungen)</h2><p class="mute">Kostenloser Schlüssel von themoviedb.org unter Einstellungen, API. Der lange "API Read Access Token" geht auch.</p>'
       . f_text('TMDb-Schlüssel', 'tmdb_key', setting('tmdb_key'), ['attr' => 'autocomplete="off"'])
       . f_select('Sprache der Infos', 'tmdb_lang', ['de-DE' => 'Deutsch', 'en-US' => 'Englisch', 'fr-FR' => 'Französisch', 'es-ES' => 'Spanisch', 'it-IT' => 'Italienisch', 'tr-TR' => 'Türkisch', 'pl-PL' => 'Polnisch', 'ru-RU' => 'Russisch'], setting('tmdb_lang')) . '</div>'
       . '<div class="card"><h2>Admin-Passwort ändern</h2>' . f_text('Aktuelles Passwort', 'cur_pw', '', ['type' => 'password', 'attr' => 'autocomplete="off"'])
       . f_text('Neues Passwort', 'new_pw', '', ['type' => 'password', 'attr' => 'autocomplete="new-password"', 'hint' => 'Leer lassen, um es nicht zu ändern.']) . '</div></div></div>'
       . '<button>Einstellungen speichern</button></form><div style="height:22px"></div><div class="cols">';

    $epg = is_file(DATA_DIR . '/epg.xml') ? human_bytes(filesize(DATA_DIR . '/epg.xml')) : '';
    echo '<div class="card"><h2>Programmführer (EPG)</h2>' . ($epg ? '<p>Aktuell gespeichert: ' . h($epg) . ' (xmltv.php liefert diese Datei an die Player)</p>' : '<p class="mute">Noch keine EPG-Datei hinterlegt.</p>')
       . '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="epg_upload">'
       . f_text('XMLTV-Datei hochladen', 'epg', '', ['type' => 'file']) . f_text('Oder von URL laden', 'epg_url', '', ['ph' => 'https://.../epg.xml'])
       . '<div class="row"><button>Speichern</button>' . ($epg ? post_btn('epg_remove', [], 'EPG entfernen', 'bad') : '') . '</div></form></div>';
    echo '<div class="card"><h2>Sicherung und Wartung</h2><p class="mute">Die Sicherung enthält Benutzer, Metadaten und Einstellungen als eine Datei. Hochgeladene Cover liegen im Ordner covers/.</p><div class="row">'
       . post_btn('backup', [], 'Datenbank herunterladen', 'sec') . post_btn('scan', [], 'Mediathek scannen', 'sec') . post_btn('sizes', [], 'Größen neu berechnen', 'sec') . post_btn('cleanup', [], 'Fehlende Dateien entfernen', 'sec', 'Einträge löschen, deren Datei nicht mehr existiert?')
       . '</div></div></div>';
    ui_foot();
}