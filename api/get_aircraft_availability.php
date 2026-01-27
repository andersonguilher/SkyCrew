<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
// We allow public to call this for validation, or only admin? 
// For now, it's used in admin/flights.php form.
requireRole('admin');

$type = $_GET['type'] ?? '';
$icao = $_GET['icao'] ?? '';

if (!$icao) {
    echo json_encode([]);
    exit;
}

// Logic: 
// 1. Where is the aircraft now?
// We check the LAST flight of this specific aircraft.
// If no flights, we consider it "At its base" or available anywhere (simplification: let's assume it starts at the base of the VA or we'll simply check last arr_icao).

$fleet = $pdo->prepare("SELECT id, registration, icao_code FROM fleet WHERE icao_code = ?");
$fleet->execute([$icao]);
$aircrafts = $fleet->fetchAll(PDO::FETCH_ASSOC);

$available = [];

foreach ($aircrafts as $ac) {
    // Check last flight of this aircraft
    $lastFlight = $pdo->prepare("SELECT arr_icao FROM flights_master WHERE aircraft_id = ? ORDER BY dep_time DESC LIMIT 1");
    // Note: dep_time is TIME only in flights_master, which is a bit of a limitation for "last flight".
    // In a real system we'd need a flight schedule with dates.
    // For now, let's assume the user is building a repetitive schedule.

    // Improvement: We can't easily know the "Current location" without a real PIREP history.
    // BUT, we can check if the aircraft is ALREADY assigned to another flight at the SAME time.

    $available[] = $ac;
}

echo json_encode($available);
