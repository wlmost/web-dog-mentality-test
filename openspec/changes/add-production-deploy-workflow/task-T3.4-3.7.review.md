# Review: T3.4–3.7 (deploy.yml — rsync, Migrationen, Cleanup/Summary, Guard)

**Commit:** `d75cba4` | **Branch:** `feature/add-production-deploy-workflow`
**Gesamtempfehlung:** ok

## Hinweis zu Prüfgrundlagen

- Kein `CLAUDE.md` und kein `TESTING.md` im Projekt-Root gefunden — beide
  Pflichtlektüren aus dem Workflow existieren in diesem Projekt nicht. Der
  Diff enthält keine Test-Dateien, daher hat das fehlende `TESTING.md` hier
  keine Auswirkung auf die Prüfdimensionen.
- `design.md`, `specs/production-deployment/spec.md` (unter
  `openspec/changes/add-production-deploy-workflow/specs/`), `tasks.md`
  Abschnitt 3 und `task-T3.4-3.7.notes.md` vollständig gelesen.

## Unabhängige Verifikation: Guard-Step-Position (D8)

**Bestätigt korrekt.** Per `python3 -c "import yaml; ..."` geladen (13 Steps)
und per `grep -n "^      - name:" .github/workflows/deploy.yml` die
tatsächliche Reihenfolge gezogen:

```
1. Checkout                                   6. Kopplungs-Guard
2. PHP einrichten                             7. SSH einrichten
3. rsync sicherstellen                        8. Wartungsmodus an
4. Build ausführen                            9. Dateien übertragen (rsync)
5. Kern-Dateien verifizieren                 10. Migrationen ausführen
                                              11. Wartungsmodus aus
                                              12. SSH-Key entfernen
                                              13. Deployment summary
```

Das entspricht exakt den 13 in design.md D8 nummerierten Schritten. Der
Guard-Step steht nach „Kern-Dateien verifizieren" und vor „SSH einrichten" —
die im Auftrag behauptete Korrektur ist zutreffend.

## Detailprüfung der sieben Punkte

1. **rsync-Excludes:** Alle zwölf Muster aus D2 sind vorhanden, exakte
   Reihenfolge und Quoting wie in `design.md` (`.github/workflows/deploy.yml:106-117`).
   Quelle `dist/dog-mentality-test/` mit Trailing Slash (`:119`), `--delete`
   aktiv (`:105`). Keine Case-Mismatches gefunden: `build.sh` erzeugt
   durchgängig kleingeschriebene `uploads/`, `logs/`, `wizard/`
   (`build.sh:100-155`), passend zu den Exclude-Mustern. Alle
   Verzeichnis-Patterns (`uploads/`, `logs/`, `wizard/`, `dist/`,
   `.well-known/`) tragen konsistent den Trailing Slash. Kein Reihenfolge-Effekt
   erkennbar, da sich die zwölf Muster nicht überschneiden. Kein Fall
   gefunden, in dem trotz der Excludes eine kritische serverseitige Datei
   gelöscht werden könnte.
2. **`wizard/`-Exclude:** Vollständig, `grep -n "wizard/.lock"` liefert keinen
   Treffer mehr in `deploy.yml`. Begründung deckt sich mit D2b
   (`WizardHelper::isLocked()`-Risiko bei fehlendem `.lock`).
3. **Migrations-Step:** Kein `|| true`, kein `continue-on-error` im gesamten
   File (`grep -n "continue-on-error"` / `grep -n '|| true'` → beide leer).
   Da GitHub Actions `run:`-Steps standardmäßig mit `bash -eo pipefail`
   laufen, lässt ein Exit ≠ 0 von `ssh …` den Step und damit den Job rot
   werden (`.github/workflows/deploy.yml:122-126`).
