<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsToSave = [
        'va_name' => $_POST['va_name'] ?? '',
        'va_callsign' => $_POST['va_callsign'] ?? '',
        'va_logo_url' => $_POST['va_logo_url'] ?? '',
        'hotel_daily_rate' => $_POST['hotel_daily_rate'] ?? '100.00',
        'breakfast_cost' => $_POST['breakfast_cost'] ?? '15.00',
        'lunch_cost' => $_POST['lunch_cost'] ?? '20.00',
        'dinner_cost' => $_POST['dinner_cost'] ?? '15.00',
        'currency_symbol' => $_POST['currency_symbol'] ?? 'R$',
        'simbrief_api_key' => $_POST['simbrief_api_key'] ?? '',
        'fleet_registration_prefixes' => $_POST['fleet_registration_prefixes'] ?? '',
        'pbs_generation_day' => $_POST['pbs_generation_day'] ?? '0',
        'enforce_flight_windows' => isset($_POST['enforce_flight_windows']) ? '1' : '0'
    ];

    try {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settingsToSave as $key => $value) {
            $finalValue = ($key === 'va_callsign') ? strtoupper($value) : $value;
            $stmt->execute([$key, $finalValue]);
        }
        $message = "Configurações atualizadas!";
    } catch (Exception $e) {
        $message = "Erro: " . $e->getMessage();
    }
}

// Fetch current settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$settings = array_merge([
    'va_name' => 'SkyCrew Virtual Airline',
    'va_callsign' => 'SKY',
    'va_logo_url' => '',
    'hotel_daily_rate' => '100.00',
    'breakfast_cost' => '15.00',
    'lunch_cost' => '20.00',
    'dinner_cost' => '15.00',
    'currency_symbol' => 'R$',
    'simbrief_api_key' => '',
    'fleet_registration_prefixes' => 'PR,PT,PP,PS,PU',
    'pbs_generation_day' => '0',
    'enforce_flight_windows' => '1'
], $rows);

