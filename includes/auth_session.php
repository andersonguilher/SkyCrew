<?php
// includes/auth_session.php
session_start();

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit;
    }
}

function requireRole($role)
{
    requireLogin();
    
    // If role requested is pilot, both pilot and admin can access
    if ($role === 'pilot') {
        return;
    }

    // If role requested is admin, check if user is admin
    if ($role === 'admin') {
        $isAdmin = ($_SESSION['role'] === 'admin');
        if (!$isAdmin) {
            global $pdo;
            if (isset($pdo)) {
                $stmt = $pdo->prepare("SELECT is_admin FROM pilots WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $isAdmin = (bool)$stmt->fetchColumn();
            }
        }
        
        if (!$isAdmin) {
            header("Location: ../pilot/dashboard.php");
            exit;
        }
    }
}

function getCurrentPilotId($pdo)
{
    if (!isset($_SESSION['user_id']))
        return null;
    $stmt = $pdo->prepare("SELECT id FROM pilots WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetchColumn();
}
