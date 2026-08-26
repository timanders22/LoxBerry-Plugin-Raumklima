# LoxBerry-Plugin Raumklima

Taupunkt, absolute Feuchte, Schimmelrisiko und eine Lüftungsempfehlung **mit
Uhrzeit** — für beliebig viele Räume und beliebige Sensor-Hardware.

Version 0.10.0 · benötigt LoxBerry ab 3.0.0 · reines PHP (7.4 und 8.x)

---

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
| Schimmelampel | 0 unbedenklich · 1 beobachten · 2 Gefahr · 3 Tauwasser |
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
`data/plugins/raumklima/verlauf.json` (rund 6 kB je Raum). Erst damit lassen
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

## Nach Loxone

Zwei Wege, beide gleichzeitig nutzbar:

* **MQTT** über das MQTT Gateway von LoxBerry — ohne Broker-Zugangsdaten.
* **Virtueller Eingang** — der Reiter „Einbindung in Loxone“ erzeugt eine
  fertige Vorlage zum Import, mit Adresse und Wortzeichen darin.

Fehlende Werte werden **nicht** gesendet und als Strich angezeigt. Eine 0
wäre bei einer Temperatur eine Falschaussage.

**Ausfälle sind sichtbar.** `OK` zählt Räume *mit Werten*, nicht eingetragene
Räume; je Raum sagen `RALTER` die Sekunden seit dem letzten gültigen Wert und
`STEHT`, ob sich der Wert seit einer einstellbaren Zeit überhaupt nicht mehr
bewegt hat. Ein eingefrorener Fühler liefert sonst unauffällige Zahlen — nur
immer dieselben —, und in Loxone fällt das nie auf, weil virtuelle Eingänge
ohnehin ihren letzten Wert behalten.

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

Der Abruf läuft danach alle fünf Minuten über Cron; ein Dauerdienst ist nicht
nötig, Raumklima ändert sich langsam.

## Lizenz

Siehe `LICENSE`.
