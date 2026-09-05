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

/* Groesste Antwort, die eine Quelle liefern darf. Ein Fuehler antwortet mit
 * einigen hundert Byte, der groesste bekannte Umschlag (Shelly.GetStatus)
 * mit knapp 8 kB. Zwei Megabyte sind das Hundertfache davon und bleiben
 * weit unter jedem memory_limit; was darueber kommt, ist kein Messwert
 * mehr. Siehe die Begruendung in rk_holen(). */
define('RK_ANTWORT_MAX', 2 * 1024 * 1024);

/**
 * Ein GESAMTBUDGET fuer einen Abruf, in Sekunden.
 *
 * Die Schranken in rk_holen() gelten je Abruf (12 s, davon 6 s fuer den
 * Verbindungsaufbau). Ein voll besetzter Aufbau macht zwoelf Raeume mal
 * zwei Adressen plus die Aussenquelle = 25 Abrufe nacheinander;
 * rechnerisch 300 s - und genau 300 s stehen als PollingTime in der
 * erzeugten Vorlage und als Untergrenze des Taktes. Der naechste Cron-Lauf
 * ueberholte den vorigen also im schlimmsten Fall.
 *
 * Deshalb fuehrt rk_abrufen() seit 0.11.3 eine Frist mit. Wer sie
 * ueberschreitet, fragt nicht weiter, sondern meldet ZEIT_ABGELAUFEN -
 * ein fehlender Wert ist eine Auskunft, ein haengender Lauf keine.
 */
function rk_frist($setzen = null)
{
    static $bis = 0;
    if ($setzen !== null) { $bis = (int) $setzen; }
    return $bis;
}

/** Wie viele Sekunden bleiben? 0 heisst: keine Frist gesetzt. */
function rk_frist_rest()
{
    $bis = rk_frist();
    if ($bis <= 0) { return 0; }
    return $bis - time();
}

/**
 * Die Zeitzone, in der dieses Plugin rechnet.
 *
 * Ohne `date.timezone` in der php.ini rechnet PHP in UTC, und die
 * Umgebungsvariable TZ liest es seit 5.4 nicht mehr. Bis 0.11.2 setzte
 * das Plugin nichts - damit standen alle Protokollzeitstempel gegen das
 * LoxBerry-Systemprotokoll verschoben, der Tageswechsel der .kaputt-Kopie
 * fiel auf 02:00 Ortszeit, und eine Ruhezeit 22:00-06:00 wirkte von 00:00
 * bis 08:00. Der Kommentar in rk_klima.php behauptete das Gegenteil.
 *
 * Reihenfolge: was das Betriebssystem sagt (auf Debian /etc/timezone,
 * und genau dort landet die Einstellung aus der LoxBerry-Oberflaeche).
 * Sonst die php.ini. PHP 8 fuehrt dort UTC als VORGABE, nicht als
 * Entscheidung - wer die ini zuerst fragte, laese auf einem LoxBerry 4
 * mit Debian 13 nie die Systemzone.
 * Sonst UTC - und dann steht es so im Reiter Test, statt geraten zu
 * werden. Ein ungueltiger Name wird abgewiesen, nicht gesetzt.
 */
function rk_zeitzone()
{
    static $z = null;
    if ($z !== null) { return $z; }
    $z = rk_zeitzone_system();
    if ($z === '') { $z = trim((string) ini_get('date.timezone')); }
    if ($z === '' || !in_array($z, timezone_identifiers_list(), true)) {
        $z = 'UTC';
    }
    return $z;
}

/** Die Zone des Betriebssystems, oder '' wenn keine lesbar ist. */
function rk_zeitzone_system()
{
    if (!@is_readable('/etc/timezone')) { return ''; }
    $t = trim((string) @file_get_contents('/etc/timezone'));
    if ($t === '' || !in_array($t, timezone_identifiers_list(), true)) { return ''; }
    return $t;
}

/** Woher die Zeitzone stammt - fuer den Reiter Test. */
function rk_zeitzone_quelle()
{
    if (rk_zeitzone_system() !== '') { return '/etc/timezone'; }
    $i = trim((string) ini_get('date.timezone'));
    if ($i !== '' && in_array($i, timezone_identifiers_list(), true)) { return 'php.ini'; }
    return 'Rueckfall UTC';
}

date_default_timezone_set(rk_zeitzone());


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
        /* Eine ZWEITE Adresse, nur fuer die Feuchte.
         *
         * Bis 0.11.1 hatte ein Raum genau eine Adresse, und beide Pfade
         * gingen in dieselbe Antwort. Beim Miniserver geht das nicht: dort
         * ist jeder Baustein eine eigene Adresse
         * (jdev/sps/io/<uuid>/all), und Temperatur und Feuchte sind zwei
         * Bausteine. Leer heisst weiterhin "dieselbe wie fuer die
         * Temperatur" - fuer jede Quelle, die alles in einer Antwort
         * liefert, aendert sich damit nichts. */
        'quelle_rf' => '',
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
        /* ---- Neu in 0.11.0, alle ab Werk AUS ----
         * Eine neue Funktion, die ab Werk anlaeuft, erreicht jede bestehende
         * Anlage beim naechsten Update - und schaltet dort ungefragt. */
        'pfad_zuluft' => '',     // fuenfter Pfad: Zuluft der Lueftungsanlage
        'wrg_eta'    => 0,       // Rueckwaermzahl der Anlage in %, 0 = aus
        'wasser_g'   => 0,       // Waeschemenge in Gramm Wasser, 0 = aus
        'ruhe_von'   => '',      // Ruhezeit "HH:MM", leer = keine
        'ruhe_bis'   => '',
        /* Wie viele Menschen halten sich ueblicherweise in diesem Raum auf?
         * 0 = keine Angabe. Ohne sie laesst sich der CO2-Verlauf nicht
         * rechnen, und eine geratene Zahl waere schlimmer als keine. */
        'personen'   => 0,
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
        /* ---- Neu in 0.11.0 ----
         * wind_max und zwang_std stehen ab Werk auf 0, sind also AUS: beide
         * schalten, und was schaltet, wird nicht ungefragt eingeschaltet.
         * Die uebrigen sind Grenzen fuer Auskuenfte, die es vorher gar nicht
         * gab - sie koennen nichts abschalten, was heute laeuft.
         *
         * Eine Ausnahme mit Ansage ist co2_t_min: sie NIMMT etwas weg (CO2
         * oeffnet unter -15 Grad nicht mehr). Gemessen am 28.08.2026 oeffnete
         * der CO2-Grund das Fenster bei -18 Grad, weil er an der
         * Kaeltepruefung vorbeilief. Wer das ausdruecklich will, setzt die
         * Grenze herunter; -30 schaltet sie ganz ab. */
        'wind_max'    => 0.0,       // km/h, darueber nicht lueften; 0 = aus
        'wand_abstand' => 1.0,      // K Sicherheitsabstand zum Wandtaupunkt
        'schwuel_x'   => 11.5,      // g/kg, ab hier gilt Luft als schwuel
        'co2_t_min'   => -15.0,     // Grad, darunter oeffnet CO2 nicht mehr
        'zwang_std'   => 0,         // Stunden ohne Empfehlung; 0 = aus
        'vl_zuschlag' => 1.0,       // K ueber dem Taupunkt fuer VLMIN
        'kuehlfrei_ein' => 3.0,     // K Taupunktabstand, ab dem freigegeben wird
        'kuehlfrei_aus' => 2.0,     // K, darunter wird wieder gesperrt
        'heizgrenze'  => 15.0,      // Grad gleitendes Aussenmittel
        'trend_min'   => 60,        // Minuten Fenster fuer den Feuchtetrend
        /* CO2-Ausstoss je Person und Stunde. Schlafend rund 13 l/h, sitzend
         * rund 17, bei leichter Arbeit rund 25 - eine EINSTELLUNG, keine
         * Messung. Und die Aussenkonzentration, gegen die gerechnet wird;
         * 420 ppm ist der heutige Wert der freien Atmosphaere. */
        'co2_ltr'     => 17.0,
        'co2_aussen'  => 420.0,
        /* Welchen Miniserver der Einrichtungs-Assistent fragt. Leer = den
         * ersten, den LoxBerry kennt. Adresse und Zugangsdaten stehen in
         * dessen general.json; niemand traegt sie ein zweites Mal ein. */
        'ms_nr'       => '',
        // MQTT und Endpunkt
        'mqtt_ein'    => 1,
        'mqtt_topic'  => 'raumklima',
        'aktionstoken' => '',
    );
}

/**
 * Was ist ein zulaessiger Wert? An EINER Stelle - fuer das Formular UND
 * fuer die Sicherungsdatei.
 *
 * Bis 0.10.1 gab es darueber zwei Wahrheiten. Das Formular prueft streng
 * (Zahlenbereiche, Adressen mit http, ein MQTT-Thema ohne Filterzeichen),
 * die Sicherungsdatei uebernahm jeden Wert ungeprueft:
 *
 *     rk_sicherung_lesen():  $neu[$k] = $w;
 *
 * Gemessen am 28.08.2026, beide PHP-Fassungen:
 *
 *   "mqtt_topic": "raumklima/#"   Formular: FEHLER.THEMA   Sicherung: uebernommen
 *   "quelle": "file:///etc/passwd" Formular: FEHLER.ADRESSE Sicherung: uebernommen
 *   "raeume": 5                    -                        Sicherung: uebernommen
 *   "aktionstoken": {"x":1}        -                        Sicherung: uebernommen
 *
 * Und die WIRKUNG, mit einem eigenen Horcher am UDP-Eingang des Gateways
 * gemessen: aus dem Thema mit Filterzeichen wurde woertlich
 * `publish raumklima/#/ok 1`. Ein Thema mit # in der Mitte trifft kein Abo -
 * der ganze MQTT-Weg fiel STILL aus, und rk_mqtt_senden() meldete true.
 * Mit einem Zeilenumbruch im Thema wird es schlimmer: das Gateway liest
 * zeilenweise, und aus einem Satz werden zwei. rk_mqtt_wert_saeubern()
 * saeubert den WERT, nicht das THEMA.
 *
 * Der Aktionstoken als Feld ergab `trim((string) $feld)` = 'Array', und
 * `hash_equals('Array','Array')` ist wahr: der Endpunkt stand danach mit
 * einem geratenen Wort offen. Ueber einen echten Webserver gemessen,
 * HTTP 200 statt 403.
 *
 * Rueckgabe: array($wert, '') bei Erfolg, array(null, 'Sprachschluessel')
 * mit den Angaben fuer sprintf sonst.
 */
function rk_wert_pruefen($schluessel, $wert)
{
    /* zahl: von, bis, ganz | wahl: Liste | adr | thema | text | flag | zeit */
    static $regeln = null;
    if ($regeln === null) {
        $regeln = array(
            'takt'          => array('zahl', 300, 3600, true),
            'mindest'       => array('zahl', 0.0, 10.0, false),
            't_min'         => array('zahl', -30.0, 30.0, false),
            'af_unter'      => array('zahl', 0.0, 20.0, false),
            'vorschau'      => array('zahl', 1, 48, true),
            'steht_min'     => array('zahl', 0, 1440, true),
            'hyst'          => array('zahl', 0.0, 1.0, false),
            'dauer_min'     => array('zahl', 0, 120, true),
            'regen_max'     => array('zahl', 0.0, 20.0, false),
            'kuehl_spanne'  => array('zahl', 0.1, 10.0, false),
            'wind_max'      => array('zahl', 0.0, 200.0, false),
            'wand_abstand'  => array('zahl', 0.0, 10.0, false),
            'schwuel_x'     => array('zahl', 1.0, 30.0, false),
            'co2_t_min'     => array('zahl', -30.0, 30.0, false),
            'zwang_std'     => array('zahl', 0, 48, true),
            'vl_zuschlag'   => array('zahl', 0.0, 10.0, false),
            'kuehlfrei_ein' => array('zahl', 0.0, 20.0, false),
            'kuehlfrei_aus' => array('zahl', 0.0, 20.0, false),
            'heizgrenze'    => array('zahl', 0.0, 30.0, false),
            'trend_min'     => array('zahl', 10, 720, true),
            'co2_ltr'       => array('zahl', 1.0, 60.0, false),
            'co2_aussen'    => array('zahl', 300.0, 800.0, false),
            'breite'        => array('zahl', -90.0, 90.0, false),
            'laenge'        => array('zahl', -180.0, 180.0, false),
            'aussen_art'    => array('wahl', array('meteo', 'eigen')),
            'aussen_einheit_t'  => array('wahl', array('C', 'F')),
            'aussen_einheit_rf' => array('wahl', array('proz', 'anteil')),
            'quelle'        => array('adr'),
            'aussen_quelle' => array('adr'),
            'aussen_t'      => array('text'),
            'aussen_rf'     => array('text'),
            'mqtt_topic'    => array('thema'),
            'mqtt_ein'      => array('flag'),
            'verlauf_ein'   => array('flag'),
            'aktionstoken'  => array('token'),
            'raeume'        => array('raeume'),
            'zugang'        => array('zugang'),
            'ms_nr'         => array('text'),
        );
    }
    if (!isset($regeln[$schluessel])) {
        return array(null, 'EINST.SICH_FREMD');
    }
    $r = $regeln[$schluessel];
    switch ($r[0]) {
        case 'zahl':
            if (is_bool($wert) || is_array($wert) || $wert === null) {
                return array(null, 'FEHLER.KEINE_ZAHL');
            }
            $s = str_replace(',', '.', trim((string) $wert));
            if ($s === '' || !is_numeric($s)) { return array(null, 'FEHLER.KEINE_ZAHL'); }
            $w = $r[3] ? (int) round((float) $s) : (float) $s;
            if ($w < $r[1] || $w > $r[2]) { return array(null, 'FEHLER.AUSSERHALB'); }
            return array($w, '');
        case 'wahl':
            if (!is_string($wert) || !in_array($wert, $r[1], true)) {
                return array(null, 'FEHLER.UNBEKANNT');
            }
            return array($wert, '');
        case 'adr':
            if (!is_string($wert)) { return array(null, 'FEHLER.ADRESSE'); }
            $s = rk_text_saeubern($wert);
            if ($s === '') { return array('', ''); }
            if (!preg_match('#^https?://#i', $s)) { return array(null, 'FEHLER.ADRESSE'); }
            return array($s, '');
        case 'thema':
            if (!is_string($wert)) { return array(null, 'FEHLER.THEMA'); }
            $s = trim(strtolower(rk_text_saeubern($wert)), '/');
            if ($s === '') { return array('raumklima', ''); }
            /* + und # sind Filterzeichen und als Ziel unbrauchbar. */
            if (!preg_match('#^[a-z0-9_\-/]+$#', $s)) { return array(null, 'FEHLER.THEMA'); }
            return array($s, '');
        case 'text':
            if (!is_string($wert)) { return array(null, 'FEHLER.UNBEKANNT'); }
            return array(rk_text_saeubern($wert), '');
        case 'flag':
            if (is_array($wert)) { return array(null, 'FEHLER.UNBEKANNT'); }
            return array(empty($wert) ? 0 : 1, '');
        case 'token':
            if (!is_string($wert)) { return array(null, 'FEHLER.TOKEN'); }
            $s = trim($wert);
            if ($s === '') { return array('', ''); }
            if (!preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $s)) {
                return array(null, 'FEHLER.TOKEN');
            }
            return array($s, '');
        case 'zugang':
            /* Genau zwei Schluessel, beide Zeichenketten. Ein Feld mit
             * einem dritten Schluessel kommt aus einer anderen Fassung oder
             * einem anderen Plugin und wird beanstandet, nicht beschnitten. */
            if (!is_array($wert)) { return array(null, 'FEHLER.ZUGANG'); }
            $z = array('benutzer' => '', 'passwort' => '');
            foreach ($wert as $k2 => $v2) {
                if (!array_key_exists($k2, $z) || !is_string($v2)) {
                    return array(null, 'FEHLER.ZUGANG');
                }
                if (strlen($v2) > 256) { return array(null, 'FEHLER.ZUGANG'); }
                $z[$k2] = $v2;
            }
            return array($z, '');
        case 'raeume':
            if (!is_array($wert)) { return array(null, 'FEHLER.RAEUME'); }
            $vorg = rk_raum_vorgabe();
            $out = array();
            /* Mehr Raeume als Plaetze sind eine BEANSTANDUNG, kein stiller
             * Verlust. Bis 0.11.1 stand hier ein blosses `break`: eine
             * Sicherung mit 99 Raeumen wurde "uebernommen", uebrig blieben
             * zwoelf, und niemand erfuhr es. Das widerspricht der eigenen
             * Vorgabe, die zwei Zeilen weiter unten fuer unbekannte
             * Schluessel gilt. */
            if (count($wert) > RK_RAEUME) { return array(null, 'FEHLER.RAEUME_ZUVIEL'); }
            /* Die Schluessel bleiben, was sie sind. array_values() hat sie
             * bis 0.11.2 verdichtet: wer den Block eines Raums von Hand aus
             * der Sicherung entfernte, schob alle folgenden um eins nach
             * vorn - und der Eingang mit \i;R3TAU= zeigte danach auf einen
             * anderen Raum, ohne dass ein Wert fehlte. Das ist derselbe
             * Befund, den 0.10.0 fuer die Oberflaeche behoben hat, nur
             * ueber den Rueckspielweg. Ein Platz ausserhalb der Tabelle
             * wird beanstandet, nicht verschoben. */
            foreach ($wert as $i => $r2) {
                if (!is_int($i) && !ctype_digit((string) $i)) {
                    return array(null, 'FEHLER.RAEUME');
                }
                $i = (int) $i;
                if ($i < 0 || $i >= RK_RAEUME) { return array(null, 'FEHLER.RAEUME'); }
                if (!is_array($r2)) { return array(null, 'FEHLER.RAEUME'); }
                foreach ($r2 as $k2 => $v2) {
                    if (!array_key_exists($k2, $vorg)) { return array(null, 'FEHLER.RAEUME'); }
                    if (is_array($v2) || is_object($v2)) { return array(null, 'FEHLER.RAEUME'); }
                }
                $out[$i] = $r2;
            }
            return array($out, '');
    }
    return array(null, 'EINST.SICH_FREMD');
}

