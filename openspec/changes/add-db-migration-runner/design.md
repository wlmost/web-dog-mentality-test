## Context

**Bestehende Migrations-Infrastruktur** (verifiziert):

- `database/migrations/` enthält `001_add_user_id_to_dogs.sql` …
  `005_add_password_reset_tokens.sql`. Dateiname-Schema `NNN_beschreibung.sql`.
- Jede Datei nutzt den Platzhalter `{{PREFIX}}` — auch innerhalb von
  String-Literalen, z. B. `CONCAT('{{PREFIX}}', 'dogs')`
  (`001_add_user_id_to_dogs.sql`). Ersetzung erfolgt per einfachem
  `str_replace('{{PREFIX}}', $prefix, $sql)`
  (`wizard/WizardHelper.php:112`).
- Migrationen 001–003 (seit Commit `1251838`) enthalten je einen
  `information_schema`-Guard mit
  `SET @col_exists = (...); SET @sql = IF(...); PREPARE stmt FROM @sql;
  EXECUTE stmt; DEALLOCATE PREPARE stmt;` — mehrere Statements, die
  Session-Variablen über Anweisungsgrenzen hinweg teilen.
- 004/005 nutzen `CREATE TABLE IF NOT EXISTS` bzw. `ALTER TABLE … MODIFY`.
- Tracking-Tabelle (`database/schema.sql:205-213`,
  `database/schema-netbeat.sql:178`):

  ```sql
  CREATE TABLE IF NOT EXISTS {{PREFIX}}schema_migrations (
    version     VARCHAR(20)  NOT NULL,
    applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (version)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

- `WizardHelper::executeSqlFile()` (`wizard/WizardHelper.php:105-133`):
  ersetzt `{{PREFIX}}`, ruft `splitSqlStatements()` (verwirft `--`/`#`-Zeilen,
  splittet an Zeilen, die auf `;` enden), führt jedes Statement einzeln über
  `mysqli::query()` auf **einer** Verbindung aus. Session-Variablen bleiben
  dadurch erhalten. Genau dieses Vorgehen macht die 001–003-Guards
  lauffähig — es ist die zu spiegelnde Referenz-Semantik.
- `WizardHelper::getMigrationVersion()`: `explode('_', basename($f,'.sql'))[0]`.
- `WizardHelper::getMigrationDescription()`: erste Zeile
  `-- Description: <text>`.
- `WizardHelper::recordMigration()`: Prepared
  `INSERT INTO <prefix>schema_migrations (version, description) VALUES (?, ?)`.

**Konfiguration** (verifiziert):

- `api/config.local.php` wird vom Wizard generiert
  (`WizardHelper::generateConfigContent()`, `wizard/WizardHelper.php:182-209`)
  und definiert **immer** `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`,
  `DB_NAME`, `DB_PREFIX` (sowie `APP_URL`, `MAIL_FROM`, `CORS_ORIGIN`,
  `OPENAI_*`). Es ist eine reine Folge von `define()`-Aufrufen ohne
  Seiteneffekte.
- `api/config.php` liest zusätzlich Defaults (u. a. `DB_PREFIX`-Default `''`
  in `api/config.php:60`) und stellt eine Verbindung eager her (`new mysqli`
  in `api/config.php:93`); es sendet außerdem HTTP-Header (im CLI No-Op) —
  für den Runner wird es **nicht** benötigt.
- `wizard/` wird laut Deploy-Checkliste nach der Installation vom Server
  entfernt → der Runner **darf `wizard/WizardHelper.php` zur Laufzeit nicht
  voraussetzen**.

**Shared-Hosting-Rahmenbedingungen:** Der Runner setzt **kein**
SUPER-Privileg und keine MySQL-Trigger/Stored-Procedures voraus. Die
vorhandenen `database/migrations/*.sql` sind (vom Skeptiker bestätigt)
trigger- und procedure-frei; der Runner führt selbst nichts dergleichen ein.
(`NETBEAT_HOSTING.md` im Repo dokumentiert solche Einschränkungen für die
frühere netbeat-Umgebung; die Produktion läuft inzwischen bei alfahosting,
die Aussage „kein SUPER-Privileg voraussetzen" bleibt aber die konservative
Grundlage.)

