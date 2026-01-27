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
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Imersivo - SkyCrew Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Outfit', sans-serif; margin: 0; padding: 0; overflow: hidden; background: #0f172a; }
        #routeMap { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }
        .immersive-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 10; display: flex; flex-direction: column; }
        .immersive-overlay * { pointer-events: auto; }
        .glass-panel { background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.8); }
        .sidebar-panel { width: 380px; height: calc(100vh - 40px); margin: 20px; border-radius: 24px; display: flex; flex-direction: column; overflow: hidden; }
        .top-bar { height: 60px; margin: 20px 20px 0 20px; border-radius: 16px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .bottom-shelf { position: absolute; bottom: 20px; left: 420px; right: 20px; height: 260px; border-radius: 24px; display: flex; flex-direction: column; }
        .leaflet-container { background: #0f172a !important; }
        .leaflet-vignette { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; pointer-events: none; box-shadow: inset 0 0 150px rgba(0,0,0,0.8); }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
        .form-input { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; border-radius: 12px; padding: 10px 14px; font-size: 0.875rem; width: 100%; transition: all 0.3s; }
        .form-input:focus { background: rgba(255,255,255,0.1); border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
        .btn-glow { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); box-shadow: 0 0 20px rgba(99, 102, 241, 0.4); border: none; color: white; font-weight: 700; border-radius: 12px; cursor: pointer; transition: all 0.3s; }
        .btn-glow:hover { transform: translateY(-2px); box-shadow: 0 0 30px rgba(99, 102, 241, 0.6); }
        .route-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; transition: all 0.3s; }
        .route-card:hover { background: rgba(255,255,255,0.07); border-color: rgba(255,255,255,0.2); }
        .nav-button { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #94a3b8; transition: all 0.3s; text-decoration: none; }
        .nav-button:hover, .nav-button.active { background: rgba(99, 102, 241, 0.2); color: #818cf8; }
        .suggest-card { background: rgba(99, 102, 241, 0.1); border: 1px dashed #6366f1; border-radius: 12px; padding: 10px; text-align: center; cursor: pointer; transition: all 0.2s; }
        .suggest-card:hover { background: rgba(99, 102, 241, 0.2); transform: scale(1.02); }
    </style>
</head>

<body>
    <div id="routeMap"></div>
    <div class="leaflet-vignette"></div>

    <div class="immersive-overlay">
        <div class="top-bar glass-panel">
            <div class="flex items-center gap-6">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-500 flex items-center justify-center text-white font-bold shadow-lg shadow-indigo-500/50">S</div>
                    <span class="font-bold text-lg text-white tracking-tight">SkyCrew <span class="text-indigo-400">OS</span></span>
                </div>
                <div class="h-6 w-px bg-white/10 mx-2"></div>
                <nav class="flex gap-2">
                    <a href="dashboard.php" class="nav-button" title="Painel"><i class="fas fa-chart-pie"></i></a>
                    <a href="flights.php" class="nav-button active" title="Voos"><i class="fas fa-route"></i></a>
                    <a href="fleet.php" class="nav-button" title="Frota"><i class="fas fa-plane"></i></a>
                    <a href="financials.php" class="nav-button" title="Financeiro"><i class="fas fa-wallet"></i></a>
                    <a href="settings.php" class="nav-button" title="Configurações"><i class="fas fa-cog"></i></a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <div id="status-clock" class="text-white font-mono text-sm tracking-widest bg-white/5 px-3 py-1 rounded-full border border-white/10 text-indigo-300">--:--:-- UTC</div>
                <div class="h-8 w-8 rounded-full border border-indigo-500/50 p-0.5">
                    <div class="w-full h-full rounded-full bg-slate-800 flex items-center justify-center text-slate-400 text-xs"><i class="fas fa-user-shield"></i></div>
                </div>
                <a href="../logout.php" class="text-slate-400 hover:text-white transition text-xs uppercase font-bold tracking-widest">Sair</a>
            </div>
        </div>

        <div class="flex flex-1 overflow-hidden">
            <div class="sidebar-panel glass-panel">
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
                                <input type="text" id="dep" name="dep_icao" class="form-input uppercase" placeholder="ICAO" maxlength="4" onkeyup="searchAirport(this)" required>
                                <div id="dep_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20"></div>
                            </div>
                            <div class="space-y-2 relative">
                                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Destino</label>
                                <input type="text" id="arr" name="arr_icao" class="form-input uppercase" placeholder="ICAO" maxlength="4" onkeyup="searchAirport(this)" required>
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

            <div class="flex-1 flex flex-col justify-end p-5 pr-5">
                <div class="mb-4">
                    <?php if ($success): ?><div class="glass-panel border-l-4 border-emerald-500 px-6 py-3 rounded-2xl text-emerald-400 font-bold mb-2 animate-pulse"><i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="glass-panel border-l-4 border-rose-500 px-6 py-3 rounded-2xl text-rose-400 font-bold mb-2"><i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?></div><?php endif; ?>
                </div>
                <div class="bottom-shelf glass-panel">
                    <div class="p-4 border-b border-white/10 flex justify-between items-center shrink-0">
                        <div class="flex items-center gap-4"><h3 class="text-white font-bold text-sm">Malha Operacional</h3><div class="bg-white/5 border border-white/10 rounded-full px-3 py-1 text-[10px] text-slate-400 font-bold"><?php echo count($flights); ?> ATIVAS</div></div>
                        <div class="relative w-64"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i><input type="text" id="flightSearch" placeholder="Busca rápida..." class="w-full bg-white/5 border border-white/10 rounded-full pl-9 py-1 text-xs text-white focus:outline-none focus:ring-1 focus:ring-indigo-500"></div>
                    </div>
                    <div class="flex-1 overflow-y-auto">
                        <table class="w-full text-left text-[11px] text-slate-300">
                            <thead class="bg-white/2 fixed-header text-[9px] uppercase tracking-widest font-bold text-slate-500">
                                <tr><th class="px-6 py-3">Número</th><th class="px-6 py-3 text-center">Trecho</th><th class="px-6 py-3">UTC Window</th><th class="px-6 py-3">Equipamento</th><th class="px-6 py-3">EET</th><th class="px-6 py-3 text-right pr-8">Ação</th></tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php foreach ($flights as $f): ?>
                                        <tr class="hover:bg-white/5 transition group">
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
        </div>
    </div>

    <script>
        let debounceTimer, map;
        const routeLayerGroup = L.layerGroup();

        function initMap() {
            map = L.map('routeMap', { zoomControl: false }).setView([-15.78, -47.92], 4);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; CARTO' }).addTo(map);
            routeLayerGroup.addTo(map);
            loadMapRoutes();
        }

        async function loadMapRoutes() {
            const r = await fetch('../api/get_route_map.php');
            const routes = await r.json();
            routeLayerGroup.clearLayers();
            const b = [];
            routes.forEach(x => {
                if (x.dep_lat && x.arr_lat) {
                    const p1 = [x.dep_lat, x.dep_lon], p2 = [x.arr_lat, x.arr_lon];
                    b.push(p1, p2);
                    L.circleMarker(p1, { radius: 2, color: '#818cf8' }).addTo(routeLayerGroup);
                    L.circleMarker(p2, { radius: 2, color: '#818cf8' }).addTo(routeLayerGroup);
                    L.polyline([p1, p2], { color: '#6366f1', weight: 1, opacity: 0.3, dashArray: '5,5' }).addTo(routeLayerGroup);
                }
            });
            if (b.length) map.fitBounds(b, { padding: [100, 100] });
        }

        function updateClock() { document.getElementById('status-clock').textContent = new Date().toISOString().split('T')[1].split('.')[0] + ' UTC'; }
        setInterval(updateClock, 1000);

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
                                d.onclick = () => { input.value = x.value; list.classList.add('hidden'); checkAvailability(); };
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

        document.addEventListener('DOMContentLoaded', () => { initMap(); updateClock(); });
    </script>
</body>
</html>