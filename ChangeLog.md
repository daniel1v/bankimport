# Changelog for bankimport module


## 0.1.0

* added two-step preview and explicit per-row selection
* exact duplicates and similar transactions are warnings and remain selectable
* scoped duplicate detection to the selected Dolibarr bank account
* added booking date to new Haspa keys while recognizing historical keys
* made bank-line and import-key creation atomic
* added current English N26 CSV support and Dolibarr 24 integration tests
* warn about repeated CSV rows and protect against truncated selections and replay
* preserve long UTF-8 descriptions and reject invalid amounts and encodings
* restrict imports to enabled modules, internal users and open bank accounts
* add CLI-only, development-scoped HTTP tests and reproducible package builder


## 0.0.11

* security fixe: enabled only for users with bank permission

## 0.0.10

Initial public version
