<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$settings = getSystemSettings($pdo);
$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_model'])) {
        $icao = strtoupper(trim($_POST['icao']));
        $name = trim($_POST['model_name']);
        $maxPax = intval($_POST['max_pax']);
        $speed = intval($_POST['cruise_speed'] ?? 450);
        try {
            $stmt = $pdo->prepare("INSERT INTO aircraft_models (icao, model_name, max_pax, cruise_speed) VALUES (?, ?, ?, ?)");
            $stmt->execute([$icao, $name, $maxPax, $speed]);
            $success = "Modelo $icao ($name) adicionado.";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $error = "O modelo $icao já existe!";
            } else {
                $error = "Erro: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_model'])) {
        $icao = $_POST['icao'];
        $name = trim($_POST['model_name']);
        $maxPax = intval($_POST['max_pax']);
        $speed = intval($_POST['cruise_speed'] ?? 450);
        try {
            $stmt = $pdo->prepare("UPDATE aircraft_models SET model_name = ?, max_pax = ?, cruise_speed = ? WHERE icao = ?");
            $stmt->execute([$name, $maxPax, $speed, $icao]);
            $success = "Modelo $icao atualizado.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_model'])) {
        $icao = $_POST['icao'];
        try {
            $stmt = $pdo->prepare("DELETE FROM aircraft_models WHERE icao = ?");
            $stmt->execute([$icao]);
            $success = "Modelo $icao removido.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['add_component'])) {
        $modelIcao = strtoupper(trim($_POST['model_icao']));
        $compName = trim($_POST['component_name']);
        $costInc = floatval($_POST['cost_incident']);
        $costPrev = floatval($_POST['cost_preventive']);
        $interval = intval($_POST['interval_fh']);
        try {
            $stmt = $pdo->prepare("INSERT INTO aircraft_maintenance (model_icao, component_name, cost_incident, cost_preventive, interval_fh) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$modelIcao, $compName, $costInc, $costPrev, $interval]);
            $success = "Componente '$compName' adicionado ao $modelIcao.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_component'])) {
        $id = intval($_POST['comp_id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM aircraft_maintenance WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Componente removido.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['save_markup'])) {
        $markup = floatval($_POST['ticket_markup']);
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ticket_markup', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$markup, $markup]);
        $settings['ticket_markup'] = $markup;
        $success = "Multiplicador de bilhete atualizado para $markup.";
    }
}

// Fetch all models with maintenance cost summary
$models = $pdo->query("
    SELECT am.*, 
           COALESCE(SUM(ac.cost_preventive / ac.interval_fh), 0) as maint_per_fh,
           COUNT(ac.id) as component_count
    FROM aircraft_models am
    LEFT JOIN aircraft_maintenance ac ON ac.model_icao = am.icao
    GROUP BY am.icao
    ORDER BY am.max_pax ASC
")->fetchAll();

// Fetch selected model's components
$selectedModel = $_GET['model'] ?? '';
$components = [];
$modelInfo = null;
if ($selectedModel) {
    $stmt = $pdo->prepare("SELECT * FROM aircraft_models WHERE icao = ?");
    $stmt->execute([$selectedModel]);
    $modelInfo = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM aircraft_maintenance WHERE model_icao = ? ORDER BY component_name");
    $stmt->execute([$selectedModel]);
    $components = $stmt->fetchAll();
}

$markup = (float)($settings['ticket_markup'] ?? 700);

$pageTitle = "Modelos de Aeronaves - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="sidebar-narrow flex flex-col gap-6">
    <!-- Add Model Form -->
    <div class="glass-panel p-6 rounded-3xl shrink-0">
        <h2 class="section-title"><i class="fas fa-drafting-compass text-indigo-400"></i> Novo Modelo</h2>
        <form method="POST" id="modelForm" class="space-y-4">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Tipo (ICAO)</label>
                <input type="text" name="icao" id="add_icao" class="form-input uppercase opacity-70" placeholder="..." readonly required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Nome Completo</label>
                <input type="text" name="model_name" id="add_name" class="form-input opacity-70" placeholder="..." readonly required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Max Passageiros</label>
                <input type="number" name="max_pax" id="add_pax" class="form-input" placeholder="189" required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Velocidade Cruzeiro (kt)</label>
                <input type="number" name="cruise_speed" id="add_speed" class="form-input" placeholder="450" required>
            </div>
            <div class="pt-2 space-y-3">
                <button type="button" onclick="openSyncModal()" class="w-full py-3 border-2 border-indigo-500/30 text-indigo-400 font-bold rounded-xl hover:bg-indigo-500/10 transition text-xs uppercase tracking-widest">
                    <i class="fas fa-search mr-2"></i> Buscar SimBrief
                </button>
                <button type="submit" name="add_model" id="btnAdd" disabled class="w-full py-4 btn-glow opacity-50 cursor-not-allowed uppercase tracking-widest text-xs">
                    Adicionar Modelo
                </button>
            </div>
        </form>
    </div>

    <!-- Ticket Markup -->
    <div class="glass-panel p-6 rounded-3xl shrink-0">
        <h2 class="section-title"><i class="fas fa-calculator text-emerald-400"></i> Fórmula do Bilhete</h2>
        <div class="bg-white/5 border border-white/10 rounded-xl p-4 mb-4">
            <p class="text-[10px] text-slate-400 uppercase tracking-widest mb-2 font-bold">Fórmula</p>
            <div class="text-[11px] text-white font-mono leading-relaxed">
                <span class="text-emerald-400">ticket</span> = 
                (<span class="text-indigo-400">maint_prev/fh</span> × <span class="text-yellow-400">horas</span> / <span class="text-sky-400">max_pax</span>) × <span class="text-rose-400">markup</span>
            </div>
            <p class="text-[9px] text-slate-500 mt-2">
                A manutenção preventiva por hora de voo é somada para todos os componentes do modelo, depois distribuída por passageiro e multiplicada pelo fator de markup.
            </p>
        </div>
        <form method="POST" class="space-y-3">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Multiplicador (Markup)</label>
                <input type="number" step="1" name="ticket_markup" value="<?php echo $markup; ?>" class="form-input" required>
                <p class="text-[9px] text-slate-500 ml-1">700 = cobre custos totais + margem</p>
            </div>
            <button type="submit" name="save_markup" class="w-full py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500 hover:text-white rounded-xl font-bold text-xs transition uppercase tracking-widest">
                Salvar Markup
            </button>
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
    <?php if ($selectedModel && $modelInfo): ?>
    <!-- Component Detail View -->
    <div class="p-6 border-b border-white/10 bg-white/5 flex justify-between items-center">
        <div>
            <a href="aircraft_models.php" class="text-indigo-400 hover:text-white text-xs font-bold transition">
                <i class="fas fa-arrow-left mr-1"></i> Voltar
            </a>
            <h2 class="section-title mb-0 mt-2">
                <i class="fas fa-cogs text-indigo-400"></i>
                <?php echo $modelInfo['icao']; ?> — <?php echo $modelInfo['model_name']; ?>
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest ml-1">
                <?php echo $modelInfo['max_pax']; ?> passageiros · <?php echo $modelInfo['cruise_speed']; ?> kt · <?php echo count($components); ?> componentes
            </p>
        </div>
        <div class="text-right">
            <?php
            $totalPrev = 0;
            foreach ($components as $c) {
                $totalPrev += $c['cost_preventive'] / $c['interval_fh'];
            }
            $exTicket1h = round(($totalPrev * 1 / $modelInfo['max_pax']) * $markup, 2);
            $exTicket2h = round(($totalPrev * 2 / $modelInfo['max_pax']) * $markup, 2);
            ?>
            <div class="text-[9px] text-slate-500 uppercase tracking-widest mb-1">Maint. Prev./FH</div>
            <div class="text-lg font-bold text-indigo-400 font-mono">R$ <?php echo number_format($totalPrev, 2); ?></div>
            <div class="text-[10px] text-slate-400 mt-1">
                Bilhete 1h: <span class="text-emerald-400 font-bold">R$ <?php echo number_format($exTicket1h, 2); ?></span> · 
                2h: <span class="text-emerald-400 font-bold">R$ <?php echo number_format($exTicket2h, 2); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Add Component Form -->
    <div class="p-4 border-b border-white/10 bg-white/3">
        <form method="POST" class="flex gap-3 items-end">
            <input type="hidden" name="model_icao" value="<?php echo $selectedModel; ?>">
            <div class="flex-1 space-y-1">
                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Componente</label>
                <input type="text" name="component_name" class="form-input text-xs py-2" placeholder="Ex: Pneu Principal" required>
            </div>
            <div class="w-36 space-y-1">
                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Corretiva (R$)</label>
                <input type="number" step="0.01" name="cost_incident" class="form-input text-xs py-2" placeholder="12000.00" required>
            </div>
            <div class="w-36 space-y-1">
                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Preventiva (R$)</label>
                <input type="number" step="0.01" name="cost_preventive" class="form-input text-xs py-2" placeholder="6000.00" required>
            </div>
            <div class="w-28 space-y-1">
                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Intervalo (FH)</label>
                <input type="number" name="interval_fh" class="form-input text-xs py-2" placeholder="500" required>
            </div>
            <button type="submit" name="add_component" class="px-4 py-2 bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 hover:bg-indigo-500 hover:text-white rounded-xl font-bold text-xs transition shrink-0">
                <i class="fas fa-plus mr-1"></i> Adicionar
            </button>
        </form>
    </div>

    <!-- Components Table -->
    <div class="flex-1 overflow-y-auto">
        <table class="w-full text-left text-[12px]">
            <thead class="bg-white/5 sticky top-0 z-10">
                <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                    <th class="px-6 py-4">Componente</th>
                    <th class="px-6 py-4 text-right">Custo Corretivo</th>
                    <th class="px-6 py-4 text-right">Custo Preventivo</th>
                    <th class="px-6 py-4 text-right">Intervalo (FH)</th>
                    <th class="px-6 py-4 text-right">Prev./FH</th>
                    <th class="px-6 py-4 text-right pr-8">Ação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($components as $c): 
                    $prevPerFH = $c['cost_preventive'] / max($c['interval_fh'], 1);
                ?>
                <tr class="hover:bg-white/5 transition group">
                    <td class="px-6 py-3 font-bold text-white"><?php echo htmlspecialchars($c['component_name']); ?></td>
                    <td class="px-6 py-3 text-right font-mono text-rose-400">R$ <?php echo number_format($c['cost_incident'], 2); ?></td>
                    <td class="px-6 py-3 text-right font-mono text-emerald-400">R$ <?php echo number_format($c['cost_preventive'], 2); ?></td>
                    <td class="px-6 py-3 text-right font-mono text-slate-300"><?php echo number_format($c['interval_fh']); ?> FH</td>
                    <td class="px-6 py-3 text-right font-mono text-yellow-400 font-bold">R$ <?php echo number_format($prevPerFH, 4); ?></td>
                    <td class="px-6 py-3 text-right pr-8">
                        <form method="POST" onsubmit="return confirm('Remover este componente?');" class="inline">
                            <input type="hidden" name="comp_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" name="delete_component" class="text-slate-600 hover:text-rose-500 transition opacity-0 group-hover:opacity-100">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($components)): ?>
                <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500 italic">Nenhum componente cadastrado.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($components)): ?>
            <tfoot class="bg-white/5 border-t border-white/10">
                <tr class="font-bold text-[11px]">
                    <td class="px-6 py-4 text-slate-300">TOTAL</td>
                    <td class="px-6 py-4 text-right font-mono text-rose-400">
                        R$ <?php echo number_format(array_sum(array_column($components, 'cost_incident')), 2); ?>
                    </td>
                    <td class="px-6 py-4 text-right font-mono text-emerald-400">
                        R$ <?php echo number_format(array_sum(array_column($components, 'cost_preventive')), 2); ?>
                    </td>
                    <td class="px-6 py-4"></td>
                    <td class="px-6 py-4 text-right font-mono text-yellow-400">
                        R$ <?php echo number_format($totalPrev, 4); ?>/FH
                    </td>
                    <td class="px-6 py-4"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php else: ?>
    <!-- Models Overview -->
    <div class="p-6 border-b border-white/10 flex justify-between items-center bg-white/5">
        <h2 class="section-title mb-0"><i class="fas fa-plane text-indigo-400"></i> Modelos Cadastrados</h2>
        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-white/5 px-3 py-1 rounded-full">
            <?php echo count($models); ?> Modelos
        </div>
    </div>
    <div class="flex-1 overflow-y-auto">
        <table class="w-full text-left text-[12px]">
            <thead class="bg-white/5 sticky top-0 z-10">
                <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                    <th class="px-6 py-4">ICAO</th>
                    <th class="px-6 py-4">Modelo</th>
                    <th class="px-6 py-4 text-right">Max PAX</th>
                    <th class="px-6 py-4 text-right">Cruzeiro</th>
                    <th class="px-6 py-4 text-right">Componentes</th>
                    <th class="px-6 py-4 text-right">Maint. Prev./FH</th>
                    <th class="px-6 py-4 text-right">Bilhete 1h</th>
                    <th class="px-6 py-4 text-right">Bilhete 3h</th>
                    <th class="px-6 py-4 text-right pr-8">Ação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($models as $m): 
                    $maintFH = (float)$m['maint_per_fh'];
                    $maxP = (int)$m['max_pax'];
                    $t1h = $maxP ? round(($maintFH * 1 / $maxP) * $markup, 2) : 0;
                    $t3h = $maxP ? round(($maintFH * 3 / $maxP) * $markup, 2) : 0;
                ?>
                <tr class="hover:bg-white/5 transition group">
                    <td class="px-6 py-4">
                        <a href="?model=<?php echo $m['icao']; ?>" class="font-bold text-indigo-400 hover:text-indigo-300 transition">
                            <?php echo $m['icao']; ?>
                        </a>
                    </td>
                    <td class="px-6 py-4 text-slate-200"><?php echo htmlspecialchars($m['model_name']); ?></td>
                    <td class="px-6 py-4 text-right font-mono text-sky-400 font-bold"><?php echo $maxP; ?></td>
                    <td class="px-6 py-4 text-right font-mono text-slate-400"><?php echo $m['cruise_speed']; ?> kt</td>
                    <td class="px-6 py-4 text-right">
                        <?php if ($m['component_count'] > 0): ?>
                            <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded-full text-[10px] font-bold">
                                <?php echo $m['component_count']; ?>
                            </span>
                        <?php else: ?>
                            <span class="bg-rose-500/10 text-rose-400 border border-rose-500/20 px-2 py-0.5 rounded-full text-[10px] font-bold">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-right font-mono text-yellow-400">
                        R$ <?php echo number_format($maintFH, 2); ?>
                    </td>
                    <td class="px-6 py-4 text-right font-mono text-emerald-400 font-bold">
                        R$ <?php echo number_format($t1h, 2); ?>
                    </td>
                    <td class="px-6 py-4 text-right font-mono text-emerald-400">
                        R$ <?php echo number_format($t3h, 2); ?>
                    </td>
                    <td class="px-6 py-4 text-right pr-8">
                        <div class="flex items-center justify-end gap-3 opacity-0 group-hover:opacity-100 transition-opacity">
                            <a href="?model=<?php echo $m['icao']; ?>" class="w-8 h-8 rounded-full bg-indigo-500/20 hover:bg-indigo-500 hover:text-white text-indigo-400 flex items-center justify-center transition-all">
                                <i class="fas fa-cogs text-xs"></i>
                            </a>
                            <form method="POST" onsubmit="return confirm('Excluir modelo <?php echo $m['icao']; ?> e todos os seus componentes?');" class="inline">
                                <input type="hidden" name="icao" value="<?php echo $m['icao']; ?>">
                                <button type="submit" name="delete_model" class="w-8 h-8 rounded-full bg-rose-500/20 hover:bg-rose-500 hover:text-white text-rose-400 flex items-center justify-center transition-all">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($models)): ?>
                <tr><td colspan="9" class="px-6 py-12 text-center text-slate-500 italic">Nenhum modelo cadastrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- SimBrief Sync Modal -->
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
        
        // Set speed defaults based on aircraft type
        let speed = 450;
        if (icao.startsWith('C')) speed = 120;
        if (icao.startsWith('AT7') || icao.startsWith('AT4')) speed = 280;
        if (icao.startsWith('E1')) speed = 420;
        if (icao.startsWith('A33') || icao.startsWith('B77') || icao.startsWith('B78')) speed = 480;
        document.getElementById('add_speed').value = speed;
        
        // Set max_pax defaults based on aircraft type
        let pax = 180;
        if (icao.startsWith('C208')) pax = 14;
        else if (icao.startsWith('C')) pax = 6;
        else if (icao === 'BE58') pax = 6;
        else if (icao.startsWith('AT7')) pax = 72;
        else if (icao.startsWith('E1')) pax = 120;
        else if (icao === 'A20N') pax = 195;
        else if (icao === 'A321') pax = 236;
        else if (icao.startsWith('A33')) pax = 440;
        else if (icao === 'B738') pax = 189;
        else if (icao.startsWith('B77')) pax = 440;
        document.getElementById('add_pax').value = pax;
        
        // Enable submit
        const btn = document.getElementById('btnAdd');
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
        closeSyncModal();
    }
</script>

<?php include '../includes/layout_footer.php'; ?>
