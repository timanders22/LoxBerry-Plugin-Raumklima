<?php
/**
 * Raumklima - der Rechenkern
 *
 * Reine Rechnung: kein Netz, keine Dateien, keine Uhr ausser dem
 * uebergebenen Zeitpunkt. Deshalb laesst sich alles hier durchpruefen -
 * rk_selbsttest() rechnet 51 Faelle nach, darunter Lehrbuchwerte fuer
 * Taupunkt und absolute Feuchte.
 *
 * Die Zahl steht hier nur als Anhaltspunkt. Massgeblich ist, was der
 * Selbsttest selbst zaehlt und ausgibt - er zaehlt die Faelle beim Laufen,
 * nicht aus dieser Zeile.
 *
 * ------------------------------------------------------------------
 * Die Formeln, und woher sie stammen
 * ------------------------------------------------------------------
 *
 * Saettigungsdampfdruck nach der Magnus-Formel in der Fassung von Sonntag
 * (1990), so wie sie die WMO fuehrt - fuer Wasser ueber 0 Grad und
 * unterkuehlt darunter:
 *
 *     e_s(T) = 6.112 hPa * exp( 17.62 * T / (243.12 + T) )
 *
 * Daraus der Dampfdruck bei relativer Feuchte:  e = e_s * rF/100
 * Daraus der Taupunkt durch Umstellen:
 *
 *     Td = 243.12 * ln(e/6.112) / (17.62 - ln(e/6.112))
 *
 * Und die absolute Feuchte aus der Gasgleichung für Wasserdampf:
 *
 *     AF = 216.679 * e / (273.15 + T)      in g/m3, e in hPa
 *
 * Die Zahl 216.679 ist 100 * M / R = 100 * 18.016 / 8.3145 - keine
 * angepasste Konstante, sondern zwei Naturkonstanten und ein Faktor fuer
 * die Einheit.
 *
 * Nachgerechnete Stichproben (im Selbsttest hinterlegt):
 *   20 C / 50 %  ->  Taupunkt  9.26 C,  absolut  8.62 g/m3
 *    5 C / 90 %  ->  Taupunkt  3.50 C,  absolut  6.11 g/m3
 *   -5 C / 95 %  ->  Taupunkt -5.68 C,  absolut  3.24 g/m3
 *
 * ------------------------------------------------------------------
 * Warum absolute Feuchte und nicht Taupunkt
 * ------------------------------------------------------------------
 *
 * Zum Lueften taugt der Vergleich der ABSOLUTEN Feuchte, nicht der
 * relativen: 90 % bei 5 Grad draussen sind 6,1 g/m3, 50 % bei 20 Grad
 * drinnen sind 8,6 g/m3. Die kalte, "feuchte" Aussenluft ist also
 * trockener und nimmt beim Aufwaermen Feuchte auf. Wer nach relativer
 * Feuchte lueftet, macht es im Winter genau falsch herum.
 *
 * Der Taupunktvergleich fuehrt zum selben Ergebnis - absolute Feuchte und
 * Taupunkt sind ineinander umrechenbar. Gerechnet wird hier trotzdem in
 * g/m3, weil die Zahl anschaulich ist: "es gehen 2,5 Gramm je Kubikmeter
 * raus" versteht man, "der Taupunkt liegt 5 Kelvin tiefer" weniger.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

define('RK_KERN', '1.0.0');

/* ==================================================================
 * Grundgroessen
 * ================================================================== */

/** Saettigungsdampfdruck in hPa. */
function rk_es($t)
{
    $t = (float) $t;
    return 6.112 * exp(17.62 * $t / (243.12 + $t));
}

/**
 * Dampfdruck in hPa aus Temperatur und relativer Feuchte.
 * Der Name ist ausgeschrieben und nicht 'rk_e': dieses Kuerzel gehoert im
 * Hausstandard dem HTML-Maskierer, und eine Kollision faellt erst beim
 * Rendern auf - als Fatal error mit weisser Seite.
 */
function rk_dampfdruck($t, $rf)
{
    return rk_es($t) * max(0.0, min(100.0, (float) $rf)) / 100.0;
}

