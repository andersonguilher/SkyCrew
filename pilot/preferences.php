<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);
$daysMap = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
$dbDays = [0, 1, 2, 3, 4, 5, 6];

// Fetch Pilot Table Data (specifically for timezone and simbrief)
$stmt = $pdo->prepare("SELECT timezone, simbrief_username FROM pilots WHERE id = ?");
$stmt->execute([$pilotId]);
$pilotData = $stmt->fetch();
$timezone = $pilotData['timezone'] ?? 'UTC';
$simbriefUsername = $pilotData['simbrief_username'] ?? '';

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        // 1. Update Pilot Timezone & SimBrief
        $newTimezone = $_POST['timezone'] ?? 'UTC';
        $newSimbrief = $_POST['simbrief_username'] ?? '';
        $stmt = $pdo->prepare("UPDATE pilots SET timezone = ?, simbrief_username = ? WHERE id = ?");
        $stmt->execute([$newTimezone, $newSimbrief, $pilotId]);
        $timezone = $newTimezone; // Use new timezone for subsequent conversions

        // 2. Update Schedule Preferences
        $stmt = $pdo->prepare("DELETE FROM pilot_preferences WHERE pilot_id = ?");
        $stmt->execute([$pilotId]);

        $stmt = $pdo->prepare("INSERT INTO pilot_preferences (pilot_id, day_of_week, start_time, end_time, max_daily_hours) VALUES (?, ?, ?, ?, ?)");
        
        if (isset($_POST['pref']) && is_array($_POST['pref'])) {
            foreach ($_POST['pref'] as $day => $data) {
                if (isset($data['active'])) {
                    // Convert Local Input to UTC for Storage
                    $dateToday = date('Y-m-d');
                    $localStart = new DateTime($dateToday . ' ' . $data['start'], new DateTimeZone($timezone));
                    $localEnd = new DateTime($dateToday . ' ' . $data['end'], new DateTimeZone($timezone));
                    
                    $localStart->setTimezone(new DateTimeZone('UTC'));
                    $localEnd->setTimezone(new DateTimeZone('UTC'));

                    $stmt->execute([
                        $pilotId,
                        $day,
                        $localStart->format('H:i:s'),
                        $localEnd->format('H:i:s'),
                        $data['max']
                    ]);
                }
            }
        }

        // 3. Update Aircraft Preferences
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
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Erro ao salvar: " . $e->getMessage());
    }
}

