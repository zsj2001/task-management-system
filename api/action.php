<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

// JSON RESPONSE
function jsonResponse(bool $success, string $message, array $data = []) {
    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data'    => $data
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// GET JSON INPUT
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonResponse(
            false,
            'Invalid JSON request body.'
        );
    }
    return $data;
}

/*
 GET TASK ID
 Supports:
 GET:
  action.php?tID=123
 POST:
  {
       "tID": 123
  }
*/
$method = strtoupper($_SERVER['REQUEST_METHOD']);

if ($method === 'GET') {
    $tID = (int)($_GET['tID'] ?? 0);
} elseif ($method === 'POST') {
    $input = getJsonInput();

    // Also support normal form POST.
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }

    $tID = (int)($input['tID'] ?? 0);
} else {
    jsonResponse(
        false,
        'Only GET and POST requests are allowed.'
    );
}

if ($tID <= 0) {
    jsonResponse(
        false,
        'tID is required.'
    );
}

// GET TASK
function getTask(PDO $pdo, int $tID): ?array {
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            u.urgName,
            s.sName AS status_name,
            e.eID AS creator_eID,
            e.eName AS creator_name,
            creator_dept.deptID AS creator_deptID,
            creator_dept.deptName AS creator_department,
            creator_org.oID AS creator_oID,
            creator_org.oName AS creator_organization,
            creator_loc.lID AS creator_lID,
            creator_loc.lName AS creator_location,
            target_p.pID AS target_pID,
            target_p.display_name AS target_person_name,
            target_e.eID AS target_eID,
            target_dept.deptID AS target_deptID,
            target_dept.deptName AS target_department,
            target_loc.lID AS target_lID,
            target_loc.lName AS target_location,
            target_org.oID AS target_oID,
            target_org.oName AS target_organization,
            tc.tcID AS category_id,
            tc.tcName AS category_name
        FROM task t
        INNER JOIN urgency u ON t.urgID = u.urgID
        INNER JOIN status s ON t.current_sID = s.sID
        INNER JOIN employee e ON t.created_by_eID = e.eID
        INNER JOIN department creator_dept ON e.deptID = creator_dept.deptID
        INNER JOIN location creator_loc ON creator_dept.lID = creator_loc.lID
        INNER JOIN organization creator_org ON creator_loc.oID = creator_org.oID
        LEFT JOIN task_party tp
            ON t.tID = tp.tID
            AND tp.role = 'TARGET'
        LEFT JOIN party target_p ON tp.pID = target_p.pID
        LEFT JOIN employee target_e ON target_p.eID = target_e.eID
        LEFT JOIN department target_dept ON target_e.deptID = target_dept.deptID
        LEFT JOIN location target_loc ON target_dept.lID = target_loc.lID
        LEFT JOIN organization target_org ON target_loc.oID = target_org.oID
        LEFT JOIN task_category tc ON t.tcID = tc.tcID
        WHERE t.tID = :tID
        LIMIT 1
    ");
    $stmt->execute(['tID' => $tID]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    return $task ?: null;
}


// GET ATTACHMENTS
function getAttachments(PDO $pdo, int $tID): array {
    $stmt = $pdo->prepare("SELECT * FROM task_attachment WHERE tID = :tID ORDER BY 1");
    $stmt->execute(['tID' => $tID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET ACTION HISTORY
function getActionHistory(PDO $pdo, int $tID): array {
    $stmt = $pdo->prepare("
        SELECT
            ta.*,
            e.eID,
            e.eName,
            s.sID,
            s.sName AS status_name
        FROM task_action ta
        INNER JOIN employee e ON ta.action_by_eID = e.eID
        LEFT JOIN status s ON ta.sID = s.sID
        WHERE ta.tID = :tID
        ORDER BY ta.created_at DESC
    ");
    $stmt->execute(['tID' => $tID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET CURRENT TARGET
function getCurrentTarget(PDO $pdo, int $tID): ?array {
    $stmt = $pdo->prepare("
        SELECT
            p.pID,
            e.eID,
            e.eName,
            d.deptID,
            d.deptName,
            l.lID,
            l.lName,
            o.oID,
            o.oName
        FROM task_party tp
        INNER JOIN party p ON tp.pID = p.pID
        INNER JOIN employee e ON p.eID = e.eID
        INNER JOIN department d ON e.deptID = d.deptID
        INNER JOIN location l ON d.lID = l.lID
        INNER JOIN organization o ON l.oID = o.oID
        WHERE tp.tID = :tID AND tp.role = 'TARGET'
        LIMIT 1
    ");
    $stmt->execute(['tID' => $tID]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    return $target ?: null;
}

// GET STATUSES
function getStatuses(PDO $pdo): array {
    $stmt = $pdo->query("SELECT sID, sName FROM status ORDER BY sID");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET EMPLOYEES
function getEmployees(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT eID, eName, deptID
        FROM employee
        WHERE is_active = 1
        ORDER BY eName
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET ORGANIZATIONS
function getOrganizations(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT oID, oName
        FROM organization
        ORDER BY oName
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET LOCATIONS
function getLocations(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT lID, oID, lName
        FROM location
        ORDER BY lName
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET DEPARTMENTS
function getDepartments(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT deptID, lID, deptName
        FROM department
        ORDER BY deptName
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//GET REASSIGN EMPLOYEES
function getReassignEmployees(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT e.eID, e.eName, e.deptID, d.lID, p.pID
        FROM employee e
        INNER JOIN department d ON e.deptID = d.deptID
        INNER JOIN party p
            ON p.eID = e.eID
           AND p.pType = 'EMPLOYEE'
        WHERE e.is_active = 1
        ORDER BY e.eName
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// GET REQUEST
if ($method === 'GET') {
    $task = getTask($pdo, $tID);
    if (!$task) {
        jsonResponse(
            false,
            'Task not found.'
        );
    }
    $attachments = getAttachments($pdo, $tID);
    $actionHistory = getActionHistory($pdo, $tID);
    $currentTarget = getCurrentTarget($pdo, $tID);

    // Add latest actions.
    $latestActions = [
        'RESOLVE_PLAN'   => null,
        'REASSIGN'       => null,
        'UPDATE_STATUS'  => null,
        'CLOSE'          => null
    ];

    foreach ($actionHistory as $action) {
        $type = $action['tactType'];
        if (array_key_exists($type, $latestActions) && $latestActions[$type] === null) {
            $latestActions[$type] = $action;
        }
    }

    // Return task.
    jsonResponse(
        true,
        'Task retrieved successfully.',
        [
            'task' => $task,
            'target' => $currentTarget,
            'attachments' => $attachments,
            'action_history' => $actionHistory,
            'latest_actions' => $latestActions,
            'is_closed' => strtoupper((string)$task['status_name']) === 'CLOSED'
        ]
    );
}


/* ----------------------------------POST REQUEST---------------------------------------- */
$input = getJsonInput();
if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

//ACTION PARAMETERS
$actionType = trim((string)($input['action_type'] ?? ''));
$actionByEID = (int)($input['action_by_eID'] ?? 0);
$remark = trim((string)($input['tactRemark'] ?? ''));

if ($actionType === '') {
    jsonResponse(
        false,
        'action_type is required.'
    );
}

if ($actionByEID <= 0) {
    jsonResponse(
        false,
        'action_by_eID is required.'
    );
}

// VALID ACTIONS
$allowedActions = ['RESOLVE_PLAN', 'REASSIGN', 'UPDATE_STATUS', 'CLOSE'];

if (!in_array($actionType, $allowedActions, true)) {
    jsonResponse(
        false,
        'Invalid action_type.',
        ['allowed_actions' => $allowedActions]
    );
}

// GET CURRENT TASK
$task = getTask($pdo, $tID);
if (!$task) {
    jsonResponse(
        false,
        'Task not found.'
    );
}

//CLOSED TASK
if (strtoupper((string)$task['status_name'])=== 'CLOSED') {
    jsonResponse(
        false,
        'Task already CLOSED and cannot be modified.',
        [
            'tID' => $tID,
            'status' => 'CLOSED'
        ]
    );
}

//VALIDATE ACTION EMPLOYEE
$stmt = $pdo->prepare("
    SELECT eID, eName
    FROM employee
    WHERE eID = :eID
      AND is_active = 1
    LIMIT 1
");
$stmt->execute(['eID' => $actionByEID]);
$actionEmployee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$actionEmployee) {
    jsonResponse(
        false,
        'Invalid or inactive action_by_eID.'
    );
}

// TRANSACTION
try {
    $pdo->beginTransaction();
    //RESOLVE PLAN
    if ($actionType === 'RESOLVE_PLAN') {
        $expectedDate = trim((string)($input['expected_resolve_at'] ?? '' ));
        $rootCause = trim((string)($input['root_cause'] ?? ''));
        $solutionSteps = trim((string)($input['solution_steps'] ?? ''));
        if ($expectedDate === '') {
            $expectedDate = null;
        }

        // Update task
        $stmt = $pdo->prepare("
            UPDATE task
            SET expected_resolve_at =
                :expected_resolve_at
            WHERE tID = :tID
        ");
        $stmt->execute([
            'expected_resolve_at' => $expectedDate,
            'tID' => $tID
        ]);

        // Log action
        $stmt = $pdo->prepare("
            INSERT INTO task_action(tID, action_by_eID, tactType, tactRemark, root_cause, solution_steps, expected_resolve_at, created_at)
            VALUES(:tID, :action_by_eID, 'RESOLVE_PLAN', :tactRemark, :root_cause, :solution_steps, :expected_resolve_at, NOW())
        ");
        $stmt->execute([
            'tID' => $tID,
            'action_by_eID' => $actionByEID,
            'tactRemark' => $remark,
            'root_cause' => $rootCause,
            'solution_steps' => $solutionSteps,
            'expected_resolve_at' => $expectedDate
        ]);
        $pdo->commit();
        jsonResponse(
            true,
            'Resolve plan saved successfully.',
            [
                'tID' => $tID,
                'action_type' => $actionType,
                'expected_resolve_at' => $expectedDate,
                'root_cause' => $rootCause,
                'solution_steps' => $solutionSteps
            ]
        );
    }

    // REASSIGN
    if ($actionType === 'REASSIGN') {
        $reassignOID = (int)($input['reassign_oID'] ?? 0);
        $reassignLID = (int)($input['reassign_lID'] ?? 0);
        $reassignDeptID = (int)($input['reassign_deptID'] ?? 0);
        $reassignPID = (int)($input['reassign_pID'] ?? 0);

        if ($reassignOID <= 0 || $reassignLID <= 0 || $reassignDeptID <= 0 || $reassignPID <= 0) {
            throw new Exception('Organization, location, department and employee are required.');
        }

        // Organization -> Location
        $stmt = $pdo->prepare("
            SELECT lID
            FROM location
            WHERE lID = :lID
              AND oID = :oID
            LIMIT 1
        ");
        $stmt->execute([
            'lID' => $reassignLID,
            'oID' => $reassignOID
        ]);

        if (!$stmt->fetch()) {
            throw new Exception('Invalid organization or location.');
        }

        // Location -> Department
        $stmt = $pdo->prepare("
            SELECT deptID
            FROM department
            WHERE deptID = :deptID
              AND lID = :lID
            LIMIT 1
        ");
        $stmt->execute([
            'deptID' => $reassignDeptID,
            'lID' => $reassignLID
        ]);

        if (!$stmt->fetch()) {
            throw new Exception( 'Invalid location or department.');
        }

        // Department -> Employee -> Party
        $stmt = $pdo->prepare("
            SELECT p.pID, e.eID, e.eName, e.deptID
            FROM party p
            INNER JOIN employee e ON p.eID = e.eID
            WHERE p.pID = :pID
              AND p.pType = 'EMPLOYEE'
              AND e.deptID = :deptID
              AND e.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([
            'pID' => $reassignPID,
            'deptID' => $reassignDeptID
        ]);

        $targetEmployee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetEmployee) {
            throw new Exception('Invalid department or employee.');
        }

        // Remove old target
        $stmt = $pdo->prepare("
            DELETE FROM task_party
            WHERE tID = :tID
              AND role = 'TARGET'
        ");
        $stmt->execute(['tID' => $tID]);

        // Insert new target
        $stmt = $pdo->prepare("
            INSERT INTO task_party(tID, pID, role)
            VALUES(:tID, :pID, 'TARGET')
        ");
        $stmt->execute([
            'tID' => $tID,
            'pID' => $reassignPID
        ]);


        // Get REASSIGNED status
        $stmt = $pdo->prepare("
            SELECT sID, sName
            FROM status
            WHERE sName = 'REASSIGNED'
            LIMIT 1
        ");
        $stmt->execute();
        $reassignedStatus = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reassignedStatus) {
            throw new Exception('REASSIGNED status does not exist.');
        }

        // Update task status
        $stmt = $pdo->prepare("
            UPDATE task
            SET current_sID = :sID
            WHERE tID = :tID
        ");
        $stmt->execute([
            'sID' => $reassignedStatus['sID'],
            'tID' => $tID
        ]);

        // Log action
        $stmt = $pdo->prepare("
            INSERT INTO task_action(tID, action_by_eID, sID, tactType, tactRemark, created_at)
            VALUES(:tID, :action_by_eID, :sID, 'REASSIGN', :tactRemark, NOW())
        ");
        $stmt->execute([
            'tID' => $tID,
            'action_by_eID' => $actionByEID,
            'sID' => $reassignedStatus['sID'],
            'tactRemark' => $remark
        ]);

        $pdo->commit();

        jsonResponse(
            true,
            'Task reassigned successfully.',
            [
                'tID' => $tID,
                'action_type' => $actionType,
                'target' => [
                    'oID' => $reassignOID,
                    'lID' => $reassignLID,
                    'deptID' => $reassignDeptID,
                    'pID' => $reassignPID,
                    'employee_name' => $targetEmployee['eName']
                ],
                'status' => [
                    'sID' => (int)$reassignedStatus['sID'],
                    'sName' => $reassignedStatus['sName']
                ]
            ]
        );
    }


    // UPDATE STATUS
    if ($actionType === 'UPDATE_STATUS') {
        $newStatusID = (int)($input['sID'] ?? 0);

        if ($newStatusID <= 0) {
            throw new Exception('sID is required.');
        }

        // Verify status
        $stmt = $pdo->prepare("
            SELECT sID, sName
            FROM status
            WHERE sID = :sID
            LIMIT 1
        ");
        $stmt->execute(['sID' => $newStatusID]);
        $newStatus = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$newStatus) {
            throw new Exception('Invalid status ID.');
        }

        // CLOSE must use CLOSE action.
        if (strtoupper($newStatus['sName']) === 'CLOSED') {
            throw new Exception('Use action_type=CLOSE to close a task.');
        }

        // Update task
        $stmt = $pdo->prepare("
            UPDATE task
            SET current_sID = :sID
            WHERE tID = :tID
        ");
        $stmt->execute([
            'sID' => $newStatusID,
            'tID' => $tID
        ]);

        // Log action
        $stmt = $pdo->prepare("
            INSERT INTO task_action(tID, action_by_eID, sID, tactType, tactRemark, created_at)
            VALUES(:tID, :action_by_eID, :sID, 'UPDATE_STATUS', :tactRemark, NOW())
        ");
        $stmt->execute([
            'tID' => $tID,
            'action_by_eID' => $actionByEID,
            'sID' => $newStatusID,
            'tactRemark' => $remark
        ]);

        $pdo->commit();

        jsonResponse(
            true,
            'Task status updated successfully.',
            [
                'tID' => $tID,
                'action_type' => $actionType,
                'status' => [
                    'sID' => $newStatusID,
                    'sName' => $newStatus['sName']
                ]
            ]
        );
    }

    // CLOSE
    if ($actionType === 'CLOSE') {
        // Get CLOSED status
        $stmt = $pdo->prepare("
            SELECT sID, sName
            FROM status
            WHERE sName = 'CLOSED'
            LIMIT 1
        ");
        $stmt->execute();
        $closedStatus =$stmt->fetch(PDO::FETCH_ASSOC);

        if (!$closedStatus) {
            throw new Exception('CLOSED status does not exist.');
        }

        // Update task
        $stmt = $pdo->prepare("
            UPDATE task
            SET current_sID = :sID, closed_at = NOW()
            WHERE tID = :tID
        ");
        $stmt->execute([
            'sID' => $closedStatus['sID'],
            'tID' => $tID
        ]);

        // Log action
        $stmt = $pdo->prepare("
            INSERT INTO task_action(tID, action_by_eID, sID, tactType, tactRemark, created_at)
            VALUES(:tID, :action_by_eID, :sID, 'CLOSE', :tactRemark, NOW())
        ");
        $stmt->execute([
            'tID' => $tID,
            'action_by_eID' => $actionByEID,
            'sID' => $closedStatus['sID'],
            'tactRemark' => $remark
        ]);

        $pdo->commit();

        jsonResponse(
            true,
            'Task closed successfully.',
            [
                'tID' => $tID,
                'action_type' => $actionType,
                'status' => [
                    'sID' => (int)$closedStatus['sID'],
                    'sName' => $closedStatus['sName']
                ]
            ]
        );
    }

    // UNKNOWN ACTION
    throw new Exception('Unsupported action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(
        false,
        $e->getMessage()
    );
}