/**
 * Taupunkt in Grad Celsius.
 * Rueckgabe null, wenn die Eingabe unbrauchbar ist - nicht 0. Eine Null
 * waere ein plausibler Taupunkt und damit eine stille Falschaussage.
 */
function rk_taupunkt($t, $rf)
{
    if (!is_numeric($t) || !is_numeric($rf)) { return null; }
    $rf = (float) $rf;
    if ($rf <= 0 || $rf > 100) { return null; }
    $e = rk_dampfdruck($t, $rf);
    if ($e <= 0) { return null; }
    $l = log($e / 6.112);
    $n = 17.62 - $l;
    if (abs($n) < 1e-9) { return null; }
    return round(243.12 * $l / $n, 2);
}

/** Absolute Feuchte in g/m3. Rueckgabe null bei unbrauchbarer Eingabe. */
function rk_absolut($t, $rf)
{
    if (!is_numeric($t) || !is_numeric($rf)) { return null; }
    $rf = (float) $rf;
    if ($rf < 0 || $rf > 100) { return null; }
    return round(216.679 * rk_dampfdruck($t, $rf) / (273.15 + (float) $t), 3);
}

/**
 * Relative Feuchte, die dieselbe absolute Feuchte bei einer anderen
 * Temperatur ergibt. Damit laesst sich fragen: "Was wird aus der
 * Aussenluft, wenn sie sich im Zimmer auf 20 Grad erwaermt?"
 */
function rk_rf_bei($t_neu, $t_alt, $rf_alt)
{
    $es_neu = rk_es($t_neu);
    if ($es_neu <= 0) { return null; }
    return round(min(100.0, rk_dampfdruck($t_alt, $rf_alt) / $es_neu * 100.0), 1);
}

/* ==================================================================
 * Schimmelrisiko
 *
 * Schimmel waechst nicht am Taupunkt, sondern deutlich davor: ab etwa
 * 80 % relativer Feuchte AN DER OBERFLAECHE, dauerhaft. Massgeblich ist
 * also nicht die Raumluft, sondern die kaelteste Wand.
 *
 * Deren Temperatur laesst sich ohne Messung nur schaetzen. Dafuer gibt es
 * den TEMPERATURFAKTOR fRsi: die Oberflaeche liegt um fRsi mal die
 * Temperaturdifferenz ueber der Aussentemperatur.
 *
 *     T_oberflaeche = T_aussen + fRsi * (T_innen - T_aussen)
 *
 * fRsi = 1 waere eine perfekt gedaemmte Wand (Oberflaeche = Raumluft),
 * fRsi = 0 eine, die die Aussentemperatur annimmt. Die DIN 4108-2 nennt
 * 0,70 als Mindestwert fuer Neubauten an Waermebruecken; Altbau-Ecken
 * liegen darunter.
 *
 * DIESER FAKTOR IST EINE EINSTELLUNG, KEINE MESSUNG. Er steht deshalb in
 * der Oberflaeche je Raum und nicht fest im Code, und die Hilfe sagt
 * ausdruecklich, dass eine Schaetzung eine Schaetzung bleibt. Wer es genau
 * wissen will, klebt einen Temperaturfuehler in die kalte Ecke und traegt
 * ihn als eigenen Raum ein - dann rechnet das Plugin mit dem Messwert.
 * ================================================================== */

/** Oberflaechentemperatur der kaeltesten Stelle, geschaetzt. */
function rk_oberflaeche($t_innen, $t_aussen, $frsi)
{
    if (!is_numeric($t_innen) || !is_numeric($t_aussen)) { return null; }
    $f = max(0.05, min(1.0, (float) $frsi));
    return round((float) $t_aussen + $f * ((float) $t_innen - (float) $t_aussen), 2);
}

/**
 * Relative Feuchte an dieser Oberflaeche.
 * Ueber 80 % gilt als Schimmelgefahr, ueber 100 % faellt Wasser aus.
 */
function rk_rf_oberflaeche($t_innen, $rf_innen, $t_aussen, $frsi)
{
    $to = rk_oberflaeche($t_innen, $t_aussen, $frsi);
    if ($to === null) { return null; }
    return rk_rf_bei($to, $t_innen, $rf_innen);
}

/* ==================================================================
 * Lueftungsempfehlung
 * ================================================================== */

