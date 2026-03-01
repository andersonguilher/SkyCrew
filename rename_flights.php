<?php
require_once 'db_connect.php';

$stmt = $pdo->query("SELECT id, flight_number FROM flights_master");
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;

foreach ($flights as $f) {
    if (preg_match('/^([A-Z]+)(\d+)$/', $f['flight_number'], $matches)) {
        $prefix = $matches[1]; // KFY
        $numStr = $matches[2]; // 0110
        
        $newNum = intval($numStr) + 1000;
        
        $newFlightNumber = $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
        
        $updateStmt = $pdo->prepare("UPDATE flights_master SET flight_number = ? WHERE id = ?");
        $updateStmt->execute([$newFlightNumber, $f['id']]);
        $updated++;
        echo "Updated {$f['flight_number']} to {$newFlightNumber}\n";
    }
}

echo "Total updated: $updated\n";
?>
