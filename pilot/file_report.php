<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('pilot');

$pilotId = getCurrentPilotId($pdo);

if (!isset($_GET['roster_id'])) {
    header("Location: dashboard.php");
    exit;
}

$rosterId = $_GET['roster_id'];

// Check if roster belongs to pilot
$stmt = $pdo->prepare("
    SELECT r.*, f.flight_number, f.dep_icao, f.arr_icao 
    FROM roster_assignments r 
    JOIN flights_master f ON r.flight_id = f.id 
    WHERE r.id = ? AND r.pilot_id = ?
");
$stmt->execute([$rosterId, $pilotId]);
$flight = $stmt->fetch();

if (!$flight)
    die("Voo inválido.");

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $time = $_POST['flight_time'];
    $fuel = $_POST['fuel_used'];
    $landing = $_POST['landing_rate'];
    $comments = $_POST['comments'];

    try {
        $pdo->beginTransaction();

        // 1. Create Report
        $stmt = $pdo->prepare("INSERT INTO flight_reports (pilot_id, roster_id, flight_time, fuel_used, landing_rate, comments, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$pilotId, $rosterId, $time, $fuel, $landing, $comments]);

        // 2. Update Roster Status to 'Flown'
        $stmt = $pdo->prepare("UPDATE roster_assignments SET status = 'Flown' WHERE id = ?");
        $stmt->execute([$rosterId]);

        $pdo->commit();
        $_SESSION['flash_msg'] = ["type" => "success", "text" => "Relatório enviado com sucesso! Aguardando aprovação."];
        header("Location: dashboard.php");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_msg'] = ["type" => "error", "text" => "Erro ao enviar: " . $e->getMessage()];
    }
}

$pageTitle = "Enviar Relatório - SkyCrew OS";
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden max-w-2xl mx-auto w-full">
    <div class="flex justify-between items-end shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-file-export text-indigo-400"></i> Relatório de Voo (PIREP)
            </h2>
            <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1"><?php echo $flight['flight_number']; ?>: <?php echo $flight['dep_icao']; ?> PARA <?php echo $flight['arr_icao']; ?></p>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php" class="bg-white/5 border border-white/10 px-4 py-2 rounded-2xl text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:bg-white/10 transition">
                Cancelar
            </a>
        </div>
    </div>

    <form method="POST" class="glass-panel p-8 rounded-3xl space-y-6">
        <?php if (isset($_SESSION['flash_msg']) && $_SESSION['flash_msg']['type'] == 'error'): ?>
            <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-2xl text-xs font-bold uppercase tracking-widest">
                <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $_SESSION['flash_msg']['text']; ?>
                <?php unset($_SESSION['flash_msg']); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Tempo de Voo (Horas)</label>
                <div class="relative">
                    <input type="number" step="0.01" name="flight_time" class="form-input" placeholder="ex: 1.5" required>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-600 text-[10px] font-bold">H.DEC</div>
                </div>
            </div>
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Toque Vertical (fpm)</label>
                <div class="relative">
                    <input type="number" name="landing_rate" class="form-input" placeholder="-150" required>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-600 text-[10px] font-bold">FPM</div>
                </div>
            </div>
        </div>

        <div class="space-y-1">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Combustível Consumido</label>
            <div class="relative">
                <input type="number" name="fuel_used" class="form-input" placeholder="5000" required>
                <div class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-600 text-[10px] font-bold">KG</div>
            </div>
        </div>

        <div class="space-y-1">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-1">Observações Operacionais</label>
            <textarea name="comments" class="form-input h-32 py-3" placeholder="Relate eventos significativos ou observações do voo..."></textarea>
        </div>

        <div class="pt-4">
            <button type="submit" class="btn-glow w-full py-4 text-xs uppercase tracking-[0.2em] shadow-[0_0_30px_rgba(99,102,241,0.3)]">
                Submeter Relatório Oficial
            </button>
        </div>
    </form>

    <div class="glass-panel p-6 rounded-3xl bg-indigo-600/5 border-indigo-500/20">
        <h3 class="text-xs font-bold text-indigo-400 uppercase tracking-widest flex items-center gap-2">
            <i class="fas fa-shield-alt"></i> Declaração de Integridade
        </h3>
        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-tight mt-2 leading-relaxed">
            Ao submeter este PIREP, você atesta que os dados fornecidos são verídicos e refletem a operação realizada no simulador. Fraudes podem resultar em suspensão da conta.
        </p>
    </div>
</div>

<?php include '../includes/layout_footer.php'; ?>