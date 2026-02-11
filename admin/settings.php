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
        'simbrief_username' => $_POST['simbrief_username'] ?? '',
        'simbrief_api_key' => $_POST['simbrief_api_key'] ?? '',
        'fleet_registration_prefixes' => $_POST['fleet_registration_prefixes'] ?? ''
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
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // key => value
$settings = array_merge([
    'va_name' => 'SkyCrew Virtual Airline',
    'va_callsign' => 'SKY',
    'va_logo_url' => '',
    'hotel_daily_rate' => '100.00',
    'breakfast_cost' => '15.00',
    'lunch_cost' => '20.00',
    'dinner_cost' => '15.00',
    'currency_symbol' => 'R$',
    'simbrief_username' => '',
    'simbrief_api_key' => '',
    'fleet_registration_prefixes' => 'PR,PT,PP,PS,PU'
], $rows);

$pageTitle = "Configurações - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden">
    <div class="flex justify-between items-center shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-sliders-h text-indigo-400"></i> Painel de Controle
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">Configurações Gerais do Núcleo</p>
        </div>
        <?php if ($message): ?>
            <div class="glass-panel border-l-4 border-emerald-500 px-6 py-2 rounded-2xl text-emerald-400 text-[11px] font-bold animate-pulse">
                <i class="fas fa-check-circle mr-2"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="POST" class="flex-1 overflow-y-auto space-y-6 pr-2 custom-scrollbar pb-12">
        <!-- VA Identity Section -->
        <div class="glass-panel rounded-3xl overflow-hidden">
            <div class="p-6 border-b border-white/10 bg-white/5">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-id-card text-indigo-400"></i> Identidade Visual
                </h3>
            </div>
            <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Nome da Companhia</label>
                    <input type="text" name="va_name" value="<?php echo htmlspecialchars($settings['va_name']); ?>" class="form-input" placeholder="Ex: SkyCrew Virtual">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Callsign / Prefixo</label>
                    <input type="text" name="va_callsign" value="<?php echo htmlspecialchars($settings['va_callsign']); ?>" class="form-input uppercase" placeholder="SKY">
                </div>
                <div class="md:col-span-2 space-y-2">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">URL do Logotipo</label>
                    <div class="flex gap-4">
                        <input type="text" name="va_logo_url" value="<?php echo htmlspecialchars($settings['va_logo_url']); ?>" class="form-input flex-1" placeholder="https://...">
                        <?php if (!empty($settings['va_logo_url'])): ?>
                            <div class="w-12 h-12 glass-panel rounded-xl p-2 flex items-center justify-center bg-white/5 border border-white/10">
                                <img src="<?php echo htmlspecialchars($settings['va_logo_url']); ?>" class="max-w-full max-h-full opacity-80">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Operations Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="glass-panel rounded-3xl overflow-hidden">
                <div class="p-6 border-b border-white/10 bg-white/5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-plane text-indigo-400"></i> Malha & Frota
                    </h3>
                </div>
                <div class="p-8 space-y-2">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Prefixos de Matrícula (CSV)</label>
                    <input type="text" name="fleet_registration_prefixes" value="<?php echo htmlspecialchars($settings['fleet_registration_prefixes']); ?>" class="form-input" placeholder="PR,PT,PP,PS">
                    <p class="text-[9px] text-slate-600 font-bold ml-1">USADO PARA GERAÇÃO ALEATÓRIA DE AERONAVES</p>
                </div>
            </div>

            <div class="glass-panel rounded-3xl overflow-hidden">
                <div class="p-6 border-b border-white/10 bg-white/5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-hand-holding-usd text-indigo-400"></i> Financeiro
                    </h3>
                </div>
                <div class="p-8 grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Moeda (Símbolo)</label>
                        <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars($settings['currency_symbol']); ?>" class="form-input" placeholder="R$">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Diária Hotel</label>
                        <input type="number" step="0.01" name="hotel_daily_rate" value="<?php echo htmlspecialchars($settings['hotel_daily_rate']); ?>" class="form-input">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Custo Desjejum</label>
                        <input type="number" step="0.01" name="breakfast_cost" value="<?php echo htmlspecialchars($settings['breakfast_cost']); ?>" class="form-input">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Custo Almoço</label>
                        <input type="number" step="0.01" name="lunch_cost" value="<?php echo htmlspecialchars($settings['lunch_cost']); ?>" class="form-input">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Custo Janta</label>
                        <input type="number" step="0.01" name="dinner_cost" value="<?php echo htmlspecialchars($settings['dinner_cost']); ?>" class="form-input">
                    </div>
                </div>
            </div>
        </div>

        <!-- External Integrations -->
        <div class="glass-panel rounded-3xl overflow-hidden">
            <div class="p-6 border-b border-white/10 bg-white/5">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-network-wired text-indigo-400"></i> Integrações SimBrief (Dispatch)
                </h3>
            </div>
            <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">SimBrief Username</label>
                    <input type="text" name="simbrief_username" value="<?php echo htmlspecialchars($settings['simbrief_username'] ?? ''); ?>" class="form-input" placeholder="Seu usuário no SimBrief">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Static API Key (Opcional)</label>
                    <input type="password" name="simbrief_api_key" value="<?php echo htmlspecialchars($settings['simbrief_api_key'] ?? ''); ?>" class="form-input" placeholder="••••••••">
                </div>

            </div>
        </div>

        <div class="pt-4">
            <button type="submit" class="btn-glow px-12 py-5 uppercase tracking-widest text-xs font-bold">
                Salvar Configurações
            </button>
        </div>
    </form>
</div>

<?php include '../includes/layout_footer.php'; ?>