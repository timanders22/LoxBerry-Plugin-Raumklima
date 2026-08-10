#!/bin/bash
# Raumklima - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar sein,
# ohne Schaden anzurichten.
#
# Das Plugin ist reines PHP: keine virtuelle Python-Umgebung, keine
# Paketinstallation an dieser Stelle. Das einzige Paket (php-curl) steht in
# dpkg/apt - dort installiert es LoxBerry mit den noetigen Rechten. Ein
# "apt-get install" hier koennte gar nicht gelingen: postinstall.sh laeuft als
# Benutzer loxberry, apt braucht root.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-raumklima}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
# Im Konfigurationsordner koennen Zugangsdaten fuer eine Quelle liegen.
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/raumklima.json" ] || echo '{}' > "$PCONFIG/raumklima.json"
chmod 600 "$PCONFIG/raumklima.json" 2>/dev/null
[ -f "$PCONFIG/geheim.json" ] && chmod 600 "$PCONFIG/geheim.json" 2>/dev/null

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/raumklima.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. LoxBerry bringt PHP normalerweise mit -"
    echo "<FAIL> ohne PHP laeuft weder die Oberflaeche noch der Abruf."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- curl pruefen ----------
# Fehlt es, wird ueber Datenstroeme geholt. Das funktioniert, ist aber
# genuegsamer bei Zeitueberschreitungen - deshalb der Hinweis.
if php -r 'exit(function_exists("curl_init") ? 0 : 1);' >/dev/null 2>&1; then
    echo "<OK> Die PHP-Erweiterung curl ist geladen."
else
    echo "<INFO> Die PHP-Erweiterung curl fehlt - obwohl php-curl in dpkg/apt steht."
    echo "<INFO> Das Plugin faellt auf Datenstroeme zurueck und laeuft weiter."
    echo "<INFO> Nachholen mit: sudo apt install php-curl"
fi

# ---------- Selbsttest des Rechenkerns ----------
# Ohne Netz: rechnet Taupunkt, absolute Feuchte, Schimmelrisiko und die
# Lueftungsempfehlung gegen hinterlegte Lehrbuchwerte. Schlaegt das fehl,
# stimmt an dieser Installation etwas nicht - dann lieber jetzt melden.
if [ -f "$PBIN/raumklima_abruf.php" ]; then
    if AUS=$(php "$PBIN/raumklima_abruf.php" --selbsttest 2>&1); then
        echo "<OK> Selbsttest des Rechenkerns: $(echo "$AUS" | head -1)"
    else
        echo "<INFO> Der Selbsttest des Rechenkerns ist nicht sauber durchgelaufen:"
        echo "$AUS" | head -20 | sed 's/^/<INFO> /'
    fi
fi

chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Breiten- und Laengengrad eintragen."
echo "<INFO>  2. Die Adresse deiner Sensorquelle eintragen und je Raum die"
echo "<INFO>     beiden Pfade - der Reiter Test zeigt, welche Schluessel es gibt."
echo "<INFO>  3. Speichern, dann 'Jetzt abrufen'."
exit 0
