<?php
declare(strict_types=1);
/**
 * API Endpoint: OCEAN Analysis
 * 
 * GET /api/ocean.php?session_id=1  - OCEAN-Scores für Session berechnen
 */

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

// GET: OCEAN-Scores berechnen
if ($method === 'GET') {
    if (!isset($_GET['session_id'])) {
        sendError('Session ID erforderlich');
    }
    
    $session_id = validateInteger($_GET['session_id'], 1, null, 'Session ID');
    
    // Prüfen ob Session existiert
    $stmt = $conn->prepare("SELECT id, battery_id FROM " . tbl('test_sessions') . " WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $sessionResult = $stmt->get_result();
    
    if ($sessionResult->num_rows === 0) {
        sendError('Session nicht gefunden', 404);
    }
    
    $sessionData = $sessionResult->fetch_assoc();
    $battery_id = $sessionData['battery_id'];
    
    // OCEAN-Scores berechnen (View nutzen)
    $stmt = $conn->prepare("
        SELECT ocean_dimension, total_score, test_count, average_score
        FROM " . tbl('v_ocean_scores') . "
        WHERE session_id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Ergebnisse in strukturierte Form bringen
    $ocean = [
        'O' => 0,
        'C' => 0,
        'E' => 0,
        'A' => 0,
        'N' => 0
    ];
    
    $counts = [
        'O' => 0,
        'C' => 0,
        'E' => 0,
        'A' => 0,
        'N' => 0
    ];
    
    $averages = [
        'O' => 0.0,
        'C' => 0.0,
        'E' => 0.0,
        'A' => 0.0,
        'N' => 0.0
    ];
    
    // Mapping deutscher Namen zu Buchstaben
    $dimensionMap = [
        'Offenheit' => 'O',
        'Gewissenhaftigkeit' => 'C',
        'Extraversion' => 'E',
        'Verträglichkeit' => 'A',
        'Neurotizismus' => 'N'
    ];
    
    while ($row = $result->fetch_assoc()) {
        $dimension = $row['ocean_dimension'];
        $key = $dimensionMap[$dimension] ?? null;
        
        if ($key) {
            $ocean[$key] = (int)$row['total_score'];
            $counts[$key] = (int)$row['test_count'];
            $averages[$key] = (float)$row['average_score'];
        }
    }
    
    // Anzahl abgeschlossener Tests
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM " . tbl('test_results') . " WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $totalTests = $stmt->get_result()->fetch_assoc()['total'];
    
    // Profile aus Session laden (falls vorhanden)
    $stmt = $conn->prepare("
        SELECT ideal_profile, owner_profile, ai_assessment 
        FROM " . tbl('test_sessions') . "
        WHERE id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $profileData = $stmt->get_result()->fetch_assoc();
    
    $idealProfile = $profileData['ideal_profile'] 
        ? json_decode($profileData['ideal_profile'], true) 
        : null;
    
    $ownerProfile = $profileData['owner_profile'] 
        ? json_decode($profileData['owner_profile'], true) 
        : null;
    
    $aiAssessment = $profileData['ai_assessment'];
    
    // Response
    sendResponse([
        'session_id' => $session_id,
        'ocean_scores' => $ocean,
        'test_counts' => $counts,
        'averages' => $averages,
        'total_completed_tests' => $totalTests,
        'profiles' => [
            'ist' => $ocean,  // Aktuelles Profil
            'ideal' => $idealProfile,
            'owner' => $ownerProfile
        ],
        'ai_assessment' => $aiAssessment
    ]);
}

else {
    sendError('Methode nicht erlaubt', 405);
}

?>
