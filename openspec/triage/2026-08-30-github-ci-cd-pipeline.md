# Triage: GitHub CI/CD-Pipeline (Build+Test, gated Deploy auf Produktion)

**Pfad:** gross
**Geschätzter Umfang:** ca. 8-15 Dateien (neu) — YAML (GitHub Actions), PHP (Test-Infra + composer.json), Bash (build.sh-Anpassung), Doku
**Risiko:** hoch — der Deploy-Workflow schreibt auf die Produktionsumgebung; rsync/FTP mit Loeschlogik kann `api/config.local.php`, `wizard/`, `database/` und User-Uploads zerstoeren oder exponieren.
**Klarheit:** mehrdeutig — High-Level-Ziel ist klar, aber Deployment-Transport (SSH vs. FTP), Art der Abnahme (GitHub Environment vs. workflow_dispatch), Umfang der Tests (es existiert keinerlei Test-Infra) und Migrations-Handling sind offen.

## Anforderung (Zusammenfassung)
Es soll eine zweistufige GitHub-Actions-Pipeline entstehen. Stufe 1 (CI) baut die Anwendung und fuehrt Tests aus, ausgeloest bei Push/PR. Stufe 2 (Deploy) laeuft erst nach gruenem CI und einer manuellen Freigabe durch den User und rollt die Anwendung auf die Produktionsumgebung aus. Als Vorlage dienen die Workflows `ci.yml` und `deploy.yml` aus dem Nachbarprojekt `../dog-school-app`.

## Befund aus der Codebasis

### Aktuelles Projekt (web-dog-mentality-test)
- **Kein `.github/workflows/`** vorhanden — nur `.github/agents/` (Developer, CodeReviewer, Tester). Pipeline wird komplett neu aufgebaut.
- **Kein `openspec/`** initialisiert — der Architekt muss `openspec init` ausfuehren, bevor ein Change angelegt werden kann.
- **Kein `CLAUDE.md`** im Projekt-Root — projektspezifische Regeln/Pre-Flight fehlen.
- **Stack:** Vanilla PHP 8.0 (kein Framework, kein `artisan`), 29 PHP-Dateien in `api/`. Frontend ist statisches HTML/CSS/JS in `frontend/` — **kein Build-Schritt, keine `package.json`, kein npm**.
- **`composer.json`** minimal: nur `phpoffice/phpspreadsheet`, **keine `scripts`, keine dev-Dependencies**. `composer.lock` ist in `.gitignore` (Reproduzierbarkeit im CI derzeit nicht gegeben).
- **Keine Test-Infrastruktur:** kein PHPUnit, kein PHPStan, kein Linter/CS-Fixer, kein `tests/`-Verzeichnis, kein `composer qa`.
- **Build:** `build.sh` erzeugt `dist/dog-mentality-test/` (composer `--no-dev`, Whitelist der `api/`-Dateien, `frontend/` ohne Test-Seiten, `wizard/`, DB-Schemas + `database/migrations/*.sql`, `DEPLOY_CHECKLIST.txt`). Erzeugt **kein Archiv**, nur ein Verzeichnis. `dist/` ist gitignored.
- **Deployment heute:** manuelles FTP auf **Shared Hosting ohne Shell-Zugriff** (`FTP_DEPLOYMENT.md`, `QUICKSTART_FTP.md`, `prepare-deployment.ps1`). Installation/Schema ueber den Web-`wizard/`. Migrations sind SQL-Dateien, die der Wizard einspielt — **kein CLI-`migrate`**.
- Sicherheitsrelevante Pfade laut `.gitignore` / jüngsten OWASP-Commits: `api/config.local.php` (Zugangsdaten, nie im Repo), `wizard/.lock`, `logs/`, `uploads/`.