/**
 * Steuerzeichen und Anfuehrungszeichen heraus - mehr nicht.
 *
 * Ein hartes preg_replace auf eine Positivliste zerstoert eingefuegte Werte
 * (belegt am ACTi-Plugin am 26.07.2026). Die Funktion stand bis 0.10.1 als
 * anonyme Funktion im Speichern-Handler und war damit fuer die Sicherung
 * nicht erreichbar.
 */
function rk_text_saeubern($s)
{
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $s));
}

/**
 * Rueckgabe: array(Feld, Lage) mit Lage aus 'fehlt', 'ok', 'kaputt'.
 *
 * Bis 0.10.1 gab diese Funktion bei ungueltigem JSON stillschweigend ein
 * leeres Feld zurueck - genau wie bei einer fehlenden Datei. rk_config()
 * konnte die beiden Faelle deshalb nicht unterscheiden, und was daraus
 * folgte, steht dort.
 */
function rk_json_lage($pfad)
{
    if (!is_file($pfad)) { return array(array(), 'fehlt'); }
    $roh = trim((string) @file_get_contents($pfad));
    if ($roh === '') { return array(array(), 'fehlt'); }
    $d = json_decode($roh, true);
    if (is_array($d)) { return array($d, 'ok'); }
    return array(array(), 'kaputt');
}

function rk_json_lesen($pfad)
{
    list($d, $lage) = rk_json_lage($pfad);
    return $lage === 'ok' ? $d : array();
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
    /* Die Rechte gehoeren an das ANLEGEN. Zwischen fopen() und chmod()
     * laege die Nebendatei sonst mit 0666 & ~umask da - bei geheim.json
     * waeren das die Zugangsdaten des Miniservers, fuer jeden lesbar.
     * Deshalb die umask fuer diesen einen Aufruf enger stellen. */
    $umask_alt = ($rechte !== null) ? umask(~$rechte & 0777) : null;
    $fh = @fopen($tmp, 'c');
    if ($umask_alt !== null) { umask($umask_alt); }
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    /* `!== false` genuegt nicht: bei voller Platte schreibt fwrite() nur
     * einen Teil und liefert dessen Laenge zurueck. Das galt als Erfolg,
     * und die abgeschnittene Nebendatei wurde ueber die gute Datei
     * umbenannt. Auch fflush() und fclose() koennen ENOSPC nachreichen. */
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) === strlen($json);
    if (!fflush($fh)) { $ok = false; }
    if (!fclose($fh)) { $ok = false; }
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Wie steht die Konfiguration da? 'ok', 'leer', 'zweitschrift', 'kaputt'.
 *
 * Der Reiter Test zeigt das an. Jeder Zustand, den der Code erzeugen kann,
 * braucht seinen Satz - sonst rate der Anwender, was los ist.
 */
function rk_config_lage($setzen = null)
{
    static $lage = 'ok';
    if ($setzen !== null) { $lage = (string) $setzen; }
    return $lage;
}

/**
 * Die Konfiguration lesen.
 *
 * $heilen = false schaltet JEDEN Schreibvorgang ab. Der unangemeldete
 * Endpunkt liest damit nur.
 *
 * ------------------------------------------------------------------
 * Zwei Befunde vom 28.08.2026, beide hier behoben
 * ------------------------------------------------------------------
 *
 * 1. DIE SELBSTHEILUNG LIEF VOR DER TOKENPRUEFUNG. html/index.php ruft
 *    rk_token_lesen(), das ruft rk_config(), und die legte ein Verzeichnis
 *    an und kopierte die Zweitschrift. Ueber einen echten Webserver
 *    gemessen, beide PHP-Fassungen:
 *
 *        falsches Wortzeichen, Konfigordner leer, Zweitschrift daneben
 *        HTTP 403  'FEHLER;GRUND=TOKEN'
 *        Dateien vorher/nachher: 2 / 3   -> GESCHRIEBEN trotz 403
 *
 *    Der Kommentar ueber rk_token_lesen() begruendete ausfuehrlich, warum
 *    dort nicht rk_token() steht - und die Ebene darunter schrieb doch.
 *
 * 2. EINE BESCHAEDIGTE KONFIGURATION RISS DIE ZWEITSCHRIFT MIT. rk_json_lesen()
 *    gab bei ungueltigem JSON ein leeres Feld zurueck, rk_config() kannte
 *    keinen Zustand 'kaputt', und der Reiter Loxone ruft beim Rendern
 *    rk_token(). Das leere Wortzeichen loeste rk_config_speichern() aus, und
 *    dessen copy() legte den aus der Vorgabe entstandenen Truemmerstand ueber
 *    die HEILE Zweitschrift. Gemessen:
 *
 *        Ausgangslage: raumklima.json halbiert, Zweitschrift heil
 *        Handlung    : EIN Aufruf von rk_token()
 *        Danach      : Zweitschrift enthaelt 'Wohnzimmer' NEIN
 *                      altes Wortzeichen                  NEIN
 *                      .kaputt-Datei beiseitegelegt       NEIN
 *                      Protokolleintrag                   keiner
 *
 *    Nach EINEM Seitenaufruf ohne Knopfdruck waren Raeume, Quellen und
 *    Aktionstoken weg, ohne Rueckweg und ohne ein Wort. Jetzt wird die
 *    kaputte Datei beiseitegelegt, die Zweitschrift NICHT angefasst und der
 *    Zustand gemeldet.
 */
function rk_config($heilen = true)
{
    $p = rk_paths();
    list($daten, $lage) = rk_json_lage($p['config']);

    if ($lage === 'kaputt') {
        rk_config_lage('kaputt');
        if ($heilen) {
            /* Beiseitelegen, nicht ueberschreiben - und die Zweitschrift
             * bleibt unberuehrt, sie ist der einzige Rueckweg. */
            /* ------------------------------------------------------------
             * EINE Kopie je Tag, nicht je Sekunde.
             *
             * Der Name trug bis 0.11.1 `date('Ymd_His')` und wechselte damit
             * jede Sekunde; das `is_file()` davor konnte nie greifen.
             * Gebremst war nur der Protokolleintrag (900 s), das `copy()`
             * nicht. Gemessen am 30.08.2026: drei Aufrufe in drei Sekunden
             * ergaben drei Kopien. Solange raumklima.json unlesbar ist, legt
             * jeder Cron-Lauf eine weitere an - 288 Dateien je Tag, jede mit
             * Raumnamen und Heimnetzadressen, unbegrenzt.
             *
             * Der Tag im Namen reicht: was beiseitegelegt werden soll, ist
             * der kaputte Stand, und der aendert sich nicht dadurch, dass
             * man ihn oefter kopiert. Wird die Datei am selben Tag erneut
             * kaputt geschrieben, bleibt die erste Kopie - sie ist die
             * aeltere und damit die naeher am letzten guten Stand.
             * ------------------------------------------------------------ */
            $ziel = $p['config'] . '.kaputt.' . date('Ymd');
            if (!is_file($ziel)) { @copy($p['config'], $ziel); @chmod($ziel, 0600); }
            rk_log_gebremst('cfg_kaputt', 'Die Konfiguration ist unlesbar. Sie liegt als '
                . basename($ziel) . ' daneben; die Zweitschrift wurde NICHT angefasst.'
                . ' Bis zur Berichtigung gelten die Vorgabewerte.', 900);
        }
        /* Ohne Daten weiter - aber NICHTS zurueckschreiben. */
        $daten = array();
    } elseif ($lage === 'fehlt' || $daten === array()) {
        list($zdaten, $zlage) = rk_json_lage($p['sicherung']);
        if ($zlage === 'ok' && $zdaten !== array()) {
            $daten = $zdaten;
            rk_config_lage('zweitschrift');
            if ($heilen) {
                /* is_dir() davor: '@' unterdrueckt die Meldung nur, solange
                 * KEIN eigener Fehlerbehandler gesetzt ist. Mit einem
                 * gesetzten Behandler schlaegt mkdir() auf ein vorhandenes
                 * Verzeichnis durch - gemessen am 24.08.2026. */
                if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
                @copy($p['sicherung'], $p['config']);
            }
        } else {
            rk_config_lage('leer');
        }
    } else {
        rk_config_lage('ok');
    }

    $cfg = array_merge(rk_vorgaben(), $daten);

    if (!is_array($cfg['raeume'])) { $cfg['raeume'] = array(); }
    for ($i = 0; $i < RK_RAEUME; $i++) {
        $r = isset($cfg['raeume'][$i]) && is_array($cfg['raeume'][$i]) ? $cfg['raeume'][$i] : array();
        /* ------------------------------------------------------------
         * Unbekannte Raumschluessel werden ABGELEGT, nicht mitgeschleppt.
         *
         * Bis 0.11.1 stand hier nur `$r += rk_raum_vorgabe();`. Das ergaenzt
         * fehlende Schluessel und laesst fremde stehen - und genau daran
         * zerbrach der eigene Rueckspielweg. rk_wert_pruefen('raeume') weist
         * die GANZE Datei ab, sobald ein Raum einen Schluessel traegt, der
         * nicht in rk_raum_vorgabe() steht. Gemessen am 30.08.2026 mit einem
         * Rest aus einer aelteren Fassung:
         *
         *     rk_config haelt alt_feld:             JA
         *     eigene Sicherung wieder einlesbar:    NEIN
         *
         * Die vom Plugin zwei Zeilen vorher erzeugte Sicherung wurde von der
         * eigenen Bibliothek abgelehnt - der Befund, den der Kopfkommentar
         * zu rk_sicherung_bauen() unter Berufung auf den WiFi-Scanner
         * ausdruecklich vermeiden will. Der Speichern-Handler baut den Raum
         * aus dem gespeicherten Stand auf und schleppte den Schluessel mit;
         * es heilte also auch nicht von selbst.
         * ------------------------------------------------------------ */
        $vorg_r = rk_raum_vorgabe();
        $fremd = array_diff_key($r, $vorg_r);
        /* NUR mit $heilen. Ohne die Klammer schrieb ein unangemeldeter
         * Aufruf mit falschem Wortzeichen zwei Dateien, bevor der 403
         * hinausging - gemessen am 05.09.2026, 8 auf 9 Dateien. Das
         * Ablegen der Fremdschluessel darunter wirkt nur im Speicher. */
        if ($fremd && $heilen) {
            rk_log_gebremst('raum_fremdschluessel',
                'Raum ' . ($i + 1) . ': unbekannte Felder abgelegt ('
                . implode(', ', array_slice(array_keys($fremd), 0, 6))
                . '). Sie stammen aus einer anderen Fassung und haetten die '
                . 'eigene Sicherung unlesbar gemacht.', 86400);
            $r = array_intersect_key($r, $vorg_r);
        }
        $r += $vorg_r;
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
        $r['wrg_eta'] = max(0.0, min(100.0, (float) $r['wrg_eta']));
        $r['wasser_g'] = max(0.0, min(20000.0, (float) $r['wasser_g']));
        $r['personen'] = max(0.0, min(20.0, (float) $r['personen']));
        foreach (array('ruhe_von', 'ruhe_bis') as $rz) {
            $r[$rz] = trim((string) $r[$rz]);
            if ($r[$rz] !== '' && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $r[$rz])) {
                $r[$rz] = '';
            }
        }
        foreach (array('pfad_t', 'pfad_rf', 'pfad_co2', 'pfad_fenster',
                       'pfad_zuluft', 'quelle', 'quelle_rf') as $pf) {
            if (!is_string($r[$pf])) { $r[$pf] = ''; }
        }
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
    /* ---- Neu in 0.11.0 ---- */
    $cfg['wind_max'] = max(0.0, min(200.0, (float) $cfg['wind_max']));
    $cfg['wand_abstand'] = max(0.0, min(10.0, (float) $cfg['wand_abstand']));
    $cfg['schwuel_x'] = max(1.0, min(30.0, (float) $cfg['schwuel_x']));
    $cfg['co2_t_min'] = max(-30.0, min(30.0, (float) $cfg['co2_t_min']));
    $cfg['zwang_std'] = max(0, min(48, (int) $cfg['zwang_std']));
    $cfg['vl_zuschlag'] = max(0.0, min(10.0, (float) $cfg['vl_zuschlag']));
    $cfg['kuehlfrei_ein'] = max(0.0, min(20.0, (float) $cfg['kuehlfrei_ein']));
    $cfg['kuehlfrei_aus'] = max(0.0, min(20.0, (float) $cfg['kuehlfrei_aus']));
    /* Die Ausschaltschwelle liegt unter der Einschaltschwelle - sonst ist es
     * keine Hysterese, sondern ein Flattern mit zwei Namen. */
    if ($cfg['kuehlfrei_aus'] > $cfg['kuehlfrei_ein']) {
        $cfg['kuehlfrei_aus'] = $cfg['kuehlfrei_ein'];
    }
    $cfg['heizgrenze'] = max(0.0, min(30.0, (float) $cfg['heizgrenze']));
    $cfg['trend_min'] = max(10, min(720, (int) $cfg['trend_min']));
    $cfg['co2_ltr'] = max(1.0, min(60.0, (float) $cfg['co2_ltr']));
    $cfg['co2_aussen'] = max(300.0, min(800.0, (float) $cfg['co2_aussen']));

    /* ---- Was bis 0.10.1 GAR NICHT geprueft wurde ----
     * Das MQTT-Thema und die drei Adressen kamen aus der Datei, wie sie
     * dastanden. Ueber die Sicherungsdatei ging damit ein Thema mit
     * Filterzeichen durch, und `publish raumklima/#/ok 1` traf kein Abo -
     * der MQTT-Weg fiel still aus. Hier steht die letzte Wache; die erste
     * steht in rk_wert_pruefen(), die Formular und Sicherung gemeinsam
     * benutzen. */
    list($thema, $m) = rk_wert_pruefen('mqtt_topic', $cfg['mqtt_topic']);
    $cfg['mqtt_topic'] = ($m === '') ? $thema : 'raumklima';
    foreach (array('quelle', 'aussen_quelle') as $adr) {
        list($w, $m) = rk_wert_pruefen($adr, $cfg[$adr]);
        $cfg[$adr] = ($m === '') ? $w : '';
    }
    foreach (array('aussen_t', 'aussen_rf') as $tx) {
        if (!is_string($cfg[$tx])) { $cfg[$tx] = ''; }
    }
    /* Ein Aktionstoken, der kein Wort ist, ergibt beim Vergleich 'Array' -
     * und damit ein Wortzeichen, das jeder kennt. Gemessen: HTTP 200. */
    list($tok, $m) = rk_wert_pruefen('aktionstoken', $cfg['aktionstoken']);
    $cfg['aktionstoken'] = ($m === '') ? $tok : '';
    $cfg['mqtt_ein'] = !empty($cfg['mqtt_ein']) ? 1 : 0;
    return $cfg;
}

/**
 * Speichern - und die Zweitschrift nur dann nachziehen, wenn der Stand
 * nicht aus einer kaputten Datei entstanden ist.
 *
 * Die Zweitschrift ist der einzige Rueckweg. Sie mit einem Stand zu
 * ueberschreiben, der in Wahrheit die Vorgabeliste ist, nimmt genau diesen
 * Rueckweg weg - gemessen am 28.08.2026.
 */
