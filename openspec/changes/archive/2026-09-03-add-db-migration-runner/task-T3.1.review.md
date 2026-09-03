# Review: T3.1 — `scripts/migrate-selftest.php`

**Gesamtempfehlung:** nacharbeit-nötig

## Hinweis zu Prüfdimension 0

`TESTING.md` existiert im Projekt-Root nicht (geprüft: `find . -maxdepth 3
-iname TESTING.md` → kein Treffer). `scripts/migrate-selftest.php` ist
funktional ein Integrationstest, liegt aber bewusst außerhalb von `tests/`
(siehe `task-T3.1.notes.md`, Abschnitt „Eigene, kompakte Test-Harness").
Identischer Befund wie bereits in `task-T1.1.review.md`/`task-T2.1.review.md`
vermerkt — ohne praktische Konsequenz, da keine projektweiten
Test-Konventionen dokumentiert sind, gegen die zu prüfen wäre.

## Bewertung der Design-Lücken-Lösung (Kernfrage des Auftrags)

**Die Grundentscheidung (optionaler, additiver `$migrationsDir`-Parameter
in `MigrationRunner`) ist sauber und nachweislich abwärtskompatibel:**

- `scripts/MigrationRunner.php:40` (`getAvailableMigrations(?string
  $migrationsDir = null)`) und `scripts/MigrationRunner.php:293`
  (`runPending(mysqli $conn, string $prefix, ?string $migrationsDir =
  null)`): beide Erweiterungen sind neue, optionale Parameter am Ende mit
  Default `null`, der bei Nichtangabe exakt den alten Pfad
  `__DIR__ . '/../database/migrations/'` verwendet (`scripts/MigrationRunner.php:42-44`).
  Kein bestehender Aufrufer muss angepasst werden — durch `php -l` sowie den
  weiterhin grünen `tests/migration-runner-test.php` (50/50, laut
  `task-T3.1.notes.md`) auch empirisch verifiziert, nicht nur behauptet.
- Da `MigrationRunner` bereits vollständig statusfrei/statisch ist (keine
  Instanz, kein Konstruktor), fügt sich ein optionaler Parameter besser in
  den bestehenden Stil ein als z. B. Konstruktor-Injection oder eine neue
  Konfigurationsklasse — Option A ist hier tatsächlich die pragmatischere
  Wahl (YAGNI/KISS trifft zu).

**Der zweite Teil der Lösung — die stillschweigende Env-Variable
`MIGRATE_MIGRATIONS_DIR` in `scripts/migrate.php` — ist funktional
notwendig (Szenario 4 muss den echten CLI-Einstiegspunkt inkl.
Exit-Code-Vertrag testen, nicht nur die Klasse direkt), aber in der
gewählten Form ein Robustheits-Risiko für den Produktionspfad, siehe
Sollte-Punkt unten.**

## Muss (blockiert Abnahme)

_Keine._

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[Robustheit/Sicherheit]** `scripts/migrate.php:76-83`: Der
  Env-Var-Override wird in **jedem** Aufruf von `migrate.php` bedingungslos
  gelesen (`getenv('MIGRATE_MIGRATIONS_DIR')`) — auch im Produktionspfad,
  für den `design.md` D6 ausschließlich `--dry-run` und den
  Config-Pfad-Positionsparameter als Eingabekanäle vorsieht. Konkretes
  Szenario: Ist auf dem Deploy-Host (z. B. im Shell-Profil des
  SSH-Deploy-Users, versehentlich aus einer lokalen Testsitzung
  übernommen, oder durch eine unabhängige CI-Pipeline-Stufe gesetzt) die
  Variable `MIGRATE_MIGRATIONS_DIR` aktiv, liest ein produktiver
  `php scripts/migrate.php`-Lauf während des Deploys **unbemerkt** SQL-Dateien
  aus einem anderen Verzeichnis als `database/migrations/` und trägt sie in
  die echte `schema_migrations`-Tabelle ein — ohne dass Log-Ausgabe oder
  Exit-Code einen Hinweis darauf geben, dass ein Nicht-Default-Verzeichnis
  verwendet wurde (`MIGRATE OK: …` sieht identisch aus). Das Risiko ist
  gering (ein Angreifer mit Env-Var-Kontrolle auf dem Deploy-Host hätte
  ohnehin einfachere Hebel), aber die Beobachtbarkeit fehlt komplett.
  Vorschlag: mindestens eine Log-Zeile ausgeben, wenn der Override aktiv
  ist (z. B. `fwrite(STDERR, "HINWEIS: MIGRATE_MIGRATIONS_DIR aktiv: $migrationsDirOverride\n");`
  direkt nach Zeile 83), damit ein versehentlich aktiver Override in jedem
  Deploy-Log sichtbar wäre. Alternativ: den Override statt über eine global
  wirksame Env-Variable über ein explizites, im Kopfkommentar als
  „nur für Tests" markiertes CLI-Flag (`--migrations-dir=<pfad>`) reichen,
  das im selben `foreach`-Argument-Parser (`scripts/migrate.php:59-70`)
  behandelt wird — CLI-Flags sind in Prozesslisten/Logs sichtbarer als
  vererbte Umgebungsvariablen.

- **[Isolation/Aufräumen]** `scripts/migrate-selftest.php:145-159`: Das
  Aufräumen (Temp-Verzeichnisse + `DROP DATABASE`) hängt ausschließlich an
  `register_shutdown_function`. Das deckt reguläres Skriptende, `exit()` und
  uncaught Exceptions/Fatal Errors ab, **nicht** aber einen externen
  Abbruch per `SIGINT`/`SIGTERM` (z. B. Ctrl-C, während der Selbsttest an
  einer hängenden DB-Verbindung wartet) — PHP CLI ruft
  Shutdown-Functions bei Default-Signalbehandlung in diesem Fall nicht auf.
  Damit bleiben bei einem manuellen Abbruch die Testdatenbank
  (`MIGRATE_TEST_NAME`, Default `migrate_selftest`) und die
  `sys_get_temp_dir()`-Verzeichnisse `migrate-selftest-*` liegen — das
  widerspricht der in `task-T3.1.notes.md` („Aufräumen … in jedem Fall")
  formulierten Erwartung für genau diesen Fall. Vorschlag: entweder im
  Kopfkommentar (`scripts/migrate-selftest.php:53-58`) explizit
  dokumentieren, dass ein harter Abbruch (Ctrl-C) manuelles Aufräumen
  erfordert (mit den entsprechenden `DROP DATABASE`/`rm -rf`-Befehlen), oder
  — sofern `pcntl` verfügbar ist — `pcntl_signal(SIGINT, …)` /
  `pcntl_signal(SIGTERM, …)` registrieren, die dieselbe Cleanup-Routine wie
  `register_shutdown_function` aufrufen.

- **[Sicherheit]** `scripts/migrate-selftest.php:249-264`: Die temporäre
  `config.local.php` enthält `DB_PASS` im Klartext (`var_export($pass,
  true)`, Zeile 260) und liegt in einem mit `mkdir($tmpConfigDir, 0777,
  true)` (Zeile 250) angelegten Verzeichnis. `0777` ist zwar identisch zum
  bereits akzeptierten Muster in `tests/migration-runner-test.php:142-143`,
  dort liegen aber keine Zugangsdaten in den betroffenen Verzeichnissen —
  hier schon. Auf einem geteilten Mehrbenutzer-System (z. B. gemeinsam
  genutzter CI-Runner) wäre die effektive Berechtigung nur durch das
  Prozess-`umask` begrenzt, nicht durch eine explizite Einschränkung im
  Code. Vorschlag: `mkdir($tmpConfigDir, 0700, true)` (Zeile 250) statt
  `0777`, um das DB-Passwort nicht von der lokalen `umask`-Konfiguration
  abhängig zu machen. Selbes gilt optional für
  `scripts/migrate-selftest.php:311` (`$isolatedMigrationsDir`), dort aber
  unkritischer, da nur SQL-Dateiinhalte ohne Zugangsdaten betroffen sind.

## Könnte (optional, Verbesserung)

- **[Korrektheit, geringes Risiko]** `scripts/migrate-selftest.php:217-218`:
  `real_escape_string()` wird auf `$dbName` angewendet und das Ergebnis in
  Backticks (`` `...` ``) für `DROP DATABASE`/`CREATE DATABASE` eingesetzt.
  `real_escape_string()` escaped für String-Literal-Kontexte (Anführungszeichen),
  nicht für Bezeichner-Kontexte (Backticks) — ein Backtick in `$dbName`
  würde nicht korrekt escaped. Praktisch irrelevant, da `MIGRATE_TEST_NAME`
  ausschließlich vom Entwickler selbst gesetzt wird, der den Selbsttest
  aufruft (kein externer Eingabekanal), aber technisch nicht die korrekte
  Escaping-Methode für diesen Kontext.
- **[Lesbarkeit]** `scripts/migrate-selftest.php:79-118`: Test-Harness
  arbeitet mit globalem, mutierbarem Zustand (`$GLOBALS['__PASS']` etc.)
  statt z. B. einer kleinen Zählerklasse. Bewusst so gewählt, um konsistent
  zu `tests/migration-runner-test.php` zu bleiben (dort identisches Muster,
  vgl. `tests/migration-runner-test.php:68-70`) — nachvollziehbare
  Konsistenzentscheidung, aber falls beide Dateien je wieder angefasst
  werden, wäre eine gemeinsame, kleine Harness-Datei (`tests/support/`)
  eine spätere Gelegenheit, den globalen Zustand loszuwerden.

## Lob (kurz, was gut gelöst wurde)

- `proc_open` wird mit dem **Array-Befehlsformat**
  (`array_merge([PHP_BINARY, $migrateScript], $args)`,
  `scripts/migrate-selftest.php:173-180`) statt einer zusammengesetzten
  Shell-Zeichenkette aufgerufen — dadurch gibt es hier **kein**
  Command-Injection-Potential, da keine Shell-Interpretation der Argumente
  stattfindet und alle übergebenen Werte (Konfigpfad, isoliertes
  Migrationsverzeichnis) ohnehin intern generierte, nicht extern
  beeinflussbare Pfade sind.
- Sehr präzise Trennung von D8-Vorbedingung (Basis-Schema `schema.sql` +
  `schema-auth.sql` einspielen) und den vier eigentlich zu prüfenden
  Szenarien — inklusive nachvollziehbarem Verweis auf den bereits in T2.1
  dokumentierten 003/`auth_users`-Datenmodell-Zusammenhang
  (`scripts/migrate-selftest.php:21-31`). Alle vier D8-Szenarien
  (Fresh-Install, Idempotenz, Teilzustand nach `DELETE`, Fehlerabbruch mit
  isolierter kaputter Migration) sind vollständig und mit denselben
  Exit-Code-/Ausgabe-Formaten geprüft, die `migrate.php` tatsächlich
  produziert (`scripts/migrate-selftest.php:275,283,299,327` vs.
  `scripts/migrate.php:189,196`) — enge Kopplung an den echten CLI-Vertrag
  statt an eine angenommene Ausgabe.
- Szenario 4 kopiert die **echten** Migrationsdateien in das isolierte
  Testverzeichnis (`scripts/migrate-selftest.php:314-316`), statt
  synthetische Ersatzdateien zu verwenden — dadurch bleibt der Test robust
  gegenüber künftigen neuen Migrationen (z. B. `006_*.sql`), ohne separat
  gepflegt werden zu müssen. `database/migrations/` wird dabei nachweislich
  nie beschrieben (nur `getAvailableMigrations()`/`copy()`, keine
  Schreiboperation auf den echten Pfad).
- Sauberer, konkreter Nachweis in `task-T3.1.notes.md`, dass die
  Abwärtskompatibilität nicht nur behauptet, sondern durch einen gezielten
  Sabotage-Test (`$result['failedVersion'] = null` einfügen, Selbsttest
  schlägt korrekt fehl, Sabotage wieder entfernen, erneut grün) verifiziert
  wurde — ungewöhnlich gründliche Eigenkontrolle für ein Projekt ohne
  PHPUnit/CI-Absicherung.
