<?php
require __DIR__ . '/lib.php';
$user = auth_user($_GET['username'] ?? null, $_GET['password'] ?? null);
if (!$user || !user_active($user)) { http_response_code(403); exit('Forbidden'); }
header('Content-Type: application/xml; charset=utf-8');
$f = DATA_DIR . '/epg.xml';
if (is_file($f)) { readfile($f); exit; }
echo '<?xml version="1.0" encoding="UTF-8"?><tv generator-info-name="xtream-panel"></tv>';
