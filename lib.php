<?php
// Xtream-Panel: gemeinsame Funktionen (Datenbank, Einstellungen, Mediathek, TMDb, Downloads)
const APP_VERSION = '1.0';
const MEDIA_EXT = ['mkv', 'mp4', 'avi', 'mov', 'm4v', 'ts', 'webm'];
define('DATA_DIR', __DIR__ . '/data');
define('COVER_DIR', __DIR__ . '/covers');

const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password TEXT NOT NULL,
    exp_date INTEGER, max_connections INTEGER DEFAULT 1, enabled INTEGER DEFAULT 1,
    is_trial INTEGER DEFAULT 0, note TEXT DEFAULT '', created_at INTEGER);
CREATE TABLE IF NOT EXISTS categories(
    id INTEGER PRIMARY KEY, type TEXT NOT NULL, name TEXT NOT NULL, sort INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS streams(
    id INTEGER PRIMARY KEY, type TEXT NOT NULL, category_id INTEGER DEFAULT 0, name TEXT NOT NULL,
    source TEXT NOT NULL, icon TEXT DEFAULT '', backdrop TEXT DEFAULT '', epg_id TEXT DEFAULT '',
    container TEXT DEFAULT 'mp4', rating REAL DEFAULT 0, plot TEXT DEFAULT '', genre TEXT DEFAULT '',
    actors TEXT DEFAULT '', director TEXT DEFAULT '', releasedate TEXT DEFAULT '', duration INTEGER DEFAULT 0,
    trailer TEXT DEFAULT '', tmdb_id INTEGER DEFAULT 0, meta_tried INTEGER DEFAULT 0, size INTEGER DEFAULT 0, added INTEGER);
CREATE INDEX IF NOT EXISTS idx_streams_type ON streams(type, category_id);
CREATE TABLE IF NOT EXISTS series(
    id INTEGER PRIMARY KEY, category_id INTEGER DEFAULT 0, name TEXT NOT NULL, cover TEXT DEFAULT '',
    backdrop TEXT DEFAULT '', plot TEXT DEFAULT '', genre TEXT DEFAULT '', actors TEXT DEFAULT '',
    director TEXT DEFAULT '', releasedate TEXT DEFAULT '', rating REAL DEFAULT 0, trailer TEXT DEFAULT '',
    episode_run_time INTEGER DEFAULT 0, tmdb_id INTEGER DEFAULT 0, meta_tried INTEGER DEFAULT 0, added INTEGER);
CREATE TABLE IF NOT EXISTS episodes(
    id INTEGER PRIMARY KEY, series_id INTEGER NOT NULL REFERENCES series(id) ON DELETE CASCADE,
    season INTEGER DEFAULT 1, episode_num INTEGER DEFAULT 1, title TEXT DEFAULT '', plot TEXT DEFAULT '',
    cover TEXT DEFAULT '', source TEXT NOT NULL, container TEXT DEFAULT 'mp4', duration INTEGER DEFAULT 0,
    releasedate TEXT DEFAULT '', rating REAL DEFAULT 0, size INTEGER DEFAULT 0, added INTEGER);
CREATE INDEX IF NOT EXISTS idx_episodes_series ON episodes(series_id, season, episode_num);
CREATE TABLE IF NOT EXISTS sessions(
    id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT, item_id INTEGER, ip TEXT, started INTEGER, last_seen INTEGER);
CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
SQL;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function fatal_page(string $msg): never {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#0e1218;color:#e8ebf1;padding:40px;max-width:640px"><h2>Fehler</h2><p>' . h($msg) . '</p>';
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
        $p = new PDO('sqlite:' . DATA_DIR . '/panel.sqlite');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $p->exec('PRAGMA busy_timeout = 5000');
        $p->exec('PRAGMA foreign_keys = ON');
        @$p->exec('PRAGMA journal_mode = WAL');
        $p->exec(SCHEMA);
        foreach (['streams', 'episodes'] as $t) {   // Migration: Spalte size bei älteren Datenbanken ergänzen
            $has = false;
            foreach ($p->query("PRAGMA table_info($t)")->fetchAll() as $c) if ($c['name'] === 'size') $has = true;
            if (!$has) $p->exec("ALTER TABLE $t ADD COLUMN size INTEGER DEFAULT 0");
        }
        $pdo = $p;
    } catch (Throwable $e) {
        fatal_page('Datenbank nicht nutzbar: ' . $e->getMessage() . ' (Der Ordner data/ muss für den Webserver beschreibbar sein: chown -R www-data:www-data ' . DATA_DIR . ')');
    }
    return $pdo;
}