4. **Cleanup-Steps:** „Wartungsmodus aus" (`:128-133`), „SSH-Key entfernen"
   (`:135-137`) und „Deployment summary" (`:139-160`) tragen alle
   `if: always()`. Da „Wartungsmodus aus" selbst mit `|| echo "::warning::…"`
   abgesichert ist, kann dieser Step nie fehlschlagen — „SSH-Key entfernen"
   läuft also so oder so danach (und selbst bei hypothetischem Fehlschlag
   einer `always()`-Vorgänger-Step würde die nächste `always()`-Step trotzdem
   ausgeführt, das ist GHA-Standardverhalten). `rm -f` ist zusätzlich
   idempotent gegen fehlenden Key.
5. **Guard-Step:** `grep -qF -- "exclude='$pattern'"` wurde für die
   Metazeichen-Fälle geprüft: für `.git` erfordert der Fixed-String-Vergleich
   exakt `exclude='.git'` gefolgt von einem schließenden `'`, wodurch z. B.
   `exclude='.github'` **nicht** fälschlich matcht — die Wahl von `-F` statt
   Basic-Regex ist hier tatsächlich notwendig und korrekt (bei `grep -q`
   ohne `-F` hätte `*.zip` als BRE `'*` interpretiert und wäre effektiv ein
   Null-oder-mehr-Quantor auf `'`, was zu Fehlverhalten führen könnte).
   Der Guard liest `.github/workflows/ci.yml` und `.github/workflows/deploy.yml`
   direkt vom durch `actions/checkout` ausgecheckten Arbeitsverzeichnis dieses
   Laufs — es gibt keinen Cache-Layer dazwischen, die Datei ist exakt die des
   geprüften Commits (durch D4/Checkout-`ref` sichergestellt).
6. **Summary:** Alle fünf Felder vorhanden (Auslöser, Deployter Commit,
   Actor, Zeitpunkt UTC, Migrations-Ergebnis, `:150-159`).
   `${{ steps.migrate.outcome || 'übersprungen' }}` ist korrekt: greift der
   Migrations-Step wegen eines vorherigen Fehlers nie, liefert der
   `steps.migrate.outcome`-Kontext `null`, und GHA-Ausdrücke werten
   `null || 'übersprungen'` zu `'übersprungen'` aus.
7. **Secret-Handling:** `grep -n "secrets\."` zeigt, dass alle
   `${{ secrets.* }}`-Zugriffe im job-weiten `env:`-Block
   (`:27-32`, `DEPLOY_HOST`/`DEPLOY_USER`/`DEPLOY_PATH`) bzw. im
   `env:`-Block des „SSH einrichten"-Steps (`DEPLOY_SSH_KEY`, unverändert aus
   3.3) liegen. Die neuen Steps (rsync, Migration) referenzieren ausschließlich
   die daraus resultierenden Shell-Variablen (`$DEPLOY_HOST` etc.) — keine
   direkte `${{ secrets.* }}`-Interpolation in `run:`-Skripten dieser Tasks.

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[DRY/Testbarkeit]** `.github/workflows/deploy.yml:37-40` (Checkout
  `with.ref`) und `:142-145` (`DEPLOY_REF` im Summary-Step) enthalten den
  identischen, mehrzeiligen Ausdruck
  `${{ github.event_name == 'workflow_run' && github.event.workflow_run.head_sha || (github.event.inputs.ref || github.ref) }}`
  in zweifacher, unabhängiger Kopie. Beide hängen ausschließlich vom
  `github`-Kontext ab (kein Step-Output nötig) und könnten daher als ein
  einziger Eintrag im job-weiten `env:`-Block (`:27-32`, dort wo bereits
  `DEPLOY_PORT`/`DEPLOY_PHP_BIN` stehen) definiert und von beiden Stellen
  referenziert werden. Aktuell besteht keine funktionale Abweichung, aber bei
  einer künftigen Änderung nur einer der beiden Kopien würde die
  Deployment-Summary einen anderen Commit ausweisen als tatsächlich
  ausgecheckt/deployt wurde — gerade bei der produktionskritischsten
  Nachvollziehbarkeits-Komponente (Audit-Trail) ein vermeidbares
  Drift-Risiko. Vorschlag: `DEPLOY_REF` in den job-weiten `env:`-Block heben
  und im Checkout-Step als `ref: ${{ env.DEPLOY_REF }}` sowie im
  Summary-Step unverändert referenzieren.
