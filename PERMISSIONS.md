# BankImport Modul - Berechtigungen konfigurieren

## Zugriff auf den Bankimport

Der Bankimport verwendet dieselbe Berechtigung wie das Bearbeiten von
Bankkonten in Dolibarr: **Banken und Kassen ändern** (`banque->modifier`).

## Lösung: Berechtigungen einrichten

### Schritt 1: Modul aktivieren

1. Gehen Sie zu **Setup** → **Module/Applications**
2. Suchen Sie nach "BankImport"
3. Aktivieren Sie das Modul **BankImport**.

### Schritt 2: Berechtigungen konfigurieren

1. Gehen Sie zu **Setup** → **Users & Groups** → **Permissions**
2. Wählen Sie die gewünschte Benutzergruppe aus (z.B. "Users" oder "Administrators")
3. Scrollen Sie zum Abschnitt **Banken und Kassen**
4. Aktivieren Sie die Berechtigung zum Ändern von Bankkonten
5. Klicken Sie auf **Speichern**

### Schritt 3: Benutzer-Berechtigungen prüfen

1. Gehen Sie zu **Setup** → **Users & Groups** → **Users**
2. Wählen Sie den gewünschten Benutzer aus
3. Gehen Sie zum Tab **Permissions**
4. Stellen Sie sicher, dass die Berechtigung zum Ändern unter Banken und Kassen aktiviert ist

## Überprüfung der Installation

### 1. Modul-Status prüfen

Gehen Sie zu **Setup** → **Module/Applications** und stellen Sie sicher, dass:
- BankImport als **aktiviert** angezeigt wird
- Keine Fehlermeldungen vorhanden sind

### 2. Menü-Eintrag prüfen

Gehen Sie zu **Bank** und prüfen Sie, ob der Menüpunkt **"Kontoauszüge importieren"** angezeigt wird.

### 3. Berechtigungen testen

1. Melden Sie sich mit einem Benutzer an, der die Berechtigungen hat
2. Gehen Sie zu **Bank** → **Kontoauszüge importieren**
3. Die Seite sollte ohne "Zugriff verweigert" laden

## Häufige Probleme

### Problem: Berechtigungen werden nicht gespeichert
**Lösung**:
- Cache leeren (Setup → Tools → Clear cache)
- Browser-Cache leeren
- Dolibarr neu starten

### Problem: Modul wird nicht angezeigt
**Lösung**:
- Überprüfen Sie die Dateiberechtigungen
- Stellen Sie sicher, dass alle Dateien korrekt kopiert wurden
- Prüfen Sie die Dolibarr-Logs auf Fehler

### Problem: Menüpunkt fehlt
**Lösung**:
- Modul deaktivieren und wieder aktivieren
- Überprüfen Sie die Menü-Konfiguration in der Modulklasse

## Support

Bei weiterhin bestehenden Problemen:
- Überprüfen Sie die Dolibarr-Logs
- Kontaktieren Sie den Systemadministrator
- Erstellen Sie ein Issue im GitHub-Repository
