<?php
// db_connect is handled by caller

class PayrollSystem
{
    private $pdo;
    private $settings = [];


    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    private function loadSettings()
    {
        // Load defaults
        $this->settings = [
            'hotel_daily_rate' => 100.00,
            'breakfast_cost' => 15.00,
            'lunch_cost' => 20.00,
            'dinner_cost' => 15.00,
            'currency_symbol' => 'R$'
        ];

        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($dbSettings) {
                $this->settings = array_merge($this->settings, $dbSettings);
            }
        } catch (Exception $e) {
            // sticky with defaults if table doesn't exist yet
        }
    }

    // Helper to get random venue deterministically based on date/pilot
    private function getVenue($dateStr, $pilotId, $type = 'HOTEL')
    {
        // Use crc32 to generate a numeric seed from the string+pilot combination
        // This handles "2024-01-01lunch" correctly where strtotime would fail
        $seed = crc32($dateStr . $pilotId);
        srand($seed);

        $stmt = $this->pdo->prepare("SELECT name FROM expense_venues WHERE type = ? ORDER BY RAND(" . $seed . ") LIMIT 1");
        $stmt->execute([$type]);
        $default = ($type == 'HOTEL' ? 'Hotel Padrão' : 'Restaurante Local');
        return $stmt->fetchColumn() ?: $default;
    }



    public function generatePaycheck($pilotId, $monthStr = null)
    {
        if (!$monthStr)
            $monthStr = date('Y-m'); // Corrente

        // 1. Get Pilot & Rank
        $stmt = $this->pdo->prepare("SELECT p.*, r.pay_rate FROM pilots p LEFT JOIN ranks r ON p.`rank` = r.rank_name WHERE p.id = ?");
        $stmt->execute([$pilotId]);
        $pilot = $stmt->fetch();

        // 2. Get Flight Earnings for Month
        $stmt = $this->pdo->prepare("
            SELECT  
                SUM(flight_time) as total_hours, 
                COUNT(*) as total_flights
            FROM flight_reports 
            WHERE pilot_id = ? 
            AND status = 'Approved' 
            AND DATE_FORMAT(submitted_at, '%Y-%m') = ?
        ");
        $stmt->execute([$pilotId, $monthStr]);
        $activity = $stmt->fetch();

        $flightPay = ($activity['total_hours'] ?? 0) * ($pilot['pay_rate'] ?? 15);

        // Base Salary (Fixo por patente? Vamos assumir fixo universal ou escalonado.
        // Simplificação: Cadet=1500, Junior=2500, Senior=4000, Captain=7000)
        $baseSalaries = [
            'Cadet' => 1500,
            'Junior First Officer' => 2500,
            'Senior First Officer' => 4000,
            'Captain' => 7000,
            'Senior Captain' => 9000
        ];
        $baseSalary = $baseSalaries[$pilot['rank']] ?? 1500;

        // 3. Calculate Idle Cost (Ociosidade por voo perdido)
        // Regra: Se tem voo na escala e não voa, gera custo operacional (hotel/comida)
        // do dia do voo perdido até o próximo voo realizado.

        $idleDays = 0;
        $idleDetails = [];
        $daysInMonth = date('t', strtotime($monthStr . '-01'));
        $monthStart = date('Y-m-01', strtotime($monthStr . '-01'));
        $monthEnd = date('Y-m-t', strtotime($monthStr . '-01'));

        // Look back 2 months to capture carry-over idleness
        $lookbackDate = date('Y-m-d', strtotime("$monthStr -2 months"));

        // 1. Get List of "Events": Scheduled Flights (Potential Misses) and Actual Flights (Stops)
        // We need Roster Info (Date, Origin) and Status
        $sql = "
            SELECT 
                r.flight_date, 
                fm.dep_icao as origin,
                CASE WHEN fr.status = 'Approved' THEN 'FLOWN' ELSE 'MISSED' END as type
            FROM roster_assignments r 
            JOIN flights_master fm ON r.flight_id = fm.id
            LEFT JOIN flight_reports fr ON r.id = fr.roster_id AND fr.status = 'Approved'
            WHERE r.pilot_id = ? 
            AND r.flight_date >= ?
            
            ORDER BY flight_date ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$pilotId, $lookbackDate]);
        $events = $stmt->fetchAll();

        $isIdle = false;
        $idleStart = null;
        $idleLocation = null;

        // Determine initial state if we started looking mid-stream? 
        // Difficult without infinite lookback. Assuming clean slate or captured by 2-month lookback.

        foreach ($events as $ev) {
            $evDate = $ev['flight_date'];

            if ($ev['type'] == 'MISSED') {
                if (!$isIdle) {
                    // Start of Idleness
                    // Checks if date is past (can't be idle in future ?)
                    if ($evDate <= date('Y-m-d')) {
                        $isIdle = true;
                        $idleStart = $evDate;
                        $idleLocation = $ev['origin']; // Stranded at origin of missed flight
                    }
                }
            } elseif ($ev['type'] == 'FLOWN') {
                if ($isIdle) {
                    // End of Idleness
                    $isIdle = false;
                    $idleEnd = date('Y-m-d', strtotime($evDate . ' -1 day')); // Ends day before flight

                    // Add Range if valid
                    if ($idleEnd >= $idleStart) {
                        $this->processIdleRange($idleStart, $idleEnd, $idleLocation, $monthStart, $monthEnd, $pilotId, $idleDays, $idleDetails);
                    }
                }
            }
        }

        // If still idle at "end of timeline" (e.g. up to today/month-end)
        if ($isIdle) {
            // Cap at today or month end
            $capDate = min(date('Y-m-d'), $monthEnd);
            if ($capDate >= $idleStart) {
                $this->processIdleRange($idleStart, $capDate, $idleLocation, $monthStart, $monthEnd, $pilotId, $idleDays, $idleDetails);
            }
        }

        $idleCost = $idleDays * ((float)$this->settings['hotel_daily_rate'] + (float)$this->settings['breakfast_cost'] + (float)$this->settings['lunch_cost'] + (float)$this->settings['dinner_cost']);

        // 4. Totals (Simplified - No taxes as requested)
        $grossPay = $baseSalary + $flightPay;
        $totalDeductions = $idleCost;
        $netPay = $grossPay - $totalDeductions;

        return [
            'month' => $monthStr,
            'rank' => $pilot['rank'],
            'base_salary' => $baseSalary,
            'flight_pay' => $flightPay,
            'hours_flown' => $activity['total_hours'] ?? 0,
            'per_diem_deduction' => $idleCost,
            'idle_days' => $idleDays,
            'idle_details' => $idleDetails,
            'tax_deduction' => 0.00,
            'pension_deduction' => 0.00,
            'total_net_pay' => $netPay
        ];
    }

    // Helper to clip range to current month and generate details
    private function processIdleRange($start, $end, $location, $monthStart, $monthEnd, $pilotId, &$totalDays, &$details)
    {
        $s = max($start, $monthStart);
        $e = min($end, $monthEnd);

        if ($s <= $e) {
            $days = (strtotime($e) - strtotime($s)) / 86400 + 1;
            $totalDays += $days;

            // Generate details
            // Temporarily override getPilotLocation logic by passing explicit location if needed, 
            // or we add a location arg to generateExpenseBreakdown. Added arg below.
            $details[] = [
                'start' => date('d/m/Y', strtotime($s)),
                'end' => date('d/m/Y', strtotime($e)),
                'days' => $days,
                'breakdown' => $this->generateExpenseBreakdown(strtotime($s), strtotime($e), $pilotId, $location)
            ];
        }
    }


    // Get where the pilot is (Last arrival airport)
    private function getPilotLocation($pilotId, $dateStr)
    {
        // Find last approved flight arrival before this date
        $stmt = $this->pdo->prepare("
            SELECT f.arr_icao 
            FROM roster_assignments r 
            JOIN flights_master f ON r.flight_id = f.id 
            JOIN flight_reports fr ON r.id = fr.roster_id 
            WHERE r.pilot_id = ? 
            AND fr.status = 'Approved' 
            AND r.flight_date < ? 
            ORDER BY r.flight_date DESC 
            LIMIT 1
        ");
        $stmt->execute([$pilotId, $dateStr]);
        $icao = $stmt->fetchColumn();

        if (!$icao) {
            // Fallback to Pilot's current base if no previous flight found
            $stmt = $this->pdo->prepare("SELECT current_base FROM pilots WHERE id = ?");
            $stmt->execute([$pilotId]);
            $icao = $stmt->fetchColumn();
        }
        return $icao ?: 'SBGR'; // Default default
    }

    private function getLocationName($icao)
    {
        if (!$icao)
            return '';
        $stmt = $this->pdo->prepare("SELECT municipality, iso_country, name FROM airports WHERE ident = ? OR icao_code = ? LIMIT 1");
        $stmt->execute([$icao, $icao]);
        $data = $stmt->fetch();
        if ($data) {
            // Use Municipality (City) or Name
            return $data['municipality'] ?: $data['name'];
        }
        return $icao;
    }

    private function generateExpenseBreakdown($startTs, $endTs, $pilotId, $overrideLocation = null)
    {
        $desc = [];
        $currency = $this->settings['currency_symbol'];
        
        $pHotel = (float) $this->settings['hotel_daily_rate'];
        $pBfast = (float) $this->settings['breakfast_cost'];
        $pLunch = (float) $this->settings['lunch_cost'];
        $pDinner = (float) $this->settings['dinner_cost'];

        $days = (int) round(($endTs - $startTs) / 86400) + 1;

        if ($overrideLocation) {
            $locationIcao = $overrideLocation;
        } else {
            $locationIcao = $this->getPilotLocation($pilotId, date('Y-m-d', $startTs));
        }
        $locationName = $this->getLocationName($locationIcao);

        $blockStartDateStr = date('Y-m-d', $startTs);
        $hotel = $this->getVenue($blockStartDateStr, $pilotId, 'HOTEL');

        $hotelDisplay = $hotel;
        if ($locationName) {
            if ($hotel == 'Hotel Padrão') {
                $hotelDisplay = "Hotel em $locationName";
            } else {
                $hotelDisplay = "$hotel ($locationName)";
            }
        }

        // 1. Hotel Summary
        $totalHotel = $days * $pHeight;

        $desc[] = "<div class='mb-2 pb-2 border-b border-gray-100'>";
        $desc[] = "<div class='font-bold text-gray-700 text-xs flex justify-between items-center'>";
        $desc[] = "<span><i class='fas fa-bed text-blue-500 mr-1'></i> $hotelDisplay</span>";
        $desc[] = "<span class='font-mono'>$currency " . number_format($totalHotel, 2, ',', '.') . "</span>";
        $desc[] = "</div>";
        $desc[] = "<div class='text-[10px] text-gray-400 ml-5'>$days diárias x $currency " . number_format($pHotel, 2, ',', '.') . "</div>";
        $desc[] = "</div>";

        // 2. Daily Food Details
        $current = $startTs;

        $desc[] = "<div class='space-y-2 mt-2'>";
        while ($current <= $endTs) {
            $dateStr = date('Y-m-d', $current);
            $bfast = $this->getVenue($dateStr . 'bfast', $pilotId, 'FOOD');
            $lunch = $this->getVenue($dateStr . 'lunch', $pilotId, 'FOOD');
            $dinner = $this->getVenue($dateStr . 'dinner', $pilotId, 'FOOD');

            $dailyFood = $pBfast + $pLunch + $pDinner;

            $desc[] = "<div class='text-[10px] text-gray-600 border-b border-gray-50 pb-1'>";
            $desc[] = "<div class='flex justify-between items-center mb-0.5'><span class='font-bold text-gray-700'>" . date('d/m', $current) . "</span> <span class='font-mono text-gray-500'>$currency " . number_format($dailyFood, 2, ',', '.') . "</span></div>";

            // Grid for meals
            $desc[] = "<div class='grid grid-cols-1 gap-0 ml-2'>";
            $desc[] = "<div class='flex justify-between'><span><i class='fas fa-coffee text-orange-300 w-3 mr-1'></i> Desjejum: $bfast</span> <span class='font-mono text-gray-400'>$currency " . number_format($pBfast, 2, ',', '.') . "</span></div>";
            $desc[] = "<div class='flex justify-between'><span><i class='fas fa-utensils text-orange-400 w-3 mr-1'></i> Almoço: $lunch</span> <span class='font-mono text-gray-400'>$currency " . number_format($pLunch, 2, ',', '.') . "</span></div>";
            $desc[] = "<div class='flex justify-between'><span><i class='fas fa-glass-cheers text-purple-400 w-3 mr-1'></i> Jantar: $dinner</span> <span class='font-mono text-gray-400'>$currency " . number_format($pDinner, 2, ',', '.') . "</span></div>";
            $desc[] = "</div>";

            $desc[] = "</div>";

            $current += 86400;
        }

        $desc[] = "</div>"; // close space-y-1

        return implode("", $desc);
    }
}
