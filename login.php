<?php
require_once 'db_connect.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];

                if ($user['role'] === 'admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: pilot/dashboard.php");
                }
                exit;
            } else {
                $error = "E-mail ou senha inválidos.";
            }
        } catch (Exception $e) {
            $error = "Erro no banco de dados: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, preencha todos os campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SkyCrew OS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Outfit', sans-serif; margin: 0; padding: 0; background: #0c0e17; height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .immersive-bg { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; background: radial-gradient(circle at top right, #1e1b4b 0%, #0c0e17 100%); }
        .glass-panel { background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.8); }
        .form-input { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; border-radius: 16px; padding: 14px 20px; font-size: 0.9rem; width: 100%; transition: all 0.3s; }
        .form-input:focus { background: rgba(255,255,255,0.1); border-color: #6366f1; outline: none; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.2); }
        .btn-glow { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.4); border: none; color: white; font-weight: 700; border-radius: 16px; cursor: pointer; transition: all 0.3s; padding: 14px; width: 100%; }
        .btn-glow:hover { transform: translateY(-2px); box-shadow: 0 15px 30px -5px rgba(99, 102, 241, 0.6); }
        .btn-glow:active { transform: translateY(0); }
    </style>
</head>
<body>
    <div class="immersive-bg"></div>
    
    <div class="w-full max-w-[420px] p-4 animate-in fade-in zoom-in duration-700">
        <div class="glass-panel p-10 rounded-[32px] space-y-8">
            <div class="text-center space-y-2">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-indigo-500 shadow-lg shadow-indigo-500/50 mb-4">
                    <i class="fas fa-plane-up text-white text-2xl"></i>
                </div>
                <h1 class="text-3xl font-black text-white tracking-tight">SkyCrew <span class="text-indigo-400">OS</span></h1>
                <p class="text-slate-500 text-[10px] font-bold uppercase tracking-[0.3em]">Operational System</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-2xl text-[11px] font-bold uppercase tracking-widest text-center">
                    <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-4">Credential Identity</label>
                    <input type="email" name="email" class="form-input" placeholder="E-mail de Acesso" required>
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-4">Secure Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Senha de Segurança" required>
                </div>
                
                <div class="pt-2">
                    <button type="submit" class="btn-glow text-[11px] uppercase tracking-[0.2em]">
                        Initial Authentication
                    </button>
                </div>
            </form>

            <div class="pt-4 border-t border-white/5 space-y-3">
                <p class="text-[9px] text-slate-600 font-bold uppercase tracking-widest text-center">Test Environment Access</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="text-[8px] text-slate-500 font-bold text-center bg-white/5 py-2 rounded-xl">ADMIN@SKYCREW.COM<br>123456</div>
                    <div class="text-[8px] text-slate-500 font-bold text-center bg-white/5 py-2 rounded-xl">SHEPARD@SKYCREW.COM<br>123456</div>
                </div>
            </div>
        </div>
        
        <p class="mt-8 text-center text-slate-600 font-bold text-[10px] uppercase tracking-widest">
            Kafly Media &copy; 2024 &bull; Version 4.2.0-Indigo
        </p>
    </div>
</body>
</html>