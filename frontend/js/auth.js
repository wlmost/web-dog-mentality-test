/**
 * Authentication & 2FA Client
 */

class AuthAPI {
    constructor() {
        // Automatische Protokoll-Erkennung (HTTPS-kompatibel)
        const protocol = window.location.protocol;
        const host = window.location.host;
        const basePath = window.location.pathname.replace(/\/frontend.*$/, '');
        this.baseUrl = `${protocol}//${host}${basePath}/api/auth.php`;
        this.sessionToken = localStorage.getItem('session_token');
        this.tempData = null;
    }
    
    async login(username, password) {
        const response = await fetch(this.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'login',
                username,
                password
            })
        });
        
        return await this.parseResponse(response);
    }
    
    async verify2FA(userId, code) {
        const response = await fetch(this.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'verify_2fa',
                user_id: userId,
                code
            })
        });
        
        return await this.parseResponse(response);
    }
    
    async setup2FA() {
        const response = await fetch(this.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'setup_2fa',
                session_token: this.sessionToken
            })
        });
        
        return await this.parseResponse(response);
    }
    
    async verifySession() {
        const response = await fetch(this.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'verify_session',
                session_token: this.sessionToken
            })
        });
        
        return await this.parseResponse(response);
    }
    
    async logout() {
        const response = await fetch(this.baseUrl, {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${this.sessionToken}`
            }
        });
        
        localStorage.removeItem('session_token');
        this.sessionToken = null;
        
        return await this.parseResponse(response);
    }
    
    saveSession(token) {
        this.sessionToken = token;
        localStorage.setItem('session_token', token);
    }
    
    async parseResponse(response) {
        // Prüfe Content-Type
        const contentType = response.headers.get('content-type');
        
        if (!contentType || !contentType.includes('application/json')) {
            // Nicht-JSON Antwort (wahrscheinlich HTML-Fehlerseite)
            const text = await response.text();
            console.error('Server returned non-JSON response:', text.substring(0, 500));
            throw new Error(`Server-Fehler: Keine JSON-Antwort erhalten (Content-Type: ${contentType || 'undefined'})`);
        }
        
        // Leerer Body?
        const text = await response.text();
        if (!text || text.trim() === '') {
            console.error('Server returned empty response');
            throw new Error('Server-Fehler: Leere Antwort erhalten');
        }
        
        // JSON parsen
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Response text:', text.substring(0, 500));
            throw new Error(`Server-Fehler: Ungültiges JSON (${e.message})`);
        }
    }
}

const auth = new AuthAPI();

// ===================================================================
// Login Form
// ===================================================================
document.getElementById('loginFormElement')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    
    try {
        const result = await auth.login(username, password);
        
        if (!result.success) {
            showAlert('loginAlert', result.error, 'danger');
            return;
        }
        
        if (result.requires_2fa) {
            // 2FA erforderlich
            auth.tempData = {
                userId: result.user_id,
                tempToken: result.temp_token
            };
            
            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('twoFAForm').style.display = 'block';
            
            // Auto-focus auf Code-Feld
            setTimeout(() => {
                document.getElementById('totpCode').focus();
            }, 100);
        } else {
            // Kein 2FA - direkt einloggen
            auth.saveSession(result.session_token);
            window.location.href = 'index.html';
        }
        
    } catch (error) {
        showAlert('loginAlert', 'Verbindungsfehler: ' + error.message, 'danger');
    }
});

// ===================================================================
// 2FA Verification Form
// ===================================================================
document.getElementById('twoFAFormElement')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const code = document.getElementById('totpCode').value;
    
    if (code.length !== 6) {
        showAlert('twoFAAlert', 'Code muss 6 Ziffern haben', 'warning');
        return;
    }
    
    try {
        const result = await auth.verify2FA(auth.tempData.userId, code);
        
        if (!result.success) {
            showAlert('twoFAAlert', result.error, 'danger');
            document.getElementById('totpCode').value = '';
            document.getElementById('totpCode').focus();
            return;
        }
        
        auth.saveSession(result.session_token);
        window.location.href = 'index.html';
        
    } catch (error) {
        showAlert('twoFAAlert', 'Verbindungsfehler: ' + error.message, 'danger');
    }
});

// Auto-submit bei 6 Ziffern
document.getElementById('totpCode')?.addEventListener('input', (e) => {
    const value = e.target.value.replace(/\D/g, '');
    e.target.value = value;
    
    if (value.length === 6) {
        setTimeout(() => {
            document.getElementById('twoFAFormElement').dispatchEvent(new Event('submit'));
        }, 300);
    }
});

// Backup Code verwenden
document.getElementById('useBackupCode')?.addEventListener('click', () => {
    const code = prompt('Gib einen deiner 8-stelligen Backup-Codes ein:');
    if (code && code.length === 8) {
        document.getElementById('totpCode').value = code;
        document.getElementById('twoFAFormElement').dispatchEvent(new Event('submit'));
    }
});

// Zurück zum Login
document.getElementById('cancelTwoFA')?.addEventListener('click', () => {
    document.getElementById('twoFAForm').style.display = 'none';
    document.getElementById('loginForm').style.display = 'block';
    document.getElementById('totpCode').value = '';
    auth.tempData = null;
});

// ===================================================================
// 2FA Setup (nur in Settings, aber Code hier für Vollständigkeit)
// ===================================================================
window.setup2FA = async function() {
    try {
        const result = await auth.setup2FA();
        
        if (!result.success) {
            showAlert('setupAlert', result.error, 'danger');
            return;
        }
        
        // QR Code anzeigen
        const qrcodeContainer = document.getElementById('qrcode');
        qrcodeContainer.innerHTML = '';
        
        new QRCode(qrcodeContainer, {
            text: result.qr_url,
            width: 250,
            height: 250,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        
        // Manuellen Code anzeigen
        document.getElementById('manualSecret').value = result.secret;
        
        // Backup Codes anzeigen
        const backupCodesDiv = document.getElementById('backupCodes');
        backupCodesDiv.innerHTML = result.backup_codes
            .map(code => `<div class="backup-code">${code}</div>`)
            .join('');
        
        // Backup Codes zum Download vorbereiten
        window.backupCodesData = result.backup_codes;
        
        // UI wechseln
        document.getElementById('loginForm').style.display = 'none';
        document.getElementById('setupTwoFA').style.display = 'block';
        
    } catch (error) {
        showAlert('setupAlert', 'Fehler beim Einrichten: ' + error.message, 'danger');
    }
};

// Secret kopieren
document.getElementById('copySecret')?.addEventListener('click', () => {
    const input = document.getElementById('manualSecret');
    input.select();
    document.execCommand('copy');
    
    const btn = document.getElementById('copySecret');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i>';
    setTimeout(() => {
        btn.innerHTML = originalHTML;
    }, 2000);
});

// Backup Codes herunterladen
document.getElementById('downloadBackupCodes')?.addEventListener('click', () => {
    const codes = window.backupCodesData || [];
    const text = '=== Dog Mentality Test - Backup Codes ===\n\n' +
                 'WICHTIG: Bewahre diese Codes sicher auf!\n' +
                 'Jeder Code kann nur EINMAL verwendet werden.\n\n' +
                 codes.join('\n') +
                 '\n\nErstellt am: ' + new Date().toLocaleString('de-DE');
    
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'backup-codes-' + Date.now() + '.txt';
    a.click();
    URL.revokeObjectURL(url);
});

// Setup abschließen
document.getElementById('finishSetup')?.addEventListener('click', () => {
    alert('2FA wurde erfolgreich eingerichtet!\n\nBenutze ab jetzt deine Authenticator-App beim Login.');
    window.location.href = 'index.html';
});

// ===================================================================
// Session Check (für index.html)
// ===================================================================
window.checkAuth = async function() {
    const token = localStorage.getItem('session_token');
    
    if (!token) {
        window.location.href = 'login.html';
        return false;
    }
    
    try {
        const result = await auth.verifySession();
        
        if (!result.success || !result.valid) {
            localStorage.removeItem('session_token');
            window.location.href = 'login.html';
            return false;
        }
        
        return true;
        
    } catch (error) {
        console.error('Session check failed:', error);
        window.location.href = 'login.html';
        return false;
    }
};

window.logout = async function() {
    if (confirm('Wirklich abmelden?')) {
        await auth.logout();
        window.location.href = 'login.html';
    }
};

// ===================================================================
// Hilfsfunktionen
// ===================================================================
function showAlert(elementId, message, type = 'info') {
    const alertDiv = document.getElementById(elementId);
    if (!alertDiv) return;
    
    alertDiv.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
}
