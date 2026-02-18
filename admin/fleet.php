<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$success = '';
$error = '';

$settings = getSystemSettings($pdo);

// Fetch available aircraft models for dropdown
$models = $pdo->query("SELECT icao, model_name, cruise_speed FROM aircraft_models ORDER BY model_name")->fetchAll();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_aircraft'])) {
        $icao = strtoupper(trim($_POST['icao_code']));
        $registration = trim($_POST['registration']);
        $current_icao = strtoupper(trim($_POST['current_icao']));

        // Fetch model data for fullname and cruise_speed
        $stmt = $pdo->prepare("SELECT model_name, cruise_speed FROM aircraft_models WHERE icao = ?");
        $stmt->execute([$icao]);
        $modelData = $stmt->fetch();
        $name = $modelData ? $modelData['model_name'] : $icao;
        $speed = $modelData ? $modelData['cruise_speed'] : 450;

        try {
            $stmt = $pdo->prepare("INSERT INTO fleet (icao_code, registration, fullname, cruise_speed, current_icao) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$icao, $registration, $name, $speed, $current_icao]);
            $newFleetId = $pdo->lastInsertId();

            // Initialize maintenance component tracking for this aircraft
            $stmtInit = $pdo->prepare("
                INSERT IGNORE INTO fleet_component_hours (fleet_id, maintenance_component_id, hours_since_maintenance)
                SELECT ?, am.id, 0
                FROM aircraft_maintenance am WHERE am.model_icao = ?
            ");
            $stmtInit->execute([$newFleetId, $icao]);

            $success = "Aeronave $registration ($icao) adicionada em $current_icao.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_id'])) {
        try {
            // Check if aircraft is assigned to routes
            $delId = $_POST['delete_id'];
            $routeCheck = $pdo->prepare("SELECT COUNT(*) FROM flights_master WHERE aircraft_id = ?");
            $routeCheck->execute([$delId]);
            if ($routeCheck->fetchColumn() > 0) {
                $error = "Não é possível remover: esta aeronave está atribuída a rotas ativas. Exclua os voos primeiro.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM fleet WHERE id = ?");
                $stmt->execute([$delId]);
                $success = "Aeronave removida.";
            }
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    }
}

// Fetch Fleet with route info and maintenance status
$fleet = $pdo->query("
    SELECT f.*, 
           (SELECT COUNT(*) FROM flights_master fm WHERE fm.aircraft_id = f.id) as route_count,
           (SELECT GROUP_CONCAT(DISTINCT CONCAT(fm.dep_icao, '→', fm.arr_icao) ORDER BY fm.flight_number SEPARATOR ', ') 
            FROM flights_master fm WHERE fm.aircraft_id = f.id) as routes,
           (SELECT COUNT(*) FROM fleet_maintenance_log fml WHERE fml.fleet_id = f.id) as total_maintenances
    FROM fleet f 
    ORDER BY f.icao_code, f.registration
")->fetchAll();

// Fetch maintenance component status for each aircraft (nearest to due)
$fleetMaintStatus = [];
foreach ($fleet as &$ac) {
    $stmtComp = $pdo->prepare("
        SELECT fch.hours_since_maintenance, am.component_name, am.interval_fh, am.cost_preventive,
               (fch.hours_since_maintenance / am.interval_fh * 100) as pct_used
        FROM fleet_component_hours fch
        JOIN aircraft_maintenance am ON fch.maintenance_component_id = am.id
        WHERE fch.fleet_id = ?
        ORDER BY pct_used DESC
    ");
    $stmtComp->execute([$ac['id']]);
    $components = $stmtComp->fetchAll();
    $ac['components'] = $components;
    $ac['nearest_pct'] = !empty($components) ? (float)$components[0]['pct_used'] : 0;
    $ac['nearest_comp'] = !empty($components) ? $components[0]['component_name'] : '-';
    $ac['nearest_remaining'] = !empty($components) ? ($components[0]['interval_fh'] - $components[0]['hours_since_maintenance']) : 0;
}
unset($ac);

$pageTitle = "Gerenciar Frota - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="sidebar-narrow flex flex-col gap-6">
    <div class="glass-panel p-6 rounded-3xl shrink-0">
        <h2 class="section-title"><i class="fas fa-plane-arrival text-indigo-400"></i> Nova Aeronave</h2>
        <form method="POST" id="fleetForm" class="space-y-4">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Matrícula</label>
                <div class="flex gap-2">
                    <input type="text" name="registration" id="add_reg" class="form-input font-bold text-indigo-400" placeholder="PR-..." readonly required>
                    <button type="button" onclick="refreshReg()" class="bg-white/5 border border-white/10 px-3 rounded-xl hover:bg-white/10 transition text-slate-400">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Modelo da Aeronave</label>
                <select name="icao_code" id="add_icao" class="form-input" required onchange="onModelSelect(this)">
                    <option value="">Selecione o modelo...</option>
                    <?php foreach ($models as $m): ?>
                        <option value="<?php echo $m['icao']; ?>" data-name="<?php echo htmlspecialchars($m['model_name']); ?>" data-speed="<?php echo $m['cruise_speed']; ?>">
                            <?php echo $m['icao']; ?> — <?php echo htmlspecialchars($m['model_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($models)): ?>
                    <p class="text-[9px] text-rose-400 ml-1">
                        <i class="fas fa-exclamation-circle mr-1"></i> Cadastre modelos em <a href="aircraft_models.php" class="text-indigo-400 hover:text-indigo-300 underline">Modelos</a>.
                    </p>
                <?php endif; ?>
            </div>
            <div class="space-y-1 relative">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Localização (ICAO)</label>
                <input type="text" name="current_icao" id="current_icao" class="form-input uppercase" placeholder="SBGR" maxlength="4" onkeyup="searchAirport(this)" autocomplete="off" required>
                <div id="current_icao_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20 shadow-2xl bg-[#1e293b]"></div>
                <div id="icao_hint" class="text-[9px] text-slate-500 ml-1 mt-1 hidden">
                    <i class="fas fa-info-circle mr-1"></i> <span id="icao_hint_text"></span>
                </div>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Detalhes</label>
                <div id="model_info" class="bg-white/5 border border-white/10 rounded-xl p-3 text-[11px] text-slate-500 italic">
                    Selecione um modelo acima
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" name="add_aircraft" id="btnAdd" class="w-full py-4 btn-glow uppercase tracking-widest text-xs">
                    Adicionar à Frota
                </button>
            </div>
        </form>
    </div>

    <?php if ($success): ?>
        <div class="glass-panel border-l-4 border-emerald-500 px-6 py-4 rounded-2xl text-emerald-400 text-sm font-bold animate-pulse">
            <i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="glass-panel border-l-4 border-rose-500 px-6 py-4 rounded-2xl text-rose-400 text-sm font-bold">
            <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>
</div>

<div class="scrollable-panel glass-panel rounded-3xl overflow-hidden flex flex-col">
    <div class="p-6 border-b border-white/10 flex justify-between items-center bg-white/5">
        <h2 class="section-title mb-0"><i class="fas fa-plane text-indigo-400"></i> Frota Ativa</h2>
        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-white/5 px-3 py-1 rounded-full">
            <?php echo count($fleet); ?> Aeronaves
        </div>
    </div>
    <div class="flex-1 overflow-y-auto">
        <table class="w-full text-left text-[12px]">
            <thead class="bg-white/5 sticky top-0 z-10">
                <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                    <th class="px-4 py-4">Matrícula</th>
                    <th class="px-4 py-4">Tipo</th>
                    <th class="px-4 py-4">Base</th>
                    <th class="px-4 py-4">Horas Voadas</th>
                    <th class="px-4 py-4">Manutenção</th>
                    <th class="px-4 py-4">Rotas</th>
                    <th class="px-4 py-4 text-right pr-6">Ação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($fleet as $ac): 
                    $pct = min(100, $ac['nearest_pct']);
                    if ($pct >= 90) { $barColor = 'bg-rose-500'; $textColor = 'text-rose-400'; $statusIcon = 'fa-exclamation-triangle'; }
                    elseif ($pct >= 70) { $barColor = 'bg-amber-500'; $textColor = 'text-amber-400'; $statusIcon = 'fa-clock'; }
                    else { $barColor = 'bg-emerald-500'; $textColor = 'text-emerald-400'; $statusIcon = 'fa-check-circle'; }
                ?>
                    <tr class="hover:bg-white/5 transition group">
                        <td class="px-4 py-3">
                            <div class="font-mono font-bold text-indigo-400"><?php echo $ac['registration']; ?></div>
                            <div class="text-[9px] text-slate-500"><?php echo $ac['fullname']; ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="bg-white/5 border border-white/10 px-2 py-0.5 rounded text-white font-bold text-[11px]">
                                <?php echo $ac['icao_code']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded text-emerald-400 font-bold text-[11px]">
                                <?php echo $ac['current_icao'] ?: '---'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-white font-bold text-[13px]"><?php echo number_format($ac['total_flight_hours'], 1); ?>h</div>
                            <?php if ($ac['total_maintenances'] > 0): ?>
                                <div class="text-[9px] text-slate-500">
                                    <i class="fas fa-wrench mr-1"></i><?php echo $ac['total_maintenances']; ?> manutenções
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <button type="button" onclick="showMaintDetail(<?php echo $ac['id']; ?>)" class="w-full text-left hover:opacity-80 transition" title="Clique para ver detalhes">
                                <div class="flex items-center gap-2 mb-1">
                                    <i class="fas <?php echo $statusIcon; ?> <?php echo $textColor; ?> text-[10px]"></i>
                                    <span class="text-[10px] <?php echo $textColor; ?> font-bold">
                                        <?php echo $ac['nearest_comp']; ?>
                                    </span>
                                </div>
                                <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
                                    <div class="<?php echo $barColor; ?> h-full rounded-full transition-all" style="width: <?php echo round($pct); ?>%"></div>
                                </div>
                                <div class="flex justify-between mt-0.5">
                                    <span class="text-[8px] text-slate-500"><?php echo round($pct); ?>%</span>
                                    <span class="text-[8px] text-slate-500"><?php echo number_format($ac['nearest_remaining'], 0); ?>h restantes</span>
                                </div>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($ac['route_count'] > 0): ?>
                                <div class="text-[10px] text-yellow-400 font-mono leading-relaxed max-w-[120px] truncate" title="<?php echo htmlspecialchars($ac['routes']); ?>">
                                    <span class="bg-yellow-500/10 border border-yellow-500/20 px-1.5 py-0.5 rounded mr-1"><?php echo $ac['route_count']; ?></span>
                                    <?php echo htmlspecialchars($ac['routes']); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-[10px] text-slate-600 italic">Sem rotas</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right pr-6">
                            <form method="POST" onsubmit="return confirm('<?php echo $ac['route_count'] > 0 ? 'Esta aeronave tem rotas ativas. Exclua os voos primeiro.' : 'Excluir esta aeronave?'; ?>');"> 
                                <input type="hidden" name="delete_id" value="<?php echo $ac['id']; ?>">
                                <button type="submit" class="text-slate-600 hover:text-rose-500 transition opacity-0 group-hover:opacity-100 <?php echo $ac['route_count'] > 0 ? 'cursor-not-allowed' : ''; ?>">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($fleet)): ?>
                    <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500 italic">Nenhuma aeronave cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    let debounceTimer;
    const registrationPrefixes = "<?php echo $settings['fleet_registration_prefixes'] ?? 'PR,PT,PS,PP'; ?>".split(',');
    const existingRegistrations = new Set(<?php echo json_encode(array_column($fleet, 'registration')); ?>);

    function generateJSMatricula() {
        const prefixes = registrationPrefixes.map(p => p.trim());
        const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let reg;
        do {
            const prefix = prefixes[Math.floor(Math.random() * prefixes.length)] || 'PR';
            let suffix = '';
            for (let i = 0; i < 3; i++) suffix += letters[Math.floor(Math.random() * 26)];
            reg = prefix + "-" + suffix;
        } while (existingRegistrations.has(reg));
        return reg;
    }

    function refreshReg() { document.getElementById('add_reg').value = generateJSMatricula(); }

    // Auto-generate on load
    refreshReg();

    async function onModelSelect(sel) {
        const opt = sel.options[sel.selectedIndex];
        const info = document.getElementById('model_info');
        const icaoInput = document.getElementById('current_icao');
        const hint = document.getElementById('icao_hint');
        const hintText = document.getElementById('icao_hint_text');
        
        if (opt.value) {
            const name = opt.getAttribute('data-name');
            const speed = opt.getAttribute('data-speed');
            info.innerHTML = `
                <div class="flex items-center gap-3 text-white not-italic">
                    <span class="bg-indigo-500/20 border border-indigo-500/30 px-2 py-0.5 rounded font-bold text-indigo-400">${opt.value}</span>
                    <span>${name}</span>
                    <span class="text-slate-500">·</span>
                    <span class="text-slate-400">${speed} kt</span>
                </div>
            `;
            
            // Fetch available locations for this model
            try {
                const res = await fetch(`../api/fleet_availability.php?action=locations_for&model=${opt.value}`);
                const data = await res.json();
                if (data.any_location) {
                    hint.classList.remove('hidden');
                    hintText.textContent = 'Modelo sem rotas — pode ser posicionada em qualquer ICAO';
                    hintText.className = 'text-emerald-400';
                } else if (data.locations.length > 0) {
                    hint.classList.remove('hidden');
                    hintText.innerHTML = 'Locais operacionais: <strong class="text-indigo-400">' + data.locations.join(', ') + '</strong>';
                } else {
                    hint.classList.add('hidden');
                }
            } catch(e) { hint.classList.add('hidden'); }
        } else {
            info.innerHTML = '<span class="italic text-slate-500">Selecione um modelo acima</span>';
            hint.classList.add('hidden');
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
                            d.className = 'px-4 py-2 hover:bg-white/10 cursor-pointer text-[11px] text-slate-300 border-b border-white/5 last:border-0 transition';
                            d.textContent = x.label;
                            d.onclick = () => { input.value = x.value; list.classList.add('hidden'); onIcaoSelected(x.value); };
                            list.appendChild(d);
                        });
                    } else list.classList.add('hidden');
                });
        }, 300);
    }

    async function onIcaoSelected(icao) {
        // Filter model dropdown based on ICAO
        const modelSel = document.getElementById('add_icao');
        try {
            const res = await fetch(`../api/fleet_availability.php?action=models_at&icao=${icao}`);
            const data = await res.json();
            const availableModels = data.models.map(m => m.icao);
            
            // Highlight/filter available options
            Array.from(modelSel.options).forEach(opt => {
                if (opt.value === '') return; // skip placeholder
                if (availableModels.includes(opt.value)) {
                    opt.disabled = false;
                    opt.style.opacity = '1';
                } else {
                    opt.disabled = true;
                    opt.style.opacity = '0.3';
                }
            });
        } catch(e) {
            // On error, enable all options
            Array.from(modelSel.options).forEach(opt => { opt.disabled = false; opt.style.opacity = '1'; });
        }
    }

    // Close dropdown lists when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.relative')) {
            document.querySelectorAll('[id$="_list"]').forEach(l => l.classList.add('hidden'));
        }
    });

    // Maintenance Detail Modal
    async function showMaintDetail(fleetId) {
        const modal = document.getElementById('maintModal');
        const body = document.getElementById('maintModalBody');
        modal.classList.remove('hidden');
        body.innerHTML = '<div class="text-center py-12 text-slate-500"><i class="fas fa-spinner fa-spin text-2xl mb-3"></i><p class="text-xs">Carregando...</p></div>';

        try {
            const res = await fetch(`../api/fleet_maintenance.php?fleet_id=${fleetId}&action=status`);
            const data = await res.json();
            if (data.error) { body.innerHTML = `<div class="text-rose-400 p-6">${data.error}</div>`; return; }

            const ac = data.aircraft;
            const curr = '<?php echo $settings['currency_symbol'] ?? 'R$'; ?>';
            
            let html = `
                <div class="p-6 border-b border-white/10 bg-white/5">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-bold text-white">
                                <span class="text-indigo-400 font-mono">${ac.registration}</span>
                                <span class="text-slate-400 text-sm ml-2">${ac.model_name}</span>
                            </h3>
                            <p class="text-[11px] text-slate-500 mt-1">
                                <i class="fas fa-clock mr-1"></i>${ac.total_flight_hours.toFixed(1)}h total voadas ·
                                <i class="fas fa-map-marker-alt ml-2 mr-1"></i>${ac.current_icao} ·
                                <i class="fas fa-money-bill ml-2 mr-1"></i>${curr} ${Number(data.total_maintenance_cost).toLocaleString('pt-BR', {minimumFractionDigits: 2})} gasto em manutenção
                            </p>
                        </div>
                        <button onclick="document.getElementById('maintModal').classList.add('hidden')" class="text-slate-400 hover:text-white text-xl transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto">
                    <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                        <i class="fas fa-cogs mr-1"></i> Componentes (${data.components.length})
                    </h4>
            `;

            data.components.forEach(c => {
                const pct = Math.min(100, parseFloat(c.pct_used));
                let barClass, textClass;
                if (pct >= 90) { barClass = 'bg-rose-500'; textClass = 'text-rose-400'; }
                else if (pct >= 70) { barClass = 'bg-amber-500'; textClass = 'text-amber-400'; }
                else { barClass = 'bg-emerald-500'; textClass = 'text-emerald-400'; }

                const remaining = parseFloat(c.hours_remaining);
                const lastMaint = c.last_maintenance_at ? new Date(c.last_maintenance_at).toLocaleDateString('pt-BR') : 'Nunca';

                html += `
                    <div class="bg-white/5 border border-white/10 rounded-xl p-4">
                        <div class="flex justify-between items-start mb-2">
                            <div>
                                <span class="font-bold text-white text-[12px]">${c.component_name}</span>
                                <span class="text-[9px] text-slate-500 ml-2">Intervalo: ${Number(c.interval_fh).toLocaleString()} FH</span>
                            </div>
                            <div class="text-right">
                                <span class="${textClass} font-bold text-[12px]">${pct.toFixed(0)}%</span>
                            </div>
                        </div>
                        <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden mb-2">
                            <div class="${barClass} h-full rounded-full transition-all" style="width: ${pct.toFixed(0)}%"></div>
                        </div>
                        <div class="flex justify-between text-[9px] text-slate-500">
                            <span><i class="fas fa-tachometer-alt mr-1"></i>${Number(c.hours_since_maintenance).toFixed(1)}h / ${Number(c.interval_fh).toLocaleString()}h</span>
                            <span><i class="fas fa-hourglass-half mr-1"></i>${remaining.toFixed(0)}h restantes</span>
                            <span><i class="fas fa-wrench mr-1"></i>Última: ${lastMaint}</span>
                            <span><i class="fas fa-dollar-sign mr-1"></i>${curr} ${Number(c.cost_preventive).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span>
                        </div>
                    </div>
                `;
            });

            // Maintenance Log 
            if (data.maintenance_log.length > 0) {
                html += `
                    <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-6">
                        <i class="fas fa-history mr-1"></i> Histórico de Manutenções
                    </h4>
                    <div class="space-y-2">
                `;
                data.maintenance_log.forEach(log => {
                    html += `
                        <div class="flex items-center gap-3 bg-white/5 rounded-lg px-4 py-2 text-[11px]">
                            <i class="fas fa-wrench text-amber-400 text-[10px]"></i>
                            <span class="text-white font-bold flex-1">${log.component_name}</span>
                            <span class="text-slate-400">${log.hours_at_maintenance}h</span>
                            <span class="text-emerald-400 font-mono">${curr} ${Number(log.cost).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span>
                            <span class="text-slate-500 text-[9px]">${log.performed_at_fmt}</span>
                        </div>
                    `;
                });
                html += '</div>';
            } else {
                html += '<p class="text-slate-500 text-xs italic py-4"><i class="fas fa-info-circle mr-1"></i>Nenhuma manutenção realizada ainda.</p>';
            }

            html += '</div>';
            body.innerHTML = html;
        } catch(e) {
            body.innerHTML = `<div class="text-rose-400 p-6">Erro ao carregar dados: ${e.message}</div>`;
        }
    }
</script>

<!-- Maintenance Detail Modal -->
<div id="maintModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="glass-panel rounded-3xl w-full max-w-3xl mx-4 overflow-hidden shadow-2xl border border-white/10" onclick="event.stopPropagation()">
        <div id="maintModalBody"></div>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>