<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Fetch Stats
try {
    $stats = [
        'pilots' => $pdo->query("SELECT COUNT(*) FROM pilots")->fetchColumn(),
        'flights' => $pdo->query("SELECT COUNT(*) FROM flights_master")->fetchColumn(),
        'rosters_pending' => $pdo->query("SELECT COUNT(*) FROM roster_assignments WHERE status='Suggested'")->fetchColumn(),
    ];

    // Fetch recent rosters
    $recentRosters = $pdo->query("
        SELECT r.*, p.name as pilot_name, f.flight_number, f.dep_icao, f.arr_icao 
        FROM roster_assignments r
        JOIN pilots p ON r.pilot_id = p.id
        JOIN flights_master f ON r.flight_id = f.id
        ORDER BY r.assigned_at DESC LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    $stats = ['pilots' => 0, 'flights' => 0, 'rosters_pending' => 0];
    $recentRosters = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Painel Admin - SkyCrew</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100">

    <!-- Sidebar -->
    <div class="flex h-screen">
        <aside class="w-64 bg-gray-900 text-white flex flex-col">
            <div class="h-16 flex items-center justify-center font-bold text-xl border-b border-gray-800">
                SkyCrew Admin
            </div>
            <nav class="flex-1 px-4 py-6 space-y-2">
                <a href="dashboard.php" class="block py-2.5 px-4 rounded bg-gray-800 text-white font-bold">Painel</a>
                <a href="financials.php"
                    class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Financeiro</a>
                <a href="reports.php"
                    class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Relatórios</a>
                <a href="pilots.php"
                    class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Pilotos</a>
                <a href="flights.php"
                    class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Voos</a>
                <a href="fleet.php"
                    class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Frota</a>
                <a href="settings.php"
                    class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Configurações</a>
            </nav>
            <div class="p-4 border-t border-gray-800">
                <a href="../logout.php" class="block text-center text-sm text-gray-400 hover:text-white">Sair</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <header class="bg-white shadow h-16 flex items-center px-6 justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Visão Geral</h2>
                <div class="text-sm text-gray-600">Usuário Admin</div>
            </header>

            <div class="p-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
                        <div class="text-gray-500 text-sm uppercase font-semibold">Total de Pilotos</div>
                        <div class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['pilots']; ?></div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-purple-500">
                        <div class="text-gray-500 text-sm uppercase font-semibold">Rotas Ativas</div>
                        <div class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['flights']; ?></div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-yellow-500">
                        <div class="text-gray-500 text-sm uppercase font-semibold">Aprovações Pendentes</div>
                        <div class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['rosters_pending']; ?></div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="font-bold text-gray-800">Atribuições de Escala Recentes</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 font-semibold text-gray-600">Piloto</th>
                                    <th class="px-6 py-3 font-semibold text-gray-600">Voo</th>
                                    <th class="px-6 py-3 font-semibold text-gray-600">Data</th>
                                    <th class="px-6 py-3 font-semibold text-gray-600">Rota</th>
                                    <th class="px-6 py-3 font-semibold text-gray-600">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($recentRosters as $r): ?>
                                    <tr>
                                        <td class="px-6 py-3 text-gray-800 font-medium">
                                            <?php echo htmlspecialchars($r['pilot_name']); ?>
                                        </td>
                                        <td class="px-6 py-3 text-indigo-600 font-bold"><?php echo $r['flight_number']; ?></td>
                                        <td class="px-6 py-3 text-gray-500"><?php echo $r['flight_date']; ?></td>
                                        <td class="px-6 py-3 text-gray-500">
                                            <?php echo $r['dep_icao'] . ' > ' . $r['arr_icao']; ?>
                                        </td>
                                        <td class="px-6 py-3">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold
                                            <?php
                                            echo match ($r['status']) {
                                                'Suggested' => 'bg-yellow-100 text-yellow-800',
                                                'Accepted' => 'bg-green-100 text-green-800',
                                                'Rejected' => 'bg-red-100 text-red-800',
                                                'Flown' => 'bg-blue-100 text-blue-800',
                                                default => 'bg-gray-100 text-gray-800'
                                            };
                                            ?>">
                                                <?php
                                                echo match ($r['status']) {
                                                    'Suggested' => 'Sugerido',
                                                    'Accepted' => 'Aceito',
                                                    'Rejected' => 'Rejeitado',
                                                    'Flown' => 'Voado',
                                                    default => $r['status']
                                                };
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

</body>

</html>