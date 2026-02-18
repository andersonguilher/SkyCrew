<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$sysSettings = getSystemSettings($pdo);

// 1. Fetch Financial Stats (All Time / This Month)
$month = date('Y-m');

// Revenue
$totalRevenue = $pdo->query("SELECT SUM(revenue) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;
$monthRevenueStmt = $pdo->prepare("SELECT SUM(revenue) FROM flight_reports WHERE status='Approved' AND DATE_FORMAT(submitted_at, '%Y-%m') = ?");
$monthRevenueStmt->execute([$month]);
$monthRevenue = $monthRevenueStmt->fetchColumn() ?: 0;

// Expenses (Combined)
$costsQuery = $pdo->query("SELECT 
    SUM(fuel_cost) as fuel_total, 
    SUM(maintenance_cost) as maint_total, 
    SUM(airport_fees) as fees_total,
    SUM(pilot_pay) as pilot_total,
    SUM(fuel_used) as fuel_kg
    FROM flight_reports WHERE status='Approved'")->fetch();

$totalFuelCost = $costsQuery['fuel_total'] ?: 0;
$totalMaintCost = $costsQuery['maint_total'] ?: 0;
$totalFeesCost = $costsQuery['fees_total'] ?: 0;
$totalPilotPay = $costsQuery['pilot_total'] ?: 0;
$totalFuelKg = $costsQuery['fuel_kg'] ?: 0;

$totalExpenses = $totalFuelCost + $totalMaintCost + $totalFeesCost + $totalPilotPay;

// Pax
$totalPax = $pdo->query("SELECT SUM(pax) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;

// Chart Data (Last 6 Months)
$chartData = [];
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $stmt = $pdo->prepare("SELECT SUM(revenue) as rev, SUM(fuel_cost + maintenance_cost + airport_fees + pilot_pay) as cost FROM flight_reports WHERE status='Approved' AND DATE_FORMAT(submitted_at, '%Y-%m') = ?");
    $stmt->execute([$m]);
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
$operatingProfit = $totalRevenue - $totalExpenses;

$pageTitle = "Financeiro - SkyCrew OS";
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
include '../includes/layout_header.php';
?>

<div class="space-y-6 flex-1 flex flex-col overflow-hidden">
    <!-- Stats Row -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 shrink-0">
        <div class="glass-panel p-6 rounded-3xl border-l-4 border-emerald-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Receita Total</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?> <?php echo number_format($totalRevenue, 2, ',', '.'); ?></h3>
                    <p class="text-[10px] text-emerald-400 font-bold mt-1 uppercase tracking-tighter">Fluxo Global</p>
                </div>
                <div class="bg-emerald-500/10 p-3 rounded-2xl text-emerald-400">
                    <i class="fas fa-wallet text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-panel p-6 rounded-3xl border-l-4 border-indigo-500 shadow-xl shadow-indigo-500/5">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Lucro Operacional</p>
                    <h3 class="text-2xl font-bold text-indigo-400 mt-1"><?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?> <?php echo number_format($operatingProfit, 2, ',', '.'); ?></h3>
                    <p class="text-[10px] text-slate-500 font-bold mt-1 uppercase tracking-tighter">Líquido Estimado</p>
                </div>
                <div class="bg-indigo-500/10 p-3 rounded-2xl text-indigo-400">
                    <i class="fas fa-coins text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-panel p-6 rounded-3xl border-l-4 border-blue-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Passageiros</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo number_format($totalPax, 0, ',', '.'); ?></h3>
                    <p class="text-[10px] text-blue-400 font-bold mt-1 uppercase tracking-tighter">Embarcados</p>
                </div>
                <div class="bg-blue-500/10 p-3 rounded-2xl text-blue-400">
                    <i class="fas fa-users text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-panel p-6 rounded-3xl border-l-4 border-amber-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Despesas Reais</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?> <?php echo number_format($totalExpenses, 2, ',', '.'); ?></h3>
                    <p class="text-[10px] text-rose-500 font-bold mt-1 uppercase tracking-tighter">Fuel + Maint + Fees + Pay</p>
                </div>
                <div class="bg-amber-500/10 p-3 rounded-2xl text-amber-400">
                    <i class="fas fa-hand-holding-usd text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 flex-1 overflow-hidden">
        <!-- Chart -->
        <div class="glass-panel p-8 rounded-3xl flex flex-col overflow-hidden">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-chart-area text-indigo-400"></i> Evolução Financeira
                </h3>
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Últimos 6 meses</span>
            </div>
            <div class="flex-1 min-h-0 relative">
                <canvas id="financeChart"></canvas>
            </div>
        </div>

        <!-- Top Routes -->
        <div class="glass-panel rounded-3xl flex flex-col overflow-hidden">
            <div class="p-6 border-b border-white/10 bg-white/5 flex justify-between items-center">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-trophy text-amber-400"></i> Malha de Performance
                </h3>
                <div class="bg-white/5 border border-white/10 px-3 py-1 rounded-full text-[10px] font-bold text-slate-400 uppercase tracking-widest">Top 5 Rotas</div>
            </div>
            <div class="flex-1 overflow-y-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-white/5 text-[10px] uppercase font-bold text-slate-500 tracking-widest">
                        <tr>
                            <th class="px-8 py-4">Voo / Operações</th>
                            <th class="px-8 py-4 text-right">Ticker Médio</th>
                            <th class="px-8 py-4 text-right pr-12">L/F Médio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (count($topRoutes) > 0): ?>
                            <?php foreach ($topRoutes as $route): ?>
                                <tr class="hover:bg-white/5 transition group">
                                    <td class="px-8 py-4">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-indigo-400"><?php echo $route['flight_number']; ?></span>
                                            <span class="text-[10px] text-slate-500 uppercase"><?php echo $route['flights_count']; ?> CICLOS COMPLETOS</span>
                                        </div>
                                    </td>
                                    <td class="px-8 py-4 text-right">
                                        <span class="font-mono font-bold text-emerald-400"><?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?> <?php echo number_format($route['avg_revenue'], 2, ',', '.'); ?></span>
                                    </td>
                                    <td class="px-8 py-4 text-right pr-12">
                                        <div class="flex flex-col items-end">
                                            <span class="font-bold text-slate-200"><?php echo number_format($route['load_factor'], 1); ?>%</span>
                                            <div class="w-16 h-1 bg-white/10 rounded-full mt-1 overflow-hidden">
                                                <div class="h-full bg-indigo-500 shadow-[0_0_8px_rgba(99,102,241,0.5)]" style="width: <?php echo min(100, $route['load_factor']); ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="px-8 py-12 text-center text-slate-500 italic">Nenhuma rota aprovada detectada na malha.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    Chart.defaults.color = 'rgba(148, 163, 184, 0.5)';
    Chart.defaults.font.family = "'Outfit', sans-serif";

    const ctx = document.getElementById('financeChart').getContext('2d');
    
    // Create Gradients
    const revGradient = ctx.createLinearGradient(0, 0, 0, 400);
    revGradient.addColorStop(0, 'rgba(16, 185, 129, 0.2)');
    revGradient.addColorStop(1, 'rgba(16, 185, 129, 0)');

    const profitGradient = ctx.createLinearGradient(0, 0, 0, 400);
    profitGradient.addColorStop(0, 'rgba(99, 102, 241, 0.4)');
    profitGradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [
                {
                    label: 'Receita Operacional',
                    data: <?php echo json_encode($chartData['revenue']); ?>,
                    borderColor: '#10B981',
                    borderWidth: 3,
                    backgroundColor: revGradient,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: 'rgba(255,255,255,0.2)',
                    pointHoverRadius: 6
                },
                {
                    label: 'Lucro Estimado',
                    data: <?php echo json_encode($chartData['profit']); ?>,
                    borderColor: '#6366F1',
                    borderWidth: 3,
                    backgroundColor: profitGradient,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#6366F1',
                    pointBorderColor: 'rgba(255,255,255,0.2)',
                    pointHoverRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { 
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 6,
                        boxHeight: 6,
                        padding: 20,
                        font: { size: 10, weight: 'bold' }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleFont: { size: 12, weight: 'bold' },
                    bodyFont: { size: 12 },
                    padding: 12,
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false },
                    ticks: {
                        font: { size: 10 },
                        callback: function(value) { return 'R$ ' + value/1000 + 'k'; }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                }
            }
        }
    });
</script>

<?php include '../includes/layout_footer.php'; ?>