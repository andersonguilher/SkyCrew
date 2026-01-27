<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$daysMap = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
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
        $_SESSION['flash_msg'] = ["type" => "success", "text" => "Preferências atualizadas com sucesso."];
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

$pageTitle = "Preferências - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden max-w-5xl mx-auto w-full">
    <div class="flex justify-between items-end shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-sliders-h text-indigo-400"></i> Parâmetros Operacionais
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Configure sua disponibilidade e qualificações</p>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php" class="bg-white/5 border border-white/10 px-4 py-2 rounded-2xl text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:bg-white/10 transition">
                <i class="fas fa-arrow-left mr-2"></i> Cancelar
            </a>
        </div>
    </div>

    <form method="POST" class="flex-1 flex flex-col space-y-6 overflow-hidden">
        <div class="flex-1 overflow-y-auto pr-2 custom-scrollbar space-y-6">
            <!-- Schedule Section -->
            <div class="glass-panel p-8 rounded-3xl space-y-4">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-calendar-alt text-indigo-400"></i> Janelas de Voo (UTC)
                </h3>
                <div class="grid grid-cols-1 gap-3">
                    <?php foreach ($dbDays as $idx): 
                        $dayName = $daysMap[$idx];
                        $isActive = isset($currentData[$idx]);
                        $val = $currentData[$idx] ?? ['start_time' => '08:00', 'end_time' => '20:00', 'max_daily_hours' => 8];
                    ?>
                    <div class="flex flex-col md:flex-row items-center gap-4 p-4 bg-white/5 rounded-2xl border border-white/5 group hover:bg-white/10 transition">
                        <div class="w-full md:w-40 flex items-center">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="pref[<?php echo $idx; ?>][active]" class="sr-only peer" <?php echo $isActive ? 'checked' : ''; ?>>
                                <div class="w-9 h-5 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-500"></div>
                                <span class="ml-3 text-sm font-bold text-white uppercase tracking-tighter"><?php echo $dayName; ?></span>
                            </label>
                        </div>
                        
                        <div class="flex-1 flex items-center gap-3">
                            <input type="time" name="pref[<?php echo $idx; ?>][start]" value="<?php echo substr($val['start_time'], 0, 5); ?>" class="form-input text-center max-w-[120px]">
                            <span class="text-slate-600 text-[10px] font-bold">ATÉ</span>
                            <input type="time" name="pref[<?php echo $idx; ?>][end]" value="<?php echo substr($val['end_time'], 0, 5); ?>" class="form-input text-center max-w-[120px]">
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <span class="text-[9px] text-slate-500 font-bold uppercase">Limite Diário:</span>
                            <div class="flex items-center bg-black/40 rounded-xl border border-white/5 px-3">
                                <input type="number" name="pref[<?php echo $idx; ?>][max]" value="<?php echo $val['max_daily_hours']; ?>" min="1" max="14" class="bg-transparent text-white text-sm font-bold w-12 py-2 focus:outline-none">
                                <span class="text-[10px] text-slate-600 font-bold">H</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Aircraft Section -->
            <div class="glass-panel p-8 rounded-3xl space-y-4">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-plane text-indigo-400"></i> Qualificações de Aeronave
                </h3>
                <?php if (empty($availableAircraft)): ?>
                    <p class="text-xs text-amber-500/60 font-bold uppercase">Nenhuma aeronave disponível no sistema.</p>
                <?php else: ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
                        <?php foreach ($availableAircraft as $ac): 
                            $checked = in_array($ac, $pilotAircraft) ? 'checked' : '';
                        ?>
                        <label class="group relative cursor-pointer">
                            <input type="checkbox" name="aircraft[]" value="<?php echo $ac; ?>" class="sr-only peer" <?php echo $checked; ?>>
                            <div class="p-4 bg-white/5 border border-white/5 rounded-2xl text-center peer-checked:bg-indigo-600/20 peer-checked:border-indigo-500/50 transition hover:bg-white/10">
                                <i class="fas fa-plane-up text-xl mb-2 text-slate-600 group-hover:text-indigo-400 peer-checked:text-indigo-400 transition"></i>
                                <p class="text-xs font-bold text-white uppercase tracking-tighter"><?php echo $ac; ?></p>
                            </div>
                            <div class="absolute top-2 right-2 opacity-0 peer-checked:opacity-100 bg-indigo-500 rounded-full w-4 h-4 flex items-center justify-center text-[8px] text-white">
                                <i class="fas fa-check"></i>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="shrink-0 pt-4 flex justify-center">
            <button type="submit" class="btn-glow px-12 py-4 text-xs uppercase tracking-[0.2em] shadow-[0_0_30px_rgba(99,102,241,0.3)]">
                Salvar Configurações
            </button>
        </div>
    </form>
</div>

<?php include '../includes/layout_footer.php'; ?>
