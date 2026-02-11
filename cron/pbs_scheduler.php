<?php
/**
 * PBS Scheduler Cron Script
 * Should be run every minute: * * * * * php /path/to/sky/cron/pbs_scheduler.php
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/ScheduleMatcher.php';

// Force UTC for consistency with requirement
date_default_timezone_set('UTC');

// 1. Fetch PBS generation day (Fixed: it's the start day of the week)
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'pbs_generation_day'");
$stmt->execute();
$startDay = $stmt->fetchColumn();

if ($startDay === false) {
    exit("PBS start day not configured.\n");
}

// 2. Calculation of Generation Day (48 hours before startDay)
$genDay = ($startDay - 2);
if ($genDay < 0) $genDay += 7;

$currentDayOfWeek = (int) date('w'); // 0 (Sun) to 6 (Sat)
$currentTime = date('H:i');

// 3. Check if it's time to run (10:00 UTC on the generation day)
if ($currentDayOfWeek == $genDay && $currentTime == '10:00') {
    echo "Starting PBS generation (scheduled 10:00 UTC)...\n";
    
    // We generate for the week starting at $startDay
    // Since today is $genDay, which is 2 days before $startDay:
    $startDate = new DateTime('today');
    $startDate->modify('+2 days');

    $startDateStr = $startDate->format('Y-m-d');
    $endDate = clone $startDate;
    $endDate->modify('+6 days');
    $endDateStr = $endDate->format('Y-m-d');

    // Get all pilots
    $stmt = $pdo->query("SELECT id FROM pilots");
    $pilots = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $matcher = new ScheduleMatcher($pdo);
    $totalAssigned = 0;

    foreach ($pilots as $pilotId) {
        $schedule = $matcher->generateRoster($pilotId, $startDateStr, $endDateStr);
        if (is_array($schedule)) {
            $totalAssigned += count($schedule);
        }
    }

    echo "Finished! $totalAssigned flight assignments generated for period $startDateStr to $endDateStr.\n";
} else {
    // Silent exit if not time
    // echo "Not time yet. Current: $currentDayOfWeek $currentTime, Target: $genDay 23:59\n";
}
