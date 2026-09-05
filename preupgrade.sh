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

# Gesichert wird nur, was auch INHALT hat.
#
# Bis 0.11.2 stand hier nur `[ -f "$CF" ]`. Steht raumklima.json auf der
# mitgelieferten Vorgabe `{}` - genau das schreibt postinstall.sh, wenn
# keine Datei da ist -, kopierte diese Zeile die Vorgabe ueber die GUTE
# Zweitschrift daneben und meldete "gesichert". Der letzte Rueckweg war
# damit weg. Gemessen am 05.09.2026: Zweitschrift vorher mit Raeumen und
# Aktionstoken, nachher `{}`.
CF="$BASE/config/plugins/$PFOLDER/raumklima.json"
if [ -f "$CF" ]; then
    INHALT=$(tr -d ' \t\r\n' < "$CF")
    if [ "$INHALT" = "{}" ] || [ -z "$INHALT" ]; then
        echo "<INFO> raumklima.json ist leer - die vorhandene Zweitschrift"
        echo "<INFO> bleibt unangetastet."
    else
        cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json" \
            && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null \
            && echo "<OK> Konfiguration gesichert."
    fi
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
if [ -s "$VL" ]; then
    cp -p "$VL" "$BASE/config/plugins/$PFOLDER.backup.verlauf.json" \
        && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.verlauf.json" 2>/dev/null \
        && echo "<OK> Verlaufsspeicher gesichert."
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
if [ -s "$NETZ_CFG/geheim.json" ]; then
    if cp -p "$NETZ_CFG/geheim.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" 2>/dev/null; then
        chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" 2>/dev/null
        echo "<INFO> Zugangsdaten fuer die Dauer des Updates zwischengelegt."
    fi
fi

# Eine ALTE Zweitschrift aus 0.10.x aufraeumen: bis dahin gab es einen
# dritten Namen fuer dieselbe Sache. Zwei Sicherungsverfahren sind eines zu
# viel, drei sind zwei zu viel.
if [ -f "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.raumklima.json" ]; then
    if [ ! -s "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json" ]; then
        cp -p "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.raumklima.json" \
              "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json" 2>/dev/null
        chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json" 2>/dev/null
    fi
    rm -f "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.raumklima.json"
    echo "<INFO> Alte dritte Zweitschrift zusammengefuehrt und entfernt."
fi

exit 0
