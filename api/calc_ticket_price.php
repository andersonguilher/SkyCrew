<?php
// api/calc_ticket_price.php
// Returns the calculated ticket price for a given aircraft model and flight duration
require_once '../db_connect.php';
header('Content-Type: application/json');

$modelIcao = strtoupper(trim($_GET['model'] ?? ''));
$durationMinutes = intval($_GET['duration'] ?? 0);

if (!$modelIcao || !$durationMinutes) {
    echo json_encode(['error' => 'Missing model or duration', 'ticket_price' => 0]);
    exit;
}

$settings = getSystemSettings($pdo);
$markup = (float)($settings['ticket_markup'] ?? 700);
$hours = $durationMinutes / 60;

// Get maintenance cost per flight hour for this model
$stmt = $pdo->prepare("
    SELECT am.max_pax, am.icao,
           SUM(ac.cost_preventive / ac.interval_fh) as maint_per_fh
    FROM aircraft_models am
    JOIN aircraft_maintenance ac ON ac.model_icao = am.icao
    WHERE am.icao = ?
    GROUP BY am.icao
");
$stmt->execute([$modelIcao]);
$data = $stmt->fetch();

if (!$data || !$data['max_pax']) {
    echo json_encode(['error' => 'Model not found or no maintenance data', 'ticket_price' => 0, 'model' => $modelIcao]);
    exit;
}

$maintPerFH = (float)$data['maint_per_fh'];
$maxPax = (int)$data['max_pax'];

// Formula: ticket_price = (maint_per_fh × hours / max_pax) × markup
$ticketPrice = round(($maintPerFH * $hours / $maxPax) * $markup, 2);

echo json_encode([
    'model' => $modelIcao,
    'max_pax' => $maxPax,
    'duration_minutes' => $durationMinutes,
    'duration_hours' => round($hours, 2),
    'maint_per_fh' => round($maintPerFH, 2),
    'markup' => $markup,
    'ticket_price' => $ticketPrice,
    'formula' => "({$maintPerFH} × {$hours}h / {$maxPax} pax) × {$markup}"
]);
