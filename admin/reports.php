<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Handle actions
$success = '';
$error = '';

if (isset($_POST['approve_id'])) {
    $reportId = $_POST['approve_id'];

    try {
        $pdo->beginTransaction();

        // 1. Get Report Details
        $stmt = $pdo->prepare("SELECT * FROM flight_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();

        if ($report && $report['status'] == 'Pending') {
            // 2. Update Report Status
            $stmt = $pdo->prepare("UPDATE flight_reports SET status = 'Approved' WHERE id = ?");
            $stmt->execute([$reportId]);

            // 3. Update Pilot Hours, Balance and Rank
            $stmt = $pdo->prepare("SELECT p.*, r.pay_rate FROM pilots p LEFT JOIN ranks r ON p.rank = r.rank_name WHERE p.id = ?");
            $stmt->execute([$report['pilot_id']]);
            $pilotData = $stmt->fetch();

            $earnings = $report['pilot_pay'];
            $newHours = $pilotData['total_hours'] + $report['flight_time'];
            $newPoints = $pilotData['points'] + $report['points'];

            // Check for Promotion
            $nextRankStmt = $pdo->prepare("SELECT * FROM ranks WHERE min_hours <= ? ORDER BY min_hours DESC LIMIT 1");
            $nextRankStmt->execute([$newHours]);
            $newRankData = $nextRankStmt->fetch();
            $newRank = $newRankData ? $newRankData['rank_name'] : $pilotData['rank'];

            $updateStmt = $pdo->prepare("UPDATE pilots SET total_hours = ?, balance = balance + ?, `rank` = ?, points = ? WHERE id = ?");
            $updateStmt->execute([$newHours, $earnings, $newRank, $newPoints, $report['pilot_id']]);

            // 4. Update Aircraft Location and Roster Status
            $stmt = $pdo->prepare("
                SELECT f.aircraft_id, f.arr_icao, r.id as roster_id 
                FROM roster_assignments r
                JOIN flights_master f ON r.flight_id = f.id
                WHERE r.id = ?
            ");
            $stmt->execute([$report['roster_id']]);
            $flightInfo = $stmt->fetch();

            if ($flightInfo && $flightInfo['aircraft_id']) {
                // Update Aircraft Position
                $stmt = $pdo->prepare("UPDATE fleet SET current_icao = ? WHERE id = ?");
                $stmt->execute([$flightInfo['arr_icao'], $flightInfo['aircraft_id']]);

                // Update Roster Status to Flown
                $stmt = $pdo->prepare("UPDATE roster_assignments SET status = 'Flown' WHERE id = ?");
                $stmt->execute([$flightInfo['roster_id']]);
            }

            $pdo->commit();
            $success = "Relatório aprovado e horas creditadas.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Erro: " . $e->getMessage();
    }
}

if (isset($_POST['reject_id'])) {
    $reportId = $_POST['reject_id'];
    try {
        $stmt = $pdo->prepare("UPDATE flight_reports SET status = 'Rejected' WHERE id = ?");
        $stmt->execute([$reportId]);
        $success = "Relatório rejeitado.";
    } catch (Exception $e) {
        $error = "Erro ao rejeitar: " . $e->getMessage();
    }
}

// Fetch Pending Reports
$reports = $pdo->query("
    SELECT fr.*, p.name as pilot_name, p.current_base, f.flight_number, f.dep_icao, f.arr_icao 
    FROM flight_reports fr
    JOIN pilots p ON fr.pilot_id = p.id
    JOIN roster_assignments r ON fr.roster_id = r.id
    JOIN flights_master f ON r.flight_id = f.id
    WHERE fr.status = 'Pending'
    ORDER BY fr.submitted_at ASC
")->fetchAll();

$pageTitle = "Relatórios - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden">
    <div class="flex justify-between items-center shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-file-signature text-indigo-400"></i> Validação Operacional
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Revisão de PIREP Manual</p>
        </div>
        <div class="bg-white/5 border border-white/10 px-4 py-2 rounded-2xl flex items-center gap-4">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Pendentes</span>
            <span class="text-xl font-bold text-indigo-400"><?php echo count($reports); ?></span>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="glass-panel border-l-4 border-emerald-500 px-6 py-4 rounded-2xl text-emerald-400 font-bold animate-pulse shrink-0">
            <i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="glass-panel border-l-4 border-rose-500 px-6 py-4 rounded-2xl text-rose-400 font-bold shrink-0">
            <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="flex-1 overflow-y-auto space-y-4 pr-2 custom-scrollbar">
        <?php if (empty($reports)): ?>
            <div class="glass-panel p-16 rounded-3xl text-center flex flex-col items-center justify-center space-y-4">
                <div class="w-20 h-20 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-400 text-3xl">
                    <i class="fas fa-check"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Malha Limpa</h3>
                    <p class="text-sm text-slate-500">Todos os relatórios foram processados.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($reports as $r): ?>
                <div class="glass-panel p-6 rounded-3xl border-l-4 border-amber-500 flex flex-col lg:flex-row gap-6 hover:bg-white/5 transition group">
                    <div class="flex-1 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-slate-800 border border-white/10 flex items-center justify-center text-slate-400">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-white"><?php echo htmlspecialchars($r['pilot_name']); ?></h4>
                                    <span class="text-[10px] text-slate-500 uppercase font-bold tracking-tighter">Base: <?php echo $r['current_base']; ?></span>
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

                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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
                            <div class="bg-white/2 border border-white/5 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Data/Hora Sub</p>
                                <p class="text-white font-bold text-[11px]"><?php echo date('d/m H:i', strtotime($r['submitted_at'])); ?> Z</p>
                            </div>
                            <div class="bg-indigo-500/10 border border-indigo-500/20 p-3 rounded-2xl">
                                <p class="text-[9px] font-bold text-indigo-400 uppercase tracking-widest mb-1">Score do Voo</p>
                                <p class="text-white font-bold"><?php echo $r['points']; ?> pts</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-emerald-500/5 border border-emerald-500/10 p-3 rounded-2xl">
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Financeiro (Estimado)</span>
                                    <span class="text-[9px] font-bold text-emerald-400 uppercase"><?php echo $r['pax']; ?> PAX</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-400">Receita Bruta:</span>
                                    <span class="text-emerald-400 font-mono font-bold">+ <?php echo number_format($r['revenue'], 2, ',', '.'); ?></span>
                                </div>
                                <div class="flex justify-between text-[11px] mt-1">
                                    <span class="text-slate-400">Total Despesas:</span>
                                    <span class="text-rose-400 font-mono font-bold">- <?php echo number_format($r['fuel_cost'] + $r['maintenance_cost'] + $r['airport_fees'], 2, ',', '.'); ?></span>
                                </div>
                            </div>
                            <div class="bg-blue-500/5 border border-blue-500/10 p-3 rounded-2xl flex flex-col justify-center">
                                <div class="flex justify-between items-center">
                                    <span class="text-[9px] font-bold text-blue-400 uppercase tracking-widest">Pagamento Piloto</span>
                                    <span class="text-white font-mono font-bold text-sm"><?php echo number_format($r['pilot_pay'], 2, ',', '.'); ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if ($r['comments']): ?>
                            <div class="bg-white/2 p-3 rounded-2xl italic text-[11px] text-slate-400 border border-white/5">
                                "<?php echo htmlspecialchars($r['comments']); ?>"
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($r['incidents'])): ?>
                            <div class="bg-rose-500/10 border border-rose-500/20 p-4 rounded-2xl">
                                <p class="text-[9px] font-bold text-rose-400 uppercase tracking-widest mb-2 flex items-center gap-2">
                                    <i class="fas fa-exclamation-circle"></i> Log de Incidentes / Alertas
                                </p>
                                <ul class="text-[11px] text-rose-300/80 space-y-1 ml-4 list-disc">
                                    <?php
                                    $incidents = @json_decode($r['incidents'], true);
                                    if (is_array($incidents)) {
                                        foreach ($incidents as $inc) echo "<li>" . htmlspecialchars($inc) . "</li>";
                                    } else {
                                        echo "<li>" . htmlspecialchars($r['incidents']) . "</li>";
                                    }
                                    ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lg:w-48 flex flex-col gap-3 justify-center shrink-0">
                        <form method="POST">
                            <input type="hidden" name="approve_id" value="<?php echo $r['id']; ?>">
                            <button type="submit" class="w-full py-4 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/20 rounded-2xl font-bold text-xs uppercase tracking-widest transition-all">
                                <i class="fas fa-check-double mr-2"></i> Aprovar
                            </button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="reject_id" value="<?php echo $r['id']; ?>">
                            <button type="submit" onclick="return confirm('Tem certeza que deseja rejeitar este relatório?')" class="w-full py-4 bg-rose-500/5 hover:bg-rose-500/10 text-rose-400 border border-rose-500/10 rounded-2xl font-bold text-xs uppercase tracking-widest transition-all">
                                <i class="fas fa-times mr-2"></i> Rejeitar
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>