// Fetch Current Schedule Prefs
$stmt = $pdo->prepare("SELECT * FROM pilot_preferences WHERE pilot_id = ?");
$stmt->execute([$pilotId]);
$currentData = [];
foreach ($stmt->fetchAll() as $row) {
    // Convert UTC from DB to Local for Display
    $dateToday = date('Y-m-d');
    $utcStart = new DateTime($dateToday . ' ' . $row['start_time'], new DateTimeZone('UTC'));
    $utcEnd = new DateTime($dateToday . ' ' . $row['end_time'], new DateTimeZone('UTC'));
    
    $utcStart->setTimezone(new DateTimeZone($timezone));
    $utcEnd->setTimezone(new DateTimeZone($timezone));
    
    $row['start_time'] = $utcStart->format('H:i');
    $row['end_time'] = $utcEnd->format('H:i');
    
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


<div class="flex-1 flex flex-col space-y-6 overflow-hidden max-w-6xl mx-auto w-full">
    <div class="flex justify-between items-center shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-sliders-h text-indigo-400"></i> Parâmetros Operacionais
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Configure sua disponibilidade e qualificações</p>
        </div>
        <div class="flex gap-3">
            <a href="dashboard.php" class="bg-white/5 border border-white/10 px-6 py-2.5 rounded-2xl text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:bg-white/10 transition flex items-center gap-2">
                <i class="fas fa-times"></i> Cancelar
            </a>
            <button type="submit" form="prefs-form" class="btn-glow px-8 py-2.5 text-[10px] uppercase font-black tracking-widest flex items-center gap-2">
                <i class="fas fa-save shadow-lg"></i> Salvar Alterações
            </button>
        </div>
    </div>

    <form id="prefs-form" method="POST" class="flex-1 flex flex-col space-y-6 overflow-hidden">
        <div class="flex-1 overflow-y-auto pr-2 custom-scrollbar">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 pb-6">
                <!-- Sidebar: General Config & Aircraft (4 cols) -->
                <div class="lg:col-span-4 space-y-6">
                    <!-- SimBrief Section -->
                    <div class="glass-panel p-6 rounded-3xl border border-white/5 space-y-4">
                        <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2 mb-2">
                            <i class="fas fa-network-wired text-indigo-400"></i> SimBrief
                        </h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center ml-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Username</label>
                                <div id="sb-status" class="text-[8px] font-bold px-2 py-0.5 rounded-full uppercase transition-all duration-300 bg-white/5 text-slate-500">
                                    --
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <input type="text" id="simbrief_username" name="simbrief_username" value="<?php echo htmlspecialchars($simbriefUsername); ?>" class="form-input !bg-white/5 border-white/5 focus:!border-indigo-500/50" placeholder="Seu Username no SimBrief">
                                <button type="button" onclick="checkSimBrief()" class="bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 p-2.5 rounded-xl border border-indigo-500/20 transition-all flex items-center justify-center min-w-[40px]">
                                    <i class="fas fa-sync-alt" id="sb-check-icon"></i>
                                </button>
                            </div>
                            <div id="sb-info" class="hidden space-y-1.5 p-4 bg-black/20 rounded-2xl border border-white/5">
                                <div class="flex justify-between items-center text-[8px] uppercase tracking-widest font-black">
                                    <span class="text-slate-500">Último Plano:</span>
                                    <span id="sb-last-plan" class="text-indigo-300/80">--</span>
                                </div>
                                <div class="flex justify-between items-center text-[8px] uppercase tracking-widest font-black">
                                    <span class="text-slate-500">Voo/Rota:</span>
                                    <span id="sb-route" class="text-white/60">--</span>
                                </div>
                            </div>
                            <div id="sb-login-link" class="hidden">
                                <a href="https://www.simbrief.com/system/login.php" target="_blank" class="text-[9px] text-indigo-400 font-bold hover:underline flex items-center gap-1.5 p-2 bg-indigo-500/5 rounded-lg border border-indigo-500/10">
                                    <i class="fas fa-external-link-alt"></i> Fazer Login no SimBrief
                                </a>
                            </div>
                            <p class="text-[9px] text-slate-500 italic leading-relaxed">* Sua conta SimBrief é usada para o Despacho Operacional.</p>
                        </div>
                    </div>

                    <!-- Timezone Section -->
                    <div class="glass-panel p-6 rounded-3xl border border-white/5 space-y-4">
                        <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2 mb-2">
                            <i class="fas fa-globe text-indigo-400"></i> Fuso Horário
                        </h3>
                        <div class="space-y-3">
                            <select name="timezone" class="form-input !bg-white/5 border-white/5 focus:!border-indigo-500/50">
                                <?php
                                $tzlist = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                                $now = new DateTime('now', new DateTimeZone('UTC'));
                                foreach ($tzlist as $tz) {
                                    $selected = ($tz === $timezone) ? 'selected' : '';
                                    if (strpos($tz, '/') !== false) {
                                        $tzNow = clone $now;
                                        $tzNow->setTimezone(new DateTimeZone($tz));
                                        $timeStr = $tzNow->format('H:i');
                                        echo "<option value=\"$tz\" $selected>$tz ($timeStr)</option>";
                                    }
                                }
                                ?>
                            </select>
                            <p class="text-[9px] text-slate-500 italic leading-relaxed">* Os horários das janelas de voo serão ajustados conforme sua localização.</p>
                        </div>
                    </div>

                    <!-- Aircraft Section -->
                    <div id="aircraft-section" class="glass-panel p-6 rounded-3xl border border-white/5 space-y-4">
                        <div class="flex justify-between items-center">
                            <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                                <i class="fas fa-plane text-indigo-400"></i> Qualificações
                            </h3>
                            <span class="text-[9px] font-bold text-indigo-400/50 bg-indigo-400/5 px-2 py-0.5 rounded-full uppercase"><?php echo count($availableAircraft); ?> Tipos</span>
                        </div>
                        
                        <?php if (empty($availableAircraft)): ?>
                            <p class="text-[10px] text-amber-500/60 font-bold uppercase py-4">Nenhuma aeronave disponível.</p>
                        <?php else: ?>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ($availableAircraft as $ac): 
                                    $checked = in_array($ac, $pilotAircraft) ? 'checked' : '';
                                ?>
                                <label class="group relative cursor-pointer">
                                    <input type="checkbox" name="aircraft[]" value="<?php echo $ac; ?>" class="sr-only peer" <?php echo $checked; ?>>
                                    <div class="p-3 bg-white/5 border border-white/5 rounded-2xl text-center peer-checked:bg-indigo-600/20 peer-checked:border-indigo-500/40 peer-checked:ring-1 peer-checked:ring-indigo-500/20 transition hover:bg-white/10 group-active:scale-95 duration-200">
                                        <i class="fas fa-plane-up text-lg mb-1.5 text-slate-600 group-hover:text-indigo-400 peer-checked:text-indigo-400 transition"></i>
                                        <p class="text-[10px] font-bold text-white uppercase tracking-tighter truncate"><?php echo $ac; ?></p>
                                    </div>
                                    <div class="absolute top-1.5 right-1.5 opacity-0 peer-checked:opacity-100 bg-indigo-500 rounded-full w-3.5 h-3.5 flex items-center justify-center text-[7px] text-white shadow-lg shadow-indigo-500/50">
                                        <i class="fas fa-check"></i>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Main Content: Schedule (8 cols) -->
                <div id="schedule-section" class="lg:col-span-8 flex flex-col space-y-4">
                    <div class="glass-panel p-6 rounded-3xl border border-white/5">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                                <i class="fas fa-calendar-alt text-indigo-400"></i> Janelas de Voo (Horário Local)
                            </h3>
                        </div>

                        <div class="grid grid-cols-1 gap-3">
                            <?php foreach ($dbDays as $idx): 
                                $dayName = $daysMap[$idx];
                                $isActive = isset($currentData[$idx]);
                                $val = $currentData[$idx] ?? ['start_time' => '08:00', 'end_time' => '20:00', 'max_daily_hours' => 8];
                            ?>
                            <div class="flex flex-col md:flex-row items-center gap-4 p-4 rounded-2xl border transition duration-300 <?php echo $isActive ? 'bg-indigo-500/5 border-indigo-500/20' : 'bg-white/5 border-white/5 grayscale pointer-events-none opacity-50'; ?> hover:border-indigo-500/40 relative group day-row" data-day="<?php echo $idx; ?>">
                                <div class="w-full md:w-40 flex items-center shrink-0">
                                    <label class="relative inline-flex items-center cursor-pointer pointer-events-auto">
                                        <input type="checkbox" name="pref[<?php echo $idx; ?>][active]" class="sr-only peer day-toggle" <?php echo $isActive ? 'checked' : ''; ?>>
                                        <div class="w-9 h-5 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-500"></div>
                                        <span class="ml-3 text-sm font-bold text-white uppercase tracking-tighter"><?php echo $dayName; ?></span>
                                    </label>
                                </div>
                                
                                <div class="flex-1 flex items-center gap-3">
                                    <div class="flex flex-col gap-1 w-full max-w-[120px]">
                                        <label class="text-[8px] font-black text-slate-500 uppercase ml-1">Início</label>
                                        <input type="time" name="pref[<?php echo $idx; ?>][start]" value="<?php echo substr($val['start_time'], 0, 5); ?>" class="form-input !py-2 text-center text-xs font-bold">
                                    </div>
                                    <div class="pt-4">
                                        <span class="text-slate-700 text-[10px] font-black">---</span>
                                    </div>
                                    <div class="flex flex-col gap-1 w-full max-w-[120px]">
                                        <label class="text-[8px] font-black text-slate-500 uppercase ml-1">Fim</label>
                                        <input type="time" name="pref[<?php echo $idx; ?>][end]" value="<?php echo substr($val['end_time'], 0, 5); ?>" class="form-input !py-2 text-center text-xs font-bold">
                                    </div>
                                </div>
                                
                                <div class="flex flex-col gap-1 md:w-32">
                                    <label class="text-[8px] font-black text-slate-500 uppercase ml-1 text-center md:text-left">Tempo de Voo</label>
                                    <div class="flex items-center bg-black/40 rounded-xl border border-white/5 px-3">
                                        <input type="number" name="pref[<?php echo $idx; ?>][max]" value="<?php echo $val['max_daily_hours']; ?>" min="1" max="14" class="bg-transparent text-white text-sm font-bold w-full py-2 focus:outline-none text-center">
                                        <span class="text-[10px] text-slate-600 font-bold ml-1">H</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle toggle states visually
    const toggles = document.querySelectorAll('.day-toggle');
    toggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const row = this.closest('.day-row');
            if (this.checked) {
                row.classList.remove('grayscale', 'pointer-events-none', 'opacity-50', 'bg-white/5', 'border-white/5');
                row.classList.add('bg-indigo-500/5', 'border-indigo-500/20');
            } else {
                row.classList.add('grayscale', 'pointer-events-none', 'opacity-50', 'bg-white/5', 'border-white/5');
                row.classList.remove('bg-indigo-500/5', 'border-indigo-500/20');
            }
        });
    });

    const form = document.querySelector('form');
    
    function showTooltip(elementId, message) {
        const target = document.getElementById(elementId);
        const scrollContainer = target.closest('.overflow-y-auto');
        
        // Remove existing tooltips and highlights
        document.querySelectorAll('.validation-tooltip').forEach(el => el.remove());
        document.querySelectorAll('.border-rose-500').forEach(el => el.classList.remove('border-rose-500', 'ring-2', 'ring-rose-500/20'));
        
        // Highlight section
        target.classList.add('border-rose-500', 'ring-2', 'ring-rose-500/20');
        
        const tooltip = document.createElement('div');
        tooltip.className = 'validation-tooltip fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-rose-600 text-white text-xs font-black py-4 px-8 rounded-2xl shadow-[0_0_50px_rgba(0,0,0,0.8),0_0_20px_rgba(225,29,72,0.6)] z-[999] animate-bounce uppercase tracking-[0.2em] pointer-events-none flex flex-col items-center gap-4 border-2 border-white/20 text-center';
        tooltip.innerHTML = `
            <div class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center text-2xl">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <span>${message}</span>
        `;
        
        document.body.appendChild(tooltip);
        
        // Accurate scroll: align the top of the section with the top of the container
        if (scrollContainer) {
            const containerRect = scrollContainer.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const relativeTop = targetRect.top - containerRect.top + scrollContainer.scrollTop;
            
            scrollContainer.scrollTo({ 
                top: relativeTop - 20, 
                behavior: 'smooth' 
            });
        }
        
        // Remove after 4 seconds
        setTimeout(() => {
            tooltip.style.opacity = '0';
            tooltip.style.transition = 'opacity 0.5s';
            setTimeout(() => tooltip.remove(), 500);
            target.classList.remove('border-rose-500', 'ring-2', 'ring-rose-500/20');
        }, 4000);
        
        // Also cleanup on click
        const cleanup = () => {
            tooltip.remove();
            target.classList.remove('border-rose-500', 'ring-2', 'ring-rose-500/20');
            target.removeEventListener('click', cleanup);
        };
        target.addEventListener('click', cleanup);
    }

    form.addEventListener('submit', function(e) {
        const scheduleChecked = form.querySelectorAll('input[name^="pref"][name$="[active]"]:checked').length > 0;
        const aircraftChecked = form.querySelectorAll('input[name="aircraft[]"]:checked').length > 0;
        
        if (!scheduleChecked) {
            e.preventDefault();
            showTooltip('schedule-section', 'Selecione ao menos um dia de voo');
            return;
        }
        
        if (!aircraftChecked) {
            e.preventDefault();
            showTooltip('aircraft-section', 'Selecione ao menos uma aeronave');
            return;
        }
    });
});


