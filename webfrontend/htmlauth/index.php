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

$rk_gefunden_lib = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/rk_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/rk_lib.php',
    dirname(__DIR__) . '/html/rk_lib.php',
) as $rk_kandidat) {
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

/* Die Reiter, an EINER Stelle. Leiste, Pruefausdruck und die serverseitige
 * Klasse sm-active entstehen daraus - vergessen kann man nichts mehr. */
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
$rk_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Vorlage herunterladen ---------------- */
if ($rk_post && isset($_POST['vorlage'])) {
    list($rk_name, $rk_inhalt) = rk_vorlage();
    if ($rk_inhalt === '') {
        $rk_fehler[] = rk_t('LOX.FEHLER_VORLAGE');
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
if ($rk_post && isset($_POST['speichern'])) {
    $rk_cfg = rk_config();

    /* Kleine Helfer. Nur Steuerzeichen und Anfuehrungszeichen entfernen -
     * ein hartes preg_replace auf eine Positivliste zerstoert eingefuegte
     * Werte (belegt am ACTi-Plugin am 26.07.2026). */
    $rk_sauber = function ($s) {
        return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $s));
    };
    $rk_feld = function ($name, $i) use ($rk_sauber) {
        $a = isset($_POST[$name]) ? (array) $_POST[$name] : array();
        return isset($a[$i]) ? $rk_sauber($a[$i]) : '';
    };
    /* Eine Zahl pruefen statt sie stillschweigend zurechtzubiegen. Die
     * Hausregel ist eindeutig: abweisen und sagen, was falsch war. */
    $rk_zahl = function ($roh, $von, $bis, $bezeichnung, $ganz = false)
                use (&$rk_fehler) {
        $roh = trim((string) $roh);
        $roh = str_replace(',', '.', $roh);      // deutsche Kommaschreibweise
        if ($roh === '') { return null; }
        if (!is_numeric($roh)) {
            $rk_fehler[] = sprintf(rk_t('FEHLER.KEINE_ZAHL'), $bezeichnung, $roh);
            return null;
        }
        $w = $ganz ? (int) round((float) $roh) : (float) $roh;
        if ($w < $von || $w > $bis) {
            $rk_fehler[] = sprintf(rk_t('FEHLER.AUSSERHALB'), $bezeichnung, $roh, $von, $bis);
            return null;
        }
        return $w;
    };
    /* Eine Adresse muss http oder https sein. Wer nur "gateway/status"
     * eintraegt, bekommt es gesagt statt einer stummen leeren Tabelle. */
    $rk_adresse = function ($roh, $bezeichnung) use (&$rk_fehler, $rk_sauber) {
        $roh = $rk_sauber($roh);
        if ($roh === '') { return ''; }
        if (!preg_match('#^https?://#i', $roh)) {
            $rk_fehler[] = sprintf(rk_t('FEHLER.ADRESSE'), $bezeichnung, $roh);
            return '';
        }
        return $roh;
    };

    /* --- Raeume --- */
    $rk_neu = array();
    for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) {
        $rk_r = rk_raum_vorgabe();
        $rk_r['name']    = $rk_feld('r_name', $rk_i);
        $rk_r['quelle']  = $rk_adresse($rk_feld('r_quelle', $rk_i),
                                       rk_t('EINST.RAUM') . ' ' . ($rk_i + 1));
        $rk_r['pfad_t']  = $rk_feld('r_pfad_t', $rk_i);
        $rk_r['pfad_rf'] = $rk_feld('r_pfad_rf', $rk_i);

        $rk_leer = ($rk_r['name'] === '' && $rk_r['pfad_t'] === '' && $rk_r['pfad_rf'] === '');
        $rk_bez = rk_t('EINST.RAUM') . ' ' . ($rk_i + 1);

        $rk_w = $rk_zahl($rk_feld('r_frsi', $rk_i), 0.05, 1.0, $rk_bez . ' / fRsi');
        if ($rk_w !== null) { $rk_r['frsi'] = $rk_w; }
        $rk_w = $rk_zahl($rk_feld('r_min', $rk_i), 0, 100, $rk_bez . ' / ' . rk_t('EINST.SOLL_MIN'), true);
        if ($rk_w !== null) { $rk_r['soll_min'] = $rk_w; }
        $rk_w = $rk_zahl($rk_feld('r_max', $rk_i), 0, 100, $rk_bez . ' / ' . rk_t('EINST.SOLL_MAX'), true);
        if ($rk_w !== null) { $rk_r['soll_max'] = $rk_w; }

        if (!$rk_leer) {
            if ($rk_r['name'] === '') {
                $rk_fehler[] = sprintf(rk_t('FEHLER.NAME_FEHLT'), $rk_i + 1);
            }
            if ($rk_r['pfad_t'] === '' && $rk_r['pfad_rf'] === '') {
                $rk_fehler[] = sprintf(rk_t('FEHLER.PFAD_FEHLT'), $rk_i + 1);
            }
            if ($rk_r['soll_min'] > 0 && $rk_r['soll_max'] > 0
                && $rk_r['soll_min'] >= $rk_r['soll_max']) {
                $rk_fehler[] = sprintf(rk_t('FEHLER.KORRIDOR'), $rk_i + 1);
            }
        }
        $rk_neu[$rk_i] = $rk_r;
    }
    $rk_cfg['raeume'] = $rk_neu;

    /* --- Gemeinsames --- */
    $rk_cfg['quelle'] = $rk_adresse(isset($_POST['quelle']) ? $_POST['quelle'] : '',
                                    rk_t('EINST.QUELLE'));
    $rk_w = $rk_zahl(isset($_POST['takt']) ? $_POST['takt'] : '', 60, 3600, rk_t('EINST.TAKT'), true);
    if ($rk_w !== null) { $rk_cfg['takt'] = $rk_w; }

    $rk_cfg['aussen_art'] = (isset($_POST['aussen_art']) && $_POST['aussen_art'] === 'eigen')
        ? 'eigen' : 'meteo';
    $rk_w = $rk_zahl(isset($_POST['breite']) ? $_POST['breite'] : '', -90, 90, rk_t('EINST.BREITE'));
    if ($rk_w !== null) { $rk_cfg['breite'] = $rk_w; }
    $rk_w = $rk_zahl(isset($_POST['laenge']) ? $_POST['laenge'] : '', -180, 180, rk_t('EINST.LAENGE'));
    if ($rk_w !== null) { $rk_cfg['laenge'] = $rk_w; }
    $rk_cfg['aussen_quelle'] = $rk_adresse(isset($_POST['aussen_quelle']) ? $_POST['aussen_quelle'] : '',
                                           rk_t('EINST.AUSSEN_QUELLE'));
    $rk_cfg['aussen_t'] = $rk_sauber(isset($_POST['aussen_t']) ? $_POST['aussen_t'] : '');
    $rk_cfg['aussen_rf'] = $rk_sauber(isset($_POST['aussen_rf']) ? $_POST['aussen_rf'] : '');
    if ($rk_cfg['aussen_art'] === 'eigen' && $rk_cfg['aussen_quelle'] === '') {
        $rk_fehler[] = rk_t('FEHLER.AUSSEN_OHNE_QUELLE');
    }

    $rk_w = $rk_zahl(isset($_POST['mindest']) ? $_POST['mindest'] : '', 0, 10, rk_t('EINST.MINDEST'));
    if ($rk_w !== null) { $rk_cfg['mindest'] = $rk_w; }
    $rk_w = $rk_zahl(isset($_POST['t_min']) ? $_POST['t_min'] : '', -30, 30, rk_t('EINST.T_MIN'));
    if ($rk_w !== null) { $rk_cfg['t_min'] = $rk_w; }
    $rk_w = $rk_zahl(isset($_POST['af_unter']) ? $_POST['af_unter'] : '', 0, 20, rk_t('EINST.AF_UNTER'));
    if ($rk_w !== null) { $rk_cfg['af_unter'] = $rk_w; }
    $rk_w = $rk_zahl(isset($_POST['vorschau']) ? $_POST['vorschau'] : '', 1, 48, rk_t('EINST.VORSCHAU'), true);
    if ($rk_w !== null) { $rk_cfg['vorschau'] = $rk_w; }

    $rk_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;
    $rk_thema_neu = strtolower($rk_sauber(isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : ''));
    $rk_thema_neu = trim($rk_thema_neu, '/');
    if ($rk_thema_neu === '') {
        $rk_cfg['mqtt_topic'] = 'raumklima';
    } elseif (!preg_match('#^[a-z0-9_\-/]+$#', $rk_thema_neu)) {
        // Ein Thema mit + oder # ist ein Filtermuster und als Ziel unbrauchbar.
        $rk_fehler[] = sprintf(rk_t('FEHLER.THEMA'), $rk_thema_neu);
    } else {
        $rk_cfg['mqtt_topic'] = $rk_thema_neu;
    }

    /* --- Zugangsdaten: eigene Datei, 0600 --- */
    $rk_g = rk_geheim();
    if (isset($_POST['zug_benutzer'])) {
        $rk_g['benutzer'] = $rk_sauber($_POST['zug_benutzer']);
    }
    if (isset($_POST['zug_passwort']) && (string) $_POST['zug_passwort'] !== '') {
        // Ein leeres Feld heisst "unveraendert", nicht "loeschen".
        $rk_g['passwort'] = (string) $_POST['zug_passwort'];
    }
    if (!empty($_POST['zug_loeschen'])) {
        $rk_g = array('benutzer' => '', 'passwort' => '');
    }
    rk_geheim_speichern($rk_g);

    if (rk_config_speichern($rk_cfg)) {
        $rk_meldungen[] = rk_t('ALLG.GESPEICHERT');
        rk_log('Einstellungen gespeichert.');
    } else {
        $rk_fehler[] = rk_t('FEHLER.SPEICHERN');
    }
    $rk_tab = isset($_POST['activetab']) && preg_match($rk_muster, (string) $_POST['activetab'])
        ? (string) $_POST['activetab'] : 'tab-settings';
}

