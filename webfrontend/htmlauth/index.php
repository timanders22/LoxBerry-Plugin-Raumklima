<?php
/**
 * Raumklima - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der regelmaessige Abruf laeuft im
 * Cron-Skript (bin/raumklima_abruf.php), der Miniserver spricht mit
 * webfrontend/html/index.php.
 *
 * Praefix 'rk_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Wurzel wird GELESEN, nicht gerechnet.
 *
 * Regeln/03 nennt die Hausform dreistufig, und Stufe 1 ist $LBHOMEDIR. Bis
 * 0.11.9 fehlte sie hier: die Liste begann mit der Wette
 * dirname(dirname(__DIR__)). Gemessen am 18.09.2026 (Fall 18 des
 * Pruefstands 0.11.10, PHP 8.3.6): liegt die Oberflaeche in einem ZWEITEN
 * Baum - legacy/, Prueflauf, zweite Installation -, waehrend $LBHOMEDIR auf
 * den echten zeigt, lud die Oberflaeche die Bibliothek des zweiten Baums.
 * Klasse H der Bestandsmessung, Bauart H4.
 *
 * Welche Lage gilt, entscheidet seit 0.11.11 der eigene Ablageort: liegt
 * diese Datei unter .../htmlauth/plugins/<ordner>, ist sie installiert -
 * dann $LBHOMEDIR und danach der eigene Ort -, sonst liegt sie in einem
 * ausgepackten Archiv, und es gilt nur dessen eigene Bibliothek. Bis 0.11.10
 * probierte auch ein Archiv die installierten Kandidaten: aus einem Archiv
 * unter / war das /html/plugins/htmlauth/rk_lib.php ab der Laufwerkswurzel,
 * sonst drei Ebenen ueber dem Archiv, und was dort lag, lief als Bibliothek
 * (in WSL gemessen, Pruefung-Raumklima-0.11.11, Faelle T2 und T10); mit
 * $LBHOMEDIR nahm die Oberflaeche aus einem Archiv die Anlage (Fall A4).
 * Bauart ZendureSolarFlow 0.9.26, Spotpreis-Tibber 0.9.19.
 *
 * Der Ordnername kommt aus LBPPLUGINDIR, wo LoxBerry ihn setzt, sonst aus
 * dem Ablageort - genau wie in rk_paths().
 */
