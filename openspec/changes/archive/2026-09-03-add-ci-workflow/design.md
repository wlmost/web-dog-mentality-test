## Context

Vanilla-PHP-Projekt (PHP 8.0, kein Framework). Struktur: `api/*.php`
(REST-Endpunkte, `mysqli`-basiert — siehe `api/config.php:93` `new mysqli(...)`),
`frontend/` (statisches HTML/JS/CSS), `wizard/` (Web-Installer),
`database/` (Schemas + `migrations/*.sql`), `build.sh` (erzeugt
`dist/dog-mentality-test/`), `index.php` (Root-Redirect).

`composer.json` enthält heute nur:

```json
{ "require": { "phpoffice/phpspreadsheet": "^1.29" },
  "config": { "platform": { "php": "8.0" } } }
```

Keine `scripts`, keine `require-dev`, keine `name`/`description`/`license`.
`composer.lock` ist gitignored. GitHub-Remote:
`git@github.com:wlmost/web-dog-mentality-test.git`.

Referenz-Workflow `../dog-school-app/.github/workflows/ci.yml` ist deutlich
umfangreicher (DB-Matrix, Docker-PHP, `composer qa`, Frontend-Job). Davon wird
hier bewusst **nur die Grundidee** übernommen (ein benannter CI-Workflow auf
Push/PR, dessen Erfolg ein nachgelagerter Deploy-Workflow via `workflow_run`
abgreift).

## Goals / Non-Goals

**Goals:**

- Ein grünes, aussagekräftiges CI-Signal auf `main` mit minimalem Wartungsaufwand.
- Reproduzierbare Composer-Builds (`composer.lock` eingecheckt).
- `build.sh` im CI ohne Fehlerausgabe-Artefakte lauffähig, mit sauberen Exit-Codes.
- Stabiler Workflow-Name `CI` als Vertrag für `add-production-deploy-workflow`.

**Non-Goals:**

- PHPUnit-/Integrationstests (eigener Folge-Change).
- Statische Analyse (PHPStan/Psalm), Code-Style (php-cs-fixer).
- DB-Matrix oder MySQL-Service-Container.
- Frontend-Build / npm (Assets sind statisch).
- Branch-Protection-Regeln setzen (Infra-Aufgabe des Users, nur dokumentiert).

## Decisions

### D1: Ein Workflow `ci.yml`, drei Jobs (`lint`, `composer`, `build`)

Getrennte Jobs statt einem Monolith: klarere Fehlerlokalisierung, Parallelität.
`build` hängt via `needs: [composer]` von einem erfolgreichen Composer-Lauf ab
(build.sh ruft selbst `composer install --no-dev` auf). `lint` läuft unabhängig.

_Alternative:_ ein einzelner Job — verworfen wegen schlechterer Signalqualität.

### D2: Workflow-Name exakt `CI`

`add-production-deploy-workflow` referenziert diesen String in
`on.workflow_run.workflows: ["CI"]`. Der Name wird hier fixiert und in beiden
Change-Designs als Kopplungsvertrag dokumentiert. Änderung des Namens ist ein
**Breaking Change** für den Deploy-Workflow.

### D3: `php -l` via `find … -print0 | xargs -0 -n1 php -l`

Rekursiv über `.`, ausgenommen `-path './vendor/*'` und `-path './dist/*'`.
`xargs` mit `-P` für Parallelität; Gesamt-Exitcode ≠ 0, sobald eine Datei
einen Syntaxfehler hat (`xargs` propagiert). Deckt bewusst auch
`api/debug-*.php` und `api/test-*.php` ab (liegen im Repo, auch wenn `build.sh`
sie nicht ausliefert).

_Alternative:_ `shivammathur/setup-php` + `php-cs-fixer` — Non-Goal.

### D4: `composer validate --strict` erzwingt neue `composer.json`-Pflichtfelder

`--strict` behandelt Warnungen als Fehler. Fehlende `name`, `description`,
`license` erzeugen Warnungen → Job schlägt fehl. Deshalb ergänzt dieser Change:

```json
"name": "wlmost/web-dog-mentality-test",
"description": "Dog Mentality Test – Vanilla-PHP-Webanwendung zur OCEAN-Verhaltensbewertung von Hunden",
"license": "proprietary",
"type": "project"
```

`license` bewusst `proprietary` (kein OSS-Lizenzfile im Repo). Der genaue
Wert von `name`/`license` ist vom User bestätigungsfähig, blockiert die
Umsetzung aber nicht.

### D5: `composer install --no-dev --optimize-autoloader` als CI-Schritt

Mit eingechecktem `composer.lock` ist der Lauf deterministisch. `--no-dev`,
weil es keine `require-dev` gibt und der Produktions-Install genau dieser
Pfad ist. Erzeugt/prüft implizit `vendor/autoload.php` für den `build`-Job.

### D6: `composer.lock` einchecken + `.gitignore`-Zeile entfernen

Die Zeile `composer.lock` steht im `.gitignore`-Block `# PHP` direkt unter
`vendor/`. `vendor/` bleibt ignoriert, nur `composer.lock` wird entfernt und
die Datei per `composer install` erzeugt und committet.

### D7: `build.sh`-Härtung minimal-invasiv

`build.sh` nutzt bereits `set -euo pipefail` (`build.sh:7`). Probleme:

