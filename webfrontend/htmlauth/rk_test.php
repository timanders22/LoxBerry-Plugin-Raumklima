<?php
/**
 * Raumklima - die Tests des Reiters "Test"
 *
 * Getrennt von der Oberflaeche, damit index.php lesbar bleibt. Jeder Test
 * gibt Klartext zurueck, keine Rueckgabewerte zum Auswerten: gelesen wird
 * das von einem Menschen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

function rk_test_ausfuehren($welcher)
{
    switch ($welcher) {
        case 'selbsttest': return rk_test_selbsttest();
        case 'quellen':    return rk_test_quellen();
        case 'pfade':      return rk_test_pfade();
        case 'meteo':      return rk_test_meteo();
        case 'rechnung':   return rk_test_rechnung();
        case 'mqtt':       return rk_test_mqtt();
        case 'endpunkt':   return rk_test_endpunkt();
        case 'sicherung':  return rk_test_sicherung();
    }
    return 'Unbekannter Test.';
}

/* ==================================================================
 * Die Selbstpruefung - was die Pruefkette nicht sieht
 *
 * Acht Zeilen aus dem Hausstandard (REGELN_2). Bis 0.10.1 hatte dieses
 * Plugin genau EINE davon; die uebrigen sieben fehlten, und zwei der
 * schwersten Befunde vom 28.08.2026 waeren mit ihnen beim ersten Klick
 * aufgefallen.
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich. Ein Strich ist ausdruecklich KEIN
 * Haken: was nicht gemessen werden konnte, sagt das.
 *
 * Jede Zeile, die die eigene Datei liest, nennt die ZAHL DER ANGESEHENEN
 * STELLEN. Eine Null ist dann kein "in Ordnung", sondern der Hinweis, dass
 * nichts gemessen wurde.
 *
 * Und jede Zeile, die ueber eine Menge urteilt, prueft zuerst, ob die
 * Menge leer ist - ueber einen Dienst, der gar nicht laeuft, wird kein
 * Herzschlag beurteilt.
 * ================================================================== */

