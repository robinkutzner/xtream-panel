<?php
require __DIR__ . '/lib.php';
require __DIR__ . '/ui.php';
session_name('xtreampanel');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function go(string $q = ''): never { header('Location: admin.php' . ($q !== '' ? '?' . $q : '')); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function back(): string { return preg_replace('/[^A-Za-z0-9_=&%.+\-]/', '', (string)($_POST['back'] ?? '')); }
function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function num($v): float { return (float)str_replace(',', '.', (string)$v); }
function row(string $table, int $id): ?array {
    $st = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function db_update(string $table, int $id, array $data): void {
    $set = []; $vals = [];
    foreach ($data as $c => $v) { $set[] = "$c = ?"; $vals[] = $v; }
    $vals[] = $id;
    db()->prepare("UPDATE $table SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
}
function qs(array $over = []): string {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) if ($v === '' || $v === null) unset($q[$k]);
    return http_build_query($q);
}

// ---------- Einrichtung und Login ----------
if (($_GET['do'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }

if (setting('admin_hash') === '') {
    if (($_POST['do'] ?? '') === 'setup') {
        $pw = (string)($_POST['pw'] ?? '');
        if (strlen($pw) < 6) flash('Das Passwort braucht mindestens 6 Zeichen.', 'bad');
        elseif ($pw !== (string)($_POST['pw2'] ?? '')) flash('Die beiden Passwörter sind nicht gleich.', 'bad');
        else {
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['ok'] = 1;
            flash('Willkommen! Prüfe unter Einstellungen die Ordner und scanne danach die Mediathek.');
        }
        go();
    }
    ui_head('Einrichtung');
    echo '<div class="card"><h1>Admin-Passwort festlegen</h1><p class="mute">Das ist der erste Start. Wähle ein Passwort für dieses Panel.</p><form method="post">'
       . '<input type="hidden" name="do" value="setup">'
       . f_text('Passwort', 'pw', '', ['type' => 'password', 'attr' => 'required autofocus'])
       . f_text('Passwort wiederholen', 'pw2', '', ['type' => 'password', 'attr' => 'required'])
       . '<button>Passwort speichern</button></form></div>';
    ui_foot(); exit;
}

if (empty($_SESSION['ok'])) {
    if (($_POST['do'] ?? '') === 'login') {
        if (password_verify((string)($_POST['pw'] ?? ''), setting('admin_hash'))) {
            session_regenerate_id(true);
            $_SESSION['ok'] = 1;
            go();
        }
        sleep(1);
        flash('Falsches Passwort.', 'bad');
        go();
    }
    ui_head('Anmelden');
    echo '<div class="card"><h1>' . h(setting('server_name')) . '</h1><form method="post"><input type="hidden" name="do" value="login">'
       . f_text('Passwort', 'pw', '', ['type' => 'password', 'attr' => 'required autofocus'])
       . '<button>Anmelden</button></form></div>';
    ui_foot(); exit;
}

require __DIR__ . '/actions.php';
require __DIR__ . '/pages.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { flash('Die Sitzung ist abgelaufen. Bitte versuche es noch einmal.', 'bad'); go(); }
    $fn = 'act_' . preg_replace('/[^a-z_]/', '', (string)($_POST['do'] ?? ''));
    if (function_exists($fn)) $fn(); else flash('Unbekannte Aktion.', 'bad');
    go(back());
}

if (empty($_SESSION['sizes_done'])) {   // Größen älterer Einträge einmal pro Anmeldung nachtragen
    $_SESSION['sizes_done'] = 1;
    try { refresh_sizes(); } catch (Throwable $e) {}
}

$p = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'dashboard'));
$fn = 'page_' . $p;
if (!function_exists($fn)) { $p = 'dashboard'; $fn = 'page_dashboard'; }
$fn();
