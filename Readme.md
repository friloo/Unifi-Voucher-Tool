# UniFi Voucher Management System

Ein professionelles, webbasiertes System zur Verwaltung von WLAN-Vouchers für UniFi Controller mit Multi-Site-Unterstützung, Benutzerverwaltung und Microsoft 365 Integration.

## ✨ Features

### Kern-Funktionen
- 🎫 **Voucher-Erstellung**: Einfache Erstellung von zeitbegrenzten WLAN-Zugangscodes
- 🏢 **Multi-Site-Support**: Verwaltung mehrerer UniFi Sites/Standorte
- 👥 **Benutzerverwaltung**: Granulare Zugriffskontrolle auf Site-Ebene
- 🔐 **Authentifizierung**: Lokale Accounts und Microsoft 365 OAuth
- 📊 **Admin-Dashboard**: Übersichtliche Statistiken und Historie
- 🌐 **Öffentlicher Zugriff**: Optional ohne Login nutzbar
- 🎨 **Modernes Design**: Responsives, helles und professionelles UI

### Sicherheit
- CSRF-Schutz für alle Formulare
- Password-Hashing mit bcrypt
- Session-Management mit konfigurierbaren Timeouts
- SQL-Injection-Schutz durch Prepared Statements
- Rollenbasierte Zugriffskontrolle (Admin/User)

## 📋 Anforderungen

### Server-Anforderungen
- PHP 7.4 oder höher
- MySQL 5.7+ oder MariaDB 10.2+
- Apache/Nginx Webserver
- PHP-Extensions:
  - PDO
  - PDO_MySQL
  - cURL
  - mbstring
  - JSON

### UniFi Controller
- UniFi Network Controller 6.0 oder höher
- API-Zugriff aktiviert
- Lokaler Admin-Account oder dedizierter API-User

## 🚀 Installation

### Schritt 1: Dateien hochladen
```bash
# Repository klonen oder ZIP herunterladen
git clone https://github.com/ihr-username/unifi-voucher-system.git
cd unifi-voucher-system

# Dateien auf den Webserver hochladen
# Stellen Sie sicher, dass der Webserver-User Schreibrechte hat
```

### Schritt 2: Ordnerstruktur

```
/
├── config.php (wird vom Installer erstellt)
├── install.php
├── index.php
├── login.php
├── logout.php
├── database.sql
├── .htaccess (wird vom Installer erstellt)
├── includes/
│   ├── Database.php
│   ├── Auth.php
│   └── UniFiController.php
└── admin/
    ├── index.php
    ├── sites.php
    ├── users.php
    ├── vouchers.php
    └── settings.php
```

### Schritt 3: Installation durchführen

1. Öffnen Sie `http://ihre-domain.de/install.php` im Browser
2. Folgen Sie dem 5-Schritte-Installations-Assistenten:

#### Schritt 1: Datenbank-Konfiguration
- Datenbank-Host (meist `localhost`)
- Datenbankname (z.B. `unifi_voucher`)
- Datenbank-Benutzer
- Datenbank-Passwort

#### Schritt 2: Administrator-Account
- Name
- E-Mail-Adresse
- Passwort (min. 8 Zeichen)

#### Schritt 3: Allgemeine Einstellungen
- Anwendungs-Titel
- Logo-URL (optional)
- Anleitung für Benutzer
- Öffentlicher Zugriff aktivieren (optional)

#### Schritt 4: Microsoft 365 Integration (optional)
- Client ID
- Client Secret  
- Tenant ID

#### Schritt 5: Installation abschließen

Nach erfolgreicher Installation wird automatisch:
- Die Datenbank erstellt und initialisiert
- Die `config.php` Datei generiert
- Die `.htaccess` für URL-Rewriting erstellt
- Der Admin-Account angelegt

### Schritt 4: Installation sichern

Nach erfolgreicher Installation:
```bash
# install.php umbenennen oder löschen
mv install.php install.php.bak

# Oder komplett entfernen
rm install.php
```

## 🎯 Erste Schritte

### 1. Als Administrator anmelden
- Öffnen Sie `http://ihre-domain.de/login.php`
- Melden Sie sich mit Ihren Admin-Zugangsdaten an

