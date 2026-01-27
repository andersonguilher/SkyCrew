<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// 1. Fetch Financial Stats (All Time / This Month)
$month = date('Y-m');

// Revenue
$totalRevenue = $pdo->query("SELECT SUM(revenue) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;
$monthRevenue = $pdo->prepare("SELECT SUM(revenue) FROM flight_reports WHERE status='Approved' AND DATE_FORMAT(submitted_at, '%Y-%m') = ?");
$monthRevenue->execute([$month]);
$monthRevenue = $monthRevenue->fetchColumn() ?: 0;

// Expenses (Fuel)
// Assume Fuel Price $2.50 / kg
$fuelPrice = 2.50;
$totalFuel = $pdo->query("SELECT SUM(fuel_used) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;
$totalFuelCost = $totalFuel * $fuelPrice;

// Pax
$totalPax = $pdo->query("SELECT SUM(pax) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;

// Flights
$totalFlights = $pdo->query("SELECT COUNT(*) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;

// Chart Data (Last 6 Months)
$chartData = [];
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $stmt = $pdo->prepare("SELECT SUM(revenue) as rev, SUM(fuel_used * ?) as cost FROM flight_reports WHERE status='Approved' AND DATE_FORMAT(submitted_at, '%Y-%m') = ?");
    $stmt->execute([$fuelPrice, $m]);
    $res = $stmt->fetch();
    
    $months[] = date('M/Y', strtotime($m . "-01"));
    $chartData['revenue'][] = $res['rev'] ?: 0;
    $chartData['expense'][] = $res['cost'] ?: 0;
    $chartData['profit'][] = ($res['rev'] - $res['cost']) ?: 0;
}

// Top Routes
$topRoutes = $pdo->query("
    SELECT 
        fm.flight_number, 
        AVG(fr.revenue) as avg_revenue, 
        AVG(fr.pax / fm.max_pax * 100) as load_factor,
        COUNT(fr.id) as flights_count
    FROM flight_reports fr 
    JOIN roster_assignments r ON fr.roster_id = r.id 
    JOIN flights_master fm ON r.flight_id = fm.id
    WHERE fr.status = 'Approved'
    GROUP BY fm.flight_number 
    ORDER BY avg_revenue DESC 
    LIMIT 5
")->fetchAll();

// Calculate Profit
// Simplified: Revenue - Fuel - Pilot Pay (Estimate)
// Pilot Pay estimate: 10% of revenue? Or sum of paychecks? 
// Let's use a simple margin for now or try to sum pilot pay if we had historical paycheck records.
// We don't have historical paychecks stored as values yet, only calculated on fly.
// So let's estimate "Operating Profit" as Revenue - Fuel.
$operatingProfit = $totalRevenue - $totalFuelCost;

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Financeiro -
        <?php echo htmlspecialchars($sysSettings['va_name'] ?? 'SkyCrew'); ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="bg-gray-100 flex h-screen font-inter">

    <!-- Sidebar -->
    <aside class="w-64 bg-gray-900 text-white flex flex-col">
        <div class="h-16 flex items-center justify-center font-bold text-xl border-b border-gray-800">
            Kafly Admin
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2">
            <a href="dashboard.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Painel</a>
            <a href="financials.php" class="block py-2.5 px-4 rounded bg-gray-800 text-white font-bold">Financeiro</a>
            <a href="reports.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Relatórios</a>
            <a href="pilots.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Pilotos</a>
            <a href="flights.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Voos</a>
            <a href="fleet.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Frota</a>
            <a href="settings.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Configurações</a>
        </nav>
        <div class="p-4 border-t border-gray-800">
            <a href="../logout.php" class="block text-center text-sm text-gray-400 hover:text-white">Sair</a>
        </div>
    </aside>

    <!-- Main -->
    <main class="flex-1 overflow-y-auto">
        <header class="bg-white shadow h-16 flex items-center px-6 justify-between">
            <h2 class="text-xl font-semibold text-gray-800"> <i class="fas fa-chart-line mr-2"></i> Dashboard Financeiro
            </h2>
            <div class="text-sm text-gray-600">Admin</div>
        </header>

        <div class="p-6">

            <!-- Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <!-- Revenue -->
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase">Receita Total</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1">R$
                                <?php echo number_format($totalRevenue, 2, ',', '.'); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-green-100 rounded text-green-600">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                </div>

                <!-- Profit -->
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-emerald-600">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase">Lucro Operacional</p>
                            <h3 class="text-2xl font-bold text-emerald-600 mt-1">R$
                                <?php echo number_format($operatingProfit, 2, ',', '.'); ?>
                            </h3>
                            <p class="text-xs text-gray-400 mt-1">Receita - Combustível</p>
                        </div>
                        <div class="p-2 bg-emerald-100 rounded text-emerald-600">
                            <i class="fas fa-coins"></i>
                        </div>
                    </div>
                </div>

                <!-- Pax -->
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase">Passageiros</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1">
                                <?php echo number_format($totalPax, 0, ',', '.'); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-blue-100 rounded text-blue-600">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>

                <!-- Fuel -->
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase">Combustível</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1">
                                <?php echo number_format($totalFuel, 0, ',', '.'); ?> kg
                            </h3>
                            <p class="text-xs text-red-400 mt-1">- R$
                                <?php echo number_format($totalFuelCost, 2, 'k', '.'); ?>
                            </p>
                        </div>
                        <div class="p-2 bg-orange-100 rounded text-orange-600">
                            <i class="fas fa-gas-pump"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="font-bold text-gray-700 mb-4">Evolução Financeira (Últimos 6 Meses)</h3>
                    <div class="h-64">
                         <canvas id="financeChart"></canvas>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow">
                     <h3 class="font-bold text-gray-700 mb-4">Top Rotas Lucrativas</h3>
                     <table class="min-w-full text-sm">
                         <thead class="bg-gray-50">
                             <tr>
                                 <th class="px-4 py-2 text-left">Voo</th>
                                 <th class="px-4 py-2 text-right">Receita Média</th>
                                 <th class="px-4 py-2 text-right">Ocupação (Méd)</th>
                             </tr>
                         </thead>
                         <tbody>
                            <?php if (count($topRoutes) > 0): ?>
                                <?php foreach ($topRoutes as $route): ?>
                                 <tr class="border-t">
                                     <td class="px-4 py-2 font-bold text-gray-700"><?php echo $route['flight_number']; ?> <span class="text-xs font-normal text-gray-400">(<?php echo $route['flights_count']; ?> voos)</span></td>
                                     <td class="px-4 py-2 text-right text-green-600 font-mono">R$ <?php echo number_format($route['avg_revenue'], 2, ',', '.'); ?></td>
                                     <td class="px-4 py-2 text-right"><?php echo number_format($route['load_factor'], 1); ?>%</td>
                                 </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">Nenhum dado registrado ainda.</td></tr>
                            <?php endif; ?>
                         </tbody>
                     </table>
                </div>
            </div>

        </div>
    </main>
    
    <script>
        const ctx = document.getElementById('financeChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [
                    {
                        label: 'Receita',
                        data: <?php echo json_encode($chartData['revenue']); ?>,
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Lucro Líquido',
                        data: <?php echo json_encode($chartData['profit']); ?>,
                        borderColor: '#059669', // Darker Green
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return 'R$ ' + value/1000 + 'k'; }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>