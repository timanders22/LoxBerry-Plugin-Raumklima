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

define('RK_KERN', '1.2.0');

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
    return round(243.12 * $l / $n, 2);
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
 * Die Schimmelampel: 0 unbedenklich, 1 beobachten, 2 Gefahr, 3 Tauwasser.
 *
 * Das eine Bit bei genau 80 % springt auf Messrauschen an - 79,8 und 80,1
 * sind dieselbe Wand. Vier Stufen sagen mehr und flattern weniger:
 *
 *     unter 70 %   unbedenklich
 *     70 bis 80    beobachten - hier faengt es an, wenn es so bleibt
 *     80 bis 95    Schimmelgefahr (die Schwelle der Bauphysik)
 *     ab 95        praktisch Tauwasser
 */
function rk_ampel($ober_rf)
{
    if ($ober_rf === null || !is_numeric($ober_rf)) { return 0; }
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
 * Rueckgabe: array('lohnt' => 0/1, 'gewinn' => g/m3, 'grund' => Kuerzel)
 */
function rk_lueften($af_innen, $t_aussen, $rf_aussen, $mindest, $t_min, $af_unter,
                    $lief = false, $hyst = 0.5, $regen = null, $regen_max = 0.0)
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
    if ((float) $regen_max > 0.0 && $regen !== null && (float) $regen > (float) $regen_max) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'regen');
    }
    /* Hysterese: einschalten bei $mindest, ausschalten erst darunter.
     *
     * Gemessen am 24.08.2026: 0,4 Prozentpunkte Messrauschen an der
     * Aussenfeuchte kippten die Empfehlung, und der Cron laeuft alle fuenf
     * Minuten. Ein Fensterantrieb daran ist unruhig. */
    $schwelle = $lief ? (float) $mindest * max(0.0, min(1.0, (float) $hyst))
                      : (float) $mindest;
    if ($gewinn < $schwelle) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'aussen_feuchter');
    }
    return array('lohnt' => 1, 'gewinn' => $gewinn, 'grund' => 'lohnt');
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
 */
