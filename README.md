# Import of Bank Statement File

**Author**: Tilo Thiele <tilo.thiele@hamburg.de>
**License**: MIT (see License.txt)
The upstream repository also contains GPL-3.0-or-later notices in `import.php` and `core/class/BankImport.class.php`. Those notices are preserved; this fork does not resolve the upstream licensing inconsistency.
**Github**: https://github.com/daniel1v/bankimport

## Description

Das BankImport-Modul importiert Haspa/camt.052-v8- und N26-Kontoaktivitätsberichte im CSV-Format nach Dolibarr. Das Format wird anhand der Kopfzeile automatisch erkannt. Vor dem Import zeigt eine Vorschau jede Buchung sowie kontoabhängige Duplikat- und Ähnlichkeitshinweise; der Benutzer entscheidet selbst, welche Zeilen importiert werden.

### Features

- ✅ Import von Haspa/camt.052 v8 und aktuellen englischen N26-CSV-Dateien
- ✅ Automatische Format- und Separatorerkennung (`,` oder `;`)
- ✅ Unterstützung für UTF-8 und ISO-8859-1 Kodierung
- ✅ Auswahl einzelner Buchungen in einer Importvorschau
- ✅ Kontoabhängige Hinweise auf exakte Duplikate und ähnliche Buchungen
- ✅ Atomarer Import von Bankzeile und Import-Schlüssel
- ✅ Validierung der CSV-Daten vor dem Import
- ✅ Mehrsprachige Unterstützung (Deutsch/Englisch)
- ✅ Berechtigungen an das Bank-Modul gekoppelt

### System Requirements

- **PHP**: 7.4 oder höher
- **Dolibarr**: 24.x
- **Aktiviertes Bank-Modul** in Dolibarr

## Installation

Siehe [INSTALL.md](INSTALL.md) für detaillierte Installationsanweisungen.

### Quick Start

1. Kopieren Sie das Modul in `/path/to/dolibarr/htdocs/custom/bankimport/`
2. Aktivieren Sie das Modul in Dolibarr (Setup → Module/Applications)
3. Konfigurieren Sie die Berechtigungen
4. Gehen Sie zu **Bank** → **Kontoauszüge importieren**, erstellen Sie eine Vorschau und markieren Sie die gewünschten Buchungen.

### Lokale Entwicklung (Dolibarr 24)

Für die Integrationstests steht eine VS-Code-Devcontainer-Umgebung bereit. Sie startet Dolibarr 24 mit PHP 8.2 und MariaDB; das aktuelle Repository wird direkt als `custom/bankimport` eingebunden.

1. Öffnen Sie das Repository in VS Code und wählen Sie **Dev Containers: Reopen in Container**.
2. Dolibarr ist anschließend unter `http://127.0.0.1:8088` verfügbar (bei einer neuen lokalen Datenbank: `admin` / `admin`).
3. Im Devcontainer können Sie die CSV-Regressionsprüfung mit `php tests/BankImportCsvTest.php` ausführen.

VS Code stoppt den Stack beim Schließen des Devcontainers (`shutdownAction: stopCompose`). Während einer Entwicklungssitzung kann er mit `docker compose -f .devcontainer/compose.yml up -d` gestartet und mit `docker compose -f .devcontainer/compose.yml stop` wieder gestoppt werden; es gibt keine automatische Neustartregel. Datenbank und Dokumente bleiben in Docker-Volumes erhalten.

Die Integrationstests laufen im Dolibarr-Container und sind auf die lokale Entwicklungsinstanz beschränkt:

```sh
docker compose -f .devcontainer/compose.yml exec -T dolibarr php /var/www/html/custom/bankimport/tests/BankImportDolibarrIntegration.php
docker compose -f .devcontainer/compose.yml exec -T dolibarr php /var/www/html/custom/bankimport/tests/BankImportHttpTest.php
```

Der HTTP-Test prüft Anmeldung, Upload, Auswahl, bewusstes Importieren von Duplikaten, CSRF und erneutes Absenden mit synthetischen Daten, die anschließend entfernt werden. Die Tests sind ausschließlich per CLI ausführbar und fehlen im Installations-ZIP.

Das Installationspaket lässt sich mit `php build/package.php 0.1.0` erstellen (PHP-Erweiterung `zip` erforderlich).

## CSV Format

### Unterstützte Formate

* **Haspa/camt.052 v8:** bestehendes CSV-Format mit Haspa-Kopfzeilen wie `Buchungstag`, `Verwendungszweck` und `Betrag`.
* **N26 (englisch):** CSV-Kontoaktivitätsbericht mit `Booking Date`, `Value Date`, `Partner Name`, `Partner Iban`, `Type`, `Payment Reference` und `Amount (EUR)`.
* `Category`, `Account Name`, `Original Amount`, `Original Currency` und `Exchange Rate` sind bei N26 optional.
* Die Kopfzeile bestimmt das Format; Reihenfolge der Spalten und Separator (`,` oder `;`) werden automatisch erkannt. Quoted CSV-Felder sind unterstützt.
* N26-Daten unterstützen `YYYY-MM-DD`; Haspa weiterhin `DD.MM.YY` und `DD.MM.YYYY`.
* Unterstützte Kodierungen: ISO-8859-1, UTF-8.

Für N26 wird das Dolibarr-Label aus `Payment Reference`, andernfalls `Type` und zuletzt dem Partnernamen gebildet. Die Duplikaterkennung ist auf das ausgewählte Dolibarr-Bankkonto beschränkt. N26- und neue Haspa-Import-Keys enthalten das Buchungsdatum; historische Haspa-Keys werden weiterhin erkannt. Exakte Duplikate und ähnliche Buchungen werden in der Vorschau markiert, bleiben aber bewusst auswählbar.