/**
 * Lohnt sich Lueften mit dieser Aussenluft?
 *
 * Drei Bedingungen, alle drei muessen erfuellt sein:
 *
 *   1. Die Aussenluft ist TROCKENER - absolut, nicht relativ. Und zwar um
 *      mindestens $mindest g/m3; ohne diesen Abstand schaltet die Empfehlung
 *      bei jedem Messrauschen um.
 *   2. Es ist draussen nicht zu kalt. Bei -10 Grad ist Lueften zwar
 *      wirksam, aber teuer - die Grenze gehoert dem Bewohner, nicht dem
 *      Plugin.
 *   3. Die Raumluft ist nicht schon trockener als der Zielkorridor. Im
 *      Winter ist zu trockene Luft das haeufigere Problem, und dagegen
 *      hilft Lueften nicht, sondern es macht es schlimmer.
 *
 * Rueckgabe: array('lohnt' => 0/1, 'gewinn' => g/m3, 'grund' => Kuerzel)
 */
function rk_lueften($af_innen, $t_aussen, $rf_aussen, $mindest, $t_min, $af_unter)
{
    $af_aussen = rk_absolut($t_aussen, $rf_aussen);
    $leer = array('lohnt' => 0, 'gewinn' => 0.0, 'grund' => 'keine_daten');
    if ($af_innen === null || $af_aussen === null) { return $leer; }

    $gewinn = round($af_innen - $af_aussen, 3);
    if ($af_unter !== null && $af_innen < $af_unter) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'zu_trocken');
    }
    if ((float) $t_aussen < (float) $t_min) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'zu_kalt');
    }
    if ($gewinn < (float) $mindest) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'aussen_feuchter');
    }
    return array('lohnt' => 1, 'gewinn' => $gewinn, 'grund' => 'lohnt');
}

/**
 * Der beste Zeitpunkt in den naechsten Stunden.
 *
 * DAS IST DER EIGENTLICHE ZWECK DES PLUGINS. Die Momentaufnahme "aussen ist
 * gerade trockener" kann jede Loxone-Formel; sie sagt aber nicht, ob es in
 * drei Stunden noch gilt oder ob man besser bis 16 Uhr wartet.
 *
 * $vorhersage ist array(ts => array('t' => Grad, 'rf' => Prozent)),
 * aufsteigend. Gesucht wird die Scheibe mit dem groessten Gewinn.
 *
 * Rueckgabe: array('jetzt'=>0/1, 'ts'=>Zeitstempel|null, 'in'=>Minuten|-1,
 *                  'gewinn'=>g/m3, 'grund'=>Kuerzel)
 */
function rk_bester_zeitpunkt($af_innen, $vorhersage, $jetzt, $mindest, $t_min,
                             $af_unter, $stunden = 12)
{
    $erg = array('jetzt' => 0, 'ts' => null, 'in' => -1, 'gewinn' => 0.0,
                 'grund' => 'keine_vorhersage');
    if (!is_array($vorhersage) || !$vorhersage || $af_innen === null) { return $erg; }
    $ende = (int) $jetzt + max(1, (int) $stunden) * 3600;
    $best = null;
    foreach ($vorhersage as $ts => $w) {
        $ts = (int) $ts;
        if ($ts < (int) $jetzt || $ts >= $ende) { continue; }
        if (!is_array($w) || !isset($w['t'], $w['rf'])) { continue; }
        $l = rk_lueften($af_innen, $w['t'], $w['rf'], $mindest, $t_min, $af_unter);
        if (!$l['lohnt']) { continue; }
        if ($best === null || $l['gewinn'] > $best[1]) { $best = array($ts, $l['gewinn']); }
    }
    if ($best === null) {
        $erg['grund'] = 'kein_fenster';
        return $erg;
    }
    $erg['ts'] = $best[0];
    $erg['gewinn'] = round($best[1], 3);
    $erg['in'] = (int) round(($best[0] - (int) $jetzt) / 60);
    $erg['jetzt'] = $erg['in'] < 60 ? 1 : 0;
    $erg['grund'] = 'gefunden';
    return $erg;
}

/* ==================================================================
 * Ein Raum, vollstaendig gerechnet
 * ================================================================== */

