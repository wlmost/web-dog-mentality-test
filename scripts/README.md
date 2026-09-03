# scripts/

Nicht-interaktive CLI-Werkzeuge für Datenbank-Migrationen, unabhängig vom
Web-`wizard/`. Siehe `openspec/changes/archive/`-Historie für den
zugehörigen Change `add-db-migration-runner`.

## `migrate.php` — Migrationen anwenden

```bash
php scripts/migrate.php [--dry-run] [pfad/zu/config.local.php]
```

- Ohne Argumente: nutzt `api/config.local.php`.
- `--dry-run`: listet ausstehende Migrationen auf, ändert nichts an der DB.
- Exit 0 bei Erfolg (auch wenn nichts zu tun ist), Exit 1 bei Fehler.

## `migrate-selftest.php` — Selbsttest gegen eine Wegwerf-Datenbank

Kein PHPUnit; reines PHP-Skript, das `migrate.php` mehrfach per `proc_open`
gegen eine leere MySQL/MariaDB-Instanz durchspielt (Fresh-Install,
Idempotenz, Teilzustand, Fehlerabbruch mit kaputter Migration in einem
isolierten Test-Temp-Pfad). Räumt Testdatenbank und Temp-Dateien am Ende
selbst auf.

**Lokal ausführen** (Docker, MySQL 8, Einzeiler):

```bash
docker run --rm -d --name migrate-selftest-mysql \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -p 3307:3306 \
  mysql:8.0

# warten, bis MySQL bereit ist (z. B. mit einem kurzen Poll-Loop), dann:

MIGRATE_TEST_HOST=127.0.0.1 \
MIGRATE_TEST_PORT=3307 \
MIGRATE_TEST_USER=root \
MIGRATE_TEST_PASS= \
MIGRATE_TEST_NAME=migrate_selftest \
MIGRATE_TEST_PREFIX=dmt_ \
php scripts/migrate-selftest.php

docker rm -f migrate-selftest-mysql
```

Exit 0 = alle Szenarien bestanden. `migrate-selftest.php` wird von
`build.sh` bewusst **nicht** ins Deploy-Paket aufgenommen (Allowlist in
Abschnitt „3/5").
