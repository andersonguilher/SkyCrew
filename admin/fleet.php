<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$success = '';
$error = '';

// Handle actions
// Helper to generate registration
function generateRegistration($pdo, $prefixesStr)
{
    $prefixes = array_map('trim', explode(',', $prefixesStr));
    if (empty($prefixes) || $prefixes[0] == '')
        $prefixes = ['PR'];

    $restricted = ['PAN', 'TTT', 'VFR', 'FR', 'IMC', 'SOS'];
    $letters = "ABCDEFGHIJKLMNOPQRSTUVWYZ"; // Start without X as per rules
    $lettersWithX = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    $maxAttempts = 100;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $prefix = $prefixes[array_rand($prefixes)];

        // Brazilian pattern: 3 letters after prefix
        $r1 = $letters[rand(0, strlen($letters) - 1)];
        $r2 = $lettersWithX[rand(0, strlen($lettersWithX) - 1)];
        $r3 = $lettersWithX[rand(0, strlen($lettersWithX) - 1)];
        $regPart = $r1 . $r2 . $r3;

        if (in_array($regPart, $restricted))
            continue;

        $full = $prefix . "-" . $regPart;

        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM fleet WHERE registration = ?");
        $stmt->execute([$full]);
        if ($stmt->rowCount() == 0)
            return $full;
    }
    return "TBD-" . rand(100, 999);
}

$settings = getSystemSettings($pdo);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_aircraft'])) {
        $icao = strtoupper(trim($_POST['icao_code']));
        $name = trim($_POST['fullname']);
        $speed = intval($_POST['cruise_speed']);
        $registration = trim($_POST['registration']);

        try {
            $stmt = $pdo->prepare("INSERT INTO fleet (icao_code, registration, fullname, cruise_speed) VALUES (?, ?, ?, ?)");
            $stmt->execute([$icao, $registration, $name, $speed]);
            $success = "Aeronave $registration ($icao) adicionada com sucesso.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM fleet WHERE id = ?");
            $stmt->execute([$_POST['delete_id']]);
            $success = "Aeronave removida.";
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    }
}

// Fetch Fleet
$fleet = $pdo->query("SELECT * FROM fleet ORDER BY icao_code")->fetchAll();
$settings = getSystemSettings($pdo);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Gerenciar Frota -
        <?php echo htmlspecialchars($settings['va_name']); ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/dist/css/all.min.css">
</head>

