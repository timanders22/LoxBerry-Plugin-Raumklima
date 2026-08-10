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
    );
}

function rk_vorgaben()
{
    return array(
        'raeume'      => array(),
        'quelle'      => '',        // gemeinsame Adresse fuer alle Raeume
        'takt'        => 300,       // Sekunden zwischen zwei Abrufen
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
        @mkdir($p['configdir'], 0775, true);
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
        $cfg['raeume'][$i] = $r;
    }
    $cfg['takt'] = max(60, min(3600, (int) $cfg['takt']));
    $cfg['mindest'] = max(0.0, min(10.0, (float) $cfg['mindest']));
    $cfg['t_min'] = max(-30, min(30, (float) $cfg['t_min']));
    $cfg['af_unter'] = max(0.0, min(20.0, (float) $cfg['af_unter']));
    $cfg['vorschau'] = max(1, min(48, (int) $cfg['vorschau']));
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

/** Nur die Raeume, die einen Namen und beide Pfade tragen. 1-basiert. */
function rk_raeume()
{
    $cfg = rk_config();
    $out = array();
    $n = 0;
    foreach ($cfg['raeume'] as $r) {
        if (trim((string) $r['name']) === '') { continue; }
        if (trim((string) $r['pfad_t']) === '' && trim((string) $r['pfad_rf']) === '') { continue; }
        $n++;
        $r['nr'] = $n;
        $out[$n] = $r;
    }
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

    $kopf = array('Accept: application/json',
                  'User-Agent: LoxBerry-Raumklima/0.9.1');
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
            $t = rk_pfad($d, $cfg['aussen_t']);
            $rf = rk_pfad($d, $cfg['aussen_rf']);
            if (is_numeric($t) && is_numeric($rf)) {
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
                       'af_unter' => $cfg['af_unter'], 'vorschau' => $cfg['vorschau']);
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
                     'soll_max' => $r['soll_max']);
        if (is_array($daten)) {
            $roh['t'] = rk_pfad($daten, $r['pfad_t']);
            $roh['rf'] = rk_pfad($daten, $r['pfad_rf']);
        }
        $e = rk_raum_rechnen($roh, $aussen, $vorher, $bewertung, $jetzt);
        $e['nr'] = $nr;
        $stand['raeume'][$nr] = $e;
    }

    /* ---- Zusammenfassung ---- */
    $stand['schimmel_n'] = 0;
    $stand['lueften_n'] = 0;
    $stand['feucht_n'] = 0;
    $stand['trocken_n'] = 0;
    foreach ($stand['raeume'] as $e) {
        if ($e['schimmel']) { $stand['schimmel_n']++; }
        if ($e['lueften']) { $stand['lueften_n']++; }
        if ($e['feucht']) { $stand['feucht_n']++; }
        if ($e['trocken']) { $stand['trocken_n']++; }
    }
    $stand['ok'] = count($stand['raeume']) > 0 ? 1 : 0;

    rk_json_schreiben($p['datadir'] . '/stand.json', $stand);
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
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0,
                  'broker' => '', 'brokerport' => '');
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
        foreach (array('t', 'rf', 'taupunkt', 'absolut', 'ober_t', 'ober_rf') as $f) {
            if ($e[$f] !== null) { $w[$z . $f] = $e[$f]; }
        }
        $w[$z . 'lueften'] = (int) $e['lueften'];
        $w[$z . 'gewinn'] = $e['gewinn'];
        $w[$z . 'schimmel'] = (int) $e['schimmel'];
        $w[$z . 'feucht'] = (int) $e['feucht'];
        $w[$z . 'trocken'] = (int) $e['trocken'];
        $w[$z . 'best_in'] = (int) $e['best_in'];
        $w[$z . 'best_std'] = (int) $e['best_std'];
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
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . rk_x($kopf['title']) . '" ';
    $o .= 'Comment="' . rk_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . rk_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . rk_x(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
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
        $o .= 'MaxVal="' . rk_x(isset($c['max']) ? $c['max'] : '100') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Felder je Raum: Kuerzel, Einheit, Grenzen, Sprachschluessel.
 * MinVal/MaxVal realistisch - Loxone zieht daraus die Reglergrenzen und die
 * Plausibilitaetspruefung.
 */
function rk_felder()
{
    return array(
        'T'        => array('C',    -30,  60, 'RK_FELD.T'),
        'RF'       => array('%',      0, 100, 'RK_FELD.RF'),
        'TAU'      => array('C',    -40,  40, 'RK_FELD.TAU'),
        'ABS'      => array('g/m3',   0,  40, 'RK_FELD.ABS'),
        'OBERT'    => array('C',    -30,  60, 'RK_FELD.OBERT'),
        'OBERRF'   => array('%',      0, 100, 'RK_FELD.OBERRF'),
        'LUEFTEN'  => array('',       0,   1, 'RK_FELD.LUEFTEN'),
        'GEWINN'   => array('g/m3', -40,  40, 'RK_FELD.GEWINN'),
        'SCHIMMEL' => array('',       0,   1, 'RK_FELD.SCHIMMEL'),
        'FEUCHT'   => array('',       0,   1, 'RK_FELD.FEUCHT'),
        'TROCKEN'  => array('',       0,   1, 'RK_FELD.TROCKEN'),
        'BESTIN'   => array('min',   -1, 2880, 'RK_FELD.BESTIN'),
        'BESTSTD'  => array('h',     -1,  23, 'RK_FELD.BESTSTD'),
    );
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
                'check'   => '\iR' . $nr . $feld . '=\i\v',
                'min'     => $info[1],
                'max'     => $info[2],
            );
        }
    }
    // Aussen und Zusammenfassung
    foreach (array(
        'AT'   => array('C',   -50, 60, 'RK_FELD.AT'),
        'ARF'  => array('%',     0, 100, 'RK_FELD.ARF'),
        'ATAU' => array('C',   -50, 40, 'RK_FELD.ATAU'),
        'AABS' => array('g/m3',  0, 40, 'RK_FELD.AABS'),
        'NLUEFT' => array('',    0, 99, 'RK_FELD.NLUEFT'),
        'NSCHIMMEL' => array('', 0, 99, 'RK_FELD.NSCHIMMEL'),
        'ALTER' => array('s',    0, 86400, 'RK_FELD.ALTER'),
        'OK'   => array('',      0, 1, 'RK_FELD.OK'),
    ) as $feld => $info) {
        $cmds[] = array(
            'title'   => 'RK_' . $feld,
            'comment' => rk_klartext($info[3]) . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => $info[1],
            'max'     => $info[2],
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

/** Die Statuszeile fuer den Miniserver. */
function rk_zeile($stand)
{
    $o = sprintf("RAUMKLIMA;OK=%d;NLUEFT=%d;NSCHIMMEL=%d;NFEUCHT=%d;NTROCKEN=%d;ALTER=%d",
        isset($stand['ok']) ? (int) $stand['ok'] : 0,
        isset($stand['lueften_n']) ? (int) $stand['lueften_n'] : 0,
        isset($stand['schimmel_n']) ? (int) $stand['schimmel_n'] : 0,
        isset($stand['feucht_n']) ? (int) $stand['feucht_n'] : 0,
        isset($stand['trocken_n']) ? (int) $stand['trocken_n'] : 0,
        isset($stand['ts']) ? max(0, time() - (int) $stand['ts']) : -1);
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
                       'TROCKEN' => 'trocken', 'BESTIN' => 'best_in', 'BESTSTD' => 'best_std');
        foreach ($paare as $kurz => $feld) {
            $t[] = 'R' . (int) $nr . $kurz . '=' . $w($e[$feld]);
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
