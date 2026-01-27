<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../includes/PayrollSystem.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo);
$payroll = new PayrollSystem($pdo);

// Fetch Pilot Name
if (!isset($_SESSION['name'])) {
    $stmt = $pdo->prepare("SELECT name FROM pilots WHERE id = ?");
    $stmt->execute([$pilotId]);
    $pilotName = $stmt->fetchColumn();
} else {
    $pilotName = $_SESSION['name'];
}

// Generate for Current Month
$paycheck = $payroll->generatePaycheck($pilotId, date('Y-m'));

function money($val) {
    global $sysSettings;
    return ($sysSettings['currency_symbol'] ?? 'R$') . ' ' . number_format($val, 2, ',', '.');
}

$pageTitle = "Holerite - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden max-w-5xl mx-auto w-full">
    <div class="flex justify-between items-end shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-wallet text-indigo-400"></i> Demonstrativo de Pagamento
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Recibo de Vencimentos - <?php echo date('M/Y'); ?></p>
        </div>
        <div class="flex gap-2 no-print">
            <button onclick="window.print()" class="bg-indigo-500 hover:bg-indigo-600 px-4 py-2 rounded-2xl text-[10px] font-bold text-white uppercase tracking-widest transition flex items-center gap-2">
                <i class="fas fa-print"></i> Imprimir PDF
            </button>
            <a href="dashboard.php" class="bg-white/5 border border-white/10 px-4 py-2 rounded-2xl text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:bg-white/10 transition">
                <i class="fas fa-arrow-left"></i> Painel
            </a>
        </div>
    </div>

    <!-- Main Financial Card -->
    <div class="glass-panel rounded-3xl overflow-hidden flex flex-col flex-1 border border-white/10 bg-gradient-to-br from-slate-900/50 to-indigo-900/20">
        <!-- Dashboard Header -->
        <div class="p-10 grid grid-cols-1 md:grid-cols-3 gap-8 border-b border-white/5 bg-white/5">
            <div class="space-y-4 text-center md:text-left">
                <div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Colaborador</p>
                    <h3 class="text-xl font-bold text-white"><?php echo $pilotName; ?></h3>
                    <p class="text-[10px] text-indigo-400 font-bold uppercase tracking-widest mt-1">ID: <?php echo str_pad($pilotId, 4, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>
            <div class="space-y-4 text-center">
                <div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Cargo Operacional</p>
                    <h3 class="text-xl font-bold text-white"><?php echo $paycheck['rank'] ?? 'Cadet'; ?></h3>
                    <p class="text-[10px] text-emerald-400 font-bold uppercase tracking-widest mt-1">Status: Ativo</p>
                </div>
            </div>
            <div class="space-y-4 text-center md:text-right">
                <div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Líquido a Receber</p>
                    <h3 class="text-3xl font-black text-emerald-400"><?php echo money($paycheck['total_net_pay']); ?></h3>
                </div>
            </div>
        </div>

        <!-- Breakdown Row -->
        <div class="p-10 flex-1 overflow-y-auto custom-scrollbar">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                <!-- Vencimentos -->
                <div class="space-y-6">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-plus-circle text-emerald-500"></i> Proventos e Vencimentos
                    </h4>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-white/5 rounded-2xl border border-white/5 group hover:bg-white/10 transition">
                            <div>
                                <p class="text-sm font-bold text-white">Salário Base</p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-tighter">Fixo Mensal</p>
                            </div>
                            <span class="font-bold text-emerald-400"><?php echo money($paycheck['base_salary']); ?></span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-white/5 rounded-2xl border border-white/5 group hover:bg-white/10 transition">
                            <div>
                                <p class="text-sm font-bold text-white">Adicional de Voo</p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-tighter"><?php echo number_format($paycheck['hours_flown'], 1); ?> horas realizadas</p>
                            </div>
                            <span class="font-bold text-emerald-400"><?php echo money($paycheck['flight_pay']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Descontos -->
                <div class="space-y-6">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-minus-circle text-rose-500"></i> Deduções e Descontos
                    </h4>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-white/5 rounded-2xl border border-white/5 group hover:bg-white/10 transition">
                            <div>
                                <p class="text-sm font-bold text-white">Contribuição Previdenciária</p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-tighter">INSS (11%)</p>
                            </div>
                            <span class="font-bold text-rose-400">- <?php echo money($paycheck['pension_deduction']); ?></span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-white/5 rounded-2xl border border-white/5 group hover:bg-white/10 transition">
                            <div>
                                <p class="text-sm font-bold text-white">IRRF Retido</p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-tighter">Imposto de Renda (15%)</p>
                            </div>
                            <span class="font-bold text-rose-400">- <?php echo money($paycheck['tax_deduction']); ?></span>
                        </div>
                        <?php if ($paycheck['per_diem_deduction'] > 0): ?>
                        <div class="flex justify-between items-center p-4 bg-amber-500/5 rounded-2xl border border-amber-500/10 group hover:bg-amber-500/10 transition">
                            <div>
                                <p class="text-sm font-bold text-amber-500">Multa de Inatividade</p>
                                <p class="text-[10px] text-amber-600/60 uppercase font-bold tracking-tighter"><?php echo round($paycheck['idle_days'], 1); ?> dias ociosos detectados</p>
                            </div>
                            <span class="font-bold text-rose-400">- <?php echo money($paycheck['per_diem_deduction']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Footer -->
        <div class="px-10 py-6 bg-black/40 flex justify-between items-center">
            <div class="text-[9px] font-bold text-slate-600 uppercase tracking-[0.2em]">
                Autenticação Digital: <?php echo md5($pilotId . date('Y-m') . $paycheck['total_net_pay']); ?>
            </div>
            <div class="text-[9px] font-bold text-slate-600 uppercase tracking-[0.2em]">
                Documento Gerado por Ikaros Finance em <?php echo date('d/m/Y H:i'); ?>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; color: black !important; }
    .glass-panel { border: none !important; background: white !important; box-shadow: none !important; }
    .text-white { color: black !important; }
    .text-slate-500, .text-slate-400 { color: #666 !important; }
    .bg-white\/5 { background: #f5f5f5 !important; }
    .border-white\/5 { border-color: #eee !important; }
    .bg-black\/40 { background: #eee !important; }
    .immersive-bg, .top-bar { display: none !important; }
    .content-area { padding: 0 !important; overflow: visible !important; }
    .page-container { height: auto !important; }
}
</style>

<?php include '../includes/layout_footer.php'; ?>