<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

header('Content-Type: application/json');

try {
    // Get all flights with their airport coordinates
    $stmt = $pdo->query("
        SELECT 
            f.id,
            f.flight_number, 
            f.dep_icao, 
            f.arr_icao, 
            a1.latitude_deg as dep_lat, 
            a1.longitude_deg as dep_lon,
            a2.latitude_deg as arr_lat, 
            a2.longitude_deg as arr_lon,
            fl.registration,
            fl.icao_code as ac_type,
            f.dep_time,
            f.arr_time,
            f.route_waypoints,
            (SELECT status FROM roster_assignments WHERE flight_id = f.id ORDER BY assigned_at DESC LIMIT 1) as roster_status
        FROM flights_master f
        LEFT JOIN airports a1 ON f.dep_icao = a1.ident
        LEFT JOIN airports a2 ON f.arr_icao = a2.ident
        LEFT JOIN fleet fl ON f.aircraft_id = fl.id
    ");

    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($routes);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
