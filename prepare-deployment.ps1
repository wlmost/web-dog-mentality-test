# Deployment-Vorbereitung für FTP-Upload
# Dieses Script bereitet das Projekt für FTP-Deployment vor

Write-Host "=== Dog Mentality Test - FTP Deployment Vorbereitung ===" -ForegroundColor Green
Write-Host ""

# Aktuelles Verzeichnis prüfen
$currentDir = Get-Location
Write-Host "Aktuelles Verzeichnis: $currentDir" -ForegroundColor Cyan

if (!(Test-Path "api") -or !(Test-Path "frontend")) {
    Write-Host "FEHLER: Bitte das Script im Projekt-Root ausführen!" -ForegroundColor Red
    Write-Host "Erwartet: web-dog-mentality-test/" -ForegroundColor Red
    exit 1
}

# 1. Composer-Abhängigkeiten installieren
Write-Host ""
Write-Host "[1/5] Composer-Abhängigkeiten installieren..." -ForegroundColor Yellow

if (!(Get-Command composer -ErrorAction SilentlyContinue)) {
    Write-Host "WARNUNG: Composer nicht gefunden!" -ForegroundColor Red
    Write-Host "Bitte Composer installieren: https://getcomposer.org/download/" -ForegroundColor Yellow
    $continue = Read-Host "Ohne Composer fortfahren? (Excel-Import wird nicht funktionieren) [j/n]"
    if ($continue -ne "j") {
        exit 1
    }
} else {
    Write-Host "Composer gefunden: " -NoNewline
    composer --version
    
    Write-Host "Installiere PhpSpreadsheet..." -ForegroundColor Cyan
    composer install --no-dev --optimize-autoloader
    
    if (Test-Path "vendor/phpoffice") {
        Write-Host "✓ PhpSpreadsheet installiert" -ForegroundColor Green
    } else {
        Write-Host "✗ Installation fehlgeschlagen" -ForegroundColor Red
        exit 1
    }
}

# 2. .env Datei erstellen
Write-Host ""
Write-Host "[2/5] .env Datei erstellen..." -ForegroundColor Yellow

if (Test-Path ".env") {
    Write-Host "WARNUNG: .env existiert bereits" -ForegroundColor Yellow
    $overwrite = Read-Host "Überschreiben? [j/n]"
    if ($overwrite -ne "j") {
        Write-Host "Überspringe .env Erstellung" -ForegroundColor Cyan
    } else {
        Copy-Item ".env.example" ".env" -Force
        Write-Host "✓ .env erstellt (bitte anpassen!)" -ForegroundColor Green
    }
} else {
    Copy-Item ".env.example" ".env"
    Write-Host "✓ .env erstellt" -ForegroundColor Green
    Write-Host "  WICHTIG: Bitte .env mit echten Zugangsdaten anpassen!" -ForegroundColor Yellow
}

# 3. Logs- und Uploads-Verzeichnisse erstellen
Write-Host ""
Write-Host "[3/5] Verzeichnisse erstellen..." -ForegroundColor Yellow

if (!(Test-Path "logs")) {
    New-Item -ItemType Directory -Path "logs" | Out-Null
    Write-Host "✓ logs/ erstellt" -ForegroundColor Green
}

if (!(Test-Path "uploads")) {
    New-Item -ItemType Directory -Path "uploads" | Out-Null
    Write-Host "✓ uploads/ erstellt" -ForegroundColor Green
}

# .htaccess erstellen
if (!(Test-Path ".htaccess")) {
    $htaccess = @"
# .htaccess für Shared Webhosting

# PHP-Settings (falls erlaubt - bei Fehler 500 auskommentieren!)
<IfModule mod_php.c>
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value memory_limit 256M
    php_value max_execution_time 120
</IfModule>

# Security
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

# Logging
php_flag display_errors Off
php_flag log_errors On
php_value error_log logs/php_error.log
"@
    $htaccess | Out-File -FilePath ".htaccess" -Encoding UTF8
    Write-Host "✓ .htaccess erstellt" -ForegroundColor Green
}

# 4. Statistiken anzeigen
Write-Host ""
Write-Host "[4/5] Deployment-Statistiken..." -ForegroundColor Yellow

