<?php
// Alle Aktionen, die per POST ausgelöst werden (act_<name>)

// ---------- Mediathek ----------
function act_scan(): void {
    $r = scan_library();
    flash($r['error'] ?? "Scan fertig: {$r['movies']} neue Filme, {$r['series']} neue Serien, {$r['episodes']} neue Folgen.", $r['error'] ? 'bad' : 'ok');
}

function sizes_message(array $r): array {
    $msg = $r['done'] . ' Dateigrößen berechnet.';
    if ($r['missing']) $msg .= ' ' . $r['missing'] . ' Dateien wurden nicht gefunden (z. B. ' . $r['example'] . '). Prüfe die Ordner unter Einstellungen oder nutze "Fehlende Dateien entfernen".';
    return [$msg, ($r['missing'] && !$r['done']) ? 'bad' : 'ok'];
}

function act_sizes(): void {
    [$m, $t] = sizes_message(refresh_sizes(true));
    flash($m, $t);
}

function act_cleanup(): void {
    $c = cleanup_missing();
    flash("Aufgeräumt: {$c['movies']} Filme, {$c['episodes']} Folgen und {$c['series']} Serien entfernt, deren Dateien fehlen.");
}

function act_tmdb_bulk(): void {
    if (trim(setting('tmdb_key')) === '') { flash('Trage zuerst unter Einstellungen einen TMDb-Schlüssel ein.', 'bad'); return; }
    $kind = ($_POST['kind'] ?? '') === 'series' ? 'series' : 'movie';
    [$ok, $miss] = tmdb_bulk($kind, 25);
    flash("TMDb: $ok gefunden, $miss ohne Treffer (maximal 25 pro Klick; erneut klicken für die nächsten).");
}

function act_bulk(): void {
    $kind = (string)($_POST['kind'] ?? ''); $op = (string)($_POST['op'] ?? '');
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    if (!$ids) { flash('Es ist nichts ausgewählt.', 'bad'); return; }
    if (!in_array($kind, ['movie', 'series', 'live'], true)) return;
    $table = $kind === 'series' ? 'series' : 'streams';
    $typeSql = $kind === 'series' ? '' : " AND type = '$kind'";
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($op === 'move') {
        db()->prepare("UPDATE $table SET category_id = ? WHERE id IN ($in)$typeSql")->execute(array_merge([(int)($_POST['category_id'] ?? 0)], $ids));
        flash(count($ids) . ' Einträge verschoben.');
    } elseif ($op === 'delete') {
        $col = $kind === 'movie' || $kind === 'live' ? 'icon' : 'cover';
        $st = db()->prepare("SELECT $col FROM $table WHERE id IN ($in)$typeSql");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) drop_cover((string)$c);
        db()->prepare("DELETE FROM $table WHERE id IN ($in)$typeSql")->execute($ids);
        flash(count($ids) . ' Einträge gelöscht. Die Videodateien auf der Platte bleiben unverändert.');
    } elseif ($op === 'tmdb' && $kind !== 'live') {
        if (trim(setting('tmdb_key')) === '') { flash('Trage zuerst unter Einstellungen einen TMDb-Schlüssel ein.', 'bad'); return; }
        [$ok, $miss] = tmdb_bulk($kind, 0, $ids);
        flash("TMDb: $ok gefunden, $miss ohne Treffer.");
    }
}

