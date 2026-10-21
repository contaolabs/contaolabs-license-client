# contaolabs License Client

Gemeinsame Lizenzprüfung für die Erweiterungen von [contaolabs.de](https://contaolabs.de). Contao 4.13 (und 5.x), PHP 8.1 oder neuer. Wird als Abhängigkeit der Erweiterungen installiert und hat selbst keine Lizenzpflicht (MIT).

## Für Shop-Betreiber

Lizenzschlüssel unter **System → Einstellungen → contaolabs-Lizenzen** eintragen. Die Seite zeigt darunter den Stand der Prüfung.

- **Wann:** höchstens einmal täglich je Erweiterung und Domain, nur nach dem Aufruf einer Backend-Seite und erst nachdem die Seite ausgeliefert ist. Beim Speichern eines neuen Schlüssels sofort.
- **Was:** Lizenzschlüssel, Produktkennung und Domain, per POST an `https://contaolabs.de/api/license` (Ersatz: `https://contaolabs.zstov.de/api/license`). Keine Daten von Besuchern oder Kunden.
- **Welche Domain:** die Domains der veröffentlichten Startpunkte (Feld „Domainname“), sonst die Domain des Backend-Aufrufs.
- **Entwicklungsumgebungen:** `localhost`, private IP-Adressen, `*.local`, `*.test`, `*.localhost`, `*.ddev.site`, `*.lndo.site`, `dev.*`, `stage.*`, `staging.*`, `test.*`. Dafür wird nichts gesendet.
- **Ohne gültige Lizenz:** nur ein Hinweis auf der Startseite des Backends (für Administratoren). Keine Funktion wird abgeschaltet.
- **Lizenzserver nicht erreichbar:** Der letzte Stand bleibt gültig, neuer Versuch nach 6 Stunden. Zeitlimit je Anfrage 3 Sekunden.
- **Zwischenspeicher:** `var/contaolabs/licenses.json` (ohne den Schlüssel selbst). Ist der Ordner nicht beschreibbar, wird gar nicht geprüft.

Die Adresse der API lässt sich mit der Umgebungsvariable `CONTAOLABS_LICENSE_API` überschreiben (mehrere Adressen durch Komma getrennt).

## Für Erweiterungen

In der `composer.json` `"contaolabs/license-client": "^1.0"` verlangen und in der `config.php` die Produktkennung (Alias des Produkts im Shop) anmelden:

```php
$GLOBALS['CONTAOLABS_LICENSES']['isotope-onepage-checkout'] = 'Isotope OnePage-Checkout';
```

## Tests

```bash
composer install
vendor/bin/phpunit
```
