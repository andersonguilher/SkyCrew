<?php
// includes/layout_header.php
require_once __DIR__ . '/auth_session.php';
$sysSettings = getSystemSettings($pdo);
$role = $_SESSION['role'] ?? 'pilot';
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Fetch actual admin status from DB to be safe
$db_admin = false;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT is_admin FROM pilots WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $db_admin = (bool)$stmt->fetchColumn();
}

// A user is considered in "Admin Mode" if they have the role OR the flag, 
// AND they are currently accessing a page in the admin directory.
$is_admin_privileged = ($role === 'admin' || $db_admin);
$is_admin = ($is_admin_privileged && $current_dir === 'admin');

// For the dynamic button, we still need to know if they COULD be admin
$is_actually_admin = $is_admin_privileged;

// Fetch Pilot Info for the top bar (Available on all pages)
$pilot = ['name' => 'Usuário', 'profile_image' => ''];
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT name, profile_image FROM pilots WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $pData = $stmt->fetch();
    if ($pData) $pilot = $pData;
}

$showParamsAlert = false;
if (!$is_admin && isset($pdo)) {
    $hid = getCurrentPilotId($pdo);
    if ($hid) {
        $stmt = $pdo->prepare("SELECT 
            (SELECT 1 FROM pilot_preferences WHERE pilot_id = ? LIMIT 1) as has_sched,
            (SELECT 1 FROM pilot_aircraft_prefs WHERE pilot_id = ? LIMIT 1) as has_ac");
        $stmt->execute([$hid, $hid]);
        $checkRes = $stmt->fetch();
        $showParamsAlert = (!$checkRes || !$checkRes['has_sched'] || !$checkRes['has_ac']);
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'SkyCrew OS'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Outfit', sans-serif; margin: 0; padding: 0; overflow: hidden; background: #0c0e17; color: white; height: 100vh; }
        .immersive-bg { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; background: radial-gradient(circle at top right, #1e1b4b 0%, #0c0e17 100%); }
        .glass-panel { background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4); }
        .top-bar { height: 80px; margin: 20px 20px 10px 20px; border-radius: 16px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; flex-shrink: 0; }
        .nav-button { min-width: 60px; height: 60px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8; transition: all 0.3s; text-decoration: none; padding: 5px; }
        .nav-button i { font-size: 1.2rem; }
        .nav-label { font-size: 0.65rem; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
        .nav-button:hover, .nav-button.active { background: rgba(99, 102, 241, 0.2); color: #818cf8; }
        .btn-glow { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); box-shadow: 0 0 20px rgba(99, 102, 241, 0.3); border: none; color: white; font-weight: 700; border-radius: 12px; cursor: pointer; transition: all 0.3s; }
        .btn-glow:hover { transform: translateY(-2px); box-shadow: 0 0 30px rgba(99, 102, 241, 0.5); }
        .form-input { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; border-radius: 12px; padding: 10px 14px; font-size: 0.875rem; width: 100%; transition: all 0.3s; }
        .form-input:focus { background: rgba(255,255,255,0.1); border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
        .form-input option { background-color: #0c0e17; color: white; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
        .page-container { display: flex; flex-direction: column; height: 100vh; }
        .content-area { flex: 1; overflow: hidden; display: flex; padding: 10px 20px 20px 20px; gap: 20px; }
        .scrollable-panel { flex: 1; overflow-y: auto; padding-right: 5px; }
        .section-title { font-weight: 700; font-size: 1.125rem; color: white; display: flex; align-items: center; gap: 2px; margin-bottom: 1rem; }
    </style>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>
    <?php echo $bgElement ?? '<div class="immersive-bg"></div>'; ?>
    <div class="page-container">
        <div class="top-bar glass-panel">
            <div class="flex items-center gap-6">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-500 flex items-center justify-center text-white font-bold shadow-lg shadow-indigo-500/50">S</div>
                    <span class="font-bold text-lg text-white tracking-tight">SkyCrew <span class="text-indigo-400">OS</span></span>
                </div>
                <div class="h-6 w-px bg-white/10 mx-2"></div>
                <nav class="flex gap-2">
                    <?php if ($is_admin): ?>
                        <a href="dashboard.php" class="nav-button <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" title="Painel">
                            <i class="fas fa-chart-pie"></i>
                            <span class="nav-label">Painel</span>
                        </a>
                        <a href="flights.php" class="nav-button <?php echo $current_page == 'flights.php' ? 'active' : ''; ?>" title="Voos">
                            <i class="fas fa-route"></i>
                            <span class="nav-label">Voos</span>
                        </a>
                        <a href="fleet.php" class="nav-button <?php echo $current_page == 'fleet.php' ? 'active' : ''; ?>" title="Frota">
                            <i class="fas fa-plane"></i>
                            <span class="nav-label">Frota</span>
                        </a>
                        <a href="pilots.php" class="nav-button <?php echo $current_page == 'pilots.php' ? 'active' : ''; ?>" title="Pilotos">
                            <i class="fas fa-users"></i>
                            <span class="nav-label">Pilotos</span>
                        </a>
                        <a href="financials.php" class="nav-button <?php echo $current_page == 'financials.php' ? 'active' : ''; ?>" title="Financeiro">
                            <i class="fas fa-wallet"></i>
                            <span class="nav-label">Financeiro</span>
                        </a>
                        <a href="ranks.php" class="nav-button <?php echo $current_page == 'ranks.php' ? 'active' : ''; ?>" title="Patentes">
                            <i class="fas fa-medal"></i>
                            <span class="nav-label">Patentes</span>
                        </a>
                        <a href="expense_venues.php" class="nav-button <?php echo $current_page == 'expense_venues.php' ? 'active' : ''; ?>" title="Locais de Despesa">
                            <i class="fas fa-map-marker-alt"></i>
                            <span class="nav-label">Locais</span>
                        </a>
                        <a href="settings.php" class="nav-button <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" title="Configurações">
                            <i class="fas fa-cog"></i>
                            <span class="nav-label">Config</span>
                        </a>

                        <div class="h-8 w-px bg-white/5 mx-2"></div>

                        <a href="../pilot/dashboard.php" class="nav-button" title="Portal do Piloto">
                            <i class="fas fa-user"></i>
                            <span class="nav-label">Portal</span>
                        </a>
                    <?php else: ?>
                        <a href="dashboard.php" class="nav-button <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" title="Painel">
                            <i class="fas fa-home"></i>
                            <span class="nav-label">Início</span>
                        </a>

                        <a href="paycheck.php" class="nav-button <?php echo $current_page == 'paycheck.php' ? 'active' : ''; ?>" title="Pagamentos">
                            <i class="fas fa-wallet"></i>
                            <span class="nav-label">Pagamentos</span>
                        </a>
                        <a href="preferences.php" class="nav-button group/prefs <?php echo $current_page == 'preferences.php' ? 'active' : ''; ?>" title="Preferências">
                            <div class="relative">
                                <i class="fas fa-sliders-h"></i>
                                <?php if ($showParamsAlert): ?>
                                    <div class="absolute -top-1 -right-1 w-2 h-2 bg-rose-500 rounded-full animate-pulse ring-2 ring-[#0c0e17]"></div>
                                    
                                    <!-- Tooltip / Alerta -->
                                    <div class="absolute bottom-full mb-3 left-1/2 -translate-x-1/2 w-48 pointer-events-none transition-all duration-300 opacity-100 translate-y-0">
                                        <div class="bg-rose-600 text-white text-[10px] font-bold py-2 px-3 rounded-lg shadow-xl text-center uppercase tracking-tighter">
                                            Preencher Parâmetros
                                            <div class="absolute top-full left-1/2 -translate-x-1/2 border-8 border-transparent border-t-rose-600"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="nav-label">Prefs</span>
                        </a>

                        <?php if ($is_actually_admin): ?>
                            <div class="h-8 w-px bg-white/5 mx-2"></div>
                            <a href="../admin/dashboard.php" class="nav-button" title="Área Administrativa">
                                <i class="fas fa-shield-alt"></i>
                                <span class="nav-label">Admin</span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <div id="top-clock" class="text-white font-mono text-xs tracking-widest bg-white/5 px-3 py-1 rounded-full border border-white/10 text-indigo-300">--:--:-- UTC</div>
                
                <div class="flex items-center gap-3 bg-white/5 pl-3 pr-1 py-1 rounded-full border border-white/10">
                    <span class="text-[10px] font-bold text-slate-300 uppercase tracking-widest"><?php echo $pilot['name'] ?? 'Usuário'; ?></span>
                    <div class="h-8 w-8 rounded-full border border-indigo-500/50 p-0.5 overflow-hidden">
                        <?php if (!empty($pilot['profile_image'])): ?>
                            <img src="<?php echo $pilot['profile_image']; ?>" class="w-full h-full rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-full h-full rounded-full bg-slate-800 flex items-center justify-center text-slate-400 text-xs">
                                <i class="fas fa-user-<?php echo $is_admin ? 'shield' : 'pilot'; ?>"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="../logout.php" class="text-slate-400 hover:text-white transition text-[10px] uppercase font-bold tracking-widest">Sair</a>
            </div>
        </div>
        <div class="content-area">
