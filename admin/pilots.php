<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

// Fetch Pilots
$stmt = $pdo->query("SELECT p.*, u.email FROM pilots p JOIN users u ON p.user_id = u.id ORDER BY p.name");
$pilots = $stmt->fetchAll();

// Handle Add Pilot
$success = '';
$error = '';
if (isset($_POST['add_pilot'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash('123456', PASSWORD_DEFAULT);
    $base = $_POST['base'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'pilot')");
        $stmt->execute([$email, $password]);
        $userId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO pilots (user_id, name, current_base) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $name, $base]);
        $pdo->commit();
        $success = "Piloto criado! Senha padrão: 123456";
        // Refresh list
        header("Refresh:0");
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Gerenciar Pilotos - SkyCrew Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex h-screen">

    <aside class="w-64 bg-gray-900 text-white flex flex-col">
        <div class="h-16 flex items-center justify-center font-bold text-xl border-b border-gray-800">SkyCrew Admin
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2">
            <a href="dashboard.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Painel</a>
            <a href="financials.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Financeiro</a>
            <a href="reports.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Relatórios</a>
            <a href="pilots.php" class="block py-2.5 px-4 rounded bg-gray-800 text-white font-bold">Pilotos</a>
            <a href="flights.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Voos</a>
            <a href="fleet.php" class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Frota</a>
            <a href="settings.php"
                class="block py-2.5 px-4 rounded hover:bg-gray-800 transition text-gray-400">Configurações</a>
        </nav>
        <div class="p-4 border-t border-gray-800"><a href="../logout.php"
                class="block text-center text-sm text-gray-400">Sair</a></div>
    </aside>

    <main class="flex-1 flex flex-col">
        <div class="p-8 overflow-y-auto">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Gerenciar Pilotos</h1>

            <?php if ($success): ?>
                <div class="bg-green-100 text-green-700 p-4 rounded mb-4"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-red-100 text-red-700 p-4 rounded mb-4"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Add Pilot Form -->
            <div class="bg-white p-6 rounded shadow mb-8">
                <h3 class="font-bold text-gray-700 mb-4">Adicionar Novo Piloto</h3>
                <form method="POST" class="flex gap-4 items-end">
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-gray-500 mb-1">Nome</label>
                        <input type="text" name="name" class="w-full border p-2 rounded" required>
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-gray-500 mb-1">E-mail</label>
                        <input type="email" name="email" class="w-full border p-2 rounded" required>
                    </div>
                    <div class="w-24">
                        <label class="block text-xs font-bold text-gray-500 mb-1">Base</label>
                        <input type="text" name="base" class="w-full border p-2 rounded" value="SBGR" maxlength="4"
                            required>
                    </div>
                    <button type="submit" name="add_pilot"
                        class="bg-indigo-600 text-white font-bold py-2 px-6 rounded hover:bg-indigo-700">Adicionar</button>
                </form>
            </div>

            <!-- Pilot List -->
            <div class="bg-white rounded shadow overflow-hidden">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-6 py-3">ID</th>
                            <th class="px-6 py-3">Nome</th>
                            <th class="px-6 py-3">E-mail</th>
                            <th class="px-6 py-3">Base</th>
                            <th class="px-6 py-3">Rank</th>
                            <th class="px-6 py-3">Horas Totais</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($pilots as $p): ?>
                            <tr>
                                <td class="px-6 py-3 text-gray-500">#<?php echo $p['id']; ?></td>
                                <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($p['name']); ?>
                                </td>
                                <td class="px-6 py-3 text-gray-500"><?php echo htmlspecialchars($p['email']); ?></td>
                                <td class="px-6 py-3 font-mono text-indigo-600"><?php echo $p['current_base']; ?></td>
                                <td class="px-6 py-3">
                                    <span
                                        class="bg-gray-100 text-gray-800 text-xs px-2 py-1 rounded-full border border-gray-200"><?php echo $p['rank']; ?></span>
                                </td>
                                <td class="px-6 py-3"><?php echo number_format($p['total_hours'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>

</html>