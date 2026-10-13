<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

// JSON Response Helper
function jsonResponse(bool $success, string $message, array $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// Only GET is allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
        false,
        'Only GET requests are allowed.'
    );
}

// Get Task ID (e.g.: view.php?tID=21)
$tID = filter_input(INPUT_GET, 'tID', FILTER_VALIDATE_INT);

if (!$tID || $tID <= 0) {
    jsonResponse(
        false,
        'Invalid or missing tID.'
    );
}

try {
    // Fetch Task
    $stmt = $pdo->prepare("
        SELECT
            t.tID,
            t.tNo,
            t.request_date,
            t.tTitle,
            t.tDesc,
            t.expected_resolve_at,
            t.created_at,
            t.closed_at,
            u.urgName,
            s.sName AS status_name,
            tc.tcID,
            tc.tcName AS category_name,

            -- Requestor
            creator_e.eName AS creator_name,
            creator_dept.deptName AS creator_department,
            creator_loc.lName AS creator_location,
            creator_org.oName AS creator_organization,

            -- Target
            target_p.pID AS target_pID,
            target_e.eName AS target_person_name,
            target_dept.deptName AS target_department,
            target_loc.lName AS target_location,
            target_org.oName AS target_organization
        FROM task t
        INNER JOIN urgency u ON t.urgID = u.urgID
        INNER JOIN status s ON t.current_sID = s.sID
        INNER JOIN employee creator_e ON t.created_by_eID = creator_e.eID
        INNER JOIN department creator_dept ON creator_e.deptID = creator_dept.deptID
        INNER JOIN location creator_loc ON creator_dept.lID = creator_loc.lID
        INNER JOIN organization creator_org ON creator_loc.oID = creator_org.oID
        LEFT JOIN task_category tc ON t.tcID = tc.tcID

        -- Target
        LEFT JOIN task_party tp_target
            ON t.tID = tp_target.tID
            AND tp_target.role = 'TARGET'
        LEFT JOIN party target_p
            ON tp_target.pID = target_p.pID
        LEFT JOIN employee target_e ON target_p.eID = target_e.eID
        LEFT JOIN department target_dept ON target_e.deptID = target_dept.deptID
        LEFT JOIN location target_loc ON target_dept.lID = target_loc.lID
        LEFT JOIN organization target_org ON target_loc.oID = target_org.oID
        WHERE t.tID = :tID
        LIMIT 1
    ");
    $stmt->execute(['tID' => $tID]);
    $task = $stmt->fetch();

    if (!$task) {
        jsonResponse(
            false,
            'Task not found.'
        );
    }

    // Fetch Attachments
    $stmtAtt = $pdo->prepare("
        SELECT attID, tID, file_name, uploaded_at 
        FROM task_attachment
        WHERE tID = :tID
        ORDER BY uploaded_at ASC, attID ASC
    ");

    $stmtAtt->execute(['tID' => $tID]);
    $attachments = $stmtAtt->fetchAll();

    // Build Attachment Response
    $attachmentData = [];

    foreach ($attachments as $attachment) {
        $attachmentData[] = [
            'file_name' => $attachment['file_name']
        ];
    }

    // Build Final Response
    $data = [
        'tID' => (int)$task['tID'],
        'tNo' => $task['tNo'],
        'title' => $task['tTitle'],
        'description' => $task['tDesc'],
        'request_date' => $task['request_date'],
        'expected_resolve_at' => $task['expected_resolve_at'],
        'created_at' => $task['created_at'],
        'closed_at' => $task['closed_at'],
        'status' => $task['status_name'],
        'urgency' => $task['urgName'],
        'category' => $task['tcID'] !== null ? $task['category_name'] : null,
        'requestor' => [
            'employee' => $task['creator_name'],
            'department' => $task['creator_department'],
            'location' => $task['creator_location'],
            'organization' => $task['creator_organization']
        ],
        'target' => $task['target_pID'] !== null
            ? [
                'employee' => $task['target_person_name'],
                'department' => $task['target_department'],
                'location' => $task['target_location'],
                'organization' => $task['target_organization']
            ] : null,
        'attachments' => $attachmentData
    ];

    // Success
    jsonResponse(
        true,
        'Task retrieved successfully.',
        $data
    );
} catch (PDOException $e) {
    error_log(
        'view_task API database error: '
        . $e->getMessage()
    );

    jsonResponse(
        false,
        'Database error while retrieving task.'
    );
} catch (Throwable $e) {
    error_log(
        'view_task API error: '
        . $e->getMessage()
    );

    jsonResponse(
        false,
        'An unexpected error occurred.'
    );
}