$files = Get-ChildItem -Recurse -File | Where-Object { $_.FullName -notmatch '\.git' }
$totalSize = ($files | Measure-Object -Property Length -Sum).Sum
$totalCount = $files.Count

$vendorSize = 0
if (Test-Path "vendor") {
    $vendorFiles = Get-ChildItem -Path "vendor" -Recurse -File
    $vendorSize = ($vendorFiles | Measure-Object -Property Length -Sum).Sum
}

Write-Host "  Gesamtdateien: $totalCount" -ForegroundColor Cyan
Write-Host "  Gesamtgröße: $([math]::Round($totalSize/1MB, 2)) MB" -ForegroundColor Cyan
if ($vendorSize -gt 0) {
    Write-Host "  vendor/ Größe: $([math]::Round($vendorSize/1MB, 2)) MB" -ForegroundColor Cyan
    Write-Host "  (Dies kann 5-15 Minuten Upload-Zeit bedeuten)" -ForegroundColor Yellow
}

# 5. ZIP für schnelleren Upload erstellen (optional)
Write-Host ""
Write-Host "[5/5] ZIP-Archive erstellen (optional)..." -ForegroundColor Yellow
$createZip = Read-Host "Möchten Sie ZIP-Archive für schnelleren Upload erstellen? [j/n]"

if ($createZip -eq "j") {
    Write-Host "Erstelle vendor.zip..." -ForegroundColor Cyan
    if (Test-Path "vendor") {
        Compress-Archive -Path "vendor" -DestinationPath "vendor.zip" -Force
        $zipSize = (Get-Item "vendor.zip").Length
        Write-Host "✓ vendor.zip erstellt ($([math]::Round($zipSize/1MB, 2)) MB)" -ForegroundColor Green
        Write-Host "  Tipp: vendor.zip hochladen und auf Server entpacken (File Manager)" -ForegroundColor Cyan
    }
    
    Write-Host "Erstelle api.zip..." -ForegroundColor Cyan
    Compress-Archive -Path "api" -DestinationPath "api.zip" -Force
    Write-Host "✓ api.zip erstellt" -ForegroundColor Green
    
    Write-Host "Erstelle frontend.zip..." -ForegroundColor Cyan
    Compress-Archive -Path "frontend" -DestinationPath "frontend.zip" -Force
    Write-Host "✓ frontend.zip erstellt" -ForegroundColor Green
}

# Zusammenfassung
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "DEPLOYMENT-VORBEREITUNG ABGESCHLOSSEN!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Nächste Schritte:" -ForegroundColor Yellow
Write-Host "1. .env Datei mit echten DB-Zugangsdaten anpassen" -ForegroundColor White
Write-Host "2. FTP-Client öffnen (FileZilla, WinSCP, etc.)" -ForegroundColor White
Write-Host "3. Folgende Dateien/Ordner hochladen:" -ForegroundColor White
Write-Host "   - api/" -ForegroundColor Cyan
Write-Host "   - frontend/" -ForegroundColor Cyan
Write-Host "   - vendor/ (WICHTIG! ~$([math]::Round($vendorSize/1MB, 2)) MB)" -ForegroundColor Cyan
Write-Host "   - .env" -ForegroundColor Cyan
Write-Host "   - .htaccess" -ForegroundColor Cyan
Write-Host "   - logs/ (leerer Ordner)" -ForegroundColor Cyan
Write-Host "   - uploads/ (leerer Ordner)" -ForegroundColor Cyan
Write-Host "4. Berechtigungen setzen: logs/ und uploads/ → 755" -ForegroundColor White
Write-Host "5. Datenbank in phpMyAdmin importieren (database/schema.sql)" -ForegroundColor White
Write-Host "6. Testen: https://deine-domain.de/frontend/" -ForegroundColor White
Write-Host ""
Write-Host "Details: Siehe FTP_DEPLOYMENT.md" -ForegroundColor Yellow
Write-Host ""

# .env Erinnerung
if (Test-Path ".env") {
    Write-Host "ERINNERUNG: .env Datei anpassen!" -ForegroundColor Red
    $openEnv = Read-Host "Möchten Sie .env jetzt bearbeiten? [j/n]"
    if ($openEnv -eq "j") {
        notepad .env
    }
}

Write-Host ""
Write-Host "Viel Erfolg beim Deployment! 🚀" -ForegroundColor Green
