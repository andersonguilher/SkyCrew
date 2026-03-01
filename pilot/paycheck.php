<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../includes/PayrollSystem.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo);
$payroll = new PayrollSystem($pdo);

// Fetch Pilot Info
$stmt = $pdo->prepare("SELECT name, `rank` FROM pilots WHERE id = ?");
$stmt->execute([$pilotId]);
$pilot = $stmt->fetch();
$pilotName = $pilot['name'];

// Generate for Current Month
$paycheck = $payroll->generatePaycheck($pilotId, date('Y-m'));

function money($val) {
    global $sysSettings;
    return ($sysSettings['currency_symbol'] ?? 'R$') . ' ' . number_format($val, 2, ',', '.');
}

$pageTitle = "Holerite - " . ($sysSettings['va_name'] ?? 'SkyCrew');
$extraHead = '
    <style>
        @media print {
            /* Reset layout constraints from layout_header to allow normal document flow for printing */
            body, html, .page-container, .content-area, .custom-scrollbar {
                height: auto !important;
                min-height: auto !important;
                overflow: visible !important;
                background: white !important;
                display: block !important;
            }
            /* Hide UI elements */
            .top-bar, .no-print, .immersive-bg { display: none !important; }
            /* Remove glass effects */
            .glass-panel { background: none !important; border: none !important; box-shadow: none !important; }
            /* Convert text to black for white paper */
            body * { color: black !important; border-color: #ddd !important; text-shadow: none !important; }
            /* Specific fix for watermark to ensure it stays subtle */
            .pointer-events-none { color: rgba(0,0,0,0.05) !important; }
            /* Print boundary */
            .print-border { border: 1px solid #ccc !important; padding: 20px; margin: 0; }
        }
    </style>
';
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden">
    <div class="flex justify-between items-center shrink-0 no-print">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-file-invoice-dollar text-indigo-400"></i> Holerite Eletrônico
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Recibo de Pagamento - <?php echo date('m/Y'); ?></p>
        </div>
        <div class="flex gap-3">
            <button onclick="window.print()" class="bg-indigo-500/10 hover:bg-indigo-500 text-indigo-400 hover:text-white border border-indigo-500/20 px-4 py-2 rounded-2xl text-[10px] font-bold uppercase tracking-widest transition-all">
                <i class="fas fa-print mr-1"></i> Imprimir
            </button>
            <a href="dashboard.php" class="bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white border border-white/10 px-4 py-2 rounded-2xl text-[10px] font-bold uppercase tracking-widest transition-all">
                <i class="fas fa-arrow-left mr-1"></i> Voltar
            </a>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto space-y-4 pr-2 custom-scrollbar print-border">
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
            <!-- Watermark -->
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-[-45deg] text-6xl font-black text-white/[0.02] whitespace-nowrap pointer-events-none select-none z-0">
                SKYCREW VIRTUAL
            </div>
            
            <div class="relative z-10 w-full max-w-4xl mx-auto space-y-8">
                <!-- Header Card -->
                <div class="flex justify-between items-start border-b border-white/10 pb-6">
                    <div>
                        <h1 class="text-2xl font-black text-white tracking-tighter"><?php echo strtoupper($sysSettings['va_name'] ?? 'SKYCREW'); ?></h1>
                        <p class="text-[10px] text-slate-400 uppercase font-bold tracking-widest mt-1">Recibo de Pagamento de Salário</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Mês/Ano Ref.</p>
                        <p class="text-xl font-black text-indigo-400 font-mono"><?php echo date('m/Y'); ?></p>
                    </div>
                </div>

                <!-- Pilot Info -->
                <div class="grid grid-cols-2 gap-6 bg-white/5 border border-white/10 rounded-2xl p-6">
                    <div class="border-r border-white/10 pr-6">
                        <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest mb-1">Colaborador</p>
                        <p class="text-lg font-bold text-white"><?php echo $pilotName; ?></p>
                        <p class="text-[10px] text-slate-400 font-mono mt-1">ID: <?php echo str_pad($pilotId, 4, '0', STR_PAD_LEFT); ?></p>
                    </div>
                    <div class="pl-2">
                        <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest mb-1">Cargo / Patente</p>
                        <p class="text-lg font-bold text-indigo-400"><?php echo strtoupper($paycheck['rank'] ?? 'CADET'); ?></p>
                        <p class="text-[10px] text-slate-400 mt-1">Base: Fixa</p>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-white/20">
                                <th class="py-3 px-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest w-16">Cód.</th>
                                <th class="py-3 px-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Descrição</th>
                                <th class="py-3 px-2 text-center text-[10px] font-bold text-slate-500 uppercase tracking-widest w-24">Ref.</th>
                                <th class="py-3 px-2 text-right text-[10px] font-bold text-emerald-500 uppercase tracking-widest w-32">Vencimentos</th>
                                <th class="py-3 px-2 text-right text-[10px] font-bold text-rose-500 uppercase tracking-widest w-32">Descontos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.05]">
                            <!-- Earnings -->
                            <tr class="hover:bg-white/5 transition-colors group">
                                <td class="py-4 px-2 text-sm text-slate-400 font-mono">001</td>
                                <td class="py-4 px-2 font-bold text-sm text-white">Salário Base</td>
                                <td class="py-4 px-2 text-center text-xs text-slate-500 font-mono">30d</td>
                                <td class="py-4 px-2 text-right text-sm text-emerald-400 font-mono font-bold"><?php echo money($paycheck['base_salary']); ?></td>
                                <td class="py-4 px-2 text-right text-sm text-slate-600 font-mono">-</td>
                            </tr>
                            <tr class="hover:bg-white/5 transition-colors group">
                                <td class="py-4 px-2 text-sm text-slate-400 font-mono">002</td>
                                <td class="py-4 px-2 text-sm text-slate-300">Adicional de Voo</td>
                                <td class="py-4 px-2 text-center text-xs text-slate-400 font-mono"><?php echo number_format($paycheck['hours_flown'], 1); ?>h</td>
                                <td class="py-4 px-2 text-right text-sm text-emerald-400 font-mono font-bold"><?php echo money($paycheck['flight_pay']); ?></td>
                                <td class="py-4 px-2 text-right text-sm text-slate-600 font-mono">-</td>
                            </tr>

                            <!-- Deductions -->
                            <?php if ($paycheck['per_diem_deduction'] > 0): ?>
                                <tr class="bg-rose-500/5 hover:bg-rose-500/10 transition cursor-pointer" onclick="document.getElementById('idle-details').classList.toggle('hidden')">
                                    <td class="py-4 px-2 text-sm text-rose-400/70 font-mono">205</td>
                                    <td class="py-4 px-2 flex items-center text-sm font-bold text-rose-400">
                                        Custo Operacional (Ociosidade)
                                        <i class="fas fa-search-plus ml-2 text-[10px] opacity-70"></i>
                                    </td>
                                    <td class="py-4 px-2 text-center text-xs text-rose-400/80 font-mono"><?php echo round($paycheck['idle_days'], 1); ?>d</td>
                                    <td class="py-4 px-2 text-right text-sm text-slate-600 font-mono">-</td>
                                    <td class="py-4 px-2 text-right text-sm text-rose-400 font-mono font-bold">- <?php echo money($paycheck['per_diem_deduction']); ?></td>
                                </tr>
                                <!-- Hidden Detail Row -->
                                <tr id="idle-details" class="hidden bg-black/20">
                                    <td colspan="5" class="py-4 px-6 border-l-2 border-rose-500/30">
                                        <p class="text-[10px] font-bold text-rose-300 uppercase tracking-widest mb-3 ml-8"><i class="fas fa-info-circle mr-1"></i> Detalhamento de Custos (Dias Ociosos)</p>
                                        <div class="ml-8 space-y-3">
                                            <?php
                                            if (isset($paycheck['idle_details'])) {
                                                foreach ($paycheck['idle_details'] as $detail) {
                                                    echo "<div class='border-l border-white/10 pl-4 py-1.5'>";
                                                    echo "<p class='text-[10px] text-slate-400 font-mono mb-1.5'>De " . htmlspecialchars($detail['start']) . " até " . htmlspecialchars($detail['end']) . " <span class='text-slate-500'>(" . $detail['days'] . " dias)</span></p>";
                                                    echo "<p class='text-xs text-rose-300/80'>" . htmlspecialchars($detail['breakdown']) . "</p>";
                                                    echo "</div>";
                                                }
                                            }
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="flex flex-col md:flex-row justify-between items-end border-t border-white/20 pt-8 gap-8">
                    <div class="w-full md:w-1/2 flex flex-col items-center md:items-start">
                        <div class="w-64 border-b border-white/20 pb-2 text-center">
                            <span class="text-[9px] text-slate-500 font-bold uppercase tracking-widest">Assinatura Eletrônica do Colaborador</span>
                        </div>
                        <p class="text-[8px] text-slate-600 uppercase tracking-tight mt-3 max-w-[250px] text-center md:text-left">
                            Reconheço a exatidão deste demonstrativo e os valores nele depositados.
                        </p>
                    </div>

                    <div class="w-full md:w-1/2 space-y-3">
                        <div class="flex justify-between items-center px-4">
                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Total Vencimentos</span>
                            <span class="text-sm font-mono text-emerald-400"><?php echo money($paycheck['base_salary'] + $paycheck['flight_pay']); ?></span>
                        </div>
                        <div class="flex justify-between items-center px-4">
                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Total Descontos</span>
                            <span class="text-sm font-mono text-rose-400">- <?php echo money($paycheck['per_diem_deduction']); ?></span>
                        </div>
                        <div class="mt-4 bg-indigo-500/10 border border-indigo-500/20 p-4 rounded-2xl flex justify-between items-center shadow-[0_0_15px_rgba(99,102,241,0.1)]">
                            <span class="text-[11px] font-black text-indigo-400 uppercase tracking-widest">Valor Líquido</span>
                            <span class="text-2xl font-black text-white font-mono"><?php echo money($paycheck['total_net_pay']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Footer Authenticity -->
                <div class="pt-6 mt-6 border-t border-white/5 text-center">
                    <p class="text-[8px] text-slate-600 font-mono uppercase tracking-widest">
                        Processado por Ikaros Finance em <?php echo date('d/m/Y H:i:s'); ?><br>
                        Auth-Hash: <span class="text-slate-500"><?php echo md5($pilotId . date('Y-m') . $paycheck['total_net_pay']); ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>
