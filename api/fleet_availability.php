<?php
/**
 * API: Fleet availability - returns available models and locations for fleet management.
 * 
 * GET ?action=models_at&icao=SBGR
 *   → Returns models that have routes arriving at SBGR (or models with no routes)
 * 
 * GET ?action=locations_for&model=A20N
 *   → Returns ICAOs where this model's routes arrive (departure ICAOs of outbound legs)
 * 
 * GET ?action=available_aircraft
 *   → Returns aircraft not currently assigned to any route pair
 */
require_once '../db_connect.php';
require_once '../includes/auth_session.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'models_at':
        // Models whose routes arrive at (or depart from) this ICAO
        $icao = strtoupper(trim($_GET['icao'] ?? ''));
        if (strlen($icao) < 3) {
            echo json_encode(['models' => []]);
            exit;
        }
        
        // Models that operate at this ICAO (either dep or arr in any flight)
        // Plus models with NO routes at all
        $stmt = $pdo->prepare("
            SELECT DISTINCT am.icao, am.model_name, am.cruise_speed, am.max_pax
            FROM aircraft_models am
            WHERE am.icao IN (
                -- Models that have flights dep/arr at this ICAO
                SELECT DISTINCT aircraft_type FROM flights_master WHERE dep_icao = ? OR arr_icao = ?
            )
            OR am.icao NOT IN (
                -- Models with no flights at all
                SELECT DISTINCT aircraft_type FROM flights_master
            )
            ORDER BY am.model_name
        ");
        $stmt->execute([$icao, $icao]);
        echo json_encode(['models' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'locations_for':
        // ICAOs where this model operates (from route pairs)
        $model = strtoupper(trim($_GET['model'] ?? ''));
        if (!$model) {
            echo json_encode(['locations' => []]);
            exit;
        }
        
        // Get all distinct dep/arr ICAOs for this model's flights
        $stmt = $pdo->prepare("
            SELECT DISTINCT icao FROM (
                SELECT dep_icao as icao FROM flights_master WHERE aircraft_type = ?
                UNION
                SELECT arr_icao as icao FROM flights_master WHERE aircraft_type = ?
            ) t
            ORDER BY icao
        ");
        $stmt->execute([$model, $model]);
        $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If model has no routes, return empty (aircraft can be placed anywhere)
        echo json_encode([
            'locations' => $locations,
            'any_location' => empty($locations) // true = model has no routes, can go anywhere
        ]);
        break;
        
    case 'available_aircraft':
        // Aircraft not assigned to any route
        $model = strtoupper(trim($_GET['model'] ?? ''));
        $icao = strtoupper(trim($_GET['icao'] ?? ''));
        
        $sql = "SELECT f.id, f.registration, f.icao_code, f.current_icao 
                FROM fleet f 
                WHERE f.id NOT IN (SELECT DISTINCT aircraft_id FROM flights_master)";
        $params = [];
        
        if ($model) {
            $sql .= " AND f.icao_code = ?";
            $params[] = $model;
        }
        if ($icao) {
            $sql .= " AND f.current_icao = ?";
            $params[] = $icao;
        }
        
        $sql .= " ORDER BY f.registration";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['aircraft' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    default:
        echo json_encode(['error' => 'Unknown action. Use: models_at, locations_for, available_aircraft']);
}
