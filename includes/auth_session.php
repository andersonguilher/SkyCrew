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
    if ($_SESSION['role'] !== $role) {
        // Redirect to their appropriate dashboard if they try to access wrong area
        if ($_SESSION['role'] === 'admin') {
            header("Location: ../admin/dashboard.php");
        } else {
            header("Location: ../pilot/dashboard.php");
        }
        exit;
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
