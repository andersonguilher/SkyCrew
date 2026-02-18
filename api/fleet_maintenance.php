<?php
// api/fleet_maintenance.php
// Returns maintenance component status and history for a specific aircraft
header('Content-Type: application/json');
require_once '../db_connect.php';
require_once '../includes/auth_session.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$fleetId = intval($_GET['fleet_id'] ?? 0);
$action = $_GET['action'] ?? 'status';

if (!$fleetId) {
    http_response_code(400);
    echo json_encode(['error' => 'fleet_id required']);
    exit;
}

// Get aircraft info
$stmt = $pdo->prepare("SELECT f.*, am.model_name FROM fleet f LEFT JOIN aircraft_models am ON am.icao = f.icao_code WHERE f.id = ?");
$stmt->execute([$fleetId]);
$aircraft = $stmt->fetch();

if (!$aircraft) {
    http_response_code(404);
    echo json_encode(['error' => 'Aircraft not found']);
    exit;
}

if ($action === 'status') {
    // Get component status
    $stmt = $pdo->prepare("
        SELECT fch.id, fch.hours_since_maintenance, fch.last_maintenance_at,
               am.component_name, am.interval_fh, am.cost_preventive, am.cost_incident,
               (fch.hours_since_maintenance / am.interval_fh * 100) as pct_used,
               (am.interval_fh - fch.hours_since_maintenance) as hours_remaining
        FROM fleet_component_hours fch
        JOIN aircraft_maintenance am ON fch.maintenance_component_id = am.id
        WHERE fch.fleet_id = ?
        ORDER BY pct_used DESC
    ");
    $stmt->execute([$fleetId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent maintenance log
    $stmtLog = $pdo->prepare("
        SELECT fml.*, DATE_FORMAT(fml.performed_at, '%d/%m/%Y %H:%i') as performed_at_fmt
        FROM fleet_maintenance_log fml
        WHERE fml.fleet_id = ?
        ORDER BY fml.performed_at DESC
        LIMIT 20
    ");
    $stmtLog->execute([$fleetId]);
    $log = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total maintenance cost spent
    $stmtTotal = $pdo->prepare("SELECT COALESCE(SUM(cost), 0) as total_spent FROM fleet_maintenance_log WHERE fleet_id = ?");
    $stmtTotal->execute([$fleetId]);
    $totalSpent = (float)$stmtTotal->fetchColumn();

    echo json_encode([
        'aircraft' => [
            'id' => $aircraft['id'],
            'registration' => $aircraft['registration'],
            'icao_code' => $aircraft['icao_code'],
            'model_name' => $aircraft['model_name'] ?? $aircraft['fullname'],
            'total_flight_hours' => (float)$aircraft['total_flight_hours'],
            'current_icao' => $aircraft['current_icao']
        ],
        'components' => $components,
        'maintenance_log' => $log,
        'total_maintenance_cost' => $totalSpent
    ]);
} elseif ($action === 'history') {
    // Full maintenance history with pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare("
        SELECT fml.*, DATE_FORMAT(fml.performed_at, '%d/%m/%Y %H:%i') as performed_at_fmt
        FROM fleet_maintenance_log fml
        WHERE fml.fleet_id = ?
        ORDER BY fml.performed_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$fleetId, $limit, $offset]);
    $log = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fleet_maintenance_log WHERE fleet_id = ?");
    $countStmt->execute([$fleetId]);
    $totalCount = (int)$countStmt->fetchColumn();

    echo json_encode([
        'log' => $log,
        'total' => $totalCount,
        'page' => $page,
        'pages' => ceil($totalCount / $limit)
    ]);
}