function rk_config_speichern($cfg)
{
    $p = rk_paths();
    if (!rk_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    if (rk_config_lage() !== 'kaputt') {
        @copy($p['config'], $p['sicherung']);
        @chmod($p['sicherung'], 0600);
        rk_config_lage('ok');
    }
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
    /* rk_config(false) - KEIN Schreibvorgang. Bis 0.10.1 stand hier
     * rk_config(), und die legte bei fehlender Datei ein Verzeichnis an und
     * kopierte die Zweitschrift: ein Aufruf mit falschem Wortzeichen, korrekt
     * mit 403 beantwortet, hinterliess eine neue Datei. Gemessen ueber einen
     * echten Webserver, beide PHP-Fassungen. */
    $cfg = rk_config(false);
    $t = $cfg['aktionstoken'];
    return is_string($t) ? trim($t) : '';
}

/**
 * Liest das Wortzeichen und legt eines an, falls noch keines da ist.
 *
 * Bei unlesbarer Konfiguration wird NICHTS angelegt und nichts geschrieben.
 * Genau dieser Weg hat am 28.08.2026 die heile Zweitschrift ueberschrieben:
 * der Reiter Loxone ruft diese Funktion beim Rendern, das leere Wortzeichen
 * loeste das Speichern aus, und gespeichert wurde der Vorgabestand.
 */
function rk_token()
{
    $cfg = rk_config();
    if (rk_config_lage() === 'kaputt') {
        return is_string($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    }
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = rk_token_erzeugen();
        rk_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ==================================================================
 * Das Formularmerkmal gegen fremde Absender
 *
 * Die Oberflaeche liegt hinter der LoxBerry-Anmeldung - und genau deshalb
 * traegt der Browser des Bedieners sie bei JEDEM Formular mit, auch bei
 * einem, das auf einer fremden Seite steht. Die Anmeldung schuetzt also
 * nicht davor, dass jemand anders das Formular abschickt.
 *
 * Bis 0.10.1 gab es dieses Merkmal nicht. wachposten_pruefen.py hat die
 * Linie deshalb STILL uebersprungen und eine leere Tabelle ausgegeben - kein
 * "in Ordnung", sondern gar keine Messung. Ueber den Bestand gezaehlt
 * erschienen 28 von 53 Ordnern, alle 28 mit Wachposten; Raumklima war unter
 * den 25 uebersprungenen. Der Kommentar in htmlauth/index.php behauptete den
 * Schutz derweil woertlich.
 *
 * Was ein fremdes Formular anrichten konnte, an einem laufenden Server
 * gemessen: ein POST auf 'token_neu' wuerfelt das Wortzeichen neu, und der
 * Miniserver bekommt danach ausnahmslos 403 - ein virtueller Ausgang wertet
 * die Antwort nicht aus, der Ausfall bleibt still. Ein POST auf 'speichern'
 * mit 'feld_einst' leert alle zwoelf Raeume; in Loxone sieht danach alles
 * normal aus, weil virtuelle Eingaenge ihren letzten Wert behalten.
 *
 * Das Merkmal ist NICHT der Aktionstoken. Es lebt in einer eigenen Datei
 * unter data/, gehoert nicht in die Sicherungsdatei und darf nie in einer
 * Adresse stehen.
 *
 * ES IST GESPEICHERT, NICHT ABGELEITET - und das hat eine Folge fuer
 * jeden, der diese Linie prueft.
 *
 * Der Hausstandard kennt beide Bauarten. Die VORLAGE leitet das Merkmal
 * aus dem Aktionstoken ab (hash_hmac('sha256', 'formular-v1', $token));
 * ein Pruefstand kann es dort in drei Zeilen nachrechnen und damit einen
 * POST bauen, der den Wachposten passiert. Hier geht das NICHT: das
 * Merkmal wird einmal gewuerfelt und in data/ abgelegt, es laesst sich
 * aus nichts herleiten. Wer einen Zweig hinter dem Wachposten messen
 * will, muss es deshalb LESEN - aus der gerenderten Seite (jedes
 * Formular traegt es als verstecktes Feld) oder aus der Datei -, und
 * nicht ausrechnen. Ein selbst zusammengesetzter POST wird abgewiesen,
 * bevor der Handler anlaeuft; die Messung misst dann den Wachposten und
 * nicht den Fehler, den sie sucht.
 *
 * wirkungstest.py kommt damit zurecht - es liest die versteckten Felder
 * aus dem gerenderten Formular. Selbst gebaute Pruefstuecke tun das
 * nicht von allein.
 *
 * Gewuerfelt statt abgeleitet ist hier Absicht: waere es abgeleitet,
 * haette jeder, der das Wortzeichen einmal gesehen hat - es steht in
 * der Adresse jedes virtuellen Eingangs -, damit auch das
 * Formularmerkmal.
 * ================================================================== */

function rk_formtoken()
{
    $d = rk_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/formtoken';
    $t = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (strlen($t) < 16) {
        $t = rk_token_erzeugen(32);
        @file_put_contents($f, $t);
        @chmod($f, 0600);
    }
    return $t;
}

/** Traegt die Anfrage das Merkmal? Nur fuer POST-Handler. */
function rk_formtoken_ok()
{
    /* Aus $_POST, nie aus $_REQUEST - sonst traegt auch ein Wert aus der
     * Adresszeile, und der steht in jedem Verlauf. */
    $ist = (isset($_POST['formtoken']) && is_string($_POST['formtoken']))
        ? $_POST['formtoken'] : '';
    $soll = rk_formtoken();
    /* Der leere Fall MUSS vor hash_equals abgefangen werden:
     * hash_equals('', '') ist true. */
    return $soll !== '' && $ist !== '' && hash_equals($soll, $ist);
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
    clearstatcache(true, $p['log']);
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
/**
 * Der Wirt einer Adresse, klein geschrieben und ohne Port. Leer, wenn sich
 * keiner lesen laesst - und ein leerer Wirt darf nie zu jemandem passen.
 */
function rk_wirt($url)
{
    $h = parse_url(trim((string) $url), PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : '';
}

/**
 * Darf diese Adresse die Zugangsdaten aus geheim.json bekommen?
 *
 * ------------------------------------------------------------------------
 * Der Befund vom 30.08.2026. `geheim.json` traegt EINEN Satz Zugangsdaten,
 * und `rk_holen($url, true)` haengte ihn an JEDE Adresse - gleich wem sie
 * gehoert. Seit 0.11.1 legt der Einrichtungs-Assistent dort die
 * ADMINISTRATORDATEN DES MINISERVERS ab; vorher stand dort meist ein
 * Shelly-Zugang.
 *
 * Gemessen mit einem Horcher an einer frei eingetragenen Aussenquelle:
 *
 *     [Authorization] => Basic bG94YWRtaW46U2VockdlaGVpbTEyMw==
 *     entschluesselt:    loxadmin:SehrGeheim123
 *
 * 288 Cron-Laeufe am Tag, bei http:// im Klartext. Der Kommentar in
 * rk_holen() begruendet ausfuehrlich, warum keiner WEITERLEITUNG gefolgt
 * wird - dass die Daten an eine unmittelbar eingetragene Fremdadresse
 * gehen, war nicht bedacht.
 *
 * Erlaubt ist ein Wirt, der in dieser Anlage ohnehin die Zugangsdaten
 * kennt: einer der Raumquellen, oder der Miniserver aus der general.json.
 * Wer seine Aussenwerte vom selben Geraet holt, merkt nichts; wer eine
 * fremde Adresse eintraegt, verschickt nichts mehr.
 * ------------------------------------------------------------------------
 */
function rk_zugang_erlaubt($url, $cfg = null)
{
    $wirt = rk_wirt($url);
    if ($wirt === '') { return false; }
    if (!is_array($cfg)) { $cfg = rk_config(); }

    $bekannt = array();
    foreach ((array) (isset($cfg['raeume']) ? $cfg['raeume'] : array()) as $r) {
        if (!is_array($r)) { continue; }
        foreach (array('quelle', 'quelle_rf') as $k) {
            $w = isset($r[$k]) ? rk_wirt($r[$k]) : '';
            if ($w !== '') { $bekannt[$w] = 1; }
        }
    }
    $w = isset($cfg['quelle']) ? rk_wirt($cfg['quelle']) : '';
    if ($w !== '') { $bekannt[$w] = 1; }
    $ms = rk_miniserver_gewaehlt($cfg);
    if (is_array($ms) && $ms['adresse'] !== '') {
        $bekannt[strtolower($ms['adresse'])] = 1;
    }

    if (isset($bekannt[$wirt])) { return true; }
    $g = rk_geheim();
    if ($g['benutzer'] !== '') {
        rk_log_gebremst('zugang_fremd_' . $wirt,
            'Zugangsdaten NICHT mitgeschickt: ' . $wirt . ' traegt keinen '
            . 'Fuehler dieser Anlage. Sie gehen nur an die Wirte der '
            . 'Raumquellen und an den Miniserver.', 3600);
    }
    return false;
}

function rk_holen($url, $mit_zugang = false)
{
    /* Die Frist gilt fuer den ganzen Lauf, nicht je Abruf. Ist sie
     * abgelaufen, wird nicht mehr gefragt - sonst haengt der Lauf laenger
     * als sein eigener Takt. Ohne gesetzte Frist (Oberflaeche, Reiter
     * Test) aendert sich nichts. */
    $rest = rk_frist_rest();
    if (rk_frist() > 0 && $rest <= 0) { return array(null, 'ZEIT_ABGELAUFEN'); }
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
        /* ------------------------------------------------------------------
         * Obergrenze fuer die Antwort. Bis 0.11.1 gab es nur eine Zeitschranke
         * von 12 Sekunden - und ein LAN-Geraet schafft darin sehr viel.
         * Gemessen am 30.08.2026 gegen eine Quelle, die 40 MB lieferte, bei
         * memory_limit=128M: Speicherspitze 84 MB. Ein Gateway, das statt
         * JSON sein Protokoll ausliefert, bringt damit den Cron-Lauf mit
         * einem Fatal error zu Fall - und ueber ?aktion=abrufen den
         * Webarbeiter gleich mit.
         *
         * CURLOPT_MAXFILESIZE allein reicht nicht: es greift nur, wenn der
         * Server eine Laenge ANKUENDIGT. Deshalb zusaetzlich eine eigene
         * Schreibfunktion, die abbricht, sobald das Empfangene zu gross wird.
         * ------------------------------------------------------------------ */
        $grenze = RK_ANTWORT_MAX;
        $text = '';
        $zuviel = false;
        /* Die Schranken je Abruf, gedeckelt durch die verbleibende Frist. */
        $zeit = 12;
        $verb = 6;
        if (rk_frist() > 0) {
            $zeit = max(2, min($zeit, (int) $rest));
            $verb = max(1, min($verb, $zeit));
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_TIMEOUT, $zeit);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $verb);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $grenze);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION,
            function ($ch2, $stueck) use (&$text, &$zuviel, $grenze) {
                $n = strlen($stueck);
                if (strlen($text) + $n > $grenze) {
                    $zuviel = true;
                    return 0;   /* 0 heisst: abbrechen */
                }
                $text .= $stueck;
                return $n;
            });
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($zuviel) {
            rk_log_gebremst('antwort_zu_gross_' . rk_wirt($url),
                'Antwort von ' . rk_wirt($url) . ' ueberschreitet '
                . $grenze . ' Byte - abgebrochen. Liefert die Quelle wirklich '
                . 'JSON?', 3600);
            return array(null, 'ANTWORT_ZU_GROSS');
        }
        if ($ok === false && $text === '') { return array(null, 'NICHT_ERREICHBAR'); }
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
            'timeout' => (rk_frist() > 0 ? max(2, min(12, (int) $rest)) : 12),
            'header' => implode("\r\n", $kopf), 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1)));
        /* Dieselbe Obergrenze wie im curl-Zweig - sonst haengt es davon ab,
         * ob php-curl geladen ist, ob eine Quelle den Lauf umbringen kann.
         * Ein Byte mehr als die Grenze wird geholt, damit sich "genau voll"
         * von "abgeschnitten" unterscheiden laesst. */
        $text = @file_get_contents($url, false, $ctx, 0, RK_ANTWORT_MAX + 1);
        if (is_string($text) && strlen($text) > RK_ANTWORT_MAX) {
            rk_log_gebremst('antwort_zu_gross_' . rk_wirt($url),
                'Antwort von ' . rk_wirt($url) . ' ueberschreitet '
                . RK_ANTWORT_MAX . ' Byte - abgebrochen. Liefert die Quelle '
                . 'wirklich JSON?', 3600);
            return array(null, 'ANTWORT_ZU_GROSS');
        }
        $code = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
            }
        }
        if ($text === false) { return array(null, 'NICHT_ERREICHBAR'); }
    }
    if ($code === 401 || $code === 403) { return array(null, 'ZUGANG_ABGELEHNT'); }
    /* Jeder andere Fehlercode ebenfalls. Bis 0.11.2 ging eine Antwort mit
     * 404, 500 oder 502 unbesehen durch json_decode(): der Pfad wurde nicht
     * gefunden, der Raum stand auf ok=0, und in meldungen stand NICHTS -
     * der Anwender sah einen stummen Raum ohne Grund und suchte am Pfad.
     * rk_struktur_holen() macht es seit jeher richtig. */
    if ($code > 0 && ($code < 200 || $code > 299)) {
        /* Die Nummer gehoert ins Protokoll, nicht in den Meldungscode:
         * der wird ueber rk_t('MELD.' . <code>) in einen Satz
         * uebersetzt, und ein je Fehlercode anderer Schluessel haette
         * in den Sprachdateien nie einen. */
        rk_log_gebremst('http_' . rk_wirt($url) . '_' . (int) $code,
            'Die Quelle ' . rk_wirt($url) . ' hat mit HTTP ' . (int) $code
            . ' geantwortet. Es wurde kein Wert uebernommen.', 3600);
        return array(null, 'HTTP_FEHLER');
    }
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
 * Der Miniserver als Quelle
 *
 * ------------------------------------------------------------------
 * Warum dieser Weg, und warum nicht die Fuehler selbst
 * ------------------------------------------------------------------
 *
 * Ein Shelly H&T Gen3 ist ein BATTERIEGERAET. Es schlaeft und meldet sich
 * nur bei Ereignissen; es publiziert dann ueber MQTT nach
 * `shellyhtg3-<Name>/events/rpc`, und das LoxBerry-Gateway fuellt daraus
 * virtuelle Eingaenge. Ein HTTP-Abruf auf seine Adresse laeuft in die
 * Zeitschranke, weil dort meistens niemand horcht. Das Modell "Adresse
 * eintragen und Pfad" kann solche Fuehler prinzipiell nicht erreichen.
 *
 * Im Miniserver stehen die Werte aber laengst. Am 29.08.2026 an einer
 * Anlage mit zwoelf solcher Fuehler abgelesen:
 *
 *     Baustein "01) Temperatur Kueche"        ->  "27.2 °C"
 *     Baustein "02) Luftfeuchtigkeit Kueche"  ->  "51 % - gut"
 *
 * Beide Formen liest rk_zahl_aus() ohne Aenderung; die Gegenprobe
 * ("keine Verbindung", "--") ergibt null. Der Weg ueber den Miniserver ist
 * damit der richtige - nicht als Notnagel, sondern weil dort die Werte
 * aller Fuehler zusammenlaufen, gleich wie sie hereinkommen.
 *
 * Die Adresse und die Zugangsdaten stehen in der general.json des
 * LoxBerry; niemand muss sie ein zweites Mal eintragen. Das Muster ist aus
 * dem Beschattungswaechter uebernommen, der denselben Weg geht.
 * ================================================================== */

/** Die Miniserver, die LoxBerry kennt. Leeres Feld, wenn keiner da ist. */
function rk_miniserver()
{
    $p = rk_paths();
    if ($p['home'] === '') { return array(); }
    $j = rk_json_lesen($p['home'] . '/config/system/general.json');
    if (empty($j['Miniserver']) || !is_array($j['Miniserver'])) { return array(); }
    $aus = array();
    foreach ($j['Miniserver'] as $nr => $ms) {
        if (!is_array($ms)) { continue; }
        $adresse = '';
        foreach (array('Ipaddress', 'IPAddress') as $k) {
            if (!empty($ms[$k])) { $adresse = (string) $ms[$k]; break; }
        }
        if ($adresse === '') { continue; }
        /* ------------------------------------------------------------
         * HTTPS wird GELESEN, nicht angenommen.
         *
         * Bis 0.11.1 las rk_miniserver() nur `Port` und rk_ms_url() baute
         * fest 'http://'. Die general.json von LoxBerry fuehrt aber
         * `Porthttps` und `Preferhttps`; steht das zweite auf 1, redet das
         * Plugin Klartext gegen einen Port, der TLS erwartet. Gemessen am
         * 30.08.2026 mit Preferhttps=1: MELD.MS_STUMM, obwohl der Server
         * antwortete - und der Anwender hat kein Feld, um das zu
         * berichtigen. Selbst wenn die Suche gelaenge, stuende in
         * quelle/quelle_rf eine http://-Adresse, die bei jedem Cron-Lauf
         * scheitert.
         * ------------------------------------------------------------ */
        $https = false;
        foreach (array('Preferhttps', 'PreferHttps', 'preferhttps') as $k) {
            if (isset($ms[$k])) {
                $https = in_array((string) $ms[$k], array('1', 'true'), true);
                break;
            }
        }
        $port_https = 443;
        foreach (array('Porthttps', 'PortHttps', 'porthttps') as $k) {
            if (!empty($ms[$k])) { $port_https = (int) $ms[$k]; break; }
        }
        $aus[] = array(
            'nr'      => (string) $nr,
            'name'    => !empty($ms['Name']) ? (string) $ms['Name'] : ('Miniserver ' . $nr),
            'adresse' => $adresse,
            'https'   => $https ? 1 : 0,
            'port'    => $https ? $port_https
                         : (!empty($ms['Port']) ? (int) $ms['Port'] : 80),
            'user'    => !empty($ms['Admin']) ? (string) $ms['Admin']
                         : (!empty($ms['Username']) ? (string) $ms['Username'] : ''),
            'pass'    => !empty($ms['Pass']) ? (string) $ms['Pass']
                         : (!empty($ms['Password']) ? (string) $ms['Password'] : ''),
        );
    }
    return $aus;
}

/** Den eingestellten Miniserver auswaehlen, sonst den ersten. */
function rk_miniserver_gewaehlt($cfg, ?array $alle = null)
{
    if ($alle === null) { $alle = rk_miniserver(); }
    if (!$alle) { return null; }
    $nr = isset($cfg['ms_nr']) ? trim((string) $cfg['ms_nr']) : '';
    if ($nr !== '') {
        foreach ($alle as $m) { if ($m['nr'] === $nr) { return $m; } }
    }
    /* reset(), nicht [0]: rk_miniserver() liefert zwar eine Liste, aber ein
     * Aufrufer darf sie gefiltert haben - dann gibt es keinen Schluessel 0. */
    return reset($alle);
}

/** Die Adresse eines Miniserver-Aufrufs. An EINER Stelle. */
function rk_ms_url($ms, $pfad)
{
    /* Das Schema kommt aus der general.json, nicht aus einer Annahme -
     * siehe rk_miniserver(). Aeltere Aufrufer, die kein 'https' im Feld
     * haben, bekommen wie bisher http. */
    $schema = !empty($ms['https']) ? 'https://' : 'http://';
    return $schema . $ms['adresse'] . ':' . (int) $ms['port'] . '/'
         . ltrim((string) $pfad, '/');
}

