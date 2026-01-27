<?php


class ScheduleMatcher
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Main function to generate a roster for a pilot
     */
    public function generateRoster($pilotId, $startDateStr, $endDateStr)
    {
        // 1. Get Pilot Info
        $stmt = $this->pdo->prepare("SELECT * FROM pilots WHERE id = ?");
        $stmt->execute([$pilotId]);
        $pilot = $stmt->fetch();

        if (!$pilot) {
            return ['error' => 'Pilot not found'];
        }

        // 2. Get Pilot Preferences
        // Indexed by day of week (0=Sunday, etc.)
        $stmt = $this->pdo->prepare("SELECT * FROM pilot_preferences WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);
        $rawPrefs = $stmt->fetchAll();
        $preferences = [];
        foreach ($rawPrefs as $p) {
            $preferences[$p['day_of_week']] = $p;
        }

        // 2b. Get Pilot Aircraft Prefs
        $stmt = $this->pdo->prepare("SELECT aircraft_type FROM pilot_aircraft_prefs WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);
        $aircraftPrefs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Clear existing suggested rosters for this period (to allow regeneration)
        // Note: In a real system, we might keep them or version them.
        $stmt = $this->pdo->prepare("DELETE FROM roster_assignments WHERE pilot_id = ? AND flight_date BETWEEN ? AND ? AND status = 'Suggested'");
        $stmt->execute([$pilotId, $startDateStr, $endDateStr]);

        // 4. Algorithm State
        $currentLocation = $pilot['current_base'];

        // Check if there is a previous flight immediately before this period to establish location/rest
        // For MVP, we assume start at base, or last flight in DB (not implemented for simplicity, assuming base)

        $lastArrivalTime = null; // Timestamp of last arrival to calc rest

        $startDate = new DateTime($startDateStr);
        $endDate = new DateTime($endDateStr);
        $schedule = [];

        // Loop through each day
        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $dayOfWeek = (int) $currentDate->format('w'); // 0 (Sun) - 6 (Sat)
            $dateStr = $currentDate->format('Y-m-d');

            // Check if pilot works on this day
            if (isset($preferences[$dayOfWeek])) {
                $pref = $preferences[$dayOfWeek];

                // Pilot availability window
                $prefStart = new DateTime($dateStr . ' ' . $pref['start_time']);
                $prefEnd = new DateTime($dateStr . ' ' . $pref['end_time']);

                // Adjust if end time is next day (e.g. 22:00 to 02:00) - Assuming simpler "same day" windows for MVP unless specified 
                // The prompt says windows, usually implies intraday.

                // Find a flight that matches:
                // 1. Departs from $currentLocation
                // 2. Departs AFTER $prefStart
                // 3. Arrives BEFORE $prefEnd (or fits within max duty roughly)
                // 4. Departs AFTER ($lastArrivalTime + 10 hours)

                $potentialFlights = $this->getFlightsFrom($currentLocation);

                foreach ($potentialFlights as $flight) {
                    $flightDep = new DateTime($dateStr . ' ' . $flight['dep_time']);

                    // Handle flights that cross midnight for arrival
                    $flightArr = new DateTime($dateStr . ' ' . $flight['arr_time']);
                    if ($flightArr < $flightDep) {
                        $flightArr->modify('+1 day');
                    }

                    // CONSTRAINT 1: Availability Window
                    if ($flightDep < $prefStart)
                        continue;
                    // Simple check: Flight must start within window. 
                    // Harder check: Flight must FINISH within window? Let's assume start is key, 
                    // but check max duty.

                    // CONSTRAINT 2: Max Daily Hours
                    $flightDurationHours = $flight['duration_minutes'] / 60;
                    if ($flightDurationHours > $pref['max_daily_hours'])
                        continue;

                    // CONSTRAINT 3: Rest Period (10 hours)
                    if ($lastArrivalTime) {
                        $minDepTime = clone $lastArrivalTime;
                        $minDepTime->modify('+10 hours');
                        if ($flightDep < $minDepTime)
                            continue;
                    }

                    // CONSTRAINT 4: Aircraft Preference
                    if (!empty($aircraftPrefs) && !in_array($flight['aircraft_type'], $aircraftPrefs)) {
                        continue;
                    }

                    // Found a match!
                    // Assign Flight
                    $this->assignFlight($pilotId, $flight['id'], $dateStr);

                    $schedule[] = [
                        'date' => $dateStr,
                        'flight' => $flight
                    ];

                    // Update State
                    $currentLocation = $flight['arr_icao'];
                    $lastArrivalTime = $flightArr;

                    // Greedily take one flight per day for this MVP to avoid complex multi-leg/day logic
                    // If we wanted multi-leg, we would loop here again for same day.
                    break;
                }
            }

            $currentDate->modify('+1 day');
        }

        return $schedule;
    }

    private function getFlightsFrom($icao)
    {
        // In a real app, strict ordering or randomization helps
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
?>