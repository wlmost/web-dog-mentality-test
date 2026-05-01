/**
 * User Management Client (nur für Admins)
 */

class UserAPI {
    constructor() {
        // Automatische Protokoll-Erkennung (HTTPS-kompatibel)
        const protocol = window.location.protocol;
        const host = window.location.host;
        const basePath = window.location.pathname.replace(/\/frontend.*$/, '');
        this.baseUrl = `${protocol}//${host}${basePath}/api/users.php`;
        this.sessionToken = localStorage.getItem('session_token');
        console.log('User API URL:', this.baseUrl);
    }
    
    async listUsers() {
        // Token frisch holen (falls er sich geändert hat)
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        if (!response.ok) {
            throw new Error('Failed to load users');
        }
        
        return await response.json();
    }
    
    async createUser(userData) {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(userData)
        });
        
        return await response.json();
    }
    
    async updateUser(userId, userData) {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ id: userId, ...userData })
        });
        
        return await response.json();
    }
    
    async deleteUser(userId) {
        const token = localStorage.getItem('session_token');
        const response = await fetch(this.baseUrl, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ id: userId })
        });
        
        return await response.json();
    }
}

const userAPI = new UserAPI();

// ===================================================================
// User-Liste laden und anzeigen
// ===================================================================
async function loadUsers() {
    try {
        const result = await userAPI.listUsers();
        
        if (!result.success) {
            showAlert('userAlert', result.error, 'danger');
            return;
        }
        
        const tbody = document.getElementById('userTableBody');
        tbody.innerHTML = '';
        
        result.users.forEach(user => {
            const tr = document.createElement('tr');
            
            const isActive = user.is_active ? 'Aktiv' : 'Deaktiviert';
            const isAdmin = user.is_admin ? '<span class="badge bg-danger">Admin</span>' : '';
            const has2FA = user.totp_enabled ? '<i class="bi bi-shield-check text-success"></i>' : '';
            const lastLogin = user.last_login ? new Date(user.last_login).toLocaleString('de-DE') : 'Nie';
            
            tr.innerHTML = `
                <td>${user.id}</td>
                <td>
                    <strong>${user.username}</strong>
                    ${isAdmin}
                    ${has2FA}
                </td>
                <td>${user.full_name || '-'}</td>
                <td>${user.email || '-'}</td>
                <td>
                    <span class="badge ${user.is_active ? 'bg-success' : 'bg-secondary'}">
                        ${isActive}
                    </span>
                </td>
                <td>${lastLogin}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="editUser(${user.id})">
                        <i class="bi bi-pencil"></i>
                    </button>
                    ${!user.is_admin || result.users.filter(u => u.is_admin && u.is_active).length > 1 ? `
                        <button class="btn btn-sm btn-danger" onclick="confirmDeleteUser(${user.id}, '${user.username}')">
                            <i class="bi bi-trash"></i>
                        </button>
                    ` : ''}
                </td>
            `;
            
            tbody.appendChild(tr);
        });
        
    } catch (error) {
        showAlert('userAlert', 'Fehler beim Laden der Benutzer: ' + error.message, 'danger');
    }
}

// ===================================================================
// Neuen Benutzer anlegen
// ===================================================================
window.createUser = async function() {
    const formData = {
        username: document.getElementById('create-username').value,
        password: document.getElementById('create-password').value,
        email: document.getElementById('create-email').value || null,
        full_name: document.getElementById('create-full-name').value || null,
        is_admin: document.getElementById('create-is-admin').checked
    };
    
    // Passwort-Bestätigung prüfen
    const passwordConfirm = document.getElementById('create-password-confirm').value;
    if (formData.password !== passwordConfirm) {
        showAlert('user-alert-container', 'Passwörter stimmen nicht überein', 'warning');
        return;
    }
    
    // Validierung
    if (formData.username.length < 3) {
        showAlert('user-alert-container', 'Benutzername muss mindestens 3 Zeichen haben', 'warning');
        return;
    }
    
    if (formData.password.length < 8) {
        showAlert('user-alert-container', 'Passwort muss mindestens 8 Zeichen haben', 'warning');
        return;
    }
    
    try {
        const result = await userAPI.createUser(formData);
        
        if (!result.success) {
            showAlert('user-alert-container', result.error, 'danger');
            return;
        }
        
        showAlert('user-alert-container', 'Benutzer erfolgreich angelegt', 'success');
        
        // Modal schließen und Liste neu laden
        const modal = bootstrap.Modal.getInstance(document.getElementById('createUserModal'));
        if (modal) modal.hide();
        document.getElementById('createUserForm').reset();
        loadUsers();
        
    } catch (error) {
        showAlert('user-alert-container', 'Fehler: ' + error.message, 'danger');
    }
};

