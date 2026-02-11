<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$success = '';
$error = '';

// Handle Add/Edit
if (isset($_POST['save_rank'])) {
    $id = $_POST['rank_id'] ?? null;
    $name = $_POST['rank_name'];
    $minHours = $_POST['min_hours'];
    $payRate = $_POST['pay_rate'];
    $baseSalary = $_POST['base_salary'];

    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE ranks SET rank_name = ?, min_hours = ?, pay_rate = ?, base_salary = ? WHERE id = ?");
            $stmt->execute([$name, $minHours, $payRate, $baseSalary, $id]);
            $success = "Patente atualizada com sucesso!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO ranks (rank_name, min_hours, pay_rate, base_salary) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $minHours, $payRate, $baseSalary]);
            $success = "Nova patente adicionada!";
        }
    } catch (Exception $e) {
        $error = "Erro: " . $e->getMessage();
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM ranks WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $success = "Patente removida.";
    } catch (Exception $e) {
        $error = "Erro ao excluir: " . $e->getMessage();
    }
}

// Fetch Ranks
$ranks = $pdo->query("SELECT * FROM ranks ORDER BY min_hours ASC")->fetchAll();

$pageTitle = "Gerenciar Patentes - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="sidebar-narrow flex flex-col gap-6">
    <div class="glass-panel p-6 rounded-3xl shrink-0">
        <h2 class="section-title"><i class="fas fa-medal text-indigo-400"></i> Configurar Patente</h2>
        <form method="POST" class="space-y-4" id="rankForm">
            <input type="hidden" name="rank_id" id="rank_id">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Nome da Patente</label>
                <input type="text" name="rank_name" id="rank_name" class="form-input" placeholder="Ex: Comandante" required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Horas Mínimas</label>
                <input type="number" step="0.1" name="min_hours" id="min_hours" class="form-input" placeholder="0.0" required>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Valor/Hora</label>
                    <input type="number" step="0.01" name="pay_rate" id="pay_rate" class="form-input" placeholder="0.00" required>
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Salário Base</label>
                    <input type="number" step="0.01" name="base_salary" id="base_salary" class="form-input" placeholder="0.00" required>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" name="save_rank" class="btn-glow flex-1 py-3 mt-2 uppercase tracking-widest text-xs font-bold">Salvar</button>
                <button type="button" onclick="resetForm()" class="bg-white/5 hover:bg-white/10 text-white/50 px-4 py-3 mt-2 rounded-xl text-xs uppercase font-bold transition-all">Limpar</button>
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
        <h2 class="section-title mb-0"><i class="fas fa-layer-group text-indigo-400"></i> Hierarquia e Remuneração</h2>
        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-white/5 px-3 py-1 rounded-full">
            <?php echo count($ranks); ?> Patentes Configuradas
        </div>
    </div>
    <div class="flex-1 overflow-y-auto">
        <table class="w-full text-left text-[12px]">
            <thead class="bg-white/5 sticky top-0 z-10">
                <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                    <th class="px-8 py-4">Patente</th>
                    <th class="px-8 py-4 text-center">Horas Mínimas</th>
                    <th class="px-8 py-4 text-right">Valor/Hora</th>
                    <th class="px-8 py-4 text-right">Salário Base</th>
                    <th class="px-8 py-4 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($ranks as $r): ?>
                    <tr class="hover:bg-white/5 transition group">
                        <td class="px-8 py-4">
                            <div class="font-bold text-indigo-400"><?php echo htmlspecialchars($r['rank_name']); ?></div>
                        </td>
                        <td class="px-8 py-4 text-center text-slate-300 font-mono">
                            <?php echo number_format($r['min_hours'], 1); ?> h
                        </td>
                        <td class="px-8 py-4 text-right font-mono text-emerald-400 font-bold">
                            R$ <?php echo number_format($r['pay_rate'], 2, ',', '.'); ?>
                        </td>
                        <td class="px-8 py-4 text-right font-mono text-white/80">
                            R$ <?php echo number_format($r['base_salary'], 2, ',', '.'); ?>
                        </td>
                        <td class="px-8 py-4 text-center">
                            <div class="flex justify-center gap-2">
                                <button onclick="editRank(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="p-2 hover:bg-indigo-500/20 text-indigo-400 rounded-lg transition-colors">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?delete=<?php echo $r['id']; ?>" onclick="return confirm('Excluir esta patente?')" class="p-2 hover:bg-rose-500/20 text-rose-400 rounded-lg transition-colors">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function editRank(rank) {
    document.getElementById('rank_id').value = rank.id;
    document.getElementById('rank_name').value = rank.rank_name;
    document.getElementById('min_hours').value = rank.min_hours;
    document.getElementById('pay_rate').value = rank.pay_rate;
    document.getElementById('base_salary').value = rank.base_salary;
    
    // Smooth scroll to top
    document.querySelector('.sidebar-narrow').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('rankForm').reset();
    document.getElementById('rank_id').value = '';
}
</script>

<?php include '../includes/layout_footer.php'; ?>
