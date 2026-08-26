<?php
/**
 * Raumklima - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche und der Abrufdienst.
 *
 * Die Rechnung selbst steht in rk_klima.php daneben - ohne Netz, ohne
 * Dateien und deshalb vollstaendig pruefbar. Hier steht nur, woher die
 * Zahlen kommen und wohin sie gehen.
 *
 * ------------------------------------------------------------------
 * Warum das Plugin keine eigene Sensorik kennt
 * ------------------------------------------------------------------
 *
 * Temperatur und Feuchte misst jeder anders: Ecowitt, Shelly, Zigbee ueber
 * einen Gateway, Loxone selbst, ein eigenes Skript. Ein Plugin, das sich
 * auf eine Marke festlegt, ist fuer alle anderen wertlos.
 *
 * Deshalb nimmt dieses Plugin die Werte auf dem kleinsten gemeinsamen
 * Nenner entgegen: **eine Adresse, die JSON liefert, und ein Pfad darin.**
 * Das kann eine Gateway-Adresse im Heimnetz sein, der Endpunkt eines
 * anderen LoxBerry-Plugins oder eine selbst gebaute Datei. Wer alle Raeume
 * in einer Antwort hat, traegt die Adresse einmal oben ein und je Raum nur
 * die beiden Pfade.
 *
 * Fuer Loxone-Nutzer gibt es zusaetzlich den Weg ueber den Miniserver:
 * dessen /jdev/sps/io/<Name>/all liefert JSON, und die Zugangsdaten stehen
 * in der Geheimnisdatei mit 0600.
 *
 * Praefix 'rk_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

/* Der HTML-Maskierer. Steht hier, damit ihn Oberflaeche und Endpunkt aus
 * derselben Quelle haben. */
if (!function_exists('rk_e')) {
    function rk_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/rk_klima.php';

/** Wie viele Raeume die Oberflaeche fuehrt. */
define('RK_RAEUME', 12);


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function rk_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und hat Vorrang.
     * Der feste Name greift nur, wo der ermittelte nachweislich kein
     * Plugin-Ordner sein kann - aus dem ausgepackten Archiv heraus heisst
     * er 'html'. Haengt LoxBerry bei einer Zweitinstallation einen Zaehler
     * an (raumklima_01), zeigten sonst beide auf dieselbe Konfiguration. */
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) { $dir = basename(dirname(__FILE__)); }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html' || $dir === 'plugins') {
        $dir = 'raumklima';
    }
    if ($home) {
        $p = array(
            'home'      => rtrim($home, '/'),
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/raumklima.json',
            'geheim'    => $home . '/config/plugins/' . $dir . '/geheim.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/raumklima.log',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home' => '', 'plugin' => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/raumklima.json',
            'geheim'    => $basis . '/config/geheim.json',
            'sicherung' => $basis . '/config/raumklima.backup.json',
            'datadir'   => $basis . '/data',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/raumklima.log',
        );
    }
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function rk_raum_vorgabe()
{
    return array(
        'name'     => '',
        'quelle'   => '',    // leer = die gemeinsame Adresse benutzen
        'pfad_t'   => '',
        'pfad_rf'  => '',
        'frsi'     => 0.70,  // Temperaturfaktor der kaeltesten Stelle
        'soll_min' => 40,    // Zielkorridor der Raumfeuchte in Prozent
        'soll_max' => 60,
        'art'      => 'aussen',  // aussen | keller | innen
        'erd_t'    => 12.0,      // Erdreichtemperatur, nur bei art=keller
        'einheit_t'  => 'C',     // C | F
        'einheit_rf' => 'proz',  // proz (0-100) | anteil (0-1)
        'volumen'    => 0,       // m3, 0 = unbekannt (dann keine Kosten)
        'fenster'    => 'stoss', // kipp | stoss | quer
        't_soll'     => 0,       // Zieltemperatur zum Kuehlen, 0 = aus
        'pfad_co2'   => '',      // dritter, freiwilliger Pfad
        'co2_max'    => 1000,    // ppm, ab hier lueften; 0 = aus
        'pfad_fenster' => '',    // Fensterkontakt, z. B. aus dem Miniserver
    );
}

function rk_vorgaben()
{
    return array(
        'raeume'      => array(),
        'quelle'      => '',        // gemeinsame Adresse fuer alle Raeume
        'takt'        => 300,       // Sekunden zwischen zwei Abrufen (Cron laeuft alle 300)
        // Aussenwerte
        'aussen_art'  => 'meteo',   // meteo | eigen
        'breite'      => 48.1372,   // Muenchen als Vorgabe, damit etwas da ist
        'laenge'      => 11.5756,
        'aussen_quelle' => '',
        'aussen_t'    => '',
        'aussen_rf'   => '',
        // Bewertung
        'mindest'     => 0.5,       // g/m3 Unterschied, ab dem Lueften lohnt
        't_min'       => -5,        // Grad, unter denen nicht mehr empfohlen wird
        'af_unter'    => 0.0,       // g/m3, darunter ist es zu trocken; 0 = aus
        'vorschau'    => 12,        // Stunden, die in die Zukunft gesehen wird
        'steht_min'   => 60,        // Minuten ohne Wertaenderung = Fuehler steht; 0 = aus
        'aussen_einheit_t'  => 'C',
        'aussen_einheit_rf' => 'proz',
        'hyst'        => 0.5,       // Ausschaltschwelle als Anteil von mindest
        'dauer_min'   => 10,        // Minuten, die eine Empfehlung mindestens steht
        'regen_max'   => 0.5,       // mm je Stunde, darueber nicht lueften; 0 = aus
        'kuehl_spanne' => 1.0,      // Kelvin, ab denen Kuehlen zaehlt
        'verlauf_ein' => 1,         // Verlaufsspeicher fuehren
        // MQTT und Endpunkt
        'mqtt_ein'    => 1,
        'mqtt_topic'  => 'raumklima',
        'aktionstoken' => '',
    );
}

function rk_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen. Die Rechte gehoeren an das
 * ANLEGEN, nicht hinterher; die Nebendatei traegt die PID im Namen, sonst
 * zerlegen zwei gleichzeitige Schreiber einander die Datei.
 */
function rk_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

