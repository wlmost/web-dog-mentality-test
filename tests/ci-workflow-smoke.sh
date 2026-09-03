#!/usr/bin/env bash
# =============================================================================
# Smoke-Test: openspec/changes/add-ci-workflow
# Verifiziert die Verhaltens-Szenarien aus
# openspec/changes/add-ci-workflow/specs/continuous-integration/spec.md,
# soweit sie ohne echten GitHub-Actions-Runner lokal reproduzierbar sind.
#
# Kein PHPUnit (bewusster Non-Goal von add-ci-workflow) — reines Bash, analog
# zu build.sh. Verändert keine Produktivdateien dauerhaft: temporäre
# Verschiebungen werden im selben Schritt zurückgesetzt (trap).
#
# Ausführen aus dem Projekt-Root: bash tests/ci-workflow-smoke.sh
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

cleanup() {
    [[ -f "api/auth.php.smoketest-bak" ]] && mv "api/auth.php.smoketest-bak" "api/auth.php"
    [[ -f "frontend/index.html.smoketest-bak" ]] && mv "frontend/index.html.smoketest-bak" "frontend/index.html"
    [[ -f "wizard/index.php.smoketest-bak" ]] && mv "wizard/index.php.smoketest-bak" "wizard/index.php"
    rm -f "api/broken-syntax.smoketest.php"
    rm -rf dist
}
trap cleanup EXIT

echo ""
echo "== Requirement: PHP-Syntaxprüfung aller Projektdateien =="

# Szenario: Alle Dateien syntaktisch korrekt
if find . -name '*.php' -not -path './vendor/*' -not -path './dist/*' -print0 \
        | xargs -0 -r -n1 -P4 php -l >/tmp/ci-smoke-lint-ok.log 2>&1; then
    pass "lint: alle Dateien syntaktisch korrekt -> Exit 0"
else
    fail "lint: alle Dateien syntaktisch korrekt -> erwartete Exit 0, siehe /tmp/ci-smoke-lint-ok.log"
fi
rm -f /tmp/ci-smoke-lint-ok.log

# Szenario: Eine Datei mit Syntaxfehler -> Exit != 0, Pfad wird genannt
printf '<?php this is not valid php {{{ \n' > "api/broken-syntax.smoketest.php"
if find . -name '*.php' -not -path './vendor/*' -not -path './dist/*' -print0 \
        | xargs -0 -r -n1 -P4 php -l >/tmp/ci-smoke-lint-fail.log 2>&1; then
    fail "lint: Datei mit Syntaxfehler -> erwarteter Exit != 0, aber Exit 0 erhalten"
else
    if grep -q "broken-syntax.smoketest.php" /tmp/ci-smoke-lint-fail.log; then
        pass "lint: Datei mit Syntaxfehler -> Exit != 0, Pfad in Log genannt"
    else
        fail "lint: Datei mit Syntaxfehler -> Exit != 0, aber Pfad NICHT im Log gefunden"
    fi
fi
rm -f "api/broken-syntax.smoketest.php" /tmp/ci-smoke-lint-fail.log

# Szenario: vendor/ und dist/ werden nicht geprüft
mkdir -p vendor dist
printf '<?php this is not valid php {{{ \n' > "vendor/broken-syntax.smoketest.php"
printf '<?php this is not valid php {{{ \n' > "dist/broken-syntax.smoketest.php"
if find . -name '*.php' -not -path './vendor/*' -not -path './dist/*' -print0 \
        | xargs -0 -r -n1 -P4 php -l >/tmp/ci-smoke-lint-excl.log 2>&1; then
    pass "lint: vendor/ und dist/ werden ausgeschlossen (kaputte Datei dort ignoriert)"
else
    fail "lint: vendor/ oder dist/ wurden fälschlich mitgeprüft"
fi
rm -f "vendor/broken-syntax.smoketest.php" "dist/broken-syntax.smoketest.php" /tmp/ci-smoke-lint-excl.log
rmdir dist 2>/dev/null || rm -rf dist

echo ""
echo "== Requirement: .gitignore / composer.lock =="

if git check-ignore -q composer.lock; then
    fail "composer.lock ist weiterhin von .gitignore erfasst"
else
    pass "composer.lock ist NICHT (mehr) von .gitignore erfasst"
fi

if git check-ignore -q vendor/does-not-need-to-exist; then
    pass "vendor/ ist weiterhin von .gitignore erfasst"
else
    fail "vendor/ ist NICHT mehr von .gitignore erfasst (Regression)"