// ---------- Einstellungen ----------
function settings_all(bool $reload = false): array {
    static $c = null;
    if ($c === null || $reload) {
        $c = ['server_name' => 'Xtream Server', 'base_url' => '', 'timezone' => 'Europe/Berlin',
              'vod_dir' => '/opt/vod', 'series_dir' => '/opt/series', 'tmdb_key' => '', 'tmdb_lang' => 'de-DE',
              'enforce_cons' => '0', 'admin_hash' => ''];
        foreach (db()->query('SELECT k, v FROM settings') as $r) $c[$r['k']] = $r['v'];
    }
    return $c;
}
function setting(string $k): string { return (string)(settings_all()[$k] ?? ''); }
function set_setting(string $k, string $v): void {
    db()->prepare('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
    settings_all(true);
}

$__tz = setting('timezone');
date_default_timezone_set(in_array($__tz, DateTimeZone::listIdentifiers(), true) ? $__tz : 'UTC');

// ---------- Hilfsfunktionen ----------
function json_out($data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function base_url(): array {   // [proto, host, port, url]
    $ov = trim(setting('base_url'));
    if ($ov !== '' && ($pu = parse_url($ov)) && !empty($pu['host'])) {
        $proto = $pu['scheme'] ?? 'http';
        $port = (int)($pu['port'] ?? ($proto === 'https' ? 443 : 80));
        return [$proto, $pu['host'], $port, rtrim($ov, '/')];
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $port = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $proto = $https ? 'https' : 'http';
    $show = (($https && $port === 443) || (!$https && $port === 80)) ? '' : ":$port";
    return [$proto, $host, $port, "$proto://$host$show$dir"];
}

function abs_img(string $v): string {
    if ($v === '') return '';
    if (preg_match('#^https?://#i', $v)) return $v;
    return base_url()[3] . '/' . ltrim($v, '/');
}

function fmt_dur(int $s): string { return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); }
function human_bytes($b): string {
    $b = (float)$b;
    if ($b >= 1099511627776) return round($b / 1099511627776, 2) . ' TB';
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    return round($b / 1024) . ' KB';
}
function rand_str(int $n): string { return substr(bin2hex(random_bytes((int)ceil($n / 2))), 0, $n); }

// ---------- Benutzer / Auth ----------
function auth_user(?string $u, ?string $p): ?array {
    if (!$u || !$p) return null;
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$u]);
    $user = $st->fetch();
    if (!$user || !hash_equals((string)$user['password'], $p)) return null;
    return $user;
}
function user_active(array $u): bool {
    if (!$u['enabled']) return false;
    return !$u['exp_date'] || $u['exp_date'] > time();
}

// ---------- Programme prüfen ----------
function exec_ok(): bool {
    static $ok = null;
    if ($ok === null) {
        $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $ok = function_exists('exec') && !in_array('exec', $dis, true);
    }
    return $ok;
}
function have_bin(string $b): bool {
    static $c = [];
    if (!isset($c[$b])) {
        $c[$b] = false;
        if (exec_ok()) { exec('command -v ' . escapeshellarg($b) . ' 2>/dev/null', $o, $rc); $c[$b] = ($rc === 0); }
    }
    return $c[$b];
}

// ---------- Lokale Dateien ----------
function dir_for(string $root): string { return $root === 'vod' ? setting('vod_dir') : ($root === 'series' ? setting('series_dir') : ''); }

function local_path(string $src): ?string {
    if (strncmp($src, 'local:', 6) !== 0) return null;
    [$root, $sub] = array_pad(explode('/', substr($src, 6), 2), 2, '');
    $dir = dir_for($root);
    $base = $dir !== '' ? realpath($dir) : false;
    $path = ($base && $sub !== '') ? realpath($base . '/' . $sub) : false;
    if (!$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) return null;
    return $path;
}