function rk_config()
{
    $p = rk_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        /* is_dir() davor: '@' unterdrueckt die Meldung nur, solange KEIN
         * eigener Fehlerbehandler gesetzt ist. Mit einem gesetzten Behandler
         * schlaegt mkdir() auf ein vorhandenes Verzeichnis durch - gemessen
         * am 24.08.2026 im Aktualisierungsfall. */
        if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = array_merge(rk_vorgaben(), rk_json_lesen($p['config']));

    if (!is_array($cfg['raeume'])) { $cfg['raeume'] = array(); }
    for ($i = 0; $i < RK_RAEUME; $i++) {
        $r = isset($cfg['raeume'][$i]) && is_array($cfg['raeume'][$i]) ? $cfg['raeume'][$i] : array();
        $r += rk_raum_vorgabe();
        $r['name'] = trim((string) $r['name']);
        $r['frsi'] = max(0.05, min(1.0, (float) $r['frsi']));
        $r['soll_min'] = max(0, min(100, (int) $r['soll_min']));
        $r['soll_max'] = max(0, min(100, (int) $r['soll_max']));
        if (!in_array($r['art'], array('aussen', 'keller', 'innen'), true)) {
            $r['art'] = 'aussen';
        }
        $r['erd_t'] = max(-20.0, min(40.0, (float) $r['erd_t']));
        if (!in_array($r['einheit_t'], array('C', 'F'), true)) { $r['einheit_t'] = 'C'; }
        if (!in_array($r['einheit_rf'], array('proz', 'anteil'), true)) {
            $r['einheit_rf'] = 'proz';
        }
        $r['volumen'] = max(0.0, min(2000.0, (float) $r['volumen']));
        if (!in_array($r['fenster'], array('kipp', 'stoss', 'quer'), true)) {
            $r['fenster'] = 'stoss';
        }
        $r['t_soll'] = max(0.0, min(35.0, (float) $r['t_soll']));
        $r['co2_max'] = max(0, min(5000, (int) $r['co2_max']));
        $cfg['raeume'][$i] = $r;
    }
    /* Untergrenze 300, nicht 60: cron.05min ruft alle fuenf Minuten auf.
     * Ein kleinerer Takt bewirkt nichts und verspricht trotzdem etwas. */
    $cfg['takt'] = max(300, min(3600, (int) $cfg['takt']));
    $cfg['mindest'] = max(0.0, min(10.0, (float) $cfg['mindest']));
    $cfg['t_min'] = max(-30, min(30, (float) $cfg['t_min']));
    $cfg['af_unter'] = max(0.0, min(20.0, (float) $cfg['af_unter']));
    $cfg['vorschau'] = max(1, min(48, (int) $cfg['vorschau']));
    $cfg['steht_min'] = max(0, min(1440, (int) $cfg['steht_min']));
    $cfg['hyst'] = max(0.0, min(1.0, (float) $cfg['hyst']));
    $cfg['dauer_min'] = max(0, min(120, (int) $cfg['dauer_min']));
    $cfg['regen_max'] = max(0.0, min(20.0, (float) $cfg['regen_max']));
    $cfg['kuehl_spanne'] = max(0.1, min(10.0, (float) $cfg['kuehl_spanne']));
    $cfg['verlauf_ein'] = !empty($cfg['verlauf_ein']) ? 1 : 0;
    if (!in_array($cfg['aussen_einheit_t'], array('C', 'F'), true)) {
        $cfg['aussen_einheit_t'] = 'C';
    }
    if (!in_array($cfg['aussen_einheit_rf'], array('proz', 'anteil'), true)) {
        $cfg['aussen_einheit_rf'] = 'proz';
    }
    $cfg['breite'] = max(-90.0, min(90.0, (float) $cfg['breite']));
    $cfg['laenge'] = max(-180.0, min(180.0, (float) $cfg['laenge']));
    if (!in_array($cfg['aussen_art'], array('meteo', 'eigen'), true)) {
        $cfg['aussen_art'] = 'meteo';
    }
    return $cfg;
}

function rk_config_speichern($cfg)
{
    $p = rk_paths();
    if (!rk_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/** Zugangsdaten - eigene Datei mit 0600, nie in der Oberflaeche sichtbar. */
function rk_geheim()
{
    return array_merge(array('benutzer' => '', 'passwort' => ''),
                       rk_json_lesen(rk_paths()['geheim']));
}

function rk_geheim_speichern($g)
{
    return rk_json_schreiben(rk_paths()['geheim'], $g, 0600);
}

/**
 * Nur die Raeume, die einen Namen und mindestens einen Pfad tragen.
 *
 * DIE NUMMER IST DER PLATZ IN DER TABELLE, nicht der Rang unter den
 * ausgefuellten. Bis 0.9.8 zaehlte hier ein Zaehler ueber die gefuellten
 * Zeilen hoch. Wer dann eine Zeile in der Mitte leerte, verschob alle
 * folgenden um eins:
 *
 *     vorher : 1 Wohnzimmer, 2 Kueche, 3 Bad, 4 Schlafen
 *     nachher: 1 Wohnzimmer, 2 Bad,    3 Schlafen
 *
 * Der virtuelle Eingang mit dem Suchtext \i;R3TAU= zeigte auf 'Bad' und
 * lieferte danach den Wert von 'Schlafen'. Kein Wert fehlt, nichts steht auf
 * '-', keine Meldung erscheint - beide Zahlen sehen aus wie ein Taupunkt.
 *
 * Mit dem Platz als Nummer bleibt eine geleerte Zeile eine Luecke, und die
 * Nummer in Loxone ist dieselbe, die in der Oberflaeche in der Spalte
 * 'Raum' steht.
 */
function rk_raeume()
{
    $cfg = rk_config();
    $out = array();
    foreach ($cfg['raeume'] as $i => $r) {
        if (trim((string) $r['name']) === '') { continue; }
        if (trim((string) $r['pfad_t']) === '' && trim((string) $r['pfad_rf']) === '') { continue; }
        $nr = (int) $i + 1;
        $r['nr'] = $nr;
        $out[$nr] = $r;
    }
    ksort($out);
    return $out;
}

function rk_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Nur LESEN, nie erzeugen. Fuer den unangemeldeten Endpunkt.
 *
 * rk_token() legt bei Bedarf ein Wortzeichen an und SCHREIBT dafuer die
 * Konfiguration. Aus webfrontend/html/index.php aufgerufen hiesse das: ein
 * Aufruf ohne jede Anmeldung loest einen Schreibvorgang aus. Abgewiesen
 * wuerde er zwar trotzdem - verglichen wuerde gegen ein frisch gewuerfeltes
 * Wortzeichen -, aber ein unangemeldeter Aufruf hat auf der Platte nichts
 * zu suchen. Angelegt wird das Wortzeichen dort, wo jemand angemeldet ist:
 * in der Oberflaeche.
 */
function rk_token_lesen()
{
    $cfg = rk_config();
    return trim((string) $cfg['aktionstoken']);
}

/** Liest das Wortzeichen und legt eines an, falls noch keines da ist. */
function rk_token()
{
    $cfg = rk_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = rk_token_erzeugen();
        rk_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ==================================================================
 * Protokoll
 * ================================================================== */

function rk_log($text)
{
    $p = rk_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* log/plugins liegt auf einer Ramdisk - eine unbegrenzt wachsende
     * Logdatei frisst Arbeitsspeicher, nicht Plattenplatz. */
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster. */
function rk_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = rk_paths()['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        rk_log($text);
    }
}

/**
 * Die letzten Zeilen einer Datei, neueste zuerst - rueckwaerts mit fseek.
 * Gemessen an 12.000 Zeilen: file() 0,37 ms und 2 MB, exec("tail") 2,17 ms,
 * fseek 0,05 ms und 0 kB. Ein Prozessstart kostet mehr, als das Einlesen
 * je gespart hat.
 */
function rk_log_ende($datei, $anzahl = 400, $block = 8192)
{
    /* is_file() davor: auf einer frischen Installation gibt es die Datei
     * noch nicht, und '@' haelt die Meldung nur zurueck, solange kein
     * eigener Fehlerbehandler gesetzt ist. */
    if (!is_file($datei)) { return array(); }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) { return array(); }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ==================================================================
 * Fremde Auskuenfte holen
 * ================================================================== */

/** Einen Punktpfad in einem verschachtelten Feld aufloesen. */
function rk_pfad($daten, $pfad)
{
    $pfad = trim((string) $pfad);
    if ($pfad === '') { return null; }
    foreach (explode('.', $pfad) as $teil) {
        if (is_array($daten) && array_key_exists($teil, $daten)) {
            $daten = $daten[$teil];
        } else {
            return null;
        }
    }
    return (is_array($daten) || is_object($daten)) ? null : $daten;
}

/**
 * Eine JSON-Adresse holen. Rueckgabe: array(Feld|null, Meldung).
 *
 * Ein Fehler ist ein Fehler und kein leerer Wert: wer nicht sagt, dass die
 * Quelle stumm blieb, laesst die Oberflaeche alte Zahlen als aktuelle
 * zeigen.
 */
function rk_holen($url, $mit_zugang = false)
{
    $url = trim((string) $url);
    if ($url === '') { return array(null, 'KEINE_ADRESSE'); }
    if (!preg_match('#^https?://#i', $url)) { return array(null, 'KEINE_ADRESSE'); }

    /* Ohne Fassungsnummer: eine Zahl an dieser Stelle wird beim naechsten
     * Release vergessen und widerspricht dann der plugin.cfg. */
    $kopf = array('Accept: application/json',
                  'User-Agent: LoxBerry-Raumklima');
    if ($mit_zugang) {
        $g = rk_geheim();
        if ($g['benutzer'] !== '') {
            $kopf[] = 'Authorization: Basic '
                    . base64_encode($g['benutzer'] . ':' . $g['passwort']);
        }
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        $text = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($text === false) { return array(null, 'NICHT_ERREICHBAR'); }
    } else {
        /* follow_location=0 ist kein Schoenheitsfehler, sondern gleicht die
         * beiden Wege an. Oben wird CURLOPT_FOLLOWLOCATION nicht gesetzt,
         * curl folgt also KEINER Weiterleitung. file_get_contents folgt von
         * sich aus (bis zu 20-mal) UND schickt den mitgegebenen Kopf erneut -
         * samt 'Authorization: Basic'. Eine Quelle koennte damit auf einen
         * fremden Rechner weiterleiten und bekaeme die Zugangsdaten dorthin
         * geliefert; wohin weitergeleitet wird, bestimmt die Quelle, nicht
         * der Betreiber. Ohne diese Zeile haengt es also davon ab, ob
         * php-curl geladen ist, ob die Zugangsdaten abfliessen koennen. */
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 12, 'header' => implode("\r\n", $kopf), 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1)));
        $text = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
            }
        }
        if ($text === false) { return array(null, 'NICHT_ERREICHBAR'); }
    }
    if ($code === 401 || $code === 403) { return array(null, 'ZUGANG_ABGELEHNT'); }
    $d = json_decode((string) $text, true);
    if (!is_array($d)) {
        /* Kommt HTML statt JSON zurueck, hat ein Anmeldeportal oder ein
         * Gateway geantwortet und nicht die Quelle. Das gehoert
         * unterschieden, sonst sucht man den Fehler am Pfad. */
        $anfang = ltrim(substr((string) $text, 0, 40));
        if ($anfang !== '' && $anfang[0] === '<') { return array(null, 'HTML_STATT_JSON'); }
        return array(null, 'KEIN_JSON');
    }
    return array($d, '');
}

