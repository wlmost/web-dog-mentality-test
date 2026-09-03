# Task T4 (4.1 + 4.2) — Dokumentation

## Umfang

- 4.1 `CI_CD.md` (neu)
- 4.2 `FTP_DEPLOYMENT.md` (Hinweiskasten am Anfang ergänzt)
- Zusatz (in 4.1 vorgesehen): kurzer Verweis in `README.md`

Reine Dokumentations-Task, keine PHP-Code-Änderung. Es gibt kein
`CLAUDE.md` im Projekt-Root und keine `composer.json`-`scripts`, daher
entfallen `composer test` / `phpstan` / `php-cs-fixer` — nicht anwendbar
auf Markdown-Dateien.

## Vorgehen

1. `design.md`, `specs/production-deployment/spec.md`, `tasks.md`
   (Abschnitt 4), `proposal.md` vollständig gelesen.
2. `.github/workflows/deploy.yml` als Sekundärquelle der Wahrheit gelesen
   und Secret-/Variablennamen per `grep` verifiziert:
   - Secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`
   - Variablen: `DEPLOY_PORT` (Default `22`), `DEPLOY_PHP_BIN`
     (Default `php`)
   - Alle zwölf rsync-Excludes (`.maintenance`, `api/config.local.php`,
     `uploads/`, `logs/`, `.git`, `wizard/`, `*.zip`, `dist/`, `php.ini`,
     `.user.ini`, `.htpasswd`, `.well-known/`)
3. `FTP_DEPLOYMENT.md` (bestehend) und `README.md` gelesen, um Anknüpfungs-
   punkte für Verweise zu finden.
4. `CI_CD.md` neu geschrieben mit den Abschnitten (a)–(j) exakt wie in
   Task 4.1 gefordert (Zielplattform, Secrets, Variablen, Environment-
   Einrichtung, SSH-Key-Hinterlegung, Erstinbetriebnahme als 3-Schritt-
   Ablauf inkl. wörtlichem Hinweis „erster Deploy ist rot … das ist
   erwartet und vom User akzeptiert", Regelbetrieb, Rollback, Notfall-
   Kommando `rm -f $DEPLOY_PATH/.maintenance`, `wizard/`-Begründung D2b).
5. `README.md`: im bestehenden Abschnitt „📦 Deployment auf Webhosting"
   einen kurzen Verweis auf `CI_CD.md` (bevorzugt) und
   `FTP_DEPLOYMENT.md` (Fallback) vorangestellt — bestehender Inhalt
   unverändert gelassen.
6. `FTP_DEPLOYMENT.md`: Hinweiskasten (Blockquote) direkt nach der
   H1-Überschrift eingefügt, der alfahosting + SSH + CI/CD als
   bevorzugten Weg nennt und die FTP-Anleitung als Fallback einordnet.
   Per `git diff` verifiziert, dass ausschließlich 6 Zeilen eingefügt
   wurden und nichts Bestehendes gelöscht/umformuliert wurde.
7. `tasks.md`: Tasks 4.1 und 4.2 sowie deren Akzeptanzkriterien abgehakt.

## Verifikation

```bash
grep -n "secrets\.\|vars\." .github/workflows/deploy.yml
# → bestätigt exakte Namen DEPLOY_SSH_KEY, DEPLOY_HOST, DEPLOY_USER,
#   DEPLOY_PATH, DEPLOY_PORT, DEPLOY_PHP_BIN

git diff FTP_DEPLOYMENT.md
# → nur ein Insert-Block (6 Zeilen), keine Löschung/Umformulierung
```

## Ergebnis

- Neu: `CI_CD.md` — enthält alle Abschnitte (a)–(j), Secret-/Variablen-
  namen 1:1 aus `deploy.yml` übernommen und verifiziert.
- Geändert: `FTP_DEPLOYMENT.md` — Hinweiskasten vorangestellt, bestehende
  Anleitung vollständig erhalten (per Diff geprüft).
- Geändert: `README.md` — zwei Zeilen Verweis auf `CI_CD.md` /
  `FTP_DEPLOYMENT.md` im Abschnitt „Deployment auf Webhosting".
- `tasks.md`: 4.1 und 4.2 inkl. aller Akzeptanzkriterien abgehakt.

## Nicht im Scope dieser Task

- Abschnitt 5 (Verifikation, `actionlint` etc.) — separate Task.
- Keine Änderung an `deploy.yml`, `build.sh`, `index.php`,
  `api/config.php` — diese sind Teil anderer, bereits abgeschlossener
  Tasks (1.x–3.x).
