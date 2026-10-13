<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

try {
    $deptID = filter_input(
        INPUT_GET,
        'deptID',
        FILTER_VALIDATE_INT
    );

    if ($deptID !== null && $deptID !== false) {
        $stmt = $pdo->prepare("
            SELECT
                e.eID AS id,
                e.deptID AS department_id,
                e.eName AS name,
                e.is_active
            FROM employee e
            WHERE e.deptID = :deptID
              AND e.is_active = 1
            ORDER BY e.eName ASC
        ");

        $stmt->execute(['deptID' => $deptID]);
    } else {
        $stmt = $pdo->query("
            SELECT
                e.eID AS id,
                e.deptID AS department_id,
                e.eName AS name,
                e.is_active
            FROM employee e
            WHERE e.is_active = 1
            ORDER BY e.eName ASC
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
        'message' => 'Failed to retrieve employees'
    ]);
}
