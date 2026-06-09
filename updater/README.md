# Updater (OpenVoucherTool)

Auto-Update-System nach dem OpenNIT-Modell: zieht Quellcode + DB-Migrationen
aus einem zentralen Repository über einen HTTP-Update-Proxy nach.

```
[diese App]  ←HTTPS→  [Update-Proxy]  ←pull→  [Git-Repo]
```

- **Versions-Identität:** 40-stelliger Git-Commit-SHA in `updater/storage/.version`
- **Channels:** `stable` → `https://update.loheide.eu/openvouchertool`,
  `development` → `https://update.loheide.eu/openvouchertool-development`
- **User-Agent:** `OpenVoucherTool-Updater/1.0`
- **DB-Treiber:** MySQL/MariaDB (Migrations-Tracking-Tabelle `_updater_migrations`)

## Aufruf

Admin-Oberfläche: **`/admin/update.php`** (nur für angemeldete Admins).

| Endpoint | Methode | Zweck |
|---|---|---|
| `admin/update.php` | GET | Admin-UI |
| `admin/update.php?action=check` | GET | Update-Prüfung (JSON) |
| `admin/update.php?action=progress` | GET | Fortschritt (JSON) |
| `admin/update.php?action=migrations` | GET | Migrations-Status (JSON) |
| `admin/update.php` `action=install` | POST | Update installieren (+`csrf_token`) |
| `admin/update.php` `action=set_channel` | POST | Channel wählen (+`csrf_token`) |

> Optional: In der Admin-Navigation (`admin/index.php`) einen Link zu
> `update.php` ergänzen, damit die Seite auffindbar ist. Das ist bewusst
> **nicht** automatisch geschehen (Isolations-Prinzip – siehe unten).

## Isolation

Der Updater liegt vollständig in `updater/` (eigener Namespace `Updater\`) plus
zwei dünne, klar markierte Anknüpfungen:

1. **Front-Controller-Hook** in `index.php` (Maintenance-Check, markiert mit
   `// Updater maintenance hook`).
2. **Entry-Shim** `admin/update.php` (lädt nur Basis + Bootstrap, delegiert an
   den Controller).

Eigene Settings (`updater/storage/updater-settings.json`), eigener Autoloader
(`updater/bootstrap.php`), eigener AuditLogger (schreibt in die vorhandene
`audit_log`-Tabelle), eigenes Migrations-System (`updater/migrations/` +
Tabelle `_updater_migrations`). Es werden **keine** Projekt-Klassen erweitert –
`\Database` und `\Auth` werden nur per Injection genutzt.

### Geschützte Pfade (werden bei Updates nie überschrieben)

`config.php`, `.htaccess`, `.env*`, `.git/`, `.gitignore`, `public/uploads/`,
`vendor/`, `composer.lock`, `updater/storage/`.

## Rückbau (restlos entfernen)

1. In `index.php` den Block **„Updater maintenance hook"** (die Zeilen 2–8,
   beginnend mit `$maintenanceFile = …` bis zum schließenden `}`) entfernen.
2. `rm -r updater/`
3. `rm admin/update.php`
4. *(optional)* In der Datenbank: `DROP TABLE _updater_migrations;`
5. *(optional, falls vorhanden)* Laufzeitdateien sind bereits in `updater/`
   und damit mit Schritt 2 weg. Nichts liegt außerhalb.

Danach ist keine Spur des Updaters mehr im Bestandscode – der Beweis für die
Isolation.

## Smoke-Test

```bash
# 1) Syntax aller Updater-Dateien
php -l updater/UpdateManager.php
php -l updater/MigrationRunner.php
php -l updater/UpdateController.php
php -l updater/UpdaterFactory.php
php -l updater/AuditLogger.php

# 2) Admin-Seite aufrufen (eingeloggt als Admin):
#    https://<host>/admin/update.php
#    -> "Auf Updates prüfen" klicken. Erwartung: Proxy-Antwort oder klare
#       Fehlermeldung (wenn Proxy/Channel nicht erreichbar).

# 3) Maintenance-Mode manuell testen:
touch updater/storage/.maintenance     # index.php zeigt jetzt 503-Wartungsseite
rm    updater/storage/.maintenance     # wieder normal
```

> Hinweis: Ein vollständiger Installations-Durchlauf (`action=install`) setzt
> einen erreichbaren Update-Proxy unter den oben genannten URLs voraus.

## Sicherheits-Hinweise & Limitierungen

- **Keine Paket-Signatur:** Die Integrität der Updates hängt derzeit allein an
  TLS zur Update-Proxy-URL. Der Updater validiert Zip-Einträge und Dateipfade
  gegen Pfad-Traversal und legt vor dem Anwenden ein Backup an
  (`updater/storage/.backup-last`), das bei Fehlern automatisch
  zurückgespielt wird. Eine kryptografische Signaturprüfung der Pakete
  (z.B. signierte SHA-256-Manifeste) erfordert serverseitige Unterstützung
  des Update-Proxys und steht noch aus.
- **Rollback:** Schlägt das Anwenden des Updates oder eine Migration fehl,
  werden die überschriebenen Dateien aus dem Backup wiederhergestellt.
  Datenbank-Migrationen werden dabei nicht automatisch rückgängig gemacht
  (jede Migration läuft aber in einer eigenen Transaktion).
