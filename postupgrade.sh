#!/bin/bash
# Raumklima - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Wuerde dieses Skript es zusaetzlich starten, liefe es ZWEIMAL, mit
# allem, was darin nicht idempotent ist. Es gibt keinen Dauerdienst, der
# wieder anlaufen muesste; der naechste Cron-Lauf holt von selbst.
#
# Was hier bleibt: das zwischengespeicherte Abbild verwerfen. Aendert sich
# der Aufbau von stand.json zwischen zwei Fassungen, zeigte die Oberflaeche
# sonst bis zum naechsten Abruf alte Felder.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-raumklima}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
rm -f "$BASE/data/plugins/$PFOLDER/stand.json"

# ---------- INHALT statt GROESSE ----------
# Wortgleich zu preupgrade.sh und postinstall.sh; ein Hakenskript kann sich
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

# Die zwischengelegten Zugangsdaten wieder wegraeumen.
#
# preupgrade.sh legt geheim.json neben den Konfigurationsordner, weil der
# Installer den Ordner ausraeumt; postinstall.sh hat sie inzwischen
# zurueckgespielt. Bis 0.10.1 blieb die Datei danach LIEGEN - im Klartext,
# ueber die ganze Laufzeit und ueber die Deinstallation hinaus. Gemessen am
# 28.08.2026.
#
# Aufgeraeumt wird nur, wenn die echte Datei wieder dasteht - und zwar MIT
# den Zugangsdaten darin.
#
# Bis 0.11.9 entschied `[ -s "$GEHEIM" ]`. Eine abgeschnittene geheim.json ist
# nicht leer, bestand die Pruefung und liess das `rm -f` zu. Gemessen am
# 18.09.2026 (Bestand-2026-09-18/klasse-C, Fall 5, und Fall 1 des eigenen
# Pruefstands): die Zweitschrift war danach weg, die Zugangsdaten standen
# nirgends mehr vollstaendig da - und gemeldet wurde
# "<OK> Zwischengelegte Zugangsdaten wieder entfernt."
#
# Gemeldet wird jetzt die WIRKUNG (liegt die Datei wirklich nicht mehr da?),
# nicht der Rueckgabewert von rm (CLAUDE.md, 2).
GEHEIM="$BASE/config/plugins/$PFOLDER/geheim.json"
ZWEIT="$BASE/config/plugins/$PFOLDER.backup.geheim.json"
if [ -f "$ZWEIT" ]; then
    rk_inhalt "$GEHEIM" geheim
    case "$?" in
    0)
        rm -f "$ZWEIT" 2>/dev/null
        if [ -e "$ZWEIT" ]; then
            echo "<WARNING> Die zwischengelegten Zugangsdaten liessen sich NICHT"
            echo "<WARNING> entfernen. Sie liegen weiterhin im Klartext unter"
            echo "<WARNING>   $ZWEIT"
            echo "<WARNING> und sollten von Hand geloescht werden."
        else
            echo "<OK> Zwischengelegte Zugangsdaten wieder entfernt."
        fi
        ;;
    1)
        echo "<WARNING> geheim.json fehlt nach dem Update oder traegt keine"
        echo "<WARNING> Zugangsdaten mehr. Die Zweitschrift bleibt deshalb"
        echo "<WARNING> unter $ZWEIT liegen - bitte von Hand nach"
        echo "<WARNING> $GEHEIM kopieren und die Zweitschrift danach loeschen."
        ;;
    *)
        echo "<WARNING> Der Inhalt von $GEHEIM liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Die Zweitschrift bleibt vorsichtshalber liegen:"
        echo "<WARNING>   $ZWEIT"
        echo "<WARNING> Bitte nachsehen, ob die Zugangsdaten wieder dastehen,"
        echo "<WARNING> und die Zweitschrift danach loeschen."
        ;;
    esac
fi

echo "<OK> postupgrade abgeschlossen - beim naechsten Lauf wird frisch geholt."
exit 0