/**
 * Einen Aufruf an den Miniserver. Rueckgabe: array(code, roh, fehler).
 *
 * Die Zugangsdaten gehen in den KOPF, nicht in die Adresse: eine Adresse
 * landet im Protokoll jedes Webservers und in jedem Verlauf, ein Kopf
 * nicht. Derselbe Satz steht im Beschattungswaechter.
 */
function rk_ms_holen($ms, $pfad, $zeit = 12)
{
    $url = rk_ms_url($ms, $pfad);
    $kopf = array('Accept: application/json', 'User-Agent: LoxBerry-Raumklima');
    if ($ms['user'] !== '') {
        $kopf[] = 'Authorization: Basic '
                . base64_encode($ms['user'] . ':' . $ms['pass']);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $zeit);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(8, (int) $zeit));
        $roh = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($roh === false) { return array(0, '', $fehler !== '' ? $fehler : 'keine Antwort'); }
        return array($code, (string) $roh, '');
    }
    /* follow_location = 0 aus demselben Grund wie in rk_holen(): der Kopf
     * mit den Zugangsdaten darf keiner fremden Weiterleitung folgen. */
    $ctx = stream_context_create(array('http' => array(
        'timeout' => (int) $zeit, 'header' => implode("\r\n", $kopf),
        'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 1)));
    $roh = @file_get_contents($url, false, $ctx);
    if ($roh === false) { return array(0, '', 'keine Antwort'); }
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
        }
    }
    return array($code, (string) $roh, '');
}

/**
 * Die Strukturdatei holen und in Raeume und Bausteine zerlegen.
 *
 * Rueckgabe: array(ok, Meldungsschluessel, Raeume, Bausteine)
 *   Raeume    uuid => Name
 *   Bausteine Liste aus array(uuid, name, raum, kategorie, typ)
 */
function rk_struktur_holen($ms, $zeit = 30)
{
    if (!is_array($ms)) { return array(0, 'MELD.MS_KEINER', array(), array()); }
    list($code, $roh, $fehler) = rk_ms_holen($ms, 'data/LoxAPP3.json', $zeit);
    if ($code === 0)   { return array(0, 'MELD.MS_STUMM', array(), array()); }
    if ($code === 401) { return array(0, 'MELD.MS_401', array(), array()); }
    if ($code !== 200) { return array(0, 'MELD.MS_HTTP', array(), array()); }
    $j = json_decode($roh, true);
    if (!is_array($j) || empty($j['controls'])) {
        return array(0, 'MELD.MS_KEIN_JSON', array(), array());
    }
    $raeume = array();
    foreach ((array) (isset($j['rooms']) ? $j['rooms'] : array()) as $u => $r) {
        if (is_array($r) && isset($r['name'])) { $raeume[(string) $u] = (string) $r['name']; }
    }
    $kat = array();
    foreach ((array) (isset($j['cats']) ? $j['cats'] : array()) as $u => $c) {
        if (is_array($c) && isset($c['name'])) { $kat[(string) $u] = (string) $c['name']; }
    }
    $bausteine = array();
    foreach ($j['controls'] as $u => $c) {
        if (!is_array($c) || !isset($c['name'])) { continue; }
        $ru = isset($c['room']) ? (string) $c['room'] : '';
        $cu = isset($c['cat']) ? (string) $c['cat'] : '';
        $bausteine[] = array(
            'uuid' => (string) $u,
            'name' => (string) $c['name'],
            'raum' => isset($raeume[$ru]) ? $raeume[$ru] : '',
            'kategorie' => isset($kat[$cu]) ? $kat[$cu] : '',
            'typ'  => isset($c['type']) ? (string) $c['type'] : '',
        );
    }
    return array(1, '', $raeume, $bausteine);
}

/**
 * Je Raum den Temperatur- und den Feuchtebaustein vorschlagen.
 *
 * ZUGEORDNET WIRD UEBER DEN RAUM, NICHT UEBER DEN NAMEN. An einer echten
 * Anlage heissen dieselben Kacheln je nach Raum ganz verschieden - mal
 * nach dem Zimmer, mal nach der Person, die darin wohnt, mal nach dem
 * Stockwerk:
 *
 *     "01) Temperatur Kueche"       "01) Temperatur OG Kinderzimmer"
 *     "01) Temperatur Arbeitsraum"  "01) Temperatur OG Schlafzimmer"
 *
 * (Die Beispiele trugen bis 0.11.2 die Vornamen aus dem Haushalt, an dem
 * gemessen wurde - in ausgeliefertem Quelltext haben die nichts zu
 * suchen.)
 *
 * Ueber den Namen ginge die Zuordnung also schief, ueber den Raum nicht.
 * Der Name entscheidet nur, WELCHE der beiden Groessen ein Baustein traegt.
 *
 * Gibt es in einem Raum mehrere Bewerber, wird der erste genommen UND die
 * Zahl gemeldet - eine stille Auswahl unter mehreren ist eine Entscheidung,
 * die der Anwender treffen muss, nicht das Plugin.
 */
function rk_fuehler_vorschlag($bausteine, $nur_kategorie = '')
{
    $t_wort  = array('temperatur', 'temp');
    $rf_wort = array('luftfeuchtigkeit', 'feuchtigkeit', 'feuchte', 'humidity');
    /* Kein Bewerber sein duerfen die abgeleiteten Merker - sie tragen
     * dieselben Woerter und sind doch keine Messwerte. */
    /* 'lueften' UND 'lüften': mb_strtolower_ersatz() bildet Ü auf ü ab, aus
     * `Lüften` wird also `lüften` und nie `lueften`. Das Ausschlusswort
     * konnte damit nie greifen. An der Anlage vom 30.08.2026 fiel es nicht
     * auf, weil die Kacheln `Lüften sinnvoll (außen trockener)` heissen und
     * schon `sinnvoll` sie ausschliesst - ein Merker namens `Lüften Feuchte`
     * waere dagegen als Feuchtequelle eingetragen worden, und der Raum
     * haette dauerhaft 1 % relative Feuchte gemeldet. */
    $nicht = array('schimmel', 'lueften', "l\xc3\xbcften", 'sinnvoll', 'gefahr',
                   'warnung', 'taupunkt', 'soll', 'ziel');
    $raeume = array();
    foreach ($bausteine as $b) {
        if ($b['raum'] === '') { continue; }
        if ($nur_kategorie !== ''
            && stripos($b['kategorie'], $nur_kategorie) === false) { continue; }
        $n = mb_strtolower_ersatz($b['name']);
        foreach ($nicht as $w) { if (strpos($n, $w) !== false) { continue 2; } }
        $ist_t = false; $ist_rf = false;
        foreach ($rf_wort as $w) { if (strpos($n, $w) !== false) { $ist_rf = true; break; } }
        if (!$ist_rf) {
            foreach ($t_wort as $w) { if (strpos($n, $w) !== false) { $ist_t = true; break; } }
        }
        if (!$ist_t && !$ist_rf) { continue; }
        $r = $b['raum'];
        if (!isset($raeume[$r])) {
            $raeume[$r] = array('raum' => $r, 't' => null, 'rf' => null,
                                't_mehr' => 0, 'rf_mehr' => 0);
        }
        $k = $ist_rf ? 'rf' : 't';
        if ($raeume[$r][$k] === null) { $raeume[$r][$k] = $b; }
        else { $raeume[$r][$k . '_mehr']++; }
    }
    ksort($raeume);
    return array_values($raeume);
}

/**
 * Kleinschreibung ohne mbstring.
 *
 * `mb_strtolower` ist auf einem LoxBerry nicht garantiert geladen - das
 * steht seit der Govee-Sitzung in den Hausregeln, und es war dort die
 * einzige mb_-Stelle im ganzen Plugin. Fuer die Woerter, nach denen hier
 * gesucht wird, reicht ASCII plus die deutschen Umlaute.
 */
function mb_strtolower_ersatz($s)
{
    /* NICHT strtolower(): das ist bis PHP 8.1 gebietsschema-abhaengig und
     * bildet unter einem Einbyte-Gebietsschema auch das Byte 0xC3 der
     * UTF-8-Folge mit ab. Aus "L" + 0xC3 0xBC + "ften" wurde dann 0xE3,
     * das Ausschlusswort griff nicht mehr, und der Assistent trug einen
     * Merker namens "Lueften Feuchte" als Feuchtequelle ein - der Raum
     * meldete danach dauerhaft 1 % relative Feuchte. Gemessen am
     * 05.09.2026 unter PHP 7.4.33 mit LC_CTYPE German_Germany.1252: der
     * eigene Selbsttest fiel an genau dieser Stelle durch, unter 8.4.24
     * nicht. strtr() mit ausgeschriebenen Tabellen kennt kein
     * Gebietsschema und arbeitet Byte fuer Byte. */
    $s = strtr((string) $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
                            'abcdefghijklmnopqrstuvwxyz');
    return strtr($s, array("\xc3\x84" => "\xc3\xa4", "\xc3\x96" => "\xc3\xb6",
                           "\xc3\x9c" => "\xc3\xbc"));
}

/**
 * Einen einzelnen Baustein am Miniserver ablesen - und den Pfad in der
 * Antwort MESSEN, nicht annehmen.
 *
 * Der Umschlag von `jdev/sps/io/<uuid>/all` ist dokumentiert als
 * `{"LL":{"control":…,"value":…,"Code":"200"}}`. Dokumentiert ist aber
 * nicht gemessen: welche Felder wirklich kommen, sagt nur die Anlage. Diese
 * Funktion holt die Antwort und sucht darin selbst den Pfad, unter dem eine
 * lesbare Zahl steht. Was der Assistent danach eintraegt, ist damit an der
 * eigenen Anlage abgelesen.
 *
 * Rueckgabe: array(ok, Meldung, Pfad, Rohwert, Zahl)
 */
function rk_ms_probe($ms, $uuid, $zeit = 12)
{
    list($code, $roh, $fehler) = rk_ms_holen($ms, 'jdev/sps/io/' . rawurlencode($uuid) . '/all', $zeit);
    if ($code === 0)   { return array(0, 'MELD.MS_STUMM', '', '', null); }
    if ($code === 401) { return array(0, 'MELD.MS_401', '', '', null); }
    if ($code !== 200) { return array(0, 'MELD.MS_HTTP', '', '', null); }
    $j = json_decode($roh, true);
    if (!is_array($j)) { return array(0, 'MELD.MS_KEIN_JSON', '', substr($roh, 0, 60), null); }
    list($pok, $pfad, $wert) = rk_wert_pfad($j);
    if (!$pok) {
        return array(0, 'MELD.MS_KEINE_ZAHL', '',
                     $wert !== '' ? $wert : substr($roh, 0, 80), null);
    }
    return array(1, '', $pfad, $wert, rk_zahl_aus($wert));
}

/**
 * Ist dieser Raumplatz wirklich frei?
 *
 * ------------------------------------------------------------------------
 * Bis 0.11.1 stand die Bedingung im Assistenten und sah nur auf drei
 * Felder: `name`, `pfad_t`, `pfad_rf`. Ein Platz, in den jemand von Hand
 * eine Adresse eingetragen hatte, aber noch keinen Namen, galt damit als
 * frei. Gemessen am 30.08.2026 mit `quelle = http://192.0.2.66/rpc/
 * Shelly.GetStatus` und sonst leerem Platz: nach der Uebernahme stand dort
 * der Miniserver, die von Hand eingetragene Shelly-Adresse war weg.
 * (Die Adresse ist fuer diese Aufzeichnung durch die Dokumentationsadresse
 * aus RFC 5737 ersetzt; gemessen wurde an einem echten Shelly.)
 *
 * Frei ist ein Platz, in dem NICHTS steht, was jemand eingetragen haben
 * koennte. Die Zahlenfelder zaehlen nicht mit - sie tragen Vorgabewerte,
 * und ein Platz, an dem nur die Vorgabe steht, ist unberuehrt.
 * ------------------------------------------------------------------------
 */
function rk_platz_frei($r)
{
    if (!is_array($r)) { return true; }
    foreach (array('name', 'quelle', 'quelle_rf', 'pfad_t', 'pfad_rf',
                   'pfad_co2', 'pfad_fenster', 'pfad_zuluft') as $k) {
        if (isset($r[$k]) && trim((string) $r[$k]) !== '') { return false; }
    }
    return true;
}

/**
 * Zwei Raumnamen vergleichen - so, wie sie nach dem Speichern dastehen.
 *
 * ------------------------------------------------------------------------
 * Der Assistent verglich bis 0.11.1 `trim($eintrag) === $loxone_name` und
 * schrieb danach den UNGETRIMMTEN Loxone-Namen. rk_config() trimmt beim
 * naechsten Lesen - der Waechter "Raum steht schon" fand seinen eigenen
 * Eintrag also nie wieder. Gemessen mit einem Loxone-Raum `'Dachboden '`:
 *
 *     vorher : ['Dachboden ', 'EG Gast', ..., 'EG Wohnzimmer', ...]
 *     nachher: ['Dachboden',  'EG Gast', ..., 'Dachboden ',    ...]
 *
 * `Dachboden` stand zweimal, `EG Wohnzimmer` war weg - und der Suchtext in
 * Loxone zeigte danach auf den falschen Raum.
 *
 * Verglichen wird getrimmt und ohne Ruecksicht auf Gross- und
 * Kleinschreibung: `Küche` und `küche` sind derselbe Raum, und zwei Zeilen
 * dafuer anzulegen waere nie das, was jemand wollte.
 * ------------------------------------------------------------------------
 */
function rk_raum_gleich($a, $b)
{
    $a = trim((string) $a);
    $b = trim((string) $b);
    if ($a === '' || $b === '') { return false; }
    return mb_strtolower_ersatz($a) === mb_strtolower_ersatz($b);
}

/**
 * Je BAUSTEINART eine Probe - fuer Temperatur und Feuchte getrennt.
 *
 * ------------------------------------------------------------------------
 * Der Befund vom 30.08.2026. Bis 0.11.1 machte der Assistent GENAU EINE
 * Probe, am ersten Temperaturbaustein, und trug deren Pfad in `pfad_t` UND
 * `pfad_rf` ein - fuer alle zwoelf Raeume. Auf der Seite stand dazu "an
 * deiner Anlage abgelesen, nicht angenommen". Fuer die Feuchte war genau
 * das nicht wahr. Gemessen mit einem Feuchtebaustein anderer Bauart:
 *
 *     Probe T : ok=1 pfad=LL.value               roh=19.7 °C
 *     Probe RF: ok=1 pfad=daten.zustand.aktuell  roh=45 % - gut
 *     eingetragen wurde aber pfad_rf = LL.value  ->  ergibt NULL
 *
 * Der Raum bekaeme nie einen Feuchtewert - keine Schimmelbewertung, kein
 * Taupunkt, keine Lueftungsempfehlung. Das ist der Kern des Plugins.
 *
 * Alle 24 Bausteine zu proben waere ehrlich, aber zu langsam: 24 Abrufe zu
 * je 12 Sekunden Zeitschranke sind im schlimmsten Fall fuenf Minuten, und
 * so lange wartet kein Webarbeiter. Geprobt wird deshalb EIN Vertreter je
 * (Groesse, Loxone-Bauart). An der eigenen Anlage sind alle Fuehler
 * `TextState`, das sind zwei Abrufe; eine gemischte Anlage kostet ein paar
 * mehr. Die Zahl der Proben ist damit durch die Zahl der Bauarten begrenzt
 * und nicht durch die Zahl der Raeume.
 *
 * Rueckgabe: array('t' => array(typ => probe, ...), 'rf' => ...), wobei
 * eine Probe array(ok, meld, pfad, roh, zahl, name) ist.
 * ------------------------------------------------------------------------
 */
function rk_ms_proben($ms, $voll, $zeit = 12)
{
    $aus = array('t' => array(), 'rf' => array());
    foreach (array('t', 'rf') as $g) {
        foreach ($voll as $v) {
            if (empty($v[$g]) || !is_array($v[$g])) { continue; }
            $typ = (string) $v[$g]['typ'];
            if (isset($aus[$g][$typ])) { continue; }
            list($ok, $meld, $pfad, $roh, $zahl)
                = rk_ms_probe($ms, $v[$g]['uuid'], $zeit);
            $aus[$g][$typ] = array('ok' => $ok, 'meld' => $meld, 'pfad' => $pfad,
                                   'roh' => $roh, 'zahl' => $zahl,
                                   'name' => (string) $v[$g]['name'],
                                   'typ' => $typ, 'raum' => (string) $v['raum']);
        }
    }
    return $aus;
}

