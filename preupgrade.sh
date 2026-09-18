#!/bin/bash
# Raumklima - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers ist:
#   preupgrade -> config/* aus dem Archiv ueber config/plugins/<ordner>
#              -> postinstall -> postupgrade -> Cleaning
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das auf dem LoxBerry
# fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# sechsten Argument. Deshalb wird hier ausschliesslich mit $3 und $5
# gearbeitet.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-raumklima}"
BASE="${ARGV5:-$LBHOMEDIR}"

# ---------- INHALT statt GROESSE ----------
#
# Eine ABGESCHNITTENE Datei ist nicht leer. Sie besteht jede Groessenpruefung
# (`[ -s ]`, `[ -f ]`, Textvergleich auf `{}`) und gilt damit als brauchbar -
# obwohl von den Einstellungen nichts mehr uebrig ist.
#
# Gemessen am 18.09.2026 (Bestand-2026-09-18/klasse-C, Fall 5): eine um 34 Byte
# abgeschnittene geheim.json bestand `[ -s ]`, postupgrade.sh:36 loeschte
# daraufhin die HEILE Zweitschrift, und danach trug keine Datei mehr die
# Zugangsdaten - das Protokoll meldete dabei
# "<OK> Zwischengelegte Zugangsdaten wieder entfernt."
#
# Gefragt wird deshalb nach dem Inhalt: ein lesbares JSON-Objekt, und bei den
# Zugangsdaten zusaetzlich, ob ueberhaupt ein Geheimnis darin steht.
# Vorbild: Intercom 2.2.12 `cf_mit_inhalt()`, GardenaSmartSystem 1.2.10
# `json_heil()`. Die Funktion steht in allen drei Hakenskripten wortgleich -
# ein Hakenskript kann sich nichts aus dem Plugin-Ordner holen, den es
# gerade erst auspackt.
#
# Beim Aktionstoken wird AUSDRUECKLICH nicht gefragt: rk_lib.php:1002 erzeugt
# ihn erst beim ersten auslesenden Aufruf. Eine Konfiguration ohne Token ist
# deshalb keine leere Konfiguration, und sie darf nicht weggeworfen werden.
#
# Rueckgabe: 0 = traegt Inhalt, 1 = traegt keinen, 2 = NICHT PRUEFBAR.
# Bei 2 (kein php) wird nichts ueberschrieben und nichts geloescht: ein
# Schutz faellt geschlossen aus (CLAUDE.md, 4).
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

# Gesichert wird nur, was auch INHALT hat.
#
# Bis 0.11.2 stand hier nur `[ -f "$CF" ]`. Steht raumklima.json auf der
# mitgelieferten Vorgabe `{}` - genau das schreibt postinstall.sh, wenn
# keine Datei da ist -, kopierte diese Zeile die Vorgabe ueber die GUTE
# Zweitschrift daneben und meldete "gesichert". Der letzte Rueckweg war
# damit weg. Gemessen am 05.09.2026: Zweitschrift vorher mit Raeumen und
# Aktionstoken, nachher `{}`.
#
# Bis 0.11.9 blieb die zweite Luecke offen: eine ABGESCHNITTENE Datei ist
# weder leer noch `{}` und ging durch. Gemessen am 18.09.2026 (Fall 7 des
# eigenen Pruefstands): die heile Zweitschrift wurde verdraengt.
CF="$BASE/config/plugins/$PFOLDER/raumklima.json"
ZW="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -e "$CF" ]; then
    rk_inhalt "$CF" conf
    case "$?" in
    0)
        if cp -p "$CF" "$ZW" 2>/dev/null; then
            chmod 600 "$ZW" 2>/dev/null
            echo "<OK> Konfiguration gesichert."
        else
            echo "<WARNING> Die Zweitschrift der Konfiguration liess sich NICHT"
            echo "<WARNING> anlegen: $ZW"
        fi
        ;;
    1)
        if [ -s "$ZW" ]; then
            echo "<WARNING> raumklima.json ist leer oder unlesbar. Die vorhandene"
            echo "<WARNING> Zweitschrift bleibt deshalb unveraendert:"
            echo "<WARNING>   $ZW"
        else
            echo "<INFO> raumklima.json traegt keine Einstellungen - es gibt"
            echo "<INFO> nichts zu sichern."
        fi
        ;;
    *)
        echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Die vorhandene Zweitschrift bleibt unveraendert."
        ;;
    esac
fi

# Der Verlaufsspeicher (B7).
#
# data/plugins/<ordner>/ raeumt der Installer beim Upgrade vollstaendig ab
# (purge_installation, Aufrufstelle im Upgrade-Zweig). Bis 0.11.2 sicherte
# ihn niemand: nach JEDEM Update standen Nassstunden, Lueftungserfolg und
# Feuchteeintrag wieder auf null - lautlos, ohne eine Zeile. Genau die drei
# Fragen, die die README als wichtiger bezeichnet als jede Momentaufnahme.
# Die Datei traegt keine Zugangsdaten, aber Raumnamen; deshalb 600 und ein
# Eintrag in uninstall.
VL="$BASE/data/plugins/$PFOLDER/verlauf.json"
VLZ="$BASE/config/plugins/$PFOLDER.backup.verlauf.json"
if [ -e "$VL" ]; then
    rk_inhalt "$VL" verlauf
    case "$?" in
    0)
        if cp -p "$VL" "$VLZ" 2>/dev/null; then
            chmod 600 "$VLZ" 2>/dev/null
            echo "<OK> Verlaufsspeicher gesichert."
        else
            echo "<WARNING> Der Verlaufsspeicher liess sich NICHT sichern: $VLZ"
        fi
        ;;
    1)
        if [ -s "$VLZ" ]; then
            echo "<WARNING> verlauf.json ist leer oder unlesbar. Die vorhandene"
            echo "<WARNING> Sicherung der Messreihen bleibt unveraendert:"
            echo "<WARNING>   $VLZ"
        fi
        ;;
    *)
        echo "<WARNING> verlauf.json liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Eine vorhandene Sicherung bleibt unveraendert."
        ;;
    esac