function rk_selbstpruefung()
{
    $z = array();
    $add = function ($bez, $ok, $text) use (&$z) {
        $z[] = array('bez' => $bez, 'ok' => (int) $ok, 'text' => (string) $text);
    };
    $p = rk_paths();
    $cfg = rk_config();
    $stand = rk_stand();

    /* ---- 1. Steht der Cron-Eintrag? ----
     * Dieses Plugin hat keinen Dauerlaeufer; an seine Stelle tritt der
     * Cron. Ein Plugin muss seinen eigenen Cron-Eintrag pruefen - sonst
     * merkt niemand, dass er beim Update verlorenging. */
    $cronorte = array();
    if ($p['home'] !== '') {
        foreach (array('/system/cron/cron.05min/', '/system/cron/cron.5min/') as $c) {
            $cronorte[] = $p['home'] . $c . $p['plugin'];
        }
    }
    $cron_da = '';
    foreach ($cronorte as $c) { if (is_file($c)) { $cron_da = $c; break; } }
    if (!$cronorte) {
        $add('PRUEF.CRON', 2, 'LBHOMEDIR unbekannt');
    } elseif ($cron_da !== '') {
        $add('PRUEF.CRON', 1, basename(dirname($cron_da)) . '/' . basename($cron_da));
    } else {
        $add('PRUEF.CRON', 0, count($cronorte) . ' Orte abgesucht, keiner belegt');
    }

    /* ---- 2. Arbeitet der Abruf noch? ----
     * Nicht "gibt es ein Abbild", sondern "ist der letzte LAUF juenger als
     * drei Takte". Ueber eine leere Menge - noch nie gelaufen - wird nicht
     * geurteilt, das ist ein Strich. */
    $lauf = isset($stand['lauf_ts']) ? (int) $stand['lauf_ts'] : 0;
    $grenze = max(900, 3 * (int) $cfg['takt']);
    if ($lauf <= 0) {
        $add('PRUEF.LAUF', 2, 'noch kein Lauf');
    } else {
        $alt = time() - $lauf;
        $add('PRUEF.LAUF', $alt <= $grenze ? 1 : 0,
             sprintf('%d s alt, Grenze %d s', $alt, $grenze));
    }

    /* ---- 3. Ist die Konfiguration heil? ----
     * Vier Zustaende, jeder mit seinem Satz. */
    $lage = rk_config_lage();
    $add('PRUEF.CFG', $lage === 'ok' ? 1 : ($lage === 'kaputt' ? 0 : 2), $lage);

    /* ---- 4. Antwortet der eigene Endpunkt? ----
     * Zwischengespeichert, sonst ruft sich der Webserver bei jedem
     * Seitenaufbau selbst auf - und alle Reiter werden mitgerendert.
     * Drei Ausgaenge: geantwortet und plausibel, geantwortet und falsch,
     * nicht feststellbar. */
    list($eok, $etext) = rk_test_endpunkt_kurz(300);
    $add('PRUEF.ENDPUNKT', $eok, $etext);

    /* ---- 5. Passen Reiterleiste, Bereiche und Positivliste zusammen? ----
     * Drei Stellen in der eigenen Datei, gegeneinander gezaehlt. Die
     * Leiste ist ausgeschrieben und kann deshalb auseinanderlaufen. */
    $idx = __DIR__ . '/index.php';
    $q = is_file($idx) ? (string) @file_get_contents($idx) : '';
    $leiste = preg_match_all('/data-ziel="(tab-[a-z]+)"/', $q, $m1) ? $m1[1] : array();
    $bereich = preg_match_all('/ id="(tab-[a-z]+)"/', $q, $m2) ? $m2[1] : array();
    $liste = preg_match('/\$rk_reiter = array\((.*?)\);/s', $q, $m3)
        ? preg_match_all("/'([a-z]+)'\s*=>/", $m3[1], $m4) ? $m4[1] : array()
        : array();
    $liste = array_map(function ($x) { return 'tab-' . $x; }, $liste);
    sort($leiste); sort($bereich); sort($liste);
    if (!$leiste || !$bereich || !$liste) {
        $add('PRUEF.REITER', 0, sprintf('%d Leiste, %d Bereiche, %d Positivliste - '
            . 'mindestens eine Menge ist leer', count($leiste), count($bereich), count($liste)));
    } else {
        $gleich = ($leiste === $bereich && $leiste === $liste);
        $add('PRUEF.REITER', $gleich ? 1 : 0,
             sprintf('%d / %d / %d angesehen', count($leiste), count($bereich), count($liste)));
    }

    /* ---- 6. Tragen ALLE Formulare das Merkmal? ----
     * Ein Formular vergisst man. Gezaehlt wird in der eigenen Datei. */
    $formulare = substr_count($q, '<form ');
    $merkmale = substr_count($q, 'name="formtoken"');
    if ($formulare === 0) {
        $add('PRUEF.FORMULAR', 0, '0 Formulare gefunden - hier wurde nichts gemessen');
    } else {
        $add('PRUEF.FORMULAR', $formulare === $merkmale ? 1 : 0,
             sprintf('%d Formulare, %d mit Merkmal', $formulare, $merkmale));
    }

    /* ---- 7. Stimmt die Themenliste mit dem Sendecode ueberein? ----
     * Die Tabelle im Reiter MQTT ist die ANLEITUNG. Verglichen wird gegen
     * das, was rk_mqtt_werte() bei einem voll besetzten Raum wirklich
     * erzeugt - nicht gegen den Quelltext. */
    list($mok, $mtext) = rk_test_themen_vergleich();
    $add('PRUEF.THEMEN', $mok, $mtext);

    /* ---- 8. Sind die Vorlagen wohlgeformt? ----
     * Eine kaputte Vorlage merkt der Anwender sonst erst in Loxone Config. */
    if (!rk_raeume()) {
        $add('PRUEF.VORLAGE', 2, 'kein Raum eingerichtet - nichts zu erzeugen');
    } else {
        list($vn, $vi) = rk_vorlage();
        $vorher = libxml_use_internal_errors(true);
        $x = simplexml_load_string($vi);
        $fehler = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        $cmds = $x !== false ? count($x->VirtualInHttpCmd) : 0;
        $add('PRUEF.VORLAGE', $x !== false ? 1 : 0,
             $x !== false ? sprintf('%s, %d Eingaenge', $vn, $cmds)
                          : (count($fehler) ? trim($fehler[0]->message) : 'nicht lesbar'));
    }

    return $z;
}