$rk_gefunden_lib = false;
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $rk_home   = getenv('LBHOMEDIR');
    $rk_ordner = getenv('LBPPLUGINDIR');
    if (!$rk_ordner) { $rk_ordner = basename(__DIR__); }
    $rk_kandidaten = array();
    if ($rk_home && is_dir($rk_home)) {
        $rk_kandidaten[] = rtrim($rk_home, '/') . '/webfrontend/html/plugins/' . $rk_ordner . '/rk_lib.php';
    }
    $rk_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/rk_lib.php';
} else {
    $rk_kandidaten = array(dirname(__DIR__) . '/html/rk_lib.php');
}
foreach ($rk_kandidaten as $rk_kandidat) {
    if (is_file($rk_kandidat)) {
        require_once $rk_kandidat;
        $rk_gefunden_lib = true;
        break;
    }
}
if (!$rk_gefunden_lib) {
    echo '<p><b>Fehler:</b> rk_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/rk_test.php';

$rk_p = rk_paths();
if ($rk_p['home'] !== '' && is_file($rk_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $rk_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $rk_p['home'] . '/libs/phplib/loxberry_web.php';
}

if (!function_exists('rk_e')) {
    function rk_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

/* ==================================================================
 * X-2 (Durchgang 01.10.2026, Regeln/04): nach einer Beanstandung stehen die
 * eingetippten Werte wieder im Formular, das beanstandete Feld ist markiert.
 * Bis 0.11.13 zeigte der GET nach der Umleitung die gespeicherten Werte, kein
 * Feld war markiert, und unter 325 Feldern war das falsche zu suchen
 * (gemessen, Bericht oberflaeche Nr. 2). Die Eingaben reisen mit der
 * Einmalmeldung (0600, Datenordner, 120 s), nur fuer das beanstandete
 * Formular, nie ein Kennwort. Bauart ACTiKamera 1.9.26.
 * ================================================================== */

/** Die Raumspalten des Formulars: Raumschluessel => array(Feldname, Bezeichnung). */
function rk_ui_raumspalten()
{
    return array(
        'name'         => array('r_name', rk_t('EINST.NAME')),
        'pfad_t'       => array('r_pfad_t', rk_t('EINST.PFAD_T')),
        'pfad_rf'      => array('r_pfad_rf', rk_t('EINST.PFAD_RF')),
        'pfad_co2'     => array('r_pfad_co2', rk_t('EINST.PFAD_CO2')),
        'pfad_fenster' => array('r_pfad_fenster', rk_t('EINST.PFAD_FENSTER')),
        'pfad_zuluft'  => array('r_pfad_zuluft', rk_t('EINST.PFAD_ZULUFT')),
        'quelle'       => array('r_quelle', rk_t('EINST.EIGENE_QUELLE')),
        'quelle_rf'    => array('r_quelle_rf', rk_t('EINST.QUELLE_RF')),
        'einheit_t'    => array('r_einheit_t', rk_t('EINST.EINHEIT')),
        'einheit_rf'   => array('r_einheit_rf', rk_t('EINST.EINHEIT')),
        'frsi'         => array('r_frsi', 'fRsi'),
        'soll_min'     => array('r_min', rk_t('EINST.SOLL_MIN')),
        'soll_max'     => array('r_max', rk_t('EINST.SOLL_MAX')),
        'art'          => array('r_art', rk_t('EINST.ART')),
        'erd_t'        => array('r_erd_t', rk_t('EINST.ERD_T')),
        'volumen'      => array('r_volumen', rk_t('EINST.VOLUMEN')),
        'fenster'      => array('r_fenster', rk_t('EINST.FENSTERART')),
        't_soll'       => array('r_t_soll', rk_t('EINST.T_SOLL')),
        'co2_max'      => array('r_co2_max', rk_t('EINST.CO2_MAX')),
        'wrg_eta'      => array('r_wrg_eta', rk_t('EINST.WRG_ETA')),
        'wasser_g'     => array('r_wasser_g', rk_t('EINST.WASSER_G')),
        'ruhe_von'     => array('r_ruhe_von', rk_t('EINST.RUHE_VON')),
        'ruhe_bis'     => array('r_ruhe_bis', rk_t('EINST.RUHE_BIS')),
        'personen'     => array('r_personen', rk_t('EINST.PERSONEN')),
    );
}

/** Die einzelnen Felder des Reiters Einstellungen: Schluessel => Sprachschluessel. */
function rk_ui_einzelfelder()
{
    return array(
        'quelle'        => 'EINST.QUELLE',
        'takt'          => 'EINST.TAKT',
        'aussen_art'    => 'EINST.AUSSEN_ART',
        'breite'        => 'EINST.BREITE',
        'laenge'        => 'EINST.LAENGE',
        'aussen_quelle' => 'EINST.AUSSEN_QUELLE',
        'aussen_t'      => 'EINST.AUSSEN_T',
        'aussen_rf'     => 'EINST.AUSSEN_RF',
        'aussen_einheit_t'  => 'EINST.EINHEIT',
        'aussen_einheit_rf' => 'EINST.EINHEIT',
        'mindest'       => 'EINST.MINDEST',
        't_min'         => 'EINST.T_MIN',
        'af_unter'      => 'EINST.AF_UNTER',
        'vorschau'      => 'EINST.VORSCHAU',
        'steht_min'     => 'EINST.STEHT_MIN',
        'hyst'          => 'EINST.HYST',
        'dauer_min'     => 'EINST.DAUER_MIN',
        'regen_max'     => 'EINST.REGEN_MAX',
        'kuehl_spanne'  => 'EINST.KUEHL_SPANNE',
        'wind_max'      => 'EINST.WIND_MAX',
        'wand_abstand'  => 'EINST.WAND_ABSTAND',
        'schwuel_x'     => 'EINST.SCHWUEL_X',
        'co2_t_min'     => 'EINST.CO2_T_MIN',
        'zwang_std'     => 'EINST.ZWANG_STD',
        'vl_zuschlag'   => 'EINST.VL_ZUSCHLAG',
        'kuehlfrei_ein' => 'EINST.KUEHLFREI_EIN',
        'kuehlfrei_aus' => 'EINST.KUEHLFREI_AUS',
        'heizgrenze'    => 'EINST.HEIZGRENZE',
        'trend_min'     => 'EINST.TREND_MIN',
        'co2_ltr'       => 'EINST.CO2_LTR',
        'co2_aussen'    => 'EINST.CO2_AUSSEN',
    );
}

/** Die Felder eines Formulars, deren Eingaben zurueckreisen duerfen (ohne Kennwort). */
function rk_eingabe_felder($formular)
{
    if ($formular === 'mqtt') { return array('mqtt_ein', 'mqtt_topic'); }
    if ($formular !== 'einst') { return array(); }
    $aus = array();
    foreach (rk_ui_raumspalten() as $sp) {
        for ($i = 0; $i < RK_RAEUME; $i++) { $aus[] = $sp[0] . '[' . $i . ']'; }
    }
    return array_merge($aus, array_keys(rk_ui_einzelfelder()),
                       array('zug_benutzer', 'zug_loeschen', 'verlauf_ein'),
                       /* Sprachausgabe (seit 0.11.15): ansage_x2_felder() nennt nie die Sprechtoken. */
                       ansage_x2_felder(rk_ansage_opt()), array('ansage_lueften', 'ansage_schimmel',
                       'ansage_ruhe_von', 'ansage_ruhe_bis'));
}

/** Ein Wert aus $_POST - auch fuer 'r_min[3]'. null = nicht mitgeschickt. */
function rk_post_wert($feld)
{
    /* Ziffern gehoeren in den Namen: r_co2_max, r_pfad_co2. */
    if (preg_match('/^([a-z0-9_]+)\[(\d+)\]$/', (string) $feld, $m)) {
        return (isset($_POST[$m[1]]) && is_array($_POST[$m[1]]) && array_key_exists($m[2], $_POST[$m[1]]))
            ? $_POST[$m[1]][$m[2]] : null;
    }
    return isset($_POST[$feld]) ? $_POST[$feld] : null;
}

/** Die abgewiesenen Eingaben EINES Formulars einsammeln (X-2). */
function rk_eingaben_sammeln($formular, array $falsch)
{
    $werte = array();
    foreach (rk_eingabe_felder($formular) as $f) {
        if (in_array($f, array('mqtt_ein', 'verlauf_ein', 'zug_loeschen', 'ansage_lueften', 'ansage_schimmel',
                               'tts_alexa_token_loeschen', 'tts_google_token_loeschen'), true)) {
            $werte[$f] = !empty($_POST[$f]) ? '1' : '0';
            continue;
        }
        $v = rk_post_wert($f);
        if (!is_string($v) || strlen($v) > 1024 || !preg_match('//u', $v)) { continue; }
        $werte[$f] = $v;
    }
    $markierbar = array_merge(rk_eingabe_felder($formular),
                              array('zug_passwort', 'tts_alexa_token', 'tts_google_token'));
    $fa = array();
    foreach ($falsch as $f) {
        if (in_array($f, $markierbar, true) && !in_array($f, $fa, true)) { $fa[] = $f; }
    }
    return array('formular' => (string) $formular, 'werte' => $werte, 'falsch' => $fa);
}

/** Die zurueckgereisten Eingaben fuer diesen Seitenaufbau setzen bzw. lesen. */
function rk_eingaben_aktiv($setzen = null)
{
    static $e = array('formular' => '', 'werte' => array(), 'falsch' => array());
    if (is_array($setzen)) {
        $f = (isset($setzen['formular']) && is_string($setzen['formular'])) ? $setzen['formular'] : '';
        $erlaubt = rk_eingabe_felder($f);
        $markierbar = array_merge($erlaubt, array('zug_passwort', 'tts_alexa_token', 'tts_google_token'));
        $w = array();
        $fa = array();
        foreach ((isset($setzen['werte']) && is_array($setzen['werte'])) ? $setzen['werte'] : array() as $k => $v) {
            if (is_string($k) && in_array($k, $erlaubt, true) && is_string($v)) { $w[$k] = $v; }
        }
        foreach ((isset($setzen['falsch']) && is_array($setzen['falsch'])) ? $setzen['falsch'] : array() as $k) {
            if (is_string($k) && in_array($k, $markierbar, true)) { $fa[] = $k; }
        }
        $e = array('formular' => $erlaubt ? $f : '', 'werte' => $w, 'falsch' => $fa);
    }
    return $e;
}

/** Der Wert fuer ein Formularfeld: die zurueckgereiste Eingabe oder der gespeicherte. */
function rk_ein($feld, $gespeichert)
{
    $e = rk_eingaben_aktiv();
    return array_key_exists((string) $feld, $e['werte']) ? $e['werte'][(string) $feld] : (string) $gespeichert;
}

/** Ein Haken: die zurueckgereiste Eingabe oder der gespeicherte Stand. */
function rk_haken($feld, $gespeichert)
{
    $e = rk_eingaben_aktiv();
    $an = array_key_exists((string) $feld, $e['werte']) ? ($e['werte'][(string) $feld] === '1') : (bool) $gespeichert;
    return $an ? ' checked' : '';
}

/** Das Merkmal am beanstandeten Feld: rot umrandet und fuer Vorleseprogramme markiert. */
function rk_mark($feld)
{
    $e = rk_eingaben_aktiv();
    return in_array((string) $feld, $e['falsch'], true) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/**
 * Abrufen und sagen, ob wirklich abgerufen wurde (U11). rk_abrufen() liefert
 * waehrend einer Aktualisierung oder bei besetzter Sperre den letzten Stand
 * zurueck; bis 0.11.13 stand dann trotzdem "Abgerufen" da.
 * Rueckgabe array(gelaufen, Stand).
 */
function rk_ui_abrufen()
{
    $t0 = time();
    $s = rk_abrufen(true);
    if (!is_array($s)) { $s = array(); }
    return array(isset($s['lauf_ts']) && (int) $s['lauf_ts'] >= $t0, $s);
}

/* Die Positivliste fuer activetab entsteht HIERAUS.
 *
 * Nicht mehr: die Leiste weiter unten ist ausgeschrieben, und das ist
 * Absicht - eine foreach-Schleife macht sie fuer hausstandard_pruefen.py
 * unsichtbar (Spalte tab zeigt dann einen Strich, und ein Strich sieht
 * aus wie ein Haken). Bis 0.11.2 behauptete dieser Kommentar, auch Leiste
 * und sm-active entstuenden daraus; gemessen wird $rk_reiter genau einmal
 * gelesen, fuer $rk_muster. Dass die drei Stellen zusammenpassen, misst
 * deshalb der Reiter Test nach - nicht dieser Satz. */
$rk_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,                    // Eigenname, wird nicht uebersetzt
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$rk_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($rk_reiter))) . ')$/';
$rk_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($rk_muster, (string) $_POST['activetab'])) {
    $rk_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($rk_muster, 'tab-' . (string) $_GET['form'])) {
    $rk_tab = 'tab-' . (string) $_GET['form'];
}

$rk_meldungen = array();
$rk_fehler = array();      // gesammelt, nicht ueberschrieben
$rk_testausgabe = '';
/* U11 (Durchgang 01.10.2026): eigene Listen. Bis 0.11.13 stand jede Stoerung -
 * ein Abruf ohne Standort, ein Schreibfehler, ein stummer Miniserver - unter der
 * Ueberschrift der Beanstandungsliste "Das konnte so nicht uebernommen werden"
 * (Regeln/04; gemessen, Bericht oberflaeche Nr. 13). $rk_fehler traegt jetzt nur
 * Beanstandungen einer Eingabe oder Datei. */
$rk_stoerung = array();         // "Der Vorgang ist nicht gelungen"
$rk_abrufhinweise = array();    // "Hinweise zum Abruf"
$rk_eingaben = null;            // X-2
$rk_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
/* Merkt sich, dass ein POST kam - auch wenn der Wachposten ihn abweist.
 * Auch dann endet die Anfrage mit einer Umleitung. */
$rk_war_post = $rk_post;

/* ---------------- EIN Wachposten fuer alle Formulare ----------------
 *
 * Vor jedem Handler, nicht in jedem Handler. Eine Abfrage je Knopf haette
 * man beim naechsten Knopf vergessen - und genau das ist der Grund, warum
 * hier bis 0.10.1 gar keine stand.
 *
 * Alle acht Handler haengen bereits an $rk_post; das Merkmal zu pruefen und
 * $rk_post auf false zu setzen deckt sie damit vollstaendig ab.
 * wachposten_pruefen.py misst das nach und meldet die Zahl der ungedeckten
 * Zweige - vor dem Einbau erschien die Linie in seiner Tabelle ueberhaupt
 * nicht. */
/* $rk_merkmal_fehlt gab es bis 0.11.2 - zweimal zugewiesen, nirgends
 * gelesen. Eine Variable, die aussieht, als wuerde ein abgewiesener POST
 * gesondert behandelt, obwohl er es nicht wird. Sichtbar wird der Fall
 * ueber FEHLER.FREMDES_FORMULAR in der Sammelliste, und das genuegt. */
if ($rk_post && !rk_formtoken_ok()) {
    $rk_post = false;
    $rk_fehler[] = rk_t('FEHLER.FREMDES_FORMULAR');
    rk_log('Ein POST ohne gueltiges Formularmerkmal wurde abgewiesen.');
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Reiterwahl, Wachposten,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 *
 * SEIT 0.11.0 GIBT ES DEN WACHPOSTEN WIRKLICH. Bis 0.10.1 stand er nur in
 * diesem Kommentar - fuenfzehn Formulare, kein einziges Merkmal, und
 * wachposten_pruefen.py hat die Linie deshalb still uebersprungen. Ein
 * Kommentar, der eine Eigenschaft zusichert, ist kein Beleg fuer sie; das
 * steht seit dem 17.08.2026 in REGELN_2 und ist hier trotzdem passiert.
 *
 * UND DIE HANDLER FUER DIE SICHERUNG STEHEN JETZT HIER OBEN. Bis 0.10.1
 * lagen sie hinter der Zeile, die die Anzeigewerte liest. Gemessen am
 * 28.08.2026 mit einer Datei, die takt=1800 trug, gegen einen Stand mit
 * takt=600:
 *
 *     Konfiguration danach : "takt": 1800
 *     Seite zeigt im Feld  : value="600"
 *     Meldung auf der Seite: "zurueckgespielt: 24 Werte uebernommen."
 *
 * Der Anwender liest "uebernommen" und sieht ueberall die alten Zahlen.
 * Drueckt er daraufhin Speichern - der naheliegende naechste Griff -, ist
 * die Rueckspielung wieder fort.
 * ================================================================== */
/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * Der Kopf mit Datum kommt aus rk_sicherung_bauen(); rk_sicherung_lesen()
 * uebergeht die Unterstrich-Schluessel. Beide Seiten wurden im selben Zug
 * gebaut - eine Sicherung, die die eigene Bibliothek zwei Zeilen spaeter
 * ablehnt, ist der Befund vom 26.08.2026 aus dem WiFi-Scanner. */
if ($rk_post && isset($_POST['rk_sichern'])) {
    /* Die Zugangsdaten kommen nur mit, wenn der Haken gesetzt ist. Ein
     * Passwort geht nicht ungefragt in eine Datei, die jemand herunterlaedt -
     * und der Kopf der Datei sagt hinterher, was wirklich drin steht. */
    /* Das Protokoll sagt, was WIRKLICH in der Datei steht - nicht, was der
     * Haken wollte. Bis 0.11.2 fragte diese Zeile den Haken: war er
     * gesetzt, aber nichts hinterlegt, schrieb das Protokoll "MIT
     * Zugangsdaten", waehrend der Kopf der Datei das Gegenteil sagte.
     * Wer spaeter nachsieht, ob eine herausgegebene Datei ein Geheimnis
     * trug, bekam die falsche Auskunft - in der gefaehrlichen Richtung.
     * Deshalb dasselbe Kriterium wie in rk_sicherung_bauen(). */
    $rk_mit_zugang = !empty($_POST['sich_zugang']);
    $rk_zug = $rk_mit_zugang ? rk_geheim() : null;
    $rk_zug_drin = is_array($rk_zug)
        && ((string) $rk_zug['benutzer'] !== '' || (string) $rk_zug['passwort'] !== '');
    $rk_js = rk_sicherung_bauen(rk_config(), $rk_zug);
    /* X-3: die Datei kommt vollstaendig, auch wenn das eigene Zurueckspielen
     * sie abweisen wuerde - dann traegt sie "_warnung", und das Protokoll sagt es. */
    if (is_string($rk_js) && strpos($rk_js, '"_warnung"') !== false) {
        rk_log('Die Sicherung traegt eine Warnung: das Zurueckspielen wuerde sie abweisen (X-3).');
    }
    if ($rk_zug_drin) {
        rk_log('Einstellungen gesichert - MIT Zugangsdaten der Quelle.');
    } else {
        rk_log('Einstellungen gesichert - ohne Zugangsdaten.');
    }
    if ($rk_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="raumklima_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $rk_js;
        exit;
    }
    $rk_stoerung[] = rk_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen.
 *
 * Und danach ein sofortiger Abruf: das Abbild stammt sonst aus der alten
 * Konfiguration, und die Tabelle darueber zeigte Werte, die es nicht mehr
 * gibt (Punkt 7 der Hausvorgabe - "nach dem Zurueckspielen den Dienst
 * nachziehen und sagen, was mit ihm geschehen ist"). */
if ($rk_post && isset($_POST['rk_zurueck'])) {
    if (!isset($_FILES['rk_sicherung']) || !is_array($_FILES['rk_sicherung'])
        || !isset($_FILES['rk_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['rk_sicherung']['tmp_name'])) {
        $rk_fehler[] = rk_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['rk_sicherung']['size'] > 262144) {
        $rk_fehler[] = rk_t('EINST.SICH_ZU_GROSS');
    } else {
        list($rk_neu, $rk_mangel, $rk_n, $rk_zug, $rk_hinw) = rk_sicherung_lesen(
            (string) @file_get_contents($_FILES['rk_sicherung']['tmp_name']));
        if ($rk_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $rk_fehler[] = rk_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $rk_mangel);
        } elseif (rk_config_speichern($rk_neu)) {
            $rk_meldungen[] = sprintf(rk_t('EINST.SICH_UEBERNOMMEN'), $rk_n);
            /* C6 (Durchgang 01.10.2026): ein leeres Aktionstoken in der Datei -
             * das geltende blieb stehen, und das wird gesagt. */
            if (in_array('TOKEN_BEHALTEN', (array) $rk_hinw, true)) {
                $rk_meldungen[] = rk_t('EINST.SICH_TOKEN_BEHALTEN');
            }
            if (in_array('TOKEN_NEU', (array) $rk_hinw, true)) {
                $rk_meldungen[] = rk_t('EINST.SICH_TOKEN_NEU');
            }
            if (in_array('ANSAGE_BEHALTEN', (array) $rk_hinw, true)) {
                $rk_meldungen[] = rk_t('EINST.SICH_ANSAGE_BEHALTEN');
            }
            /* Die Zugangsdaten wandern nach geheim.json, NICHT in die
             * Konfiguration. Und es wird gesagt, ob welche dabei waren -
             * beides, weil beides eine Aussage ist. */
            if (is_array($rk_zug)) {
                if (rk_geheim_speichern($rk_zug)) {
                    $rk_meldungen[] = rk_t('EINST.SICH_MIT_ZUGANG');
                    rk_log('Zugangsdaten aus der Sicherungsdatei uebernommen.');
                } else {
                    $rk_stoerung[] = rk_t('EINST.SICH_ZUGANG_FEHL');
                }
            }
            /* Was mit dem Abruf geschehen ist. Ohne diesen Satz sucht der
             * Anwender auf dem zweiten LoxBerry lange nach dem Grund fuer
             * HTTP 401. Und nur, wenn wirklich abgerufen wurde (U11). */
            list($rk_lief, $rk_s) = rk_ui_abrufen();
            if (!$rk_lief) {
                $rk_stoerung[] = rk_t('ALLG.NICHT_ABGERUFEN');
            } else {
                $rk_meldungen[] = empty($rk_s['meldungen'])
                    ? rk_t('EINST.SICH_ABRUF_OK') : rk_t('EINST.SICH_ABRUF_FEHL');
            }
            $rk_g0 = rk_geheim();
            if ($rk_g0['benutzer'] === '' && $rk_g0['passwort'] === '') {
                $rk_meldungen[] = rk_t('EINST.SICH_OHNE_ZUGANG');
            }
            rk_log('Einstellungen aus einer Sicherungsdatei zurueckgespielt ('
                   . (int) $rk_n . ' Werte).');
        } else {
            $rk_stoerung[] = rk_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
    $rk_tab = 'tab-settings';
}

/* ---------------- Der Einrichtungs-Assistent ----------------
 *
 * Zwei Schritte, und der erste schreibt NICHTS. Erst wird gezeigt, was
 * gefunden wurde und was daraus wuerde; uebernommen wird nur auf einen
 * zweiten Knopfdruck. Ein Assistent, der beim Hinsehen schon schreibt,
 * nimmt dem Anwender die Entscheidung ab, die ihm gehoert.
 *
 * Der PFAD in die Antwort wird an der eigenen Anlage GEMESSEN, nicht
 * angenommen: rk_ms_probe() holt einen Baustein wirklich und sucht darin
 * selbst die Stelle, an der eine lesbare Zahl steht. Was der Assistent
 * eintraegt, ist damit abgelesen. */
$rk_ass = null;
if ($rk_post && (isset($_POST['ms_suchen']) || isset($_POST['ms_uebernehmen']))) {
    $rk_tab = 'tab-settings';
    $rk_cfg0 = rk_config();
    $rk_ms = rk_miniserver_gewaehlt($rk_cfg0);
    if ($rk_ms === null) {
        $rk_stoerung[] = rk_t('MELD.MS_KEINER');
    } else {
        list($rk_ok, $rk_meld, $rk_msr, $rk_bau) = rk_struktur_holen($rk_ms);
        if (!$rk_ok) {
            /* Die VOLLE Adresse in die Meldung - Schema und Port
             * eingeschlossen. Bis 0.11.1 stand dort nur der Wirt, und
             * genau die beiden Angaben, die den Fehlschlag erklaeren
             * (redet das Plugin http oder https, und gegen welchen Port),
             * fehlten. Ein LoxBerry mit Preferhttps=1 meldete "antwortet
             * nicht", ohne zu verraten, wohin er gefragt hat. */
            /* U6 (Durchgang 01.10.2026): roh - maskiert wird einmal bei der Ausgabe. */
            $rk_stoerung[] = sprintf(rk_t('EINST.MS_FEHLER'),
                $rk_ms['name'] . ' (' . rtrim(rk_ms_url($rk_ms, ''), '/') . ')',
                rk_t($rk_meld));
        } else {
            $rk_kat = isset($_POST['ms_kategorie']) ? rk_text_saeubern($_POST['ms_kategorie']) : '';
            $rk_vor = rk_fuehler_vorschlag($rk_bau, $rk_kat);

            /* ------------------------------------------------------------
             * DIE LAGE JE RAUM WIRD HIER ENTSCHIEDEN, nicht in der Tabelle.
             *
             * Bis 0.11.1 rechnete die Vorschautabelle selbst aus, was sie
             * anzeigt, und die Uebernahme rechnete es ein zweites Mal - mit
             * anderen Bedingungen. Daraus wurden zwei Falschaussagen
             * (beide am 30.08.2026 an der eigenen Anlage gemessen):
             *
             *   - Dreizehn Zeilen standen auf "wird angelegt", eingetragen
             *     wurden zwoelf. `if (!$rk_frei) break;` bricht stumm ab,
             *     und die Erfolgsmeldung nennt nur die Zahl der
             *     geschriebenen Raeume. Der alphabetisch letzte Raum
             *     verschwand wortlos.
             *   - Raeume mit mehreren Bewerbern standen ebenfalls auf "wird
             *     angelegt". Im KG Technikraum ist der erste von neunzehn
             *     Bewerbern `Temp. Relay 1` - die Gehaeusetemperatur eines
             *     Relaismoduls. Der richtige Fuehler ist der achtzehnte.
             *
             * Jetzt gibt es EINE Stelle, die 'lage' setzt, und die Tabelle
             * zeigt nur noch an, was hier steht.
             * ------------------------------------------------------------ */
            $rk_frei_n = 0;
            for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) {
                if (rk_platz_frei($rk_cfg0['raeume'][$rk_i])) { $rk_frei_n++; }
            }
            $rk_uebrig = $rk_frei_n;
            $rk_voll = array();
            $rk_mehrdeutig = 0;
            $rk_kein_platz = 0;
            foreach ($rk_vor as $rk_k => $v) {
                $rk_lage = '';
                $rk_schon = false;
                foreach ($rk_cfg0['raeume'] as $rk_b) {
                    if (rk_raum_gleich($rk_b['name'], $v['raum'])) { $rk_schon = true; break; }
                }
                if ($rk_schon) {
                    $rk_lage = 'schon_da';
                } elseif ($v['t'] === null || $v['rf'] === null) {
                    /* Ein Raum mit nur einer Haelfte ergaebe ok=0 und stuende
                     * danach als Ausfall da - das waere kein Vorschlag,
                     * sondern eine Falle. Er wird gezeigt, nicht angelegt. */
                    $rk_lage = 'unvollstaendig';
                } elseif ($v['t_mehr'] > 0 || $v['rf_mehr'] > 0) {
                    /* KEINE STILLE WAHL UNTER MEHREREN. Der Kopfkommentar zu
                     * rk_fuehler_vorschlag() sagt es seit 0.11.1: "eine
                     * stille Auswahl unter mehreren ist eine Entscheidung,
                     * die der Anwender treffen muss, nicht das Plugin."
                     * Getan hat das Plugin bis 0.11.1 trotzdem das
                     * Gegenteil - es nahm den ersten und meldete nur die
                     * Zahl daneben. */
                    $rk_lage = 'mehrdeutig';
                    $rk_mehrdeutig++;
                } elseif ($rk_uebrig <= 0) {
                    $rk_lage = 'kein_platz';
                    $rk_kein_platz++;
                } else {
                    $rk_lage = 'wird_angelegt';
                    $rk_uebrig--;
                    $rk_voll[] = $v;
                }
                $rk_vor[$rk_k]['lage'] = $rk_lage;
            }

            $rk_ass = array('ms' => $rk_ms, 'raeume' => count($rk_msr),
                            'bausteine' => count($rk_bau), 'vorschlag' => $rk_vor,
                            'voll' => $rk_voll, 'kategorie' => $rk_kat,
                            'frei' => $rk_frei_n, 'mehrdeutig' => $rk_mehrdeutig,
                            'kein_platz' => $rk_kein_platz,
                            'proben' => array(), 'probe' => null, 'geschrieben' => 0);

            /* Je Bausteinart eine Probe - siehe rk_ms_proben(). */
            if ($rk_voll) {
                $rk_ass['proben'] = rk_ms_proben($rk_ms, $rk_voll);
                foreach (array('t', 'rf') as $rk_g) {
                    foreach ($rk_ass['proben'][$rk_g] as $rk_pr) {
                        if ($rk_ass['probe'] === null) { $rk_ass['probe'] = $rk_pr; }
                        if (!$rk_pr['ok']) {
                            $rk_stoerung[] = sprintf(rk_t('EINST.MS_PROBE_FEHL2'),
                                $rk_pr['name'], rk_t($rk_pr['meld']));
                        }
                    }
                }
            }

            /* ---- Zweiter Schritt: uebernehmen ----
             *
             * Die leere Menge bekommt eine EIGENE Meldung. Seit die Lage je
             * Raum vorab entschieden wird, kann $rk_voll leer sein, obwohl
             * der Miniserver zwoelf Raeume liefert - dann stehen sie alle
             * schon in der Tabelle, sind mehrdeutig oder haben keinen Platz
             * mehr. Ohne diesen Zweig druecke der Anwender den Knopf und
             * bekaeme UEBERHAUPT KEINE Antwort. Genau das hat die
             * Knopfprobe am 30.08.2026 gemeldet, nachdem der erste Entwurf
             * nur den Erfolgsfall behandelte. */
            if (isset($_POST['ms_uebernehmen']) && !$rk_voll) {
                $rk_meldungen[] = rk_t('EINST.MS_NICHTS_NEU');
                if ($rk_ass['mehrdeutig'] > 0) {
                    $rk_meldungen[] = sprintf(rk_t('EINST.MS_MEHRDEUTIG_N'),
                        (int) $rk_ass['mehrdeutig']);
                }
                if ($rk_ass['kein_platz'] > 0) {
                    $rk_stoerung[] = sprintf(rk_t('EINST.MS_KEIN_PLATZ_N'),
                        (int) $rk_ass['kein_platz'], RK_RAEUME);
                }
            }
            if (isset($_POST['ms_uebernehmen']) && $rk_voll
                && !empty($rk_ass['probe']['ok'])) {
                $rk_neu2 = $rk_cfg0['raeume'];
                $rk_frei = array();
                /* Belegte Plaetze bleiben belegt. Ein Assistent, der einen
                 * eingerichteten Raum ueberschreibt, loescht Arbeit - und
                 * die Nummer eines Raums ist sein Platz in der Tabelle, an
                 * dem in Loxone jeder Suchtext haengt. */
                for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) {
                    if (rk_platz_frei($rk_cfg0['raeume'][$rk_i])) { $rk_frei[] = $rk_i; }
                }
                $rk_n2 = 0;
                foreach ($rk_voll as $rk_v) {
                    if (!$rk_frei) { break; }
                    /* Ein Raum, der schon eingetragen ist, wird nicht ein
                     * zweites Mal angelegt. Verglichen wird ueber
                     * rk_raum_gleich() - siehe dort, warum ein blosses
                     * trim()-Vergleich Raeume verdoppelt hat. */
                    $rk_schon = false;
                    foreach ($rk_neu2 as $rk_b) {
                        if (rk_raum_gleich($rk_b['name'], $rk_v['raum'])) { $rk_schon = true; break; }
                    }
                    if ($rk_schon) { continue; }
                    /* Der Pfad kommt aus der Probe DIESER Bauart, nicht aus
                     * der ersten Probe ueberhaupt. Fehlt sie oder ist sie
                     * fehlgeschlagen, wird der Raum uebersprungen - lieber
                     * kein Eintrag als einer, der nie einen Wert liefert. */
                    $rk_pt = isset($rk_ass['proben']['t'][$rk_v['t']['typ']])
                        ? $rk_ass['proben']['t'][$rk_v['t']['typ']] : null;
                    $rk_prf = isset($rk_ass['proben']['rf'][$rk_v['rf']['typ']])
                        ? $rk_ass['proben']['rf'][$rk_v['rf']['typ']] : null;
                    if (!$rk_pt || !$rk_prf || !$rk_pt['ok'] || !$rk_prf['ok']) { continue; }

                    $rk_i = array_shift($rk_frei);
                    /* GETRIMMT eintragen. rk_config() trimmt den Namen beim
                     * naechsten Lesen ohnehin; ungetrimmt geschrieben fand
                     * der Waechter "Raum steht schon" seinen eigenen Eintrag
                     * nicht wieder und legte ihn ein zweites Mal an. */
                    $rk_neu2[$rk_i]['name'] = trim((string) $rk_v['raum']);
                    $rk_neu2[$rk_i]['quelle'] = rk_ms_url($rk_ms,
                        'jdev/sps/io/' . rawurlencode($rk_v['t']['uuid']) . '/all');
                    $rk_neu2[$rk_i]['quelle_rf'] = rk_ms_url($rk_ms,
                        'jdev/sps/io/' . rawurlencode($rk_v['rf']['uuid']) . '/all');
                    $rk_neu2[$rk_i]['pfad_t'] = $rk_pt['pfad'];
                    $rk_neu2[$rk_i]['pfad_rf'] = $rk_prf['pfad'];
                    $rk_n2++;
                }
                if ($rk_n2 === 0) {
                    $rk_meldungen[] = rk_t('EINST.MS_NICHTS_NEU');
                } else {
                    $rk_cfg0['raeume'] = $rk_neu2;
                    if (rk_config_speichern($rk_cfg0)) {
                        $rk_ass['geschrieben'] = $rk_n2;
                        $rk_meldungen[] = sprintf(rk_t('EINST.MS_UEBERNOMMEN'), $rk_n2);
                        /* WAS NICHT HINEINPASSTE, WIRD GENANNT. Bis 0.11.1
                         * stand hier nur die Zahl der geschriebenen Raeume,
                         * und der Ueberhang fiel wortlos weg. */
                        if ($rk_ass['kein_platz'] > 0) {
                            $rk_stoerung[] = sprintf(rk_t('EINST.MS_KEIN_PLATZ_N'),
                                (int) $rk_ass['kein_platz'], RK_RAEUME);
                        }
                        if ($rk_ass['mehrdeutig'] > 0) {
                            $rk_meldungen[] = sprintf(rk_t('EINST.MS_MEHRDEUTIG_N'),
                                (int) $rk_ass['mehrdeutig']);
                        }
                        /* Die Zugangsdaten des Miniservers gehoeren in die
                         * Geheimnisdatei - rk_holen() schickt sie beim Abruf
                         * als Basic-Auth mit. Ohne sie antwortet der
                         * Miniserver mit 401, und der Anwender suchte lange. */
                        $rk_g2 = rk_geheim();
                        if ($rk_g2['benutzer'] === '' && $rk_ms['user'] !== '') {
                            /* Rueckgabewert ansehen. Bis 0.11.1 meldete die
                             * Seite "Zugangsdaten gesetzt", auch wenn das
                             * Schreiben an Rechten oder voller Platte
                             * scheiterte - der Sicherungs-Block zwanzig
                             * Zeilen darueber macht es seit jeher richtig. */
                            if (rk_geheim_speichern(array('benutzer' => $rk_ms['user'],
                                                          'passwort' => $rk_ms['pass']))) {
                                $rk_meldungen[] = rk_t('EINST.MS_ZUGANG_GESETZT');
                            } else {
                                $rk_stoerung[] = rk_t('EINST.SICH_ZUGANG_FEHL');
                            }
                        } elseif ($rk_g2['benutzer'] !== ''
                                  && $rk_g2['benutzer'] !== $rk_ms['user']) {
                            /* ES STEHEN SCHON FREMDE ZUGANGSDATEN DA.
                             * Bis 0.11.1 blieben sie kommentarlos stehen.
                             * Gemessen am 30.08.2026: eine laufende
                             * Shelly-Einrichtung wurde durch zwoelf Raeume
                             * ersetzt, die alle 24 Abrufe mit 401 beendeten
                             * - und die Meldung nannte nur "Abruf
                             * fehlgeschlagen". */
                            $rk_stoerung[] = sprintf(rk_t('EINST.MS_ZUGANG_FREMD'),
                                $rk_g2['benutzer'], $rk_ms['user']);
                        }
                        rk_log('Einrichtungs-Assistent: ' . $rk_n2 . ' Raum/Raeume vom '
                               . 'Miniserver uebernommen (Pfad T '
                               . $rk_ass['probe']['pfad'] . ', ' . $rk_ass['kein_platz']
                               . ' ohne Platz, ' . $rk_ass['mehrdeutig'] . ' mehrdeutig).');
                        list($rk_lief2, $rk_s2) = rk_ui_abrufen();
                        if (!$rk_lief2) {
                            $rk_stoerung[] = rk_t('ALLG.NICHT_ABGERUFEN');
                        } else {
                            $rk_meldungen[] = empty($rk_s2['meldungen'])
                                ? rk_t('EINST.SICH_ABRUF_OK') : rk_t('EINST.SICH_ABRUF_FEHL');
                        }
                    } else {
                        $rk_stoerung[] = rk_t('EINST.SICH_SCHREIBFEHLER');
                    }
                }
            }
        }
    }
}

/* ---------------- Vorlage herunterladen ---------------- */
if ($rk_post && isset($_POST['vorlage'])) {
    list($rk_name, $rk_inhalt) = rk_vorlage();
    if ($rk_inhalt === '') {
        $rk_stoerung[] = rk_t('LOX.FEHLER_VORLAGE');
        $rk_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        // Anfuehrungszeichen um den Dateinamen: ohne sie bricht jeder Name
        // mit einem Leerzeichen darin.
        header('Content-Disposition: attachment; filename="' . $rk_name . '"');
        echo $rk_inhalt;
        exit;
    }
}

/* ---------------- Einstellungen speichern ---------------- */
/*
 * Jedes Formular besitzt nur SEINE Felder.
 *
 * Bis 0.9.8 baute dieser Handler jeden Wert aus $_POST neu. Ein Speichern
 * im Reiter MQTT schickt aber weder Raeume noch Quellen mit - sie kamen als
 * leer an und wurden als Loeschung uebernommen (gemessen am 24.08.2026).
 * Deshalb traegt jedes Formular ein verstecktes Feld, das sagt, wofuer es
 * zustaendig ist. Angefasst wird nur, was wirklich mitkam.
 *
 * Durchgang 01.10.2026 (U1-U3, Entscheidungen Nr. 16 und 19): ERST wird jedes
 * Feld geprueft, und bei einer einzigen Beanstandung wird NICHTS geschrieben -
 * weder raumklima.json noch geheim.json. Bis 0.11.13 stand
 * `elseif (rk_config_speichern($rk_cfg))` hinter den Pruefungen, ohne nach
 * $rk_fehler zu sehen: die Seite meldete "gespeichert" und die Beanstandung
 * untereinander, und im Reiter MQTT ging mqtt_ein still auf 0, wenn nur das
 * Thema falsch war (gemessen, Bericht oberflaeche Nr. 1). Still
 * zurechtgebogen wird nichts mehr - die Regeln stehen in rk_regel_pruefen(),
 * dieselben wie fuer die Sicherung. Die eingetippten Werte reisen mit der
 * Einmalmeldung zurueck, das falsche Feld ist markiert (X-2).
 */
if ($rk_post && isset($_POST['speichern'])) {
    $rk_cfg = rk_config();
    $rk_hat_einst = isset($_POST['feld_einst']);
    $rk_hat_mqtt  = isset($_POST['feld_mqtt']);
    $rk_falsch = array();
    $rk_g_neu = null;
    /* Ein Feld pruefen. $regel ist ein Schluessel fuer rk_wert_pruefen() oder
     * eine Regel fuer rk_regel_pruefen(). Rueckgabe array(gut, Wert). Die
     * Eingabe steht in der Beanstandung - ausser bei einem Kennwort. */
    $rk_pruefe = function ($regel, $feld, $bez) use (&$rk_fehler, &$rk_falsch) {
        $roh = rk_post_wert($feld);
        $erg = is_string($regel) ? rk_wert_pruefen($regel, $roh) : rk_regel_pruefen($regel, $roh);
        if ($erg[1] === '') { return array(true, $erg[0]); }
        $rk_falsch[] = $feld;
        $rk_wr = rk_wert_regeln();
        $rk_rg = is_string($regel) ? (isset($rk_wr[$regel]) ? $rk_wr[$regel] : null) : $regel;
        $rk_fehler[] = sprintf(rk_t('FEHLER.FELD_GRUND'), $bez,
            is_string($roh) ? rk_zeichen_kuerzen($roh, 60) : '(Feld)', rk_grund($erg[1], $rk_rg));
        return array(false, null);
    };

    /* ================= Felder des Reiters Einstellungen ================= */
    if ($rk_hat_einst) {
        $rk_regeln_r = rk_raum_regeln();
        $rk_spalten = rk_ui_raumspalten();
        $rk_neu = array();
        for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) {
            /* Grundlage ist der GESPEICHERTE Raum, nicht die Vorgabe. */
            $rk_r = $rk_cfg['raeume'][$rk_i];
            $rk_bez0 = rk_t('EINST.RAUM') . ' ' . ($rk_i + 1);
            foreach ($rk_spalten as $rk_k => $rk_sp) {
                $rk_f = $rk_sp[0] . '[' . $rk_i . ']';
                if (rk_post_wert($rk_f) === null) { continue; }   // nicht mitgeschickt: bleibt
                list($rk_gut, $rk_w) = $rk_pruefe($rk_regeln_r[$rk_k], $rk_f, $rk_bez0 . ' / ' . $rk_sp[1]);
                if ($rk_gut) { $rk_r[$rk_k] = $rk_w; }
            }
            /* Name ohne Pfad, Pfad ohne Name, Korridor verkehrt - dieselbe
             * Pruefung wie beim Zurueckspielen (rk_raum_quer_pruefen()). */
            $rk_q = rk_raum_quer_pruefen($rk_r);
            if ($rk_q !== null) {
                $rk_fehler[] = sprintf(rk_t($rk_q[0]), $rk_i + 1);
                $rk_falsch[] = $rk_spalten[$rk_q[1]][0] . '[' . $rk_i . ']';
            }
            $rk_neu[$rk_i] = $rk_r;
        }
        $rk_cfg['raeume'] = $rk_neu;

        /* --- Gemeinsames, Aussenluft und Bewertung --- */
        foreach (rk_ui_einzelfelder() as $rk_k => $rk_bez) {
            if (rk_post_wert($rk_k) === null) { continue; }
            list($rk_gut, $rk_w) = $rk_pruefe($rk_k, $rk_k, rk_t($rk_bez));
            if ($rk_gut) { $rk_cfg[$rk_k] = $rk_w; }
        }
        if ($rk_cfg['aussen_art'] === 'eigen' && $rk_cfg['aussen_quelle'] === '') {
            $rk_fehler[] = rk_t('FEHLER.AUSSEN_OHNE_QUELLE');
            $rk_falsch[] = 'aussen_quelle';
        }
        /* Die Ausschaltschwelle liegt unter der Einschaltschwelle. Bis 0.11.13
         * glich rk_config() sie still an (Nr. 19). */
        if ((float) $rk_cfg['kuehlfrei_aus'] > (float) $rk_cfg['kuehlfrei_ein']) {
            $rk_fehler[] = rk_t('FEHLER.KUEHLFREI');
            $rk_falsch[] = 'kuehlfrei_aus';
            $rk_falsch[] = 'kuehlfrei_ein';
        }
        /* Ein Haken schickt nichts mit, wenn er aus ist - das darf hier
         * gelesen werden, weil das Formular sich als zustaendig gemeldet hat. */
        $rk_cfg['verlauf_ein'] = !empty($_POST['verlauf_ein']) ? 1 : 0;

        /* --- Zugangsdaten: eigene Datei, 0600 ---
         * U4 (Durchgang 01.10.2026): hoechstens 256 Zeichen wie beim
         * Zurueckspielen; bis 0.11.13 nahm das Formular ein Passwort mit 300
         * Zeichen, und die eigene Sicherung wurde danach abgewiesen (gemessen,
         * Bericht oberflaeche Nr. 6). Ein leeres Kennwortfeld heisst
         * "unveraendert"; ein Kennwort reist nie mit der Einmalmeldung. */
        $rk_g_neu = rk_geheim();
        $rk_b = rk_post_wert('zug_benutzer');
        if ($rk_b !== null) {
            if (!is_string($rk_b) || strlen(trim($rk_b)) > 256 || preg_match('/[\x00-\x1F\x7F]/', $rk_b)) {
                $rk_falsch[] = 'zug_benutzer';
                $rk_fehler[] = sprintf(rk_t('FEHLER.FELD_GRUND'), rk_t('EINST.ZUG_BENUTZER'),
                    is_string($rk_b) ? rk_zeichen_kuerzen($rk_b, 60) : '(Feld)', rk_grund('FEHLER.ZUGANG'));
            } else {
                $rk_g_neu['benutzer'] = trim($rk_b);
            }
        }
        $rk_pw = rk_post_wert('zug_passwort');
        if ($rk_pw !== null && (!is_string($rk_pw) || strlen($rk_pw) > 256)) {
            $rk_falsch[] = 'zug_passwort';
            $rk_fehler[] = sprintf(rk_t('FEHLER.PASSWORT_LANG'), is_string($rk_pw) ? strlen($rk_pw) : 0);
        } elseif (is_string($rk_pw) && $rk_pw !== '') {
            $rk_g_neu['passwort'] = $rk_pw;
        }
        if (!empty($_POST['zug_loeschen'])) {
            $rk_g_neu = array('benutzer' => '', 'passwort' => '');
        }

        /* --- Sprachausgabe (Nr. 36 b, Stufe 2, seit 0.11.15) ---
         * Jede Beanstandung verhindert das Speichern (Nr. 16); kein Sprechtoken steht in einer
         * Meldung, ein leeres Tokenfeld heisst "behalten", der Haken loescht, beides zugleich
         * ist ein Widerspruch. Adresse und Vorlage nur im Heimnetz (im Modul). */
        $rk_tmangel = array();
        $rk_tbean = array();
        $rk_cfg['tts'] = ansage_formular_lesen($_POST, rk_tts(), $rk_tmangel, $rk_tbean, rk_ansage_opt(),
                                               rk_ansage_k());
        foreach ($rk_tmangel as $rk_tm) { $rk_fehler[] = $rk_tm['text']; }
        foreach ($rk_tbean as $rk_tb) { $rk_falsch[] = $rk_tb; }
        $rk_cfg['ansage_lueften'] = !empty($_POST['ansage_lueften']) ? 1 : 0;
        $rk_cfg['ansage_schimmel'] = !empty($_POST['ansage_schimmel']) ? 1 : 0;
        /* Ruhezeit der Ansagen (seit 08.10.2026): je Feld HH:MM oder leer, beide oder keines. */
        $rk_rgut = true;
        foreach (array('ansage_ruhe_von' => 'EINST.ANSAGE_RUHE', 'ansage_ruhe_bis' => 'EINST.ANSAGE_RUHE_BIS') as $rk_rk => $rk_rb) {
            list($rk_gut, $rk_w) = $rk_pruefe($rk_rk, $rk_rk, rk_t($rk_rb));
            if ($rk_gut) { $rk_cfg[$rk_rk] = $rk_w; } else { $rk_rgut = false; }
        }
        if ($rk_rgut && rk_ansage_ruhe_grund($rk_cfg['ansage_ruhe_von'], $rk_cfg['ansage_ruhe_bis']) !== '') {
            $rk_fehler[] = rk_t('FEHLER.ANSAGE_RUHE');
            $rk_falsch[] = 'ansage_ruhe_von';
            $rk_falsch[] = 'ansage_ruhe_bis';
        }
    }

    /* ===================== Felder des Reiters MQTT ===================== */
    if ($rk_hat_mqtt) {
        $rk_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;
        /* Ein leeres Thema wird beanstandet, nicht still zu 'raumklima' (U3):
         * jedes MQTT-Abo in Loxone bliebe danach stumm. */
        list($rk_gut, $rk_w) = $rk_pruefe('mqtt_topic', 'mqtt_topic', rk_t('MQTT.THEMA'));
        if ($rk_gut) { $rk_cfg['mqtt_topic'] = $rk_w; }
    }

    if (!$rk_hat_einst && !$rk_hat_mqtt) {
        /* Kein Formular hat sich gemeldet. Lieber nichts speichern als
         * alles ueberschreiben - genau daran ist 0.9.8 gescheitert. */
        $rk_fehler[] = rk_t('FEHLER.KEIN_FORMULAR');
    } elseif ($rk_fehler) {
        /* U1/U2: nichts gespeichert, die Eingaben reisen zurueck. */
        array_unshift($rk_fehler, rk_t('ALLG.NICHTS_GESPEICHERT'));
        $rk_eingaben = rk_eingaben_sammeln($rk_hat_einst ? 'einst' : 'mqtt', $rk_falsch);
        rk_log('Speichern beanstandet (' . count($rk_falsch) . ' Feld(er)) - es wurde nichts gespeichert.');
    } else {
        $rk_g_gut = true;
        if ($rk_hat_einst && $rk_g_neu !== rk_geheim()) {
            /* Den Rueckgabewert ansehen (seit 0.11.2): scheitert das Schreiben,
             * wird auch die Konfiguration nicht geschrieben. */
            $rk_g_gut = rk_geheim_speichern($rk_g_neu);
            if (!$rk_g_gut) { $rk_stoerung[] = rk_t('FEHLER.GEHEIM_SCHREIBEN'); }
        }
        if (!$rk_g_gut) {
            rk_log('Speichern: geheim.json liess sich nicht schreiben - es wurde nichts gespeichert.');
        } elseif (rk_config_speichern($rk_cfg)) {
            $rk_meldungen[] = rk_t('ALLG.GESPEICHERT');
            rk_log('Einstellungen gespeichert (' . ($rk_hat_einst ? 'Einstellungen' : '')
                   . ($rk_hat_einst && $rk_hat_mqtt ? '+' : '') . ($rk_hat_mqtt ? 'MQTT' : '') . ').');
        } else {
            $rk_stoerung[] = rk_t('FEHLER.SPEICHERN');
        }
    }
    $rk_tab = isset($_POST['activetab']) && preg_match($rk_muster, (string) $_POST['activetab'])
        ? (string) $_POST['activetab'] : 'tab-settings';
}

/* ---------------- Jetzt abrufen ----------------
 * U11 (Durchgang 01.10.2026): "Abgerufen" nur, wenn wirklich abgerufen wurde,
 * und die Meldungen der Quellen unter "Hinweise zum Abruf" - nicht unter der
 * Ueberschrift der Beanstandungen. */
if ($rk_post && isset($_POST['abrufen'])) {
    list($rk_lief, $rk_s) = rk_ui_abrufen();
    if (!$rk_lief) {
        $rk_stoerung[] = rk_t('ALLG.NICHT_ABGERUFEN');
    } else {
        $rk_meldungen[] = rk_t('ALLG.ABGERUFEN');
        foreach ((array) (isset($rk_s['meldungen']) ? $rk_s['meldungen'] : array()) as $rk_k => $rk_m) {
            $rk_abrufhinweise[] = $rk_k . ': ' . rk_t('MELD.' . $rk_m);
        }
    }
}

/* ---------------- Neues Wortzeichen ----------------
 * U7 (Durchgang 01.10.2026): der Rueckgabewert wird angesehen. Bis 0.11.13
 * meldete die Seite "Ein neues Wortzeichen wurde erzeugt", auch wenn die
 * Konfiguration nicht schreibbar war und das alte Token galt (gemessen,
 * Bericht oberflaeche Nr. 9) - der Anwender importierte die Vorlage neu und
 * suchte den Fehler danach an der falschen Stelle. */
if ($rk_post && isset($_POST['token_neu'])) {
    $rk_cfg = rk_config();
    $rk_cfg['aktionstoken'] = rk_token_erzeugen();
    if (rk_config_speichern($rk_cfg)) {
        $rk_meldungen[] = rk_t('LOX.TOKEN_NEU_OK');
        rk_log('Neues Wortzeichen erzeugt.');
    } else {
        $rk_stoerung[] = rk_t('LOX.TOKEN_NEU_FEHL');
        rk_log('Neues Wortzeichen: die Konfiguration liess sich nicht schreiben - das bisherige gilt weiter.');
    }
    $rk_tab = 'tab-loxone';
}

/* ---------------- Protokoll leeren ----------------
 * U8 (Durchgang 01.10.2026): "geleert" nur, wenn geleert wurde. */
if ($rk_post && isset($_POST['log_leeren'])) {
    if (@file_put_contents($rk_p['log'], '') === false) {
        $rk_stoerung[] = rk_t('LOG.NICHT_GELEERT');
    } else {
        rk_log('Protokoll geleert.');
        $rk_meldungen[] = rk_t('LOG.GELEERT');
    }
    $rk_tab = 'tab-log';
}

/* ---------------- Test ---------------- */
if ($rk_post && isset($_POST['test'])) {
    $rk_testausgabe = rk_test_ausfuehren((string) $_POST['test']);
    $rk_tab = 'tab-test';
}

/* ================= Umleitung nach jedem POST =================
 * Alle Handler sind durch - die Downloads haben vorher mit exit geendet.
 * Das Ergebnis geht in die Einmalmeldung, und der Browser holt die Seite
 * mit GET ab (303: ausdruecklich GET, auch bei Browsern, die 302 als
 * Wiederholung des POST verstehen). Laesst sich die Einmalmeldung nicht
 * schreiben, wird wie bisher unmittelbar gerendert - lieber ein
 * Neuladen-Risiko als eine verlorene Fehlermeldung. */
if ($rk_war_post) {
    if (rk_flash_schreiben(array('tab' => $rk_tab, 'meldungen' => $rk_meldungen,
                                 'fehler' => $rk_fehler, 'stoerung' => $rk_stoerung,
                                 'abruf' => $rk_abrufhinweise, 'testausgabe' => $rk_testausgabe,
                                 'ass' => $rk_ass, 'eingaben' => $rk_eingaben))) {
        header('Location: index.php?form=' . rawurlencode(substr($rk_tab, 4)), true, 303);
        exit;
    }
    rk_log_gebremst('flash_schreiben', 'Die Einmalmeldung liess sich nicht schreiben; '
        . 'die Seite wird ohne Umleitung angezeigt.', 3600);
    if (is_array($rk_eingaben)) { rk_eingaben_aktiv($rk_eingaben); }
} else {
    /* NUR beim GET: beim POST ist die Fehlerliste zugleich der Sammler,
     * mit dem die Handler pruefen - eine alte Meldung darin verhinderte
     * das naechste Speichern (Regeln/04). */
    $rk_flash = rk_flash_lesen();
    if ($rk_flash !== null) {
        $rk_meldungen = isset($rk_flash['meldungen']) ? (array) $rk_flash['meldungen'] : array();
        $rk_fehler = isset($rk_flash['fehler']) ? (array) $rk_flash['fehler'] : array();
        $rk_testausgabe = isset($rk_flash['testausgabe']) ? (string) $rk_flash['testausgabe'] : '';
        $rk_stoerung = isset($rk_flash['stoerung']) ? (array) $rk_flash['stoerung'] : array();
        $rk_abrufhinweise = isset($rk_flash['abruf']) ? (array) $rk_flash['abruf'] : array();
        /* X-2: nur nach einer Beanstandung, und nur fuer diesen einen GET. */
        if (isset($rk_flash['eingaben']) && is_array($rk_flash['eingaben'])) {
            rk_eingaben_aktiv($rk_flash['eingaben']);
        }
        if (isset($rk_flash['tab']) && preg_match($rk_muster, (string) $rk_flash['tab'])) {
            $rk_tab = (string) $rk_flash['tab'];
        }
        if (isset($rk_flash['ass']) && is_array($rk_flash['ass'])) { $rk_ass = $rk_flash['ass']; }
    }
}

/* ================= Werte fuer die Anzeige ================= */
$rk_cfg = rk_config();
$rk_g = rk_geheim();
$rk_stand = rk_stand();
$rk_raeume = rk_raeume();
$rk_mqtt = rk_mqtt_zustand();
/* !empty statt isset - der Unterschied ist die ganze Wirkung.
 *
 * Solange keine einzige Messung gelungen ist, steht in stand.json `ts: 0`.
 * isset() ist darauf WAHR, und `time() - 0` ist der Unix-Zeitstempel
 * selbst. Die Kachel zeigte dann "Letzter Abruf vor 1788652628 Sekunden" -
 * am Geraet gesehen am 06.09.2026. rk_zeile() und rk_mqtt_werte() machen es
 * seit jeher mit !empty richtig; die Oberflaeche widersprach dem eigenen
 * Endpunkt. */
$rk_alter = !empty($rk_stand['ts']) ? max(0, time() - (int) $rk_stand['ts']) : -1;
$rk_basis = rk_endpunkt();
$rk_thema = trim((string) $rk_cfg['mqtt_topic'], '/');
// Nur das Ende lesen, nicht die ganze Datei - siehe rk_log_ende().
$rk_logzeilen = rk_log_ende($rk_p['log'], 400);

/* Eine Zahl anzeigen oder einen Strich. NIE eine erfundene 0: eine 0 bei der
 * Temperatur sieht aus wie eine Messung. */
function rk_z($v, $nach = 1, $einheit = '')
{
    /* Die Einheit geht durch rk_e() - sie wird deshalb als ZEICHEN
     * uebergeben ('°C', 'g/m³'), nie als HTML-Entitaet. Bis 0.11.7 standen
     * an den vier Aufrufern '&deg;C' und 'g/m&sup3;'; am Geraet stand
     * daraufhin in der Raumtabelle woertlich '22,5 &deg;C' (gemessen
     * 06.09.2026, vier Stellen je Raum). hausstandard_pruefen.py sieht
     * Entitaeten nur in Sprachdateien, nicht als Argument im PHP. */
    if ($v === null || !is_numeric($v)) { return '&ndash;'; }
    return rk_e(number_format((float) $v, $nach, ',', '')) . ($einheit !== '' ? ' ' . rk_e($einheit) : '');
}

$rk_rahmen = class_exists('LBWeb', false);
$rk_ft = rk_e(rk_formtoken());
/* U9 (Durchgang 01.10.2026): der erste Zustand dieser Anfrage - sonst hat die
 * Selbstheilung ihn beseitigt, bevor er angezeigt wird. */
$rk_lage = rk_config_lage_anfang();

if ($rk_rahmen) {
    LBWeb::lbheader('Raumklima', 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Eine Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   einen Rollbehaelter. Ohne ihn steht die letzte Spalte AUSSERHALB und ist
   nicht bloss unbequem, sondern unerreichbar: .sm-tbl hat width:100%, und
   .sm-wrap hat max-width ohne Ueberlauf.
   Im Browser gemessen am 28.08.2026, Fenster 1280 px:
     ohne  sm-wrap clientWidth 980, scrollWidth 1130  -> 150 px Ueberstand
     mit   sm-wrap clientWidth 980, scrollWidth  980  -> kein Ueberstand
   Raumklima hat die breitesten Eingabetabellen des Bestands: acht und zehn
   Spalten. Betroffen war zuletzt die Spalte CO2-Grenze. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Die erste Spalte ist in allen vier Rolltabellen die RAUMNUMMER. Rollt
   man nach rechts, lief sie bis 0.11.4 mit hinaus - und dann steht man
   vor einer Reihe Eingabefelder, ohne zu wissen, zu welchem Raum sie
   gehoeren. position:sticky haelt sie fest.

   Zwei Dinge gehoeren dazu, sonst sieht es kaputt aus: eine eigene
   Hintergrundfarbe (sonst rollt der Inhalt sichtbar DURCH die Zelle)
   und ein Schatten als Rahmen - border-collapse zeichnet den rechten
   Rahmen einer klebenden Zelle nicht mit. */
.sm-breit .sm-tbl th:first-child,
.sm-breit .sm-tbl td:first-child {
    position: -webkit-sticky; position: sticky; left: 0; z-index: 2;
    background: #fff; box-shadow: 1px 0 0 #ccc; }
.sm-breit .sm-tbl th:first-child { background: #eef3e6; z-index: 3; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
/* .sm-log ist eine EIGENE Klasse neben .sm-pre, und der Hausstandard
   verlangt fuer solche Einzelfaelle eine Begruendung an Ort und Stelle.
   Sie steht hier: das Protokoll ist die einzige Ausgabe des Plugins, die
   Hunderte Zeilen lang werden kann. Ohne max-height schiebt sie den Rest
   des Reiters aus dem Bild, und die kleinere Schrift bringt eine
   Protokollzeile mit Zeitstempel auf einem Telefon noch in eine Zeile.
   Alles Uebrige ist mit .sm-pre gleich. */
.sm-log { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.82em;
    overflow: auto; margin: 8px 0; max-height: 420px; white-space: pre-wrap;
    font-family: Consolas, "Courier New", monospace; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. sm-active gehoert schon ins
   ausgelieferte HTML, sonst ist die Seite ohne JavaScript vollstaendig leer. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Ergaenzung zum Vorlagenblock (Durchgang 01.10.2026, X-2): ein beanstandetes
   Feld ist rot umrandet; aria-invalid sagt es Vorleseprogrammen. Bauart
   ACTiKamera 1.9.26. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }

</style>

<div class="sm-wrap">

<?php if ($rk_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('rk_e', $rk_meldungen)) ?></div>
<?php } ?>
<?php if ($rk_fehler) { ?>
<div class="sm-warnung"><b><?= rk_e(rk_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('rk_e', $rk_fehler)) ?></div>
<?php } ?>
<?php if ($rk_stoerung) { ?>
<div class="sm-warnung"><b><?= rk_e(rk_t('ALLG.NICHT_GELUNGEN')) ?></b><br><?= implode('<br>', array_map('rk_e', $rk_stoerung)) ?></div>
<?php } ?>
<?php if ($rk_abrufhinweise) { ?>
<div class="sm-warnung"><b><?= rk_e(rk_t('ALLG.H_ABRUF')) ?></b><br><?= implode('<br>', array_map('rk_e', $rk_abrufhinweise)) ?></div>
<?php } ?>
<?php
/* Jeder Zustand, den der Code erzeugen kann, braucht seinen Satz. Bis
 * 0.10.1 sah eine kaputte Konfiguration aus wie eine leere - und die
 * Oberflaeche sagte dazu nichts, waehrend im Hintergrund die Zweitschrift
 * ueberschrieben wurde. */
if ($rk_lage === 'kaputt') { ?>
<div class="sm-warnung"><b><?= rk_e(rk_t('ALLG.CFG_KAPUTT_H')) ?></b><br><?= rk_t('ALLG.CFG_KAPUTT') ?></div>
<?php } elseif ($rk_lage === 'zweitschrift') { ?>
<div class="sm-hinweis"><?= rk_t('ALLG.CFG_ZWEITSCHRIFT') ?></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= rk_e(rk_t('ALLG.RAEUME')) ?>
    <b><?= count($rk_raeume) ?></b>
    <span class="sm-hilfe"><?= (int) (isset($rk_stand['ok']) ? array_reduce((array) $rk_stand['raeume'], function ($c, $e) { return $c + (int) $e['ok']; }, 0) : 0) ?> <?= rk_e(rk_t('ALLG.MIT_WERTEN')) ?></span>
  </div>
  <div class="sm-kachel"><?= rk_e(rk_t('ALLG.LUEFTEN')) ?>
    <b><?= isset($rk_stand['lueften_n']) ? (int) $rk_stand['lueften_n'] : 0 ?></b>
    <span class="sm-hilfe"><?= rk_e(rk_t('ALLG.LOHNT_JETZT')) ?></span>
  </div>
  <div class="sm-kachel"><?= rk_e(rk_t('ALLG.SCHIMMEL')) ?>
    <b class="<?= !empty($rk_stand['schimmel_n']) ? 'sm-aus' : 'sm-an' ?>"><?= isset($rk_stand['schimmel_n']) ? (int) $rk_stand['schimmel_n'] : 0 ?></b>
    <span class="sm-hilfe"><?= rk_e(rk_t('ALLG.GEFAEHRDET')) ?></span>
  </div>
  <div class="sm-kachel"><?= rk_e(rk_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= rk_e(rk_dauer_text($rk_alter)) ?></b>
    <span class="sm-hilfe"><?= $rk_alter < 0 ? rk_e(rk_t('ALLG.NIE_HILFE'))
        : sprintf(rk_e(rk_t('ALLG.SEKUNDEN_GENAU')), (int) $rk_alter) ?></span>
  </div>
  <!-- Der grosse Wert ist die MQTT-Veroeffentlichung DIESES Plugins (mqtt_ein),
       der Autostart des Gateways steht klein darunter. Bis 0.11.10 stand hier
       der Autostart des Gateways; "MQTT ein" las sich, als sende das Plugin,
       auch wenn es gar nicht veroeffentlichte.
       Vorbild ZendureSolarFlow 0.9.21 und BatterieBMS 0.9.22. Ohne
       MQTT-Abschnitt in general.json heisst der Autostart "nicht feststellbar"
       statt "aus". -->
  <div class="sm-kachel">MQTT
    <b class="<?= !empty($rk_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($rk_cfg['mqtt_ein']) ? rk_e(rk_t('ALLG.EIN')) : rk_e(rk_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= rk_e(sprintf(rk_t('ALLG.KACHEL_MQTT_HILFE'),
        !$rk_mqtt['gefunden'] ? rk_t('ALLG.NICHT_FESTSTELLBAR')
        : ($rk_mqtt['autostart'] ? rk_t('ALLG.EIN') : rk_t('ALLG.AUS')))) ?></span>
  </div>
</div>

<?php if (!empty($rk_stand['meldungen'])) { ?>
<div class="sm-warnung"><b><?= rk_e(rk_t('ALLG.LETZTE_STOERUNG')) ?></b><br>
<?php foreach ($rk_stand['meldungen'] as $rk_k => $rk_m) { ?>
<span class="sm-mono"><?= rk_e($rk_k) ?></span>: <?= rk_e(rk_t('MELD.' . $rk_m)) ?><br>
<?php } ?>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und Eingaben in anderen Reitern gehen nicht verloren.
     Welcher Reiter offen ist, entscheidet der SERVER - sm-active steht schon
     im ausgelieferten HTML, an der Leiste und am Bereich. -->
<!-- Ausgeschrieben, nicht als Schleife. hausstandard_pruefen.py sucht
     data-ziel="tab-..." als LITERAL; bei einer Schleife findet es null
     Reiter und setzt die Spalte auf "-", also "trifft nicht zu" - und ein
     Strich sammelt sich beim Ueberfliegen wie ein Haken ein. Die
     Positivliste fuer activetab entsteht weiterhin aus $rk_reiter. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $rk_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= rk_e(rk_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $rk_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $rk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= rk_e(rk_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $rk_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= rk_e(rk_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $rk_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= rk_e(rk_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<?php /* U13 (Durchgang 01.10.2026): EINE gesammelte Legende oben im Reiter
         (Regeln/04). Bis 0.11.13 standen drei Einzellegenden ueber einzelnen
         Reihen, und ueber der Sicherungsreihe nannte die naechste nur Orange
         (gemessen, Bericht oberflaeche Nr. 15). */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rk_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rk_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>

<h2><?= rk_e(rk_t('EINST.H_LAGE')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.LAGE_ERKLAERUNG') ?></div>

<?php if ($rk_stand && isset($rk_stand['raeume']) && $rk_stand['raeume']) { ?>
<h3><?= rk_e(rk_t('EINST.H_TABELLE')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr>
  <th><?= rk_e(rk_t('TAB.RAUM')) ?></th>
  <th><?= rk_e(rk_t('TAB.T')) ?></th>
  <th><?= rk_e(rk_t('TAB.RF')) ?></th>
  <th><?= rk_e(rk_t('TAB.TAU')) ?></th>
  <th><?= rk_e(rk_t('TAB.ABS')) ?></th>
  <th><?= rk_e(rk_t('TAB.OBER')) ?></th>
  <th><?= rk_e(rk_t('TAB.EMPFEHLUNG')) ?></th>
</tr>
<?php foreach ($rk_stand['raeume'] as $rk_nr => $rk_r) { ?>
<tr>
  <td><b><?= rk_e($rk_r['name']) ?></b>
    <?php if ($rk_r['feucht']) { ?><br><span class="sm-aus"><?= rk_e(rk_t('TAB.ZU_FEUCHT')) ?></span><?php } ?>
    <?php if ($rk_r['trocken']) { ?><br><span class="sm-aus"><?= rk_e(rk_t('TAB.ZU_TROCKEN')) ?></span><?php } ?>
    <?php if (!empty($rk_r['steht'])) { ?><br><span class="sm-aus"><?= rk_e(rk_t('TAB.STEHT')) ?></span><?php } ?>
    <?php if (!$rk_r['ok'] && isset($rk_r['alter']) && $rk_r['alter'] > 0) { ?>
    <br><span class="sm-aus"><?= sprintf(rk_e(rk_t('TAB.OHNE_SEIT')), (int) round($rk_r['alter'] / 60)) ?></span>
    <?php } ?>
  </td>
  <td><?= rk_z($rk_r['t'], 1, '°C') ?></td>
  <td><?= rk_z($rk_r['rf'], 0, '%') ?></td>
  <td><?= rk_z($rk_r['taupunkt'], 1, '°C') ?></td>
  <td><?= rk_z($rk_r['absolut'], 2, 'g/m³') ?></td>
  <td><?= rk_z($rk_r['ober_t'], 1, '°C') ?>
    <?php if ($rk_r['ober_rf'] !== null) { ?>
    <br><span class="<?= (int) $rk_r['schimmel'] === 1 ? 'sm-aus' : '' ?>"><?= rk_z($rk_r['ober_rf'], 0, '%') ?></span>
    <?php } ?>
    <?php if (isset($rk_r['nass24']) && $rk_r['nass24'] > 0) { ?>
    <br><span class="sm-hilfe"><?= sprintf(rk_e(rk_t('TAB.NASS24')), rk_e(number_format((float) $rk_r['nass24'], 1, ',', ''))) ?></span>
    <?php } ?>
  </td>
  <td>
    <?php if (!$rk_r['ok']) { ?>
      <?= rk_e(rk_t('TAB.KEINE_WERTE')) ?>
    <?php } elseif ($rk_r['lueften']) { ?>
      <b class="sm-an"><?= rk_e(rk_t('TAB.JETZT_LUEFTEN')) ?></b>
      <span class="sm-hilfe">(<?= rk_e(rk_t('MELD.' . strtoupper($rk_r['grund']))) ?>)</span><br>
      <span class="sm-hilfe"><?= sprintf(rk_e(rk_t('TAB.GEWINN')), rk_e(number_format((float) $rk_r['gewinn'], 2, ',', ''))) ?></span>
      <?php if (isset($rk_r['dauer']) && $rk_r['dauer'] > 0) { ?>
      <br><span class="sm-hilfe"><?= sprintf(rk_e(rk_t('TAB.DAUER')), (int) $rk_r['dauer']) ?><?php
          if (isset($rk_r['kosten']) && $rk_r['kosten'] > 0) {
              echo ' &middot; ' . sprintf(rk_e(rk_t('TAB.KOSTEN')), rk_e(number_format((float) $rk_r['kosten'], 0, ',', '.')));
          } ?></span>
      <?php } ?>
    <?php } elseif ($rk_r['best_in'] >= 0 && $rk_r['best_in'] < 90) { ?>
      <?= sprintf(rk_e(rk_t('TAB.SPAETER_MIN')), (int) $rk_r['best_std'], (int) $rk_r['best_in']) ?>
    <?php } elseif ($rk_r['best_in'] >= 0) { ?>
      <?= sprintf(rk_e(rk_t('TAB.SPAETER')), (int) $rk_r['best_std'], (int) round($rk_r['best_in'] / 60)) ?>
    <?php } else { ?>
      <?= rk_e(rk_t('MELD.' . strtoupper($rk_r['grund']))) ?>
    <?php } ?>
  </td>
</tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= rk_t('EINST.TABELLE_HINWEIS') ?></p>
<?php } else { ?>
<div class="sm-hinweis"><?= rk_t('EINST.NOCH_NICHTS') ?></div>
<?php } ?>

<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="abrufen" value="1"><?= rk_e(rk_t('EINST.K_ABRUFEN')) ?></button>
  </form>
</div>

<h2><?= rk_e(rk_t('EINST.H_ASSISTENT')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.ASSISTENT_ERKLAERUNG') ?></div>
<?php $rk_mslist = rk_miniserver(); ?>
<?php if (!$rk_mslist) { ?>
<div class="sm-warnung"><?= rk_t('EINST.MS_KEINER_LANG') ?></div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
<div class="sm-feld">
  <label for="rk_mskat"><?= rk_e(rk_t('EINST.MS_KATEGORIE')) ?></label>
  <input data-role="none" type="text" id="rk_mskat" name="ms_kategorie"
         value="<?= rk_e($rk_ass !== null ? $rk_ass['kategorie'] : '') ?>"
         placeholder="<?= rk_e(rk_t('EINST.MS_KATEGORIE_BEISPIEL')) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.MS_KATEGORIE_HILFE') ?></p>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ms_suchen" value="1"><?= rk_e(rk_t('EINST.K_MS_SUCHEN')) ?></button>
</div>
</form>
<?php } ?>

<?php if ($rk_ass !== null) { ?>
<div class="sm-hinweis">
<?= sprintf(rk_e(rk_t('EINST.MS_GEFUNDEN')), rk_e($rk_ass['ms']['name']),
            rk_e($rk_ass['ms']['adresse']), (int) $rk_ass['raeume'],
            (int) $rk_ass['bausteine'], count($rk_ass['voll'])) ?>
</div>
<?php /* Eine Zeile JE PROBE - und die Ueberschrift sagt, wie viele es waren.
       * Bis 0.11.1 stand hier eine einzige Zeile, und der darin genannte
       * Pfad galt auch fuer die Feuchte, ohne dass sie je geprobt worden
       * waere. Wer "abgelesen" schreibt, muss so viele Zeilen zeigen, wie
       * er abgelesen hat. */
foreach ((array) $rk_ass['proben']['t'] as $rk_pr) {
    if (!$rk_pr['ok']) { continue; } ?>
<div class="sm-hinweis"><?= sprintf(rk_t('EINST.MS_PROBE_OK_T'),
    rk_e($rk_pr['name']), rk_e($rk_pr['raum']), rk_e($rk_pr['roh']),
    rk_e((string) $rk_pr['zahl']), rk_e($rk_pr['pfad']), rk_e($rk_pr['typ'])) ?></div>
<?php }
foreach ((array) $rk_ass['proben']['rf'] as $rk_pr) {
    if (!$rk_pr['ok']) { continue; } ?>
<div class="sm-hinweis"><?= sprintf(rk_t('EINST.MS_PROBE_OK_RF'),
    rk_e($rk_pr['name']), rk_e($rk_pr['raum']), rk_e($rk_pr['roh']),
    rk_e((string) $rk_pr['zahl']), rk_e($rk_pr['pfad']), rk_e($rk_pr['typ'])) ?></div>
<?php } ?>
<?php if ($rk_ass['kein_platz'] > 0 && !$rk_ass['geschrieben']) { ?>
<div class="sm-warnung"><?= sprintf(rk_e(rk_t('EINST.MS_KEIN_PLATZ_N')),
    (int) $rk_ass['kein_platz'], RK_RAEUME) ?></div>
<?php } ?>
<?php if ($rk_ass['mehrdeutig'] > 0 && !$rk_ass['geschrieben']) { ?>
<div class="sm-warnung"><?= sprintf(rk_e(rk_t('EINST.MS_MEHRDEUTIG_N')),
    (int) $rk_ass['mehrdeutig']) ?></div>
<?php } ?>
<?php if ($rk_ass['vorschlag']) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_RAUM')) ?></th>
    <th><?= rk_e(rk_t('EINST.PFAD_T')) ?></th>
    <th><?= rk_e(rk_t('EINST.PFAD_RF')) ?></th>
    <th><?= rk_e(rk_t('EINST.MS_SP_LAGE')) ?></th></tr>
<?php /* Die Lage steht im Handler, nicht hier - siehe dort. Diese Tabelle
       * zeigt nur noch an. Bis 0.11.1 rechnete sie es selbst aus, mit
       * anderen Bedingungen als die Uebernahme, und versprach dreizehn
       * Raeume, wo zwoelf angelegt wurden. */
$rk_lagen = array(
    'schon_da'       => array('',       'EINST.MS_SCHON_DA'),
    'wird_angelegt'  => array('sm-an',  'EINST.MS_WIRD_ANGELEGT'),
    'mehrdeutig'     => array('sm-aus', 'EINST.MS_MEHRDEUTIG'),
    'kein_platz'     => array('sm-aus', 'EINST.MS_KEIN_PLATZ'),
    'unvollstaendig' => array('sm-aus', 'EINST.MS_UNVOLLSTAENDIG'),
);
foreach ($rk_ass['vorschlag'] as $rk_v) {
    $rk_l = isset($rk_lagen[$rk_v['lage']]) ? $rk_lagen[$rk_v['lage']]
                                            : array('sm-aus', 'EINST.MS_UNVOLLSTAENDIG'); ?>
<tr>
  <td><b><?= rk_e($rk_v['raum']) ?></b></td>
  <td><?= $rk_v['t'] !== null ? rk_e($rk_v['t']['name']) : '<span class="sm-aus">&ndash;</span>' ?>
      <?= $rk_v['t_mehr'] > 0 ? '<br><span class="sm-hilfe">' . sprintf(rk_e(rk_t('EINST.MS_MEHRERE')), (int) $rk_v['t_mehr'] + 1) . '</span>' : '' ?></td>
  <td><?= $rk_v['rf'] !== null ? rk_e($rk_v['rf']['name']) : '<span class="sm-aus">&ndash;</span>' ?>
      <?= $rk_v['rf_mehr'] > 0 ? '<br><span class="sm-hilfe">' . sprintf(rk_e(rk_t('EINST.MS_MEHRERE')), (int) $rk_v['rf_mehr'] + 1) . '</span>' : '' ?></td>
  <td><?php if ($rk_l[0] === '') { ?><?= rk_e(rk_t($rk_l[1])) ?><?php }
           else { ?><span class="<?= $rk_l[0] ?>"><?= rk_e(rk_t($rk_l[1])) ?></span><?php } ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php if ($rk_ass['voll'] && $rk_ass['probe'] !== null && $rk_ass['probe']['ok']
          && !$rk_ass['geschrieben']) { ?>
<div class="sm-warnung"><?= rk_t('EINST.MS_VOR_UEBERNAHME') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
<input data-role="none" type="hidden" name="ms_kategorie" value="<?= rk_e($rk_ass['kategorie']) ?>">
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ms_uebernehmen" value="1"><?= rk_e(rk_t('EINST.K_MS_UEBERNEHMEN')) ?></button>
</div>
</form>
<?php } ?>
<?php } else { ?>
<div class="sm-warnung"><?= rk_t('EINST.MS_NICHTS_GEFUNDEN') ?></div>
<?php } ?>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="<?= rk_e($rk_tab) ?>">
<input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
<!-- Sagt dem Speichern-Handler, welche Felder dieses Formular besitzt.
     Ohne diese Marke uebernahm er auch die Felder der anderen Reiter als
     leer - und loeschte sie damit. -->
<input data-role="none" type="hidden" name="feld_einst" value="1">

<h2><?= rk_e(rk_t('EINST.H_QUELLEN')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.QUELLEN_ERKLAERUNG') ?></div>

<div class="sm-feld">
  <label for="rk_quelle"><?= rk_e(rk_t('EINST.QUELLE')) ?></label>
  <input data-role="none" type="text" id="rk_quelle" name="quelle" value="<?= rk_e(rk_ein('quelle', $rk_cfg['quelle'])) ?>"<?= rk_mark('quelle') ?> placeholder="http://gateway-im-heimnetz/get_livedata_info">
  <p class="sm-hilfe"><?= rk_t('EINST.QUELLE_HILFE') ?></p>
</div>

<div class="sm-breit">
<table class="sm-tbl">
<tr>
  <th><?= rk_e(rk_t('EINST.RAUM')) ?></th>
  <th><?= rk_e(rk_t('EINST.NAME')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_T')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_RF')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_CO2')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_FENSTER')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_ZULUFT')) ?></th>
  <th><?= rk_e(rk_t('EINST.EIGENE_QUELLE')) ?></th>
  <th><?= rk_e(rk_t('EINST.QUELLE_RF')) ?></th>
  <th><?= rk_e(rk_t('EINST.EINHEIT')) ?></th>
</tr>
<?php for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) { $rk_r = $rk_cfg['raeume'][$rk_i]; ?>
<tr>
  <td><?= $rk_i + 1 ?></td>
  <td><input data-role="none" type="text" size="14" name="r_name[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_name[' . $rk_i . ']', $rk_r['name'])) ?>"<?= rk_mark('r_name[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="20" name="r_pfad_t[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_pfad_t[' . $rk_i . ']', $rk_r['pfad_t'])) ?>"<?= rk_mark('r_pfad_t[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="20" name="r_pfad_rf[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_pfad_rf[' . $rk_i . ']', $rk_r['pfad_rf'])) ?>"<?= rk_mark('r_pfad_rf[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="16" name="r_pfad_co2[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_pfad_co2[' . $rk_i . ']', $rk_r['pfad_co2'])) ?>"<?= rk_mark('r_pfad_co2[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="16" name="r_pfad_fenster[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_pfad_fenster[' . $rk_i . ']', $rk_r['pfad_fenster'])) ?>"<?= rk_mark('r_pfad_fenster[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="16" name="r_pfad_zuluft[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_pfad_zuluft[' . $rk_i . ']', $rk_r['pfad_zuluft'])) ?>"<?= rk_mark('r_pfad_zuluft[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="18" name="r_quelle[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_quelle[' . $rk_i . ']', $rk_r['quelle'])) ?>"<?= rk_mark('r_quelle[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="18" name="r_quelle_rf[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_quelle_rf[' . $rk_i . ']', $rk_r['quelle_rf'])) ?>"<?= rk_mark('r_quelle_rf[' . $rk_i . ']') ?>></td>
  <td><select data-role="none" name="r_einheit_t[<?= $rk_i ?>]"<?= rk_mark('r_einheit_t[' . $rk_i . ']') ?>>
    <option value="C"<?= rk_ein('r_einheit_t[' . $rk_i . ']', $rk_r['einheit_t']) === 'C' ? ' selected' : '' ?>>&deg;C</option>
    <option value="F"<?= rk_ein('r_einheit_t[' . $rk_i . ']', $rk_r['einheit_t']) === 'F' ? ' selected' : '' ?>>&deg;F</option>
  </select>
  <select data-role="none" name="r_einheit_rf[<?= $rk_i ?>]"<?= rk_mark('r_einheit_rf[' . $rk_i . ']') ?>>
    <option value="proz"<?= rk_ein('r_einheit_rf[' . $rk_i . ']', $rk_r['einheit_rf']) === 'proz' ? ' selected' : '' ?>>%</option>
    <option value="anteil"<?= rk_ein('r_einheit_rf[' . $rk_i . ']', $rk_r['einheit_rf']) === 'anteil' ? ' selected' : '' ?>>0&ndash;1</option>
  </select></td>
</tr>
<?php } ?>
</table>
</div>

<h3><?= rk_e(rk_t('EINST.H_EIGENSCHAFTEN')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr>
  <th><?= rk_e(rk_t('EINST.RAUM')) ?></th>
  <th>fRsi</th>
  <th><?= rk_e(rk_t('EINST.SOLL_MIN')) ?></th>
  <th><?= rk_e(rk_t('EINST.SOLL_MAX')) ?></th>
  <th><?= rk_e(rk_t('EINST.ART')) ?></th>
  <th><?= rk_e(rk_t('EINST.ERD_T')) ?></th>
  <th><?= rk_e(rk_t('EINST.VOLUMEN')) ?></th>
  <th><?= rk_e(rk_t('EINST.FENSTERART')) ?></th>
  <th><?= rk_e(rk_t('EINST.T_SOLL')) ?></th>
  <th><?= rk_e(rk_t('EINST.CO2_MAX')) ?></th>
  <th><?= rk_e(rk_t('EINST.WRG_ETA')) ?></th>
  <th><?= rk_e(rk_t('EINST.WASSER_G')) ?></th>
  <th><?= rk_e(rk_t('EINST.RUHE_VON')) ?></th>
  <th><?= rk_e(rk_t('EINST.RUHE_BIS')) ?></th>
  <th><?= rk_e(rk_t('EINST.PERSONEN')) ?></th>
</tr>
<?php for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) { $rk_r = $rk_cfg['raeume'][$rk_i]; ?>
<tr>
  <td><?= $rk_i + 1 ?><?= $rk_r['name'] !== '' ? ' ' . rk_e($rk_r['name']) : '' ?></td>
  <td><input data-role="none" type="text" size="4" name="r_frsi[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_frsi[' . $rk_i . ']', $rk_r['frsi'])) ?>"<?= rk_mark('r_frsi[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="3" name="r_min[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_min[' . $rk_i . ']', $rk_r['soll_min'])) ?>"<?= rk_mark('r_min[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="3" name="r_max[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_max[' . $rk_i . ']', $rk_r['soll_max'])) ?>"<?= rk_mark('r_max[' . $rk_i . ']') ?>></td>
  <td><select data-role="none" name="r_art[<?= $rk_i ?>]"<?= rk_mark('r_art[' . $rk_i . ']') ?>>
    <option value="aussen"<?= rk_ein('r_art[' . $rk_i . ']', $rk_r['art']) === 'aussen' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_AUSSEN')) ?></option>
    <option value="keller"<?= rk_ein('r_art[' . $rk_i . ']', $rk_r['art']) === 'keller' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_KELLER')) ?></option>
    <option value="innen"<?= rk_ein('r_art[' . $rk_i . ']', $rk_r['art']) === 'innen' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_INNEN')) ?></option>
  </select></td>
  <td><input data-role="none" type="text" size="4" name="r_erd_t[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_erd_t[' . $rk_i . ']', $rk_r['erd_t'])) ?>"<?= rk_mark('r_erd_t[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="4" name="r_volumen[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_volumen[' . $rk_i . ']', $rk_r['volumen'])) ?>"<?= rk_mark('r_volumen[' . $rk_i . ']') ?>></td>
  <td><select data-role="none" name="r_fenster[<?= $rk_i ?>]"<?= rk_mark('r_fenster[' . $rk_i . ']') ?>>
    <option value="kipp"<?= rk_ein('r_fenster[' . $rk_i . ']', $rk_r['fenster']) === 'kipp' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.F_KIPP')) ?></option>
    <option value="stoss"<?= rk_ein('r_fenster[' . $rk_i . ']', $rk_r['fenster']) === 'stoss' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.F_STOSS')) ?></option>
    <option value="quer"<?= rk_ein('r_fenster[' . $rk_i . ']', $rk_r['fenster']) === 'quer' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.F_QUER')) ?></option>
  </select></td>
  <td><input data-role="none" type="text" size="4" name="r_t_soll[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_t_soll[' . $rk_i . ']', $rk_r['t_soll'])) ?>"<?= rk_mark('r_t_soll[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="5" name="r_co2_max[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_co2_max[' . $rk_i . ']', $rk_r['co2_max'])) ?>"<?= rk_mark('r_co2_max[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="4" name="r_wrg_eta[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_wrg_eta[' . $rk_i . ']', $rk_r['wrg_eta'])) ?>"<?= rk_mark('r_wrg_eta[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="5" name="r_wasser_g[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_wasser_g[' . $rk_i . ']', $rk_r['wasser_g'])) ?>"<?= rk_mark('r_wasser_g[' . $rk_i . ']') ?>></td>
  <td><input data-role="none" type="text" size="5" name="r_ruhe_von[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_ruhe_von[' . $rk_i . ']', $rk_r['ruhe_von'])) ?>"<?= rk_mark('r_ruhe_von[' . $rk_i . ']') ?> placeholder="22:00"></td>
  <td><input data-role="none" type="text" size="5" name="r_ruhe_bis[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_ruhe_bis[' . $rk_i . ']', $rk_r['ruhe_bis'])) ?>"<?= rk_mark('r_ruhe_bis[' . $rk_i . ']') ?> placeholder="06:00"></td>
  <td><input data-role="none" type="text" size="3" name="r_personen[<?= $rk_i ?>]" value="<?= rk_e(rk_ein('r_personen[' . $rk_i . ']', $rk_r['personen'])) ?>"<?= rk_mark('r_personen[' . $rk_i . ']') ?>></td>
</tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= rk_t('EINST.TABELLE_FELDER') ?></p>
<p class="sm-hilfe"><?= rk_t('EINST.ART_HILFE') ?></p>
<p class="sm-hilfe"><?= rk_t('EINST.EINHEIT_HILFE') ?></p>

<h3><?= rk_e(rk_t('EINST.H_ZUGANG')) ?></h3>
<div class="sm-feld">
  <label for="rk_zb"><?= rk_e(rk_t('EINST.ZUG_BENUTZER')) ?></label>
  <input data-role="none" type="text" id="rk_zb" name="zug_benutzer" value="<?= rk_e(rk_ein('zug_benutzer', $rk_g['benutzer'])) ?>"<?= rk_mark('zug_benutzer') ?>>
</div>
<div class="sm-feld">
  <label for="rk_zp"><?= rk_e(rk_t('EINST.ZUG_PASSWORT')) ?></label>
  <input data-role="none" type="password" id="rk_zp" name="zug_passwort" value=""<?= rk_mark('zug_passwort') ?> autocomplete="new-password">
  <p class="sm-hilfe"><?= sprintf(rk_t('EINST.ZUG_HILFE'), strlen((string) $rk_g['passwort'])) ?></p>
  <label><input data-role="none" type="checkbox" name="zug_loeschen" value="1"<?= rk_haken('zug_loeschen', false) ?>> <?= rk_e(rk_t('EINST.ZUG_LOESCHEN')) ?></label>
</div>

<h2><?= rk_e(rk_t('EINST.H_AUSSEN')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.AUSSEN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="rk_aart"><?= rk_e(rk_t('EINST.AUSSEN_ART')) ?></label>
  <select data-role="none" id="rk_aart" name="aussen_art"<?= rk_mark('aussen_art') ?>>
    <option value="meteo"<?= rk_ein('aussen_art', $rk_cfg['aussen_art']) === 'meteo' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_METEO')) ?></option>
    <option value="eigen"<?= rk_ein('aussen_art', $rk_cfg['aussen_art']) === 'eigen' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_EIGEN')) ?></option>
  </select>
</div>
<div class="sm-feld">
  <label for="rk_breite"><?= rk_e(rk_t('EINST.BREITE')) ?></label>
  <input data-role="none" type="text" id="rk_breite" name="breite" value="<?= rk_e(rk_ein('breite', $rk_cfg['breite'])) ?>"<?= rk_mark('breite') ?> placeholder="51.3183">
</div>
<div class="sm-feld">
  <label for="rk_laenge"><?= rk_e(rk_t('EINST.LAENGE')) ?></label>
  <input data-role="none" type="text" id="rk_laenge" name="laenge" value="<?= rk_e(rk_ein('laenge', $rk_cfg['laenge'])) ?>"<?= rk_mark('laenge') ?> placeholder="9.4896">
  <p class="sm-hilfe"><?= rk_t('EINST.ORT_HILFE') ?></p>
<?php if ($rk_cfg['aussen_art'] === 'meteo' && (trim((string) $rk_cfg['breite']) === '' || trim((string) $rk_cfg['laenge']) === '')) { ?>
  <div class="sm-warnung"><?= rk_e(rk_t('MELD.KEIN_STANDORT')) ?></div>
<?php } ?>
</div>
<div class="sm-feld">
  <label for="rk_aq"><?= rk_e(rk_t('EINST.AUSSEN_QUELLE')) ?></label>
  <input data-role="none" type="text" id="rk_aq" name="aussen_quelle" value="<?= rk_e(rk_ein('aussen_quelle', $rk_cfg['aussen_quelle'])) ?>"<?= rk_mark('aussen_quelle') ?>>
</div>
<div class="sm-feld">
  <label for="rk_at"><?= rk_e(rk_t('EINST.AUSSEN_T')) ?></label>
  <input data-role="none" type="text" id="rk_at" name="aussen_t" value="<?= rk_e(rk_ein('aussen_t', $rk_cfg['aussen_t'])) ?>"<?= rk_mark('aussen_t') ?>>
</div>
<div class="sm-feld">
  <label for="rk_arf"><?= rk_e(rk_t('EINST.AUSSEN_RF')) ?></label>
  <input data-role="none" type="text" id="rk_arf" name="aussen_rf" value="<?= rk_e(rk_ein('aussen_rf', $rk_cfg['aussen_rf'])) ?>"<?= rk_mark('aussen_rf') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.AUSSEN_EIGEN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_aeh"><?= rk_e(rk_t('EINST.EINHEIT')) ?></label>
  <select data-role="none" id="rk_aeh" name="aussen_einheit_t"<?= rk_mark('aussen_einheit_t') ?>>
    <option value="C"<?= rk_ein('aussen_einheit_t', $rk_cfg['aussen_einheit_t']) === 'C' ? ' selected' : '' ?>>&deg;C</option>
    <option value="F"<?= rk_ein('aussen_einheit_t', $rk_cfg['aussen_einheit_t']) === 'F' ? ' selected' : '' ?>>&deg;F</option>
  </select>
  <select data-role="none" name="aussen_einheit_rf"<?= rk_mark('aussen_einheit_rf') ?>>
    <option value="proz"<?= rk_ein('aussen_einheit_rf', $rk_cfg['aussen_einheit_rf']) === 'proz' ? ' selected' : '' ?>>%</option>
    <option value="anteil"<?= rk_ein('aussen_einheit_rf', $rk_cfg['aussen_einheit_rf']) === 'anteil' ? ' selected' : '' ?>>0&ndash;1</option>
  </select>
  <p class="sm-hilfe"><?= rk_t('EINST.EINHEIT_HILFE') ?></p>
</div>

<h2><?= rk_e(rk_t('EINST.H_BEWERTUNG')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.BEWERTUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="rk_mind"><?= rk_e(rk_t('EINST.MINDEST')) ?></label>
  <input data-role="none" type="text" id="rk_mind" name="mindest" value="<?= rk_e(rk_ein('mindest', $rk_cfg['mindest'])) ?>"<?= rk_mark('mindest') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.MINDEST_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_tmin"><?= rk_e(rk_t('EINST.T_MIN')) ?></label>
  <input data-role="none" type="text" id="rk_tmin" name="t_min" value="<?= rk_e(rk_ein('t_min', $rk_cfg['t_min'])) ?>"<?= rk_mark('t_min') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.T_MIN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_afu"><?= rk_e(rk_t('EINST.AF_UNTER')) ?></label>
  <input data-role="none" type="text" id="rk_afu" name="af_unter" value="<?= rk_e(rk_ein('af_unter', $rk_cfg['af_unter'])) ?>"<?= rk_mark('af_unter') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.AF_UNTER_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_vs"><?= rk_e(rk_t('EINST.VORSCHAU')) ?></label>
  <input data-role="none" type="text" id="rk_vs" name="vorschau" value="<?= rk_e(rk_ein('vorschau', $rk_cfg['vorschau'])) ?>"<?= rk_mark('vorschau') ?>>
</div>
<div class="sm-feld">
  <label for="rk_hyst"><?= rk_e(rk_t('EINST.HYST')) ?></label>
  <input data-role="none" type="text" id="rk_hyst" name="hyst" value="<?= rk_e(rk_ein('hyst', $rk_cfg['hyst'])) ?>"<?= rk_mark('hyst') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.HYST_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_dauer"><?= rk_e(rk_t('EINST.DAUER_MIN')) ?></label>
  <input data-role="none" type="text" id="rk_dauer" name="dauer_min" value="<?= rk_e(rk_ein('dauer_min', $rk_cfg['dauer_min'])) ?>"<?= rk_mark('dauer_min') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.DAUER_MIN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_regen"><?= rk_e(rk_t('EINST.REGEN_MAX')) ?></label>
  <input data-role="none" type="text" id="rk_regen" name="regen_max" value="<?= rk_e(rk_ein('regen_max', $rk_cfg['regen_max'])) ?>"<?= rk_mark('regen_max') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.REGEN_MAX_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_ksp"><?= rk_e(rk_t('EINST.KUEHL_SPANNE')) ?></label>
  <input data-role="none" type="text" id="rk_ksp" name="kuehl_spanne" value="<?= rk_e(rk_ein('kuehl_spanne', $rk_cfg['kuehl_spanne'])) ?>"<?= rk_mark('kuehl_spanne') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.KUEHL_SPANNE_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_wind"><?= rk_e(rk_t('EINST.WIND_MAX')) ?></label>
  <input data-role="none" type="text" id="rk_wind" name="wind_max" value="<?= rk_e(rk_ein('wind_max', $rk_cfg['wind_max'])) ?>"<?= rk_mark('wind_max') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.WIND_MAX_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_wabs"><?= rk_e(rk_t('EINST.WAND_ABSTAND')) ?></label>
  <input data-role="none" type="text" id="rk_wabs" name="wand_abstand" value="<?= rk_e(rk_ein('wand_abstand', $rk_cfg['wand_abstand'])) ?>"<?= rk_mark('wand_abstand') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.WAND_ABSTAND_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_co2t"><?= rk_e(rk_t('EINST.CO2_T_MIN')) ?></label>
  <input data-role="none" type="text" id="rk_co2t" name="co2_t_min" value="<?= rk_e(rk_ein('co2_t_min', $rk_cfg['co2_t_min'])) ?>"<?= rk_mark('co2_t_min') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.CO2_T_MIN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_zwang"><?= rk_e(rk_t('EINST.ZWANG_STD')) ?></label>
  <input data-role="none" type="text" id="rk_zwang" name="zwang_std" value="<?= rk_e(rk_ein('zwang_std', $rk_cfg['zwang_std'])) ?>"<?= rk_mark('zwang_std') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.ZWANG_STD_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_schw"><?= rk_e(rk_t('EINST.SCHWUEL_X')) ?></label>
  <input data-role="none" type="text" id="rk_schw" name="schwuel_x" value="<?= rk_e(rk_ein('schwuel_x', $rk_cfg['schwuel_x'])) ?>"<?= rk_mark('schwuel_x') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.SCHWUEL_X_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_vlz"><?= rk_e(rk_t('EINST.VL_ZUSCHLAG')) ?></label>
  <input data-role="none" type="text" id="rk_vlz" name="vl_zuschlag" value="<?= rk_e(rk_ein('vl_zuschlag', $rk_cfg['vl_zuschlag'])) ?>"<?= rk_mark('vl_zuschlag') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.VL_ZUSCHLAG_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_kfe"><?= rk_e(rk_t('EINST.KUEHLFREI_EIN')) ?></label>
  <input data-role="none" type="text" id="rk_kfe" name="kuehlfrei_ein" value="<?= rk_e(rk_ein('kuehlfrei_ein', $rk_cfg['kuehlfrei_ein'])) ?>"<?= rk_mark('kuehlfrei_ein') ?>>
  <label for="rk_kfa"><?= rk_e(rk_t('EINST.KUEHLFREI_AUS')) ?></label>
  <input data-role="none" type="text" id="rk_kfa" name="kuehlfrei_aus" value="<?= rk_e(rk_ein('kuehlfrei_aus', $rk_cfg['kuehlfrei_aus'])) ?>"<?= rk_mark('kuehlfrei_aus') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.KUEHLFREI_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_heiz"><?= rk_e(rk_t('EINST.HEIZGRENZE')) ?></label>
  <input data-role="none" type="text" id="rk_heiz" name="heizgrenze" value="<?= rk_e(rk_ein('heizgrenze', $rk_cfg['heizgrenze'])) ?>"<?= rk_mark('heizgrenze') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.HEIZGRENZE_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_trend"><?= rk_e(rk_t('EINST.TREND_MIN')) ?></label>
  <input data-role="none" type="text" id="rk_trend" name="trend_min" value="<?= rk_e(rk_ein('trend_min', $rk_cfg['trend_min'])) ?>"<?= rk_mark('trend_min') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.TREND_MIN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_cltr"><?= rk_e(rk_t('EINST.CO2_LTR')) ?></label>
  <input data-role="none" type="text" id="rk_cltr" name="co2_ltr" value="<?= rk_e(rk_ein('co2_ltr', $rk_cfg['co2_ltr'])) ?>"<?= rk_mark('co2_ltr') ?>>
  <label for="rk_causs"><?= rk_e(rk_t('EINST.CO2_AUSSEN')) ?></label>
  <input data-role="none" type="text" id="rk_causs" name="co2_aussen" value="<?= rk_e(rk_ein('co2_aussen', $rk_cfg['co2_aussen'])) ?>"<?= rk_mark('co2_aussen') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.CO2_LTR_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="verlauf_ein" value="1"<?= rk_haken('verlauf_ein', !empty($rk_cfg['verlauf_ein'])) ?>> <?= rk_e(rk_t('EINST.VERLAUF')) ?></label>
  <p class="sm-hilfe"><?= rk_t('EINST.VERLAUF_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_steht"><?= rk_e(rk_t('EINST.STEHT_MIN')) ?></label>
  <input data-role="none" type="text" id="rk_steht" name="steht_min" value="<?= rk_e(rk_ein('steht_min', $rk_cfg['steht_min'])) ?>"<?= rk_mark('steht_min') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.STEHT_MIN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_takt"><?= rk_e(rk_t('EINST.TAKT')) ?></label>
  <input data-role="none" type="text" id="rk_takt" name="takt" value="<?= rk_e(rk_ein('takt', $rk_cfg['takt'])) ?>"<?= rk_mark('takt') ?>>
  <p class="sm-hilfe"><?= rk_t('EINST.TAKT_HILFE') ?></p>
</div>

<h2><?= rk_e(rk_t('EINST.H_ANSAGE')) ?></h2>
<div class="sm-step"><?= rk_e(rk_t('EINST.ANSAGE_ERKLAERUNG')) ?></div>
<?= ansage_formular_html(rk_tts(), array(
    'w' => function ($n, $g) { return rk_ein($n, $g); },
    'm' => function ($n) { return rk_mark($n); },
    'c' => function ($n, $g) { return rk_haken($n, $g) !== ''; },
    'modi' => rk_ansage_modi()), rk_ansage_k()) ?>
<h3><?= rk_e(rk_t('EINST.ANSAGE_ANLAESSE')) ?></h3>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="ansage_lueften" value="1"<?= rk_haken('ansage_lueften', !empty($rk_cfg['ansage_lueften'])) ?><?= rk_mark('ansage_lueften') ?>> <?= rk_e(rk_t('EINST.ANSAGE_LUEFTEN')) ?></label>
  <label><input data-role="none" type="checkbox" name="ansage_schimmel" value="1"<?= rk_haken('ansage_schimmel', !empty($rk_cfg['ansage_schimmel'])) ?><?= rk_mark('ansage_schimmel') ?>> <?= rk_e(rk_t('EINST.ANSAGE_SCHIMMEL')) ?></label>
  <p class="sm-hilfe"><?= rk_e(rk_t('EINST.ANSAGE_ANLAESSE_HILFE')) ?></p>
</div>
<div class="sm-feld">
  <label for="rk_aruhe_von"><?= rk_e(rk_t('EINST.ANSAGE_RUHE')) ?></label>
  <input data-role="none" type="text" id="rk_aruhe_von" name="ansage_ruhe_von" maxlength="5" placeholder="22:00" value="<?= rk_e(rk_ein('ansage_ruhe_von', $rk_cfg['ansage_ruhe_von'])) ?>"<?= rk_mark('ansage_ruhe_von') ?>>
  <label for="rk_aruhe_bis"><?= rk_e(rk_t('EINST.ANSAGE_RUHE_BIS')) ?></label>
  <input data-role="none" type="text" id="rk_aruhe_bis" name="ansage_ruhe_bis" maxlength="5" placeholder="07:00" value="<?= rk_e(rk_ein('ansage_ruhe_bis', $rk_cfg['ansage_ruhe_bis'])) ?>"<?= rk_mark('ansage_ruhe_bis') ?>>
  <p class="sm-hilfe"><?= rk_e(rk_t('EINST.ANSAGE_RUHE_HILFE')) ?></p>
</div>
<p class="sm-hilfe"><?= rk_e(sprintf(rk_t('EINST.ANSAGE_TEST_HINWEIS'), rk_t('REITER.TEST'))) ?></p>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= rk_e(rk_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= rk_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= rk_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= rk_t('EINST.SICH_WARNUNG') ?></div>
<p class="sm-hilfe"><?= rk_e(rk_t('EINST.SICH_OHNE_SPRECHTOKEN')) ?></p>
<?php /* X-3 (Durchgang 01.10.2026): wuerde das eigene Zurueckspielen die
         Sicherung abweisen, steht es gelb am Knopf - mit dem Namen der
         Einstellung und dem Grund, nie mit dem Wert. DIESELBE Pruefung wie
         beim Zurueckspielen. Gesichert wird trotzdem vollstaendig (die Datei
         traegt dann "_warnung"). Bauart AWM-Abfuhr 1.4.19. */
$rk_x3 = rk_sicherung_maengel($rk_cfg, rk_geheim());
if ($rk_x3) {
    $rk_x3l = array();
    foreach ($rk_x3 as $rk_xk => $rk_xg) { $rk_x3l[] = ($rk_xk === '_' ? '' : $rk_xk . ': ') . $rk_xg; } ?>
<div class="sm-warnung" id="sicherung-altwerte"><?= rk_e(sprintf(rk_t('EINST.SICH_ALTWERT'), implode('; ', $rk_x3l))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <label><input data-role="none" type="checkbox" name="sich_zugang" value="1"> <?= rk_e(rk_t('EINST.SICH_ZUGANG_HAKEN')) ?></label>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="rk_sichern" value="1"><?= rk_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <input data-role="none" type="file" name="rk_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="rk_zurueck" value="1"><?= rk_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<div class="sm-step"><?= rk_t('MQTT.ERKLAERUNG') ?></div>

<?php if (!$rk_mqtt['gefunden']) { ?>
<div class="sm-warnung"><?= rk_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$rk_mqtt['autostart']) { ?>
<div class="sm-warnung"><?= rk_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(rk_t('MQTT.LAEUFT'), rk_e((string) $rk_mqtt['udpport'])) ?></div>
<?php } ?>

<h3><?= rk_e(rk_t('MQTT.H_ABO')) ?></h3>
<?php
/* ALLES, was ueber das Abo gesagt wird, haengt an der gemessenen Fassung -
 * und zwar an EINER Stelle, rk_abo_text().
 *
 * Bis 0.10.1 verzweigte nur der Kasten unten. Der erklaerende Satz darueber
 * (MQTT.ABO_HILFE, "Der Eintrag steht in LoxBerry unter System, MQTT
 * Gateway") und die Tabelle zum Abschreiben standen UNBEDINGT da. Unter
 * Gateway V2 gibt es diesen Eintrag nicht - der Kern schaltet die Knoepfe
 * auf der Abonnement-Seite ab -, und auf der Seite standen beide Aussagen
 * untereinander. Gemessen an der gerenderten Seite, drei Laeufe, beide
 * PHP-Fassungen: ABO_HILFE war in allen dreien da.
 *
 * Das ist woertlich der Befund, den MGiSmart am 25.08.2026 gefunden hat -
 * an einer Stelle verzweigt, an der zweiten weiter behauptet. */
$rk_gwf = (int) $rk_mqtt['fassung'];
?>
<?php /* Zwei Faelle, nicht drei. Bis 0.11.2 standen hier ein
         elseif-Zweig fuer Fassung 1 und ein else-Zweig fuer "nicht
         feststellbar" - Zeichen fuer Zeichen derselbe Inhalt. Wer die
         Verzweigung spaeter anfasst, muesste erst messen, ob das Absicht
         war. Der Unterschied, auf den es ankommt, steckt in
         rk_abo_text(): der nennt bei fassung = 0 beide Saetze. */ ?>
<?php if ($rk_gwf >= 2 || rk_abo_da()) { ?>
<div class="sm-hinweis"><?= rk_abo_text() ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= rk_abo_text() ?></div>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('MQTT.SP_ABO')) ?></th><th><?= rk_e(rk_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= rk_e($rk_thema . '/#') ?></span></td>
    <td><?= rk_e(rk_t('MQTT.ABO_ALLES')) ?></td></tr>
</table>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
<input data-role="none" type="hidden" name="feld_mqtt" value="1">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= rk_haken('mqtt_ein', !empty($rk_cfg['mqtt_ein'])) ?>> <?= rk_e(rk_t('MQTT.EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="rk_thema"><?= rk_e(rk_t('MQTT.THEMA')) ?></label>
  <input data-role="none" type="text" id="rk_thema" name="mqtt_topic" value="<?= rk_e(rk_ein('mqtt_topic', $rk_cfg['mqtt_topic'])) ?>"<?= rk_mark('mqtt_topic') ?>>
  <p class="sm-hilfe"><?= rk_t('MQTT.THEMA_HILFE') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= rk_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= rk_e(rk_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= rk_e(rk_t('MQTT.H_THEMEN')) ?></h3>
<table class="sm-tbl">
<?php /* Die Spalte "retained" ist Pflicht, sobald eine Linie ueberhaupt
         retained sendet (Regeln/07): wer ein Thema anlegt, schreibt hier
         hin, ob es einen Neustart ueberlebt. Die Spalte wird GERECHNET,
         nicht getippt - sie fragt dieselbe Funktion wie der Sendeweg. */ ?>
<tr><th><?= rk_e(rk_t('MQTT.SP_THEMA')) ?></th><th><?= rk_e(rk_t('MQTT.SP_BEDEUTUNG')) ?></th><th><?= rk_e(rk_t('MQTT.SP_RETAIN')) ?></th></tr>
<?php foreach (rk_mqtt_themen() as $rk_k => $rk_schl) { ?>
<tr>
  <td><span class="sm-mono"><?= rk_e($rk_thema . '/' . $rk_k) ?></span></td>
  <td><?= rk_e(rk_t($rk_schl)) ?></td>
  <td><?= rk_mqtt_retain($rk_k) ? rk_e(rk_t('ALLG.JA')) : rk_e(rk_t('ALLG.NEIN')) ?></td>
</tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= rk_t('MQTT.RETAIN_HILFE') ?></p>
<p class="sm-hilfe"><?= rk_t('MQTT.RAUMN_HILFE') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= rk_e(rk_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-step"><?= rk_t('LOX.ERKLAERUNG') ?></div>

<?php if (!$rk_raeume) { ?>
<div class="sm-warnung"><?= rk_t('LOX.KEINE_RAEUME') ?></div>
<?php } ?>

<h3><?= rk_e(rk_t('LOX.H_S1')) ?></h3>
<div class="sm-step"><?= rk_t('LOX.S1') ?></div>

<h3><?= rk_e(rk_t('LOX.H_S2')) ?></h3>
<div class="sm-step"><?= rk_t('LOX.S2') ?></div>

<h3><?= rk_e(rk_t('LOX.H_S3')) ?></h3>
<div class="sm-step"><?= rk_t('LOX.S3') ?></div>

<h4><?= rk_e(rk_t('LOX.H_ADRESSE')) ?></h4>
<p class="sm-hilfe"><?= rk_t('LOX.ADRESSE_HILFE') ?></p>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_ZWECK')) ?></th><th><?= rk_e(rk_t('LOX.SP_ADRESSE')) ?></th></tr>
<tr><td><?= rk_e(rk_t('LOX.Z_STATUS')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=status') ?></span></td></tr>
<tr><td><?= rk_e(rk_t('LOX.Z_JSON')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=json') ?></span></td></tr>
<tr><td><?= rk_e(rk_t('LOX.Z_ABRUFEN')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=abrufen') ?></span></td></tr>
<?php /* Die vierte Adresse. Bis 0.11.2 stand sie nur im Kopfkommentar von
         webfrontend/html/index.php - der Anwender musste sie aus dem
         Quelltext holen. Die Nummer ist der erste eingerichtete Raum,
         damit das Beispiel an dieser Anlage wirklich antwortet. */
   /* U16 (Durchgang 01.10.2026): ohne eingerichteten Raum keine Adresse -
    * die Beispielnummer 1 antwortete dann mit 404 RAUM_UNBEKANNT (gemessen,
    * Bericht oberflaeche Nr. 18). */
   if ($rk_raeume) {
       $rk_bsp = (int) array_keys($rk_raeume)[0]; ?>
<tr><td><?= rk_e(rk_t('LOX.Z_RAUM')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=raum&nr=' . $rk_bsp) ?></span></td></tr>
<?php } else { ?>
<tr><td><?= rk_e(rk_t('LOX.Z_RAUM')) ?></td><td><?= rk_e(rk_t('LOX.Z_RAUM_ERST')) ?></td></tr>
<?php } ?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= rk_t('LEGENDE.TECHNIK_XML') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rk_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vi"><?= rk_e(rk_t('LOX.K_VI')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= rk_e(rk_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<p class="sm-hilfe"><?= rk_t('LOX.TOKEN_HINWEIS') ?></p>

<h3><?= rk_e(rk_t('LOX.H_NUMMERN')) ?></h3>
<p class="sm-hilfe"><?= rk_t('LOX.NUMMERN_HILFE') ?></p>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_NUMMER')) ?></th><th><?= rk_e(rk_t('LOX.SP_RAUM')) ?></th>
    <th><?= rk_e(rk_t('LOX.SP_PRAEFIX')) ?></th></tr>
<?php if ($rk_raeume) { foreach ($rk_raeume as $rk_nr => $rk_r) { ?>
<tr><td><?= (int) $rk_nr ?></td><td><?= rk_e($rk_r['name']) ?></td>
    <td><span class="sm-mono">R<?= (int) $rk_nr ?></span></td></tr>
<?php } } else { ?>
<tr><td colspan="3"><?= rk_e(rk_t('LOX.KEINE_RAEUME_KURZ')) ?></td></tr>
<?php } ?>
</table>

<h3><?= rk_e(rk_t('LOX.H_FELDER')) ?></h3>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_FELD')) ?></th><th><?= rk_e(rk_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (rk_felder() as $rk_f => $rk_info) { ?>
<tr><td><span class="sm-mono">R&lt;n&gt;<?= rk_e($rk_f) ?></span></td>
    <td><?= rk_e(rk_t($rk_info[3])) ?><?= $rk_info[0] !== '' ? ' [' . rk_e($rk_info[0]) . ']' : '' ?></td></tr>
<?php } ?>
</table>

<h3><?= rk_e(rk_t('LOX.H_S4')) ?></h3>
<div class="sm-warnung"><?= rk_t('LOX.S4') ?></div>

<h3><?= rk_e(rk_t('LOX.H_S5')) ?></h3>
<div class="sm-step"><?= rk_t('LOX.S5') ?></div>
<?php $rk_bl = rk_bausteine(); ?>
<p class="sm-hilfe"><?= sprintf(rk_t('LOX.BAUSTEINE_FUER'), rk_e($rk_bl['raum']), (int) $rk_bl['nr']) ?></p>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_NR')) ?></th><th><?= rk_e(rk_t('LOX.SP_TYP')) ?></th>
    <th><?= rk_e(rk_t('LOX.SP_NAME')) ?></th><th><?= rk_e(rk_t('LOX.SP_PARAM')) ?></th>
    <th><?= rk_e(rk_t('LOX.SP_EING')) ?></th></tr>
<?php foreach ($rk_bl['felder'] as $rk_z) { ?>
<tr><td><?= (int) $rk_z['nr'] ?></td>
    <td><?= rk_e(rk_t('LOX.B_FELD')) ?></td>
    <td><span class="sm-mono"><?= rk_e($rk_z['titel']) ?></span></td>
    <td><?= rk_e($rk_z['bedeutung']) ?></td>
    <td><?= rk_e(rk_t('LOX.BE_AUS_VORLAGE')) ?></td></tr>
<?php } ?>
<?php foreach ($rk_bl['bausteine'] as $rk_z) { ?>
<tr><td><b><?= (int) $rk_z[0] ?></b></td><td><?= rk_e($rk_z[1]) ?></td>
    <td><?= rk_e($rk_z[2]) ?></td><td><?= rk_e($rk_z[3]) ?></td>
    <td><?= rk_e($rk_z[4]) ?></td></tr>
<?php } ?>
</table>
<?php foreach ($rk_bl['hinweise'] as $rk_h) { ?>
<p class="sm-hilfe"><b><?= sprintf(rk_e(rk_t('LOX.ZU')), rk_e($rk_h[0])) ?></b> <?= $rk_h[1] ?></p>
<?php } ?>
<p class="sm-hilfe"><?= rk_e(rk_t('LOX.ANSAGE_KEIN_BAUSTEIN')) ?></p>
<div class="sm-hinweis"><?= sprintf(rk_t('LOX.WEITERE_RAEUME'), (int) $rk_bl['nr']) ?></div>

<h3><?= rk_e(rk_t('LOX.H_S6')) ?></h3>
<div class="sm-step"><?= rk_t('LOX.S6') ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= rk_e(rk_t('TEST.H')) ?></h2>
<div class="sm-step"><?= rk_t('TEST.ERKLAERUNG') ?></div>
<!-- Die Klassen stehen LITERAL an jedem Knopf, nicht aus einer Variablen
     zusammengesetzt: hausstandard_pruefen.py vergleicht je Reiter die Farben
     der Legende mit denen der Knoepfe und findet nur literale
     Klassenattribute. Zusammengesetzt war die Spalte blind - und genau darin
     steckten bis 0.9.8 zwei falsch gefaerbte Knoepfe. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rk_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= rk_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= rk_t('LEGENDE.AKTION_TEST') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="quellen"><?= rk_e(rk_t('TEST.K_QUELLEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="pfade"><?= rk_e(rk_t('TEST.K_PFADE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="meteo"><?= rk_e(rk_t('TEST.K_METEO')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="selbsttest"><?= rk_e(rk_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="endpunkt"><?= rk_e(rk_t('TEST.K_ENDPUNKT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="sicherung"><?= rk_e(rk_t('TEST.K_SICHERUNG')) ?></button>
  </form>
</div>

<h3><?= rk_e(rk_t('TEST.H_PRUEFUNG')) ?></h3>
<div class="sm-step"><?= rk_t('TEST.PRUEFUNG_HINWEIS') ?></div>
<?php
/* ------------------------------------------------------------------
 * Die Selbstpruefung - acht Zeilen aus dem Hausstandard
 *
 * Bis 0.10.1 gab es sie ueberhaupt nicht: von den acht Zeilen der Tabelle
 * in REGELN_2 war genau eine vorhanden (der Endpunktaufruf). Die dritte
 * ist die teuerste - haette es sie gegeben, waere der Befund "eine kaputte
 * Konfiguration reisst die Zweitschrift mit" beim ersten Klick
 * aufgefallen. Die sechste haette gemeldet, dass die Formulare kein
 * Merkmal tragen.
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich ("nicht feststellbar"). Ein Strich ist
 * ausdruecklich KEIN Haken. Und die Zusammenfassung darf nicht besser
 * aussehen als ihr schlechtester Punkt.
 * ------------------------------------------------------------------ */
/* Eine Selbstpruefung, die etwas KOSTET, laeuft nur im geoeffneten Reiter.
 * Diese hier ruft den eigenen Endpunkt auf; stuende sie unbedingt hier,
 * liefe bei JEDEM Seitenaufbau ein Netzaufruf mit - auch beim Speichern im
 * Reiter Einstellungen, denn die Seite rendert alle Reiter mit. Der Aufruf
 * ist zusaetzlich fuenf Minuten zwischengespeichert. */
$rk_pruef = ($rk_tab === 'tab-test') ? rk_selbstpruefung() : null;
if ($rk_pruef === null) { ?>
<div class="sm-hinweis"><?= rk_e(rk_t('TEST.PRUEFUNG_ZU')) ?></div>
<?php } else {
$rk_pz = array(1 => 0, 0 => 0, 2 => 0);
foreach ($rk_pruef as $rk_p1) { $rk_pz[(int) $rk_p1['ok']]++; }
?>
<?php
/* Beide Klassen AUSGESCHRIEBEN, nicht zusammengesetzt: hausstandard_pruefen.py
 * findet nur literale Klassenattribute; ein Klassenattribut, das erst zur
 * Laufzeit aus zwei Teilen entsteht, macht seine Spalte blind. Das steht
 * seit dem 17.08.2026 in REGELN_2.
 *
 * Und der Wortlaut dieses Kommentars nennt die gesuchte Form ABSICHTLICH
 * nicht im Original - sonst schlaegt das Werkzeug auf die Erklaerung an.
 * Genau das ist am 16.08. und am 17.08.2026 je einmal passiert. */
if ($rk_pz[0] > 0) { ?>
<div class="sm-warnung">
<?= sprintf(rk_e(rk_t('TEST.PRUEFUNG_BILANZ')), $rk_pz[1], count($rk_pruef), $rk_pz[0], $rk_pz[2]) ?>
</div>
<?php } else { ?>
<div class="sm-hinweis">
<?= sprintf(rk_e(rk_t('TEST.PRUEFUNG_BILANZ')), $rk_pz[1], count($rk_pruef), $rk_pz[0], $rk_pz[2]) ?>
</div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('TEST.SP_ZEILE')) ?></th><th><?= rk_e(rk_t('TEST.SP_ERGEBNIS')) ?></th>
    <th><?= rk_e(rk_t('TEST.SP_MESSWERT')) ?></th></tr>
<?php foreach ($rk_pruef as $rk_p1) { ?>
<tr>
  <td><?= rk_e(rk_t($rk_p1['bez'])) ?></td>
  <td><?php if ((int) $rk_p1['ok'] === 1) { ?><span class="sm-an">&#10003;</span><?php }
           elseif ((int) $rk_p1['ok'] === 0) { ?><span class="sm-aus">&#10007;</span><?php }
           else { ?><span>&ndash;</span><?php } ?></td>
  <td><?= rk_e($rk_p1['text']) ?></td>
</tr>
<?php } ?>
</table>
<?php } ?>

<h3><?= rk_e(rk_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-step"><?= rk_t('TEST.SCHALTEN_HINWEIS') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="rechnung"><?= rk_e(rk_t('TEST.K_RECHNUNG')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="mqtt"><?= rk_e(rk_t('TEST.K_MQTT')) ?></button>
  </form>
</div>

<h3><?= rk_e(rk_t('TEST.H_ANSAGE')) ?></h3>
<div class="sm-step"><?= rk_e(rk_t('TEST.ANSAGE_HINWEIS')) ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ansage"><?= rk_e(rk_t('TEST.K_ANSAGE')) ?></button>
  </form>
</div>
<?php if ($rk_testausgabe !== '') { ?>
<div class="sm-pre"><?= rk_e($rk_testausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= rk_e(rk_t('LOG.H')) ?></h2>
<p class="sm-hilfe"><?= rk_t('LOG.ERKLAERUNG') ?>
<span class="sm-mono"><?= rk_e($rk_p['log']) ?></span></p>
<?php if ($rk_logzeilen) { ?>
<div class="sm-log"><?= rk_e(implode("\n", $rk_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= rk_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rk_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $rk_ft ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= rk_e(rk_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($rk_tab) ?>);
})();
</script>
<?php
if ($rk_rahmen) {
    LBWeb::lbfooter();
}
