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
 *   ?token=...&aktion=raum&nr=3  Nur ein Raum (als JSON); nr nur aus Ziffern, sonst 400
 *
 * OK wird zur Lesezeit 0, sobald die letzte erfolgreiche Messung aelter ist
 * als das Dreifache des Takts (Entscheidung Nr. 4); ALTER und RALTER werden
 * zur Lesezeit gerechnet (rk_stand_lesezeit()). Jeder Ausgang schreibt eine
 * gebremste Protokollzeile mit dem Anrufer (rk_ep_log()).
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

/* C10 (Durchgang 01.10.2026, Regeln/03): jeder Ausgang dieses Endpunkts
 * schreibt eine Protokollzeile mit der Adresse des Anrufers - Erfolg,
 * Abweisung, Bremse. Bis 0.11.13 blieb das Protokoll nach elf Aufrufen leer
 * (gemessen, Bericht code Nr. 10): "der Miniserver fragt nicht" war von "er
 * wird abgewiesen" nicht zu unterscheiden. Nie das Token, von einem
 * abgewiesenen Wert nur die Laenge. Gebremst je Ausgang und Anrufer, weil der
 * Miniserver alle 300 s fragt; geschrieben nur, wenn der Datenordner schon
 * besteht - ein unangemeldeter Aufruf legt keinen an. */
