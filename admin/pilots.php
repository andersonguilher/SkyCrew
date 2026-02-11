<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Search logic
$search = $_GET['search'] ?? '';
$where = "";
$params = [];
if ($search) {
    $where = " WHERE p.name LIKE ? OR u.email LIKE ? OR p.current_base LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

// Fetch Pilots
$stmt = $pdo->prepare("SELECT p.*, u.email FROM pilots p JOIN users u ON p.user_id = u.id $where ORDER BY p.name");
$stmt->execute($params);
$pilots = $stmt->fetchAll();

// Handle Delete Pilot
if (isset($_GET['delete_pilot'])) {
    $pilotId = (int)$_GET['delete_pilot'];
    try {
        $pdo->beginTransaction();
        
        // Fetch user_id to delete from users table as well
        $stmt = $pdo->prepare("SELECT user_id FROM pilots WHERE id = ?");
        $stmt->execute([$pilotId]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            // Delete from all potential related tables first (child records)
            $pdo->prepare("DELETE FROM pilot_preferences WHERE pilot_id = ?")->execute([$pilotId]);
            $pdo->prepare("DELETE FROM pilot_aircraft_prefs WHERE pilot_id = ?")->execute([$pilotId]);
            $pdo->prepare("DELETE FROM roster_assignments WHERE pilot_id = ?")->execute([$pilotId]);
            $pdo->prepare("DELETE FROM flight_reports WHERE pilot_id = ?")->execute([$pilotId]);
            
            // Delete the pilot and user
            $pdo->prepare("DELETE FROM pilots WHERE id = ?")->execute([$pilotId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        }
        
        $pdo->commit();
        header("Location: pilots.php?success=Piloto e dados de acesso removidos com sucesso!");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: pilots.php?error=Erro ao remover piloto: " . $e->getMessage());
        exit;
    }
}

// Handle Toggle Admin
if (isset($_GET['toggle_admin'])) {
    $pilotId = (int)$_GET['toggle_admin'];
    try {
        $pdo->beginTransaction();
        
        // Get current status and user_id
        $stmt = $pdo->prepare("SELECT user_id, is_admin FROM pilots WHERE id = ?");
        $stmt->execute([$pilotId]);
        $pilotData = $stmt->fetch();

        if ($pilotData) {
            $newStatus = $pilotData['is_admin'] ? 0 : 1;
            $newRole = $newStatus ? 'admin' : 'pilot';

            // Update pilot
            $pdo->prepare("UPDATE pilots SET is_admin = ? WHERE id = ?")->execute([$newStatus, $pilotId]);
            // Update user role
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $pilotData['user_id']]);
        }
        
        $pdo->commit();
        header("Location: pilots.php?success=Permissões do piloto atualizadas!");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: pilots.php?error=Erro ao atualizar permissões: " . $e->getMessage());
        exit;
    }
}

// Get messages from URL if any
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
if (isset($_POST['add_pilot'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash('123456', PASSWORD_DEFAULT);
    $base = $_POST['base'];

    try {
        $pdo->beginTransaction();
        $role = isset($_POST['is_admin']) ? 'admin' : 'pilot';
        $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$email, $password, $role]);
        $userId = $pdo->lastInsertId();
        
        $isAdminVal = ($role === 'admin') ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO pilots (user_id, name, current_base, is_admin) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $name, $base, $isAdminVal]);
        
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
            <label class="flex items-center gap-3 px-3 py-2 bg-white/5 rounded-xl border border-white/5 cursor-pointer hover:bg-white/10 transition">
                <input type="checkbox" name="is_admin" class="w-4 h-4 rounded border-white/10 bg-slate-900 text-indigo-500 focus:ring-indigo-500/20">
                <span class="text-[10px] font-bold text-slate-300 uppercase tracking-widest">Definir como Administrador</span>
            </label>
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
    <div class="p-6 border-b border-white/10 flex flex-wrap justify-between items-center bg-white/5 gap-4">
        <h2 class="section-title mb-0"><i class="fas fa-users text-indigo-400"></i> Tripulação Operacional</h2>
        
        <div class="flex items-center gap-4 flex-1 md:flex-none">
            <form method="GET" class="relative flex-1 md:w-64">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       class="form-input !py-1.5 !pl-9 text-xs" placeholder="Pesquisar piloto, e-mail ou base...">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-[10px]"></i>
                <?php if ($search): ?>
                    <a href="pilots.php" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white">
                        <i class="fas fa-times-circle text-[10px]"></i>
                    </a>
                <?php endif; ?>
            </form>
            
            <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest bg-white/5 px-3 py-1.5 rounded-full border border-white/10 shrink-0">
                <?php echo count($pilots); ?> Pilotos
            </div>
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
                    <th class="px-8 py-4 text-center">Horas</th>
                    <th class="px-8 py-4 text-right pr-8">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($pilots as $p): ?>
                    <tr class="hover:bg-white/5 transition group">
                        <td class="px-8 py-4 text-slate-500 font-mono">#<?php echo $p['id']; ?></td>
                        <td class="px-8 py-4">
                            <div class="flex items-center gap-2">
                                <div class="font-bold text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                                <?php if ($p['is_admin']): ?>
                                    <span class="bg-amber-500/20 text-amber-400 text-[8px] px-1.5 py-0.5 rounded border border-amber-500/30 font-black uppercase tracking-tighter" title="Administrador">Admin</span>
                                <?php endif; ?>
                            </div>
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
                        <td class="px-8 py-4 text-center font-mono text-indigo-300 font-bold">
                            <?php echo number_format($p['total_hours'], 1); ?> h
                        </td>
                        <td class="px-8 py-4 text-right pr-8 flex justify-end gap-1">
                            <a href="pilots.php?toggle_admin=<?php echo $p['id']; ?>" 
                               class="p-2 rounded-lg transition <?php echo $p['is_admin'] ? 'text-amber-500 bg-amber-500/10 hover:bg-amber-500/20' : 'text-slate-500 hover:text-white hover:bg-white/10'; ?>"
                               title="<?php echo $p['is_admin'] ? 'Revogar Admin' : 'Tornar Admin'; ?>">
                                <i class="fas fa-shield-alt"></i>
                            </a>
                            <button onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo addslashes($p['name']); ?>')" 
                                    class="text-rose-500 hover:text-rose-400 p-2 hover:bg-rose-500/10 rounded-lg transition"
                                    title="Excluir Piloto">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    if (confirm(`ATENÇÃO: Tem certeza que deseja excluir o piloto "${name}"?\n\nEsta ação apagará permanentemente todos os dados deste piloto (perfil, preferências e acessos) no banco virtual_airline_cms.`)) {
        window.location.href = 'pilots.php?delete_pilot=' + id;
    }
}
</script>

<?php include '../includes/layout_footer.php'; ?>