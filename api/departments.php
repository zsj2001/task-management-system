<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

try {
    $lID = filter_input(
        INPUT_GET,
        'lID',
        FILTER_VALIDATE_INT
    );

    if ($lID !== null && $lID !== false) {
        $stmt = $pdo->prepare("
            SELECT
                d.deptID AS id,
                d.lID AS location_id,
                d.deptName AS name
            FROM department d
            WHERE d.lID = :lID
            ORDER BY d.deptName ASC
        ");
        $stmt->execute(['lID' => $lID]);
    } else {
        $stmt = $pdo->query("
            SELECT
                d.deptID AS id,
                d.lID AS location_id,
                d.deptName AS name
            FROM department d
            ORDER BY d.deptName ASC
        ");
    }
    $data = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve departments'
    ]);
}