# Changelog

## 1.1.2 (22.10.2026)

- Ersatzadresse `https://contaolabs.zstov.de/api/license` entfernt, die Testdomain gibt es nicht mehr. Geprüft wird nur noch `https://contaolabs.de/api/license`; ist sie nicht erreichbar, bleibt wie bisher der letzte Stand gültig und nichts wird gesperrt
- Eigene Adressen über die Umgebungsvariable `CONTAOLABS_LICENSE_API` werden weiter der Reihe nach probiert
- Versionsangabe im User-Agent korrigiert (stand noch auf 1.1.0)

## 1.1.1 (22.10.2026)

- Meldung bei erreichter Obergrenze nennt die Lizenzstufen (S, M, L, Unlimited) und den Wechsel in eine höhere Stufe unter „Mein Konto“

## 1.1.0 (21.10.2026)

- Ohne gültige Lizenz sind die Backend-Bereiche der Erweiterung gesperrt, mit rotem Hinweis auf jeder Backend-Seite. Das Frontend läuft weiter
- Die Erweiterungen melden Backend-Menüpunkte, Modultypen und Inhaltselemente an
- Der Hinweis auf der Backend-Startseite entfällt (ersetzt durch den roten Hinweis)

## 1.0.1 (21.10.2026)

- Kürzerer Hilfetext unter den Schlüsselfeldern, damit er in den Einstellungen nicht abgeschnitten wird

## 1.0.0 (21.10.2026)

- Erste Version: Lizenzschlüssel in den Contao-Einstellungen, tägliche Prüfung im Hintergrund, Hinweis im Backend, Entwicklungsdomains frei
