<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'va_name' => $_POST['va_name'] ?? '',
        'va_callsign' => $_POST['va_callsign'] ?? '',
        'va_logo_url' => $_POST['va_logo_url'] ?? '',
        'daily_idle_cost' => $_POST['daily_idle_cost'] ?? '',
        'currency_symbol' => $_POST['currency_symbol'] ?? '',
        'simbrief_username' => $_POST['simbrief_username'] ?? '',
        'simbrief_api_key' => $_POST['simbrief_api_key'] ?? '',
        'fleet_registration_prefixes' => $_POST['fleet_registration_prefixes'] ?? ''
    ];

    try {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $message = "Configurações atualizadas com sucesso!";
    } catch (Exception $e) {
        $message = "Erro ao atualizar: " . $e->getMessage();
    }
}

// Fetch current settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // key => value
$settings = array_merge([
    'va_name' => 'SkyCrew Virtual Airline',
    'va_callsign' => 'SKY',
    'va_logo_url' => '',
    'daily_idle_cost' => '150.00',
    'currency_symbol' => 'R$',
    'simbrief_username' => '',
    'simbrief_api_key' => '',
    'fleet_registration_prefixes' => 'PR,PT,PP,PS,PU'
], $rows);

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Configurações - SkyCrew Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex h-screen">

    <!-- Sidebar -->
    <aside class="w-64 bg-gray-900 text-white flex flex-col">
        <div class="h-16 flex items-center justify-center font-bold text-xl border-b border-gray-800">
            SkyCrew Admin
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2">
            <a href="dashboard.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Painel</a>
            <a href="financials.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Financeiro</a>
            <a href="reports.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Relatórios</a>
            <a href="pilots.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Pilotos</a>
            <a href="flights.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Voos</a>
            <a href="fleet.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Frota</a>
            <a href="settings.php" class="block py-2.5 px-4 rounded bg-gray-800 text-white font-bold">Configurações</a>
        </nav>
        <div class="p-4 border-t border-gray-800">
            <a href="../logout.php" class="block text-center text-sm text-gray-400 hover:text-white">Sair</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto">
        <header class="bg-white shadow h-16 flex items-center px-6 justify-between">
            <h2 class="text-xl font-semibold text-gray-800"> <i class="fas fa-cogs mr-2"></i> Configurações do Sistema
            </h2>
            <div class="text-sm text-gray-600">Admin</div>
        </header>

        <div class="p-8 max-w-4xl mx-auto">

            <?php if ($message): ?>
                <div class="mb-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm"
                    role="alert">
                    <p>
                        <?php echo $message; ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="POST" class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-700">Identidade da VA</h3>
                </div>

                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- VA Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nome da Companhia Aérea</label>
                        <input type="text" name="va_name" value="<?php echo htmlspecialchars($settings['va_name']); ?>"
                            class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border">
                        <p class="text-xs text-gray-500 mt-1">Ex: SkyCrew Virtual Airlines</p>
                    </div>

                    <!-- Call Sign -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prefixo / Callsign</label>
                        <input type="text" name="va_callsign"
                            value="<?php echo htmlspecialchars($settings['va_callsign']); ?>"
                            class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border">
                        <p class="text-xs text-gray-500 mt-1">Ex: SKY, GLO. Usado em comunicações.</p>
                    </div>

                    <!-- Logo URL -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">URL do Logo</label>
                        <div class="flex gap-4 items-center">
                            <input type="text" name="va_logo_url"
                                value="<?php echo htmlspecialchars($settings['va_logo_url']); ?>"
                                class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border"
                                placeholder="https://...">
                            <?php if ($settings['va_logo_url']): ?>
                                <img src="<?php echo htmlspecialchars($settings['va_logo_url']); ?>" alt="Logo Preview"
                                    class="h-10 w-auto border rounded p-1">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 mt-4">
                    <h3 class="text-lg font-bold text-gray-700">Padrões de Matrícula (Fleet)</h3>
                </div>
                <div class="p-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prefixos de Nacionalidade (separados por vírgula)</label>
                        <input type="text" name="fleet_registration_prefixes" value="<?php echo htmlspecialchars($settings['fleet_registration_prefixes']); ?>"
                            class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border"
                            placeholder="ex: PR,PT,PP,PS">
                        <p class="text-xs text-gray-500 mt-1">Sorteia um prefixo e gera 3 letras aleatórias (Ex: PR-XYZ). Padrão Brasil.</p>
                    </div>
                </div>

                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 mt-4">
                    <h3 class="text-lg font-bold text-gray-700">Parâmetros Financeiros & Sistema</h3>
                </div>

                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Currency -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Símbolo da Moeda</label>
                        <input type="text" name="currency_symbol"
                            value="<?php echo htmlspecialchars($settings['currency_symbol']); ?>"
                            class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border">
                    </div>

                    <!-- Idle Cost -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Custo Diário de Ociosidade</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">
                                    <?php echo htmlspecialchars($settings['currency_symbol']); ?>
                                </span>
                            </div>
                            <input type="number" step="0.01" name="daily_idle_cost"
                                value="<?php echo htmlspecialchars($settings['daily_idle_cost']); ?>"
                                class="w-full rounded border-gray-300 py-2 pl-10 px-3 border focus:border-blue-500 focus:ring focus:ring-blue-200">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Valor fixo cobrado por dia de hotel/alimentação.</p>
                    </div>
                </div>

                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 mt-4">
                    <h3 class="text-lg font-bold text-gray-700">Integrações Externas</h3>
                </div>

                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- SimBrief Username -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SimBrief Username</label>
                        <input type="text" name="simbrief_username"
                            value="<?php echo htmlspecialchars($settings['simbrief_username'] ?? ''); ?>"
                            class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border">
                        <p class="text-xs text-gray-500 mt-1">Seu usuário SimBrief para geração de rotas.</p>
                    </div>

                    <!-- SimBrief API Key -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SimBrief API Key (Opcional)</label>
                        <input type="text" name="simbrief_api_key"
                            value="<?php echo htmlspecialchars($settings['simbrief_api_key'] ?? ''); ?>"
                            class="w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 py-2 px-3 border">
                        <p class="text-xs text-gray-500 mt-1">Para recursos avançados (Fetch OFP data).</p>
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
                    <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow transition">
                        <i class="fas fa-save mr-2"></i> Salvar Configurações
                    </button>
                </div>
            </form>
        </div>
    </main>
</body>

</html>