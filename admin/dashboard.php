<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Fetch Stats
try {
    $stats = [
        'pilots' => $pdo->query("SELECT COUNT(*) FROM pilots")->fetchColumn(),
        'flights' => $pdo->query("SELECT COUNT(*) FROM flights_master")->fetchColumn(),
        'rosters_pending' => $pdo->query("SELECT COUNT(*) FROM roster_assignments WHERE status='Suggested'")->fetchColumn(),
    ];

    // Fetch recent rosters
    $recentRosters = $pdo->query("
        SELECT r.*, p.name as pilot_name, f.flight_number, f.dep_icao, f.arr_icao 
        FROM roster_assignments r
        JOIN pilots p ON r.pilot_id = p.id
        JOIN flights_master f ON r.flight_id = f.id
        ORDER BY r.assigned_at DESC LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    $stats = ['pilots' => 0, 'flights' => 0, 'rosters_pending' => 0];
    $recentRosters = [];
    $error = $e->getMessage();
}

$pageTitle = "Painel Admin - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="scrollable-panel space-y-8">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="glass-panel p-6 rounded-3xl border-l-4 border-indigo-500 relative overflow-hidden group hover:bg-white/5 transition">
            <div class="absolute right-0 top-0 opacity-10 transform translate-x-4 -translate-y-4 group-hover:scale-110 transition duration-500">
                <i class="fas fa-users text-8xl text-white"></i>
            </div>
            <div class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Total de Pilotos</div>
            <div class="text-4xl font-black text-white mt-2"><?php echo $stats['pilots']; ?></div>
            <div class="mt-4 flex items-center gap-2 text-[10px] text-indigo-400 font-bold">
                <i class="fas fa-chevron-right"></i> GERENCIAR TRIPULAÇÃO
            </div>
        </div>
        
        <div class="glass-panel p-6 rounded-3xl border-l-4 border-indigo-500 relative overflow-hidden group hover:bg-white/5 transition">
            <div class="absolute right-0 top-0 opacity-10 transform translate-x-4 -translate-y-4 group-hover:scale-110 transition duration-500">
                <i class="fas fa-route text-8xl text-white"></i>
            </div>
            <div class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Rotas Ativas</div>
            <div class="text-4xl font-black text-white mt-2"><?php echo $stats['flights']; ?></div>
            <div class="mt-4 flex items-center gap-2 text-[10px] text-indigo-400 font-bold">
                <i class="fas fa-chevron-right"></i> MALHA OPERACIONAL
            </div>
        </div>

        <div class="glass-panel p-6 rounded-3xl border-l-4 border-indigo-500 relative overflow-hidden group hover:bg-white/5 transition">
            <div class="absolute right-0 top-0 opacity-10 transform translate-x-4 -translate-y-4 group-hover:scale-110 transition duration-500">
                <i class="fas fa-clock text-8xl text-white"></i>
            </div>
            <div class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Aprovações Pendentes</div>
            <div class="text-4xl font-black text-white mt-2"><?php echo $stats['rosters_pending']; ?></div>
            <div class="mt-4 flex items-center gap-2 text-[10px] text-amber-400 font-bold">
                <i class="fas fa-exclamation-circle"></i> AGUARDANDO PILOTOS
            </div>
        </div>
    </div>

    <div class="glass-panel rounded-3xl overflow-hidden flex flex-col">
        <div class="p-6 border-b border-white/10 flex justify-between items-center bg-white/5">
            <h2 class="section-title mb-0"><i class="fas fa-history text-indigo-400"></i> Atribuições de Escala Recentes</h2>
            <a href="reports.php" class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest hover:text-white transition">Ver Relatórios</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-[12px]">
                <thead class="bg-white/5">
                    <tr class="text-[10px] uppercase tracking-widest text-slate-500 font-bold">
                        <th class="px-8 py-4">Piloto</th>
                        <th class="px-8 py-4">Voo</th>
                        <th class="px-8 py-4">Data</th>
                        <th class="px-8 py-4">Rota</th>
                        <th class="px-8 py-4 text-right pr-12">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($recentRosters as $r): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-8 py-4 font-bold text-white"><?php echo htmlspecialchars($r['pilot_name']); ?></td>
                            <td class="px-8 py-4 font-mono font-bold text-indigo-400"><?php echo $r['flight_number']; ?></td>
                            <td class="px-8 py-4 text-slate-400"><?php echo date('d/m/Y', strtotime($r['flight_date'])); ?></td>
                            <td class="px-8 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-200"><?php echo $r['dep_icao']; ?></span>
                                    <i class="fas fa-arrow-right text-[10px] text-slate-600"></i>
                                    <span class="text-slate-200"><?php echo $r['arr_icao']; ?></span>
                                </div>
                            </td>
                            <td class="px-8 py-4 text-right pr-12">
                                <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-tighter
                                <?php
                                echo match ($r['status']) {
                                    'Suggested' => 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
                                    'Accepted' => 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
                                    'Rejected' => 'bg-rose-500/10 text-rose-400 border border-rose-500/20',
                                    'Flown' => 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
                                    default => 'bg-slate-500/10 text-slate-400 border border-slate-500/20'
                                };
                                ?>">
                                    <?php
                                    echo match ($r['status']) {
                                        'Suggested' => 'Sugerido',
                                        'Accepted' => 'Aceito',
                                        'Rejected' => 'Rejeitado',
                                        'Flown' => 'Voado',
                                        default => $r['status']
                                    };
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>