/* ---------------- Jetzt abrufen ---------------- */
if ($rk_post && isset($_POST['abrufen'])) {
    $rk_s = rk_abrufen(true);
    if (!empty($rk_s['meldungen'])) {
        foreach ($rk_s['meldungen'] as $rk_k => $rk_m) {
            $rk_fehler[] = $rk_k . ': ' . rk_t('MELD.' . $rk_m);
        }
    } else {
        $rk_meldungen[] = rk_t('ALLG.ABGERUFEN');
    }
}

/* ---------------- Neues Wortzeichen ---------------- */
if ($rk_post && isset($_POST['token_neu'])) {
    $rk_cfg = rk_config();
    $rk_cfg['aktionstoken'] = rk_token_erzeugen();
    rk_config_speichern($rk_cfg);
    $rk_meldungen[] = rk_t('LOX.TOKEN_NEU_OK');
    rk_log('Neues Wortzeichen erzeugt.');
    $rk_tab = 'tab-loxone';
}

/* ---------------- Protokoll leeren ---------------- */
if ($rk_post && isset($_POST['log_leeren'])) {
    @file_put_contents($rk_p['log'], '');
    rk_log('Protokoll geleert.');
    $rk_meldungen[] = rk_t('LOG.GELEERT');
    $rk_tab = 'tab-log';
}

