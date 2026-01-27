<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo);

if (!isset($_GET['flight_id'])) {
    header("Location: dashboard.php");
    exit;
}

$rosterId = $_GET['flight_id'];

// Fetch Flight Details
$stmt = $pdo->prepare("
    SELECT r.id, r.flight_date, fm.flight_number, fm.dep_icao, fm.arr_icao, 
           fm.dep_time, fm.arr_time, fm.aircraft_type, fm.duration_minutes
    FROM roster_assignments r
    JOIN flights_master fm ON r.flight_id = fm.id
    WHERE r.id = ? AND r.pilot_id = ?
");
$stmt->execute([$rosterId, $pilotId]);
$flight = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flight) {
    die("Voo não encontrado.");
}

// SimBrief URL Construction
$sbUrl = "https://www.simbrief.com/system/dispatch.php?";
$params = [
    'airline' => $sysSettings['va_callsign'],
    'fltnum' => preg_replace('/\D/', '', $flight['flight_number']),
    'type' => $flight['aircraft_type'],
    'orig' => $flight['dep_icao'],
    'dest' => $flight['arr_icao'],
    'date' => date('dMy', strtotime($flight['flight_date'])),
    'deph' => substr($flight['dep_time'], 0, 2),
    'depm' => substr($flight['dep_time'], 3, 2),
    'steh' => floor($flight['duration_minutes'] / 60),
    'stem' => $flight['duration_minutes'] % 60,
];
$dispatchUrl = $sbUrl . http_build_query($params);

$pageTitle = "Briefing " . $flight['flight_number'] . " - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden max-w-5xl mx-auto w-full">
    <div class="flex justify-between items-end shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-file-invoice text-indigo-400"></i> Dossier de Voo
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Briefing Operacional Pré-voo</p>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php" class="bg-white/5 border border-white/10 px-4 py-2 rounded-2xl text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:bg-white/10 transition">
                <i class="fas fa-arrow-left mr-2"></i> Painel
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1 overflow-hidden">
        <!-- Main Briefing View -->
        <div class="lg:col-span-2 space-y-6 overflow-y-auto pr-2 custom-scrollbar">
            <!-- Route Banner -->
            <div class="glass-panel p-10 rounded-3xl relative overflow-hidden bg-gradient-to-br from-indigo-600/20 to-transparent border-indigo-500/20">
                <div class="absolute top-0 right-0 p-8 opacity-10">
                    <i class="fas fa-route text-9xl"></i>
                </div>
                
                <div class="relative z-10 flex items-center justify-between">
                    <div class="text-center">
                        <p class="text-[11px] font-bold text-indigo-400 uppercase tracking-widest mb-1">Origem</p>
                        <h3 class="text-5xl font-black text-white"><?php echo $flight['dep_icao']; ?></h3>
                        <span class="text-sm font-mono text-slate-400"><?php echo substr($flight['dep_time'], 0, 5); ?>Z</span>
                    </div>

                    <div class="flex-1 flex flex-col items-center px-12">
                        <div class="w-full h-px bg-white/20 relative mb-4">
                            <i class="fas fa-plane text-indigo-400 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-2xl"></i>
                        </div>
                        <div class="bg-white/10 px-4 py-1 rounded-full border border-white/10">
                            <span class="text-[10px] font-bold text-white uppercase tracking-widest">
                                ETE: <?php echo floor($flight['duration_minutes']/60); ?>H <?php echo $flight['duration_minutes']%60; ?>M
                            </span>
                        </div>
                    </div>

                    <div class="text-center">
                        <p class="text-[11px] font-bold text-indigo-400 uppercase tracking-widest mb-1">Destino</p>
                        <h3 class="text-5xl font-black text-white"><?php echo $flight['arr_icao']; ?></h3>
                        <span class="text-sm font-mono text-slate-400"><?php echo substr($flight['arr_time'], 0, 5); ?>Z</span>
                    </div>
                </div>
            </div>

            <!-- Dispatch Box -->
            <div class="glass-panel p-8 rounded-3xl border-l-4 border-amber-500 space-y-6">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500 text-xl border border-amber-500/20">
                        <i class="fas fa-satellite-dish"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-lg font-bold text-white">Despacho Eletrônico</h4>
                        <p class="text-sm text-slate-400 leading-relaxed mt-1">Gere seu plano de voo no <strong>SimBrief</strong> para obter os dados de combustível (Block Fuel), payload e a rota computada atualizada (NOTAM/WSR).</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Voo ID</p>
                        <p class="text-xl font-bold text-indigo-400 font-mono"><?php echo $flight['flight_number']; ?></p>
                    </div>
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Equipamento</p>
                        <p class="text-xl font-bold text-white font-mono"><?php echo $flight['aircraft_type']; ?></p>
                    </div>
                </div>

                <a href="<?php echo $dispatchUrl; ?>" target="_blank" class="btn-glow w-full py-5 flex items-center justify-center gap-3 text-sm uppercase tracking-[0.2em]">
                    <i class="fas fa-external-link-alt"></i> Iniciar Despacho SimBrief
                </a>
                
                <p class="text-[10px] text-center text-slate-500 font-bold uppercase">Atenção: O plano deve ser gerado antes da partida.</p>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="space-y-6 shrink-0">
            <div class="glass-panel p-6 rounded-3xl space-y-4">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-400"></i> Check-in Checklist
                </h3>
                <div class="space-y-3">
                    <label class="flex items-center gap-3 p-3 bg-white/5 rounded-2xl border border-white/5 cursor-pointer hover:bg-white/10 transition">
                        <input type="checkbox" class="accent-indigo-500 w-4 h-4">
                        <span class="text-xs text-slate-300 font-bold uppercase tracking-tighter">Reservar aeronave no ACARS</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 bg-white/5 rounded-2xl border border-white/5 cursor-pointer hover:bg-white/10 transition">
                        <input type="checkbox" class="accent-indigo-500 w-4 h-4">
                        <span class="text-xs text-slate-300 font-bold uppercase tracking-tighter">Verificar NOTAMs da Origem</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 bg-white/5 rounded-2xl border border-white/5 cursor-pointer hover:bg-white/10 transition">
                        <input type="checkbox" class="accent-indigo-500 w-4 h-4">
                        <span class="text-xs text-slate-300 font-bold uppercase tracking-tighter">Checar clima no Destino (METAR)</span>
                    </label>
                </div>
            </div>

            <div class="glass-panel p-6 rounded-3xl bg-amber-500/5 border-amber-500/20">
                <h3 class="text-xs font-bold text-amber-500 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle"></i> Regras de Ouro
                </h3>
                <ul class="text-[10px] text-slate-400 space-y-3 font-bold uppercase tracking-tight mt-3">
                    <li class="flex items-start gap-2 italic">Landing lights devem estar ligadas abaixo de 10.000ft.</li>
                    <li class="flex items-start gap-2 italic">Velocidade máxima de 250kts abaixo de 10.000ft.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>