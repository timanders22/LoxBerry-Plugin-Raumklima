#!/bin/bash

# Raumklima - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu im Durchgang 01.10.2026 (X-1, Entscheidung 1 vom 29.09.2026, Bauart F).
# Bauform LoxBerry-Plugin-Abfahrtsassistent-1.6.19/preinstall.sh. Der Installer
# ruft dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung
# und VOR dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschrift und die
# Rettungen braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften einer
# frueheren Installation gehen nach <name>.alt, gemeldet mit genau einer
# <WARNING>: die Konfiguration (.backup.json, samt Aktionstoken), die
# Zugangsdaten (.backup.geheim.json, im Klartext), der dritte Name aus 0.10.x
# (.backup.raumklima.json) und die Verlaufsrettung (.backup.verlauf.json).
# Bis 0.11.13 spielte postinstall.sh sie ein, und schon der erste Takt in der
# Kopierluecke heilte die alte Konfiguration ueber die Bibliothek ein - eine
# frische Installation trug danach Raeume, Heimnetzadressen, das ALTE
# Aktionstoken und das alte Kennwort (in WSL gemessen, Bericht installer,
# Faelle D, D2, D3, D4). Die Selbstheilung der Bibliothek liest .alt nie; die
# Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-raumklima}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in preupgrade.sh, postinstall.sh und postupgrade.sh: ohne
# config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06, der Vorfall dieser Linie vom 05.09.2026).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BEISEITE=""
FEST=""
for ZIEL in \
    "$BASE/config/plugins/$PFOLDER.backup.json" \
    "$BASE/config/plugins/$PFOLDER.backup.geheim.json" \
    "$BASE/config/plugins/$PFOLDER.backup.raumklima.json" \
    "$BASE/config/plugins/$PFOLDER.backup.verlauf.json"
do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    RK_TEXT="<WARNING> Neuinstallation: Einstellungen, Zugangsdaten und Verlauf einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && RK_TEXT="$RK_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && RK_TEXT="$RK_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$RK_TEXT"
fi
exit 0
