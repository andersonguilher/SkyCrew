<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../includes/ScheduleMatcher.php';

requireRole('pilot');
$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo);

// Initialize Matcher
$start_date = date('Y-m-d');
$end_date = date('Y-m-d', strtotime('+7 days'));

// Handle Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $matcher = new ScheduleMatcher($pdo);
    $schedule = $matcher->generateRoster($pilotId, $start_date, $end_date);
    $count = count($schedule);

    if ($count > 0) {
        $_SESSION['flash_msg'] = ["type" => "success", "text" => "Escala gerada com sucesso! $count voos atribuídos."];
    } else {
        $_SESSION['flash_msg'] = ["type" => "error", "text" => "Nenhum voo compatível encontrado saindo de sua base atual."];
    }

    header("Location: dashboard.php");
    exit;
}

// Handle Actions
if (isset($_GET['action']) && isset($_GET['roster_id'])) {
    $status = $_GET['action'] == 'accept' ? 'Accepted' : 'Rejected';
    $stmt = $pdo->prepare("UPDATE roster_assignments SET status = ? WHERE id = ? AND pilot_id = ?");
    $stmt->execute([$status, $_GET['roster_id'], $pilotId]);
    $_SESSION['flash_msg'] = ["type" => "success", "text" => "Operação realizada com sucesso."];
    header("Location: dashboard.php");
    exit;
}

// Fetch Pilot Data Enriched
$stmt = $pdo->prepare("SELECT p.*, r.pay_rate, r.image_url as rank_image FROM pilots p LEFT JOIN ranks r ON p.rank = r.rank_name WHERE p.id = ?");
$stmt->execute([$pilotId]);
$pilot = $stmt->fetch();

if (!$pilot)
    die("Perfil não encontrado.");

// Calculate Progress to Next Rank
$stmt = $pdo->prepare("SELECT * FROM ranks WHERE min_hours > ? ORDER BY min_hours ASC LIMIT 1");
$stmt->execute([$pilot['total_hours']]);
$nextRank = $stmt->fetch();

