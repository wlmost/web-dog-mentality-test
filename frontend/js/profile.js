/**
 * Profile Management Client
 * API-Client für Profilverwaltung
 */

class ProfileAPI {
    constructor() {
        // Automatische Protokoll-Erkennung (HTTPS-kompatibel)
        const protocol = window.location.protocol;
        const host = window.location.host;
        const basePath = window.location.pathname.replace(/\/frontend.*$/, '');
        this.baseUrl = `${protocol}//${host}${basePath}/api/profile.php`;
        console.log('Profile API URL:', this.baseUrl);
    }
    
    /**
     * Profil abrufen
     */
    async getProfile() {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        if (!response.ok) {
            throw new Error('Failed to load profile');
        }
        
        return await response.json();
    }
    
    /**
     * Profil-Informationen aktualisieren
     */
    async updateInfo(email, fullName) {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                action: 'update_info',
                email: email,
                full_name: fullName
            })
        });
        
        return await response.json();
    }
    
    /**
     * Passwort ändern
     */
    async changePassword(currentPassword, newPassword) {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                action: 'change_password',
                current_password: currentPassword,
                new_password: newPassword
            })
        });
        
        return await response.json();
    }
    
    /**
     * Avatar hochladen
     */
    async uploadAvatar(file) {
        const token = localStorage.getItem('session_token');
        const formData = new FormData();
        formData.append('avatar', file);
        
        const response = await fetch(this.baseUrl, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`
            },
            body: formData
        });
        
        return await response.json();
    }
    
    /**
     * Avatar löschen
     */
    async deleteAvatar() {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                action: 'delete_avatar'
            })
        });
        
        return await response.json();
    }
}

// Globale Instanz
const profileAPI = new ProfileAPI();

// ===================================================================
// Profil laden und anzeigen
// ===================================================================
async function loadProfile() {
    try {
        const result = await profileAPI.getProfile();
        
        if (!result.success) {
            showAlert('profileAlert', result.error, 'danger');
            return;
        }
        
        const profile = result.profile;
        
        // Avatar
        const avatarUrl = profile.avatar 
            ? `../${profile.avatar}?t=${Date.now()}`
            : '';
        document.getElementById('avatarPreview').src = avatarUrl;
        
        // Header
        document.getElementById('profileUsername').textContent = profile.username;
        document.getElementById('profileEmail').textContent = profile.email || 'Keine E-Mail hinterlegt';
        
        // Formular-Felder
        document.getElementById('inputEmail').value = profile.email || '';
        document.getElementById('inputFullName').value = profile.full_name || '';
        
        // Konto-Informationen
        document.getElementById('infoUsername').textContent = profile.username;
        
        const info2FABadge = profile.totp_enabled 
            ? '<span class="badge bg-success"><i class="bi bi-shield-check"></i> Aktiviert</span>'
            : '<span class="badge bg-secondary"><i class="bi bi-shield-x"></i> Deaktiviert</span>';
        document.getElementById('info2FA').innerHTML = info2FABadge;
        
        // 2FA Toggle Button
        const btn2FA = document.getElementById('btn2FAToggle');
        const btn2FAText = document.getElementById('btn2FAText');
        btn2FA.style.display = 'inline-block';
        
        if (profile.totp_enabled) {
            btn2FA.className = 'btn btn-sm btn-outline-danger ms-2';
            btn2FAText.innerHTML = '<i class="bi bi-shield-x"></i> 2FA deaktivieren';
            btn2FA.onclick = () => new bootstrap.Modal(document.getElementById('modal2FADisable')).show();
        } else {
            btn2FA.className = 'btn btn-sm btn-outline-primary ms-2';
            btn2FAText.innerHTML = '<i class="bi bi-shield-check"></i> 2FA einrichten';
            btn2FA.onclick = () => start2FASetup();
        }
        
        document.getElementById('infoLastLogin').textContent = profile.last_login 
            ? new Date(profile.last_login).toLocaleString('de-DE')
            : 'Nie';
        document.getElementById('infoCreated').textContent = new Date(profile.created_at).toLocaleString('de-DE');
        
    } catch (error) {
        console.error('Error loading profile:', error);
        showAlert('profileAlert', 'Fehler beim Laden des Profils', 'danger');
    }
}

// ===================================================================
// Informationen aktualisieren
// ===================================================================
document.getElementById('formUpdateInfo')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const email = document.getElementById('inputEmail').value.trim();
    const fullName = document.getElementById('inputFullName').value.trim();
    
    try {
        const result = await profileAPI.updateInfo(email, fullName);
        
        if (result.success) {
            showAlert('profileAlert', result.message, 'success');
            await loadProfile(); // Profil neu laden
        } else {
            showAlert('profileAlert', result.error, 'danger');
        }
    } catch (error) {
        console.error('Error updating profile:', error);
        showAlert('profileAlert', 'Fehler beim Speichern', 'danger');
    }
});

// ===================================================================
// Passwort ändern
// ===================================================================
document.getElementById('formChangePassword')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const currentPassword = document.getElementById('inputCurrentPassword').value;
    const newPassword = document.getElementById('inputNewPassword').value;
    const confirmPassword = document.getElementById('inputConfirmPassword').value;
    
    // Validierung
    if (newPassword !== confirmPassword) {
        showAlert('profileAlert', 'Die Passwörter stimmen nicht überein', 'danger');
        return;
    }
    
    if (newPassword.length < 8) {
        showAlert('profileAlert', 'Das Passwort muss mindestens 8 Zeichen lang sein', 'danger');
        return;
    }
    
    try {
        const result = await profileAPI.changePassword(currentPassword, newPassword);
        
        if (result.success) {
            showAlert('profileAlert', result.message + ' - Sie werden abgemeldet.', 'success');
            
            // Formular zurücksetzen
            document.getElementById('formChangePassword').reset();
            
            // Nach 2 Sekunden ausloggen
            setTimeout(() => {
                logout();
            }, 2000);
        } else {
            showAlert('profileAlert', result.error, 'danger');
        }
    } catch (error) {
        console.error('Error changing password:', error);
        showAlert('profileAlert', 'Fehler beim Ändern des Passworts', 'danger');
    }
});

// ===================================================================
// Avatar hochladen
// ===================================================================
document.getElementById('btnEditAvatar')?.addEventListener('click', () => {
    document.getElementById('avatarInput').click();
});

document.getElementById('avatarInput')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    
    // Validierung
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        showAlert('profileAlert', 'Nur JPG, PNG, GIF und WebP Dateien erlaubt', 'danger');
        return;
    }
    
    if (file.size > 5 * 1024 * 1024) {
        showAlert('profileAlert', 'Datei zu groß. Maximum 5 MB', 'danger');
        return;
    }
    
    try {
        const result = await profileAPI.uploadAvatar(file);
        
        if (result.success) {
            showAlert('profileAlert', result.message, 'success');
            // Avatar-Vorschau aktualisieren
            document.getElementById('avatarPreview').src = `../${result.avatar}?t=${Date.now()}`;
        } else {
            showAlert('profileAlert', result.error, 'danger');
        }
    } catch (error) {
        console.error('Error uploading avatar:', error);
        showAlert('profileAlert', 'Fehler beim Hochladen', 'danger');
    }
    
    // Input zurücksetzen
    e.target.value = '';
});

// ===================================================================
// Avatar löschen
// ===================================================================
document.getElementById('btnDeleteAvatar')?.addEventListener('click', async () => {
    if (!confirm('Möchten Sie Ihren Avatar wirklich löschen?')) {
        return;
    }
    
    try {
        const result = await profileAPI.deleteAvatar();
        
        if (result.success) {
            showAlert('profileAlert', result.message, 'success');
            // Avatar-Vorschau zurücksetzen
            document.getElementById('avatarPreview').src = '';
        } else {
            showAlert('profileAlert', result.error, 'danger');
        }
    } catch (error) {
        console.error('Error deleting avatar:', error);
        showAlert('profileAlert', 'Fehler beim Löschen', 'danger');
    }
});

// ===================================================================
// Hilfsfunktion: Alert anzeigen
// ===================================================================
function showAlert(elementId, message, type = 'info') {
    const alertDiv = document.getElementById(elementId);
    alertDiv.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Zum Alert scrollen
    alertDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ===================================================================
// 2FA Setup
// ===================================================================
let current2FAData = null;

async function start2FASetup() {
    try {
        const token = localStorage.getItem('session_token');
        const response = await fetch('../api/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'setup_2fa',
                session_token: token
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showAlert('profileAlert', result.error, 'danger');
            return;
        }
        
        // Daten speichern
        current2FAData = result;
        
        // QR-Code generieren (mit qrcode.js library oder Google Charts API)
        const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(result.qr_url)}`;
        document.getElementById('qrCodeContainer').innerHTML = `<img src="${qrCodeUrl}" alt="QR Code" class="img-fluid">`;
        document.getElementById('totpSecret').textContent = result.secret;
        
        // Modal öffnen und Schritt 1 anzeigen
        show2FAStep1();
        new bootstrap.Modal(document.getElementById('modal2FASetup')).show();
        
    } catch (error) {
        console.error('Error setting up 2FA:', error);
        showAlert('profileAlert', 'Fehler beim Einrichten von 2FA', 'danger');
    }
}