/**
 * Aus einer Miniserver-Antwort den Pfad heraussuchen, unter dem der
 * MESSWERT steht. Rueckgabe: array(ok, pfad, rohwert).
 *
 * Eigene Funktion, damit sie sich OHNE Miniserver pruefen laesst - die
 * Selbstpruefung im Reiter Test fuehrt sie am Geraet mit erfundenen
 * Antworten vor. Solange sie in rk_ms_probe() steckte, war sie nur mit
 * einem laufenden Miniserver messbar, und damit am Geraet gar nicht.
 *
 * ZWEI FALLEN, beide am 29.08.2026 vom Gegenfall des Pruefstands gefunden -
 * der Code hatte sie, das Lesen hatte sie nicht gezeigt:
 *
 * 1. `Code` IST EINE ZAHL. Antwortet der Miniserver mit
 *    {"LL":{"value":"keine Verbindung","Code":"200"}}, dann trug die erste
 *    Fassung dieser Funktion den Pfad LL.Code ein - und danach haette jeder
 *    Raum 200 Grad gemessen. Ein Statuscode ist nie ein Messwert; solche
 *    Namen kommen gar nicht erst in die Auswahl.
 *
 * 2. GIBT ES EINEN value-PFAD, DANN ENTSCHEIDET NUR ER. Steht dort keine
 *    Zahl, ist das ein Fehlschlag und kein Grund, ein anderes Feld zu
 *    nehmen. Sonst waehlt der Assistent ein Feld, das gerade zufaellig eine
 *    Zahl traegt, und der Anwender sucht spaeter lange - der Funkpegel
 *    eines Shelly ist eine tadellose Zahl und keine Raumtemperatur.
 */
function rk_wert_pfad($j)
{
    $treffer = array();
    rk_blaetter($j, '', $treffer, 0);
    $nie = array('code', 'control', 'uuid', 'id', 'ts', 'timestamp');
    $wert_pfad = '';
    $best = '';
    foreach ($treffer as $pfad => $wert) {
        $letzt = strtolower(substr($pfad, (int) strrpos('.' . $pfad, '.')));
        if (in_array($letzt, $nie, true)) { continue; }
        if ($letzt === 'value' && $wert_pfad === '') { $wert_pfad = $pfad; }
        if ($best === '' && rk_zahl_aus($wert) !== null) { $best = $pfad; }
    }
    if ($wert_pfad !== '') {
        /* Es gibt ein value - dann gilt es, und nur es. */
        if (rk_zahl_aus($treffer[$wert_pfad]) === null) {
            return array(0, '', (string) $treffer[$wert_pfad]);
        }
        $best = $wert_pfad;
    }
    if ($best === '') { return array(0, '', ''); }
    return array(1, $best, (string) $treffer[$best]);
}

