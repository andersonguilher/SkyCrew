<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Fetch All Reports (Approved, Rejected, etc.)
$reports = $pdo->query("
    SELECT fr.*, p.name as pilot_name, p.current_base, f.flight_number, f.dep_icao, f.arr_icao 
    FROM flight_reports fr
    JOIN pilots p ON fr.pilot_id = p.id
    JOIN roster_assignments r ON fr.roster_id = r.id
    JOIN flights_master f ON r.flight_id = f.id
    ORDER BY fr.submitted_at DESC
    LIMIT 50
")->fetchAll();

$pageTitle = "Logbook Global - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden">
    <div class="flex justify-between items-center shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-book text-indigo-400"></i> Logbook Global
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Histórico Completo de Operações (Últimos 50 PIREPs)</p>
        </div>
        <div class="bg-white/5 border border-white/10 px-4 py-2 rounded-2xl flex items-center gap-4">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Registrado</span>
            <span class="text-xl font-bold text-indigo-400"><?php echo count($reports); ?></span>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto space-y-4 pr-2 custom-scrollbar">
        <?php if (empty($reports)): ?>
            <div class="glass-panel p-16 rounded-3xl text-center flex flex-col items-center justify-center space-y-4">
                <div class="w-20 h-20 rounded-full bg-slate-800 flex items-center justify-center text-slate-500 text-3xl">
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Sem Histórico</h3>
                    <p class="text-sm text-slate-500">Nenhum voo foi registrado no logbook global ainda.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($reports as $r): ?>
                <a href="flight_log.php?id=<?php echo $r['id']; ?>" class="glass-panel p-6 rounded-3xl border-l-4 <?php echo $r['status'] == 'Approved' ? 'border-emerald-500' : ($r['status'] == 'Rejected' ? 'border-rose-500' : 'border-amber-500'); ?> flex flex-col lg:flex-row gap-6 hover:bg-white/5 transition group cursor-pointer">
                    <div class="flex-1 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-slate-800 border border-white/10 flex items-center justify-center text-slate-400 overflow-hidden">
                                     <i class="fas fa-user-pilot"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-white"><?php echo htmlspecialchars($r['pilot_name']); ?></h4>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-slate-500 uppercase font-bold tracking-tighter">PIREP #<?php echo $r['id']; ?></span>
                                        <span class="w-1 h-1 rounded-full bg-slate-700"></span>
                                        <span class="text-[10px] <?php echo $r['status'] === 'Approved' ? 'text-emerald-400' : 'text-rose-400'; ?> font-bold uppercase"><?php echo $r['status']; ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right flex flex-col">
                                <span class="text-xl font-bold text-indigo-400 font-mono"><?php echo $r['flight_number']; ?></span>
                                <div class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                                    <span><?php echo $r['dep_icao']; ?></span>
                                    <i class="fas fa-plane text-[8px]"></i>
                                    <span><?php echo $r['arr_icao']; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                            <div class="bg-white/2 border border-white/5 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Duração</p>
                                <p class="text-white font-bold"><?php echo $r['flight_time']; ?>h</p>
                            </div>
                            <div class="bg-white/2 border border-white/5 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Combustível</p>
                                <p class="text-white font-bold"><?php echo number_format($r['fuel_used'], 0, ',', '.'); ?> kg</p>
                            </div>
                            <div class="bg-white/2 border border-white/5 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Landing Rate</p>
                                <p class="font-bold <?php echo $r['landing_rate'] < -500 ? 'text-rose-400' : 'text-emerald-400'; ?>">
                                    <?php echo $r['landing_rate']; ?> fpm
                                </p>
                            </div>
                            <div class="bg-indigo-500/10 border border-indigo-500/20 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-indigo-400 uppercase tracking-widest mb-1">Score</p>
                                <p class="text-white font-bold"><?php echo $r['points']; ?> pts</p>
                            </div>
                            <div class="bg-white/2 border border-white/5 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Data</p>
                                <p class="text-white font-bold text-[11px]"><?php echo date('d/m/Y H:i', strtotime($r['submitted_at'])); ?> Z</p>
                            </div>
                        </div>

                        <?php if ($r['comments']): ?>
                            <div class="bg-white/2 p-3 rounded-2xl italic text-[11px] text-slate-400 border border-white/5 flex items-start gap-3">
                                <i class="fas fa-comment shadow-sm"></i>
                                "<?php echo htmlspecialchars($r['comments']); ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>