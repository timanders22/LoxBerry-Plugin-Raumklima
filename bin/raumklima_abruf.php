<?php
/* ---- Die Sperre steht seit 0.11.2 NICHT MEHR HIER ----
 *
 * Sie sass bis 0.11.1 an dieser Stelle und schuetzte damit genau einen
 * Aufrufer: dieses Skript. rk_abrufen() wird aber von sechs weiteren
 * Stellen gerufen - html/index.php (?aktion=abrufen), htmlauth/index.php
 * (dreimal) und rk_test.php -, und keine davon nahm ein Schloss. Ein
 * Cron-Lauf und ein gleichzeitiger Knopfdruck ueberschrieben einander die
 * Messpunkte; gemessen am 30.08.2026.
 *
 * Jetzt sitzt sie in rk_abrufen() selbst, also an der Stelle, die den
 * Verlauf liest und schreibt. Sie hier ZUSAETZLICH zu halten waere kein
 * doppelter Boden, sondern eine Falle: `flock` auf denselben Ablauf mit
 * zwei Kennungen aus DEMSELBEN Prozess blockiert auf Linux.
 *
 * Wer nicht drankommt, bekommt dort den letzten Stand zurueck. Fuer dieses
 * Skript heisst das: keine Meldungen, Rueckgabewert 0 - dasselbe Verhalten
 * wie vorher, nur eine Ebene tiefer entschieden.
 */

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
