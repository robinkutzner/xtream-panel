#!/bin/bash
# Installation für Debian / Ubuntu (als root ausführen):  bash install.sh
set -e
if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausführen." >&2; exit 1; fi
DIR="$(cd "$(dirname "$0")" && pwd)"
export DEBIAN_FRONTEND=noninteractive

echo "==> Pakete installieren (Apache, PHP, SQLite, curl)"
apt-get update -qq
apt-get install -y apache2 php libapache2-mod-php php-sqlite3 php-curl curl unzip
if [ "${SKIP_FFMPEG:-0}" != "1" ]; then
  echo "==> ffmpeg installieren (Laufzeit auslesen, Cover aus Video). Überspringen mit SKIP_FFMPEG=1"
  apt-get install -y ffmpeg
fi

echo "==> Apache einrichten (mod_rewrite und .htaccess erlauben für $DIR)"
a2enmod rewrite >/dev/null
cat > /etc/apache2/conf-available/xtream-panel.conf <<CONF
<Directory $DIR>
    AllowOverride All
    Require all granted
</Directory>
CONF
a2enconf xtream-panel >/dev/null

echo "==> Ordner und Rechte"
mkdir -p /opt/vod /opt/series "$DIR/data" "$DIR/covers"
chown -R www-data:www-data "$DIR/data" "$DIR/covers" /opt/vod /opt/series
chmod -R u+rwX,go+rX /opt/vod /opt/series

if [ "$DIR" = "/var/www/html" ] && [ -f /var/www/html/index.html ] && grep -q "Apache2 Debian Default Page" /var/www/html/index.html; then
  rm -f /var/www/html/index.html
fi

systemctl restart apache2

IP="$(hostname -I | awk '{print $1}')"
REL="${DIR#/var/www/html}"
echo
echo "Fertig. Öffne im Browser:  http://$IP$REL/admin.php"
echo "Beim ersten Aufruf legst du dein Admin-Passwort fest."
echo "Filme kommen nach /opt/vod, Serien nach /opt/series."
