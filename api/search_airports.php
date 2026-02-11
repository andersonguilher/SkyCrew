<?php
require_once '../db_connect.php';

header('Content-Type: application/json');

$term = $_GET['term'] ?? '';

if (strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT ident, name, municipality, iso_country, latitude_deg, longitude_deg
        FROM airports 
        WHERE ident LIKE ? OR municipality LIKE ? 
        ORDER BY CASE WHEN ident LIKE ? THEN 1 ELSE 2 END 
        LIMIT 10
    ");

    $likeTerm = "%$term%";
    $startTerm = "$term%";

    $stmt->execute([$likeTerm, $likeTerm, $startTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for frontend
    $data = [];
    foreach ($results as $row) {
        $data[] = [
            'value' => $row['ident'],
            'label' => "{$row['ident']} - {$row['municipality']} ({$row['name']})",
            'lat' => $row['latitude_deg'],
            'lng' => $row['longitude_deg']
        ];
    }

    echo json_encode($data);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