Referenz `../dog-school-app/.github/workflows/deploy.yml` löst Migrationen mit
`php artisan migrate --force` über SSH aus — d. h. ein **anwendungseigener
CLI-Runner**, nicht ein roher `mysql`-Import. Dieses Muster wird übernommen.

## Goals / Non-Goals

**Goals:**

- Ein eigenständiger, idempotenter CLI-Runner, per SSH aufrufbar
  (`php scripts/migrate.php`), ohne `wizard/`-Abhängigkeit zur Laufzeit.
- Identische Migrations-Semantik wie der Wizard (keine zweite
  „Wahrheit" über das SQL-Ausführungsverhalten).
- Unabhängig vom Deploy testbar (Selbsttest-Skript + `--dry-run`).
- Keine zusätzlichen Secrets; Konfiguration ausschließlich aus
  `api/config.local.php`.

**Non-Goals:**

- Down-/Rollback-Migrationen (die vorhandenen Dateien haben kein `down`).
- Migrations-*Erzeugung*/Scaffolding.
- Einbindung in den CI-Workflow (Open Question).
- Parallel-/Locking-Schutz gegen gleichzeitige Runner-Läufe (der
  Deploy-Workflow serialisiert bereits via `concurrency`).
- Änderungen an `wizard/` oder Zusammenführung der Migrations-Logik
  (Folge-Aufgabe).
- PHPUnit.

## Decisions

### D1: PHP-CLI-Runner, der `WizardHelper`-Semantik nachbildet (nicht wiederverwendet)

Neue Klasse `scripts/MigrationRunner.php`, reines PHP + `mysqli`, keine
`require` auf `wizard/`. Grund: `wizard/` ist auf dem Produktionsserver nach
der Installation nicht mehr vorhanden. Die ~60 Zeilen SQL-Split-/
Ausführungslogik werden 1:1 aus `WizardHelper::executeSqlFile()` /
`splitSqlStatements()` übernommen, damit das Laufzeitverhalten (inkl.
Session-Variablen für 001–003) identisch ist.

_Alternative A (verworfen):_ `scripts/migrate.php` `require`-t
`wizard/WizardHelper.php`. Bricht, sobald `wizard/` serverseitig gelöscht ist.

_Alternative B (verworfen, aber empfohlen als Folge-Change):_ Migrations-Logik
in eine gemeinsame Klasse `database/MigrationRunner.php` extrahieren, die
sowohl `wizard/update.php` als auch `scripts/migrate.php` nutzen. Sauberste
DRY-Lösung, aber erweitert den Scope auf den Wizard und dessen erneute
Verifikation. Bewusst zurückgestellt (YAGNI/Risiko), als Open Question notiert.

### D2: SQL-Ausführung über `mysqli` in PHP, **nicht** über die `mysql`-CLI

Der User hat den `mysql`-CLI-Weg als „bevorzugt" markiert (keine extra
Secrets, Server kennt Credentials). Beide Kernanliegen — **keine zusätzlichen
Secrets** und **Server kennt die Credentials** — werden vom PHP-Runner ebenso
erfüllt (er liest `api/config.local.php`). Gegen die `mysql`-CLI spricht:

- Unklar, ob das `mysql`-Binary auf dem Shared-Hosting-Tarif via SSH im
  `PATH` liegt (bestätigt ist nur php-CLI + `rsync`); ein fehlendes Binary
  würde jeden Deploy hart brechen. `php` + `ext-mysqli` ist dagegen
  garantiert vorhanden (die gesamte `api/` läuft darauf).
- Passwortübergabe an `mysql` ist fummelig (`MYSQL_PWD` env oder
  `--defaults-extra-file`), sonst Warnung/Leak in der Prozessliste.
- Die Buchführung in `schema_migrations` (pro Datei ein `INSERT` nach Erfolg,
  Abbruch ohne Eintrag bei Fehler) braucht ohnehin PHP-Logik drumherum.
- `mysqli::query()` je Statement bildet exakt das bereits erprobte
  Wizard-Verhalten ab; ein roher `mysql < file`-Import hätte anderes
  Fehler-/Statement-Verhalten (z. B. bei mehreren Statements, Delimitern).

