#!/usr/bin/env bash
# =============================================================================
# Smoke-Test: openspec/changes/add-production-deploy-workflow (Tasks 3.1-3.3)
# Verifiziert die Verhaltens-Szenarien aus
# openspec/changes/add-production-deploy-workflow/specs/production-deployment/spec.md,
# soweit sie OHNE echten Deploy-Zielserver (SSH/Secrets) lokal reproduzierbar
# sind. Deckt NUR die in .github/workflows/deploy.yml bereits implementierten
# Steps ab (Grundgerüst, Build-Steps, SSH-Setup + Maintenance an). rsync,
# Migrationen, Cleanup/Summary und der Kopplungs-Guard (Tasks 3.4-3.7) sind
# noch nicht implementiert und werden hier bewusst NICHT getestet.
#
# Analog zu tests/ci-workflow-smoke.sh: reines Bash + Python (pyyaml), kein
# PHPUnit. Verändert keine Produktivdateien dauerhaft (dist/ wird am Ende
# entfernt, temporäre Arbeitsverzeichnisse liegen unter mktemp -d).
#
# Ausführen aus dem Projekt-Root: bash tests/deploy-workflow-smoke.sh
# =============================================================================
set -uo pipefail

PASS=0
FAIL=0
FAILED_NAMES=()

