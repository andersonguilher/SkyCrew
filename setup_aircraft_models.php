<?php
require_once 'db_connect.php';

echo "=== Setting up Aircraft Maintenance Components ===\n";

// 1. Create aircraft_maintenance table (references aircraft_models.icao)
$pdo->exec("
CREATE TABLE IF NOT EXISTS aircraft_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    model_icao VARCHAR(10) CHARACTER SET utf8mb3 NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    cost_incident DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Custo Manutenção Corretiva',
    cost_preventive DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Custo Manutenção Preventiva',
    interval_fh INT NOT NULL DEFAULT 1000 COMMENT 'Intervalo em Horas de Voo',
    FOREIGN KEY (model_icao) REFERENCES aircraft_models(icao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
");
echo "aircraft_maintenance table OK\n";

// 2. Update aircraft_models max_pax with correct values from user data
$updates = [
    ['C208', 'Cessna 208 Caravan', 14],
    ['A20N', 'Airbus A320neo', 195],
    ['A320', 'Airbus A320-200', 180],
    ['B738', 'Boeing 737-800', 189],
    ['A321', 'Airbus A321', 236],
    ['A333', 'Airbus A330-300', 440],
    ['B77L', 'Boeing 777-200LR', 440],
];
$upd = $pdo->prepare("UPDATE aircraft_models SET max_pax = ?, model_name = ? WHERE icao = ?");
foreach ($updates as $u) {
    $upd->execute([$u[2], $u[1], $u[0]]);
    echo "  Updated {$u[0]}: {$u[1]} ({$u[2]} pax)\n";
}

// 3. Clear old maintenance components and insert new
$pdo->exec("DELETE FROM aircraft_maintenance");

$components = [
    ["C208", "Pneu Principal", 2088.00, 1305.00, 300],
    ["C208", "Atuador de Flap", 41760.00, 20880.00, 10000],
    ["C208", "Atuador de Profundor", 26100.00, 13050.00, 10000],
    ["C208", "Amortecedor / Garfo", 52200.00, 18270.00, 15000],
    ["A20N", "Pneu Principal", 13050.00, 6525.00, 600],
    ["A20N", "Atuador de Flap", 730800.00, 156600.00, 25000],
    ["A20N", "Atuador de Profundor", 522000.00, 130500.00, 25000],
    ["A20N", "Trem de Pouso / Amort.", 3132000.00, 1983600.00, 30000],
    ["A320", "Pneu Principal", 12528.00, 6264.00, 500],
    ["A320", "Atuador de Flap", 626400.00, 130500.00, 25000],
    ["A320", "Atuador de Profundor", 469800.00, 104400.00, 25000],
    ["A320", "Trem de Pouso / Amort.", 2610000.00, 1827000.00, 30000],
    ["B738", "Pneu Principal", 12006.00, 6003.00, 500],
    ["B738", "Atuador de Flap", 574200.00, 104400.00, 25000],
    ["B738", "Atuador de Profundor", 443700.00, 93960.00, 25000],
    ["B738", "Trem de Pouso / Amort.", 2505600.00, 1670400.00, 30000],
    ["A321", "Pneu Principal", 14616.00, 7308.00, 450],
    ["A321", "Atuador de Flap", 678600.00, 146160.00, 25000],
    ["A321", "Atuador de Profundor", 495900.00, 114840.00, 25000],
    ["A321", "Trem de Pouso / Amort.", 2088000.00, 2088000.00, 30000],
    ["A333", "Pneu Principal", 22968.00, 11484.00, 800],
    ["A333", "Atuador de Flap", 1461600.00, 313200.00, 35000],
    ["A333", "Atuador de Profundor", 1096200.00, 261000.00, 35000],
    ["A333", "Trem de Pouso / Amort.", 9396000.00, 6264000.00, 40000],
    ["B77L", "Pneu Principal", 26100.00, 13050.00, 900],
    ["B77L", "Atuador de Flap", 2349000.00, 443700.00, 40000],
    ["B77L", "Atuador de Profundor", 1827000.00, 365400.00, 40000],
    ["B77L", "Trem de Pouso / Amort.", 13050000.00, 9396000.00, 45000],
];

$ins = $pdo->prepare("INSERT INTO aircraft_maintenance (model_icao, component_name, cost_incident, cost_preventive, interval_fh) VALUES (?, ?, ?, ?, ?)");
foreach ($components as $c) {
    $ins->execute($c);
}
echo "\nAll " . count($components) . " components populated.\n";

// 4. Add ticket_markup to system_settings if not exists
$stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
$stmt->execute(['ticket_markup', '700']);
echo "ticket_markup=700 setting OK\n";

// 5. Add fuel_price_per_liter to system_settings if not exists
$stmt->execute(['fuel_price_per_liter', '5.50']);
echo "fuel_price_per_liter=5.50 setting OK\n";

// 6. Show maintenance cost breakdown per model
echo "\n=== Custo Manutenção Preventiva por FH (por modelo) ===\n";
$stmt = $pdo->query("
    SELECT am.icao, am.max_pax,
           SUM(ac.cost_preventive / ac.interval_fh) as maint_per_fh,
           SUM(ac.cost_preventive / ac.interval_fh) / am.max_pax as maint_per_fh_per_pax
    FROM aircraft_models am
    JOIN aircraft_maintenance ac ON ac.model_icao = am.icao
    GROUP BY am.icao
    ORDER BY am.max_pax
");

$modelData = $stmt->fetchAll();
foreach ($modelData as $r) {
    printf("  %s (%3d pax): R$ %8.2f/fh | R$ %.4f/fh/pax\n", 
        $r['icao'], $r['max_pax'], $r['maint_per_fh'], $r['maint_per_fh_per_pax']);
}

// 7. Show ticket price examples with markup=700
echo "\n=== Exemplo Preço Bilhete (markup=700) ===\n";
echo "Fórmula: ticket = (maint_preventiva_por_fh × horas / max_pax) × markup\n\n";
$markup = 700;
$examples = [
    ['C208', 30, 'Curta distância'],
    ['A20N', 60, '1h doméstico'],
    ['B738', 120, '2h doméstico (SBRJ-SBFZ)'],
    ['B738', 210, '3.5h longa doméstica'],
    ['A333', 300, '5h internacional curta'],
    ['B77L', 540, '9h internacional (SBGL-KMIA)'],
];

foreach ($examples as $ex) {
    $icao = $ex[0];
    $dur = $ex[1];
    $hours = $dur / 60;
    
    $q = $pdo->prepare("
        SELECT am.max_pax, SUM(ac.cost_preventive / ac.interval_fh) as maint_per_fh
        FROM aircraft_models am
        JOIN aircraft_maintenance ac ON ac.model_icao = am.icao
        WHERE am.icao = ?
        GROUP BY am.icao
    ");
    $q->execute([$icao]);
    $data = $q->fetch();
    
    $ticket = ($data['maint_per_fh'] * $hours / $data['max_pax']) * $markup;
    printf("  %s %3dmin (%4.1fh) [%s]: R$ %8.2f/pax\n", $icao, $dur, $hours, $ex[2], $ticket);
}

// 8. Ensure ticket_price column exists in flights_master
try {
    $pdo->exec("ALTER TABLE flights_master ADD COLUMN ticket_price DECIMAL(10,2) DEFAULT 0 AFTER max_pax");
    echo "\nticket_price column added to flights_master\n";
} catch (Exception $e) {
    echo "\nticket_price column already exists\n";
}

// 9. Update existing flights with calculated ticket_price
echo "\n=== Atualizando rotas existentes com preço calculado ===\n";
$flights = $pdo->query("
    SELECT fm.id, fm.flight_number, fm.dep_icao, fm.arr_icao, fm.duration_minutes, fm.aircraft_type,
           am.max_pax, SUM(ac.cost_preventive / ac.interval_fh) as maint_per_fh
    FROM flights_master fm
    LEFT JOIN aircraft_models am ON fm.aircraft_type = am.icao
    LEFT JOIN aircraft_maintenance ac ON ac.model_icao = am.icao
    GROUP BY fm.id
")->fetchAll();

$upd = $pdo->prepare("UPDATE flights_master SET ticket_price = ?, max_pax = ? WHERE id = ?");
foreach ($flights as $f) {
    $hours = ($f['duration_minutes'] ?: 60) / 60;
    $maxPax = $f['max_pax'] ?: 180;
    $maintHr = $f['maint_per_fh'] ?: 75; // fallback
    
    $ticket = ($maintHr * $hours / $maxPax) * $markup;
    $upd->execute([round($ticket, 2), $maxPax, $f['id']]);
    printf("  %s (%s→%s) %dmin %s: R$ %.2f/pax\n", 
        $f['flight_number'], $f['dep_icao'], $f['arr_icao'], 
        $f['duration_minutes'], $f['aircraft_type'], $ticket);
}

echo "\n=== DONE ===\n";
