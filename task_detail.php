<?php

require_once 'db.php';

$tID = (int)($_GET['tID'] ?? 0);

if (!$tID) {
    header("Location: index.php");
    exit;
}

$back = $_GET['back'] ?? ($_POST['back'] ?? '');

function getFreshTaskListUrl(PDO $pdo, int $tID, string $back, int $perPage = 10): string {
    parse_str($back, $query);
    unset($query['page']);

    $stmt = $pdo->prepare("SELECT created_at FROM task WHERE tID = :tID");
    $stmt->execute(['tID' => $tID]);
    $currentTask = $stmt->fetch();

    if (!$currentTask) {
        return 'index.php' . (!empty($query) ? '?' . http_build_query($query) : '');
    }

    $fromSql = "
        FROM task t
        INNER JOIN urgency u ON t.urgID = u.urgID
        INNER JOIN status s ON t.current_sID = s.sID
        INNER JOIN employee e_creator ON t.created_by_eID = e_creator.eID
        INNER JOIN department creator_dept ON e_creator.deptID = creator_dept.deptID
        INNER JOIN location creator_loc ON creator_dept.lID = creator_loc.lID
        INNER JOIN organization creator_org ON creator_loc.oID = creator_org.oID
        LEFT JOIN task_party tp ON t.tID = tp.tID AND tp.role = 'TARGET'
        LEFT JOIN party target_p ON tp.pID = target_p.pID
        LEFT JOIN task_category tc ON t.tcID = tc.tcID
        WHERE 1=1
    ";

    $params = [];

    if (($query['status'] ?? '') !== '') {
        $fromSql .= " AND t.current_sID = :status";
        $params['status'] = $query['status'];
    }

    if (($query['location'] ?? '') !== '') {
        $fromSql .= " AND creator_loc.lID = :location";
        $params['location'] = $query['location'];
    }

    if (($query['category'] ?? '') !== '') {
        $fromSql .= " AND t.tcID = :category";
        $params['category'] = $query['category'];
    }

    if (($query['urgency'] ?? '') !== '') {
        $fromSql .= " AND u.urgID = :urgency";
        $params['urgency'] = $query['urgency'];
    }

    if (($query['request_date'] ?? '') !== '') {
        $fromSql .= " AND t.request_date = :request_date";
        $params['request_date'] = $query['request_date'];
    }

    if (trim($query['q'] ?? '') !== '') {
        $fromSql .= "
            AND (
                t.tTitle LIKE :q_title
                OR t.tDesc LIKE :q_desc
                OR t.tNo LIKE :q_no
                OR target_p.display_name LIKE :q_person
            )
        ";

        $searchValue = '%' . trim($query['q']) . '%';
        $params['q_title']  = $searchValue;
        $params['q_desc']   = $searchValue;
        $params['q_no']     = $searchValue;
        $params['q_person'] = $searchValue;
    }

    $sql = "
        SELECT COUNT(DISTINCT t.tID)
        " . $fromSql . "
        AND (
            t.created_at > :created_at_after
            OR (t.created_at = :created_at_tie AND t.tID > :current_tID)
        )
    ";

    $params['created_at_after'] = $currentTask['created_at'];
    $params['created_at_tie'] = $currentTask['created_at'];
    $params['current_tID'] = $tID;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $recordsAfter = (int)$stmt->fetchColumn();
    $query['page'] = (int)floor($recordsAfter / $perPage) + 1;

    return 'index.php?' . http_build_query($query);
}

if (isset($_GET['go_back']) && $_GET['go_back'] === '1') {
    header("Location: " . getFreshTaskListUrl($pdo, $tID, $back));
    exit;
}

$backUrl = 'task_detail.php?tID=' . $tID . '&go_back=1' . ($back !== '' ? '&back=' . urlencode($back) : '');
$backSuffix = $back !== '' ? ('&back=' . urlencode($back)) : '';

