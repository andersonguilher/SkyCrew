<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../SimBrief_APIv1/simbrief.apiv1.php';
requireRole('admin');
$settings = getSystemSettings($pdo);

// Fast detection for SimBrief Iframe Redirection
?>
<script>
    if (window.self !== window.top && window.location.search.includes('ofp_id=')) {
        const ofpId = new URLSearchParams(window.location.search).get('ofp_id');
        if (ofpId) {
            // Force parent to redirect immediately
            window.parent.location.href = window.location.href;
            document.documentElement.innerHTML = '<body style="background:#0c0e17; color:white; display:flex; align-items:center; justify-content:center; height:100vh; font-family:sans-serif; margin:0; overflow:hidden;"><div>SINCRONIZANDO...</div></body>';
            window.stop();
        }
    }
</script>
<?php
// Initialize form values
$sb_dep = '';
$sb_arr = '';
$sb_route = '';
$sb_dur = '';
$sb_fuel = '';
$sb_dur = '';
$sb_fuel = '';
$sb_pax = '';
$sb_max_pax = '180';
$sb_ticket_price = '500.00';
$sb_waypoints = null;

$edit_id = $_GET['edit_id'] ?? null;
$edit_flight = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM flights_master WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_flight = $stmt->fetch();
    
    if ($edit_flight) {
        // Pre-fill from DB
        $sb_dep = $edit_flight['dep_icao'];
        $sb_arr = $edit_flight['arr_icao'];
        $sb_route = $edit_flight['route'];
        $sb_dur = $edit_flight['duration_minutes'];
        $sb_out = substr($edit_flight['dep_time'], 0, 5);
        $sb_fuel = $edit_flight['estimated_fuel'];
        $sb_pax = $edit_flight['passenger_count'];
        $sb_max_pax = $edit_flight['max_pax'];
        $sb_ticket_price = $edit_flight['ticket_price'];
        $sb_waypoints = $edit_flight['route_waypoints'];
    }
}

// Handle SimBrief Callback
if (isset($_GET['ofp_id'])) {
    $sb = new SimBrief($_GET['ofp_id']);
    if ($sb->ofp_avail) {
        $sb_data = $sb->ofp_array;
        
        // Debug logging for the received OFP data (optional, can be disabled)
        // file_put_contents('../api/simbrief_debug.log', "\nOFP ID Processed: " . $_GET['ofp_id'] . "\nData: " . json_encode($sb_data) . "\n", FILE_APPEND);

        $sb_dep = $sb_data['origin']['icao_code'] ?? '';
        $sb_arr = $sb_data['destination']['icao_code'] ?? '';
        $sb_route = $sb_data['general']['route'] ?? '';
        
        // Try multiple keys for duration (SimBrief XML can vary or be nested differently)
        $raw_dur = $sb_data['times']['est_time_enroute'] ?? $sb_data['general']['route_duration'] ?? 0;
        $sb_dur = floor(intval($raw_dur) / 60);
        
        // Extract departure time from SimBrief (usually UTC)
        $sb_out = isset($sb_data['times']['sched_out']) ? date('H:i', intval($sb_data['times']['sched_out'])) : '';


        // Extract Fuel and Pax
        $sb_fuel = $sb_data['fuel']['plan_ramp'] ?? 0;
        $sb_pax = $sb_data['weights']['pax_count'] ?? 0;

        // Extract Waypoints (Real Route)
        $fixes = [];
        if (isset($sb_data['navlog']['fix'])) {
            foreach ($sb_data['navlog']['fix'] as $fix) {
                if (isset($fix['pos_lat']) && isset($fix['pos_long'])) {
                    $fixes[] = [
                        'lat' => floatval($fix['pos_lat']), 
                        'lng' => floatval($fix['pos_long']),
                        'name' => $fix['ident'] ?? '',
                        'type' => $fix['type'] ?? 'WPT'
                    ];
                }
            }
        }
        $sb_waypoints = !empty($fixes) ? json_encode($fixes) : null;
    }
}

$selected_ac = $_GET['aircraft_id'] ?? ($edit_flight['aircraft_id'] ?? '');
$passed_flight_number = $_GET['fn'] ?? ($edit_flight['flight_number'] ?? '');

// Handle actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_flight'])) {
        $prefix = strtoupper($settings['va_callsign'] ?: 'VA');
        $rawNum = preg_replace('/\D/', '', $_POST['flight_number']);
        $fnum = $prefix . $rawNum;
        $dep = strtoupper(trim($_POST['dep_icao']));
        $arr = strtoupper(trim($_POST['arr_icao']));
        $dtime = $_POST['dep_time'];
        $atime = $_POST['arr_time'];
        $aircraft_id = $_POST['aircraft_id'];
        $dur = $_POST['duration'];
        $route = $_POST['route'] ?? null;
        $fuel = $_POST['estimated_fuel'] ?? 0;
        $pax = $_POST['passenger_count'] ?? 0;
        $max_pax = $_POST['max_pax'] ?? 180;
        $ticket_price = $_POST['ticket_price'] ?? 500.00;
        $waypoints = $_POST['route_waypoints'] ?? null;
        if (trim($waypoints) === '') $waypoints = null;

        // Get ICAO for compat
        $acStmt = $pdo->prepare("SELECT icao_code FROM fleet WHERE id = ?");
        $acStmt->execute([$aircraft_id]);
        $acData = $acStmt->fetch();
        $ac = $acData['icao_code'] ?? 'Unknown';

        // Check for duplicates
        $check = $pdo->prepare("SELECT id FROM flights_master WHERE flight_number = ?");
        $check->execute([$fnum]);

        if ($check->rowCount() > 0) {
            $error = "O voo $fnum já existe!";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO flights_master (flight_number, aircraft_id, dep_icao, arr_icao, dep_time, arr_time, aircraft_type, duration_minutes, route, estimated_fuel, passenger_count, max_pax, ticket_price, route_waypoints) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fnum, $aircraft_id, $dep, $arr, $dtime, $atime, $ac, $dur, $route, $fuel, $pax, $max_pax, $ticket_price, $waypoints]);
                $success = "Voo $fnum adicionado com sucesso.";
            } catch (PDOException $e) {
                $error = "Erro ao salvar: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM flights_master WHERE id = ?");
            $stmt->execute([$_POST['delete_id']]);
            $success = "Voo excluído com sucesso.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_flight'])) {
        $id = $_POST['update_id'];
        $prefix = strtoupper($settings['va_callsign'] ?: 'VA');
        $rawNum = preg_replace('/\D/', '', $_POST['flight_number']);
        $fnum = $prefix . $rawNum;
        $dep = strtoupper(trim($_POST['dep_icao']));
        $arr = strtoupper(trim($_POST['arr_icao']));
        $dtime = $_POST['dep_time'];
        $atime = $_POST['arr_time'];
        $aircraft_id = $_POST['aircraft_id'];
        $dur = $_POST['duration'];
        $route = $_POST['route'] ?? null;
        $fuel = $_POST['estimated_fuel'] ?? 0;
        $pax = $_POST['passenger_count'] ?? 0;
        $max_pax = $_POST['max_pax'] ?? 180;
        $ticket_price = $_POST['ticket_price'] ?? 500.00;
        $waypoints = $_POST['route_waypoints'] ?? null;
        if (trim($waypoints) === '') $waypoints = null;

        // Get ICAO for compat
        $acStmt = $pdo->prepare("SELECT icao_code FROM fleet WHERE id = ?");
        $acStmt->execute([$aircraft_id]);
        $acData = $acStmt->fetch();
        $ac = $acData['icao_code'] ?? 'Unknown';

        try {
            $stmt = $pdo->prepare("UPDATE flights_master SET flight_number=?, aircraft_id=?, dep_icao=?, arr_icao=?, dep_time=?, arr_time=?, aircraft_type=?, duration_minutes=?, route=?, estimated_fuel=?, passenger_count=?, max_pax=?, ticket_price=?, route_waypoints=? WHERE id=?");
            $stmt->execute([$fnum, $aircraft_id, $dep, $arr, $dtime, $atime, $ac, $dur, $route, $fuel, $pax, $max_pax, $ticket_price, $waypoints, $id]);
            $success = "Voo $fnum atualizado com sucesso.";
            // Clear edit mode
            $edit_flight = null;
            $edit_id = null;
            // Reset form vars
             $sb_dep = ''; $sb_arr = ''; $sb_route = ''; $sb_dur = ''; $sb_out = ''; $sb_fuel = ''; $sb_pax = ''; $passed_flight_number = ''; $selected_ac = '';
        } catch (PDOException $e) {
            $error = "Erro ao atualizar: " . $e->getMessage();
        }
    }
}