/**
 * Der Endpunktaufruf, zwischengespeichert.
 *
 * Drei Ausgaenge, und der dritte ist der wichtige: "ich kann es nicht
 * messen" darf nicht wie "in Ordnung" aussehen.
 */
function rk_test_endpunkt_kurz($sekunden = 300)
{
    $p = rk_paths();
    $cache = $p['datadir'] . '/.endpunkt_probe';
    if (is_file($cache) && (time() - (int) filemtime($cache)) < $sekunden) {
        $d = json_decode((string) @file_get_contents($cache), true);
        if (is_array($d) && isset($d['ok'], $d['text'])) {
            return array((int) $d['ok'], $d['text'] . ' (zwischengespeichert)');
        }
    }
    $token = rk_token_lesen();
    if ($token === '') {
        return array(2, 'noch kein Wortzeichen vergeben');
    }
    $url = rk_endpunkt() . '?token=' . rawurlencode($token) . '&aktion=status';
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0)));
    $text = @file_get_contents($url, false, $ctx);
    if ($text === false) {
        /* Nicht feststellbar - der eingebaute PHP-Server etwa ist einlaeufig
         * und kann sich nicht selbst rufen. Das sagt nichts ueber den
         * Endpunkt, und ein rotes Kreuz waere hier eines, das nichts
         * bedeutet. */
        $erg = array(2, 'nicht erreichbar - Hostname oder einlaeufiger Server?');
    } elseif (strpos($text, 'RAUMKLIMA;') === 0) {
        $erg = array(1, strlen($text) . ' Zeichen, Antwortzeile erkannt');
    } else {
        $erg = array(0, 'geantwortet, aber nicht mit der Antwortzeile: '
                        . substr(trim($text), 0, 60));
    }
    @file_put_contents($cache, json_encode(array('ok' => $erg[0], 'text' => $erg[1])));
    return $erg;
}

/** Die Tabelle im Reiter MQTT gegen das, was wirklich gesendet wird. */
function rk_test_themen_vergleich()
{
    $stand = rk_stand();
    if (empty($stand['raeume'])) {
        return array(2, 'noch kein Abbild - nichts zu vergleichen');
    }
    $gesendet = array();
    foreach (array_keys(rk_mqtt_werte($stand)) as $k) {
        $gesendet[preg_replace('/^raum\d+\//', 'raumN/', $k)] = true;
    }
    $tabelle = rk_mqtt_themen();
    $fehlt_tab = array_diff(array_keys($gesendet), array_keys($tabelle));
    $fehlt_send = array_diff(array_keys($tabelle), array_keys($gesendet));
    $n = count($gesendet);
    if ($n === 0) { return array(0, '0 Themen erzeugt - hier wurde nichts gemessen'); }
    if (!$fehlt_tab && !$fehlt_send) {
        return array(1, sprintf('%d Themen, deckungsgleich', $n));
    }
    /* Ein Thema, das nur bei besonderer Einrichtung entsteht (Zuluft,
     * Aussenmittel), fehlt in der Sendemenge zu Recht. Gemeldet wird
     * deshalb nur die andere Richtung als Kreuz. */
    if ($fehlt_tab) {
        return array(0, sprintf('%d Themen gesendet, %d fehlen in der Tabelle: %s',
            $n, count($fehlt_tab), implode(', ', array_slice($fehlt_tab, 0, 5))));
    }
    return array(2, sprintf('%d Themen gesendet; %d stehen in der Tabelle und '
        . 'entstehen erst mit weiterer Einrichtung', $n, count($fehlt_send)));
}

/**
 * Der Pruefstand fuer die Sicherung - DREI Faelle, und der erste ist der,
 * den man vergisst.
 *
 * Genau dieser Knopf haette den Befund vom 26.08.2026 aus dem WiFi-Scanner
 * beim ersten Klick gemeldet: dort wies xx_sicherung_lesen() die Datei ab,
 * die zwei Zeilen vorher dieselbe Bibliothek erzeugt hatte.
 *
 * Geschrieben wird dabei NICHTS - geprueft wird nur die Leseseite.
 */
