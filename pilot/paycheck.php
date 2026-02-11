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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .paycheck-container { max-width: 800px; margin: 40px auto; background: white; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; position: relative; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 80px; font-weight: 900; color: rgba(0,0,0,0.03); white-space: nowrap; pointer-events: none; }
        @media print {
            body { background: white; margin: 0; }
            .paycheck-container { box-shadow: none; margin: 0; max-width: 100%; border: 1px solid #eee; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="p-4 md:p-10">

    <div class="paycheck-container">
        <div class="watermark">SKYCREW VIRTUAL</div>
        
        <!-- Header -->
        <div class="p-8 border-b-2 border-gray-100 flex justify-between items-center bg-gray-50">
            <div>
                <h1 class="text-2xl font-black text-gray-900 tracking-tighter"><?php echo strtoupper($sysSettings['va_name'] ?? 'SKYCREW'); ?></h1>
                <p class="text-xs text-gray-500 uppercase font-bold tracking-widest mt-1">Recibo de Pagamento de Salário</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-400 font-bold uppercase">Mês/Ano de Referência</p>
                <p class="text-xl font-black text-indigo-600"><?php echo date('m/Y'); ?></p>
            </div>
        </div>

        <!-- Pilot Info -->
        <div class="grid grid-cols-2 border-b border-gray-100">
            <div class="p-6 border-r border-gray-100">
                <p class="text-[10px] text-gray-400 font-bold uppercase mb-1">Colaborador</p>
                <p class="text-lg font-bold text-gray-800"><?php echo $pilotName; ?></p>
                <p class="text-xs text-gray-500">ID: <?php echo str_pad($pilotId, 4, '0', STR_PAD_LEFT); ?></p>
            </div>
            <div class="p-6">
                <p class="text-[10px] text-gray-400 font-bold uppercase mb-1">Cargo / Patente</p>
                <p class="text-lg font-bold text-indigo-900"><?php echo strtoupper($paycheck['rank'] ?? 'CADET'); ?></p>
                <p class="text-xs text-gray-500">Base: Fixa</p>
            </div>
        </div>

        <!-- Table -->
        <div class="p-8 relative z-10">
            <table class="w-full mb-8">
                <thead>
                    <tr class="border-b-2 border-gray-800 text-left">
                        <th class="py-2 w-16 text-xs uppercase text-gray-500">Cód.</th>
                        <th class="py-2 text-xs uppercase text-gray-500">Descrição</th>
                        <th class="py-2 text-center text-xs uppercase text-gray-500">Ref.</th>
                        <th class="py-2 text-right text-xs uppercase text-green-700">Vencimentos</th>
                        <th class="py-2 text-right text-xs uppercase text-red-700">Descontos</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700">
                    <!-- Earnings -->
                    <tr>
                        <td class="py-2 text-sm">001</td>
                        <td class="py-2 font-bold text-sm">Salário Base</td>
                        <td class="py-2 text-center text-sm">30d</td>
                        <td class="py-2 text-right text-sm font-mono"><?php echo money($paycheck['base_salary']); ?></td>
                        <td class="py-2 text-right text-sm"></td>
                    </tr>
                    <tr>
                        <td class="py-2 text-sm">002</td>
                        <td class="py-2 text-sm">Adicional de Voo</td>
                        <td class="py-2 text-center text-sm font-mono"><?php echo number_format($paycheck['hours_flown'], 1); ?>h</td>
                        <td class="py-2 text-right text-sm font-mono"><?php echo money($paycheck['flight_pay']); ?></td>
                        <td class="py-2 text-right text-sm"></td>
                    </tr>

                    <!-- Deductions -->
                    <?php if ($paycheck['per_diem_deduction'] > 0): ?>
                        <tr class="bg-orange-50 font-bold text-red-800 cursor-pointer hover:bg-orange-100 transition"
                            onclick="document.getElementById('idle-details').classList.toggle('hidden')">
                            <td class="py-2 text-sm">205</td>
                            <td class="py-2 flex items-center text-sm">
                                Custo Operacional (Ociosidade)
                                <i class="fas fa-chevron-down ml-2 text-[10px] opacity-50"></i>
                            </td>
                            <td class="py-2 text-center text-sm"><?php echo round($paycheck['idle_days'], 1); ?>d</td>
                            <td class="py-2 text-right text-sm"></td>
                            <td class="py-2 text-right text-sm font-mono">- <?php echo money($paycheck['per_diem_deduction']); ?></td>
                        </tr>
                        <!-- Hidden Detail Row -->
                        <tr id="idle-details" class="hidden bg-orange-50/50 text-[11px] text-red-600">
                            <td colspan="5" class="py-4 px-6 shadow-inner">
                                <p class="font-bold mb-2 ml-10">Detalhamento de Custos (Dias Ociosos):</p>
                                <div class="ml-10 space-y-4">
                                    <?php
                                    if (isset($paycheck['idle_details'])) {
                                        foreach ($paycheck['idle_details'] as $detail) {
                                            echo "<div class='border-l-2 border-red-200 pl-4'>";
                                            echo "<p class='font-bold text-gray-700 mb-1'>Período: {$detail['start']} até {$detail['end']} ({$detail['days']} dias)</p>";
                                            echo $detail['breakdown'];
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

            <!-- Totals -->
            <div class="flex justify-between items-end border-t-2 border-gray-800 pt-6">
                <div class="text-gray-400 text-[10px] w-1/2 leading-relaxed font-bold uppercase tracking-tight">
                    <p>DECLARO TER RECEBIDO A IMPORTÂNCIA LÍQUIDA DISCRIMINADA NESTE RECIBO.</p>
                    <div class="mt-12 border-t border-gray-200 w-2/3 pt-1 text-gray-300">ASSINATURA DO PILOTO</div>
                </div>
                <div class="w-1/2">
                    <div class="flex justify-between mb-2 text-gray-500 text-sm">
                        <span>Total Vencimentos</span>
                        <span class="font-mono text-gray-700"><?php echo money($paycheck['base_salary'] + $paycheck['flight_pay']); ?></span>
                    </div>
                    <div class="flex justify-between mb-4 text-red-500 text-sm">
                        <span>Total Descontos</span>
                        <span class="font-mono">- <?php echo money($paycheck['per_diem_deduction']); ?></span>
                    </div>
                    <div class="flex justify-between bg-gray-900 p-4 rounded-lg font-black text-xl text-white shadow-xl ring-4 ring-indigo-500/10">
                        <span class="uppercase text-xs self-center text-gray-400 tracking-widest">Valor Líquido</span>
                        <span class="font-mono"><?php echo money($paycheck['total_net_pay']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-gray-50 p-6 text-center text-[9px] text-gray-400 border-t border-gray-100 font-bold uppercase tracking-widest">
            Documento Gerado por Ikaros Finance em <?php echo date('d/m/Y H:i:s'); ?> - Autenticação: <?php echo md5($pilotId . date('Y-m') . $paycheck['total_net_pay']); ?>
        </div>

        <!-- Action Buttons -->
        <div class="absolute top-6 right-8 no-print flex gap-2">
            <a href="dashboard.php" class="bg-white border border-gray-200 text-gray-600 px-4 py-2 rounded-lg hover:bg-gray-50 transition shadow-sm text-xs font-bold uppercase tracking-widest flex items-center gap-2">
                <i class="fas fa-arrow-left text-[10px]"></i> Painel
            </a>
            <button onclick="window.print()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition shadow-lg shadow-indigo-200 text-xs font-bold uppercase tracking-widest flex items-center gap-2">
                <i class="fas fa-print text-[10px]"></i> Imprimir
            </button>
        </div>
    </div>

</body>
</html>