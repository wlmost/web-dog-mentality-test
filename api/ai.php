<?php
/**
 * API Endpoint: AI Profile Service (OpenAI Integration)
 * 
 * POST /api/ai.php?action=ideal_profile  - Idealprofil generieren
 * POST /api/ai.php?action=assessment     - 3-Profil-Bewertung erstellen
 */

// Error Handling für cleane JSON-Responses
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Error Log Pfad
$errorLogPath = __DIR__ . '/../logs/error.log';
if (!file_exists(dirname($errorLogPath))) {
    mkdir(dirname($errorLogPath), 0755, true);
}
ini_set('error_log', $errorLogPath);

// Globaler Error Handler für unerwartete Fehler
set_exception_handler(function($e) use ($errorLogPath) {
    $timestamp = date('Y-m-d H:i:s');
    $errorMsg = "[$timestamp] EXCEPTION: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n";
    $errorMsg .= "Stack trace:\n{$e->getTraceAsString()}\n\n";
    file_put_contents($errorLogPath, $errorMsg, FILE_APPEND);

    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Interner Serverfehler']);
    exit;
});

register_shutdown_function(function() use ($errorLogPath) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $timestamp = date('Y-m-d H:i:s');
        $errorMsg = "[$timestamp] FATAL: {$error['message']} in {$error['file']}:{$error['line']}\n\n";
        file_put_contents($errorLogPath, $errorMsg, FILE_APPEND);

        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Interner Serverfehler']);
    }
});

