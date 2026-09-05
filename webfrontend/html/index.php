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
 *   ?token=...&aktion=raum&nr=3  Nur ein Raum (als JSON)
 *
 * Jede andere Aktion wird mit 400 abgewiesen, BEVOR etwas geholt oder
 * geschrieben wird.
 */

/* Ein Fehler DARF NICHT wie eine gesunde Antwort aussehen.
 *
 * Bis 0.11.2 setzte diese Datei weder error_reporting noch
 * display_errors und fing nichts ab. Starb der Lauf an der
 * max_execution_time des Webarbeiters, antwortete der Endpunkt mit
 * HTTP 200 und ohne die Zeile RAUMKLIMA;... - kein Suchtext griff, jeder
 * virtuelle Eingang behielt seinen letzten Wert, und in Loxone sah der
 * Stillstand aus wie ein ruhiges Haus. Mit display_errors=On stand
 * zusaetzlich der absolute Pfad der Bibliothek unangemeldet im Netz.
 *
 * Deshalb: Ausgabe puffern, am Ende in einem Zug hinaus - und bei einem
 * fatalen Fehler den Puffer verwerfen und mit 500 und einer Zeile
 * antworten, die Loxone von einer gesunden unterscheiden kann. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '0');

require_once __DIR__ . '/rk_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

ob_start();
register_shutdown_function(function () {
    $l = error_get_last();
    if (!$l || !in_array($l['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR,
                                           E_COMPILE_ERROR, E_USER_ERROR), true)) {
        return;
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { header('HTTP/1.1 500 Internal Server Error'); }
    echo "FEHLER;GRUND=INTERN\n";
});

/* rk_token_lesen() statt rk_token(): hier wird nur gelesen. Ein Aufruf ohne
 * Anmeldung soll die Konfiguration nicht anfassen. Ist noch kein Wortzeichen
 * eingetragen, wird abgewiesen - der leere Fall MUSS vor hash_equals
 * abgefangen werden, denn hash_equals('', '') ist true. */
/* is_string davor: ?token[]=x macht aus dem Wert ein Feld, und (string)
 * darauf ist unter 7.4 eine Notiz und unter 8 eine Warnung - im
 * Prueflauf sichtbar, im Betrieb ein Eintrag im Fehlerprotokoll. */
$token = (isset($_GET['token']) && is_string($_GET['token']))
    ? $_GET['token'] : '';
$soll = rk_token_lesen();
if ($soll === '' || !hash_equals($soll, $token)) {
    header('HTTP/1.1 403 Forbidden');
    echo "FEHLER;GRUND=TOKEN\n";
    exit;
}

/* Eine Aktion, die kein Text ist (?aktion[]=x), wird ABGEWIESEN und nicht
 * still auf 'status' zurechtgebogen. Fehlt sie ganz, ist 'status' die
 * Vorgabe - das ist etwas anderes als eine unbrauchbare Angabe. */
if (isset($_GET['aktion']) && !is_string($_GET['aktion'])) {
    header('HTTP/1.1 400 Bad Request');
    echo "FEHLER;GRUND=AKTION_UNBEKANNT
";
    exit;
}
$aktion = isset($_GET['aktion']) ? strtolower($_GET['aktion']) : 'status';

/* DIE AKTION WIRD GEPRUEFT, BEVOR GEARBEITET WIRD.
 *
 * Bis 0.11.2 stand diese Pruefung ganz unten, hinter dem Abruf. Ein
 * Aufruf mit unbekannter Aktion holte deshalb zuerst alle Quellen,
 * schrieb stand.json und verlauf.json und veroeffentlichte ueber MQTT -
 * und antwortete danach mit 400 "unbekannt". Gemessen am 05.09.2026:
 * derselbe Aufruf machte genauso viele Netzabrufe wie ein gueltiger.
 * Ein Tippfehler in der Adresse loeste damit beliebig oft einen vollen
 * Lauf aus. */
if (!in_array($aktion, array('status', 'json', 'abrufen', 'raum'), true)) {
    header('HTTP/1.1 400 Bad Request');
    echo "FEHLER;GRUND=AKTION_UNBEKANNT\n";
    exit;
}

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

/* Hier stand bis 0.11.2 die Aktionspruefung. Sie ist nach oben gewandert;
 * an dieser Stelle waere sie jetzt ein toter Zweig - und ein toter Zweig
 * ist schlimmer als ein fehlender, weil er erledigt aussieht. */
echo rk_zeile($stand);