// Fetch Main Task Data
$stmt = $pdo->prepare("
    SELECT 
        t.*, u.urgName, s.sName as status_name,
        e.eName as creator_name, creator_dept.deptName as creator_department, creator_org.oName as creator_organization, creator_loc.lName as creator_location,
        target_p.display_name as target_person_name, target_dept.deptName as target_department, target_org.oName as target_organization, target_loc.lName as target_location,
        tc.tcName as category_name
    FROM task t
    INNER JOIN urgency u ON t.urgID = u.urgID
    INNER JOIN status s ON t.current_sID = s.sID
    INNER JOIN employee e ON t.created_by_eID = e.eID
    INNER JOIN department creator_dept ON e.deptID = creator_dept.deptID
    INNER JOIN location creator_loc ON creator_dept.lID = creator_loc.lID
    INNER JOIN organization creator_org ON creator_loc.oID = creator_org.oID
    LEFT JOIN task_party tp ON t.tID = tp.tID AND tp.role = 'TARGET'
    LEFT JOIN party target_p ON tp.pID = target_p.pID
    LEFT JOIN employee target_e ON target_p.eID = target_e.eID
    LEFT JOIN department target_dept ON target_e.deptID = target_dept.deptID
    LEFT JOIN location target_loc ON target_dept.lID = target_loc.lID
    LEFT JOIN organization target_org ON target_loc.oID = target_org.oID
    LEFT JOIN task_category tc ON t.tcID = tc.tcID
    WHERE t.tID = :tID
");
$stmt->execute(['tID' => $tID]);
$task = $stmt->fetch();

if (!$task) {
    die("Task not found.");
}

// closed task is read-only
$isClosed = ($task['status_name'] === 'CLOSED');
$disabledAttr = $isClosed ? 'disabled' : '';

// Handle Form Submissions (Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isClosed) {
        header("Location: task_detail.php?tID=" . $tID . $backSuffix);
        exit;
    }

    $action_type = $_POST['action_type'];
    $action_by   = (int)$_POST['action_by_eID'];
    $remark      = trim($_POST['tactRemark'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($action_type === 'RESOLVE_PLAN') {
            $expected_date  = $_POST['expected_resolve_at'] ?: null;
            $root_cause     = trim($_POST['root_cause'] ?? '');
            $solution_steps = trim($_POST['solution_steps'] ?? '');

            // Update Task expected resolve date
            $stmtUpd = $pdo->prepare("UPDATE task SET expected_resolve_at = :exp WHERE tID = :tID");
            $stmtUpd->execute(['exp' => $expected_date, 'tID' => $tID]);

            // Log action
            $stmtAct = $pdo->prepare("
                INSERT INTO task_action (tID, action_by_eID, tactType, tactRemark, root_cause, solution_steps, expected_resolve_at, created_at)
                VALUES (:tID, :eID, 'RESOLVE_PLAN', :remark, :root_cause, :solution_steps, :exp, NOW())
            ");
            $stmtAct->execute([
                'tID'            => $tID,
                'eID'            => $action_by,
                'remark'         => $remark,
                'root_cause'     => $root_cause,
                'solution_steps' => $solution_steps,
                'exp'            => $expected_date
            ]);

        } elseif ($action_type === 'REASSIGN') {
            $reassign_oID    = (int)($_POST['reassign_oID'] ?? 0);
            $reassign_deptID = (int)($_POST['reassign_deptID'] ?? 0);
            $reassign_pID    = (int)($_POST['reassign_pID'] ?? 0);

            // Validate reassign org -> dept -> employee
            $stmtTarget = $pdo->prepare("
                SELECT p.pID
                FROM party p
                INNER JOIN employee e ON p.eID = e.eID
                INNER JOIN department d ON e.deptID = d.deptID
                WHERE p.pID = :pID
                  AND p.pType = 'EMPLOYEE'
                  AND e.deptID = :deptID
                  AND d.lID = :lID
                  AND e.is_active = 1
                LIMIT 1
            ");
            $stmtTarget->execute([
                'pID'    => $reassign_pID,
                'deptID' => $reassign_deptID,
                'oID'    => $reassign_oID
            ]);

            if (!$stmtTarget->fetch()) {
                throw new Exception('Invalid reassign organization, department, or employee.');
            }

            $new_party_id = $reassign_pID;

            // Update Target Task Party
            $pdo->prepare("DELETE FROM task_party WHERE tID = :tID AND role = 'TARGET'")->execute(['tID' => $tID]);
            $pdo->prepare("INSERT INTO task_party (tID, pID, role) VALUES (:tID, :pID, 'TARGET')")->execute(['tID' => $tID, 'pID' => $new_party_id]);

            // Get 'REASSIGNED' status
            $sStmt = $pdo->prepare("SELECT sID FROM status WHERE sName = 'REASSIGNED' LIMIT 1");
            $sStmt->execute();
            $reassignedStatus = $sStmt->fetchColumn();

            if ($reassignedStatus) {
                $pdo->prepare("UPDATE task SET current_sID = :sID WHERE tID = :tID")->execute(['sID' => $reassignedStatus, 'tID' => $tID]);
            }

            // Log Action
            $pdo->prepare("
                INSERT INTO task_action (tID, action_by_eID, sID, tactType, tactRemark, created_at)
                VALUES (:tID, :eID, :sID, 'REASSIGN', :remark, NOW())
            ")->execute(['tID' => $tID, 'eID' => $action_by, 'sID' => $reassignedStatus, 'remark' => $remark]);

        } elseif ($action_type === 'UPDATE_STATUS') {
            $new_status_id = (int)$_POST['sID'];

            $pdo->prepare("UPDATE task SET current_sID = :sID WHERE tID = :tID")->execute(['sID' => $new_status_id, 'tID' => $tID]);

            $pdo->prepare("
                INSERT INTO task_action (tID, action_by_eID, sID, tactType, tactRemark, created_at)
                VALUES (:tID, :eID, :sID, 'UPDATE_STATUS', :remark, NOW())
            ")->execute(['tID' => $tID, 'eID' => $action_by, 'sID' => $new_status_id, 'remark' => $remark]);

        } elseif ($action_type === 'CLOSE') {
            // Get 'CLOSED' status
            $sStmt = $pdo->prepare("SELECT sID FROM status WHERE sName = 'CLOSED' LIMIT 1");
            $sStmt->execute();
            $closedStatus = $sStmt->fetchColumn();

            $pdo->prepare("UPDATE task SET current_sID = :sID, closed_at = NOW() WHERE tID = :tID")->execute(['sID' => $closedStatus, 'tID' => $tID]);

            $pdo->prepare("
                INSERT INTO task_action (tID, action_by_eID, sID, tactType, tactRemark, created_at)
                VALUES (:tID, :eID, :sID, 'CLOSE', :remark, NOW())
            ")->execute(['tID' => $tID, 'eID' => $action_by, 'sID' => $closedStatus, 'remark' => $remark]);
        }

        $pdo->commit();
        header("Location: task_detail.php?tID=" . $tID . $backSuffix);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Action failed: " . $e->getMessage();
    }
}

// Fetch Attachments
$stmtAtt = $pdo->prepare("SELECT * FROM task_attachment WHERE tID = :tID");
$stmtAtt->execute(['tID' => $tID]);
$attachments = $stmtAtt->fetchAll();

// Fetch Action History
$stmtActHistory = $pdo->prepare("
    SELECT ta.*, e.eName, s.sName as status_name 
    FROM task_action ta
    INNER JOIN employee e ON ta.action_by_eID = e.eID
    LEFT JOIN status s ON ta.sID = s.sID
    WHERE ta.tID = :tID
    ORDER BY ta.created_at DESC
");
$stmtActHistory->execute(['tID' => $tID]);
$action_history = $stmtActHistory->fetchAll();

// Extract latest actions for pre-populating fields
$latest_resolve = null;
$latest_reassign = null;
$latest_status = null;
$latest_close = null;
foreach ($action_history as $act) {
    if (!$latest_resolve && $act['tactType'] === 'RESOLVE_PLAN') $latest_resolve = $act;
    if (!$latest_reassign && $act['tactType'] === 'REASSIGN') $latest_reassign = $act;
    if (!$latest_status && $act['tactType'] === 'UPDATE_STATUS') $latest_status = $act;
    if (!$latest_close && $act['tactType'] === 'CLOSE') $latest_close = $act;
}

// Fetch current target person org/dept info if assigned
$stmtTargetInfo = $pdo->prepare("
    SELECT p.pID, e.deptID, d.lID, l.oID
    FROM task_party tp
    JOIN party p ON tp.pID = p.pID
    JOIN employee e ON p.eID = e.eID
    JOIN department d ON e.deptID = d.deptID
    JOIN location l ON d.lID = l.lID
    WHERE tp.tID = :tID AND tp.role = 'TARGET'
    LIMIT 1
");
$stmtTargetInfo->execute(['tID' => $tID]);
$targetInfo = $stmtTargetInfo->fetch();

$statuses  = $pdo->query("SELECT sID, sName FROM status ORDER BY sID")->fetchAll();
$employees = $pdo->query("SELECT eID, eName FROM employee WHERE is_active = 1 ORDER BY eName")->fetchAll();

// Reassign To: org -> loc -> dept -> emp
$reassignOrganizations = $pdo->query("SELECT oID, oName FROM organization ORDER BY oName")->fetchAll();
$reassignLocations     = $pdo->query("SELECT lID, oID, lName FROM location ORDER BY lName")->fetchAll();
$reassignDepartments   = $pdo->query("SELECT deptID, lID, deptName FROM department ORDER BY deptName")->fetchAll();
$reassignEmployees     = $pdo->query("SELECT e.eID, e.eName, e.deptID, d.lID, p.pID
    FROM employee e
    INNER JOIN department d
        ON e.deptID = d.deptID
    INNER JOIN party p
        ON p.eID = e.eID
       AND p.pType = 'EMPLOYEE'
    WHERE e.is_active = 1
    ORDER BY e.eName
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assigned person - <?= htmlspecialchars($task['tNo']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f9; }
        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 12px; }
        label { font-weight: bold; display: block; margin-bottom: 4px; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        input:disabled, select:disabled, textarea:disabled { background-color: #e9ecef; cursor: not-allowed; }
        .btn { padding: 8px 16px; color: #fff; background: #2ba3b6; border: none; border-radius: 4px; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
        .btn-close { background: #dc3545; }
        .history-item { border-bottom: 1px solid #eee; padding: 10px 0; }
        .img-preview { max-width: 100%; max-height: 250px; border-radius: 4px; border: 1px solid #ddd; }
        .target-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; width: 100%; }
        .target-field { min-width: 0; }
        .target-field label { font-weight: bold; margin-bottom: 4px; }
        .target-field select { width: 100%; }
        @media (max-width: 700px) {
            .target-row { grid-template-columns: 1fr; gap: 12px; }
        }
    </style>
</head>
<body>

<a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-secondary" style="text-decoration:none; display:inline-block; margin-bottom:15px;">Back to Task List</a>

<div class="grid">
    <div>
        <!-- Task Details -->
        <div class="card">
            <h2>[<?= htmlspecialchars($task['tNo']) ?>] <?= htmlspecialchars($task['tTitle']) ?></h2>
            <p><strong>Status:</strong> <?= htmlspecialchars($task['status_name']) ?>&emsp; | &emsp;<strong>Urgency:</strong> <?= htmlspecialchars($task['urgName']) ?>&emsp; | &emsp;<strong>Category:</strong> <?= htmlspecialchars($task['category_name'] ?? 'Uncategorized') ?></p>
            <p><strong>Requestor:</strong> 
                <?= htmlspecialchars($task['creator_name']) ?>,
                <?= htmlspecialchars($task['creator_department']) ?>,
                <?= htmlspecialchars($task['creator_organization']) ?>,
                <?= htmlspecialchars($task['creator_location']) ?>&emsp; | &emsp;<strong>Request Date:</strong> <?= htmlspecialchars($task['request_date']) ?>
            </p>
            <p><strong>Target:</strong> 
                <?php if (!empty($task['target_person_name'])): ?>
                    <?= htmlspecialchars($task['target_person_name']) ?>,
                    <?= htmlspecialchars($task['target_department'] ?? '') ?>,
                    <?= htmlspecialchars($task['target_organization'] ?? '') ?>,
                    <?= htmlspecialchars($task['target_location'] ?? '') ?>
                <?php else: ?>
                    Unassigned
                <?php endif; ?>
            </p>
            <hr>
            <h4>Issue Description:</h4>
            <p><?= nl2br(htmlspecialchars($task['tDesc'])) ?></p>

            <?php if (!empty($attachments)): ?>
                <h4>Attachments:</h4>
                <?php foreach ($attachments as $att): ?>
                    <a href="uploads/<?= htmlspecialchars($att['file_name']) ?>" target="_blank">
                        <img src="uploads/<?= htmlspecialchars($att['file_name']) ?>" class="img-preview" alt="Task Attachment">
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Action Panel -->
        <div class="card">
            <h3>Take Action</h3>

            <!-- Action 1: Resolve Plan -->
            <form method="POST" style="margin-bottom: 20px;" data-form-section="1">
                <input type="hidden" name="action_type" value="RESOLVE_PLAN">
                <h4>1. State Resolve Plan & Expected Date</h4>
                
                <div class="form-group">
                    <label>Action Performed By:</label>
                    <select name="action_by_eID" <?= $disabledAttr ?> required>
                        <option value=""></option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['eID'] ?>" <?= (isset($latest_resolve['action_by_eID']) && $latest_resolve['action_by_eID'] == $e['eID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['eName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Expected Resolve Date:</label>
                    <input type="datetime-local" name="expected_resolve_at" <?= $disabledAttr ?> value="<?= htmlspecialchars(isset($task['expected_resolve_at']) && $task['expected_resolve_at'] ? date('Y-m-d\TH:i', strtotime($task['expected_resolve_at'])) : '') ?>">
                </div>

                <div class="form-group">
                    <label>Root Cause:</label>
                    <textarea name="root_cause" rows="2" <?= $disabledAttr ?>><?= htmlspecialchars($latest_resolve['root_cause'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Solution Steps:</label>
                    <textarea name="solution_steps" rows="2" <?= $disabledAttr ?>><?= htmlspecialchars($latest_resolve['solution_steps'] ?? '') ?></textarea>
                </div>

                <?php if (!$isClosed): ?>
                    <button type="submit" class="btn">Save Resolve Plan</button>
                    <button type="button" class="btn btn-secondary btn-update" style="display:none;">Update</button>
                <?php endif; ?>
            </form>
            <hr>

            <!-- Action 2: Reassign -->
            <form method="POST" style="margin-bottom: 20px;" data-form-section="2">
                <input type="hidden" name="action_type" value="REASSIGN">
                <h4>2. Reassign Task</h4>

                <div class="form-group">
                    <label>Action Performed By:</label>
                    <select name="action_by_eID" <?= $disabledAttr ?> required>
                        <option value=""></option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['eID'] ?>" <?= (isset($latest_reassign['action_by_eID']) && $latest_reassign['action_by_eID'] == $e['eID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['eName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Reassign To:</label>
                    <div class="target-row"style="grid-template-columns: repeat(4, 1fr);">
                        <div class="target-field">
                            <label>Organization:</label>
                            <select name="reassign_oID" id="reassignOrganizationSelect" <?= $disabledAttr ?> required>
                                <option value=""></option>
                                <?php foreach ($reassignOrganizations as $org): ?>
                                    <option value="<?= (int)$org['oID'] ?>" <?= (isset($targetInfo['oID']) && $targetInfo['oID'] == $org['oID']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($org['oName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="target-field">
                            <label>Location:</label>
                            <select name="reassign_lID" id="reassignLocationSelect" <?= $disabledAttr ?> required <?= !isset($targetInfo['oID']) ? 'disabled' : '' ?>>
                                <option value=""></option>
                                <?php foreach ($reassignLocations as $loc): ?>
                                    <option value="<?= (int)$loc['lID'] ?>" data-org-id="<?= (int)$loc['oID'] ?>" <?= (isset($targetInfo['lID']) && $targetInfo['lID'] == $loc['lID']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($loc['lName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="target-field">
                            <label>Department:</label>
                            <select name="reassign_deptID" id="reassignDepartmentSelect" <?= $disabledAttr ?> required <?= !isset($targetInfo['oID']) ? 'disabled' : '' ?>>
                                <option value=""></option>
                                <?php foreach ($reassignDepartments as $dept): ?>
                                    <option value="<?= (int)$dept['deptID'] ?>"
                                        data-loc-id="<?= (int)$dept['lID'] ?>"
                                        <?= (isset($targetInfo['deptID']) && $targetInfo['deptID'] == $dept['deptID']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($dept['deptName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="target-field">
                            <label>Employee:</label>
                            <select name="reassign_pID" id="reassignEmployeeSelect" <?= $disabledAttr ?> required <?= !isset($targetInfo['deptID']) ? 'disabled' : '' ?>>
                                <option value=""></option>
                                <?php foreach ($reassignEmployees as $emp): ?>
                                    <option value="<?= (int)$emp['pID'] ?>"
                                        data-dept-id="<?= (int)$emp['deptID'] ?>"
                                        <?= (isset($targetInfo['pID']) && $targetInfo['pID'] == $emp['pID']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($emp['eName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Remarks:</label>
                    <input type="text" name="tactRemark" <?= $disabledAttr ?> value="<?= htmlspecialchars($latest_reassign['tactRemark'] ?? '') ?>" placeholder="">
                </div>

                <?php if (!$isClosed): ?>
                    <button type="submit" class="btn">Reassign</button>
                    <button type="button" class="btn btn-secondary btn-update" style="display:none;">Update</button>
                <?php endif; ?>
            </form>
            <hr>

            <!-- Action 3: Update Status -->
            <form method="POST" style="margin-bottom: 20px;" data-form-section="3">
                <input type="hidden" name="action_type" value="UPDATE_STATUS">
                <h4>3. Update Status</h4>

                <div class="form-group">
                    <label>Action Performed By:</label>
                    <select name="action_by_eID" <?= $disabledAttr ?> required>
                        <option value=""></option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['eID'] ?>" <?= (isset($latest_status['action_by_eID']) && $latest_status['action_by_eID'] == $e['eID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['eName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Change Status To:</label>
                    <select name="sID" <?= $disabledAttr ?> required>
                        <?php foreach ($statuses as $st): ?>
                            <?php $statusLabel = str_replace('_', ' ', $st['sName']); ?>
                            <option value="<?= $st['sID'] ?>" <?= $task['current_sID'] == $st['sID'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($statusLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Remarks:</label>
                    <input type="text" name="tactRemark" <?= $disabledAttr ?> value="<?= htmlspecialchars($latest_status['tactRemark'] ?? '') ?>">
                </div>

                <?php if (!$isClosed): ?>
                    <button type="submit" class="btn">Update Status</button>
                    <button type="button" class="btn btn-secondary btn-update" style="display:none;">Update</button>
                <?php endif; ?>
            </form>
            <hr>

            <!-- Action 4: Close Task -->
            <form method="POST" data-form-section="4">
                <input type="hidden" name="action_type" value="CLOSE">
                <h4>4. Close Task</h4>

                <div class="form-group">
                    <label>Action Performed By:</label>
                    <select name="action_by_eID" <?= $disabledAttr ?> required>
                        <option value=""></option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['eID'] ?>" <?= (isset($latest_close['action_by_eID']) && $latest_close['action_by_eID'] == $e['eID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['eName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Remarks:</label>
                    <input type="text" name="tactRemark" <?= $disabledAttr ?> value="<?= htmlspecialchars($latest_close['tactRemark'] ?? '') ?>" placeholder="">
                </div>

                <?php if (!$isClosed): ?>
                    <button type="submit" class="btn btn-close" onclick="return confirm('Are you sure you want to close this task?')">Close This Task</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Action History Log -->
    <div>
        <div class="card">
            <h3>Task Action History</h3>
            <?php if (empty($action_history)): ?>
                <p>No activity logged yet.</p>
            <?php else: ?>
                <?php foreach ($action_history as $act): ?>
                    <div class="history-item">
                        <strong><?= htmlspecialchars($act['tactType']) ?></strong> by <?= htmlspecialchars($act['eName']) ?><br>
                        <small style="color:#777;"><?= $act['created_at'] ?></small>
                        <?php if ($act['status_name']): ?>
                            <div><small>Status: <?= htmlspecialchars($act['status_name']) ?></small></div>
                        <?php endif; ?>
                        <?php if ($act['tactRemark'] && trim($act['tactRemark']) !== ''): ?>
                            <div><em>"<?= htmlspecialchars($act['tactRemark']) ?>"</em></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$isClosed): ?>
<script>
// org -> loc -> dept -> emp (Reassign To)
const reassignOrganizationSelect = document.getElementById('reassignOrganizationSelect');
const reassignLocationSelect = document.getElementById('reassignLocationSelect');
const reassignDepartmentSelect = document.getElementById('reassignDepartmentSelect');
const reassignEmployeeSelect = document.getElementById('reassignEmployeeSelect');

reassignOrganizationSelect.addEventListener('change', function () { // org -> loc
    const selectedOrgId = this.value;

    reassignLocationSelect.value = '';
    reassignDepartmentSelect.value = '';
    reassignEmployeeSelect.value = '';
    reassignDepartmentSelect.disabled = true;
    reassignEmployeeSelect.disabled = true;

    if (!selectedOrgId) {
        reassignLocationSelect.disabled = true;
        return;
    }

    reassignLocationSelect.disabled = false;
    let hasLocations = false;

    Array.from(reassignLocationSelect.options).forEach(option => {
        if (!option.value) return;

        const belongsToOrganization = option.dataset.orgId === selectedOrgId;
        option.hidden = !belongsToOrganization;

        if (belongsToOrganization) hasLocations = true;
    });

    if (!hasLocations) {
        reassignLocationSelect.disabled = true;
    }
});

reassignLocationSelect.addEventListener('change', function () { // Loc -> Dept
    const selectedLocId = this.value;

    reassignDepartmentSelect.value = '';
    reassignEmployeeSelect.value = '';
    reassignEmployeeSelect.disabled = true;

    if (!selectedLocId) {
        reassignDepartmentSelect.disabled = true;
        return;
    }

    reassignDepartmentSelect.disabled = false;
    let hasDepartments = false;

    Array.from(reassignDepartmentSelect.options).forEach(option => {
        if (!option.value) return;
        const belongsToLoc = option.dataset.locId === selectedLocId;
        option.hidden = !belongsToLoc;
        if (belongsToLoc) hasDepartments = true;
    });

    if (!hasDepartments) {
        reassignDepartmentSelect.disabled = true;
    }
});

reassignDepartmentSelect.addEventListener('change', function () { //dept -> emp
    const selectedDeptId = this.value;
    reassignEmployeeSelect.value = '';

    if (!selectedDeptId) {
        reassignEmployeeSelect.disabled = true;
        return;
    }

    reassignEmployeeSelect.disabled = false;
    let hasEmployees = false;

    Array.from(reassignEmployeeSelect.options).forEach(option => {
        if (!option.value) return;

        const belongsToDepartment = option.dataset.deptId === selectedDeptId;
        option.hidden = !belongsToDepartment;

        if (belongsToDepartment) hasEmployees = true;
    });

    if (!hasEmployees) {
        reassignEmployeeSelect.disabled = true;
    }
});

// Read-only state management & draft storage
(function () {
    const taskId = <?= (int)$tID ?>;
    const storageKey = 'task_detail_draft_'  + taskId;
    const statusKey = 'task_detail_status_' + taskId;
    const forms = Array.from(document.querySelectorAll('.card form'));

    function getAllFields() {
        let fields = [];
        forms.forEach((form, formIndex) => {
            Array.from(form.querySelectorAll('input[name], select[name], textarea[name]')).forEach(field => {
                if (field.name === 'action_type') return;
                fields.push({ field, key: formIndex + '.' + field.name, form });
            });
        });
        return fields;
    }

    function saveDraft() {
        const draft = {};
        getAllFields().forEach(({ field, key }) => {
            draft[key] = field.value;
        });
        try {
            localStorage.setItem(storageKey, JSON.stringify(draft));
        } catch (e) {}
    }

    function getSubmittedState() {
        try {
            return JSON.parse(localStorage.getItem(statusKey) || '{}');
        } catch (e) {
            return {};
        }
    }

    function setSubmittedState(sectionNum, isSubmitted) {
        let state = getSubmittedState();
        state[sectionNum] = isSubmitted;
        try {
            localStorage.setItem(statusKey, JSON.stringify(state));
        } catch (e) {}
    }

    function applyFormReadOnlyState() {
        let state = getSubmittedState();
        forms.forEach((form, idx) => {
            let sectionNum = form.getAttribute('data-form-section');
            let isSubmitted = state[sectionNum];
            let fields = form.querySelectorAll('input[name], select[name], textarea[name]');
            let submitBtn = form.querySelector('button[type="submit"]');
            let updateBtn = form.querySelector('.btn-update');

            if (isSubmitted) {
                fields.forEach(f => f.disabled = true);
                if (submitBtn) submitBtn.style.display = 'none';
                if (updateBtn) updateBtn.style.display = 'inline-block';
            } else {
                fields.forEach(f => {
                    if (f === reassignDepartmentSelect && !reassignOrganizationSelect.value) return;
                    if (f === reassignEmployeeSelect && !reassignDepartmentSelect.value) return;
                    f.disabled = false;
                });
                if (submitBtn) submitBtn.style.display = 'inline-block';
                if (updateBtn) updateBtn.style.display = 'none';
            }
        });
    }

    function restoreDraft() {
        let raw;
        try {
            raw = localStorage.getItem(storageKey);
        } catch (e) {
            return;
        }
        if (!raw) return;

        let draft;
        try {
            draft = JSON.parse(raw);
        } catch (e) {
            return;
        }

        const cascadeSelects = [reassignOrganizationSelect, reassignDepartmentSelect, reassignEmployeeSelect];

        getAllFields().forEach(({ field, key }) => {
            if (cascadeSelects.includes(field)) return;
            if (draft[key] !== undefined) {
                field.value = draft[key];
            }
        });

        const orgKey = getAllFields().find(({ field }) => field === reassignOrganizationSelect)?.key;
        const deptKey = getAllFields().find(({ field }) => field === reassignDepartmentSelect)?.key;
        const empKey = getAllFields().find(({ field }) => field === reassignEmployeeSelect)?.key;

        if (orgKey && draft[orgKey]) {
            reassignOrganizationSelect.value = draft[orgKey];
            reassignOrganizationSelect.dispatchEvent(new Event('change'));

            if (deptKey && draft[deptKey]) {
                reassignDepartmentSelect.value = draft[deptKey];
                reassignDepartmentSelect.dispatchEvent(new Event('change'));

                if (empKey && draft[empKey]) {
                    reassignEmployeeSelect.value = draft[empKey];
                }
            }
        }
    }

    restoreDraft();
    applyFormReadOnlyState();

    getAllFields().forEach(({ field }) => {
        field.addEventListener('input', saveDraft);
        field.addEventListener('change', saveDraft);
    });

    forms.forEach(form => {
        let sectionNum = form.getAttribute('data-form-section');
        let updateBtn = form.querySelector('.btn-update');

        if (updateBtn) {
            updateBtn.addEventListener('click', function() {
                setSubmittedState(sectionNum, false);
                applyFormReadOnlyState();
            });
        }

        form.addEventListener('submit', function() {
            setSubmittedState(sectionNum, true);
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>