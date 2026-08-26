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
    }
    return 'Unbekannter Test.';
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
