# Xtream-Panel

Ein selbst gehosteter Server mit Xtream-Codes-Schnittstelle (PHP 8.1+, SQLite) und dunkler Admin-Oberfläche.
Er liefert deine Videodateien direkt von der Platte aus (mit Spulen) und kennt Filme, Serien und Live-Kanäle.

## Installation (Debian / Ubuntu)
```
unzip xtream-panel.zip -d /var/www/html/
cd /var/www/html
bash install.sh
```
Das Skript installiert Apache, PHP, SQLite, curl und ffmpeg, aktiviert `mod_rewrite`, erlaubt `.htaccess`
und legt `/opt/vod` und `/opt/series` an. Danach `http://SERVER-IP/admin.php` öffnen und das Admin-Passwort festlegen.
Ohne ffmpeg installieren: `SKIP_FFMPEG=1 bash install.sh`.

## Ordnerstruktur der Videos
```
/opt/vod/Film.mkv                     Kategorie "Filme"
/opt/vod/Action/Film2.mkv             Kategorie "Action"
/opt/series/Serienname/S01E01.mkv     Serie "Serienname"
/opt/series/Serienname/Staffel 2/S02E01.mkv
```
Die Ordner stellst du unter Einstellungen ein. Im Panel auf "Mediathek scannen" klicken (oder `php scan.php`),
damit neue Dateien erscheinen. Automatisch alle 15 Minuten:
```
echo '*/15 * * * * www-data php /var/www/html/scan.php --cleanup' > /etc/cron.d/xtream-scan
```

## Was das Panel kann
- Filme, Serien, Folgen und Live-Kanäle bearbeiten: Titel, Cover (URL oder Upload), Hintergrund, Beschreibung, Bewertung, Genre, Besetzung, Regie, Laufzeit, Trailer
- Infos automatisch von TMDb holen (einzeln, für eine Auswahl oder für alle), Cover aus dem Video erzeugen (ffmpeg)
- Benutzer mit Laufzeit und Verbindungslimit, Zufallszugänge, mehrere auf einmal, Zugangsdaten zum Kopieren
- Downloads per URL direkt in die Ordner, fertige Dateien werden automatisch aufgenommen
- M3U-Import für Live-TV, EPG-Upload, Datenbank-Sicherung, Systemcheck, Anzeige wer gerade schaut

## Im Player eintragen
Server-URL `http://SERVER-IP`, dazu Benutzername und Passwort aus dem Panel (Benutzer, Bearbeiten).
Funktioniert mit Xtream-Codes-fähigen Playern wie TiviMate, IPTV Smarters oder XCIPTV.

## Nginx statt Apache
```
location ~ ^/(live|movie|series)/([^/]+)/([^/]+)/(\d+)(\.\w+)?$ {
    rewrite ^/(live|movie|series)/([^/]+)/([^/]+)/(\d+)(\.\w+)?$ /stream.php?type=$1&u=$2&p=$3&id=$4 last;
}
location ~ ^/(data|covers)/.*\.(php|sqlite.*)$ { deny all; }
```
Dazu PHP-FPM wie gewohnt einrichten und `data/` für den Webserver beschreibbar machen.

## Hinweise
- Hinter dem Internet nur mit HTTPS betreiben, die Zugangsdaten stehen in den Stream-URLs.
- Mit "Löschen" im Panel werden nur Einträge entfernt, nie die Videodateien.
- Beim ersten Aufruf von `admin.php` legt die erste Person das Passwort fest. Rufe die Seite deshalb direkt nach der Installation selbst auf.
