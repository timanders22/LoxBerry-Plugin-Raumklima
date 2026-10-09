# LoxBerry-Plugin Raumklima

Taupunkt, absolute Feuchte, Schimmelrisiko und eine Lüftungsempfehlung **mit
Uhrzeit** — für beliebig viele Räume und beliebige Sensor-Hardware.

Version 0.11.17 · benötigt LoxBerry ab 3.0.0 · reines PHP (7.4 und 8.x)

---

## Neu in 0.11.17

Baustein-Liste in der Schreibweise des Leitungswerkzeugs, gemeinsame Sprachausgabe 1.1.2.

* **Baustein-Liste (Reiter Einbindung in Loxone):** Die Spalte „Eingänge verbinden mit“ nennt die
  Quellen in fester Form: `Ausgang von RK_ALTER (#2)` statt „#2 RK_ALTER“, `I1 = #18, I2 = #19` statt
  „Eingang 1: #18, Eingang 2: #19“, an der Benachrichtigung `Ausgang von #25` (dass nur dieser eine
  Ausgang daran kommt, sagt der Hinweis darunter). Gleiche Bausteine, gleiche Verbindungen.
* **Sprachausgabe 1.1.2:** gemeinsames Modul und Abschnitt [ANSAGE] mit 157 Sätzen. Die Sätze zu einem
  unbekannten Eintrag im Block der Sprachausgabe und zu einem Block, der kein Feld von Einstellungen
  ist, bringt jetzt das Modul mit; die beiden eigenen Umlenkungen sind gestrichen (gleicher Wortlaut).
  Dazu aus dem Modul: Zeichenzahl bei kaputtem UTF-8 in Zeichen, die Meldung „Port abgewiesen“ nennt
  das Feld nicht mehr doppelt.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 0.11.16

Kopf wie alle Hausplugins: Statusübersicht über den Reitern, Zusammenfassung oben im ersten Reiter.

* **Zusammenfassung** des Plugins in einem grünen Kasten oben im Reiter Einstellungen.
* Die Statuskacheln über den Reitern (Räume, Lüften, Schimmel, letzter Abruf, MQTT) bleiben,
  wie sie sind.
* Nur Oberfläche; gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 0.11.15

Eigene Sprachausgabe bei „Lüften empfohlen“ und „Schimmelgefahr“, ab Werk aus (Entscheidung 36/40).
Gemessen unter
PHP 7.4 und 8.5 gegen Attrappen (Fühlerquelle, Music Server, Alexa-NG, Chromecast 4 Lox NG); nicht am Gerät, nicht an einem echten Lautsprecher.

* **Neu: Raumklima sagt selbst an (ab Werk aus).** Reiter Einstellungen, Abschnitt „Sprachausgabe“: Loxone Music
  Server, MusicServer4Home, eine eigene Adressvorlage, Alexa-NG oder Google-Lautsprecher (Chromecast 4 Lox NG).
* Zwei Anlässe, je Raum und je mit eigenem Haken: **Lüften empfohlen** und **Schimmelgefahr**. Gesprochen wird
  einmal beim Eintritt, nicht in jedem Abruf; erst wenn der Zustand endet und wieder eintritt, kommt die nächste
  Ansage. Ein kurz stummer Fühler löst keine zweite Ansage aus. Treten mehrere Räume im selben Abruf ein, kommt ein
  Satz mit allen Raumnamen; bei nur einem eingerichteten Raum nennt der Satz keinen Namen.
* Wer die Ausgabe einschaltet, während ein Zustand schon anhält, hört ihn beim nächsten Abruf einmal.
* Adresse des Music Servers und Adressvorlage müssen im Heimnetz liegen – eine andere Adresse wird beim Speichern
  abgewiesen (sie trüge den Ansagetext hinaus).
* Testansage per Knopf im Reiter Test; die Zeile „Ist die Sprachausgabe eingerichtet?“ zeigt Ausgabeart, gewählte
  Anlässe und wie die letzte Ansage ausging (Alexa-NG/Chromecast werden dabei gefragt, ohne dass dort etwas
  gesprochen wird).
* Die Sprechtoken für Alexa-NG und Chromecast 4 Lox NG stehen nie in der Seite, im Protokoll oder in einer
  Sicherung; eine Sicherungsdatei, die eines trägt, wird abgewiesen, das gespeicherte bleibt. Eine Sicherung aus
  0.11.14 oder früher wird weiter angenommen; die Sprachausgabe bleibt dabei, wie sie ist. Eine Sicherung aus
  0.11.15 nimmt 0.11.14 nicht zurück (unbekannte Einstellung `tts`).
* Eigene **Ruhezeit der Ansagen** (z. B. 22:00 bis 07:00, ab Werk leer): ein Zustand, der in ihr beginnt, wird
  weder angesagt noch morgens nachgeholt.
* Mit dem gemeinsamen Sprachmodul 1.1.1 sind Zonen mit leerem Eintrag oder Lautstärke 0 (`1,2,`, `2~0`) und eine
  Alexa-/Google-Lautstärke 0 nicht mehr zulässig – sie ergäben eine stumme Ansage.
* Einbindung in Loxone: kein neuer Baustein nötig (Satz in der Baustein-Liste).