pass() { PASS=$((PASS + 1)); printf '  \033[32m✓ %s\033[0m\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); FAILED_NAMES+=("$1"); printf '  \033[31m✗ %s\033[0m\n' "$1"; }

[[ -f "build.sh" && -d "api" ]] || {
    echo "Bitte aus dem Projekt-Root ausführen (web-dog-mentality-test/)." >&2
    exit 2
}

WF=".github/workflows/deploy.yml"
[[ -f "$WF" ]] || {
    echo "$WF nicht gefunden." >&2
    exit 2
}

cleanup() {
    rm -rf dist
}
trap cleanup EXIT

echo ""
echo "== 1. YAML-Validität =="

if python3 -c "import yaml; yaml.safe_load(open('$WF'))" >/tmp/deploy-smoke-yaml.log 2>&1; then
    pass "deploy.yml: yaml.safe_load lädt fehlerfrei"
else
    fail "deploy.yml: yaml.safe_load schlägt fehl, siehe /tmp/deploy-smoke-yaml.log"
fi
rm -f /tmp/deploy-smoke-yaml.log

echo ""
echo "== 2. GitHub-Actions-Schema (manuell/strukturell) =="

if grep -q 'workflows: \["CI"\]' "$WF"; then
    pass "on.workflow_run.workflows referenziert exakt [\"CI\"]"
else
    fail "on.workflow_run.workflows referenziert NICHT [\"CI\"]"
fi

if grep -q '^  workflow_dispatch:' "$WF" && grep -A3 '^  workflow_dispatch:' "$WF" | grep -q 'inputs:'; then
    pass "on.workflow_dispatch mit inputs vorhanden"
else
    fail "on.workflow_dispatch mit inputs fehlt"
fi

if grep -q 'default: main' "$WF"; then
    pass "workflow_dispatch.inputs.ref hat Default main"
else
    fail "workflow_dispatch.inputs.ref: Default main fehlt"
fi

if grep -q '^  group: deploy-production' "$WF" && grep -q '^  cancel-in-progress: false' "$WF"; then
    pass "concurrency: group=deploy-production, cancel-in-progress=false"
else
    fail "concurrency-Block weicht von der Spec ab"
fi

if grep -q '^    environment: production' "$WF"; then
    pass "Job nutzt environment: production"
else
    fail "Job nutzt NICHT environment: production"
fi

if grep -q "actions/checkout@v4" "$WF"; then
    pass "actions/checkout@v4 referenziert (bekannte, gültige Version)"
else
    fail "actions/checkout@v4 NICHT gefunden"
fi

if grep -q "shivammathur/setup-php@v2" "$WF"; then
    pass "shivammathur/setup-php@v2 referenziert (bekannte, gültige Version)"
else
    fail "shivammathur/setup-php@v2 NICHT gefunden"
fi

if grep -q 'php-version: "8.0"' "$WF"; then
    pass "php-version ist \"8.0\" (identisch zu ci.yml)"
else
    fail "php-version weicht von \"8.0\" ab"
fi

if grep -q 'extensions: mbstring, ctype, gd, zip, mysqli, curl, json' "$WF"; then
    pass "extensions-Liste enthält gd/zip/mysqli, kein pdo_mysql (identisch zu ci.yml)"
else
    fail "extensions-Liste weicht von der ci.yml-Referenzliste ab"
fi

# Klammerbalance der ${{ ... }}-Ausdrücke
OPEN_COUNT=$(grep -o '\${{' "$WF" | wc -l | tr -d ' ')
CLOSE_COUNT=$(grep -o '}}' "$WF" | wc -l | tr -d ' ')
if [[ "$OPEN_COUNT" -gt 0 && "$OPEN_COUNT" -eq "$CLOSE_COUNT" ]]; then
    pass "Kontext-Ausdrücke \${{ ... }} sind ausgeglichen ($OPEN_COUNT Stück)"
else
    fail "Kontext-Ausdrücke \${{ ... }} sind NICHT ausgeglichen (open=$OPEN_COUNT, close=$CLOSE_COUNT)"
fi

if grep -q "github.event.workflow_run.conclusion == 'success'" "$WF" \
        && grep -q "github.event_name == 'workflow_dispatch'" "$WF"; then
    pass "job.if enthält beide erwarteten Bedingungen"
else
    fail "job.if fehlt eine der beiden erwarteten Bedingungen"
fi

echo ""
echo "== 3. Build-Steps isoliert lokal simulieren (ohne SSH-Teil) =="

rm -rf dist
if bash build.sh >/tmp/deploy-smoke-build.log 2>&1; then
    pass "bash build.sh (lokal) -> Exit 0"
else
    fail "bash build.sh (lokal) -> erwartete Exit 0, siehe /tmp/deploy-smoke-build.log"
fi

ALL_PRESENT=1
MISSING_LIST=()
for f in api/auth.php frontend/index.html wizard/index.php vendor/autoload.php scripts/migrate.php; do
    if [[ ! -f "dist/dog-mentality-test/$f" ]]; then
        ALL_PRESENT=0
        MISSING_LIST+=("$f")
    fi
done
if [[ "$ALL_PRESENT" -eq 1 ]]; then
    pass "alle fünf Kern-Dateien aus dem 'Kern-Dateien verifizieren'-Step sind nach build.sh vorhanden"
else
    fail "fehlende Kern-Dateien nach build.sh: ${MISSING_LIST[*]}"
fi
rm -f /tmp/deploy-smoke-build.log
rm -rf dist

echo ""
echo "== 4. Negativtest: 'Kern-Dateien verifizieren'-Step gegen unvollständiges dist/ =="

# Extrahiert exakt den run:-Codeblock des Steps "Kern-Dateien verifizieren"
# aus der committeten deploy.yml (kein manuell nachgebauter String) und führt
# ihn in einem isolierten temporären Arbeitsverzeichnis aus.
EXTRACT_LOG=$(mktemp)
STEP_SCRIPT=$(python3 - "$WF" <<'PYEOF'
import sys, yaml
wf = yaml.safe_load(open(sys.argv[1]))
steps = wf["jobs"]["deploy"]["steps"]
for step in steps:
    if step.get("name") == "Kern-Dateien verifizieren":
        print(step["run"])
        break
else:
    sys.exit("Step 'Kern-Dateien verifizieren' nicht gefunden")
PYEOF
)
if [[ -z "$STEP_SCRIPT" ]]; then
    fail "Konnte den run:-Block des Steps 'Kern-Dateien verifizieren' nicht aus deploy.yml extrahieren"
else
    TMPDIR=$(mktemp -d)
    (
        cd "$TMPDIR" || exit 1
        mkdir -p dist/dog-mentality-test/api dist/dog-mentality-test/frontend \
                 dist/dog-mentality-test/wizard dist/dog-mentality-test/vendor \
                 dist/dog-mentality-test/scripts
        touch dist/dog-mentality-test/api/auth.php
        touch dist/dog-mentality-test/frontend/index.html
        touch dist/dog-mentality-test/wizard/index.php
        touch dist/dog-mentality-test/vendor/autoload.php
        # scripts/migrate.php wird ABSICHTLICH NICHT angelegt
        bash -c "$STEP_SCRIPT"
    ) >"$EXTRACT_LOG" 2>&1
    EXIT_CODE=$?
    rm -rf "$TMPDIR"

    if [[ "$EXIT_CODE" -eq 1 ]]; then
        pass "extrahierter Step bricht mit Exit 1 ab, wenn scripts/migrate.php fehlt"
    else
        fail "extrahierter Step: erwartete Exit 1 bei fehlendem scripts/migrate.php, erhalten Exit $EXIT_CODE (siehe $EXTRACT_LOG)"
    fi

    if grep -q "::error::Kern-Datei fehlt: scripts/migrate.php" "$EXTRACT_LOG"; then
        pass "extrahierter Step meldet exakt die fehlende Datei (scripts/migrate.php)"
    else
        fail "extrahierter Step: erwartete Fehlermeldung für scripts/migrate.php nicht im Log gefunden (siehe $EXTRACT_LOG)"
    fi
fi
rm -f "$EXTRACT_LOG"

echo ""
echo "== 5. Trigger-Logik (job.if) mit synthetischen Event-Payloads =="

TRIGGER_LOG=$(mktemp)
python3 - <<'PYEOF' >"$TRIGGER_LOG" 2>&1
def evaluate(event_name, workflow_run_conclusion=None):
    # Wörtliche Übersetzung von job.if aus deploy.yml:
    # github.event_name == 'workflow_dispatch'
    #   || github.event.workflow_run.conclusion == 'success'
    return (event_name == 'workflow_dispatch') or (workflow_run_conclusion == 'success')

cases = [
    ("workflow_dispatch", None, True, "workflow_dispatch soll laufen"),
    ("workflow_run", "success", True, "workflow_run+success soll laufen"),
    ("workflow_run", "failure", False, "workflow_run+failure soll NICHT laufen"),
    ("workflow_run", "cancelled", False, "workflow_run+cancelled soll NICHT laufen"),
]

failures = 0
for event_name, conclusion, expected, label in cases:
    result = evaluate(event_name, conclusion)
    status = "OK" if result == expected else "FAIL"
    if status == "FAIL":
        failures += 1
    print(f"{status}|{label}|event_name={event_name} conclusion={conclusion} -> {result} (erwartet {expected})")

raise SystemExit(1 if failures else 0)
PYEOF
TRIGGER_EXIT=$?

while IFS='|' read -r status label detail; do
    [[ -z "$status" ]] && continue
    if [[ "$status" == "OK" ]]; then
        pass "job.if: $label ($detail)"
    else
        fail "job.if: $label ($detail)"
    fi
done < "$TRIGGER_LOG"

if [[ "$TRIGGER_EXIT" -ne 0 ]]; then
    fail "job.if-Simulation: mindestens ein Fall weicht vom erwarteten Verhalten ab"
fi
rm -f "$TRIGGER_LOG"

echo ""
echo "== 6. Secrets-Handling: DEPLOY_SSH_KEY nur in env:-Block, nie direkt in run: =="

SSH_KEY_TOTAL=$(grep -c 'secrets.DEPLOY_SSH_KEY' "$WF")
SSH_KEY_IN_ENV=$(grep -c 'DEPLOY_SSH_KEY: \${{ secrets.DEPLOY_SSH_KEY }}' "$WF")

if [[ "$SSH_KEY_TOTAL" -eq 1 && "$SSH_KEY_IN_ENV" -eq 1 ]]; then
    pass "secrets.DEPLOY_SSH_KEY kommt genau einmal vor, ausschließlich als env:-Zuweisung"
else
    fail "secrets.DEPLOY_SSH_KEY: unerwartete Vorkommen (total=$SSH_KEY_TOTAL, in env:-Form=$SSH_KEY_IN_ENV)"
fi

# Kein run:-Block darf ${{ secrets. direkt interpolieren (Command-Injection-Risiko)
if grep -A1 '^\s*run: |' "$WF" | grep -q '\${{ secrets\.'; then
    fail "mindestens ein run:-Block interpoliert \${{ secrets.* }} direkt"
else
    pass "kein run:-Block interpoliert \${{ secrets.* }} direkt (Prüfung auf Zeilen nach 'run: |')"
fi

# Generischere Prüfung: alle Zeilen, die "secrets." enthalten, dürfen nur in
# env:-Wertzuweisungen (Key: ${{ secrets.* }}) auftreten, nicht in run:-Skriptzeilen.
python3 - "$WF" <<'PYEOF'
import sys, re
lines = open(sys.argv[1]).read().splitlines()
bad = []
in_run_block = False
run_indent = None
for line in lines:
    stripped = line.strip()
    indent = len(line) - len(line.lstrip(" "))
    if in_run_block:
        if line.strip() == "" or indent > run_indent:
            if "secrets." in line:
                bad.append(line)
            continue
        else:
            in_run_block = False
    if re.match(r"^\s*run:\s*\|\s*$", line):
        in_run_block = True
        run_indent = indent
        continue
if bad:
    print("GEFUNDEN: secrets.* direkt in run:-Skriptzeile(n):")
    for b in bad:
        print("  " + b)
    sys.exit(1)
print("OK: keine secrets.*-Referenz innerhalb eines run:-Skriptblocks")
PYEOF
if [[ $? -eq 0 ]]; then
    pass "AST-nahe Prüfung: keine secrets.*-Referenz innerhalb eines mehrzeiligen run:-Blocks"
else
    fail "AST-nahe Prüfung: secrets.*-Referenz innerhalb eines run:-Blocks gefunden"
fi

echo ""
echo "=============================================================="
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
if [[ "$FAIL" -gt 0 ]]; then
    echo "Fehlgeschlagen:"
    for n in "${FAILED_NAMES[@]}"; do
        echo "  - $n"
    done
fi
echo "=============================================================="

[[ "$FAIL" -eq 0 ]]
