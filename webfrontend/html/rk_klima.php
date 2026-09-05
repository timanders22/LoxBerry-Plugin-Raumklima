<?php
/**
 * Raumklima - der Rechenkern
 *
 * Reine Rechnung: kein Netz, keine Dateien, keine Uhr ausser dem
 * uebergebenen Zeitpunkt. Deshalb laesst sich alles hier durchpruefen -
 * rk_selbsttest() rechnet Lehrbuchwerte fuer Taupunkt und absolute Feuchte
 * nach, dazu die Grenzfaelle, an denen das Plugin schon gestanden hat.
 *
 * Hier steht bewusst KEINE Fallzahl. Sie altert mit jedem neuen Fall und
 * wird dann zum Selbstwiderspruch; massgeblich ist, was der Selbsttest
 * beim Laufen zaehlt und ausgibt.
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

define('RK_KERN', '1.3.0');

/* ==================================================================
 * Grundgroessen
 * ================================================================== */

/* Der Gueltigkeitsbereich, in dem gerechnet wird.
 *
 * Gemessen am 24.08.2026: ein Fuehler, der -273,15 meldet, macht den Nenner
 * (273,15 + T) zu null. Unter PHP 7.4 kommt INF heraus, json_encode()
 * scheitert daran und die Zustandsdatei wird STILL nicht geschrieben; unter
 * PHP 8.4 wirft dieselbe Zeile einen DivisionByZeroError, der Cron-Lauf
 * endet mit Rueckgabewert 255. Ein einziger Fuehlerwert nahm damit das ganze
 * Plugin vom Netz.
 *
 * -60 bis 80 Grad deckt jeden Fuehler ab, den jemand in ein Haus haengt, und
 * haelt beide Nenner (243,12 + T und 273,15 + T) sicher von der Null weg.
 * Ausserhalb wird nicht gerechnet, sondern null geliefert - ein fehlender
 * Wert ist eine Aussage, eine erfundene Zahl nicht. */
define('RK_T_MIN', -60.0);
define('RK_T_MAX', 80.0);

/** Liegt die Temperatur im Bereich, in dem die Formeln gelten? */
function rk_t_gueltig($t)
{
    if (!is_numeric($t)) { return false; }
    $t = (float) $t;
    return $t >= RK_T_MIN && $t <= RK_T_MAX && is_finite($t);
}

/** Saettigungsdampfdruck in hPa. Rueckgabe null ausserhalb des Bereichs. */
function rk_es($t)
{
    if (!rk_t_gueltig($t)) { return null; }
    $t = (float) $t;
    return 6.112 * exp(17.62 * $t / (243.12 + $t));
}

/**
 * Eine Zahl aus dem lesen, was eine fremde Quelle geliefert hat.
 *
 * Gemessen am 24.08.2026 an den Quellen, die die Anleitung selbst nennt:
 * Ecowitt liefert die Feuchte als "52%", der Miniserver die Temperatur als
 * "22.5" mit angehaengtem Gradzeichen. is_numeric() sagt zu beidem nein,
 * und der Raum fiel damit stumm aus. Dazu kommt eine Fassungsfalle:
 * is_numeric(" 45 ") ist unter PHP 7.4 falsch und unter PHP 8.4 wahr -
 * dieselbe Quelle traegt auf LoxBerry 4 und faellt auf LoxBerry 3 aus.
 *
 * Gelesen wird deshalb die fuehrende Zahl; was dahinter steht, darf eine
 * Einheit sein, aber KEINE weitere Ziffer - sonst wuerde aus einer
 * Fassungsangabe "1.2.3" klammheimlich eine 1,2.
 */
function rk_zahl_aus($roh)
{
    if (is_int($roh)) { return (float) $roh; }
    if (is_float($roh)) { return is_finite($roh) ? $roh : null; }
    if ($roh === null || is_bool($roh) || is_array($roh) || is_object($roh)) { return null; }
    $s = trim((string) $roh);
    if ($s === '') { return null; }
    /* Deutsche Kommaschreibweise, aber nur wo sie eindeutig ist: genau ein
     * Komma und kein Punkt. "1,234.5" bleibt unangetastet und bricht dann
     * hinter der 1 ab - lieber kein Wert als ein um Faktor 1000 falscher. */
    if (strpos($s, '.') === false && substr_count($s, ',') === 1) {
        $s = str_replace(',', '.', $s);
    }
    if (!preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)/', $s, $m)) { return null; }
    $rest = substr($s, strlen($m[0]));
    if (preg_match('/\d/', $rest)) { return null; }
    $z = (float) $m[0];
    return is_finite($z) ? $z : null;
}

/** Temperatur in Grad Celsius, aus 'C' oder 'F'. */
function rk_temp_c($wert, $einheit = 'C')
{
    if ($wert === null) { return null; }
    if (strtoupper((string) $einheit) === 'F') {
        return round(((float) $wert - 32.0) * 5.0 / 9.0, 3);
    }
    return (float) $wert;
}

/** Relative Feuchte in Prozent, aus 'proz' (0-100) oder 'anteil' (0-1). */
function rk_rf_prozent($wert, $einheit = 'proz')
{
    if ($wert === null) { return null; }
    if ((string) $einheit === 'anteil') { return round((float) $wert * 100.0, 3); }
    return (float) $wert;
}

/**
 * Dampfdruck in hPa aus Temperatur und relativer Feuchte.
 * Der Name ist ausgeschrieben und nicht 'rk_e': dieses Kuerzel gehoert im
 * Hausstandard dem HTML-Maskierer, und eine Kollision faellt erst beim
 * Rendern auf - als Fatal error mit weisser Seite.
 */
function rk_dampfdruck($t, $rf)
{
    $es = rk_es($t);
    if ($es === null || !is_numeric($rf)) { return null; }
    return $es * max(0.0, min(100.0, (float) $rf)) / 100.0;
}

/**
 * Taupunkt in Grad Celsius.
 * Rueckgabe null, wenn die Eingabe unbrauchbar ist - nicht 0. Eine Null
 * waere ein plausibler Taupunkt und damit eine stille Falschaussage.
 *
 * Seit 0.11.0 wird auch das ERGEBNIS gegen den Gueltigkeitsbereich gehalten,
 * nicht nur die Eingabe. Gemessen am 28.08.2026: ein Fuehler am unteren
 * Anschlag meldet 0,1 % statt 0 %; die Eingabe kommt damit durch, und heraus
 * kommt ein Taupunkt von -57,89 Grad. Aus ihm wird VLMIN = -56,89 - und das
 * geht als "kleinste Vorlauftemperatur" nach Loxone. Der Bereich, in dem die
 * Magnus-Formel laut Dateikopf gilt, endet bei -60; eine Zahl 30 Kelvin
 * darunter ist keine Aussage mehr, sondern eine gefaehrliche.
 */
function rk_taupunkt($t, $rf)
{
    if (!is_numeric($t) || !is_numeric($rf)) { return null; }
    $rf = (float) $rf;
    if ($rf <= 0 || $rf > 100) { return null; }
    $e = rk_dampfdruck($t, $rf);
    if ($e === null || $e <= 0) { return null; }
    $l = log($e / 6.112);
    $n = 17.62 - $l;
    if (abs($n) < 1e-9) { return null; }
    $td = round(243.12 * $l / $n, 2);
    return rk_t_gueltig($td) ? $td : null;
}

/** Absolute Feuchte in g/m3. Rueckgabe null bei unbrauchbarer Eingabe. */
function rk_absolut($t, $rf)
{
    if (!is_numeric($t) || !is_numeric($rf)) { return null; }
    $rf = (float) $rf;
    if ($rf < 0 || $rf > 100) { return null; }
    $e = rk_dampfdruck($t, $rf);
    if ($e === null) { return null; }
    return round(216.679 * $e / (273.15 + (float) $t), 3);
}

/**
 * Relative Feuchte, die dieselbe absolute Feuchte bei einer anderen
 * Temperatur ergibt. Damit laesst sich fragen: "Was wird aus der
 * Aussenluft, wenn sie sich im Zimmer auf 20 Grad erwaermt?"
 */
function rk_rf_bei($t_neu, $t_alt, $rf_alt)
{
    $es_neu = rk_es($t_neu);
    if ($es_neu === null || $es_neu <= 0) { return null; }
    $e_alt = rk_dampfdruck($t_alt, $rf_alt);
    if ($e_alt === null) { return null; }
    return round(min(100.0, $e_alt / $es_neu * 100.0), 1);
}

/**
 * Mischungsverhaeltnis in g Wasser je kg trockener Luft.
 *
 * Die absolute Feuchte in g/m3 sagt, wie viel Wasser in einem Kubikmeter
 * steckt - richtig fuer die Frage "wie viel geht beim Lueften raus". Fuer
 * die Frage "kuehlt das Lueften oder heizt es" braucht man die Enthalpie,
 * und die haengt am Mischungsverhaeltnis, nicht am Volumen.
 */
function rk_mischung($t, $rf, $druck = 1013.25)
{
    $e = rk_dampfdruck($t, $rf);
    if ($e === null) { return null; }
    $p = max(1.0, (float) $druck);
    if ($e >= $p) { return null; }
    return round(622.0 * $e / ($p - $e), 3);
}

/**
 * Enthalpie feuchter Luft in kJ je kg trockener Luft.
 *
 *     h = 1,006 * T + x * (2501 + 1,86 * T)      x in kg/kg
 *
 * Damit laesst sich beantworten, was ein Luftwechsel energetisch bedeutet -
 * die Verdampfungswaerme des Wassers steckt darin. Zwei Luftmengen gleicher
 * Temperatur, aber verschiedener Feuchte, tragen verschieden viel Energie.
 */
function rk_enthalpie($t, $rf, $druck = 1013.25)
{
    $x = rk_mischung($t, $rf, $druck);
    if ($x === null || !rk_t_gueltig($t)) { return null; }
    $t = (float) $t;
    return round(1.006 * $t + ($x / 1000.0) * (2501.0 + 1.86 * $t), 2);
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

/**
 * Oberflaechentemperatur der kaeltesten Stelle, geschaetzt.
 *
 * Die Raumart entscheidet, WOHER die kalte Flaeche ihre Temperatur nimmt -
 * das fRsi-Modell gilt nur fuer eine Aussenwand im Heizfall:
 *
 *   aussen  Aussenwand: T_flaeche = T_aussen + fRsi * (T_innen - T_aussen).
 *           Ist es draussen WAERMER als drinnen, gibt es keine kalte Ecke aus
 *           der Aussenluft; die Formel lieferte sonst eine Flaeche ueber der
 *           Raumtemperatur (gemessen: innen 24, aussen 32, fRsi 0,70 ergab
 *           26,4 Grad). Dann gilt die Raumtemperatur.
 *   keller  Erdberuehrte Wand: sie folgt dem Erdreich, nicht der Aussenluft.
 *           Genau hier entsteht Sommerschimmel - warme, feuchte Luft trifft
 *           auf eine Wand, die bei 12 bis 14 Grad steht. Mit dem fRsi-Modell
 *           las dieser Fall sich unauffaellig (56 % statt 96 %).
 *   innen   Innenraum ohne kalte Flaeche: keine Aussage statt einer erfundenen.
 */
function rk_oberflaeche($t_innen, $t_aussen, $frsi, $art = 'aussen', $erd_t = null)
{
    if (!rk_t_gueltig($t_innen)) { return null; }
    $t_innen = (float) $t_innen;
    $art = (string) $art;
    if ($art === 'innen') { return null; }
    if ($art === 'keller') {
        return rk_t_gueltig($erd_t) ? round((float) $erd_t, 2) : null;
    }
    if (!rk_t_gueltig($t_aussen)) { return null; }
    $t_aussen = (float) $t_aussen;
    if ($t_aussen >= $t_innen) { return round($t_innen, 2); }
    $f = max(0.05, min(1.0, (float) $frsi));
    return round($t_aussen + $f * ($t_innen - $t_aussen), 2);
}

/**
 * Relative Feuchte an dieser Oberflaeche.
 * Ueber 80 % gilt als Schimmelgefahr, ueber 100 % faellt Wasser aus.
 */
function rk_rf_oberflaeche($t_innen, $rf_innen, $t_aussen, $frsi,
                          $art = 'aussen', $erd_t = null)
{
    $to = rk_oberflaeche($t_innen, $t_aussen, $frsi, $art, $erd_t);
    if ($to === null) { return null; }
    return rk_rf_bei($to, $t_innen, $rf_innen);
}

/**
 * Die Schimmelampel: -1 keine Aussage, 0 unbedenklich, 1 beobachten,
 * 2 Gefahr, 3 Tauwasser.
 *
 * Das eine Bit bei genau 80 % springt auf Messrauschen an - 79,8 und 80,1
 * sind dieselbe Wand. Vier Stufen sagen mehr und flattern weniger:
 *
 *     unter 70 %   unbedenklich
 *     70 bis 80    beobachten - hier faengt es an, wenn es so bleibt
 *     80 bis 95    Schimmelgefahr (die Schwelle der Bauphysik)
 *     ab 95        praktisch Tauwasser
 *
 * Bis 0.10.1 stand hier eine 0, wenn sich die Oberflaechenfeuchte nicht
 * berechnen liess. Gemessen am 28.08.2026: bei stummer Aussenquelle und bei
 * der Raumart 'innen' meldete das Plugin damit dieselbe 0 wie bei einer
 * gemessenen, unbedenklichen Wand. Ein Waechter in Loxone konnte "keine
 * Gefahr" nicht von "keine Daten" unterscheiden - dieselbe stille
 * Falschaussage, die der Sammelwert OK eine Ebene hoeher laengst vermeidet.
 * -1 reiht sich in nass24, alter und best_in ein, die alle so verfahren.
 */
function rk_ampel($ober_rf)
{
    if ($ober_rf === null || !is_numeric($ober_rf)) { return -1; }
    $r = (float) $ober_rf;
    if ($r >= 95.0) { return 3; }
    if ($r >= 80.0) { return 2; }
    if ($r >= 70.0) { return 1; }
    return 0;
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
 * ------------------------------------------------------------------
 * Die HARTEN SPERREN stehen seit 0.11.0 VORN - und sie tragen ein eigenes
 * Merkmal, keine Zeichenkette
 * ------------------------------------------------------------------
 *
 * Bis 0.10.1 war die Regenpruefung die dritte von dreien, und der Aufrufer
 * las die Sperre aus dem Feld 'grund' ab (`if ($l['grund'] === 'regen')`).
 * Griff vorher schon 'zu_kalt' oder 'zu_trocken', wurde 'grund' nie auf
 * 'regen' gesetzt - und die Sperre, die auch CO2 und Kuehlen abfangen soll,
 * lief ins Leere. Gemessen am 28.08.2026:
 *
 *     Schnee, -6 C, 3 mm, CO2 1400   ->  lueften=1 grund=co2
 *     Regen,  -4 C, 3 mm, CO2 1400   ->  lueften=0 grund=regen
 *
 * Bei Schneefall und sechs Grad unter null oeffnete der Fensterantrieb.
 * Ein Zustand, den man aus einer Zeichenkette liest, ist eine zweite Stelle,
 * die man mitpflegen muss; deshalb gibt es jetzt 'sperre'.
 *
 * Drei harte Sperren, alle vor jeder Abwaegung:
 *
 *   regen           Starkregen - ein Fensterantrieb macht dann nicht auf
 *   wind            Sturm. Fuer einen Antrieb der wichtigere der beiden
 *                   Schalter; die Boe schlaegt den Fluegel, der Regen nicht.
 *   wand_tauwasser  Der Taupunkt der AUSSENluft liegt ueber der kaeltesten
 *                   Flaeche im Raum. Dann faellt genau dort Wasser aus,
 *                   auch wenn die Aussenluft absolut trockener ist.
 *                   Gemessen: Keller 20 C/72 %, Wand 13 C, aussen 25 C/51 % -
 *                   Gewinn +0,70 g/m3, also "lohnt", und der Taupunkt der
 *                   Aussenluft liegt mit 14,16 C um 1,2 K UEBER der Wand.
 *                   An der Wand waeren es danach 100 %. Der Kopfkommentar zu
 *                   rk_oberflaeche() begruendet die Raumart 'keller'
 *                   ausdruecklich mit diesem Sommerschimmel; die Ampel
 *                   behandelte ihn richtig, die Empfehlung nicht.
 *
 * Rueckgabe: array('lohnt' => 0/1, 'gewinn' => g/m3, 'grund' => Kuerzel,
 *                  'sperre' => 0/1)
 */
function rk_lueften($af_innen, $t_aussen, $rf_aussen, $mindest, $t_min, $af_unter,
                    $lief = false, $hyst = 0.5, $regen = null, $regen_max = 0.0,
                    $wind = null, $wind_max = 0.0, $wand_t = null,
                    $wand_abstand = 1.0)
{
    $af_aussen = rk_absolut($t_aussen, $rf_aussen);
    /* gewinn = null, nicht 0.0. GEWINN ist g/m3 mit MinVal -40 - dort IST
     * die Null ein gueltiger Messwert ("aussen genauso feucht wie innen").
     * Ein Waechter in Loxone auf GEWINN < 0.5 konnte den stummen Fuehler
     * deshalb nicht von der windstillen, gleich feuchten Nacht
     * unterscheiden. Die Nachbarfelder machen es laengst richtig: SPREAD
     * und VLMIN kommen als '-', DAUER und KOSTEN als -1. */
    $leer = array('lohnt' => 0, 'gewinn' => null, 'grund' => 'keine_daten',
                  'sperre' => 0);
    if ($af_innen === null || $af_aussen === null) { return $leer; }

    $gewinn = round($af_innen - $af_aussen, 3);
    $aus = function ($grund, $sperre = 0) use ($gewinn) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => $grund,
                     'sperre' => $sperre);
    };

    /* ---- Die harten Sperren, vor jeder Abwaegung ---- */
    if ((float) $regen_max > 0.0 && $regen !== null && (float) $regen > (float) $regen_max) {
        return $aus('regen', 1);
    }
    if ((float) $wind_max > 0.0 && $wind !== null && (float) $wind > (float) $wind_max) {
        return $aus('wind', 1);
    }
    if (rk_t_gueltig($wand_t)) {
        $td_aussen = rk_taupunkt($t_aussen, $rf_aussen);
        if ($td_aussen !== null
            && (float) $td_aussen > (float) $wand_t - (float) $wand_abstand) {
            return $aus('wand_tauwasser', 1);
        }
    }

    /* ---- Danach die Abwaegung ---- */
    if ($af_unter !== null && $af_innen < $af_unter) {
        return $aus('zu_trocken');
    }
    if ((float) $t_aussen < (float) $t_min) {
        return $aus('zu_kalt');
    }
    /* Hysterese: einschalten bei $mindest, ausschalten erst darunter.
     *
     * Gemessen am 24.08.2026: 0,4 Prozentpunkte Messrauschen an der
     * Aussenfeuchte kippten die Empfehlung, und der Cron laeuft alle fuenf
     * Minuten. Ein Fensterantrieb daran ist unruhig. */
    $schwelle = $lief ? (float) $mindest * max(0.0, min(1.0, (float) $hyst))
                      : (float) $mindest;
    if ($gewinn < $schwelle) {
        return $aus('aussen_feuchter');
    }
    return array('lohnt' => 1, 'gewinn' => $gewinn, 'grund' => 'lohnt',
                 'sperre' => 0);
}

