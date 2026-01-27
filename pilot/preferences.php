<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$daysMap = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
// Original indexes for DB: 0=Sunday...
$dbDays = [0, 1, 2, 3, 4, 5, 6];

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        // 1. Update Schedule Preferences
        $stmt = $pdo->prepare("DELETE FROM pilot_preferences WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);

        $stmt = $pdo->prepare("INSERT INTO pilot_preferences (pilot_id, day_of_week, start_time, end_time, max_daily_hours) VALUES (?, ?, ?, ?, ?)");
        
        if (isset($_POST['pref']) && is_array($_POST['pref'])) {
            foreach ($_POST['pref'] as $day => $data) {
                if (isset($data['active'])) {
                    $stmt->execute([
                        $pilotId,
                        $day,
                        $data['start'],
                        $data['end'],
                        $data['max']
                    ]);
                }
            }
        }

        // 2. Update Aircraft Preferences
        $stmt = $pdo->prepare("DELETE FROM pilot_aircraft_prefs WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);

        if (isset($_POST['aircraft']) && is_array($_POST['aircraft'])) {
            $stmt = $pdo->prepare("INSERT INTO pilot_aircraft_prefs (pilot_id, aircraft_type) VALUES (?, ?)");
            foreach ($_POST['aircraft'] as $ac) {
                $stmt->execute([$pilotId, $ac]);
            }
        }

        $pdo->commit();
        header("Location: dashboard.php");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Erro ao salvar: " . $e->getMessage());
    }
}

// Fetch Current Schedule Prefs
$stmt = $pdo->prepare("SELECT * FROM pilot_preferences WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
$currentData = [];
foreach ($stmt->fetchAll() as $row) {
    $currentData[$row['day_of_week']] = $row;
}

// Fetch Available Aircraft Types
$stmt = $pdo->query("SELECT DISTINCT aircraft_type FROM flights_master ORDER BY aircraft_type");
$availableAircraft = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Current Aircraft Prefs
$stmt = $pdo->prepare("SELECT aircraft_type FROM pilot_aircraft_prefs WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
$pilotAircraft = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Preferências - SkyCrew</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
            <h1 class="text-white text-xl font-bold">Editar Preferências Operacionais</h1>
            <a href="dashboard.php" class="text-indigo-200 hover:text-white text-sm">Voltar ao Painel</a>
        </div>
        
        <form method="POST" class="p-6 divide-y divide-gray-200">
            
            <!-- Schedule Section -->
            <div class="pb-8">
                <h2 class="text-lg font-bold text-gray-800 mb-2">Disponibilidade Semanal</h2>
                <p class="text-sm text-gray-500 mb-6">Defina suas janelas de voo preferidas (UTC) e máximo de horas.</p>
                
                <div class="space-y-4">
                    <?php foreach ($dbDays as $idx): 
                        $dayName = $daysMap[$idx];
                        $isActive = isset($currentData[$idx]);
                        $val = $currentData[$idx] ?? ['start_time' => '08:00', 'end_time' => '20:00', 'max_daily_hours' => 8];
                    ?>
                    <div class="flex items-center space-x-4 hover:bg-gray-50 p-2 rounded">
                        <div class="w-32 flex items-center">
                            <input type="checkbox" name="pref[<?php echo $idx; ?>][active]" id="day_<?php echo $idx; ?>" 
                                   class="mr-2 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                   <?php echo $isActive ? 'checked' : ''; ?>>
                            <label for="day_<?php echo $idx; ?>" class="font-medium text-gray-700"><?php echo $dayName; ?></label>
                        </div>
                        
                        <div class="flex items-center space-x-2">
                            <input type="time" name="pref[<?php echo $idx; ?>][start]" value="<?php echo substr($val['start_time'], 0, 5); ?>" class="border rounded px-2 py-1 text-sm bg-white">
                            <span class="text-gray-400">até</span>
                            <input type="time" name="pref[<?php echo $idx; ?>][end]" value="<?php echo substr($val['end_time'], 0, 5); ?>" class="border rounded px-2 py-1 text-sm bg-white">
                        </div>
                        
                        <div class="flex items-center space-x-2 ml-auto">
                            <label class="text-xs text-gray-500">Máx Horas:</label>
                            <input type="number" name="pref[<?php echo $idx; ?>][max]" value="<?php echo $val['max_daily_hours']; ?>" min="1" max="14" class="border rounded w-16 px-2 py-1 text-sm bg-white">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Aircraft Section -->
            <div class="pt-8">
                <h2 class="text-lg font-bold text-gray-800 mb-2">Preferência de Aeronaves</h2>
                <p class="text-sm text-gray-500 mb-4">Selecione as aeronaves que você prefere (ou está qualificado) voar. Se nenhuma for selecionada, todas serão consideradas.</p>
                
                <?php if (empty($availableAircraft)): ?>
                    <p class="text-sm text-yellow-600">Nenhuma aeronave cadastrada no sistema ainda.</p>
                <?php else: ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ($availableAircraft as $ac): 
                            $checked = in_array($ac, $pilotAircraft) ? 'checked' : '';
                        ?>
                        <div class="flex items-center p-3 border rounded hover:border-indigo-300 bg-gray-50">
                            <input type="checkbox" name="aircraft[]" value="<?php echo $ac; ?>" id="ac_<?php echo $ac; ?>" 
                                   class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded mr-2"
                                   <?php echo $checked; ?>>
                            <label for="ac_<?php echo $ac; ?>" class="text-gray-800 font-medium"><?php echo $ac; ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-8 flex justify-end">
                <button type="submit" class="bg-indigo-600 text-white font-bold py-2 px-6 rounded hover:bg-indigo-700 transition">Salvar Tudo</button>
            </div>
        </form>
    </div>
</body>
</html>
