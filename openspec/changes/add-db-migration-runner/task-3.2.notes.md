# Task 3.2 — Selbsttest gegen lokale MySQL ausführen und dokumentieren

**Ergebnis:** erledigt, grün.

## Dokumentation

`scripts/README.md` (neu) beschreibt `migrate.php` und `migrate-selftest.php`,
inkl. eines lauffähigen Docker-Einzeilers für MySQL 8 und der benötigten
`MIGRATE_TEST_*`-Umgebungsvariablen.

## Reproduktion (2026-09-03)

Der in `scripts/README.md` dokumentierte Befehl wurde wörtlich ausgeführt:

```bash
docker run --rm -d --name migrate-selftest-mysql \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3307:3306 mysql:8.0

MIGRATE_TEST_HOST=127.0.0.1 MIGRATE_TEST_PORT=3307 MIGRATE_TEST_USER=root \
MIGRATE_TEST_PASS= MIGRATE_TEST_NAME=migrate_selftest MIGRATE_TEST_PREFIX=dmt_ \
php scripts/migrate-selftest.php

docker rm -f migrate-selftest-mysql
```

Ergebnis: **18 bestanden, 0 fehlgeschlagen, Exit 0.** Alle vier D8-Szenarien
(Fresh-Install, Idempotenz, Teilzustand nach DELETE, Fehlerabbruch mit
isolierter kaputter Migration) grün. Container danach vollständig entfernt
(`docker ps -a` bestätigt keinen Rest).

## Akzeptanzkriterium

- [x] Dokumentierter Befehl reproduziert einen grünen Selbsttest-Lauf