/**
 * Lohnt Lueften, um zu KUEHLEN?
 *
 * Bis 0.9.9 bewertete das Plugin nur Feuchte. Gemessen: innen 25 Grad bei
 * 50 %, draussen 17 Grad bei 85 % - acht Kelvin kuehlere Nachtluft, und die
 * Antwort war "lohnt nicht, aussen ist feuchter". Fuer eine Anlage mit
 * Fensterantrieben fehlte damit die halbe Aussage.
 *
 * Bedingungen: der Raum ist ueber seiner Zieltemperatur, draussen ist es
 * mindestens $spanne kaelter, und die Aussenluft bringt nicht mehr Feuchte
 * herein, als $af_zusatz erlaubt.
 *
 * Seit 0.11.0 mit derselben Hysterese wie die Feuchteseite. Bis 0.10.1 hatte
 * diese Funktion keinen $lief-Parameter, und gemessen am 28.08.2026 kippte
 * die Empfehlung an 0,05 Kelvin Messrauschen im Fuenfminutentakt:
 *
 *     aussen 24.90 (Spanne 1.10 K) -> kuehlen=1
 *     aussen 25.02 (Spanne 0.98 K) -> kuehlen=0
 *     aussen 24.96 (Spanne 1.04 K) -> kuehlen=1
 *
 * Genau das Verhalten, das der Kommentar bei der Mindestdauer verhindern
 * will - nur auf der anderen Haelfte.
 */
function rk_kuehlen($t_innen, $rf_innen, $t_aussen, $rf_aussen, $t_soll,
                    $spanne = 1.0, $af_zusatz = 1.0, $lief = false, $hyst = 0.5)
{
    /* Wie bei rk_lueften(): KUEHLG ist K mit MinVal -40, die Null ist dort
     * ein Messwert. Ohne Daten und ohne Zieltemperatur gibt es keinen. */
    $leer = array('lohnt' => 0, 'gewinn' => null, 'grund' => 'keine_daten');
    if (!rk_t_gueltig($t_innen) || !rk_t_gueltig($t_aussen)) { return $leer; }
    if (!is_numeric($t_soll) || (float) $t_soll <= 0) {
        return array('lohnt' => 0, 'gewinn' => null, 'grund' => 'kein_ziel');
    }
    $t_innen = (float) $t_innen; $t_aussen = (float) $t_aussen;
    $gewinn = round($t_innen - $t_aussen, 2);
    if ($t_innen <= (float) $t_soll) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'ziel_erreicht');
    }
    $schwelle = $lief ? (float) $spanne * max(0.0, min(1.0, (float) $hyst))
                      : (float) $spanne;
    if ($gewinn < $schwelle) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'aussen_waermer');
    }
    $af_i = rk_absolut($t_innen, $rf_innen);
    $af_a = rk_absolut($t_aussen, $rf_aussen);
    if ($af_i !== null && $af_a !== null && ($af_a - $af_i) > (float) $af_zusatz) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'kuehl_zu_feucht');
    }
    return array('lohnt' => 1, 'gewinn' => $gewinn, 'grund' => 'kuehlen');
}

/**
 * Luftwechsel je Stunde - eine SCHAETZUNG, keine Messung.
 *
 * Die Spannen stammen aus der Bauphysik-Literatur und schwanken mit Wind,
 * Fenstergroesse und Raumgeometrie um ein Mehrfaches. Sie taugen fuer die
 * Aussage "zehn Minuten reichen" und nicht fuer eine Bilanz.
 *
 *     kipp   gekipptes Fenster        rund  1 pro Stunde
 *     stoss  Fenster ganz offen       rund 10
 *     quer   Durchzug, zwei Seiten    rund 25
 *
 * Der Antrieb ist der Temperaturunterschied (Auftrieb) und der Wind.
 */
function rk_luftwechsel($art, $dt, $wind = null)
{
    $grund = array('kipp' => 1.0, 'stoss' => 10.0, 'quer' => 25.0);
    $n = isset($grund[(string) $art]) ? $grund[(string) $art] : $grund['stoss'];
    $dt = is_numeric($dt) ? abs((float) $dt) : 0.0;
    $f = sqrt(max(1.0, $dt) / 10.0);              // 10 K ist der Bezugsfall
    $f = max(0.4, min(2.0, $f));
    if (is_numeric($wind)) {
        $f *= max(1.0, min(1.8, 1.0 + (float) $wind / 30.0));   // km/h
    }
    return round($n * $f, 2);
}

/**
 * Wie lange muss das Fenster offen bleiben, bis die Luft einmal getauscht
 * ist? Rueckgabe Minuten, auf 3 bis 60 begrenzt.
 */
function rk_lueftdauer($art, $dt, $wind = null)
{
    $n = rk_luftwechsel($art, $dt, $wind);
    if ($n <= 0) { return null; }
    return (int) max(3, min(60, round(60.0 / $n)));
}

/**
 * Was kostet dieser eine Luftwechsel an Waerme? Rueckgabe Wattstunden.
 *
 *     Q = V * rho * c * dT      rho 1,2 kg/m3, c 1,006 kJ/(kg K)
 *
 * Ein Gegengewicht zur Empfehlung: wer bei -5 Grad zwanzig Minuten quer
 * lueftet, sieht die Zahl.
 */
function rk_lueftkosten($volumen, $t_innen, $t_aussen)
{
    if (!is_numeric($volumen) || (float) $volumen <= 0) { return null; }
    if (!rk_t_gueltig($t_innen) || !rk_t_gueltig($t_aussen)) { return null; }
    $dt = (float) $t_innen - (float) $t_aussen;
    if ($dt <= 0) { return 0.0; }
    return round((float) $volumen * 1.2 * 1.006 * $dt / 3.6, 1);
}

/* ==================================================================
 * Neu in 0.11.0 - alles aus Groessen gerechnet, die das Plugin schon hat
 * ================================================================== */

/**
 * Schwuel oder nicht - am Mischungsverhaeltnis, nicht an der relativen
 * Feuchte.
 *
 * Die relative Feuchte taugt fuer diese Frage nicht: 60 % bei 20 Grad und
 * 60 % bei 28 Grad sind zwei voellig verschiedene Luftmengen. Massgeblich
 * ist, wie viel Wasser die Luft traegt - und dafuer steht rk_mischung()
 * schon da; es wurde bisher nur fuer die Enthalpie gebraucht.
 *
 * 11,5 g/kg ist die uebliche Behaglichkeitsgrenze. Das ist eine
 * EINSTELLUNG, keine Messung - sie steht deshalb in der Oberflaeche.
 *
 * Rueckgabe: 1 schwuel, 0 nicht, null wenn nicht zu rechnen.
 */
function rk_schwuel($t, $rf, $grenze = 11.5)
{
    $x = rk_mischung($t, $rf);
    if ($x === null || !is_numeric($grenze) || (float) $grenze <= 0) { return null; }
    return $x >= (float) $grenze ? 1 : 0;
}

/**
 * Wie viel Wasser traegt das Lueften je Stunde aus dem Raum? In Gramm.
 *
 *     m = n * V * (AF_innen - AF_aussen)
 *
 * n ist der Luftwechsel je Stunde (rk_luftwechsel), V das Raumvolumen,
 * die beiden Feuchten kommen aus rk_absolut(). Alle drei Groessen liegen
 * vor - es fehlte nur das Produkt.
 *
 * Das ist die Antwort auf "wie lange braucht die Waesche": eine Ladung
 * traegt rund 2 bis 2,5 kg Wasser.
 *
 * Rueckgabe null ohne Volumen - eine Zahl ohne Raumgroesse waere geraten.
 */
function rk_trocknen($art, $dt, $wind, $volumen, $af_innen, $af_aussen)
{
    if (!is_numeric($volumen) || (float) $volumen <= 0) { return null; }
    if ($af_innen === null || $af_aussen === null) { return null; }
    $n = rk_luftwechsel($art, $dt, $wind);
    if ($n <= 0) { return null; }
    $g = $n * (float) $volumen * ((float) $af_innen - (float) $af_aussen);
    return round($g, 1);
}

/**
 * Wie viele Stunden bei dieser Leistung, bis die Wassermenge heraus ist?
 * Rueckgabe null, wenn nichts herausgeht - lieber keine Zahl als eine
 * Restzeit, die nie ablaeuft.
 */
function rk_trockenrest($leistung_g_h, $wasser_g)
{
    if (!is_numeric($leistung_g_h) || (float) $leistung_g_h <= 0) { return null; }
    if (!is_numeric($wasser_g) || (float) $wasser_g <= 0) { return null; }
    return round((float) $wasser_g / (float) $leistung_g_h, 1);
}

/**
 * Rueckwaermzahl einer Lueftungsanlage aus drei Temperaturen.
 *
 *     eta = (t_zuluft - t_aussen) / (t_innen - t_aussen)
 *
 * Der Nenner wird gesperrt, sobald innen und aussen nahe beieinander
 * liegen: dann steht im Zaehler wie im Nenner fast nur Messrauschen, und
 * heraus kaeme eine Zahl, die zwischen 0 und 5 springt. Drei Kelvin sind
 * die uebliche Grenze.
 *
 * Rueckgabe in Prozent, auf 0 bis 100 begrenzt, oder null.
 */
function rk_wrg($t_innen, $t_aussen, $t_zuluft, $mindest_dt = 3.0)
{
    if (!rk_t_gueltig($t_innen) || !rk_t_gueltig($t_aussen)
        || !rk_t_gueltig($t_zuluft)) { return null; }
    $nenner = (float) $t_innen - (float) $t_aussen;
    if (abs($nenner) < (float) $mindest_dt) { return null; }
    $eta = ((float) $t_zuluft - (float) $t_aussen) / $nenner;
    return round(max(0.0, min(100.0, $eta * 100.0)), 1);
}

/**
 * Fortlufttemperatur einer Lueftungsanlage - und damit die Frage, ob der
 * Waermetauscher vereist.
 *
 *     t_fort = t_innen - eta * (t_innen - t_aussen)
 *
 * Unter null Grad friert das Kondensat im Tauscher. Die Rueckwaermzahl ist
 * eine ANGABE des Anlagenherstellers, keine Messung dieses Plugins - sie
 * steht deshalb je Raum in der Oberflaeche und ist ab Werk 0, also aus.
 */
function rk_fortluft($t_innen, $t_aussen, $eta_proz)
{
    if (!rk_t_gueltig($t_innen) || !rk_t_gueltig($t_aussen)) { return null; }
    if (!is_numeric($eta_proz) || (float) $eta_proz <= 0) { return null; }
    $eta = max(0.0, min(100.0, (float) $eta_proz)) / 100.0;
    $tf = (float) $t_innen - $eta * ((float) $t_innen - (float) $t_aussen);
    return rk_t_gueltig($tf) ? round($tf, 2) : null;
}

/**
 * Liegt der Zeitpunkt in der Ruhezeit dieses Raums?
 *
 * Die Angaben sind "HH:MM"; ein leeres Feld heisst "keine Ruhezeit". Ueber
 * Mitternacht hinweg wird richtig gerechnet - 22:00 bis 06:00 ist ein
 * Fenster, keine leere Menge.
 *
 * Rueckgabe 1, 0 - oder -1, wenn keine Ruhezeit eingestellt ist. Eine 0
 * hiesse "ist gerade nicht Ruhezeit" und waere eine Aussage; -1 heisst
 * "es gibt keine".
 */
function rk_ruhe_aktiv($von, $bis, $jetzt)
{
    $lies = function ($s) {
        $s = trim((string) $s);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m)) { return null; }
        return (int) $m[1] * 60 + (int) $m[2];
    };
    $a = $lies($von);
    $b = $lies($bis);
    if ($a === null || $b === null || $a === $b) { return -1; }
    $jetzt = (int) $jetzt;
    $min = (int) date('G', $jetzt) * 60 + (int) date('i', $jetzt);
    if ($a < $b) { return ($min >= $a && $min < $b) ? 1 : 0; }
    // ueber Mitternacht
    return ($min >= $a || $min < $b) ? 1 : 0;
}

/* ==================================================================
 * CO2 und die Personenzahl
 *
 * Bis 0.11.0 fehlte die Voraussetzung: ohne die Zahl der Personen im Raum
 * liess sich der CO2-Verlauf nicht rechnen, und eine geratene Zahl waere
 * schlimmer als keine gewesen. Sie ist jetzt ein Feld je Raum und ab Werk 0,
 * also aus.
 *
 * Die Rechnung ist die klassische Massenbilanz. Ein Mensch atmet CO2 aus;
 * wie viel, haengt an seiner Taetigkeit:
 *
 *     schlafend       rund 13 l/h
 *     sitzend, ruhig  rund 17 l/h     <- Vorgabe
 *     leichte Arbeit  rund 25 l/h
 *
 * DIESE ZAHL IST EINE EINSTELLUNG, KEINE MESSUNG. Sie steht deshalb in der
 * Oberflaeche und nicht fest im Code - genau wie fRsi und der Luftwechsel.
 * ================================================================== */

/**
 * Erwarteter CO2-Anstieg in ppm je Stunde, aus Personenzahl und Volumen.
 *
 *     dC/dt = E * 1e6 / V        E in m3/h, V in m3
 *
 * OHNE Luftwechsel gerechnet, also die obere Schranke: mit Infiltration
 * steigt es langsamer. Fuer eine Warnung ist das die richtige Richtung -
 * sie kommt eher zu frueh als zu spaet.
 *
 * Rueckgabe null ohne Personen oder ohne Volumen. Eine Null hiesse "es
 * steigt nicht", und das waere eine Aussage.
 */
function rk_co2_anstieg_erwartet($personen, $volumen, $liter_je_stunde = 17.0)
{
    if (!is_numeric($personen) || (float) $personen <= 0) { return null; }
    if (!is_numeric($volumen) || (float) $volumen <= 0) { return null; }
    if (!is_numeric($liter_je_stunde) || (float) $liter_je_stunde <= 0) { return null; }
    $e = (float) $personen * (float) $liter_je_stunde / 1000.0;   // m3/h
    return round($e * 1000000.0 / (float) $volumen, 0);
}