/** Rekursiv durch eine Antwort - nur Blaetter, mit vollem Punktpfad. */
function rk_blaetter($daten, $praefix, &$treffer, $tiefe)
{
    if ($tiefe > 8 || count($treffer) > 200 || !is_array($daten)) { return; }
    foreach ($daten as $k => $v) {
        $pfad = ($praefix === '') ? (string) $k : $praefix . '.' . $k;
        if (is_array($v)) { rk_blaetter($v, $pfad, $treffer, $tiefe + 1); }
        elseif (!is_bool($v) && $v !== null) { $treffer[$pfad] = $v; }
    }
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
    /* ------------------------------------------------------------------
     * Eine KAPUTTE Verlaufsdatei ist etwas anderes als eine fehlende.
     *
     * Bis 0.11.1 stand hier rk_json_lesen(), und das liefert bei
     * ungueltigem JSON dasselbe leere Feld wie bei "noch keine Datei". Der
     * naechste Schreibvorgang ueberschrieb sie dann - dreissig Tage
     * Stundenreihe, Aussenmittel, Erfolgsquote und die Strecke ohne
     * Empfehlung waren weg, NASS24/NASS7T/ERFOLG sprangen auf -1,
     * HEIZFALL ebenfalls, und im Protokoll stand kein Wort.
     *
     * rk_config() behandelt genau denselben Fall fuer raumklima.json seit
     * 0.10.1 vorbildlich: beiseitelegen und melden. Der Verlauf hatte diese
     * Behandlung nie bekommen. Er ist weniger wichtig als die
     * Konfiguration - aber "weniger wichtig" ist kein Grund, ihn WORTLOS zu
     * verlieren. Wer im Protokoll liest, warum NASS24 auf -1 steht, findet
     * die Antwort jetzt dort.
     * ------------------------------------------------------------------ */
    $pfad = rk_paths()['datadir'] . '/verlauf.json';
    list($v, $lage) = rk_json_lage($pfad);
    if ($lage === 'kaputt') {
        $ziel = $pfad . '.kaputt.' . date('Ymd');
        if (!is_file($ziel)) { @copy($pfad, $ziel); @chmod($ziel, 0600); }
        rk_log_gebremst('verlauf_kaputt',
            'Der Verlaufsspeicher war unlesbar und liegt als '
            . basename($ziel) . ' daneben. Die Reihen beginnen von vorn; '
            . 'NASS24, NASS7T und ERFOLG stehen bis dahin auf -1.', 900);
        $v = array();
    }
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
    /* `!== false` genuegt nicht: bei voller Platte schreibt fwrite() nur
     * einen Teil und liefert dessen Laenge zurueck. Das galt als Erfolg,
     * und die abgeschnittene Nebendatei wurde ueber die gute Datei
     * umbenannt. Auch fflush() und fclose() koennen ENOSPC nachreichen. */
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) === strlen($json);
    if (!fflush($fh)) { $ok = false; }
    if (!fclose($fh)) { $ok = false; }
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
function rk_verlauf_raum(&$vr, $e, $jetzt, $takt = 300)
{
    if (!is_array($vr)) { $vr = array(); }
    foreach (array('fein', 'stunden', 'ereignisse') as $k) {
        if (!isset($vr[$k]) || !is_array($vr[$k])) { $vr[$k] = array(); }
    }
    /* Die Strecke ohne Empfehlung laeuft auch waehrend eines Ausfalls
     * weiter - der Feuchteschutz haengt nicht daran, dass gerade jemand
     * misst. Sie wird deshalb VOR dem Ausstieg fortgeschrieben. */
    if (!isset($vr['ohne_ts']) || !is_numeric($vr['ohne_ts'])) {
        $vr['ohne_ts'] = (int) $jetzt;
    }
    if (!empty($e['lueften'])) { $vr['ohne_ts'] = (int) $jetzt; }

    if (empty($e['ok'])) { return; }

    $takt = max(60, min(3600, (int) $takt));

    /* ------------------------------------------------------------------
     * EIN PUNKT JE TAKT - auch wenn oefter gemessen wird.
     *
     * Der Stundenkorb rechnet jede Messung als takt/3600 Stunden. Das gilt,
     * solange ausschliesslich der Cron misst. Jeder Druck auf "Jetzt
     * abrufen" und jedes ?aktion=abrufen haengt aber einen weiteren Punkt
     * an. Gemessen am 30.08.2026, eine Stunde mit zwoelf regulaeren
     * Messungen (sechs davon nass) plus sechs Handabrufen im nassen
     * Abschnitt:
     *
     *     wahr: 0,5 h nass   -   gemeldet: nass24 = 1,0
     *     Feinreihe traegt 18 Punkte statt 12
     *
     * Zweierlei ging schief. Die Nassstunden liefen nach oben - wieder die
     * Richtung Fehlalarm. Und die Feinreihe (144 Punkte fest) deckte statt
     * zwoelf nur noch acht Stunden ab; wer den Endpunkt mit aktion=abrufen
     * statt aktion=status abfragt, verkuerzt das Fenster beliebig weit.
     *
     * Der Messwert selbst geht dadurch nicht verloren - er steht in
     * stand.json und geht nach Loxone. Nur der VERLAUF nimmt ihn nicht auf,
     * und genau der lebt davon, dass seine Punkte gleich viel wiegen.
     *
     * 0,8 statt 1,0: ein Cron laeuft nie auf die Sekunde genau, und ein
     * Lauf, der drei Sekunden zu frueh kommt, ist der regulaere.
     * ------------------------------------------------------------------ */
    $letzter = !empty($vr['fein']) ? (int) $vr['fein'][count($vr['fein']) - 1][0] : 0;
    if ($letzter > 0 && (int) $jetzt >= $letzter
        && ((int) $jetzt - $letzter) < (int) round($takt * 0.8)) {
        return;
    }

    $vr['fein'][] = array((int) $jetzt, $e['t'], $e['rf'], $e['absolut'],
                          $e['ober_rf'], $e['co2'], (int) $e['lueften']);
    if (count($vr['fein']) > RK_FEIN_MAX) {
        $vr['fein'] = array_slice($vr['fein'], -RK_FEIN_MAX);
    }

    /* ------------------------------------------------------------------
     * Stundenkorb: sammeln, und beim Stundenwechsel als Mittel ablegen.
     *
     * Zwei Zaehlfehler vom 28.08.2026 sind hier behoben:
     *
     * 1. DER KORB NORMIERTE AUF DIE MESSZAHL, NICHT AUF DIE STUNDE. Die
     *    Nassstunden wurden als `summe / anzahl` abgelegt. Eine Stunde, in
     *    der der Fuehler 55 Minuten schwieg und einmal Naesse meldete, ging
     *    damit als VOLLE Nassstunde ein:
     *
     *        12 von 12 Messungen, alle nass -> Anteil 1
     *         6 von 12 Messungen, alle nass -> Anteil 1   (falsch)
     *         1 von 12 Messungen, alle nass -> Anteil 1   (falsch)
     *
     *    Der Fehler wirkte einseitig nach oben, also in Richtung Fehlalarm.
     *    Gerechnet wird jetzt mit der TAKTZEIT: jede Messung steht fuer
     *    takt/3600 Stunden. Sechs von zwoelf ergeben 0,5 - die fehlende
     *    Zeit zaehlt nicht als nass, denn niemand weiss, wie sie war.
     *
     * 2. EIN ZEITSPRUNG VERDOPPELTE STUNDEN. Geprueft wurde nur `!==` gegen
     *    die laufende Stunde, nicht die Reihenfolge. Mit zehn Vor- und
     *    Ruecksprungen ueber 20 Stunden Naesse standen 37 Koerbe im Ring,
     *    davon 9 mit doppelter Stundenmarke, und nass24 kam auf 28 - bei
     *    einer physikalischen Obergrenze von 24. Ein LoxBerry auf
     *    Raspberry-Grundlage hat keine gepufferte Uhr und springt nach jedem
     *    Start. Eine Stundenmarke, die schon im Ring steht, wird jetzt
     *    ersetzt statt angehaengt.
     * ------------------------------------------------------------------ */
    $stunde = (int) $jetzt - ((int) $jetzt % 3600);
    $korb_gut = isset($vr['korb']) && is_array($vr['korb']) && count($vr['korb']) >= 6;
    if (!$korb_gut || (int) $vr['korb'][0] !== $stunde) {
        if ($korb_gut && (int) $vr['korb'][1] > 0) {
            $k = $vr['korb'];
            $n = (int) $k[1];
            /* ------------------------------------------------------------
             * Feld 7: wie viele Messungen dieser Stunde ueberhaupt eine
             * Aussage ueber die kalte Flaeche trugen.
             *
             * Bis 0.11.1 stand hier nur die Zahl der NASSEN Messungen, und
             * `ober_rf === null` fiel in denselben Zweig wie "gemessen und
             * trocken". Gemessen am 30.08.2026, zwoelf Stunden im
             * Fuenfminutentakt:
             *
             *     ohne Aussenwerte (ober_rf null) -> nass24 = 0,0
             *     dieselbe Reihe mit ober_rf 88 % -> nass24 = 12,0
             *
             * Ein tagelanger Ausfall der Aussenquelle erschien damit als
             * lueckenlos TROCKENE Wand. Das ist die gefaehrliche Richtung:
             * nicht ein Fehlalarm, sondern ein unterdrueckter.
             *
             * Eine Stunde ganz ohne Aussage bekommt jetzt -1 statt 0,0 und
             * wird beim Summieren uebersprungen. Aeltere Stundeneintraege
             * tragen nie eine -1, sie rechnen unveraendert weiter.
             * ------------------------------------------------------------ */
            $gemessen = isset($k[7]) ? (int) $k[7] : $n;
            $nassstd = $gemessen > 0
                ? round(min(1.0, $k[5] * $k[6] / 3600.0), 3)
                : -1.0;
            $eintrag = array((int) $k[0], round($k[2] / $n, 2), round($k[3] / $n, 1),
                             round($k[4] / $n, 3), $nassstd);
            $ersetzt = false;
            foreach ($vr['stunden'] as $i => $alt) {
                if ((int) $alt[0] === (int) $k[0]) {
                    $vr['stunden'][$i] = $eintrag;
                    $ersetzt = true;
                    break;
                }
            }
            if (!$ersetzt) { $vr['stunden'][] = $eintrag; }
            if (count($vr['stunden']) > RK_STUNDEN_MAX) {
                $vr['stunden'] = array_slice($vr['stunden'], -RK_STUNDEN_MAX);
            }
        }
        /* Feld 6 ist die Taktzeit: die Normierung muss dieselbe sein, mit
         * der gesammelt wurde - auch wenn der Anwender den Takt inzwischen
         * verstellt hat. */
        $vr['korb'] = array($stunde, 0, 0.0, 0.0, 0.0, 0.0, $takt, 0);
    }
    /* Ein Korb aus 0.11.1 traegt sieben Felder. Ihn zu verwerfen kostete
     * eine angefangene Stunde; das Feld nachzutragen kostet nichts. */
    if (!isset($vr['korb'][7])) { $vr['korb'][7] = 0; }
    $vr['korb'][1]++;
    $vr['korb'][2] += (float) $e['t'];
    $vr['korb'][3] += (float) $e['rf'];
    $vr['korb'][4] += (float) $e['absolut'];
    if ($e['ober_rf'] !== null) {
        $vr['korb'][7]++;
        $vr['korb'][5] += ($e['ober_rf'] >= 80.0) ? 1.0 : 0.0;
    }

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
function rk_verlauf_werte($vr, $jetzt, $volumen = 0, $trend_min = 60)
{
    $out = array('nass24' => -1, 'nass7t' => -1, 'erfolg' => -1, 'eintrag' => -1,
                 'trend' => null, 'dusche' => 0, 'ohne_std' => -1,
                 'co2_anstieg' => null);
    if (!is_array($vr)) { return $out; }

    /* Die Strecke ohne Empfehlung - fuer die Zwangslueftung. */
    if (isset($vr['ohne_ts']) && is_numeric($vr['ohne_ts'])) {
        $out['ohne_std'] = (int) floor(max(0, (int) $jetzt - (int) $vr['ohne_ts']) / 3600);
    }

    /* ------------------------------------------------------------------
     * Nassstunden - jetzt einschliesslich der LAUFENDEN Stunde.
     *
     * Bis 0.10.1 ging der offene Korb nie ein (er wird erst beim
     * Stundenwechsel abgelegt), und die Fenstergrenze schnitt eine weitere
     * ab. Gemessen: 24 Stunden ununterbrochene Naesse ergaben nass24 = 23,
     * 26 Stunden ebenfalls 23. Die Anzeige konnte "24 von 24" nie
     * erreichen - und die ersten 60 Minuten nach dem Einschalten lieferten
     * gar nichts, obwohl der Korb schon elf Treffer hielt.
     * ------------------------------------------------------------------ */
    $offen = 0.0;
    $hat_offen = false;
    if (isset($vr['korb']) && is_array($vr['korb']) && count($vr['korb']) >= 7
        && (int) $vr['korb'][1] > 0
        /* Der offene Korb zaehlt nur mit, wenn in dieser Stunde ueberhaupt
         * eine Aussage ueber die kalte Flaeche gemessen wurde. Ohne diese
         * Bedingung ginge er als 0,0 - also als "trocken" - ein. */
        && (!isset($vr['korb'][7]) || (int) $vr['korb'][7] > 0)) {
        $offen = min(1.0, (float) $vr['korb'][5] * (float) $vr['korb'][6] / 3600.0);
        $hat_offen = true;
    }
    if (!empty($vr['stunden']) || $hat_offen) {
        $s24 = $offen; $n24 = $hat_offen ? 1 : 0;
        $s7 = $offen;  $n7 = $n24;
        foreach ((array) $vr['stunden'] as $h) {
            if (!is_array($h) || count($h) < 5) { continue; }
            /* Eine Stunde ohne jede Aussage traegt -1 und wird
             * uebersprungen - nicht als 0 mitgezaehlt. Sonst waere ein
             * Ausfall von der gemessenen Trockenheit nicht zu
             * unterscheiden. */
            if ((float) $h[4] < 0) { continue; }
            $alt = (int) $jetzt - (int) $h[0];
            if ($alt < 0) { continue; }
            if ($alt < 24 * 3600) { $s24 += (float) $h[4]; $n24++; }
            if ($alt < 7 * 24 * 3600) { $s7 += (float) $h[4]; $n7++; }
        }
        if ($n24 > 0) { $out['nass24'] = round(min(24.0, $s24), 1); }
        if ($n7 > 0) { $out['nass7t'] = round(min(168.0, $s7), 1); }
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

    /* ------------------------------------------------------------------
     * Der Trend: wie schnell steigt oder faellt die absolute Feuchte?
     *
     * Ausgleichsgerade ueber die Feinreihe im eingestellten Fenster. Anders
     * als 'eintrag' braucht der Trend KEIN Raumvolumen und keine ungelueftete
     * Strecke - er beantwortet "wohin geht es gerade", nicht "wie viel Wasser
     * kommt dazu". Deshalb steht er auch dann da, wenn kein Volumen
     * eingetragen ist, und das ist der Normalfall.
     *
     * Einheit: g/m3 je Stunde. Rueckgabe null bei weniger als drei Punkten
     * oder ohne Zeitspanne - eine 0 hiesse "steht", und das waere eine
     * Aussage, die auf zwei Messwerten nicht belegt ist.
     * ------------------------------------------------------------------ */
    $fenster = max(10, min(720, (int) $trend_min)) * 60;

    /* Die Ausgleichsgerade steht in einer Funktion, nicht zweimal im Text -
     * sie wird fuer die Feuchte UND fuer CO2 gebraucht, und zwei Abschriften
     * derselben Rechnung laufen frueher oder spaeter auseinander. */
    $steigung = function ($spalte, $stellen) use ($vr, $jetzt, $fenster) {
        $px = array();
        foreach ((array) (isset($vr['fein']) ? $vr['fein'] : array()) as $f) {
            if (!is_array($f) || count($f) <= $spalte || $f[$spalte] === null) { continue; }
            $alt = (int) $jetzt - (int) $f[0];
            if ($alt < 0 || $alt > $fenster) { continue; }
            $px[] = array((int) $f[0], (float) $f[$spalte]);
        }
        $anz = count($px);
        if ($anz < 3) { return null; }
        $sx = $sy = $sxy = $sxx = 0.0;
        $t0p = (int) $px[0][0];
        foreach ($px as $q) {
            $x = ((int) $q[0] - $t0p) / 3600.0;   // Stunden
            $sx += $x; $sy += $q[1]; $sxy += $x * $q[1]; $sxx += $x * $x;
        }
        $nenner = $anz * $sxx - $sx * $sx;
        if (abs($nenner) < 1e-9) { return null; }
        return round(($anz * $sxy - $sx * $sy) / $nenner, $stellen);
    };

    $out['trend'] = $steigung(3, 2);          // absolute Feuchte, g/(m3*h)
    $out['co2_anstieg'] = $steigung(5, 0);    // CO2, ppm/h

    /* ------------------------------------------------------------------
     * Duschstoss: ein Sprung der absoluten Feuchte, wie ihn nur eine
     * Wasserquelle im Raum erzeugt.
     *
     * Erkannt wird an der Steigung ueber die letzten dreissig Minuten (die
     * Schranke unten ist 1800 s; bis 0.11.2 sagte dieser Satz zwanzig, und
     * eine der beiden Zahlen musste falsch sein) - nicht
     * an einem festen Schwellwert der Feuchte, denn wie feucht ein Bad
     * "normal" ist, weiss niemand. Abgeschaltet wird nicht nach einer festen
     * Minutenzahl, sondern wenn die Feuchte wieder unter den Wert VOR dem
     * Sprung faellt; sonst steht die Empfehlung, waehrend das Wasser noch
     * an den Fliesen haengt.
     * ------------------------------------------------------------------ */
    $kurz = array();
    foreach ((array) (isset($vr['fein']) ? $vr['fein'] : array()) as $f) {
        if (!is_array($f) || count($f) < 4 || $f[3] === null) { continue; }
        $alt = (int) $jetzt - (int) $f[0];
        if ($alt < 0 || $alt > 1800) { continue; }
        $kurz[] = array((int) $f[0], (float) $f[3]);
    }
    if (count($kurz) >= 3) {
        $erster = $kurz[0];
        $letzter = $kurz[count($kurz) - 1];
        $std = ((int) $letzter[0] - (int) $erster[0]) / 3600.0;
        if ($std >= 0.15) {
            $steig = ((float) $letzter[1] - (float) $erster[1]) / $std;
            /* 2,0 g/(m3*h) trennt eine Dusche von jeder Alltagsbewegung -
             * Kochen und Waesche liegen darunter, ein Mensch im Raum weit
             * darunter. Die Zahl ist eine EINSTELLUNG in Zahlenform, keine
             * Messung; sie steht hier, weil ein Feld dafuer in der
             * Oberflaeche mehr Verwirrung als Nutzen braechte. */
            if ($steig >= 2.0) { $out['dusche'] = 1; }
        }
    }
    return $out;
}

/* ==================================================================
 * Der Aussenspeicher - fuer das gleitende Aussenmittel
 *
 * Der Raumspeicher haelt je Raum nur t, rf, absolut und den Nassanteil; die
 * AUSSENtemperatur wurde bis 0.10.1 nirgends historisiert. Ohne sie laesst
 * sich Heiz- von Kuehlfall nicht unterscheiden - aus der Vorhersage geht es
 * nicht, sie reicht nur vorwaerts.
 *
 * Gefuehrt werden Tagesmittel, dreissig Tage weit. Das gleitende Mittel
 * folgt der ueblichen Form mit alpha = 0,8: der gestrige Tag zaehlt am
 * meisten, und je weiter zurueck, desto weniger.
 * ================================================================== */

define('RK_AUSSEN_TAGE', 30);

function rk_verlauf_aussen(&$va, $t_aussen, $jetzt)
{
    if (!is_array($va)) { $va = array(); }
    if (!isset($va['tage']) || !is_array($va['tage'])) { $va['tage'] = array(); }
    if (!rk_t_gueltig($t_aussen)) { return; }
    /* Der Kalendertag, nicht der UTC-Tag. `% 86400` teilt den Zeitstempel
     * an der UTC-Mitternacht, also um 02:00 MESZ bzw. 01:00 MEZ - das
     * Tagesmittel enthielt damit die beiden kaeltesten Stunden der
     * FOLGENDEN Nacht, und ueber rk_aussen_mittel() verschob das an der
     * Heizgrenze den Umschaltpunkt um einen Tag. */
    $tag = (int) strtotime('today 00:00', (int) $jetzt);
    $korb_gut = isset($va['korb']) && is_array($va['korb']) && count($va['korb']) >= 3;
    if (!$korb_gut || (int) $va['korb'][0] !== $tag) {
        if ($korb_gut && (int) $va['korb'][1] > 0) {
            $eintrag = array((int) $va['korb'][0],
                             round($va['korb'][2] / (int) $va['korb'][1], 2));
            $ersetzt = false;
            foreach ($va['tage'] as $i => $alt) {
                if ((int) $alt[0] === (int) $va['korb'][0]) {
                    $va['tage'][$i] = $eintrag; $ersetzt = true; break;
                }
            }
            if (!$ersetzt) { $va['tage'][] = $eintrag; }
            if (count($va['tage']) > RK_AUSSEN_TAGE) {
                $va['tage'] = array_slice($va['tage'], -RK_AUSSEN_TAGE);
            }
        }
        $va['korb'] = array($tag, 0, 0.0);
    }
    $va['korb'][1]++;
    $va['korb'][2] += (float) $t_aussen;
}

/**
 * Das gleitende Aussenmittel. Rueckgabe null, solange zu wenig Tage
 * vorliegen - drei sind das Mindeste, darunter ist es das Wetter von
 * gestern und kein Mittel.
 */
function rk_aussen_mittel($va, $jetzt)
{
    if (!is_array($va) || empty($va['tage'])) { return null; }
    $tage = array();
    foreach ($va['tage'] as $e) {
        if (!is_array($e) || count($e) < 2) { continue; }
        if ((int) $e[0] > (int) $jetzt) { continue; }
        $tage[(int) $e[0]] = (float) $e[1];
    }
    if (count($tage) < 3) { return null; }
    krsort($tage);
    $alpha = 0.8;
    $zaehler = 0.0; $nenner = 0.0; $g = 1.0;
    foreach ($tage as $w) {
        $zaehler += $g * $w;
        $nenner += $g;
        $g *= $alpha;
        if ($g < 0.01) { break; }
    }
    return $nenner > 0 ? round($zaehler / $nenner, 2) : null;
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
    /* Der Takt haengt am LAUF, nicht am Messwert. Seit 0.11.0 sind das zwei
     * verschiedene Zeitstempel - siehe unten. */
    $letzter_lauf = isset($alt['lauf_ts']) ? (int) $alt['lauf_ts']
        : (isset($alt['ts']) ? (int) $alt['ts'] : 0);
    /* $abstand >= 0 gehoert dazu. Steht in stand.json ein Zeitstempel in
     * der ZUKUNFT - ein Raspberry ohne gepufferte Uhr springt beim ersten
     * Zeitabgleich -, ist der Abstand negativ und damit immer kleiner als
     * der Takt: rk_abrufen(false) kehrte dann bei JEDEM Cron-Lauf um, bis
     * die echte Zeit den Zeitstempel eingeholt hat. Gemessen am 05.09.2026
     * mit lauf_ts + 30 Tage: drei Laeufe, Zaehler blieb auf 1. Dieselbe
     * Wache steht an den drei anderen Zeitstellen laengst. */
    /* Die Frist: der Takt abzueglich einer halben Minute, mindestens 30 s.
     * Bei der Vorgabe 300 s sind das 270 s - der naechste Cron-Lauf findet
     * damit immer einen beendeten Vorgaenger. */
    rk_frist(time() + max(30, (int) $cfg['takt'] - 30));

    $abstand = time() - $letzter_lauf;
    if (!$erzwingen && $letzter_lauf > 0
        && $abstand >= 0 && $abstand < (int) $cfg['takt']) {
        return $alt;
    }

    /* ------------------------------------------------------------------
     * DIE SPERRE STEHT SEIT 0.11.2 HIER - nicht mehr nur im Cron-Skript.
     *
     * `flock` gab es bis 0.11.1 an genau einer Stelle: bin/raumklima_abruf.php.
     * Damit war der Cron gegen sich selbst geschuetzt, gegen die Oberflaeche
     * aber nicht - und rk_abrufen() wird von sechs weiteren Stellen gerufen
     * (html/index.php ueber ?aktion=abrufen, htmlauth/index.php dreimal,
     * rk_test.php).
     *
     * Gemessen am 30.08.2026 mit einem Cron-Lauf und einem gleichzeitigen
     * ?aktion=abrufen: rk_json_schreiben() schreibt zwar atomar (erst
     * daneben, dann umbenennen), der Zyklus LESEN-RECHNEN-SCHREIBEN aber
     * ist ungeschuetzt. Beide lasen dieselbe verlauf.json, beide rechneten,
     * der zweite schrieb:
     *
     *     Start: fein = 1   nach zwei Laeufen: fein = 2   (erwartet 3)
     *     der Eintrag des Cron war weg
     *
     * Verloren gehen Messpunkte, Stundenkoerbe, Lueftungsereignisse und der
     * Laufzaehler - lautlos.
     *
     * Nicht blockierend: wer nicht drankommt, bekommt den letzten Stand
     * zurueck. Warten waere schlechter - der Webarbeiter haenge dann an
     * einem Netzabruf, den ein anderer gerade macht, und der naechste Takt
     * kommt ohnehin gleich.
     * ------------------------------------------------------------------ */
    $sperrdatei = $p['datadir'] . '/.abruf.lock';
    $sperre = @fopen($sperrdatei, 'c');
    if ($sperre === false) {
        /* Kein Schloss zu bekommen ist kein Grund, gar nicht zu messen -
         * aber es gehoert gesagt. */
        rk_log_gebremst('sperre_nicht_moeglich',
            'Die Abrufsperre liess sich nicht anlegen (' . $sperrdatei
            . '). Gleichzeitige Laeufe koennen einander ueberschreiben.', 3600);
    } elseif (!flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        return $alt;
    }

    $jetzt = time();
    /* ------------------------------------------------------------------
     * ZWEI Zeitstempel und ein Zaehler - der Befund vom 28.08.2026
     * ------------------------------------------------------------------
     *
     * Bis 0.10.1 gab es nur 'ts', und es wurde bei JEDEM Lauf aufgefrischt -
     * auch wenn keine einzige Quelle geantwortet hatte. Drei echte Cron-
     * Laeufe gegen eine tote Quelle, mit einem Horcher am UDP-Eingang:
     *
     *     Lauf  RC  stand.ts     alter (MQTT)  ok (MQTT)
     *     1     1   1787872668   4             0
     *     2     1   1787872677   4             0
     *     3     1   1787872685   4             0
     *     mit_werten: 0, drei Stoermeldungen
     *
     * Zweierlei steckt darin. Erstens mass 'ts' nicht das Alter der WERTE,
     * sondern nur, dass der Cron lief. Zweitens ist 'alter' ueber MQTT
     * strukturell konstant: rk_abrufen() setzt ts auf jetzt und sendet
     * unmittelbar danach. Stirbt der Cron, wird gar nichts mehr gesendet,
     * und der Broker liefert die letzte 4 mit Retain weiter - in Loxone
     * steht dann auf Dauer "vor vier Sekunden gemessen".
     *
     *   ts        Zeitpunkt der letzten ERFOLGREICHEN Messung. Nur dann
     *             aufgefrischt. Daraus rechnet der HTTP-Weg ALTER zur
     *             Lesezeit - und das ist jetzt das wahre Alter der Werte.
     *   lauf_ts   Zeitpunkt des letzten Laufs, gleichgueltig wie er ausging.
     *             Daran haengt der Takt, und ueber MQTT geht er als eigenes
     *             Thema hinaus; der Miniserver rechnet selbst.
     *   zaehler   0...999, laeuft um. Er beantwortet, was ein Zeitstempel
     *             nicht kann: ein Raspberry ohne gepufferte Uhr springt beim
     *             ersten Zeitabgleich, und ein Alter kann danach negativ
     *             sein. Eine umlaufende Zahl nicht.
     * ------------------------------------------------------------------ */
    $zaehler = (isset($alt['zaehler']) ? ((int) $alt['zaehler'] + 1) : 0) % 1000;
    $stand = array('ts' => 0, 'lauf_ts' => $jetzt, 'zaehler' => $zaehler,
                   'raeume' => array(), 'aussen' => null,
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
        /* Zugangsdaten NUR an einen Wirt, der auch die Fuehler traegt -
         * siehe rk_zugang_erlaubt(). Die Aussenquelle ist das einzige Feld,
         * in das ueblicherweise eine FREMDE Adresse eingetragen wird. */
        list($d, $m) = rk_holen($cfg['aussen_quelle'],
                                rk_zugang_erlaubt($cfg['aussen_quelle'], $cfg));
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
                       'kuehl_spanne' => $cfg['kuehl_spanne'],
                       'wind_max' => $cfg['wind_max'],
                       'wand_abstand' => $cfg['wand_abstand'],
                       'schwuel_x' => $cfg['schwuel_x'],
                       'co2_t_min' => $cfg['co2_t_min'],
                       'zwang_std' => $cfg['zwang_std'],
                       'vl_zuschlag' => $cfg['vl_zuschlag'],
                       'kuehlfrei_ein' => $cfg['kuehlfrei_ein'],
                       'kuehlfrei_aus' => $cfg['kuehlfrei_aus'],
                       'co2_ltr' => $cfg['co2_ltr'],
                       'co2_aussen' => $cfg['co2_aussen']);
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

        /* ---- Die zweite Adresse, nur fuer die Feuchte ----
         *
         * Beim Miniserver ist jeder Baustein eine eigene Adresse; Temperatur
         * und Feuchte sind zwei. Leer heisst weiterhin "dieselbe Antwort wie
         * fuer die Temperatur", und dann wird auch nichts zweites geholt -
         * eine Quelle, die alles auf einmal liefert, wird nicht zweimal
         * gefragt. Gleiche Adressen werden ebenfalls nur einmal geholt. */
        $daten_rf = $daten;
        $q_rf = trim((string) $r['quelle_rf']);
        if ($q_rf !== '' && $q_rf !== trim((string) $quelle)) {
            list($daten_rf, $m2) = rk_holen($q_rf, true);
            if ($daten_rf === null) { $stand['meldungen']['raum' . $nr . '_rf'] = $m2; }
        }

        $roh = array('name' => $r['name'], 't' => null, 'rf' => null,
                     'frsi' => $r['frsi'], 'soll_min' => $r['soll_min'],
                     'soll_max' => $r['soll_max'], 'art' => $r['art'],
                     'erd_t' => $r['erd_t'], 'einheit_t' => $r['einheit_t'],
                     'einheit_rf' => $r['einheit_rf'], 'volumen' => $r['volumen'],
                     'fenster' => $r['fenster'], 't_soll' => $r['t_soll'],
                     'co2_max' => $r['co2_max'], 'co2' => null,
                     'fenster_offen' => null,
                     'zuluft' => null, 'wrg_eta' => $r['wrg_eta'],
                     'wasser_g' => $r['wasser_g'],
                     'ruhe_von' => $r['ruhe_von'], 'ruhe_bis' => $r['ruhe_bis'],
                     'personen' => $r['personen']);
        if (is_array($daten_rf)) {
            $roh['rf'] = rk_pfad($daten_rf, $r['pfad_rf']);
        }
        if (is_array($daten)) {
            $roh['t'] = rk_pfad($daten, $r['pfad_t']);
            if (trim((string) $r['pfad_co2']) !== '') {
                $roh['co2'] = rk_pfad($daten, $r['pfad_co2']);
            }
            if (trim((string) $r['pfad_fenster']) !== '') {
                $roh['fenster_offen'] = rk_pfad($daten, $r['pfad_fenster']);
            }
            if (trim((string) $r['pfad_zuluft']) !== '') {
                $roh['zuluft'] = rk_pfad($daten, $r['pfad_zuluft']);
            }
        }
        /* Der vorige Eintrag desselben Raums - daraus entsteht die
         * Ausfallerkennung: seit wann kein Wert mehr kam und seit wann sich
         * der vorhandene nicht mehr bewegt. */
        $letzt = isset($alt['raeume'][$nr]) && is_array($alt['raeume'][$nr])
            ? $alt['raeume'][$nr] : null;
        $vr = isset($verlauf['raeume'][$nr]) ? $verlauf['raeume'][$nr] : array();
        $abgeleitet = !empty($cfg['verlauf_ein'])
            ? rk_verlauf_werte($vr, $jetzt, $r['volumen'], $cfg['trend_min']) : null;
        $e = rk_raum_rechnen($roh, $aussen, $vorher, $bewertung, $jetzt, $letzt, $abgeleitet);
        $e['nr'] = $nr;
        $stand['raeume'][$nr] = $e;
        if (!empty($cfg['verlauf_ein'])) {
            rk_verlauf_raum($vr, $e, $jetzt, $cfg['takt']);
            $verlauf['raeume'][$nr] = $vr;
        }
    }

    /* Der Aussenspeicher - er traegt das gleitende Mittel und damit die
     * Unterscheidung Heiz- gegen Kuehlfall. Er laeuft unabhaengig von den
     * Raeumen; ohne einen einzigen eingerichteten Raum fuellt er sich
     * trotzdem, und das ist richtig so. */
    if (!empty($cfg['verlauf_ein'])) {
        $va = isset($verlauf['aussen']) ? $verlauf['aussen'] : array();
        rk_verlauf_aussen($va, isset($stand['aussen']['t']) ? $stand['aussen']['t'] : null,
                          $jetzt);
        $verlauf['aussen'] = $va;
        $stand['aussen_mittel'] = rk_aussen_mittel($va, $jetzt);
    } else {
        $stand['aussen_mittel'] = null;
    }
    $stand['heizfall'] = rk_heizfall($stand['aussen_mittel'], $cfg['heizgrenze']);

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
    /* Neu in 0.11.0 - hinten angehaengt, wie es die Regel verlangt. */
    $stand['ampellos_n'] = 0;
    $stand['schwuel_n'] = 0;
    $stand['zwang_n'] = 0;
    $stand['sperre_n'] = 0;
    $stand['vereist_n'] = 0;
    $mit_werten = 0;
    foreach ($stand['raeume'] as $e) {
        /* === 1 und nicht "wahr". Seit 0.11.2 kann SCHIMMEL auch -1 tragen
         * ("keine Aussage moeglich"), und -1 ist in PHP wahr. Ein blosses
         * `if ($e['schimmel'])` haette jeden stummen Fuehler als
         * Schimmelgefahr gezaehlt - aus einer fehlenden Aussage waere ein
         * Alarm geworden. */
        if ((int) $e['schimmel'] === 1) { $stand['schimmel_n']++; }
        if ($e['lueften']) { $stand['lueften_n']++; }
        if ($e['feucht']) { $stand['feucht_n']++; }
        if ($e['trocken']) { $stand['trocken_n']++; }
        if ($e['ok']) { $mit_werten++; } else { $stand['ohne_n']++; }
        if (!empty($e['steht'])) { $stand['steht_n']++; }
        if (!empty($e['kuehlen'])) { $stand['kuehl_n']++; }
        if (!empty($e['co2_hoch'])) { $stand['co2_n']++; }
        if (!empty($e['fenster_zu'])) { $stand['fenster_n']++; }
        /* Raeume, ueber deren Schimmelgefahr sich NICHTS sagen laesst.
         * Die Ampel steht dort auf -1; frueher stand dort eine 0, und die
         * war von "gemessen und unbedenklich" nicht zu unterscheiden. Wer
         * einen Waechter auf schimmel_n legt, braucht diese Zahl daneben.
         *
         * OHNE die $e['ok']-Bedingung, seit 0.11.2. Sie stand dort, solange
         * ein stummer Fuehler AMPEL=0 lieferte - dann waere er hier gar
         * nicht aufgefallen. Seit dem Ausfallzweig oben AMPEL=-1 setzt, ist
         * er genau das, was der Name sagt: ein Raum, ueber dessen
         * Schimmelgefahr sich nichts sagen laesst. Ihn hier auszunehmen
         * hiesse, die Zahl kleiner zu machen als die Wirklichkeit. Wie viele
         * Raeume ueberhaupt Werte tragen, sagt OK daneben. */
        if (isset($e['ampel']) && (int) $e['ampel'] < 0) { $stand['ampellos_n']++; }
        if (!empty($e['schwuel']) && (int) $e['schwuel'] === 1) { $stand['schwuel_n']++; }
        if (!empty($e['zwang'])) { $stand['zwang_n']++; }
        if (!empty($e['sperre'])) { $stand['sperre_n']++; }
        if (!empty($e['vereist'])) { $stand['vereist_n']++; }
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

    /* Der Zeitstempel der WERTE wandert nur bei Erfolg. Hat kein Raum
     * geantwortet, bleibt der alte stehen - und ALTER waechst, wie es soll.
     * Bis 0.10.1 wurde er bedingungslos aufgefrischt und mass damit nur
     * noch, dass der Cron lief. */
    $stand['ts'] = ($mit_werten > 0)
        ? $jetzt
        : (isset($alt['ts']) ? (int) $alt['ts'] : 0);

    /* Der Rueckgabewert wird angesehen: schlaegt das Schreiben fehl, zeigte
     * die Oberflaeche sonst alte Zahlen als aktuelle. */
    if (!rk_json_schreiben($p['datadir'] . '/stand.json', $stand)) {
        $stand['meldungen']['stand'] = 'NICHT_GESPEICHERT';
    }
    if (!empty($cfg['verlauf_ein'])) { rk_verlauf_schreiben($verlauf); }

    /* Erst hier loesen: der Verlauf ist der Teil, um den es bei der Sperre
     * geht, und er wird eine Zeile darueber geschrieben. Das Versenden
     * darunter liest nur noch. */
    if (is_resource($sperre)) {
        @flock($sperre, LOCK_UN);
        @fclose($sperre);
    }

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
        'alter'      => (!empty($stand['ts'])) ? max(0, time() - (int) $stand['ts']) : -1,
        /* ---- Das Lebenszeichen, neu in 0.11.0 ----
         * Ueber MQTT gibt es kein "Alter", sondern nur einen Zeitstempel:
         * gesendet wird nur, wenn der Cron laeuft, und der Broker liefert
         * den letzten Wert mit Retain unbegrenzt weiter. 'alter' konnte
         * deshalb nie wachsen - gemessen ueber drei Cron-Laeufe blieb es
         * konstant bei 4. Der Miniserver rechnet selbst:
         *     Alter = (Loxone-Zeit + 1230768000) - ts
         * 'zaehler' beantwortet, was ein Zeitstempel nicht kann: eine Uhr,
         * die beim ersten Zeitabgleich springt, macht jedes Alter unbrauchbar,
         * eine umlaufende Zahl nicht. */
        'ts'         => isset($stand['ts']) ? (int) $stand['ts'] : 0,
        'lauf_ts'    => isset($stand['lauf_ts']) ? (int) $stand['lauf_ts'] : 0,
        'zaehler'    => isset($stand['zaehler']) ? (int) $stand['zaehler'] : 0,
        'ampellos'   => isset($stand['ampellos_n']) ? (int) $stand['ampellos_n'] : 0,
        'schwuel'    => isset($stand['schwuel_n']) ? (int) $stand['schwuel_n'] : 0,
        'zwang'      => isset($stand['zwang_n']) ? (int) $stand['zwang_n'] : 0,
        'sperre'     => isset($stand['sperre_n']) ? (int) $stand['sperre_n'] : 0,
        'vereist'    => isset($stand['vereist_n']) ? (int) $stand['vereist_n'] : 0,
        'heizfall'   => isset($stand['heizfall']) ? (int) $stand['heizfall'] : -1,
    );
    if (isset($stand['aussen_mittel']) && $stand['aussen_mittel'] !== null) {
        $w['aussen/mittel'] = round((float) $stand['aussen_mittel'], 2);
    }
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
        /* gewinn und kuehlgewinn stehen seit 0.11.3 in dieser Liste: sie
         * duerfen keinen fehlenden Wert als 0 veroeffentlichen, und ein
         * fehlendes Thema ist in dieser Linie die eingefuehrte Form dafuer
         * (spread und vlmin machen es seit jeher so). */
        foreach (array('t', 'rf', 'taupunkt', 'absolut', 'ober_t', 'ober_rf',
                       'spread', 'vlmin', 'enth', 'co2', 'gewinn',
                       'kuehlgewinn') as $f) {
            if (isset($e[$f]) && $e[$f] !== null) { $w[$z . $f] = $e[$f]; }
        }
        $w[$z . 'lueften'] = (int) $e['lueften'];
        $w[$z . 'schimmel'] = (int) $e['schimmel'];
        $w[$z . 'feucht'] = (int) $e['feucht'];
        $w[$z . 'trocken'] = (int) $e['trocken'];
        $w[$z . 'best_in'] = (int) $e['best_in'];
        $w[$z . 'best_std'] = (int) $e['best_std'];
        $w[$z . 'alter'] = isset($e['alter']) ? (int) $e['alter'] : -1;
        $w[$z . 'steht'] = isset($e['steht']) ? (int) $e['steht'] : 0;
        foreach (array('ampel', 'nass24', 'nass7t', 'kuehlen', 'kuehlgewinn',
                       'dauer', 'kosten', 'erfolg', 'eintrag', 'co2_hoch',
                       'fenster', 'fenster_zu',
                       /* ---- neu in 0.11.0, hinten angehaengt ---- */
                       'schwuel', 'trocknen', 'trockenrest', 'zuluft', 'wrg',
                       'fortluft', 'vereist', 'ruhe', 'zwang', 'trend',
                       'dusche', 'kuehlfrei', 'kbest_in', 'kbest_std',
                       'sperre', 'co2_anstieg', 'co2_erwartet', 'co2_voll',
                       'co2_lw') as $f) {
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
        /* ---- neu in 0.11.0 ---- */
        'ts'                 => 'MQTT.TS',
        'lauf_ts'            => 'MQTT.LAUF_TS',
        'zaehler'            => 'MQTT.ZAEHLER',
        'ampellos'           => 'MQTT.AMPELLOS',
        'schwuel'            => 'MQTT.SCHWUEL',
        'zwang'              => 'MQTT.ZWANG',
        'sperre'             => 'MQTT.SPERRE',
        'vereist'            => 'MQTT.VEREIST',
        'heizfall'           => 'MQTT.HEIZFALL',
        'aussen/mittel'      => 'MQTT.A_MITTEL',
        'raumN/schwuel'      => 'MQTT.R_SCHWUEL',
        'raumN/trocknen'     => 'MQTT.R_TROCKNEN',
        'raumN/trockenrest'  => 'MQTT.R_TROCKENREST',
        'raumN/zuluft'       => 'MQTT.R_ZULUFT',
        'raumN/wrg'          => 'MQTT.R_WRG',
        'raumN/fortluft'     => 'MQTT.R_FORTLUFT',
        'raumN/vereist'      => 'MQTT.R_VEREIST',
        'raumN/ruhe'         => 'MQTT.R_RUHE',
        'raumN/zwang'        => 'MQTT.R_ZWANG',
        'raumN/trend'        => 'MQTT.R_TREND',
        'raumN/dusche'       => 'MQTT.R_DUSCHE',
        'raumN/kuehlfrei'    => 'MQTT.R_KUEHLFREI',
        'raumN/kbest_in'     => 'MQTT.R_KBESTIN',
        'raumN/kbest_std'    => 'MQTT.R_KBESTSTD',
        'raumN/sperre'       => 'MQTT.R_SPERRE',
        'raumN/co2_anstieg'  => 'MQTT.R_CO2ANSTIEG',
        'raumN/co2_erwartet' => 'MQTT.R_CO2ERWARTET',
        'raumN/co2_voll'     => 'MQTT.R_CO2VOLL',
        'raumN/co2_lw'       => 'MQTT.R_CO2LW',
    );
}

/**
 * Der Abo-Hinweis in der Fassung, die zum Gateway passt - an EINER Stelle.
 *
 * Der Befund vom 28.08.2026: die Verzweigung nach Gatewayversion war
 * richtig gebaut, aber der Satz MQTT.ABO_HILFE stand DARUEBER und
 * unbedingt. Gemessen an der gerenderten Seite, drei Laeufe, beide
 * PHP-Fassungen:
 *
 *     Fassung    V1-Satz   V2-Satz   ABO_HILFE
 *     1          ja        nein      ja
 *     2          nein      ja        ja        <- der Widerspruch
 *     fehlt      ja        ja        ja
 *
 * Unter Gateway V2 gibt es den Eintrag nicht, den ABO_HILFE beschreibt -
 * der Kern schaltet die Knoepfe auf der Abonnement-Seite ab. Das ist
 * woertlich der Fall, den MGiSmart am 25.08.2026 gefunden hat: an einer
 * Stelle verzweigt, an der zweiten weiter unbedingt behauptet.
 *
 * Und der Satz nennt jetzt die GEMESSENE Fassung. Ist sie nicht lesbar,
 * stehen beide Faelle da und es wird gesagt, dass sie nicht feststellbar
 * war - ein Strich ist kein Haken, muss aber als Strich erkennbar sein.
 */
function rk_abo_text()
{
    $z = rk_mqtt_zustand();
    $f = (int) $z['fassung'];
    if ($f >= 2) {
        return rk_t('MQTT.ABO_V2') . ' '
             . sprintf(rk_t('MQTT.ABO_GEMESSEN'), $f);
    }
    if ($f === 1) {
        return rk_t('MQTT.ABO_PFLICHT') . ' ' . rk_t('MQTT.ABO_HILFE') . ' '
             . sprintf(rk_t('MQTT.ABO_GEMESSEN'), $f);
    }
    return rk_t('MQTT.ABO_UNBEKANNT') . ' ' . rk_t('MQTT.ABO_PFLICHT') . ' '
         . rk_t('MQTT.ABO_HILFE') . ' ' . rk_t('MQTT.ABO_V2');
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
        /* -1 heisst "keine Aussage moeglich", wie bei AMPEL. MinVal muss
         * deshalb -1 sein: mit 0 schneidet Loxone die -1 ab und zeigt
         * genau die 0, die hier vermieden werden soll. */
        'SCHIMMEL' => array('',      -1,   1, 'RK_FELD.SCHIMMEL', ''),
        'FEUCHT'   => array('',       0,   1, 'RK_FELD.FEUCHT',   ''),
        'TROCKEN'  => array('',       0,   1, 'RK_FELD.TROCKEN',  ''),
        'BESTIN'   => array('min',   -1, 2880, 'RK_FELD.BESTIN',  '<v.0> min'),
        'BESTSTD'  => array('h',     -1,  23, 'RK_FELD.BESTSTD',  '<v.0> h'),
        'SPREAD'   => array('K',    -40,  60, 'RK_FELD.SPREAD',   '<v.1> K'),
        'VLMIN'    => array('C',    -40,  40, 'RK_FELD.VLMIN',    '<v.1> °C'),
        'RALTER'   => array('s',     -1, 86400, 'RK_FELD.RALTER', '<v.0> s'),
        'STEHT'    => array('',       0,   1, 'RK_FELD.STEHT',    ''),
        'ENTH'     => array('kJ/kg',  0, 120, 'RK_FELD.ENTH',     '<v.1> kJ/kg'),
        /* -1 heisst "keine Aussage moeglich" - siehe rk_ampel(). MinVal
         * musste dafuer von 0 auf -1 wandern; Loxone zieht daraus die
         * Plausibilitaetsgrenze, und eine -1 unter einer Untergrenze 0
         * kaeme dort nicht an. */
        'AMPEL'    => array('',      -1,   3, 'RK_FELD.AMPEL',    ''),
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
        /* ---- Neu in 0.11.0. Hinten angehaengt - siehe der Satz oben. ---- */
        'SCHWUEL'  => array('',      -1,   1, 'RK_FELD.SCHWUEL',  ''),
        'TROCKNEN' => array('g/h', -5000, 5000, 'RK_FELD.TROCKNEN', '<v.0> g/h'),
        'TROCKENREST' => array('h',  -1, 240, 'RK_FELD.TROCKENREST', '<v.1> h'),
        'ZULUFT'   => array('C',    -30,  60, 'RK_FELD.ZULUFT',   '<v.1> °C'),
        'WRG'      => array('%',     -1, 100, 'RK_FELD.WRG',      '<v.0> %'),
        'FORTLUFT' => array('C',    -60,  60, 'RK_FELD.FORTLUFT', '<v.1> °C'),
        'VEREIST'  => array('',       0,   1, 'RK_FELD.VEREIST',  ''),
        'RUHE'     => array('',      -1,   1, 'RK_FELD.RUHE',     ''),
        'ZWANG'    => array('',       0,   1, 'RK_FELD.ZWANG',    ''),
        'TREND'    => array('g/m3h', -20,  20, 'RK_FELD.TREND',   '<v.2> g/m³h'),
        'DUSCHE'   => array('',       0,   1, 'RK_FELD.DUSCHE',   ''),
        'KUEHLFREI' => array('',     -1,   1, 'RK_FELD.KUEHLFREI', ''),
        'KBESTIN'  => array('min',   -1, 2880, 'RK_FELD.KBESTIN', '<v.0> min'),
        'KBESTSTD' => array('h',     -1,  23, 'RK_FELD.KBESTSTD', '<v.0> h'),
        'SPERRE'   => array('',       0,   1, 'RK_FELD.SPERRE',   ''),
        /* ---- CO2 mit Personenzahl, 28.08.2026 ---- */
        'CO2ANSTIEG'  => array('ppm/h', -5000, 5000, 'RK_FELD.CO2ANSTIEG', '<v.0> ppm/h'),
        'CO2ERWARTET' => array('ppm/h', 0, 5000, 'RK_FELD.CO2ERWARTET', '<v.0> ppm/h'),
        'CO2VOLL'  => array('min',   -1, 2880, 'RK_FELD.CO2VOLL',  '<v.0> min'),
        'CO2LW'    => array('1/h',    0,  50, 'RK_FELD.CO2LW',     '<v.2> 1/h'),
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
    /* Die Kurznamen muessen EINDEUTIG sein. Bis 0.11.2 wurde nach zwoelf
     * Zeichen abgeschnitten, ohne nachzusehen: "Kinderzimmer Nord" und
     * "Kinderzimmer Sued" ergaben beide KINDERZIMMER, und die erzeugte
     * Vorlage trug 50 Titel doppelt. Die Werte blieben richtig - die
     * Befehlserkennung unterscheidet ;R1T= von ;R3T= -, aber in Loxone
     * Config waren die Eingaenge nicht mehr auseinanderzuhalten, und
     * beide Zahlen sehen aus wie eine Temperatur. Gemessen am 05.09.2026
     * an der erzeugten Datei: 226 Befehle, 176 verschiedene Titel.
     * Bei Gleichheit haengt die Raumnummer an - sie ist ohnehin die
     * Nummer, unter der der Raum in der Antwortzeile steht. */
    $vergeben = array();
    foreach (rk_raeume() as $nr => $r) {
        $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $r['name']));
        if ($kurz === '') { $kurz = 'RAUM' . $nr; }
        $kurz = substr($kurz, 0, 12);
        if (isset($vergeben[$kurz])) { $kurz = substr($kurz, 0, 9) . '_' . $nr; }
        $vergeben[$kurz] = true;
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
        /* MinVal -1, nicht 0: rk_zeile() liefert -1, solange nie eine
         * Messung gelang, und Loxone schnitte das an der Untergrenze ab -
         * dort staende dann 0, also "gerade eben gemessen". Genau die
         * stille Falschaussage, fuer die 0.11.0 bei AMPEL und SCHIMMEL
         * MinVal -1 eingefuehrt hat. Und die Obergrenze deckelte das
         * Wachstum bei 24 Stunden, waehrend die README sagt, es wachse -
         * 100 Tage sind die ehrlichere Schranke. */
        'ALTER' => array('s',   -1, 8640000, 'RK_FELD.ALTER', '<v.0> s'),
        'OK'   => array('',      0, 1, 'RK_FELD.OK', ''),
        'NOHNE' => array('',     0, 99, 'RK_FELD.NOHNE', ''),
        'NSTEHT' => array('',    0, 99, 'RK_FELD.NSTEHT', ''),
        'NKUEHL' => array('',    0, 99, 'RK_FELD.NKUEHL', ''),
        'NCO2'  => array('',     0, 99, 'RK_FELD.NCO2', ''),
        'NFENSTER' => array('',  0, 99, 'RK_FELD.NFENSTER', ''),
        /* NFEUCHT und NTROCKEN standen seit jeher in der Antwortzeile und
         * gingen ueber MQTT hinaus - nur hier fehlten sie, und wer die
         * erzeugte Importdatei einlas, bekam sie deshalb nicht. Gemessen
         * am 28.08.2026 durch einen Mengenvergleich zwischen rk_zeile()
         * und dieser Liste. */
        'NFEUCHT' => array('',   0, 99, 'RK_FELD.NFEUCHT', ''),
        'NTROCKEN' => array('',  0, 99, 'RK_FELD.NTROCKEN', ''),
        /* ---- Neu in 0.11.0 ---- */
        'ZAEHLER' => array('',   0, 999, 'RK_FELD.ZAEHLER', ''),
        'TS'      => array('s',  0, 2147483647, 'RK_FELD.TS', '<v.0>'),
        'LAUFTS'  => array('s',  0, 2147483647, 'RK_FELD.LAUFTS', '<v.0>'),
        'NAMPELLOS' => array('', 0, 99, 'RK_FELD.NAMPELLOS', ''),
        'NSCHWUEL' => array('',  0, 99, 'RK_FELD.NSCHWUEL', ''),
        'NZWANG'  => array('',   0, 99, 'RK_FELD.NZWANG', ''),
        'NSPERRE' => array('',   0, 99, 'RK_FELD.NSPERRE', ''),
        'NVEREIST' => array('',  0, 99, 'RK_FELD.NVEREIST', ''),
        'HEIZFALL' => array('', -1,  1, 'RK_FELD.HEIZFALL', ''),
        'AMITTEL' => array('C', -50, 60, 'RK_FELD.AMITTEL', '<v.1> °C'),
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
        /* ---- neu in 0.11.0 ---- */
        array('RK_ZAEHLER',                'RK_FELD.ZAEHLER'),
        array('RK_NAMPELLOS',              'RK_FELD.NAMPELLOS'),
        array('RK_' . $kurz . '_AMPEL',    'RK_FELD.AMPEL'),
        array('RK_' . $kurz . '_SPERRE',   'RK_FELD.SPERRE'),
        array('RK_' . $kurz . '_RUHE',     'RK_FELD.RUHE'),
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
        /* ---- neu in 0.11.0 ---- */
        array($b(9), rk_t('LOX.B_NICHT'), sprintf(rk_t('LOX.BN_SPERRE'), $rname),
              rk_t('LOX.BP_KEINE'), $vf('RK_' . $kurz . '_SPERRE')),
        array($b(10), rk_t('LOX.B_UND'), sprintf(rk_t('LOX.BN_FREIGABE'), $rname),
              rk_t('LOX.BP_KEINE'),
              sprintf(rk_t('LOX.BE_FREIGABE'), $vf('RK_' . $kurz . '_LUEFTEN'), $vb(9))),
        array($b(11), rk_t('LOX.B_SCHWELL'), rk_t('LOX.BN_ZAEHLER_STEHT'),
              rk_t('LOX.BP_ZAEHLER'), $vf('RK_ZAEHLER')),
    );

    $hinweise = array(
        array($vb(1), rk_t('LOX.ZU_ABRUF_ALT')),
        array($vb(4), rk_t('LOX.ZU_SCHIMMEL')),
        array($vb(5), rk_t('LOX.ZU_ODER')),
        array($vb(6), rk_t('LOX.ZU_BENACHR')),
        array($vb(8), rk_t('LOX.ZU_VLMIN')),
        array($vb(10), rk_t('LOX.ZU_FREIGABE')),
        array($vb(11), rk_t('LOX.ZU_ZAEHLER')),
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
        (!empty($stand['ts'])) ? max(0, time() - (int) $stand['ts']) : -1,
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
    /* ---- Neu in 0.11.0, HINTEN angehaengt ----
     * ZAEHLER und TS sind das Lebenszeichen: ALTER allein kann einen toten
     * Cron nicht anzeigen, wenn niemand mehr fragt. Ueber HTTP fragt der
     * Miniserver zwar selbst, und ALTER wird zur Lesezeit gerechnet - aber
     * ein Zaehler, der stehenbleibt, ist die eindeutigere Aussage, und die
     * Hausregel verlangt alle drei. */
    $o .= ';ZAEHLER=' . (isset($stand['zaehler']) ? (int) $stand['zaehler'] : 0);
    $o .= ';TS=' . (isset($stand['ts']) ? (int) $stand['ts'] : 0);
    $o .= ';LAUFTS=' . (isset($stand['lauf_ts']) ? (int) $stand['lauf_ts'] : 0);
    $o .= ';NAMPELLOS=' . (isset($stand['ampellos_n']) ? (int) $stand['ampellos_n'] : 0);
    $o .= ';NSCHWUEL=' . (isset($stand['schwuel_n']) ? (int) $stand['schwuel_n'] : 0);
    $o .= ';NZWANG=' . (isset($stand['zwang_n']) ? (int) $stand['zwang_n'] : 0);
    $o .= ';NSPERRE=' . (isset($stand['sperre_n']) ? (int) $stand['sperre_n'] : 0);
    $o .= ';NVEREIST=' . (isset($stand['vereist_n']) ? (int) $stand['vereist_n'] : 0);
    $o .= ';HEIZFALL=' . (isset($stand['heizfall']) ? (int) $stand['heizfall'] : -1);
    $o .= ';AMITTEL=' . $w(isset($stand['aussen_mittel']) ? $stand['aussen_mittel'] : null);
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
                       'FENSTER' => 'fenster', 'FENSTERZU' => 'fenster_zu',
                       /* ---- neu in 0.11.0, hinten angehaengt ---- */
                       'SCHWUEL' => 'schwuel', 'TROCKNEN' => 'trocknen',
                       'TROCKENREST' => 'trockenrest', 'ZULUFT' => 'zuluft',
                       'WRG' => 'wrg', 'FORTLUFT' => 'fortluft',
                       'VEREIST' => 'vereist', 'RUHE' => 'ruhe',
                       'ZWANG' => 'zwang', 'TREND' => 'trend',
                       'DUSCHE' => 'dusche', 'KUEHLFREI' => 'kuehlfrei',
                       'KBESTIN' => 'kbest_in', 'KBESTSTD' => 'kbest_std',
                       'SPERRE' => 'sperre',
                       'CO2ANSTIEG' => 'co2_anstieg',
                       'CO2ERWARTET' => 'co2_erwartet',
                       'CO2VOLL' => 'co2_voll', 'CO2LW' => 'co2_lw');
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
 * ------------------------------------------------------------------
 * Die Zugangsdaten kommen SEPARAT zurueck, nicht in der Konfiguration
 * ------------------------------------------------------------------
 *
 * Seit dem 28.08.2026 darf die Sicherung auf Wunsch Benutzername und
 * Passwort der Sensorquelle mitfuehren. Sie duerfen aber auf keinen Fall in
 * raumklima.json landen: die Datei liegt mit 0600 im Konfigordner, die
 * Zugangsdaten gehoeren in geheim.json daneben, und wer beides vermischt,
 * schreibt ein Passwort in eine Datei, die es nie tragen sollte.
 *
 * Deshalb der vierte Rueckgabewert. $neu bleibt reine Konfiguration.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 *                  Zugangsdaten|null).
 */
