<?php
// Aufruf auf dem Server: php scan.php [--cleanup] [--tmdb]
if (PHP_SAPI !== 'cli') exit('Nur per Kommandozeile');
require __DIR__ . '/lib.php';
$r = scan_library();
echo $r['error'] ?? "Neu: {$r['movies']} Filme, {$r['series']} Serien, {$r['episodes']} Folgen\n";
if (in_array('--cleanup', $argv, true)) { $c = cleanup_missing(); echo "Entfernt: {$c['movies']} Filme, {$c['episodes']} Folgen, {$c['series']} Serien\n"; }
if (in_array('--tmdb', $argv, true)) {
    [$a, $b] = tmdb_bulk('movie', 200); [$c, $d] = tmdb_bulk('series', 200);
    echo "TMDb: " . ($a + $c) . " gefunden, " . ($b + $d) . " ohne Treffer\n";
}