- **[Spec-Konformität]** `openspec/changes/add-production-deploy-workflow/tasks.md:184`
  beschreibt Task 3.7 wörtlich als „Früher Step (nach Checkout, vor Build)".
  Das widerspricht der tatsächlich korrekten, in `design.md` D8 (Schritt 6)
  spezifizierten und im Code umgesetzten Position (nach Build-Verifikation,
  vor SSH-Setup). `task-T3.4-3.7.notes.md:79-81` behauptet, die anfängliche
  Fehlplatzierung habe „sowohl design.md D8 … als auch tasks.md 3.7 selbst"
  widersprochen — das trifft auf `design.md` zu, aber nicht auf den
  tatsächlichen Wortlaut von `tasks.md:184`, der die (falsche)
  Vor-Build-Position ja selbst nennt. Der Code ist korrekt (er folgt zu Recht
  `design.md` D8), aber `tasks.md` sollte redaktionell an `design.md`
  angeglichen werden, damit zukünftige Agenten nicht durch den
  wörtlichen Tasks-Text in die falsche Richtung geleitet werden.

## Könnte (optional, Verbesserung)

- **[Testbarkeit/Präzision]** Der Guard-Step (`:60-85`) prüft die zwölf
  Excludes gegen die **gesamte** `deploy.yml`-Datei, nicht spezifisch gegen
  den rsync-Step-Block. `design.md` D3/D8 und `spec.md` („dass der
  rsync-Schritt … enthält") formulieren die Anforderung enger. Praktisch ist
  das Risiko gering (die Excludes tauchen aktuell nur im rsync-Aufruf auf),
  aber ein zukünftiger Kommentar wie `# exclude='wizard/' entfernt` würde den
  Guard fälschlich grün lassen. Optionale Härtung: den Suchbereich auf den
  Textblock zwischen `- name: Dateien übertragen (rsync)` und dem nächsten
  `- name:` eingrenzen.
- **[DRY]** Die SSH-Verbindungsparameter
  (`-i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$DEPLOY_USER@$DEPLOY_HOST"`)
  wiederholen sich wörtlich in vier Steps (Maintenance an/aus, Migration,
  rsync `-e`). Angesichts der überschaubaren Zahl an Stellen und der
  Referenz-Vorlage (`design.md` Context) ein akzeptabler KISS-Kompromiss,
  könnte aber bei weiterem Wachstum in eine Env-Variable
  (`SSH_CMD`/gemeinsames `-e`-Fragment) extrahiert werden.

## Lob

- Die Korrektur der Guard-Position wurde unabhängig nachvollzogen und ist
  zutreffend: alle 13 Steps stehen exakt in der von `design.md` D8
  vorgegebenen Reihenfolge.
- Saubere, defensive Wahl von `grep -qF --` statt Basic-Regex im Guard —
  korrekt begründet und für die Metazeichen-Fälle (`.git`, `*.zip`,
  `.maintenance`) tatsächlich notwendig, nicht nur kosmetisch.
- Konsequente Trennung von Secrets (`env:`-Block) und `run:`-Skripten in den
  neuen Steps — keine `${{ secrets.* }}`-Interpolation direkt im Skripttext.
- Migrations-Step lässt Fehler korrekt durchschlagen (kein `|| true`,
  kein `continue-on-error`), Cleanup-Steps sauber mit `if: always()`
  abgesichert.
- `task-T3.4-3.7.notes.md` dokumentiert die eigene Fehlkorrektur transparent
  inklusive durchgeführter Verifikationsschritte (YAML-Parse, Guard-Simulation,
  Secrets-Grep) — nachvollziehbar und ehrlich, auch bezüglich nicht
  durchgeführter Live-Tests gegen den echten Server.
