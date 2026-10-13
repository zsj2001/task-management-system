<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

try {
    $oID = filter_input(
        INPUT_GET,
        'oID',
        FILTER_VALIDATE_INT
    );

    if ($oID !== null && $oID !== false) {
        $stmt = $pdo->prepare("
            SELECT
                l.lID AS id,
                l.oID AS organization_id,
                l.lName AS name
            FROM location l
            WHERE l.oID = :oID
            ORDER BY l.lName ASC
        ");
        $stmt->execute(['oID' => $oID]);
    } else {
        $stmt = $pdo->query("
            SELECT
                l.lID AS id,
                l.oID AS organization_id,
                l.lName AS name
            FROM location l
            ORDER BY l.lName ASC
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
        'message' => 'Failed to retrieve locations'
    ]);
}
