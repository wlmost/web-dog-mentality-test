/**
 * Main Application Logic
 */

// Global State
const state = {
    currentTab: 'batteries',
    currentDog: null,
    currentSession: null,
    currentBattery: null,
    currentUser: null,
    dogs: [],
    sessions: [],
    batteries: [],
    oceanChart: null,
    oceanChartModal: null,
    wizard: {
        step: 1,
        selectedDog: null,
        selectedBattery: null,
        dogs: [],
        batteries: []
    }
};

// ===== HELPER FUNCTIONS =====

function getSessionStatusBadge(session) {
    if (session.completed_tests === 0) {
        return '<span class="badge bg-secondary">Neu</span>';
    } else if (session.has_ideal_profile) {
        // Session hat Idealprofil = ist abgeschlossen
        return '<span class="badge bg-success">Abgeschlossen</span>';
    } else {
        return '<span class="badge bg-primary">In Bearbeitung</span>';
    }
}

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    initializeApp();
});

async function initializeApp() {
    // Prüfe Authentifizierung
    await checkAuthentication();
    
    setupEventListeners();
    await loadBatteriesList();
    switchTab('batteries');
}

// Prüfe ob Benutzer eingeloggt ist und Admin-Tab anzeigen
async function checkAuthentication() {
    const sessionToken = localStorage.getItem('session_token');
    
    if (!sessionToken) {
        window.location.href = 'login.html';
        return;
    }
    
    try {
        const result = await auth.verifySession(sessionToken);
        
        if (!result.success || !result.valid) {
            localStorage.removeItem('session_token');
            window.location.href = 'login.html';
            return;
        }
        
        // Admin-Tab anzeigen, wenn Benutzer Admin ist
        if (result.user && result.user.is_admin) {
            document.getElementById('usersTab').style.display = 'block';
            
            // users.js dynamisch laden (nur für Admins)
            const script = document.createElement('script');
            script.src = 'js/users.js?v=3';
            document.body.appendChild(script);
        }
        
        // Benutzerdaten global speichern
        state.currentUser = result.user;
        
    } catch (error) {
        console.error('Auth check failed:', error);
        localStorage.removeItem('session_token');
        window.location.href = 'login.html';
    }
}

// ===== EVENT LISTENERS =====
function setupEventListeners() {
    // Tab Navigation
    document.querySelectorAll('[data-tab]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const tab = e.currentTarget.dataset.tab;
            switchTab(tab);
        });
    });

    // Hunde
    document.getElementById('btn-new-dog').addEventListener('click', () => openDogModal());
    document.getElementById('btn-save-dog').addEventListener('click', saveDog);
    document.getElementById('search-dogs').addEventListener('input', debounce(searchDogs, 300));

    // Sessions
    document.getElementById('btn-new-session').addEventListener('click', startSessionWizard);
    document.getElementById('btn-back-sessions').addEventListener('click', () => {
        document.getElementById('session-detail').classList.add('d-none');
        document.getElementById('sessions-list').classList.remove('d-none');
    });
    document.getElementById('btn-save-session').addEventListener('click', saveSession);

    // Analysis
    document.getElementById('select-analysis-session').addEventListener('change', loadAnalysis);
    document.getElementById('btn-generate-ideal').addEventListener('click', generateIdealProfile);
    document.getElementById('btn-save-owner-profile').addEventListener('click', saveOwnerProfile);
    document.getElementById('btn-generate-assessment').addEventListener('click', generateAssessment);
}

// ===== TAB SWITCHING =====
function switchTab(tabName) {
    state.currentTab = tabName;

    // Update nav
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
        if (link.dataset.tab === tabName) {
            link.classList.add('active');
        }
    });

    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`tab-${tabName}`).classList.add('active');

    // Load data for tab
    if (tabName === 'dogs') {
        loadDogs();
    } else if (tabName === 'sessions') {
        loadSessions();
    } else if (tabName === 'batteries') {
        loadBatteriesList();
    } else if (tabName === 'analysis') {
        loadAnalysisSessionList();
    } else if (tabName === 'users') {
        if (typeof loadUsers === 'function') {
            loadUsers();
        }
    }
}

// ===== DOGS =====
async function loadDogs(search = '') {
    showLoading();
    try {
        const data = await api.getDogs(search);
        state.dogs = data.dogs;
        renderDogs(data.dogs);
    } catch (error) {
        showToast('Fehler beim Laden der Hunde', 'danger');
    } finally {
        hideLoading();
    }
}

