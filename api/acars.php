<?php
// api/acars.php
// Endpoint para receber dados do Toolbar MSFS (ACARS)
header('Content-Type: application/json');
require_once '../db_connect.php';

// Detect if running from CLI or Web
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Get JSON Input
if ($isCLI) {
    // In CLI, we take the JSON from the first argument
    $input = $argv[1] ?? '';
} else {
    $input = file_get_contents('php://input');
}

$data = json_decode($input, true);

if (!$data) {
    if (!$isCLI) http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
if (!isset($data['email']) || !isset($data['roster_id'])) {
    if (!$isCLI) http_response_code(400);
    echo json_encode(['error' => 'Missing required identification fields (email, roster_id)']);
    exit;
}

// 1. Authenticate Request
$settings = getSystemSettings($pdo);
$internalKey = $settings['internal_api_key'] ?? null;
$isAuthenticated = $isCLI; // If CLI, we assume local trust

// Check if it's the internal WebSocket server
if ($internalKey && isset($data['api_key']) && $data['api_key'] === $internalKey) {
    $isAuthenticated = true;
}

// Fallback to Pilot Password (if provided)
$stmt = $pdo->prepare("SELECT u.*, p.id as pilot_id, p.rank FROM users u JOIN pilots p ON u.id = p.user_id WHERE u.email = ?");
$stmt->execute([$data['email']]);
$user = $stmt->fetch();

if (!$isAuthenticated) {
    if (!$user || !isset($data['password']) || !password_verify($data['password'], $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} elseif (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'Pilot not found for the provided email']);
    exit;
}

$pilotId = $user['pilot_id'];
$rosterId = $data['roster_id'];

// 2. Fetch System Settings for Calculations
$settings = getSystemSettings($pdo);
$fuelPrice = (float)($settings['fuel_price'] ?? 2.50);
$maintenanceRate = (float)($settings['maintenance_per_minute'] ?? 1.00);
$parkingRate = (float)($settings['airport_fee_parking_per_minute'] ?? 0.50);
$ticketPriceRate = (float)($settings['passenger_ticket_price'] ?? 500.00);

// Fetch Roster/Flight Data
$stmt = $pdo->prepare("
    SELECT fm.*, r.flight_date, fl.maintenance_rate as ac_maintenance_rate
    FROM roster_assignments r 
    JOIN flights_master fm ON r.flight_id = fm.id 
    LEFT JOIN fleet fl ON fm.aircraft_id = fl.id
    WHERE r.id = ?
");
$stmt->execute([$rosterId]);
$flightInfo = $stmt->fetch();

if (!$flightInfo) {
    http_response_code(404);
    echo json_encode(['error' => 'Roster assignment not found']);
    exit;
}

// 3. Extract Data from Log
$events = $data['Events'] ?? [];
$landing = $data['Landing'] ?? [];
$startTimeStr = $data['StartTime'] ?? date('c');
$endTimeStr = $data['EndTime'] ?? date('c');
$fuelConsumed = (float)($data['FuelConsumed'] ?? 0);
$verticalSpeed = (float)($landing['VerticalSpeed'] ?? 0);

$startTime = strtotime($startTimeStr);
$endTime = strtotime($endTimeStr);
$flightTimeHours = ($endTime - $startTime) / 3600;
$flightTimeMinutes = ($endTime - $startTime) / 60;

// 4. Financial Calculations
// Determine Pax Count (Strictly from log as per user request)
// Key can be 'pax', 'PaxCount', 'Passengers' or 'passenger_count'
$paxCount = (int)($data['pax'] ?? $data['PaxCount'] ?? $data['Passengers'] ?? $data['passenger_count'] ?? 0);

// Fallback logic: If missing in log, we could check flights_master.passenger_count 
// but the user said "virá no logo" (it will come in the log). 
// We'll use 0 if not provided, or 1 to avoid division by zero issues in other contexts (though not here).

// Size multiplier (using 100 pax as baseline for configured rates)
// This implements the user request: "definir pelo número de passageiros carregados"
$sizeFactor = $paxCount / 100;
if ($sizeFactor < 0.05) $sizeFactor = 0.05; // Minimum factor to avoid zero costs for ferry flights

// Simplified Fuel Calculation
$fuelCost = $fuelConsumed * $fuelPrice;

// Maintenance Cost: computed from aircraft model's component data
$aircraftType = $flightInfo['aircraft_type'] ?? '';
$stmtMaint = $pdo->prepare("
    SELECT COALESCE(SUM(cost_preventive / interval_fh), 0) as maint_per_fh
    FROM aircraft_maintenance WHERE model_icao = ?
");
$stmtMaint->execute([$aircraftType]);
$maintPerFH = (float)$stmtMaint->fetchColumn();

if ($maintPerFH > 0) {
    // Use real component-based costs
    $maintenanceCost = $maintPerFH * $flightTimeHours;
} else {
    // Fallback to flat rate from fleet or global settings
    $finalMaintRate = (float)($flightInfo['ac_maintenance_rate'] ?? $maintenanceRate);
    $maintenanceCost = $flightTimeMinutes * $finalMaintRate;
}

// Parking Fee calculation
$engineStartTime = null;
$touchdownTime = null;
$shutdownTime = null;

foreach ($events as $event) {
    if ($event['Phase'] === 'EngineStart' && !$engineStartTime) $engineStartTime = strtotime($event['Timestamp']);
    if ($event['Phase'] === 'Shutdown' && !$shutdownTime) $shutdownTime = strtotime($event['Timestamp']);
    if (($event['Phase'] === 'Approach' || $event['Phase'] === 'Rollout') && stripos($event['Message'], 'Touchdown') !== false && !$touchdownTime) {
        $touchdownTime = strtotime($event['Timestamp']);
    }
}
// Fallback for touchdown if not found in events
if (!$touchdownTime && isset($landing['Timestamp'])) $touchdownTime = strtotime($landing['Timestamp']);

// Scheduled Departure vs Actual Engine Start
$scheduledDepStr = $flightInfo['flight_date'] . ' ' . $flightInfo['dep_time'];
$scheduledDepTime = strtotime($scheduledDepStr);
$parkingBefore = ($engineStartTime && $engineStartTime > $scheduledDepTime) ? ($engineStartTime - $scheduledDepTime) : 0;
$parkingAfter = ($shutdownTime && $touchdownTime && $shutdownTime > $touchdownTime) ? ($shutdownTime - $touchdownTime) : 0;
$totalParkingMinutes = ($parkingBefore + $parkingAfter) / 60;
$airportFees = ($totalParkingMinutes * $parkingRate) * $sizeFactor;

// Passenger Revenue (using route-specific ticket price)
$routeTicketPrice = (float)($flightInfo['ticket_price'] ?? 500.00);
$revenue = $paxCount * $routeTicketPrice;

// Pilot Pay
$stmt = $pdo->prepare("SELECT pay_rate FROM ranks WHERE rank_name = ?");
$stmt->execute([$user['rank']]);
$payRate = (float)($stmt->fetchColumn() ?: 15.00);
$pilotPay = $flightTimeHours * $payRate;

// 5. Ranking Points
$points = 100; // Base points

// Punctuality penalty/bonus
if ($engineStartTime) {
    $delay = $engineStartTime - $scheduledDepTime;
    if ($delay <= 300) $points += 20; // Within 5 min
    else if ($delay > 900) $points -= 20; // Over 15 min late
}

// Landing Smoothness
if ($verticalSpeed >= -150) $points += 25; // Butter
else if ($verticalSpeed >= -300) $points += 10;
else if ($verticalSpeed >= -500) $points -= 15;
else $points -= 40; // Hard landing

// Errors in log
$errorCount = 0;
$criticalErrors = 0;
foreach ($events as $event) {
    if (isset($event['IsError']) && $event['IsError']) {
        $errorCount++;
        if (stripos($event['Message'], 'overspeed') !== false || 
            stripos($event['Message'], 'G-force') !== false || 
            stripos($event['Message'], 'Bank angle') !== false) {
            $criticalErrors++;
        }
    }
}
$points -= ($errorCount * 10);
$points -= ($criticalErrors * 40);

// Ensure points don't go below 0 for a flight (optional)
$points = max(0, $points);

try {
    $pdo->beginTransaction();

    $logJson = json_encode($data);
    $incidents = $criticalErrors > 0 ? "Critical flight errors detected!" : null;
    $comments = "Auto-validated via Advanced ACARS Client";

    $stmt = $pdo->prepare("
        INSERT INTO flight_reports 
        (pilot_id, roster_id, flight_time, fuel_used, landing_rate, pax, revenue, 
         comments, incidents, status, log_json, vertical_speed_touchdown, 
         points, maintenance_cost, airport_fees, fuel_cost, pilot_pay) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $pilotId, $rosterId, round($flightTimeHours, 2), $fuelConsumed, (int)$verticalSpeed, 
        $paxCount, $revenue, $comments, $incidents, $logJson, (int)$verticalSpeed, 
        $points, $maintenanceCost, $airportFees, $fuelCost, $pilotPay
    ]);

    // 7. Update Pilot Stats (Hours, Balance, Rank, Points)
    $newHours = $user['total_hours'] + $flightTimeHours;
    $newPoints = $user['points'] + $points;
    $newBalance = $user['balance'] + $pilotPay;

    // Check for Promotion
    $nextRankStmt = $pdo->prepare("SELECT * FROM ranks WHERE min_hours <= ? ORDER BY min_hours DESC LIMIT 1");
    $nextRankStmt->execute([$newHours]);
    $newRankData = $nextRankStmt->fetch();
    $newRank = $newRankData ? $newRankData['rank_name'] : $user['rank'];

    $updatePilot = $pdo->prepare("UPDATE pilots SET total_hours = ?, balance = ?, `rank` = ?, points = ? WHERE id = ?");
    $updatePilot->execute([$newHours, $newBalance, $newRank, $newPoints, $pilotId]);

    // 8. Update Aircraft Position and Roster Status
    $stmt = $pdo->prepare("UPDATE fleet f JOIN flights_master fm ON f.id = fm.aircraft_id SET f.current_icao = fm.arr_icao WHERE fm.id = ?");
    $stmt->execute([$flightInfo['id']]);

    $stmt = $pdo->prepare("UPDATE roster_assignments SET status = 'Flown' WHERE id = ?");
    $stmt->execute([$rosterId]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success', 
        'message' => 'PIREP received and auto-validated.',
        'details' => [
            'pax' => $paxCount,
            'points' => $points,
            'new_rank' => $newRank,
            'revenue' => number_format($revenue, 2),
            'pilot_earnings' => number_format($pilotPay, 2)
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$isCLI) http_response_code(500);
    echo json_encode(['error' => 'Database Error: ' . $e->getMessage()]);
}
