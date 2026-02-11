<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$success = '';
$error = '';

// Fetch Fleet grouped by ICAO
$fleet_stmt = $pdo->query("SELECT id, registration, icao_code FROM fleet ORDER BY icao_code, registration");
$fleet_by_type = [];
while ($row = $fleet_stmt->fetch()) {
    $fleet_by_type[$row['icao_code']][] = $row;
}

// Fetch Unassigned Flights grouped by Aircraft Type
$flights_stmt = $pdo->query("SELECT aircraft_type, COUNT(*) as count FROM flights_master WHERE aircraft_id IS NULL GROUP BY aircraft_type");
$unassigned_flights = $flights_stmt->fetchAll();

// Handle Bulk Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_type'])) {
    $type_to_assign = $_POST['flight_type'];
    $icao_to_use = $_POST['fleet_icao'];

    if (isset($fleet_by_type[$icao_to_use])) {
        $available_ac = $fleet_by_type[$icao_to_use];
        $ac_count = count($available_ac);
        
        // Get all flight IDs for this type
        $stmt = $pdo->prepare("SELECT id FROM flights_master WHERE aircraft_type = ? AND aircraft_id IS NULL");
        $stmt->execute([$type_to_assign]);
        $flight_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $assigned_count = 0;
        foreach ($flight_ids as $index => $flight_id) {
            $ac_index = $index % $ac_count;
            $selected_ac_id = $available_ac[$ac_index]['id'];
            
            $update = $pdo->prepare("UPDATE flights_master SET aircraft_id = ? WHERE id = ?");
            $update->execute([$selected_ac_id, $flight_id]);
            $assigned_count++;
        }
        
        $success = "Sucesso! $assigned_count voos do tipo $type_to_assign foram atribuídos a aeronaves $icao_to_use.";
        
        // Refresh data
        $flights_stmt = $pdo->query("SELECT aircraft_type, COUNT(*) as count FROM flights_master WHERE aircraft_id IS NULL GROUP BY aircraft_type");
        $unassigned_flights = $flights_stmt->fetchAll();
    } else {
        $error = "Nenhuma aeronave do tipo $icao_to_use encontrada na frota.";
    }
}

