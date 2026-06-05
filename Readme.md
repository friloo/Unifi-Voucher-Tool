<div align="center">

# 🎫 UniFi Voucher Management System

**Webbasiertes WLAN-Voucher-Management für UniFi OS** – mit Multi-Site-Support, Benutzerverwaltung, Microsoft-365-Login und integriertem Auto-Updater.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB-4479A1?logo=mysql&logoColor=white)
![UniFi OS](https://img.shields.io/badge/UniFi%20OS-7.0%2B-0559C9?logo=ubiquiti&logoColor=white)
![License](https://img.shields.io/badge/Lizenz-MIT-green)
![Version](https://img.shields.io/badge/Version-2.1.0-blueviolet)

</div>

---

<div align="center">
  <img src="docs/screenshots/voucher-result.png" alt="Erstellter Voucher mit QR-Code" width="48%">
  <img src="docs/screenshots/admin-dashboard.png" alt="Admin Dashboard" width="48%">
</div>

---

## ✨ Features

- 🎟️ **Voucher-Erstellung** mit sofortiger QR-Code-Anzeige, Druckvorlage und E-Mail-Versand
- 📦 **Bulk-Erstellung** – bis zu 20 Vouchers auf einmal, inkl. Sammeldruck-Layout
- 🧩 **Voucher-Profile/Templates** – vordefinierte Laufzeiten & Gerätelimits per Schnellauswahl
- 🏢 **Multi-Site-Support** – beliebig viele UniFi-Standorte zentral verwalten
- 👥 **Benutzerverwaltung** mit granularer Site-Zugriffskontrolle
- 🔐 **Authentifizierung** via lokale Accounts **oder** Microsoft 365 OAuth
- 🔑 **Passwort-Reset** per E-Mail (token-basiert, zeitlich begrenzt)
- 🌍 **Öffentlicher Modus** – optional ohne Login nutzbar (mit CSRF-Schutz & Throttle)
- 🌗 **Dark Mode** – umschaltbar, Einstellung wird im Browser gespeichert
- 🌐 **Mehrsprachig** – Deutsch / Englisch per Umschalter (`lang/`)
- 📱 **Responsive Admin-Layout** mit Hamburger-Menü & Sidebar-Overlay
- 📊 **Admin-Dashboard** mit Live-Statistiken und Sync-Funktion
- 📝 **Audit-Log** – nachvollziehbare Protokollierung von Login & Änderungen (mit Filter)
- 📥 **CSV-Export** aller Vouchers pro Site
- 🔄 **Integrierter Auto-Updater** – Updates & DB-Migrationen per Klick aus dem Admin-Bereich
- 🛡️ **Security-by-default**: CSRF-Schutz, bcrypt-Passwörter, Prepared Statements,
  Login-Rate-Limiting, OAuth-State-Validierung, Verschlüsselung sensibler Daten

---

## 📸 Screenshots

### Anmeldung & Voucher-Erstellung

<div align="center">
  <img src="docs/screenshots/login.png" alt="Login mit Microsoft 365" width="32%">
  <img src="docs/screenshots/voucher-form.png" alt="Voucher erstellen" width="32%">
  <img src="docs/screenshots/voucher-result.png" alt="Voucher-Ergebnis" width="32%">
</div>

### Bulk-Erstellung & Dark Mode

<div align="center">
  <img src="docs/screenshots/bulk-vouchers.png" alt="Bulk-Voucher-Erstellung" width="48%">
  <img src="docs/screenshots/admin-dashboard-dark.png" alt="Dashboard im Dark Mode" width="48%">
</div>

### Administration & Updater

<div align="center">
  <img src="docs/screenshots/admin-dashboard.png" alt="Dashboard" width="48%">
  <img src="docs/screenshots/updater-available.png" alt="Auto-Updater" width="48%">
</div>

<div align="center">
  <img src="docs/screenshots/updater.png" alt="Updater – Ausgangszustand" width="48%">
  <img src="docs/screenshots/maintenance.png" alt="Wartungsmodus" width="48%">
</div>

---

## 📋 Anforderungen

| Komponente | Version |
|---|---|
| PHP | 7.4+ (mit PDO, PDO_MySQL, cURL, mbstring, JSON; empfohlen: OpenSSL/libsodium, Zip) |
| Datenbank | MySQL 5.7+ / MariaDB 10.2+ |
| Webserver | Apache / Nginx |
| UniFi | **Network Application 7.0+ mit UniFi OS** (z. B. UDM, UDR, UniFi OS Server) |

---

## 🚀 Installation

```bash
git clone https://github.com/friloo/unifi-voucher-tool.git
cd unifi-voucher-tool
```

1. Dateien auf den Webserver hochladen
2. `https://ihre-domain.de/install.php` öffnen
3. Den **5-Schritte-Assistenten** durchlaufen:
   1. Datenbank-Verbindungsdaten
   2. Administrator-Account
   3. Allgemeine Einstellungen (Titel, Logo, öffentlicher Zugriff)
   4. Microsoft 365 Integration (optional)
   5. Installation abschließen
4. `install.php` nach erfolgreicher Installation **löschen**

> 🔒 Der Installer erzeugt automatisch einen zufälligen `APP_KEY` (für die
> Verschlüsselung der UniFi-Passwörter) und eine `.htaccess`, die sensible
> Dateien sperrt. Ein erneuter Aufruf von `install.php?reinstall=1` ist nur
> für **angemeldete Administratoren** möglich.

### Sites konfigurieren

1. **Administration → Sites verwalten → Neue Site hinzufügen**
2. Felder ausfüllen:
   - **Name:** Anzeigename (z. B. „Hauptgebäude")
   - **Site ID:** UniFi Site ID (meist `default`)
   - **Controller URL:** `https://unifi.example.com:11443`
   - **Benutzername / Passwort:** UniFi Admin-Zugangsdaten
3. **Verbindung testen** klicken, dann speichern

### Voucher erstellen

1. Startseite öffnen (Login je nach Konfiguration optional)
2. Voucher-Name, Anzahl Geräte und Standort wählen
3. **Voucher erstellen** – Code und QR-Code werden sofort angezeigt
4. Code per E-Mail senden oder ausdrucken

---

## 🔄 Auto-Updater

Das System bringt einen vollständig integrierten Updater mit, der Quellcode und
Datenbank-Migrationen über einen zentralen Update-Proxy nachzieht – ganz ohne
SSH oder manuelles `git pull`.

```
[diese Instanz]  ←HTTPS→  [Update-Proxy]  ←pull→  [Git-Repo]
```

**Aufruf:** Administration → **System-Update** (`/admin/update.php`)

- 🔍 **„Auf Updates prüfen"** zeigt verfügbare Versionen inkl. Changelog
- ⬇️ **„Update installieren"** spielt das Update ein – mit Wartungsseite,
  geschützten Pfaden (`config.php`, Uploads, …), automatischen DB-Migrationen
  und OPcache-Reset
- 🔀 **Channel-Auswahl** zwischen `stable` und `development`
- 📊 **Migrations-Status** in einem eigenen Tab – inkl. Button **„Ausstehende
  Migrationen ausführen"** (legt z. B. neue Tabellen für bestehende
  Installationen an, ohne dass ein Code-Update nötig ist)

Während eines Updates wird die Anwendung kurz in den **Wartungsmodus** versetzt:

<div align="center">
  <img src="docs/screenshots/maintenance.png" alt="Wartungsmodus" width="60%">
</div>

> Der Updater ist vollständig isoliert im Ordner [`updater/`](updater/) gekapselt.
> Details zur Architektur und eine Rückbau-Anleitung stehen in
> [`updater/README.md`](updater/README.md).

---

## ⚙️ Konfiguration

### `config.php`

Wird automatisch durch den Installer erstellt:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'unifi_voucher');
define('DB_USER', 'username');
define('DB_PASS', 'password');
define('APP_KEY', '...');            // Schlüssel für Verschlüsselung-at-rest
define('SESSION_LIFETIME', 3600);    // Session-Timeout in Sekunden
date_default_timezone_set('Europe/Berlin');
```

### Microsoft 365 OAuth (optional)

1. Im [Azure Portal](https://portal.azure.com) eine App-Registrierung anlegen
2. Umleitungs-URI: `https://ihre-domain.de/m365_callback.php`
3. API-Berechtigungen: `openid`, `profile`, `email`, `User.Read`
4. Client ID, Client Secret und Tenant ID in **Administration → Einstellungen** eintragen

### Cron-Job (empfohlen)

Automatische Synchronisation alle 30 Minuten:

```bash
*/30 * * * * curl -s "https://ihre-domain.de/cron_sync.php?token=IHR_CRON_TOKEN"
```

Den Token finden Sie unter **Administration → Einstellungen → Cron**.

---

## 🛡️ Sicherheit

Das Tool ist auf einen sicheren Standardbetrieb ausgelegt:

| Schutz | Umsetzung |
|---|---|
| **Passwörter** | bcrypt (`password_hash`) für Accounts |
| **UniFi-Passwörter** | Verschlüsselt-at-rest (AES-256-GCM / libsodium) über `APP_KEY` |
| **SQL-Injection** | Durchgängig Prepared Statements (PDO) |
| **CSRF** | Token für alle schreibenden Aktionen – auch im öffentlichen Modus |
| **OAuth** | `state`-Validierung gegen Login-CSRF |
| **Brute-Force** | Login-Rate-Limiting + Throttle der öffentlichen Voucher-Erstellung |
| **Sessions** | HttpOnly, SameSite, strict mode + absolutes Timeout |
| **Fehler** | `display_errors` aus, `log_errors` an (kein Info-Leak) |

Empfohlene zusätzliche Härtung am Server:

```apache
# .htaccess – sensible Dateien sperren (wird vom Installer erzeugt)
<FilesMatch "^(config\.php|database\.sql|install\.php|test\.php|m365_debug\.php|.*\.md)$">
    Require all denied
</FilesMatch>
```

```sql
-- Dedizierter Datenbank-Benutzer mit minimalen Rechten
CREATE USER 'unifi_voucher'@'localhost' IDENTIFIED BY 'sicheres_passwort';
GRANT SELECT, INSERT, UPDATE, DELETE ON unifi_voucher.* TO 'unifi_voucher'@'localhost';
```

---

## 🩺 Problembehandlung

<details>
<summary><b>Login funktioniert nicht</b></summary>

- Datenbankverbindung und PHP-Session-Konfiguration prüfen
- Fehler werden ins PHP-Error-Log geschrieben (nicht mehr in den Browser)
</details>

<details>
<summary><b>UniFi-Verbindung schlägt fehl</b></summary>

- Controller-URL im Browser testen
- Port **11443** für UniFi OS verwenden (nicht 8443)
- Benutzername, Passwort und Site ID prüfen
- cURL-Extension muss aktiviert sein
</details>

<details>
<summary><b>UniFi OS: HTTP 404 oder 401</b></summary>

- Login-Endpunkt ist `/api/auth/login` (nicht `/api/login`)
- API-Pfade benötigen Präfix `/proxy/network/api/s/{site}/...`
- Älterer UniFi Network Controller (ohne UniFi OS) wird ab Version 2.1.0 nicht mehr unterstützt
</details>

<details>
<summary><b>Microsoft 365 Login funktioniert nicht</b></summary>

- Redirect URI in Azure AD prüfen
- Client ID, Secret und Tenant ID kontrollieren
- Diagnose unter `m365_debug.php` (nur als Admin erreichbar)
</details>

---

## 📡 API-Dokumentation (UniFi OS)

```http
# Login
POST /api/auth/login
Body: {"username": "admin", "password": "password"}
Response-Header: X-CSRF-Token: <token>

# Voucher erstellen
POST /proxy/network/api/s/{site_id}/cmd/hotspot
X-CSRF-Token: <token>
Body: {"cmd": "create-voucher", "expire": 480, "n": 1, "note": "Name", "quota": 1}

# Vouchers abrufen
GET /proxy/network/api/s/{site_id}/stat/voucher

# Voucher löschen
POST /proxy/network/api/s/{site_id}/cmd/hotspot
Body: {"cmd": "delete-voucher", "_id": "<voucher_id>"}
```

---

## 🗺️ Roadmap

- [x] Voucher-Templates (vordefinierte Laufzeiten)
- [x] Bulk-Voucher-Erstellung
- [x] Mehrsprachigkeit (DE/EN)
- [x] Dark Mode
- [x] Passwort-Reset
- [x] Audit-Log
- [x] Auto-Updater mit DB-Migrationen
- [ ] Erweiterte Reporting-Funktionen
- [ ] Docker-Container

---

<div align="center">

**Version 2.1.0** · Autor: **Friederich Loheide** · Lizenz: **MIT**

</div>