// ===================================================================
// Benutzer bearbeiten
// ===================================================================
window.editUser = async function(userId) {
    try {
        const result = await userAPI.listUsers();
        const user = result.users.find(u => u.id === userId);
        
        if (!user) {
            throw new Error('User not found');
        }
        
        // Formular füllen (mit korrekten IDs)
        document.getElementById('edit-user-id').value = user.id;
        document.getElementById('edit-username').value = user.username;
        document.getElementById('edit-email').value = user.email || '';
        document.getElementById('edit-full-name').value = user.full_name || '';
        document.getElementById('edit-is-admin').checked = user.is_admin;
        document.getElementById('edit-is-active').checked = user.is_active;
        
        // Modal öffnen
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
        
    } catch (error) {
        showAlert('user-alert-container', 'Fehler: ' + error.message, 'danger');
    }
};

document.getElementById('editUserForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const userId = parseInt(document.getElementById('edit-user-id').value);
    const updateData = {
        email: document.getElementById('edit-email').value || null,
        full_name: document.getElementById('edit-full-name').value || null,
        is_admin: document.getElementById('edit-is-admin').checked,
        is_active: document.getElementById('edit-is-active').checked
    };
    
    // Neues Passwort?
    const newPassword = document.getElementById('edit-password').value;
    if (newPassword) {
        const passwordConfirm = document.getElementById('edit-password-confirm').value;
        if (newPassword !== passwordConfirm) {
            showAlert('user-alert-container', 'Passwörter stimmen nicht überein', 'warning');
            return;
        }
        if (newPassword.length < 8) {
            showAlert('user-alert-container', 'Passwort muss mindestens 8 Zeichen haben', 'warning');
            return;
        }
        updateData.password = newPassword;
    }
    
    try {
        const result = await userAPI.updateUser(userId, updateData);
        
        if (!result.success) {
            showAlert('user-alert-container', result.error, 'danger');
            return;
        }
        
        showAlert('user-alert-container', 'Benutzer erfolgreich aktualisiert', 'success');
        
        // Modal schließen und Liste neu laden
        const modal = bootstrap.Modal.getInstance(document.getElementById('editUserModal'));
        if (modal) modal.hide();
        document.getElementById('editUserForm').reset();
        loadUsers();
        
    } catch (error) {
        showAlert('editUserAlert', 'Fehler: ' + error.message, 'danger');
    }
});

// ===================================================================
// Benutzer löschen
// ===================================================================
window.deleteUser = async function(userId) {
    try {
        const result = await userAPI.deleteUser(userId);
        
        if (!result.success) {
            showAlert('user-alert-container', result.error, 'danger');
            return;
        }
        
        showAlert('user-alert-container', 'Benutzer erfolgreich gelöscht', 'success');
        loadUsers();
        
    } catch (error) {
        showAlert('user-alert-container', 'Fehler: ' + error.message, 'danger');
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
    
    // Auto-hide nach 5 Sekunden
    if (type === 'success') {
        setTimeout(() => {
            alertDiv.innerHTML = '';
        }, 5000);
    }
}

window.confirmDeleteUser = function(userId, username) {
    if (confirm(`Benutzer "${username}" wirklich löschen?`)) {
        deleteUser(userId);
    }
};

// Beim Laden der Seite
if (document.getElementById('userTableBody')) {
    loadUsers();
}