$pageTitle = "Atribuição em Lote - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 p-8 overflow-y-auto">
    <div class="max-w-4xl mx-auto space-y-8">
        <div>
            <h1 class="text-3xl font-bold text-white mb-2">Atribuição de Aeronaves em Lote</h1>
            <p class="text-slate-400">Distribua automaticamente as aeronaves da frota para as rotas sem atribuição.</p>
        </div>

        <?php if ($success): ?>
            <div class="glass-panel border-l-4 border-emerald-500 px-6 py-4 rounded-2xl text-emerald-400 font-bold animate-pulse">
                <i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="glass-panel border-l-4 border-rose-500 px-6 py-4 rounded-2xl text-rose-400 font-bold">
                <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Summary of Unassigned -->
            <div class="glass-panel rounded-3xl overflow-hidden flex flex-col">
                <div class="p-6 border-b border-white/10 bg-white/5">
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-list-ul text-indigo-400"></i> Rotas por Equipamento
                    </h2>
                </div>
                <div class="p-0">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-white/2">
                            <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                                <th class="px-6 py-4">Equipamento (da Rota)</th>
                                <th class="px-6 py-4">Quantidade</th>
                                <th class="px-6 py-4 text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($unassigned_flights as $row): ?>
                                <tr class="hover:bg-white/5 transition group">
                                    <td class="px-6 py-4 font-bold text-indigo-400"><?php echo $row['aircraft_type']; ?></td>
                                    <td class="px-6 py-4 text-slate-200"><?php echo $row['count']; ?> voos</td>
                                    <td class="px-6 py-4 text-right">
                                        <button onclick="openAssignModal('<?php echo $row['aircraft_type']; ?>', <?php echo $row['count']; ?>)" class="text-indigo-400 hover:text-white transition font-bold text-xs uppercase tracking-widest bg-indigo-500/10 px-3 py-1 rounded-lg border border-indigo-500/20">
                                            Atribuir
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($unassigned_flights)): ?>
                                <tr>
                                    <td colspan="3" class="px-6 py-12 text-center text-slate-500 italic">
                                        Todos os voos já possuem aeronaves atribuídas!
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Fleet Summary -->
            <div class="glass-panel rounded-3xl overflow-hidden flex flex-col">
                <div class="p-6 border-b border-white/10 bg-white/5">
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-plane text-indigo-400"></i> Frota Disponível
                    </h2>
                </div>
                <div class="p-6 space-y-4 overflow-y-auto max-h-[400px]">
                    <?php foreach ($fleet_by_type as $icao => $aircrafts): ?>
                        <div class="p-4 bg-white/5 rounded-2xl border border-white/10">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-lg font-bold text-white"><?php echo $icao; ?></span>
                                <span class="bg-indigo-500/20 text-indigo-400 px-3 py-1 rounded-full text-[10px] font-bold">
                                    <?php echo count($aircrafts); ?> Aeronaves
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($aircrafts as $ac): ?>
                                    <span class="text-[10px] font-mono text-slate-400 bg-black/20 px-2 py-1 rounded border border-white/5">
                                        <?php echo $ac['registration']; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="text-center">
            <a href="flights.php" class="text-slate-400 hover:text-white transition text-xs uppercase tracking-widest flex items-center justify-center gap-2">
                <i class="fas fa-arrow-left"></i> Voltar ao Painel de Voos
            </a>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="fixed inset-0 bg-black/80 hidden z-[1000] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="glass-panel rounded-3xl w-full max-w-md flex flex-col overflow-hidden border border-white/20 shadow-2xl">
        <form method="POST">
            <div class="p-6 border-b border-white/10 bg-white/5">
                <h3 class="text-xl font-bold text-white">Atribuir Aeronaves</h3>
                <p class="text-xs text-slate-400 mt-1">Defina qual grupo de aeronaves operará estas rotas.</p>
            </div>
            <div class="p-8 space-y-6">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Equipamento Previsto na Rota</label>
                    <input type="text" id="modal_flight_type" name="flight_type" class="form-input bg-white/5 border-white/10" readonly>
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Grupo da Frota para Usar</label>
                    <select name="fleet_icao" class="form-input" required>
                        <option value="">Selecione um grupo...</option>
                        <?php foreach ($fleet_by_type as $icao => $aircrafts): ?>
                            <option value="<?php echo $icao; ?>"><?php echo $icao; ?> (<?php echo count($aircrafts); ?> disp.)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="p-4 bg-indigo-500/10 rounded-2xl border border-indigo-500/20">
                    <p class="text-[11px] text-indigo-300 leading-relaxed">
                        <i class="fas fa-info-circle mr-1"></i> 
                        O sistema distribuirá os <span id="modal_count" class="font-bold"></span> voos entre todas as aeronaves do grupo selecionado seguindo um padrão circular (round-robin).
                    </p>
                </div>
            </div>
            <div class="p-6 bg-white/5 border-t border-white/10 flex gap-4">
                <button type="button" onclick="closeAssignModal()" class="flex-1 py-3 text-slate-400 font-bold hover:text-white transition text-xs uppercase tracking-widest">Cancelar</button>
                <button type="submit" name="assign_type" class="flex-1 py-3 btn-glow text-white font-bold rounded-xl text-xs uppercase tracking-widest">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAssignModal(type, count) {
        document.getElementById('modal_flight_type').value = type;
        document.getElementById('modal_count').textContent = count;
        document.getElementById('assignModal').classList.remove('hidden');
    }
    function closeAssignModal() {
        document.getElementById('assignModal').classList.add('hidden');
    }
</script>

<?php include '../includes/layout_footer.php'; ?>