function rk_test_sicherung()
{
    $o = array();
    $cfg = rk_config();

    $o[] = '--- Fall 1: die eigene Ausgabe wieder einlesen ---';
    $eigen = rk_sicherung_bauen($cfg);
    $o[] = 'erzeugt: ' . strlen($eigen) . ' Zeichen';
    list($n1, $m1, $c1, $z1) = rk_sicherung_lesen($eigen);
    if ($n1 === null) {
        $o[] = 'FEHLGESCHLAGEN - die eigene Datei wird abgewiesen:';
        foreach ($m1 as $m) { $o[] = '   ' . strip_tags($m); }
    } else {
        $o[] = 'uebernommen, ' . $c1 . ' Werte. In Ordnung.';
        $abw = array();
        foreach (rk_vorgaben() as $k => $v) {
            $a = isset($cfg[$k]) ? $cfg[$k] : $v;
            if (json_encode($a) !== json_encode($n1[$k])) { $abw[] = $k; }
        }
        $o[] = $abw
            ? 'ACHTUNG: ' . count($abw) . ' Wert(e) kamen anders zurueck: '
              . implode(', ', array_slice($abw, 0, 8))
            : 'Alle ' . count(rk_vorgaben()) . ' Werte kamen zeichengenau zurueck.';
    }

    $o[] = '';
    $o[] = '--- Fall 1b: dieselbe Datei MIT Zugangsdaten ---';
    /* Erfundene Zugangsdaten, nicht die echten: der Reiter Test darf kein
     * Passwort auf den Bildschirm bringen, das jemand mitliest. */
    $probe = array('benutzer' => 'probe', 'passwort' => 'nur-fuer-den-test');
    $mit = rk_sicherung_bauen($cfg, $probe);
    list($nm, $mm, $cm, $zm) = rk_sicherung_lesen($mit);
    if ($nm === null) {
        $o[] = 'FEHLGESCHLAGEN - die eigene Datei mit Zugangsdaten wird abgewiesen:';
        foreach ($mm as $m) { $o[] = '   ' . strip_tags($m); }
    } else {
        $o[] = 'uebernommen, ' . $cm . ' Werte.';
        $o[] = is_array($zm) && $zm['benutzer'] === 'probe'
                 && $zm['passwort'] === 'nur-fuer-den-test'
            ? 'Zugangsdaten kamen zeichengenau zurueck.'
            : 'ACHTUNG: die Zugangsdaten kamen NICHT zurueck.';
        $o[] = array_key_exists('zugang', $nm)
            ? 'ACHTUNG: die Zugangsdaten stehen in der KONFIGURATION - sie'
              . ' gehoeren nach geheim.json, nicht in raumklima.json.'
            : 'Und sie stehen richtigerweise NICHT in der Konfiguration.';
        $o[] = strpos($mit, 'nur-fuer-den-test') !== false
            ? 'Der Kopf der Datei sagt: ' . (strpos($mit, 'UND Benutzername') !== false
                ? 'Zugangsdaten enthalten - richtig.'
                : 'ACHTUNG - er verschweigt sie.')
            : 'ACHTUNG: das Passwort steht gar nicht in der Datei.';
    }
    /* Geprueft wird die STRUKTUR, nicht ein Wort.
     *
     * Die erste Fassung dieser Zeile suchte mit strpos() nach 'zugang' und
     * schlug dabei auf den eigenen Kopfschluessel '_zugangsdaten' an - sie
     * meldete "ohne Haken stehen trotzdem Zugangsdaten darin", obwohl kein
     * Passwort in der Datei war. Eine Wache, die ein Wort zaehlt, schlaegt
     * auf den eigenen Text an; das steht zweimal in REGELN_1. */
    $ohne = json_decode(rk_sicherung_bauen($cfg, null), true);
    $o[] = (is_array($ohne) && !array_key_exists('zugang', $ohne)
            && isset($ohne['_zugangsdaten']) && $ohne['_zugangsdaten'] === 'nein')
        ? 'KONTROLLFALL ohne Haken: kein Schluessel zugang, und der Kopf sagt es.'
        : 'ACHTUNG: ohne Haken stehen trotzdem Zugangsdaten darin.';

    $o[] = '';
    $o[] = '--- Fall 2: eine halb gueltige Datei ---';
    $halb = json_encode(array('takt' => 600, 'mindest' => 0.5, 'fremdes_feld' => 1));
    list($n2, $m2, $c2) = rk_sicherung_lesen($halb);
    $o[] = $n2 === null
        ? 'richtig ABGEWIESEN, ' . count($m2) . ' Beanstandung(en):'
        : 'FEHLGESCHLAGEN - eine halb gueltige Datei wurde uebernommen.';
    foreach ($m2 as $m) { $o[] = '   ' . strip_tags($m); }

    $o[] = '';
    $o[] = '--- Fall 3: eine fremde Sicherung ---';
    list($n3, $m3, $c3) = rk_sicherung_lesen(json_encode(
        array('wi_host' => 'x', 'wi_port' => 8080)));
    $o[] = $n3 === null
        ? 'richtig ABGEWIESEN, ' . count($m3) . ' Beanstandung(en).'
        : 'FEHLGESCHLAGEN - eine fremde Sicherung wurde uebernommen.';

    $o[] = '';
    $o[] = '--- Fall 4: Werte, die das Formular abweisen wuerde ---';
    foreach (array('{"mqtt_topic":"raumklima/#"}'      => 'MQTT-Thema mit Filterzeichen',
                   '{"quelle":"file:///etc/passwd"}'   => 'Adresse ohne http',
                   '{"raeume":5}'                      => 'raeume als Zahl',
                   '{"aktionstoken":{"x":1}}'          => 'Wortzeichen als Feld',
                   '{"zugang":"nur ein Wort"}'         => 'Zugangsdaten als Zeichenkette',
                   '{"zugang":{"benutzer":"a","rolle":"root"}}' => 'Zugangsdaten mit drittem Schluessel') as $roh => $bez) {
        list($nx, $mx, $cx) = rk_sicherung_lesen($roh);
        $o[] = sprintf('  %-32s %s', $bez,
            $nx === null ? 'abgewiesen' : 'DURCHGELASSEN - das ist ein Fehler');
    }

    $o[] = '';
    $o[] = 'Geschrieben wurde bei diesem Test nichts. Die Konfiguration steht';
    $o[] = 'unveraendert da.';
    return implode("\n", $o);
}