/* ==================================================================
 * Verlaufsspeicher
 *
 * Bis 0.9.9 hatte das Plugin KEIN Gedaechtnis: stand.json war eine
 * Momentaufnahme. Damit liessen sich drei Fragen nicht beantworten, die
 * wichtiger sind als jede Momentaufnahme:
 *
 *   Wie lange steht die kalte Flaeche schon ueber 80 %? Schimmel waechst
 *   nicht aus einer Minute, sondern aus Stunden.
 *   Ist die Empfehlung eigentlich befolgt worden - und hat sie gewirkt?
 *   Wie viel Wasser kommt in diesem Raum je Stunde dazu?
 *
 * Zwei Aufloesungen, beide hart begrenzt: die Feinreihe traegt zwoelf
 * Stunden im Fuenfminutentakt, die Stundenreihe dreissig Tage. Der Ordner
 * data/ liegt auf der Platte, nicht auf der Ramdisk - anders als log/.
 * ================================================================== */

define('RK_FEIN_MAX', 144);      // 12 Stunden zu 5 Minuten
define('RK_STUNDEN_MAX', 720);   // 30 Tage
define('RK_EREIGNIS_MAX', 20);

function rk_verlauf_lesen()
{
    $v = rk_json_lesen(rk_paths()['datadir'] . '/verlauf.json');
    if (!isset($v['raeume']) || !is_array($v['raeume'])) { $v['raeume'] = array(); }
    return $v;
}

/** Ohne JSON_PRETTY_PRINT: die Datei waere sonst um ein Vielfaches groesser. */
function rk_verlauf_schreiben($v)
{
    $p = rk_paths();
    $ordner = $p['datadir'];
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    $ziel = $ordner . '/verlauf.json';
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $ziel)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Einen Raum fortschreiben. Veraendert $vr an Ort und Stelle.
 *
 * Feinreihe: ts, t, rf, absolut, ober_rf, co2, lueften
 * Stundenreihe: ts, t, rf, absolut, Stunden ueber 80 % (0 bis 1)
 */
function rk_verlauf_raum(&$vr, $e, $jetzt)
{
    if (!is_array($vr)) { $vr = array(); }
    foreach (array('fein', 'stunden', 'ereignisse') as $k) {
        if (!isset($vr[$k]) || !is_array($vr[$k])) { $vr[$k] = array(); }
    }
    if (empty($e['ok'])) { return; }

    $vr['fein'][] = array((int) $jetzt, $e['t'], $e['rf'], $e['absolut'],
                          $e['ober_rf'], $e['co2'], (int) $e['lueften']);
    if (count($vr['fein']) > RK_FEIN_MAX) {
        $vr['fein'] = array_slice($vr['fein'], -RK_FEIN_MAX);
    }

    /* Stundenkorb: sammeln, und beim Stundenwechsel als Mittel ablegen. */
    $stunde = (int) $jetzt - ((int) $jetzt % 3600);
    if (!isset($vr['korb']) || !is_array($vr['korb']) || (int) $vr['korb'][0] !== $stunde) {
        if (isset($vr['korb']) && is_array($vr['korb']) && (int) $vr['korb'][1] > 0) {
            $k = $vr['korb'];
            $n = (int) $k[1];
            $vr['stunden'][] = array((int) $k[0], round($k[2] / $n, 2), round($k[3] / $n, 1),
                                     round($k[4] / $n, 3), round($k[5] / $n, 3));
            if (count($vr['stunden']) > RK_STUNDEN_MAX) {
                $vr['stunden'] = array_slice($vr['stunden'], -RK_STUNDEN_MAX);
            }
        }
        $vr['korb'] = array($stunde, 0, 0.0, 0.0, 0.0, 0.0);
    }
    $vr['korb'][1]++;
    $vr['korb'][2] += (float) $e['t'];
    $vr['korb'][3] += (float) $e['rf'];
    $vr['korb'][4] += (float) $e['absolut'];
    $vr['korb'][5] += ($e['ober_rf'] !== null && $e['ober_rf'] >= 80.0) ? 1.0 : 0.0;

    /* Lueftungsereignis: beim Wechsel aus -> ein merken, spaeter bewerten. */
    $anzahl = count($vr['fein']);
    $vorher_an = ($anzahl >= 2) ? (int) $vr['fein'][$anzahl - 2][6] : 0;
    if (!$vorher_an && (int) $e['lueften']) {
        $vr['ereignisse'][] = array((int) $jetzt, (float) $e['absolut'], null, null);
        if (count($vr['ereignisse']) > RK_EREIGNIS_MAX) {
            $vr['ereignisse'] = array_slice($vr['ereignisse'], -RK_EREIGNIS_MAX);
        }
    }
    /* Offene Ereignisse nach 30 Minuten bewerten: ist die absolute Feuchte
     * wirklich gefallen? Das prueft die WIRKUNG der Empfehlung, nicht ihre
     * Ausgabe. 0,3 g/m3 liegen sicher ueber dem Messrauschen. */
    foreach ($vr['ereignisse'] as $i => $er) {
        if ($er[2] !== null) { continue; }
        if ((int) $jetzt - (int) $er[0] < 1800) { continue; }
        $vr['ereignisse'][$i][2] = (float) $e['absolut'];
        $vr['ereignisse'][$i][3] = (((float) $er[1] - (float) $e['absolut']) >= 0.3) ? 1 : 0;
    }
}

/**
 * Die abgeleiteten Werte eines Raums. Rueckgabe mit -1, wo die Reihe noch
 * zu kurz ist - eine 0 waere hier eine Aussage, die nicht belegt ist.
 */