function show2FAStep1() {
    document.getElementById('step1-qr').style.display = 'block';
    document.getElementById('step2-verify').style.display = 'none';
    document.getElementById('step3-backup').style.display = 'none';
    document.getElementById('2fa-alert').innerHTML = '';
}

function show2FAStep2() {
    document.getElementById('step1-qr').style.display = 'none';
    document.getElementById('step2-verify').style.display = 'block';
    document.getElementById('step3-backup').style.display = 'none';
    document.getElementById('input2FACode').value = '';
    document.getElementById('input2FACode').focus();
}

async function verify2FACode() {
    const code = document.getElementById('input2FACode').value.trim();
    
    if (!/^\d{6}$/.test(code)) {
        document.getElementById('2fa-alert').innerHTML = `
            <div class="alert alert-warning">Bitte geben Sie einen 6-stelligen Code ein.</div>
        `;
        return;
    }
    
    try {
        const token = localStorage.getItem('session_token');
        const response = await fetch('../api/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'enable_2fa',
                session_token: token,
                code: code
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            document.getElementById('2fa-alert').innerHTML = `
                <div class="alert alert-danger">${result.error}</div>
            `;
            return;
        }
        
        // Erfolg - zeige Backup-Codes
        show2FAStep3();
        
    } catch (error) {
        console.error('Error verifying 2FA code:', error);
        document.getElementById('2fa-alert').innerHTML = `
            <div class="alert alert-danger">Fehler bei der Verifizierung</div>
        `;
    }
}

