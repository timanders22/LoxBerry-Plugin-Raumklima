<?php
/**
 * Raumklima - der Endpunkt fuer den Miniserver
 *
 * Ohne LoxBerry-Anmeldung erreichbar, deshalb durch ein Wortzeichen
 * geschuetzt. Verglichen wird mit hash_equals: ein normaler Vergleich
 * bricht beim ersten falschen Zeichen ab, und aus der Antwortzeit laesst
 * sich das Zeichen erraten.
 *
 * Aufrufe:
 *   ?token=...&aktion=status     Alle Werte als Textzeilen (die Vorlage)
 *   ?token=...&aktion=json       Dasselbe als JSON
 *   ?token=...&aktion=abrufen    Sofort neu holen und ausgeben
 *   ?token=...&aktion=raum&nr=3  Nur ein Raum
 */

require_once __DIR__ . '/rk_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/* rk_token_lesen() statt rk_token(): hier wird nur gelesen. Ein Aufruf ohne
 * Anmeldung soll die Konfiguration nicht anfassen. Ist noch kein Wortzeichen
 * eingetragen, wird abgewiesen - der leere Fall MUSS vor hash_equals
 * abgefangen werden, denn hash_equals('', '') ist true. */
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$soll = rk_token_lesen();
if ($soll === '' || !hash_equals($soll, $token)) {
    header('HTTP/1.1 403 Forbidden');
    echo "FEHLER;GRUND=TOKEN\n";
    exit;
}

$aktion = isset($_GET['aktion']) ? strtolower((string) $_GET['aktion']) : 'status';

if ($aktion === 'abrufen') {
    $stand = rk_abrufen(true);
    $aktion = 'status';
} else {
    $stand = rk_stand();
    if (!$stand) { $stand = rk_abrufen(false); }
}

if ($aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($aktion === 'raum') {
    $nr = isset($_GET['nr']) ? (int) $_GET['nr'] : 0;
    if (!isset($stand['raeume'][$nr])) {
        header('HTTP/1.1 404 Not Found');
        echo "FEHLER;GRUND=RAUM_UNBEKANNT\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stand['raeume'][$nr], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($aktion !== 'status') {
    header('HTTP/1.1 400 Bad Request');
    echo "FEHLER;GRUND=AKTION_UNBEKANNT\n";
    exit;
}

echo rk_zeile($stand);