function rk_sicherung_lesen($roh)
{
    $mangel = array();
    $zugang = null;
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(rk_t('EINST.SICH_KEIN_JSON')), 0, null);
    }
    $neu = rk_vorgaben();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        /* Die Zugangsdaten sind KEINE Einstellung - sie wandern an
         * $neu vorbei und werden getrennt zurueckgegeben. */
        if ($k === 'zugang') {
            list($z, $f) = rk_wert_pruefen('zugang', $w);
            if ($f !== '') { $mangel[] = rk_sicherung_mangeltext($k, '', $f); continue; }
            $zugang = $z;
            $anzahl++;
            continue;
        }
        /* Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
         *
         * Ohne diese zwei Zeilen weist die Funktion die Datei ab, die zwei
         * Zeilen vorher dieselbe Bibliothek erzeugt hat. Vorab gemessen am
         * 28.08.2026, bevor der Kopf ueberhaupt eingebaut war:
         *   ABGEWIESEN: Unbekannte Einstellung in der Datei: _hinweis
         *   ABGEWIESEN: Unbekannte Einstellung in der Datei: _stand
         * Wer der Sicherungsdatei etwas hinzufuegt, das keine Einstellung
         * ist, ergaenzt im selben Zug die Leseseite. */
        if ($k !== '' && $k[0] === '_') { continue; }

        list($wert, $fehler) = rk_wert_pruefen($k, $w);
        if ($fehler !== '') {
            $mangel[] = rk_sicherung_mangeltext($k, $w, $fehler);
            continue;
        }
        $neu[$k] = $wert;
        $anzahl++;
    }
    /* Nur wenn wirklich nichts Bekanntes DRINSTAND. Standen bekannte
     * Schluessel da und wurden ihre Werte beanstandet, ist $anzahl
     * ebenfalls 0 - der Satz waere dann unwahr. */
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = rk_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $mangel ? null : $zugang);
}

