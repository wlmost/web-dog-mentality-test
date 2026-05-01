/**
 * API Client - Kommunikation mit Backend
 */

// Automatische API-URL-Generierung (HTTPS-kompatibel)
function getApiBaseUrl() {
    const protocol = window.location.protocol;
    const host = window.location.host;
    const basePath = window.location.pathname.replace(/\/frontend.*$/, '');
    return `${protocol}//${host}${basePath}/api`;
}

const API_BASE_URL = getApiBaseUrl();

class APIClient {
    constructor(baseUrl = API_BASE_URL) {
        this.baseUrl = baseUrl;
        console.log('API Base URL:', this.baseUrl);
    }

    /**
     * Generic HTTP Request
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}/${endpoint}`;
        
        // Session-Token aus localStorage holen
        const token = localStorage.getItem('session_token');
        
        const config = {
            headers: {
                'Content-Type': 'application/json',
                ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                ...options.headers
            },
            ...options
        };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }

    // ===== DOGS API =====
    async getDogs(search = '') {
        const query = search ? `?search=${encodeURIComponent(search)}` : '';
        return this.request(`dogs.php${query}`);
    }

    async getDog(id) {
        return this.request(`dogs.php?id=${id}`);
    }

    async createDog(dogData) {
        return this.request('dogs.php', {
            method: 'POST',
            body: JSON.stringify(dogData)
        });
    }

    async updateDog(id, dogData) {
        return this.request(`dogs.php?id=${id}`, {
            method: 'PUT',
            body: JSON.stringify(dogData)
        });
    }

    async deleteDog(id) {
        return this.request(`dogs.php?id=${id}`, {
            method: 'DELETE'
        });
    }

    // ===== SESSIONS API =====
    async getSessions(dogId = null) {
        const query = dogId ? `?dog_id=${dogId}` : '';
        return this.request(`sessions.php${query}`);
    }

    async getSession(id) {
        return this.request(`sessions.php?id=${id}`);
    }

    async createSession(sessionData) {
        return this.request('sessions.php', {
            method: 'POST',
            body: JSON.stringify(sessionData)
        });
    }

    async updateSession(id, sessionData) {
        return this.request(`sessions.php?id=${id}`, {
            method: 'PUT',
            body: JSON.stringify(sessionData)
        });
    }

    async deleteSession(id) {
        return this.request(`sessions.php?id=${id}`, {
            method: 'DELETE'
        });
    }

    // ===== RESULTS API =====
    async getResults(sessionId) {
        return this.request(`results.php?session_id=${sessionId}`);
    }

    async saveResult(resultData) {
        return this.request('results.php', {
            method: 'POST',
            body: JSON.stringify(resultData)
        });
    }

    async deleteResult(id) {
        return this.request(`results.php?id=${id}`, {
            method: 'DELETE'
        });
    }

    // ===== OCEAN API =====
    async getOceanScores(sessionId) {
        return this.request(`ocean.php?session_id=${sessionId}`);
    }

    // ===== AI API =====
    async generateIdealProfile(dogData) {
        return this.request('ai.php?action=ideal_profile', {
            method: 'POST',
            body: JSON.stringify(dogData)
        });
    }

    async generateAssessment(assessmentData) {
        return this.request('ai.php?action=assessment', {
            method: 'POST',
            body: JSON.stringify(assessmentData)
        });
    }

    // ===== BATTERIES API =====
    async getBatteries() {
        return this.request('batteries.php');
    }

    async getBattery(id) {
        return this.request(`batteries.php?id=${id}`);
    }

    async createBattery(batteryData) {
        return this.request('batteries.php', {
            method: 'POST',
            body: JSON.stringify(batteryData)
        });
    }

    async updateBattery(id, batteryData) {
        return this.request(`batteries.php?id=${id}`, {
            method: 'PUT',
            body: JSON.stringify(batteryData)
        });
    }

    async deleteBattery(id) {
        return this.request(`batteries.php?id=${id}`, {
            method: 'DELETE'
        });
    }

    // ===== IMPORT API =====
    downloadTemplate() {
        // Direct download CSV template
        window.location.href = `${this.baseUrl}/import-csv.php?template`;
    }

    async uploadBattery(file) {
        const formData = new FormData();
        formData.append('file', file);
        
        const url = `${this.baseUrl}/import-csv.php`;
        
        try {
            const response = await fetch(url, {
                method: 'POST',
                body: formData
                // NO Content-Type header! (automatically set by browser)
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }
            
            return data;
        } catch (error) {
            console.error('Upload Error:', error);
            throw error;
        }
    }
}

// Global API instance
const api = new APIClient();
