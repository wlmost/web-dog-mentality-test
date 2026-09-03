# Review: T3.1-3.3

**Gesamtempfehlung:** ok

Geprüft: `.github/workflows/deploy.yml` (Commit `f653e44`), Abschnitte 3.1
(Trigger/Concurrency/Environment/Checkout), 3.2 (Build-Steps inkl.
Kern-Dateien-Verifikation) und 3.3 (SSH-Setup + Wartungsmodus an) gegen
`proposal.md`, `design.md` (D2–D5, D8, D9) und
`specs/production-deployment/spec.md`. 3.4–3.7 sind laut `tasks.md` und
`task-T3.1-3.2.notes.md` bewusst noch nicht implementiert und wurden nicht
bewertet.

## Muss (blockiert Abnahme)

Keine.

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[Testbarkeit/Prozess]** `openspec/changes/add-production-deploy-workflow/task-T3.1-3.2.notes.md:149-156`: Als Verifikationsnachweis wird
  `python3 -c "import yaml; yaml.safe_load(...)"` angeführt ("lädt fehlerfrei").
  Das ist irreführend: PyYAML (YAML-1.1-Loader) interpretiert den Top-Level-Key
  `on:` als Boolean `True`, nicht als String `"on"` — geprüft in diesem Review
  (`python3 -c "import yaml,json; print(json.dumps(yaml.safe_load(open('.github/workflows/deploy.yml'))))"`
  liefert `{"name": "Deploy", "true": {...}, ...}`). Für GitHubs eigenen
  Actions-Parser ist das irrelevant (der behandelt `on:` korrekt als
  reserviertes Schlüsselwort), aber als *Nachweis für korrekte GHA-Syntax*
  taugt ein generischer YAML-1.1-Loader nicht — er hätte z. B. eine
  Fehlkonfiguration mit doppeltem `on:`-Key nicht zuverlässig angezeigt.
  Vorschlag: Diese Zwischenverifikation im Notes-Dokument nicht als
  Struktur-Nachweis führen, sondern klar als reinen Parse-Smoke-Test
  kennzeichnen; der eigentliche Nachweis bleibt `actionlint` in Task 5.1.
- **[Konsistenz]** `.github/workflows/deploy.yml:65-70`: `DEPLOY_HOST`,
  `DEPLOY_USER`, `DEPLOY_PATH` werden bereits in T3.1 auf Job-Ebene aus den
  Secrets gesetzt, obwohl sie in diesem Diff nur vom "Wartungsmodus an"-Step
  (T3.3) benötigt werden. Das ist eine bewusste Vorgriff-Entscheidung
  (`task-T3.1-3.2.notes.md:73-81`) und laut Design (Secrets werden ohnehin
  für den ganzen Job benötigt, siehe D2/D6) sachlich gedeckt, weitet aber die
  Sichtbarkeit der Secrets unnötig auf die Steps "PHP einrichten", "rsync
  sicherstellen" und "Build ausführen" aus, die sie nicht brauchen. Kein
  akutes Risiko (GitHub maskiert Secret-Werte ohnehin repo-/step-übergreifend
  im Log), aber ein Least-Privilege-Punkt: Erwägenswert wäre, diese drei
  Variablen erst ab dem Step einzuführen, der sie zuerst braucht (Step-Env
  statt Job-Env), sobald 3.4–3.6 ergänzt werden.

## Könnte (optional, Verbesserung)

- **[Robustheit]** `.github/workflows/deploy.yml:53`: `ssh-keyscan -p "$DEPLOY_PORT" -H "$DEPLOY_HOST" >> ~/.ssh/known_hosts`
  hat keinen Retry und keine explizite Prüfung, dass tatsächlich mindestens
  eine Zeile geschrieben wurde (z. B. bei kurzzeitig nicht erreichbarem Host
  liefert `ssh-keyscan` ggf. leere Ausgabe statt eines Fehlercodes ungleich
  0, wodurch `known_hosts` leer bliebe und der nachfolgende SSH-Schritt mit
  `StrictHostKeyChecking=yes` dann korrekt, aber erst beim eigentlichen
  Verbindungsversuch fehlschlägt). Funktional unkritisch, da der Fehler
  ohnehin spätestens beim ersten `ssh`-Aufruf sichtbar wird und der Workflow
  dann korrekt rot wird — aber eine explizite Prüfung
  (`[[ -s ~/.ssh/known_hosts ]] || exit 1`) würde die Fehlerursache klarer
  benennen.
- **[Dokumentation]** Es fehlt weiterhin ein `TESTING.md` im Projekt-Root.
  Da dieser Diff keine Testdateien enthält, ist das für T3.1-3.3 nicht
  blockierend, aber als generelle Lücke erwähnenswert für spätere
  CI/CD-bezogene Testarbeiten (z. B. Task 5.1).

## Lob (kurz, was gut gelöst wurde)

- Sauberes Secret-Handling: **kein** `secrets.*`-Ausdruck wird direkt in
  einen `run:`-String interpoliert — sowohl `DEPLOY_SSH_KEY`
  (`.github/workflows/deploy.yml:60-61`) als auch `DEPLOY_HOST`/`DEPLOY_USER`/
  `DEPLOY_PATH` (Zeilen 22-26) laufen ausschließlich über `env:` und werden im
  Skript nur als Shell-Variable referenziert. Genau die von GitHub empfohlene
  Absicherung gegen Command-Injection über Secret-Inhalte.
- SSH-Key-Schreiben ist vorbildlich: `printf '%s\n' "$DEPLOY_SSH_KEY" > ~/.ssh/deploy_key`
  (kein `echo`, keine Gefahr von Shell-Escape-Interpretation), unmittelbar
  gefolgt von `chmod 600`; `~/.ssh` selbst erhält vorher `chmod 700`
  (Zeilen 58-62).
- Host-Key-Handling exakt wie in D9 gefordert: `ssh-keyscan` befüllt
  `known_hosts` **vor** dem ersten echten `ssh`-Aufruf ("Wartungsmodus an"),
  und `-o StrictHostKeyChecking=yes` ist konsequent gesetzt — kein `no`,
  kein `accept-new` im gesamten Diff.
- Trigger- und Concurrency-Konfiguration entspricht wortgleich dem Vertrag
  aus `add-ci-workflow` design.md D2 (`workflows: ["CI"]`, verifiziert gegen
  `name: CI` in `.github/workflows/ci.yml:1`) sowie der `if:`-Bedingung und
  `cancel-in-progress: false` aus `design.md`/`spec.md`.
- Checkout-`ref`-Ausdruck ist wortgleich zu design.md D4 übernommen und
  vermeidet damit korrekt das TOCTOU-Risiko (deployt wird
  `workflow_run.head_sha`, nicht der potenziell neuere `main`-HEAD).
- Die Kern-Dateien-Verifikation (inkl. `scripts/migrate.php`) steht sauber
  vor jedem SSH-Schritt und bricht mit `exit 1` ab, bevor überhaupt ein
  SSH-Schlüssel geschrieben wird — genau wie in der Spec gefordert.
- `permissions: contents: read` auf Workflow-Ebene ist sinnvoll (least
  privilege) und konsistent mit `ci.yml`.
