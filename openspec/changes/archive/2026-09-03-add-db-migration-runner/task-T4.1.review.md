# Review: T4.1

**Gesamtempfehlung:** ok

## Muss (blockiert Abnahme)

_Keine._

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[Konsistenz]** `build.sh:135-137`: Der neue Block prüft nicht explizit,
  ob `scripts/MigrationRunner.php` bzw. `scripts/migrate.php` existieren,
  bevor kopiert wird. In den umgebenden Blöcken derselben Sektion „3/5" gibt
  es für als kritisch markierte Dateien ein explizites Hard-Fail-Muster:
  `[[ -f "$DIST/api/auth.php" ]] || error "Kern-Datei fehlt: api/auth.php"`
  (`build.sh:84`), analog für `frontend/index.html` (`build.sh:96`) und
  `wizard/index.php` (`build.sh:103`). `MigrationRunner.php`/`migrate.php`
  sind laut Spec (`specs/database-migrations/spec.md:199-211`, Requirement
  „Runner ist Teil des Deploy-Pakets") ebenso verpflichtend fürs Deploy-Paket,
  bekommen aber keinen solchen Check. Funktional bricht `cp` bei fehlender
  Quelldatei wegen `set -euo pipefail` (`build.sh:7`) zwar ohnehin sofort mit
  Exit ≠ 0 ab, aber mit der rohen `cp`-Fehlermeldung
  (`cp: scripts/MigrationRunner.php: No such file or directory`) statt der
  farbig formatierten, sprechenden `error()`-Meldung, die die Kern-Dateien in
  den Nachbarblöcken bekommen. Vorschlag: nach dem `cp` (oder davor per
  `[[ -f ... ]]`) einen `error "Kern-Datei fehlt: scripts/..."`-Check
  ergänzen, um das Muster der Sektion konsistent auf alle für den
  Produktivbetrieb zwingend nötigen Dateien anzuwenden.

## Könnte (optional, Verbesserung)

- **[Lesbarkeit]** `build.sh:132-134`: Der Kommentar über dem Block nennt nur
  `migrate-selftest.php` als bewusst ausgeschlossen. `scripts/README.md`
  wird durch dieselbe explizite Allowlist ebenfalls nicht kopiert (siehe
  `task-T4.1.notes.md`, Abschnitt „Verifikation"), das ist aber aus dem
  Kommentar nicht ersichtlich. Ein kurzer Zusatz („… und README.md") würde
  verhindern, dass das später als Versehen missverstanden und „repariert"
  wird.

## Lob (kurz, was gut gelöst wurde)

- Platzierung exakt wie in `design.md` (D7) gefordert: direkt nach dem
  Datenbank-Block und vor `uploads/` (`build.sh:132` ff.), ohne die
  bestehende Struktur der Sektion „3/5" zu verändern.
- Die Allowlist (`cp scripts/MigrationRunner.php scripts/migrate.php ...`,
  `build.sh:136`) trifft exakt die beiden Dateien, die
  `specs/database-migrations/spec.md:200-202` als SHALL/SHALL-NOT fürs
  Deploy-Paket benennt — keine Über- oder Untererfüllung der Spec.
- `ok "scripts/ (MigrationRunner.php, migrate.php)"` (`build.sh:137`) folgt
  exakt dem Stil-Muster der Nachbarzeile `ok "database/ (Schemas +
  Migrations)"` (`build.sh:130`).