/** Der Klartext zu einer Beanstandung - mit Schluessel UND Wert. */
function rk_sicherung_mangeltext($k, $w, $fehler)
{
    $ks = htmlspecialchars($k, ENT_QUOTES, 'UTF-8');
    if (is_array($w) || is_object($w)) { $ws = '(Feld)'; }
    elseif (is_bool($w)) { $ws = $w ? 'true' : 'false'; }
    elseif ($w === null) { $ws = 'null'; }
    else { $ws = htmlspecialchars(substr((string) $w, 0, 60), ENT_QUOTES, 'UTF-8'); }
    if ($fehler === 'EINST.SICH_FREMD') {
        return sprintf(rk_t('EINST.SICH_FREMD'), $ks);
    }
    return sprintf(rk_t('EINST.SICH_WERT'), $ks, $ws);
}

/**
 * Die Sicherungsdatei bauen - VOLLSTAENDIG und mit lesbarem Kopf.
 *
 * Punkt 1 und 2 der Hausvorgabe: geschrieben werden ALLE Schluessel aus
 * rk_vorgaben(), nicht nur die abweichenden - ein Schluessel, der in der
 * Sicherung fehlt, kaeme beim Zurueckspielen aus der Vorgabe, und das ist
 * genau dann falsch, wenn der Anwender ihn bewusst auf den Vorgabewert
 * gesetzt hatte und die Vorgabe sich spaeter aendert.
 *
 * Der Kopf traegt ein Unterstrich-Praefix; rk_sicherung_lesen() uebergeht
 * genau diese Schluessel.
 *
 * Der AKTIONSTOKEN gehoert hinein: ohne ihn stuenden nach dem Zurueckspielen
 * alle Felder richtig, und das Plugin kaeme trotzdem nicht an die Anlage.
 *
 * Die ZUGANGSDATEN der Quelle kommen nur mit, wenn $zugang gesetzt ist -
 * also wenn der Bediener den Haken gesetzt hat. Ab Werk ist er aus: ein
 * Passwort geht nicht ungefragt in eine Datei, die jemand herunterlaedt.
 * Sie stehen unter dem Schluessel 'zugang' und wandern beim Zurueckspielen
 * nach geheim.json, NICHT in raumklima.json.
 *
 * Der Kopf sagt, was wirklich drin ist - nicht, was ueblicherweise drin
 * waere. Ein Hinweistext, der bei gesetztem Haken behauptet, es seien keine
 * Zugangsdaten enthalten, waere die naechste stille Falschaussage.
 */
function rk_sicherung_bauen($cfg, $zugang = null)
{
    $mit = is_array($zugang)
        && (trim((string) $zugang['benutzer']) !== ''
            || (string) $zugang['passwort'] !== '');
    $kopf = array(
        '_hinweis' => 'Sicherung des LoxBerry-Plugins Raumklima. Sie enthaelt das'
            . ' Aktionstoken dieser Anlage'
            . ($mit ? ' UND Benutzername und Passwort der Sensorquelle.'
                    : '. Zugangsdaten der Sensorquelle sind NICHT enthalten.')
            . ' Wie ein Passwort behandeln.',
        '_plugin'  => 'raumklima',
        '_fassung' => rk_fassung(),
        '_stand'   => date('Y-m-d H:i:s'),
        '_zugangsdaten' => $mit ? 'ja' : 'nein',
    );
    $voll = array();
    foreach (rk_vorgaben() as $k => $v) {
        $voll[$k] = array_key_exists($k, $cfg) ? $cfg[$k] : $v;
    }
    if ($mit) {
        $voll['zugang'] = array('benutzer' => (string) $zugang['benutzer'],
                                'passwort' => (string) $zugang['passwort']);
    }
    return json_encode($kopf + $voll,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Die Fassungsnummer aus der plugin.cfg - an EINER Stelle.
 *
 * parse_ini_file() scheitert an dieser Datei (Hausregel), deshalb zeilenweise.
 * Eine Zahl im Quelltext waere die naechste, die beim Release vergessen wird.
 */
function rk_fassung()
{
    static $v = null;
    if ($v !== null) { return $v; }
    $v = '';
    /* Drei Kandidaten, nicht zwei. Installiert liegt diese Datei unter
     * <home>/webfrontend/html/plugins/<ordner>/; der erste Kandidat zeigt
     * dann auf <home>/webfrontend/html/plugin.cfg, wo keine liegt, und der
     * zweite setzt eine plugin.cfg im Konfigordner voraus, die weder das
     * Archiv mitbringt noch postinstall.sh ablegt. Ergebnis war ein leerer
     * Fassungseintrag im Kopf jeder Sicherungsdatei - und auffallen konnte
     * es nicht, weil im ausgepackten Archiv der erste Kandidat trifft.
     * Der dritte liest die Plugin-Datenbank, die LoxBerry selbst fuehrt. */
    foreach (array(dirname(dirname(__DIR__)) . '/plugin.cfg',
                   rk_paths()['home'] . '/config/plugins/'
                   . rk_paths()['plugin'] . '/plugin.cfg',
                   __DIR__ . '/plugin.cfg') as $p) {
        if (!is_file($p)) { continue; }
        foreach (file($p, FILE_IGNORE_NEW_LINES) ?: array() as $z) {
            if (preg_match('/^\s*VERSION\s*=\s*([0-9][0-9A-Za-z.\-]*)/', $z, $m)) {
                $v = $m[1];
                break 2;
            }
        }
    }
    if ($v === '') { $v = rk_fassung_aus_datenbank(); }
    return $v;
}

/** Die Fassung aus der Plugin-Datenbank, die LoxBerry selbst fuehrt. */
function rk_fassung_aus_datenbank()
{
    $p = rk_paths();
    if ($p['home'] === '') { return ''; }
    $d = rk_json_lesen($p['home'] . '/data/system/plugindatabase.json');
    if (!isset($d['plugins']) || !is_array($d['plugins'])) { return ''; }
    foreach ($d['plugins'] as $e) {
        if (!is_array($e)) { continue; }
        $ord = isset($e['directories']['lbpplugindir'])
            ? (string) $e['directories']['lbpplugindir']
            : (isset($e['folder']) ? (string) $e['folder'] : '');
        if ($ord !== '' && $ord === $p['plugin'] && isset($e['version'])) {
            return (string) $e['version'];
        }
    }
    return '';
}