// ---------- Film ----------
function act_movie_save(): void {
    $id = (int)($_POST['id'] ?? 0); $act = (string)($_POST['act'] ?? 'save'); $pdo = db();
    if ($act === 'delete' && $id) {
        if ($r = row('streams', $id)) { drop_cover($r['icon']); $pdo->prepare('DELETE FROM streams WHERE id = ?')->execute([$id]); flash('Film gelöscht. Die Videodatei bleibt auf der Platte.'); }
        go('p=movies');
    }
    if ($id === 0) {
        $src = post_str('source');
        if (!preg_match('#^https?://#i', $src)) { flash('Für einen neuen Film brauchst du eine Quell-URL (http oder https).', 'bad'); go('p=movie_edit&id=0'); }
        $pdo->prepare("INSERT INTO streams(type, name, source, container, added) VALUES('movie', ?, ?, ?, ?)")
            ->execute([post_str('name') ?: 'Neuer Film', $src, post_str('container') ?: 'mp4', time()]);
        $id = (int)$pdo->lastInsertId();
    }
    $row = row('streams', $id);
    if (!$row) { flash('Film nicht gefunden.', 'bad'); go('p=movies'); }
    $icon = post_str('icon');
    [$up, $err] = save_cover_upload($_FILES['cover_file'] ?? [], 'movie' . $id);
    if ($err) flash($err, 'bad');
    if ($up) $icon = $up;
    if ($icon !== $row['icon']) drop_cover($row['icon']);
    $data = ['name' => post_str('name') ?: $row['name'], 'category_id' => (int)($_POST['category_id'] ?? 0), 'plot' => post_str('plot'),
             'rating' => num($_POST['rating'] ?? 0), 'releasedate' => post_str('releasedate'), 'genre' => post_str('genre'),
             'actors' => post_str('actors'), 'director' => post_str('director'), 'duration' => (int)round(num($_POST['duration_min'] ?? 0) * 60),
             'trailer' => post_str('trailer'), 'icon' => $icon, 'backdrop' => post_str('backdrop'),
             'container' => ltrim(strtolower(post_str('container')), '.') ?: $row['container']];
    if (strncmp($row['source'], 'local:', 6) !== 0 && preg_match('#^https?://#i', post_str('source'))) $data['source'] = post_str('source');
    db_update('streams', $id, $data);
    flash('Film gespeichert.');

    if ($act === 'tmdb') {
        if (trim(setting('tmdb_key')) === '') flash('Trage zuerst unter Einstellungen einen TMDb-Schlüssel ein.', 'bad');
        elseif ($m = tmdb_item('movie', $id, !empty($_POST['overwrite']), post_str('tmdb_q'), (int)($_POST['tmdb_id_in'] ?? 0))) flash('TMDb: "' . $m['title'] . '" gefunden, ' . $m['changed'] . ' Felder übernommen.');
        else flash('TMDb: kein Treffer. Versuche einen anderen Suchbegriff oder trage die TMDb-ID ein.', 'bad');
    } elseif ($act === 'frame') {
        $path = local_path((string)row('streams', $id)['source']);
        $f = $path ? make_frame($path, 'frame' . $id, (int)$row['duration']) : null;
        if ($f) { drop_cover((string)row('streams', $id)['icon']); db_update('streams', $id, ['icon' => $f]); flash('Bild aus dem Video erzeugt.'); }
        else flash('Bild konnte nicht erzeugt werden (ffmpeg installiert? Nur bei lokalen Dateien).', 'bad');
    }
    go("p=movie_edit&id=$id");
}

