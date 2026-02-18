<?php
require_once 'db_connect.php';
require_once 'includes/ScheduleMatcher.php';

$pilotId = 4;
$stmt = $pdo->prepare("SELECT * FROM pilots WHERE id = ?");
$stmt->execute([$pilotId]);
$pilot = $stmt->fetch();
echo "Pilot: {$pilot['name']} ({$pilot['id']})\n";
echo "Current Base: {$pilot['current_base']}\n";
echo "Timezone: {$pilot['timezone']}\n";

$stmt = $pdo->prepare("SELECT * FROM pilot_preferences WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
echo "Preferences:\n";
while($p = $stmt->fetch()) {
    echo "  Day {$p['day_of_week']}: UTC {$p['start_time']} to {$p['end_time']} (Max {$p['max_daily_hours']}h)\n";
}

$stmt = $pdo->prepare("SELECT * FROM pilot_aircraft_prefs WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
echo "Aircraft Quals: " . implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN)) . "\n";

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enforce_flight_windows'");
echo "Enforce Windows: " . $stmt->fetchColumn() . "\n";

// Re-generate for Feb 15 - Feb 21
$matcher = new ScheduleMatcher($pdo);
$schedule = $matcher->generateRoster($pilotId, '2026-02-15', '2026-02-21');

echo "\nGenerated Schedule:\n";
if (empty($schedule)) {
    echo "NO FLIGHTS GENERATED.\n";
} else {
    foreach ($schedule as $s) {
        echo "  Date: {$s['date']} | Flight: {$s['flight']['flight_number']} | From: {$s['flight']['dep_icao']} to {$s['flight']['arr_icao']} | Dep (UTC): {$s['flight']['dep_time']}\n";
    }
}

echo "\nAvailable Flights from {$pilot['current_base']}:\n";
$stmt = $pdo->prepare("SELECT * FROM flights_master WHERE dep_icao = ?");
$stmt->execute([$pilot['current_base']]);
while($f = $stmt->fetch()) {
    echo "  {$f['flight_number']} | {$f['dep_icao']}->{$f['arr_icao']} | {$f['aircraft_type']} | Dep: {$f['dep_time']}\n";
}
