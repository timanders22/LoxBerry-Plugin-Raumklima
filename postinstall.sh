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

# ---------- Eine Altlast von 0.11.0 wegraeumen ----------
#
# Bis 0.11.0 lag im Archiv ein VERZEICHNIS dpkg/apt/ mit php-curl.list darin.
# plugininstall.pl oeffnet aber $tempfolder/dpkg/apt als DATEI; bei einem
# Verzeichnis scheitert das. Gemessen am 29.08.2026 in einem echten
# Installationsprotokoll:
#
#   <INFO> Installing additional software packages.
#   <ERROR> Cannot open APT file.
#   ...
#   0 upgraded, 0 newly installed, 0 to remove
#   <OK> Packages  successfully installed      <- die Liste kam LEER an
#
# php-curl wurde also nie ueber diesen Weg installiert. Seit 0.11.1 ist
# dpkg/apt eine Datei. Auf einer Anlage, die 0.11.0 gesehen hat, steht unter
# data/system/install/<ordner>/dpkg/apt aber noch das alte VERZEICHNIS - und
# ein cp der neuen Datei dorthin legte sie INNEN ab (dpkg/apt/apt), statt sie
# zu ersetzen. Der Fehler bliebe damit ueber das Update hinweg bestehen.
#
# Die Reihenfolge traegt: der Installer sichert die dpkg-Dateien NACH
# postinstall (im Protokoll 38.262 gegen 38.749). Hier ist also der letzte
# Zeitpunkt, an dem der Platz noch frei geraeumt werden kann.
#
# Dieselbe Klasse wie cron/cron.XXmin: LoxBerry erwartet an dieser Stelle
# eine Datei, und ein Verzeichnis macht daraus einen stillen Ausfall.
ALT_APT="$BASE/data/system/install/$PFOLDER/dpkg/apt"
if [ -d "$ALT_APT" ]; then
    if rm -rf "$ALT_APT"; then
        echo "<OK> Altes dpkg/apt-Verzeichnis aus 0.11.0 entfernt - die"
        echo "<OK> Paketliste kommt ab jetzt an."
    else
        echo "<WARNING> Das alte Verzeichnis $ALT_APT liess sich nicht entfernen."
        echo "<WARNING> php-curl wird dann weiterhin nicht ueber apt nachinstalliert."
    fi
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

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-raumklima}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2; zweit=$3
    ziel="$NETZ_CFG/$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
# EIN Name fuer die Konfigurationssicherung, nicht drei.
#
# Bis 0.10.1 gab es .backup.json (aus dem ersten Block von preupgrade.sh und
# aus rk_config_speichern()) UND .backup.raumklima.json (aus dem angehaengten
# Block). Gelesen hat rk_config() nur den ersten, zurueckgespielt hat
# postinstall.sh nur den zweiten. Zwei Sicherungsverfahren sind eines zu
# viel; preupgrade.sh fuehrt eine vorhandene alte Datei jetzt zusammen.
netz_zurueck "raumklima.json" \
    "ca3d163bab055381827226140568f3bef7eaac187cebd76878e0b63e9e442356" \
    "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json"


# Zurueckspielen fuer Dateien OHNE mitgelieferte Vorgabe: es gibt nichts,
# womit man vergleichen koennte, also ist das Kriterium "fehlt oder leer".
# Eine vorhandene Datei wird nie ueberschrieben.
netz_ohne_vorgabe() {
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -f "$zweit" ] || return 0
    if [ ! -s "$ziel" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            chmod 0600 "$ziel" 2>/dev/null
            echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
        fi
    fi
}
netz_ohne_vorgabe "geheim.json"

exit 0