// ---------- Serie ----------
function act_series_save(): void {
    $id = (int)($_POST['id'] ?? 0); $act = (string)($_POST['act'] ?? 'save'); $pdo = db();
    if ($act === 'delete' && $id) {
        if ($r = row('series', $id)) { drop_cover($r['cover']); $pdo->prepare('DELETE FROM series WHERE id = ?')->execute([$id]); flash('Serie gelöscht. Die Videodateien bleiben auf der Platte.'); }
        go('p=series');
    }
    if ($id === 0) {
        $pdo->prepare('INSERT INTO series(name, added) VALUES(?, ?)')->execute([post_str('name') ?: 'Neue Serie', time()]);
        $id = (int)$pdo->lastInsertId();
    }
    $row = row('series', $id);
    if (!$row) { flash('Serie nicht gefunden.', 'bad'); go('p=series'); }
    $cover = post_str('cover');
    [$up, $err] = save_cover_upload($_FILES['cover_file'] ?? [], 'series' . $id);
    if ($err) flash($err, 'bad');
    if ($up) $cover = $up;
    if ($cover !== $row['cover']) drop_cover($row['cover']);
    db_update('series', $id, ['name' => post_str('name') ?: $row['name'], 'category_id' => (int)($_POST['category_id'] ?? 0), 'plot' => post_str('plot'),
        'rating' => num($_POST['rating'] ?? 0), 'releasedate' => post_str('releasedate'), 'genre' => post_str('genre'), 'actors' => post_str('actors'),
        'director' => post_str('director'), 'episode_run_time' => (int)round(num($_POST['run_min'] ?? 0)), 'trailer' => post_str('trailer'),
        'cover' => $cover, 'backdrop' => post_str('backdrop')]);

    foreach ((array)($_POST['ep'] ?? []) as $eid => $e) {
        $eid = (int)$eid;
        if (!empty($_POST['epdel'][$eid])) { $pdo->prepare('DELETE FROM episodes WHERE id = ? AND series_id = ?')->execute([$eid, $id]); continue; }
        $er = row('episodes', $eid);
        if (!$er || (int)$er['series_id'] !== $id) continue;
        $d = ['season' => max(0, (int)($e['season'] ?? 1)), 'episode_num' => max(0, (int)($e['num'] ?? 1)), 'title' => trim((string)($e['title'] ?? '')),
              'plot' => trim((string)($e['plot'] ?? '')), 'cover' => trim((string)($e['cover'] ?? ''))];
        $src = trim((string)($e['source'] ?? ''));
        if (strncmp($er['source'], 'local:', 6) !== 0 && preg_match('#^https?://#i', $src)) $d['source'] = $src;
        db_update('episodes', $eid, $d);
    }
    foreach ((array)($_POST['nep'] ?? []) as $e) {
        $src = trim((string)($e['source'] ?? ''));
        if (!preg_match('#^https?://#i', $src)) continue;
        $ext = strtolower(pathinfo((string)parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
        $pdo->prepare('INSERT INTO episodes(series_id, season, episode_num, title, source, container, added) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, max(0, (int)($e['season'] ?? 1)), max(0, (int)($e['num'] ?? 1)), trim((string)($e['title'] ?? '')), $src, in_array($ext, MEDIA_EXT, true) ? $ext : 'mp4', time()]);
    }
    flash('Serie gespeichert.');

    if ($act === 'tmdb') {
        if (trim(setting('tmdb_key')) === '') flash('Trage zuerst unter Einstellungen einen TMDb-Schlüssel ein.', 'bad');
        elseif ($m = tmdb_item('series', $id, !empty($_POST['overwrite']), post_str('tmdb_q'), (int)($_POST['tmdb_id_in'] ?? 0))) flash('TMDb: "' . $m['title'] . '" gefunden, ' . $m['changed'] . ' Felder übernommen.');
        else flash('TMDb: kein Treffer. Versuche einen anderen Suchbegriff oder trage die TMDb-ID ein.', 'bad');
    } elseif ($act === 'sizes') {
        [$m, $t] = sizes_message(refresh_sizes(true, $id));
        flash($m, $t);
    } elseif ($act === 'tmdb_eps') {
        if (!(int)row('series', $id)['tmdb_id']) flash('Lade zuerst die Serie über TMDb, damit die TMDb-ID bekannt ist.', 'bad');
        else flash('TMDb: ' . tmdb_episodes($id, !empty($_POST['overwrite'])) . ' Folgen aktualisiert.');
    }
    go("p=series_edit&id=$id");
}

// ---------- Live ----------
function act_live_save(): void {
    $id = (int)($_POST['id'] ?? 0); $pdo = db();
    if (($_POST['act'] ?? '') === 'delete' && $id) {
        if ($r = row('streams', $id)) { drop_cover($r['icon']); $pdo->prepare("DELETE FROM streams WHERE id = ? AND type = 'live'")->execute([$id]); flash('Kanal gelöscht.'); }
        go('p=live');
    }
    $src = post_str('source');
    if (!preg_match('#^https?://#i', $src)) { flash('Die Quell-URL muss mit http:// oder https:// beginnen.', 'bad'); go('p=live_edit&id=' . $id); }
    if ($id === 0) {
        $pdo->prepare("INSERT INTO streams(type, name, source, container, added) VALUES('live', ?, ?, 'ts', ?)")->execute([post_str('name') ?: 'Neuer Kanal', $src, time()]);
        $id = (int)$pdo->lastInsertId();
    }
    $row = row('streams', $id);
    if (!$row || $row['type'] !== 'live') { flash('Kanal nicht gefunden.', 'bad'); go('p=live'); }
    $icon = post_str('icon');
    [$up, $err] = save_cover_upload($_FILES['cover_file'] ?? [], 'live' . $id);
    if ($err) flash($err, 'bad');
    if ($up) $icon = $up;
    if ($icon !== $row['icon']) drop_cover($row['icon']);
    db_update('streams', $id, ['name' => post_str('name') ?: $row['name'], 'category_id' => (int)($_POST['category_id'] ?? 0),
        'source' => $src, 'icon' => $icon, 'epg_id' => post_str('epg_id')]);
    flash('Kanal gespeichert.');
    go('p=live_edit&id=' . $id);
}

function act_live_import(): void {
    $text = '';
    if (!empty($_FILES['m3u']['tmp_name']) && ($_FILES['m3u']['error'] ?? 1) === UPLOAD_ERR_OK) $text = (string)file_get_contents($_FILES['m3u']['tmp_name']);
    elseif (post_str('m3u_url') !== '') {
        $text = http_get(post_str('m3u_url'), [], 30) ?? '';
        if ($text === '') { flash('Die URL konnte nicht geladen werden.', 'bad'); return; }
    } else $text = (string)($_POST['m3u_text'] ?? '');
    if (stripos($text, '#EXTINF') === false) { flash('Keine gültige M3U-Liste gefunden (Zeilen mit #EXTINF fehlen).', 'bad'); return; }
    flash(import_m3u($text) . ' Kanäle importiert.');
}

// ---------- Kategorien ----------
function act_cats_save(): void {
    $type = (string)($_POST['type'] ?? '');
    if (!in_array($type, ['live', 'movie', 'series'], true)) return;
    $pdo = db();
    $table = $type === 'series' ? 'series' : 'streams';
    foreach ((array)($_POST['n'] ?? []) as $cid => $name) {
        $cid = (int)$cid;
        if (!empty($_POST['d'][$cid])) {
            $fallback = get_category($type, 'Unkategorisiert');
            if ($cid !== $fallback) {
                $pdo->prepare("UPDATE $table SET category_id = ? WHERE category_id = ?" . ($type === 'series' ? '' : " AND type = '$type'"))->execute([$fallback, $cid]);
                $pdo->prepare('DELETE FROM categories WHERE id = ? AND type = ?')->execute([$cid, $type]);
                continue;
            }
        }
        if (trim((string)$name) !== '') $pdo->prepare('UPDATE categories SET name = ?, sort = ? WHERE id = ? AND type = ?')->execute([trim((string)$name), (int)($_POST['s'][$cid] ?? 0), $cid, $type]);
    }
    if (post_str('new_name') !== '') get_category($type, post_str('new_name'));
    flash('Kategorien gespeichert.');
}

// ---------- Benutzer ----------
function act_user_create(): void {
    $count = max(1, min(100, (int)($_POST['count'] ?? 1)));
    $days = (int)($_POST['days'] ?? 0);
    $pdo = db(); $made = 0; $last = '';
    for ($i = 0; $i < $count; $i++) {
        $name = ($count === 1 && post_str('username') !== '') ? post_str('username') : 'u' . rand_str(8);
        $pass = post_str('password') !== '' && $count === 1 ? post_str('password') : rand_str(10);
        try {
            $pdo->prepare('INSERT INTO users(username, password, exp_date, max_connections, is_trial, note, created_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$name, $pass, $days > 0 ? time() + $days * 86400 : null, max(1, (int)($_POST['max'] ?? 1)), !empty($_POST['trial']) ? 1 : 0, post_str('note'), time()]);
            $made++; $last = $name;
        } catch (PDOException $e) {
            flash("Der Benutzername \"$name\" ist schon vergeben.", 'bad');
            if ($count === 1) return;
        }
    }
    if ($made) flash($made === 1 ? "Benutzer \"$last\" angelegt." : "$made Benutzer angelegt.");
}

function act_user_save(): void {
    $id = (int)($_POST['id'] ?? 0);
    if (!row('users', $id)) { flash('Benutzer nicht gefunden.', 'bad'); go('p=users'); }
    $exp = post_str('exp');
    $ts = $exp !== '' ? strtotime($exp . ' 23:59:59') : false;
    try {
        db_update('users', $id, ['username' => post_str('username'), 'password' => post_str('password'), 'exp_date' => $ts ?: null,
            'max_connections' => max(1, (int)($_POST['max'] ?? 1)), 'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'is_trial' => !empty($_POST['trial']) ? 1 : 0, 'note' => post_str('note')]);
        flash('Benutzer gespeichert.');
    } catch (PDOException $e) { flash('Dieser Benutzername ist schon vergeben.', 'bad'); }
    go('p=user_edit&id=' . $id);
}

function act_user_delete(): void { db()->prepare('DELETE FROM users WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]); flash('Benutzer gelöscht.'); }
function act_user_toggle(): void { db()->prepare('UPDATE users SET enabled = 1 - enabled WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]); flash('Status geändert.'); }
function act_user_extend(): void {
    $u = row('users', (int)($_POST['id'] ?? 0));
    if (!$u) return;
    if (!$u['exp_date']) { flash('Dieser Benutzer läuft nie ab.', 'bad'); return; }
    $days = max(1, (int)($_POST['days'] ?? 30));
    db_update('users', (int)$u['id'], ['exp_date' => max(time(), (int)$u['exp_date']) + $days * 86400]);
    flash("Um $days Tage verlängert.");
}

// ---------- Downloads ----------
function act_dl_add(): void { $m = dl_start(post_str('url'), post_str('type'), post_str('sub'), post_str('fname')); flash($m, strncmp($m, 'Download gestartet', 18) === 0 ? 'ok' : 'bad'); }
function act_dl_cancel(): void { dl_cancel(post_str('job')); flash('Download abgebrochen.'); }
function act_dl_clear(): void { flash(dl_clear() . ' erledigte Einträge entfernt.'); }

// ---------- Einstellungen ----------
function act_settings_save(): void {
    $tz = post_str('timezone');
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) { flash('Unbekannte Zeitzone.', 'bad'); return; }
    foreach (['server_name', 'base_url', 'tmdb_key', 'tmdb_lang'] as $k) set_setting($k, post_str($k));
    set_setting('base_url', rtrim(post_str('base_url'), '/'));
    set_setting('timezone', $tz);
    set_setting('vod_dir', rtrim(post_str('vod_dir'), '/') ?: '/opt/vod');
    set_setting('series_dir', rtrim(post_str('series_dir'), '/') ?: '/opt/series');
    set_setting('enforce_cons', !empty($_POST['enforce_cons']) ? '1' : '0');
    if (post_str('new_pw') !== '') {
        if (!password_verify((string)($_POST['cur_pw'] ?? ''), setting('admin_hash'))) { flash('Das aktuelle Passwort stimmt nicht. Die übrigen Einstellungen sind gespeichert.', 'bad'); return; }
        if (strlen(post_str('new_pw')) < 6) { flash('Das neue Passwort braucht mindestens 6 Zeichen.', 'bad'); return; }
        set_setting('admin_hash', password_hash(post_str('new_pw'), PASSWORD_DEFAULT));
        flash('Passwort geändert.');
    }
    flash('Einstellungen gespeichert.');
}

function epg_store(string $xml): bool {
    if (stripos(substr($xml, 0, 4096), '<tv') === false) return false;
    return file_put_contents(DATA_DIR . '/epg.xml', $xml) !== false;
}
function act_epg_upload(): void {
    if (!empty($_FILES['epg']['tmp_name']) && ($_FILES['epg']['error'] ?? 1) === UPLOAD_ERR_OK) $xml = (string)file_get_contents($_FILES['epg']['tmp_name']);
    elseif (post_str('epg_url') !== '') $xml = (string)http_get(post_str('epg_url'), [], 60);
    else { flash('Wähle eine XMLTV-Datei oder trage eine URL ein.', 'bad'); return; }
    $ok = epg_store($xml);
    flash($ok ? 'EPG gespeichert.' : 'Das ist keine gültige XMLTV-Datei.', $ok ? 'ok' : 'bad');
}
function act_epg_remove(): void { @unlink(DATA_DIR . '/epg.xml'); flash('EPG entfernt.'); }

function act_backup(): void {
    @db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $f = DATA_DIR . '/panel.sqlite';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="panel-' . date('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}