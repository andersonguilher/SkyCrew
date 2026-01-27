<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);

if (!isset($_GET['roster_id'])) {
    header("Location: dashboard.php");
    exit;
}

$rosterId = $_GET['roster_id'];

// Check if roster belongs to pilot
$stmt = $pdo->prepare("SELECT r.*, f.flight_number, f.dep_icao, f.arr_icao FROM roster_assignments r JOIN flights_master f ON r.flight_id = f.id WHERE r.id = ? AND r.pilot_id = ?");
$stmt->execute([$rosterId, $pilotId]);
$flight = $stmt->fetch();

if (!$flight)
    die("Voo inválido.");

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $time = $_POST['flight_time'];
    $fuel = $_POST['fuel_used'];
    $landing = $_POST['landing_rate'];
    $comments = $_POST['comments'];

    try {
        $pdo->beginTransaction();

        // 1. Create Report
        $stmt = $pdo->prepare("INSERT INTO flight_reports (pilot_id, roster_id, flight_time, fuel_used, landing_rate, comments, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$pilotId, $rosterId, $time, $fuel, $landing, $comments]);

        // 2. Update Roster Status to 'Flown'
        $stmt = $pdo->prepare("UPDATE roster_assignments SET status = 'Flown' WHERE id = ?");
        $stmt->execute([$rosterId]);

        $pdo->commit();
        $_SESSION['flash_msg'] = ["type" => "success", "text" => "Relatório enviado com sucesso! Aguardando aprovação."];
        header("Location: dashboard.php");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro ao enviar: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Enviar Relatório - SkyCrew</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 p-8 flex justify-center">

    <div class="w-full max-w-lg bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-indigo-600 px-6 py-4">
            <h1 class="text-white text-xl font-bold">Relatório de Voo (PIREP)</h1>
            <p class="text-indigo-200 text-sm">
                <?php echo $flight['flight_number']; ?>:
                <?php echo $flight['dep_icao']; ?> &rarr;
                <?php echo $flight['arr_icao']; ?>
            </p>
        </div>

        <form method="POST" class="p-6">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">Tempo de Voo (Horas)</label>
                    <input type="number" step="0.01" name="flight_time" class="w-full border p-2 rounded"
                        placeholder="ex: 1.5" required>
                    <p class="text-xs text-gray-400 mt-1">Decimal (1h30m = 1.5)</p>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">Toque (fpm)</label>
                    <input type="number" name="landing_rate" class="w-full border p-2 rounded" placeholder="-150"
                        required>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Combustível Usado (kg)</label>
                <input type="number" name="fuel_used" class="w-full border p-2 rounded" placeholder="5000" required>
            </div>

            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Comentários / Ocorrências</label>
                <textarea name="comments" class="w-full border p-2 rounded h-24"
                    placeholder="Voo tranquilo..."></textarea>
            </div>

            <div class="flex justify-between items-center">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700 text-sm">Cancelar</a>
                <button type="submit"
                    class="bg-green-600 text-white font-bold py-2 px-6 rounded hover:bg-green-700 transition">Enviar
                    Relatório</button>
            </div>
        </form>
    </div>

</body>

</html>