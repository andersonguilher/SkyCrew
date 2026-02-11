<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$message = '';
$error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'FOOD';
            if ($name) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO expense_venues (name, type) VALUES (?, ?)");
                    $stmt->execute([$name, $type]);
                    $message = "Local '$name' adicionado com sucesso!";
                } catch (Exception $e) {
                    $error = "Erro ao adicionar: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = $_POST['id'] ?? 0;
            try {
                $stmt = $pdo->prepare("DELETE FROM expense_venues WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Local removido com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao remover: " . $e->getMessage();
            }
        }
    }
}

// Fetch Venues
$venues = [];
try {
    $stmt = $pdo->query("SELECT * FROM expense_venues ORDER BY type ASC, name ASC");
    $venues = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Erro ao carregar locais: " . $e->getMessage();
}

$pageTitle = "Gerenciar Locais de Despesas - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden w-full">
    <div class="flex justify-between items-center shrink-0">
        <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                <i class="fas fa-map-marker-alt text-indigo-400 text-sm"></i> Locais de Despesas (Venues)
            </h2>
            <p class="text-slate-400 text-[11px] mt-1">Gerencie hotéis e restaurantes usados nos cálculos de ociosidade.</p>
        </div>
        
        <div class="flex gap-3">
            <?php if ($message): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/20 px-4 py-1.5 rounded-full text-emerald-400 text-[10px] font-bold h-fit">
                    <i class="fas fa-check mr-1"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-rose-500/10 border border-rose-500/20 px-4 py-1.5 rounded-full text-rose-400 text-[10px] font-bold h-fit">
                    <i class="fas fa-exclamation-triangle mr-1"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 overflow-hidden flex-1">
        <!-- Add New Venue Form -->
        <div class="lg:col-span-4 space-y-6">
            <div class="glass-panel p-6 rounded-2xl border-white/5 h-fit">
                <h3 class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <i class="fas fa-plus"></i> Novo Local
                </h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add">
                    <div class="space-y-1">
                        <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Nome da Marca/Local</label>
                        <input type="text" name="name" required class="form-input !bg-white/5 !border-white/5 focus:!border-indigo-500/50" placeholder="Ex: Hilton Garden Inn">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Tipo</label>
                        <select name="type" class="form-input !bg-white/5 !border-white/5 focus:!border-indigo-500/50">
                            <option value="HOTEL">Hotel</option>
                            <option value="FOOD">Alimentação (Restaurante/Fast Food)</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-3 rounded-xl text-[10px] font-bold uppercase tracking-widest transition shadow-lg shadow-indigo-600/20 active:scale-95">
                        Adicionar Local
                    </button>
                </form>
            </div>

            <!-- Summary Card -->
            <div class="glass-panel p-6 rounded-2xl border-white/5">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                        <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Total Hotéis</div>
                        <div class="text-2xl font-bold text-white">
                            <?php 
                                $hotéis = array_filter($venues, fn($v) => $v['type'] === 'HOTEL');
                                echo count($hotéis);
                            ?>
                        </div>
                    </div>
                    <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                        <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Total Food</div>
                        <div class="text-2xl font-bold text-white">
                            <?php 
                                $food = array_filter($venues, fn($v) => $v['type'] === 'FOOD');
                                echo count($food);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Venue List -->
        <div class="lg:col-span-8 flex flex-col overflow-hidden glass-panel rounded-2xl border-white/5">
            <div class="p-4 border-b border-white/5 flex justify-between items-center shrink-0">
                <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Lista de Locais Cadastrados</h3>
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                    <input type="text" id="venueSearch" onkeyup="filterVenues()" placeholder="Pesquisar..." class="bg-white/5 border border-white/5 rounded-full pl-9 pr-4 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500/50 w-48 transition-all">
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto custom-scrollbar p-2 pb-10">
                <table class="w-full text-left" id="venueTable">
                    <thead class="sticky top-0 bg-[#0f111a] z-10">
                        <tr>
                            <th class="px-4 py-3 text-[9px] font-bold text-slate-500 uppercase tracking-widest">Nome</th>
                            <th class="px-4 py-3 text-[9px] font-bold text-slate-500 uppercase tracking-widest">Tipo</th>
                            <th class="px-4 py-3 text-[9px] font-bold text-slate-500 uppercase tracking-widest text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($venues as $venue): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors group">
                                <td class="px-4 py-3 text-xs font-medium text-slate-200"><?php echo htmlspecialchars($venue['name']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider <?php echo $venue['type'] === 'HOTEL' ? 'bg-indigo-500/10 text-indigo-400' : 'bg-amber-500/10 text-amber-500'; ?>">
                                        <?php echo $venue['type']; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" onsubmit="return confirm('Tem certeza que deseja remover este local?');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $venue['id']; ?>">
                                        <button type="submit" class="text-slate-600 hover:text-rose-500 transition-colors p-1.5 hover:bg-rose-500/10 rounded-lg">
                                            <i class="fas fa-trash-alt text-xs"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function filterVenues() {
    const input = document.getElementById('venueSearch');
    const filter = input.value.toUpperCase();
    const table = document.getElementById('venueTable');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const tdName = tr[i].getElementsByTagName('td')[0];
        const tdType = tr[i].getElementsByTagName('td')[1];
        if (tdName || tdType) {
            const txtName = tdName.textContent || tdName.innerText;
            const txtType = tdType.textContent || tdType.innerText;
            if (txtName.toUpperCase().indexOf(filter) > -1 || txtType.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}
</script>

<?php include '../includes/layout_footer.php'; ?>
