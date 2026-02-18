<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$sysSettings = getSystemSettings($pdo);

// 1. Fetch Financial Stats (All Time / This Month)
$month = date('Y-m');

// Revenue
$totalRevenue = $pdo->query("SELECT SUM(revenue) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;

// Expenses Breakdown (All Time)
$costsQuery = $pdo->query("SELECT 
    SUM(fuel_cost) as fuel_total, 
    SUM(maintenance_cost) as maint_total, 
    SUM(airport_fees) as fees_total,
    SUM(pilot_pay) as pilot_total
    FROM flight_reports WHERE status='Approved'")->fetch();

$totalFuelCost = $costsQuery['fuel_total'] ?: 0;
$totalMaintCost = $costsQuery['maint_total'] ?: 0;
$totalFeesCost = $costsQuery['fees_total'] ?: 0;
$totalPilotPay = $costsQuery['pilot_total'] ?: 0;
$totalExpenses = $totalFuelCost + $totalMaintCost + $totalFeesCost + $totalPilotPay;

// Chart Data (Last 6 Months)
$chartData = [];
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $stmt = $pdo->prepare("
        SELECT 
            SUM(revenue) as rev, 
            SUM(fuel_cost) as fuel,
            SUM(maintenance_cost) as maint,
            SUM(airport_fees) as fees,
            SUM(pilot_pay) as pay,
            SUM(fuel_cost + maintenance_cost + airport_fees + pilot_pay) as total_cost 
        FROM flight_reports 
        WHERE status='Approved' AND DATE_FORMAT(submitted_at, '%Y-%m') = ?
    ");
    $stmt->execute([$m]);
    $res = $stmt->fetch();
    
    $months[] = date('M/Y', strtotime($m . "-01"));
    $chartData['revenue'][] = $res['rev'] ?: 0;
    $chartData['expense'][] = $res['total_cost'] ?: 0;
    $chartData['profit'][] = ($res['rev'] - $res['total_cost']) ?: 0;
    $chartData['fuel'][] = $res['fuel'] ?: 0;
    $chartData['maint'][] = $res['maint'] ?: 0;
    $chartData['fees'][] = $res['fees'] ?: 0;
    $chartData['pay'][] = $res['pay'] ?: 0;
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

// Recent Operations
$recentOps = $pdo->query("
    SELECT 
        fr.*, 
        fm.flight_number, 
        fm.dep_icao, 
        fm.arr_icao,
        p.name as pilot_name
    FROM flight_reports fr 
    JOIN roster_assignments r ON fr.roster_id = r.id 
    JOIN flights_master fm ON r.flight_id = fm.id
    JOIN pilots p ON fr.pilot_id = p.id
    WHERE fr.status = 'Approved'
    ORDER BY fr.submitted_at DESC 
    LIMIT 10
")->fetchAll();

$operatingProfit = $totalRevenue - $totalExpenses;
$totalPax = $pdo->query("SELECT SUM(pax) FROM flight_reports WHERE status='Approved'")->fetchColumn() ?: 0;

$pageTitle = "Financeiro - SkyCrew OS";
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
include '../includes/layout_header.php';
?>

<div class="flex-1 overflow-y-auto pr-2">
    <div class="max-w-[1800px] mx-auto space-y-6 py-4 pb-12">
    <!-- Stats Row -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 shrink-0">
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

        <div class="glass-panel p-6 rounded-3xl border-l-4 border-amber-500 relative group overflow-hidden">
            <div class="flex justify-between items-start relative z-10">
                <div class="flex-1">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Despesas Totais</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo $sysSettings['currency_symbol'] ?? 'R$'; ?> <?php echo number_format($totalExpenses, 2, ',', '.'); ?></h3>
                    
                    <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1">
                        <div class="flex justify-between items-center text-[9px] uppercase tracking-tighter">
                            <span class="text-slate-500">Combustível:</span>
                            <span class="text-rose-400 font-bold"><?php echo number_format($totalFuelCost, 2, ',', '.'); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[9px] uppercase tracking-tighter">
                            <span class="text-slate-500">Manutenção:</span>
                            <span class="text-rose-400 font-bold"><?php echo number_format($totalMaintCost, 2, ',', '.'); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[9px] uppercase tracking-tighter">
                            <span class="text-slate-500">Pátio/Taxas:</span>
                            <span class="text-rose-400 font-bold"><?php echo number_format($totalFeesCost, 2, ',', '.'); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[9px] uppercase tracking-tighter">
                            <span class="text-slate-500">Tripulação:</span>
                            <span class="text-rose-400 font-bold"><?php echo number_format($totalPilotPay, 2, ',', '.'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="bg-amber-500/10 p-3 rounded-2xl text-amber-400">
                    <i class="fas fa-hand-holding-usd text-xl"></i>
                </div>
            </div>
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:opacity-10 transition-opacity">
                <i class="fas fa-file-invoice-dollar text-8xl text-amber-500"></i>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="grid grid-cols-1 gap-6">
        <!-- Interactive Chart Panel -->
        <div class="glass-panel p-8 rounded-3xl flex flex-col relative group overflow-hidden">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8 relative z-10">
                <div>
                    <h3 class="text-xl font-black text-white flex items-center gap-3">
                        <i class="fas fa-chart-line text-indigo-400"></i> Performance Histórica
                    </h3>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em] mt-1">Análise consolidada da malha aérea</p>
                </div>
                
                <!-- Chart Controls -->
                <div class="flex flex-wrap gap-2 items-center">
                    <button onclick="updateChartMode('summary')" id="btn-summary" class="chart-tab active">Resumo</button>
                    <button onclick="updateChartMode('costs')" id="btn-costs" class="chart-tab">Custos</button>
                    <div class="w-px h-6 bg-white/10 mx-2 hidden md:block"></div>
                    
                    <!-- Base Legends -->
                    <div id="legends-summary" class="flex flex-wrap gap-2 items-center">
                        <button onclick="toggleDataset(0)" class="chart-legend-btn" data-index="0" style="--color: #10B981">Receita</button>
                        <button onclick="toggleDataset(1)" class="chart-legend-btn" data-index="1" style="--color: #6366F1">Lucro</button>
                        <button onclick="toggleDataset(2)" class="chart-legend-btn" data-index="2" style="--color: #F43F5E">Despesas</button>
                    </div>

                    <!-- Cost Legends (Hidden by default) -->
                    <div id="legends-costs" class="hidden flex flex-wrap gap-2 items-center">
                        <button onclick="toggleDataset(2)" class="chart-legend-btn" data-index="2" style="--color: #F43F5E">Total</button>
                        <button onclick="toggleDataset(3)" class="chart-legend-btn" data-index="3" style="--color: #FCA5A5">Combustível</button>
                        <button onclick="toggleDataset(4)" class="chart-legend-btn" data-index="4" style="--color: #FCD34D">Manutenção</button>
                        <button onclick="toggleDataset(5)" class="chart-legend-btn" data-index="5" style="--color: #93C5FD">Pátio</button>
                        <button onclick="toggleDataset(6)" class="chart-legend-btn" data-index="6" style="--color: #C4B5FD">Tripulação</button>
                    </div>
                </div>
            </div>

            <div class="flex-1 min-h-[350px] relative">
                <canvas id="financeChart"></canvas>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <!-- Top Routes -->
            <div class="glass-panel rounded-3xl flex flex-col overflow-hidden">
                <div class="p-6 border-b border-white/10 bg-white/5 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-trophy text-amber-400"></i> Performance de Rotas
                    </h3>
                </div>
                <div class="flex-1 overflow-y-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-white/5 text-[10px] uppercase font-bold text-slate-500 tracking-widest">
                            <tr>
                                <th class="px-8 py-4">Voo</th>
                                <th class="px-8 py-4 text-right">Avg. Revenue</th>
                                <th class="px-8 py-4 text-right pr-12">L/F</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (count($topRoutes) > 0): ?>
                                <?php foreach ($topRoutes as $route): ?>
                                    <tr class="hover:bg-white/5 transition">
                                        <td class="px-8 py-4">
                                            <div class="flex flex-col">
                                                <span class="font-bold text-indigo-400"><?php echo $route['flight_number']; ?></span>
                                                <span class="text-[10px] text-slate-500 uppercase"><?php echo $route['flights_count']; ?> voos</span>
                                            </div>
                                        </td>
                                        <td class="px-8 py-4 text-right">
                                            <span class="font-mono font-bold text-emerald-400">R$ <?php echo number_format($route['avg_revenue'], 2, ',', '.'); ?></span>
                                        </td>
                                        <td class="px-8 py-4 text-right pr-12 text-slate-300 font-bold"><?php echo number_format($route['load_factor'], 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="px-8 py-10 text-center text-slate-500 italic">Nenhum dado de rota disponível.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Ops Summary -->
            <div class="glass-panel rounded-3xl flex flex-col overflow-hidden">
                <div class="p-6 border-b border-white/10 bg-white/5 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-history text-indigo-400"></i> Ciclos Recentes
                    </h3>
                </div>
                <div class="flex-1 overflow-y-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-white/5 text-[10px] uppercase font-bold text-slate-500 tracking-widest">
                            <tr>
                                <th class="px-8 py-4">Voo / ICAO</th>
                                <th class="px-8 py-4 text-right pr-12">Líquido</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (count($recentOps) > 0): ?>
                                <?php foreach (array_slice($recentOps, 0, 5) as $op): 
                                    $opNet = $op['revenue'] - ($op['fuel_cost'] + $op['maintenance_cost'] + $op['airport_fees'] + $op['pilot_pay']);
                                ?>
                                    <tr class="hover:bg-white/5 transition">
                                        <td class="px-8 py-4 text-slate-200">
                                            <div class="flex flex-col">
                                                <span class="font-bold"><?php echo $op['flight_number']; ?></span>
                                                <span class="text-[10px] text-slate-500"><?php echo $op['dep_icao']; ?> → <?php echo $op['arr_icao']; ?></span>
                                            </div>
                                        </td>
                                        <td class="px-8 py-4 text-right pr-12 font-mono font-bold <?php echo $opNet >= 0 ? 'text-indigo-400' : 'text-rose-400'; ?>">
                                            R$ <?php echo number_format($opNet, 2, ',', '.'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="px-8 py-10 text-center text-slate-500 italic">Nenhum ciclo recente finalizado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .chart-tab {
        padding: 0.4rem 0.8rem;
        border-radius: 0.5rem;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        transition: all 0.2s;
        border: 1px solid rgba(255, 255, 255, 0.05);
        background: rgba(255, 255, 255, 0.02);
        color: #94a3b8;
    }
    .chart-tab.active { background: #6366f1; color: white; border-color: #6366f1; }
    .chart-tab:hover:not(.active) { background: rgba(255, 255, 255, 0.1); color: white; }
    
    .chart-legend-btn {
        padding: 0.3rem 0.6rem;
        border-radius: 0.4rem;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        color: #94a3b8;
        background: transparent;
        border: 1px solid transparent;
        transition: 0.2s;
    }
    .chart-legend-btn::before {
        content: '';
        width: 5px; height: 5px; border-radius: 50%; background: var(--color);
    }
    .chart-legend-btn.hidden-ds { opacity: 0.2; filter: grayscale(1); }
</style>

<script>
    Chart.defaults.color = 'rgba(148, 163, 184, 0.5)';
    Chart.defaults.font.family = "'Outfit', sans-serif";

    const chartCtx = document.getElementById('financeChart').getContext('2d');
    
    const chartDataObj = {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            { label: 'Receita', data: <?php echo json_encode($chartData['revenue']); ?>, borderColor: '#10B981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4, hidden: false, borderWidth: 3 },
            { label: 'Lucro', data: <?php echo json_encode($chartData['profit']); ?>, borderColor: '#6366F1', backgroundColor: 'rgba(99, 102, 241, 0.1)', fill: true, tension: 0.4, hidden: false, borderWidth: 3 },
            { label: 'Despesas', data: <?php echo json_encode($chartData['expense']); ?>, borderColor: '#F43F5E', borderDash: [5, 5], fill: false, tension: 0.4, hidden: false, borderWidth: 2 },
            // Sub-costs
            { label: 'Combustível', data: <?php echo json_encode($chartData['fuel']); ?>, borderColor: '#FCA5A5', fill: false, tension: 0.4, hidden: true, borderWidth: 1.5 },
            { label: 'Manutenção', data: <?php echo json_encode($chartData['maint']); ?>, borderColor: '#FCD34D', fill: false, tension: 0.4, hidden: true, borderWidth: 1.5 },
            { label: 'Pátio', data: <?php echo json_encode($chartData['fees']); ?>, borderColor: '#93C5FD', fill: false, tension: 0.4, hidden: true, borderWidth: 1.5 },
            { label: 'Tripulação', data: <?php echo json_encode($chartData['pay']); ?>, borderColor: '#C4B5FD', fill: false, tension: 0.4, hidden: true, borderWidth: 1.5 }
        ]
    };

    const financeChart = new Chart(chartCtx, {
        type: 'line',
        data: chartDataObj,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 12,
                    callbacks: {
                        label: (c) => ` ${c.dataset.label}: R$ ${c.parsed.y.toLocaleString('pt-BR')}`
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { font: { size: 10 } } }
            }
        }
    });

    function updateChartMode(mode) {
        document.querySelectorAll('.chart-tab').forEach(b => b.classList.remove('active'));
        document.getElementById('btn-' + mode).classList.add('active');
        
        // Show/Hide relevant legends
        const summaryLeg = document.getElementById('legends-summary');
        const costsLeg = document.getElementById('legends-costs');

        if (mode === 'summary') {
            summaryLeg.classList.remove('hidden');
            costsLeg.classList.add('hidden');
        } else {
            summaryLeg.classList.add('hidden');
            costsLeg.classList.remove('hidden');
        }

        financeChart.data.datasets.forEach((ds, i) => {
            if (mode === 'summary') {
                ds.hidden = i > 2;
            } else {
                ds.hidden = i < 2; // Hide Rev and Profit, keep Total Exp (2) and others
            }
        });
        financeChart.update();
        syncLegendBtns();
    }

    function toggleDataset(i) {
        financeChart.data.datasets[i].hidden = !financeChart.data.datasets[i].hidden;
        financeChart.update();
        syncLegendBtns();
    }

    function syncLegendBtns() {
        document.querySelectorAll('.chart-legend-btn').forEach(btn => {
            const i = parseInt(btn.dataset.index);
            btn.classList.toggle('hidden-ds', financeChart.data.datasets[i].hidden);
        });
    }
</script>

<?php include '../includes/layout_footer.php'; ?>