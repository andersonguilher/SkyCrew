<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../includes/ScheduleMatcher.php';

requireRole('pilot');
$pilotId = getCurrentPilotId($pdo);
$sysSettings = getSystemSettings($pdo); // Fetch Global Settings
$sysSettings = getSystemSettings($pdo); // Fetch Global Settings

// Initialize Matcher
$start_date = date('Y-m-d');
$end_date = date('Y-m-d', strtotime('+7 days'));

// Handle Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $matcher = new ScheduleMatcher($pdo);
    $schedule = $matcher->generateRoster($pilotId, $start_date, $end_date);
    $count = count($schedule);

    if ($count > 0) {
        $_SESSION['flash_msg'] = ["type" => "success", "text" => "Escala gerada com sucesso! $count voos atribuídos."];
    } else {
        $_SESSION['flash_msg'] = ["type" => "error", "text" => "Nenhum voo encontrado. Verifique se há rotas saindo de sua base ({$pilot['current_base']}) compatíveis com suas preferências de horário."];
    }

    header("Location: dashboard.php");
    exit;
}

// Handle Actions
if (isset($_GET['action']) && isset($_GET['roster_id'])) {
    $status = $_GET['action'] == 'accept' ? 'Accepted' : 'Rejected';
    $stmt = $pdo->prepare("UPDATE roster_assignments SET status = ? WHERE id = ? AND pilot_id = ?");
    $stmt->execute([$status, $_GET['roster_id'], $pilotId]);
    $_SESSION['flash_msg'] = ["type" => "success", "text" => "Voo " . ($status == 'Accepted' ? 'Aceito' : 'Recusado') . "."];
    header("Location: dashboard.php");
    exit;
}

// Fetch Pilot Data
// Fetch Pilot Data Enriched
$stmt = $pdo->prepare("SELECT p.*, r.pay_rate, r.image_url as rank_image FROM pilots p LEFT JOIN ranks r ON p.rank = r.rank_name WHERE p.id = ?");
$stmt->execute([$pilotId]);
$pilot = $stmt->fetch();

// Calculate Progress to Next Rank
$stmt = $pdo->prepare("SELECT * FROM ranks WHERE min_hours > ? ORDER BY min_hours ASC LIMIT 1");
$stmt->execute([$pilot['total_hours']]);
$nextRank = $stmt->fetch();

if ($nextRank) {
    $hoursNeeded = $nextRank['min_hours'] - $pilot['total_hours'];
    $rankProgress = ($pilot['total_hours'] / $nextRank['min_hours']) * 100;
} else {
    $rankProgress = 100; // Top rank
    $hoursNeeded = 0;
}

if (!$pilot)
    die("Perfil de piloto não encontrado para este usuário.");

