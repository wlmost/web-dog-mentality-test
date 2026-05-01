# Hosting bei netbeat - Wichtige Hinweise

## Trigger werden nicht unterstützt

Netbeat (und viele andere Shared-Hosting-Provider) unterstützen keine MySQL-Trigger aus Sicherheitsgründen.

**Lösung:** Die Anwendung funktioniert **ohne Trigger einwandfrei**!

Die Validierung ist bereits in der PHP-Anwendungslogik implementiert:
- `api/dogs.php` validiert Alter (0-20 Jahre, 0-11 Monate)
- `api/dogs.php` prüft Mindestalter (mindestens 1 Monat)

## Installation

### 1. Datenbank importieren

**Nur diese Dateien importieren:**
```bash
# Hauptschema
mysql -u username -p datenbankname < database/schema.sql

# Auth-System (falls gewünscht)
mysql -u username -p datenbankname < database/schema-auth.sql
```

**NICHT importieren:**
- ❌ `database/triggers.sql` - Wird bei netbeat nicht funktionieren

### 2. Migrationen (falls bestehende Datenbank)

```bash
# Avatar-Spalte hinzufügen
mysql -u username -p datenbankname < database/migration-avatar.sql

# Session User-Zuordnung
mysql -u username -p datenbankname < database/migration-session-user.sql
```

## Fehlende Rechte

Falls Sie Fehlermeldungen wie diese erhalten:
```
ERROR 1419: You do not have the SUPER privilege
ERROR 1227: Access denied; you need SUPER privilege
```

**Das ist normal bei netbeat!** Diese Rechte werden nicht vergeben.

**Lösung:** Ignorieren Sie `triggers.sql` - Die Anwendung funktioniert ohne!

## Was funktioniert

✅ Alle Tabellen (dogs, test_sessions, test_results, etc.)  
✅ Views (v_session_overview, v_ocean_scores)  
✅ Foreign Keys  
✅ Indexes  
✅ Auth-System (auth_users, auth_sessions, auth_logs)  
✅ Profilverwaltung  
✅ Session User-Zuordnung  
✅ **Alle Validierungen** (werden in PHP durchgeführt)

## Was nicht funktioniert

❌ Trigger (nicht benötigt, da Validierung in PHP erfolgt)  
❌ Stored Procedures (nicht verwendet)  
❌ Events (nicht verwendet)

## FTP-Deployment

Für netbeat-Hosting siehe: [FTP_DEPLOYMENT.md](FTP_DEPLOYMENT.md)

## Test nach Installation

1. **Schema importieren** (ohne triggers.sql)
2. **Hund erstellen** via API
3. **Ungültiges Alter eingeben** (z.B. 25 Jahre)
4. **Fehler erwarten**: "Alter (Jahre) muss zwischen 0 und 20 liegen"

→ Die Validierung funktioniert in PHP!

## Support

Die Anwendung ist vollständig kompatibel mit netbeat-Hosting ohne jegliche Einschränkungen!