function rk_verlauf_werte($vr, $jetzt, $volumen = 0)
{
    $out = array('nass24' => -1, 'nass7t' => -1, 'erfolg' => -1, 'eintrag' => -1);
    if (!is_array($vr)) { return $out; }

    if (!empty($vr['stunden'])) {
        $s24 = 0.0; $n24 = 0; $s7 = 0.0; $n7 = 0;
        foreach ($vr['stunden'] as $h) {
            $alt = (int) $jetzt - (int) $h[0];
            if ($alt < 0) { continue; }
            if ($alt <= 24 * 3600) { $s24 += (float) $h[4]; $n24++; }
            if ($alt <= 7 * 24 * 3600) { $s7 += (float) $h[4]; $n7++; }
        }
        if ($n24 > 0) { $out['nass24'] = round($s24, 1); }
        if ($n7 > 0) { $out['nass7t'] = round($s7, 1); }
    }

    if (!empty($vr['ereignisse'])) {
        $g = 0; $n = 0;
        foreach ($vr['ereignisse'] as $er) {
            if ($er[3] === null) { continue; }
            $n++;
            if ((int) $er[3]) { $g++; }
        }
        if ($n > 0) { $out['erfolg'] = (int) round(100.0 * $g / $n); }
    }

    /* Feuchteeintrag: der Anstieg der absoluten Feuchte in einer Strecke,
     * in der NICHT gelueftet wurde, mal das Raumvolumen. Gebraucht werden
     * mindestens zwei Stunden am Stueck; sonst ist die Steigung Rauschen. */
    if ((float) $volumen > 0 && !empty($vr['fein'])) {
        $strecke = array();
        foreach (array_reverse($vr['fein']) as $f) {
            if ((int) $f[6]) { break; }
            if ($f[3] === null) { break; }
            $strecke[] = $f;
        }
        $anz = count($strecke);
        if ($anz >= 24) {
            $neu = $strecke[0];
            $alt = $strecke[$anz - 1];
            $stunden = ((int) $neu[0] - (int) $alt[0]) / 3600.0;
            if ($stunden >= 2.0) {
                $g = ((float) $neu[3] - (float) $alt[3]) * (float) $volumen;
                $out['eintrag'] = round($g / $stunden, 1);
            }
        }
    }
    return $out;
}

/* ==================================================================
 * Der Zustand: alle Raeume, einmal gerechnet
 * ================================================================== */

function rk_stand()
{
    return rk_json_lesen(rk_paths()['datadir'] . '/stand.json');
}

/**
 * Alles abrufen und rechnen. Rueckgabe: das Abbild, das auch geschrieben
 * wird. $erzwingen umgeht den Takt.
 */
function rk_abrufen($erzwingen = false)
{
    $cfg = rk_config();
    $p = rk_paths();
    $alt = rk_stand();
    if (!$erzwingen && isset($alt['ts']) && (time() - (int) $alt['ts']) < (int) $cfg['takt']) {
        return $alt;
    }
    $jetzt = time();
    $stand = array('ts' => $jetzt, 'raeume' => array(), 'aussen' => null,
                   'meldungen' => array(), 'vorher_n' => 0);
    $verlauf = !empty($cfg['verlauf_ein']) ? rk_verlauf_lesen() : array('raeume' => array());

    /* ---- Aussen ---- */
    $vorher = array();
    if ($cfg['aussen_art'] === 'meteo') {
        list($d, $m) = rk_holen(rk_meteo_url($cfg['breite'], $cfg['laenge'], 2));
        if ($d === null) {
            $stand['meldungen']['aussen'] = $m;
        } else {
            list($vorher, $m2) = rk_meteo_lesen($d);
            if ($m2 !== '') {
                $stand['meldungen']['aussen'] = $m2;
            } else {
                $jetztwert = rk_meteo_jetzt($vorher, $jetzt);
                if ($jetztwert !== null) { $stand['aussen'] = $jetztwert; }
            }
        }
    } else {
        list($d, $m) = rk_holen($cfg['aussen_quelle'], true);
        if ($d === null) {
            $stand['meldungen']['aussen'] = $m;
        } else {
            $t = rk_temp_c(rk_zahl_aus(rk_pfad($d, $cfg['aussen_t'])),
                           $cfg['aussen_einheit_t']);
            $rf = rk_rf_prozent(rk_zahl_aus(rk_pfad($d, $cfg['aussen_rf'])),
                                $cfg['aussen_einheit_rf']);
            if (rk_t_gueltig($t) && $rf !== null && $rf > 0.0 && $rf <= 100.0) {
                $stand['aussen'] = array('t' => (float) $t, 'rf' => (float) $rf);
            } else {
                $stand['meldungen']['aussen'] = 'PFAD_LEER';
            }
        }
        /* Ohne Vorhersage bleibt die Momentaufnahme - und genau das ist die
         * Luecke, die dieses Plugin schliessen soll. Deshalb steht es als
         * Meldung da und nicht nur im Handbuch. */
        $stand['meldungen']['vorhersage'] = 'EIGENE_QUELLE_OHNE_VORHERSAGE';
    }
    $stand['vorher_n'] = count($vorher);
    if ($vorher) {
        // Nur die naechsten zwei Tage aufheben - mehr braucht niemand, und
        // das Abbild soll klein bleiben.
        $stand['vorher'] = array_slice($vorher, 0, 48, true);
    }

    /* ---- Raeume ---- */
    $gemeinsam = null;
    $bewertung = array('mindest' => $cfg['mindest'], 't_min' => $cfg['t_min'],
                       'af_unter' => $cfg['af_unter'], 'vorschau' => $cfg['vorschau'],
                       'steht_min' => $cfg['steht_min'], 'hyst' => $cfg['hyst'],
                       'dauer_min' => $cfg['dauer_min'], 'regen_max' => $cfg['regen_max'],
                       'kuehl_spanne' => $cfg['kuehl_spanne']);
    $aussen = is_array($stand['aussen']) ? $stand['aussen'] : array('t' => null, 'rf' => null);

    foreach (rk_raeume() as $nr => $r) {
        $quelle = trim((string) $r['quelle']) !== '' ? $r['quelle'] : $cfg['quelle'];
        $daten = null;
        if (trim((string) $r['quelle']) === '' && trim((string) $cfg['quelle']) !== '') {
            // Die gemeinsame Adresse wird EINMAL geholt, nicht je Raum.
            if ($gemeinsam === null) {
                list($gd, $gm) = rk_holen($cfg['quelle'], true);
                $gemeinsam = array($gd, $gm);
                if ($gd === null) { $stand['meldungen']['quelle'] = $gm; }
            }
            $daten = $gemeinsam[0];
        } elseif (trim((string) $quelle) !== '') {
            list($daten, $m) = rk_holen($quelle, true);
            if ($daten === null) { $stand['meldungen']['raum' . $nr] = $m; }
        }

        $roh = array('name' => $r['name'], 't' => null, 'rf' => null,
                     'frsi' => $r['frsi'], 'soll_min' => $r['soll_min'],
                     'soll_max' => $r['soll_max'], 'art' => $r['art'],
                     'erd_t' => $r['erd_t'], 'einheit_t' => $r['einheit_t'],
                     'einheit_rf' => $r['einheit_rf'], 'volumen' => $r['volumen'],
                     'fenster' => $r['fenster'], 't_soll' => $r['t_soll'],
                     'co2_max' => $r['co2_max'], 'co2' => null,
                     'fenster_offen' => null);
        if (is_array($daten)) {
            $roh['t'] = rk_pfad($daten, $r['pfad_t']);
            $roh['rf'] = rk_pfad($daten, $r['pfad_rf']);
            if (trim((string) $r['pfad_co2']) !== '') {
                $roh['co2'] = rk_pfad($daten, $r['pfad_co2']);
            }
            if (trim((string) $r['pfad_fenster']) !== '') {
                $roh['fenster_offen'] = rk_pfad($daten, $r['pfad_fenster']);
            }
        }
        /* Der vorige Eintrag desselben Raums - daraus entsteht die
         * Ausfallerkennung: seit wann kein Wert mehr kam und seit wann sich
         * der vorhandene nicht mehr bewegt. */
        $letzt = isset($alt['raeume'][$nr]) && is_array($alt['raeume'][$nr])
            ? $alt['raeume'][$nr] : null;
        $vr = isset($verlauf['raeume'][$nr]) ? $verlauf['raeume'][$nr] : array();
        $abgeleitet = !empty($cfg['verlauf_ein'])
            ? rk_verlauf_werte($vr, $jetzt, $r['volumen']) : null;
        $e = rk_raum_rechnen($roh, $aussen, $vorher, $bewertung, $jetzt, $letzt, $abgeleitet);
        $e['nr'] = $nr;
        $stand['raeume'][$nr] = $e;
        if (!empty($cfg['verlauf_ein'])) {
            rk_verlauf_raum($vr, $e, $jetzt);
            $verlauf['raeume'][$nr] = $vr;
        }
    }

    /* ---- Zusammenfassung ---- */
    $stand['schimmel_n'] = 0;
    $stand['lueften_n'] = 0;
    $stand['feucht_n'] = 0;
    $stand['trocken_n'] = 0;
    $stand['ohne_n'] = 0;
    $stand['steht_n'] = 0;
    $stand['kuehl_n'] = 0;
    $stand['co2_n'] = 0;
    $stand['fenster_n'] = 0;
    $mit_werten = 0;
    foreach ($stand['raeume'] as $e) {
        if ($e['schimmel']) { $stand['schimmel_n']++; }
        if ($e['lueften']) { $stand['lueften_n']++; }
        if ($e['feucht']) { $stand['feucht_n']++; }
        if ($e['trocken']) { $stand['trocken_n']++; }
        if ($e['ok']) { $mit_werten++; } else { $stand['ohne_n']++; }
        if (!empty($e['steht'])) { $stand['steht_n']++; }
        if (!empty($e['kuehlen'])) { $stand['kuehl_n']++; }
        if (!empty($e['co2_hoch'])) { $stand['co2_n']++; }
        if (!empty($e['fenster_zu'])) { $stand['fenster_n']++; }
    }
    /* OK zaehlt Raeume MIT WERTEN, nicht eingetragene Raeume.
     *
     * Gemessen am 24.08.2026: mit einer Quelle, die nur unlesbare Werte
     * lieferte, stand in der Antwortzeile OK=1 und ALTER=1 - frisch und in
     * Ordnung -, waehrend jeder einzelne Raum auf '-' stand. Ein Waechter
     * in Loxone konnte diesen Zustand von einem gesunden nicht
     * unterscheiden. */
    $stand['ok'] = $mit_werten > 0 ? 1 : 0;
    $stand['mit_werten'] = $mit_werten;

    /* Der Rueckgabewert wird angesehen: schlaegt das Schreiben fehl, zeigte
     * die Oberflaeche sonst alte Zahlen als aktuelle. */
    if (!rk_json_schreiben($p['datadir'] . '/stand.json', $stand)) {
        $stand['meldungen']['stand'] = 'NICHT_GESPEICHERT';
    }
    if (!empty($cfg['verlauf_ein'])) { rk_verlauf_schreiben($verlauf); }
    foreach ($stand['meldungen'] as $k => $m) {
        rk_log_gebremst('quelle_' . $k, 'Quelle ' . $k . ': ' . $m);
    }
    rk_mqtt_senden($stand);
    return $stand;
}

