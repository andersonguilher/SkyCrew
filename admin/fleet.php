<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$success = '';
$error = '';

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
            $success = "Aeronave $registration ($icao) adicionada com sucesso em $current_icao.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM fleet WHERE id = ?");
            $stmt->execute([$_POST['delete_id']]);
            $success = "Aeronave removida.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    }
}

// Fetch Fleet
$fleet = $pdo->query("SELECT * FROM fleet ORDER BY icao_code")->fetchAll();
$settings = getSystemSettings($pdo);

$pageTitle = "Gerenciar Frota - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="sidebar-narrow flex flex-col gap-6">
    <div class="glass-panel p-6 rounded-3xl shrink-0">
        <h2 class="section-title"><i class="fas fa-plane-arrival text-indigo-400"></i> Nova Aeronave</h2>
        <form method="POST" class="space-y-4">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Matrícula</label>
                <div class="flex gap-2">
                    <input type="text" name="registration" id="add_reg" class="form-input font-bold text-indigo-400" placeholder="PR-..." readonly required>
                    <button type="button" onclick="refreshReg()" class="bg-white/5 border border-white/10 px-3 rounded-xl hover:bg-white/10 transition text-slate-400">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div class="space-y-1 relative">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Localização (ICAO)</label>
                <input type="text" name="current_icao" id="current_icao" class="form-input uppercase" placeholder="SBGR" maxlength="4" onkeyup="searchAirport(this)" autocomplete="off" required>
                <div id="current_icao_list" class="absolute left-0 right-0 top-full mt-1 glass-panel rounded-xl overflow-hidden z-50 hidden border border-white/20 shadow-2xl bg-[#1e293b]"></div>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Modelo da Aeronave</label>
                <select name="icao_code" id="add_icao" class="form-input" required onchange="onModelSelect(this)">
                    <option value="">Selecione o modelo...</option>
                    <?php foreach ($models as $m): ?>
                        <option value="<?php echo $m['icao']; ?>" data-name="<?php echo htmlspecialchars($m['model_name']); ?>" data-speed="<?php echo $m['cruise_speed']; ?>">
                            <?php echo $m['icao']; ?> — <?php echo htmlspecialchars($m['model_name']); ?> (<?php echo $m['cruise_speed']; ?> kt)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($models)): ?>
                    <p class="text-[9px] text-rose-400 ml-1">
                        <i class="fas fa-exclamation-circle mr-1"></i> Cadastre modelos em <a href="aircraft_models.php" class="text-indigo-400 hover:text-indigo-300 underline">Modelos</a> antes de adicionar aeronaves.
                    </p>
                <?php endif; ?>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Detalhes do Modelo</label>
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
                    <th class="px-8 py-4">Matrícula</th>
                    <th class="px-8 py-4">Tipo</th>
                    <th class="px-8 py-4">Localização</th>
                    <th class="px-8 py-4">Aeronave</th>
                    <th class="px-8 py-4">Velocidade</th>
                    <th class="px-8 py-4 text-right pr-12">Ação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($fleet as $ac): ?>
                    <tr class="hover:bg-white/5 transition group">
                        <td class="px-8 py-4 font-mono font-bold text-indigo-400"><?php echo $ac['registration']; ?></td>
                        <td class="px-8 py-4">
                            <span class="bg-white/5 border border-white/10 px-2 py-1 rounded text-white font-bold">
                                <?php echo $ac['icao_code']; ?>
                            </span>
                        </td>
                        <td class="px-8 py-4">
                            <span class="bg-emerald-500/10 border border-emerald-500/20 px-2 py-1 rounded text-emerald-400 font-bold">
                                <?php echo $ac['current_icao'] ?: '---'; ?>
                            </span>
                        </td>
                        <td class="px-8 py-4 text-slate-200"><?php echo $ac['fullname']; ?></td>
                        <td class="px-8 py-4 text-slate-400"><?php echo $ac['cruise_speed']; ?> kt</td>
                        <td class="px-8 py-4 text-right pr-12">
                            <form method="POST" onsubmit="return confirm('Excluir esta aeronave?');">
                                <input type="hidden" name="delete_id" value="<?php echo $ac['id']; ?>">
                                <button type="submit" class="text-slate-600 hover:text-rose-500 transition opacity-0 group-hover:opacity-100">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($fleet)): ?>
                    <tr><td colspan="6" class="px-8 py-12 text-center text-slate-500 italic">Nenhuma aeronave cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    let debounceTimer;
    const registrationPrefixes = "<?php echo $settings['fleet_registration_prefixes'] ?? 'PR,PT,PS,PP'; ?>".split(',');

    function generateJSMatricula() {
        const prefixes = registrationPrefixes.map(p => p.trim());
        const prefix = prefixes[Math.floor(Math.random() * prefixes.length)] || 'PR';
        const chars = "ABCDEFGHIJKLMNOPQRSTUVWYZ";
        const r1 = chars[Math.floor(Math.random() * chars.length)];
        const r2 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"[Math.floor(Math.random() * 26)];
        const r3 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"[Math.floor(Math.random() * 26)];
        return (prefix + "-" + r1 + r2 + r3).toUpperCase();
    }

    function refreshReg() { document.getElementById('add_reg').value = generateJSMatricula(); }

    function onModelSelect(sel) {
        const opt = sel.options[sel.selectedIndex];
        const info = document.getElementById('model_info');
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
        } else {
            info.innerHTML = '<span class="italic text-slate-500">Selecione um modelo acima</span>';
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
                            d.onclick = () => { input.value = x.value; list.classList.add('hidden'); };
                            list.appendChild(d);
                        });
                    } else list.classList.add('hidden');
                });
        }, 300);
    }

    // Close dropdown lists when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.relative')) {
            document.querySelectorAll('[id$="_list"]').forEach(l => l.classList.add('hidden'));
        }
    });
</script>

<?php include '../includes/layout_footer.php'; ?>