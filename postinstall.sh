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
# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# Bis 0.11.10 stand hier BASE="${ARGV5:-$LBHOMEDIR}" und danach der Rueckfall $SELF/../.. -
# zwei Ebenen ueber dem Skript, auch in einem fremden Baum. Standen weder das
# fuenfte Argument noch $LBHOMEDIR, arbeitete das Skript gegen einen Baum, der
# keine LoxBerry-Wurzel ist (in WSL gemessen, Pruefung-Raumklima-0.11.11,
# Faelle W1 bis W4). Eine LoxBerry-Wurzel traegt immer
# config/system/general.json (Regeln/06, der Vorfall dieser Linie vom
# 05.09.2026). Ohne Wurzel: <WARNING>, nichts anlegen, nichts entfernen,
# Rueckgabe ungleich 0. Die Funktion steht in preupgrade.sh, postinstall.sh
# und postupgrade.sh wortgleich; Bauart Skoda-Connect-NG 0.9.24.
rk_wurzel_suchen() {
    rk_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    rk_i=0
    while [ -n "$rk_v" ] && [ "$rk_v" != "/" ] && [ "$rk_i" -lt 8 ]; do
        if [ -d "$rk_v/config/plugins" ] && [ -d "$rk_v/data/plugins" ] \
           && [ -f "$rk_v/config/system/general.json" ]; then
            echo "$rk_v"
            return 0
        fi
        rk_v=$(dirname "$rk_v")
        rk_i=$((rk_i + 1))
    done
    return 1
}
BASE="${ARGV5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(rk_wurzel_suchen) || BASE=""
    fi
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<WARNING> Das Wurzelverzeichnis des LoxBerry liess sich nicht"
    echo "<WARNING> bestimmen: weder das fuenfte Argument noch \$LBHOMEDIR noch"
    echo "<WARNING> der eigene Ablageort fuehrten auf einen Ordner mit"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde NICHTS eingerichtet."
    exit 1
fi

# ---------- Die Marke "Aktualisierung laeuft" ----------
# preupgrade.sh hat sie als Erstes gelegt; solange sie gilt, setzt jeder
# Abruf aus (rk_upgrade_laeuft() in webfrontend/html/rk_lib.php). Hier wird
# sie ausgewertet und auf JEDEM Ausgang wieder entfernt (trap) - das Skript
# steigt an mehreren Stellen mit 'exit 1' aus.
#
# Sie gilt, sobald sie LIEGT - ohne Altersvergleich (Entscheidung 1 vom
# 29.09.2026 und Nr. 8 vom 30.09.2026, Durchgang 01.10.2026). Bis 0.11.13
# zaehlte sie nur bis 3600 s Alter: ein Update mit mehr als einer Stunde
# zwischen preupgrade und postinstall - an der Funkwacht gemessen - oder ein
# Uhrsprung auf einem Pi ohne Echtzeituhr galt dann als Neuinstallation, und
# 30 Tage Stundenreihe, Nassstunden und Lueftungserfolg waren weg (in WSL
# gemessen, Bericht installer, Faelle E und E2). Die 3600 s bleiben nur als
# Startsperre des Abrufs (rk_upgrade_laeuft()). Eine vergessene Marke gilt
# ebenso; wer frisch anfangen will, deinstalliert vorher.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
MARKE_GILT=""
[ -f "$MARKE" ] && MARKE_GILT=ja
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

# ---------- INHALT statt GROESSE ----------
# Wortgleich zu preupgrade.sh und postupgrade.sh; ein Hakenskript kann sich
# nichts aus dem Plugin-Ordner holen. Anlass und Messung stehen in
# preupgrade.sh ueber derselben Funktion.
# Rueckgabe: 0 = traegt Inhalt, 1 = traegt keinen, 2 = NICHT PRUEFBAR.
rk_inhalt() {   # $1 Datei, $2 Art: conf | geheim | verlauf
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || count($d) === 0) { exit(1); }
        $art = isset($argv[2]) ? $argv[2] : "";
        if ($art === "geheim") {
            $b = isset($d["benutzer"]) && is_string($d["benutzer"]) && trim($d["benutzer"]) !== "";
            $w = isset($d["passwort"]) && is_string($d["passwort"]) && $d["passwort"] !== "";
            exit(($b || $w) ? 0 : 1);
        }
        exit(0);
    ' -- "$1" "$2" 2>/dev/null
    rk_rc=$?
    [ "$rk_rc" = 0 ] || [ "$rk_rc" = 1 ] || return 2
    return "$rk_rc"
}

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