<body class="bg-gray-100 font-sans">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-900 text-white flex flex-col">
            <div class="h-16 flex items-center justify-center font-bold text-xl border-b border-gray-800">
                <?php echo htmlspecialchars($settings['va_name']); ?>
            </div>
            <nav class="flex-1 px-4 py-6 space-y-2 text-sm text-gray-400">
                <a href="dashboard.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition">Painel</a>
                <a href="financials.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition">Financeiro</a>
                <a href="reports.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition">Relatórios</a>
                <a href="pilots.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition">Pilotos</a>
                <a href="flights.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition">Voos</a>
                <a href="fleet.php" class="block py-2.5 px-4 rounded bg-gray-800 text-white">Frota</a>
                <a href="settings.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition">Configurações</a>
            </nav>
            <div class="p-4 border-t border-gray-800">
                <a href="../logout.php" class="block text-center text-sm text-gray-400 hover:text-white">Sair</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto p-10">
            <header class="flex justify-between items-center mb-8">
                <h2 class="text-3xl font-bold text-gray-800 text-indigo-600">Gerenciar Frota</h2>
                <button onclick="openSyncModal()"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow transition flex items-center">
                    <i class="fas fa-cloud-download-alt mr-2"></i> Sugestões SimBrief
                </button>
            </header>

            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Add Aircraft Form -->
            <div class="bg-white p-6 rounded shadow mb-8 border-l-4 border-indigo-500">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-gray-700">Adicionar Nova Aeronave</h3>
                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wider">Apenas aeronaves
                        compatíveis com SimBrief</span>
                </div>

                <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div class="md:col-span-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Matrícula</label>
                            <div class="flex gap-1">
                                <input type="text" name="registration" id="add_reg"
                                    class="w-full border p-2 rounded bg-gray-50 font-bold text-green-700"
                                    placeholder="PR-..." readonly required>
                                <button type="button" onclick="refreshReg()"
                                    class="bg-gray-200 px-2 rounded hover:bg-gray-300 text-gray-600"
                                    title="Gerar outra">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Código ICAO</label>
                            <input type="text" name="icao_code" id="add_icao"
                                class="w-full border p-2 rounded bg-gray-50 font-bold text-indigo-700 cursor-not-allowed uppercase"
                                placeholder="..." maxlength="10" readonly required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Nome Completo</label>
                            <input type="text" name="fullname" id="add_name"
                                class="w-full border p-2 rounded bg-gray-50 text-gray-600 cursor-not-allowed"
                                placeholder="..." readonly required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Velocidade Cruzeiro (kt)</label>
                            <input type="number" name="cruise_speed" id="add_speed" class="w-full border p-2 rounded"
                                placeholder="450" required>
                        </div>
                    </div>
                    <div>
                        <div id="actionButtons">
                            <button type="button" onclick="openSyncModal()" id="btnSelect"
                                class="w-full bg-white border-2 border-indigo-600 text-indigo-600 font-bold py-2 rounded hover:bg-indigo-50 transition mb-2">
                                <i class="fas fa-search mr-2"></i> Selecionar
                            </button>
                            <button type="submit" name="add_aircraft" id="btnAdd" disabled
                                class="w-full bg-gray-300 text-white font-bold py-2 rounded cursor-not-allowed transition">
                                <i class="fas fa-plus mr-1"></i> Adicionar
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Fleet List -->
            <div class="bg-white rounded shadow overflow-hidden">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-6 py-3">Matrícula</th>
                            <th class="px-6 py-3">ICAO</th>
                            <th class="px-6 py-3">Aeronave</th>
                            <th class="px-6 py-3">Velocidade (kt)</th>
                            <th class="px-6 py-3 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($fleet as $ac): ?>
                            <tr>
                                <td class="px-6 py-3 font-mono font-bold text-gray-700">
                                    <?php echo $ac['registration']; ?>
                                </td>
                                <td class="px-6 py-3 font-bold text-indigo-600">
                                    <?php echo $ac['icao_code']; ?>
                                </td>
                                <td class="px-6 py-3 font-medium">
                                    <?php echo $ac['fullname']; ?>
                                </td>
                                <td class="px-6 py-3 text-gray-500">
                                    <?php echo $ac['cruise_speed']; ?> kt
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <form method="POST" onsubmit="return confirm('Excluir esta aeronave da frota?');">
                                        <input type="hidden" name="delete_id" value="<?php echo $ac['id']; ?>">
                                        <button type="submit"
                                            class="text-red-500 hover:text-red-700 text-xs font-bold uppercase transition">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fleet)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-400">Nenhuma aeronave cadastrada.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Sync Modal -->
    <div id="syncModal" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[80vh] flex flex-col">
            <div class="p-6 border-b flex justify-between items-center">
                <h3 class="text-xl font-bold text-gray-800">Supported Aircraft (SimBrief)</h3>
                <button onclick="closeSyncModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <div class="p-4 bg-gray-50 border-b">
                <input type="text" id="sbSearch" oninput="filterSBAircraft(this.value)"
                    class="w-full border rounded-lg px-4 py-2" placeholder="Filtrar por nome ou ICAO...">
            </div>
            <div class="p-6 overflow-y-scroll flex-1" id="sbList">
                <div class="flex items-center justify-center p-12 text-gray-400">
                    <i class="fas fa-spinner fa-spin mr-2"></i> Carregando lista do SimBrief...
                </div>
            </div>
            <div class="p-6 border-t bg-gray-50 text-right">
                <button onclick="closeSyncModal()"
                    class="px-6 py-2 rounded font-bold text-gray-600 hover:text-gray-800">Fechar</button>
            </div>
        </div>
    </div>

    <script>
        let allSBAircraft = {};
        const registrationPrefixes = "<?php echo $settings['fleet_registration_prefixes']; ?>".split(',');

        function generateJSMatricula() {
            const prefixes = registrationPrefixes.map(p => p.trim());
            const prefix = prefixes[Math.floor(Math.random() * prefixes.length)] || 'PR';
            const chars = "ABCDEFGHIJKLMNOPQRSTUVWYZ"; // No X at start
            const charsFull = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
            const r1 = chars[Math.floor(Math.random() * chars.length)];
            const r2 = charsFull[Math.floor(Math.random() * charsFull.length)];
            const r3 = charsFull[Math.floor(Math.random() * charsFull.length)];
            return (prefix + "-" + r1 + r2 + r3).toUpperCase();
        }

        function refreshReg() {
            document.getElementById('add_reg').value = generateJSMatricula();
        }

        async function openSyncModal() {
            document.getElementById('syncModal').classList.remove('hidden');
            if (Object.keys(allSBAircraft).length === 0) {
                try {
                    const response = await fetch('https://www.simbrief.com/api/inputs.list.json');
                    const data = await response.json();
                    allSBAircraft = data.aircraft || {};
                    renderSBAircraft(allSBAircraft);
                } catch (e) {
                    document.getElementById('sbList').innerHTML = '<div class="text-red-500 text-center py-8">Erro ao carregar lista do SimBrief. Verifique sua conexão.</div>';
                }
            }
        }

        function closeSyncModal() {
            document.getElementById('syncModal').classList.add('hidden');
        }

        function renderSBAircraft(list) {
            const container = document.getElementById('sbList');
            container.innerHTML = '';

            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-1 md:grid-cols-2 gap-4';

            Object.entries(list).forEach(([icao, details]) => {
                const item = document.createElement('div');
                item.className = 'p-4 border rounded hover:border-indigo-500 hover:bg-indigo-50 cursor-pointer group flex justify-between items-center transition';
                item.onclick = () => selectSBAircraft(icao, details.name);

                item.innerHTML = `
                    <div>
                        <div class="font-bold text-indigo-600 group-hover:text-indigo-700">${icao}</div>
                        <div class="text-xs text-gray-500">${details.name}</div>
                    </div>
                    <i class="fas fa-plus text-indigo-300 group-hover:text-indigo-600"></i>
                `;
                grid.appendChild(item);
            });

            if (Object.keys(list).length === 0) {
                container.innerHTML = '<div class="text-center text-gray-400 py-8">Nenhuma aeronave encontrada.</div>';
            } else {
                container.appendChild(grid);
            }
        }

        function filterSBAircraft(query) {
            query = query.toLowerCase();
            const filtered = {};
            Object.entries(allSBAircraft).forEach(([icao, details]) => {
                if (icao.toLowerCase().includes(query) || details.name.toLowerCase().includes(query)) {
                    filtered[icao] = details;
                }
            });
            renderSBAircraft(filtered);
        }

        function selectSBAircraft(icao, name) {
            document.getElementById('add_icao').value = icao;
            document.getElementById('add_name').value = name;

            // Set some default cruise speeds based on ICAO common patterns
            let speed = 450;
            if (icao.startsWith('C')) speed = 120; // Cessna
            if (icao.startsWith('A3') || icao.startsWith('B7') || icao.startsWith('E1')) speed = 450;
            if (icao.startsWith('AT7')) speed = 280; // ATR

            document.getElementById('add_speed').value = speed;

            // Auto-generate registration
            refreshReg();

            // Enable Add Button and style it
            const btnAdd = document.getElementById('btnAdd');
            btnAdd.disabled = false;
            btnAdd.classList.remove('bg-gray-300', 'cursor-not-allowed');
            btnAdd.classList.add('bg-indigo-600', 'hover:bg-indigo-700');

            closeSyncModal();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>

</html>