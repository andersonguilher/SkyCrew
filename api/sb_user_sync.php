<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

header('Content-Type: application/json');

$username = $_GET['username'] ?? '';
$pilotId = getCurrentPilotId($pdo);

if (!$username || !$pilotId) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

try {
    // 1. First Verify with SimBrief
    $url = "https://www.simbrief.com/api/xml.fetcher.php?username=" . urlencode($username) . "&json=1";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        if (isset($json['fetch']) && strpos($json['fetch']['status'], 'Error') !== false) {
             echo json_encode(['success' => false, 'error' => 'Usuário não existe no SimBrief']);
             exit;
        }

        // 2. Update DB
        $stmt = $pdo->prepare("UPDATE pilots SET simbrief_username = ? WHERE id = ?");
        $stmt->execute([$username, $pilotId]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Erro na conexão com SimBrief']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
