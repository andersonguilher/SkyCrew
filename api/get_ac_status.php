<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo json_encode(['error' => 'ID missing']);
    exit;
}

// 1. Get info from fleet
$stmt = $pdo->prepare("SELECT current_icao FROM fleet WHERE id = ?");
$stmt->execute([$id]);
$ac = $stmt->fetch();

if (!$ac) {
    echo json_encode(['error' => 'Aircraft not found']);
    exit;
}

// 2. Get full schedule for today
$stmt = $pdo->prepare("SELECT flight_number, dep_icao, arr_icao, dep_time, arr_time FROM flights_master WHERE aircraft_id = ? ORDER BY dep_time ASC");
$stmt->execute([$id]);
$schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lastFlight = end($schedule);

$location = $lastFlight['arr_icao'] ?? $ac['current_icao'] ?? 'SBGR';
$readyAt = $lastFlight['arr_time'] ?? '00:00:00';

echo json_encode([
    'current_location' => $location,
    'ready_at' => substr($readyAt, 0, 5),
    'schedule' => $schedule
]);
?>