Voraussetzung: PHP-CLI mit `ext-mysqli` auf dem Server (bei diesem Projekt
gegeben — die gesamte `api/` läuft auf `mysqli`). Vom Skeptiker/User zu
bestätigen ist lediglich, dass **PHP per SSH als `php` aufrufbar** ist.

### D3: Numerische Reihenfolge über den Zahlpräfix

Version = `preg_replace('/\D.*/', '', basename($file))` bzw. führender
`\d+`-Teil, dann `usort` mit `<=>` auf `(int)`-Wert. Robust auch für künftige
`010_`, `100_`. (`WizardHelper` nutzt `sort()` lexikografisch — bei
gleichbreiten Nummern äquivalent, hier bewusst numerisch korrekt.)

### D4: Tracking-Tabelle idempotent anlegen

Der Runner führt vor dem Abgleich das `CREATE TABLE IF NOT EXISTS
<prefix>schema_migrations (...)` mit der DDL aus `database/schema.sql` aus.
Damit funktioniert der Runner auch, wenn die Tabelle fehlt (Alt-Installation).
`applied_at` hat `DEFAULT CURRENT_TIMESTAMP` → der `INSERT` setzt nur
`version` und `description`.

### D5: Idempotenz & Fehlerverhalten

- Ausstehend = Version **nicht** in `SELECT version FROM
  <prefix>schema_migrations`.
- Reihenfolge: aufsteigend nach Version, sequenziell.
- Bei Statement-Fehler: sofort stoppen, **kein** `INSERT` für die
  fehlgeschlagene Datei, Log-Zeile mit Dateiname + `mysqli`-Fehlertext +
  gekürztem Statement, `exit(1)`.
- „Nichts zu tun" (alle Versionen bereits eingetragen) → Log-Hinweis,
  `exit(0)`.
- Kein automatisches Transaktions-Rollback: MySQL-DDL ist nicht
  transaktional (implizites COMMIT). Migrationen 001–005 sind so geschrieben,
  dass ein erneuter Lauf unschädlich ist (Guards / `IF NOT EXISTS`), sodass
  nach Fehlerbehebung ein Wiederholungslauf sicher ist. Das wird in der Spec
  als Anforderung an **künftige** Migrationsdateien festgehalten.

### D6: CLI-Schnittstelle

```
php scripts/migrate.php [--dry-run] [pfad/zu/config.local.php]
```

- ohne Argumente: nutzt `__DIR__ . '/../api/config.local.php'`.
- `--dry-run`: gibt die Liste ausstehender Versionen aus, führt nichts aus,
  `exit(0)`.
- fehlende/unvollständige Konfiguration (`DB_*` nicht definiert) →
  Fehlermeldung, `exit(1)`.
- Verbindungsfehler → Fehlermeldung, `exit(1)`.
- Ausgabe: pro Migration `-> NNN <description> … OK`, am Ende
  `MIGRATE OK: X angewendet, Y übersprungen` bzw.
  `MIGRATE FAIL bei NNN: <fehler>`.

### D7: `scripts/` ins Deploy-Paket

`build.sh` kopiert bisher aus `database/` nur eine feste Schema-Allowlist und
`migrations/*.sql`. Ergänzung: `mkdir -p "$DIST/scripts"` und
`cp scripts/MigrationRunner.php scripts/migrate.php "$DIST/scripts/"` (explizite
Allowlist, damit `migrate-selftest.php` **nicht** mitgeht). Platzierung im
`build.sh`-Abschnitt „3/5".

### D8: Selbsttest ohne PHPUnit

`scripts/migrate-selftest.php`: nutzt DB-Zugang aus Umgebungsvariablen
(`MIGRATE_TEST_HOST`, `_PORT`, `_USER`, `_PASS`, `_NAME`, `_PREFIX`),
schreibt eine temporäre `config.local.php` in ein `tempnam`-Verzeichnis und
ruft `scripts/migrate.php` per `proc_open`/`exec` mehrfach auf:

1. Frische DB → Exit 0, `schema_migrations` existiert, enthält 001–005.
2. Zweiter Lauf → Exit 0, Ausgabe „0 angewendet, 5 übersprungen".
3. Nach `DELETE FROM schema_migrations WHERE version='005'` → dritter Lauf
   wendet nur 005 an, Exit 0.
4. Mit einer absichtlich kaputten Zusatzdatei (nur im Test-Temp-Migrationspfad)
   → Exit 1, kaputte Version **nicht** in `schema_migrations`.

Läuft lokal/manuell gegen eine Docker-MySQL oder eine Wegwerf-Datenbank.
Ist damit **unabhängig vom Deploy** testbar (User-Anforderung).

## Risks / Trade-offs

- **[PHP-CLI per SSH nicht als `php` erreichbar]** (manche Shared Hoster
  brauchen `php81` o. ä.) → Mitigation: `add-production-deploy-workflow`
  macht den PHP-Binary-Namen konfigurierbar (`vars.DEPLOY_PHP_BIN`, Default
  `php`); hier nur dokumentiert.
- **[`ext-mysqli` im CLI-SAPI nicht geladen, obwohl im Web-SAPI vorhanden]**
  → Mitigation: `migrate.php` prüft `extension_loaded('mysqli')` und gibt
  eine klare Fehlermeldung mit `exit(1)` aus; im Deploy-Change als
  Vorab-Check dokumentiert.
- **[Logik-Duplikat zu `WizardHelper` driftet auseinander]** → Mitigation:
  Code-Kommentar in beiden Dateien mit gegenseitigem Verweis; Folge-Aufgabe
  „gemeinsame `MigrationRunner`-Klasse" in Open Questions. Die
  Migrationsdateien selbst bleiben die einzige inhaltliche Wahrheit.
- **[DDL nicht transaktional → Teilzustand bei Fehler mitten in einer
  Mehr-Statement-Migration]** → Mitigation: bestehende Migrationen sind
  wiederholungssicher (Guards / `IF NOT EXISTS`); Spec fordert das für
  künftige Dateien; Runner bricht sofort ab und dokumentiert die Fehlstelle.
- **[`splitSqlStatements` kommt mit künftigen `DELIMITER`-Blöcken oder
  `;` in String-Literalen nicht klar]** → akzeptiert: gilt schon heute für
  den Wizard. Spec verbietet `DELIMITER`/Trigger/Procedures in Migrationen
  explizit (Shared Hosting ohne SUPER-Privileg).
- **[Selbsttest braucht eine echte MySQL-Instanz]** → akzeptiert: manuelle
  Ausführung bzw. lokale Docker-MySQL; keine CI-Kopplung in diesem Change.

## Migration Plan

1. `scripts/MigrationRunner.php` + `scripts/migrate.php` implementieren.
2. `scripts/migrate-selftest.php` implementieren, lokal gegen Docker-MySQL
   grün fahren.
3. `build.sh` um `scripts/`-Kopie ergänzen; `bash build.sh` prüfen
   (`dist/dog-mentality-test/scripts/migrate.php` vorhanden,
   `migrate-selftest.php` **nicht**).
4. Merge nach A, vor C.

Rollback: `scripts/`-Dateien und die `build.sh`-Ergänzung entfernen. Der
Wizard-Pfad (`wizard/update.php`) bleibt unberührt und weiterhin nutzbar.

## Open Questions

- **Folge-Change:** Migrations-Logik in eine gemeinsame Klasse extrahieren,
  die `wizard/update.php` und `scripts/migrate.php` teilen (DRY), inklusive
  erneuter Wizard-Verifikation.
- Soll der Runner zusätzlich in den CI-Workflow (`add-ci-workflow`) als Job
  gegen einen MySQL-Service-Container laufen, um Idempotenz bei jedem PR zu
  beweisen? (Empfohlen, aber Scope-Erweiterung.)
- ~~PHP-CLI per SSH aufrufbar + `ext-mysqli`?~~ **Erledigt:** vom User am
  2026-08-30 für die Produktion (alfahosting) bestätigt. Der konfigurierbare
  Binary-Name (`vars.DEPLOY_PHP_BIN`, Default `php`) wird trotzdem in
  `add-production-deploy-workflow` vorgesehen.
