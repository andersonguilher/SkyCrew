<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Handle actions
if (isset($_POST['approve_id'])) {
    $reportId = $_POST['approve_id'];

    try {
        $pdo->beginTransaction();

        // 1. Get Report Details
        $stmt = $pdo->prepare("SELECT * FROM flight_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();

        if ($report && $report['status'] == 'Pending') {
            // 2. Update Report Status
            $stmt = $pdo->prepare("UPDATE flight_reports SET status = 'Approved' WHERE id = ?");
            $stmt->execute([$reportId]);

            // 3. Update Pilot Hours, Balance and Rank
            // Fetch current pilot data and rank info
            $stmt = $pdo->prepare("SELECT p.*, r.pay_rate FROM pilots p LEFT JOIN ranks r ON p.rank = r.rank_name WHERE p.id = ?");
            $stmt->execute([$report['pilot_id']]);
            $pilotData = $stmt->fetch();

            // Fallback pay rate if no rank found or mismatch
            $hourlyRate = $pilotData['pay_rate'] ?? 15.00;

            $earnings = $report['flight_time'] * $hourlyRate;
            $newHours = $pilotData['total_hours'] + $report['flight_time'];

            // Check for Promotion
            $nextRankStmt = $pdo->prepare("SELECT * FROM ranks WHERE min_hours <= ? ORDER BY min_hours DESC LIMIT 1");
            $nextRankStmt->execute([$newHours]);
            $newRankData = $nextRankStmt->fetch();
            $newRank = $newRankData ? $newRankData['rank_name'] : $pilotData['rank'];

            $updateStmt = $pdo->prepare("UPDATE pilots SET total_hours = ?, balance = balance + ?, rank = ? WHERE id = ?");
            $updateStmt->execute([$newHours, $earnings, $newRank, $report['pilot_id']]);

            $pdo->commit();
            $success = "Relatório aprovado e horas creditadas.";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro: " . $e->getMessage();
    }
}

// Fetch Pending Reports
$reports = $pdo->query("
    SELECT fr.*, p.name as pilot_name, p.current_base, f.flight_number, f.dep_icao, f.arr_icao 
    FROM flight_reports fr
    JOIN pilots p ON fr.pilot_id = p.id
    JOIN roster_assignments r ON fr.roster_id = r.id
    JOIN flights_master f ON r.flight_id = f.id
    WHERE fr.status = 'Pending'
    ORDER BY fr.submitted_at ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Gerenciar Relatórios - SkyCrew Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex h-screen">

    <aside class="w-64 bg-gray-900 text-white flex flex-col">
        <div class="h-16 flex items-center justify-center font-bold text-xl border-b border-gray-800">SkyCrew Admin
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2">
            <a href="dashboard.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Painel</a>
            <a href="financials.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Financeiro</a>
            <a href="reports.php" class="block py-2.5 px-4 rounded bg-gray-800 text-white font-bold">Relatórios</a>
            <a href="pilots.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Pilotos</a>
            <a href="flights.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Voos</a>
            <a href="fleet.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Frota</a>
            <a href="settings.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Configurações</a>
        </nav>
        <div class="p-4 border-t border-gray-800"><a href="../logout.php"
                class="block text-center text-sm text-gray-400">Sair</a></div>
    </aside>

    <main class="flex-1 flex flex-col p-8 overflow-y-auto">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Aprovação de Relatórios</h1>

        <?php if (isset($success)): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded mb-4">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($reports)): ?>
            <div class="bg-white p-12 rounded shadow text-center text-gray-500">
                <i class="fas fa-check-circle text-4xl mb-4 text-green-500"></i>
                <p>Nenhum relatório pendente.</p>
            </div>
        <?php else: ?>
            <div class="grid gap-4">
                <?php foreach ($reports as $r): ?>
                    <div
                        class="bg-white p-6 rounded shadow flex flex-col md:flex-row justify-between items-center border-l-4 border-yellow-500">
                        <div class="flex-1">
                            <div class="flex items-center space-x-2 mb-1">
                                <span class="font-bold text-lg text-gray-800">
                                    <?php echo htmlspecialchars($r['pilot_name']); ?>
                                </span>
                                <span class="bg-gray-200 text-xs px-2 py-1 rounded">
                                    <?php echo $r['current_base']; ?>
                                </span>
                            </div>
                            <div class="text-gray-600 text-sm mb-2">
                                <span class="font-bold text-indigo-600">
                                    <?php echo $r['flight_number']; ?>
                                </span>:
                                <?php echo $r['dep_icao']; ?> &rarr;
                                <?php echo $r['arr_icao']; ?>
                            </div>
                            <div class="grid grid-cols-3 gap-4 text-sm bg-gray-50 p-3 rounded max-w-lg">
                                <div>
                                    <p class="text-gray-500 text-xs">Tempo</p>
                                    <p class="font-bold">
                                        <?php echo $r['flight_time']; ?>h
                                    </p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-xs">Combustível</p>
                                    <p class="font-bold">
                                        <?php echo $r['fuel_used']; ?> kg
                                    </p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-xs">Toque</p>
                                    <p
                                        class="font-bold <?php echo $r['landing_rate'] < -500 ? 'text-red-500' : 'text-green-600'; ?>">
                                        <?php echo $r['landing_rate']; ?> fpm
                                    </p>
                                </div>
                            </div>
                            <?php if ($r['comments']): ?>
                                <p class="text-xs text-gray-500 mt-2 italic">"
                                    <?php echo htmlspecialchars($r['comments']); ?>"
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($r['incidents'])): ?>
                                <div class="mt-3 bg-red-50 border border-red-200 p-2 rounded text-xs text-red-700">
                                    <p class="font-bold mb-1"><i class="fas fa-exclamation-triangle"></i> Falhas/Alertas:</p>
                                    <ul class="list-disc pl-4">
                                        <?php
                                        $incidents = json_decode($r['incidents'], true);
                                        if (is_array($incidents)) {
                                            foreach ($incidents as $inc) {
                                                echo "<li>" . htmlspecialchars($inc) . "</li>";
                                            }
                                        } else {
                                            echo "<li>" . htmlspecialchars($r['incidents']) . "</li>";
                                        }
                                        ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 md:mt-0 ml-6 flex flex-col space-y-2">
                            <form method="POST">
                                <input type="hidden" name="approve_id" value="<?php echo $r['id']; ?>">
                                <button type="submit"
                                    class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded shadow transition w-full">
                                    Aprovar
                                </button>
                            </form>
                            <button
                                class="bg-red-100 hover:bg-red-200 text-red-600 font-bold py-2 px-6 rounded transition w-full text-sm">
                                Rejeitar
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>