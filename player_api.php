<?php
require __DIR__ . '/lib.php';
$user = auth_user($_GET['username'] ?? $_POST['username'] ?? null, $_GET['password'] ?? $_POST['password'] ?? null);
if (!$user) json_out(['user_info' => ['auth' => 0]]);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();
$cat = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;

function api_cats(string $type): array {
    $st = db()->prepare('SELECT * FROM categories WHERE type = ? ORDER BY sort, name COLLATE NOCASE');
    $st->execute([$type]);
    return array_map(fn($c) => ['category_id' => (string)$c['id'], 'category_name' => $c['name'], 'parent_id' => 0], $st->fetchAll());
}

function api_streams(string $type, ?int $cat): array {
    $sql = 'SELECT * FROM streams WHERE type = ?' . ($cat !== null ? ' AND category_id = ?' : '') . ' ORDER BY name COLLATE NOCASE';
    $st = db()->prepare($sql);
    $st->execute($cat !== null ? [$type, $cat] : [$type]);
    $out = []; $n = 1;
    foreach ($st->fetchAll() as $s) {
        $row = ['num' => $n++, 'name' => $s['name'], 'stream_type' => $type === 'live' ? 'live' : 'movie',
                'stream_id' => (int)$s['id'], 'stream_icon' => abs_img($s['icon']), 'added' => (string)$s['added'],
                'category_id' => (string)$s['category_id'], 'custom_sid' => '', 'direct_source' => ''];
        if ($type === 'live') {
            $row += ['epg_channel_id' => $s['epg_id'], 'tv_archive' => 0, 'tv_archive_duration' => 0];
        } else {
            $row += ['rating' => (string)$s['rating'], 'rating_5based' => round($s['rating'] / 2, 1), 'container_extension' => $s['container']];
        }
        $out[] = $row;
    }
    return $out;
}