// Fetch Data Enriched for Map and List
$flights = $pdo->query("
    SELECT fm.*, fl.registration,
    a1.latitude_deg as dep_lat, a1.longitude_deg as dep_lon,
    a2.latitude_deg as arr_lat, a2.longitude_deg as arr_lon,
    (SELECT status FROM roster_assignments WHERE flight_id = fm.id ORDER BY assigned_at DESC LIMIT 1) as roster_status
    FROM flights_master fm 
    LEFT JOIN fleet fl ON fm.aircraft_id = fl.id 
    LEFT JOIN airports a1 ON fm.dep_icao = a1.ident
    LEFT JOIN airports a2 ON fm.arr_icao = a2.ident
    ORDER BY fm.flight_number
")->fetchAll();

// 1. Performance: By default, only load Accepted flights to keep map and DOM light
$showFullNetwork = isset($_GET['full_network']);
$mapFlights = array_filter($flights, function($f) use ($showFullNetwork) {
    return $showFullNetwork || $f['roster_status'] === 'Accepted';
});

$initialMapData = json_encode(array_values($mapFlights));

// Logic for table rendering: matches map for consistency and speed
$tableFlights = $mapFlights;
$totalFlightsCount = count($flights);

$fleet = $pdo->query("
    SELECT f.*, 
    COALESCE((SELECT arr_icao FROM flights_master WHERE aircraft_id = f.id ORDER BY dep_time DESC LIMIT 1), f.current_icao) as last_location
    FROM fleet f 
    ORDER BY f.icao_code, f.registration
")->fetchAll();

$prefix = strtoupper($settings['va_callsign'] ?: 'VA');
$maxNum = 1000;
foreach ($flights as $f) {
    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $f['flight_number'], $matches)) {
        $num = intval($matches[1]);
        if ($num > $maxNum)
            $maxNum = $num;
    }
}
$nextNum = $maxNum + 1;