Wiederholte Zeilen innerhalb derselben CSV werden ebenfalls markiert. Zeilen mit Warnungen sind zunächst abgewählt; andere Zeilen sind vorausgewählt. Die Vorschau läuft nach einer Stunde ab. Pro Datei gelten 10 MB und höchstens 5.000 Buchungen; bei niedrigem PHP-Formularlimit (`max_input_vars`) wird das Zeilenlimit entsprechend reduziert. Fremdwährungen, geschlossene Konten und Kassenkonten sind für diesen Import nicht vorgesehen.

### Field Mapping

Nicht alle Haspa-Felder werden importiert. Das Mapping zu Dolibarr-Feldern:

| CSV Field | Dolibarr Field | Description |
|-----------|----------------|-------------|
| 1 | dateo | Buchungstag |
| 2 | datev | Valutadatum |
| 4 | label | Verwendungszweck |
| 5 | note | Gläubiger-ID |
| 6 | note | Mandatsreferenz |
| 8 | note | Sammlerreferenz |
| 11 | emetteur | Begünstigter/Zahlungspflichtiger |
| 12 | note | Kontonummer/IBAN (Gegenpartei) |
| 13 | banque, note | BIC (Gegenpartei) |
| 14 | amount | Betrag |
| 15 | Kontowährung | Wird gegen die Währung des gewählten Kontos geprüft |

### N26-Mapping

| N26-Feld | Internes Transaktionsfeld | Dolibarr-Verwendung |
|---|---|---|
| `Booking Date` | `booking_date` | Buchungstag |
| `Value Date` | `value_date` | Valutadatum (leer → Buchungstag) |
| `Partner Name`, `Partner Iban` | Gegenpartei | Name und IBAN |
| `Type`, `Payment Reference` | Buchungstext/Verwendungszweck | Label und Referenz |
| `Amount (EUR)` | `amount` | Betrag, Währung ist immer EUR |

## 📑 Record Description – Haspa CSV (camt.052 v8 Export)

This document describes the structure of the CSV export file (Haspa, format camt.052 v8).

---

### Table of Fields

| Field name | Description | Example |
|------------|-------------|---------|
| **Account** (`Auftragskonto`) | IBAN of the account for which the statement is created. | `DE82200505501139432180` |
| **Booking Date** (`Buchungstag`) | Date on which the bank posts the transaction. | `04.08.25` |
| **Value Date** (`Valutadatum`) | Value date (date relevant for interest calculation). | `04.08.25` |
| **Booking Text** (`Buchungstext`) | Short text from the bank indicating the transaction type. | `GUTSCHRIFT UEBERWEISUNG` |
| **Payment Purpose** (`Verwendungszweck`) | Purpose of payment or accounting text provided by the originator. | `Tina Pilz` |
| **Creditor ID** (`Glaeubiger ID`) | SEPA Creditor Identifier (for SEPA direct debits). | *(empty in example)* |
| **Mandate Reference** (`Mandatsreferenz`) | Mandate reference of the SEPA direct debit. | *(empty in example)* |
| **Customer Reference (End-to-End)** | End-to-End reference from the originator. | *(empty in example)* |
| **Collector Reference** (`Sammlerreferenz`) | Batch reference of a SEPA direct debit collection. | *(empty in example)* |
| **Original Direct Debit Amount** (`Lastschrift Ursprungsbetrag`) | Original amount of the direct debit before chargeback. | *(empty in example)* |
| **Chargeback Fee** (`Auslagenersatz Ruecklastschrift`) | Bank fee related to chargebacks. | *(empty in example)* |
| **Counterparty Name** (`Beguenstigter/Zahlungspflichtiger`) | Name of the business partner (payer or payee). | `TINA PILZ` |
| **Counterparty IBAN** (`Kontonummer/IBAN`) | IBAN of the business partner. | `DE54200505123435467997` |
| **Counterparty BIC** (`BIC (SWIFT-Code)`) | BIC of the partner's bank. | `HASPDEHHXXX` |
| **Amount** (`Betrag`) | Transaction amount. Positive = credit (inflow), Negative = debit (outflow). | `5.00` |
| **Currency** (`Waehrung`) | Currency of the transaction. | `EUR` |
| **Info** | Status information, usually `"Umsatz gebucht"` ("transaction booked"). | `Umsatz gebucht` |

---

### Notes

- **Positive amounts** = incoming funds (credits).
- **Negative amounts** = outgoing payments, charges, fees.
- Some fields are **only filled for SEPA direct debits** (e.g. *Creditor ID*, *Mandate Reference*, *Collector Reference*).
- **Booking Text + Payment Purpose** often need to be combined to fully identify the transaction.
- **Info** field is usually static (`Umsatz gebucht` = booked transaction).

## Security Features

- ✅ CSRF-Schutz durch Dolibarr-Token-System
- ✅ Sichere Datei-Upload-Validierung
- ✅ SQL-Injection-Schutz durch `$db->escape()`
- ✅ Berechtigungsprüfung vor Zugriff
- ✅ Dateigrößen-Limit (10 MB)
- ✅ Dateityp-Validierung

## Support

Bei Fragen oder Problemen:
- **Autor**: Tilo Thiele
- **E-Mail**: tilo.thiele@hamburg.de
- **Lizenz**: MIT

## Changelog

Siehe [ChangeLog.md](ChangeLog.md) für detaillierte Änderungen.

### Version 0.1.0
- Importvorschau mit Einzelauswahl
- Kontoabhängige Duplikat- und Ähnlichkeitshinweise
- Atomarer Import von Buchung und Import-Key
- Aktuelle englische N26-CSV sowie Haspa/camt.052 v8
- Dolibarr 24 und PHP 7.4+
