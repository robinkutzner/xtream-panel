<?php
// Wird von .htaccess für /live|movie|series/USER/PASS/ID.ext aufgerufen
require __DIR__ . '/lib.php';
$user = auth_user($_GET['u'] ?? null, $_GET['p'] ?? null);
if (!$user || !user_active($user)) { http_response_code(403); exit('Forbidden'); }
$type = $_GET['type'] ?? ''; $id = (int)($_GET['id'] ?? 0);
if ($type === 'series') {
    $st = db()->prepare('SELECT source FROM episodes WHERE id = ?');
    $st->execute([$id]);
} elseif ($type === 'movie' || $type === 'live') {
    $st = db()->prepare('SELECT source FROM streams WHERE id = ? AND type = ?');
    $st->execute([$id, $type]);
} else { http_response_code(404); exit('Not found'); }
$src = (string)$st->fetchColumn();
if (strncmp($src, 'local:', 6) === 0) {
    $path = local_path($src);
    if (!$path) { http_response_code(404); exit('Datei nicht gefunden'); }
    serve_file($path, $user, $type, $id);
}
if (preg_match('#^https?://#i', $src)) { header('Location: ' . $src, true, 302); exit; }
http_response_code(404); exit('Not found');
