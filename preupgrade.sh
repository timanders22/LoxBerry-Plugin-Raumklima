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
# Die Datei mit den Zugangsdaten wird bewusst NICHT neben den Ordner
# gesichert: eine Sicherung daneben ueberlebt die Deinstallation, und dort
# stuenden dann Benutzername und Passwort herrenlos herum.
echo "<OK> preupgrade abgeschlossen."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-raumklima}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/raumklima.json" ]; then
    cp -p "$NETZ_CFG/raumklima.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.raumklima.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.raumklima.json" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."


# NICHT MITGELIEFERTE Dateien - und gerade deshalb die wichtigen.
# Das Archiv liefert sie nie, also standen sie bis jetzt auf keiner Liste;
# geloescht werden sie vom Installer trotzdem, samt Token und Zugangsdaten.
if [ -s "$NETZ_CFG/geheim.json" ]; then
    cp -p "$NETZ_CFG/geheim.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" 2>/dev/null
fi

exit 0