### Referenz (../dog-school-app)
- `ci.yml`: DB-Matrix (mysql/pgsql) via Service-Container, Docker-PHP-Image, `composer qa` (lint, stan, compat-check, test), separater Frontend-Job (`npm test`/`lint`/`build`), plus ein Job der `deploy.yml` auf Schutz-Grep prueft. Trigger: alle Push + PR.
- `deploy.yml`: Trigger `workflow_run` nach erfolgreichem CI auf `main` **plus** `workflow_dispatch`. Nutzt GitHub Environment **`production`** (dort konfigurierbare Approval-Regeln = die "Abnahme"). Transport: **SSH + rsync `-az --delete`** mit Excludes fuer `.env`, `storage/*`, `public/storage`. Laravel-spezifische Schritte (`artisan down/up`, `migrate --force`, `storage:link`, `config:cache`). `concurrency: deploy-production, cancel-in-progress: false`. Secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`, Var `DEPLOY_PORT`.
- **Uebertragbarkeit begrenzt:** Die Referenz setzt SSH, Laravel und Composer auf dem Zielserver voraus. Dieses Projekt ist bisher FTP-only Shared Hosting ohne SSH und ohne Framework. Struktur/Idee (zweistufig, workflow_run + Environment-Approval, concurrency, Excludes) ist uebertragbar; die konkreten Deploy-Steps sind es nicht.

## Warum Pfad "gross"
- Es wird eine komplette CI/CD- **und** Test-Architektur von Null aufgebaut (Test-Infra ist ein eigenes Teilprojekt).
- Mehrere Stacks betroffen: GitHub-Actions-YAML, PHP (composer/PHPUnit), Bash (`build.sh`).
- Produktions-Deploy = hohes Risiko (Loeschlogik, Schutz von `config.local.php`/`wizard/`/`database/`/`uploads/`).
- Kern-Anforderung ist unterspezifiziert (Transport SSH vs. FTP ist eine Architekturentscheidung fuer `deploy.yml`).
- Empfehlung: Architekt zerlegt vorab in Teil-Changes, z. B.
  1. `openspec init` + CI-Workflow (php -l Lint, `composer validate`, `build.sh` Smoke-Run, Artefakt-Upload)
  2. minimale PHP-Test-Infrastruktur (PHPUnit + Smoke/Unit-Tests fuer `api/`), `composer.lock` einchecken
  3. Deploy-Workflow mit Freigabe-Gate (GitHub Environment `production`), Transport gemaess Antwort auf Rueckfrage 1

## Rueckfragen an den User (Klarheit = mehrdeutig)
1. **Deployment-Transport:** Bietet die Produktionsumgebung inzwischen SSH/rsync, oder ist es weiterhin FTP-only Shared Hosting? (Bei FTP: Einsatz einer FTP-Deploy-Action statt SSH+rsync — voellig andere `deploy.yml`.)
2. **"Abnahme von mir":** Als Required-Reviewer am GitHub Environment `production` (automatischer Lauf nach CI, dann Approval-Klick) — oder manuell per `workflow_dispatch` — oder beides?
3. **Trigger-Branch:** Deploy nur von `main`? Ist das Repo bereits auf GitHub (Remote vorhanden) und koennen dort Secrets/Environments gesetzt werden?
4. **Test-Umfang:** Es gibt heute keine Tests. Reicht fuer "testet" zunaechst Syntax-Lint (`php -l` ueber alle Dateien) + `composer validate` + `build.sh`-Dry-Run — oder soll eine echte PHPUnit-Smoke-/Unit-Suite fuer `api/` mit aufgebaut werden (groesserer Umfang)?
5. **Datenbank-Migrationen beim Deploy:** Bisher spielt der Web-`wizard/` die SQL-Migrations ein. Soll der Deploy Migrations automatisch ausfuehren (setzt DB-Zugriff/CLI vom Runner voraus) oder beim Wizard-basierten Vorgehen bleiben?
6. **Schutz serverseitiger Pfade:** Bestaetigung, dass `api/config.local.php`, `wizard/`, `database/` und `uploads/` beim Deploy nie ueberschrieben/geloescht werden (Excludes) — und ob `wizard/` nach Erst-Installation serverseitig entfernt bleiben soll.
7. **Frontend:** Bestaetigung, dass kein Frontend-Build noetig ist (rein statische Assets, kein npm) — im Gegensatz zur Referenz.
8. **PHP-Zielversion Produktion:** `composer.json` nennt Plattform `8.0` — gilt das weiterhin fuer CI-Matrix und `setup-php`?

## Geschaetzt betroffene Dateien / Bereiche
- **Neu:** `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`
- **Neu (Test-Infra, falls in Scope):** `composer.json` (scripts + require-dev), `composer.lock` (einchecken), `phpunit.xml.dist`, `tests/` (Smoke-/Unit-Tests), evtl. `.php-cs-fixer.dist.php`/`phpstan.neon`
- **Aenderung:** `build.sh` (CI-tauglich machen: Idempotenz, Exit-Codes, evtl. tar.gz-Artefakt), `.gitignore` (composer.lock entkommentieren)
- **Neu/Aenderung Doku:** `README.md` / neue `CI_CD.md`, ggf. `CLAUDE.md` anlegen
- **Infra ausserhalb Repo:** GitHub Environment `production` + Secrets (SSH- oder FTP-Zugangsdaten), Branch-Protection

## Ungeprüfte Referenzen
- Keine. Alle genannten Artefakte (`build.sh`, `dist/`, `wizard/`, `api/config.local.php`, `../dog-school-app/.github/workflows/*`) wurden im Dateisystem verifiziert.

## Empfohlene naechste Aktion
1. **User beantwortet zuerst die Rueckfragen 1-8** (insb. 1, 2, 4, 5 sind designbestimmend).
2. Danach **Architekt (Schritt 3)**: `openspec init` ausfuehren und den Change als `gross` in Teil-Changes zerlegen (CI / Test-Infra / Deploy), `/opsx:propose` je Teil-Change; Design fuer `deploy.yml` gemaess gewaehltem Transport.
3. **Skeptiker (Schritt 4)**: `openspec validate` + Realitaetsabgleich (Shared-Hosting-Restriktionen, Secret-Verfuegbarkeit, Loeschlogik-Risiken).
4. **User-Gate 1**, dann Implementierung ueber den **Developer**-Agenten (YAML + PHP + Bash), anschliessend **CodeReviewer** + **Tester** parallel, dann Endabnahme.