function renderDogs(dogs) {
    const container = document.getElementById('dogs-list');
    
    if (dogs.length === 0) {
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Keine Hunde gefunden. Klicken Sie auf "Neuer Hund" um anzufangen.
                </div>
            </div>
        `;
        return;
    }

    container.innerHTML = dogs.map(dog => `
        <div class="col-lg-4 col-md-6">
            <div class="card dog-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-${dog.gender === 'Rüde' ? 'gender-male text-primary' : 'gender-female text-danger'}"></i>
                            ${escapeHtml(dog.dog_name)}
                        </h5>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editDog(${dog.id})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="deleteDog(${dog.id}, '${escapeHtml(dog.dog_name)}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <p class="text-muted mb-2">
                        <i class="bi bi-person"></i> ${escapeHtml(dog.owner_name)}
                    </p>
                    <div class="dog-meta">
                        ${dog.breed ? `<span class="badge bg-secondary">${escapeHtml(dog.breed)}</span>` : ''}
                        <span class="badge bg-info">${formatAge(dog.age_years, dog.age_months)}</span>
                        ${dog.neutered ? '<span class="badge bg-warning">Kastriert</span>' : ''}
                    </div>
                    ${dog.intended_use ? `<p class="mt-2 mb-0"><small><i class="bi bi-star"></i> ${escapeHtml(dog.intended_use)}</small></p>` : ''}
                </div>
                <div class="card-footer">
                    <button class="btn btn-success w-100" onclick="viewDogSessions(${dog.id}, '${escapeHtml(dog.dog_name)}')">
                        <i class="bi bi-clipboard-check"></i> Sessions anzeigen
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

async function searchDogs(e) {
    await loadDogs(e.target.value);
}

function openDogModal(dog = null) {
    const modal = new bootstrap.Modal(document.getElementById('modal-dog'));
    const form = document.getElementById('form-dog');
    form.reset();

    if (dog) {
        document.getElementById('modal-dog-title').textContent = 'Hund bearbeiten';
        document.getElementById('dog-id').value = dog.id;
        document.getElementById('dog-owner-name').value = dog.owner_name;
        document.getElementById('dog-name').value = dog.dog_name;
        document.getElementById('dog-breed').value = dog.breed || '';
        document.getElementById('dog-age-years').value = dog.age_years;
        document.getElementById('dog-age-months').value = dog.age_months;
        document.getElementById('dog-gender').value = dog.gender;
        document.getElementById('dog-neutered').checked = dog.neutered;
        document.getElementById('dog-intended-use').value = dog.intended_use || '';
    } else {
        document.getElementById('modal-dog-title').textContent = 'Neuer Hund';
        document.getElementById('dog-id').value = '';
    }

    modal.show();
}

async function editDog(id) {
    showLoading();
    try {
        const dog = await api.getDog(id);
        openDogModal(dog);
    } catch (error) {
        showToast('Fehler beim Laden des Hundes', 'danger');
    } finally {
        hideLoading();
    }
}

async function saveDog() {
    const form = document.getElementById('form-dog');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const dogData = {
        owner_name: document.getElementById('dog-owner-name').value,
        dog_name: document.getElementById('dog-name').value,
        breed: document.getElementById('dog-breed').value,
        age_years: parseInt(document.getElementById('dog-age-years').value),
        age_months: parseInt(document.getElementById('dog-age-months').value),
        gender: document.getElementById('dog-gender').value,
        neutered: document.getElementById('dog-neutered').checked,
        intended_use: document.getElementById('dog-intended-use').value
    };

    const id = document.getElementById('dog-id').value;

    showLoading();
    try {
        if (id) {
            await api.updateDog(id, dogData);
            showToast('Hund aktualisiert', 'success');
        } else {
            await api.createDog(dogData);
            showToast('Hund erstellt', 'success');
        }

        bootstrap.Modal.getInstance(document.getElementById('modal-dog')).hide();
        await loadDogs();
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

async function deleteDog(id, name) {
    if (!confirm(`Hund "${name}" wirklich löschen? Alle zugehörigen Sessions werden ebenfalls gelöscht!`)) {
        return;
    }

    showLoading();
    try {
        await api.deleteDog(id);
        showToast('Hund gelöscht', 'success');
        await loadDogs();
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

function viewDogSessions(dogId, dogName) {
    state.currentDog = { id: dogId, name: dogName };
    switchTab('sessions');
}

// ===== SESSIONS =====
async function loadSessions() {
    const dogId = state.currentDog?.id;

    // Button ist immer aktiv
    document.getElementById('btn-new-session').disabled = false;

    showLoading();
    try {
        const data = await api.getSessions(dogId);
        state.sessions = data.sessions;
        renderSessions(data.sessions);
    } catch (error) {
        showToast('Fehler beim Laden der Sessions', 'danger');
    } finally {
        hideLoading();
    }
}

function renderSessions(sessions) {
    const container = document.getElementById('sessions-list');

    if (sessions.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Noch keine Sessions vorhanden. Klicken Sie auf "Neue Session" um zu beginnen.
            </div>
        `;
        return;
    }

    container.innerHTML = `
        <div class="list-group">
            ${sessions.map(session => `
                <div class="list-group-item list-group-item-action p-0">
                    <div class="d-flex">
                        <a href="#" class="flex-grow-1 p-3 text-decoration-none text-dark" onclick="loadSessionDetail(${session.session_id}); return false;">
                            <div class="d-flex w-100 justify-content-between">
                                <h5 class="mb-1">${escapeHtml(session.battery_name)}</h5>
                                <small>${formatDate(session.session_date)}</small>
                            </div>
                            <p class="mb-1"><strong>${escapeHtml(session.dog_name)}</strong> (${escapeHtml(session.breed || 'Keine Rasse')})</p>
                            <div class="d-flex justify-content-between align-items-center">
                                <small>Tests abgeschlossen: ${session.completed_tests}</small>
                                ${getSessionStatusBadge(session)}
                            </div>
                        </a>
                        <div class="d-flex align-items-center px-3 border-start">
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteSessionConfirm(${session.session_id}, '${escapeHtml(session.battery_name)}'); event.stopPropagation();" title="Session löschen">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

async function deleteSessionConfirm(sessionId, batteryName) {
    if (!confirm(`Möchten Sie die Session "${batteryName}" wirklich löschen?\n\nAlle zugehörigen Testergebnisse werden ebenfalls gelöscht.`)) {
        return;
    }

    showLoading();
    try {
        await api.deleteSession(sessionId);
        showToast('Session erfolgreich gelöscht', 'success');
        
        // Session-Liste neu laden
        if (state.currentDog) {
            await loadDogSessions(state.currentDog.id);
        }
        
        // Falls die gelöschte Session gerade angezeigt wurde, Detail-Ansicht ausblenden
        if (state.currentSession && state.currentSession.session_id === sessionId) {
            state.currentSession = null;
            document.getElementById('session-detail').classList.add('d-none');
        }
    } catch (error) {
        showToast('Fehler beim Löschen der Session: ' + error.message, 'danger');
    } finally {
        hideLoading();
    }
}

async function createNewSession() {
    // Batterien laden
    showLoading();
    try {
        const data = await api.getBatteries();
        
        if (data.batteries.length === 0) {
            showToast('Keine Testbatterien vorhanden. Bitte erst eine Batterie anlegen.', 'warning');
            return;
        }
        
        // Loading ausblenden bevor Modal angezeigt wird
        hideLoading();
        
        // Modal zur Batterieauswahl
        let batteryOptions = data.batteries.map(b => 
            `<option value="${b.id}">${escapeHtml(b.name)} (${b.test_count} Tests)</option>`
        ).join('');
        
        const result = await showBatterySelectionModal(batteryOptions);
        
        if (result) {
            showLoading();
            const sessionData = {
                dog_id: state.currentDog.id,
                battery_id: parseInt(result),
                session_notes: ''
            };
            
            const newSession = await api.createSession(sessionData);
            showToast('Session erstellt', 'success');
            await loadSessionDetail(newSession.session_id);
        }
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

async function showBatterySelectionModal(options) {
    return new Promise((resolve) => {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Testbatterie auswählen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <select id="battery-select" class="form-select form-select-lg">
                            ${options}
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="button" class="btn btn-primary" id="btn-select-battery">Auswählen</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        
        modal.querySelector('#btn-select-battery').addEventListener('click', () => {
            const value = modal.querySelector('#battery-select').value;
            bsModal.hide();
            resolve(value);
        });
        
        modal.addEventListener('hidden.bs.modal', () => {
            modal.remove();
            resolve(null);
        });
        
        bsModal.show();
    });
}

async function loadSessionDetail(sessionId) {
    showLoading();
    try {
        const session = await api.getSession(sessionId);
        state.currentSession = session;
        renderSessionDetail(session);

        document.getElementById('sessions-list').classList.add('d-none');
        document.getElementById('session-detail').classList.remove('d-none');
    } catch (error) {
        showToast('Fehler beim Laden der Session', 'danger');
    } finally {
        hideLoading();
    }
}

async function renderSessionDetail(session) {
    // Session Info
    document.getElementById('session-info').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <p><strong>Hund:</strong> ${escapeHtml(session.dog_name)}</p>
                <p><strong>Rasse:</strong> ${escapeHtml(session.breed || '-')}</p>
                <p><strong>Halter:</strong> ${escapeHtml(session.owner_name)}</p>
            </div>
            <div class="col-md-6">
                <p><strong>Testbatterie:</strong> ${escapeHtml(session.battery_name)}</p>
                <p><strong>Datum:</strong> ${formatDate(session.session_date)}</p>
                <p><strong>Einsatzgebiet:</strong> ${escapeHtml(session.intended_use || '-')}</p>
            </div>
        </div>
    `;

    // Session Notes
    document.getElementById('session-notes').value = session.session_notes || '';

    // Load battery tests and render table
    try {
        const battery = await api.getBattery(session.battery_id);
        state.currentBattery = battery;
        renderTestsTable(battery.tests, session.results || []);
        
        // Update progress
        const completedTests = (session.results || []).filter(r => r.score !== null).length;
        document.getElementById('test-progress').textContent = `${completedTests} / ${battery.tests.length}`;
    } catch (error) {
        console.error('Error loading battery:', error);
        renderTestsPlaceholder();
    }
}

function renderTestsTable(tests, results) {
    const tbody = document.getElementById('tests-tbody');
    
    // Create results map for quick lookup
    const resultsMap = {};
    results.forEach(r => {
        resultsMap[r.test_number] = r;
    });
    
    tbody.innerHTML = tests.map(test => {
        const result = resultsMap[test.test_number] || {};
        const currentScore = result.score ?? null;
        const currentNotes = result.notes || '';
        
        return `
            <tr data-test-number="${test.test_number}" style="cursor: pointer;" onclick="toggleTestDetails(${test.test_number})">
                <td class="text-center fw-bold">${test.test_number}</td>
                <td>${escapeHtml(test.test_name)}</td>
                <td class="text-center">
                    <span class="badge bg-${getOceanColor(test.ocean_dimension)}">
                        ${escapeHtml(test.ocean_dimension)}
                    </span>
                </td>
                <td class="text-center" onclick="event.stopPropagation()">
                    <div class="btn-group" role="group">
                        ${[-2, -1, 0, 1, 2].map(score => `
                            <button type="button" 
                                    class="btn btn-sm score-btn ${currentScore === score ? 'btn-primary active' : 'btn-outline-primary'}"
                                    onclick="setTestScore(${test.test_number}, ${score})"
                                    data-score="${score}">
                                ${score > 0 ? '+' : ''}${score}
                            </button>
                        `).join('')}
                    </div>
                </td>
                <td onclick="event.stopPropagation()">
                    <input type="text" 
                           class="form-control form-control-sm test-notes" 
                           placeholder="Notizen..."
                           value="${escapeHtml(currentNotes)}"
                           onchange="updateTestNotes(${test.test_number}, this.value)">
                </td>
            </tr>
            <tr class="test-details-row d-none" id="test-details-${test.test_number}">
                <td colspan="5" class="bg-light">
                    <div class="p-3">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <strong>Setting & Durchführung:</strong>
                                <p class="mb-2">${escapeHtml(test.setting || '-')}</p>
                            </div>
                            <div class="col-md-3 mb-3">
                                <strong>Material:</strong>
                                <p class="mb-2">${escapeHtml(test.materials || '-')}</p>
                            </div>
                            <div class="col-md-3 mb-3">
                                <strong>Dauer:</strong>
                                <p class="mb-2">${escapeHtml(test.duration || '-')}</p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <strong>Rolle der Figurant:in:</strong>
                                <p class="mb-2">${escapeHtml(test.role_figurant || '-')}</p>
                            </div>
                            <div class="col-md-6 mb-3">
                                <strong>Beobachtungskriterien:</strong>
                                <p class="mb-2">${escapeHtml(test.observation_criteria || '-')}</p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <strong>Bewertungsskala:</strong>
                                <p class="mb-0">${escapeHtml(test.rating_scale || '-')}</p>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function renderTestsPlaceholder() {
    const tbody = document.getElementById('tests-tbody');
    tbody.innerHTML = `
        <tr>
            <td colspan="5" class="text-center text-muted py-4">
                <i class="bi bi-info-circle"></i> Testbatterie konnte nicht geladen werden.
            </td>
        </tr>
    `;
}

function getOceanColor(dimension) {
    const colors = {
        'Offenheit': 'primary',
        'Gewissenhaftigkeit': 'success',
        'Extraversion': 'warning',
        'Verträglichkeit': 'info',
        'Neurotizismus': 'danger'
    };
    return colors[dimension] || 'secondary';
}

function toggleTestDetails(testNumber) {
    const detailsRow = document.getElementById(`test-details-${testNumber}`);
    if (detailsRow) {
        detailsRow.classList.toggle('d-none');
    }
}

async function setTestScore(testNumber, score) {
    if (!state.currentSession) {
        showToast('Keine Session geladen', 'danger');
        return;
    }
    
    try {
        await api.saveResult({
            session_id: state.currentSession.session_id,
            test_number: testNumber,
            score: score,
            notes: document.querySelector(`tr[data-test-number="${testNumber}"] .test-notes`).value
        });
        
        // UI Update
        const row = document.querySelector(`tr[data-test-number="${testNumber}"]`);
        row.querySelectorAll('.score-btn').forEach(btn => {
            const btnScore = parseInt(btn.dataset.score);
            if (btnScore === score) {
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-primary', 'active');
            } else {
                btn.classList.remove('btn-primary', 'active');
                btn.classList.add('btn-outline-primary');
            }
        });
        
        // Update progress
        await updateTestProgress();
        
    } catch (error) {
        showToast('Fehler beim Speichern: ' + error.message, 'danger');
    }
}

async function updateTestNotes(testNumber, notes) {
    if (!state.currentSession) return;
    
    try {
        // Get current score
        const row = document.querySelector(`tr[data-test-number="${testNumber}"]`);
        const activeBtn = row.querySelector('.score-btn.active');
        const score = activeBtn ? parseInt(activeBtn.dataset.score) : null;
        
        if (score !== null) {
            // Get max_value from battery
            const test = state.currentBattery.tests.find(t => t.test_number === testNumber);
            const normalizedScore = Math.round((score / 2) * test.max_value);
            
            await api.saveResult({
                session_id: state.currentSession.session_id,
                test_number: testNumber,
                score: normalizedScore,
                notes: notes
            });
        }
    } catch (error) {
        console.error('Error updating notes:', error);
    }
}

async function updateTestProgress() {
    try {
        const session = await api.getSession(state.currentSession.session_id);
        const completedTests = (session.results || []).filter(r => r.score !== null).length;
        const totalTests = state.currentBattery.tests.length;
        document.getElementById('test-progress').textContent = `${completedTests} / ${totalTests}`;
    } catch (error) {
        console.error('Error updating progress:', error);
    }
}

async function saveSession() {
    const sessionData = {
        session_notes: document.getElementById('session-notes').value
    };

    showLoading();
    try {
        await api.updateSession(state.currentSession.session_id, sessionData);
        showToast('Session gespeichert', 'success');
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

// ===== BATTERIES =====
async function loadBatteriesList() {
    showLoading();
    try {
        const data = await api.getBatteries();
        state.batteries = data.batteries;
        renderBatteriesList(data.batteries);
    } catch (error) {
        showToast('Fehler beim Laden der Batterien', 'danger');
    } finally {
        hideLoading();
    }
}

function renderBatteriesList(batteries) {
    const container = document.getElementById('batteries-list');
    
    if (batteries.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Noch keine Testbatterien vorhanden. Importieren Sie eine Excel-Datei oder laden Sie das Template herunter.
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Beschreibung</th>
                        <th class="text-center">Anzahl Tests</th>
                        <th class="text-center">Erstellt am</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    ${batteries.map(battery => `
                        <tr>
                            <td>
                                <strong>${escapeHtml(battery.name)}</strong>
                            </td>
                            <td>${escapeHtml(battery.description || '-')}</td>
                            <td class="text-center">
                                <span class="badge bg-primary">${battery.test_count} Tests</span>
                            </td>
                            <td class="text-center">
                                <small>${formatDate(battery.created_at)}</small>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-info" onclick="viewBatteryDetails(${battery.id})" title="Details anzeigen">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="deleteBatteryConfirm(${battery.id}, '${escapeHtml(battery.name)}')" title="Löschen">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

async function viewBatteryDetails(id) {
    showLoading();
    try {
        const battery = await api.getBattery(id);
        
        // Modal erstellen
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${escapeHtml(battery.name)}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ${battery.description ? `<p class="lead">${escapeHtml(battery.description)}</p>` : ''}
                        <h6 class="mt-3">Tests (${battery.tests.length})</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Nr.</th>
                                        <th>Testname</th>
                                        <th>OCEAN Dimension</th>
                                        <th class="text-end">Max. Wert</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${battery.tests.map(test => `
                                        <tr>
                                            <td>${test.test_number}</td>
                                            <td>${escapeHtml(test.test_name)}</td>
                                            <td>
                                                <span class="badge bg-${getOceanColor(test.ocean_dimension)}">
                                                    ${escapeHtml(test.ocean_dimension)}
                                                </span>
                                            </td>
                                            <td class="text-end">${test.max_value}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        
        modal.addEventListener('hidden.bs.modal', () => {
            modal.remove();
        });
        
        bsModal.show();
    } catch (error) {
        showToast('Fehler beim Laden der Batterie-Details', 'danger');
    } finally {
        hideLoading();
    }
}

async function deleteBatteryConfirm(id, name) {
    if (!confirm(`Testbatterie "${name}" wirklich löschen?\n\nACHTUNG: Dies ist nur möglich, wenn keine Sessions diese Batterie verwenden!`)) {
        return;
    }
    
    showLoading();
    try {
        await api.deleteBattery(id);
        showToast('Batterie gelöscht', 'success');
        await loadBatteriesList();
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

function downloadTemplate() {
    api.downloadTemplate();
    showToast('Template wird heruntergeladen...', 'info');
}

async function uploadBattery() {
    const fileInput = document.getElementById('battery-file');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Bitte wählen Sie eine CSV-Datei aus', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    
    // Client-seitige Validierung
    const allowedExtensions = ['csv'];
    const fileExtension = file.name.split('.').pop().toLowerCase();
    
    if (!allowedExtensions.includes(fileExtension)) {
        showToast('Ungültiges Dateiformat. Nur .csv erlaubt.', 'danger');
        return;
    }
    
    if (file.size > 5 * 1024 * 1024) {
        showToast('Datei zu groß. Maximum 5 MB.', 'danger');
        return;
    }
    
    showLoading();
    try {
        const result = await api.uploadBattery(file);
        
        showToast(
            `Batterie "${result.battery_name}" mit ${result.tests_imported} Tests erfolgreich importiert!`,
            'success'
        );
        
        // Batterien-Liste neu laden
        await loadBatteriesList();
        
        // Input leeren
        fileInput.value = '';
        
    } catch (error) {
        showToast('Import fehlgeschlagen: ' + error.message, 'danger');
    } finally {
        hideLoading();
    }
}

// ===== ANALYSIS =====
async function loadAnalysisSessionList() {
    showLoading();
    try {
        const data = await api.getSessions();
        const select = document.getElementById('select-analysis-session');
        
        select.innerHTML = '<option value="">-- Bitte Session auswählen --</option>';
        data.sessions.forEach(session => {
            const option = document.createElement('option');
            option.value = session.session_id;
            option.textContent = `${session.dog_name} - ${session.battery_name} (${formatDate(session.session_date)})`;
            select.appendChild(option);
        });
    } catch (error) {
        showToast('Fehler beim Laden der Sessions', 'danger');
    } finally {
        hideLoading();
    }
}

async function loadAnalysis(e) {
    const sessionId = e.target.value;
    
    if (!sessionId) {
        document.getElementById('analysis-container').classList.add('d-none');
        return;
    }

    showLoading();
    try {
        const oceanData = await api.getOceanScores(sessionId);
        const session = await api.getSession(sessionId);
        
        console.log('Ocean Data:', oceanData);
        console.log('Session Data:', session);
        
        // OCEAN-Daten in Session speichern für Modal und KI-Bewertung
        state.currentSession = {
            ...session,
            ocean_scores: oceanData.ocean_scores,
            profiles: oceanData.profiles
        };
        
        // Container ZUERST einblenden, damit Canvas sichtbar ist
        document.getElementById('analysis-container').classList.remove('d-none');
        
        console.log('About to call renderOceanChart');
        console.log('renderOceanChart function exists?', typeof renderOceanChart);
        
        try {
            renderOceanChart(oceanData);
            console.log('renderOceanChart completed');
        } catch (e) {
            console.error('Error in renderOceanChart:', e);
        }
        
        try {
            renderOceanStats(oceanData);
            console.log('renderOceanStats completed');
        } catch (e) {
            console.error('Error in renderOceanStats:', e);
        }
        
        // Owner Profile
        if (session.owner_profile) {
            document.getElementById('owner-o').value = session.owner_profile.O;
            document.getElementById('owner-c').value = session.owner_profile.C;
            document.getElementById('owner-e').value = session.owner_profile.E;
            document.getElementById('owner-a').value = session.owner_profile.A;
            document.getElementById('owner-n').value = session.owner_profile.N;
        }
        
        // AI Assessment
        if (session.ai_assessment) {
            document.getElementById('ai-assessment-content').innerHTML = `
                <div class="alert alert-success">
                    ${escapeHtml(session.ai_assessment).replace(/\n/g, '<br>')}
                </div>
            `;
        }
        
        // Container ist bereits eingeblendet (oben vor Chart-Rendering)
    } catch (error) {
        showToast('Fehler beim Laden der Analyse', 'danger');
    } finally {
        hideLoading();
    }
}

function renderOceanStats(oceanData) {
    const container = document.getElementById('ocean-stats');
    
    const dimensions = [
        { key: 'O', name: 'Openness (Offenheit)' },
        { key: 'C', name: 'Conscientiousness (Gewissenhaftigkeit)' },
        { key: 'E', name: 'Extraversion (Extraversion)' },
        { key: 'A', name: 'Agreeableness (Verträglichkeit)' },
        { key: 'N', name: 'Neuroticism (Neurotizismus)' }
    ];
    
    container.innerHTML = `
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Dimension</th>
                    <th class="text-end">Summe</th>
                    <th class="text-end">Anzahl Tests</th>
                    <th class="text-end">Durchschnitt</th>
                </tr>
            </thead>
            <tbody>
                ${dimensions.map(dim => `
                    <tr>
                        <td><strong>${dim.name}</strong></td>
                        <td class="text-end">${oceanData.ocean_scores[dim.key]}</td>
                        <td class="text-end">${oceanData.test_counts[dim.key]}</td>
                        <td class="text-end">${oceanData.averages[dim.key].toFixed(2)}</td>
                    </tr>
                `).join('')}
            </tbody>
            <tfoot>
                <tr class="table-active">
                    <td><strong>Gesamt</strong></td>
                    <td class="text-end" colspan="2"><strong>${oceanData.total_completed_tests} Tests</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    `;
}

async function generateIdealProfile() {
    if (!state.currentSession) {
        showToast('Bitte Session auswählen', 'warning');
        return;
    }

    const session = state.currentSession;
    
    showLoading();
    try {
        const data = await api.generateIdealProfile({
            breed: session.breed || 'Mischling',
            age_years: session.age_years,
            age_months: session.age_months,
            gender: session.gender,
            intended_use: session.intended_use || 'Familienhund',
            test_count: 7
        });

        // Update session with ideal profile
        await api.updateSession(session.session_id, {
            ideal_profile: data.ideal_profile
        });

        showToast('Idealprofil generiert', 'success');
        
        // Reload chart
        loadAnalysis({ target: { value: session.session_id } });
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

async function saveOwnerProfile() {
    if (!state.currentSession) {
        showToast('Bitte Session auswählen', 'warning');
        return;
    }

    const ownerProfile = {
        O: parseInt(document.getElementById('owner-o').value),
        C: parseInt(document.getElementById('owner-c').value),
        E: parseInt(document.getElementById('owner-e').value),
        A: parseInt(document.getElementById('owner-a').value),
        N: parseInt(document.getElementById('owner-n').value)
    };

    showLoading();
    try {
        await api.updateSession(state.currentSession.session_id, {
            owner_profile: ownerProfile
        });

        showToast('Halter-Profil gespeichert', 'success');
        
        // Reload chart
        loadAnalysis({ target: { value: state.currentSession.session_id } });
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

async function generateAssessment() {
    if (!state.currentSession) {
        showToast('Bitte Session auswählen', 'warning');
        return;
    }

    showLoading();
    try {
        // Lade OCEAN-Daten falls nicht vorhanden
        let oceanScores = state.currentSession.ocean_scores;
        let profiles = state.currentSession.profiles;
        
        if (!oceanScores || !profiles) {
            const oceanData = await api.getOceanScores(state.currentSession.session_id);
            oceanScores = oceanData.ocean_scores;
            profiles = oceanData.profiles;
            
            // Update state
            state.currentSession.ocean_scores = oceanScores;
            state.currentSession.profiles = profiles;
        }

        if (!profiles || !profiles.ideal) {
            showToast('Bitte erst Idealprofil generieren', 'warning');
            hideLoading();
            return;
        }

        const assessmentData = {
            ist_profile: oceanScores,
            ideal_profile: profiles.ideal,
            owner_profile: profiles.owner,
            dog_data: {
                dog_name: state.currentSession.dog_name,
                breed: state.currentSession.breed || 'Mischling',
                intended_use: state.currentSession.intended_use || 'Familienhund'
            }
        };

        const data = await api.generateAssessment(assessmentData);

        // Save to session
        await api.updateSession(state.currentSession.session_id, {
            ai_assessment: data.ai_assessment
        });

        document.getElementById('ai-assessment-content').innerHTML = `
            <div class="alert alert-success">
                ${escapeHtml(data.ai_assessment).replace(/\n/g, '<br>')}
            </div>
        `;

        showToast('KI-Bewertung generiert', 'success');
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}

// ===== UTILITY FUNCTIONS =====
function showLoading() {
    document.getElementById('loading-overlay').classList.remove('d-none');
}

function hideLoading() {
    document.getElementById('loading-overlay').classList.add('d-none');
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const id = `toast-${Date.now()}`;
    
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.id = id;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${escapeHtml(message)}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatAge(years, months) {
    if (years === 0) return `${months} Monat${months !== 1 ? 'e' : ''}`;
    if (months === 0) return `${years} Jahr${years !== 1 ? 'e' : ''}`;
    return `${years}J ${months}M`;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('de-DE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ===== SESSION WIZARD =====

async function startSessionWizard() {
    // Wizard-State zurücksetzen; bereits gewählten Hund vorbelegen
    state.wizard = {
        step: 1,
        selectedDog: state.currentDog ? { id: state.currentDog.id, dog_name: state.currentDog.name } : null,
        selectedBattery: null,
        dogs: [],
        batteries: []
    };

    const modal = new bootstrap.Modal(document.getElementById('session-wizard-modal'));
    modal.show();

    // Modal-Close: State zurücksetzen
    document.getElementById('session-wizard-modal').addEventListener('hidden.bs.modal', () => {
        state.wizard = { step: 1, selectedDog: null, selectedBattery: null, dogs: [], batteries: [] };
    }, { once: true });

    // Event-Listener registrieren
    document.getElementById('wizard-btn-next').onclick = wizardNext;
    document.getElementById('wizard-btn-back').onclick = wizardBack;
    document.getElementById('wizard-btn-new-dog').onclick = showWizardDogForm;
    document.getElementById('wizard-btn-cancel-new-dog').onclick = hideWizardDogForm;
    document.getElementById('wizard-btn-save-new-dog').onclick = saveWizardNewDog;
    document.getElementById('wizard-btn-start-test').onclick = wizardStartTest;
    document.getElementById('wizard-dog-search').oninput = debounce(async (e) => {
        await loadWizardDogs(e.target.value);
    }, 300);

    await showWizardStep(1);
}

async function showWizardStep(step) {
    state.wizard.step = step;

    // Alle Steps ausblenden
    [1, 2, 3].forEach(n => {
        document.getElementById(`wizard-step-${n}`).classList.add('d-none');
    });
    document.getElementById(`wizard-step-${step}`).classList.remove('d-none');

    // Progress-Indikatoren aktualisieren
    [1, 2, 3].forEach(n => {
        const el = document.getElementById(`wizard-indicator-${n}`);
        el.classList.remove('active', 'completed');
        if (n < step) el.classList.add('completed');
        else if (n === step) el.classList.add('active');
    });

    // Verbindungslinien
    document.querySelectorAll('.wizard-step-line').forEach((line, i) => {
        line.classList.toggle('completed', i + 2 <= step);
    });

    // Zurück-Button
    const btnBack = document.getElementById('wizard-btn-back');
    const btnNext = document.getElementById('wizard-btn-next');
    btnBack.classList.toggle('d-none', step === 1);
    btnNext.classList.toggle('d-none', step === 3);

    // Step-spezifisches Laden
    if (step === 1) {
        document.getElementById('wizard-dog-search').value = '';
        hideWizardDogForm();
        await loadWizardDogs();
    } else if (step === 2) {
        const dog = state.wizard.selectedDog;
        document.getElementById('wizard-selected-dog-info').textContent =
            `${dog.dog_name}${dog.owner_name ? ' (' + dog.owner_name + ')' : ''}`;
        await loadWizardBatteries();
    } else if (step === 3) {
        const dog = state.wizard.selectedDog;
        const battery = state.wizard.selectedBattery;
        document.getElementById('wizard-confirm-dog').textContent =
            `${dog.dog_name}${dog.owner_name ? ' – ' + dog.owner_name : ''}${dog.breed ? ' (' + dog.breed + ')' : ''}`;
        document.getElementById('wizard-confirm-battery').textContent =
            `${battery.name} (${battery.test_count} Tests)`;
    }
}

async function loadWizardDogs(search = '') {
    showLoading();
    try {
        const data = await api.getDogs(search);
        state.wizard.dogs = data.dogs;
        renderWizardDogs(data.dogs);
    } catch (error) {
        showToast('Fehler beim Laden der Hunde', 'danger');
    } finally {
        hideLoading();
    }
}

function renderWizardDogs(dogs) {
    const container = document.getElementById('wizard-dogs-list');
    if (dogs.length === 0) {
        container.innerHTML = `
            <div class="text-center p-4 text-muted">
                <i class="bi bi-info-circle"></i> Keine Hunde gefunden.
            </div>
        `;
        return;
    }
    container.innerHTML = dogs.map(dog => {
        const isSelected = state.wizard.selectedDog?.id === dog.id;
        const genderIcon = dog.gender === 'Rüde' ? 'gender-male text-primary' : 'gender-female text-danger';
        return `
            <div class="wizard-list-item ${isSelected ? 'selected' : ''}" onclick="selectWizardDog(${dog.id})">
                <div class="wizard-list-item-title">
                    <i class="bi bi-${genderIcon}"></i> ${escapeHtml(dog.dog_name)}
                    ${isSelected ? '<i class="bi bi-check-circle-fill text-success float-end"></i>' : ''}
                </div>
                <div class="wizard-list-item-meta">
                    <span><i class="bi bi-person"></i> ${escapeHtml(dog.owner_name)}</span>
                    ${dog.breed ? `<span><i class="bi bi-tag"></i> ${escapeHtml(dog.breed)}</span>` : ''}
                    <span><i class="bi bi-calendar"></i> ${formatAge(dog.age_years, dog.age_months)}</span>
                </div>
            </div>
        `;
    }).join('');
}

function selectWizardDog(dogId) {
    const dog = state.wizard.dogs.find(d => d.id === dogId);
    if (!dog) return;
    state.wizard.selectedDog = dog;
    renderWizardDogs(state.wizard.dogs);
}

async function loadWizardBatteries() {
    showLoading();
    try {
        const data = await api.getBatteries();
        state.wizard.batteries = data.batteries;
        renderWizardBatteries(data.batteries);
    } catch (error) {
        showToast('Fehler beim Laden der Batterien', 'danger');
    } finally {
        hideLoading();
    }
}

function renderWizardBatteries(batteries) {
    const container = document.getElementById('wizard-batteries-list');
    if (batteries.length === 0) {
        container.innerHTML = `
            <div class="p-4">
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    Keine Testbatterien vorhanden. Bitte erst eine Batterie im Tab "Batterien" importieren.
                </div>
            </div>
        `;
        return;
    }
    container.innerHTML = batteries.map(battery => {
        const isSelected = state.wizard.selectedBattery?.id === battery.id;
        return `
            <div class="wizard-list-item ${isSelected ? 'selected' : ''}" onclick="selectWizardBattery(${battery.id})">
                <div class="wizard-list-item-title">
                    ${escapeHtml(battery.name)}
                    ${isSelected ? '<i class="bi bi-check-circle-fill text-success float-end"></i>' : ''}
                </div>
                <div class="wizard-list-item-meta">
                    <span><i class="bi bi-list-check"></i> ${battery.test_count} Tests</span>
                    ${battery.description ? `<span>${escapeHtml(battery.description)}</span>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function selectWizardBattery(batteryId) {
    const battery = state.wizard.batteries.find(b => b.id === batteryId);
    if (!battery) return;
    state.wizard.selectedBattery = battery;
    renderWizardBatteries(state.wizard.batteries);
}

async function wizardNext() {
    const step = state.wizard.step;
    if (step === 1) {
        if (!state.wizard.selectedDog) {
            showToast('Bitte wählen Sie einen Hund aus oder erstellen Sie einen neuen.', 'warning');
            return;
        }
    } else if (step === 2) {
        if (!state.wizard.selectedBattery) {
            showToast('Bitte wählen Sie eine Testbatterie aus.', 'warning');
            return;
        }
    }
    await showWizardStep(step + 1);
}

async function wizardBack() {
    if (state.wizard.step > 1) {
        await showWizardStep(state.wizard.step - 1);
    }
}

function showWizardDogForm() {
    document.getElementById('wizard-dog-form').classList.remove('d-none');
    document.getElementById('wizard-dogs-list').classList.add('d-none');
    document.getElementById('wizard-dog-search').parentElement.classList.add('d-none');
    document.getElementById('wizard-btn-new-dog').classList.add('d-none');
}

function hideWizardDogForm() {
    document.getElementById('wizard-dog-form').classList.add('d-none');
    document.getElementById('wizard-dogs-list').classList.remove('d-none');
    document.getElementById('wizard-dog-search').parentElement.classList.remove('d-none');
    document.getElementById('wizard-btn-new-dog').classList.remove('d-none');
    // Formularfelder leeren
    ['wizard-dog-owner-name', 'wizard-dog-name', 'wizard-dog-breed',
     'wizard-dog-intended-use'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('wizard-dog-age-years').value = '1';
    document.getElementById('wizard-dog-age-months').value = '0';
    document.getElementById('wizard-dog-gender').value = '';
    document.getElementById('wizard-dog-neutered').checked = false;
}

async function saveWizardNewDog() {
    const ownerName = document.getElementById('wizard-dog-owner-name').value.trim();
    const dogName = document.getElementById('wizard-dog-name').value.trim();
    const gender = document.getElementById('wizard-dog-gender').value;

    if (!ownerName || !dogName || !gender) {
        showToast('Bitte füllen Sie alle Pflichtfelder aus (Halter, Hundename, Geschlecht).', 'warning');
        return;
    }

    const dogData = {
        owner_name: ownerName,
        dog_name: dogName,
        breed: document.getElementById('wizard-dog-breed').value,
        age_years: parseInt(document.getElementById('wizard-dog-age-years').value) || 1,
        age_months: parseInt(document.getElementById('wizard-dog-age-months').value) || 0,
        gender: gender,
        neutered: document.getElementById('wizard-dog-neutered').checked,
        intended_use: document.getElementById('wizard-dog-intended-use').value
    };

    showLoading();
    try {
        const newDog = await api.createDog(dogData);
        state.wizard.selectedDog = { ...dogData, id: newDog.id || newDog.dog_id };
        showToast(`Hund "${dogName}" erfolgreich erstellt!`, 'success');
        await showWizardStep(2);
    } catch (error) {
        showToast('Fehler beim Erstellen: ' + error.message, 'danger');
    } finally {
        hideLoading();
    }
}

async function wizardStartTest() {
    const dog = state.wizard.selectedDog;
    const battery = state.wizard.selectedBattery;

    showLoading();
    try {
        const newSession = await api.createSession({
            dog_id: dog.id,
            battery_id: battery.id,
            session_notes: ''
        });

        showToast('Session erfolgreich erstellt!', 'success');

        // Modal schließen
        bootstrap.Modal.getInstance(document.getElementById('session-wizard-modal')).hide();

        // Kontext setzen und Session öffnen
        state.currentDog = { id: dog.id, name: dog.dog_name };
        await loadSessionDetail(newSession.session_id);
        switchTab('sessions');
    } catch (error) {
        showToast('Fehler beim Erstellen der Session: ' + error.message, 'danger');
    } finally {
        hideLoading();
    }
}

