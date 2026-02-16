<?php
class ScheduleMatcher
{
    private $pdo;
    private $aircraftCache = []; // [ac_id => ['location' => 'ICAO', 'busy_until' => timestamp]]

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Pre-load aircraft states based on current DB (Fleet + Confirmed Assignments)
     */
    private function initAircraftStates($startDateStr)
    {
        $this->aircraftCache = [];
        
        // 1. Get initial positions from fleet
        $stmt = $this->pdo->query("SELECT id, current_icao FROM fleet");
        while ($row = $stmt->fetch()) {
            $this->aircraftCache[$row['id']] = [
                'location' => $row['current_icao'],
                'busy_until' => 0
            ];
        }

        // 2. Adjust based on any confirmed assignments (Accepted/Flown) that might happen before or during our period
        // For simplicity, we look for the last confirmed flight for each aircraft
        foreach ($this->aircraftCache as $id => $state) {
            $stmt = $this->pdo->prepare("
                SELECT f.arr_icao, f.flight_number, r.flight_date, f.arr_time, f.duration_minutes, f.dep_time
                FROM roster_assignments r
                JOIN flights_master f ON r.flight_id = f.id
                WHERE f.aircraft_id = ? AND r.status IN ('Accepted', 'Flown')
                ORDER BY r.flight_date DESC, f.dep_time DESC LIMIT 1
            ");
            $stmt->execute([$id]);
            $last = $stmt->fetch();
            if ($last) {
                $arrTS = strtotime($last['flight_date'] . ' ' . $last['dep_time']) + ($last['duration_minutes'] * 60);
                $this->aircraftCache[$id] = [
                    'location' => $last['arr_icao'],
                    'busy_until' => $arrTS
                ];
            }
        }
    }

    private function getAircraftBusyIntervals($acId, $dateStr)
    {
        // Get all booked intervals for this aircraft on this date (Accepted/Flown)
        $stmt = $this->pdo->prepare("
            SELECT f.dep_time, f.duration_minutes
            FROM roster_assignments r
            JOIN flights_master f ON r.flight_id = f.id
            WHERE f.aircraft_id = ? AND r.flight_date = ? AND r.status IN ('Accepted', 'Flown')
        ");
        $stmt->execute([$acId, $dateStr]);
        return $stmt->fetchAll();
    }

    public function generateRoster($pilotId, $startDateStr, $endDateStr)
    {
        $this->initAircraftStates($startDateStr);

        // Fetch flight window enforcement setting (Default: true)
        $stmt = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enforce_flight_windows'");
        $val = $stmt->fetchColumn();
        $enforceWindows = ($val === false || $val == '1');

        $stmt = $this->pdo->prepare("SELECT * FROM pilots WHERE id = ?");
        $stmt->execute([$pilotId]);
        $pilot = $stmt->fetch();
        if (!$pilot) return ['error' => 'Pilot not found'];

        $stmt = $this->pdo->prepare("SELECT * FROM pilot_preferences WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);
        $preferences = [];
        foreach ($stmt->fetchAll() as $p) $preferences[$p['day_of_week']] = $p;

        $stmt = $this->pdo->prepare("SELECT aircraft_type FROM pilot_aircraft_prefs WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);
        $aircraftPrefs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Clear existing suggestions
        $stmt = $this->pdo->prepare("DELETE FROM roster_assignments WHERE pilot_id = ? AND status IN ('Suggested', 'Rejected')");
        $stmt->execute([$pilotId]);

        $currentPilotLocation = $pilot['current_base'];
        $lastArrivalTime = null; 

        $startDate = new DateTime($startDateStr);
        $endDate = new DateTime($endDateStr);
        $schedule = [];

        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $dayOfWeek = (int) $currentDate->format('w');
            $dateStr = $currentDate->format('Y-m-d');

            $pref = null;
            if (isset($preferences[$dayOfWeek])) {
                $pref = $preferences[$dayOfWeek];
                if (!$enforceWindows) {
                    // Override strict window if enforcement is off
                    $pref['start_time'] = '00:00:00';
                    $pref['end_time'] = '23:59:59';
                }
            } elseif (!$enforceWindows) {
                // Allow scheduling on non-preferred days if enforcement is off
                $pref = [
                    'start_time' => '00:00:00', 
                    'end_time' => '23:59:59', 
                    'max_daily_hours' => 14
                ];
            } else {
                // Strict mode: No preference -> No flight
                $currentDate->modify('+1 day');
                continue;
            }

            $prefStart = new DateTime($dateStr . ' ' . $pref['start_time']);
            $prefEnd = new DateTime($dateStr . ' ' . $pref['end_time']);
            $dailyHours = 0;
            $lastFlightId = null;

                while (true) {
                    $potentialFlights = $this->getFlightsFrom($currentPilotLocation);
                    $legAdded = false;

                    foreach ($potentialFlights as $flight) {
                        if ($flight['id'] == $lastFlightId) continue;
                        if (!$flight['aircraft_id']) continue;

                        $acId = $flight['aircraft_id'];
                        $acState = $this->aircraftCache[$acId];

                        $flightDep = new DateTime($dateStr . ' ' . $flight['dep_time']);
                        $flightArr = new DateTime($dateStr . ' ' . $flight['arr_time']);
                        if ($flightArr < $flightDep) $flightArr->modify('+1 day');

                        // NEW CONSTRAINTS: Aircraft Availability & Location
                        // 1. Location Match
                        if ($acState['location'] !== $flight['dep_icao']) continue;
                        
                        // 2. Overlap Check (Confirmed)
                        $depTS = $flightDep->getTimestamp();
                        $arrTS = $flightArr->getTimestamp();
                        
                        if ($depTS < $acState['busy_until']) continue;
                        
                        $busyIntervals = $this->getAircraftBusyIntervals($acId, $dateStr);
                        $hasOverlap = false;
                        foreach ($busyIntervals as $interval) {
                            $iStart = strtotime($dateStr . ' ' . $interval['dep_time']);
                            $iEnd = $iStart + ($interval['duration_minutes'] * 60);
                            if ($depTS < $iEnd && $arrTS > $iStart) {
                                $hasOverlap = true; break;
                            }
                        }
                        if ($hasOverlap) continue;

                        // Original constraints
                        if ($flightDep < $prefStart) continue;
                        if ($flightArr > $prefEnd) continue;
                        $flightDurationHours = $flight['duration_minutes'] / 60;
                        if (($dailyHours + $flightDurationHours) > $pref['max_daily_hours']) continue;
                        if ($lastArrivalTime) {
                            $isSameDay = $lastArrivalTime->format('Y-m-d') == $dateStr;
                            $minRestMinutes = $isSameDay ? 45 : 600; 
                            $minDepTime = clone $lastArrivalTime;
                            $minDepTime->modify("+$minRestMinutes minutes");
                            if ($flightDep < $minDepTime) continue;
                        }
                        if (!empty($aircraftPrefs) && !in_array($flight['aircraft_type'], $aircraftPrefs)) continue;

                        // SUCCESS
                        $this->assignFlight($pilotId, $flight['id'], $dateStr);
                        $schedule[] = ['date' => $dateStr, 'flight' => $flight];

                        // Update State
                        $currentPilotLocation = $flight['arr_icao'];
                        $lastArrivalTime = $flightArr;
                        $dailyHours += $flightDurationHours;
                        $lastFlightId = $flight['id'];
                        $legAdded = true;

                        // Update Aircraft Tracker
                        $this->aircraftCache[$acId] = [
                            'location' => $flight['arr_icao'],
                            'busy_until' => $arrTS
                        ];
                        
                        break; 
                    }
                    if (!$legAdded) break;
                    if ($dailyHours >= $pref['max_daily_hours']) break;
                }

            $currentDate->modify('+1 day');
            $lastArrivalTime = null; // Reset rest for new day start (but keep location)
        }
        return $schedule;
    }

    private function getFlightsFrom($icao)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM flights_master WHERE dep_icao = ? ORDER BY dep_time ASC");
        $stmt->execute([$icao]);
        return $stmt->fetchAll();
    }

    private function assignFlight($pilotId, $flightId, $date)
    {
        $stmt = $this->pdo->prepare("INSERT INTO roster_assignments (pilot_id, flight_id, flight_date, status) VALUES (?, ?, ?, 'Suggested')");
        $stmt->execute([$pilotId, $flightId, $date]);
    }
}