function rk_kuehlen($t_innen, $rf_innen, $t_aussen, $rf_aussen, $t_soll,
                    $spanne = 1.0, $af_zusatz = 1.0)
{
    $leer = array('lohnt' => 0, 'gewinn' => 0.0, 'grund' => 'keine_daten');
    if (!rk_t_gueltig($t_innen) || !rk_t_gueltig($t_aussen)) { return $leer; }
    if (!is_numeric($t_soll) || (float) $t_soll <= 0) {
        return array('lohnt' => 0, 'gewinn' => 0.0, 'grund' => 'kein_ziel');
    }
    $t_innen = (float) $t_innen; $t_aussen = (float) $t_aussen;
    $gewinn = round($t_innen - $t_aussen, 2);
    if ($t_innen <= (float) $t_soll) {
        return array('lohnt' => 0, 'gewinn' => $gewinn, 'grund' => 'ziel_erreicht');
    }
    if ($gewinn < (float) $spanne) {
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
                             $af_unter, $stunden = 12, $regen_max = 0.0)
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
        /* Eine Stunde mit Starkregen ist kein guenstiger Zeitpunkt, auch
         * wenn die Luft dann trocken waere. */
        $l = rk_lueften($af_innen, $w['t'], $w['rf'], $mindest, $t_min, $af_unter,
                        false, 0.5, isset($w['regen']) ? $w['regen'] : null, $regen_max);
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
 *                'fenster','t_soll','co2_max')
 * $aussen  array('t','rf','regen','wind','druck') - die Messwerte von jetzt
 * $vorher  array(ts => array('t','rf')) - die Vorhersage, oder leer
 * $cfg     array('mindest','t_min','af_unter','vorschau','steht_min','hyst',
 *                'dauer_min','regen_max','kuehl_spanne')
 * $letzt   der Eintrag desselben Raums aus dem vorigen Abruf, oder null
 * $verlauf array('nass24','nass7t','erfolg','erfolg_n','eintrag') aus dem
 *          Verlaufsspeicher - hier nur durchgereicht, damit der Kern ohne
 *          Dateien auskommt und pruefbar bleibt
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
        'gewinn'    => 0.0,
        'grund'     => 'keine_daten',
        'kuehlen'   => 0,
        'kuehlgewinn' => 0.0,
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
    if (!$e['ok']) { return $e; }

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
    $e['schimmel'] = $e['ampel'] >= 2 ? 1 : 0;

    if ($e['ober_t'] !== null && $e['taupunkt'] !== null) {
        $e['spread'] = round($e['ober_t'] - $e['taupunkt'], 2);
    }
    if ($e['taupunkt'] !== null) {
        $e['vlmin'] = round($e['taupunkt'] + 1.0, 2);
    }

    /* Aus dem Verlaufsspeicher - Stunden, Quote, Gramm je Stunde. */
    if (is_array($verlauf)) {
        foreach (array('nass24', 'nass7t', 'erfolg', 'eintrag') as $k) {
            if (isset($verlauf[$k]) && $verlauf[$k] !== null) { $e[$k] = $verlauf[$k]; }
        }
    }

    /* ---- Lueften: Feuchte, CO2 oder Kuehlen ---- */
    $af_unter = (isset($cfg['af_unter']) && $cfg['af_unter'] > 0) ? (float) $cfg['af_unter'] : null;
    $lief = !empty($letzt['lueften']);
    $l = rk_lueften($e['absolut'], $ta, $ra,
        isset($cfg['mindest']) ? $cfg['mindest'] : 0.5,
        isset($cfg['t_min']) ? $cfg['t_min'] : -5,
        $af_unter, $lief,
        isset($cfg['hyst']) ? $cfg['hyst'] : 0.5,
        $regen, isset($cfg['regen_max']) ? $cfg['regen_max'] : 0.0);
    $e['gewinn'] = $l['gewinn'];
    $e['grund'] = $l['grund'];

    $k = rk_kuehlen($t, $rf, $ta, $ra, isset($raum['t_soll']) ? $raum['t_soll'] : 0,
                    isset($cfg['kuehl_spanne']) ? $cfg['kuehl_spanne'] : 1.0);
    $e['kuehlen'] = $k['lohnt'];
    $e['kuehlgewinn'] = $k['gewinn'];

    if ($co2 !== null && $co2_max > 0 && $co2 >= $co2_max) { $e['co2_hoch'] = 1; }

    $e['lueften'] = ($l['lohnt'] || $e['co2_hoch'] || $k['lohnt']) ? 1 : 0;
    if (!$l['lohnt']) {
        if ($e['co2_hoch']) { $e['grund'] = 'co2'; }
        elseif ($k['lohnt']) { $e['grund'] = 'kuehlen'; }
    }
    /* Regen sperrt auch die beiden anderen Gruende - ein Fensterantrieb
     * soll nicht bei Starkregen aufmachen, nur weil CO2 hoch ist. */
    if ($l['grund'] === 'regen') { $e['lueften'] = 0; $e['grund'] = 'regen'; }

    /* Mindestdauer: einmal empfohlen, bleibt die Empfehlung eine Weile
     * stehen. Sonst schaltet ein Fensterantrieb im Fuenfminutentakt. */
    $dauer_min = isset($cfg['dauer_min']) ? (int) $cfg['dauer_min'] : 0;
    $seit = ($lief && !empty($letzt['lueften_seit'])) ? (int) $letzt['lueften_seit'] : (int) $jetzt;
    if (!$e['lueften'] && $lief && $dauer_min > 0
        && ((int) $jetzt - $seit) < $dauer_min * 60 && $l['grund'] !== 'regen') {
        $e['lueften'] = 1;
        $e['grund'] = 'nachlauf';
    }
    $e['lueften_seit'] = $e['lueften'] ? $seit : 0;

    /* ---- Wie lange, und was kostet es ---- */
    if ($e['lueften'] && rk_t_gueltig($ta)) {
        $e['dauer'] = rk_lueftdauer(isset($raum['fenster']) ? $raum['fenster'] : 'stoss',
                                    $t - (float) $ta, $wind);
        $kw = rk_lueftkosten(isset($raum['volumen']) ? $raum['volumen'] : 0, $t, $ta);
        if ($kw !== null) { $e['kosten'] = $kw; }
    }

    /* ---- Fenster wieder schliessen? ---- */
    if ($e['fenster'] === 1 && !$e['lueften']) { $e['fenster_zu'] = 1; }

    $b = rk_bester_zeitpunkt($e['absolut'], $vorher, $jetzt,
        isset($cfg['mindest']) ? $cfg['mindest'] : 0.5,
        isset($cfg['t_min']) ? $cfg['t_min'] : -5,
        $af_unter,
        isset($cfg['vorschau']) ? $cfg['vorschau'] : 12,
        isset($cfg['regen_max']) ? $cfg['regen_max'] : 0.0);
    $e['best_in'] = $b['in'];
    $e['best_std'] = ($b['ts'] !== null) ? (int) date('G', $b['ts']) : -1;
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
         * ("2026-08-10T13:00"). strtotime nimmt dann die Zeitzone des
         * Systems - und die stimmt auf einem LoxBerry mit der Ortszeit
         * ueberein. Ein blindes gmmktime() waere hier um die Zeitzone
         * daneben, und die Empfehlung um Stunden. */
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
    $pr('Ampel ohne Wert ist 0', rk_ampel(null), 0);

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

    array_unshift($z, sprintf('Rechenkern %s: %d Faelle geprueft, %d Fehlschlaege.',
        RK_KERN, $anzahl, $fehl), '');
    return array($anzahl, $fehl, implode("\n", $z));
}