function serve_file(string $path, array $user, string $kind, int $item): never {
    $mimes = ['mkv' => 'video/x-matroska', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'avi' => 'video/x-msvideo',
              'mov' => 'video/quicktime', 'ts' => 'video/mp2t', 'webm' => 'video/webm'];
    if (!is_readable($path)) {
        http_response_code(500);
        exit('Die Datei ist fuer den Webserver nicht lesbar. Auf dem Server ausfuehren: chmod -R a+rX ' . setting('vod_dir') . ' ' . setting('series_dir'));
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $size = filesize($path);
    $start = 0; $end = $size - 1; $code = 200;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        $code = 206;
    }
    $head = (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
    $sid = 0; $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!$head) { try {
        $pdo = db();
        $pdo->exec('DELETE FROM sessions WHERE last_seen < ' . (time() - 120));
        if (setting('enforce_cons') === '1') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM (SELECT DISTINCT ip, kind, item_id FROM sessions WHERE user_id = ? AND last_seen > ? AND NOT (ip = ? AND kind = ? AND item_id = ?))');
            $st->execute([$user['id'], time() - 30, $ip, $kind, $item]);
            if ((int)$st->fetchColumn() >= max(1, (int)$user['max_connections'])) { http_response_code(403); exit('Verbindungslimit erreicht'); }
        }
        $pdo->prepare('INSERT INTO sessions(user_id, kind, item_id, ip, started, last_seen) VALUES(?,?,?,?,?,?)')
            ->execute([$user['id'], $kind, $item, $ip, time(), time()]);
        $sid = (int)$pdo->lastInsertId();
        register_shutdown_function(function () use ($sid) {
            try { db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sid]); } catch (Throwable $e) {}
        });
    } catch (Throwable $e) { $sid = 0; } }   // Die Anzeige "wer schaut" ist nur Zugabe und darf das Abspielen nie verhindern
    http_response_code($code);
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if ($code === 206) header("Content-Range: bytes $start-$end/$size");
    header('Cache-Control: no-store');
    if ($head) exit;
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);
    ignore_user_abort(false);
    $fp = @fopen($path, 'rb');
    if (!$fp) exit;
    fseek($fp, $start);
    $left = $end - $start + 1; $beat = time();
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(1048576, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk; $left -= strlen($chunk);
        flush();
        if (time() - $beat >= 15) {
            $beat = time();
            try { db()->prepare('UPDATE sessions SET last_seen = ? WHERE id = ?')->execute([$beat, $sid]); } catch (Throwable $e) {}
        }
    }
    fclose($fp);
    exit;
}

// ---------- Mediathek scannen ----------
function clean_title(string $f): string {
    $t = pathinfo($f, PATHINFO_FILENAME);
    $t = str_replace(['.', '_'], ' ', $t);
    $t = preg_replace('/\b(480p|576p|720p|1080p|2160p|4k|uhd|bluray|blu-ray|brrip|bdrip|webrip|web-dl|webdl|hdtv|dvdrip|x264|x265|h264|h265|hevc|german|dl)\b.*$/i', '', $t);
    return trim(preg_replace('/\s+/', ' ', $t)) ?: pathinfo($f, PATHINFO_FILENAME);
}

function get_category(string $type, string $name): int {
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM categories WHERE type = ? AND name = ?');
    $st->execute([$type, $name]);
    if ($id = $st->fetchColumn()) return (int)$id;
    $pdo->prepare('INSERT INTO categories(type, name) VALUES(?, ?)')->execute([$type, $name]);
    return (int)$pdo->lastInsertId();
}

function list_videos(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), MEDIA_EXT, true)) $out[] = $f->getPathname();
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function rel_parts(string $path, string $base): array {
    return explode('/', str_replace('\\', '/', ltrim(substr($path, strlen($base)), '/\\')));
}

