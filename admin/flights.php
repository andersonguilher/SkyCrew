<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');
$settings = getSystemSettings($pdo);

// Handle actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_flight'])) {
        $prefix = $settings['va_callsign'] ?: 'VA';
        $rawNum = preg_replace('/\D/', '', $_POST['flight_number']);
        $fnum = $prefix . $rawNum;
        $dep = strtoupper(trim($_POST['dep_icao']));
        $arr = strtoupper(trim($_POST['arr_icao']));
        $dtime = $_POST['dep_time'];
        $atime = $_POST['arr_time'];
        $aircraft_id = $_POST['aircraft_id'];
        $dur = $_POST['duration'];
        $route = $_POST['route'] ?? null;

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
                $stmt = $pdo->prepare("INSERT INTO flights_master (flight_number, aircraft_id, dep_icao, arr_icao, dep_time, arr_time, aircraft_type, duration_minutes, route) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fnum, $aircraft_id, $dep, $arr, $dtime, $atime, $ac, $dur, $route]);
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
    }
}

// Fetch Data
$flights = $pdo->query("
    SELECT fm.*, fl.registration 
    FROM flights_master fm 
    LEFT JOIN fleet fl ON fm.aircraft_id = fl.id 
    ORDER BY fm.flight_number
")->fetchAll();

$fleet = $pdo->query("
    SELECT f.*, 
    COALESCE((SELECT arr_icao FROM flights_master WHERE aircraft_id = f.id ORDER BY dep_time DESC LIMIT 1), f.current_icao) as last_location
    FROM fleet f 
    ORDER BY f.icao_code, f.registration
")->fetchAll();

$prefix = $settings['va_callsign'] ?: 'VA';
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        #routeMap { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }
        .leaflet-container { background: #0c0e17 !important; }
        .leaflet-vignette { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; pointer-events: none; box-shadow: inset 0 0 150px rgba(0,0,0,0.8); }
        .sidebar-panel { width: 380px; flex-shrink: 0; display: flex; flex-direction: column; overflow: hidden; position: relative; z-index: 10; }
        .bottom-shelf { height: 260px; flex-shrink: 0; display: flex; flex-direction: column; margin-top: auto; transition: height 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; z-index: 10; pointer-events: auto; }
        .bottom-shelf.minimized { height: 48px; }
        .shelf-toggle { cursor: pointer; padding: 4px 8px; border-radius: 6px; background: rgba(255,255,255,0.05); }
        .shelf-toggle:hover { background: rgba(255,255,255,0.1); }
        .glass-tooltip { background: rgba(15, 23, 42, 0.9) !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; color: white !important; font-weight: bold !important; font-family: "Outfit", sans-serif !important; border-radius: 6px !important; box-shadow: 0 4px 12px rgba(0,0,0,0.5) !important; z-index: 2000 !important; }
        .top-bar { position: relative; z-index: 100; }
        .leaflet-tooltip-top:before, .leaflet-tooltip-bottom:before, .leaflet-tooltip-left:before, .leaflet-tooltip-right:before { border: none !important; }
        .route-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; transition: all 0.3s; }
        .route-card:hover { background: rgba(255,255,255,0.07); border-color: rgba(255,255,255,0.2); }
        .suggest-card { background: rgba(99, 102, 241, 0.1); border: 1px dashed #6366f1; border-radius: 12px; padding: 10px; text-align: center; cursor: pointer; transition: all 0.2s; }
        .suggest-card:hover { background: rgba(99, 102, 241, 0.2); transform: scale(1.02); }
        
        @keyframes dash {
            to { stroke-dashoffset: -20; }
        }
        .route-line { transition: all 0.5s; stroke-dasharray: 10, 10; }
        .route-line-active { 
            stroke: #fbbf24 !important; 
            stroke-width: 3 !important; 
            stroke-opacity: 1 !important; 
            animation: dash 1s linear infinite; 
            stroke-dasharray: 10, 5;
            z-index: 1000 !important;
        }
        .airport-marker {
            background: #818cf8;
            border: 2px solid white;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 0 15px rgba(129, 140, 248, 0.7);
            transition: all 0.3s;
        }
        .airport-marker:hover {
            background: #fbbf24;
            transform: scale(1.5);
            z-index: 1000 !important;
            box-shadow: 0 0 20px #fbbf24;
        }
    </style>
';

$bgElement = '<div id="routeMap"></div><div class="leaflet-vignette"></div>';

include '../includes/layout_header.php';
?>

<div class="sidebar-panel glass-panel rounded-3xl z-10">
    <div class="p-6 border-b border-white/10 shrink-0">
        <h2 class="text-lg font-bold text-white flex items-center gap-2"><i class="fas fa-plus-circle text-indigo-400"></i> Despacho Operacional</h2>
        <p class="text-[10px] text-slate-400 uppercase tracking-widest mt-1">Planejamento de Voo</p>
    </div>

    <div class="flex-1 overflow-y-auto p-6 space-y-6">
        <form method="POST" id="dispatchForm" class="space-y-5">
            <div class="space-y-2">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Voo</label>
                <input type="text" name="flight_number" value="<?php echo $prefix . $nextNum; ?>" class="form-input font-bold" required>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2 relative">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Origem</label>
                    <input type="text" id="dep" name="dep_icao" class="form-input uppercase" placeholder="ICAO" maxlength="4" onkeyup="searchAirport(this)" onchange="updatePreview()" required>
                    <div id="dep_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20"></div>
                </div>
                <div class="space-y-2 relative">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Destino</label>
                    <input type="text" id="arr" name="arr_icao" class="form-input uppercase" placeholder="ICAO" maxlength="4" onkeyup="searchAirport(this)" onchange="updatePreview()" required>
                    <div id="arr_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20"></div>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between items-center"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Aeronave</label>
                <label class="text-[10px] text-slate-500 cursor-pointer hover:text-white transition"><input type="checkbox" id="show_all_ac" class="rounded bg-white/5 border-white/20 text-indigo-500" onchange="checkAvailability()"> Ver Todas</label></div>
                <select name="aircraft_id" id="ac" class="form-input" required onchange="checkAvailability(true)">
                    <option value="">Selecione...</option>
                    <?php foreach ($fleet as $f): ?>
                            <option value="<?php echo $f['id']; ?>" data-location="<?php echo $f['last_location']; ?>">
                                <?php echo $f['registration']; ?> (<?php echo $f['icao_code']; ?>)
                            </option>
                    <?php endforeach; ?>
                </select>
                <div id="ac_status" class="text-[10px] ml-1 hidden"></div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between items-center mr-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Plano de Rota</label>
                    <button type="button" onclick="fetchSimBrief()" class="text-[10px] font-bold text-indigo-400 hover:text-indigo-300 uppercase flex items-center gap-1 transition">
                        <i class="fas fa-cloud-download-alt"></i> SimBrief
                    </button>
                </div>
                <textarea id="route" name="route" class="form-input h-20 text-[11px] font-mono resize-none" placeholder="Gerado automaticamente ou manual..."></textarea>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Saída (Z)</label>
                <input type="time" id="dep_time" name="dep_time" class="form-input p-1" onchange="calcArrTime()" required></div>
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">EET</label>
                <input type="number" id="dur" name="duration" class="form-input p-1" onchange="calcArrTime()" required></div>
                <div class="space-y-2"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Chegada (Z)</label>
                <input type="time" id="arr_time" name="arr_time" class="form-input p-1 bg-white/10" readonly required></div>
            </div>
            <button type="submit" name="add_flight" class="btn-glow w-full py-4 mt-4 uppercase tracking-widest text-sm">Criar Voo</button>
        </form>
    </div>
    <div id="ac_schedule" class="p-6 bg-indigo-500/5 border-t border-white/10 hidden shrink-0 max-h-[250px] overflow-y-auto">
        <p class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest mb-3">Linha do Tempo (Hoje)</p>
        <div id="schedule_list" class="space-y-2"></div>
    </div>
</div>

<div class="flex-1 flex flex-col justify-end z-10 max-h-full overflow-hidden pointer-events-none">
    <div class="mb-4">
        <?php if ($success): ?><div class="glass-panel border-l-4 border-emerald-500 px-6 py-3 rounded-2xl text-emerald-400 font-bold mb-2 animate-pulse"><i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="glass-panel border-l-4 border-rose-500 px-6 py-3 rounded-2xl text-rose-400 font-bold mb-2"><i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?></div><?php endif; ?>
    </div>
    <div class="bottom-shelf glass-panel rounded-3xl overflow-hidden minimized">
        <div class="p-4 border-b border-white/10 flex justify-between items-center shrink-0">
            <div class="flex items-center gap-4">
                <div class="shelf-toggle text-slate-400 hover:text-white transition" onclick="toggleShelf()">
                    <i class="fas fa-chevron-up" id="shelf-icon"></i>
                </div>
                <h3 class="text-white font-bold text-sm">Malha Operacional</h3>
                <div class="bg-white/5 border border-white/10 rounded-full px-3 py-1 text-[10px] text-slate-400 font-bold"><?php echo count($flights); ?> ATIVAS</div>
            </div>
            <div class="relative w-64"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i><input type="text" id="flightSearch" placeholder="Busca rápida..." class="w-full bg-white/5 border border-white/10 rounded-full pl-9 py-1 text-xs text-white focus:outline-none focus:ring-1 focus:ring-indigo-500"></div>
        </div>
        <div class="flex-1 overflow-y-auto">
            <table class="w-full text-left text-[11px] text-slate-300">
                <thead class="bg-white/2 sticky top-0 z-20 text-[9px] uppercase tracking-widest font-bold text-slate-500 bg-[#0c0e17]">
                    <tr><th class="px-6 py-3">Número</th><th class="px-6 py-3 text-center">Trecho</th><th class="px-6 py-3">UTC Window</th><th class="px-6 py-3">Equipamento</th><th class="px-6 py-3">EET</th><th class="px-6 py-3 text-right pr-8">Ação</th></tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($flights as $f): ?>
                            <tr class="hover:bg-white/5 transition group cursor-pointer" 
                                onmouseover="highlightRoute('<?php echo $f['flight_number']; ?>')" 
                                onmouseout="resetRouteHighlight()">
                                <td class="px-6 py-3 font-bold text-indigo-400"><?php echo $f['flight_number']; ?></td>
                                <td class="px-6 py-3 text-center"><div class="flex items-center justify-center gap-2"><span><?php echo $f['dep_icao']; ?></span><i class="fas fa-arrow-right text-[10px] text-slate-600"></i><span><?php echo $f['arr_icao']; ?></span></div></td>
                                <td class="px-6 py-3 font-mono"><?php echo substr($f['dep_time'], 0, 5); ?> - <?php echo substr($f['arr_time'], 0, 5); ?></td>
                                <td class="px-6 py-3 flex flex-col"><span class="font-bold text-slate-200"><?php echo $f['registration'] ?: '--'; ?></span><span class="text-[9px] text-slate-500 uppercase"><?php echo $f['aircraft_type']; ?></span></td>
                                <td class="px-6 py-3"><?php echo intval($f['duration_minutes'] / 60) . 'h ' . ($f['duration_minutes'] % 60) . 'm'; ?></td>
                                <td class="px-6 py-3 text-right pr-8"><form method="POST" onsubmit="return confirm('Excluir?');"><input type="hidden" name="delete_id" value="<?php echo $f['id']; ?>"><button type="submit" class="text-slate-600 hover:text-rose-500 transition-colors opacity-0 group-hover:opacity-100"><i class="fas fa-trash-alt"></i></button></form></td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    let debounceTimer, map, mapBounds;
    const routeLayerGroup = L.layerGroup();
    const activeRouteLayer = L.layerGroup();
    const aircraftLayerGroup = L.layerGroup();
    const previewLayerGroup = L.layerGroup();
    const flightPolylines = {};

    function initMap() {
        map = L.map('routeMap', { 
            zoomControl: false,
            preferCanvas: true
        }).setView([-15.78, -47.92], 4);
        
        // Custom panes for layering
        map.createPane('bgRoutes');
        map.createPane('activeRoutes');
        map.createPane('markers');
        map.getPane('activeRoutes').style.zIndex = 600;
        map.getPane('markers').style.zIndex = 650;
        
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; CARTO' }).addTo(map);
        routeLayerGroup.addTo(map);
        activeRouteLayer.addTo(map);
        aircraftLayerGroup.addTo(map);
        previewLayerGroup.addTo(map);
        loadMapRoutes();
    }

    async function loadMapRoutes() {
        const r = await fetch('../api/get_route_map.php');
        const data = await r.json();
        if (!data.routes) return;
        
        routeLayerGroup.clearLayers();
        aircraftLayerGroup.clearLayers();
        activeRouteLayer.clearLayers();
        Object.keys(flightPolylines).forEach(k => delete flightPolylines[k]);
        
        const b = [];
        const airports = {};

        // 1. Draw Routes (Canvas for Performance)
        data.routes.forEach(x => {
            if (x.dep_lat && x.arr_lat) {
                const p1 = [x.dep_lat, x.dep_lon], p2 = [x.arr_lat, x.arr_lon];
                b.push(p1, p2);
                airports[x.dep_icao] = p1;
                airports[x.arr_icao] = p2;

                const line = L.polyline([p1, p2], { 
                    color: '#6366f1', 
                    weight: 1, 
                    opacity: 0.1, 
                    pane: 'bgRoutes',
                    interactive: true
                }).addTo(routeLayerGroup);
                
                line.bindTooltip(`${x.flight_number}: ${x.dep_icao} ➜ ${x.arr_icao}`, { sticky: true, className: 'glass-tooltip' });
                line.on('click', (e) => { L.DomEvent.stopPropagation(e); highlightRoute(x.flight_number); });
                
                flightPolylines[x.flight_number] = { coords: [p1, p2], dep: x.dep_icao, arr: x.arr_icao };
            }
        });

        // 2. Draw Aircraft (Fleet Positions)
        if (data.fleet) {
            data.fleet.forEach(ac => {
                if (ac.lat && ac.lon) {
                    const planeIcon = L.divIcon({
                        className: 'aircraft-marker-container',
                        html: `<div class="bg-indigo-500 w-6 h-6 rounded-full border-2 border-white flex items-center justify-center text-[10px] text-white shadow-lg"><i class="fas fa-plane"></i></div>`,
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    });
                    L.marker([ac.lat, ac.lon], { icon: planeIcon, pane: 'markers' })
                        .addTo(aircraftLayerGroup)
                        .bindTooltip(`<b>${ac.registration}</b> (${ac.icao_code})<br>Em: ${ac.current_icao}`, { className: 'glass-tooltip' });
                }
            });
        }

        // 3. Draw Airport Markers
        Object.entries(airports).forEach(([icao, pos]) => {
            const marker = L.marker(pos, {
                icon: L.divIcon({
                    className: 'airport-marker',
                    iconSize: [10, 10],
                    iconAnchor: [5, 5]
                }),
                pane: 'markers',
                interactive: true
            }).addTo(routeLayerGroup);

            marker.bindTooltip(icao, { direction: 'top', className: 'glass-tooltip' });
            marker.on('click', (e) => {
                L.DomEvent.stopPropagation(e);
                highlightAirportRoutes(icao);
            });
        });

        if (b.length) {
            mapBounds = L.latLngBounds(b);
            fitMapToRoutes();
        }
    }

    function fitMapToRoutes() {
        if (!map || !mapBounds) return;
        const isMinimized = document.querySelector('.bottom-shelf').classList.contains('minimized');
        const padding = {
            top: 100,
            bottom: isMinimized ? 80 : 300,
            left: 420,
            right: 50
        };
        map.fitBounds(mapBounds, { 
            paddingTopLeft: [padding.left, padding.top],
            paddingBottomRight: [padding.right, padding.bottom],
            animate: true 
        });
    }

    function highlightAirportRoutes(icao) {
        activeRouteLayer.clearLayers();
        const activeCoords = [];
        
        Object.values(flightPolylines).forEach(item => {
            if (item.dep === icao || item.arr === icao) {
                L.polyline(item.coords, {
                    color: '#fbbf24',
                    weight: 3,
                    opacity: 1,
                    className: 'route-line-active',
                    pane: 'activeRoutes'
                }).addTo(activeRouteLayer);
                activeCoords.push(item.coords[0], item.coords[1]);
            }
        });
        
        if (activeCoords.length > 0) {
            map.fitBounds(L.latLngBounds(activeCoords), { padding: [150, 150], animate: true });
        }
    }

    function toggleShelf() {
        const shelf = document.querySelector('.bottom-shelf');
        const icon = document.getElementById('shelf-icon');
        shelf.classList.toggle('minimized');
        icon.classList.toggle('fa-chevron-up');
        icon.classList.toggle('fa-chevron-down');
        
        setTimeout(() => {
            map.invalidateSize();
            fitMapToRoutes();
        }, 450);
    }

    function highlightRoute(fnum) {
        activeRouteLayer.clearLayers();
        const item = flightPolylines[fnum];
        if (item && item.coords) {
            L.polyline(item.coords, {
                color: '#fbbf24',
                weight: 4,
                opacity: 1,
                className: 'route-line-active',
                pane: 'activeRoutes'
            }).addTo(activeRouteLayer);
            map.fitBounds(item.coords, { padding: [150, 150], animate: true });
        }
    }

    function resetRouteHighlight() {
        activeRouteLayer.clearLayers();
    }

    async function updatePreview() {
        const dep = document.getElementById('dep').value.toUpperCase();
        const arr = document.getElementById('arr').value.toUpperCase();
        if (dep.length === 4 && arr.length === 4) {
            const r = await fetch(`../api/calculate_route.php?dep=${dep}&arr=${arr}`);
            const d = await r.json();
            previewLayerGroup.clearLayers();
            if (d.dep_lat && d.arr_lat) {
                const p1 = [d.dep_lat, d.dep_lon], p2 = [d.arr_lat, d.arr_lon];
                L.circleMarker(p1, { radius: 5, color: '#fbbf24', fillOpacity: 0.8 }).addTo(previewLayerGroup);
                L.circleMarker(p2, { radius: 5, color: '#fbbf24', fillOpacity: 0.8 }).addTo(previewLayerGroup);
                L.polyline([p1, p2], { 
                    color: '#fbbf24', 
                    weight: 3, 
                    opacity: 1, 
                    className: 'route-line-active' 
                }).addTo(previewLayerGroup);
                map.fitBounds([p1, p2], { padding: [100, 100] });
                
                // Also update duration if empty
                const durInput = document.getElementById('dur');
                if(!durInput.value) {
                    durInput.value = d.duration;
                    calcArrTime();
                }
            }
        }
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
                            d.onclick = () => { input.value = x.value; list.classList.add('hidden'); checkAvailability(); updatePreview(); };
                            list.appendChild(d);
                        });
                    } else list.classList.add('hidden');
                });
        }, 300);
    }

    function fetchSimBrief() {
        const dep = document.getElementById('dep').value.toUpperCase(), arr = document.getElementById('arr').value.toUpperCase(), acId = document.getElementById('ac').value;
        if (!dep || !arr || !acId) return alert('DADOS INCOMPLETOS');
        const acIcao = document.querySelector(`#ac option[value="${acId}"]`).text.match(/\((.*?)\)/)[1];
        const btn = document.querySelector('button[onclick="fetchSimBrief()"]');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true;
        fetch(`../api/sb_fetch.php?dep=${dep}&arr=${arr}&ac=${acIcao}&ac_id=${acId}`)
            .then(r => r.json())
            .then(d => {
                if (d.duration_minutes) document.getElementById('dur').value = d.duration_minutes;
                if (d.route) document.getElementById('route').value = d.route;
                calcArrTime();
            }).finally(() => { btn.innerHTML = '<i class="fas fa-cloud-download-alt"></i> SimBrief'; btn.disabled = false; });
    }

    function calcArrTime() {
        const dep = document.getElementById('dep_time').value, dur = parseInt(document.getElementById('dur').value) || 0;
        if (dep) {
            const [h, m] = dep.split(':').map(Number);
            const t = (h * 60) + m + dur;
            document.getElementById('arr_time').value = `${String(Math.floor(t / 60) % 24).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
        }
    }

    function checkAvailability(isAcChange = false) {
        const acEl = document.getElementById('ac'), acId = acEl.value, depInput = document.getElementById('dep'), dep = depInput.value.toUpperCase();
        const showAll = document.getElementById('show_all_ac').checked, statusEl = document.getElementById('ac_status'), schedEl = document.getElementById('ac_schedule'), schedList = document.getElementById('schedule_list');
        
        if (isAcChange && acId) {
            const loc = acEl.options[acEl.selectedIndex].getAttribute('data-location');
            if (loc) depInput.value = loc;
        }

        acEl.querySelectorAll('option').forEach(o => { if (o.value) o.style.display = (showAll || !dep || o.getAttribute('data-location') === dep) ? 'block' : 'none'; });

        if (acId) {
            fetch(`../api/get_ac_status.php?id=${acId}`).then(r => r.json()).then(data => {
                statusEl.classList.remove('hidden'); statusEl.innerHTML = `<span class="text-indigo-400 font-bold uppercase tracking-tighter">Posição: ${data.current_location}</span>`;
                schedEl.classList.remove('hidden'); schedList.innerHTML = '';
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
                    suggDiv.onclick = () => { document.getElementById('dep_time').value = suggest; calcArrTime(); };
                    schedList.appendChild(suggDiv);
                } else schedList.innerHTML = '<div class="text-[10px] text-slate-500 italic text-center py-2">Pronto para despacho.</div>';
            });
        }
    }

    document.getElementById('flightSearch').addEventListener('keyup', function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll('tbody tr').forEach(tr => tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none');
    });

    document.addEventListener('DOMContentLoaded', () => { initMap(); });
</script>

<?php include '../includes/layout_footer.php'; ?>