$pageTitle = "Configurações - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden w-full">
    <div class="flex justify-between items-center shrink-0">
        <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                <i class="fas fa-sliders-h text-indigo-400 text-sm"></i> Configurações
            </h2>
        </div>
        <?php if ($message): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/20 px-4 py-1.5 rounded-full text-emerald-400 text-[10px] font-bold">
                <i class="fas fa-check mr-1"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="POST" class="flex-1 overflow-y-auto space-y-4 pr-2 custom-scrollbar pb-32">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
            <!-- Sidebar Nav (Minimalist Tabs Concept) -->
            <div class="md:col-span-3 space-y-1">
                <button type="button" onclick="switchTab('general')" id="tab-btn-general" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-bold text-[11px] uppercase tracking-wider text-indigo-400 bg-indigo-500/10 border border-indigo-500/20">
                    <i class="fas fa-id-card w-4"></i> Identidade & Operação
                </button>
                <button type="button" onclick="switchTab('financial')" id="tab-btn-financial" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-bold text-[11px] uppercase tracking-wider text-slate-400 hover:bg-white/5 border border-transparent">
                    <i class="fas fa-wallet w-4"></i> Financeiro
                </button>
                <button type="button" onclick="switchTab('integrations')" id="tab-btn-integrations" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-bold text-[11px] uppercase tracking-wider text-slate-400 hover:bg-white/5 border border-transparent">
                    <i class="fas fa-network-wired w-4"></i> Integrações & PBS
                </button>
            </div>

            <!-- Content Area -->
            <div class="md:col-span-9">
                <!-- Tab: General -->
                <div id="tab-general" class="tab-content space-y-4">
                    <div class="glass-panel p-6 rounded-2xl border-white/5">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">VA Name</label>
                                <input type="text" name="va_name" value="<?php echo htmlspecialchars($settings['va_name']); ?>" class="form-input !bg-white/5 !border-white/5 focus:!border-indigo-500/50" placeholder="SkyCrew Virtual">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Callsign</label>
                                <input type="text" name="va_callsign" value="<?php echo htmlspecialchars($settings['va_callsign']); ?>" class="form-input uppercase !bg-white/5 !border-white/5 focus:!border-indigo-500/50" placeholder="SKY">
                            </div>
                            <div class="col-span-2 space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">VA Logo URL</label>
                                <input type="text" name="va_logo_url" value="<?php echo htmlspecialchars($settings['va_logo_url']); ?>" class="form-input !bg-white/5 !border-white/5 focus:!border-indigo-500/50" placeholder="https://...">
                            </div>
                            <div class="col-span-2 space-y-1 pt-2">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Prefixos Frota (CSV)</label>
                                <input type="text" name="fleet_registration_prefixes" value="<?php echo htmlspecialchars($settings['fleet_registration_prefixes']); ?>" class="form-input !bg-white/5 !border-white/5 focus:!border-indigo-500/50" placeholder="PR,PT,PP,PS">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Financial -->
                <div id="tab-financial" class="tab-content hidden space-y-4">
                    <div class="glass-panel p-6 rounded-2xl border-white/5">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Moeda</label>
                                <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars($settings['currency_symbol']); ?>" class="form-input !bg-white/5" placeholder="R$">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Diária Hotel</label>
                                <input type="number" step="0.01" name="hotel_daily_rate" value="<?php echo htmlspecialchars($settings['hotel_daily_rate']); ?>" class="form-input !bg-white/5">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Desjejum</label>
                                <input type="number" step="0.01" name="breakfast_cost" value="<?php echo htmlspecialchars($settings['breakfast_cost']); ?>" class="form-input !bg-white/5">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Almoço</label>
                                <input type="number" step="0.01" name="lunch_cost" value="<?php echo htmlspecialchars($settings['lunch_cost']); ?>" class="form-input !bg-white/5">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Janta</label>
                                <input type="number" step="0.01" name="dinner_cost" value="<?php echo htmlspecialchars($settings['dinner_cost']); ?>" class="form-input !bg-white/5">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Integrations -->
                <div id="tab-integrations" class="tab-content hidden space-y-4">
                    <!-- SimBrief Container -->
                    <div class="glass-panel p-6 rounded-2xl border-white/5">
                        <h4 class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                             <i class="fas fa-network-wired"></i> SimBrief
                        </h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2 space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">API Key</label>
                                <input type="password" name="simbrief_api_key" value="<?php echo htmlspecialchars($settings['simbrief_api_key'] ?? ''); ?>" class="form-input !bg-white/5" placeholder="••••••••">
                            </div>
                        </div>
                    </div>

                    <!-- PBS Container -->
                    <div class="glass-panel p-6 rounded-2xl border-white/5 border-t-2 border-t-indigo-500/30">
                        <h4 class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest mb-4 flex items-center gap-2 text-indigo-300">
                             <i class="fas fa-calendar-check"></i> Escala PBS
                        </h4>
                        <div class="space-y-4">
                            <div class="space-y-1">
                                <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest ml-1">Renovação Semanal</label>
                                <select name="pbs_generation_day" onchange="updatePBSInfo()" class="form-input !bg-white/5 border-white/5">
                                    <option value="0" <?php echo $settings['pbs_generation_day'] == '0' ? 'selected' : ''; ?>>Domingo</option>
                                    <option value="1" <?php echo $settings['pbs_generation_day'] == '1' ? 'selected' : ''; ?>>Segunda-feira</option>
                                    <option value="2" <?php echo $settings['pbs_generation_day'] == '2' ? 'selected' : ''; ?>>Terça-feira</option>
                                    <option value="3" <?php echo $settings['pbs_generation_day'] == '3' ? 'selected' : ''; ?>>Quarta-feira</option>
                                    <option value="4" <?php echo $settings['pbs_generation_day'] == '4' ? 'selected' : ''; ?>>Quinta-feira</option>
                                    <option value="5" <?php echo $settings['pbs_generation_day'] == '5' ? 'selected' : ''; ?>>Sexta-feira</option>
                                    <option value="6" <?php echo $settings['pbs_generation_day'] == '6' ? 'selected' : ''; ?>>Sábado</option>
                                </select>
                            </div>

                            <div id="pbs-info" class="p-4 bg-indigo-500/5 rounded-2xl border border-indigo-500/10 text-[11px] text-indigo-200">
                                Calculando previsão...
                            </div>

                            <div class="flex gap-2">
                                <button type="button" onclick="handlePBS('generate', this)" class="flex-1 bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 py-2 rounded-lg text-[9px] font-bold uppercase tracking-widest transition">
                                    <i class="fas fa-magic mr-1"></i> Gerar Agora
                                </button>
                                <button type="button" onclick="handlePBS('clear', this)" class="flex-1 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 py-2 rounded-lg text-[9px] font-bold uppercase tracking-widest transition">
                                    <i class="fas fa-trash-alt mr-1"></i> Limpar
                                </button>
                            </div>
                        </div>

                    </div>
                    
                    <!-- Scheduling Rules -->
                    <div class="glass-panel p-6 rounded-2xl border-white/5 border-t-2 border-t-amber-500/30">
                        <h4 class="text-[10px] font-bold text-amber-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                             <i class="fas fa-calendar-alt"></i> Regras de Escala
                        </h4>
                        
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest block">Respeitar Janelas de Voo</span>
                                    <span class="text-[9px] text-slate-600">Se desativado, o sistema ignorará as preferências de horário dos pilotos.</span>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="enforce_flight_windows" class="sr-only peer" <?php echo ($settings['enforce_flight_windows'] == '1') ? 'checked' : ''; ?>>
                                    <div class="w-9 h-5 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-amber-500"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="fixed bottom-6 right-6">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white px-8 py-3 rounded-full text-[10px] font-bold uppercase tracking-[2px] shadow-2xl shadow-indigo-500/40 transition-all active:scale-95 flex items-center gap-2">
                <i class="fas fa-save"></i> Salvar Alterações
            </button>
        </div>
    </form>