if ($nextRank) {
    $hoursNeeded = $nextRank['min_hours'] - $pilot['total_hours'];
    $rankProgress = ($pilot['total_hours'] / $nextRank['min_hours']) * 100;
} else {
    $rankProgress = 100;
    $hoursNeeded = 0;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM pilot_preferences WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
$hasPreferences = $stmt->fetchColumn() > 0;

// Fetch Roster
$stmt = $pdo->prepare("
    SELECT r.id as roster_id, r.flight_date, r.status, f.* 
    FROM roster_assignments r 
    JOIN flights_master f ON r.flight_id = f.id 
    WHERE r.pilot_id = ? 
    ORDER BY r.flight_date ASC, f.dep_time ASC
");
$stmt->execute([$pilotId]);
$roster = $stmt->fetchAll();

$pageTitle = "Dashboard - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden">
    <!-- Welcome Header -->
    <div class="flex items-center justify-between shrink-0">
        <div class="flex items-center gap-6">
            <div class="relative">
                <div class="w-20 h-20 rounded-3xl border-2 border-indigo-500/30 p-1.5 glass-panel">
                    <div class="w-full h-full rounded-2xl overflow-hidden bg-slate-800 flex items-center justify-center">
                        <?php if (!empty($pilot['profile_image'])): ?>
                            <img src="<?php echo $pilot['profile_image']; ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <i class="fas fa-user-pilot text-slate-600 text-3xl"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-emerald-500 border-4 border-[#0c0e17] flex items-center justify-center" title="Online">
                    <div class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></div>
                </div>
            </div>
            <div>
                <h1 class="text-3xl font-black text-white tracking-tight">Bem-vindo, <span class="text-indigo-400"><?php echo htmlspecialchars(explode(' ', $pilot['name'])[0]); ?></span></h1>
                <p class="text-slate-500 text-[10px] font-bold uppercase tracking-[0.3em] flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span> Comandante em Operação
                </p>
            </div>
        </div>
        <div class="flex gap-4">
            <div class="glass-panel px-6 py-3 rounded-2xl border border-white/5 text-right">
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Último Login</p>
                <p class="text-xs font-mono text-indigo-300"><?php echo date('H:i'); ?> <span class="text-slate-500">Local</span></p>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 shrink-0">
        <!-- Rank Card -->
        <div class="glass-panel p-6 rounded-3xl border-l-4 border-indigo-500 overflow-hidden relative">
            <div class="absolute -right-4 -bottom-4 opacity-5 transform rotate-12">
                <i class="fas fa-medal text-8xl"></i>
            </div>
            <div class="relative z-10">
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest"><?php echo $pilot['rank']; ?></p>
                <h3 class="text-2xl font-bold text-white mt-1"><?php echo number_format($pilot['total_hours'], 1); ?> <span class="text-xs text-slate-500">h</span></h3>
                <div class="mt-4 space-y-1">
                    <div class="flex justify-between text-[9px] font-bold text-slate-400 uppercase tracking-tighter">
                        <span>Progresso Rank</span>
                        <span><?php echo number_format($rankProgress, 0); ?>%</span>
                    </div>
                    <div class="w-full bg-white/5 h-1.5 rounded-full overflow-hidden border border-white/5">
                        <div class="h-full bg-indigo-500 shadow-[0_0_8px_rgba(99,102,241,0.5)]" style="width: <?php echo min(100, $rankProgress); ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wallet Card -->
        <div class="glass-panel p-6 rounded-3xl border-l-4 border-emerald-500 shadow-xl shadow-emerald-500/5">
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Saldo Disponível</p>
            <h3 class="text-2xl font-bold text-emerald-400 mt-1"><?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?> <?php echo number_format($pilot['balance'] ?? 0, 2, ',', '.'); ?></h3>
            <div class="flex items-center gap-2 mt-2">
                <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded-full">
                    <i class="fas fa-hand-holding-usd mr-1"></i> <?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?><?php echo number_format($pilot['pay_rate'] ?? 15, 2); ?>/h
                </span>
            </div>
        </div>

        <!-- Base Card -->
        <div class="glass-panel p-6 rounded-3xl border-l-4 border-blue-500">
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Base de Operações</p>
            <h3 class="text-2xl font-bold text-white mt-1"><?php echo $pilot['current_base']; ?></h3>
            <p class="text-[9px] text-blue-400 font-bold mt-1 uppercase tracking-tighter"><i class="fas fa-map-marker-alt mr-1"></i> Hub Ativo</p>
        </div>

        <!-- Schedule CTA -->
        <div class="glass-panel p-2 rounded-3xl bg-indigo-600/10 border border-indigo-500/20 group hover:border-indigo-500/50 transition-all cursor-pointer overflow-hidden">
            <form method="POST" class="h-full">
                <button type="submit" name="generate" class="w-full h-full flex flex-col items-center justify-center space-y-2 p-4">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-500 shadow-lg shadow-indigo-500/40 flex items-center justify-center text-white group-hover:scale-110 transition-transform">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <span class="text-[10px] font-bold text-white uppercase tracking-widest">Gerar Escala PBS</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="flex-1 grid grid-cols-1 lg:grid-cols-12 gap-6 overflow-hidden">
        <!-- Roster Column -->
        <div class="lg:col-span-8 flex flex-col space-y-4 overflow-hidden">
            <div class="flex justify-between items-center">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-tasks text-indigo-400 text-sm"></i> Escala de Voo
                </h2>
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest"><?php echo count($roster); ?> Trechos</span>
            </div>

            <div class="flex-1 overflow-y-auto space-y-4 pr-2 custom-scrollbar">
                <?php if (isset($_SESSION['flash_msg'])): ?>
                    <div class="glass-panel border-l-4 border-indigo-500 px-6 py-3 rounded-2xl text-indigo-300 text-[11px] font-bold flex items-center gap-3">
                        <i class="fas fa-info-circle"></i> <?php echo $_SESSION['flash_msg']['text']; ?>
                    </div>
                    <?php unset($_SESSION['flash_msg']); ?>
                <?php endif; ?>

                <?php if (empty($roster)): ?>
                    <div class="glass-panel p-16 rounded-3xl text-center flex flex-col items-center justify-center space-y-4">
                        <div class="w-20 h-20 rounded-full bg-white/5 flex items-center justify-center text-slate-600 text-3xl">
                            <i class="fas fa-calendar-times"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white">Nenhum voo escalado</h3>
                            <p class="text-sm text-slate-500">Use o botão acima para gerar sua escala semanal.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($roster as $flight): ?>
                        <?php
                        $statusColor = 'amber';
                        $statusIcon = 'clock';
                        $statusText = 'SUGESTÃO';
                        
                        if ($flight['status'] == 'Accepted') { $statusColor = 'emerald'; $statusIcon = 'check-circle'; $statusText = 'CONFIRMADO'; }
                        elseif ($flight['status'] == 'Flown') { $statusColor = 'blue'; $statusIcon = 'plane-arrival'; $statusText = 'CONCLUÍDO'; }
                        elseif ($flight['status'] == 'Rejected') { $statusColor = 'rose'; $statusIcon = 'times-circle'; $statusText = 'RECUSADO'; }
                        ?>
                        <div class="glass-panel rounded-3xl overflow-hidden border-l-4 border-<?php echo $statusColor; ?>-500 group transition hover:bg-white/5">
                            <div class="bg-white/5 px-6 py-2 flex justify-between items-center border-b border-white/5">
                                <span class="text-[9px] font-bold text-slate-400 tracking-widest uppercase">
                                    <?php echo date('D, d \d\e M', strtotime($flight['flight_date'])); ?>
                                </span>
                                <span class="text-[9px] font-bold text-<?php echo $statusColor; ?>-400 tracking-widest uppercase flex items-center gap-1">
                                    <i class="fas fa-<?php echo $statusIcon; ?> text-[8px]"></i> <?php echo $statusText; ?>
                                </span>
                            </div>
                            <div class="p-6 flex flex-col md:flex-row items-center gap-8">
                                <div class="flex items-center gap-4 shrink-0">
                                    <div class="w-12 h-12 rounded-2xl bg-slate-800 flex items-center justify-center flex-col border border-white/10">
                                        <span class="text-[9px] text-slate-500 font-bold leading-none uppercase"><?php echo substr($flight['flight_number'], 0, 2); ?></span>
                                        <span class="text-sm font-bold text-indigo-400"><?php echo substr($flight['flight_number'], 2); ?></span>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-white"><?php echo $flight['aircraft_type']; ?></h4>
                                        <span class="text-[9px] text-slate-500 font-bold uppercase tracking-tighter">Ikaros Dispatch System</span>
                                    </div>
                                </div>

                                <div class="flex-1 flex items-center justify-center gap-4">
                                    <div class="text-center">
                                        <p class="text-xl font-bold text-white"><?php echo $flight['dep_icao']; ?></p>
                                        <span class="text-[10px] text-slate-500 font-mono"><?php echo substr($flight['dep_time'], 0, 5); ?>Z</span>
                                    </div>
                                    <div class="flex-1 max-w-[120px] flex flex-col items-center gap-1 px-2 relative">
                                        <div class="w-full h-px bg-white/10 relative border-b border-dashed border-white/20">
                                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-indigo-400 group-hover:left-[90%] transition-all duration-1000">
                                                <i class="fas fa-plane text-xs"></i>
                                            </div>
                                        </div>
                                        <span class="text-[9px] font-bold text-slate-600 uppercase tracking-widest mt-1">
                                            <?php echo floor($flight['duration_minutes']/60); ?>h <?php echo $flight['duration_minutes']%60; ?>m
                                        </span>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-xl font-bold text-white"><?php echo $flight['arr_icao']; ?></p>
                                        <span class="text-[10px] text-slate-500 font-mono"><?php echo substr($flight['arr_time'], 0, 5); ?>Z</span>
                                    </div>
                                </div>

                                <div class="flex gap-2 shrink-0">
                                    <?php if ($flight['status'] == 'Suggested'): ?>
                                        <a href="?action=accept&roster_id=<?php echo $flight['roster_id']; ?>" class="w-10 h-10 glass-panel rounded-xl flex items-center justify-center text-emerald-400 hover:bg-emerald-500/10 transition border-emerald-500/20">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <a href="?action=reject&roster_id=<?php echo $flight['roster_id']; ?>" class="w-10 h-10 glass-panel rounded-xl flex items-center justify-center text-rose-400 hover:bg-rose-500/10 transition border-rose-500/20">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php elseif ($flight['status'] == 'Accepted'): ?>
                                        <a href="briefing.php?flight_id=<?php echo $flight['roster_id']; ?>" class="btn-glow px-6 py-2 text-[10px] uppercase font-bold tracking-widest flex items-center gap-2">
                                            <i class="fas fa-file-invoice"></i> Briefing
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="lg:col-span-4 space-y-6">
            <div class="glass-panel p-6 rounded-3xl flex flex-col gap-4">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-400"></i> SkyOS Notícias
                </h3>
                <div class="space-y-4">
                    <div class="border-b border-white/5 pb-4 last:border-0 last:pb-0">
                        <span class="text-[9px] font-bold text-indigo-400 uppercase tracking-widest">Sistema PBS</span>
                        <p class="text-xs text-white/80 mt-1 leading-relaxed">O algoritmo PBS foi atualizado para priorizar conexões com tempo de solo inferior a 60 minutos.</p>
                    </div>
                </div>
            </div>

            <div class="glass-panel p-6 rounded-3xl flex flex-col gap-4 bg-indigo-600/5 border-indigo-500/20 ring-1 ring-indigo-500/10">
                <h3 class="text-xs font-bold text-indigo-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-shield-alt"></i> Pilot Conduct
                </h3>
                <ul class="text-[10px] text-slate-400 space-y-3 font-bold uppercase tracking-tight">
                    <li class="flex items-start gap-2"><i class="fas fa-chevron-right text-[8px] mt-1 text-indigo-500"></i> Voar na rede (VATSIM/IVAO) é encorajado.</li>
                    <li class="flex items-start gap-2"><i class="fas fa-chevron-right text-[8px] mt-1 text-indigo-500"></i> Landing rates acima de 600fpm requerem revisão técnica.</li>
                    <li class="flex items-start gap-2"><i class="fas fa-chevron-right text-[8px] mt-1 text-indigo-500"></i> Mantenha o ACARS conectado durante todo o voo.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>