/** Der Rechenkern gegen die hinterlegten Lehrbuchwerte. */
function rk_test_selbsttest()
{
    list($anzahl, $fehl, $text) = rk_selbsttest();
    return $text . "\n\n"
        . ($fehl === 0
            ? 'Alle ' . $anzahl . ' Faelle stimmen. Die Rechnung ist in Ordnung; '
              . 'was jetzt noch schiefgehen kann, liegt an den Quellen.'
            : $fehl . ' von ' . $anzahl . ' Faellen stimmen NICHT. Das ist ein '
              . 'Fehler im Plugin, nicht in deiner Einstellung - bitte melden.');
}

/** Jede eingetragene Quelle einmal holen und die Pfade nachschlagen. */
function rk_test_quellen()
{
    $cfg = rk_config();
    $o = array();
    $gemeinsam = null;

    if (trim((string) $cfg['quelle']) !== '') {
        $o[] = 'Gemeinsame Quelle: ' . $cfg['quelle'];
        list($d, $m) = rk_holen($cfg['quelle'], true);
        $gemeinsam = $d;
        if ($d === null) {
            $o[] = '  FEHLER: ' . rk_test_klartext($m);
        } else {
            $o[] = '  Antwort gelesen, ' . count($d) . ' Eintraege auf oberster Ebene.';
            $o[] = '  Oberste Schluessel: ' . implode(', ', array_slice(array_keys($d), 0, 12));
        }
        $o[] = '';
    }

    $raeume = rk_raeume();
    if (!$raeume) {
        $o[] = 'Es ist noch kein Raum vollstaendig eingetragen (Name und mindestens ein Pfad).';
        return implode("\n", $o);
    }

    foreach ($raeume as $nr => $r) {
        $o[] = 'Raum ' . $nr . ': ' . $r['name']
             . '  [' . $r['art'] . ', '
             . ($r['einheit_t'] === 'F' ? 'Grad Fahrenheit' : 'Grad Celsius') . ', '
             . ($r['einheit_rf'] === 'anteil' ? 'Feuchte als Anteil 0-1' : 'Feuchte in Prozent')
             . ']';
        $eigen = trim((string) $r['quelle']) !== '';
        $daten = null;
        if ($eigen) {
            list($daten, $m) = rk_holen($r['quelle'], true);
            $o[] = '  Eigene Quelle: ' . $r['quelle'];
            if ($daten === null) { $o[] = '  FEHLER: ' . rk_test_klartext($m); }
        } else {
            $daten = $gemeinsam;
            if ($daten === null) { $o[] = '  Keine Daten (gemeinsame Quelle fehlt oder schwieg).'; }
        }
        if (is_array($daten)) {
            foreach (array('pfad_t' => 'Temperatur', 'pfad_rf' => 'Feuchte') as $f => $bez) {
                if (trim((string) $r[$f]) === '') {
                    $o[] = '  ' . $bez . ': kein Pfad eingetragen.';
                    continue;
                }
                $w = rk_pfad($daten, $r[$f]);
                if ($w === null) {
                    $o[] = '  ' . $bez . ': Pfad "' . $r[$f] . '" fuehrt zu nichts.';
                    $o[] = '    ' . rk_test_pfadhilfe($daten, $r[$f]);
                    continue;
                }
                /* Rohwert UND gelesene Zahl. Eine Quelle darf "52%" oder
                 * "24.6 C" liefern; wer die Einheit umstellt, muss sehen,
                 * was daraus geworden ist. */
                $zahl = rk_zahl_aus($w);
                if ($zahl === null) {
                    $o[] = '  ' . $bez . ': Pfad "' . $r[$f] . '" liefert "' . $w
                         . '" - daraus laesst sich keine Zahl lesen.';
                    continue;
                }
                $um = ($f === 'pfad_t')
                    ? rk_temp_c($zahl, $r['einheit_t'])
                    : rk_rf_prozent($zahl, $r['einheit_rf']);
                $anhang = '';
                if ((string) $w !== (string) $zahl) { $anhang = ' (gelesen aus "' . $w . '")'; }
                if (abs($um - $zahl) > 1e-9) {
                    $anhang .= ' (umgerechnet aus ' . $zahl . ')';
                }
                $gut = ($f === 'pfad_t') ? rk_t_gueltig($um) : ($um > 0.0 && $um <= 100.0);
                $o[] = '  ' . $bez . ': ' . $um . $anhang
                     . ($gut ? '' : '   ACHTUNG: ausserhalb des gueltigen Bereichs,'
                                  . ' wird als Ausfall behandelt.');
            }
        }
        $o[] = '';
    }
    return implode("\n", $o);
}

