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
echo "<OK> postupgrade abgeschlossen - beim naechsten Lauf wird frisch geholt."
exit 0