function probe_duration(string $path): int {
    if (!have_bin('ffprobe')) return 0;
    exec('ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($path) . ' 2>/dev/null', $out, $rc);
    return $rc === 0 ? (int)round((float)($out[0] ?? 0)) : 0;
}

function scan_library(): array {
    set_time_limit(0);
    $pdo = db();
    $res = ['movies' => 0, 'series' => 0, 'episodes' => 0, 'error' => null];
    $vod = realpath(setting('vod_dir')); $ser = realpath(setting('series_dir'));
    if (!$vod && !$ser) { $res['error'] = 'Weder ' . setting('vod_dir') . ' noch ' . setting('series_dir') . ' gefunden (Einstellungen prüfen).'; return $res; }
    $now = time();
    $pdo->beginTransaction();
    try {
        if ($vod) {
            $exists = $pdo->prepare('SELECT 1 FROM streams WHERE source = ?');
            foreach (list_videos($vod) as $p) {
                $parts = rel_parts($p, $vod);
                $src = 'local:vod/' . implode('/', $parts);
                $exists->execute([$src]);
                if ($exists->fetchColumn()) continue;
                $cat = count($parts) > 1 ? $parts[0] : 'Filme';
                $pdo->prepare("INSERT INTO streams(type, category_id, name, source, container, duration, size, added) VALUES('movie', ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([get_category('movie', $cat), clean_title($p), $src, strtolower(pathinfo($p, PATHINFO_EXTENSION)), probe_duration($p), (int)@filesize($p), $now]);
                $res['movies']++;
            }
        }
        if ($ser) {
            $epExists = $pdo->prepare('SELECT 1 FROM episodes WHERE source = ?');
            foreach (list_videos($ser) as $p) {
                $parts = rel_parts($p, $ser);
                if (count($parts) < 2) continue;
                $src = 'local:series/' . implode('/', $parts);
                $epExists->execute([$src]);
                if ($epExists->fetchColumn()) continue;
                $sname = $parts[0];
                $st = $pdo->prepare('SELECT id FROM series WHERE name = ?'); $st->execute([$sname]);
                $sid = $st->fetchColumn();
                if (!$sid) {
                    $pdo->prepare('INSERT INTO series(category_id, name, added) VALUES(?, ?, ?)')->execute([get_category('series', 'Serien'), $sname, $now]);
                    $sid = $pdo->lastInsertId(); $res['series']++;
                }
                $file = basename($p);
                if (preg_match('/S(\d{1,2})\s*E(\d{1,3})/i', $file, $m)) { $season = (int)$m[1]; $epn = (int)$m[2]; }
                elseif (preg_match('/(\d{1,2})x(\d{2,3})/i', $file, $m)) { $season = (int)$m[1]; $epn = (int)$m[2]; }
                else {
                    $season = 1;
                    $c = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE series_id = ? AND season = 1'); $c->execute([$sid]);
                    $epn = (int)$c->fetchColumn() + 1;
                }
                $pdo->prepare('INSERT INTO episodes(series_id, season, episode_num, title, source, container, duration, size, added) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute([$sid, $season, $epn, clean_title($file), $src, strtolower(pathinfo($p, PATHINFO_EXTENSION)), probe_duration($p), (int)@filesize($p), $now]);
                $res['episodes']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $res['error'] = 'Scan abgebrochen: ' . $e->getMessage();
    }
    if (!$res['error']) refresh_sizes();   // Größen für ältere Einträge nachtragen
    return $res;
}

function unreadable_files(int $limit = 10): array {   // Dateien, die der Webserver nicht lesen darf
    $out = [];
    $q = db()->query("SELECT source FROM streams WHERE source LIKE 'local:%' UNION ALL SELECT source FROM episodes WHERE source LIKE 'local:%'");
    foreach ($q as $r) {
        [$root, $sub] = array_pad(explode('/', substr($r['source'], 6), 2), 2, '');
        $dir = dir_for($root);
        $p = $dir . '/' . $sub;
        if ($dir !== '' && is_file($p) && !is_readable($p)) { $out[] = $p; if (count($out) >= $limit) break; }
    }
    return $out;
}

function refresh_sizes(bool $all = false, int $seriesId = 0): array {   // Dateigrößen in die Datenbank schreiben
    set_time_limit(0);
    $pdo = db();
    $r = ['done' => 0, 'missing' => 0, 'example' => ''];
    foreach (['streams', 'episodes'] as $t) {
        if ($seriesId && $t === 'streams') continue;
        $sql = "SELECT id, source FROM $t WHERE source LIKE 'local:%'" . ($all ? '' : ' AND size = 0')
             . ($seriesId && $t === 'episodes' ? ' AND series_id = ' . (int)$seriesId : '');
        $rows = $pdo->query($sql)->fetchAll();
        if (!$rows) continue;
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE $t SET size = ? WHERE id = ?");
        foreach ($rows as $row) {
            $p = local_path($row['source']);
            if (!$p) { $r['missing']++; if ($r['example'] === '') $r['example'] = substr($row['source'], 6); continue; }
            $up->execute([(int)filesize($p), $row['id']]);
            $r['done']++;
        }
        $pdo->commit();
    }
    return $r;
}

function disk_info(): array {   // je Platte: [Beschriftung, Ordner, frei, gesamt]; gleiche Platte nur einmal
    $out = []; $seen = [];
    foreach (['Filme' => setting('vod_dir'), 'Serien' => setting('series_dir')] as $label => $dir) {
        if (!is_dir($dir)) continue;
        $free = @disk_free_space($dir); $total = @disk_total_space($dir);
        if ($free === false || $total === false || $total <= 0) continue;
        $st = @stat($dir);
        $dev = $st ? (string)$st['dev'] : $dir;
        if (isset($seen[$dev])) { $out[$seen[$dev]][0] .= ' und ' . $label; continue; }
        $seen[$dev] = count($out);
        $out[] = [$label, $dir, $free, $total];
    }
    return $out;
}

function root_ok(string $root): bool { $d = dir_for($root); return $d !== '' && realpath($d) !== false; }

function cleanup_missing(): array {
    $pdo = db();
    $rm = ['movies' => 0, 'episodes' => 0, 'series' => 0];
    if (root_ok('vod')) {
        foreach ($pdo->query("SELECT id, source FROM streams WHERE source LIKE 'local:vod/%'")->fetchAll() as $r) {
            if (local_path($r['source']) === null) { $pdo->prepare('DELETE FROM streams WHERE id = ?')->execute([$r['id']]); $rm['movies']++; }
        }
    }
    if (root_ok('series')) {
        $touched = [];
        foreach ($pdo->query("SELECT id, series_id, source FROM episodes WHERE source LIKE 'local:series/%'")->fetchAll() as $r) {
            if (local_path($r['source']) === null) {
                $pdo->prepare('DELETE FROM episodes WHERE id = ?')->execute([$r['id']]);
                $touched[$r['series_id']] = true; $rm['episodes']++;
            }
        }
        foreach (array_keys($touched) as $sid) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE series_id = ?'); $c->execute([$sid]);
            if ((int)$c->fetchColumn() === 0) { $pdo->prepare('DELETE FROM series WHERE id = ?')->execute([$sid]); $rm['series']++; }
        }
    }
    return $rm;
}

// ---------- Cover ----------
function is_local_cover(string $v): bool { return strncmp($v, 'covers/', 7) === 0 && strpos($v, '..') === false; }
function drop_cover(string $v): void { if (is_local_cover($v) && is_file(__DIR__ . '/' . $v)) @unlink(__DIR__ . '/' . $v); }

function save_cover_upload(array $f, string $prefix): array {   // [pfad|null, fehler|null]
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'Upload fehlgeschlagen (Code ' . $f['error'] . ', evtl. upload_max_filesize zu klein)'];
    if ($f['size'] > 8 * 1024 * 1024) return [null, 'Bild zu groß (max. 8 MB)'];
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($map[$info[2]])) return [null, 'Kein gültiges Bild (jpg, png, webp oder gif)'];
    if (!is_dir(COVER_DIR) && !@mkdir(COVER_DIR, 0775, true)) return [null, 'Ordner covers/ nicht beschreibbar'];
    $name = $prefix . '_' . rand_str(10) . '.' . $map[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], COVER_DIR . '/' . $name)) return [null, 'Speichern fehlgeschlagen (Rechte auf covers/ prüfen)'];
    return ['covers/' . $name, null];
}

