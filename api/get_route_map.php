<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

header('Content-Type: application/json');

try {
    // Get all flights with their airport coordinates
    $stmt = $pdo->query("
        SELECT 
            f.flight_number, 
            f.dep_icao, 
            f.arr_icao, 
            a1.latitude_deg as dep_lat, 
            a1.longitude_deg as dep_lon,
            a2.latitude_deg as arr_lat, 
            a2.longitude_deg as arr_lon,
            fl.registration,
            fl.icao_code as ac_type
        FROM flights_master f
        LEFT JOIN airports a1 ON f.dep_icao = a1.ident
        LEFT JOIN airports a2 ON f.arr_icao = a2.ident
        LEFT JOIN fleet fl ON f.aircraft_id = fl.id
    ");

    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get fleet with current locations
    $fleetStmt = $pdo->query("
        SELECT 
            f.registration, 
            f.icao_code, 
            f.current_icao,
            a.latitude_deg as lat,
            a.longitude_deg as lon
        FROM fleet f
        LEFT JOIN airports a ON f.current_icao = a.ident
    ");
    $fleet = $fleetStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'routes' => $routes,
        'fleet' => $fleet
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