- Die Farb-Helfer `info/ok/warn/section/error` (`build.sh:14-18`) und die
  farbcodierten Abschluss-`echo`-Blöcke (`build.sh:207` und `build.sh:213`)
  verwenden `echo "…\033[…m…"` **ohne** `-e`.
  In bash gibt das die Escape-Sequenzen wörtlich aus. Fix: auf
  `printf '%b\n' "…"` umstellen bzw. Farbe ganz weglassen, wenn `NO_COLOR`
  oder kein TTY. Kein funktionaler Umbau.
- `error()` macht bereits `exit 1` — beibehalten.
- Verifikation, dass alle `warn "Nicht gefunden: …"`-Pfade, die im CI zu
  einem unvollständigen Paket führen würden (`api/auth.php`,
  `frontend/index.html`, `wizard/index.php`), tatsächlich als **Fehler**
  (Exit ≠ 0) enden, nicht nur als Warnung. Der `build`-Job prüft die
  Kern-Dateien zusätzlich extern (Gürtel + Hosenträger).

_Alternative:_ `build.sh` komplett neu schreiben — verworfen (YAGNI, Risiko).

### D8: Trigger `push: branches: ["**"]` + `pull_request`

Analog Referenz. Der Deploy-Workflow filtert später selbst auf
`branches: [main]` in seinem `workflow_run`-Trigger. Damit läuft CI auf jedem
Branch (frühes Feedback) und liefert trotzdem das benötigte `main`-Signal.

### D9: Setup via `shivammathur/setup-php@v2`, `php-version: '8.0'`

Extensions: `mbstring, ctype, gd, zip, mysqli, curl, json`. Begründung:
`phpoffice/phpspreadsheet ^1.29` deklariert als **harte** `require` u. a.
`ext-gd`, `ext-dom`, `ext-fileinfo`, `ext-iconv`, `ext-simplexml`,
`ext-xmlreader`, `ext-xmlwriter`, `ext-zlib`, `ext-zip`, `ext-ctype`,
`ext-mbstring` (Platform-Check bricht `composer install` ab, wenn eine
fehlt). `shivammathur/setup-php` aktiviert `dom`, `simplexml`, `xml`,
`xmlreader`, `xmlwriter`, `iconv`, `fileinfo`, `zlib`, `libxml` bereits per
Default — **`gd` und `zip` jedoch nicht** und müssen explizit in
`extensions:` stehen. `mbstring`/`ctype` werden zur Sicherheit ebenfalls
explizit gelistet. `mysqli` ist für Lint/Build nicht zwingend, wird aber für
Parität mit dem Deploy-Workflow und künftige Tests mitgeführt (die gesamte
`api/` nutzt `mysqli`, nicht `pdo_mysql`). `curl`/`json` decken die
Laufzeit-Nutzung in `api/ai.php` bzw. den JSON-Endpunkten ab. Die exakte
Liste wird beim ersten CI-Lauf gegen den realen Platform-Check verifiziert
und bis grün nachgezogen (Task 3.4).

## Risks / Trade-offs

- **`composer validate --strict` schlägt aus weiteren Gründen fehl** (z. B.
  `require` ohne `version`-Constraint-Probleme) → Mitigation: Task 2.x führt
  den Befehl lokal/im CI aus und ergänzt fehlende Felder iterativ, bevor der
  Change als fertig gilt.
- **`build.sh` bricht im CI an unerwarteter Stelle ab** (fehlendes `rsync`,
  Pfadannahmen) → Mitigation: `ubuntu-latest` hat `rsync`/`composer` nicht
  vorinstalliert; `composer` kommt via `setup-php`, `rsync` via
  `sudo apt-get install -y rsync` im `build`-Job (oder Prüfung, ob bereits
  vorhanden). Explizit als Task.
- **Workflow-Name-Drift** zwischen `ci.yml` und `deploy.yml` → Mitigation:
  D2 + expliziter Hinweis in beiden Designs; der Deploy-Change ergänzt einen
  Guard-Grep (analog Referenz `deploy-workflow-lint`), der prüft, dass
  `ci.yml` `name: CI` enthält.
- **`composer.lock` erzeugt Diff-Rauschen / Plattform-Abweichung** (lokale
  PHP-Version ≠ 8.0) → Mitigation: `composer install` mit gesetztem
  `config.platform.php=8.0` (bereits in `composer.json`) sorgt für
  konsistente Auflösung; Lock einmalig in einer 8.0-Umgebung erzeugen
  (setup-php lokal oder im CI generieren und committen).
- **Lint über `api/debug-*.php`/`test-*.php`** könnte an veraltetem Code
  scheitern → akzeptiert: solche Dateien sollen dann repariert oder entfernt
  werden; sie liegen im Repo und im `main`-Branch.

## Migration Plan

1. `composer.json` um Pflichtfelder erweitern.
2. In einer PHP-8.0-Umgebung `composer install` ausführen → `composer.lock`.
3. `.gitignore`-Zeile entfernen, `composer.lock` committen.
4. `build.sh` härten.
5. `ci.yml` hinzufügen.
6. Push des Feature-Branches → CI läuft erstmals; iterieren bis grün.

Rollback: `ci.yml` und `composer.lock` entfernen, `.gitignore`-Zeile
wiederherstellen. Kein Produktionseinfluss (CI-only).

## Open Questions

- Soll das CI-Signal später zusätzlich in `main`-Branch-Protection als
  "required status check" verdrahtet werden? (Infra-Entscheidung des Users,
  außerhalb dieses Changes.)
- Finaler Wert für `composer.json` `name` und `license` (Vorschlag:
  `wlmost/web-dog-mentality-test`, `proprietary`).