function make_frame(string $video, string $prefix, int $duration): ?string {
    if (!have_bin('ffmpeg')) return null;
    if (!is_dir(COVER_DIR) && !@mkdir(COVER_DIR, 0775, true)) return null;
    $sec = $duration > 0 ? (int)max(5, min(600, $duration * 0.12)) : 60;
    $name = $prefix . '_' . rand_str(10) . '.jpg';
    exec('ffmpeg -y -loglevel error -ss ' . $sec . ' -i ' . escapeshellarg($video) . ' -frames:v 1 -vf scale=500:-2 -q:v 3 ' . escapeshellarg(COVER_DIR . '/' . $name) . ' 2>&1', $o, $rc);
    return ($rc === 0 && is_file(COVER_DIR . '/' . $name)) ? 'covers/' . $name : null;
}

// ---------- HTTP / TMDb ----------
function http_get(string $url, array $headers = [], int $timeout = 12): ?string {
    if (!preg_match('#^https?://#i', $url)) return null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'xtream-panel/' . APP_VERSION, CURLOPT_HTTPHEADER => $headers];
        if (defined('CURLPROTO_HTTP')) { $opt[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS; $opt[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS; }
        curl_setopt_array($ch, $opt);
        $r = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($r !== false && $code >= 200 && $code < 400) ? (string)$r : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => implode("\r\n", array_merge($headers, ['User-Agent: xtream-panel'])) . "\r\n"]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}

function tmdb(string $path, array $params = []): ?array {
    $key = trim(setting('tmdb_key'));
    if ($key === '') return null;
    $params['language'] = setting('tmdb_lang') ?: 'de-DE';
    $headers = ['Accept: application/json'];
    if (strlen($key) > 40) $headers[] = 'Authorization: Bearer ' . $key; else $params['api_key'] = $key;
    $r = http_get('https://api.themoviedb.org/3' . $path . '?' . http_build_query($params), $headers);
    $j = $r === null ? null : json_decode($r, true);
    return is_array($j) ? $j : null;
}

function guess_query(string $name): array {   // [suchbegriff, jahr]
    $year = 0;
    if (preg_match_all('/\b(19|20)\d{2}\b/', $name, $m)) { $all = $m[0]; $year = (int)end($all); }
    $q = trim(preg_replace('/\s+/', ' ', preg_replace('/[\(\[]?\b(19|20)\d{2}\b[\)\]]?/', ' ', $name)));
    return [$q !== '' ? $q : $name, $year];
}