function rk_ep_log($ausgang, $text, $sekunden)
{
    $p = rk_paths();
    if ($p['home'] === '' || !is_dir($p['datadir'])) { return; }
    $wer = (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']))
        ? preg_replace('/[^0-9A-Fa-f:.]/', '', $_SERVER['REMOTE_ADDR']) : '';
    if ($wer === '') { $wer = '?'; }
    rk_log_gebremst('ep_' . $ausgang . '_' . $wer, 'Endpunkt: ' . $text . ' (Anrufer ' . $wer . ').',
                    $sekunden);
}

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

/* ?selftest=1 beantwortet die Tokenfrage, ohne etwas auszuloesen:
 * kein Abruf, kein Schreiben, kein Geraetekontakt. Die drei Antworten
 * sind Hausstandard (Regeln/07, Regeln/03). Bis 0.11.7 wurde der
 * Parameter stillschweigend uebergangen - gemessen 06.09.2026: HTTP 200
 * mit der normalen Statuszeile. */
if (isset($_GET['selftest'])) {
    if ($soll === '') {
        header('HTTP/1.1 403 Forbidden');
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        rk_ep_log('selftest403', 'Selbsttest, noch kein Wortzeichen eingerichtet', 600);
    } elseif (!hash_equals($soll, $token)) {
        header('HTTP/1.1 403 Forbidden');
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        rk_ep_log('selftest403', 'Selbsttest abgewiesen, falsches Wortzeichen (Laenge '
            . strlen($token) . ')', 600);
    } else {
        echo "SELFTEST;OK=1;TOKEN=OK\n";
        rk_ep_log('selftest', 'Selbsttest bestanden', 3600);
    }
    exit;
}

if ($soll === '' || !hash_equals($soll, $token)) {
    header('HTTP/1.1 403 Forbidden');
    echo "FEHLER;GRUND=TOKEN\n";
    rk_ep_log('403', 'abgewiesen (403), ' . ($soll === '' ? 'noch kein Wortzeichen eingerichtet'
        : 'falsches oder fehlendes Wortzeichen, Laenge ' . strlen($token)), 600);
    exit;
}

/* Eine Aktion, die kein Text ist (?aktion[]=x), wird ABGEWIESEN und nicht
 * still auf 'status' zurechtgebogen. Fehlt sie ganz, ist 'status' die
 * Vorgabe - das ist etwas anderes als eine unbrauchbare Angabe. */
if (isset($_GET['aktion']) && !is_string($_GET['aktion'])) {
    header('HTTP/1.1 400 Bad Request');
    echo "FEHLER;GRUND=AKTION_UNBEKANNT
";
    rk_ep_log('400', 'abgewiesen (400), die Aktion ist keine Zeichenkette', 600);
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
    rk_ep_log('400', 'abgewiesen (400), unbekannte Aktion (Laenge ' . strlen($aktion) . ')', 600);
    exit;
}

if ($aktion === 'abrufen') {
    /* Die Bremse: ein virtueller Ausgang hat keinen Takt. Liegt der letzte
     * Lauf weniger als RK_ABRUF_MINDESTABSTAND zurueck, wird der letzte
     * Stand geliefert - die Antwortzeile bleibt dieselbe, damit Loxone
     * nichts verliert -, und der Grund steht in einer Kopfzeile und
     * einmal je Stunde im Protokoll. */
    $alt = rk_stand();
    $letzt = isset($alt['lauf_ts']) ? (int) $alt['lauf_ts'] : 0;
    $abst = time() - $letzt;
    if ($alt && $letzt > 0 && $abst >= 0 && $abst < RK_ABRUF_MINDESTABSTAND) {
        header('X-Raumklima-Abruf: gebremst, naechster Lauf in '
               . (RK_ABRUF_MINDESTABSTAND - $abst) . ' s');
        rk_ep_log('abruf_gebremst', 'aktion=abrufen kam ' . $abst
            . ' s nach dem letzten Lauf und wurde gebremst (Mindestabstand '
            . RK_ABRUF_MINDESTABSTAND . ' s); geliefert wurde der letzte Stand', 3600);
        $stand = $alt;
    } else {
        $stand = rk_abrufen(true);
        rk_ep_log('abrufen', 'aktion=abrufen, neu geholt', 3600);
    }
    $aktion = 'status';
} else {
    $stand = rk_stand();
    if (!$stand) { $stand = rk_abrufen(false); }
}
/* C1/C2 (Durchgang 01.10.2026): OK und RALTER zur Lesezeit - fuer json und
 * raum hier, fuer die Antwortzeile ebenso in rk_zeile(). */
$stand = rk_stand_lesezeit($stand);

if ($aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    rk_ep_log('json', 'aktion=json beantwortet', 3600);
    exit;
}

if ($aktion === 'raum') {
    /* C11 (Durchgang 01.10.2026, Regeln/03): zuerst is_string, dann nur
     * Ziffern. Bis 0.11.13 lieferten nr[]=9 und nr=1abc beide Raum 1 (HTTP 200)
     * - ein unbrauchbarer Parameter wurde still zu einer Raumnummer. */
    $nr_roh = isset($_GET['nr']) ? $_GET['nr'] : '';
    if (!is_string($nr_roh) || !ctype_digit($nr_roh)) {
        header('HTTP/1.1 400 Bad Request');
        echo "FEHLER;GRUND=NR_UNGUELTIG\n";
        rk_ep_log('400nr', 'abgewiesen (400), Raumnummer unbrauchbar (Laenge '
            . (is_string($nr_roh) ? strlen($nr_roh) : 0) . ')', 600);
        exit;
    }
    $nr = (int) $nr_roh;
    if (!isset($stand['raeume'][$nr])) {
        header('HTTP/1.1 404 Not Found');
        echo "FEHLER;GRUND=RAUM_UNBEKANNT\n";
        rk_ep_log('404', 'Raum ' . $nr . ' unbekannt (404)', 600);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stand['raeume'][$nr], JSON_UNESCAPED_UNICODE);
    rk_ep_log('raum', 'aktion=raum beantwortet', 3600);
    exit;
}

/* Hier stand bis 0.11.2 die Aktionspruefung. Sie ist nach oben gewandert;
 * an dieser Stelle waere sie jetzt ein toter Zweig - und ein toter Zweig
 * ist schlimmer als ein fehlender, weil er erledigt aussieht. */
echo rk_zeile($stand);
rk_ep_log('status', 'aktion=status beantwortet', 3600);