fi

if git ls-files --error-unmatch composer.lock >/dev/null 2>&1; then
    pass "composer.lock ist als Datei im Git-Index versioniert"
else
    fail "composer.lock ist NICHT im Git-Index versioniert"
fi

echo ""
echo "== Requirement: Build-Smoke-Test über build.sh =="

# Szenario: build.sh erzeugt ein vollständiges Paket
rm -rf dist
if bash build.sh >/tmp/ci-smoke-build-full.log 2>&1; then
    ALL_PRESENT=1
    for f in api/auth.php frontend/index.html wizard/index.php vendor/autoload.php; do
        [[ -f "dist/dog-mentality-test/$f" ]] || ALL_PRESENT=0
    done
    if [[ "$ALL_PRESENT" -eq 1 ]]; then
        pass "build.sh: vollständiger Baum -> Exit 0, alle vier Kern-Dateien vorhanden"
    else
        fail "build.sh: vollständiger Baum -> Exit 0, aber mind. eine Kern-Datei fehlt in dist/"
    fi
else
    fail "build.sh: vollständiger Baum -> erwartete Exit 0, siehe /tmp/ci-smoke-build-full.log"
fi

# Szenario: keine wörtlichen \033[-Sequenzen in der Ausgabe
if grep -qF '\033[' /tmp/ci-smoke-build-full.log; then
    fail "build.sh: Ausgabe enthält wörtliche \\033[-Zeichenfolgen"
else
    pass "build.sh: Ausgabe enthält KEINE wörtlichen \\033[-Zeichenfolgen"
fi
rm -f /tmp/ci-smoke-build-full.log
rm -rf dist

# Szenario: Kern-Datei fehlt -> build.sh bricht mit Exit != 0 ab
for core in "api/auth.php" "frontend/index.html" "wizard/index.php"; do
    mv "$core" "${core}.smoketest-bak"
    rm -rf dist
    if bash build.sh >/tmp/ci-smoke-build-missing.log 2>&1; then
        fail "build.sh: fehlende Kern-Datei $core -> erwarteter Exit != 0, aber Exit 0 erhalten"
    else
        pass "build.sh: fehlende Kern-Datei $core -> Exit != 0"
    fi
    mv "${core}.smoketest-bak" "$core"
    rm -f /tmp/ci-smoke-build-missing.log
done
rm -rf dist

echo ""
echo "== Requirement: CI-Workflow-Datei (statische Prüfung) =="

WF=".github/workflows/ci.yml"
if [[ -f "$WF" ]] && grep -qx "name: CI" "$WF"; then
    pass "ci.yml enthält exakt 'name: CI'"
else
    fail "ci.yml fehlt oder enthält NICHT 'name: CI'"
fi

if grep -q "^  push:" "$WF" && grep -A2 "^  push:" "$WF" | grep -q 'branches: \["\*\*"\]' \
        && grep -qx "  pull_request:" "$WF"; then
    pass "ci.yml definiert Trigger push (alle Branches) UND pull_request"
else
    fail "ci.yml Trigger-Konfiguration weicht von der Spec ab (push/pull_request)"
fi

if grep -q "^  lint:" "$WF" && grep -q "^  composer:" "$WF" && grep -q "^  build:" "$WF"; then
    pass "ci.yml definiert die drei Jobs lint, composer, build"
else
    fail "ci.yml: mindestens einer der Jobs lint/composer/build fehlt"
fi

if grep -q "needs: \[composer\]" "$WF"; then
    pass "ci.yml: build-Job hängt via needs: [composer] vom composer-Job ab"
else
    fail "ci.yml: build-Job hat keine needs: [composer]-Abhängigkeit"
fi

if grep -q "actions/upload-artifact@v4" "$WF"; then
    pass "ci.yml: build-Job lädt ein Artefakt hoch (actions/upload-artifact@v4)"
else
    fail "ci.yml: kein actions/upload-artifact-Schritt im build-Job gefunden"
fi

echo ""
echo "== Requirement: composer.json Pflichtfelder =="

for field in '"name"' '"description"' '"license"' '"type"'; do
    if grep -q "$field" composer.json; then
        pass "composer.json enthält Feld $field"
    else
        fail "composer.json: Feld $field fehlt"
    fi
done

if grep -A2 '"platform"' composer.json | grep -q '"php": "8.0"'; then
    pass "composer.json: config.platform.php ist weiterhin \"8.0\""
else
    fail "composer.json: config.platform.php ist NICHT (mehr) \"8.0\""
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