function tmdb_lookup(string $kind, string $query, int $year = 0, int $id = 0): ?array {   // $kind: movie | tv
    if (!$id) {
        $p = ['query' => $query];
        if ($year) $p[$kind === 'movie' ? 'year' : 'first_air_date_year'] = $year;
        $r = tmdb("/search/$kind", $p);
        $id = (int)($r['results'][0]['id'] ?? 0);
        if (!$id && $year) { $r = tmdb("/search/$kind", ['query' => $query]); $id = (int)($r['results'][0]['id'] ?? 0); }
    }
    if (!$id) return null;
    $d = tmdb("/$kind/$id", ['append_to_response' => 'credits,videos', 'include_video_language' => 'de,en,null']);
    if (!$d || empty($d['id'])) return null;
    $img = function ($p, $w) { return $p ? "https://image.tmdb.org/t/p/$w$p" : ''; };
    $trailer = '';
    foreach ($d['videos']['results'] ?? [] as $v) {
        if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer') { $trailer = (string)$v['key']; break; }
    }
    $director = '';
    if ($kind === 'movie') {
        foreach ($d['credits']['crew'] ?? [] as $c) { if (($c['job'] ?? '') === 'Director') { $director = (string)$c['name']; break; } }
    } else {
        $director = implode(', ', array_column($d['created_by'] ?? [], 'name'));
    }
    return [
        'tmdb_id' => $id,
        'title' => (string)($d['title'] ?? $d['name'] ?? ''),
        'plot' => (string)($d['overview'] ?? ''),
        'rating' => round((float)($d['vote_average'] ?? 0), 1),
        'releasedate' => (string)($d['release_date'] ?? $d['first_air_date'] ?? ''),
        'genre' => implode(', ', array_column($d['genres'] ?? [], 'name')),
        'actors' => implode(', ', array_slice(array_column($d['credits']['cast'] ?? [], 'name'), 0, 8)),
        'cover' => $img($d['poster_path'] ?? '', 'w500'),
        'backdrop' => $img($d['backdrop_path'] ?? '', 'w1280'),
        'trailer' => $trailer,
        'director' => $director,
        'duration' => (int)($d['runtime'] ?? 0) * 60,
        'episode_run_time' => (int)($d['episode_run_time'][0] ?? 0),
    ];
}