# Sicherung zurueckspielen - NUR bei einer Aktualisierung (Marke).
#
# Bis 0.11.13 stand hier "uebersteht Update UND Neuinstallation", und genau
# das war der Befund: eine Neuinstallation spielte die liegengebliebene
# Zweitschrift einer frueheren Installation samt altem Aktionstoken ein
# (Bauart F, Entscheidung 1; in WSL gemessen, Bericht installer, Fall D). Bei
# einer Neuinstallation legt preinstall.sh sie vorher nach .alt; liegt hier
# trotzdem eine, ohne Marke, wird sie nicht eingespielt und genannt.
#
# Bis 0.11.9 entschied `[ ! -s "$CF" ] || [ "$INHALT" = "{}" ]`. Eine
# ABGESCHNITTENE raumklima.json ist weder leer noch `{}`: sie galt als heil,
# es wurde nichts zurueckgespielt, und der Anwender stand ohne Einstellungen
# da. Gemessen am 18.09.2026 (Fall 8 des eigenen Pruefstands).
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/raumklima.json"
if [ -f "$BK" ] && [ -z "$MARKE_GILT" ]; then
    echo "<WARNING> Eine Zweitschrift liegt, aber keine Marke einer laufenden Aktualisierung -"
    echo "<WARNING> sie wird nicht eingespielt: $BK"
elif [ -f "$BK" ]; then
    rk_inhalt "$CF" conf
    case "$?" in
    1)
        rk_inhalt "$BK" conf
        if [ "$?" = 0 ]; then
            if cp -p "$BK" "$CF" 2>/dev/null; then
                chmod 600 "$CF" 2>/dev/null
                echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
            else
                echo "<WARNING> Die Konfiguration liess sich NICHT zurueckspielen."
                echo "<WARNING> Die Sicherung liegt unter $BK."
            fi
        else
            echo "<WARNING> Die Sicherung $BK traegt selbst keine lesbaren"
            echo "<WARNING> Einstellungen. Es wurde nichts zurueckgespielt."
        fi
        ;;
    2)
        echo "<WARNING> raumklima.json liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Es wurde nichts zurueckgespielt und nichts geloescht."
        ;;
    esac
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

# Den Verlaufsspeicher zurueckholen (B7), bevor die Rechte gesetzt werden.
# preupgrade.sh hat ihn neben den Ordner gelegt, weil der Installer den
# Ordner ausraeumt.
#
# Bis 0.11.10 entschied, ob in verlauf.json schon Inhalt steht: dann wurde die
# Rettung geloescht, ohne zurueckgeholt zu werden. Lief der Takt in der Luecke
# des Updates, stand dort eine frische Reihe mit einem Punkt - und der ganze
# Verlauf war weg (in WSL gemessen, Pruefung-Raumklima-0.11.11, Fall Z1).
# Und die Kopie wurde nach Groesse geprueft (`[ -s ]`), nicht nach Inhalt.
#
# Jetzt entscheidet die Marke, ob die Rettung aus DIESEM Vorgang stammt
# (preupgrade.sh legt beide an, Regeln/06 "Eine Sicherung, die NICHT aus
# diesem Vorgang stammt, spielt nichts ein"): mit gueltiger Marke kommt die
# Rettung zurueck, auch ueber eine Reihe aus der Luecke, und sie wird erst
# geloescht, wenn die Kopie byteweise gleich am Ziel steht. Ohne Marke wird
# nichts eingespielt; die Rettung bleibt liegen, und es wird gesagt.
VLZ="$BASE/config/plugins/$PFOLDER.backup.verlauf.json"
VL="$PDATA/verlauf.json"
if [ -f "$VLZ" ]; then
    rk_inhalt "$VLZ" verlauf
    VLZ_RC=$?
    if [ "$VLZ_RC" = 2 ]; then
        echo "<WARNING> verlauf.json liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Die Rettung bleibt liegen: $VLZ"
    elif [ "$VLZ_RC" != 0 ]; then
        echo "<WARNING> Die Rettung des Verlaufsspeichers ist selbst unlesbar."
        echo "<WARNING> Sie bleibt liegen: $VLZ"
    elif [ -z "$MARKE_GILT" ]; then
        echo "<WARNING> Eine Rettung des Verlaufsspeichers liegt, aber keine gueltige"
        echo "<WARNING> Marke einer laufenden Aktualisierung - sie stammt nicht aus"
        echo "<WARNING> diesem Vorgang und wird nicht eingespielt. Sie bleibt liegen:"
        echo "<WARNING>   $VLZ"
    elif cp -p "$VLZ" "$VL" 2>/dev/null && cmp -s "$VLZ" "$VL"; then
        rm -f "$VLZ"
        echo "<OK> Verlaufsspeicher wiederhergestellt."
    else
        echo "<WARNING> Der Verlaufsspeicher liess sich NICHT zurueckholen."
        echo "<WARNING> Die Rettung bleibt liegen: $VLZ"
    fi
fi

chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
# Dieselbe Wurzel wie oben. Bis 0.11.10 rechnete dieser Block sie ein zweites
# Mal aus $5 und $LBHOMEDIR.
NETZ_BASE="$BASE"
NETZ_PDIR="$PFOLDER"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
#
# Die vierte Erkennung seit 0.11.10: der INHALT. Eine abgeschnittene Datei
# fehlt nicht, ist nicht leer und hat auch nicht die Pruefsumme der Vorgabe -
# sie ging durch alle drei bisherigen Pruefungen. Gemessen am 18.09.2026.
netz_zurueck() {
    datei=$1; soll=$2; zweit=$3
    ziel="$NETZ_CFG/$datei"
    [ -f "$zweit" ] || return 0
    rk_inhalt "$ziel" conf
    ziel_rc=$?
    verloren=0
    if [ "$ziel_rc" = 1 ]; then
        verloren=1
    elif [ "$ziel_rc" = 0 ]; then
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    else
        echo "<WARNING> $datei liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Es wurde nichts zurueckgespielt und nichts geloescht."
        return 0
    fi
    if [ "$verloren" = "1" ]; then
        rk_inhalt "$zweit" conf
        if [ "$?" != "0" ]; then
            echo "<WARNING> Die Zweitschrift $zweit traegt selbst keine lesbaren"
            echo "<WARNING> Einstellungen. Es wurde nichts zurueckgespielt."
            return 0
        fi
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            chmod 0600 "$ziel" 2>/dev/null
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
# Nur bei einer Aktualisierung (Marke) - wie der Block oben (Bauart F).
if [ -n "$MARKE_GILT" ]; then
netz_zurueck "raumklima.json" \
    "ca3d163bab055381827226140568f3bef7eaac187cebd76878e0b63e9e442356" \
    "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json"
fi


# Zurueckspielen fuer Dateien OHNE mitgelieferte Vorgabe: es gibt nichts,
# womit man vergleichen koennte, also ist das Kriterium "fehlt oder leer".
# Eine vorhandene Datei wird nie ueberschrieben.
#
# Auch hier entschied bis 0.11.9 `[ ! -s "$ziel" ]`. Eine abgeschnittene
# geheim.json galt als vorhanden, die Zugangsdaten wurden nicht zurueckgeholt -
# und postupgrade.sh loeschte die Zweitschrift unmittelbar danach. Gemessen am
# 18.09.2026 (Fall 10 des eigenen Pruefstands).
netz_ohne_vorgabe() {   # $1 Dateiname, $2 Art fuer rk_inhalt
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -f "$zweit" ] || return 0
    rk_inhalt "$ziel" "$2"
    ziel_rc=$?
    if [ "$ziel_rc" = "2" ]; then
        echo "<WARNING> $1 liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Es wurde nichts zurueckgespielt und nichts geloescht."
        return 0
    fi
    [ "$ziel_rc" = "1" ] || return 0
    rk_inhalt "$zweit" "$2"
    if [ "$?" != "0" ]; then
        echo "<WARNING> Die Zweitschrift $zweit traegt selbst nichts Brauchbares."
        echo "<WARNING> Es wurde nichts zurueckgespielt."
        return 0
    fi
    if cp -p "$zweit" "$ziel" 2>/dev/null; then
        chmod 0600 "$ziel" 2>/dev/null
        echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
    fi
}
# Die Zugangsdaten nur bei einer Aktualisierung (Marke). Bis 0.11.13 holte eine
# Neuinstallation das Kennwort einer frueheren Installation aus einer
# liegengebliebenen Klartext-Zweitschrift, und die Datei blieb danach liegen,
# weil postupgrade.sh bei einer Neuinstallation nicht laeuft (in WSL gemessen,
# Bericht installer, Fall D3).
if [ -n "$MARKE_GILT" ]; then
    netz_ohne_vorgabe "geheim.json" geheim
fi

# ---------- Schlusswort: die Erstanleitung nur ohne Einstellungen ----------
# postinstall.sh laeuft auch bei jedem Update. Bis 0.11.10 riet es danach
# unbedingt zur Ersteinrichtung, auch wenn die Raeume gerade uebernommen
# worden waren (Regeln/06 "Nach einer Aktualisierung darf der Schlusstext
# nicht zur Erstinstallation raten"; Auftrag vom 24.09.2026). Das Schlusswort
# steht deshalb HINTER allem Zurueckspielen, und es entscheidet der Inhalt:
# eingerichtet ist raumklima.json, wenn sie mindestens einen Raum mit Namen
# fuehrt - dasselbe Merkmal, an dem rk_raeume() einen Raum ueberhaupt fuehrt.
# Ein blosses Aktionstoken zaehlt nicht: es entsteht beim ersten Oeffnen der
# Oberflaeche.
rk_eingerichtet() {   # $1 Datei; 0 = eingerichtet
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || !isset($d["raeume"]) || !is_array($d["raeume"])) { exit(1); }
        foreach ($d["raeume"] as $r) {
            if (is_array($r) && isset($r["name"]) && is_string($r["name"])
                && trim($r["name"]) !== "") { exit(0); }
        }
        exit(1);
    ' -- "$1" 2>/dev/null
}
if rk_eingerichtet "$CF"; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen - es ist nichts weiter zu tun."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
    echo "<INFO>  1. Reiter Einstellungen: Breiten- und Laengengrad eintragen."
    echo "<INFO>  2. Die Adresse deiner Sensorquelle eintragen und je Raum die"
    echo "<INFO>     beiden Pfade - der Reiter Test zeigt, welche Schluessel es gibt."
    echo "<INFO>  3. Speichern, dann 'Jetzt abrufen'."
fi

exit 0
