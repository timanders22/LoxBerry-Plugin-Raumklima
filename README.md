# LoxBerry-Plugin Raumklima

Taupunkt, absolute Feuchte, Schimmelrisiko und eine Lüftungsempfehlung **mit
Uhrzeit** — für beliebig viele Räume und beliebige Sensor-Hardware.

Version 0.11.8 · benötigt LoxBerry ab 3.0.0 · reines PHP (7.4 und 8.x)

---

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
Werte liefert, sonst 0 — ein Merkmal, kein Zähler. Wie viele Räume ohne
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
