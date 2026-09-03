# Review: T2.1 — `scripts/migrate.php`

**Gesamtempfehlung:** nacharbeit-nötig

## Hinweis zu Prüfdimension 0
`CLAUDE.md` und `TESTING.md` existieren im Projekt-Root nicht (geprüft:
`test -f`). Der Diff enthält keine Testdateien (`scripts/migrate-selftest.php`
ist T3.1, noch offen), daher ohne praktische Konsequenz für dieses Review —
identischer Befund wie bereits in `task-T1.1.review.md` vermerkt.

## Bewertung der bewussten Abweichung von tasks.md (Kernfrage des Auftrags)

**Die Abweichung ist sachlich gerechtfertigt und deckt kein Spec-Problem
auf, das an den Architekten zurückgehen müsste.**

- `specs/database-migrations/spec.md:162-167` („Requirement: Dry-Run-Modus")
  fordert wörtlich: „keine Datenbankänderung vornehmen (auch nicht das
  Anlegen der Tracking-Tabelle, **sofern vermeidbar** — andernfalls nur
  lesend agieren)". Das ist bereits im Spec-Delta selbst so präzisiert und
  steht nicht im Widerspruch zu `tasks.md:44` — die dortige Prosa-Ablaufbeschreibung
  ist lediglich ungenauer formuliert als das Requirement und die eigene
  Akzeptanzbedingung in `tasks.md:51` (**„`--dry-run` ändert die DB nicht"**).
- Die in `scripts/migrate.php:97-126` gewählte Lösung (`SHOW TABLES LIKE`
  rein lesend, `ensureTrackingTable()` nur im Nicht-Dry-Run-Zweig,
  `scripts/migrate.php:129`) erfüllt exakt den im Requirement vorgesehenen
  Fallback „andernfalls nur lesend agieren" und ist sogar strenger als
  gefordert, da sie die Tabellenanlage im Dry-Run vollständig vermeidet statt
  nur „möglichst".
- Die Abweichung ist in `task-T2.1.notes.md` und in der Commit-Message
  (`60841b2`) nachvollziehbar begründet und referenziert konkret Spec und
  Akzeptanzkriterium. Kein Rückgang an den Architekten nötig.

## Muss (blockiert Abnahme)

_Keine._

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[Korrektheit/Doku]** `scripts/migrate.php:96,153-155`: Der Kommentar in
  `task-T2.1.notes.md` („PHP garantiert, dass `finally` vor der tatsächlichen
  Skript-Terminierung noch ausgeführt wird") ist **empirisch falsch**. Jeder
  Codepfad im `try`/`catch`-Block endet mit einem expliziten `exit()`
  (`migrateFail()` in Zeile 28-32, `exit(0)` in Zeile 126 und 150) —
  `exit()` innerhalb eines `try`- oder `catch`-Blocks überspringt den
  zugehörigen `finally`-Block (verifiziert: `php -r 'try { exit(3); } finally
  { echo "läuft"; }'` gibt „läuft" **nicht** aus). Damit ist
  `finally { $conn->close(); }` in Zeile 153-155 in **jedem** realen
  Ausführungspfad toter Code — er wird nie erreicht. Funktional unkritisch
  (der Prozess terminiert ohnehin und das OS schließt den Socket), aber die
  Absicht „definiertes Verbindungs-Cleanup" wird nicht erfüllt und die
  Begründung in den Notes ist irreführend für künftige Wartung. Vorschlag:
  entweder `$conn->close()` vor jedem `exit()`-Aufruf explizit einfügen
  (z. B. über eine gemeinsame `migrateExit(int $code, ?string $message)`-
  Hilfsfunktion, die schließt und danach beendet), oder auf das explizite
  `finally` verzichten und im Kommentar dokumentieren, dass der
  Verbindungsabbau dem Prozessende überlassen wird.

- **[DRY/SRP]** `scripts/migrate.php:102-110`: Der Dry-Run-Zweig baut den
  Tracking-Tabellennamen (`$dbPrefix . 'schema_migrations'`) und die
  `SHOW TABLES LIKE`-Query eigenständig, obwohl `MigrationRunner` genau diese
  Verantwortung bereits kapselt (`ensureTrackingTable()`,
  `getAppliedVersions()` in `scripts/MigrationRunner.php:89-129`). Damit
  existiert die Tabellennamen-Konstruktion jetzt an zwei Stellen; ändert sich
  das Namensschema künftig, muss es synchron in `MigrationRunner.php` und
  `migrate.php` gepflegt werden. Vorschlag: eine schreibfreie Methode
  `MigrationRunner::trackingTableExists(mysqli $conn, string $prefix): bool`
  ergänzen (Folge-Task oder Nachtrag zu T1.1) und in `migrate.php` nur noch
  aufrufen, statt SQL-String-Bau im CLI-Einstiegspunkt zu duplizieren.

- **[Korrektheit]** `scripts/migrate.php:103-106`: Die `SHOW TABLES LIKE`-
  Abfrage prüft das Ergebnis nicht auf einen Query-Fehler — `$conn->query(...)`
  liefert bei einem Fehler `false` (Report-Modus ist durch Zeile 85
  `mysqli_report(MYSQLI_REPORT_OFF)` bewusst klassisch), und
  `$tableCheck instanceof mysqli_result` wird dann `false`, was code-seitig
  identisch zu „Tabelle existiert nicht" behandelt wird
  (`$trackingTableExists = false`). Ein echter Abfragefehler (z. B. fehlendes
  `SHOW`-Privileg) würde im Dry-Run also fälschlich als „alle Migrationen
  ausstehend" gemeldet, statt mit einer Fehlermeldung/Exit 1 zu terminieren —
  inkonsistent zum Verhalten von `MigrationRunner::getAppliedVersions()`
  (`scripts/MigrationRunner.php:113-129`), das bei einem Query-Fehler bewusst
  eine `RuntimeException` wirft. Vorschlag: bei `$tableCheck === false` einen
  Fehler (`$conn->error`) über `migrateFail()` ausgeben statt stillschweigend
  „Tabelle fehlt" anzunehmen.

- **[Lesbarkeit]** `scripts/migrate.php:87`: `!@$conn->real_connect(...)` —
  das `@` ist angesichts des bereits eine Zeile zuvor gesetzten
  `mysqli_report(MYSQLI_REPORT_OFF)` (Zeile 85) redundant (die Begründung
  „Absicherung, unabhängig vom Report-Modus" steht nur in
  `task-T2.1.notes.md`, nicht im Quellcode). `@` unterdrückt pauschal
  **alle** PHP-Warnungen/-Notices der Zeile, nicht nur mysqli-spezifische —
  ein generelles Clean-Code-Risiko, wenn die Unterdrückung nicht am
  Einsatzort begründet ist. Vorschlag: entweder das `@` entfernen (da
  `MYSQLI_REPORT_OFF` bereits ausreicht) oder einen Inline-Kommentar direkt
  über Zeile 87 ergänzen, der die Verteidigungslinie erklärt.

- **[Konsistenz]** `scripts/migrate.php:129-141` im Zusammenspiel mit
  `scripts/MigrationRunner.php:199-221` (`recordMigration`): Schlägt der
  `INSERT` in `<prefix>schema_migrations` fehl (kürzlich in T1.1 gehärtet via
  `runStatement()`), wirft `recordMigration()` eine `RuntimeException`, die
  `runPending()` (Zeile 314) **ungefangen** durchreicht. In `migrate.php`
  landet dieser Fehler dadurch nicht im strukturierten
  `MIGRATE FAIL bei %s: %s`-Pfad (Zeile 136-142, mit `failedVersion`/
  `failedError` aus dem Rückgabe-Array), sondern im generischen
  `catch (Throwable $exception)`-Zweig (Zeile 151-152). Zwei Effekte: (1)
  das Ausgabeformat weicht vom in `design.md` D6 festgelegten
  `MIGRATE FAIL bei NNN: <fehler>`-Schema ab (stattdessen generisches
  `MIGRATE FAIL: Migration NNN konnte nicht in ... eingetragen werden: ...`
  — die Versionsnummer ist zwar noch im Fließtext enthalten, aber nicht im
  erwarteten Format), und (2) der bis dahin gesammelte `$result['log']` mit
  den Erfolgs-Zeilen der bereits ausgeführten Statements dieser Datei wird
  nie ausgegeben, da `$result` in diesem Fall nie zugewiesen wird. Der
  Exit-Code (1) und die Nicht-Eintragung stimmen weiterhin — funktional kein
  Fehler, aber ein Konsistenz-/Beobachtbarkeits-Verlust an genau der Stelle,
  an der Task 1.1 kürzlich robuster gemacht wurde. Vorschlag (kann auch als
  Nachtrag zu T1.1 statt hier gelöst werden): `runPending()` fängt
  `RuntimeException` aus `recordMigration()` und bildet sie auf dieselbe
  `failedVersion`/`failedError`-Struktur ab wie einen `executeSqlFile()`-
  Fehler.

## Könnte (optional, Verbesserung)

- **[Robustheit]** `scripts/migrate.php:74-81`: `DB_PASS` und `DB_PORT`
  werden explizit gecastet (`(string)`/`(int)`), `DB_HOST`, `DB_USER`,
  `DB_NAME`, `DB_PREFIX` dagegen nur per `@var`-Docblock als `string`
  annotiert, ohne Laufzeit-Cast. Da die Datei `declare(strict_types=1)`
  nutzt (Zeile 3), würde eine fehlkonfigurierte `config.local.php` mit z. B.
  `define('DB_HOST', 12345);` zu einem ungefangenen `TypeError` beim Aufruf
  von `real_connect()` führen (der Verbindungsaufbau liegt außerhalb des
  `try`-Blocks, Zeile 86-90) statt zur geforderten Meldung
  „Verbindungsfehler → Fehlermeldung, exit(1)" (`design.md` D6). Sehr
  geringe Praxisrelevanz (Wizard erzeugt `config.local.php` ausschließlich
  mit String-Literalen), aber ein `(string)`-Cast analog zu `DB_PASS` wäre
  für Konsistenz und Robustheit günstig.
- **[Korrektheit, geringes Risiko]** `scripts/migrate.php:104`: Die
  `LIKE`-Suche escaped über `real_escape_string()` nur Anführungszeichen/
  Backslashes, nicht die `LIKE`-Metazeichen `%`/`_`. Da `DB_PREFIX`
  üblicherweise selbst auf `_` endet (Beispiel `dmt_` aus
  `specs/database-migrations/spec.md:111`), matcht `SHOW TABLES LIKE
  'dmt_schema_migrations'` theoretisch auch eine Tabelle, die an dieser
  Stelle ein beliebiges anderes Zeichen hat. Praktisch irrelevant (müsste
  eine gleichnamige Tabelle mit einem Zeichen Abweichung existieren), aber
  bei Umsetzung der oben vorgeschlagenen `trackingTableExists()`-Methode
  ließe sich das mit einer `information_schema.tables`-Abfrage per Prepared
  Statement gleich mitlösen.
- **[Stil]** `scripts/migrate.php:85`: `mysqli_report(MYSQLI_REPORT_OFF)`
  ändert globalen Prozesszustand für das gesamte Skript. Unkritisch, solange
  `migrate.php` der einzige Einstiegspunkt bleibt (aktuell der Fall), aber
  erwähnenswert, falls der Runner später in einen größeren PHP-Prozess
  eingebunden würde (aktuell nicht der Fall/nicht geplant).

## Lob (kurz, was gut gelöst wurde)

- Die Kernentscheidung, `ensureTrackingTable()` im Dry-Run **nicht**
  aufzurufen und stattdessen rein lesend per `SHOW TABLES LIKE` zu prüfen,
  ist genau richtig begründet und liegt näher an Spec und eigener
  Akzeptanzbedingung als die Prosa-Beschreibung in `tasks.md` — vorbildlich
  dokumentiert in Notes und Commit-Message, keine Rückfrage an den
  Architekten nötig.
- Saubere Trennung STDOUT (Log/Erfolg) vs. STDERR (Fehler), passend zum
  CLI-/SSH-Deploy-Kontext.
- Die kombinierte `defined()`-Prüfung mit gesammelter Fehlerliste
  (`$missingConstants`, Zeile 64-72) ist bedienerfreundlicher als eine
  Prüfung, die nur die erste fehlende Konstante meldet.
- Umfangreiche manuelle Verifikation gegen einen echten `mysql:8.0`-Docker-
  Container über alle Kern-Szenarien der Spec (Fresh-Install, Fehlerabbruch
  ohne Fortschrittseintrag, Wiederholungslauf, Dry-Run vorher/nachher) —
  gerade weil dieses Projekt kein PHPUnit nutzt, eine solide Absicherung.
- Sauberer Hinweis im „Nicht angefasst"-Abschnitt der Notes sowie
  Weitergabe des 003/005-Datenmodell-Mismatch-Befunds an die Folge-Tasks
  3.1/3.2, statt es stillschweigend im Scope dieser Task mitzulösen.