try {
    require_once 'config.php';
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($errorLogPath, "[$timestamp] CONFIG ERROR: {$e->getMessage()}\n\n", FILE_APPEND);

    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Interner Serverfehler']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// -----------------------------------------------------------------------
// Rate Limiting: max. AI_RATE_LIMIT Anfragen pro Stunde pro User/IP
// Nutzt die Tabelle ai_rate_limits (Migration 004).
// -----------------------------------------------------------------------
define('AI_RATE_LIMIT', (int)(getenv('AI_RATE_LIMIT') ?: 20));

/**
 * Identifikator für Rate Limiting ermitteln.
 * Gibt 'user_{id}' zurück wenn ein gültiger Token gesendet wird,
 * sonst 'ip_{hash}' auf Basis der Client-IP.
 */
function getAiRateLimitIdentifier(mysqli $conn): string {
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (!empty($authHeader)) {
        $token = str_replace('Bearer ', '', $authHeader);
        // Abgelaufene Sessions bereinigen
        $conn->query("DELETE FROM " . tbl('auth_sessions') . " WHERE expires_at < NOW()");
        $stmt = $conn->prepare("
            SELECT u.id FROM " . tbl('auth_sessions') . " s
            JOIN " . tbl('auth_users') . " u ON u.id = s.user_id
            WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_active = TRUE
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return 'user_' . $row['id'];
        }
    }

    // Fallback: SHA1 der IP (kein persönliches Datum gespeichert)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return 'ip_' . sha1($ip);
}

/**
 * Rate Limit prüfen und Zähler erhöhen.
 * Bricht mit HTTP 429 ab wenn das Limit überschritten ist.
 */
function checkAiRateLimit(mysqli $conn): void {
    $identifier  = getAiRateLimitIdentifier($conn);
    $windowHour  = date('Y-m-d H:00:00');

    // Gelegentlich alte Fenster bereinigen (~1 % der Requests)
    if (random_int(1, 100) === 1) {
        $conn->query("DELETE FROM " . tbl('ai_rate_limits') .
            " WHERE window_hour < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
    }

    // Zähler atomar erhöhen (INSERT … ON DUPLICATE KEY UPDATE)
    $stmt = $conn->prepare("
        INSERT INTO " . tbl('ai_rate_limits') . " (identifier, window_hour, request_count)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE request_count = request_count + 1
    ");
    $stmt->bind_param('ss', $identifier, $windowHour);
    $stmt->execute();

    // Aktuellen Stand lesen
    $stmt = $conn->prepare("
        SELECT request_count FROM " . tbl('ai_rate_limits') . "
        WHERE identifier = ? AND window_hour = ?
    ");
    $stmt->bind_param('ss', $identifier, $windowHour);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row && $row['request_count'] > AI_RATE_LIMIT) {
        header('Retry-After: 3600');
        sendError('Zu viele Anfragen. Bitte in einer Stunde erneut versuchen.', 429);
    }
}

// POST: KI-Features
if ($method === 'POST') {
    // Prüfe OpenAI Konfiguration
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        sendError('OpenAI nicht konfiguriert. Bitte OPENAI_API_KEY in .env Datei setzen.', 503);
    }

    // Rate Limit durchsetzen (vor jeder OpenAI-Anfrage)
    checkAiRateLimit($conn);
    
    $action = $_GET['action'] ?? '';
    $data = getJsonInput();
    
    // ACTION: Idealprofil generieren
    if ($action === 'ideal_profile') {
        validateRequired($data, ['breed', 'age_years', 'gender', 'intended_use']);
        
        $breed        = sanitizeForPrompt(sanitizeString($data['breed']), 100);
        $age_years    = validateInteger($data['age_years'], 0, 20);
        $age_months   = validateInteger($data['age_months'] ?? 0, 0, 11);
        $gender       = validateEnum($data['gender'], ['Rüde', 'Hündin']);
        $intended_use = sanitizeForPrompt(sanitizeString($data['intended_use']), 150);
        $test_count   = validateInteger($data['test_count'] ?? 7, 1, 20);
        
        $max_value = $test_count * 2;
        $age_total = $age_years + ($age_months / 12.0);
        
        $prompt = "You are a working dog behavior specialist with expertise in canine personality assessment using the OCEAN model.

I need you to generate the OPTIMAL OCEAN personality profile for a dog that would ideally suit the following characteristics and role:

Dog Characteristics:
- Breed: $breed
- Age: $age_years years and $age_months months (total: " . number_format($age_total, 1) . " years)
- Sex: $gender
- Intended Use/Role: $intended_use

OCEAN Dimensions (Big Five for Dogs):
- O (Openness): Curiosity, learning ability, adaptability to new situations
- C (Conscientiousness): Reliability, impulse control, focus, trainability
- E (Extraversion): Social behavior, energy level, contact-seeking behavior
- A (Agreeableness): Friendliness, cooperation, compatibility with others
- N (Neuroticism): Nervousness, anxiety, emotional stability (negative values = stable)

Your task: Generate the IDEAL personality values that a dog should have to excel in the role \"$intended_use\".

CRITICAL SCORING RULES:
1. Valid Range: ALL values MUST be integers between -$max_value and +$max_value
2. AVOID EXTREME VALUES: Prefer -12 to +12, reserve extremes for exceptional cases
3. Realistic Distribution: Most values between -10 and +10

Response Format (JSON only, no explanations):
{\"O\": <integer>, \"C\": <integer>, \"E\": <integer>, \"A\": <integer>, \"N\": <integer>}

Example for $test_count tests (range -$max_value to +$max_value):
{\"O\": 7, \"C\": 11, \"E\": 5, \"A\": 9, \"N\": -8}";
        
        try {
            $response = callOpenAI([
                'model' => OPENAI_MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a dog behavior specialist. Always return valid JSON.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 150,
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object']
            ]);
            
            $content = $response['choices'][0]['message']['content'] ?? '';
            $profile = json_decode($content, true);
            
            if (!$profile || !isset($profile['O'], $profile['C'], $profile['E'], $profile['A'], $profile['N'])) {
                sendError('Ungültige KI-Response', 500, ['response' => $content]);
            }
            
            // Werte auf Range begrenzen
            foreach ($profile as $key => $value) {
                $profile[$key] = max(-$max_value, min($max_value, (int)$value));
            }
            
            logMessage("Idealprofil generiert für: $breed, $intended_use");
            
            sendResponse([
                'ideal_profile' => $profile,
                'metadata' => [
                    'breed' => $breed,
                    'age' => "$age_years Jahre, $age_months Monate",
                    'gender' => $gender,
                    'intended_use' => $intended_use,
                    'test_count' => $test_count,
                    'range' => "-$max_value bis +$max_value"
                ]
            ]);
            
        } catch (Exception $e) {
            sendError('KI-Fehler beim Generieren des Idealprofils', 500, ['error' => $e->getMessage()]);
        }
    }
    
    // ACTION: 3-Profil-Bewertung
    elseif ($action === 'assessment') {
        try {
            validateRequired($data, ['ist_profile', 'ideal_profile', 'dog_data']);
            
            $istProfile = validateOceanProfile($data['ist_profile']);
            $idealProfile = validateOceanProfile($data['ideal_profile']);
            $ownerProfile = isset($data['owner_profile']) ? validateOceanProfile($data['owner_profile']) : null;
            
            $dogData = $data['dog_data'];
            validateRequired($dogData, ['dog_name', 'breed', 'intended_use']);
            
            // Sicherstellen dass alle Werte Strings sind
            if (!isset($dogData['dog_name']) || !isset($dogData['breed']) || !isset($dogData['intended_use'])) {
                throw new Exception('dog_data unvollständig: ' . json_encode($dogData));
            }
            
            // Debug für dog_name
            if (is_array($dogData['dog_name'])) {
                throw new Exception('dog_name ist ein Array: ' . json_encode($dogData['dog_name']));
            }
            if (is_array($dogData['breed'])) {
                throw new Exception('breed ist ein Array: ' . json_encode($dogData['breed']));
            }
            if (is_array($dogData['intended_use'])) {
                throw new Exception('intended_use ist ein Array: ' . json_encode($dogData['intended_use']));
            }
            
            $dogName     = sanitizeForPrompt(sanitizeString($dogData['dog_name']), 100);
            $breed       = sanitizeForPrompt(sanitizeString($dogData['breed']), 100);
            $intendedUse = sanitizeForPrompt(sanitizeString($dogData['intended_use']), 150);
        
        $prompt = "You are a certified dog behavior specialist and working dog consultant.

Analyze this dog's personality assessment results:

Dog Information:
- Name: $dogName
- Breed: $breed
- Intended Role: $intendedUse

OCEAN Profile Comparison:
IST-Profile (Actual Test Results):
- Openness: {$istProfile['O']}
- Conscientiousness: {$istProfile['C']}
- Extraversion: {$istProfile['E']}
- Agreeableness: {$istProfile['A']}
- Neuroticism: {$istProfile['N']}

IDEAL-Profile (Optimal for Role):
- Openness: {$idealProfile['O']}
- Conscientiousness: {$idealProfile['C']}
- Extraversion: {$idealProfile['E']}
- Agreeableness: {$idealProfile['A']}
- Neuroticism: {$idealProfile['N']}";

        if ($ownerProfile) {
            $prompt .= "\n\nOWNER-Profile (Handler's Expectations):
- Openness: {$ownerProfile['O']}
- Conscientiousness: {$ownerProfile['C']}
- Extraversion: {$ownerProfile['E']}
- Agreeableness: {$ownerProfile['A']}
- Neuroticism: {$ownerProfile['N']}";
        }
        
        $prompt .= "\n\nProvide a comprehensive assessment (in German) covering:
1. Suitability for intended role (comparing IST vs IDEAL)
2. Key strengths and areas for development
3. Specific training recommendations
4. Handler compatibility" . ($ownerProfile ? " (considering owner expectations)" : "") . "

Write in professional yet accessible language, 300-400 words.";
        
            $response = callOpenAI([
                'model' => OPENAI_MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein zertifizierter Hundepsychologe. Antworte auf Deutsch, professionell und präzise.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => OPENAI_MAX_TOKENS,
                'temperature' => 0.5
            ]);
            
            $assessment = $response['choices'][0]['message']['content'] ?? '';
            
            if (empty($assessment)) {
                sendError('Leere KI-Response', 500);
            }
            
            logMessage("Bewertung generiert für: $dogName ($breed)");
            
            sendResponse([
                'ai_assessment' => $assessment,
                'profiles_compared' => [
                    'ist' => $istProfile,
                    'ideal' => $idealProfile,
                    'owner' => $ownerProfile
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('AI assessment error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            sendError('KI-Fehler beim Erstellen der Bewertung', 500);
        }
    }
    
    else {
        sendError('Ungültige Action. Verfügbar: ideal_profile, assessment');
    }
}

else {
    sendError('Methode nicht erlaubt', 405);
}

/**
 * Helper: Eingabe für KI-Prompts bereinigen.
 *
 * Entfernt alle Steuerzeichen (inkl. Zeilenumbrüche \n, \r) — das primäre
 * Vehikel für Prompt-Injection — und begrenzt die Länge des Feldes.
 */
function sanitizeForPrompt(string $input, int $maxLength = 150): string {
    // Alle ASCII-Steuerzeichen (inkl. \n, \r, \t, Null-Bytes) entfernen
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $input);
    // Mehrfach-Leerzeichen zusammenfassen
    $clean = preg_replace('/\s+/', ' ', $clean ?? '');
    $clean = trim($clean);
    // Auf Maximallänge kürzen (mb_substr für Multibyte/Umlaute)
    if (mb_strlen($clean) > $maxLength) {
        $clean = mb_substr($clean, 0, $maxLength);
    }
    return $clean;
}

/**
 * Helper: OpenAI API Call
 */
function callOpenAI($data) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => OPENAI_TIMEOUT
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: $error");
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Unknown error';
        throw new Exception("OpenAI API Error ($httpCode): $errorMsg");
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !isset($result['choices'])) {
        throw new Exception('Invalid OpenAI response format');
    }
    
    return $result;
}

?>
