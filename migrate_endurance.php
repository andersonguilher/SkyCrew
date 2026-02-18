<?php
require_once 'db_connect.php';

try {
    // 1. Add column if not exists
    $pdo->exec("ALTER TABLE aircraft_models ADD COLUMN IF NOT EXISTS max_flight_time DECIMAL(4,1) DEFAULT 0.0");
    echo "Column max_flight_time added or already exists.\n";

    // 2. Update existing models based on the table provided by the user
    $endurances = [
        'C208' => 6.5,
        'B738' => 7.0,
        'A321' => 7.5,
        'A320' => 7.5,
        'A20N' => 8.0,
        'A333' => 14.0,
        'B77L' => 18.0
    ];

    $stmt = $pdo->prepare("UPDATE aircraft_models SET max_flight_time = ? WHERE icao = ?");
    foreach ($endurances as $icao => $hours) {
        $stmt->execute([$hours, $icao]);
        echo "Updated $icao with $hours hours.\n";
    }

    echo "Migration completed successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
