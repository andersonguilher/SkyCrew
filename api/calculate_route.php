<?php
require_once '../db_connect.php';

header('Content-Type: application/json');

if (!isset($_GET['dep']) || !isset($_GET['arr'])) {
    echo json_encode(['error' => 'Missing ICAO']);
    exit;
}

$dep = strtoupper($_GET['dep']);
$arr = strtoupper($_GET['arr']);
$ac = strtoupper($_GET['ac'] ?? 'B738');

try {
    // 1. Get Coordinates
    $stmt = $pdo->prepare("SELECT ident, latitude_deg, longitude_deg FROM airports WHERE ident = ? OR icao_code = ? LIMIT 1");

    $stmt->execute([$dep, $dep]);
    $dCoord = $stmt->fetch();

    $stmt->execute([$arr, $arr]);
    $aCoord = $stmt->fetch();

    if (!$dCoord || !$aCoord) {
        // Fallback: Use SimBrief API Fetcher if possible, but for now just error/estimate
        echo json_encode(['error' => 'Airport not found in local DB']);
        exit;
    }

    // 2. Calculate Distance (Haversine)
    function distance($lat1, $lon1, $lat2, $lon2)
    {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $nm = $miles * 0.8684;
        return $nm;
    }

    $dist = distance($dCoord['latitude_deg'], $dCoord['longitude_deg'], $aCoord['latitude_deg'], $aCoord['longitude_deg']);

    // 3. Estimate Duration
    // Cruise Speed Avg (knots)
    $speed = 450;
    if (strpos($ac, '320') !== false || strpos($ac, '738') !== false)
        $speed = 450;
    if (strpos($ac, '777') !== false || strpos($ac, '787') !== false || strpos($ac, '330') !== false)
        $speed = 480;
    if (strpos($ac, 'ATR') !== false)
        $speed = 280;
    if (strpos($ac, 'C172') !== false)
        $speed = 110;

    // Time = Distance / Speed
    // Add 30 mins for taxi/climb/descend overhead
    $hours = $dist / $speed;
    $minutes = ($hours * 60) + 30; // +30 min buffer

    echo json_encode([
        'distance' => round($dist),
        'duration' => round($minutes),
        'origin' => $dCoord['ident'],
        'dest' => $aCoord['ident']
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
