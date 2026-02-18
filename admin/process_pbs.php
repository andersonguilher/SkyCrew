<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
require_once '../includes/ScheduleMatcher.php';
requireRole('admin');

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    if ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM roster_assignments WHERE status = 'Suggested'");
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'Todas as escalas sugeridas foram apagadas.']);
        exit;
    }

    if ($action === 'generate') {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pbs_generation_day'");
        $startDay = (int) ($stmt->fetchColumn() ?: 0);
        
        $startDate = new DateTime('today');
        // Find start of current period (most recent start day)
        while ((int)$startDate->format('w') !== $startDay) {
            $startDate->modify('-1 day');
        }

        $startDateStr = $startDate->format('Y-m-d');
        $endDate = clone $startDate;
        $endDate->modify('+6 days');
        $endDateStr = $endDate->format('Y-m-d');

        // Get all pilots
        $stmt = $pdo->query("SELECT id FROM pilots");
        $pilots = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $matcher = new ScheduleMatcher($pdo);
        $totalAssigned = 0;

        foreach ($pilots as $pilotId) {
            $schedule = $matcher->generateRoster($pilotId, $startDateStr, $endDateStr);
            if (is_array($schedule)) {
                $totalAssigned += count($schedule);
            }
        }

        echo json_encode([
            'success' => true, 
            'message' => "Geração concluída! Foram geradas $totalAssigned atribuições de voo para o período de $startDateStr a $endDateStr."
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Ação inválida.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
