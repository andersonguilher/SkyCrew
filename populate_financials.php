<?php
require_once 'db_connect.php';

echo "Updating Fleet Maintenance Rates...\n";
$rates = [
    'A320' => 35.00,
    'A321' => 38.00,
    'A20N' => 32.00,
    'B738' => 36.00,
    'B77L' => 120.00,
    'C208' => 8.00,
    'BE58' => 3.50,
];

foreach ($rates as $icao => $rate) {
    $stmt = $pdo->prepare("UPDATE fleet SET maintenance_rate = ? WHERE icao_code = ?");
    $stmt->execute([$rate, $icao]);
    echo "Set $icao to R$ $rate/min\n";
}

echo "\nUpdating Flights Ticket Prices...\n";
$stmt = $pdo->query("SELECT id, dep_icao, arr_icao, duration_minutes FROM flights_master");
$flights = $stmt->fetchAll();

foreach ($flights as $f) {
    $price = 500.00; // Default
    $dep = strtoupper($f['dep_icao']);
    $arr = strtoupper($f['arr_icao']);
    
    // Specific examples from user
    if (($dep == 'SBRJ' && $arr == 'SBSP') || ($dep == 'SBSP' && $arr == 'SBRJ')) {
        $price = 443.00;
    } elseif (($dep == 'SBSP' && $arr == 'SBFZ') || ($dep == 'SBFZ' && $arr == 'SBSP')) {
        $price = 2000.00;
    } elseif (($dep == 'SBGL' && $arr == 'KMIA') || ($dep == 'KMIA' && $arr == 'SBGL')) {
        $price = 3000.00;
    } else {
        // Linear interpolation based on examples
        // 1h (60m) -> 443
        // 3.5h (210m) -> 2000
        // (~2000-443) / (210-60) = 1557 / 150 = 10.38 per minute
        // Base = 443 - (60 * 10.38) = -179.8
        // Let's use simpler: duration_minutes * 10 
        $price = $f['duration_minutes'] * 8.5; // Roughly R$ 510 per hour
    }
    
    // Ensure min price
    $price = max(150.00, $price);
    
    $update = $pdo->prepare("UPDATE flights_master SET ticket_price = ? WHERE id = ?");
    $update->execute([$price, $f['id']]);
    echo "Voo {$f['id']} ($dep->$arr): R$ " . number_format($price, 2) . "\n";
}

echo "\nDone!\n";
