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
 *   raumklima_abruf.php --mqtt-leeren aus der Deinstallation: zurueckbehaltene
 *                                    Themen leeren (rk_mqtt_leeren())
 */

/* Die Bibliothek: welche Lage gilt, entscheidet der eigene Ablageort.
 * Installiert liegt diese Datei unter <Wurzel>/bin/plugins/<ordner>; dann
 * zuerst $LBHOMEDIR (Regeln/03, Stufe 1), danach der eigene Ort. Sonst ist es
 * ein ausgepacktes Archiv, und es gilt nur dessen eigene Bibliothek.
 *
 * Bis 0.11.10 standen drei Kandidaten der Reihe nach da: ohne LBHOMEDIR hiess
 * der erste '/webfrontend/html/plugins/bin/rk_lib.php' ab der
 * Laufwerkswurzel, und der zweite rechnete drei Ebenen ueber einem Archiv -
 * was dort lag, lief als Bibliothek (in WSL gemessen,
 * Pruefung-Raumklima-0.11.11, Faelle T3 und T11). Bauart tb_cron.php
 * (Spotpreis-Tibber 0.9.19). */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'bin') {
    $rk_kandidaten = array();
    $rk_h = getenv('LBHOMEDIR');
    if ($rk_h) {
        $rk_kandidaten[] = rtrim($rk_h, '/') . '/webfrontend/html/plugins/'
            . basename(__DIR__) . '/rk_lib.php';
    }
    $rk_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/rk_lib.php';
} else {
    $rk_kandidaten = array(dirname(__DIR__) . '/webfrontend/html/rk_lib.php');
}
$rk_lib = null;
foreach ($rk_kandidaten as $rk_k) {
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

/* Ohne Wurzel oder aus einem Archiv heraus: nichts holen, nichts senden,
 * nichts schreiben (rk_keine_wurzel_abbruch(), Muster 1 und 3 der Nachlese).
 * Bis 0.11.10 lief der Abruf dort los und schrieb in die Anlage, deren
 * Wurzel er gefunden hatte (Faelle A1, A3, T5). */
rk_keine_wurzel_abbruch('raumklima_abruf.php');

if (in_array('--mqtt-leeren', $rk_argv, true)) {
    exit(rk_mqtt_leeren());
}

/* Waehrend einer Aktualisierung setzt der Takt aus - VOR dem Ergaenzen der
 * Konfiguration, das sonst in die Luecke schriebe (rk_upgrade_laeuft()). */
if (rk_upgrade_laeuft()) {
    rk_log('Eine Aktualisierung laeuft (Marke ' . basename(rk_paths()['marke'])
        . ') - dieser Lauf setzt aus.');
    exit(0);
}

/* Einmal je Lauf nachsehen, ob der Konfiguration Schluessel fehlen, und
 * sie dann mit Protokollzeile ergaenzen - siehe rk_config_vervollstaendigen(). */
rk_config_vervollstaendigen();

$rk_stand = rk_abrufen(in_array('--sofort', $rk_argv, true));

/* Sprachausgabe (Nr. 36 b, seit 0.11.15, ab Werk aus): die Ansagen beim Eintritt
 * "Lueften empfohlen" und "Schimmelgefahr" - siehe rk_ansage_lauf(). Der Rueckgabewert
 * dieses Skripts haengt weiter nur am Abruf. */
rk_ansage_lauf($rk_stand);

if (!empty($rk_stand['meldungen'])) {
    /* Ueber die gebremste Meldung im Protokoll - eine Quelle, die eine Woche
     * lang schweigt, soll das Protokoll nicht mit 2016 gleichen Zeilen
     * fuellen. Das erledigt rk_abrufen() bereits; hier bleibt der
     * Rueckgabewert fuer den Aufrufer. */
    exit(1);
}
exit(0);
