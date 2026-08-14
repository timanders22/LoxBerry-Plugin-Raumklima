<?php
/* ---- Sperre gegen Parallellaeufe (Muster fer_sperre, FerienFeiertage) ----
 *
 * Der Abruf der Messwerte wartet auf ein Netz. Dauert der Lauf laenger als der Cron-Takt,
 * startet der naechste, waehrend dieser noch laeuft: doppelte Abrufe,
 * doppelte Meldungen, im schlimmsten Fall zwei Schreibvorgaenge auf dieselbe
 * Datei. Die Sperre ist nicht blockierend - wer nicht drankommt, geht
 * kommentarlos wieder (der naechste Takt kommt ohnehin gleich).
 */
$rk_sperrdatei = sys_get_temp_dir() . '/rk_cron.lock';
$rk_sperre = @fopen($rk_sperrdatei, 'c');
if ($rk_sperre === false || !flock($rk_sperre, LOCK_EX | LOCK_NB)) {
    exit(0);
}

/**
 * Raumklima - der regelmaessige Abruf
 *
 * Wird von cron.05min aufgerufen. Holt die Quellen, rechnet und
 * veroeffentlicht ueber MQTT. Der eingestellte Takt entscheidet, ob wirklich
 * geholt wird - rk_abrufen(false) kehrt vorher um.
 *
 * Bewusst OHNE Shebang: aufgerufen wird ausdruecklich ueber php. Ein Shebang
 * verspraeche Ausfuehrbarkeit, die nach einem misslungenen Update fehlen kann.
 *
 * Aufrufe:
 *   raumklima_abruf.php              regulaer, Takt wird beachtet
 *   raumklima_abruf.php --sofort     Takt uebergehen
 *   raumklima_abruf.php --selbsttest nur rechnen, nichts holen
 */

$rk_lib = null;
foreach (array(
    getenv('LBHOMEDIR') . '/webfrontend/html/plugins/' . basename(__DIR__) . '/rk_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/rk_lib.php',
    dirname(__DIR__) . '/webfrontend/html/rk_lib.php',
) as $rk_k) {
    if (is_file($rk_k)) { $rk_lib = $rk_k; break; }
}
if ($rk_lib === null) {
    fwrite(STDERR, "rk_lib.php wurde nicht gefunden.\n");
    exit(2);
}
require_once $rk_lib;

$rk_argv = isset($argv) ? $argv : array();

if (in_array('--selbsttest', $rk_argv, true)) {
    list($rk_n, $rk_f, $rk_text) = rk_selbsttest();
    echo $rk_text, "\n";
    exit($rk_f > 0 ? 1 : 0);
}

$rk_stand = rk_abrufen(in_array('--sofort', $rk_argv, true));

if (!empty($rk_stand['meldungen'])) {
    /* Ueber die gebremste Meldung im Protokoll - eine Quelle, die eine Woche
     * lang schweigt, soll das Protokoll nicht mit 2016 gleichen Zeilen
     * fuellen. Das erledigt rk_abrufen() bereits; hier bleibt der
     * Rueckgabewert fuer den Aufrufer. */
    exit(1);
}
exit(0);