/**
 * $raum    array('name','t','rf','frsi','soll_min','soll_max')
 * $aussen  array('t','rf')          - der Messwert von jetzt
 * $vorher  array(ts => array('t','rf')) - die Vorhersage, oder leer
 * $cfg     array('mindest','t_min','af_unter','vorschau')
 *
 * Rueckgabe: ein Feld mit allen Werten, die nach Loxone gehen.
 */
function rk_raum_rechnen($raum, $aussen, $vorher, $cfg, $jetzt)
{
    $t = isset($raum['t']) ? $raum['t'] : null;
    $rf = isset($raum['rf']) ? $raum['rf'] : null;

    $e = array(
        'name'      => isset($raum['name']) ? (string) $raum['name'] : '',
        'ok'        => 0,
        't'         => is_numeric($t) ? round((float) $t, 2) : null,
        'rf'        => is_numeric($rf) ? round((float) $rf, 1) : null,
        'taupunkt'  => rk_taupunkt($t, $rf),
        'absolut'   => rk_absolut($t, $rf),
        'ober_t'    => null,
        'ober_rf'   => null,
        'schimmel'  => 0,
        'lueften'   => 0,
        'gewinn'    => 0.0,
        'grund'     => 'keine_daten',
        'best_in'   => -1,
        'best_std'  => -1,
        'trocken'   => 0,
        'feucht'    => 0,
    );
    if ($e['absolut'] === null) { return $e; }
    $e['ok'] = 1;

    // Zielkorridor der Raumfeuchte
    $min = isset($raum['soll_min']) ? (float) $raum['soll_min'] : 0;
    $max = isset($raum['soll_max']) ? (float) $raum['soll_max'] : 0;
    if ($min > 0 && $e['rf'] !== null && $e['rf'] < $min) { $e['trocken'] = 1; }
    if ($max > 0 && $e['rf'] !== null && $e['rf'] > $max) { $e['feucht'] = 1; }

    $ta = isset($aussen['t']) ? $aussen['t'] : null;
    $ra = isset($aussen['rf']) ? $aussen['rf'] : null;

    if (is_numeric($ta)) {
        $e['ober_t'] = rk_oberflaeche($t, $ta, isset($raum['frsi']) ? $raum['frsi'] : 0.7);
        $e['ober_rf'] = rk_rf_oberflaeche($t, $rf, $ta, isset($raum['frsi']) ? $raum['frsi'] : 0.7);
        if ($e['ober_rf'] !== null && $e['ober_rf'] >= 80.0) { $e['schimmel'] = 1; }
    }

    $af_unter = (isset($cfg['af_unter']) && $cfg['af_unter'] > 0) ? (float) $cfg['af_unter'] : null;
    $l = rk_lueften($e['absolut'], $ta, $ra,
        isset($cfg['mindest']) ? $cfg['mindest'] : 0.5,
        isset($cfg['t_min']) ? $cfg['t_min'] : -5,
        $af_unter);
    $e['lueften'] = $l['lohnt'];
    $e['gewinn'] = $l['gewinn'];
    $e['grund'] = $l['grund'];

    $b = rk_bester_zeitpunkt($e['absolut'], $vorher, $jetzt,
        isset($cfg['mindest']) ? $cfg['mindest'] : 0.5,
        isset($cfg['t_min']) ? $cfg['t_min'] : -5,
        $af_unter,
        isset($cfg['vorschau']) ? $cfg['vorschau'] : 12);
    $e['best_in'] = $b['in'];
    $e['best_std'] = ($b['ts'] !== null) ? (int) date('G', $b['ts']) : -1;
    if (!$e['lueften'] && $b['jetzt']) {
        // Die Vorhersage sagt "jetzt", die Momentaufnahme nicht. Das kommt
        // vor, wenn die Vorhersage fuer die laufende Stunde gilt und der
        // Messwert von eben etwas anderes sagt. Der Messwert gewinnt.
        $e['best_in'] = $b['in'];
    }
    return $e;
}

/* ==================================================================
 * Vorhersage von Open-Meteo
 *
 * Kostenlos, ohne Konto, ohne Schluessel. Gebraucht werden genau zwei
 * Reihen: temperature_2m und relative_humidity_2m. Mehr wird nicht
 * abgefragt - eine Anfrage, die weniger holt, ist schneller und faellt
 * seltener aus.
 *
 * Hier steht nur das AUSWERTEN, damit es ohne Netz pruefbar bleibt.
 * ================================================================== */