/* ---------------- Test ---------------- */
if ($rk_post && isset($_POST['test'])) {
    $rk_testausgabe = rk_test_ausfuehren((string) $_POST['test']);
    $rk_tab = 'tab-test';
}

/* ================= Werte fuer die Anzeige ================= */
$rk_cfg = rk_config();
$rk_g = rk_geheim();
$rk_stand = rk_stand();
$rk_raeume = rk_raeume();
$rk_mqtt = rk_mqtt_zustand();
$rk_alter = isset($rk_stand['ts']) ? max(0, time() - (int) $rk_stand['ts']) : -1;
$rk_basis = rk_endpunkt();
$rk_thema = trim((string) $rk_cfg['mqtt_topic'], '/');
// Nur das Ende lesen, nicht die ganze Datei - siehe rk_log_ende().
$rk_logzeilen = rk_log_ende($rk_p['log'], 400);

/* Eine Zahl anzeigen oder einen Strich. NIE eine erfundene 0: eine 0 bei der
 * Temperatur sieht aus wie eine Messung. */
function rk_z($v, $nach = 1, $einheit = '')
{
    if ($v === null || !is_numeric($v)) { return '&ndash;'; }
    return rk_e(number_format((float) $v, $nach, ',', '')) . ($einheit !== '' ? ' ' . rk_e($einheit) : '');
}

