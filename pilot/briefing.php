<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo);

if (!isset($_GET['flight_id'])) {
    header("Location: dashboard.php");
    exit;
}

$rosterId = $_GET['flight_id'];

// Fetch Flight Details
$stmt = $pdo->prepare("
    SELECT r.id, r.flight_date, fm.flight_number, fm.dep_icao, fm.arr_icao, 
           fm.dep_time, fm.arr_time, fm.aircraft_type, fm.duration_minutes
    FROM roster_assignments r
    JOIN flights_master fm ON r.flight_id = fm.id
    WHERE r.id = ? AND r.pilot_id = ?
");
$stmt->execute([$rosterId, $pilotId]);
$flight = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flight) {
    die("Voo não encontrado ou não atribuído a você.");
}

// SimBrief URL Construction
$sbUrl = "https://www.simbrief.com/system/dispatch.php?";
$params = [
    'airline' => $sysSettings['va_callsign'],
    'fltnum' => preg_replace('/\D/', '', $flight['flight_number']), // Just numbers usually
    'type' => $flight['aircraft_type'],
    'orig' => $flight['dep_icao'],
    'dest' => $flight['arr_icao'],
    'date' => date('dMy', strtotime($flight['flight_date'])), // Format depends on SB
    'deph' => substr($flight['dep_time'], 0, 2),
    'depm' => substr($flight['dep_time'], 3, 2),
    'steh' => floor($flight['duration_minutes'] / 60),
    'stem' => $flight['duration_minutes'] % 60,
];
$dispatchUrl = $sbUrl . http_build_query($params);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Briefing de Voo - <?php echo $flight['flight_number']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
    </style>
</head>

<body class="p-6">

    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700 text-sm mb-2 block">&larr; Voltar ao
                    Painel</a>
                <h1 class="text-3xl font-bold text-gray-900">Briefing Operacional</h1>
                <p class="text-gray-600"><?php echo $flight['flight_number']; ?> &bull;
                    <?php echo date('d M Y', strtotime($flight['flight_date'])); ?></p>
            </div>
        </div>

        <!-- Main Card -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-6">
            <!-- Route Banner -->
            <div class="bg-gray-900 text-white p-6 grid grid-cols-3 items-center text-center">
                <div>
                    <h2 class="text-4xl font-bold"><?php echo $flight['dep_icao']; ?></h2>
                    <p class="text-gray-400 text-sm"><?php echo $flight['dep_time']; ?> Z</p>
                </div>
                <div>
                    <i class="fas fa-plane text-2xl text-blue-400 mb-2 transform rotate-90"></i>
                    <p class="text-xs text-gray-500 uppercase tracking-widest">Duração Est.</p>
                    <p class="font-bold"><?php echo floor($flight['duration_minutes'] / 60); ?>h
                        <?php echo $flight['duration_minutes'] % 60; ?>m</p>
                </div>
                <div>
                    <h2 class="text-4xl font-bold"><?php echo $flight['arr_icao']; ?></h2>
                    <p class="text-gray-400 text-sm"><?php echo $flight['arr_time']; ?> Z</p>
                </div>
            </div>

            <div class="p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Dispatch Actions -->
                    <div>
                        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Despacho de Voo</h3>
                        <p class="text-sm text-gray-600 mb-6">
                            Utilize o sistema SimBrief para gerar seu plano de voo oficial (OFP). O plano incluirá
                            cálculos de combustível, rota otimizada e análise de clima.
                        </p>

                        <a href="<?php echo $dispatchUrl; ?>" target="_blank"
                            class="block w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-6 rounded-lg shadow transform transition hover:-translate-y-1 text-center">
                            <i class="fas fa-file-signature mr-2"></i> Gerar OFP no SimBrief
                        </a>

                        <p class="text-xs text-gray-400 mt-4 text-center">
                            Ao clicar, você será redirecionado para o despachante SimBrief.
                        </p>
                    </div>

                    <!-- Weather / Info -->
                    <div>
                        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Informações da Aeronave</h3>
                        <div class="space-y-4">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Equipamento:</span>
                                <span class="font-bold"><?php echo $flight['aircraft_type']; ?></span>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded border border-yellow-200 text-sm text-yellow-800">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Lembre-se de importar o plano de voo no simulador antes de iniciar o voo.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>

</html>