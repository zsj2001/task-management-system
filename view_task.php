<?php
session_start();

$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

require_once 'db.php';

$tID = (int)($_GET['tID'] ?? 0);
if (!$tID) {
    header("Location: index.php");
    exit;
}

$back = $_GET['back'] ?? '';

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
$backUrl = 'view_task.php?tID=' . $tID . '&go_back=1' . ($back !== '' ? '&back=' . urlencode($back) : '');

// Fetch Task Details
$stmt = $pdo->prepare("
    SELECT 
        t.*, u.urgName, s.sName as status_name,
        e.eName as creator_name, creator_dept.deptName as creator_department, creator_org.oName as creator_organization, creator_loc.lName as creator_location,
        target_p.display_name as target_person_name, target_dept.deptName as target_department, target_org.oName as target_organization, target_loc.lName as target_location,
        tc.tcName as category_name
    FROM task t
    INNER JOIN location l ON t.lID = l.lID
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

// Fetch Attachments
$stmtAtt = $pdo->prepare("SELECT * FROM task_attachment WHERE tID = :tID");
$stmtAtt->execute(['tID' => $tID]);
$attachments = $stmtAtt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($task['tNo']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f9; color: #333; }
        .card { background: #fff; padding: 25px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        .btn { padding: 8px 16px; text-decoration: none; border-radius: 4px; color: #fff; background: #6c757d; display: inline-block; }
        .btn-edit { background: #2ba3b6; float: right; }
        .img-preview { max-width: 100%; max-height: 300px; border-radius: 4px; border: 1px solid #ddd; margin-top: 10px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .success-message { display: flex; align-items: center; justify-content: space-between; gap: 20px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 12px 16px; border-radius: 5px; margin-bottom: 20px; }
        .success-text { font-weight: 500; }
        .success-ok-btn { background-color: #28a745; color: white; border: none; padding: 7px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .success-ok-btn:hover { background-color: #218838; }
    </style>
</head>
<body>
    <?php if ($successMessage): ?>
        <div class="success-message" id="successMessage">
            <div class="success-text">
                <?= htmlspecialchars($successMessage) ?>
            </div>
            <script>
                // Automatically remove the message after 5 seconds
                setTimeout(() => {
                    const message = document.getElementById('successMessage');
                    if (message) message.remove();
                }, 5000);
            </script>
        </div>
    <?php endif; ?>

<div class="card">
    <div class="header-actions">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn" style="margin-bottom: 15px;">Back to Task Listing</a>
        <a href="create_task.php?tID=<?= $task['tID'] ?>&back=<?= urlencode($back) ?>" class="btn btn-edit">Edit Task</a>
    </div>
    
    <h2><?= htmlspecialchars($task['tNo']) ?></h2>
    
    <div class="grid-2">
        <p><strong>Status:</strong> <?= htmlspecialchars($task['status_name']) ?></p>
        <p><strong>Urgency:</strong> <?= htmlspecialchars($task['urgName'] ?? 'Uncategorized') ?></p>
        <p><strong>Category:</strong> <?= htmlspecialchars($task['category_name']) ?></p>
        <p>
            <strong>Requestor:</strong>
            <?= htmlspecialchars($task['creator_name']) ?>,
            <?= htmlspecialchars($task['creator_department']) ?>,
            <?= htmlspecialchars($task['creator_organization']) ?>,
            <?= htmlspecialchars($task['creator_location']) ?>
        </p>
        <p>
            <strong>Target:</strong>
                <?php if (!empty($task['target_person_name'])): ?>
                    <?= htmlspecialchars($task['target_person_name']) ?>,
                    <?= htmlspecialchars($task['target_department'] ?? '') ?>,
                    <?= htmlspecialchars($task['target_organization'] ?? '') ?>,
                    <?= htmlspecialchars($task['target_location'] ?? '') ?>
                <?php else: ?>
                    Unassigned
                <?php endif; ?>
        </p>
        <p><strong>Request Date:</strong> <?= htmlspecialchars($task['request_date']) ?></p>
    </div>

    <hr>
    <h4>Issue Title</h4>
    <p><?= nl2br(htmlspecialchars($task['tTitle'])) ?></p>
    
    <hr>
    <h4>Issue Description</h4>
    <p><?= nl2br(htmlspecialchars($task['tDesc'])) ?></p>

    <?php if (!empty($attachments)): ?>
        <hr>
        <h4>Attachments</h4>
        <?php foreach ($attachments as $att): ?>
            <a href="uploads/<?= htmlspecialchars($att['file_name']) ?>" target="_blank">
                <img src="uploads/<?= htmlspecialchars($att['file_name']) ?>" class="img-preview" alt="Attachment">
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>