#!/usr/bin/env bash
# =============================================================================
# Build-Script: Dog Mentality Test – FTP-Deployment vorbereiten
# Erstellt dist/dog-mentality-test/ mit allen produktionsrelevanten Dateien.
# Ausführen aus dem Projekt-Root:  bash build.sh
# =============================================================================
set -euo pipefail

DIST="dist/dog-mentality-test"

# ---------------------------------------------------------------------------
# Hilfsfunktionen
# ---------------------------------------------------------------------------
info()    { printf '  \033[36m%s\033[0m\n' "$*"; }
ok()      { printf '  \033[32m✓ %s\033[0m\n' "$*"; }
warn()    { printf '  \033[33m⚠ %s\033[0m\n' "$*"; }
section() { echo ""; printf '\033[1;34m[%s]\033[0m\n' "$*"; }
error()   { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Verzeichnis-Prüfung
# ---------------------------------------------------------------------------
[[ -d "api" && -d "frontend" && -d "wizard" ]] \
  || error "Bitte das Script im Projekt-Root ausführen (web-dog-mentality-test/)."

# ---------------------------------------------------------------------------
# 1. dist/ bereinigen und anlegen
# ---------------------------------------------------------------------------
section "1/5 – dist/ vorbereiten"

if [[ -d "$DIST" ]]; then
    rm -rf "$DIST"
    info "Altes dist/ gelöscht."
fi
mkdir -p "$DIST"
ok "dist/dog-mentality-test/ angelegt"

# ---------------------------------------------------------------------------
# 2. Composer (vendor/) – no-dev Install
# ---------------------------------------------------------------------------
section "2/5 – Composer-Abhängigkeiten"

if command -v composer &>/dev/null; then
    info "Installiere Abhängigkeiten (--no-dev) …"
    composer install --no-dev --optimize-autoloader --quiet
    ok "vendor/ aktuell"
elif [[ -d "vendor" ]]; then
    warn "Composer nicht gefunden – vorhandenes vendor/ wird übernommen."
else
    warn "Composer nicht gefunden und kein vendor/ vorhanden."
    warn "Excel-Import wird auf dem Server nicht funktionieren!"
fi

# ---------------------------------------------------------------------------
# 3. API-Dateien (nur Produktions-PHP, keine Debug/Test-Dateien)
# ---------------------------------------------------------------------------
section "3/5 – API, Frontend, Wizard, Datenbank"

mkdir -p "$DIST/api"

API_PRODUCTION=(
    auth.php
    batteries.php
    config.php
    config.local.php.example
    dogs.php
    import.php
    import-csv.php
    ocean.php
    profile.php
    results.php
    sessions.php
    users.php
    ai.php
)

for f in "${API_PRODUCTION[@]}"; do
    if [[ -f "api/$f" ]]; then
        cp "api/$f" "$DIST/api/$f"
    else
        warn "Nicht gefunden: api/$f"
    fi
done
[[ -f "$DIST/api/auth.php" ]] || error "Kern-Datei fehlt: api/auth.php"
ok "api/ (${#API_PRODUCTION[@]} Dateien, ohne Debug/Test)"

# ---------------------------------------------------------------------------
# Frontend (ohne Dev-Seiten und Docs)
# ---------------------------------------------------------------------------
mkdir -p "$DIST/frontend"
rsync -a \
    --exclude="*.md" \
    --exclude="test-api.html" \
    --exclude="test-token.html" \
    frontend/ "$DIST/frontend/"
[[ -f "$DIST/frontend/index.html" ]] || error "Kern-Datei fehlt: frontend/index.html"
ok "frontend/ (ohne Test-Seiten)"

# ---------------------------------------------------------------------------
# Wizard
# ---------------------------------------------------------------------------
cp -r wizard "$DIST/wizard"
[[ -f "$DIST/wizard/index.php" ]] || error "Kern-Datei fehlt: wizard/index.php"
# Lock-Datei mit Placeholder-Inhalt deployen (muss existieren, damit der Wizard
# nach erfolgreicher Installation gesperrt werden kann)
echo -n 'inactive' > "$DIST/wizard/.lock"
ok "wizard/"

# ---------------------------------------------------------------------------
# Datenbank-Schemas und Migrations
# ---------------------------------------------------------------------------
mkdir -p "$DIST/database/migrations"

DB_SCHEMAS=(
    schema.sql
    schema-netbeat.sql
    schema-auth.sql
)

for f in "${DB_SCHEMAS[@]}"; do
    if [[ -f "database/$f" ]]; then
        cp "database/$f" "$DIST/database/$f"
    else
        warn "Nicht gefunden: database/$f"
    fi
done

# Migrations-Dateien
cp database/migrations/*.sql "$DIST/database/migrations/"
ok "database/ (Schemas + Migrations)"

# ---------------------------------------------------------------------------
# Migration-Runner (Allowlist – migrate-selftest.php und README.md bleiben
# bewusst außen vor)
# ---------------------------------------------------------------------------
mkdir -p "$DIST/scripts"
[[ -f "scripts/MigrationRunner.php" ]] || error "Kern-Datei fehlt: scripts/MigrationRunner.php"
[[ -f "scripts/migrate.php" ]] || error "Kern-Datei fehlt: scripts/migrate.php"
cp scripts/MigrationRunner.php scripts/migrate.php "$DIST/scripts/"
ok "scripts/ (MigrationRunner.php, migrate.php)"

# ---------------------------------------------------------------------------
# uploads/ (mit .htaccess, ohne echte Uploads)
# ---------------------------------------------------------------------------
mkdir -p "$DIST/uploads/avatars"
[[ -f "uploads/avatars/.htaccess" ]] && cp "uploads/avatars/.htaccess" "$DIST/uploads/avatars/.htaccess"
touch "$DIST/uploads/avatars/.gitkeep"
ok "uploads/avatars/ (.htaccess + .gitkeep)"

# ---------------------------------------------------------------------------
# logs/ anlegen (muss schreibbar sein, kein Inhalt)
# ---------------------------------------------------------------------------
mkdir -p "$DIST/logs"
touch "$DIST/logs/.gitkeep"
ok "logs/ angelegt"

# ---------------------------------------------------------------------------
# Root-Dateien
# ---------------------------------------------------------------------------
[[ -f ".htaccess"    ]] && cp ".htaccess"    "$DIST/.htaccess"
[[ -f "index.php"   ]] && cp "index.php"    "$DIST/index.php"
[[ -f "php.ini.example" ]] && cp "php.ini.example" "$DIST/php.ini.example"
cp "composer.json" "$DIST/composer.json"
ok ".htaccess, index.php, php.ini.example, composer.json"

# ---------------------------------------------------------------------------
# vendor/ kopieren (nach composer install)
# ---------------------------------------------------------------------------
if [[ -d "vendor" ]]; then
    info "Kopiere vendor/ (kann einen Moment dauern) …"
    cp -r vendor "$DIST/vendor"
    ok "vendor/ übernommen"
fi

# ---------------------------------------------------------------------------
# 4. Hinweis-Datei: nächste Schritte
# ---------------------------------------------------------------------------
section "4/5 – Hinweise ins dist/ schreiben"

cat > "$DIST/DEPLOY_CHECKLIST.txt" << 'EOF'
Dog Mentality Test – Deployment-Checkliste
==========================================

1. Dieses Verzeichnis per FTP vollständig auf den Server übertragen.

2. WICHTIG – Wizard ausführen:
   https://deine-domain.de/wizard/
   → Datenbankverbindung konfigurieren
   → Tabellenpräfix festlegen
   → Tabellen erstellen + Migrations einspielen
   → Admin-Benutzer anlegen
   → Installation abschließen (erzeugt api/config.local.php)

3. KI-Features aktivieren (optional):
   In api/config.local.php den OpenAI API-Key eintragen:
     define('OPENAI_API_KEY', 'sk-...');
   Key erstellen: https://platform.openai.com/api-keys

4. Nach der Installation:
   - /wizard/-Verzeichnis per FTP SOFORT löschen!
   - /database/-Verzeichnis per FTP löschen (nicht öffentlich zugänglich lassen)

5. php.ini.example → php.ini umbenennen falls nötig.

6. Schreibrechte prüfen (chmod 755):
   - uploads/avatars/
   - logs/
EOF
ok "DEPLOY_CHECKLIST.txt erstellt"

# ---------------------------------------------------------------------------
# 5. Zusammenfassung
# ---------------------------------------------------------------------------
section "5/5 – Ergebnis"

FILECOUNT=$(find "$DIST" -type f | wc -l | tr -d ' ')
DIRSIZE=$(du -sh "$DIST" 2>/dev/null | cut -f1)

echo ""
printf '  \033[32mBereit für FTP-Upload:\033[0m\n'
echo "  Verzeichnis : $DIST/"
echo "  Dateien     : $FILECOUNT"
echo "  Größe       : $DIRSIZE"
echo ""
echo "  Nächste Schritte:"
printf '  1. \033[1mdist/dog-mentality-test/\033[0m per FTP übertragen\n'
echo "  2. Wizard aufrufen: https://deine-domain.de/wizard/"
echo "  3. Nach der Installation: /wizard/ per FTP löschen!"
echo ""