function meta_apply(string $table, int $id, array $m, bool $overwrite): int {   // $table: streams | series
    $common = ['plot' => 'plot', 'rating' => 'rating', 'releasedate' => 'releasedate', 'genre' => 'genre', 'actors' => 'actors',
               'backdrop' => 'backdrop', 'trailer' => 'trailer', 'director' => 'director', 'tmdb_id' => 'tmdb_id'];
    $map = $table === 'streams' ? $common + ['cover' => 'icon', 'duration' => 'duration'] : $common + ['cover' => 'cover', 'episode_run_time' => 'episode_run_time'];
    $st = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return 0;
    $set = []; $vals = [];
    foreach ($map as $from => $col) {
        $new = $m[$from] ?? '';
        if ($new === '' || $new === 0 || $new === 0.0) continue;
        $old = $row[$col] ?? '';
        $empty = ($old === '' || $old === null || (is_numeric($old) && (float)$old == 0));
        if ($col === 'tmdb_id' || $overwrite || $empty) { $set[] = "$col = ?"; $vals[] = $new; }
    }
    $set[] = 'meta_tried = 1';
    $vals[] = $id;
    db()->prepare("UPDATE $table SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    return count($set) - 1;
}

function tmdb_item(string $kind, int $id, bool $overwrite, string $q = '', int $tid = 0): ?array {   // $kind: movie | series
    $table = $kind === 'movie' ? 'streams' : 'series';
    $st = db()->prepare("SELECT name, tmdb_id FROM $table WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    [$guess, $year] = guess_query($q !== '' ? $q : $row['name']);
    if (!$tid && !empty($row['tmdb_id']) && $q === '') $tid = (int)$row['tmdb_id'];
    $m = tmdb_lookup($kind === 'movie' ? 'movie' : 'tv', $guess, $year, $tid);
    if (!$m) { db()->prepare("UPDATE $table SET meta_tried = 1 WHERE id = ?")->execute([$id]); return null; }
    $m['changed'] = meta_apply($table, $id, $m, $overwrite);
    return $m;
}

function tmdb_bulk(string $kind, int $limit = 25, array $ids = []): array {   // [gefunden, nicht gefunden]
    set_time_limit(0);
    $table = $kind === 'movie' ? 'streams' : 'series';
    $pdo = db();
    if ($ids) {
        $ids = array_map('intval', $ids);
    } else {
        $icon = $kind === 'movie' ? 'icon' : 'cover';
        $where = $kind === 'movie' ? "type = 'movie' AND " : '';
        $ids = $pdo->query("SELECT id FROM $table WHERE {$where}meta_tried = 0 AND (plot = '' OR $icon = '') ORDER BY id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
    }
    $ok = 0; $miss = 0;
    foreach ($ids as $id) { if (tmdb_item($kind, (int)$id, false)) $ok++; else $miss++; }
    return [$ok, $miss];
}

function tmdb_episodes(int $sid, bool $overwrite): int {
    $st = db()->prepare('SELECT tmdb_id FROM series WHERE id = ?');
    $st->execute([$sid]);
    $tid = (int)$st->fetchColumn();
    if (!$tid) return 0;
    $seasons = db()->prepare('SELECT DISTINCT season FROM episodes WHERE series_id = ?');
    $seasons->execute([$sid]);
    $n = 0;
    foreach ($seasons->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $r = tmdb("/tv/$tid/season/" . (int)$s);
        foreach ($r['episodes'] ?? [] as $e) {
            $q = db()->prepare('SELECT * FROM episodes WHERE series_id = ? AND season = ? AND episode_num = ?');
            $q->execute([$sid, (int)$s, (int)($e['episode_number'] ?? 0)]);
            foreach ($q->fetchAll() as $row) {
                $set = []; $vals = [];
                $try = ['title' => (string)($e['name'] ?? ''), 'plot' => (string)($e['overview'] ?? ''),
                        'cover' => !empty($e['still_path']) ? 'https://image.tmdb.org/t/p/w300' . $e['still_path'] : '',
                        'releasedate' => (string)($e['air_date'] ?? ''), 'rating' => round((float)($e['vote_average'] ?? 0), 1),
                        'duration' => (int)($e['runtime'] ?? 0) * 60];
                foreach ($try as $col => $new) {
                    if ($new === '' || $new === 0 || $new === 0.0) continue;
                    $old = $row[$col] ?? '';
                    $empty = ($old === '' || $old === null || (is_numeric($old) && (float)$old == 0));
                    if ($overwrite || $empty) { $set[] = "$col = ?"; $vals[] = $new; }
                }
                if ($set) { $vals[] = $row['id']; db()->prepare('UPDATE episodes SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals); $n++; }
            }
        }
    }
    return $n;
}

// ---------- Live: M3U-Import ----------
function import_m3u(string $text): int {
    $pdo = db(); $n = 0; $meta = null; $now = time();
    $exists = $pdo->prepare("SELECT 1 FROM streams WHERE type = 'live' AND source = ? AND name = ?");
    $pdo->beginTransaction();
    foreach (preg_split('/\r\n|\r|\n/', $text) as $l) {
        $l = trim($l);
        if ($l === '') continue;
        if (stripos($l, '#EXTINF') === 0) {
            preg_match_all('/([\w-]+)="([^"]*)"/', $l, $m, PREG_SET_ORDER);
            $attr = [];
            foreach ($m as $x) $attr[strtolower($x[1])] = $x[2];
            $q = strrpos($l, '"');
            $pos = strpos($l, ',', $q === false ? 0 : $q);
            $name = $pos !== false ? trim(substr($l, $pos + 1)) : '';
            $meta = ['name' => $name !== '' ? $name : ($attr['tvg-name'] ?? 'Kanal'), 'icon' => $attr['tvg-logo'] ?? '',
                     'epg' => $attr['tvg-id'] ?? '', 'group' => ($attr['group-title'] ?? '') !== '' ? $attr['group-title'] : 'Live'];
        } elseif ($l[0] !== '#' && $meta && preg_match('#^https?://#i', $l)) {
            $exists->execute([$l, $meta['name']]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare("INSERT INTO streams(type, category_id, name, source, icon, epg_id, container, added) VALUES('live', ?, ?, ?, ?, ?, 'ts', ?)")
                    ->execute([get_category('live', $meta['group']), $meta['name'], $l, $meta['icon'], $meta['epg'], $now]);
                $n++;
            }
            $meta = null;
        }
    }
    $pdo->commit();
    return $n;
}

// ---------- Downloads per URL ----------
define('DL_DIR', DATA_DIR . '/dl');

function dl_clean(string $s): string { return trim(preg_replace('/[^\p{L}\p{N}._ \-()\[\]]/u', '', $s), " ."); }

function dl_start(string $url, string $type, string $sub, string $fname): string {
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) return 'Ungültige URL (nur http/https).';
    if (!exec_ok()) return 'exec() ist in der php.ini deaktiviert.';
    if (!have_bin('curl')) return 'curl fehlt auf dem Server (apt install curl).';
    $baseDir = dir_for($type === 'series' ? 'series' : 'vod');
    if ($baseDir === '' || !is_dir($baseDir) || !is_writable($baseDir)) return "Ordner nicht beschreibbar: $baseDir (chown -R www-data:www-data $baseDir)";
    $sub = dl_clean($sub);
    if ($type === 'series' && $sub === '') return 'Bei Serien bitte den Serienordner angeben.';
    $name = $fname !== '' ? dl_clean($fname) : dl_clean(urldecode(basename((string)parse_url($url, PHP_URL_PATH))));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || !in_array($ext, MEDIA_EXT, true)) return 'Dateiname mit Endung (' . implode('/', MEDIA_EXT) . ') nötig, bitte Dateiname angeben.';
    $dir = rtrim($baseDir, '/') . ($sub !== '' ? "/$sub" : '');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return "Konnte Ordner nicht anlegen: $dir";
    $real = realpath($dir); $rb = realpath($baseDir);
    if (!$real || !$rb || ($real !== $rb && strpos($real, $rb . '/') !== 0)) return 'Ungültiger Zielordner.';
    $target = $real . '/' . $name;
    if (file_exists($target) || file_exists($target . '.part')) return "Datei existiert schon: $name";
    if (!is_dir(DL_DIR) && !@mkdir(DL_DIR, 0775, true)) return 'data/dl nicht beschreibbar.';
    $base = DL_DIR . '/' . rand_str(12);
    file_put_contents($base . '.json', json_encode(['url' => $url, 'target' => $target, 'name' => $name, 'started' => time()]));
    $script = 'echo $$ > "$3.pid"; curl -L --fail --max-redirs 5 --proto "=http,https" -sS -o "$1.part" "$2" 2>"$3.err"; '
            . 'if [ $? -eq 0 ]; then mv "$1.part" "$1" && echo ok > "$3.status"; else rm -f "$1.part"; echo fail > "$3.status"; fi';
    exec('nohup sh -c ' . escapeshellarg($script) . ' sh ' . escapeshellarg($target) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($base) . ' >/dev/null 2>&1 &');
    return "Download gestartet: $name";
}

function dl_jobs(): array {
    clearstatcache(true);
    $files = glob(DL_DIR . '/*.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $jobs = [];
    foreach (array_slice($files, 0, 20) as $f) {
        $j = json_decode((string)file_get_contents($f), true) ?: [];
        $base = substr($f, 0, -5);
        $t = $j['target'] ?? ''; $size = 0;
        if ($t !== '') { if (is_file($t . '.part')) $size = filesize($t . '.part'); elseif (is_file($t)) $size = filesize($t); }
        $j['id'] = basename($base);
        $j['size'] = (int)$size;
        $j['status'] = is_file($base . '.status') ? trim((string)file_get_contents($base . '.status')) : 'running';
        $j['err'] = is_file($base . '.err') ? trim((string)file_get_contents($base . '.err')) : '';
        if ($j['status'] === 'ok' && !is_file($base . '.scanned')) { touch($base . '.scanned'); $j['new'] = true; }
        $jobs[] = $j;
    }
    return $jobs;
}

function dl_cancel(string $id): void {
    $id = preg_replace('/[^a-f0-9]/', '', $id);
    $base = DL_DIR . '/' . $id;
    if (!is_file($base . '.json') || is_file($base . '.status')) return;
    $j = json_decode((string)file_get_contents($base . '.json'), true) ?: [];
    $pid = (int)@file_get_contents($base . '.pid');
    if ($pid > 1 && exec_ok()) exec('pkill -P ' . $pid . ' 2>/dev/null; kill ' . $pid . ' 2>/dev/null');
    if (!empty($j['target'])) @unlink($j['target'] . '.part');
    file_put_contents($base . '.status', 'fail');
    file_put_contents($base . '.err', 'Abgebrochen');
}

function dl_clear(): int {
    $n = 0;
    foreach (glob(DL_DIR . '/*.json') ?: [] as $f) {
        $base = substr($f, 0, -5);
        if (!is_file($base . '.status')) continue;
        foreach (['json', 'status', 'err', 'pid', 'scanned'] as $e) @unlink("$base.$e");
        $n++;
    }
    return $n;
}