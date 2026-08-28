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

CF="$BASE/config/plugins/$PFOLDER/raumklima.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json" \
        && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null \
        && echo "<OK> Konfiguration gesichert."
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