/* ==================================================================
 * MQTT
 * ================================================================== */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function rk_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function rk_mqtt_zustand()
{
    $p = rk_paths();
    /* fassung 0 heisst NICHT LESBAR und nicht "Fassung 1". Die Oberflaeche
     * behandelt beides verschieden: bei 0 stehen beide Saetze da, weil einen
     * davon zu behaupten fuer die Haelfte der Anlagen falsch waere. */
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0,
                  'broker' => '', 'brokerport' => '', 'fassung' => 0);
    if ($p['home'] === '') { return $leer; }
    $gen = rk_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $leer; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return $m[$gross]; }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'),
                                 array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
    );
}

/**
 * Ueber den UDP-Eingang des Gateways veroeffentlichen. Bewusst nicht mit
 * einem eigenen MQTT-Client: so muss das Plugin keine Broker-Zugangsdaten
 * kennen. Datenstroeme statt socket_* - die Erweiterung 'sockets' ist nicht
 * garantiert geladen, und ein Aufruf ohne sie ist ein Fatal error.
 */
function rk_mqtt_senden($stand)
{
    $cfg = rk_config();
    if (empty($cfg['mqtt_ein'])) { return false; }
    $z = rk_mqtt_zustand();
    if (!$z['udpport']) {
        rk_log_gebremst('mqtt_kein_port',
            'MQTT: kein UDP-Eingangsport in der general.json - nichts gesendet.');
        return false;
    }
    if (!$z['autostart']) {
        rk_log_gebremst('mqtt_aus', 'MQTT: das Gateway steht nicht auf Autostart '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    $paare = rk_mqtt_werte($stand);
    $nr = 0; $txt = '';
    $s = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'], $nr, $txt, 2);
    if (!$s) {
        rk_log_gebremst('mqtt_socket', 'MQTT: UDP-Eingang nicht erreichbar: ' . trim($txt));
        return false;
    }
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') { continue; }   // lieber nichts als eine erfundene 0
        @fwrite($s, 'publish ' . $praefix . '/' . $k . ' ' . rk_mqtt_wert_saeubern($v));
    }
    fclose($s);
    return true;
}

/** Alle Werte flach, so wie sie veroeffentlicht werden. */
function rk_mqtt_werte($stand)
{
    $w = array(
        'ok'         => isset($stand['ok']) ? (int) $stand['ok'] : 0,
        'raeume'     => isset($stand['raeume']) ? count($stand['raeume']) : 0,
        'lueften'    => isset($stand['lueften_n']) ? (int) $stand['lueften_n'] : 0,
        'schimmel'   => isset($stand['schimmel_n']) ? (int) $stand['schimmel_n'] : 0,
        'feucht'     => isset($stand['feucht_n']) ? (int) $stand['feucht_n'] : 0,
        'trocken'    => isset($stand['trocken_n']) ? (int) $stand['trocken_n'] : 0,
        'ohne'       => isset($stand['ohne_n']) ? (int) $stand['ohne_n'] : 0,
        'steht'      => isset($stand['steht_n']) ? (int) $stand['steht_n'] : 0,
        'kuehlen'    => isset($stand['kuehl_n']) ? (int) $stand['kuehl_n'] : 0,
        'co2'        => isset($stand['co2_n']) ? (int) $stand['co2_n'] : 0,
        'fenster'    => isset($stand['fenster_n']) ? (int) $stand['fenster_n'] : 0,
        'alter'      => isset($stand['ts']) ? max(0, time() - (int) $stand['ts']) : -1,
    );
    if (isset($stand['aussen']['t'])) {
        $w['aussen/t'] = round((float) $stand['aussen']['t'], 2);
        $w['aussen/rf'] = round((float) $stand['aussen']['rf'], 1);
        $w['aussen/taupunkt'] = rk_taupunkt($stand['aussen']['t'], $stand['aussen']['rf']);
        $w['aussen/absolut'] = rk_absolut($stand['aussen']['t'], $stand['aussen']['rf']);
    }
    foreach ((array) (isset($stand['raeume']) ? $stand['raeume'] : array()) as $nr => $e) {
        $z = 'raum' . (int) $nr . '/';
        $w[$z . 'name'] = $e['name'];
        $w[$z . 'ok'] = (int) $e['ok'];
        foreach (array('t', 'rf', 'taupunkt', 'absolut', 'ober_t', 'ober_rf',
                       'spread', 'vlmin', 'enth', 'co2') as $f) {
            if (isset($e[$f]) && $e[$f] !== null) { $w[$z . $f] = $e[$f]; }
        }
        $w[$z . 'lueften'] = (int) $e['lueften'];
        $w[$z . 'gewinn'] = $e['gewinn'];
        $w[$z . 'schimmel'] = (int) $e['schimmel'];
        $w[$z . 'feucht'] = (int) $e['feucht'];
        $w[$z . 'trocken'] = (int) $e['trocken'];
        $w[$z . 'best_in'] = (int) $e['best_in'];
        $w[$z . 'best_std'] = (int) $e['best_std'];
        $w[$z . 'alter'] = isset($e['alter']) ? (int) $e['alter'] : -1;
        $w[$z . 'steht'] = isset($e['steht']) ? (int) $e['steht'] : 0;
        foreach (array('ampel', 'nass24', 'nass7t', 'kuehlen', 'kuehlgewinn',
                       'dauer', 'kosten', 'erfolg', 'eintrag', 'co2_hoch',
                       'fenster', 'fenster_zu') as $f) {
            if (isset($e[$f]) && $e[$f] !== null) { $w[$z . $f] = $e[$f]; }
        }
    }
    return $w;
}

/** Die Themen mit ihrer Bedeutung - fuer den Reiter MQTT. */
function rk_mqtt_themen()
{
    return array(
        'ok'                 => 'MQTT.OK',
        'raeume'             => 'MQTT.RAEUME',
        'lueften'            => 'MQTT.LUEFTEN',
        'schimmel'           => 'MQTT.SCHIMMEL',
        'feucht'             => 'MQTT.FEUCHT',
        'trocken'            => 'MQTT.TROCKEN',
        'ohne'               => 'MQTT.OHNE',
        'steht'              => 'MQTT.STEHT',
        'kuehlen'            => 'MQTT.KUEHLEN',
        'co2'                => 'MQTT.CO2',
        'fenster'            => 'MQTT.FENSTER',
        'alter'              => 'MQTT.ALTER',
        'aussen/t'           => 'MQTT.A_T',
        'aussen/rf'          => 'MQTT.A_RF',
        'aussen/taupunkt'    => 'MQTT.A_TAU',
        'aussen/absolut'     => 'MQTT.A_ABS',
        'raumN/name'         => 'MQTT.R_NAME',
        'raumN/ok'           => 'MQTT.R_OK',
        'raumN/t'            => 'MQTT.R_T',
        'raumN/rf'           => 'MQTT.R_RF',
        'raumN/taupunkt'     => 'MQTT.R_TAU',
        'raumN/absolut'      => 'MQTT.R_ABS',
        'raumN/ober_t'       => 'MQTT.R_OBER_T',
        'raumN/ober_rf'      => 'MQTT.R_OBER_RF',
        'raumN/lueften'      => 'MQTT.R_LUEFTEN',
        'raumN/gewinn'       => 'MQTT.R_GEWINN',
        'raumN/schimmel'     => 'MQTT.R_SCHIMMEL',
        'raumN/feucht'       => 'MQTT.R_FEUCHT',
        'raumN/trocken'      => 'MQTT.R_TROCKEN',
        'raumN/best_in'      => 'MQTT.R_BEST_IN',
        'raumN/best_std'     => 'MQTT.R_BEST_STD',
        'raumN/spread'       => 'MQTT.R_SPREAD',
        'raumN/vlmin'        => 'MQTT.R_VLMIN',
        'raumN/alter'        => 'MQTT.R_ALTER',
        'raumN/steht'        => 'MQTT.R_STEHT',
        'raumN/enth'         => 'MQTT.R_ENTH',
        'raumN/ampel'        => 'MQTT.R_AMPEL',
        'raumN/nass24'       => 'MQTT.R_NASS24',
        'raumN/nass7t'       => 'MQTT.R_NASS7T',
        'raumN/kuehlen'      => 'MQTT.R_KUEHLEN',
        'raumN/kuehlgewinn'  => 'MQTT.R_KUEHLG',
        'raumN/dauer'        => 'MQTT.R_DAUER',
        'raumN/kosten'       => 'MQTT.R_KOSTEN',
        'raumN/erfolg'       => 'MQTT.R_ERFOLG',
        'raumN/eintrag'      => 'MQTT.R_EINTRAG',
        'raumN/co2'          => 'MQTT.R_CO2',
        'raumN/co2_hoch'     => 'MQTT.R_CO2HOCH',
        'raumN/fenster'      => 'MQTT.R_FENSTER',
        'raumN/fenster_zu'   => 'MQTT.R_FENSTERZU',
    );
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 * ================================================================== */

function rk_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function rk_xml_virtual_in_http($kopf, $cmds)
{
    /* Gemessen am 24.08.2026 gegen die beiden massgeblichen Ausfuhren
     * (VI_Marstek Speicher und VI_Rasenmaeher, beide vom 12.08.2026). Drei
     * Dinge fehlten und sind jetzt da: die Bytefolge am Anfang, das
     * Attribut HintText - im Original steht es VOR Title - und das
     * Kindelement <Info>. Dazu die Einheit je Befehl. */
    $crlf = "\r\n";
    $o = "\xEF\xBB\xBF";
    $o .= '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . rk_x($kopf['title']) . '" ';
    $o .= 'Comment="' . rk_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . rk_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . rk_x(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . rk_x($c['title']) . '" ';
        $o .= 'Comment="' . rk_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . rk_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . rk_x(isset($c['min']) ? $c['min'] : '-100') . '" ';
        $o .= 'MaxVal="' . rk_x(isset($c['max']) ? $c['max'] : '100') . '" ';
        $o .= 'Unit="' . rk_x(isset($c['unit']) ? $c['unit'] : '') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Felder je Raum: Kuerzel, Einheit, Grenzen, Sprachschluessel, und die
 * Einheit in der Schreibweise von Loxone.
 *
 * MinVal/MaxVal realistisch - Loxone zieht daraus die Reglergrenzen und die
 * Plausibilitaetspruefung. Die letzte Spalte wird als Attribut 'Unit' in die
 * Vorlage geschrieben; Config legt sie beim Speichern an ein
 * <Display>-Kindelement um, angekommen ist sie trotzdem.
 *
 * NEUE FELDER GEHOEREN ANS ENDE. Weiter oben eingefuegt verschoeben sie die
 * Reihenfolge in der Statuszeile.
 */
function rk_felder()
{
    return array(
        'T'        => array('C',    -30,  60, 'RK_FELD.T',        '<v.1> °C'),
        'RF'       => array('%',      0, 100, 'RK_FELD.RF',       '<v.0> %'),
        'TAU'      => array('C',    -40,  40, 'RK_FELD.TAU',      '<v.1> °C'),
        'ABS'      => array('g/m3',   0,  40, 'RK_FELD.ABS',      '<v.2> g/m³'),
        'OBERT'    => array('C',    -30,  60, 'RK_FELD.OBERT',    '<v.1> °C'),
        'OBERRF'   => array('%',      0, 100, 'RK_FELD.OBERRF',   '<v.0> %'),
        'LUEFTEN'  => array('',       0,   1, 'RK_FELD.LUEFTEN',  ''),
        'GEWINN'   => array('g/m3', -40,  40, 'RK_FELD.GEWINN',   '<v.2> g/m³'),
        'SCHIMMEL' => array('',       0,   1, 'RK_FELD.SCHIMMEL', ''),
        'FEUCHT'   => array('',       0,   1, 'RK_FELD.FEUCHT',   ''),
        'TROCKEN'  => array('',       0,   1, 'RK_FELD.TROCKEN',  ''),
        'BESTIN'   => array('min',   -1, 2880, 'RK_FELD.BESTIN',  '<v.0> min'),
        'BESTSTD'  => array('h',     -1,  23, 'RK_FELD.BESTSTD',  '<v.0> h'),
        'SPREAD'   => array('K',    -40,  60, 'RK_FELD.SPREAD',   '<v.1> K'),
        'VLMIN'    => array('C',    -40,  40, 'RK_FELD.VLMIN',    '<v.1> °C'),
        'RALTER'   => array('s',     -1, 86400, 'RK_FELD.RALTER', '<v.0> s'),
        'STEHT'    => array('',       0,   1, 'RK_FELD.STEHT',    ''),
        'ENTH'     => array('kJ/kg',  0, 120, 'RK_FELD.ENTH',     '<v.1> kJ/kg'),
        'AMPEL'    => array('',       0,   3, 'RK_FELD.AMPEL',    ''),
        'NASS24'   => array('h',     -1,  24, 'RK_FELD.NASS24',   '<v.1> h'),
        'NASS7T'   => array('h',     -1, 168, 'RK_FELD.NASS7T',   '<v.1> h'),
        'KUEHLEN'  => array('',       0,   1, 'RK_FELD.KUEHLEN',  ''),
        'KUEHLG'   => array('K',    -40,  40, 'RK_FELD.KUEHLG',   '<v.1> K'),
        'DAUER'    => array('min',   -1,  60, 'RK_FELD.DAUER',    '<v.0> min'),
        'KOSTEN'   => array('Wh',    -1, 5000, 'RK_FELD.KOSTEN',  '<v.0> Wh'),
        'ERFOLG'   => array('%',     -1, 100, 'RK_FELD.ERFOLG',   '<v.0> %'),
        'EINTRAG'  => array('g/h',   -1, 2000, 'RK_FELD.EINTRAG', '<v.0> g/h'),
        'CO2'      => array('ppm',   -1, 5000, 'RK_FELD.CO2',     '<v.0> ppm'),
        'CO2HOCH'  => array('',       0,   1, 'RK_FELD.CO2HOCH',  ''),
        'FENSTER'  => array('',      -1,   1, 'RK_FELD.FENSTER',  ''),
        'FENSTERZU' => array('',      0,   1, 'RK_FELD.FENSTERZU', ''),
    );
}

/**
 * Der Suchtext fuer einen virtuellen Eingang - an EINER Stelle.
 *
 * Das Semikolon gehoert dazu. Loxone nimmt die ERSTE Fundstelle, und ohne
 * Trennzeichen faende '\iR1T=' zuerst jedes laengere Feld, das auf 'R1T'
 * endet. In der heutigen Feldliste kollidiert nichts - das entscheidet aber
 * das naechste neue Feld neu, und ein falscher Treffer faellt nicht auf:
 * beide Zahlen sehen aus wie eine Temperatur. Vor jedem Feldnamen der
 * Antwortzeile steht ein Semikolon, auch vor dem ersten.
 */
function rk_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

function rk_klartext($schluessel)
{
    return trim(strip_tags(html_entity_decode(rk_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

function rk_endpunkt()
{
    $p = rk_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php';
}

/** Vorlage fuer den Import. Rueckgabe: array(name, inhalt) */
function rk_vorlage()
{
    $cmds = array();
    foreach (rk_raeume() as $nr => $r) {
        $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $r['name']));
        if ($kurz === '') { $kurz = 'RAUM' . $nr; }
        $kurz = substr($kurz, 0, 12);
        foreach (rk_felder() as $feld => $info) {
            $cmds[] = array(
                'title'   => 'RK_' . $kurz . '_' . $feld,
                'comment' => $r['name'] . ': ' . rk_klartext($info[3])
                             . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
                'check'   => rk_check('R' . $nr . $feld),
                'min'     => $info[1],
                'max'     => $info[2],
                'unit'    => isset($info[4]) ? $info[4] : '',
            );
        }
    }
    // Aussen und Zusammenfassung
    foreach (array(
        'AT'   => array('C',   -50, 60, 'RK_FELD.AT',   '<v.1> °C'),
        'ARF'  => array('%',     0, 100, 'RK_FELD.ARF', '<v.0> %'),
        'ATAU' => array('C',   -50, 40, 'RK_FELD.ATAU', '<v.1> °C'),
        'AABS' => array('g/m3',  0, 40, 'RK_FELD.AABS', '<v.2> g/m³'),
        'NLUEFT' => array('',    0, 99, 'RK_FELD.NLUEFT', ''),
        'NSCHIMMEL' => array('', 0, 99, 'RK_FELD.NSCHIMMEL', ''),
        'ALTER' => array('s',    0, 86400, 'RK_FELD.ALTER', '<v.0> s'),
        'OK'   => array('',      0, 1, 'RK_FELD.OK', ''),
        'NOHNE' => array('',     0, 99, 'RK_FELD.NOHNE', ''),
        'NSTEHT' => array('',    0, 99, 'RK_FELD.NSTEHT', ''),
        'NKUEHL' => array('',    0, 99, 'RK_FELD.NKUEHL', ''),
        'NCO2'  => array('',     0, 99, 'RK_FELD.NCO2', ''),
        'NFENSTER' => array('',  0, 99, 'RK_FELD.NFENSTER', ''),
    ) as $feld => $info) {
        $cmds[] = array(
            'title'   => 'RK_' . $feld,
            'comment' => rk_klartext($info[3]) . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => rk_check($feld),
            'min'     => $info[1],
            'max'     => $info[2],
            'unit'    => isset($info[4]) ? $info[4] : '',
        );
    }
    $adresse = rk_endpunkt() . '?token=' . rk_token() . '&aktion=status';
    return array('VI_RAUMKLIMA.xml', rk_xml_virtual_in_http(array(
        'title'   => 'Raumklima',
        'address' => $adresse,
        'polling' => '300',
        'comment' => sprintf(rk_klartext('RK_XML.KOPF'), date('d.m.Y')),
    ), $cmds));
}

/**
 * Die Baustein-Liste fuer den Reiter "Einbindung in Loxone".
 *
 * Zuerst die Felder - je eines je Zeile -, danach die Bausteine. Die
 * Verweise darin ("Ausgang von #17") werden GERECHNET, nicht getippt: kommt
 * ein Feld dazu, verschoebe sich sonst jeder Verweis um eins, lautlos, denn
 * eine Zahl sieht immer richtig aus.
 *
 * Gebaut wird die Liste fuer den ERSTEN eingerichteten Raum. Fuer jeden
 * weiteren sind es dieselben Bausteine mit seiner Nummer davor - alles
 * zwoelfmal auszuschreiben hilft niemandem.
 */
function rk_bausteine()
{
    $raeume = rk_raeume();
    $erster = $raeume ? reset($raeume) : null;
    $rn = $erster ? (int) $erster['nr'] : 1;
    $rname = $erster ? $erster['name'] : rk_t('LOX.B_RAUM_PLATZHALTER');
    $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rname));
    if ($kurz === '') { $kurz = 'RAUM' . $rn; }
    $kurz = substr($kurz, 0, 12);

    /* --- Die Felder, die die Bausteine unten brauchen --- */
    $felder = array(
        array('RK_OK',                     'RK_FELD.OK'),
        array('RK_ALTER',                  'RK_FELD.ALTER'),
        array('RK_NOHNE',                  'RK_FELD.NOHNE'),
        array('RK_NSTEHT',                 'RK_FELD.NSTEHT'),
        array('RK_' . $kurz . '_LUEFTEN',  'RK_FELD.LUEFTEN'),
        array('RK_' . $kurz . '_SCHIMMEL', 'RK_FELD.SCHIMMEL'),
        array('RK_' . $kurz . '_SPREAD',   'RK_FELD.SPREAD'),
        array('RK_' . $kurz . '_VLMIN',    'RK_FELD.VLMIN'),
        array('RK_' . $kurz . '_RALTER',   'RK_FELD.RALTER'),
        array('RK_' . $kurz . '_STEHT',    'RK_FELD.STEHT'),
        array('RK_' . $kurz . '_BESTIN',   'RK_FELD.BESTIN'),
        array('RK_' . $kurz . '_BESTSTD',  'RK_FELD.BESTSTD'),
    );
    $f = array();
    $nr = 0;
    $platz = array();
    foreach ($felder as $e) {
        $nr++;
        $platz[$e[0]] = $nr;
        $f[] = array('nr' => $nr, 'titel' => $e[0], 'bedeutung' => rk_t($e[1]));
    }
    /* Verweis auf ein Feld - gerechnet, nie getippt. */
    $vf = function ($titel) use ($platz) {
        return isset($platz[$titel]) ? '#' . $platz[$titel] . ' ' . $titel : $titel;
    };

    /* --- Die Bausteine. $b(1) ist der erste, gezaehlt hinter den Feldern. --- */
    $anzf = $nr;
    $b = function ($i) use ($anzf) { return $anzf + $i; };
    $vb = function ($i) use ($anzf) { return '#' . ($anzf + $i); };

    $bausteine = array(
        array($b(1), rk_t('LOX.B_SCHWELL'), rk_t('LOX.BN_ABRUF_ALT'),
              rk_t('LOX.BP_ABRUF_ALT'), $vf('RK_ALTER')),
        array($b(2), rk_t('LOX.B_SCHWELL'), sprintf(rk_t('LOX.BN_RAUM_STUMM'), $rname),
              rk_t('LOX.BP_RAUM_STUMM'), $vf('RK_' . $kurz . '_RALTER')),
        array($b(3), rk_t('LOX.B_NICHT'), rk_t('LOX.BN_KEINE_WERTE'),
              rk_t('LOX.BP_KEINE'), $vf('RK_OK')),
        array($b(4), rk_t('LOX.B_EINVERZ'), sprintf(rk_t('LOX.BN_SCHIMMEL'), $rname),
              rk_t('LOX.BP_SCHIMMEL'), $vf('RK_' . $kurz . '_SCHIMMEL')),
        array($b(5), rk_t('LOX.B_ODER'), rk_t('LOX.BN_STOERUNG'), rk_t('LOX.BP_KEINE'),
              sprintf(rk_t('LOX.BE_STOERUNG'), $vb(1), $vb(2), $vb(3), $vb(4),
                      $vf('RK_' . $kurz . '_STEHT'))),
        array($b(6), rk_t('LOX.B_BENACHR'), rk_t('LOX.BN_MELDUNG'),
              rk_t('LOX.BP_BENACHR'), sprintf(rk_t('LOX.BE_NUR_ODER'), $vb(5))),
        array($b(7), rk_t('LOX.B_MERKER'), sprintf(rk_t('LOX.BN_LUEFTEN'), $rname),
              rk_t('LOX.BP_KEINE'), $vf('RK_' . $kurz . '_LUEFTEN')),
        array($b(8), rk_t('LOX.B_BEGRENZER'), sprintf(rk_t('LOX.BN_VLMIN'), $rname),
              rk_t('LOX.BP_VLMIN'), $vf('RK_' . $kurz . '_VLMIN')),
    );

    $hinweise = array(
        array($vb(1), rk_t('LOX.ZU_ABRUF_ALT')),
        array($vb(4), rk_t('LOX.ZU_SCHIMMEL')),
        array($vb(5), rk_t('LOX.ZU_ODER')),
        array($vb(6), rk_t('LOX.ZU_BENACHR')),
        array($vb(8), rk_t('LOX.ZU_VLMIN')),
    );

    return array('felder' => $f, 'bausteine' => $bausteine, 'hinweise' => $hinweise,
                 'raum' => $rname, 'nr' => $rn, 'kurz' => $kurz);
}

/** Die Statuszeile fuer den Miniserver. */
function rk_zeile($stand)
{
    /* Neue Felder haengen HINTEN an. Sie in die Mitte zu setzen verschoebe
     * die Reihenfolge der bestehenden - und jede beim Anwender eingetragene
     * Befehlserkennung zeigte danach auf einen anderen Wert. */
    $o = sprintf("RAUMKLIMA;OK=%d;NLUEFT=%d;NSCHIMMEL=%d;NFEUCHT=%d;NTROCKEN=%d"
        . ";ALTER=%d;NOHNE=%d;NSTEHT=%d;NKUEHL=%d;NCO2=%d;NFENSTER=%d",
        isset($stand['ok']) ? (int) $stand['ok'] : 0,
        isset($stand['lueften_n']) ? (int) $stand['lueften_n'] : 0,
        isset($stand['schimmel_n']) ? (int) $stand['schimmel_n'] : 0,
        isset($stand['feucht_n']) ? (int) $stand['feucht_n'] : 0,
        isset($stand['trocken_n']) ? (int) $stand['trocken_n'] : 0,
        isset($stand['ts']) ? max(0, time() - (int) $stand['ts']) : -1,
        isset($stand['ohne_n']) ? (int) $stand['ohne_n'] : 0,
        isset($stand['steht_n']) ? (int) $stand['steht_n'] : 0,
        isset($stand['kuehl_n']) ? (int) $stand['kuehl_n'] : 0,
        isset($stand['co2_n']) ? (int) $stand['co2_n'] : 0,
        isset($stand['fenster_n']) ? (int) $stand['fenster_n'] : 0);
    $w = function ($v) { return ($v === null || !is_numeric($v)) ? '-' : (string) (0 + $v); };
    $o .= ';AT=' . $w(isset($stand['aussen']['t']) ? $stand['aussen']['t'] : null);
    $o .= ';ARF=' . $w(isset($stand['aussen']['rf']) ? $stand['aussen']['rf'] : null);
    $o .= ';ATAU=' . $w(isset($stand['aussen']['t'])
        ? rk_taupunkt($stand['aussen']['t'], $stand['aussen']['rf']) : null);
    $o .= ';AABS=' . $w(isset($stand['aussen']['t'])
        ? rk_absolut($stand['aussen']['t'], $stand['aussen']['rf']) : null);
    $o .= "\n";

    foreach ((array) (isset($stand['raeume']) ? $stand['raeume'] : array()) as $nr => $e) {
        $t = array();
        $paare = array('T' => 't', 'RF' => 'rf', 'TAU' => 'taupunkt', 'ABS' => 'absolut',
                       'OBERT' => 'ober_t', 'OBERRF' => 'ober_rf', 'LUEFTEN' => 'lueften',
                       'GEWINN' => 'gewinn', 'SCHIMMEL' => 'schimmel', 'FEUCHT' => 'feucht',
                       'TROCKEN' => 'trocken', 'BESTIN' => 'best_in', 'BESTSTD' => 'best_std',
                       'SPREAD' => 'spread', 'VLMIN' => 'vlmin', 'RALTER' => 'alter',
                       'STEHT' => 'steht', 'ENTH' => 'enth', 'AMPEL' => 'ampel',
                       'NASS24' => 'nass24', 'NASS7T' => 'nass7t',
                       'KUEHLEN' => 'kuehlen', 'KUEHLG' => 'kuehlgewinn',
                       'DAUER' => 'dauer', 'KOSTEN' => 'kosten',
                       'ERFOLG' => 'erfolg', 'EINTRAG' => 'eintrag',
                       'CO2' => 'co2', 'CO2HOCH' => 'co2_hoch',
                       'FENSTER' => 'fenster', 'FENSTERZU' => 'fenster_zu');
        foreach ($paare as $kurz => $feld) {
            $t[] = 'R' . (int) $nr . $kurz . '=' . $w(isset($e[$feld]) ? $e[$feld] : null);
        }
        $o .= 'RAUM' . (int) $nr . ';' . implode(';', $t) . "\n";
    }
    return $o;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch.
 * ================================================================== */

function rk_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function rk_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . rk_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $wv) { $texte[$ab][$s] = trim((string) $wv, '"'); }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function rk_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(rk_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = rk_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(rk_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = rk_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
