# Notes — Task 4.1: `scripts/` ins Deploy-Paket aufnehmen

## Umgesetzt

- `build.sh`, Abschnitt „3/5 – API, Frontend, Wizard, Datenbank": nach dem
  bestehenden Block „Datenbank-Schemas und Migrations" (Kopie von
  `database/*.sql` und `database/migrations/*.sql`) einen neuen Block
  „Migration-Runner" eingefügt:

  ```bash
  mkdir -p "$DIST/scripts"
  cp scripts/MigrationRunner.php scripts/migrate.php "$DIST/scripts/"
  ok "scripts/ (MigrationRunner.php, migrate.php)"
  ```

- Explizite Allowlist wie in `design.md` (D7) und
  `specs/database-migrations/spec.md` gefordert: nur die beiden für den
  Produktionsbetrieb nötigen Dateien werden kopiert.
  `scripts/migrate-selftest.php` und `scripts/README.md` werden bewusst
  **nicht** aufgenommen (kein `cp -r scripts` o. ä., sondern zwei benannte
  Dateien).

## Platzierung im Skript (Design-Entscheidung)

Der neue Block wurde direkt im Anschluss an den Datenbank-Block platziert
(vor „uploads/ (mit .htaccess, ohne echte Uploads)"), da Migrationen und der
Runner, der sie ausführt, inhaltlich zusammengehören und beide Teil des
„Datenbank"-Themas des Abschnitts „3/5" sind. Das entspricht der Vorgabe aus
`design.md` D7 („Platzierung im `build.sh`-Abschnitt „3/5"") ohne die
bestehende Reihenfolge/Struktur des Skripts sonst zu verändern.

## Nicht angefasst

- `scripts/MigrationRunner.php`, `scripts/migrate.php`,
  `scripts/migrate-selftest.php` (Inhalt unverändert, nur kopiert).
- Alle anderen Abschnitte von `build.sh` (API-Allowlist, Frontend, Wizard,
  uploads/, logs/, Root-Dateien, vendor/, DEPLOY_CHECKLIST.txt).

## Verifikation

- `bash build.sh` lokal ausgeführt → Exit-Code `0` (per `echo "EXIT_CODE=$?"`
  direkt nach dem Aufruf bestätigt).
- Nach dem Lauf geprüft (alle drei Akzeptanzkriterien erfüllt):
  - `dist/dog-mentality-test/scripts/MigrationRunner.php` existiert.
  - `dist/dog-mentality-test/scripts/migrate.php` existiert.
  - `dist/dog-mentality-test/scripts/migrate-selftest.php` existiert **nicht**.
  - Zusätzlich (nicht Teil der Akzeptanzkriterien, aber Beleg für die
    Allowlist-Wirkung): `dist/dog-mentality-test/scripts/README.md` existiert
    ebenfalls nicht, `ls` des Verzeichnisses zeigt ausschließlich die beiden
    kopierten Dateien.
- `dist/` danach mit `rm -rf dist` entfernt (Verzeichnis ist laut `.gitignore`
  ausgeschlossen, keine Repo-Änderung durch den Build-Lauf selbst).

## Verbleibende Abhängigkeit / Hinweis für Folge-Arbeiten

`proposal.md` (Abschnitt „Merge-Reihenfolge") weist darauf hin, dass
`build.sh` auch vom Change `add-ci-workflow` angefasst wird — bei einem
späteren Merge-Konflikt ist der hier eingefügte `scripts/`-Block zu erhalten.
