# Review: T-ALL (add-ci-workflow)

**Gesamtempfehlung:** ok

## Hinweis zur Prüfgrundlage

- Weder `CLAUDE.md` noch `TESTING.md` existieren im Projekt-Root
  (`web-dog-mentality-test/`). Die in `~/.claude/WORKFLOW.md` vorgesehene
  Pflichtlektüre für projektspezifische Konventionen und Pre-Flight-Checks
  fehlt komplett. Das ist kein Befund gegen diesen Diff, sollte aber
  nachgeholt werden, bevor der nächste Change (`add-production-deploy-workflow`,
  der laut `proposal.md:75-79` explizit auf dieses CI-Signal aufbaut) startet
  — sonst fehlt eine verbindliche Referenz für QA-Befehle und Test-Konventionen.
- Der Diff enthält keine Test-Dateien, daher entfällt Prüfdimension 0 sonst
  inhaltlich; der obige Punkt bleibt trotzdem als Prozess-Lücke bestehen.
- Der reale CI-Lauf auf `feature/add-ci-workflow` (Run `33736777974`,
  „chore(openspec): Task 3.4 abhaken – CI end-to-end grün") ist verifiziert
  grün (`conclusion: success`) — die Spec-Anforderung „CI end-to-end grün"
  (Task 3.4) ist damit objektiv bestätigt, nicht nur behauptet.

## Muss (blockiert Abnahme)

_Keine._ Diff, Design und Spec-Deltas sind deckungsgleich; alle Requirements
aus `specs/continuous-integration/spec.md` sind durch den Diff sichtbar
umgesetzt und durch den grünen CI-Lauf verifiziert.

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[Sicherheit]** `.github/workflows/ci.yml:1-66`: Kein `permissions:`-Block
  auf Workflow- oder Job-Ebene. Das Repository ist **public**
  (`gh repo view` → `isPrivate: false`), und der Workflow triggert auf
  `push` für **jeden** Branch sowie auf `pull_request`. Ohne expliziten
  `permissions:`-Block erhält `GITHUB_TOKEN` die in den Repo-Einstellungen
  hinterlegten Default-Rechte (potenziell `read-write` je nach
  "Workflow permissions"-Einstellung), obwohl keiner der drei Jobs
  (`lint`, `composer`, `build`) Schreibzugriff auf das Repo braucht (kein
  Commit, kein PR-Kommentar, kein Release). Vorschlag: auf Workflow-Ebene
  `permissions: contents: read` ergänzen (Least-Privilege, reduziert die
  Angriffsfläche, falls z. B. eine transitive GitHub-Action oder ein
  bösartiger PR-Branch versucht, das Token zu missbrauchen).

- **[Prozess/Doku]** Projekt-Root: `CLAUDE.md` und `TESTING.md` fehlen (siehe
  Hinweis oben). Da `add-production-deploy-workflow` als Folge-Change auf
  denselben Konventionen aufbauen soll, empfiehlt es sich, spätestens vor
  diesem Folge-Change eine minimale `CLAUDE.md` mit den projekt-lokalen
  QA-Befehlen (`composer validate --strict`, `bash build.sh`, `php -l`)
  anzulegen, damit Workflow-Schritt 12 ("QA-Suite final laufen lassen —
  Befehle aus lokaler CLAUDE.md") überhaupt eine Grundlage hat.

## Könnte (optional, Verbesserung)

- **[DRY]** `.github/workflows/ci.yml:23` und `.github/workflows/ci.yml:39`:
  Die Extensions-Liste `mbstring, ctype, gd, zip, mysqli, curl, json` ist
  wortgleich in den Jobs `composer` und `build` dupliziert. Bei nur zwei
  Vorkommen und einem bewusst schlanken Ein-Datei-Workflow ist eine
  Auslagerung (z. B. via YAML-Anchor oder Composite-Action) aktuell YAGNI —
  aber sobald ein dritter Job dieselbe Liste braucht, wäre eine gemeinsame
  Definition (z. B. `env:`-Variable auf Workflow-Ebene) sinnvoll, um
  Drift zwischen den Jobs zu vermeiden.
- **[Performance]** `.github/workflows/ci.yml` (Jobs `composer`, `build`):
  Kein Composer-Cache (`actions/cache` oder `shivammathur/setup-php`'s
  eingebautes Caching) — jeder Lauf installiert `phpoffice/phpspreadsheet`
  und Abhängigkeiten neu von Packagist. Bei aktuell drei kleinen Jobs
  unkritisch; bei künftig mehr CI-Läufen (z. B. Matrix im Folge-Change)
  würde Caching spürbar Zeit sparen. Non-Goal in diesem Change, daher kein
  Muss.
- **[Stil]** `.github/workflows/ci.yml:36`: Der `rsync sicherstellen`-Step
  installiert nur bedingt nach; auf `ubuntu-latest`-Runnern ist `rsync`
  bereits vorinstalliert, der Step ist damit aktuell praktisch ein No-Op.
  Das ist explizit so in `tasks.md:99-100` vorgesehen (Robustheit gegen
  Runner-Image-Änderungen) — kein Änderungsbedarf, nur zur Transparenz
  erwähnt.

## Lob

- Der Diff hält sich exakt an `design.md`/`tasks.md`: keine
  Scope-Erweiterung (keine PHPUnit-Suite, keine DB-Matrix, kein
  Frontend-Build), obwohl das leicht verlockend gewesen wäre.
- Saubere "Gürtel + Hosenträger"-Absicherung: `build.sh` bricht jetzt selbst
  bei fehlenden Kern-Dateien hart ab (`build.sh:84,96,103`), **und** der
  `build`-Job prüft dieselben Dateien zusätzlich extern
  (`ci.yml:59-63`) — doppelte, unabhängige Absicherung für dasselbe
  Requirement.
- Der `echo`/`\033`-Bugfix in `build.sh` ist korrekt gelöst: durch die
  Nutzung von `printf` mit den Escape-Sequenzen direkt im (einfach
  gequoteten) Format-String und `%s` für den variablen Teil werden die
  Escape-Codes zuverlässig interpretiert, ohne dass Nutzinhalt (`$*`)
  ungewollt selbst als Escape-Sequenz interpretiert wird (verifiziert via
  `printf` lokal — erzeugt echte ESC-Bytes statt der wörtlichen Zeichenfolge
  `\033[`).
- Minimalinvasiver `.gitignore`-Diff (nur die eine Zeile `composer.lock`
  entfernt, `vendor/` bleibt unangetastet) — reduziert das Konfliktrisiko
  mit dem parallel laufenden Change `add-db-migration-runner`, der laut
  `proposal.md:77-79` dieselbe Datei anfasst.
- Task 3.4 wurde nicht nur behauptet, sondern nachweislich verifiziert: der
  referenzierte CI-Lauf auf dem Feature-Branch ist tatsächlich grün.