function show2FAStep3() {
    document.getElementById('step1-qr').style.display = 'none';
    document.getElementById('step2-verify').style.display = 'none';
    document.getElementById('step3-backup').style.display = 'block';
    
    // Backup-Codes anzeigen
    const codes = current2FAData.backup_codes;
    const codesText = codes.map((code, i) => `${(i + 1).toString().padStart(2, '0')}. ${code}`).join('\n');
    document.getElementById('backupCodes').textContent = codesText;
}

function downloadBackupCodes() {
    const codes = current2FAData.backup_codes;
    const text = `Dog Mentality Test - 2FA Backup Codes\n` +
                 `Generiert am: ${new Date().toLocaleString('de-DE')}\n\n` +
                 `WICHTIG: Bewahren Sie diese Codes sicher auf!\n` +
                 `Jeder Code kann nur einmal verwendet werden.\n\n` +
                 codes.map((code, i) => `${(i + 1).toString().padStart(2, '0')}. ${code}`).join('\n');
    
    const blob = new Blob([text], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `2fa-backup-codes-${Date.now()}.txt`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
}

async function disable2FA() {
    try {
        const token = localStorage.getItem('session_token');
        const response = await fetch('../api/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'disable_2fa',
                session_token: token
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal2FADisable')).hide();
            showAlert('profileAlert', '2FA erfolgreich deaktiviert', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('profileAlert', result.error, 'danger');
        }
        
    } catch (error) {
        console.error('Error disabling 2FA:', error);
        showAlert('profileAlert', 'Fehler beim Deaktivieren von 2FA', 'danger');
    }
}

// ===================================================================
// Beim Laden der Seite
// ===================================================================
document.addEventListener('DOMContentLoaded', () => {
    // Session prüfen
    const token = localStorage.getItem('session_token');
    if (!token) {
        window.location.href = 'login.html';
        return;
    }
    
    // Profil laden
    loadProfile();
});