### 2. Sites konfigurieren
1. Navigieren Sie zu **Administration** → **Sites verwalten**
2. Klicken Sie auf **Neue Site hinzufügen**
3. Geben Sie folgende Daten ein:
   - **Name**: Anzeigename (z.B. "Hauptgebäude")
   - **Site ID**: UniFi Site ID (z.B. "default")
   - **Controller URL**: URL Ihres UniFi Controllers (z.B. "https://unifi.example.com:8443")
   - **Benutzername**: UniFi Admin-Username
   - **Passwort**: UniFi Admin-Passwort
   - **Öffentlicher Zugriff**: Aktivieren für Login-freie Nutzung

4. Klicken Sie auf **Verbindung testen**, um die Einstellungen zu überprüfen
5. Speichern Sie die Site

### 3. Benutzer anlegen
1. Navigieren Sie zu **Administration** → **Benutzer verwalten**
2. Klicken Sie auf **Neuer Benutzer**
3. Geben Sie die Benutzerdaten ein:
   - Name
   - E-Mail
   - Passwort
   - Admin-Rechte (optional)
4. Wählen Sie die Sites aus, auf die der Benutzer Zugriff haben soll
5. Speichern Sie den Benutzer

### 4. Vouchers erstellen
1. Gehen Sie zur Startseite
2. Wählen Sie eine Site aus
3. Geben Sie einen Voucher-Namen ein
4. Legen Sie die Anzahl der Geräte fest (1-10)
5. Klicken Sie auf **Voucher erstellen**
6. Der Code wird sofort angezeigt und ist 8 Stunden gültig

## 🔧 Konfiguration

### config.php
Die Datei wird automatisch erstellt, kann aber manuell angepasst werden:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'unifi_voucher');
define('DB_USER', 'username');
define('DB_PASS', 'password');

define('SESSION_LIFETIME', 3600); // 1 Stunde

date_default_timezone_set('Europe/Berlin');
```

### Microsoft 365 OAuth einrichten

1. **Azure AD App registrieren**:
   - Gehen Sie zu https://portal.azure.com
   - Navigieren Sie zu "Azure Active Directory" → "App-Registrierungen"
   - Klicken Sie auf "Neue Registrierung"
   - Name: "UniFi Voucher System"
   - Unterstützte Kontotypen: "Nur Konten in diesem Organisationsverzeichnis"
   - Umleitungs-URI: `https://ihre-domain.de/login.php`

2. **API-Berechtigungen**:
   - Microsoft Graph → Delegierte Berechtigungen
   - `User.Read`
   - `email`
   - `profile`
   - `openid`

3. **Client Secret erstellen**:
   - Gehen Sie zu "Zertifikate & Geheimnisse"
   - Erstellen Sie ein neues Client-Geheimnis
   - Notieren Sie den Wert (nur einmal sichtbar!)

4. **In System eintragen**:
   - Administration → Einstellungen
   - Microsoft 365 Bereich ausfüllen
   - Client ID, Client Secret und Tenant ID eintragen

## 🔐 Sicherheitsempfehlungen

### Server-Konfiguration
```apache
# .htaccess zusätzliche Sicherheit
<Files "config.php">
    Order Allow,Deny
    Deny from all
</Files>

<FilesMatch "\.(sql|md)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
```

### Datenbank-Benutzer
Erstellen Sie einen dedizierten Datenbankbenutzer nur für diese Anwendung:

```sql
CREATE USER 'unifi_voucher'@'localhost' IDENTIFIED BY 'sicheres_passwort';
GRANT SELECT, INSERT, UPDATE, DELETE ON unifi_voucher.* TO 'unifi_voucher'@'localhost';
FLUSH PRIVILEGES;
```

