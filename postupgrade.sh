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

# Die zwischengelegten Zugangsdaten wieder wegraeumen.
#
# preupgrade.sh legt geheim.json neben den Konfigurationsordner, weil der
# Installer den Ordner ausraeumt; postinstall.sh hat sie inzwischen
# zurueckgespielt. Bis 0.10.1 blieb die Datei danach LIEGEN - im Klartext,
# ueber die ganze Laufzeit und ueber die Deinstallation hinaus. Gemessen am
# 28.08.2026.
#
# Aufgeraeumt wird nur, wenn die echte Datei wieder dasteht. Sonst waere die
# Zweitschrift das Einzige, was von den Zugangsdaten noch uebrig ist.
GEHEIM="$BASE/config/plugins/$PFOLDER/geheim.json"
ZWEIT="$BASE/config/plugins/$PFOLDER.backup.geheim.json"
if [ -f "$ZWEIT" ]; then
    if [ -s "$GEHEIM" ]; then
        rm -f "$ZWEIT" \
            && echo "<OK> Zwischengelegte Zugangsdaten wieder entfernt."
    else
        echo "<WARNING> geheim.json fehlt nach dem Update. Die Zweitschrift bleibt"
        echo "<WARNING> vorerst unter $ZWEIT liegen - bitte von Hand nach"
        echo "<WARNING> $GEHEIM kopieren und die Zweitschrift danach loeschen."
    fi
fi

echo "<OK> postupgrade abgeschlossen - beim naechsten Lauf wird frisch geholt."
exit 0