$rk_rahmen = class_exists('LBWeb', false);
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
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
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
</style>

<div class="sm-wrap">

<?php if ($rk_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('rk_e', $rk_meldungen)) ?></div>
<?php } ?>
<?php if ($rk_fehler) { ?>
<div class="sm-warnung"><b><?= rk_e(rk_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('rk_e', $rk_fehler)) ?></div>
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
    <b><?= $rk_alter < 0 ? '&ndash;' : (int) $rk_alter ?></b>
    <span class="sm-hilfe"><?= $rk_alter < 0 ? rk_e(rk_t('ALLG.NIE')) : rk_e(rk_t('ALLG.SEKUNDEN')) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $rk_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $rk_mqtt['autostart'] ? rk_e(rk_t('ALLG.EIN')) : rk_e(rk_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= rk_e(rk_t('ALLG.GATEWAY')) ?></span>
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
<div class="sm-tabs">
<?php foreach ($rk_reiter as $rk_k => $rk_schl): $rk_id = 'tab-' . $rk_k; ?>
	<a class="sm-tab<?= $rk_tab === $rk_id ? ' sm-active' : '' ?>" data-ziel="<?= rk_e($rk_id) ?>"
	   href="index.php?form=<?= rk_e($rk_k) ?>"><?= $rk_schl === null ? 'MQTT' : rk_e(rk_t($rk_schl)) ?></a>
<?php endforeach; ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= rk_e(rk_t('EINST.H_LAGE')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.LAGE_ERKLAERUNG') ?></div>

<?php if ($rk_stand && isset($rk_stand['raeume']) && $rk_stand['raeume']) { ?>
<h3><?= rk_e(rk_t('EINST.H_TABELLE')) ?></h3>
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
  </td>
  <td><?= rk_z($rk_r['t'], 1, '&deg;C') ?></td>
  <td><?= rk_z($rk_r['rf'], 0, '%') ?></td>
  <td><?= rk_z($rk_r['taupunkt'], 1, '&deg;C') ?></td>
  <td><?= rk_z($rk_r['absolut'], 2, 'g/m&sup3;') ?></td>
  <td><?= rk_z($rk_r['ober_t'], 1, '&deg;C') ?>
    <?php if ($rk_r['ober_rf'] !== null) { ?>
    <br><span class="<?= $rk_r['schimmel'] ? 'sm-aus' : '' ?>"><?= rk_z($rk_r['ober_rf'], 0, '%') ?></span>
    <?php } ?>
  </td>
  <td>
    <?php if (!$rk_r['ok']) { ?>
      <?= rk_e(rk_t('TAB.KEINE_WERTE')) ?>
    <?php } elseif ($rk_r['lueften']) { ?>
      <b class="sm-an"><?= rk_e(rk_t('TAB.JETZT_LUEFTEN')) ?></b><br>
      <span class="sm-hilfe"><?= sprintf(rk_e(rk_t('TAB.GEWINN')), rk_e(number_format((float) $rk_r['gewinn'], 2, ',', ''))) ?></span>
    <?php } elseif ($rk_r['best_in'] >= 0) { ?>
      <?= sprintf(rk_e(rk_t('TAB.SPAETER')), (int) $rk_r['best_std'], (int) round($rk_r['best_in'] / 60)) ?>
    <?php } else { ?>
      <?= rk_e(rk_t('MELD.' . strtoupper($rk_r['grund']))) ?>
    <?php } ?>
  </td>
</tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= rk_t('EINST.TABELLE_HINWEIS') ?></p>
<?php } else { ?>
<div class="sm-hinweis"><?= rk_t('EINST.NOCH_NICHTS') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rk_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="abrufen" value="1"><?= rk_e(rk_t('EINST.K_ABRUFEN')) ?></button>
  </form>
</div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="<?= rk_e($rk_tab) ?>">

<h2><?= rk_e(rk_t('EINST.H_QUELLEN')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.QUELLEN_ERKLAERUNG') ?></div>

<div class="sm-feld">
  <label for="rk_quelle"><?= rk_e(rk_t('EINST.QUELLE')) ?></label>
  <input data-role="none" type="text" id="rk_quelle" name="quelle" value="<?= rk_e($rk_cfg['quelle']) ?>" placeholder="http://gateway-im-heimnetz/get_livedata_info">
  <p class="sm-hilfe"><?= rk_t('EINST.QUELLE_HILFE') ?></p>
</div>

<table class="sm-tbl">
<tr>
  <th><?= rk_e(rk_t('EINST.RAUM')) ?></th>
  <th><?= rk_e(rk_t('EINST.NAME')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_T')) ?></th>
  <th><?= rk_e(rk_t('EINST.PFAD_RF')) ?></th>
  <th><?= rk_e(rk_t('EINST.EIGENE_QUELLE')) ?></th>
  <th>fRsi</th>
  <th><?= rk_e(rk_t('EINST.SOLL_MIN')) ?></th>
  <th><?= rk_e(rk_t('EINST.SOLL_MAX')) ?></th>
</tr>
<?php for ($rk_i = 0; $rk_i < RK_RAEUME; $rk_i++) { $rk_r = $rk_cfg['raeume'][$rk_i]; ?>
<tr>
  <td><?= $rk_i + 1 ?></td>
  <td><input data-role="none" type="text" size="14" name="r_name[<?= $rk_i ?>]" value="<?= rk_e($rk_r['name']) ?>"></td>
  <td><input data-role="none" type="text" size="22" name="r_pfad_t[<?= $rk_i ?>]" value="<?= rk_e($rk_r['pfad_t']) ?>"></td>
  <td><input data-role="none" type="text" size="22" name="r_pfad_rf[<?= $rk_i ?>]" value="<?= rk_e($rk_r['pfad_rf']) ?>"></td>
  <td><input data-role="none" type="text" size="20" name="r_quelle[<?= $rk_i ?>]" value="<?= rk_e($rk_r['quelle']) ?>"></td>
  <td><input data-role="none" type="text" size="4" name="r_frsi[<?= $rk_i ?>]" value="<?= rk_e($rk_r['frsi']) ?>"></td>
  <td><input data-role="none" type="text" size="3" name="r_min[<?= $rk_i ?>]" value="<?= rk_e($rk_r['soll_min']) ?>"></td>
  <td><input data-role="none" type="text" size="3" name="r_max[<?= $rk_i ?>]" value="<?= rk_e($rk_r['soll_max']) ?>"></td>
</tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= rk_t('EINST.TABELLE_FELDER') ?></p>

<h3><?= rk_e(rk_t('EINST.H_ZUGANG')) ?></h3>
<div class="sm-feld">
  <label for="rk_zb"><?= rk_e(rk_t('EINST.ZUG_BENUTZER')) ?></label>
  <input data-role="none" type="text" id="rk_zb" name="zug_benutzer" value="<?= rk_e($rk_g['benutzer']) ?>">
</div>
<div class="sm-feld">
  <label for="rk_zp"><?= rk_e(rk_t('EINST.ZUG_PASSWORT')) ?></label>
  <input data-role="none" type="password" id="rk_zp" name="zug_passwort" value="" autocomplete="new-password">
  <p class="sm-hilfe"><?= sprintf(rk_t('EINST.ZUG_HILFE'), strlen((string) $rk_g['passwort'])) ?></p>
  <label><input data-role="none" type="checkbox" name="zug_loeschen" value="1"> <?= rk_e(rk_t('EINST.ZUG_LOESCHEN')) ?></label>
</div>

<h2><?= rk_e(rk_t('EINST.H_AUSSEN')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.AUSSEN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="rk_aart"><?= rk_e(rk_t('EINST.AUSSEN_ART')) ?></label>
  <select data-role="none" id="rk_aart" name="aussen_art">
    <option value="meteo"<?= $rk_cfg['aussen_art'] === 'meteo' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_METEO')) ?></option>
    <option value="eigen"<?= $rk_cfg['aussen_art'] === 'eigen' ? ' selected' : '' ?>><?= rk_e(rk_t('EINST.ART_EIGEN')) ?></option>
  </select>
</div>
<div class="sm-feld">
  <label for="rk_breite"><?= rk_e(rk_t('EINST.BREITE')) ?></label>
  <input data-role="none" type="text" id="rk_breite" name="breite" value="<?= rk_e($rk_cfg['breite']) ?>">
</div>
<div class="sm-feld">
  <label for="rk_laenge"><?= rk_e(rk_t('EINST.LAENGE')) ?></label>
  <input data-role="none" type="text" id="rk_laenge" name="laenge" value="<?= rk_e($rk_cfg['laenge']) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.ORT_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_aq"><?= rk_e(rk_t('EINST.AUSSEN_QUELLE')) ?></label>
  <input data-role="none" type="text" id="rk_aq" name="aussen_quelle" value="<?= rk_e($rk_cfg['aussen_quelle']) ?>">
</div>
<div class="sm-feld">
  <label for="rk_at"><?= rk_e(rk_t('EINST.AUSSEN_T')) ?></label>
  <input data-role="none" type="text" id="rk_at" name="aussen_t" value="<?= rk_e($rk_cfg['aussen_t']) ?>">
</div>
<div class="sm-feld">
  <label for="rk_arf"><?= rk_e(rk_t('EINST.AUSSEN_RF')) ?></label>
  <input data-role="none" type="text" id="rk_arf" name="aussen_rf" value="<?= rk_e($rk_cfg['aussen_rf']) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.AUSSEN_EIGEN_HILFE') ?></p>
</div>

<h2><?= rk_e(rk_t('EINST.H_BEWERTUNG')) ?></h2>
<div class="sm-step"><?= rk_t('EINST.BEWERTUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="rk_mind"><?= rk_e(rk_t('EINST.MINDEST')) ?></label>
  <input data-role="none" type="text" id="rk_mind" name="mindest" value="<?= rk_e($rk_cfg['mindest']) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.MINDEST_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_tmin"><?= rk_e(rk_t('EINST.T_MIN')) ?></label>
  <input data-role="none" type="text" id="rk_tmin" name="t_min" value="<?= rk_e($rk_cfg['t_min']) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.T_MIN_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_afu"><?= rk_e(rk_t('EINST.AF_UNTER')) ?></label>
  <input data-role="none" type="text" id="rk_afu" name="af_unter" value="<?= rk_e($rk_cfg['af_unter']) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.AF_UNTER_HILFE') ?></p>
</div>
<div class="sm-feld">
  <label for="rk_vs"><?= rk_e(rk_t('EINST.VORSCHAU')) ?></label>
  <input data-role="none" type="text" id="rk_vs" name="vorschau" value="<?= rk_e($rk_cfg['vorschau']) ?>">
</div>
<div class="sm-feld">
  <label for="rk_takt"><?= rk_e(rk_t('EINST.TAKT')) ?></label>
  <input data-role="none" type="text" id="rk_takt" name="takt" value="<?= rk_e($rk_cfg['takt']) ?>">
  <p class="sm-hilfe"><?= rk_t('EINST.TAKT_HILFE') ?></p>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rk_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= rk_e(rk_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
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

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= $rk_cfg['mqtt_ein'] ? ' checked' : '' ?>> <?= rk_e(rk_t('MQTT.EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="rk_thema"><?= rk_e(rk_t('MQTT.THEMA')) ?></label>
  <input data-role="none" type="text" id="rk_thema" name="mqtt_topic" value="<?= rk_e($rk_cfg['mqtt_topic']) ?>">
  <p class="sm-hilfe"><?= rk_t('MQTT.THEMA_HILFE') ?></p>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= rk_e(rk_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= rk_e(rk_t('MQTT.H_THEMEN')) ?></h3>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('MQTT.SP_THEMA')) ?></th><th><?= rk_e(rk_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (rk_mqtt_themen() as $rk_k => $rk_schl) { ?>
<tr>
  <td><span class="sm-mono"><?= rk_e($rk_thema . '/' . $rk_k) ?></span></td>
  <td><?= rk_e(rk_t($rk_schl)) ?></td>
</tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= rk_t('MQTT.RAUMN_HILFE') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= rk_e(rk_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-step"><?= rk_t('LOX.ERKLAERUNG') ?></div>

<?php if (!$rk_raeume) { ?>
<div class="sm-warnung"><?= rk_t('LOX.KEINE_RAEUME') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= rk_t('LEGENDE.TECHNIK_XML') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vi"><?= rk_e(rk_t('LOX.K_VI')) ?></button>
  </form>
</div>

<h3><?= rk_e(rk_t('LOX.H_ADRESSE')) ?></h3>
<p class="sm-hilfe"><?= rk_t('LOX.ADRESSE_HILFE') ?></p>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_ZWECK')) ?></th><th><?= rk_e(rk_t('LOX.SP_ADRESSE')) ?></th></tr>
<tr><td><?= rk_e(rk_t('LOX.Z_STATUS')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=status') ?></span></td></tr>
<tr><td><?= rk_e(rk_t('LOX.Z_JSON')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=json') ?></span></td></tr>
<tr><td><?= rk_e(rk_t('LOX.Z_ABRUFEN')) ?></td><td><span class="sm-mono"><?= rk_e($rk_basis . '?token=' . rk_token() . '&aktion=abrufen') ?></span></td></tr>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= rk_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= rk_e(rk_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<p class="sm-hilfe"><?= rk_t('LOX.TOKEN_HINWEIS') ?></p>

<h3><?= rk_e(rk_t('LOX.H_FELDER')) ?></h3>
<table class="sm-tbl">
<tr><th><?= rk_e(rk_t('LOX.SP_FELD')) ?></th><th><?= rk_e(rk_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (rk_felder() as $rk_f => $rk_info) { ?>
<tr><td><span class="sm-mono">R&lt;n&gt;<?= rk_e($rk_f) ?></span></td>
    <td><?= rk_e(rk_t($rk_info[3])) ?><?= $rk_info[0] !== '' ? ' [' . rk_e($rk_info[0]) . ']' : '' ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $rk_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= rk_e(rk_t('TEST.H')) ?></h2>
<div class="sm-step"><?= rk_t('TEST.ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= rk_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= rk_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
<?php foreach (array(
    'selbsttest' => array('sm-b-technik', 'TEST.K_SELBSTTEST'),
    'quellen'    => array('sm-b-lesen', 'TEST.K_QUELLEN'),
    'meteo'      => array('sm-b-lesen', 'TEST.K_METEO'),
    'rechnung'   => array('sm-b-lesen', 'TEST.K_RECHNUNG'),
    'mqtt'       => array('sm-b-technik', 'TEST.K_MQTT'),
    'endpunkt'   => array('sm-b-technik', 'TEST.K_ENDPUNKT'),
) as $rk_k => $rk_i) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn <?= rk_e($rk_i[0]) ?>" type="submit" name="test" value="<?= rk_e($rk_k) ?>"><?= rk_e(rk_t($rk_i[1])) ?></button>
  </form>
<?php } ?>
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
