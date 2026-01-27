<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../includes/PayrollSystem.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo); // Fetch Global Settings
$payroll = new PayrollSystem($pdo);

// Fetch Pilot Name if not in session
if (!isset($_SESSION['name'])) {
    $stmt = $pdo->prepare("SELECT name FROM pilots WHERE id = ?");
    $stmt->execute([$pilotId]);
    $pilotName = $stmt->fetchColumn();
} else {
    $pilotName = $_SESSION['name'];
}

// Generate for Current Month (Simulation)
$paycheck = $payroll->generatePaycheck($pilotId, date('Y-m'));

// Format Helpers
function money($val)
{
    global $sysSettings;
    return $sysSettings['currency_symbol'] . ' ' . number_format($val, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Holerite - <?php echo htmlspecialchars($sysSettings['va_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #e2e8f0;
        }

        .receipt-font {
            font-family: 'Courier Prime', monospace;
        }

        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 8rem;
            font-weight: bold;
            color: rgba(0, 0, 0, 0.03);
            pointer-events: none;
            z-index: 0;
            white-space: nowrap; 
        }
        @media print {
            .no-print { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>

<body class="p-8 min-h-screen flex items-center justify-center">

    <div
        class="max-w-4xl w-full bg-white shadow-2xl rounded-sm overflow-hidden relative receipt-font text-sm border-t-8 border-gray-800">

        <div class="watermark"><?php echo strtoupper($sysSettings['va_callsign'] ?? 'SKY'); ?></div>

        <!-- Header -->
        <div class="border-b-2 border-gray-800 p-8 flex justify-between items-start relative z-10">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">HOLERITE DE PAGAMENTO</h1>
                <p class="text-gray-500 uppercase mt-1"><?php echo htmlspecialchars($sysSettings['va_name']); ?> - Demonstrativo Mensal</p>
            </div>
            <div class="text-right">
                <div class="bg-gray-800 text-white px-4 py-2 font-bold text-lg inline-block mb-2">
                    <?php echo date('M/Y'); ?>
                </div>
                <p class="text-gray-600 font-bold">Ref: 01/<?php echo date('m/Y'); ?> a <?php echo date('t/m/Y'); ?></p>
            </div>
        </div>

        <!-- Employee Info -->
        <div class="p-6 bg-gray-50 border-b border-gray-200 grid grid-cols-2 gap-8 relative z-10">
            <div>
                <p class="text-xs text-gray-400 uppercase font-bold">Funcionário</p>
                <p class="text-lg font-bold">
                    <?php echo strtoupper($pilotName); ?>
                </p>
                <p class="text-gray-600">ID: <?php echo str_pad($pilotId, 6, '0', STR_PAD_LEFT); ?></p>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-400 uppercase font-bold">Cargo / Patente</p>
                <p class="text-lg font-bold text-indigo-900">
                    <?php echo strtoupper($paycheck['rank'] ?? 'CADET'); ?>
                </p>
                <p class="text-gray-600">Base: Fixa</p>
            </div>
        </div>

        <!-- Table -->
        <div class="p-8 relative z-10">
            <table class="w-full mb-8">
                <thead>
                    <tr class="border-b-2 border-gray-800 text-left">
                        <th class="py-2 w-16">Cód.</th>
                        <th class="py-2">Descrição</th>
                        <th class="py-2 text-center">Ref.</th>
                        <th class="py-2 text-right text-green-700">Vencimentos</th>
                        <th class="py-2 text-right text-red-700">Descontos</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700">
                    <!-- Earnings -->
                    <tr>
                        <td class="py-2">001</td>
                        <td class="py-2 font-bold">Salário Base (<?php echo $paycheck['rank'] ?? 'Cadet'; ?>)</td>
                        <td class="py-2 text-center">30d</td>
                        <td class="py-2 text-right">
                            <?php echo money($paycheck['base_salary']); ?>
                        </td>
                        <td class="py-2 text-right"></td>
                    </tr>
                    <tr>
                        <td class="py-2">002</td>
                        <td class="py-2">Hora de Voo</td>
                        <td class="py-2 text-center">
                            <?php echo number_format($paycheck['hours_flown'], 1); ?>h
                        </td>
                        <td class="py-2 text-right">
                            <?php echo money($paycheck['flight_pay']); ?>
                        </td>
                        <td class="py-2 text-right"></td>
                    </tr>

                    <!-- Deductions -->
                    <tr class="bg-red-50/50">
                        <td class="py-2">101</td>
                        <td class="py-2">INSS (Previdência)</td>
                        <td class="py-2 text-center">11%</td>
                        <td class="py-2 text-right"></td>
                        <td class="py-2 text-right">
                            <?php echo money($paycheck['pension_deduction']); ?>
                        </td>
                    </tr>
                    <tr class="bg-red-50/50">
                        <td class="py-2">102</td>
                        <td class="py-2">IRRF (Imposto de Renda)</td>
                        <td class="py-2 text-center">15%</td>
                        <td class="py-2 text-right"></td>
                        <td class="py-2 text-right">
                            <?php echo money($paycheck['tax_deduction']); ?>
                        </td>
                    </tr>

                    <?php if ($paycheck['per_diem_deduction'] > 0): ?>
                        <tr class="bg-orange-50 font-bold text-red-800 cursor-pointer hover:bg-orange-100 transition"
                            onclick="document.getElementById('idle-details').classList.toggle('hidden')">
                            <td class="py-2">205</td>
                            <td class="py-2 flex items-center">
                                Custo Operacional (Ociosidade)
                                <i class="fas fa-chevron-down ml-2 text-xs opacity-50"></i>
                            </td>
                            <td class="py-2 text-center">
                                <?php echo round($paycheck['idle_days'], 1); ?>d
                            </td>
                            <td class="py-2 text-right"></td>
                            <td class="py-2 text-right">
                                <?php echo money($paycheck['per_diem_deduction']); ?>
                            </td>
                        </tr>
                        <!-- Hidden Detail Row -->
                        <tr id="idle-details" class="hidden bg-orange-50/50 text-xs text-red-600">
                            <td colspan="5" class="py-2 px-4 shadow-inner">
                                <p class="font-bold mb-1 ml-10">Detalhamento de Dias Ociosos ( > 1 dia sem voo):</p>
                                <ul class="ml-10 list-disc pl-4 space-y-1">
                                    <?php
                                    if (isset($paycheck['idle_details'])) {
                                        foreach ($paycheck['idle_details'] as $detail) {
                                            $dailyCost = (float)$sysSettings['daily_idle_cost'];
                                            $currency = $sysSettings['currency_symbol'];
                                            $cost = $detail['days'] * $dailyCost; 
                                            if (isset($detail['breakdown'])) {
                                                echo "<li class='mb-2'><span class='font-bold block'>De {$detail['start']} até {$detail['end']} (Total: $currency " . number_format($cost, 2, ',', '.') . ")</span>" . $detail['breakdown'] . "</li>";
                                            } else {
                                                echo "<li>De {$detail['start']} até {$detail['end']} ({$detail['days']} dias) - $currency " . number_format($cost, 2, ',', '.') . "</li>";
                                            }
                                        }
                                    }
                                    ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>

            <!-- Totals -->
            <div class="flex justify-between items-end border-t-2 border-gray-800 pt-4">
                <div class="text-gray-500 text-xs w-1/2">
                    <p>DECLARO TER RECEBIDO A IMPORTÂNCIA LÍQUIDA DISCRIMINADA NESTE RECIBO.</p>
                    <p class="mt-8 border-t border-gray-400 w-3/4 pt-1">ASSINATURA DO COLABORADOR</p>
                </div>
                <div class="w-1/2">
                    <div class="flex justify-between mb-2 text-gray-600">
                        <span>Total Vencimentos</span>
                        <span>
                            <?php echo money($paycheck['base_salary'] + $paycheck['flight_pay']); ?>
                        </span>
                    </div>
                    <div class="flex justify-between mb-4 text-red-600">
                        <span>Total Descontos</span>
                        <span>-
                            <?php echo money($paycheck['pension_deduction'] + $paycheck['tax_deduction'] + $paycheck['per_diem_deduction']); ?>
                        </span>
                    </div>
                    <div class="flex justify-between bg-gray-200 p-3 rounded font-bold text-xl border border-gray-300">
                        <span>Líquido a Receber</span>
                        <span class="text-gray-900">
                            <?php echo money($paycheck['total_net_pay']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-gray-100 p-4 text-center text-xs text-gray-400 border-t border-gray-200">
            <?php echo htmlspecialchars($sysSettings['va_name']); ?> Management System - Documento Gerado Automaticamente em
            <?php echo date('d/m/Y H:i:s'); ?>
        </div>

        <!-- Action Buttons -->
        <div class="absolute top-8 right-8 no-print">
            <a href="dashboard.php"
                class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 shadow mr-2 font-sans text-xs">Voltar</a>
            <button onclick="window.print()"
                class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 shadow font-sans text-xs"><i
                    class="fas fa-print mr-1"></i> Imprimir</button>
        </div>

    </div>
</body>

</html>