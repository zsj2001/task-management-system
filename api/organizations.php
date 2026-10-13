<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

try {
    $stmt = $pdo->query("
        SELECT
            oID AS id,
            oName AS name
        FROM organization
        ORDER BY oID ASC
    ");

    $data = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve organization'
    ]);
}