function checkSimBrief(isAuto = false, callback = null, customUser = null) {
    const input = document.getElementById('simbrief_username');
    const statusDiv = document.getElementById('sb-status');
    const icon = document.getElementById('sb-check-icon');
    const loginLink = document.getElementById('sb-login-link');
    const infoBox = document.getElementById('sb-info');
    const lastPlanSpan = document.getElementById('sb-last-plan');
    const routeSpan = document.getElementById('sb-route');
    
    const username = customUser || (input ? input.value.trim() : '<?php echo $simbriefUsername; ?>');

    if (!username) {
        if (statusDiv) {
            statusDiv.innerHTML = "Vazio";
            statusDiv.className = "text-[8px] font-bold px-2 py-0.5 rounded-full uppercase bg-amber-500/10 text-amber-500 border border-amber-500/20";
        }
        if (loginLink) loginLink.classList.remove('hidden');
        if (infoBox) infoBox.classList.add('hidden');
        if (callback) callback(false);
        return;
    }

    if (icon) icon.classList.add('animate-spin');
    if (statusDiv) {
        statusDiv.innerHTML = "Verificando...";
        statusDiv.className = "text-[8px] font-bold px-2 py-0.5 rounded-full uppercase bg-indigo-500/10 text-indigo-400 border border-indigo-500/20";
    }

    const apiUrl = '../api/sb_user_check.php';

    fetch(`${apiUrl}?username=${encodeURIComponent(username)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (statusDiv) {
                    statusDiv.innerHTML = "Sincronizado";
                    statusDiv.className = "text-[8px] font-bold px-2 py-0.5 rounded-full uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20";
                }
                if (loginLink) loginLink.classList.add('hidden');
                if (infoBox) {
                    infoBox.classList.remove('hidden');
                    lastPlanSpan.innerText = data.last_plan;
                    routeSpan.innerText = `${data.flight}: ${data.route}`;
                }
                if (callback) callback(true);
            } else {
                if (statusDiv) {
                    statusDiv.innerHTML = "Não Encontrado";
                    statusDiv.className = "text-[8px] font-bold px-2 py-0.5 rounded-full uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20";
                }
                if (loginLink) loginLink.classList.remove('hidden');
                if (infoBox) infoBox.classList.add('hidden');
                if (callback) callback(false);
            }
        })
        .catch(() => {
            if (statusDiv) {
                statusDiv.innerHTML = "Erro";
                statusDiv.className = "text-[8px] font-bold px-2 py-0.5 rounded-full uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20";
            }
            if (infoBox) infoBox.classList.add('hidden');
            if (callback) callback(false);
        })
        .finally(() => {
            if (icon) icon.classList.remove('animate-spin');
        });
}
</script>

<?php include '../includes/layout_footer.php'; ?>
