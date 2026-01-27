<?php
// api/acars.php
// Endpoint para receber dados do Toolbar MSFS (ACARS)
header('Content-Type: application/json');
require_once '../db_connect.php';

// Check Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Get JSON Input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
// Expected: email, password (or token), roster_id, flight_time, fuel_used, landing_rate
if (!isset($data['email']) || !isset($data['roster_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// 1. Authenticate Pilot inside API mainly for security
// In production, use Bearer Token, but for MVP/Toolbar simple auth:
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$data['email']]);
$user = $stmt->fetch();

// You might want to actually check password here if the toolbar prompts for it,
// or generate an API Key for the pilot user.
// For simplicitly, we assume the toolbar sends a valid 'api_key' or we trust the email if simple local setup.
// Let's implement Password check if provided, or proceed if trusted env.
// WE WILL REQUIRE PASSWORD for security proof of concept.
if (!$user || !password_verify($data['password'], $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get Pilot ID
$stmt = $pdo->prepare("SELECT id FROM pilots WHERE user_id = ?");
$stmt->execute([$user['id']]);
$pilot = $stmt->fetch();
$pilotId = $pilot['id'];

// 2. Process Flight Data
$rosterId = $data['roster_id'];
$flightTime = $data['flight_time'];
$fuelUsed = $data['fuel_used'];
$landingRate = $data['landing_rate'];
$incidents = isset($data['incidents']) ? json_encode($data['incidents']) : null;
$comments = "Auto-filing via ACARS Toolbar";

try {
    $pdo->beginTransaction();

    // Check if report already exists for this roster?
    // ... logic skipped for brevity, assuming simple insert

    // Insert Report
    $stmt = $pdo->prepare("INSERT INTO flight_reports (pilot_id, roster_id, flight_time, fuel_used, landing_rate, comments, incidents, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");
    $stmt->execute([$pilotId, $rosterId, $flightTime, $fuelUsed, $landingRate, $comments, $incidents]);

    // Update Roster to Flown
    $stmt = $pdo->prepare("UPDATE roster_assignments SET status = 'Flown' WHERE id = ?");
    $stmt->execute([$rosterId]);

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'PIREP received and filed via ACARS.']);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database Error: ' . $e->getMessage()]);
}
?>