</div>

<script>
function switchTab(tabId) {
    // Hide all content
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    // Show selected
    document.getElementById('tab-' + tabId).classList.remove('hidden');
    
    // Reset buttons
    const btns = ['general', 'financial', 'integrations'];
    btns.forEach(b => {
        const el = document.getElementById('tab-btn-' + b);
        el.classList.remove('bg-indigo-500/10', 'border-indigo-500/20', 'text-indigo-400');
        el.classList.add('text-slate-400', 'border-transparent');
    });
    
    // Activate current button
    const activeBtn = document.getElementById('tab-btn-' + tabId);
    activeBtn.classList.remove('text-slate-400', 'border-transparent');
    activeBtn.classList.add('bg-indigo-500/10', 'border-indigo-500/20', 'text-indigo-400');
}

function handlePBS(action, btn) {
    if (action === 'clear' && !confirm('Apagar todas as escalas?')) return;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner animate-spin"></i>';
    fetch('process_pbs.php?action=' + action).then(r => r.json()).then(data => {
        alert(data.message);
        if (data.success) location.reload();
    }).finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function updatePBSInfo() {
    const daySelect = document.querySelector('select[name="pbs_generation_day"]');
    const infoDiv = document.getElementById('pbs-info');
    const targetDay = parseInt(daySelect.value);
    const genDay = (targetDay - 2 + 7) % 7;
    const daysArr = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    
    const now = new Date();
    let nextGen = new Date();
    nextGen.setUTCHours(10, 0, 0, 0);
    
    let isDelayed = false;
    let iterations = 0;
    while (nextGen.getUTCDay() !== genDay || nextGen < now) {
        if (nextGen.getUTCDay() === genDay && nextGen < now && iterations === 0) isDelayed = true;
        nextGen.setUTCDate(nextGen.getUTCDate() + 1);
        iterations++;
    }
    
    const dateStr = nextGen.toLocaleDateString('pt-BR');
    const timeStr = nextGen.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    
    let html = `<span class="opacity-60 uppercase font-bold text-[9px] block mb-1">Status PBS</span>`;
    html += `Escala renovada toda <span class="text-white font-bold">${daysArr[targetDay]}</span>.<br>`;
    
    if (isDelayed) {
        html += `<span class="text-amber-400 font-bold mt-1 inline-block">Muda para a próxima semana: ${dateStr} às ${timeStr}</span>`;
    } else {
        html += `<span class="text-indigo-300 mt-1 inline-block">Agendado: ${dateStr} às ${timeStr}</span>`;
    }
    
    infoDiv.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', updatePBSInfo);
</script>

<?php include '../includes/layout_footer.php'; ?>