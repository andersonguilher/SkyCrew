<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_aircraft'])) {
        $icao = strtoupper(trim($_POST['icao_code']));
        $name = trim($_POST['fullname']);
        $speed = intval($_POST['cruise_speed']);
        $registration = trim($_POST['registration']);

        try {
            $stmt = $pdo->prepare("INSERT INTO fleet (icao_code, registration, fullname, cruise_speed) VALUES (?, ?, ?, ?)");
            $stmt->execute([$icao, $registration, $name, $speed]);
            $success = "Aeronave $registration ($icao) adicionada com sucesso.";
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
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Código ICAO</label>
                <input type="text" name="icao_code" id="add_icao" class="form-input uppercase opacity-70" placeholder="..." readonly required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Nome Completo</label>
                <input type="text" name="fullname" id="add_name" class="form-input opacity-70" placeholder="..." readonly required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Velocidade Cruzeiro (kt)</label>
                <input type="number" name="cruise_speed" id="add_speed" class="form-input" placeholder="450" required>
            </div>
            
            <div class="pt-2 space-y-3">
                <button type="button" onclick="openSyncModal()" class="w-full py-3 border-2 border-indigo-500/30 text-indigo-400 font-bold rounded-xl hover:bg-indigo-500/10 transition text-xs uppercase tracking-widest">
                    <i class="fas fa-search mr-2"></i> Buscar SimBrief
                </button>
                <button type="submit" name="add_aircraft" id="btnAdd" disabled class="w-full py-4 btn-glow opacity-50 cursor-not-allowed uppercase tracking-widest text-xs">
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
                    <th class="px-8 py-4">ICAO</th>
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
                    <tr><td colspan="5" class="px-8 py-12 text-center text-slate-500 italic">Nenhuma aeronave cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Sync Modal -->
<div id="syncModal" class="fixed inset-0 bg-black/80 hidden z-[1000] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="glass-panel rounded-3xl w-full max-w-4xl max-h-[85vh] flex flex-col overflow-hidden border border-white/20 shadow-2xl">
        <div class="p-6 border-b border-white/10 flex justify-between items-center bg-white/5">
            <h3 class="text-xl font-bold text-white flex items-center gap-2"><i class="fas fa-database text-indigo-400"></i> SimBrief Aircraft Database</h3>
            <button onclick="closeSyncModal()" class="text-slate-400 hover:text-white text-2xl transition">&times;</button>
        </div>
        <div class="p-4 bg-white/5 border-b border-white/10">
            <div class="relative">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                <input type="text" id="sbSearch" oninput="filterSBAircraft(this.value)" class="form-input pl-12" placeholder="Filtrar por nome ou ICAO...">
            </div>
        </div>
        <div class="p-6 overflow-y-auto flex-1 bg-black/20" id="sbList">
            <div class="flex items-center justify-center p-12 text-slate-500">
                <i class="fas fa-spinner fa-spin mr-2"></i> Carregando lista...
            </div>
        </div>
        <div class="p-6 border-t border-white/10 bg-white/5 text-right">
            <button onclick="closeSyncModal()" class="px-8 py-2 rounded-xl font-bold text-slate-400 hover:text-white transition uppercase text-xs tracking-widest">Fechar</button>
        </div>
    </div>
</div>

<script>
    let allSBAircraft = {};
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

    async function openSyncModal() {
        document.getElementById('syncModal').classList.remove('hidden');
        if (Object.keys(allSBAircraft).length === 0) {
            try {
                const response = await fetch('https://www.simbrief.com/api/inputs.list.json');
                const data = await response.json();
                allSBAircraft = data.aircraft || {};
                renderSBAircraft(allSBAircraft);
            } catch (e) {
                document.getElementById('sbList').innerHTML = '<div class="text-rose-500 text-center py-8 font-bold">Erro ao carregar lista do SimBrief.</div>';
            }
        }
    }

    function closeSyncModal() { document.getElementById('syncModal').classList.add('hidden'); }

    function renderSBAircraft(list) {
        const container = document.getElementById('sbList');
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-1 md:grid-cols-2 gap-4';

        Object.entries(list).forEach(([icao, details]) => {
            const item = document.createElement('div');
            item.className = 'p-4 glass-panel border border-white/5 hover:border-indigo-500/50 hover:bg-white/5 cursor-pointer group flex justify-between items-center transition-all rounded-2xl';
            item.onclick = () => selectSBAircraft(icao, details.name);
            item.innerHTML = `
                <div>
                    <div class="font-bold text-indigo-400 group-hover:text-indigo-300">${icao}</div>
                    <div class="text-xs text-slate-400 group-hover:text-slate-200">${details.name}</div>
                </div>
                <i class="fas fa-plus text-slate-700 group-hover:text-indigo-500 transition"></i>
            `;
            grid.appendChild(item);
        });
        container.appendChild(grid);
    }

    function filterSBAircraft(query) {
        query = query.toLowerCase();
        const filtered = {};
        Object.entries(allSBAircraft).forEach(([icao, details]) => {
            if (icao.toLowerCase().includes(query) || details.name.toLowerCase().includes(query)) filtered[icao] = details;
        });
        renderSBAircraft(filtered);
    }

    function selectSBAircraft(icao, name) {
        document.getElementById('add_icao').value = icao;
        document.getElementById('add_name').value = name;
        let speed = 450;
        if (icao.startsWith('C')) speed = 120;
        if (icao.startsWith('A3') || icao.startsWith('B7') || icao.startsWith('E1')) speed = 450;
        if (icao.startsWith('AT7')) speed = 280;
        document.getElementById('add_speed').value = speed;
        refreshReg();
        const btnAdd = document.getElementById('btnAdd');
        btnAdd.disabled = false;
        btnAdd.classList.remove('opacity-50', 'cursor-not-allowed');
        closeSyncModal();
    }
</script>

<?php include '../includes/layout_footer.php'; ?>