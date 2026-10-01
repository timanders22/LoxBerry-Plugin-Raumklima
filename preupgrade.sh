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
# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# Bis 0.11.10 stand hier BASE="${ARGV5:-$LBHOMEDIR}" ohne jede Pruefung - mit leerem BASE hiessen die Pfade /config/plugins/<ordner>. Standen weder das
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
    echo "<WARNING> Es wurde NICHTS gesichert."
    exit 1
fi

# ---------- Die Marke "Aktualisierung laeuft", als ERSTES ----------
#
# Zwischen dem Kopieren der neuen Dateien und postinstall.sh liegt fast eine
# Minute (Regeln/06, am Geraet gemessen), und der Fuenf-Minuten-Takt laeuft
# in dieser Zeit. purge_installation hat data/plugins/<ordner>/ dann schon
# geleert; ein Abruf legte eine frische verlauf.json an, und postinstall.sh
# verwarf daraufhin die Rettung (in WSL gemessen, Pruefung-Raumklima-0.11.11,
# Fall Z1). Solange die Marke gilt, setzt jeder Abruf aus
# (rk_upgrade_laeuft() in webfrontend/html/rk_lib.php); postinstall.sh
# entfernt sie. Sie liegt NEBEN dem Datenordner, sonst loeschte
# purge_installation sie mit. Inhalt: die Unixzeit.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if date +%s > "$MARKE" 2>/dev/null && [ -s "$MARKE" ]; then
    echo "<INFO> Marke gesetzt: der Abruf setzt bis zum Ende der Aktualisierung aus."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen."
    echo "<WARNING> Ein Abruf waehrend der Aktualisierung kann den Verlauf verdraengen."
fi

# ---------- Alte Rettungen aus einem FRUEHEREN Vorgang beiseite ----------
#
# Entscheidung 1 (29.09.2026): preupgrade.sh raeumt einen alten Bestand weg,
# bevor es einen neuen anlegt - bei einem Upgrade wird nie ein Bestand aus
# einem frueheren Vorgang eingespielt. Bis 0.11.13 blieb eine liegengebliebene
# Verlaufsrettung stehen, wenn verlauf.json fehlte oder unlesbar war, und
# postinstall.sh spielte sie mit gueltiger Marke ein - einen fremden Verlauf
# (in WSL gemessen, Bericht installer, Fall D4). Dasselbe galt fuer eine
# liegengebliebene Zugangsdaten-Zweitschrift: hatte der Anwender die
# Zugangsdaten inzwischen geloescht, kamen die alten zurueck. Die Zweitschrift
# der Konfiguration (.backup.json) ist dagegen die LAUFENDE Rueckfallkopie und
# bleibt (AGENTEN_AUFTRAG, Klasse 12).
for ALTB in "$BASE/config/plugins/$PFOLDER.backup.verlauf.json" \
            "$BASE/config/plugins/$PFOLDER.backup.geheim.json"; do
    if [ -e "$ALTB" ] || [ -L "$ALTB" ]; then
        rm -rf "${ALTB:?}.alt" 2>/dev/null
        if mv -f "$ALTB" "$ALTB.alt" 2>/dev/null; then
            [ -f "$ALTB.alt" ] && [ ! -L "$ALTB.alt" ] && chmod 600 "$ALTB.alt" 2>/dev/null
            echo "<WARNING> Eine Rettung aus einem frueheren Vorgang lag noch da und wird nicht eingespielt: $ALTB.alt (die Deinstallation raeumt sie ab)."
        else
            echo "<WARNING> Eine Rettung aus einem frueheren Vorgang liess sich nicht beiseitelegen: $ALTB - bitte von Hand entfernen."
        fi
    fi
done

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
        echo "<WARNING> verlauf.json ist leer oder unlesbar - es wird nichts gesichert;"
        echo "<WARNING> die Messreihen beginnen nach dem Update von vorn."
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
# Dieselbe Wurzel wie oben. Bis 0.11.10 rechnete dieser Block sie ein zweites
# Mal aus $5 und $LBHOMEDIR.
NETZ_BASE="$BASE"
NETZ_PDIR="$PFOLDER"
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
        echo "<INFO> geheim.json traegt keine Zugangsdaten - es gibt nichts zwischenzulegen."
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