switch ($action) {
    case '':
        [$proto, $host, $port] = base_url();
        json_out([
            'user_info' => [
                'username' => $user['username'], 'password' => $user['password'], 'message' => (string)$user['note'], 'auth' => 1,
                'status' => !$user['enabled'] ? 'Disabled' : (user_active($user) ? 'Active' : 'Expired'),
                'exp_date' => $user['exp_date'] ? (string)$user['exp_date'] : null,
                'is_trial' => (string)$user['is_trial'], 'active_cons' => '0', 'created_at' => (string)$user['created_at'],
                'max_connections' => (string)$user['max_connections'], 'allowed_output_formats' => ['m3u8', 'ts'],
            ],
            'server_info' => [
                'url' => $host, 'port' => (string)$port, 'https_port' => '443', 'server_protocol' => $proto, 'rtmp_port' => '0',
                'timezone' => date_default_timezone_get(), 'timestamp_now' => time(), 'time_now' => date('Y-m-d H:i:s'),
            ],
        ]);

    case 'get_live_categories':   json_out(api_cats('live'));
    case 'get_vod_categories':    json_out(api_cats('movie'));
    case 'get_series_categories': json_out(api_cats('series'));
    case 'get_live_streams':      json_out(api_streams('live', $cat));
    case 'get_vod_streams':       json_out(api_streams('movie', $cat));

    case 'get_vod_info':
        $st = $pdo->prepare("SELECT * FROM streams WHERE id = ? AND type = 'movie'");
        $st->execute([(int)($_GET['vod_id'] ?? 0)]);
        $s = $st->fetch();
        if (!$s) json_out([]);
        $trailer = $s['trailer'];
        json_out([
            'info' => [
                'tmdb_id' => (string)$s['tmdb_id'], 'name' => $s['name'], 'o_name' => $s['name'],
                'cover_big' => abs_img($s['icon']), 'movie_image' => abs_img($s['icon']),
                'releasedate' => $s['releasedate'], 'episode_run_time' => (string)intdiv((int)$s['duration'], 60),
                'youtube_trailer' => $trailer, 'director' => $s['director'], 'actors' => $s['actors'], 'cast' => $s['actors'],
                'description' => $s['plot'], 'plot' => $s['plot'], 'age' => '', 'mpaa_rating' => '', 'country' => '',
                'genre' => $s['genre'], 'backdrop_path' => $s['backdrop'] !== '' ? [abs_img($s['backdrop'])] : [],
                'duration_secs' => (int)$s['duration'], 'duration' => fmt_dur((int)$s['duration']),
                'video' => [], 'audio' => [], 'bitrate' => 0, 'rating' => (string)$s['rating'],
            ],
            'movie_data' => [
                'stream_id' => (int)$s['id'], 'name' => $s['name'], 'added' => (string)$s['added'],
                'category_id' => (string)$s['category_id'], 'container_extension' => $s['container'], 'custom_sid' => '', 'direct_source' => '',
            ],
        ]);

    case 'get_series':
        $sql = 'SELECT * FROM series' . ($cat !== null ? ' WHERE category_id = ?' : '') . ' ORDER BY name COLLATE NOCASE';
        $st = $pdo->prepare($sql);
        $st->execute($cat !== null ? [$cat] : []);
        $out = []; $n = 1;
        foreach ($st->fetchAll() as $s) {
            $out[] = ['num' => $n++, 'name' => $s['name'], 'series_id' => (int)$s['id'], 'cover' => abs_img($s['cover']),
                'plot' => $s['plot'], 'cast' => $s['actors'], 'director' => $s['director'], 'genre' => $s['genre'],
                'releaseDate' => $s['releasedate'], 'last_modified' => (string)$s['added'], 'rating' => (string)$s['rating'],
                'rating_5based' => round($s['rating'] / 2, 1), 'backdrop_path' => $s['backdrop'] !== '' ? [abs_img($s['backdrop'])] : [],
                'youtube_trailer' => $s['trailer'], 'episode_run_time' => (string)$s['episode_run_time'], 'category_id' => (string)$s['category_id']];
        }
        json_out($out);

    case 'get_series_info':
        $sid = (int)($_GET['series_id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM series WHERE id = ?');
        $st->execute([$sid]);
        $s = $st->fetch();
        if (!$s) json_out([]);
        $st = $pdo->prepare('SELECT * FROM episodes WHERE series_id = ? ORDER BY season, episode_num');
        $st->execute([$sid]);
        $eps = []; $seasons = [];
        foreach ($st->fetchAll() as $e) {
            $eps[(string)$e['season']][] = [
                'id' => (string)$e['id'], 'episode_num' => (int)$e['episode_num'],
                'title' => $e['title'] !== '' ? $e['title'] : 'Folge ' . $e['episode_num'],
                'container_extension' => $e['container'],
                'info' => ['movie_image' => abs_img($e['cover']), 'plot' => $e['plot'], 'releasedate' => $e['releasedate'],
                           'rating' => (string)$e['rating'], 'duration_secs' => (int)$e['duration'], 'duration' => fmt_dur((int)$e['duration'])],
                'custom_sid' => '', 'added' => (string)$e['added'], 'season' => (int)$e['season'], 'direct_source' => '',
            ];
            $seasons[$e['season']] = ['season_number' => (int)$e['season'], 'name' => 'Staffel ' . $e['season'], 'episode_count' => count($eps[(string)$e['season']]), 'cover' => abs_img($s['cover'])];
        }
        ksort($seasons);
        json_out([
            'seasons' => array_values($seasons),
            'info' => ['name' => $s['name'], 'cover' => abs_img($s['cover']), 'plot' => $s['plot'], 'cast' => $s['actors'],
                       'director' => $s['director'], 'genre' => $s['genre'], 'releaseDate' => $s['releasedate'], 'last_modified' => (string)$s['added'],
                       'rating' => (string)$s['rating'], 'rating_5based' => round($s['rating'] / 2, 1),
                       'backdrop_path' => $s['backdrop'] !== '' ? [abs_img($s['backdrop'])] : [], 'youtube_trailer' => $s['trailer'],
                       'episode_run_time' => (string)$s['episode_run_time'], 'category_id' => (string)$s['category_id']],
            'episodes' => $eps ?: new stdClass,
        ]);

    case 'get_short_epg':
    case 'get_simple_data_table':
        json_out(['epg_listings' => []]);

    default:
        json_out([]);
}