### HTTPS erzwingen
```apache
# In .htaccess hinzufügen
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Regelmäßige Updates
- PHP und MySQL aktuell halten
- Sicherheitspatches zeitnah einspielen
- Passwörter regelmäßig ändern

## 📚 Verwendung

### Für Endbenutzer

**Voucher erstellen:**
1. Startseite öffnen (Login optional je nach Konfiguration)
2. Voucher-Name eingeben
3. Anzahl Geräte wählen
4. Standort auswählen
5. Code erstellen und notieren

**Code verwenden:**
1. Mit dem WLAN verbinden
2. Browser öffnet automatisch Anmeldeseite
3. Voucher-Code eingeben
4. Zugang für 8 Stunden

### Für Administratoren

**Sites verwalten:**
- Neue Standorte hinzufügen
- Verbindungen testen
- Sites deaktivieren
- Zugangsdaten aktualisieren

**Benutzer verwalten:**
- Neue Benutzer anlegen
- Berechtigungen zuweisen
- Sites-Zugriff konfigurieren
- Admin-Rechte vergeben

**Historie einsehen:**
- Alle erstellten Vouchers
- Filterfunktionen nach Site/Benutzer/Datum
- Export-Funktion (optional)

## 🐛 Problembehandlung

### Häufige Probleme

**Login funktioniert nicht:**
- Prüfen Sie die Datenbankverbindung
- Stellen Sie sicher, dass Sessions funktionieren
- Überprüfen Sie die PHP-Session-Konfiguration

**UniFi-Verbindung schlägt fehl:**
- Testen Sie die Controller-URL im Browser
- Prüfen Sie Benutzername und Passwort
- Stellen Sie sicher, dass cURL aktiviert ist
- Prüfen Sie SSL-Zertifikate (CURLOPT_SSL_VERIFYPEER)

**Voucher werden nicht erstellt:**
- Überprüfen Sie die UniFi Controller Logs
- Prüfen Sie API-Berechtigungen
- Stellen Sie sicher, dass die Site-ID korrekt ist

**Microsoft 365 Login funktioniert nicht:**
- Prüfen Sie die Redirect URI
- Überprüfen Sie Client ID und Secret
- Stellen Sie sicher, dass API-Berechtigungen erteilt wurden

### Debugging aktivieren

In `config.php` hinzufügen:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/pfad/zu/error.log');
```

## 🔄 Update/Migration

### Von der alten Version migrieren
Das System ist eine komplette Neuentwicklung. Migration erfordert:

1. **Daten-Export** aus dem alten System (falls vorhanden)
2. **Neue Installation** gemäß dieser Anleitung durchführen
3. **Sites manuell neu anlegen**
4. **Benutzer neu erstellen**

### Updates einspielen
```bash
# Backup erstellen
mysqldump -u username -p database_name > backup.sql
cp -r /var/www/html/voucher /backup/voucher-$(date +%Y%m%d)

# Neue Dateien hochladen (config.php nicht überschreiben!)
# Datenbank-Updates ausführen falls vorhanden
```

## 📝 API-Dokumentation

### UniFi Controller API Endpoints

**Login:**
```
POST /api/login
Body: {"username": "admin", "password": "password"}
```

**Voucher erstellen:**
```
POST /api/s/{site_id}/cmd/hotspot
Body: {
  "cmd": "create-voucher",
  "expire": 480,
  "n": 1,
  "note": "Voucher Name",
  "quota": 1
}
```

**Vouchers abrufen:**
```
GET /api/s/{site_id}/stat/voucher
```

## 🤝 Mitwirken

Contributions sind willkommen! Bitte:

1. Forken Sie das Repository
2. Erstellen Sie einen Feature-Branch (`git checkout -b feature/AmazingFeature`)
3. Committen Sie Ihre Änderungen (`git commit -m 'Add some AmazingFeature'`)
4. Pushen Sie den Branch (`git push origin feature/AmazingFeature`)
5. Öffnen Sie einen Pull Request

## 📄 Lizenz

Dieses Projekt steht unter der MIT-Lizenz. Siehe `LICENSE` Datei für Details.

## 👨‍💻 Autor

**Friederich Loheide**

## 🙏 Danksagungen

- UniFi Controller API Dokumentation
- Microsoft Graph API
- Bootstrap und FontAwesome Icons

## 📞 Support

Bei Fragen oder Problemen:
- Erstellen Sie ein Issue auf GitHub
- E-Mail an support@example.com

## 🗺️ Roadmap

Geplante Features:
- [ ] Voucher-Templates
- [ ] Bulk-Voucher-Erstellung
- [ ] QR-Code-Generierung
- [ ] SMS-Versand von Codes
- [ ] Erweiterte Reporting-Funktionen
- [ ] REST API für externe Integration
- [ ] Docker-Container
- [ ] Mehrsprachigkeit

---

**Version:** 2.0.0  
**Letztes Update:** Januar 2026