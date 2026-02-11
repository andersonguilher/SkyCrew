<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Fetch Pilots
$stmt = $pdo->query("SELECT p.*, u.email FROM pilots p JOIN users u ON p.user_id = u.id ORDER BY p.name");
$pilots = $stmt->fetchAll();

// Handle Add Pilot
$success = '';
$error = '';
if (isset($_POST['add_pilot'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash('123456', PASSWORD_DEFAULT);
    $base = $_POST['base'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'pilot')");
        $stmt->execute([$email, $password]);
        $userId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO pilots (user_id, name, current_base) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $name, $base]);
        $pdo->commit();
        $success = "Piloto criado! Senha padrão: 123456";
        header("Refresh:2");
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro: " . $e->getMessage();
    }
}

$pageTitle = "Gerenciar Pilotos - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="sidebar-narrow flex flex-col gap-6">
    <div class="glass-panel p-6 rounded-3xl shrink-0">
        <h2 class="section-title"><i class="fas fa-user-plus text-indigo-400"></i> Novo Piloto</h2>
        <form method="POST" class="space-y-4">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Nome Completo</label>
                <input type="text" name="name" class="form-input" placeholder="Ex: Anderson Guilherme" required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">E-mail</label>
                <input type="email" name="email" class="form-input" placeholder="pilot@kafly.com.br" required>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Base (ICAO)</label>
                <input type="text" name="base" class="form-input uppercase" value="SBGR" maxlength="4" required>
            </div>
            <button type="submit" name="add_pilot" class="btn-glow w-full py-3 mt-2 uppercase tracking-widest text-xs">Adicionar Piloto</button>
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
        <h2 class="section-title mb-0"><i class="fas fa-users text-indigo-400"></i> Tripulação Operacional</h2>
        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-white/5 px-3 py-1 rounded-full">
            <?php echo count($pilots); ?> Pilotos Ativos
        </div>
    </div>
    <div class="flex-1 overflow-y-auto">
        <table class="w-full text-left text-[12px]">
            <thead class="bg-white/5 sticky top-0 z-10">
                <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                    <th class="px-8 py-4">ID</th>
                    <th class="px-8 py-4">Nome / E-mail</th>
                    <th class="px-8 py-4 text-center">Base</th>
                    <th class="px-8 py-4">Patente</th>
                    <th class="px-8 py-4 text-right pr-12">Horas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($pilots as $p): ?>
                    <tr class="hover:bg-white/5 transition group">
                        <td class="px-8 py-4 text-slate-500 font-mono">#<?php echo $p['id']; ?></td>
                        <td class="px-8 py-4">
                            <div class="font-bold text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                            <div class="text-[10px] text-slate-500"><?php echo htmlspecialchars($p['email']); ?></div>
                        </td>
                        <td class="px-8 py-4 text-center">
                            <span class="bg-indigo-500/10 text-indigo-400 px-3 py-1 rounded-lg font-bold border border-indigo-500/20">
                                <?php echo $p['current_base']; ?>
                            </span>
                        </td>
                        <td class="px-8 py-4">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-medal text-amber-500/50"></i>
                                <span class="font-semibold text-slate-300"><?php echo $p['rank'] ?: 'Cadet'; ?></span>
                            </div>
                        </td>
                        <td class="px-8 py-4 text-right pr-12 font-mono text-indigo-300 font-bold">
                            <?php echo number_format($p['total_hours'], 1); ?> h
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>