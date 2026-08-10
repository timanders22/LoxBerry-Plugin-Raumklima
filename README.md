# LoxBerry-Plugin Raumklima

Taupunkt, absolute Feuchte, Schimmelrisiko und eine Lüftungsempfehlung **mit
Uhrzeit** — für beliebig viele Räume und beliebige Sensor-Hardware.

Fassung 0.9.1 · benötigt LoxBerry ab 3.0.0 · reines PHP (7.4 und 8.x)

---

## Was es macht

Für jeden eingerichteten Raum:

| Wert | Bedeutung |
|---|---|
| Taupunkt | ab wann Wasser ausfällt |
| absolute Feuchte | Gramm Wasser je Kubikmeter — die Größe, auf die es beim Lüften ankommt |
| kälteste Fläche | geschätzte Oberflächentemperatur und die Feuchte dort |
| Schimmelrisiko | 1 ab 80 % relativer Feuchte an dieser Fläche |
| Lüften jetzt | 0/1 plus der Gewinn in g/m³ |
| bester Zeitpunkt | Minuten und Stunde — aus der Wettervorhersage |

Dazu die Sammelwerte: wie viele Räume gerade gelüftet werden sollten, wie
viele gefährdet sind, wie viele außerhalb ihres Feuchtekorridors liegen.

## Warum absolute Feuchte

Draußen 5 °C bei 90 % rF enthalten **6,1 g/m³**. Drinnen 20 °C bei 50 % sind
**8,6 g/m³**. Die kalte, „nasse“ Außenluft ist also trockener — im Zimmer
werden aus ihren 90 % rund 34 %.

Wer nach relativer Feuchte lüftet, macht es im Winter genau falsch herum.
Das Plugin vergleicht deshalb durchgehend g/m³.

Formeln: Magnus-Gleichung in der Fassung von Sonntag (1990), wie sie die WMO
führt. Der Selbsttest rechnet **51 Fälle** gegen Lehrbuchwerte nach.

## Sensoren: keine Marke, ein Format

Das Plugin nimmt **jede Adresse, die JSON liefert**, und einen **Pfad** darin
(`ch_aisle.0.temp`). Damit funktionieren Ecowitt, Shelly, Zigbee-Gateways,
Endpunkte anderer LoxBerry-Plugins und selbst erzeugte Dateien gleichermaßen.

Liefert eine Antwort alle Räume — der Normalfall bei Gateways —, wird sie pro
Abruf **einmal** geholt, nicht einmal je Raum.

Im Reiter *Test* zeigt „Quellen und Pfade prüfen“, welche Schlüssel es an der
Stelle wirklich gibt, an der ein Pfad abbricht.

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

## Nach Loxone

Zwei Wege, beide gleichzeitig nutzbar:

* **MQTT** über das MQTT Gateway von LoxBerry — ohne Broker-Zugangsdaten.
* **Virtueller Eingang** — der Reiter „Einbindung in Loxone“ erzeugt eine
  fertige Vorlage zum Import, mit Adresse und Wortzeichen darin.

Fehlende Werte werden **nicht** gesendet und als Strich angezeigt. Eine 0
wäre bei einer Temperatur eine Falschaussage.

## Kein CO₂, kein VOC

Bewusst nicht enthalten. Ohne Sensoren gäbe es nichts zu rechnen, und eine
Oberfläche mit leeren Feldern hilft niemandem. Sobald welche da sind, ist die
generische JSON-Quelle der richtige Ort dafür.

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
