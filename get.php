<?php
// M3U-Playlist: Live-Kanäle, Filme und Serienfolgen. Mit &only=live, &only=movie oder &only=series einschränkbar.
require __DIR__ . '/lib.php';
$user = auth_user($_GET['username'] ?? null, $_GET['password'] ?? null);
if (!$user || !user_active($user)) { http_response_code(403); exit('Forbidden'); }
[, , , $base] = base_url();
$u = rawurlencode($user['username']); $p = rawurlencode($user['password']);
$only = (string)($_GET['only'] ?? $_GET['content'] ?? '');
$q = fn($v) => str_replace('"', "'", (string)$v);
header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: attachment; filename="playlist.m3u"');
echo "#EXTM3U\n";

if ($only === '' || $only === 'live') {
    $st = db()->query("SELECT s.*, c.name AS cat FROM streams s LEFT JOIN categories c ON c.id = s.category_id WHERE s.type = 'live' ORDER BY c.sort, c.name, s.name COLLATE NOCASE");
    foreach ($st as $s) {
        printf("#EXTINF:-1 tvg-id=\"%s\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\n%s/live/%s/%s/%d.ts\n",
            $s['epg_id'], $q($s['name']), abs_img($s['icon']), $q($s['cat']), $s['name'], $base, $u, $p, $s['id']);
    }
}
if ($only === '' || $only === 'movie') {
    $st = db()->query("SELECT s.*, c.name AS cat FROM streams s LEFT JOIN categories c ON c.id = s.category_id WHERE s.type = 'movie' ORDER BY c.sort, c.name, s.name COLLATE NOCASE");
    foreach ($st as $s) {
        printf("#EXTINF:-1 tvg-id=\"\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\n%s/movie/%s/%s/%d.%s\n",
            $q($s['name']), abs_img($s['icon']), $q('Filme: ' . ($s['cat'] ?: 'Unkategorisiert')), $s['name'], $base, $u, $p, $s['id'], $s['container'] ?: 'mp4');
    }
}
if ($only === '' || $only === 'series') {
    $st = db()->query('SELECT e.*, s.name AS sname, s.cover AS scover FROM episodes e JOIN series s ON s.id = e.series_id ORDER BY s.name COLLATE NOCASE, e.season, e.episode_num');
    foreach ($st as $e) {
        $title = sprintf('%s S%02dE%02d%s', $e['sname'], $e['season'], $e['episode_num'], $e['title'] !== '' ? ' ' . $e['title'] : '');
        printf("#EXTINF:-1 tvg-id=\"\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\n%s/series/%s/%s/%d.%s\n",
            $q($title), abs_img($e['cover'] !== '' ? $e['cover'] : $e['scover']), $q('Serien: ' . $e['sname']), $title, $base, $u, $p, $e['id'], $e['container'] ?: 'mp4');
    }
}