/**
 * Welcher Luftwechsel haelt die Grenze?
 *
 *     n = E * 1e6 / (V * (C_grenze - C_aussen))
 *
 * Die Zahl ist zum Vergleichen da: rk_luftwechsel() sagt, was gekipptes,
 * ganz geoeffnetes und quer gelueftetes Fenster leisten (rund 1, 10 und 25
 * je Stunde). Liegt der erforderliche Wert ueber 1, reicht Kippen nicht.
 *
 * Rueckgabe null, wenn die Grenze nicht ueber der Aussenluft liegt - dann
 * ist sie mit Lueften ueberhaupt nicht zu halten, und eine Zahl waere
 * irrefuehrend.
 */
function rk_co2_luftwechsel($personen, $volumen, $grenze, $aussen = 420.0,
                            $liter_je_stunde = 17.0)
{
    if (!is_numeric($personen) || (float) $personen <= 0) { return null; }
    if (!is_numeric($volumen) || (float) $volumen <= 0) { return null; }
    if (!is_numeric($grenze) || !is_numeric($aussen)) { return null; }
    $spanne = (float) $grenze - (float) $aussen;
    if ($spanne <= 0) { return null; }
    $e = (float) $personen * (float) $liter_je_stunde / 1000.0;
    return round($e * 1000000.0 / ((float) $volumen * $spanne), 2);
}

/**
 * Minuten, bis die CO2-Grenze erreicht ist.
 *
 * Gerechnet wird mit dem GEMESSENEN Anstieg aus dem Verlaufsspeicher, nicht
 * mit dem erwarteten: der gemessene enthaelt die Undichtigkeiten des Raums
 * und die tatsaechliche Zahl der Anwesenden. Der erwartete steht daneben und
 * dient dem Vergleich - wer beide sieht, erkennt einen Fuehler, der driftet,
 * und ein Fenster, das gekippt steht.
 *
 * Rueckgabe -1, wenn nichts zu sagen ist: kein Anstieg, fallende Werte, oder
 * die Grenze ist schon ueberschritten. Die Ueberschreitung selbst meldet
 * co2_hoch - zwei Namen fuer eine Sache waeren einer zu viel.
 */
function rk_co2_voll($co2_jetzt, $grenze, $anstieg_ppm_h)
{
    if (!is_numeric($co2_jetzt) || !is_numeric($grenze) || (float) $grenze <= 0) { return -1; }
    if ($anstieg_ppm_h === null || !is_numeric($anstieg_ppm_h)) { return -1; }
    $rest = (float) $grenze - (float) $co2_jetzt;
    if ($rest <= 0) { return -1; }
    /* Unter 10 ppm je Stunde ist der Anstieg Messrauschen; daraus eine
     * Restzeit zu rechnen ergaebe Tage und sieht aus wie eine Aussage. */
    if ((float) $anstieg_ppm_h < 10.0) { return -1; }
    return (int) min(2880, round($rest / (float) $anstieg_ppm_h * 60.0));
}

/**
 * Heizfall oder Kuehlfall - am gleitenden Aussenmittel, nicht an der
 * Momentantemperatur.
 *
 * Ein einzelner kuehler Augustabend macht keinen Heizfall, und ein warmer
 * Februartag keinen Kuehlfall. Massgeblich ist das gleitende Mittel der
 * letzten Tage; die Heizgrenze liegt ueblicherweise um 15 Grad und ist
 * eine EINSTELLUNG.
 *
 * Rueckgabe 1 Heizfall, 0 Kuehlfall, -1 noch keine Aussage. Die -1 ist
 * wichtig: der Aussenspeicher braucht ein paar Tage, bis er traegt, und in
 * dieser Zeit waere jede der beiden Zahlen geraten.
 */
