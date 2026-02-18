<?php
require_once 'db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Setting up Maintenance Tracking per Aircraft ===\n";

// 1. Add total_flight_hours to fleet table
try {
    $pdo->exec("ALTER TABLE fleet ADD COLUMN total_flight_hours DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Total de horas voadas pela aeronave'");
    echo "fleet.total_flight_hours added\n";
} catch (Exception $e) {
    echo "fleet.total_flight_hours: " . $e->getMessage() . "\n";
}

// 2. Create fleet_component_hours - tracks hours per component per aircraft (registration)
$pdo->exec("
CREATE TABLE IF NOT EXISTS fleet_component_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fleet_id INT NOT NULL,
    maintenance_component_id INT NOT NULL,
    hours_since_maintenance DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Horas desde última manutenção',
    last_maintenance_at TIMESTAMP NULL COMMENT 'Data da última manutenção',
    UNIQUE KEY uk_fleet_comp (fleet_id, maintenance_component_id),
    FOREIGN KEY (fleet_id) REFERENCES fleet(id) ON DELETE CASCADE,
    FOREIGN KEY (maintenance_component_id) REFERENCES aircraft_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "fleet_component_hours table created\n";

// 3. Create fleet_maintenance_log - history of maintenances performed
$pdo->exec("
CREATE TABLE IF NOT EXISTS fleet_maintenance_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fleet_id INT NOT NULL,
    maintenance_component_id INT NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    hours_at_maintenance DECIMAL(10,2) NOT NULL COMMENT 'Horas acumuladas quando a manutenção ocorreu',
    cost DECIMAL(15,2) NOT NULL COMMENT 'Custo cobrado (preventivo)',
    maintenance_type ENUM('PREVENTIVE','CORRECTIVE') NOT NULL DEFAULT 'PREVENTIVE',
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fleet_id) REFERENCES fleet(id) ON DELETE CASCADE,
    FOREIGN KEY (maintenance_component_id) REFERENCES aircraft_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "fleet_maintenance_log table created\n";

// 4. Initialize fleet_component_hours for all existing fleet + components
$inserted = $pdo->exec("
INSERT IGNORE INTO fleet_component_hours (fleet_id, maintenance_component_id, hours_since_maintenance)
SELECT f.id, am.id, 0
FROM fleet f
JOIN aircraft_maintenance am ON am.model_icao = f.icao_code
");
echo "fleet_component_hours initialized: $inserted rows\n";

// 5. Show summary
$stmt = $pdo->query("
    SELECT f.registration, f.icao_code, f.total_flight_hours, COUNT(fch.id) as component_count
    FROM fleet f
    LEFT JOIN fleet_component_hours fch ON fch.fleet_id = f.id
    GROUP BY f.id
    ORDER BY f.icao_code, f.registration
    LIMIT 10
");
echo "\n=== Sample Fleet with Components ===\n";
foreach ($stmt as $row) {
    printf("  %s (%s): %.1fh | %d componentes\n", $row['registration'], $row['icao_code'], $row['total_flight_hours'], $row['component_count']);
}

echo "\n=== DONE ===\n";
