<?php
require_once '../db_connect.php';

header('Content-Type: application/json');

// 0. Auth & Input Check
$settings = getSystemSettings($pdo);
// Allow override from frontend if prompting user
$username = $_GET['sb_user'] ?? $settings['simbrief_username'] ?? '';
$username = trim($username);

if (!$username) {
    echo json_encode(['error' => 'USERNAME_REQUIRED']);
    exit;
}

$dep = $_GET['dep'] ?? '';
$arr = $_GET['arr'] ?? '';
$ac = $_GET['ac'] ?? '';

if (!$dep || !$arr || !$ac) {
    echo json_encode(['error' => 'Faltam dados (Dep, Arr ou Aeronave).']);
    exit;
}

// 1. Fetch Aircraft Speed for fallback
$ac_id = $_GET['ac_id'] ?? 0;
$stmt = $pdo->prepare("SELECT cruise_speed FROM fleet WHERE id = ? OR icao_code = ? LIMIT 1");
$stmt->execute([$ac_id, $ac]);
$acData = $stmt->fetch();
$cruiseSpeed = ($acData['cruise_speed'] ?? 450) ?: 450;

// 2. Helper for Distance Fallback
function calculateEstimate($pdo, $dep, $arr, $cruiseSpeed)
{
    // Try to get coords
    $stmt = $pdo->prepare("SELECT ident, latitude_deg, longitude_deg FROM airports WHERE ident IN (?, ?)");
    $stmt->execute([$dep, $arr]);
    $airports = $stmt->fetchAll(PDO::FETCH_UNIQUE);

    if (count($airports) < 2)
        return null;

    $lat1 = $airports[$dep]['latitude_deg'];
    $lon1 = $airports[$dep]['longitude_deg'];
    $lat2 = $airports[$arr]['latitude_deg'];
    $lon2 = $airports[$arr]['longitude_deg'];

    // Haversine
    $rad = M_PI / 180;
    $dist = acos(sin($lat2 * $rad) * sin($lat1 * $rad) + cos($lat2 * $rad) * cos($lat1 * $rad) * cos(($lon2 - $lon1) * $rad)) * 6371; // km
    $nm = $dist * 0.539957;

    // Time = (Dist / Speed) * 60 + 20 min (climb/taxi)
    $mins = ($nm / $cruiseSpeed) * 60 + 20;

    return [
        'status' => 'estimate',
        'duration_minutes' => round($mins),
        'route' => 'DCT',
        'distance_nm' => round($nm)
    ];
}

// 3. Build SimBrief Fetch URL
$url = "https://www.simbrief.com/api/xml.fetcher.php";
$params = [
    'username' => $username,
    'json' => 1
];

$query = http_build_query($params);
$target = "$url?$query";

$sbError = '';

// 2. Fetch
try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        if ($json && (isset($json['general']) || isset($json['params']))) {
            $sbOrigin = $json['general']['origin'] ?? $json['params']['orig'] ?? '';
            $sbDest = $json['general']['destination'] ?? $json['params']['dest'] ?? '';

            if (strtoupper($sbOrigin) === strtoupper($dep) && strtoupper($sbDest) === strtoupper($arr)) {
                $durationSeconds = $json['times']['est_time_enroute'] ?? 0;
                echo json_encode([
                    'status' => 'success',
                    'duration_minutes' => floor($durationSeconds / 60),
                    'route' => $json['general']['route'] ?? '',
                    'source' => 'SimBrief',
                    'icao_type' => $json['general']['icao_type'] ?? ''
                ]);
                exit;
            }
        }
    }

    // 4. Build Signed Generation Request
    $apiKey = $settings['simbrief_api_key'] ?? '';
    $timestamp = time();
    $outputPage = "manual_fetch";
    // Sign with API key using SimBrief's internal logic
    $apiSig = md5($apiKey . $dep . $arr . $ac . $timestamp . $outputPage);
    $apiCode = strtoupper(substr($apiSig, 0, 10));

    $genUrl = "https://www.simbrief.com/api/xml.gen.php";
    $genParams = [
        'user_id' => $username,
        'orig' => $dep,
        'dest' => $arr,
        'type' => $ac,
        'apicode' => $apiCode,
        'timestamp' => $timestamp,
        'outputpage' => $outputPage,
        'json' => 1
    ];
    $genTarget = "$genUrl?" . http_build_query($genParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $genTarget);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25); // Generation takes time
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // Mimic the header JS might send
    curl_setopt($ch, CURLOPT_USERAGENT, 'SkyCrewVA-Admin/1.0');
    $genResponse = curl_exec($ch);
    $genHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // LOG RAW RESPONSE FOR ADMIN
    file_put_contents(__DIR__ . '/simbrief_debug.log', "Target: $genTarget\nResponse: $genResponse\n\n", FILE_APPEND);

    if ($genHttpCode === 200 && $genResponse) {
        $genJson = json_decode($genResponse, true);
        if ($genJson && (isset($genJson['general']) || isset($genJson['params']))) {
            $durationSeconds = $genJson['times']['est_time_enroute'] ?? 0;
            $route = $genJson['general']['route'] ?? '';

            if ($route) {
                echo json_encode([
                    'status' => 'success',
                    'duration_minutes' => floor($durationSeconds / 60),
                    'route' => $route,
                    'source' => 'SimBrief (API v1 Signed)',
                    'icao_type' => $genJson['general']['icao_type'] ?? ''
                ]);
                exit;
            } else if (isset($genJson['fetch']['status']) && $genJson['fetch']['status'] === 'error') {
                $sbError = $genJson['fetch']['status_msg'] ?? 'SimBrief: Erro na geração';
            }
        }
    }

    // 5. Final Fallback to Estimate
    $estimate = calculateEstimate($pdo, $dep, $arr, $cruiseSpeed);
    if ($estimate) {
        $estimate['sb_error'] = $sbError ?: 'Plano não encontrado no SB';
        echo json_encode($estimate);
    } else {
        throw new Exception("SimBrief Error: " . ($sbError ?: "Aeroportos não encontrados localmente."));
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