fi
echo "<OK> preupgrade abgeschlossen."

# ==== NICHT MITGELIEFERTE DATEIEN - und gerade deshalb die wichtigen ====
#
# Das Archiv liefert geheim.json nie, also stand sie auf keiner aus dem
# Archivinhalt abgeleiteten Liste. Geloescht wird sie vom Installer
# trotzdem: er kopiert config/* aus dem Archiv ueber config/plugins/<ordner>
# (plugininstall.pl, cp -r ohne -n).
#
# BIS 0.10.1 STAND HIER DAS GEGENTEIL. Der Kommentar sagte, die Zugangsdaten
# wuerden "bewusst NICHT neben den Ordner gesichert", und der Block darunter
# tat genau das. Am 28.08.2026 durchgespielt: nach Update und Deinstallation
# lag
#     config/plugins/raumklima.backup.geheim.json
#     {"benutzer":"loxadmin","passwort":"SehrGeheim123"}
# im Klartext da, und uninstall meldete "Zugangsdaten geloescht".
#
# Aufgeloest ist der Widerspruch jetzt in die andere Richtung: die
# Zweitschrift WIRD angelegt, denn ohne sie verliert jedes Update die
# Zugangsdaten - aber sie lebt nur, solange das Update laeuft.
# postupgrade.sh raeumt sie unmittelbar danach weg, und uninstall/uninstall
# loescht sie in jedem Fall.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-raumklima}"
if [ -z "$NETZ_BASE" ] || [ ! -d "$NETZ_BASE" ]; then NETZ_BASE="$BASE"; fi
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
GZ="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json"
# Auch hier: eine abgeschnittene geheim.json besteht `[ -s ]` und verdraengte
# bis 0.11.9 die heile Zweitschrift. Gemessen am 18.09.2026 (Fall 3 des
# eigenen Pruefstands): Merktoken vorher da, nachher weg.
if [ -e "$NETZ_CFG/geheim.json" ]; then
    rk_inhalt "$NETZ_CFG/geheim.json" geheim
    case "$?" in
    0)
        if cp -p "$NETZ_CFG/geheim.json" "$GZ" 2>/dev/null; then
            chmod 0600 "$GZ" 2>/dev/null
            echo "<INFO> Zugangsdaten fuer die Dauer des Updates zwischengelegt."
        else
            echo "<WARNING> Die Zugangsdaten liessen sich NICHT zwischenlegen."
            echo "<WARNING> Sie koennen dieses Update nicht ueberstehen - bitte"
            echo "<WARNING> $NETZ_CFG/geheim.json vorher von Hand sichern."
        fi
        ;;
    1)
        if [ -s "$GZ" ]; then
            echo "<WARNING> geheim.json traegt keine Zugangsdaten mehr. Die"
            echo "<WARNING> vorhandene Zweitschrift bleibt deshalb unveraendert:"
            echo "<WARNING>   $GZ"
        fi
        ;;
    *)
        echo "<WARNING> geheim.json liess sich nicht pruefen (fehlt php?)."
        echo "<WARNING> Eine vorhandene Zweitschrift bleibt unveraendert."
        ;;
    esac
fi

# Eine ALTE Zweitschrift aus 0.10.x aufraeumen: bis dahin gab es einen
# dritten Namen fuer dieselbe Sache. Zwei Sicherungsverfahren sind eines zu
# viel, drei sind zwei zu viel.
#
# Bis 0.11.9 entschied `[ ! -s ]` ueber das Zusammenfuehren, und das `rm -f`
# fiel danach UNBEDINGT. Eine abgeschnittene .backup.json galt damit als
# "schon da", die alte heile Datei wurde nicht uebernommen und trotzdem
# geloescht. Gemessen am 18.09.2026 (Fall 6 des eigenen Pruefstands):
# Merktoken danach nirgends mehr heil. Geloescht wird jetzt erst, wenn der
# Inhalt nachweislich anderswo steht.
ALT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.raumklima.json"
NEU="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json"
if [ -f "$ALT" ]; then
    rk_inhalt "$NEU" conf
    if [ "$?" = 0 ]; then
        rm -f "$ALT"
        echo "<INFO> Alte dritte Zweitschrift entfernt - die aktuelle traegt Inhalt."
    else
        rk_inhalt "$ALT" conf
        if [ "$?" = 0 ]; then
            if cp -p "$ALT" "$NEU" 2>/dev/null; then
                chmod 0600 "$NEU" 2>/dev/null
                rm -f "$ALT"
                echo "<OK> Alte dritte Zweitschrift zusammengefuehrt und entfernt."
            else
                echo "<WARNING> Die alte dritte Zweitschrift liess sich nicht"
                echo "<WARNING> zusammenfuehren. Sie bleibt liegen: $ALT"
            fi
        else
            echo "<WARNING> Weder $NEU noch $ALT tragen lesbare Einstellungen."
            echo "<WARNING> Beide bleiben unveraendert liegen."
        fi
    fi
fi

exit 0