/**
 * Der Quellen-Assistent: JEDEN Zahlenwert der Antwort mit vollem Pfad
 * auflisten.
 *
 * Bei zwoelf Raeumen sind zwei bis vier Pfade je Raum abzutippen, und der
 * haeufigste Fehler ist ein Tippfehler eine Ebene zu frueh. Wer die Liste
 * vor sich hat, schreibt ab statt zu raten.
 *
 * Die Obergrenze ist hart: ein Gateway mit hundert Kanaelen liefert sonst
 * eine Seite, die niemand mehr liest.
 */
function rk_test_pfade()
{
    $cfg = rk_config();
    $o = array();
    $quellen = array();
    if (trim((string) $cfg['quelle']) !== '') { $quellen[rk_t('EINST.QUELLE')] = $cfg['quelle']; }
    foreach (rk_raeume() as $nr => $r) {
        if (trim((string) $r['quelle']) !== '') {
            $quellen[rk_t('EINST.RAUM') . ' ' . $nr . ' (' . $r['name'] . ')'] = $r['quelle'];
        }
    }
    if (trim((string) $cfg['aussen_quelle']) !== '') {
        $quellen[rk_t('EINST.AUSSEN_QUELLE')] = $cfg['aussen_quelle'];
    }
    if (!$quellen) {
        return 'Es ist noch keine Adresse eingetragen. Der Assistent kann erst dann'
             . " zeigen, welche Pfade es gibt.
";
    }
    foreach ($quellen as $bez => $url) {
        $o[] = $bez . ': ' . $url;
        list($d, $m) = rk_holen($url, true);
        if ($d === null) { $o[] = '  FEHLER: ' . rk_test_klartext($m); $o[] = ''; continue; }
        $treffer = array();
        rk_test_blaetter($d, '', $treffer, 0);
        if (!$treffer) { $o[] = '  In dieser Antwort steht keine einzige Zahl.'; $o[] = ''; continue; }
        $o[] = '  ' . count($treffer) . ' Zahlenwerte gefunden:';
        $n = 0;
        foreach ($treffer as $pfad => $wert) {
            $o[] = sprintf('    %-44s = %s', $pfad, $wert);
            if (++$n >= 300) { $o[] = '    ... weitere ausgelassen.'; break; }
        }
        $o[] = '';
    }
    $o[] = 'Den passenden Pfad in die Spalte des Raums uebernehmen.';
    return implode("
", $o);
}

/** Rekursiv durch die Antwort - nur Blaetter, die eine Zahl ergeben. */
function rk_test_blaetter($daten, $praefix, &$treffer, $tiefe)
{
    if ($tiefe > 8 || count($treffer) > 400) { return; }
    if (!is_array($daten)) { return; }
    foreach ($daten as $k => $v) {
        $pfad = ($praefix === '') ? (string) $k : $praefix . '.' . $k;
        if (is_array($v)) {
            rk_test_blaetter($v, $pfad, $treffer, $tiefe + 1);
        } elseif (rk_zahl_aus($v) !== null) {
            $treffer[$pfad] = is_string($v) ? '"' . $v . '"  -> ' . rk_zahl_aus($v) : $v;
        }
    }
}

/**
 * Wenn ein Pfad ins Leere fuehrt, ist die haeufigste Ursache ein Tippfehler
 * eine Ebene zu frueh. Deshalb wird gesagt, WO er abbricht und was dort
 * stattdessen steht - das erspart das Raten.
 */
function rk_test_pfadhilfe($daten, $pfad)
{
    $bisher = array();
    foreach (explode('.', (string) $pfad) as $teil) {
        if (is_array($daten) && array_key_exists($teil, $daten)) {
            $daten = $daten[$teil];
            $bisher[] = $teil;
            continue;
        }
        $stelle = $bisher ? implode('.', $bisher) : '(oberste Ebene)';
        if (!is_array($daten)) {
            return 'Bei "' . $stelle . '" steht keine Ebene mehr, sondern ein Wert.';
        }
        $da = array_slice(array_keys($daten), 0, 15);
        return 'Bei "' . $stelle . '" gibt es kein "' . $teil . '". Vorhanden: '
             . implode(', ', $da) . (count($daten) > 15 ? ' ...' : '');
    }
    return '';
}

/** Open-Meteo einmal fragen und die naechsten Stunden zeigen. */
function rk_test_meteo()
{
    $cfg = rk_config();
    $url = rk_meteo_url($cfg['breite'], $cfg['laenge'], 2);
    $o = array('Adresse: ' . $url, '');
    list($d, $m) = rk_holen($url);
    if ($d === null) { return implode("\n", $o) . 'FEHLER: ' . rk_test_klartext($m); }
    list($vor, $m2) = rk_meteo_lesen($d);
    if ($m2 !== '') { return implode("\n", $o) . 'FEHLER: ' . rk_test_klartext($m2); }

    $o[] = count($vor) . ' Stundenwerte erhalten.';
    if (isset($d['timezone'])) { $o[] = 'Zeitzone laut Dienst: ' . $d['timezone']; }
    $o[] = '';
    $o[] = 'Zeit          Temp    rF    absolut';
    $n = 0;
    foreach ($vor as $ts => $w) {
        if ($ts < time() - 3600) { continue; }
        $o[] = sprintf('%-13s %5.1f  %4.0f   %6.2f g/m3',
            date('d.m. H:i', $ts), $w['t'], $w['rf'], (float) rk_absolut($w['t'], $w['rf']));
        if (++$n >= 24) { break; }
    }
    return implode("\n", $o);
}

/** Alles einmal durchrechnen und zeigen, was nach Loxone ginge. */
function rk_test_rechnung()
{
    $stand = rk_abrufen(true);
    $o = array('Abruf um ' . date('H:i:s', (int) $stand['ts']) . '.', '');
    if (isset($stand['aussen']['t'])) {
        $o[] = sprintf('Aussen: %.1f C, %.0f %%, Taupunkt %.1f C, absolut %.2f g/m3',
            $stand['aussen']['t'], $stand['aussen']['rf'],
            (float) rk_taupunkt($stand['aussen']['t'], $stand['aussen']['rf']),
            (float) rk_absolut($stand['aussen']['t'], $stand['aussen']['rf']));
    } else {
        $o[] = 'Aussen: keine Werte.';
    }
    $o[] = 'Vorhersagestunden: ' . (int) $stand['vorher_n'];
    if (!empty($stand['meldungen'])) {
        $o[] = '';
        $o[] = 'Meldungen:';
        foreach ($stand['meldungen'] as $k => $m) {
            $o[] = '  ' . $k . ': ' . rk_test_klartext($m);
        }
    }
    $o[] = '';
    $o[] = '--- Die Zeilen, die der Miniserver bekommt ---';
    $o[] = rtrim(rk_zeile($stand));
    return implode("\n", $o);
}

/** Was tatsaechlich veroeffentlicht wuerde. */
function rk_test_mqtt()
{
    $cfg = rk_config();
    $z = rk_mqtt_zustand();
    $o = array();
    $o[] = 'Gateway in der general.json gefunden: ' . ($z['gefunden'] ? 'ja' : 'NEIN');
    $o[] = 'Autostart: ' . ($z['autostart'] ? 'ja' : 'NEIN');
    $o[] = 'UDP-Eingangsport: ' . ($z['udpport'] ? $z['udpport'] : '- keiner -');
    $o[] = 'Veroeffentlichen im Plugin: ' . ($cfg['mqtt_ein'] ? 'ein' : 'aus');
    $o[] = '';
    if (!$z['autostart']) {
        $o[] = 'Hinweis: Solange das Gateway nicht auf Autostart steht, hoert niemand zu.';
        $o[] = 'Das steht in LoxBerry unter System, MQTT Gateway.';
        $o[] = '';
    }
    $o[] = '--- Diese Themen wuerden gesendet ---';
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    foreach (rk_mqtt_werte(rk_stand()) as $k => $v) {
        if ($v === null || $v === '') { continue; }
        $o[] = $praefix . '/' . $k . '  =  ' . $v;
    }
    if (rk_mqtt_senden(rk_stand())) {
        $o[] = '';
        $o[] = 'Gesendet. Ob es angekommen ist, sagt der MQTT Finder in LoxBerry.';
    }
    return implode("\n", $o);
}

/** Den eigenen Endpunkt aufrufen - so, wie der Miniserver es taete. */
function rk_test_endpunkt()
{
    $url = rk_endpunkt() . '?token=' . rk_token() . '&aktion=status';
    $o = array('Aufruf: ' . $url, '');
    $ctx = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
    $text = @file_get_contents($url, false, $ctx);
    if ($text === false) {
        $o[] = 'FEHLER: der Endpunkt war nicht erreichbar.';
        $o[] = 'Das kann am Hostnamen liegen - der Miniserver braucht denselben Namen,';
        $o[] = 'unter dem der LoxBerry bei ihm eingetragen ist.';
        return implode("\n", $o);
    }
    $o[] = $text;
    /* Ein falsches Wortzeichen MUSS abgewiesen werden. Wird das hier nicht
     * bestaetigt, steht der Endpunkt offen - und das ist wichtiger als
     * jedes andere Ergebnis auf dieser Seite. */
    $o[] = '';
    $o[] = '--- Gegenprobe mit falschem Wortzeichen ---';
    $falsch = @file_get_contents(rk_endpunkt() . '?token=falsch&aktion=status', false, $ctx);
    $o[] = ($falsch !== false && strpos((string) $falsch, 'GRUND=TOKEN') !== false)
        ? 'Richtig abgewiesen.'
        : 'ACHTUNG: der Endpunkt hat NICHT abgewiesen. Antwort: ' . substr((string) $falsch, 0, 200);
    return implode("\n", $o);
}

function rk_test_klartext($kuerzel)
{
    $t = rk_t('MELD.' . $kuerzel);
    return $t === 'MELD.' . $kuerzel ? $kuerzel : $t;
}
