<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

header('Content-Type: application/json');

$username = $_GET['username'] ?? '';
if (!$username) {
    echo json_encode(['success' => false, 'error' => 'Username required']);
    exit;
}

$url = "https://www.simbrief.com/api/xml.fetcher.php?username=" . urlencode($username) . "&json=1";

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        
        if (isset($json['fetch']) && strpos($json['fetch']['status'], 'Error') !== false) {
             echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        } else {
            // Success - extract some info
            $lastOFP = $json['params']['time_generated'] ?? null;
            $flightNum = $json['general']['flight_number'] ?? '---';
            $origin = $json['general']['origin'] ?? '---';
            $dest = $json['general']['destination'] ?? '---';
            
            echo json_encode([
                'success' => true, 
                'username' => $json['params']['username'] ?? $username,
                'last_plan' => $lastOFP ? date('d/m/Y H:i', (int)$lastOFP) : 'Nenhum plano recente',
                'flight' => $flightNum,
                'route' => "$origin ($dest)"
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Integração SimBrief indisponível']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
