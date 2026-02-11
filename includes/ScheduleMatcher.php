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

        // 3. Clear existing suggested and rejected rosters for this pilot (to allow regeneration of a clean weekly schedule)
        $stmt = $this->pdo->prepare("DELETE FROM roster_assignments WHERE pilot_id = ? AND status IN ('Suggested', 'Rejected')");
        $stmt->execute([$pilotId]);

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
                
                $dailyHours = 0;
                $lastFlightId = null;

                // Inner loop to find multiple legs in the same day
                while (true) {
                    $potentialFlights = $this->getFlightsFrom($currentLocation);
                    $legAdded = false;

                    foreach ($potentialFlights as $flight) {
                        // CONSTRAINT 0: No sequential repetition of the same flight
                        if ($flight['id'] == $lastFlightId) continue;

                        $flightDep = new DateTime($dateStr . ' ' . $flight['dep_time']);
                        $flightArr = new DateTime($dateStr . ' ' . $flight['arr_time']);
                        
                        // Handle flights that cross midnight for arrival
                        if ($flightArr < $flightDep) {
                            $flightArr->modify('+1 day');
                        }

                        // CONSTRAINT 1: Availability Window
                        if ($flightDep < $prefStart) continue;
                        if ($flightArr > $prefEnd) continue;

                        // CONSTRAINT 2: Max Daily Hours
                        $flightDurationHours = $flight['duration_minutes'] / 60;
                        if (($dailyHours + $flightDurationHours) > $pref['max_daily_hours']) continue;

                        // CONSTRAINT 3: Rest Period
                        if ($lastArrivalTime) {
                            // If same day arrival, use 45 min turnaround. If different day, use 10h rest.
                            $isSameDay = $lastArrivalTime->format('Y-m-d') == $dateStr;
                            $minRestMinutes = $isSameDay ? 45 : 600; 
                            
                            $minDepTime = clone $lastArrivalTime;
                            $minDepTime->modify("+$minRestMinutes minutes");
                            
                            if ($flightDep < $minDepTime) continue;
                        }

                        // CONSTRAINT 4: Aircraft Preference
                        if (!empty($aircraftPrefs) && !in_array($flight['aircraft_type'], $aircraftPrefs)) {
                            continue;
                        }

                        // Found a match!
                        $this->assignFlight($pilotId, $flight['id'], $dateStr);

                        $schedule[] = [
                            'date' => $dateStr,
                            'flight' => $flight
                        ];

                        // Update State
                        $currentLocation = $flight['arr_icao'];
                        $lastArrivalTime = $flightArr;
                        $dailyHours += $flightDurationHours;
                        $lastFlightId = $flight['id'];
                        $legAdded = true;
                        
                        // Successfully added a leg, break the foreach to search for the NEXT leg from the new location
                        break; 
                    }

                    // If no leg was added in this pass, we are done for today
                    if (!$legAdded) break;
                    
                    // Safety: if we hit max daily hours exactly, stop
                    if ($dailyHours >= $pref['max_daily_hours']) break;
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