/** Die Abrufadresse. Bewusst eine Funktion: so steht sie genau einmal. */
function rk_meteo_url($breite, $laenge, $tage = 2)
{
    return 'https://api.open-meteo.com/v1/forecast'
        . '?latitude=' . rawurlencode(number_format((float) $breite, 4, '.', ''))
        . '&longitude=' . rawurlencode(number_format((float) $laenge, 4, '.', ''))
        . '&hourly=temperature_2m,relative_humidity_2m'
        . '&forecast_days=' . max(1, min(7, (int) $tage))
        . '&timezone=auto';
}

/**
 * Die Antwort auswerten.
 * Rueckgabe: array(array(ts => array('t','rf')), Meldung)
 */
function rk_meteo_lesen($daten)
{
    if (!is_array($daten)) { return array(array(), 'KEINE_ANTWORT'); }
    if (isset($daten['error']) && $daten['error']) {
        return array(array(), 'DIENST_MELDET_FEHLER');
    }
    $h = isset($daten['hourly']) && is_array($daten['hourly']) ? $daten['hourly'] : null;
    if (!$h || !isset($h['time'], $h['temperature_2m'], $h['relative_humidity_2m'])) {
        return array(array(), 'REIHEN_FEHLEN');
    }
    $out = array();
    $n = count($h['time']);
    for ($i = 0; $i < $n; $i++) {
        if (!isset($h['temperature_2m'][$i], $h['relative_humidity_2m'][$i])) { continue; }
        if (!is_numeric($h['temperature_2m'][$i]) || !is_numeric($h['relative_humidity_2m'][$i])) {
            continue;
        }
        /* Open-Meteo liefert mit timezone=auto Ortszeit OHNE Zeitzonenangabe
         * ("2026-08-10T13:00"). strtotime nimmt dann die Zeitzone des
         * Systems - und die stimmt auf einem LoxBerry mit der Ortszeit
         * ueberein. Ein blindes gmmktime() waere hier um die Zeitzone
         * daneben, und die Empfehlung um Stunden. */
        $ts = strtotime((string) $h['time'][$i]);
        if ($ts === false || $ts <= 0) { continue; }
        $out[$ts] = array('t' => (float) $h['temperature_2m'][$i],
                          'rf' => (float) $h['relative_humidity_2m'][$i]);
    }
    if (!$out) { return array(array(), 'KEINE_WERTE'); }
    ksort($out);
    return array($out, '');
}

/** Den Wert der laufenden Stunde aus der Vorhersage. */
function rk_meteo_jetzt($vorher, $jetzt)
{
    $stunde = (int) $jetzt - ((int) $jetzt % 3600);
    if (isset($vorher[$stunde])) { return $vorher[$stunde]; }
    // Sonst der letzte Wert, der nicht in der Zukunft liegt.
    $treffer = null;
    foreach ($vorher as $ts => $w) {
        if ($ts <= $stunde) { $treffer = $w; } else { break; }
    }
    return $treffer;
}

/* ==================================================================
 * Selbsttest
 * ================================================================== */