**In Loxone:** nichts zu tun; wer die Ansagen will, schaltet sie im Reiter Einstellungen ein.

## Neu in 0.11.14

Baustein-Liste nach A4 (Nachzug B).
Gemessen an der gerenderten Oberfläche
(PHP 8.4, Netzsperre) und mit `php -l` unter 7.4/8.4/8.5; nicht am Gerät.

* **Baustein-Liste im Reiter „Einbindung in Loxone“:** Die fünf Störungsquellen liefen bisher über ein ODER mit
  fünf Eingängen (#22). Jetzt führt eine Kette aus vier ODER mit je zwei Eingängen sie zusammen (#22 bis #25,
  „Störung Raumklima, Teil 1–3“ und „Störung Raumklima“); jede Zeile verweist nur auf kleinere Nummern. Die
  folgenden Bausteine rücken um drei (Benachrichtigung jetzt #26, Freigabe-UND #30, Zählerwächter #31).
  **In Loxone:** nichts zu tun – ein schon gebautes ODER mit fünf Eingängen wirkt gleich.

## Neu in 0.11.13

Durchgang mit vier Prüfern (Befunde: `Pruefung-Durchgang-2026-09-29/Raumklima_BEFUNDE_UND_VERBESSERUNGEN.md`, Entscheidungen 1, 4, 8, 16, 19 und 26).
Gemessen mit Attrappen für Fühler, Open-Meteo und Broker unter PHP 7.4, 8.3, 8.4 und 8.5; nicht am Gerät.

* **Fühler am Anschlag:** Eine Feuchte unter 1 % rF gilt als Fühlerausfall. Bisher
  ergab z. B. 20 °C/0,1 % einen Taupunkt von −58 °C und eine Mindest-Vorlauftemperatur,
  die Loxone als „jede Vorlauftemperatur erlaubt“ las.
* **`OK` im Endpunkt** fällt auf 0, sobald die Werte älter als das Dreifache des
  Takts sind; `ALTER` und `RALTER` gelten zur Abfragezeit.
* **Vorlage:** Die Grenzen von 16 Feldern sind geweitet (Dachboden, Bad, Frost) –
  bitte die Loxone-Vorlage neu importieren.
* Ein Open-Meteo-Aussetzer wird mit der gespeicherten Vorhersage überbrückt.
* **Speichern:** Bei einer Beanstandung wird nichts gespeichert, die Eingaben kommen
  markiert zurück, nichts wird mehr still verbogen. „Einstellungen sichern“ warnt;
  beim Zurückspielen werden die Raumfelder geprüft, ein leeres Token behält das
  geltende.
* **Neuinstallation:** Alte Einstellungen, Zugangsdaten und Verlauf einer früheren
  Installation werden nach `.alt` gelegt statt eingespielt (`preinstall.sh`).
* **MQTT:** Bei Fühlerausfall bleiben die Zustände stehen (die gesperrte
  Kühlfreigabe bleibt retained), ein ausgetragener Raum wird mit `-` geräumt,
  Präfixwechsel und „MQTT aus“ räumen ab, die Abodatei wird mitgeliefert; gesendet
  werden nur Änderungen, der volle Satz alle 30 Minuten. Gateway V1 einmal neu
  starten, damit es die Abodatei liest.
* PHP 8.5: keine Verfallsmeldung mehr (`$http_response_header` ersetzt).
* Der Endpunkt protokolliert Aufrufe (gebremst, ohne Token).

## Neu in 0.11.12

Sammelnachzug vom 30.09.2026, sonst keine Änderung: `curl_close()` wird nur
noch unter PHP 7 aufgerufen. Ab PHP 8.0 wirkt der Aufruf nicht mehr, und
PHP 8.5 meldet ihn zur Laufzeit als veraltet. Bei eingeschalteter
Fehleranzeige konnte diese Meldung vor einer Antwort an Loxone landen. Am
LoxBerry mit PHP 7.4 ändert sich nichts.

## Neu in 0.11.11

- **Die Kachel „MQTT" zeigt jetzt, ob dieses Plugin veröffentlicht.** Bis 0.11.10
  stand dort als großer Wert der Autostart des MQTT-Gateways von LoxBerry, und
  „MQTT ein" las sich, als sende das Plugin — auch wenn es im Reiter MQTT
  ausgeschaltet war. Der Autostart des Gateways steht jetzt klein darunter;
  fehlt der MQTT-Abschnitt in der LoxBerry-Konfiguration, heißt er dort
  „nicht feststellbar" statt „aus".
- **Was der Abruf über sich selbst sagt, geht nicht mehr retained hinaus.**
  `ok`, `raumN/ok`, `ohne` und `ampellos` sagen, ob der Abruf selbst Werte
  bekommen hat; `steht`, `raumN/steht`, `raumN/ruhe`, `zwang` und
  `raumN/zwang` werden allein durch die Uhr falsch. Zurückbehalten stünden sie
  nach dem Ende des Abrufs für immer als „in Ordnung" im Broker. Sie gehen
  jetzt ohne Retain hinaus; Themen und Werte sind unverändert, in Loxone ist
  nichts umzustellen. Nach einem Neustart von Broker oder Gateway fehlen sie
  bis zum nächsten Lauf (höchstens fünf Minuten). Ebenso geht bei
  `raumN/ampel` und `raumN/schimmel` der Wert −1 („keine Aussage", etwa bei
  einem stummen Fühler) ohne Retain hinaus: Loxone sieht −1 wie bisher, und im
  Broker bleibt der letzte echte Wert stehen, statt überschrieben zu werden.
- **Die alten zurückbehaltenen Werte räumt das Plugin selbst ab.** Es fragt
  den Broker (Adresse und Zugangsdaten aus der LoxBerry-Konfiguration), welche
  davon noch stehen, schickt für jedes unmittelbar vor dem gültigen Wert eine
  leere retained Nachricht und merkt sich „erledigt" erst, wenn der Broker
  bestätigt, dass nichts mehr dasteht. **Grenze:** lässt sich der Broker nicht
  befragen (kein Broker-Eintrag, Anmeldung abgewiesen), geht die leere
  Nachricht in jedem Lauf vor dem gültigen Wert hinaus; Loxone sieht dann
  jedes Mal für einen Augenblick einen leeren Wert.
- **Die Deinstallation leert die zurückbehaltenen Themen** der Linie
  (`raumklima_abruf.php --mqtt-leeren`, mit Rückfrage beim Broker). Themen
  unter einem früher eingestellten Präfix erreicht sie nicht.
- **Ein Abruf mitten im Update kostete den Verlauf.** Zwischen dem Kopieren
  der neuen Dateien und dem Abschluss eines Updates liegt fast eine Minute.
  Lief der Fünf-Minuten-Takt in dieser Lücke, legte er einen neuen Verlauf an,
  und das Update verwarf daraufhin die Rettung — Nassstunden,
  Lüftungserfolg und Feuchteeintrag waren weg. Eine Marke hält jetzt jeden
  Abruf an, bis das Update fertig ist, und die Rettung wird erst gelöscht,
  wenn sie byteweise am Ziel steht.
- **Ein ausgepacktes Archiv fasst die Anlage nicht mehr an.** Ohne
  LoxBerry-Wurzel (erkannt an `config/system/general.json`) oder aus einem
  Archiv heraus holt der Abruf nichts, sendet nichts und schreibt nichts; die
  Hakenskripte warnen, statt zu handeln. Kein fester Systempfad mehr und
  keine Datei mehr, die ab der Laufwerkswurzel gesucht wird.
- **Nach einem Update rät das Installationsprotokoll nicht mehr zur
  Ersteinrichtung**, wenn die Räume übernommen wurden.

## Neu in 0.11.10

Die Sicherungen beim Update entscheiden jetzt nach dem **Inhalt** einer
Datei, nicht mehr nach ihrer Größe. Wer nichts davon merkt, hat nichts
verloren; die Änderungen greifen nur, wenn eine Datei beschädigt ist.

- **Die Zugangsdaten gingen bei einer beschädigten `geheim.json` endgültig
  verloren.** Eine abgeschnittene Datei ist nicht leer. Bis 0.11.9 galt sie
  deshalb als „wieder da“, und `postupgrade.sh` löschte die heile Zweitschrift
  — das Protokoll meldete dabei `<OK> Zwischengelegte Zugangsdaten wieder
  entfernt.` Jetzt wird die Zweitschrift nur gelöscht, wenn in `geheim.json`
  wirklich ein Benutzername oder Kennwort steht, und die Meldung sagt, ob die
  Datei danach tatsächlich weg ist.
- **Dieselbe Lücke an acht weiteren Stellen**, je vier in `preupgrade.sh`
  und `postinstall.sh`: eine abgeschnittene Konfiguration, `geheim.json` oder
  `verlauf.json` verdrängte die heile Sicherung, wurde nicht zurückgespielt,
  oder die Rettung wurde gelöscht, ohne dass ihr Inhalt irgendwo angekommen
  war. Überall entscheidet jetzt der Inhalt; kann `php` den Inhalt nicht
  prüfen, wird nichts überschrieben und nichts gelöscht.
- **Die Zweitschrift der Einstellungen wird unteilbar geschrieben.** Bis
  0.11.9 kopierte das Speichern sie mit `copy()`; das leert die alte Datei,
  bevor die neue steht. Brach der Vorgang dazwischen ab (volle Karte), gab es
  keine Zweitschrift mehr.
- **Eine aus der Zweitschrift wiederhergestellte `raumklima.json` bekommt
  wieder die Rechte 0600.** Bis 0.11.9 stand sie danach auf 0644.
- **Die Oberfläche lädt ihre Bibliothek zuerst aus `$LBHOMEDIR`.** Liegt eine
  zweite Kopie des Plugins in einem anderen Baum, wird nicht mehr deren
  Bibliothek geladen.

## Neu in 0.11.8

**Nach dem Update die Loxone-Vorlage neu erzeugen und importieren** — fünf
Grenzen und 34 Einheiten der Vorlage haben sich geändert (unten). Loxone
Config legt beim Import neu an und überschreibt nichts; eine alte Vorlage
also vorher entfernen.

- **Kurze Kachelnamen.** Der Kommentar jedes Vorlagenbefehls wird in Loxone
  Config zum Namen der Kachel. Bis 0.11.7 stand dort der ganze Erklärtext —
  51 von 75 Namen waren länger als 40 Zeichen, der längste 127. Jetzt heißen
  sie „Raumname Temperatur“, „Raumname Schimmelgefahr“ oder „Raumklima
  Außentemperatur“; der ausführliche Text steht weiter in den Tabellen der
  Oberfläche. Wer die alte Vorlage schon importiert hat, benennt entweder von
  Hand um oder importiert neu.
- **Nach jedem Knopfdruck lädt die Seite neu, statt das Formular zu
  wiederholen.** Bis 0.11.7 wurde nach einem POST sofort gerendert; ein
  Neuladen im Browser wiederholte den Vorgang (Abruf, neues Wortzeichen,
  Protokoll leeren). Jetzt antwortet jeder Knopf mit einer Umleitung, und
  Meldung, Fehler oder Testausgabe erscheinen danach genau einmal.
  Die Downloads (Vorlage, Sicherung) sind davon ausgenommen.
- **Die Raumtabelle zeigt wieder °C und g/m³.** Bis 0.11.7 stand dort
  wörtlich `22,5 &deg;C` und `8,37 g/m&sup3;` — die Einheit wurde als
  HTML-Entität übergeben und dann noch einmal maskiert.
- **Kein Cron-Lauf fällt mehr aus.** Die Taktschranke lag genau auf dem
  Takt; am Gerät hielt der Abstand sie um null Sekunden ein, und zweimal in
  einer Stunde entfiel ein Lauf samt Lebenszeichen. Sie liegt jetzt eine
  halbe Minute darunter.
- **`?selftest=1&token=…` am Endpunkt** beantwortet, ob ein Wortzeichen
  gilt, ohne etwas auszulösen: `SELFTEST;OK=1;TOKEN=OK`, bei falschem
  Wortzeichen HTTP 403 `SELFTEST;OK=0;ERR=TOKEN`, ohne eingerichtetes
  HTTP 403 `SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET`.
- **`?aktion=abrufen` ist gebremst.** Liegt der letzte Lauf weniger als
  60 Sekunden zurück, kommt der letzte Stand zurück (dieselbe Antwortzeile),
  der Grund steht in der Kopfzeile `X-Raumklima-Abruf` und einmal je Stunde
  im Protokoll. Vorher löste jeder Aufruf einen vollen Lauf aus.
- **Zwischen zwei MQTT-Datagrammen liegen 5 ms.** Der UDP-Eingang des
  Gateways verwirft unter Last stumm; bei einem Raum dauert ein Versand
  damit rund 0,35 Sekunden.
- **Die Prüfzeile „Steht der Cron-Eintrag?" misst jetzt auf der Anlage.**
  Sie suchte den Eintrag nur im Plugin-Ordner, den es installiert nicht gibt,
  und zeigte immer einen Strich.
- **Fehlende Einstellungen werden beim Cron-Lauf ergänzt**, einmal und mit
  einer Protokollzeile, die die Schlüssel nennt. Vorher geschah das nur beim
  Speichern und ohne Hinweis.
- **Die Adresse in Vorlage und Adresstabelle** trägt nie mehr `127.0.0.1`
  oder `localhost`, wenn die Oberfläche über eine Rückschleife geöffnet
  wurde; dann gilt der Rechnername. Der Reiter *Einbindung in Loxone* sagt
  jetzt, dass der Rechnername zu prüfen ist.

**Vorlage:** jeder Befehl trägt eine Einheit (einheitenlose Werte `<v.0>`,
wie in den Ausfuhren von Loxone Config), und fünf Grenzen sind geweitet,
weil `MaxVal` in Config eine Validierung ist und ein Wert darüber zu 0 wird:
`RALTER` 86 400 → 8 640 000 s (ein Tag Funkstille stand sonst als „eben
gemessen" da), `TROCKENREST` 240 → 99 999 h, `KOSTEN` 5 000 → 99 999 Wh,
`EINTRAG` 2 000 → 99 999 g/h, `TROCKNEN` ±5 000 → ±99 999 g/h.

## Neu in 0.11.5

- **Zustände gehen jetzt mit Retain hinaus.** Bis 0.11.4 schickte das Plugin
  ausnahmslos `publish`; am laufenden Broker gemessen waren **0 von 26** Themen
  retained — während diese README und die Hilfe das Gegenteil sagten. Jetzt
  gehen die **36 Zustandsthemen** (`ok`, `schimmel`, `lueften`, `raumN/ampel`,
  …) retained hinaus, damit der Miniserver nach einem Neustart sofort wieder
  den Stand hat. **Messwerte mit Zeitbezug** (Temperatur, `alter`, Zeitstempel)
  und das **Lebenszeichen** bleiben ohne Retain — sonst stünde nach einem
  Ausfall ein alter Wert da und sähe aus wie ein aktueller. Welches Thema was
  ist, steht jetzt als eigene Spalte in der Themenübersicht im Reiter *MQTT*;
  die Spalte wird gerechnet, nicht getippt.
- **„Letzter Abruf“ zeigt wieder eine Zeitspanne.** Solange keine Messung
  gelungen war, stand dort der Unix-Zeitstempel als Sekundenzahl — eine
  zehnstellige Zahl, die aussah wie ein Alter (`isset()` statt einer Prüfung
  auf einen Wert größer null; `stand.json` führt dann `ts: 0`). Die Kachel
  schreibt die Spanne jetzt aus („gerade eben“, „vor 75 Minuten“, „vor 2
  Tagen“, „noch nie“) und nennt die genaue Sekundenzahl darunter. In der
  Antwortzeile und über MQTT bleibt `ALTER` unverändert eine Zahl — dort
  rechnet Loxone damit.
- **Die Raumnummer bleibt beim Rollen stehen.** Die vier breiten Tabellen
  rollen waagerecht; die erste Spalte lief bis 0.11.4 mit hinaus, und dann
  standen Eingabefelder da, ohne dass man den Raum dazu sah.

## Neu in 0.11.4

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 0.11.3 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

## Was es macht

Für jeden eingerichteten Raum:

| Wert | Bedeutung |
|---|---|
| Taupunkt | ab wann Wasser ausfällt |
| absolute Feuchte | Gramm Wasser je Kubikmeter — die Größe, auf die es beim Lüften ankommt |
| kälteste Fläche | geschätzte Oberflächentemperatur und die Feuchte dort |
| Schimmelrisiko | 1 ab 80 % relativer Feuchte an dieser Fläche |
| Taupunktabstand | wie viele Kelvin dieser Fläche bis zum Tauwasser fehlen |
| Vorlaufgrenze | kleinste Kühl-Vorlauftemperatur ohne Kondensat |
| Schimmelampel | −1 keine Aussage · 0 unbedenklich · 1 beobachten · 2 Gefahr · 3 Tauwasser |
| Nassstunden | wie lange die Fläche in 24 h und 7 Tagen über 80 % lag |
| Lüften jetzt | 0/1 plus der Gewinn in g/m³ und der Grund |
| Lüftungsdauer | Minuten bis zu einem Luftwechsel, und was er an Wärme kostet |
| Kühlen | 0/1 plus der Temperaturgewinn in Kelvin |
| bester Zeitpunkt | Minuten und Stunde — aus der Wettervorhersage |
| Ausfall | Sekunden seit dem letzten Wert, und ob der Fühler steht |
| Erfolg | Anteil der Empfehlungen, nach denen die Feuchte wirklich fiel |
| Feuchteeintrag | geschätzte Gramm je Stunde, die im Raum dazukommen |

Dazu die Sammelwerte: wie viele Räume gerade gelüftet werden sollten, wie
viele gefährdet sind, wie viele außerhalb ihres Feuchtekorridors liegen.

- **Ansagen (seit 0.11.15, ab Werk aus):** Auf Wunsch sagt das Plugin selbst an, wenn in einem Raum
  Lüften empfohlen wird oder Schimmelgefahr eintritt – einmal beim Eintritt, nicht in jedem Abruf;
  jeder Anlass ist einzeln abwählbar, bei mehreren Räumen nennt der Satz den Raum. Ausgabe über die
  gemeinsame Sprachausgabe der Plugins dieses Hauses: Loxone Music Server, MusicServer4Home, eine eigene
  Adressvorlage, Alexa-NG oder Google-Lautsprecher (Chromecast 4 Lox NG). Adresse und Vorlage müssen im
  Heimnetz liegen; die Sprechtoken stehen in keiner Sicherung. Eine eigene Ruhezeit der Ansagen (ab Werk
  leer) hält die Nacht frei; was in sie fällt, wird nicht nachgeholt. Testansage und Prüfzeile im Reiter Test.

## Warum absolute Feuchte

Draußen 5 °C bei 90 % rF enthalten **6,1 g/m³**. Drinnen 20 °C bei 50 % sind
**8,6 g/m³**. Die kalte, „nasse“ Außenluft ist also trockener — im Zimmer
werden aus ihren 90 % rund 34 %.

Wer nach relativer Feuchte lüftet, macht es im Winter genau falsch herum.
Das Plugin vergleicht deshalb durchgehend g/m³.

Formeln: Magnus-Gleichung in der Fassung von Sonntag (1990), wie sie die WMO
führt. Der Selbsttest rechnet gegen Lehrbuchwerte nach — und gegen jeden
Grenzfall, an dem das Plugin schon einmal gestanden hat. Die Fallzahl nennt
er beim Laufen; eine Zahl an dieser Stelle wäre nur die nächste, die
veraltet.

## Sensoren: keine Marke, ein Format

Das Plugin nimmt **jede Adresse, die JSON liefert**, und einen **Pfad** darin
(`ch_aisle.0.temp`). Damit funktionieren Ecowitt, Shelly, Zigbee-Gateways,
Endpunkte anderer LoxBerry-Plugins und selbst erzeugte Dateien gleichermaßen.

Werte mit Einheit im Text (`"52%"`, `"24.6 C"`, `"22.5°"`) und deutsches
Dezimalkomma werden gelesen; je Raum lässt sich **°C oder °F** und **% oder
0–1** einstellen. Ein Wert außerhalb von −60 bis 80 °C oder eine Feuchte von
genau 0 % gilt als Ausfall, nicht als Messung.

Liefert eine Antwort alle Räume — der Normalfall bei Gateways —, wird sie pro
Abruf **einmal** geholt, nicht einmal je Raum.

Im Reiter *Test* zeigt „Quellen und Pfade prüfen“, welche Schlüssel es an der
Stelle wirklich gibt, an der ein Pfad abbricht.

## Mehr als „ist es draußen trockener"

**Kühlen.** Ein Raum kann eine Zieltemperatur bekommen. Ist es draußen kühler
und nicht deutlich feuchter, wird Lüften auch dann empfohlen, wenn die Feuchte
allein nicht dafür spräche — der Fall der Sommernacht, in dem 25 °C innen und
17 °C außen bisher „lohnt nicht" ergaben.

**Wie lange, und was es kostet.** Aus Raumvolumen, Fensterart (gekippt, ganz
offen, Durchzug), Temperaturunterschied und Wind schätzt das Plugin die
Minuten bis zu einem Luftwechsel und die Wärme, die dabei hinausgeht. Beides
ist eine **Schätzung** und als solche gekennzeichnet; die Spannen in der
Literatur sind weit.

**CO₂.** Ein dritter, freiwilliger Pfad je Raum. Über der eingestellten Grenze
wird gelüftet, auch wenn die Feuchte dagegen spricht.

**Fensterkontakt.** Ein vierter Pfad — etwa aus dem Miniserver selbst. Damit
sagt das Plugin auch, wann das Fenster wieder **zu** gehört.

**Regen und Wind** kommen aus derselben Open-Meteo-Anfrage wie Temperatur und
Feuchte und kosten nichts extra. Bei Starkregen wird nicht zum Lüften geraten.

**Hysterese und Nachlauf.** Eingeschaltet wird beim Mindestunterschied,
ausgeschaltet erst deutlich darunter, und eine Empfehlung bleibt eine
einstellbare Zeit stehen. Ohne das kippte sie an 0,4 Prozentpunkten
Messrauschen — bei einem Abruf alle fünf Minuten.

## Der Verlauf

Zwölf Stunden im Fünfminutentakt und dreißig Tage als Stundenmittel, unter
`data/plugins/raumklima/verlauf.json` (rund 30 kB je Raum, davon 25 kB die
Stundenreihe; bis 0.11.2 stand hier „rund 6 kB“, das war nur die
Fünfminutenreihe). Erst damit lassen
sich drei Fragen beantworten, die wichtiger sind als jede Momentaufnahme:

* Wie lange steht die kalte Fläche schon über 80 %? Schimmel wächst aus
  Stunden, nicht aus Minuten — deshalb die Ampel und die Nassstunden statt
  eines Bits, das bei genau 80 % umspringt.
* Hat eine Empfehlung **gewirkt**? Eine halbe Stunde nach jedem Umschalten
  wird nachgesehen, ob die absolute Feuchte wirklich gefallen ist.
* Wie viel Wasser kommt je Stunde dazu? Aus dem Anstieg zwischen zwei
  Lüftungen und dem Raumvolumen — „hier kommen 120 g/h dazu" ist eine andere
  Aussage als „es ist feucht".

## Aussenluft

**Open-Meteo**, kostenlos und ohne Konto, stündlich für die nächsten Tage.
Die Vorhersage ist der eigentliche Zweck: nicht nur *ob* Lüften jetzt lohnt,
sondern *ob es sich lohnt zu warten*.

Alternativ die eigene Wetterstation — dann allerdings ohne Vorhersage, und
damit ohne die einzige Aussage, die eine Formel in Loxone nicht auch schon
liefern kann. Das Plugin sagt das in dem Fall ausdrücklich.

## Schimmel: eine Schätzung, die als solche gekennzeichnet ist

    T_Oberfläche = T_außen + fRsi × (T_innen − T_außen)

`fRsi` ist eine **Einstellung je Raum**, keine Messung. DIN 4108-2 verlangt im
Neubau an Wärmebrücken mindestens 0,70; ungedämmte Altbau-Ecken liegen
darunter. Wer es genau wissen will, klebt einen Fühler in die kalte Ecke und
trägt ihn als eigenen „Raum“ ein.

Diese Formel gilt aber **nur für eine Außenwand im Heizfall**. Deshalb hat
jeder Raum eine **Art**:

* **Außenwand** — wie oben. Ist es draußen wärmer als drinnen, gibt es keine
  kalte Ecke aus der Außenluft; dann gilt die Raumtemperatur.
* **Keller / Erdreich** — die Wand folgt dem Erdreich, nicht der Außenluft.
  Genau dort entsteht Sommerschimmel: 18 °C und 70 % im Keller sind an einer
  13 °C kalten Wand rund 96 % relative Feuchte. Mit dem Außenwandmodell las
  sich derselbe Fall als unauffällige 56 %.
* **Innenraum** — keine Schimmelaussage statt einer erfundenen.

**„Keine Aussage" ist ein eigener Wert, nicht die Null.** `AMPEL` und
`SCHIMMEL` tragen **−1**, wo sich nichts sagen lässt: bei einem stummen
Fühler und bei der Raumart *Innenraum*. Bis 0.11.1 stand dort eine 0 — von
„gemessen und unbedenklich" nicht zu unterscheiden. Wer einen Wächter auf
`AMPEL = 0` legt, verließe sich sonst auf einen Fühler, der seit Tagen
schweigt. `NAMPELLOS` zählt diese Räume; wie viele **keine** Werte tragen, sagt
`NOHNE` daneben (`OK` ist nur ein 0/1-Merkmal).

Dasselbe gilt für `NASS24` und `NASS7T`: eine Stunde, in der über die kalte
Fläche **nichts** bekannt war, zählt nicht als trockene Stunde mit, sondern
gar nicht. Sonst erschiene ein tagelanger Ausfall der Außenquelle als
lückenlos trockene Wand — und das ist die gefährliche Richtung.

> **Nach dem Update die Loxone-Vorlage neu importieren.**
> `SCHIMMEL` reicht seit 0.11.2 von −1 bis 1 und `ALTER` seit 0.11.3
> ebenfalls; mit der alten Vorlage schneidet Loxone die −1 ab und zeigt
> wieder die 0, die hier vermieden werden soll. (Für `AMPEL` gilt das schon
> seit 0.11.0.) 0.11.3 vergibt außerdem **eindeutige Kurznamen**: zwei
> Räume, deren Namen in den ersten zwölf Buchstaben übereinstimmen
> („Kinderzimmer Nord“ und „Kinderzimmer Süd“), bekamen bis dahin
> dieselben Eingangsnamen.

## Nach Loxone

Zwei Wege, beide gleichzeitig nutzbar:

* **MQTT** über das MQTT Gateway von LoxBerry — ohne Broker-Zugangsdaten.
* **Virtueller Eingang** — der Reiter „Einbindung in Loxone“ erzeugt eine
  fertige Vorlage zum Import, mit Adresse und Wortzeichen darin.

Fehlende Werte werden **nicht** gesendet und als Strich angezeigt. Eine 0
wäre bei einer Temperatur eine Falschaussage.

**Ausfälle sind sichtbar.** `OK` ist **1**, sobald mindestens *ein* Raum
Werte liefert und die letzte erfolgreiche Messung nicht älter ist als das
Dreifache des Abruftakts, sonst 0 — ein Merkmal, kein Zähler. `ALTER` und
`RALTER` rechnet der Endpunkt zur Abfragezeit. Wie viele Räume ohne
Werte dastehen, sagt `NOHNE`; je Raum sagen `RALTER` die Sekunden seit dem
letzten gültigen Wert und
`STEHT`, ob sich der Wert seit einer einstellbaren Zeit überhaupt nicht mehr
bewegt hat. Ein eingefrorener Fühler liefert sonst unauffällige Zahlen — nur
immer dieselben —, und in Loxone fällt das nie auf, weil virtuelle Eingänge
ohnehin ihren letzten Wert behalten.

## Wann NICHT gelüftet wird

Drei **harte Sperren** stehen über allen Gründen — auch über CO₂ und über der
Kühlung:

* **Starkregen** und **Sturm**, beides aus derselben Open-Meteo-Anfrage.
* **Drohendes Tauwasser an der kältesten Fläche.** Die Außenluft kann absolut
  trockener sein und ihr Taupunkt trotzdem über der kalten Wand liegen — dann
  fällt genau dort Wasser aus. Keller 20 °C/72 %, Wand 13 °C, draußen
  25 °C/51 %: die Außenluft ist um 0,70 g/m³ trockener, ihr Taupunkt liegt mit
  14,2 °C aber 1,2 K über der Wand, und an der Wand wären es danach 100 %.

Dazu eine **Ruhezeit** je Raum (verbrauchte Luft schlägt sie), eine
**Zwangslüftung** nach N Stunden ohne Gelegenheit und eine eigene, mildere
Frostgrenze für den CO₂-Grund.

## Lüftungsanlage, Wäsche, Behaglichkeit

Ein fünfter Pfad je Raum liest die **Zulufttemperatur**; daraus rechnet das
Plugin die Rückwärmzahl der Anlage aus drei Temperaturen. Mit der Rückwärmzahl
als Angabe kommt die **Fortlufttemperatur** dazu und die Warnung, wenn der
Wärmetauscher einzufrieren droht. Mit Raumvolumen sagt es, wie viel Wasser ein
Luftwechsel **austrägt**, und mit einer Wäschemenge, wie lange das dauert.
**Schwüle** misst es am Wassergehalt, nicht an der relativen Feuchte.

## Woran man merkt, dass der Abruf steht

Ein virtueller Eingang behält seinen letzten Wert. Bei den **Zuständen** hält
ihn der Broker seit 0.11.5 zusätzlich mit Retain über jeden Neustart hinweg;
Messwerte gehen bewusst ohne. Stirbt der Abruf, steht in Loxone weiter die
letzte Zahl —
das ist keine fehlende Auskunft, sondern eine Falschaussage. Dagegen gehen drei
Werte hinaus: `OK`, ein **Zeitstempel** der letzten *erfolgreichen* Messung und
ein **Zähler**, der 0 bis 999 umläuft. Der Zähler beantwortet, was ein
Zeitstempel nicht kann: ein Raspberry ohne gepufferte Uhr springt beim ersten
Zeitabgleich.

`ALTER` misst seit 0.11.0 das Alter der **Werte**, nicht mehr den Zeitpunkt des
letzten Laufs. Bleibt eine Quelle stumm, wächst es — vorher stand dort
dauerhaft eine Null. Solange **nie** eine Messung gelang, steht `-1` da;
seit 0.11.3 trägt die Vorlage dafür `MinVal=-1` (vorher 0 — Loxone schnitt
die −1 ab und zeigte „gerade eben gemessen“), und die Obergrenze steht auf
100 Tagen statt auf 24 Stunden.

## CO₂ und die Personenzahl

Je Raum lässt sich eintragen, wie viele Menschen sich dort üblicherweise
aufhalten. Daraus und aus dem Raumvolumen rechnet das Plugin, **wie schnell
CO₂ ansteigt** und **welcher Luftwechsel nötig wäre**, um die eingestellte
Grenze zu halten:

> Eine Person in einem 30 m³ großen Schlafzimmer erzeugt rund **567 ppm je
> Stunde**. Um 1000 ppm gegen 420 ppm Außenluft zu halten, braucht es knapp
> **einen Luftwechsel je Stunde** — ein gekipptes Fenster leistet etwa das.
> Zu zweit reicht es nicht mehr.

Der **erwartete** Anstieg (aus Personenzahl und Volumen) und der **gemessene**
(aus dem Verlauf) stehen nebeneinander, und das ist Absicht: wer beide sieht,
erkennt ein gekipptes Fenster und einen driftenden Fühler. Die **Restzeit bis
zur Grenze** wird aus dem gemessenen Anstieg gerechnet — eine Zahl aus einer
Schätzung sähe aus wie eine aus einer Messung.

Wie viel CO₂ ein Mensch ausatmet, ist eine **Einstellung**, keine Messung:
schlafend rund 13 l/h, sitzend rund 17 (Vorgabe), bei leichter Arbeit rund 25.
Ohne Personenzahl bleiben die Felder leer statt geraten; ab Werk steht dort 0.

## Umzug auf einen zweiten LoxBerry

Zwei Knöpfe im Reiter Einstellungen: sichern und zurückspielen. Die Datei
enthält **das Aktionstoken dieser Anlage** — ohne es stünden nach dem
Zurückspielen alle Felder richtig, und der Miniserver käme trotzdem nicht
heran. Sie ist deshalb wie ein Passwort zu behandeln.

Benutzername und Passwort der Sensorquelle kommen **nur mit gesetztem Haken**
mit; dann steht das Passwort im Klartext in der Datei, und der Kopf der Datei
sagt das auch. Beim Zurückspielen landen sie in `geheim.json`, **nicht** in der
Konfigurationsdatei. Enthält eine Sicherung keine Zugangsdaten, werden
vorhandene auf dem Zielsystem **nicht** gelöscht — und die Oberfläche sagt, dass
sie fehlen.

## VOC

Nicht enthalten — anders als CO₂, das seit 0.10.0 als dritter Pfad je Raum
gelesen wird. Sobald jemand VOC-Werte liefert, ist die generische JSON-Quelle
der richtige Ort dafür; erfundene Felder ohne Fühler helfen niemandem.

## Installation

LoxBerry → Plugin-Verwaltung → Zip-Datei hochladen. Danach in der
Plugin-Oberfläche:

1. Breiten- und Längengrad eintragen.
2. Adresse der Sensorquelle und je Raum die beiden Pfade.
3. Speichern, dann „Jetzt abrufen“.

**Schritt 2 geht auch von selbst**, wenn die Fühler schon in Loxone stehen:
ganz oben im Reiter *Einstellungen* holt „Räume vom Miniserver holen“ die
Raumliste und schlägt je Raum Temperatur- und Feuchtebaustein vor.
Zugeordnet wird über den **Raum**, nicht über den Namen. Der erste Knopf
schreibt nichts.

Steht in einem Raum mehr als ein Baustein zur Wahl, wird er **übersprungen
und genannt** — welcher der richtige ist, kann das Plugin nicht wissen. In
solchen Fällen trägt man in *Nur aus dieser Kategorie* einen Teil des
Kategorienamens ein (an einer Shelly-Anlage etwa `Shelly`) und holt die
Liste erneut. Ebenso genannt wird, was **nicht mehr hineinpasst**: die
Tabelle führt zwölf Zeilen, und belegte bleiben belegt.

### Zugangsdaten

Der Assistent legt Benutzer und Passwort des Miniservers in `geheim.json`
ab. Sie gehen **nur an Wirte, die auch Fühler dieser Anlage tragen** — an
die Adressen der Raumquellen und an den Miniserver selbst. Eine frei
eingetragene Außenquelle auf einem fremden Rechner bekommt sie nicht; das
Protokoll sagt einmal je Stunde, dass sie zurückgehalten wurden.

Der Abruf läuft danach alle fünf Minuten über Cron; ein Dauerdienst ist nicht
nötig, Raumklima ändert sich langsam.

## Lizenz

Siehe `LICENSE`.