$pageTitle = "Painel de Voos - SkyCrew OS";
$extraHead = '
    <link rel="stylesheet" href="../assets/libs/leaflet/leaflet.css" />
    <script src="../assets/libs/leaflet/leaflet.js"></script>
    <style>
        #routeMap { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }
        .leaflet-container { background: #0c0e17 !important; }
        .leaflet-vignette { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; pointer-events: none; box-shadow: inset 0 0 150px rgba(0,0,0,0.8); }
        .sidebar-panel { width: 380px; flex-shrink: 0; display: flex; flex-direction: column; overflow: hidden; }
        .bottom-shelf { height: 260px; flex-shrink: 0; display: flex; flex-direction: column; margin-top: auto; transition: height 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .bottom-shelf.minimized { height: 56px; }
        .route-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; transition: all 0.3s; }
        .route-card:hover { background: rgba(255,255,255,0.07); border-color: rgba(255,255,255,0.2); }
        .suggest-card { background: rgba(99, 102, 241, 0.1); border: 1px dashed #6366f1; border-radius: 12px; padding: 10px; text-align: center; cursor: pointer; transition: all 0.2s; }
        .suggest-card:hover { background: rgba(99, 102, 241, 0.2); transform: scale(1.02); }
        select.form-input option { background: #1e1b4b; color: white; }

        .sb-iframe-container {
            background: #000;
            position: relative;
            border-radius: 0 0 24px 24px;
            overflow: hidden;
            height: 400px;
        }
        #sbIframe { width: 100%; height: 100%; border: none; }
        .glass-tooltip { background: rgba(12, 14, 23, 0.9) !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; color: white !important; font-weight: bold !important; font-size: 11px !important; border-radius: 6px !important; backdrop-filter: blur(8px); box-shadow: 0 4px 15px rgba(0,0,0,0.6); pointer-events: none; }
        .waypoint-tooltip { background: transparent !important; border: none !important; box-shadow: none !important; color: rgba(255, 255, 255, 0.9) !important; font-family: monospace !important; font-weight: bold !important; font-size: 10px !important; letter-spacing: 0.5px; text-shadow: 0 0 3px #000, 0 0 5px #000; padding: 0 !important; margin-top: -2px !important; }
        .leaflet-tooltip-top:before { border-top-color: rgba(12, 14, 23, 0.9) !important; }
        .waypoint-tooltip.leaflet-tooltip-top:before { display: none !important; }
        .airport-node { cursor: pointer !important; pointer-events: auto !important; }
        .leaflet-pane.leaflet-hubs-pane { pointer-events: none; }
        .leaflet-pane.leaflet-hubs-pane path { pointer-events: auto; }

        /* UI Event Passthrough Fix */
        .page-container, .content-area { pointer-events: none; }
        .top-bar, .sidebar-panel, .bottom-shelf, .glass-panel, input, button, select, textarea { pointer-events: auto; }

        .plane-visible { /* Removed opacity override for JS control */ }
        .plane-highlight { color: #fbbf24 !important; filter: drop-shadow(0 0 4px rgba(251, 191, 36, 0.6)) !important; scale: 1.15; z-index: 1000 !important; pointer-events: none !important; }
        .plane-node { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; transition: scale 0.3s ease-out, filter 0.3s; pointer-events: none !important; }
        .plane-svg { width: 100%; height: 100%; fill: currentColor; transform-origin: center; }
        .leaflet-tile { transition: opacity 0.4s ease !important; }

        /* Map Filters */
        .map-filters { position: absolute; top: 130px; right: 20px; z-index: 100; pointer-events: auto; width: 220px; }
        .filter-btn { background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; padding: 10px 14px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.3s; width: 100%; text-align: left; display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .filter-btn:hover { background: rgba(99, 102, 241, 0.2); border-color: rgba(99, 102, 241, 0.4); color: white; }
        .filter-btn.active { background: #6366f1; border-color: #6366f1; color: white; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); }
        .filter-btn.active i.fa-toggle-off { display: none; }
        .filter-btn i.fa-toggle-on { display: none; }
        .filter-btn.active i.fa-toggle-on { display: inline-block; }
    </style>
';

$bgElement = '
    <div id="routeMap"></div>
    <div class="leaflet-vignette"></div>
    <div class="map-filters flex flex-col gap-2">
        <button id="toggleRoster" onclick="toggleMapMode(\'roster\')" class="filter-btn active"><span><i class="fas fa-check-circle mr-2"></i> Voos Aceitos</span> <i class="fas fa-toggle-on"></i><i class="fas fa-toggle-off"></i></button>
        <button id="toggleAll" onclick="toggleMapMode(\'all\')" class="filter-btn"><span><i class="fas fa-globe mr-2"></i> Malha Completa</span> <i class="fas fa-toggle-on"></i><i class="fas fa-toggle-off"></i></button>
        <div class="glass-panel p-3 rounded-2xl border-white/5 space-y-2">
            <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Filtrar ICAO</p>
            <input type="text" id="mapIcaoFilter" onkeyup="syncFilters(this, event)" placeholder="Ex: SBGR" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-[11px] text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 uppercase">
        </div>
    </div>';

include '../includes/layout_header.php';
?>

<div class="sidebar-panel glass-panel rounded-3xl z-10">
    <div class="p-6 border-b border-white/10 shrink-0">
        <h2 class="text-lg font-bold text-white flex items-center gap-2">
            <i class="fas fa-<?php echo $edit_flight ? 'edit' : 'plus-circle'; ?> text-indigo-400"></i> 
            <?php echo $edit_flight ? 'Editar Voo' : 'Despacho Operacional'; ?>
        </h2>
        <p class="text-[10px] text-slate-400 uppercase tracking-widest mt-1">
            <?php echo $edit_flight ? 'Atualizando ' . $edit_flight['flight_number'] : 'Planejamento de Voo'; ?>
            <?php if ($edit_flight): ?>
                <a href="flights.php" class="text-indigo-400 hover:text-white ml-2 underline">Cancelar</a>
            <?php endif; ?>
        </p>
    </div>

    <div class="flex-1 overflow-y-auto p-6 space-y-6">
        <form method="POST" id="dispatchForm" class="space-y-5">
            <div class="space-y-2">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Voo</label>
                <input type="text" name="flight_number" value="<?php echo $passed_flight_number ?: ($prefix . $nextNum); ?>" class="form-input font-bold" required>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between items-center"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Aeronave</label>
                <label class="text-[10px] text-slate-500 cursor-pointer hover:text-white transition"><input type="checkbox" id="show_all_ac" class="rounded bg-white/5 border-white/20 text-indigo-500" onchange="checkAvailability()"> Ver Todas</label></div>
                <select name="aircraft_id" id="ac" class="form-input" required onchange="checkAvailability(true); calcTicketPrice();">
                    <option value="">Selecione...</option>
                    <?php foreach ($fleet as $f): ?>
                            <option value="<?php echo $f['id']; ?>" data-location="<?php echo $f['last_location']; ?>" data-icao="<?php echo $f['icao_code']; ?>" <?php echo ($selected_ac == $f['id']) ? 'selected' : ''; ?>>
                                <?php echo $f['registration']; ?> (<?php echo $f['icao_code']; ?>) - <?php echo $f['last_location']; ?>
                            </option>
                    <?php endforeach; ?>
                </select>
                <div id="ac_status" class="text-[9px] font-mono text-emerald-400 ml-1 hidden mt-1"></div>
                
                <div id="ac_schedule" class="mt-2 p-3 bg-white/5 rounded-xl border border-white/10 hidden">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-2 flex items-center gap-2">
                        <i class="fas fa-clock text-indigo-400"></i> Linha do Tempo (Hoje)
                    </p>
                    <div id="schedule_list" class="space-y-1"></div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2 relative">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Origem</label>
                    <input type="text" id="dep" name="dep_icao" class="form-input uppercase" placeholder="ICAO" maxlength="4" onkeyup="searchAirport(this); drawPlannedRoute();" value="<?php echo htmlspecialchars($sb_dep ?: ($_POST['dep_icao'] ?? '')); ?>" required>
                    <div id="dep_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20"></div>
                </div>
                <div class="space-y-2 relative">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Destino</label>
                    <input type="text" id="arr" name="arr_icao" class="form-input uppercase" placeholder="ICAO" maxlength="4" onkeyup="searchAirport(this); drawPlannedRoute();" value="<?php echo htmlspecialchars($sb_arr ?: ($_POST['arr_icao'] ?? '')); ?>" required>
                    <div id="arr_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20"></div>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between items-center mr-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Plano de Rota</label>
                    <button type="button" onclick="fetchSimBrief()" class="text-[10px] font-bold text-indigo-400 hover:text-indigo-300 uppercase flex items-center gap-1 transition">
                        <i class="fas fa-bolt text-yellow-400"></i> Auto-Completar via SimBrief
                    </button>
                </div>
                <textarea id="route" name="route" class="form-input h-20 text-[11px] font-mono resize-none" placeholder="Gerado automaticamente ou manual..."><?php echo htmlspecialchars($sb_route ?: ($_POST['route'] ?? '')); ?></textarea>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Saída (Z)</label>
                <input type="time" id="dep_time" name="dep_time" class="form-input p-1" onchange="calcArrTime()" value="<?php echo htmlspecialchars($sb_out ?? ''); ?>" required></div>
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">EET</label>
                <input type="number" id="dur" name="duration" class="form-input p-1" onchange="calcArrTime(); calcTicketPrice();" value="<?php echo htmlspecialchars($sb_dur ?: ($_POST['duration'] ?? '')); ?>" required></div>
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Chegada (Z)</label>
                <input type="time" id="arr_time" name="arr_time" class="form-input p-1 bg-white/5 pointer-events-none opacity-50" readonly required tabindex="-1"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Capacidade (MAX PAX)</label>
                <input type="number" id="max_pax" name="max_pax" value="<?php echo htmlspecialchars($sb_max_pax ?: ($_POST['max_pax'] ?? '180')); ?>" class="form-input p-1 bg-white/5 opacity-60" readonly tabindex="-1"></div>
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Preço Bilhete (R$)</label>
                        <span id="ticket_auto" class="text-[9px] text-emerald-400 font-bold hidden"><i class="fas fa-magic mr-1"></i>AUTO</span>
                    </div>
                    <input type="number" step="0.01" id="ticket_price" name="ticket_price" value="<?php echo htmlspecialchars($sb_ticket_price ?: ($_POST['ticket_price'] ?? '500.00')); ?>" class="form-input p-1">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Combustível Est. (Kg)</label>
                <input type="number" name="estimated_fuel" value="<?php echo htmlspecialchars($sb_fuel ?: ($_POST['estimated_fuel'] ?? '')); ?>" class="form-input p-1"></div>
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">PAX Planejados</label>
                <input type="number" name="passenger_count" value="<?php echo htmlspecialchars($sb_pax ?: ($_POST['passenger_count'] ?? '')); ?>" class="form-input p-1"></div>
            </div>
            <input type="hidden" name="route_waypoints" value='<?php echo htmlspecialchars($sb_waypoints ?: ($_POST['route_waypoints'] ?? '')); ?>'>
            <?php if ($edit_flight): ?>
                <input type="hidden" name="update_id" value="<?php echo $edit_flight['id']; ?>">
                <button type="submit" name="update_flight" class="btn-glow w-full py-4 mt-4 uppercase tracking-widest text-sm bg-indigo-600 hover:bg-indigo-500">Atualizar Voo</button>
            <?php else: ?>
                <button type="submit" name="add_flight" class="btn-glow w-full py-4 mt-4 uppercase tracking-widest text-sm">Criar Voo</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<form id="sbapiform" style="display:none;">
    <input type="text" name="orig"><input type="text" name="dest"><input type="text" name="route"><input type="text" name="type">
    <input type="text" name="airline" value="<?php echo $settings['va_callsign']; ?>"><input type="text" name="fltnum"><input type="text" name="units" value="KGS"><input type="text" name="navlog" value="1">
    <input type="text" name="deph"><input type="text" name="depm">
</form>




<div class="flex-1 flex flex-col justify-end z-10 max-h-full overflow-hidden">
    <div class="mb-4">
        <?php if ($success): ?><div class="glass-panel border-l-4 border-emerald-500 px-6 py-3 rounded-2xl text-emerald-400 font-bold mb-2 animate-pulse"><i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="glass-panel border-l-4 border-rose-500 px-6 py-3 rounded-2xl text-rose-400 font-bold mb-2"><i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?></div><?php endif; ?>
    </div>
    <div id="malha-shelf" class="bottom-shelf glass-panel rounded-3xl overflow-hidden minimized">
        <div class="p-4 border-b border-white/10 flex justify-between items-center shrink-0 cursor-pointer" onclick="toggleMalha(event)">
            <div class="flex items-center gap-4">
                <h3 class="text-white font-bold text-sm">Malha Operacional</h3>
                <div id="flight-count-badge" class="bg-white/5 border border-white/10 rounded-full px-3 py-1 text-[10px] text-slate-400 font-bold"><?php echo count($tableFlights); ?> / <?php echo $totalFlightsCount; ?></div>
                <?php if (!$showFullNetwork): ?>
                    <a href="?full_network=1" class="bg-indigo-500/10 hover:bg-indigo-500/30 text-indigo-400 border border-indigo-500/20 px-3 py-1 rounded-full text-[10px] font-bold transition">
                        <i class="fas fa-network-wired mr-1"></i> Carregar Malha Completa
                    </a>
                <?php endif; ?>
                <a href="bulk_assign_aircraft.php" class="bg-indigo-500/20 hover:bg-indigo-500 text-indigo-400 hover:text-white border border-indigo-500/30 px-3 py-1 rounded-full text-[10px] font-bold transition flex items-center gap-1" onclick="event.stopPropagation()">
                    <i class="fas fa-magic"></i> Atribuição em Lote
                </a>
                <i id="malha-icon" class="fas fa-chevron-up text-xs text-slate-500 transition-transform duration-300"></i>
            </div>
            <div class="relative w-64"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i><input type="text" id="flightSearch" onkeyup="syncFilters(this, event)" placeholder="Busca rápida..." class="w-full bg-white/5 border border-white/10 rounded-full pl-9 py-1 text-xs text-white focus:outline-none focus:ring-1 focus:ring-indigo-500"></div>
        </div>
        <div class="flex-1 overflow-y-auto">
            <table id="malha-table" class="w-full text-left text-[11px] text-slate-300">
                <thead class="bg-white/2 sticky top-0 z-20 text-[9px] uppercase tracking-widest font-bold text-slate-500 bg-[#0c0e17]">
                    <tr><th class="px-6 py-3">Número</th><th class="px-6 py-3 text-center">Trecho</th><th class="px-6 py-3">Rota</th><th class="px-6 py-3">UTC Window</th><th class="px-6 py-3">Dados (Pax/Fuel)</th><th class="px-6 py-3">Equipamento</th><th class="px-6 py-3">EET</th><th class="px-6 py-3 text-right pr-8">Ação</th></tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($tableFlights as $f): ?>
                            <tr class="hover:bg-white/5 transition group cursor-pointer border-l-2 border-transparent" 
                                data-status="<?php echo $f['roster_status']; ?>"
                                onclick="selectFlight(this, '<?php echo $f['flight_number']; ?>')" 
                                ondblclick="focusFlight(this, '<?php echo $f['flight_number']; ?>')">
                                <td class="px-6 py-3 font-bold text-indigo-400"><?php echo $f['flight_number']; ?></td>
                                <td class="px-6 py-3 text-center"><div class="flex items-center justify-center gap-2"><span><?php echo $f['dep_icao']; ?></span><i class="fas fa-arrow-right text-[10px] text-slate-600"></i><span><?php echo $f['arr_icao']; ?></span></div></td>
                                <td class="px-6 py-3 font-mono text-[9px] text-slate-400 max-w-[150px] truncate" title="<?php echo htmlspecialchars($f['route']); ?>"><?php echo htmlspecialchars($f['route'] ?: '--'); ?></td>
                                <td class="px-6 py-3 font-mono"><?php echo substr($f['dep_time'], 0, 5); ?> - <?php echo substr($f['arr_time'], 0, 5); ?></td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-col text-[10px]">
                                        <span class="text-slate-200"><i class="fas fa-users text-slate-500 mr-1 w-4"></i> <?php echo $f['passenger_count']; ?> / <?php echo $f['max_pax']; ?></span>
                                        <span class="text-emerald-400 font-bold"><i class="fas fa-ticket-alt text-slate-500 mr-1 w-4"></i> R$ <?php echo number_format($f['ticket_price'], 2); ?></span>
                                        <span class="text-slate-400 mt-1"><i class="fas fa-gas-pump text-slate-500 mr-1 w-4"></i> <?php echo number_format($f['estimated_fuel']); ?> kg</span>
                                    </div>
                                </td>
                                <td class="px-6 py-3 flex flex-col"><span class="font-bold text-slate-200"><?php echo $f['registration'] ?: '--'; ?></span><span class="text-[9px] text-slate-500 uppercase"><?php echo $f['aircraft_type']; ?></span></td>
                                <td class="px-6 py-3"><?php echo intval($f['duration_minutes'] / 60) . 'h ' . ($f['duration_minutes'] % 60) . 'm'; ?></td>
                                <td class="px-6 py-3 text-right pr-8">
                                    <div class="flex items-center justify-end gap-3 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <a href="?edit_id=<?php echo $f['id']; ?>" class="w-8 h-8 rounded-full bg-indigo-500/20 hover:bg-indigo-500 hover:text-white text-indigo-400 flex items-center justify-center transition-all"><i class="fas fa-edit text-xs"></i></a>
                                        <form method="POST" onsubmit="return confirm('Excluir?');" class="inline">
                                            <input type="hidden" name="delete_id" value="<?php echo $f['id']; ?>">
                                            <button type="submit" class="w-8 h-8 rounded-full bg-rose-500/20 hover:bg-rose-500 hover:text-white text-rose-400 flex items-center justify-center transition-all"><i class="fas fa-trash-alt text-xs"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../SimBrief_APIv1/simbrief.apiv1.js"></script>
<script>
    var api_dir = '../SimBrief_APIv1/';
    sbworkerstyle = 'width=1000,height=800'; // Increase size for full login interface
    let debounceTimer, map, mapObjects = {}, currentBounds = null, plannedRouteLayer = null;
    let isAnimating = false;
    let airportCoordinates = {}; // Lookup for airport positions
    let allRouteData = <?php echo $initialMapData; ?>; 
    let isFullNetworkLoaded = <?php echo $showFullNetwork ? 'true' : 'false'; ?>;
    let mapMode = 'roster'; 
    const routeLayerGroup = L.layerGroup();
    const activeFlights = new Set(); // Track only flights currently animating
    const resetTimers = {}; // Store timers for debounce

    // Aggressive redirection check
    let redirectCheckStarted = false;
    let manualCheckActive = false;

    // Override the library's Redirect_caller to make it more robust in an iframe
    function Redirect_caller() {
        if (typeof ofp_id === 'undefined' || !ofp_id) {
            setTimeout(Redirect_caller, 500);
            return;
        }

        const statusEl = document.getElementById('sbStatusText');
        if (statusEl) statusEl.textContent = 'Verificando plano: ' + ofp_id;

        if (fe_result === 'true') {
            if (statusEl) statusEl.textContent = 'PLANO ENCONTRADO! Redirecionando...';
            handleSimBriefDone(ofp_id);
            return;
        }

        // Check file status via original PHP bridge
        fe_result = 'notset';
        const url = api_dir + 'simbrief.apiv1.php?js_url_check=' + ofp_id + '&var=fe_result';
        const script = document.createElement('script');
        script.src = url + '&p=' + Math.floor(Math.random() * 1000000);
        document.head.appendChild(script);
        
        setTimeout(Redirect_caller, 1500);
    }

    function checkSBworker() {
        if (!redirectCheckStarted && typeof ofp_id !== 'undefined' && ofp_id) {
            redirectCheckStarted = true;
            Redirect_caller();
        }
    }

    function checkSBworkerManual() {
        fe_result = 'notset'; // Force re-check
        Redirect_caller();
        setTimeout(() => {
            if (fe_result !== 'true') alert('O plano ainda não parece estar pronto no servidor do SimBrief. Aguarde o 100% aparecer na barra verde.');
        }, 2000);
    }

    window.handleSimBriefDone = function(ofpId) {
        if (typeof SBloop !== 'undefined' && SBloop) window.clearInterval(SBloop);
        
        const currentUrl = new URL(window.location.origin + window.location.pathname);
        currentUrl.searchParams.set('ofp_id', ofpId);
        currentUrl.searchParams.set('aircraft_id', document.getElementById('ac').value);
        currentUrl.searchParams.set('fn', document.getElementsByName('flight_number')[0].value);
        
        // Preserve edit_id if exists
        const params = new URLSearchParams(window.location.search);
        if (params.has('edit_id')) {
            currentUrl.searchParams.set('edit_id', params.get('edit_id'));
        }
        
        window.location.href = currentUrl.toString();
    }

    window.addEventListener('message', function(event) {
        if (event.data.type === 'simbrief_done') {
            handleSimBriefDone(event.data.ofp_id);
        }
    });


    function getAeronauticalIcon(type, color = '#ffffff') {
        type = (type || 'WPT').toUpperCase();
        let svg = '';
        let size = [12, 12];
        let anchor = [6, 6];

        if (type === 'VOR' || type === 'VORTAC' || type === 'TACAN') {
            svg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="${color}" stroke="black" stroke-width="1.5">
                    <path d="M12 2L20.66 7V17L12 22L3.34 17V7L12 2Z" />
                    <circle cx="12" cy="12" r="3" fill="black" />
                  </svg>`;
        } else if (type === 'VOR-DME' || type === 'VORDME') {
            svg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="${color}" stroke="black" stroke-width="1.5">
                    <rect x="2" y="2" width="20" height="20" />
                    <path d="M12 4L18.92 8V16L12 20L5.08 16V8L12 4Z" fill="black" />
                    <circle cx="12" cy="12" r="2" fill="${color}" />
                  </svg>`;
        } else if (type === 'NDB') {
            svg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2">
                    <circle cx="12" cy="12" r="2" fill="${color}" stroke="none" />
                    <circle cx="12" cy="12" r="5" />
                    <circle cx="12" cy="12" r="9" />
                  </svg>`;
        } else if (type === 'INT' || type === 'FIX' || type === 'REPORTING') {
            svg = `<svg width="10" height="10" viewBox="0 0 24 24" fill="${color}" stroke="black" stroke-width="1">
                    <path d="M12 2L22 20H2L12 2Z" />
                  </svg>`;
            size = [10, 10]; anchor = [5, 5];
        } else {
            // Default Waypoint WPT
            svg = `<svg width="10" height="10" viewBox="0 0 24 24" fill="${color}" stroke="black" stroke-width="1">
                    <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" />
                  </svg>`;
            size = [10, 10]; anchor = [5, 5];
        }

        return L.divIcon({
            className: '',
            html: `<div style="width: ${size[0]}px; height: ${size[1]}px; display: flex; align-items: center; justify-content: center; filter: drop-shadow(0 0 2px rgba(0,0,0,0.8)); overflow: visible;">${svg}</div>`,
            iconSize: size,
            iconAnchor: anchor
        });
    }

    function initMap() {
        map = L.map('routeMap', { 
            zoomControl: false,
            preferCanvas: true // Use Canvas for rendering vector layers (huge performance boost)
        }).setView([-15.78, -47.92], 4);
        map.on('zoomend', updateMarkerSize);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { 
            attribution: '&copy; CARTO',
            subdomains: 'abcd',
            detectRetina: true,
            maxZoom: 20,
            minZoom: 3,
            keepBuffer: 8, // Pre-load a much larger area to prevent gaps during high-speed panning
            updateWhenIdle: false, // Transition back to live updates for a more responsive feel
            updateInterval: 100,
            className: 'map-tiles'
        }).addTo(map);
        
        map.createPane('hubs');
        map.getPane('hubs').style.zIndex = 700;
        
        map.createPane('lines');
        map.getPane('lines').style.zIndex = 350;

        map.createPane('planes');
        map.getPane('planes').style.zIndex = 600;
        map.getPane('planes').style.pointerEvents = 'none'; // CRITICAL: Prevent planes from stealing mouse focus
        
        map.getPane('tooltipPane').style.pointerEvents = 'none'; // Ensure tooltips don't steal focus
        
        routeLayerGroup.addTo(map);
        plannedRouteLayer = L.layerGroup().addTo(map); // Draft Route Layer
        
        // Initial Refresh using pre-baked data (Only Accepted)
        refreshMapLayers();

        if (typeof planeAnim !== 'undefined') cancelAnimationFrame(planeAnim);
        animatePlanes();
    }

    // New function to draw the current planning route
    function drawPlannedRoute() {
        if (!map) return;
        plannedRouteLayer.clearLayers();

        const dep = document.getElementById('dep').value.trim().toUpperCase();
        const arr = document.getElementById('arr').value.trim().toUpperCase();
        const waypointsInput = document.getElementsByName('route_waypoints')[0];
        const waypointsVal = waypointsInput ? waypointsInput.value : '';
        
        // Try to get coordinates if missing and field is 4 chars
        if (dep.length === 4 && !airportCoordinates[dep]) {
            fetch(`../api/search_airports.php?term=${dep}`)
                .then(r => r.json())
                .then(data => {
                    const match = data.find(x => x.value === dep);
                    if (match && match.lat && match.lng) {
                        airportCoordinates[dep] = [parseFloat(match.lat), parseFloat(match.lng)];
                        drawPlannedRoute();
                    }
                });
        }
        if (arr.length === 4 && !airportCoordinates[arr]) {
            fetch(`../api/search_airports.php?term=${arr}`)
                .then(r => r.json())
                .then(data => {
                    const match = data.find(x => x.value === arr);
                    if (match && match.lat && match.lng) {
                        airportCoordinates[arr] = [parseFloat(match.lat), parseFloat(match.lng)];
                        drawPlannedRoute();
                    }
                });
        }

        if (!dep || !arr || !airportCoordinates[dep] || !airportCoordinates[arr]) return;

        const p1 = airportCoordinates[dep];
        const p2 = airportCoordinates[arr];
        
        let path = [p1, p2];
        let isDetailed = false;

        if (waypointsVal && waypointsVal !== 'null') {
            try {
                const wps = JSON.parse(waypointsVal);
                if (Array.isArray(wps) && wps.length > 0) {
                    path = wps.map(p => [p.lat, p.lng]);
                    
                    // Ensure the polyline starts at Origin and ends at Destination
                    if (map.distance(path[0], p1) > 200) path.unshift(p1);
                    if (map.distance(path[path.length - 1], p2) > 200) path.push(p2);
                    
                    isDetailed = true;

                    // Draw Waypoint Markers for the planned route
                    wps.forEach(pt => {
                        if (!pt.lat || !pt.lng) return;
                        const wpIcon = getAeronauticalIcon(pt.type, '#fbbf24');
                        L.marker([pt.lat, pt.lng], {
                            icon: wpIcon,
                            pane: 'lines',
                            interactive: false
                        }).addTo(plannedRouteLayer)
                        .bindTooltip(pt.name, {
                            permanent: true,
                            direction: 'top',
                            className: 'waypoint-tooltip',
                            offset: [0, -6]
                        });
                    });
                }
            } catch(e) { console.error("Error parsing waypoints", e); }
        }

        // Draw Line
        L.polyline(path, {
            color: isDetailed ? '#6366f1' : '#fbbf24', 
            weight: 3,
            opacity: 0.8,
            dashArray: isDetailed ? null : '8, 12',
            interactive: false,
            pane: 'lines'
        }).addTo(plannedRouteLayer);

        // Draw Endpoints
        const markerStyle = { radius: 6, color: '#fbbf24', weight: 2, fillColor: '#0c0e17', fillOpacity: 1, interactive: false, pane: 'hubs' };
        
        const m1 = L.circleMarker(p1, markerStyle).addTo(plannedRouteLayer);
        m1.bindTooltip(dep, { permanent: true, direction: 'top', className: 'glass-tooltip', offset: [0, -10] });
        
        const m2 = L.circleMarker(p2, markerStyle).addTo(plannedRouteLayer);
        m2.bindTooltip(arr, { permanent: true, direction: 'top', className: 'glass-tooltip', offset: [0, -10] });

        // Auto-Fit if not manually roaming
        if (!selectedFlight) {
            const bounds = L.latLngBounds(path);
            map.flyToBounds(bounds, { 
                paddingTopLeft: [420, 100],
                paddingBottomRight: [60, 120],
                duration: 1.5 
            });
        }
    }

    async function loadMapRoutes() {
        if (isFullNetworkLoaded) return;
        try {
            const r = await fetch('../api/get_route_map.php');
            const data = await r.json();
            if (Array.isArray(data)) {
                allRouteData = data;
                isFullNetworkLoaded = true;
                refreshMapLayers();
            }
        } catch(e) { console.error(e); }
    }

    function toggleMapMode(mode) {
        mapMode = mode;
        document.getElementById('toggleRoster').classList.toggle('active', mode === 'roster');
        document.getElementById('toggleAll').classList.toggle('active', mode === 'all');
        
        if (mode === 'all' && !isFullNetworkLoaded) {
            loadMapRoutes();
        } else {
            refreshMapLayers();
        }
        updateMapFilters();
    }

    function syncFilters(source, event) {
        const val = source.value;
        const targetId = source.id === 'flightSearch' ? 'mapIcaoFilter' : 'flightSearch';
        const target = document.getElementById(targetId);
        if (target) target.value = val;
        
        // If ENTER is pressed, switch to 'All Routes' mode automatically to show full network
        if (event && event.key === 'Enter' && val.trim() !== '') {
            toggleMapMode('all');
        } else {
            updateMapFilters();
        }
    }

    function updateMapFilters() {
        const filterIcao = document.getElementById('mapIcaoFilter').value.trim().toUpperCase();
        
        // 1. Filter Sidebar List (Sync with Map)
        let visibleCount = 0;
        const rows = document.querySelectorAll('#malha-table tbody tr');
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            const fnum = row.cells[0]?.textContent.toUpperCase() || "";
            const trecho = row.cells[1]?.textContent.toUpperCase() || "";
            
            let show = true;

            // Apply Mode Filter (Roster vs All)
            if (mapMode === 'roster' && status !== 'Accepted') {
                show = false;
            }

            // Apply ICAO/Search Filter
            if (show && filterIcao) {
                if (!fnum.includes(filterIcao) && !trecho.includes(filterIcao)) {
                    show = false;
                }
            }

            if (show) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Update Dynamic Badge
        const badge = document.getElementById('flight-count-badge');
        if (badge) badge.textContent = `${visibleCount} ATIVAS`;

        // 2. Debounce Map Refresh (Heavier)
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            refreshMapLayers();
        }, 300);
    }

    function refreshMapLayers() {
        if (!allRouteData || !Array.isArray(allRouteData)) return;
        const filterIcao = document.getElementById('mapIcaoFilter').value.trim().toUpperCase();
        
        // Clean up existing objects - Layer group handles most of it
        routeLayerGroup.clearLayers();
        activeFlights.clear();
        mapObjects = {};
        airportMarkers = []; 
        const airportData = {}; 
        const b = [];
        
        // 1. Filter and Build Indices
        const arrivingAt = {};
        const filteredRoutes = allRouteData.filter(x => {
            if (mapMode === 'roster' && x.roster_status !== 'Accepted') return false;
            
            if (filterIcao) {
                const dep = (x.dep_icao || "").toUpperCase();
                const arr = (x.arr_icao || "").toUpperCase();
                if (!dep.includes(filterIcao) && !arr.includes(filterIcao)) return false;
            }
            return true;
        });

        filteredRoutes.forEach(x => {
            if (!arrivingAt[x.arr_icao]) arrivingAt[x.arr_icao] = [];
            arrivingAt[x.arr_icao].push(x.flight_number);

            if (x.dep_lat && x.arr_lat) {
                const p1 = [parseFloat(x.dep_lat), parseFloat(x.dep_lon)]; 
                const p2 = [parseFloat(x.arr_lat), parseFloat(x.arr_lon)];
                
                airportCoordinates[x.dep_icao] = p1;
                airportCoordinates[x.arr_icao] = p2;
                b.push(p1, p2);
                
                if (!airportData[x.dep_icao]) airportData[x.dep_icao] = { pos: p1, departing: [], hasAccepted: false };
                if (!airportData[x.arr_icao]) airportData[x.arr_icao] = { pos: p2, departing: [], hasAccepted: false };
                
                if (x.roster_status === 'Accepted') {
                    airportData[x.dep_icao].hasAccepted = true;
                    airportData[x.arr_icao].hasAccepted = true;
                }
                airportData[x.dep_icao].departing.push(x.flight_number);
            }
        });

        // 2. Draw Lines 
        filteredRoutes.forEach(x => {
            if (x.dep_lat && x.arr_lat) {
                const p1 = airportCoordinates[x.dep_icao];
                const p2 = airportCoordinates[x.arr_icao];
                const directPath = [p1, p2];
                let detailedPath = null;
                
                if (x.route_waypoints) {
                    try {
                        const wps = JSON.parse(x.route_waypoints);
                        if (Array.isArray(wps) && wps.length > 0) {
                            detailedPath = wps.map(p => [p.lat, p.lng]);
                            if (map.distance(detailedPath[0], p1) > 200) detailedPath.unshift(p1);
                            if (map.distance(detailedPath[detailedPath.length - 1], p2) > 200) detailedPath.push(p2);
                        }
                    } catch(e) {}
                }

                const isAccepted = x.roster_status === 'Accepted';
                const line = L.polyline(directPath, { 
                    color: isAccepted ? '#06b6d4' : '#94a3b8', 
                    weight: isAccepted ? 2 : 1, 
                    opacity: isAccepted ? 0.6 : 0.15, 
                    dashArray: isAccepted ? null : '3,3', 
                    interactive: false,
                    pane: 'lines' 
                }).addTo(routeLayerGroup);

                mapObjects[x.flight_number] = { 
                    line, 
                    directPath,
                    detailedPath: detailedPath || directPath,
                    markers: [], 
                    plane: null, // Don't create yet to save memory/DOM
                    p1, p2,
                    progress: 0, 
                    speed: 0.002, 
                    active: false,
                    dep_icao: x.dep_icao,
                    arr_icao: x.arr_icao,
                    dep_time: x.dep_time ? x.dep_time.substring(0, 5) : '',
                    arr_time: x.arr_time ? x.arr_time.substring(0, 5) : '',
                    roster_status: x.roster_status
                };
            }
        });

        // 3. Draw Markers (Hubs) on top
        for (const icao in airportData) {
            const data = airportData[icao];
            const markerColor = data.hasAccepted ? '#06b6d4' : '#818cf8';
            const marker = L.circleMarker(data.pos, { 
                radius: 6, color: markerColor, weight: 2, fillColor: '#1e1b4b', fillOpacity: 1, interactive: true, pane: 'hubs', className: 'airport-node'
            }).addTo(routeLayerGroup);

            marker.bindTooltip(icao, { permanent: false, direction: 'top', className: 'glass-tooltip', offset: [0, -10] });
            
            marker.on('mouseover', function(e) {
                L.DomEvent.stopPropagation(e);
                this.setStyle({ color: '#fbbf24', radius: 7, weight: 2 });
                data.departing.forEach(fn => highlightRoute(fn, false)); 
            });
            
            marker.on('mouseout', function(e) {
                L.DomEvent.stopPropagation(e);
                this.setStyle({ color: '#818cf8', radius: 6, weight: 2 });
                data.departing.forEach(fn => resetRoute(fn, false));
            });

            airportMarkers.push(marker);

            // Associate with map objects
            data.departing.forEach(fnum => {
                if (mapObjects[fnum]) mapObjects[fnum].markers.push(marker);
            });
            if (arrivingAt[icao]) {
                arrivingAt[icao].forEach(fnum => {
                    if (mapObjects[fnum] && !mapObjects[fnum].markers.includes(marker)) {
                        mapObjects[fnum].markers.push(marker);
                    }
                });
            }
        }
        
        updateMarkerSize();

        if (b.length > 0) {
            currentBounds = L.latLngBounds(b);
            fitMapToRoutes();
        }

        if (selectedFlight && mapObjects[selectedFlight]) {
            highlightRoute(selectedFlight, true, '#ffffff', true);
        }
    }
    
    let airportMarkers = [];
    function updateMarkerSize() {
        if (!map) return;
        const z = map.getZoom();
        // Scale: Zoom 4 -> 3px, Zoom 10+ -> 10px
        let r = 3 + (z - 4) * 1.5;
        if (r < 3) r = 3; 
        if (r > 12) r = 12;

        airportMarkers.forEach(m => m.setRadius(r));
    }

    function fitMapToRoutes() {
        if (!currentBounds || !map) return;
        const shelf = document.getElementById('malha-shelf');
        const isMinimized = shelf.classList.contains('minimized');
        
        const padding = {
            paddingTopLeft: [420, 100],
            paddingBottomRight: [60, isMinimized ? 120 : 320],
            animate: true,
            duration: 1.2
        };
        
        map.fitBounds(currentBounds, padding);
    }

    let selectedFlight = null;
    let selectedRow = null;

    function selectFlight(row, fnum) {
        if (isAnimating) return;
        
        // Prevent clicking buttons from triggering row selection (basic check)
        if (event.target.closest('a') || event.target.closest('button')) return;

        // If deselecting current
        if (selectedFlight === fnum) {
            resetRoute(fnum, true, true);
            row.classList.remove('bg-white/10', 'border-indigo-500');
            row.classList.add('border-transparent');
            selectedFlight = null;
            selectedRow = null;
            
            // Reset Zoom to Global View
            fitMapToRoutes();
            return;
        }

        // If changing selection
        if (selectedFlight) {
            resetRoute(selectedFlight, true, true);
            if (selectedRow) {
                selectedRow.classList.remove('bg-white/10', 'border-indigo-500');
                selectedRow.classList.add('border-transparent');
            }
        }

        // Select new
        selectedFlight = fnum;
        selectedRow = row;
        row.classList.remove('border-transparent');
        row.classList.add('bg-white/10', 'border-indigo-500');
        
        // Highlight with Detailed Path (White)
        highlightRoute(fnum, true, '#ffffff', true);

        // Auto-Zoom to Route
        const obj = mapObjects[fnum];
        if (obj) {
            const path = obj.detailedPath || obj.directPath;
            const bounds = L.latLngBounds(path);
            bounds.extend(obj.p1);
            bounds.extend(obj.p2);
            
            const shelf = document.getElementById('malha-shelf');
            const isMinimized = shelf.classList.contains('minimized');
            
            map.flyToBounds(bounds, {
                paddingTopLeft: [420, 100],
                paddingBottomRight: [60, isMinimized ? 120 : 320],
                duration: 1.5,
                easeLinearity: 0.1
            });
        }
    }

    function focusFlight(row, fnum) {
         if (isAnimating) return;
         
         // Ensure it's selected first
         if (selectedFlight !== fnum) {
             selectFlight(row, fnum);
         }
         
         const obj = mapObjects[fnum];
         if (obj) {
             // 1. Plot Detailed Route
             highlightRoute(fnum, true, '#ffffff', true);
             
             // 2. Zoom Info
             const path = obj.detailedPath || obj.directPath;
             const bounds = L.latLngBounds(path);
             bounds.extend(obj.p1);
             bounds.extend(obj.p2);
             
             // 3. Gradual Zoom
             const shelf = document.getElementById('malha-shelf');
             const isMinimized = shelf.classList.contains('minimized');
             
             map.flyToBounds(bounds, {
                 paddingTopLeft: [420, 100],
                 paddingBottomRight: [60, isMinimized ? 120 : 320],
                 duration: 2.0,
                 easeLinearity: 0.1
             });
         }
    }

    function highlightRoute(fnum, updateMarkers = true, color = '#06b6d4', useDetailed = false) {
        if (isAnimating) return;
        
        // Cancel any pending reset for this flight
        if (resetTimers[fnum]) {
            clearTimeout(resetTimers[fnum]);
            delete resetTimers[fnum];
        }

        const obj = mapObjects[fnum];
        if (obj) {
            // Swap geometry based on hover target (Row=Direct, RouteCell=Detailed)
            const path = useDetailed ? obj.detailedPath : obj.directPath;
            obj.line.setLatLngs(path);

            obj.line.setStyle({ color: color, weight: color === '#ffffff' ? 4 : 2, opacity: 1, dashArray: null });
            obj.line.bringToFront();
            
            // Activate Plane Animation & Create Marker if needed
            if (!obj.active) {
                obj.active = true;
                activeFlights.add(fnum);
                if (obj.progress < 0.05) obj.progress = 0.05; 
            }

            if (!obj.plane) {
                const dy = obj.p2[0] - obj.p1[0];
                const dx = obj.p2[1] - obj.p1[1];
                let angle = (Math.atan2(dx, dy) * 180 / Math.PI); 

                const planeIcon = L.divIcon({
                    className: 'plane-node',
                    html: `<svg class="plane-svg plane-icon" viewBox="0 0 24 24" style="transform: rotate(${angle}deg);"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>`,
                    iconSize: [24, 24],
                    iconAnchor: [12, 12] 
                });
                obj.plane = L.marker(obj.p1, { icon: planeIcon, pane: 'planes', interactive: false, opacity: 0 }).addTo(routeLayerGroup);
            }
            
            // Ensure plane is visible immediately if highlighted
            obj.plane.setOpacity(1); 
            
            const el = obj.plane.getElement();
            if (el) {
                obj.plane.setZIndexOffset(1000); 
                const icon = el.querySelector('.plane-icon');
                if (icon) {
                    icon.classList.add('plane-highlight');
                    // Force white color if using detailed route
                    if (useDetailed) {
                        icon.style.filter = "contrast(0) brightness(2)"; 
                    } else {
                        icon.style.filter = ""; 
                    }
                }
            }
            
            if (updateMarkers) {
                obj.markers.forEach(m => m.setStyle({ color: color, radius: 6, weight: 2 }));
            }

            if (useDetailed && obj.roster_status === 'Accepted') {
                if (!obj.endpointMarkers) {
                    const iconStyle = "color: white; font-size: 11px; font-weight: 800; white-space: nowrap; text-shadow: 0 0 4px #000, 0 0 8px #000; text-align: center; line-height: 1.1;";
                    
                    const m1 = L.marker(obj.p1, {
                        icon: L.divIcon({ 
                            className: '', 
                            html: `<div style="${iconStyle}">${obj.dep_icao}<br><span style="color:#94a3b8; font-size:9px; font-weight:600;">${obj.dep_time}Z</span></div>`, 
                            iconSize: [60, 30], 
                            iconAnchor: [30, -10] 
                        }),
                        interactive: false
                    });
                    const m2 = L.marker(obj.p2, {
                        icon: L.divIcon({ 
                            className: '', 
                            html: `<div style="${iconStyle}">${obj.arr_icao}<br><span style="color:#94a3b8; font-size:9px; font-weight:600;">${obj.arr_time}Z</span></div>`, 
                            iconSize: [60, 30], 
                            iconAnchor: [30, -10] 
                        }),
                        interactive: false
                    });
                    obj.endpointMarkers = [m1, m2];
                }
                obj.endpointMarkers.forEach(m => m.addTo(map));
            }

            // Waypoints Labels Logic
            if (useDetailed && obj.detailedPath && obj.detailedPath.length > 0 && obj.detailedPath[0].name) {
                 if (!obj.waypointMarkers) {
                     obj.waypointMarkers = obj.detailedPath.map(pt => {
                         // Skip if coordinates are invalid
                         if (!pt.lat || !pt.lng) return null;
                         
                         const wpIcon = getAeronauticalIcon(pt.type, color);

                         return L.marker([pt.lat, pt.lng], {
                             icon: wpIcon,
                             pane: 'lines', // Use lines pane to be below planes
                             interactive: false
                         }).bindTooltip(pt.name, {
                             permanent: true,
                             direction: 'top',
                             className: 'waypoint-tooltip',
                             offset: [0, -6]
                         });
                     }).filter(x => x);
                 }
                 obj.waypointMarkers.forEach(m => m.addTo(map));
            } else {
                 if (obj.waypointMarkers) {
                     obj.waypointMarkers.forEach(m => m.remove());
                 }
            }
        }
    }

    function resetRoute(fnum, updateMarkers = true, force = false) {
        // Prevent resetting if it's the currently selected flight in the table
        if (selectedFlight === fnum && !force) return;

        // Add debounce to prevent flickering
        if (resetTimers[fnum]) clearTimeout(resetTimers[fnum]);
        
        resetTimers[fnum] = setTimeout(() => {
            const obj = mapObjects[fnum];
            
            // Clear waypoints and endpoint markers
            if (obj && obj.waypointMarkers) {
                obj.waypointMarkers.forEach(m => m.remove());
            }
            if (obj && obj.endpointMarkers) {
                obj.endpointMarkers.forEach(m => m.remove());
            }

            if (obj && obj.plane) {
                // Optimization: Reset to direct path for background malha
                obj.line.setLatLngs(obj.directPath);
                obj.line.setStyle({ color: '#94a3b8', weight: 1, opacity: 0.15, dashArray: '3,3' });
                
                // Deactivate Plane Animation
                obj.active = false;
                activeFlights.delete(fnum); // Remove from active flights
                obj.progress = 0;
                
                // Remove the plane marker from the map
                obj.plane.remove();
                obj.plane = null; // Set to null so it can be recreated if needed
                
                if (updateMarkers) {
                    obj.markers.forEach(m => m.setStyle({ color: '#818cf8', radius: 6, weight: 2 }));
                }
            }
        }, 50); // 50ms buffer
    }

    let planeAnim;
    function getPointAtLength(path, pct) {
        // Helper to get lat/lng regardless of format
        const getVal = (pt) => ({
            lat: pt.lat !== undefined ? pt.lat : pt[0],
            lng: pt.lng !== undefined ? pt.lng : pt[1]
        });

        // Calculate total length (approx)
        let totalDist = 0;
        const dists = [];
        for (let i = 0; i < path.length - 1; i++) {
            const d = map.distance(path[i], path[i+1]);
            totalDist += d;
            dists.push(d);
        }
        
        const targetDist = totalDist * pct;
        let runningDist = 0;
        
        for (let i = 0; i < dists.length; i++) {
            if (runningDist + dists[i] >= targetDist) {
                const segPct = (targetDist - runningDist) / dists[i];
                const p1 = getVal(path[i]);
                const p2 = getVal(path[i+1]);
                
                // INTERPOLATE IN PIXEL SPACE TO STAY ON THE LINE
                // Mercator distortion means linear lat/lng interpolation doesn't match a straight line on map
                const p1_p = map.latLngToLayerPoint(p1);
                const p2_p = map.latLngToLayerPoint(p2);
                const p_p = L.point(
                    p1_p.x + (p2_p.x - p1_p.x) * segPct,
                    p1_p.y + (p2_p.y - p1_p.y) * segPct
                );
                const pt = map.layerPointToLatLng(p_p);
                
                // Calculate visual angle on map (atan2(dx, -dy) because screen Y is inverted)
                const angle = (Math.atan2(p2_p.x - p1_p.x, -(p2_p.y - p1_p.y)) * 180 / Math.PI);
                
                return { lat: pt.lat, lng: pt.lng, angle: angle };
            }
            runningDist += dists[i];
        }
        const last = getVal(path[path.length-1]);
        return { lat: last.lat, lng: last.lng, angle: 0 };
    }

    function animatePlanes() {
        if (!map) return;
        try {
            activeFlights.forEach(fnum => {
                const obj = mapObjects[fnum];
                if (obj && obj.plane && obj.active) {
                    obj.progress += obj.speed;
                    if (obj.progress >= 1) obj.progress = 0;
                    
                    const currentPath = obj.line.getLatLngs(); 

                    if (Array.isArray(currentPath) && currentPath.length > 2) {
                        const pt = getPointAtLength(currentPath, obj.progress);
                        obj.plane.setLatLng([pt.lat, pt.lng]);
                        const el = obj.plane.getElement()?.querySelector('.plane-svg');
                        if (el) el.style.transform = `rotate(${pt.angle}deg)`;
                        
                    } else {
                        const p1_p = map.latLngToLayerPoint(obj.p1);
                        const p2_p = map.latLngToLayerPoint(obj.p2);
                        const p_p = L.point(
                            p1_p.x + (p2_p.x - p1_p.x) * obj.progress,
                            p1_p.y + (p2_p.y - p1_p.y) * obj.progress
                        );
                        const pt = map.layerPointToLatLng(p_p);
                        obj.plane.setLatLng(pt);
                        
                        const angle = (Math.atan2(p2_p.x - p1_p.x, -(p2_p.y - p1_p.y)) * 180 / Math.PI);
                        const el = obj.plane.getElement()?.querySelector('.plane-svg');
                        if (el) el.style.transform = `rotate(${angle}deg)`;
                    }

                    // Fade In / Out Logic
                    let op = 1;
                    const isSelected = (selectedFlight === fnum);
                    if (!isSelected) {
                        if (obj.progress < 0.15) op = obj.progress / 0.15;
                        else if (obj.progress > 0.85) op = (1 - obj.progress) / 0.15;
                    }
                    obj.plane.setOpacity(op);
                }
            });
        } catch (e) {
            console.error("Animation Error:", e);
        }
        planeAnim = requestAnimationFrame(animatePlanes);
    }

    function searchAirport(input) {
        const term = input.value.trim();
        const list = document.getElementById(input.id + '_list');
        if (term.length < 2) { list.classList.add('hidden'); return; }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            fetch(`../api/search_airports.php?term=${term}`)
                .then(r => r.json())
                .then(data => {
                    list.innerHTML = '';
                    if (data.length) {
                        list.classList.remove('hidden');
                        data.forEach(x => {
                            const d = document.createElement('div');
                            d.className = 'px-4 py-2 hover:bg-white/10 cursor-pointer text-[11px] text-slate-300 border-b border-white/5 last:border-0';
                            d.textContent = x.label;
                            d.onclick = () => { 
                                input.value = x.value; 
                                list.classList.add('hidden'); 
                                if (x.lat && x.lng) airportCoordinates[x.value] = [parseFloat(x.lat), parseFloat(x.lng)];
                                checkAvailability(); 
                                drawPlannedRoute();
                            };
                            list.appendChild(d);
                        });
                    } else list.classList.add('hidden');
                });
        }, 300);
    }

    function updateSbInputs() {
        const dep = document.getElementById('dep').value.toUpperCase();
        const arr = document.getElementById('arr').value.toUpperCase();
        const acId = document.getElementById('ac').value;
        const fltNum = document.getElementsByName('flight_number')[0].value.replace(/\D/g, '');
        const route = document.getElementById('route').value;
        const depTime = document.getElementById('dep_time').value;
        
        if (dep) document.getElementsByName('orig')[0].value = dep;
        if (arr) document.getElementsByName('dest')[0].value = arr;
        if (fltNum) document.getElementsByName('fltnum')[0].value = fltNum;
        if (route) document.getElementsByName('route')[0].value = route;
        
        if (depTime) {
            const [h, m] = depTime.split(':');
            document.getElementsByName('deph')[0].value = h;
            document.getElementsByName('depm')[0].value = m;
        }
        
        if (acId) {
            const acOption = document.querySelector(`#ac option[value="${acId}"]`);
            const acText = acOption ? acOption.text : "";
            const match = acText.match(/\((.*?)\)/);
            if (match) document.getElementsByName('type')[0].value = match[1];
        }
    }

    function fetchSimBrief() {
        const dep = document.getElementById('dep').value, arr = document.getElementById('arr').value, acId = document.getElementById('ac').value;
        const fltNum = document.getElementsByName('flight_number')[0].value;
        if (!dep || !arr || !acId) return alert('DADOS INCOMPLETOS: Selecione Origem, Destino e Aeronave.');
        updateSbInputs();
        
        // Prepare output URL with current context to preserve state
        let url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('aircraft_id', acId);
        url.searchParams.set('fn', fltNum);
        
        // Preserve edit_id if exists
        const params = new URLSearchParams(window.location.search);
        if (params.has('edit_id')) {
            url.searchParams.set('edit_id', params.get('edit_id'));
        }
        
        redirectCheckStarted = false; 
        simbriefsubmit(url.toString());
    }

    function calcArrTime() {
        const dep = document.getElementById('dep_time').value;
        const durVal = document.getElementById('dur').value;
        
        if (dep && durVal !== "") {
            const dur = parseInt(durVal);
            const [h, m] = dep.split(':').map(Number);
            const t = (h * 60) + m + dur;
            document.getElementById('arr_time').value = `${String(Math.floor(t / 60) % 24).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
        } else {
            document.getElementById('arr_time').value = "";
        }
    }


    async function calcTicketPrice() {
        const acEl = document.getElementById('ac');
        const durEl = document.getElementById('dur');
        const ticketEl = document.getElementById('ticket_price');
        const maxPaxEl = document.getElementById('max_pax');
        const autoTag = document.getElementById('ticket_auto');
        
        if (!acEl.value || !durEl.value) return;
        
        const icao = acEl.options[acEl.selectedIndex]?.getAttribute('data-icao');
        if (!icao) return;
        
        try {
            const r = await fetch(`../api/calc_ticket_price.php?model=${icao}&duration=${durEl.value}`);
            const data = await r.json();
            
            if (data.ticket_price > 0) {
                ticketEl.value = data.ticket_price.toFixed(2);
                maxPaxEl.value = data.max_pax;
                if (autoTag) autoTag.classList.remove('hidden');
            }
        } catch(e) {
            console.error('Erro ao calcular preço:', e);
        }
    }

    function checkAvailability(isAcChange = false) {
        const acEl = document.getElementById('ac'), acId = acEl.value, depInput = document.getElementById('dep'), dep = depInput.value.trim().toUpperCase();
        const showAll = document.getElementById('show_all_ac').checked, statusEl = document.getElementById('ac_status'), schedEl = document.getElementById('ac_schedule'), schedList = document.getElementById('schedule_list');
        
        if (isAcChange && acId) {
            const loc = acEl.options[acEl.selectedIndex].getAttribute('data-location');
            if (loc) depInput.value = loc;
        }

        acEl.querySelectorAll('option').forEach(o => { 
            if (o.value) {
                const isSelected = (acId === o.value);
                const isMatch = (showAll || !dep || o.getAttribute('data-location') === dep);
                
                // Always show the selected option, otherwise filter by match
                o.style.display = (isMatch || isSelected) ? 'block' : 'none';
                
                // Logic to clear value removed to prevent auto-deselecting on load
            }
        });

        if (acId) {
            fetch(`../api/get_ac_status.php?id=${acId}`).then(r => r.json()).then(data => {
                statusEl.classList.remove('hidden'); statusEl.innerHTML = `<span class="text-indigo-400 font-bold uppercase tracking-tighter">Posição: ${data.current_location}</span>`;
                
                // Auto-Zoom to current location or planned route
                const dep = document.getElementById('dep').value.trim().toUpperCase();
                const arr = document.getElementById('arr').value.trim().toUpperCase();
                
                if (dep && arr && airportCoordinates[dep] && airportCoordinates[arr]) {
                    // Zoom to fit the planned route
                    const bounds = L.latLngBounds([airportCoordinates[dep], airportCoordinates[arr]]);
                    map.flyToBounds(bounds, { padding: [100, 100], duration: 1.5 });
                } else if (airportCoordinates[data.current_location]) {
                    // Zoom to current aircraft location
                    map.flyTo(airportCoordinates[data.current_location], 7, { duration: 1.5 });
                }

                // Only show schedule if it's a manual change OR if dep_time is empty
                const depTimeFilled = document.getElementById('dep_time').value !== "";
                if (isAcChange || !depTimeFilled) {
                    schedEl.classList.remove('hidden');
                } else {
                    schedEl.classList.add('hidden');
                }

                schedList.innerHTML = '';
                if (data.schedule?.length) {
                    data.schedule.forEach(s => {
                        const d = document.createElement('div'); d.className = 'route-card flex justify-between items-center text-[10px] text-slate-300';
                        d.innerHTML = `<span>${s.flight_number}</span> <span>${s.dep_icao}&raquo;${s.arr_icao}</span> <span class="bg-indigo-500/20 px-1 rounded text-indigo-300">${s.dep_time.substr(0,5)}</span>`;
                        schedList.appendChild(d);
                    });
                    const last = data.schedule[data.schedule.length - 1], [h, m] = last.arr_time.split(':').map(Number);
                    const t = (h * 60) + m + 45;
                    const suggest = `${String(Math.floor(t/60)%24).padStart(2, '0')}:${String(t%60).padStart(2, '0')}`;
                    const suggDiv = document.createElement('div'); suggDiv.className = 'suggest-card text-[10px] font-bold text-indigo-400';
                    suggDiv.innerHTML = `<i class="fas fa-magic mr-1"></i> Sugestão: Decolar às ${suggest} (Z)`;
                    suggDiv.onclick = () => { document.getElementById('dep_time').value = suggest; calcArrTime(); schedEl.classList.add('hidden'); };
                    schedList.appendChild(suggDiv);
                } else schedList.innerHTML = '<div class="text-[10px] text-slate-500 italic text-center py-2">Pronto para despacho.</div>';
            });
        }
    }

    function toggleMalha(e) {
        if (e.target.closest('#flightSearch')) return;
        
        isAnimating = true;
        const shelf = document.getElementById('malha-shelf');
        const icon = document.getElementById('malha-icon');
        const isMinimized = shelf.classList.toggle('minimized');
        icon.classList.toggle('fa-chevron-up', isMinimized);
        icon.classList.toggle('fa-chevron-down', !isMinimized);
        
        // Re-fit map bounds after animation completes
        setTimeout(() => {
            fitMapToRoutes();
            isAnimating = false;
        }, 500);
    }


    document.addEventListener('DOMContentLoaded', () => { 
        initMap(); 
        
        // Initial data sync
        setTimeout(() => {
            // Inject SimBrief coordinates into the lookup if they were just loaded
            <?php if (isset($sb_data)): ?>
                airportCoordinates['<?php echo $sb_dep; ?>'] = [<?php echo $sb_data['origin']['pos_lat']; ?>, <?php echo $sb_data['origin']['pos_long']; ?>];
                airportCoordinates['<?php echo $sb_arr; ?>'] = [<?php echo $sb_data['destination']['pos_lat']; ?>, <?php echo $sb_data['destination']['pos_long']; ?>];
            <?php endif; ?>
            
            checkAvailability();
            updateMapFilters();
            drawPlannedRoute(); // Draw initial or SimBrief route
        }, 1000); 

        if (document.getElementById('dep_time').value && document.getElementById('dur').value) {
            calcArrTime();
        }
        window.addEventListener('resize', fitMapToRoutes);
    });
</script>

<?php include '../includes/layout_footer.php'; ?>