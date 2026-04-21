# UniFi Voucher Management System

Webbasiertes System zur Verwaltung von WLAN-Vouchers für UniFi OS mit Multi-Site-Unterstützung, Benutzerverwaltung und Microsoft 365 Integration.

## Features

- **Voucher-Erstellung** mit QR-Code-Anzeige und E-Mail-Versand
- **Multi-Site-Support** – mehrere UniFi-Standorte verwalten
- **Benutzerverwaltung** mit granularer Site-Zugriffskontrolle
- **Authentifizierung** via lokale Accounts oder Microsoft 365 OAuth
- **CSV-Export** aller Vouchers pro Site
- **Admin-Dashboard** mit Live-Statistiken und Sync-Funktion
- **Öffentlicher Zugriff** – optional ohne Login nutzbar
- CSRF-Schutz, bcrypt-Passwörter, Prepared Statements, Login-Rate-Limiting

## Anforderungen

- PHP 7.4+, MySQL 5.7+ / MariaDB 10.2+, Apache/Nginx
- PHP-Extensions: PDO, PDO_MySQL, cURL, mbstring, JSON
- **UniFi Network Application 7.0+ mit UniFi OS** (z.B. UDM, UDR, UniFi OS Server)

## Installation

```bash
git clone https://github.com/friloo/unifi-voucher-tool.git
cd unifi-voucher-tool
```

1. Dateien auf den Webserver hochladen
2. `http://ihre-domain.de/install.php` öffnen
3. Den 5-Schritte-Assistenten durchlaufen:
   - **Schritt 1:** Datenbank-Verbindungsdaten
   - **Schritt 2:** Administrator-Account (Name, E-Mail, Passwort)
   - **Schritt 3:** Allgemeine Einstellungen (Titel, Logo, öffentlicher Zugriff)
   - **Schritt 4:** Microsoft 365 Integration (optional)
   - **Schritt 5:** Installation abschließen
4. `install.php` nach erfolgreicher Installation löschen

## Sites konfigurieren

1. **Administration → Sites verwalten → Neue Site hinzufügen**
2. Felder ausfüllen:
   - **Name:** Anzeigename (z.B. „Hauptgebäude")
   - **Site ID:** UniFi Site ID (meist `default`)
   - **Controller URL:** `https://unifi.example.com:11443`
   - **Benutzername / Passwort:** UniFi Admin-Zugangsdaten
3. **Verbindung testen** klicken, dann speichern

## Voucher erstellen

1. Startseite öffnen (Login je nach Konfiguration optional)
2. Voucher-Name, Anzahl Geräte und Standort wählen
3. **Voucher erstellen** – Code und QR-Code werden sofort angezeigt
4. Code per E-Mail senden oder ausdrucken

## Konfiguration

### config.php
Wird automatisch durch den Installer erstellt:
```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'unifi_voucher');
define('DB_USER', 'username');
define('DB_PASS', 'password');
define('SESSION_LIFETIME', 3600);
date_default_timezone_set('Europe/Berlin');
```

### Microsoft 365 OAuth (optional)
1. Im [Azure Portal](https://portal.azure.com) eine App-Registrierung anlegen
2. Umleitungs-URI: `https://ihre-domain.de/m365_callback.php`
3. API-Berechtigungen: `User.Read`, `email`, `profile`, `openid`
4. Client ID, Client Secret und Tenant ID in **Administration → Einstellungen** eintragen

### Cron-Job (empfohlen)
Automatische Synchronisation alle 30 Minuten:
```bash
*/30 * * * * curl -s "https://ihre-domain.de/cron_sync.php?token=IHR_CRON_TOKEN"
```
Den Token finden Sie unter **Administration → Einstellungen → Cron**.

## Sicherheit

```apache
# .htaccess – sensible Dateien sperren
<FilesMatch "^(config\.php|database\.sql|.*\.md)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
```

```sql
-- Dedizierter Datenbank-Benutzer
CREATE USER 'unifi_voucher'@'localhost' IDENTIFIED BY 'sicheres_passwort';
GRANT SELECT, INSERT, UPDATE, DELETE ON unifi_voucher.* TO 'unifi_voucher'@'localhost';
```

## Problembehandlung

**Login funktioniert nicht:**
- Datenbankverbindung und PHP-Session-Konfiguration prüfen

**UniFi-Verbindung schlägt fehl:**
- Controller-URL im Browser testen
- Port 11443 für UniFi OS verwenden (nicht 8443)
- Benutzername, Passwort und Site ID prüfen
- cURL-Extension muss aktiviert sein

**UniFi OS: HTTP 404 oder 401:**
- Login-Endpunkt ist `/api/auth/login` (nicht `/api/login`)
- API-Pfade benötigen Präfix `/proxy/network/api/s/{site}/...`
- Älterer UniFi Network Controller (ohne UniFi OS) wird ab Version 2.1.0 nicht mehr unterstützt

**Voucher werden nicht erstellt:**
- UniFi Controller Logs prüfen
- API-Berechtigungen des Admin-Accounts prüfen
- Site ID korrekt? (zu finden in der UniFi Controller URL)

**Microsoft 365 Login funktioniert nicht:**
- Redirect URI in Azure AD prüfen
- Client ID, Secret und Tenant ID kontrollieren

## API-Dokumentation

**Login (UniFi OS):**
```
POST /api/auth/login
Body: {"username": "admin", "password": "password"}
Response-Header: X-CSRF-Token: <token>
```

**Voucher erstellen:**
```
POST /proxy/network/api/s/{site_id}/cmd/hotspot
X-CSRF-Token: <token>
Body: {"cmd": "create-voucher", "expire": 480, "n": 1, "note": "Name", "quota": 1}
```

**Vouchers abrufen:**
```
GET /proxy/network/api/s/{site_id}/stat/voucher
```

**Voucher löschen:**
```
POST /proxy/network/api/s/{site_id}/cmd/hotspot
X-CSRF-Token: <token>
Body: {"cmd": "delete-voucher", "_id": "<voucher_id>"}
```

## Roadmap

- [ ] Voucher-Templates (vordefinierte Laufzeiten)
- [ ] Bulk-Voucher-Erstellung
- [ ] Erweiterte Reporting-Funktionen
- [ ] Docker-Container
- [ ] Mehrsprachigkeit

---

**Version:** 2.1.0 | **Autor:** Friederich Loheide | **Letztes Update:** April 2026