$stmt = $pdo->prepare("SELECT COUNT(*) FROM pilot_preferences WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
$hasPreferences = $stmt->fetchColumn() > 0;

// Fetch Roster
$stmt = $pdo->prepare("
    SELECT r.id as roster_id, r.flight_date, r.status, f.* 
    FROM roster_assignments r 
    JOIN flights_master f ON r.flight_id = f.id 
    WHERE r.pilot_id = ? 
    ORDER BY r.flight_date ASC, f.dep_time ASC
");
$stmt->execute([$pilotId]);
$roster = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Painel do Piloto - <?php echo htmlspecialchars($sysSettings['va_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }

        .glass-panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body class="text-gray-800 bg-fixed bg-cover bg-center"
    style="background-image: url('https://images.unsplash.com/photo-1474302770737-173ee21bab63?auto=format&fit=crop&q=80');">
    <!-- Overlay -->
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-0"></div>

    <!-- Content Wrapper -->
    <div class="relative z-10 min-h-screen flex flex-col">
        <!-- Navbar -->
        <nav class="bg-white/10 backdrop-blur-md border-b border-white/10 shadow-lg sticky top-0 z-50 text-white">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <?php if (!empty($sysSettings['va_logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($sysSettings['va_logo_url']); ?>" alt="Logo"
                            class="h-10 w-auto">
                    <?php else: ?>
                        <i class="fas fa-plane-departure text-2xl text-blue-400"></i>
                    <?php endif; ?>
                    <div>
                        <span
                            class="text-2xl font-bold tracking-tight text-white block leading-none"><?php echo htmlspecialchars($sysSettings['va_name']); ?></span>
                        <span class="text-[10px] uppercase tracking-[0.2em] text-blue-200">Pilot Portal</span>
                    </div>
                </div>

                <!-- UTC Clock -->
                <div class="hidden md:flex items-center bg-black/30 px-4 py-2 rounded-full border border-white/10">
                    <i class="fas fa-clock mr-2 text-blue-400"></i>
                    <span id="utc-clock" class="font-mono font-bold text-lg text-white">00:00:00 Z</span>
                </div>

                <div class="flex items-center space-x-6">
                    <div class="text-right hidden md:block">
                        <p class="font-bold text-sm text-white shadow-sm">
                            <?php echo htmlspecialchars($pilot['name']); ?>
                        </p>
                        <div class="flex items-center justify-end space-x-1 text-xs text-blue-200">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Base <?php echo $pilot['current_base']; ?></span>
                        </div>
                    </div>
                    <a href="../logout.php"
                        class="bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2 rounded text-sm transition backdrop-blur-md">Sair</a>
                </div>
            </div>
        </nav>

        <div class="container mx-auto px-6 py-8 flex-1">

            <?php if (isset($_SESSION['flash_msg'])): ?>
                <div
                    class="mb-6 p-4 rounded-lg shadow-lg border border-opacity-20 backdrop-blur-md flex items-center <?php echo $_SESSION['flash_msg']['type'] == 'success' ? 'bg-green-900/80 border-green-500 text-green-100' : 'bg-red-900/80 border-red-500 text-red-100'; ?>">
                    <i
                        class="fas <?php echo $_SESSION['flash_msg']['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> text-2xl mr-3"></i>
                    <span class="font-medium"><?php echo $_SESSION['flash_msg']['text']; ?></span>
                </div>
                <?php unset($_SESSION['flash_msg']); ?>
            <?php endif; ?>

            <!-- Header & Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <!-- Rank & Progress -->
                <div
                    class="glass-panel p-6 border-t-0 border-l-4 border-blue-500 bg-white/90 backdrop-blur transform hover:-translate-y-1 transition duration-300 relative overflow-hidden">
                    <div class="absolute right-0 top-0 opacity-10 transform translate-x-3 -translate-y-3">
                        <i class="fas fa-medal text-8xl text-blue-900"></i>
                    </div>
                    <h3 class="text-gray-500 text-xs uppercase font-bold tracking-wider mb-2">Carreira -
                        <?php echo $pilot['rank']; ?>
                    </h3>

                    <div class="flex items-end justify-between mb-1">
                        <span
                            class="text-sm font-bold text-gray-700"><?php echo number_format($pilot['total_hours'], 1); ?>h</span>
                        <span
                            class="text-xs text-gray-400"><?php echo $nextRank ? 'Próximo: ' . $nextRank['rank_name'] : 'Rank Máximo'; ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                        <div class="bg-blue-600 h-2 rounded-full"
                            style="width: <?php echo min($rankProgress, 100); ?>%"></div>
                    </div>
                    <p class="text-[10px] text-gray-400 italic">
                        <?php echo $nextRank ? "Faltam " . number_format($hoursNeeded, 1) . "h para promoção" : "Parabéns, Comandante!"; ?>
                    </p>
                </div>

                <!-- Financial -->
                <div
                    class="glass-panel p-6 border-t-0 border-l-4 border-emerald-500 bg-white/90 backdrop-blur transform hover:-translate-y-1 transition duration-300 relative overflow-hidden">
                    <div class="absolute right-0 top-0 opacity-10 transform translate-x-3 -translate-y-3">
                        <i class="fas fa-wallet text-8xl text-emerald-900"></i>
                    </div>
                    <h3 class="text-gray-500 text-xs uppercase font-bold tracking-wider">Finanças</h3>
                    <p class="text-3xl font-black text-gray-800 mt-2">
                        <span
                            class="text-lg align-top text-gray-500"><?php echo $sysSettings['currency_symbol']; ?></span><?php echo number_format($pilot['balance'], 2, ',', '.'); ?>
                    </p>
                    <p class="text-xs text-emerald-600 font-bold mt-1">
                        <i class="fas fa-arrow-up"></i>
                        <?php echo $sysSettings['currency_symbol']; ?><?php echo number_format($pilot['pay_rate'] ?? 15, 2); ?>/hora
                    </p>
                    <a href="paycheck.php"
                        class="text-xs text-blue-500 hover:text-blue-700 font-bold mt-2 block border-t border-gray-100 pt-2">
                        <i class="fas fa-receipt mr-1"></i> Ver Holerite
                    </a>
                </div>

                <!-- Stat Card 3 -->
                <div
                    class="glass-panel p-6 border-t-0 border-l-4 border-indigo-500 bg-white/90 backdrop-blur transform hover:-translate-y-1 transition duration-300 relative overflow-hidden">
                    <div class="absolute right-0 top-0 opacity-10 transform translate-x-3 -translate-y-3">
                        <i class="fas fa-plane text-8xl text-indigo-900"></i>
                    </div>
                    <h3 class="text-gray-500 text-xs uppercase font-bold tracking-wider">Escala Ativa</h3>
                    <p class="text-3xl font-black text-gray-800 mt-2">
                        <?php echo count($roster); ?><span class="text-sm text-gray-400 ml-1">voos</span>
                    </p>
                </div>

                <!-- Action Card -->
                <a href="preferences.php"
                    class="group relative overflow-hidden rounded-xl border border-white/20 shadow-xl bg-gradient-to-br from-slate-800 to-slate-900 hover:from-slate-700 hover:to-slate-800 transition p-6 flex flex-col items-center justify-center text-center cursor-pointer">
                    <div class="absolute inset-0 bg-white/5 opacity-0 group-hover:opacity-100 transition duration-500">
                    </div>
                    <i
                        class="fas fa-sliders-h text-3xl text-indigo-400 mb-2 group-hover:scale-110 transition duration-300"></i>
                    <span class="text-gray-100 font-bold group-hover:text-white transition">Preferências</span>
                    <span class="text-xs text-slate-400 mt-1">Ajustar Disponibilidade</span>
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                <!-- Left Column: Actions -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Generator Card -->
                    <div class="bg-white/90 backdrop-blur rounded-xl shadow-lg p-1">
                        <div class="p-5 border-b border-gray-100">
                            <h2 class="text-lg font-bold text-gray-800 flex items-center">
                                <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i> PBS System
                            </h2>
                        </div>
                        <div class="p-6">
                            <p class="text-sm text-gray-600 mb-6 leading-relaxed">
                                O algoritmo <strong>Preferential Bidding System</strong> analisa suas restrições de
                                horário, base e fadiga para gerar a melhor escala possível.
                            </p>
                            <form method="POST">
                                <button type="submit" name="generate"
                                    class="w-full bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 text-white font-bold py-4 px-6 rounded-lg transition duration-200 shadow-lg transform hover:-translate-y-0.5 flex justify-center items-center group">
                                    <span>Gerar Escala Semanal</span>
                                    <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition"></i>
                                </button>
                            </form>
                            <div
                                class="mt-4 flex items-center justify-center space-x-2 text-xs text-gray-400 bg-gray-50 py-2 rounded">
                                <i class="far fa-clock"></i>
                                <span>Período:
                                    <?php echo date('d/m', strtotime($start_date)) . ' - ' . date('d/m', strtotime($end_date)); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Info Card -->
                    <div
                        class="bg-blue-900/80 backdrop-blur text-blue-100 rounded-xl p-6 border border-blue-700/50 shadow-lg">
                        <h2 class="text-sm font-bold text-white mb-3 flex items-center">
                            <i class="fas fa-info-circle mr-2"></i> Importante
                        </h2>
                        <ul class="text-xs space-y-2 opacity-90">
                            <li class="flex items-start"><i class="fas fa-check mt-0.5 mr-2 opacity-70"></i> Aceite os
                                voos 24h antes da decolagem.</li>
                            <li class="flex items-start"><i class="fas fa-check mt-0.5 mr-2 opacity-70"></i> Voos
                                rejeitados voltam ao pool.</li>
                            <li class="flex items-start"><i class="fas fa-check mt-0.5 mr-2 opacity-70"></i> Use o ACARS
                                para reportar horas.</li>
                        </ul>
                    </div>
                </div>

                <!-- Right Column: Schedule -->
                <div class="lg:col-span-2">
                    <h2 class="text-xl font-bold text-white mb-4 flex items-center text-shadow">
                        <i class="fas fa-list-ul mr-2 opacity-80"></i> Sua Escala
                    </h2>

                    <?php if (empty($roster)): ?>
                        <div
                            class="bg-white/90 backdrop-blur rounded-xl shadow-lg p-12 text-center text-gray-500 border-2 border-dashed border-gray-300">
                            <?php if (!$hasPreferences): ?>
                                <div
                                    class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-orange-100 text-orange-500 mb-4">
                                    <i class="fas fa-exclamation-triangle text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">Preferências Ausentes</h3>
                                <p class="text-sm mb-6 max-w-xs mx-auto">Você precisa definir sua disponibilidade e aeronaves
                                    para receber voos.</p>
                                <a href="preferences.php"
                                    class="inline-block bg-orange-500 text-white font-bold py-2 px-6 rounded hover:bg-orange-600 transition shadow-lg">
                                    Configurar Agora
                                </a>
                            <?php else: ?>
                                <div
                                    class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                                    <i class="fas fa-inbox text-2xl"></i>
                                </div>
                                <p class="text-lg font-medium text-gray-800">Nenhum voo na escala.</p>
                                <p class="text-sm">Clique em "Gerar Escala" para iniciar o processo.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($roster as $flight): ?>
                                <?php
                                $statusClass = 'border-l-4 border-yellow-400';
                                $badgeClass = 'bg-yellow-100 text-yellow-800';
                                $statusIcon = 'fa-clock';
                                $statusText = $flight['status'];

                                if ($flight['status'] == 'Accepted') {
                                    $statusClass = 'border-l-4 border-emerald-500';
                                    $badgeClass = 'bg-emerald-100 text-emerald-800';
                                    $statusIcon = 'fa-check-circle';
                                    $statusText = 'Confirmado';
                                } else if ($flight['status'] == 'Rejected') {
                                    $statusClass = 'border-l-4 border-red-500 opacity-75 grayscale';
                                    $badgeClass = 'bg-red-100 text-red-800';
                                    $statusIcon = 'fa-times-circle';
                                    $statusText = 'Recusado';
                                } else if ($flight['status'] == 'Suggested') {
                                    $statusText = 'Sugestão';
                                } else if ($flight['status'] == 'Flown') {
                                    $statusClass = 'border-l-4 border-blue-500 bg-blue-50/90';
                                    $badgeClass = 'bg-blue-100 text-blue-800';
                                    $statusIcon = 'fa-plane-arrival';
                                    $statusText = 'Voado';
                                }
                                ?>
                                <div
                                    class="bg-white/95 backdrop-blur rounded-lg shadow-md hover:shadow-xl transition duration-300 relative overflow-hidden <?php echo $statusClass; ?>">

                                    <!-- Date Header -->
                                    <div
                                        class="px-6 py-2 bg-gray-50 flex justify-between items-center text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-100">
                                        <span>
                                            <?php
                                            echo date('l', strtotime($flight['flight_date'])) . ', ' . date('d M', strtotime($flight['flight_date']));
                                            ?>
                                        </span>
                                        <span class="<?php echo $badgeClass; ?> px-2 py-0.5 rounded flex items-center gap-1">
                                            <i class="fas <?php echo $statusIcon; ?>"></i> <?php echo $statusText; ?>
                                        </span>
                                    </div>

                                    <div class="p-5 flex flex-col md:flex-row justify-between items-center">

                                        <!-- Flight Info -->
                                        <div class="flex items-center space-x-6 w-full md:w-auto mb-4 md:mb-0">
                                            <div
                                                class="h-12 w-12 rounded-full bg-slate-800 text-white flex items-center justify-center font-bold text-xs shadow-lg">
                                                <?php echo substr($flight['flight_number'], 0, 2); ?>
                                            </div>
                                            <div>
                                                <div class="text-2xl font-black text-slate-800 leading-none">
                                                    <?php echo $flight['flight_number']; ?>
                                                </div>
                                                <div class="text-xs font-bold text-gray-400 mt-1 uppercase tracking-wider">
                                                    <?php echo $flight['aircraft_type']; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Route Visualization -->
                                        <div class="flex-1 px-4 md:px-12 w-full flex items-center justify-center">
                                            <div class="text-center w-16">
                                                <div class="text-xl font-bold text-gray-700"><?php echo $flight['dep_icao']; ?>
                                                </div>
                                                <div class="text-xs text-gray-500 font-mono bg-gray-100 rounded px-1">
                                                    <?php echo substr($flight['dep_time'], 0, 5); ?> Z
                                                </div>
                                            </div>

                                            <div class="flex-1 mx-4 flex flex-col items-center relative">
                                                <!-- Line -->
                                                <div class="w-full h-0.5 bg-gray-300 relative top-3"></div>
                                                <!-- Plane Icon -->
                                                <i class="fas fa-plane text-indigo-500 absolute top-0.5 animate-pulse"></i>
                                                <!-- Duration -->
                                                <div class="text-[10px] text-gray-400 mt-4">
                                                    <?php echo intval($flight['duration_minutes'] / 60); ?>h
                                                    <?php echo $flight['duration_minutes'] % 60; ?>m
                                                </div>
                                            </div>

                                            <div class="text-center w-16">
                                                <div class="text-xl font-bold text-gray-700"><?php echo $flight['arr_icao']; ?>
                                                </div>
                                                <div class="text-xs text-gray-500 font-mono bg-gray-100 rounded px-1">
                                                    <?php echo substr($flight['arr_time'], 0, 5); ?> Z
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="min-w-[140px] flex justify-end">
                                            <?php if ($flight['status'] == 'Suggested'): ?>
                                                <div class="flex space-x-2">
                                                    <a href="?action=accept&roster_id=<?php echo $flight['roster_id']; ?>"
                                                        class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold p-2 rounded-lg shadow transition hover:-translate-y-0.5"
                                                        title="Aceitar">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                    <a href="?action=reject&roster_id=<?php echo $flight['roster_id']; ?>"
                                                        class="bg-slate-200 hover:bg-slate-300 text-slate-600 text-xs font-bold p-2 rounded-lg shadow transition hover:-translate-y-0.5"
                                                        title="Recusar">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                </div>
                                            <?php elseif ($flight['status'] == 'Accepted'): ?>
                                                <!-- Action: Briefing / Flight Ops -->
                                                <div class="flex flex-col space-y-2 w-full min-w-[120px]">
                                                    <a href="briefing.php?flight_id=<?php echo $flight['roster_id']; ?>"
                                                        class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2 px-3 rounded shadow transition text-center flex items-center justify-center gap-2 group">
                                                        <i class="fas fa-file-alt group-hover:scale-110 transition"></i>
                                                        <span>Briefing</span>
                                                    </a>
                                                    <div
                                                        class="bg-indigo-50 border border-indigo-100 rounded px-2 py-1 text-center">
                                                        <div
                                                            class="flex items-center justify-center gap-1 text-[10px] font-bold text-indigo-600 uppercase">
                                                            <span class="relative flex h-2 w-2">
                                                                <span
                                                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                                                <span
                                                                    class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                                                            </span>
                                                            ACARS Ready
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Clock Script -->
    <script>
        function updateClock() {
            const now = new Date();
            const timeString = now.toISOString().substring(11, 19) + ' Z';
            document.getElementById('utc-clock').textContent = timeString;
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>

</html>