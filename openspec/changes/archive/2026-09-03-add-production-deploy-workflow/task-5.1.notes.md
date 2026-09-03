# Task 5.1 — Workflow-Syntax und Trockenlauf prüfen

## Akzeptanzkriterium 1: `actionlint` meldet keine Fehler für `deploy.yml`

**Erledigt.** `actionlint` (v1.7.12, via Homebrew installiert) gegen beide
Workflow-Dateien ausgeführt:

```bash
actionlint .github/workflows/deploy.yml .github/workflows/ci.yml
```

Exit-Code 0, keine Ausgabe (= keine Befunde) für beide Dateien.

## Akzeptanzkriterium 2 & 3: Pausierter Lauf im „Waiting"-Status / Steps nach Approval

**Nicht erledigt — erfordert Infrastruktur außerhalb dieses Repos, die nur
der User einrichten kann:**

1. GitHub Environment `production` mit aktivierten Required Reviewers
   (Settings → Environments → New environment → „production" →
   Deployment protection rules → Required reviewers) — siehe `CI_CD.md`
   Abschnitt (d).
2. Die vier Secrets (`DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`,
   `DEPLOY_PATH`) im Environment hinterlegt.
3. Ein tatsächlicher SSH-fähiger Zielserver (alfahosting), auf dem der
   öffentliche Schlüssel in `authorized_keys` liegt.

Diese drei Punkte kann ich als Agent nicht selbst herstellen: Punkt 1/2 sind
Repository-Admin-Handlungen im GitHub-UI mit echten Zugangsdaten, Punkt 3
ist eine reale Serverumgebung. Ein `workflow_dispatch`-Lauf ohne echte
Secrets/Zielserver würde entweder sofort an der fehlenden SSH-Verbindung
scheitern oder (schlimmer) unbeabsichtigt gegen eine falsche/nicht
vorbereitete Umgebung laufen — beides ohne Aussagekraft für dieses
Akzeptanzkriterium.

**Was stattdessen bereits verifiziert ist** (siehe `task-T3.1-3.3.test-report.md`
und `task-T3.4-3.7.test-report.md`):
- Die `if:`-Bedingung für Trigger-Zulässigkeit wurde gegen synthetische
  Event-Payloads durchgespielt.
- Der Guard-Step wurde lokal gegen mutierte Kopien von `ci.yml`/`deploy.yml`
  simuliert (erkennt Namens-Drift und fehlende Excludes zuverlässig).
- Die rsync-Excludes wurden lokal gegen ein simuliertes Produktions-Ziel
  verifiziert (alle zwölf Schutz-Pfade bleiben erhalten).
- Alle 13 Steps entsprechen exakt der in `design.md` D8 spezifizierten
  Reihenfolge.

**Empfehlung:** Sobald das GitHub Environment `production` mit Secrets
eingerichtet ist, einen `workflow_dispatch`-Lauf gegen einen echten oder
Staging-Server durchführen und dabei explizit prüfen:
- Lauf pausiert sichtbar im Status „Waiting" im Actions-UI, bis Approval erfolgt
- Nach Approval laufen die Steps in der Reihenfolge aus D8 durch
- Die Deployment-Summary erscheint korrekt befüllt nach `$GITHUB_STEP_SUMMARY`
- Bei der **Erstinbetriebnahme** ist der erste Lauf erwartungsgemäß rot
  (siehe `CI_CD.md` Abschnitt f) — kein Grund zur Beunruhigung