function rk_heizfall($aussen_mittel, $grenze = 15.0)
{
    if ($aussen_mittel === null || !is_numeric($aussen_mittel)) { return -1; }
    if (!is_numeric($grenze)) { return -1; }
    return ((float) $aussen_mittel < (float) $grenze) ? 1 : 0;
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
 * ------------------------------------------------------------------
 * Die LAUFENDE Stunde zaehlt mit - seit 0.11.0
 * ------------------------------------------------------------------
 *
 * Die Vorhersage ist auf volle Stunden gerastert, der Cron laeuft es nicht.
 * Bis 0.10.1 filterte diese Funktion mit `$ts < $jetzt` und warf damit bei
 * JEDEM realen Lauf die laufende Stunde heraus. Gemessen am 28.08.2026 an
 * derselben Vorhersage, nur der Aufrufzeitpunkt wandert:
 *
 *      0 s nach der vollen Stunde -> in=  0  gewinn=6.150
 *     60 s nach der vollen Stunde -> in=119  gewinn=2.136
 *   3540 s nach der vollen Stunde -> in= 61  gewinn=2.136
 *
 * Die beste Stunde war die laufende, und sie kam nur zum Zuge, wenn der
 * Cron die Sekunde traf. rk_meteo_jetzt() rundet fuer denselben Datensatz
 * ausdruecklich auf die Stunde ab - die beiden Funktionen widersprachen
 * sich also innerhalb derselben Datei.
 *
 * Gefiltert wird jetzt ab dem BEGINN der laufenden Stunde. Damit wird 'in'
 * rechnerisch negativ, wenn die laufende Stunde gewinnt; es wird auf 0
 * geklemmt, denn "vor fuenf Minuten" ist als Empfehlung unbrauchbar.
 *
 * $kuehl ist array('t_innen','rf_innen','t_soll','spanne') oder null. Ist
 * es gesetzt, wird zusaetzlich die beste Stunde zum KUEHLEN gesucht. Bis
 * 0.10.1 sah diese Funktion nur die Feuchte, und ein Raum konnte in
 * derselben Ausgabe "jetzt kuehlen" und "beste Stunde in fuenf Stunden"
 * melden - zweierlei aus einem Abbild.
 *
 * Rueckgabe: array('jetzt'=>0/1, 'ts'=>Zeitstempel|null, 'in'=>Minuten|-1,
 *                  'gewinn'=>g/m3, 'grund'=>Kuerzel,
 *                  'kuehl_in'=>Minuten|-1, 'kuehl_std'=>Stunde|-1,
 *                  'kuehl_gewinn'=>Kelvin)
 */
function rk_bester_zeitpunkt($af_innen, $vorhersage, $jetzt, $mindest, $t_min,
                             $af_unter, $stunden = 12, $regen_max = 0.0,
                             $wind_max = 0.0, $wand_t = null,
                             $wand_abstand = 1.0, $kuehl = null)
{
    $erg = array('jetzt' => 0, 'ts' => null, 'in' => -1, 'gewinn' => 0.0,
                 'grund' => 'keine_vorhersage',
                 'kuehl_in' => -1, 'kuehl_std' => -1, 'kuehl_gewinn' => 0.0);
    if (!is_array($vorhersage) || !$vorhersage) { return $erg; }

    /* Der Beginn der laufenden Stunde, nicht der Augenblick. */
    $jetzt = (int) $jetzt;
    $ab = $jetzt - ($jetzt % 3600);
    $ende = $ab + max(1, (int) $stunden) * 3600;

    $best = null;
    $bestk = null;
    foreach ($vorhersage as $ts => $w) {
        $ts = (int) $ts;
        if ($ts < $ab || $ts >= $ende) { continue; }
        if (!is_array($w) || !isset($w['t'], $w['rf'])) { continue; }
        $regen = isset($w['regen']) ? $w['regen'] : null;
        $wind  = isset($w['wind']) ? $w['wind'] : null;

        if ($af_innen !== null) {
            /* Eine Stunde mit Starkregen oder Sturm ist kein guenstiger
             * Zeitpunkt, auch wenn die Luft dann trocken waere - und eine,
             * deren Taupunkt ueber der kalten Flaeche liegt, erst recht
             * nicht. Dieselben Sperren wie im Augenblicksfall. */
            $l = rk_lueften($af_innen, $w['t'], $w['rf'], $mindest, $t_min,
                            $af_unter, false, 0.5, $regen, $regen_max,
                            $wind, $wind_max, $wand_t, $wand_abstand);
            if ($l['lohnt'] && ($best === null || $l['gewinn'] > $best[1])) {
                $best = array($ts, $l['gewinn']);
            }
        }

        if (is_array($kuehl)) {
            if ((float) $regen_max > 0.0 && $regen !== null
                && (float) $regen > (float) $regen_max) { continue; }
            if ((float) $wind_max > 0.0 && $wind !== null
                && (float) $wind > (float) $wind_max) { continue; }
            $k = rk_kuehlen($kuehl['t_innen'], $kuehl['rf_innen'], $w['t'],
                            $w['rf'], $kuehl['t_soll'], $kuehl['spanne']);
            if ($k['lohnt'] && ($bestk === null || $k['gewinn'] > $bestk[1])) {
                $bestk = array($ts, $k['gewinn']);
            }
        }
    }

    if ($bestk !== null) {
        $erg['kuehl_in'] = (int) max(0, round(($bestk[0] - $jetzt) / 60));
        $erg['kuehl_std'] = (int) date('G', $bestk[0]);
        $erg['kuehl_gewinn'] = round($bestk[1], 2);
    }

    if ($best === null) {
        $erg['grund'] = ($af_innen === null) ? 'keine_daten' : 'kein_fenster';
        return $erg;
    }
    $erg['ts'] = $best[0];
    $erg['gewinn'] = round($best[1], 3);
    $erg['in'] = (int) max(0, round(($best[0] - $jetzt) / 60));
    $erg['jetzt'] = $erg['in'] < 60 ? 1 : 0;
    $erg['grund'] = 'gefunden';
    return $erg;
}

/* ==================================================================
 * Ein Raum, vollstaendig gerechnet
 * ================================================================== */

/**
 * Ausfallerkennung fortschreiben - veraendert $e an Ort und Stelle.
 *
 * Zwei verschiedene Fragen, und beide beantwortet bisher niemand:
 *   alter  Wie lange ist es her, dass fuer DIESEN Raum ein gueltiger Wert
 *          kam? Der Sammelwert ALTER sagt nur, wann der Abruf lief - er
 *          bleibt frisch, waehrend eine einzelne Quelle seit Tagen schweigt.
 *   steht  Bewegt sich der Wert noch? Ein eingefrorener Fuehler liefert
 *          unauffaellige Zahlen, nur eben immer dieselben. In Loxone faellt
 *          das nie auf: virtuelle Eingaenge behalten ohnehin ihren letzten
 *          Wert, in der App sieht dann alles normal aus.
 */
function rk_ausfall_fortschreiben(&$e, $letzt, $jetzt, $steht_min)
{
    $jetzt = (int) $jetzt;
    if ($e['ok']) {
        $e['letzt_ts'] = $jetzt;
        $gleich = is_array($letzt)
            && isset($letzt['t'], $letzt['rf'])
            && $letzt['t'] !== null && $letzt['rf'] !== null
            && abs((float) $letzt['t'] - (float) $e['t']) < 1e-9
            && abs((float) $letzt['rf'] - (float) $e['rf']) < 1e-9;
        $e['seit_ts'] = ($gleich && !empty($letzt['seit_ts']))
            ? (int) $letzt['seit_ts'] : $jetzt;
    } elseif (is_array($letzt)) {
        $e['letzt_ts'] = !empty($letzt['letzt_ts']) ? (int) $letzt['letzt_ts'] : 0;
        $e['seit_ts'] = !empty($letzt['seit_ts']) ? (int) $letzt['seit_ts'] : $jetzt;
    }
    $e['alter'] = $e['letzt_ts'] > 0 ? max(0, $jetzt - $e['letzt_ts']) : -1;
    $steht_min = (int) $steht_min;
    $e['steht'] = ($steht_min > 0 && $e['ok']
                   && ($jetzt - $e['seit_ts']) >= $steht_min * 60) ? 1 : 0;
}

/**
 * $raum    array('name','t','rf','frsi','soll_min','soll_max','art','erd_t',
 *                'einheit_t','einheit_rf','co2','fenster_offen','volumen',
 *                'fenster','t_soll','co2_max',
 *                'zuluft','wrg_eta','wasser_g','ruhe_von','ruhe_bis',
 *                'personen')
 * $aussen  array('t','rf','regen','wind','druck') - die Messwerte von jetzt
 * $vorher  array(ts => array('t','rf')) - die Vorhersage, oder leer
 * $cfg     array('mindest','t_min','af_unter','vorschau','steht_min','hyst',
 *                'dauer_min','regen_max','kuehl_spanne',
 *                'wind_max','wand_abstand','schwuel_x','co2_t_min',
 *                'zwang_std','vl_zuschlag','kuehlfrei_ein','kuehlfrei_aus',
 *                'co2_ltr','co2_aussen')
 * $letzt   der Eintrag desselben Raums aus dem vorigen Abruf, oder null
 * $verlauf array('nass24','nass7t','erfolg','eintrag','trend','dusche',
 *                'ohne_std','co2_anstieg') aus dem Verlaufsspeicher - hier nur
 *          durchgereicht, damit der Kern ohne Dateien auskommt und pruefbar
 *          bleibt
 *
 * Rueckgabe: ein Feld mit allen Werten, die nach Loxone gehen.
 */
function rk_raum_rechnen($raum, $aussen, $vorher, $cfg, $jetzt, $letzt = null,
                         $verlauf = null)
{
    /* Rohwerte einlesen: Einheit umrechnen, Text mit Einheit vertragen,
     * Unplausibles verwerfen. Was hier nicht durchkommt, ist ein FEHLENDER
     * Wert - nie eine 0. Eine Feuchte von genau 0 % gibt es in einem Raum
     * nicht; sie kommt von einem Fuehler, der nicht mehr antwortet. */
    $t  = rk_temp_c(rk_zahl_aus(isset($raum['t']) ? $raum['t'] : null),
                    isset($raum['einheit_t']) ? $raum['einheit_t'] : 'C');
    $rf = rk_rf_prozent(rk_zahl_aus(isset($raum['rf']) ? $raum['rf'] : null),
                        isset($raum['einheit_rf']) ? $raum['einheit_rf'] : 'proz');
    if (!rk_t_gueltig($t)) { $t = null; }
    if ($rf === null || $rf <= 0.0 || $rf > 100.0) { $rf = null; }

    $art = isset($raum['art']) ? (string) $raum['art'] : 'aussen';
    if (!in_array($art, array('aussen', 'keller', 'innen'), true)) { $art = 'aussen'; }
    $erd_t = isset($raum['erd_t']) ? rk_zahl_aus($raum['erd_t']) : null;
    $frsi = isset($raum['frsi']) ? $raum['frsi'] : 0.7;

    /* CO2: dritter, freiwilliger Pfad. 400 ppm ist Aussenluft, unter 250
     * liefert kein Fuehler etwas Sinnvolles - das ist ein Ausfall. */
    $co2 = rk_zahl_aus(isset($raum['co2']) ? $raum['co2'] : null);
    if ($co2 !== null && ($co2 < 250.0 || $co2 > 40000.0)) { $co2 = null; }
    $co2_max = isset($raum['co2_max']) ? (float) $raum['co2_max'] : 0.0;

    $e = array(
        'name'      => isset($raum['name']) ? (string) $raum['name'] : '',
        'ok'        => 0,
        't'         => $t !== null ? round($t, 2) : null,
        'rf'        => $rf !== null ? round($rf, 1) : null,
        'taupunkt'  => rk_taupunkt($t, $rf),
        'absolut'   => rk_absolut($t, $rf),
        'enth'      => rk_enthalpie($t, $rf),
        'ober_t'    => null,
        'ober_rf'   => null,
        'spread'    => null,
        'vlmin'     => null,
        'ampel'     => 0,
        'schimmel'  => 0,
        'nass24'    => -1,
        'nass7t'    => -1,
        'lueften'   => 0,
        'gewinn'    => null,
        'grund'     => 'keine_daten',
        'kuehlen'   => 0,
        'kuehlgewinn' => null,
        'dauer'     => -1,
        'kosten'    => -1,
        'erfolg'    => -1,
        'eintrag'   => -1,
        'co2'       => $co2,
        'co2_hoch'  => 0,
        'fenster'   => -1,
        'fenster_zu' => 0,
        'best_in'   => -1,
        'best_std'  => -1,
        'trocken'   => 0,
        'feucht'    => 0,
        'alter'     => -1,
        'steht'     => 0,
        'letzt_ts'  => 0,
        'seit_ts'   => (int) $jetzt,
        'lueften_seit' => 0,
        /* ---- Neu in 0.11.0. NEUE FELDER HAENGEN HINTEN AN.
         * Weiter oben eingefuegt verschoeben sie die Reihenfolge in der
         * Statuszeile, und jede beim Anwender eingetragene Befehlserkennung
         * zeigte danach auf einen anderen Wert. ---- */
        'schwuel'   => -1,
        'trocknen'  => null,
        'trockenrest' => null,
        'zuluft'    => null,
        'wrg'       => null,
        'fortluft'  => null,
        'vereist'   => 0,
        'ruhe'      => -1,
        'zwang'     => 0,
        'trend'     => null,
        'dusche'    => 0,
        'kuehlfrei' => -1,
        'kbest_in'  => -1,
        'kbest_std' => -1,
        'sperre'    => 0,
        /* ---- CO2 mit Personenzahl, neu am 28.08.2026 ---- */
        'co2_anstieg'  => null,   // gemessen, ppm/h
        'co2_erwartet' => null,   // erwartet aus Personen und Volumen, ppm/h
        'co2_voll'     => -1,     // Minuten bis zur Grenze, aus dem GEMESSENEN
        'co2_lw'       => null,   // erforderlicher Luftwechsel, 1/h
    );
    $e['ok'] = ($e['absolut'] !== null) ? 1 : 0;
    rk_ausfall_fortschreiben($e, $letzt, $jetzt,
        isset($cfg['steht_min']) ? (int) $cfg['steht_min'] : 60);

    /* Der Fensterkontakt gilt auch ohne Messwerte - er kommt aus Loxone und
     * nicht aus dem Klimafuehler. */
    if (isset($raum['fenster_offen']) && $raum['fenster_offen'] !== null) {
        $fo = $raum['fenster_offen'];
        if (is_bool($fo)) { $e['fenster'] = $fo ? 1 : 0; }
        else {
            $z = rk_zahl_aus($fo);
            if ($z !== null) { $e['fenster'] = $z > 0.5 ? 1 : 0; }
            elseif (is_string($fo)) {
                $s = strtolower(trim($fo));
                if (in_array($s, array('on', 'true', 'open', 'offen', 'auf'), true)) { $e['fenster'] = 1; }
                elseif (in_array($s, array('off', 'false', 'closed', 'zu', 'geschlossen'), true)) { $e['fenster'] = 0; }
            }
        }
    }

    /* Die Ruhezeit haengt an der Uhr, nicht an einem Messwert - sie gilt auch
     * bei stummem Fuehler. -1 heisst "keine eingestellt". */
    $e['ruhe'] = rk_ruhe_aktiv(isset($raum['ruhe_von']) ? $raum['ruhe_von'] : '',
                               isset($raum['ruhe_bis']) ? $raum['ruhe_bis'] : '',
                               $jetzt);

    /* Die harten Sperren brauchen NUR Aussenwerte. Sie gelten deshalb auch
     * dann, wenn der Raumfuehler schweigt - und genau darum geht es: bei
     * Starkregen soll ein offenes Fenster zugehen, auch wenn niemand mehr
     * sagen kann, wie feucht es drinnen ist. */
    $ta_h = isset($aussen['t']) ? $aussen['t'] : null;
    $ra_h = isset($aussen['rf']) ? $aussen['rf'] : null;
    $regen_h = isset($aussen['regen']) ? $aussen['regen'] : null;
    $wind_h = isset($aussen['wind']) ? $aussen['wind'] : null;
    $regen_max_h = isset($cfg['regen_max']) ? (float) $cfg['regen_max'] : 0.0;
    $wind_max_h = isset($cfg['wind_max']) ? (float) $cfg['wind_max'] : 0.0;
    if ($regen_max_h > 0.0 && $regen_h !== null && (float) $regen_h > $regen_max_h) {
        $e['sperre'] = 1;
    }
    if ($wind_max_h > 0.0 && $wind_h !== null && (float) $wind_h > $wind_max_h) {
        $e['sperre'] = 1;
    }

    if (!$e['ok']) {
        /* ------------------------------------------------------------------
         * Der Ausfall-Zweig - bis 0.10.1 kehrte er hier sofort zurueck
         * ------------------------------------------------------------------
         *
         * Zwei Dinge gingen dabei verloren, beide am 28.08.2026 gemessen:
         *
         * 1. KEIN SCHLIESSBEFEHL. Der Fensterkontakt wurde ausgewertet, das
         *    daraus abgeleitete 'fenster_zu' aber nicht mehr erreicht:
         *
         *        Fuehler antwortet : fenster=1 lueften=0 fenster_zu=1
         *        Fuehler stumm     : fenster=1 lueften=0 fenster_zu=0
         *
         *    Ein Fensterantrieb, der beim Ausfall des Klimafuehlers offen
         *    steht, blieb bei 9 mm/h Regen offen.
         *
         *    Geschlossen wird jetzt nur mit einem BELEGTEN Grund - Regen oder
         *    Sturm aus der Aussenquelle. Ohne Grund bleibt es, wie es ist:
         *    ein Antrieb, der bei jedem Funkaussetzer zufaehrt, waere die
         *    naechste stille Falschaussage.
         *
         * 2. HYSTERESE UND NACHLAUF wurden geloescht. 'lueften' und
         *    'lueften_seit' blieben auf ihren Startwerten, und ein einziger
         *    ausgefallener Abruf kippte die Empfehlung:
         *
         *        Lauf 1 lief schon    : lueften=1
         *        Lauf 2 als Ausfall   : lueften=0, lueften_seit=0
         *        Lauf 3 nach der Luecke: lueften=0
         *
         *    Bei einem funkgestoerten Fuehler und Fuenfminutentakt flatterte
         *    der Antrieb dadurch. Der Nachlauf traegt jetzt ueber die Luecke -
         *    aber nur, solange die Mindestdauer laeuft, und nie gegen eine
         *    harte Sperre.
         * ------------------------------------------------------------------ */
        if ($e['fenster'] === 1 && $e['sperre']) { $e['fenster_zu'] = 1; }

        $dauer_a = isset($cfg['dauer_min']) ? (int) $cfg['dauer_min'] : 0;
        $seit_a = (is_array($letzt) && !empty($letzt['lueften_seit']))
            ? (int) $letzt['lueften_seit'] : 0;
        if (!$e['sperre'] && !empty($letzt['lueften']) && $dauer_a > 0 && $seit_a > 0
            && ((int) $jetzt - $seit_a) < $dauer_a * 60) {
            $e['lueften'] = 1;
            $e['lueften_seit'] = $seit_a;
            $e['grund'] = 'nachlauf';
        }

        /* ------------------------------------------------------------------
         * 3. DIE AMPEL STAND HIER AUF 0 - also auf "unbedenklich".
         *
         * Gemessen am 30.08.2026. Der Ausfallzweig kehrt zurueck, BEVOR
         * rk_ampel() je gerufen wird; nach Loxone ging deshalb die 0 aus dem
         * Initialisierer:
         *
         *     Fuehler stumm     : ok=0  AMPEL=0  SCHIMMEL=0
         *     gemessen, harmlos : ok=1  AMPEL=0  SCHIMMEL=0
         *
         * Zwei Zustaende, ein Wert - genau die stille Falschaussage, fuer
         * die 0.11.0 die -1 und MinVal=-1 eingefuehrt hat. Der Ausfallzweig
         * wurde damals uebersehen, weil er vor der Rechnung aussteigt.
         *
         * Ein stummer Fuehler sagt ueber Schimmel NICHTS. Das ist etwas
         * anderes als "kein Schimmel", und wer einen Waechter auf AMPEL=0
         * legt, verlaesst sich sonst auf einen Fuehler, der seit Tagen
         * schweigt.
         * ------------------------------------------------------------------ */
        $e['ampel'] = -1;
        $e['schimmel'] = -1;
        return $e;
    }

    // Zielkorridor der Raumfeuchte
    $min = isset($raum['soll_min']) ? (float) $raum['soll_min'] : 0;
    $max = isset($raum['soll_max']) ? (float) $raum['soll_max'] : 0;
    if ($min > 0 && $e['rf'] !== null && $e['rf'] < $min) { $e['trocken'] = 1; }
    if ($max > 0 && $e['rf'] !== null && $e['rf'] > $max) { $e['feucht'] = 1; }

    $ta = isset($aussen['t']) ? $aussen['t'] : null;
    $ra = isset($aussen['rf']) ? $aussen['rf'] : null;
    $regen = isset($aussen['regen']) ? $aussen['regen'] : null;
    $wind = isset($aussen['wind']) ? $aussen['wind'] : null;

    $e['ober_t'] = rk_oberflaeche($t, $ta, $frsi, $art, $erd_t);
    $e['ober_rf'] = rk_rf_oberflaeche($t, $rf, $ta, $frsi, $art, $erd_t);
    $e['ampel'] = rk_ampel($e['ober_rf']);
    /* SCHIMMEL folgt der Ampel auch nach unten. Bis 0.11.1 stand hier
     * `$e['ampel'] >= 2 ? 1 : 0` - eine Ampel auf -1 ("keine Aussage
     * moeglich") wurde damit zu SCHIMMEL=0, also zu "kein Schimmel". Das
     * trifft jeden Raum der Art 'innen', fuer den es keine kalte Flaeche
     * gibt: AMPEL=-1 und SCHIMMEL=0 nebeneinander, und die 0 ist die
     * Aussage, die der Anwender liest. */
    $e['schimmel'] = $e['ampel'] < 0 ? -1 : ($e['ampel'] >= 2 ? 1 : 0);

    if ($e['ober_t'] !== null && $e['taupunkt'] !== null) {
        $e['spread'] = round($e['ober_t'] - $e['taupunkt'], 2);
    }
    /* Der Sicherheitszuschlag auf die Vorlauftemperatur ist eine EINSTELLUNG,
     * keine Naturkonstante. Bis 0.10.1 stand hier fest +1,0 - wer eine
     * Kuehldecke traegerer regelt, braucht mehr Abstand. */
    if ($e['taupunkt'] !== null) {
        $zu = isset($cfg['vl_zuschlag']) ? (float) $cfg['vl_zuschlag'] : 1.0;
        $e['vlmin'] = round($e['taupunkt'] + $zu, 2);
    }

    /* Freigabe fuer eine Flaechenkuehlung, mit getrennten Schaltpunkten.
     * Der Taupunktabstand wurde bis 0.10.1 nur ausgegeben; ein einzelner
     * Schwellwert daran flatterte an derselben Stelle wie jede andere
     * Ein-Bit-Entscheidung. -1 heisst "kein Abstand zu rechnen". */
    if ($e['spread'] !== null) {
        $ein = isset($cfg['kuehlfrei_ein']) ? (float) $cfg['kuehlfrei_ein'] : 3.0;
        $aus = isset($cfg['kuehlfrei_aus']) ? (float) $cfg['kuehlfrei_aus'] : 2.0;
        $war = (is_array($letzt) && isset($letzt['kuehlfrei']))
            ? (int) $letzt['kuehlfrei'] : 0;
        if ($war === 1) { $e['kuehlfrei'] = ($e['spread'] >= $aus) ? 1 : 0; }
        else            { $e['kuehlfrei'] = ($e['spread'] >= $ein) ? 1 : 0; }
    }

    /* Schwuel misst man am Mischungsverhaeltnis, nicht an der relativen
     * Feuchte - siehe rk_schwuel(). */
    $sw = rk_schwuel($t, $rf, isset($cfg['schwuel_x']) ? $cfg['schwuel_x'] : 11.5);
    if ($sw !== null) { $e['schwuel'] = $sw; }

    /* Lueftungsanlage: Zuluft messen, daraus Rueckwaermzahl und Fortluft. */
    $e['zuluft'] = rk_temp_c(rk_zahl_aus(isset($raum['zuluft']) ? $raum['zuluft'] : null),
                             isset($raum['einheit_t']) ? $raum['einheit_t'] : 'C');
    if (!rk_t_gueltig($e['zuluft'])) { $e['zuluft'] = null; }
    else { $e['zuluft'] = round((float) $e['zuluft'], 2); }
    if ($e['zuluft'] !== null) {
        $e['wrg'] = rk_wrg($t, $ta, $e['zuluft']);
    }
    $e['fortluft'] = rk_fortluft($t, $ta,
        isset($raum['wrg_eta']) ? $raum['wrg_eta'] : 0);
    if ($e['fortluft'] !== null && $e['fortluft'] < 0.0) { $e['vereist'] = 1; }

    /* Aus dem Verlaufsspeicher - Stunden, Quote, Gramm je Stunde, Trend,
     * Duschstoss und die Strecke ohne Empfehlung. */
    if (is_array($verlauf)) {
        foreach (array('nass24', 'nass7t', 'erfolg', 'eintrag') as $k) {
            if (isset($verlauf[$k]) && $verlauf[$k] !== null) { $e[$k] = $verlauf[$k]; }
        }
        if (isset($verlauf['trend'])) { $e['trend'] = $verlauf['trend']; }
        if (!empty($verlauf['dusche'])) { $e['dusche'] = 1; }
        if (isset($verlauf['co2_anstieg'])) { $e['co2_anstieg'] = $verlauf['co2_anstieg']; }
    }

    /* ---- CO2 mit Personenzahl ----
     * Der ERWARTETE Anstieg kommt aus Personenzahl und Volumen, der
     * GEMESSENE aus dem Verlauf. Beide stehen nebeneinander, weil sie
     * verschiedene Fragen beantworten: der erwartete sagt, was die
     * Anwesenden erzeugen, der gemessene, was davon im Raum bleibt. Wer
     * beide sieht, erkennt ein gekipptes Fenster und einen driftenden
     * Fuehler. Ein Name fuer beides waere eine stille Falschaussage. */
    $personen = isset($raum['personen']) ? (float) $raum['personen'] : 0.0;
    $co2_ltr = isset($cfg['co2_ltr']) ? (float) $cfg['co2_ltr'] : 17.0;
    $co2_aussen = isset($cfg['co2_aussen']) ? (float) $cfg['co2_aussen'] : 420.0;
    /* KEINE zusaetzliche Abfrage auf $personen > 0 an dieser Stelle: beide
     * Funktionen weisen ohne Personenzahl bereits selbst ab. Eine zweite
     * Wache davor waere eine zweite Wahrheit ueber dieselbe Bedingung - und
     * genau das ist beim Eichen aufgefallen: der Fall blieb gruen, weil die
     * aeussere Abfrage gar nichts entschied. */
    $e['co2_erwartet'] = rk_co2_anstieg_erwartet($personen,
        isset($raum['volumen']) ? $raum['volumen'] : 0, $co2_ltr);
    $e['co2_lw'] = rk_co2_luftwechsel($personen,
        isset($raum['volumen']) ? $raum['volumen'] : 0, $co2_max, $co2_aussen, $co2_ltr);
    /* Die Restzeit kommt aus dem gemessenen Anstieg. Steht der noch nicht zur
     * Verfuegung - die ersten Minuten nach dem Einschalten -, bleibt sie -1
     * statt aus dem erwarteten gerechnet zu werden: das waere eine Zahl aus
     * einer Schaetzung, die aussaehe wie eine aus einer Messung. */
    if ($co2 !== null && $co2_max > 0) {
        $e['co2_voll'] = rk_co2_voll($co2, $co2_max, $e['co2_anstieg']);
    }

    /* ---- Lueften: Feuchte, CO2, Kuehlen, Duschstoss oder Zwang ---- */
    $af_unter = (isset($cfg['af_unter']) && $cfg['af_unter'] > 0) ? (float) $cfg['af_unter'] : null;
    $lief = !empty($letzt['lueften']);
    $l = rk_lueften($e['absolut'], $ta, $ra,
        isset($cfg['mindest']) ? $cfg['mindest'] : 0.5,
        isset($cfg['t_min']) ? $cfg['t_min'] : -5,
        $af_unter, $lief,
        isset($cfg['hyst']) ? $cfg['hyst'] : 0.5,
        $regen, isset($cfg['regen_max']) ? $cfg['regen_max'] : 0.0,
        $wind, isset($cfg['wind_max']) ? $cfg['wind_max'] : 0.0,
        $e['ober_t'], isset($cfg['wand_abstand']) ? $cfg['wand_abstand'] : 1.0);
    $e['gewinn'] = $l['gewinn'];
    $e['grund'] = $l['grund'];
    if ($l['sperre']) { $e['sperre'] = 1; }

    $k = rk_kuehlen($t, $rf, $ta, $ra, isset($raum['t_soll']) ? $raum['t_soll'] : 0,
                    isset($cfg['kuehl_spanne']) ? $cfg['kuehl_spanne'] : 1.0, 1.0,
                    !empty($letzt['kuehlen']),
                    isset($cfg['hyst']) ? $cfg['hyst'] : 0.5);
    $e['kuehlen'] = $k['lohnt'];
    $e['kuehlgewinn'] = $k['gewinn'];

    /* CO2 hat eine eigene, mildere Frostgrenze. Gemessen am 28.08.2026:
     * bei -18 Grad aussen und t_min = -5 oeffnete CO2 das Fenster trotzdem,
     * weil dieser Grund an der Kaeltepruefung vorbeilief. Die Grenze ist
     * eine Einstellung - wer im Schlafzimmer Frischluft ueber alles stellt,
     * setzt sie herunter. */
    if ($co2 !== null && $co2_max > 0 && $co2 >= $co2_max) {
        $co2_t_min = isset($cfg['co2_t_min']) ? (float) $cfg['co2_t_min'] : -15.0;
        $e['co2_hoch'] = (rk_t_gueltig($ta) && (float) $ta < $co2_t_min) ? 0 : 1;
    }

    /* Zwangslueftung: N Stunden ohne jede Empfehlung. Der Feuchteschutz
     * haengt nicht davon ab, dass gerade eine Bilanz aufgeht. 0 = aus. */
    $zwang_std = isset($cfg['zwang_std']) ? (int) $cfg['zwang_std'] : 0;
    if ($zwang_std > 0 && is_array($verlauf) && isset($verlauf['ohne_std'])
        && $verlauf['ohne_std'] >= 0 && $verlauf['ohne_std'] >= $zwang_std) {
        $e['zwang'] = 1;
    }

    $e['lueften'] = ($l['lohnt'] || $e['co2_hoch'] || $k['lohnt']
                     || $e['dusche'] || $e['zwang']) ? 1 : 0;
    if (!$l['lohnt']) {
        if ($e['dusche'])        { $e['grund'] = 'dusche'; }
        elseif ($e['co2_hoch'])  { $e['grund'] = 'co2'; }
        elseif ($k['lohnt'])     { $e['grund'] = 'kuehlen'; }
        elseif ($e['zwang'])     { $e['grund'] = 'zwang'; }
    }

    /* Eine harte Sperre schlaegt JEDEN Grund - Regen, Sturm, Tauwasser an
     * der Wand. Bis 0.10.1 wurde dafuer die Zeichenkette 'grund' abgefragt,
     * und die trug die Sperre nur, wenn nicht vorher schon ein anderer
     * Zweig gegriffen hatte. Jetzt entscheidet ein Merkmal. */
    if ($e['sperre']) { $e['lueften'] = 0; $e['grund'] = $l['grund']; }

    /* Die Ruhezeit sperrt das Fenster - ausser bei CO2. Wer nachts nicht
     * geweckt werden will, will trotzdem keine verbrauchte Luft. */
    if ($e['ruhe'] === 1 && !$e['co2_hoch'] && $e['lueften']) {
        $e['lueften'] = 0;
        $e['grund'] = 'ruhezeit';
    }

    /* Mindestdauer: einmal empfohlen, bleibt die Empfehlung eine Weile
     * stehen. Sonst schaltet ein Fensterantrieb im Fuenfminutentakt. */
    $dauer_min = isset($cfg['dauer_min']) ? (int) $cfg['dauer_min'] : 0;
    $seit = ($lief && !empty($letzt['lueften_seit'])) ? (int) $letzt['lueften_seit'] : (int) $jetzt;
    if (!$e['lueften'] && $lief && $dauer_min > 0
        && ((int) $jetzt - $seit) < $dauer_min * 60
        && !$e['sperre'] && $e['ruhe'] !== 1) {
        $e['lueften'] = 1;
        $e['grund'] = 'nachlauf';
    }
    $e['lueften_seit'] = $e['lueften'] ? $seit : 0;

    /* ---- Wie lange, was kostet es, und was traegt es aus ---- */
    if ($e['lueften'] && rk_t_gueltig($ta)) {
        $e['dauer'] = rk_lueftdauer(isset($raum['fenster']) ? $raum['fenster'] : 'stoss',
                                    $t - (float) $ta, $wind);
        $kw = rk_lueftkosten(isset($raum['volumen']) ? $raum['volumen'] : 0, $t, $ta);
        if ($kw !== null) { $e['kosten'] = $kw; }
    }
    /* Trocknungsleistung: gilt auch, wenn gerade nicht empfohlen wird - die
     * Frage "wie lange braucht die Waesche" stellt sich vorher. */
    $e['trocknen'] = rk_trocknen(isset($raum['fenster']) ? $raum['fenster'] : 'stoss',
        rk_t_gueltig($ta) ? $t - (float) $ta : 0.0, $wind,
        isset($raum['volumen']) ? $raum['volumen'] : 0,
        $e['absolut'], rk_absolut($ta, $ra));
    $e['trockenrest'] = rk_trockenrest($e['trocknen'],
        isset($raum['wasser_g']) ? $raum['wasser_g'] : 0);

    /* ---- Fenster wieder schliessen? ---- */
    if ($e['fenster'] === 1 && !$e['lueften']) { $e['fenster_zu'] = 1; }

    $b = rk_bester_zeitpunkt($e['absolut'], $vorher, $jetzt,
        isset($cfg['mindest']) ? $cfg['mindest'] : 0.5,
        isset($cfg['t_min']) ? $cfg['t_min'] : -5,
        $af_unter,
        isset($cfg['vorschau']) ? $cfg['vorschau'] : 12,
        isset($cfg['regen_max']) ? $cfg['regen_max'] : 0.0,
        isset($cfg['wind_max']) ? $cfg['wind_max'] : 0.0,
        $e['ober_t'], isset($cfg['wand_abstand']) ? $cfg['wand_abstand'] : 1.0,
        array('t_innen' => $t, 'rf_innen' => $rf,
              't_soll' => isset($raum['t_soll']) ? $raum['t_soll'] : 0,
              'spanne' => isset($cfg['kuehl_spanne']) ? $cfg['kuehl_spanne'] : 1.0));
    $e['best_in'] = $b['in'];
    $e['best_std'] = ($b['ts'] !== null) ? (int) date('G', $b['ts']) : -1;
    $e['kbest_in'] = $b['kuehl_in'];
    $e['kbest_std'] = $b['kuehl_std'];
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
        . '&hourly=temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m'
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
         * ("2026-08-10T13:00"). strtotime nimmt dann die Zeitzone, die in
         * PHP gesetzt ist. Bis 0.11.2 setzte das Plugin gar keine, und ohne
         * date.timezone in der php.ini rechnet PHP in UTC - der Satz, der
         * hier stand ("die stimmt auf einem LoxBerry mit der Ortszeit
         * ueberein"), war eine Annahme ueber die php.ini, keine gemessene
         * Eigenschaft. Seit 0.11.3 setzt rk_zeitzone() sie aus
         * /etc/timezone; der Reiter Test zeigt, welche gerade gilt.
         * Ein blindes gmmktime() waere hier um die Zeitzone daneben. */
        $ts = strtotime((string) $h['time'][$i]);
        if ($ts === false || $ts <= 0) { continue; }
        $w = array('t' => (float) $h['temperature_2m'][$i],
                   'rf' => (float) $h['relative_humidity_2m'][$i]);
        /* Regen und Wind sind freiwillig: eine aeltere Antwort ohne diese
         * Reihen bleibt brauchbar, statt als REIHEN_FEHLEN auszufallen. */
        if (isset($h['precipitation'][$i]) && is_numeric($h['precipitation'][$i])) {
            $w['regen'] = (float) $h['precipitation'][$i];
        }
        if (isset($h['wind_speed_10m'][$i]) && is_numeric($h['wind_speed_10m'][$i])) {
            $w['wind'] = (float) $h['wind_speed_10m'][$i];
        }
        $out[$ts] = $w;
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

    /* ---------- Der stumme Fuehler sagt ueber Schimmel NICHTS ----------
     * Neu in 0.11.2. Bis 0.11.1 kehrte der Ausfallzweig zurueck, bevor
     * rk_ampel() gerufen wurde, und nach Loxone ging die 0 aus dem
     * Initialisierer - also "unbedenklich". Der GEGENFALL daneben ist die
     * eigentliche Ware: ein GEMESSENER harmloser Raum muss weiterhin 0
     * liefern, sonst hat man die eine Falschaussage gegen die andere
     * getauscht. */
    $pr('Stummer Fuehler: AMPEL und SCHIMMEL auf -1, nicht auf 0',
        array($leer['ampel'], $leer['schimmel']), array(-1, -1));
    $harmlos = rk_raum_rechnen(
        array('name' => 'Warm und trocken', 't' => 22.0, 'rf' => 35.0, 'frsi' => 0.9),
        array('t' => 15.0, 'rf' => 50.0), $vor, array(), $t0);
    $pr('Gemessen und harmlos: AMPEL und SCHIMMEL bleiben 0',
        array($harmlos['ok'], $harmlos['ampel'], $harmlos['schimmel']), array(1, 0, 0));
    /* Raumart 'innen' kennt keine kalte Flaeche - ober_rf bleibt null, die
     * Ampel steht auf -1, und SCHIMMEL folgt ihr seit 0.11.2 nach unten.
     * Bis dahin stand dort AMPEL=-1 neben SCHIMMEL=0. */
    $innen = rk_raum_rechnen(
        array('name' => 'Innenraum', 't' => 21.0, 'rf' => 55.0, 'art' => 'innen'),
        array('t' => 3.0, 'rf' => 85.0), $vor, array(), $t0);
    $pr('Raumart innen: keine Aussage, also beide auf -1',
        array($innen['ok'], $innen['ober_rf'], $innen['ampel'], $innen['schimmel']),
        array(1, null, -1, -1));

    /* ---------- Zahlen, wie fremde Quellen sie wirklich liefern ----------
     * Alle sechs Schreibweisen sind am 24.08.2026 an echten Antworten
     * gemessen worden; is_numeric() sagt zu jeder einzelnen nein. */
    $pr('Ecowitt-Feuchte "52%"', rk_zahl_aus('52%'), 52.0);
    $pr('Temperatur "24.6 C"', rk_zahl_aus('24.6 C'), 24.6);
    $pr('Miniserver-Temperatur mit Gradzeichen', rk_zahl_aus("22.5\u{00b0}"), 22.5);
    $pr('deutsches Komma "23,5"', rk_zahl_aus('23,5'), 23.5);
    $pr('Leerzeichen " 45 " - unter 7.4 keine Zahl, unter 8.4 eine',
        rk_zahl_aus(' 45 '), 45.0);
    $pr('negativ mit Einheit "-3.5 \u{00b0}C"', rk_zahl_aus("-3.5 \u{00b0}C"), -3.5);
    $pr('Zahl bleibt Zahl', rk_zahl_aus(71.6), 71.6);
    $pr('Fassungsangabe "1.2.3" ist keine Zahl', rk_zahl_aus('1.2.3'), null);
    $pr('Strich ist keine Zahl', rk_zahl_aus('-'), null);
    $pr('Leertext ist keine Zahl', rk_zahl_aus(''), null);
    $pr('Wort ist keine Zahl', rk_zahl_aus('abc'), null);
    $pr('unendlich ist keine Zahl', rk_zahl_aus(INF), null);

    $pr('Fahrenheit 71,6 sind 22,0 Grad', rk_temp_c(71.6, 'F'), 22.0, 0.01);
    $pr('Celsius bleibt Celsius', rk_temp_c(20, 'C'), 20.0, 0.001);
    $pr('Anteil 0,52 sind 52 %', rk_rf_prozent(0.52, 'anteil'), 52.0, 0.001);

    /* ---------- Der Gueltigkeitsbereich ----------
     * -273,15 machte den Nenner zu null: unter 7.4 INF und eine Zustands-
     * datei, die still nicht geschrieben wurde, unter 8.4 ein Abbruch des
     * ganzen Laufs. Jetzt kommt heraus, was herauskommen muss: nichts. */
    $pr('Saettigungsdruck bei -273,15 nicht gerechnet', rk_es(-273.15), null);
    $pr('absolute Feuchte bei -273,15 nicht gerechnet', rk_absolut(-273.15, 50), null);
    $pr('absolute Feuchte bei 999 Grad nicht gerechnet', rk_absolut(999, 50), null);
    $pr('Taupunkt bei -273,15 nicht gerechnet', rk_taupunkt(-273.15, 50), null);
    $pr('Umrechnung auf -273,15 nicht gerechnet', rk_rf_bei(-273.15, 20, 50), null);
    $pr('an der Bereichsgrenze wird noch gerechnet', is_float(rk_absolut(-60, 50)), true);

    /* ---------- Raumart ---------- */
    $pr('Sommer: die Aussenluft macht keine kalte Ecke (innen 24, aussen 32)',
        rk_oberflaeche(24, 32, 0.7), 24.0, 0.01);
    $pr('  und die Feuchte an dieser Flaeche ist die des Raums',
        rk_rf_oberflaeche(24, 60, 32, 0.7), 60.0, 0.5);
    $pr('Keller: die Wand folgt dem Erdreich, nicht der Aussenluft',
        rk_oberflaeche(18, 30, 0.7, 'keller', 13), 13.0, 0.01);
    $pr('  dort sind 70 % Raumluft ueber 90 % an der Wand - Sommerschimmel',
        rk_rf_oberflaeche(18, 70, 30, 0.7, 'keller', 13), 96.4, 0.5);
    $pr('  mit dem Aussenwandmodell sah derselbe Keller unauffaellig aus',
        rk_rf_oberflaeche(18, 70, 30, 0.7) < 80, true);
    $pr('Innenraum: keine Aussage statt einer erfundenen',
        rk_oberflaeche(20, 0, 0.7, 'innen'), null);
    $pr('Keller ohne Erdreichtemperatur: keine Aussage',
        rk_oberflaeche(18, 30, 0.7, 'keller', null), null);

    /* ---------- Taupunktabstand und Vorlaufgrenze ---------- */
    $rs = rk_raum_rechnen(
        array('name' => 'Wohnen', 't' => 20.0, 'rf' => 60.0, 'frsi' => 0.7),
        array('t' => 0.0, 'rf' => 80.0), array(), array(), $t0);
    $pr('Flaeche 14,0 Grad bei Taupunkt 12,0 - Abstand 2,0 K',
        array($rs['ober_t'], $rs['taupunkt'], $rs['spread']), array(14.0, 12.0, 2.0));
    $pr('  kleinste Vorlauftemperatur ist Taupunkt plus 1 K', $rs['vlmin'], 13.0, 0.01);

    /* ---------- Ein Raum, so wie die Quellen wirklich antworten ---------- */
    $re = rk_raum_rechnen(
        array('name' => 'Ecowitt', 't' => '24.6 C', 'rf' => '52%', 'frsi' => 0.7),
        array('t' => 3.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('Raum mit Einheiten im Text wird gelesen',
        array($re['ok'], $re['t'], $re['rf']), array(1, 24.6, 52.0));
    $rf0 = rk_raum_rechnen(
        array('name' => 'Defekt', 't' => 12.0, 'rf' => 0, 'frsi' => 0.7),
        array('t' => 3.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('Feuchte 0 % ist ein Ausfall, keine Messung',
        array($rf0['ok'], $rf0['absolut']), array(0, null));
    $rk0 = rk_raum_rechnen(
        array('name' => 'Kaputt', 't' => -273.15, 'rf' => 50, 'frsi' => 0.7),
        array('t' => 3.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('-273,15 ergibt einen Ausfall, keinen Abbruch',
        array($rk0['ok'], $rk0['absolut']), array(0, null));
    $rfa = rk_raum_rechnen(
        array('name' => 'Fahrenheit', 't' => '71.6', 'rf' => '0.52',
              'einheit_t' => 'F', 'einheit_rf' => 'anteil', 'frsi' => 0.7),
        array('t' => 3.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('Fahrenheit und Anteil werden umgerechnet',
        array($rfa['ok'], $rfa['t'], $rfa['rf']), array(1, 22.0, 52.0));

    /* ---------- Ausfallerkennung ---------- */
    $vor_a = array('name' => 'A', 't' => 20.0, 'rf' => 50.0, 'frsi' => 0.7);
    $lauf1 = rk_raum_rechnen($vor_a, array('t' => 3.0, 'rf' => 85.0), array(),
        array('steht_min' => 60), $t0);
    $pr('Erster Lauf: Wert ist frisch, steht nicht',
        array($lauf1['alter'], $lauf1['steht']), array(0, 0));
    $lauf2 = rk_raum_rechnen($vor_a, array('t' => 3.0, 'rf' => 85.0), array(),
        array('steht_min' => 60), $t0 + 7200, $lauf1);
    $pr('Zwei Stunden derselbe Wert: der Fuehler steht',
        array($lauf2['alter'], $lauf2['steht']), array(0, 1));
    $vor_b = array('name' => 'A', 't' => 20.5, 'rf' => 50.0, 'frsi' => 0.7);
    $lauf3 = rk_raum_rechnen($vor_b, array('t' => 3.0, 'rf' => 85.0), array(),
        array('steht_min' => 60), $t0 + 7500, $lauf2);
    $pr('  bewegt er sich wieder, steht er nicht mehr', $lauf3['steht'], 0);
    $lauf4 = rk_raum_rechnen(array('name' => 'A', 'frsi' => 0.7),
        array('t' => 3.0, 'rf' => 85.0), array(), array('steht_min' => 60),
        $t0 + 7500 + 1800, $lauf3);
    $pr('Faellt die Quelle aus, waechst das Alter dieses Raums',
        array($lauf4['ok'], $lauf4['alter'], $lauf4['steht']), array(0, 1800, 0));
    $lauf5 = rk_raum_rechnen($vor_a, array('t' => 3.0, 'rf' => 85.0), array(),
        array('steht_min' => 0), $t0 + 99999, $lauf1);
    $pr('steht_min 0 schaltet die Standmeldung ab', $lauf5['steht'], 0);

    /* ---------- Enthalpie und Mischungsverhaeltnis ----------
     * Lehrbuchwerte fuer 20 Grad / 50 %: rund 7,3 g/kg und rund 38,6 kJ/kg. */
    $pr('Mischungsverhaeltnis 20 C / 50 %', rk_mischung(20, 50), 7.26, 0.05);
    $pr('Enthalpie 20 C / 50 %', rk_enthalpie(20, 50), 38.6, 0.2);
    $pr('Enthalpie steigt mit der Feuchte',
        rk_enthalpie(20, 80) > rk_enthalpie(20, 50), true);
    $pr('Enthalpie ohne brauchbare Eingabe', rk_enthalpie(null, 50), null);

    /* ---------- Die Schimmelampel ---------- */
    $pr('Ampel unter 70 % ist 0', rk_ampel(65), 0);
    $pr('Ampel 70 bis 80 ist 1', rk_ampel(75), 1);
    $pr('Ampel 80 bis 95 ist 2', rk_ampel(85), 2);
    $pr('Ampel ab 95 ist 3', rk_ampel(97), 3);
    /* Bis 0.10.1 stand hier eine 0 - und die war von "gemessen und
     * unbedenklich" nicht zu unterscheiden. */
    $pr('Ampel ohne Wert ist -1, nicht 0', rk_ampel(null), -1);
    $pr('  und die 0 bleibt der gemessenen, unbedenklichen Wand', rk_ampel(65), 0);

    /* ---------- Hysterese ----------
     * Derselbe Gewinn, zwei Antworten - je nachdem, ob eben schon
     * empfohlen wurde. Ohne das kippte die Empfehlung an 0,4
     * Prozentpunkten Messrauschen, alle fuenf Minuten. */
    $af_h = rk_absolut(21, 55);
    $l_aus = rk_lueften($af_h, 15, 75.0, 0.5, -5, null, false, 0.5);
    $l_an  = rk_lueften($af_h, 15, 75.0, 0.5, -5, null, true, 0.5);
    $pr('Gewinn knapp unter der Schwelle: aus bleibt aus', $l_aus['lohnt'], 0);
    $pr('  lief es schon, bleibt es an', $l_an['lohnt'], 1);
    $pr('  weit darunter geht auch das Laufende aus',
        rk_lueften($af_h, 15, 90.0, 0.5, -5, null, true, 0.5)['lohnt'], 0);

    /* ---------- Regensperre ---------- */
    $l_r = rk_lueften(rk_absolut(20, 60), 8, 60, 0.5, -5, null, false, 0.5, 2.0, 1.0);
    $pr('Starkregen sperrt die Empfehlung',
        array($l_r['lohnt'], $l_r['grund']), array(0, 'regen'));
    $l_r2 = rk_lueften(rk_absolut(20, 60), 8, 60, 0.5, -5, null, false, 0.5, 0.2, 1.0);
    $pr('  Nieselregen unter der Grenze nicht', $l_r2['lohnt'], 1);
    $l_r3 = rk_lueften(rk_absolut(20, 60), 8, 60, 0.5, -5, null, false, 0.5, 9.0, 0.0);
    $pr('  Grenze 0 schaltet die Sperre ab', $l_r3['lohnt'], 1);

    /* ---------- Sommer-Nachtkuehlung ----------
     * Der Fall, der bis 0.9.9 unbewertet blieb: acht Kelvin kuehlere
     * Nachtluft, und die Antwort war "aussen ist feuchter". */
    $k = rk_kuehlen(25, 50, 17, 85, 23);
    $pr('Nachtluft ist acht Kelvin kuehler: kuehlen lohnt',
        array($k['lohnt'], $k['gewinn'], $k['grund']), array(1, 8.0, 'kuehlen'));
    $k2 = rk_kuehlen(25, 50, 17, 98, 23);
    $pr('  ist sie zu feucht, lohnt es nicht',
        array($k2['lohnt'], $k2['grund']), array(0, 'kuehl_zu_feucht'));
    $k3 = rk_kuehlen(22, 50, 17, 60, 23);
    $pr('  unter der Zieltemperatur ist nichts zu tun',
        array($k3['lohnt'], $k3['grund']), array(0, 'ziel_erreicht'));
    $k4 = rk_kuehlen(25, 50, 24.5, 60, 23);
    $pr('  ein halbes Kelvin ist kein Gewinn',
        array($k4['lohnt'], $k4['grund']), array(0, 'aussen_waermer'));
    $k5 = rk_kuehlen(25, 50, 17, 60, 0);
    $pr('  ohne Zieltemperatur keine Aussage',
        array($k5['lohnt'], $k5['grund']), array(0, 'kein_ziel'));

    /* ---------- Luftwechsel, Dauer, Kosten ---------- */
    $pr('Luftwechsel gekippt bei 10 K', rk_luftwechsel('kipp', 10), 1.0, 0.01);
    $pr('Luftwechsel Stosslueftung bei 10 K', rk_luftwechsel('stoss', 10), 10.0, 0.01);
    $pr('Luftwechsel Querlueftung bei 10 K', rk_luftwechsel('quer', 10), 25.0, 0.01);
    $pr('  mehr Temperaturunterschied, mehr Luftwechsel',
        rk_luftwechsel('stoss', 30) > rk_luftwechsel('stoss', 10), true);
    $pr('  Wind hilft zusaetzlich',
        rk_luftwechsel('stoss', 10, 30) > rk_luftwechsel('stoss', 10, 0), true);
    $pr('Stosslueften bei 10 K: rund sechs Minuten', rk_lueftdauer('stoss', 10), 6);
    $pr('Kippen dauert viel laenger', rk_lueftdauer('kipp', 10), 60);
    $pr('Querlueften wird bei drei Minuten gedeckelt', rk_lueftdauer('quer', 10), 3);
    /* 50 m3 um 20 K erwaermen: 50 * 1,2 * 1,006 * 20 / 3,6 = 335 Wh */
    $pr('Waermeverlust eines Luftwechsels', rk_lueftkosten(50, 21, 1), 335.3, 0.5);
    $pr('  im Sommer kostet Lueften keine Heizwaerme', rk_lueftkosten(50, 21, 25), 0.0, 0.01);
    $pr('  ohne Raumvolumen keine Aussage', rk_lueftkosten(0, 21, 1), null);

    /* ---------- Ein Raum mit allem ---------- */
    $gr = array('name' => 'Bad', 't' => 20.0, 'rf' => 60.0, 'frsi' => 0.7,
                'soll_min' => 40, 'soll_max' => 60, 'volumen' => 30,
                'fenster' => 'stoss', 't_soll' => 0, 'co2_max' => 1000);
    $ga = array('t' => 0.0, 'rf' => 80.0);
    $gc = array('mindest' => 0.5, 't_min' => -20, 'af_unter' => 0, 'vorschau' => 12,
                'hyst' => 0.5, 'dauer_min' => 10, 'regen_max' => 1.0);
    $r1 = rk_raum_rechnen($gr, $ga, array(), $gc, $t0);
    $pr('Raum: Ampel 2 an der kalten Ecke, Schimmel 1',
        array($r1['ampel'], $r1['schimmel']), array(2, 1));
    $pr('  Lueften lohnt, Dauer und Kosten stehen dabei',
        array($r1['lueften'], $r1['dauer'] > 0, $r1['kosten'] > 0), array(1, true, true));
    $pr('  Enthalpie ist gerechnet', is_float($r1['enth']), true);

    /* CO2 allein reicht als Grund. */
    $gr2 = $gr; $gr2['co2'] = 1400; $gr2['rf'] = 45.0;
    $r2 = rk_raum_rechnen($gr2, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0);
    $pr('Hohes CO2 loest Lueften aus, auch wenn die Feuchte dagegen spricht',
        array($r2['lueften'], $r2['grund'], $r2['co2_hoch']), array(1, 'co2', 1));
    $gr3 = $gr2; $gr3['co2'] = '900 ppm';
    $r3 = rk_raum_rechnen($gr3, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0);
    $pr('  unter der Schwelle nicht, und "900 ppm" wird gelesen',
        array($r3['lueften'], $r3['co2']), array(0, 900.0));
    $gr4 = $gr2; $gr4['co2'] = 120;
    $r4 = rk_raum_rechnen($gr4, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0);
    $pr('  120 ppm kann kein Fuehler messen: Ausfall statt Zahl', $r4['co2'], null);

    /* Nachlauf: einmal empfohlen, bleibt es die Mindestdauer stehen. */
    $vor_an = array('lueften' => 1, 'lueften_seit' => $t0 - 300, 't' => 20.0, 'rf' => 45.0);
    $r5 = rk_raum_rechnen($gr2, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0, $vor_an);
    $gr6 = $gr; $gr6['rf'] = 45.0;
    $r6 = rk_raum_rechnen($gr6, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0, $vor_an);
    $pr('Nachlauf: die Empfehlung bleibt die Mindestdauer stehen',
        array($r6['lueften'], $r6['grund']), array(1, 'nachlauf'));
    $vor_alt = array('lueften' => 1, 'lueften_seit' => $t0 - 3600, 't' => 20.0, 'rf' => 45.0);
    $r7 = rk_raum_rechnen($gr6, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0, $vor_alt);
    $pr('  nach der Mindestdauer geht sie aus', $r7['lueften'], 0);

    /* Regen sperrt auch den CO2-Grund. */
    $r8 = rk_raum_rechnen($gr2, array('t' => 18.0, 'rf' => 95.0, 'regen' => 4.0),
                          array(), $gc, $t0);
    $pr('Starkregen sperrt auch die CO2-Empfehlung',
        array($r8['lueften'], $r8['grund']), array(0, 'regen'));

    /* Fensterkontakt. */
    $gr9 = $gr6; $gr9['fenster_offen'] = 'true';
    $r9 = rk_raum_rechnen($gr9, array('t' => 18.0, 'rf' => 95.0), array(), $gc, $t0);
    $pr('Fenster offen und Lueften lohnt nicht: wieder schliessen',
        array($r9['fenster'], $r9['fenster_zu']), array(1, 1));
    $gr10 = $gr; $gr10['fenster_offen'] = 0;
    $r10 = rk_raum_rechnen($gr10, $ga, array(), $gc, $t0);
    $pr('  geschlossenes Fenster meldet nichts',
        array($r10['fenster'], $r10['fenster_zu']), array(0, 0));

    /* Kuehlen als Grund im ganzen Raum. */
    /* Traegt schon die Feuchte, wird SIE genannt - die Kuehlung steht als
     * eigener Wert daneben. Zwei Gruende sind kein Widerspruch, aber nur
     * einer kann in der Zeile stehen. */
    $gr11 = array('name' => 'Dach', 't' => 27.0, 'rf' => 50.0, 'frsi' => 0.7,
                  't_soll' => 24, 'volumen' => 40, 'fenster' => 'quer');
    $r11 = rk_raum_rechnen($gr11, array('t' => 19.0, 'rf' => 75.0), array(), $gc, $t0);
    $pr('Sommernacht: Feuchte traegt schon, Kuehlen steht daneben',
        array($r11['lueften'], $r11['grund'], $r11['kuehlen']), array(1, 'lohnt', 1));
    /* Und der Fall, den es bis 0.9.9 gar nicht gab: die Aussenluft ist
     * etwas feuchter, aber sieben Kelvin kuehler. Nach Feuchte allein
     * lautete die Antwort "lohnt nicht". */
    $gr11b = $gr11; $gr11b['rf'] = 45.0;
    $r11b = rk_raum_rechnen($gr11b, array('t' => 20.0, 'rf' => 70.0), array(), $gc, $t0);
    $pr('  ist die Aussenluft leicht feuchter, traegt allein das Kuehlen',
        array($r11b['lueften'], $r11b['grund'], $r11b['kuehlen'],
              $r11b['gewinn'] < 0), array(1, 'kuehlen', 1, true));

    /* Werte aus dem Verlauf werden durchgereicht. */
    $r12 = rk_raum_rechnen($gr, $ga, array(), $gc, $t0, null,
        array('nass24' => 6.5, 'nass7t' => 31.0, 'erfolg' => 75, 'eintrag' => 120.0));
    $pr('Verlaufswerte kommen durch',
        array($r12['nass24'], $r12['nass7t'], $r12['erfolg'], $r12['eintrag']),
        array(6.5, 31.0, 75, 120.0));

    /* ================================================================
     * Neu in 0.11.0 - jeder Fall gehoert zu einem Befund vom 28.08.2026
     * oder zu einer neu aufgenommenen Funktion. Jeder ist so gebaut, dass
     * er OHNE die Korrektur rot wird; das ist an einer zurueckgebauten
     * Kopie nachgemessen worden.
     * ================================================================ */

    /* ---------- B7: Tauwasser an der kalten Flaeche ----------
     * Keller 20 C / 72 %, erdberuehrte Wand 13 C. Draussen 25 C / 51 % ist
     * absolut TROCKENER (Gewinn +0,70 g/m3), aber ihr Taupunkt liegt mit
     * 14,16 C um 1,2 K ueber der Wand. Wer so lueftet, holt Tauwasser an
     * die Kellerwand - der Fall, den der Kopfkommentar zu rk_oberflaeche()
     * als Sommerschimmel beschreibt. */
    $af_kel = rk_absolut(20, 72);
    $lk = rk_lueften($af_kel, 25, 51, 0.5, -5, null, false, 0.5, null, 0.0,
                     null, 0.0, 13.0, 1.0);
    $pr('Kellerwand: Aussenluft ist trockener, kondensiert aber an der Wand',
        array($lk['lohnt'], $lk['grund'], $lk['sperre']),
        array(0, 'wand_tauwasser', 1));
    $pr('  ohne die Wandangabe raet dieselbe Rechnung zum Lueften',
        rk_lueften($af_kel, 25, 51, 0.5, -5, null)['lohnt'], 1);
    $lk2 = rk_lueften($af_kel, 22, 45, 0.5, -5, null, false, 0.5, null, 0.0,
                      null, 0.0, 13.0, 1.0);
    $pr('  trockenere Aussenluft (Taupunkt 9,5 C) darf weiter herein',
        array($lk2['lohnt'], $lk2['grund']), array(1, 'lohnt'));
    $lk3 = rk_lueften($af_kel, 25, 51, 0.5, -5, null, false, 0.5, null, 0.0,
                      null, 0.0, 16.0, 1.0);
    $pr('  bei waermerer Wand (16 C) ist derselbe Fall unbedenklich',
        $lk3['lohnt'], 1);

    /* ---------- B8: die harte Sperre haengt nicht mehr an einer Zeichenkette ----------
     * Bei -6 C und 3 mm Niederschlag griff bis 0.10.1 zuerst 'zu_kalt';
     * 'grund' wurde nie 'regen', und die Sperre fuer CO2 lief ins Leere. */
    $lr = rk_lueften(rk_absolut(20, 45), -6, 90, 0.5, -5, null, false, 0.5, 3.0, 1.0);
    $pr('Schneefall unter der Kaeltegrenze: Sperre greift trotzdem',
        array($lr['grund'], $lr['sperre']), array('regen', 1));
    $lt = rk_lueften(rk_absolut(20, 30), 8, 60, 0.5, -20, 6.0, false, 0.5, 3.0, 1.0);
    $pr('  und auch dann, wenn die Raumluft zu trocken waere',
        array($lt['grund'], $lt['sperre']), array('regen', 1));
    $ln = rk_lueften(rk_absolut(20, 60), 8, 60, 0.5, -5, null, false, 0.5, 0.2, 1.0);
    $pr('  Nieselregen unter der Grenze sperrt nicht',
        array($ln['lohnt'], $ln['sperre']), array(1, 0));

    /* ---------- V12: Windsperre ---------- */
    $lw = rk_lueften(rk_absolut(20, 60), 8, 60, 0.5, -5, null, false, 0.5,
                     null, 0.0, 65.0, 40.0);
    $pr('Sturm sperrt das Fenster', array($lw['lohnt'], $lw['grund'], $lw['sperre']),
        array(0, 'wind', 1));
    $pr('  Grenze 0 schaltet die Windsperre ab',
        rk_lueften(rk_absolut(20, 60), 8, 60, 0.5, -5, null, false, 0.5,
                   null, 0.0, 65.0, 0.0)['lohnt'], 1);

    /* ---------- B6: Kuehlen bekommt dieselbe Hysterese wie die Feuchte ----------
     * 0,98 K Spanne bei Schwelle 1,0: aus bleibt aus, laufend bleibt an.
     * Die Aussenfeuchte muss dabei niedrig genug sein, sonst greift zuerst
     * 'kuehl_zu_feucht' und der Fall misst etwas anderes: 24,02 C bei 60 %
     * traegt 13,04 g/m3 gegen 11,48 innen, also 1,56 mehr - ueber dem
     * zulaessigen Zusatz von 1,0. Mit 45 % sind es 9,78 g/m3. */
    $pr('Kuehlen knapp unter der Spanne: aus bleibt aus',
        rk_kuehlen(25, 50, 24.02, 45, 23, 1.0, 1.0, false, 0.5)['lohnt'], 0);
    $pr('  lief es schon, bleibt es an',
        rk_kuehlen(25, 50, 24.02, 45, 23, 1.0, 1.0, true, 0.5)['lohnt'], 1);
    $pr('  weit darunter geht auch das Laufende aus',
        rk_kuehlen(25, 50, 24.9, 45, 23, 1.0, 1.0, true, 0.5)['lohnt'], 0);
    $pr('  und die Feuchtesperre bleibt davon unberuehrt',
        rk_kuehlen(25, 50, 24.02, 60, 23, 1.0, 1.0, true, 0.5)['grund'],
        'kuehl_zu_feucht');

    /* ---------- B12: die laufende Stunde faellt nicht mehr heraus ---------- */
    $vz = array($t0            => array('t' => 14.0, 'rf' => 40.0),
                $t0 + 3600     => array('t' => 12.0, 'rf' => 88.0),
                $t0 + 2 * 3600 => array('t' => 13.0, 'rf' => 78.0));
    $af_z = rk_absolut(21.0, 60.0);
    $bz0 = rk_bester_zeitpunkt($af_z, $vz, $t0, 0.5, -5, null, 12);
    $bz5 = rk_bester_zeitpunkt($af_z, $vz, $t0 + 300, 0.5, -5, null, 12);
    $bz59 = rk_bester_zeitpunkt($af_z, $vz, $t0 + 3540, 0.5, -5, null, 12);
    $pr('Bester Zeitpunkt auf die Sekunde genau',
        array($bz0['in'], $bz0['gewinn']), array(0, $bz0['gewinn']));
    $pr('  fuenf Minuten spaeter dieselbe Stunde und derselbe Gewinn',
        array($bz5['in'], $bz5['gewinn']), array(0, $bz0['gewinn']));
    $pr('  und 59 Minuten spaeter immer noch',
        array($bz59['in'], $bz59['gewinn']), array(0, $bz0['gewinn']));
    $pr('  "in" wird nie negativ', $bz59['in'] >= 0, true);

    /* ---------- V11: die Vorschau kennt jetzt auch das Kuehlen ---------- */
    $vk = array($t0            => array('t' => 26.0, 'rf' => 50.0),
                $t0 + 3600     => array('t' => 22.0, 'rf' => 55.0),
                $t0 + 2 * 3600 => array('t' => 19.0, 'rf' => 60.0));
    $bk = rk_bester_zeitpunkt(rk_absolut(27, 50), $vk, $t0, 0.5, -5, null, 12,
        0.0, 0.0, null, 1.0,
        array('t_innen' => 27.0, 'rf_innen' => 50.0, 't_soll' => 24.0, 'spanne' => 1.0));
    $pr('Kuehlvorschau findet die kaelteste taugliche Stunde',
        array($bk['kuehl_in'], $bk['kuehl_std']), array(120, (int) date('G', $t0 + 2 * 3600)));
    $bk2 = rk_bester_zeitpunkt(rk_absolut(27, 50), $vk, $t0, 0.5, -5, null, 12);
    $pr('  ohne Kuehlangabe bleibt sie stumm', $bk2['kuehl_in'], -1);

    /* ---------- V17: Schwuele am Mischungsverhaeltnis ---------- */
    $pr('28 C / 60 % sind schwuel', rk_schwuel(28, 60, 11.5), 1);
    $pr('20 C / 60 % nicht', rk_schwuel(20, 60, 11.5), 0);
    $pr('  ohne Messwert keine Aussage', rk_schwuel(null, 60, 11.5), null);

    /* ---------- V13: Trocknungsleistung ----------
     * 30 m3, Stosslueftung bei 10 K, innen 10,0 aussen 5,0 g/m3:
     * n = 10, also 10 * 30 * 5,0 = 1500 g/h. */
    $pr('Trocknungsleistung 30 m3, 5 g/m3 Unterschied',
        rk_trocknen('stoss', 10, null, 30, 10.0, 5.0), 1500.0, 1.0);
    $pr('  ohne Raumvolumen keine Zahl', rk_trocknen('stoss', 10, null, 0, 10.0, 5.0), null);
    $pr('  Restzeit fuer 2500 g bei 1500 g/h', rk_trockenrest(1500.0, 2500), 1.7, 0.05);
    $pr('  keine Leistung, keine Restzeit', rk_trockenrest(0.0, 2500), null);
    $pr('  keine Wassermenge, keine Restzeit', rk_trockenrest(1500.0, 0), null);

    /* ---------- V21/V22: Rueckwaermzahl und Fortluft ----------
     * innen 21, aussen 1, Zuluft 17: (17-1)/(21-1) = 0,80. */
    $pr('Rueckwaermzahl aus drei Temperaturen', rk_wrg(21, 1, 17), 80.0, 0.1);
    $pr('  bei kleiner Spanne gesperrt (Rauschen im Zaehler und Nenner)',
        rk_wrg(21, 19, 20), null);
    $pr('  ohne Zuluftwert keine Aussage', rk_wrg(21, 1, null), null);
    /* Fortluft: 21 - 0,8 * (21-1) = 5,0 - kein Vereisen. */
    $pr('Fortluft bei 80 % Rueckgewinn', rk_fortluft(21, 1, 80), 5.0, 0.05);
    /* Bei -12 aussen: 21 - 0,8 * 33 = -5,4 - der Tauscher friert. */
    $pr('  bei -12 C aussen friert der Tauscher', rk_fortluft(21, -12, 80) < 0, true);
    $pr('  ohne Rueckwaermzahl keine Aussage', rk_fortluft(21, 1, 0), null);

    /* ---------- V23: Ruhezeit, auch ueber Mitternacht ---------- */
    $nacht = mktime(23, 30, 0, 11, 15, 2026);
    $morgen = mktime(7, 30, 0, 11, 15, 2026);
    $pr('Ruhezeit 22:00-06:00 gilt um 23:30', rk_ruhe_aktiv('22:00', '06:00', $nacht), 1);
    $pr('  und nicht um 07:30', rk_ruhe_aktiv('22:00', '06:00', $morgen), 0);
    $pr('  ohne Angabe gibt es keine Ruhezeit (-1, nicht 0)',
        rk_ruhe_aktiv('', '', $nacht), -1);
    $pr('  eine unleserliche Angabe ebenso',
        rk_ruhe_aktiv('halb elf', '06:00', $nacht), -1);
    $pr('  Fenster ohne Mitternacht: 13:00-15:00 um 23:30',
        rk_ruhe_aktiv('13:00', '15:00', $nacht), 0);

    /* ---------- V20: Heizfall am gleitenden Mittel ---------- */
    $pr('Aussenmittel 9 Grad ist Heizfall', rk_heizfall(9.0, 15.0), 1);
    $pr('Aussenmittel 19 Grad ist Kuehlfall', rk_heizfall(19.0, 15.0), 0);
    $pr('  ohne Mittel keine Aussage (-1)', rk_heizfall(null, 15.0), -1);

    /* ---------- B10: der Taupunkt verlaesst den Gueltigkeitsbereich nicht ----------
     * Ein Fuehler am unteren Anschlag meldet 0,1 % statt 0 %. Daraus wurde
     * bis 0.10.1 ein Taupunkt von -57,89 und ein VLMIN von -56,89. */
    $pr('Fuehler am Anschlag: 0,1 % bei -60 C ergibt keinen Taupunkt',
        rk_taupunkt(-60, 0.1), null);
    $pr('  und auch kein VLMIN im Raum',
        rk_raum_rechnen(array('name' => 'Anschlag', 't' => -60.0, 'rf' => 0.1,
                              'frsi' => 0.7), array('t' => 3.0, 'rf' => 85.0),
                        array(), array(), $t0)['vlmin'], null);
    $pr('  ein normaler Wert bleibt unberuehrt', rk_taupunkt(20, 50), 9.26, 0.05);

    /* ---------- V18: Kuehlfreigabe mit zwei Schaltpunkten ---------- */
    $gk = array('name' => 'Decke', 't' => 24.0, 'rf' => 55.0, 'frsi' => 0.9);
    $ck = array('kuehlfrei_ein' => 3.0, 'kuehlfrei_aus' => 2.0, 'vl_zuschlag' => 1.0);
    $rk_frei = rk_raum_rechnen($gk, array('t' => 20.0, 'rf' => 50.0), array(), $ck, $t0);
    $pr('Kuehlfreigabe: grosser Taupunktabstand gibt frei',
        array($rk_frei['spread'] > 3.0, $rk_frei['kuehlfrei']), array(true, 1));
    /* 78 % ergaeben einen Abstand von 3,68 K und damit noch die Freigabe -
     * gemessen. Erst 88 % druecken ihn auf 1,71 K. */
    $ge = array('name' => 'Decke', 't' => 24.0, 'rf' => 88.0, 'frsi' => 0.9);
    $rk_zu = rk_raum_rechnen($ge, array('t' => 20.0, 'rf' => 50.0), array(), $ck, $t0);
    $pr('  kleiner Abstand sperrt',
        array($rk_zu['spread'] < 2.0, $rk_zu['kuehlfrei']), array(true, 0));
    $pr('  ohne Abstand keine Aussage (-1)',
        rk_raum_rechnen(array('name' => 'Flur', 't' => 24.0, 'rf' => 55.0,
                              'art' => 'innen'), array('t' => 20.0, 'rf' => 50.0),
                        array(), $ck, $t0)['kuehlfrei'], -1);

    /* ---------- V19: CO2 hat eine eigene Frostgrenze ---------- */
    $gco = array('name' => 'Schlafen', 't' => 20.0, 'rf' => 45.0, 'frsi' => 0.7,
                 'co2' => 1400, 'co2_max' => 1000);
    $cco = array('mindest' => 0.5, 't_min' => -5, 'co2_t_min' => -15.0);
    $rco = rk_raum_rechnen($gco, array('t' => -18.0, 'rf' => 90.0), array(), $cco, $t0);
    $pr('CO2 oeffnet bei -18 C nicht mehr',
        array($rco['lueften'], $rco['co2_hoch']), array(0, 0));
    $rco2 = rk_raum_rechnen($gco, array('t' => -8.0, 'rf' => 90.0), array(), $cco, $t0);
    $pr('  bei -8 C aber weiterhin',
        array($rco2['lueften'], $rco2['grund']), array(1, 'co2'));

    /* ---------- B9: Schliessbefehl auch bei stummem Fuehler ---------- */
    $ar = array('t' => 8.0, 'rf' => 95.0, 'regen' => 9.0);
    $cr = array('regen_max' => 1.0);
    $stumm = rk_raum_rechnen(array('name' => 'Bad', 'frsi' => 0.7,
        'fenster_offen' => 1), $ar, array(), $cr, $t0);
    $pr('Fuehler stumm, Fenster offen, Starkregen: Schliessbefehl kommt',
        array($stumm['ok'], $stumm['fenster'], $stumm['fenster_zu']), array(0, 1, 1));
    $ruhig = rk_raum_rechnen(array('name' => 'Bad', 'frsi' => 0.7,
        'fenster_offen' => 1), array('t' => 8.0, 'rf' => 60.0), array(), $cr, $t0);
    $pr('  ohne belegten Grund bleibt es, wie es ist',
        array($ruhig['ok'], $ruhig['fenster_zu']), array(0, 0));
    $zu2 = rk_raum_rechnen(array('name' => 'Bad', 'frsi' => 0.7,
        'fenster_offen' => 0), $ar, array(), $cr, $t0);
    $pr('  ein geschlossenes Fenster meldet nichts', $zu2['fenster_zu'], 0);

    /* ---------- B13: Hysterese und Nachlauf ueberleben einen Ausfall ---------- */
    $gn = array('name' => 'Wohnen', 't' => 20.0, 'rf' => 55.0, 'frsi' => 0.7);
    $cn = array('mindest' => 0.5, 't_min' => -5, 'hyst' => 0.5, 'dauer_min' => 10);
    $an = array('t' => 15.0, 'rf' => 75.0);
    $lauf_an = rk_raum_rechnen($gn, $an, array(), $cn, $t0,
        array('lueften' => 1, 'lueften_seit' => $t0 - 300, 't' => 20.0, 'rf' => 55.0));
    /* Die Empfehlung begann bei t0-300; die Mindestdauer sind 600 s. Der
     * Ausfall wird deshalb bei t0+240 gemessen (540 s seit Beginn) - bei
     * t0+300 waeren es genau 600 s, und die Grenze ist ausschliessend. */
    $ausfall = rk_raum_rechnen(array('name' => 'Wohnen', 'frsi' => 0.7), $an,
        array(), $cn, $t0 + 240, $lauf_an);
    $pr('Ein Ausfall loescht den Nachlauf nicht mehr',
        array($ausfall['ok'], $ausfall['lueften'], $ausfall['grund']),
        array(0, 1, 'nachlauf'));
    $pr('  und der Beginn der Empfehlung reist mit',
        $ausfall['lueften_seit'], $t0 - 300);
    $spaet = rk_raum_rechnen(array('name' => 'Wohnen', 'frsi' => 0.7), $an,
        array(), $cn, $t0 + 3600, $lauf_an);
    $pr('  nach der Mindestdauer laeuft er aus', $spaet['lueften'], 0);
    $csp = array('regen_max' => 1.0) + $cn;
    $sperr = rk_raum_rechnen(array('name' => 'Wohnen', 'frsi' => 0.7),
        array('t' => 15.0, 'rf' => 75.0, 'regen' => 9.0),
        array(), $csp, $t0 + 240, $lauf_an);
    $pr('  gegen eine harte Sperre traegt er nicht',
        array($sperr['lueften'], $sperr['sperre'], $sperr['fenster_zu']),
        array(0, 1, 0));

    /* ---------- V23: die Ruhezeit sperrt, ausser bei CO2 ---------- */
    $gru = array('name' => 'Schlafen', 't' => 20.0, 'rf' => 60.0, 'frsi' => 0.7,
                 'ruhe_von' => '22:00', 'ruhe_bis' => '06:00');
    $cru = array('mindest' => 0.5, 't_min' => -5);
    $rnacht = rk_raum_rechnen($gru, array('t' => 5.0, 'rf' => 60.0), array(), $cru, $nacht);
    $pr('Ruhezeit sperrt das Fenster',
        array($rnacht['ruhe'], $rnacht['lueften'], $rnacht['grund']),
        array(1, 0, 'ruhezeit'));
    /* Damit wirklich das CO2 traegt und nicht die Feuchte, muss die
     * Aussenluft absolut feuchter sein: 18 C / 95 % sind 14,56 g/m3 gegen
     * 10,35 innen. Mit 5 C / 60 % traegt die Feuchte, und der Fall haette
     * die Ruhezeit gar nicht geprueft. */
    $gru2 = $gru; $gru2['co2'] = 1400; $gru2['co2_max'] = 1000;
    $rnacht2 = rk_raum_rechnen($gru2, array('t' => 18.0, 'rf' => 95.0), array(), $cru, $nacht);
    $pr('  verbrauchte Luft schlaegt die Ruhezeit',
        array($rnacht2['lueften'], $rnacht2['grund']), array(1, 'co2'));
    $gru3 = $gru; $gru3['co2'] = 600; $gru3['co2_max'] = 1000;
    $rnacht3 = rk_raum_rechnen($gru3, array('t' => 5.0, 'rf' => 60.0), array(), $cru, $nacht);
    $pr('  ohne CO2-Grund sperrt sie auch die Feuchteempfehlung',
        array($rnacht3['lueften'], $rnacht3['grund']), array(0, 'ruhezeit'));
    $rtag = rk_raum_rechnen($gru, array('t' => 5.0, 'rf' => 60.0), array(), $cru, $morgen);
    $pr('  tagsueber gilt sie nicht',
        array($rtag['ruhe'], $rtag['lueften']), array(0, 1));

    /* ---------- V14/V15/V16: was aus dem Verlauf kommt ---------- */
    $gv = array('name' => 'Bad', 't' => 22.0, 'rf' => 75.0, 'frsi' => 0.7);
    $cv = array('mindest' => 0.5, 't_min' => -5, 'zwang_std' => 6);
    $rd = rk_raum_rechnen($gv, array('t' => 24.0, 'rf' => 80.0), array(), $cv, $t0,
        null, array('dusche' => 1, 'trend' => 2.4));
    $pr('Duschstoss loest Lueften aus, auch gegen die Feuchtebilanz',
        array($rd['lueften'], $rd['grund'], $rd['dusche']), array(1, 'dusche', 1));
    $pr('  und der Trend wird durchgereicht', $rd['trend'], 2.4);
    $rz = rk_raum_rechnen($gv, array('t' => 24.0, 'rf' => 80.0), array(), $cv, $t0,
        null, array('ohne_std' => 7));
    $pr('Sieben Stunden ohne Empfehlung loesen die Zwangslueftung aus',
        array($rz['lueften'], $rz['grund'], $rz['zwang']), array(1, 'zwang', 1));
    $rz2 = rk_raum_rechnen($gv, array('t' => 24.0, 'rf' => 80.0), array(), $cv, $t0,
        null, array('ohne_std' => 3));
    $pr('  drei Stunden noch nicht', $rz2['zwang'], 0);
    $cv0 = $cv; $cv0['zwang_std'] = 0;
    $rz3 = rk_raum_rechnen($gv, array('t' => 24.0, 'rf' => 80.0), array(), $cv0, $t0,
        null, array('ohne_std' => 99));
    $pr('  und mit 0 ist die Zwangslueftung aus', $rz3['zwang'], 0);

    /* ---------- V21: die Anlage im ganzen Raum ---------- */
    $gw = array('name' => 'Anlage', 't' => 21.0, 'rf' => 45.0, 'frsi' => 0.7,
                'zuluft' => '17.0', 'wrg_eta' => 80);
    $rw = rk_raum_rechnen($gw, array('t' => 1.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('Raum mit Lueftungsanlage: Zuluft, Rueckwaermzahl, Fortluft',
        array($rw['zuluft'], $rw['wrg'], $rw['fortluft'] > 0, $rw['vereist']),
        array(17.0, 80.0, true, 0));
    $gw2 = $gw;
    $rw2 = rk_raum_rechnen($gw2, array('t' => -12.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('  bei -12 C aussen meldet sie Vereisungsgefahr', $rw2['vereist'], 1);
    $rw3 = rk_raum_rechnen(array('name' => 'Ohne', 't' => 21.0, 'rf' => 45.0,
        'frsi' => 0.7), array('t' => 1.0, 'rf' => 85.0), array(), array(), $t0);
    $pr('  ohne Zuluftpfad bleibt beides leer',
        array($rw3['zuluft'], $rw3['wrg'], $rw3['fortluft']), array(null, null, null));

    /* ---------- CO2 mit Personenzahl ----------
     * Eine Person, 17 l/h, 30 m3 Schlafzimmer:
     *   0,017 m3/h * 1e6 / 30 = 567 ppm je Stunde ohne Luftwechsel.
     * Erforderlicher Luftwechsel, um 1000 ppm gegen 420 aussen zu halten:
     *   0,017 * 1e6 / (30 * 580) = 0,98 je Stunde - Kippen reicht knapp. */
    $pr('Eine Person in 30 m3 erzeugt rund 567 ppm je Stunde',
        rk_co2_anstieg_erwartet(1, 30, 17.0), 567.0, 1.0);
    $pr('  zwei Personen das Doppelte',
        rk_co2_anstieg_erwartet(2, 30, 17.0), 1133.0, 2.0);
    $pr('  im doppelt so grossen Raum die Haelfte',
        rk_co2_anstieg_erwartet(1, 60, 17.0), 283.0, 1.0);
    $pr('  ohne Personen keine Aussage', rk_co2_anstieg_erwartet(0, 30, 17.0), null);
    $pr('  ohne Raumvolumen keine Aussage', rk_co2_anstieg_erwartet(1, 0, 17.0), null);
    $pr('Erforderlicher Luftwechsel fuer 1000 ppm gegen 420 aussen',
        rk_co2_luftwechsel(1, 30, 1000, 420, 17.0), 0.98, 0.02);
    $pr('  ein gekipptes Fenster leistet rund 1 - reicht also knapp',
        rk_co2_luftwechsel(1, 30, 1000, 420, 17.0) < rk_luftwechsel('kipp', 10), true);
    $pr('  zwei Personen im selben Raum brauchen mehr, als Kippen leistet',
        rk_co2_luftwechsel(2, 30, 1000, 420, 17.0) > rk_luftwechsel('kipp', 10), true);
    $pr('  eine Grenze unter der Aussenluft ist nicht zu halten',
        rk_co2_luftwechsel(1, 30, 400, 420, 17.0), null);

    /* Restzeit: von 700 auf 1000 ppm bei 300 ppm je Stunde sind 60 Minuten. */
    $pr('Restzeit bis zur Grenze aus dem gemessenen Anstieg',
        rk_co2_voll(700, 1000, 300.0), 60);
    $pr('  bei doppeltem Anstieg die Haelfte', rk_co2_voll(700, 1000, 600.0), 30);
    $pr('  ueber der Grenze gibt es keine Restzeit', rk_co2_voll(1100, 1000, 300.0), -1);
    $pr('  ohne gemessenen Anstieg auch nicht', rk_co2_voll(700, 1000, null), -1);
    $pr('  und ein Anstieg im Messrauschen ergibt keine Tage',
        rk_co2_voll(700, 1000, 3.0), -1);

    /* Im ganzen Raum, mit gemessenem Anstieg aus dem Verlauf. */
    $gp = array('name' => 'Schlafen', 't' => 19.0, 'rf' => 50.0, 'frsi' => 0.7,
                'volumen' => 30, 'co2' => 700, 'co2_max' => 1000, 'personen' => 1);
    $cp = array('mindest' => 0.5, 't_min' => -5, 'co2_ltr' => 17.0,
                'co2_aussen' => 420.0);
    $rp = rk_raum_rechnen($gp, array('t' => 5.0, 'rf' => 70.0), array(), $cp, $t0,
                          null, array('co2_anstieg' => 300.0));
    $pr('Raum mit einer Person: erwartet, gemessen, Restzeit und Luftwechsel',
        array($rp['co2_erwartet'], $rp['co2_anstieg'], $rp['co2_voll'],
              $rp['co2_lw'] > 0.9 && $rp['co2_lw'] < 1.0),
        array(567.0, 300.0, 60, true));
    $gp0 = $gp; $gp0['personen'] = 0;
    $rp0 = rk_raum_rechnen($gp0, array('t' => 5.0, 'rf' => 70.0), array(), $cp, $t0,
                           null, array('co2_anstieg' => 300.0));
    $pr('  ohne Personenzahl bleibt die Schaetzung leer, die Messung nicht',
        array($rp0['co2_erwartet'], $rp0['co2_lw'], $rp0['co2_anstieg'], $rp0['co2_voll']),
        array(null, null, 300.0, 60));
    $rpk = rk_raum_rechnen($gp, array('t' => 5.0, 'rf' => 70.0), array(), $cp, $t0);
    $pr('  ohne Verlauf gibt es keine Restzeit, wohl aber die Schaetzung',
        array($rpk['co2_voll'], $rpk['co2_erwartet']), array(-1, 567.0));

    /* ================================================================
     * Neu in 0.11.2 - die Teile, die in rk_lib.php stehen.
     *
     * Diese Datei ist der reine Rechenkern und kommt ohne rk_lib.php aus;
     * darum steht sie fuer sich und laesst sich einzeln pruefen. Beim
     * Aufruf ueber bin/raumklima_abruf.php oder ueber den Reiter Test ist
     * rk_lib.php aber geladen, und dann gehoeren diese Faelle dazu.
     *
     * DER ANLASS steht in der Eichung vom 30.08.2026: zehn Verbiegungen
     * wurden zurueckgenommen, NEUN blieben gruen. Der Selbsttest mass
     * ausschliesslich rk_klima.php - Verlaufsspeicher, Zugangsdaten,
     * Raumvergleich und freier Platz waren gar nicht abgedeckt. Ein
     * Selbsttest, der die halbe Bibliothek nicht betritt, sagt ueber sie
     * nichts, und man liest ihn trotzdem als "alles in Ordnung".
     *
     * Die Wache: ohne rk_lib.php werden diese Faelle nicht gezaehlt - sonst
     * meldete ein Aufruf des Rechenkerns allein Fehlschlaege, die keine
     * sind.
     * ================================================================ */
    if (function_exists('rk_verlauf_raum')) {

        /* ---- Der Korb zaehlt "keine Aussage" NICHT als trocken ---- */
        $st = 1798000000 - (1798000000 % 3600);   /* volle Stunde */
        $mach = function ($ober_rf) {
            return array('ok' => 1, 't' => 21.0, 'rf' => 50.0, 'absolut' => 9.2,
                         'ober_rf' => $ober_rf, 'co2' => null, 'lueften' => 0);
        };
        $vr = array();
        for ($i = 0; $i < 12; $i++) {
            rk_verlauf_raum($vr, $mach(null), $st + $i * 300, 300);
        }
        $w = rk_verlauf_werte($vr, $st + 12 * 300);
        $pr('Zwoelf Messungen ohne Aussenwert: nass24 bleibt -1, nicht 0',
            $w['nass24'], -1);
        $vr2 = array();
        for ($i = 0; $i < 12; $i++) {
            rk_verlauf_raum($vr2, $mach(88.0), $st + $i * 300, 300);
        }
        $w2 = rk_verlauf_werte($vr2, $st + 12 * 300);
        $pr('  dieselbe Reihe mit 88 % an der Flaeche ergibt eine volle Stunde',
            $w2['nass24'], 1.0);

        /* Der Korb wird erst beim STUNDENWECHSEL abgelegt. Die beiden Faelle
         * oben messen nur den offenen Korb - der abgelegte Stundeneintrag
         * ist ein zweiter Weg, und ohne einen Fall, der die Stundengrenze
         * ueberschreitet, blieb er ungemessen. Genau daran blieb die Eichung
         * am 30.08.2026 zunaechst gruen. */
        $vr4 = array();
        for ($i = 0; $i < 12; $i++) {          /* erste Stunde: keine Aussage */
            rk_verlauf_raum($vr4, $mach(null), $st + $i * 300, 300);
        }
        for ($i = 0; $i < 3; $i++) {           /* zweite Stunde: nass */
            rk_verlauf_raum($vr4, $mach(88.0), $st + 3600 + $i * 300, 300);
        }
        $abgelegt = isset($vr4['stunden'][0][4]) ? $vr4['stunden'][0][4] : null;
        $pr('Abgelegte Stunde ganz ohne Aussage traegt -1, nicht 0,0',
            $abgelegt, -1.0);
        /* 3 nasse Messungen zu 300 s sind 900 s = 0,25 h, gerundet 0,3.
         * Die zwoelf Messungen der ersten Stunde gehen NICHT als trockene
         * Stunde ein - sonst stuende hier weiterhin 0,3, aber ueber einen
         * Nenner von zwei Stunden, und der Anwender laese "in zwei Stunden
         * war es fast nie nass" statt "ueber eine Stunde ist nichts
         * bekannt". */
        $w4 = rk_verlauf_werte($vr4, $st + 3600 + 3 * 300);
        $pr('  und sie zaehlt beim Summieren nicht als trockene Stunde',
            $w4['nass24'], 0.3);

        /* ---- Ein Punkt je Takt, nicht je Aufruf ---- */
        $vr3 = array();
        for ($i = 0; $i < 12; $i++) {
            rk_verlauf_raum($vr3, $mach(88.0), $st + $i * 300, 300);
            /* Zwei Handabrufe unmittelbar danach - sie duerfen die Reihe
             * nicht verlaengern und die Nassstunden nicht anheben. */
            rk_verlauf_raum($vr3, $mach(88.0), $st + $i * 300 + 20, 300);
            rk_verlauf_raum($vr3, $mach(88.0), $st + $i * 300 + 40, 300);
        }
        $pr('Handabrufe verlaengern die Feinreihe nicht', count($vr3['fein']), 12);
        $pr('  und heben die Nassstunden nicht an',
            rk_verlauf_werte($vr3, $st + 12 * 300)['nass24'], 1.0);

        /* ---- Raumnamen vergleichen ---- */
        $pr('Raumname mit Randleerzeichen ist derselbe Raum',
            rk_raum_gleich('Dachboden ', 'Dachboden'), true);
        $pr('  und Gross-/Kleinschreibung auch',
            rk_raum_gleich("K\u{00fc}che", "k\u{00fc}CHE"), true);
        $pr('  ein leerer Name ist nie derselbe Raum',
            array(rk_raum_gleich(' ', ''), rk_raum_gleich('', 'Bad')),
            array(false, false));
        $pr('  verschiedene Raeume bleiben verschieden',
            rk_raum_gleich('Bad', 'Bad OG'), false);

        /* ---- Wann ist ein Platz frei? ---- */
        $vorg = rk_raum_vorgabe();
        $pr('Unberuehrter Platz ist frei', rk_platz_frei($vorg), true);
        foreach (array('name', 'quelle', 'quelle_rf', 'pfad_t', 'pfad_rf',
                       'pfad_co2', 'pfad_fenster', 'pfad_zuluft') as $feld) {
            $belegt = $vorg;
            $belegt[$feld] = 'x';
            $pr('  ' . $feld . ' belegt den Platz', rk_platz_frei($belegt), false);
        }

        /* ---- Zugangsdaten gehen nur an bekannte Wirte ----
         * Die Adresse ist die Dokumentationsadresse aus RFC 5737
         * (TEST-NET-1). Sie darf im Netz nirgends vorkommen - anders als eine
         * echte Adresse aus dem eigenen Haus, die hier nichts zu suchen hat
         * und in der Gegenprobe unten auch nicht steht (fremder-rechner.example
         * ist die Dokumentationsdomaene aus RFC 2606). Fuer die Pruefung zaehlt
         * allein, dass BEIDE Adressen denselben Wirt nennen. */
        $zcfg = array('raeume' => array(
            array('quelle' => 'http://192.0.2.66/rpc/Shelly.GetStatus',
                  'quelle_rf' => ''),
        ), 'quelle' => '', 'ms_nr' => '');
        $pr('Zugangsdaten an den Wirt der Raumquelle: ja',
            rk_zugang_erlaubt('http://192.0.2.66/status', $zcfg), true);
        $pr('  an eine fremde Adresse: nein',
            rk_zugang_erlaubt('http://fremder-rechner.example/wetter', $zcfg), false);
        $pr('  an eine Adresse ohne Wirt: nein',
            rk_zugang_erlaubt('nicht-einmal-eine-adresse', $zcfg), false);

        /* ---- Das Schema kommt aus der general.json ---- */
        $pr('Miniserver ohne HTTPS: http und der einfache Port',
            rk_ms_url(array('adresse' => '10.0.0.5', 'port' => 80, 'https' => 0), 'a/b'),
            'http://10.0.0.5:80/a/b');
        $pr('  mit Preferhttps: https und der TLS-Port',
            rk_ms_url(array('adresse' => '10.0.0.5', 'port' => 443, 'https' => 1), 'a/b'),
            'https://10.0.0.5:443/a/b');

        /* ---- Der Umlaut im Ausschlusswort ---- */
        $bs = array(
            array('uuid' => 'u1', 'name' => '01) Temperatur Flur', 'raum' => 'Flur',
                  'kategorie' => 'k', 'typ' => 'TextState'),
            array('uuid' => 'u2', 'name' => "L\u{00fc}ften Feuchte Flur", 'raum' => 'Flur',
                  'kategorie' => 'k', 'typ' => 'InfoOnlyDigital'),
        );
        $vv = rk_fuehler_vorschlag($bs);
        $pr('Ein Merker namens "Lueften Feuchte" ist keine Feuchtequelle',
            $vv[0]['rf'], null);
    }

    array_unshift($z, sprintf('Rechenkern %s: %d Faelle geprueft, %d Fehlschlaege.',
        RK_KERN, $anzahl, $fehl), '');
    return array($anzahl, $fehl, implode("\n", $z));
}