function rk_selbsttest()
{
    $z = array();
    $fehl = 0;
    $anzahl = 0;
    $pr = function ($name, $ist, $soll, $toleranz = 0.0) use (&$z, &$fehl, &$anzahl) {
        $anzahl++;
        if ($toleranz > 0 && is_numeric($ist) && is_numeric($soll)) {
            $ok = abs((float) $ist - (float) $soll) <= $toleranz;
        } else {
            $ok = ($ist === $soll);
        }
        if (!$ok) { $fehl++; }
        $z[] = ($ok ? '[ OK ] ' : '[FEHL] ') . $name;
        if (!$ok) {
            $z[] = '       erzeugt : ' . json_encode($ist);
            $z[] = '       erwartet: ' . json_encode($soll) . ($toleranz > 0 ? ' +/- ' . $toleranz : '');
        }
    };

    /* ---------- Lehrbuchwerte ----------
     * Nachgerechnet mit der Magnus-Formel nach Sonntag; die Werte stimmen
     * mit den ueblichen Taupunkttabellen auf ein Zehntel ueberein. */
    $pr('Taupunkt 20 C / 50 %', rk_taupunkt(20, 50), 9.26, 0.05);
    $pr('Taupunkt 20 C / 60 %', rk_taupunkt(20, 60), 12.00, 0.05);
    $pr('Taupunkt 22 C / 45 %', rk_taupunkt(22, 45), 9.52, 0.05);
    $pr('Taupunkt  5 C / 90 %', rk_taupunkt(5, 90), 3.50, 0.05);
    $pr('Taupunkt -5 C / 95 %', rk_taupunkt(-5, 95), -5.68, 0.05);
    $pr('Taupunkt bei 100 % ist die Temperatur selbst', rk_taupunkt(15, 100), 15.0, 0.02);

    $pr('absolute Feuchte 20 C / 50 %', rk_absolut(20, 50), 8.62, 0.02);
    $pr('absolute Feuchte 20 C / 60 %', rk_absolut(20, 60), 10.34, 0.02);
    $pr('absolute Feuchte  5 C / 90 %', rk_absolut(5, 90), 6.11, 0.02);
    $pr('absolute Feuchte  0 C / 80 %', rk_absolut(0, 80), 3.88, 0.02);
    $pr('absolute Feuchte -5 C / 95 %', rk_absolut(-5, 95), 3.24, 0.02);
    $pr('absolute Feuchte bei 0 % ist null', rk_absolut(20, 0), 0.0, 0.001);

    /* ---------- Unbrauchbare Eingaben: null, nicht null-Komma-null ---------- */
    $pr('Taupunkt ohne Feuchte', rk_taupunkt(20, null), null);
    $pr('Taupunkt bei 0 % nicht definiert', rk_taupunkt(20, 0), null);
    $pr('Taupunkt bei 120 % abgewiesen', rk_taupunkt(20, 120), null);
    $pr('absolute Feuchte ohne Temperatur', rk_absolut(null, 50), null);
    $pr('absolute Feuchte bei 120 % abgewiesen', rk_absolut(20, 120), null);

    /* ---------- Der Winterfall, wegen dem es das Plugin gibt ----------
     * Draussen 5 C bei 90 % fuehlt sich nass an, ist aber trockener als
     * drinnen 20 C bei 50 %. Wer nach RELATIVER Feuchte lueftet, macht es
     * falsch herum. */
    $pr('Außenluft 5 C/90 % ist trockener als Raum 20 C/50 %',
        rk_absolut(5, 90) < rk_absolut(20, 50), true);
    $pr('  und beim Aufwaermen auf 20 C werden daraus rund 34 %',
        rk_rf_bei(20, 5, 90), 33.6, 0.2);
    $l = rk_lueften(rk_absolut(20, 50), 5, 90, 0.5, -5, null);
    $pr('  also lohnt Lüften', array($l['lohnt'], $l['grund']), array(1, 'lohnt'));
    $pr('  Gewinn rund 2,5 g/m3', $l['gewinn'], 2.51, 0.02);

    /* Sommer: draussen 25 C bei 70 % ist deutlich feuchter als drinnen. */
    $l = rk_lueften(rk_absolut(22, 55), 25, 70, 0.5, -5, null);
    $pr('Sommerschwuele: Lüften lohnt nicht',
        array($l['lohnt'], $l['grund']), array(0, 'aussen_feuchter'));

    /* Mindestabstand: 0,2 g/m3 Unterschied reicht nicht, wenn 0,5 gefordert. */
    $af = rk_absolut(20, 50);
    $t_knapp = 19.0;
    $rf_knapp = rk_rf_bei($t_knapp, 20, 48.6);   // knapp trockener
    $l = rk_lueften($af, $t_knapp, $rf_knapp, 0.5, -5, null);
    $pr('Zu kleiner Unterschied gilt nicht als Gewinn', $l['lohnt'], 0);
    $l2 = rk_lueften($af, $t_knapp, $rf_knapp, 0.05, -5, null);
    $pr('  mit kleinerem Mindestabstand aber schon', $l2['lohnt'], 1);

    /* Zu kalt und zu trocken schlagen den Gewinn. */
    $l = rk_lueften(rk_absolut(20, 50), -12, 90, 0.5, -5, null);
    $pr('Zu kalt: Lüften wird abgeraten', array($l['lohnt'], $l['grund']), array(0, 'zu_kalt'));
    /* 20 C bei 30 % sind 5,17 g/m3 - unter der Untergrenze von 6,0. */
    $l = rk_lueften(rk_absolut(20, 30), 0, 60, 0.5, -20, 6.0);
    $pr('Raumluft schon zu trocken: Lüften wird abgeraten',
        array($l['lohnt'], $l['grund']), array(0, 'zu_trocken'));

    /* ---------- Schimmel ---------- */
    $pr('Oberfläche bei fRsi 0,7, innen 20, aussen 0', rk_oberflaeche(20, 0, 0.7), 14.0, 0.01);
    $pr('Oberfläche bei fRsi 0,4 ist kaelter', rk_oberflaeche(20, 0, 0.4), 8.0, 0.01);
    $pr('fRsi 1,0: Oberfläche gleich Raumluft', rk_oberflaeche(20, 0, 1.0), 20.0, 0.01);
    /* 20 C / 60 % an einer Ecke mit fRsi 0,70 bei 0 C draussen: die
     * Oberflaeche liegt bei 14 C, dort sind es rund 88 % - Schimmelgefahr,
     * obwohl die Raumluft mit 60 % unauffaellig aussieht. */
    $ro = rk_rf_oberflaeche(20, 60, 0, 0.7);
    $pr('Kalte Ecke: 60 % im Raum werden über 80 % an der Wand', $ro > 80, true);
    $pr('  genauer Wert rund 88 %', $ro, 87.6, 0.5);
    /* Bei fRsi 0,55 liegt die Oberflaeche schon unter dem Taupunkt - dann
     * ist es nicht mehr Schimmelgefahr, sondern Tauwasser. Die Rechnung
     * deckelt bei 100 %, weil eine relative Feuchte ueber 100 % keine
     * Aussage mehr ist. */
    $pr('Noch kaeltere Ecke: Tauwasser, gedeckelt bei 100 %',
        rk_rf_oberflaeche(20, 60, 0, 0.55), 100.0, 0.01);
    $pr('Gut gedaemmt (fRsi 0,9): dieselbe Raumluft bleibt unauffaellig',
        rk_rf_oberflaeche(20, 60, 0, 0.9) < 80, true);
    $pr('Ohne Außentemperatur keine Aussage', rk_rf_oberflaeche(20, 60, null, 0.7), null);

    /* ---------- Bester Zeitpunkt ---------- */
    $t0 = mktime(12, 0, 0, 11, 15, 2026);
    $vor = array(
        $t0            => array('t' => 12.0, 'rf' => 85.0),   // feucht
        $t0 + 3600     => array('t' => 13.0, 'rf' => 70.0),
        $t0 + 2 * 3600 => array('t' => 14.0, 'rf' => 45.0),   // am trockensten
        $t0 + 3 * 3600 => array('t' => 12.0, 'rf' => 60.0),
    );
    $af_i = rk_absolut(21, 60);
    $b = rk_bester_zeitpunkt($af_i, $vor, $t0, 0.5, -5, null, 12);
    $pr('Bester Zeitpunkt ist die trockenste Stunde (in 120 min)',
        array($b['in'], $b['jetzt'], $b['grund']), array(120, 0, 'gefunden'));

    /* Wenn die laufende Stunde schon gut ist, ist die Antwort "jetzt". */
    $vor2 = array($t0 => array('t' => 14.0, 'rf' => 40.0),
                  $t0 + 3600 => array('t' => 12.0, 'rf' => 90.0));
    $b2 = rk_bester_zeitpunkt($af_i, $vor2, $t0, 0.5, -5, null, 12);
    $pr('Laufende Stunde ist die beste', array($b2['in'], $b2['jetzt']), array(0, 1));

    /* Keine Stunde taugt - dann wird das gesagt, nicht die am wenigsten
     * schlechte empfohlen. */
    $vor3 = array($t0 => array('t' => 20.0, 'rf' => 95.0),
                  $t0 + 3600 => array('t' => 21.0, 'rf' => 92.0));
    $b3 = rk_bester_zeitpunkt($af_i, $vor3, $t0, 0.5, -5, null, 12);
    $pr('Keine geeignete Stunde: kein Fenster statt Notloesung',
        array($b3['in'], $b3['grund']), array(-1, 'kein_fenster'));
    $b4 = rk_bester_zeitpunkt($af_i, array(), $t0, 0.5, -5, null, 12);
    $pr('Ohne Vorhersage wird das gesagt', $b4['grund'], 'keine_vorhersage');

    /* Die Vorschau begrenzt den Blick: mit einer Stunde Vorschau kommt nur
     * die laufende Stunde in Frage, obwohl die in zwei Stunden besser waere. */
    $b5 = rk_bester_zeitpunkt($af_i, $vor, $t0, 0.5, -5, null, 1);
    $pr('Vorschau 1 h: nur die laufende Stunde zaehlt',
        array($b5['in'], $b5['grund']), array(0, 'gefunden'));
    $pr('  mit 12 h Vorschau gewinnt die bessere Stunde in 2 h', $b['in'], 120);

    /* ---------- Open-Meteo auswerten ---------- */
    $mo = array('hourly' => array(
        'time' => array(date('Y-m-d\TH:00', $t0), date('Y-m-d\TH:00', $t0 + 3600)),
        'temperature_2m' => array(12.5, 13.5),
        'relative_humidity_2m' => array(80, 70),
    ));
    list($v, $m) = rk_meteo_lesen($mo);
    $pr('Open-Meteo: zwei Stunden gelesen', array($m, count($v)), array('', 2));
    $pr('  Werte richtig zugeordnet',
        array($v[$t0]['t'], $v[$t0]['rf']), array(12.5, 80.0));
    list($v2, $m2) = rk_meteo_lesen(array('hourly' => array('time' => array())));
    $pr('Fehlende Reihen werden gemeldet', $m2, 'REIHEN_FEHLEN');
    list($v3, $m3) = rk_meteo_lesen(null);
    $pr('Keine Antwort wird gemeldet', $m3, 'KEINE_ANTWORT');
    list($v4, $m4) = rk_meteo_lesen(array('error' => true, 'reason' => 'x'));
    $pr('Fehlermeldung des Dienstes wird durchgereicht', $m4, 'DIENST_MELDET_FEHLER');
    $pr('Adresse enthält beide Reihen und die Zeitzone',
        (strpos(rk_meteo_url(48.1, 11.6), 'temperature_2m') !== false
         && strpos(rk_meteo_url(48.1, 11.6), 'relative_humidity_2m') !== false
         && strpos(rk_meteo_url(48.1, 11.6), 'timezone=auto') !== false), true);
    $pr('Wert der laufenden Stunde', rk_meteo_jetzt($v, $t0)['t'], 12.5);

    /* ---------- Ein ganzer Raum ---------- */
    $r = rk_raum_rechnen(
        array('name' => 'Schlafzimmer', 't' => 18.0, 'rf' => 65.0, 'frsi' => 0.6,
              'soll_min' => 40, 'soll_max' => 60),
        array('t' => 3.0, 'rf' => 85.0), $vor,
        array('mindest' => 0.5, 't_min' => -5, 'af_unter' => 0, 'vorschau' => 12), $t0);
    $pr('Raum: erkannt als zu feucht', array($r['ok'], $r['feucht'], $r['trocken']),
        array(1, 1, 0));
    $pr('Raum: Lüften lohnt', $r['lueften'], 1);
    $pr('Raum: Schimmelgefahr an der kalten Ecke', $r['schimmel'], 1);
    $leer = rk_raum_rechnen(array('name' => 'Ohne Fuehler'), array('t' => 3.0, 'rf' => 85.0),
        $vor, array(), $t0);
    $pr('Raum ohne Messwerte: ok=0 statt erfundener Nullen',
        array($leer['ok'], $leer['taupunkt'], $leer['absolut']), array(0, null, null));

    array_unshift($z, sprintf('Rechenkern %s: %d Faelle geprueft, %d Fehlschlaege.',
        RK_KERN, $anzahl, $fehl), '');
    return array($anzahl, $fehl, implode("\n", $z));
}
