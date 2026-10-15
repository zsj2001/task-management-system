<?php
session_start();

require_once 'db.php';

function uploadImages($pdo, $tID, $eID) {
    $count = 0; // how many images were saved. Starts with zero.

    if (!isset($_FILES['attachments'])) {
        return 0;
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $uploadDir = __DIR__ . '/uploads/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $total = count($_FILES['attachments']['name']); // Counts how many file slots were sent. With multiple, PHP gives a list of names, so this is the length of that list.

    for ($i = 0; $i < $total; $i++) { // $i is file position
        // If the slot is empty, skip and go to the next one.
        if ($_FILES['attachments']['error'][$i] == UPLOAD_ERR_NO_FILE) {
            continue;
        }

        // If got file upload problem, show error message. e.g.: large file, internet connection issue
        if ($_FILES['attachments']['error'][$i] != UPLOAD_ERR_OK) {
            throw new Exception('There was an error uploading the image.');
        }

        if ($_FILES['attachments']['size'][$i] > 5 * 1024 * 1024) {
            throw new Exception('Each image must not exceed 5 MB.');
        }

        $tmp = $_FILES['attachments']['tmp_name'][$i];
        $finfo = new finfo(FILEINFO_MIME_TYPE); // creates a File Information tool and saves into $finfo. FILEINFO_MIME_TYPE option tells it to accept only the MIME type, like image/png
        $mimeType = $finfo->file($tmp); // tell the tool to open and inspect the created file, ->file() function looks inside the actual binary data to find out what it truly is

        if (!isset($allowed[$mimeType])) {
            throw new Exception('Only JPG, PNG, GIF, and WebP image files are allowed.');
        }

        if (getimagesize($tmp) === false) {
            throw new Exception('One of the files is not a valid image.');
        }

        // Check for duplicate images already attached to this task
        $newImageHash = hash_file('sha256', $tmp);

        $stmtExisting = $pdo->prepare("
            SELECT file_name
            FROM task_attachment
            WHERE tID = :tID
        ");
        $stmtExisting->execute(['tID' => $tID]);

        $existingFiles = $stmtExisting->fetchAll();

        foreach ($existingFiles as $existingFile) {
            $existingPath = $uploadDir . basename($existingFile);

            if (!is_file($existingPath)) {
                continue;
            }

            $existingImageHash = hash_file('sha256', $existingPath);

            if ($existingImageHash !== false && $newImageHash !== false && hash_equals($existingImageHash, $newImageHash)) {
                throw new Exception('This photo has already been attached to this task.');
            }
        }

        $fileName = bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];

        if (!move_uploaded_file($tmp, $uploadDir . $fileName)) {
            throw new Exception('Failed to save the uploaded image.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO task_attachment(tID, uploaded_by_eID, file_name, uploaded_at)
            VALUES(:tID, :eID, :file_name, NOW())
        ");
        $stmt->execute([
            'tID'       => $tID,
            'eID'       => $eID,
            'file_name' => $fileName
        ]);

        $count++; // Adds counter after each saved image
    }

    return $count;
}

$message = '';
$error = '';

// Check if editing an existing task
$tID = (int)($_GET['tID'] ?? 0);
$isEdit = $tID > 0;
$task = null;
$existingAttachments = [];

$back = $_GET['back'] ?? ($_POST['back'] ?? '');
$backSuffix = $back !== '' ? ('&back=' . urlencode($back)) : '';

if ($isEdit) {
    // Fetch Task Details
    $stmt = $pdo->prepare("
        SELECT 
            t.*, 
            tp_req.pID as req_pID,
            e_req.deptID as req_deptID,
            d_req.lID as req_lID,
            l_req.oID as req_oID,
            tp_target.pID as target_pID,
            e_target.deptID as target_deptID,
            d_target.lID as target_lID,
            l_target.oID as target_oID
        FROM task t
        LEFT JOIN task_party tp_req ON t.tID = tp_req.tID AND tp_req.role = 'REQUESTOR'
        LEFT JOIN party p_req ON tp_req.pID = p_req.pID
        LEFT JOIN employee e_req ON p_req.eID = e_req.eID
        LEFT JOIN department d_req ON e_req.deptID = d_req.deptID
        LEFT JOIN location l_req ON d_req.lID = l_req.lID
        LEFT JOIN task_party tp_target ON t.tID = tp_target.tID AND tp_target.role = 'TARGET'
        LEFT JOIN party p_target ON tp_target.pID = p_target.pID
        LEFT JOIN employee e_target ON p_target.eID = e_target.eID
        LEFT JOIN department d_target ON e_target.deptID = d_target.deptID
        LEFT JOIN location l_target ON d_target.lID = l_target.lID
        WHERE t.tID = :tID
    ");
    $stmt->execute(['tID' => $tID]);
    $task = $stmt->fetch();

    if (!$task) {
        die("Task not found.");
    }

    // Fetch existing attachment
    $stmtAtt = $pdo->prepare("
        SELECT *
        FROM task_attachment
        WHERE tID = :tID
        ORDER BY uploaded_at
    ");
    $stmtAtt->execute(['tID' => $tID]);
    $existingAttachments = $stmtAtt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        // get form values
        $urg_id         = (int)$_POST['urgID'];
        $request_date   = $_POST['request_date'];
        $title          = trim($_POST['tTitle']);
        $desc           = trim($_POST['tDesc']);
        $creator_eID    = (int)$_POST['created_by_eID'];
        $target_pID     = (int)$_POST['target_pID'];
        $target_lID    = (int)$_POST['target_lID'];
        $target_deptID = (int)$_POST['target_deptID'];
        $category_id    = !empty($_POST['tcID']) ? (int)$_POST['tcID'] : null;

        // validate target org → dept → employee
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
            'pID'    => $target_pID,
            'deptID' => $target_deptID,
            'lID'    => $target_lID
        ]);

        if (!$stmtTarget->fetch()) {
            throw new Exception('Invalid target organization, location, department, or employee.');
        }

        if ($isEdit) {
            // Update existing Task
            $stmtTask = $pdo->prepare("
                UPDATE task 
                SET lID = :lID, 
                    urgID = :urgID, 
                    tcID = :tcID, 
                    created_by_eID = :created_by_eID, 
                    request_date = :request_date, 
                    tTitle = :tTitle, 
                    tDesc = :tDesc 
                WHERE tID = :tID
            ");
            $stmtTask->execute([
                'lID'            => $target_lID,
                'urgID'          => $urg_id,
                'tcID'           => $category_id,
                'created_by_eID' => $creator_eID,
                'request_date'   => $request_date,
                'tTitle'         => $title,
                'tDesc'          => $desc,
                'tID'            => $tID
            ]);

            // Update Task Parties (Requestor & Target)
            $stmtParty = $pdo->prepare("SELECT pID FROM party WHERE eID = :eID LIMIT 1");
            $stmtParty->execute(['eID' => $creator_eID]);
            $requestor_pID = $stmtParty->fetchColumn();

            // Clear old roles
            $pdo->prepare("DELETE FROM task_party WHERE tID = :tID")->execute(['tID' => $tID]);

            // Insert updated roles
            $stmtTP = $pdo->prepare("INSERT INTO task_party (tID, pID, role) VALUES (:tID, :pID, :role)");
            if ($requestor_pID) {
                $stmtTP->execute(['tID' => $tID, 'pID' => $requestor_pID, 'role' => 'REQUESTOR']);
            }
            if ($target_pID) {
                $stmtTP->execute(['tID' => $tID, 'pID' => $target_pID, 'role' => 'TARGET']);
            }

            // DELETE SELECTED ATTACHMENTS
            if (!empty($_POST['delete_attachments']) && is_array($_POST['delete_attachments'])) {
                $deleteAttachment = $pdo->prepare("
                    SELECT file_name
                    FROM task_attachment
                    WHERE attID = :attID
                    AND tID = :tID
                    LIMIT 1
                ");

                $deleteAttachmentDB = $pdo->prepare("DELETE FROM task_attachment WHERE attID = :attID AND tID = :tID");

                foreach ($_POST['delete_attachments'] as $attachmentID) {
                    $attachmentID = (int)$attachmentID;

                    if ($attachmentID <= 0) {
                        continue;
                    }

                    $deleteAttachment->execute([
                        'attID' => $attachmentID,
                        'tID'  => $tID
                    ]);

                    $fileName = $deleteAttachment->fetchColumn();

                    if ($fileName) {
                        $deleteAttachmentDB->execute([
                        'attID' => $attachmentID,
                        'tID'  => $tID
                    ]);

                    $filePath = __DIR__ . '/uploads/' . basename($fileName);

                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                    }
                }
            }

            // Handle Attachment Upload for Edit
            uploadImages($pdo, $tID, $creator_eID);

            $pdo->commit();
            $_SESSION['success_message'] = 'Task Updated Successfully';
            header("Location: view_task.php?tID=" . $tID . $backSuffix);
            exit;
        } else {
            // Get 'NEW' status ID
            $stmtStatus = $pdo->prepare("SELECT sID FROM status WHERE sName = 'NEW' LIMIT 1");
            $stmtStatus->execute();
            $status_id = $stmtStatus->fetchColumn() ?: 1;

            // Generate unique Task Number
            do {
                $tNo = 'TSK-' . date('Ymd') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
                $checkTaskNo = $pdo->prepare("SELECT 1 FROM task WHERE tNo = :tNo LIMIT 1");
                $checkTaskNo->execute(['tNo' => $tNo]);
            } while ($checkTaskNo->fetchColumn());     

            // Insert Task
            $stmtTask = $pdo->prepare("
                INSERT INTO task (tNo, lID, urgID, tcID, created_by_eID, current_sID, request_date, tTitle, tDesc, created_at)
                VALUES (:tNo, :lID, :urgID, :tcID, :created_by_eID, :current_sID, :request_date, :tTitle, :tDesc, NOW())
            ");
            $stmtTask->execute([
                'tNo'            => $tNo,
                'lID'            => $target_lID,
                'urgID'          => $urg_id,
                'tcID'           => $category_id,
                'created_by_eID' => $creator_eID,
                'current_sID'    => $status_id,
                'request_date'   => $request_date,
                'tTitle'         => $title,
                'tDesc'          => $desc
            ]);
            $tID = (int)$pdo->lastInsertId();

            // Fetch Creator's Party ID
            $stmtParty = $pdo->prepare("SELECT pID FROM party WHERE eID = :eID LIMIT 1");
            $stmtParty->execute(['eID' => $creator_eID]);
            $requestor_pID = $stmtParty->fetchColumn();

            // Link Requestor & Target Party
            $stmtTP = $pdo->prepare("INSERT INTO task_party (tID, pID, role) VALUES (:tID, :pID, :role)");
            if ($requestor_pID) {
                $stmtTP->execute(['tID' => $tID, 'pID' => $requestor_pID, 'role' => 'REQUESTOR']);
            }
            if ($target_pID) {
                $stmtTP->execute(['tID' => $tID, 'pID' => $target_pID, 'role' => 'TARGET']);
            }

            // Log Create Action in task_action
            $stmtAction = $pdo->prepare("
                INSERT INTO task_action (tID, action_by_eID, sID, tactType, tactRemark, created_at)
                VALUES (:tID, :eID, :sID, 'CREATE', NULL, NOW())
            ");
            $stmtAction->execute([
                'tID' => $tID,
                'eID' => $creator_eID,
                'sID' => $status_id
            ]);

            // Handle File Uploads for Create
            $upload = uploadImages($pdo, $tID, $creator_eID);
            if($upload == 0) {
                throw new Exception ('Upload at least one file.');
            }

            $pdo->commit();
            $_SESSION['success_message'] = 'Task Created Successfully';
            header("Location: index.php" . ($back !== '' ? ('&' . $back) : ''));
            exit;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error saving task: " . $e->getMessage();
    }
}

// Fetch Dropdown Data
$organizations = $pdo->query("SELECT oID, oName FROM organization ORDER BY oName")->fetchAll();
$locations     = $pdo->query("SELECT lID, oID, lName FROM location ORDER BY lName")->fetchAll();
$urgencies     = $pdo->query("SELECT urgID, urgName FROM urgency")->fetchAll();
$categories    = $pdo->query("SELECT tcID, tcName FROM task_category ORDER BY tcName")->fetchAll();
$departments = $pdo->query("SELECT deptID, lID, deptName FROM department ORDER BY deptName")->fetchAll();
$employees = $pdo->query("SELECT e.eID, e.eName, e.deptID, d.lID, p.pID
    FROM employee e
    INNER JOIN department d ON e.deptID = d.deptID
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
    <title><?= $isEdit ? 'Edit Task' : 'Create Task' ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f9; }
        .header { display: flex; justify-content: center;  text-align: center; }
        .card { background: #fff; padding: 25px; border-radius: 6px; max-width: 700px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type="text"], input[type="date"], select, textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 8px 16px; font-size: 14px; font-weight: 500; color: #fff; background: #2ba3b6; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; box-sizing: border-box; height: 38px; line-height: 1; }
        .btn-secondary { background: #6c757d; }
        .error { color: red; margin-bottom: 15px; }
        .required-asterisk { color: red; }
        .is-invalid { border-color: #dc3545 !important; background-color: #fff8f8; }
        .target-row {display: grid; grid-template-columns: repeat(4, 1fr); gap: 25px; width: 100%; }
        .target-field {  min-width: 0; }
        .target-field label { font-weight: bold; margin-bottom: 8px; }
        .target-field select {width: 100%; }
        .existing-attachments { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; margin-top: 10px; }
        .attachment-preview { width: 100%; height: 100%; object-fit: contain; display: block; }
        .attachment-box { position: relative; width: 150px; height: 120px; border: 1px solid #ccc; border-radius: 5px; background: #f8f9fa; overflow: hidden; }
        .remove-attachment { position: absolute; top: 5px; right: 5px; z-index: 10; 
            width: 24px; height: 24px;
            border: none; border-radius: 50%;
            background: #dc3545; color: white;
            font-size: 18px; font-weight: bold; line-height: 22px;
            padding: 0; cursor: pointer;
            display: flex; align-items: center; justify-content: center; 
        }
        .remove-attachment:hover { background: #b02a37; }
        @media (max-width: 900px) { .target-row { grid-template-columns: repeat(2, 1fr); gap: 15px; } }
        @media (max-width: 600px) { .target-row { grid-template-columns: 1fr; gap: 15px; }
}
    </style>
</head>

<body>
<div class="header">
    <h2><?= $isEdit ? 'Edit Task (' . htmlspecialchars($task['tNo']) . ')' : 'Create Task' ?></h2>
</div>
<div class="card">
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form id="createTaskForm" method="POST" enctype="multipart/form-data" novalidate>
        <div class="form-group">
            <label>Requestor:</label>
            <div class="target-row">
                <div class="target-field">
                    <label>Organization:<span class="required-asterisk">*</span></label>
                    <select id="reqOrganizationSelect" required>
                        <option value=""></option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= (int)$org['oID'] ?>" <?= ($isEdit && isset($task['req_oID']) && $task['req_oID'] == $org['oID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['oName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="target-field">
                    <label>Location:<span class="required-asterisk">*</span></label>
                    <select id="reqLocationSelect" required <?= (!$isEdit || empty($task['req_oID'])) ? 'disabled' : '' ?>>
                        <option value=""></option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['lID'] ?>" data-org-id="<?= (int)$loc['oID'] ?>" <?= ($isEdit && isset($task['req_lID']) && $task['req_lID'] == $loc['lID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['lName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="target-field">
                    <label>Department:<span class="required-asterisk">*</span></label>
                    <select id="reqDepartmentSelect" required <?= (!$isEdit || empty($task['req_lID'])) ? 'disabled' : '' ?>>
                        <option value=""></option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= (int)$dept['deptID'] ?>" data-location-id="<?= (int)$dept['lID'] ?>" <?= ($isEdit && isset($task['req_deptID']) && $task['req_deptID'] == $dept['deptID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['deptName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="target-field">
                    <label>Employee:<span class="required-asterisk">*</span></label>
                    <select name="created_by_eID" id="reqEmployeeSelect" required <?= (!$isEdit || empty($task['req_deptID'])) ? 'disabled' : '' ?>>
                        <option value=""></option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int)$emp['eID'] ?>" data-dept-id="<?= (int)$emp['deptID'] ?>" <?= ($isEdit && isset($task['created_by_eID']) && $task['created_by_eID'] == $emp['eID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['eName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Category:<span class="required-asterisk">*</span></label>
            <select name="tcID" required>
                <option value=""></option>
                <?php foreach ($categories as $cat): ?>   
                <option
                    value="<?= $cat['tcID'] ?>" <?= ($isEdit && $task['tcID'] == $cat['tcID']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['tcName']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Urgency:<span class="required-asterisk">*</span></label>
            <select name="urgID" required>
                <?php foreach ($urgencies as $urg): ?>
                    <option value="<?= $urg['urgID'] ?>" <?= ($isEdit && $task['urgID'] == $urg['urgID']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($urg['urgName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Request Date:<span class="required-asterisk">*</span></label>
            <input type="date" name="request_date" value="<?= $isEdit ? htmlspecialchars($task['request_date']) : date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label>Target:</label>

            <div class="target-row">
                <div class="target-field">
                <label>Organization:<span class="required-asterisk">*</span></label>
                <select name="target_oID" id="targetOrganizationSelect" required>
                <option value=""></option>
                <?php foreach ($organizations as $org): ?>
                    <option value="<?= (int)$org['oID'] ?>" <?= ($isEdit && isset($task['target_oID']) && $task['target_oID'] == $org['oID']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($org['oName']) ?>
                    </option>
                <?php endforeach; ?>
                </select>
                </div>

                <div class="target-field">
                    <label>Location:<span class="required-asterisk">*</span></label>
                    <select name="target_lID" id="targetLocationSelect" required
                        <?= (!$isEdit || empty($task['target_oID'])) ? 'disabled' : '' ?>>
                    <option value=""></option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= (int)$loc['lID'] ?>"
                            data-org-id="<?= (int)$loc['oID'] ?>"
                            <?= ($isEdit && isset($task['target_lID']) && $task['target_lID'] == $loc['lID']) ? 'selected' : '' ?>
                        >
                        <?= htmlspecialchars($loc['lName']) ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </div>

                <div class="target-field">
                <label>Department:<span class="required-asterisk">*</span></label>
                <select name="target_deptID" id="targetDepartmentSelect" required <?= (!$isEdit || empty($task['target_lID'])) ? 'disabled' : '' ?>>
                <option value=""></option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int)$dept['deptID'] ?>"
                        data-location-id="<?= (int)$dept['lID'] ?>"
                        <?= ($isEdit && isset($task['target_deptID']) && $task['target_deptID'] == $dept['deptID']) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($dept['deptName']) ?>
                    </option>
                <?php endforeach; ?>
                </select>
                </div>

                <div class="target-field">
                <label>Employee:<span class="required-asterisk">*</span></label>
                <select name="target_pID" id="targetEmployeeSelect" required <?= (!$isEdit || empty(!$task['target_deptID'])) ? 'disabled' : '' ?>>
                <option value=""></option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int)$emp['pID'] ?>"
                        data-dept-id="<?= (int)$emp['deptID'] ?>"
                        <?= ($isEdit && isset($task['target_pID']) && $task['target_pID'] == $emp['pID']) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($emp['eName']) ?>
                    </option>
                <?php endforeach; ?>
                </select>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Issue Title:<span class="required-asterisk">*</span></label>
            <input type="text" name="tTitle" value="<?= $isEdit ? htmlspecialchars($task['tTitle']) : '' ?>" required>
        </div>

        <div class="form-group">
            <label>Issue Description:<span class="required-asterisk">*</span></label>
            <textarea name="tDesc" rows="4" required><?= $isEdit ? htmlspecialchars($task['tDesc']) : '' ?></textarea>
        </div>

        <div class="form-group">
            <label>Upload Attachment:<?= $isEdit ? '' : '<span class="required-asterisk">*</span>' ?></label>

            <?php if ($isEdit && !empty($existingAttachments)): ?>
                <div class="existing-attachments">
                <?php foreach ($existingAttachments as $attachment): ?>
                    <?php
                        $fileName = $attachment['file_name'];
                        $filePath = 'uploads/' . $fileName;
                        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    ?>

                    <?php if (in_array($extension, $imageExtensions)): ?>
                        <div class="attachment-box"
                            data-attachment-id="<?= (int)$attachment['attID'] ?>">
                            <button type="button" class="remove-attachment" title="Remove attachment" onclick="removeAttachment(this)">
                                &times;
                            </button>
                            <img src="<?= htmlspecialchars($filePath) ?>" alt="Attachment" class="attachment-preview">
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="deletedAttachments"></div>
            <input type="file" name="attachments[]" id="attachments" accept="image/jpeg,image/png,image/gif,image/webp" multiple<?= $isEdit ? '' : 'required' ?>>
            <div id="fileList" class="existing-attachments"></div>
            <small style="display:block; margin-top:5px; color:#666;">Allowed file: .jpg, .jpeg, .png, .gif, .webp. Maximum 5 MB.</small>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; margin-top: 20px;">
            <button type="submit" class="btn"><?= $isEdit ? 'Update' : 'Create' ?></button>
            <a href="<?= $isEdit ? ('view_task.php?tID=' . $tID . $backSuffix) : ('index.php' . ($back !== '' ? ('?' . $back) : '')) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
// when choose more than 1 file, keep previous file
const attachmentInput = document.querySelector('input[name="attachments[]"]'); // show the file input on the page and stores it in attachmentInput, so that can read and remove it.
const fileListDiv = document.getElementById('fileList'); // placeholder for <div id="fileList"></div>
let selectedFiles = [];

attachmentInput.addEventListener('change', () => { // Tell browser to listen for change event on the file input, which happens when user done picking the files. When that happens, it triggers () =>, which contains the logic to validate the files and update the list
    // add newly picked files to the list
    for (let i = 0; i < attachmentInput.files.length; i++) {
    if (selectedFiles.length >= 3) {
        alert('Upload 3 files only');
        break;
    }    
    const newFile = attachmentInput.files[i];
        let alreadyAdded = false;

        // skip if same file was already picked
        for (let j = 0; j < selectedFiles.length; j++) {
            if (selectedFiles[j].name === newFile.name && selectedFiles[j].size === newFile.size) {
                alreadyAdded = true;
            }
        }

        if (!alreadyAdded) {
            selectedFiles.push(newFile);
        }
    }

    updateFileInput();
    showFileList();
});

// Put the kept files back into the file input so they are submitted
function updateFileInput() {
    const dt = new DataTransfer();

    for (let i = 0; i < selectedFiles.length; i++) {
        dt.items.add(selectedFiles[i]); // Put every files into the array
    }

    attachmentInput.files = dt.files;
    // Hands the full array to the real file input. Without it, the form would only send the last files picked, because the input would still hold just those.
}

// Show the selected file with a remove button
function showFileList() {
    fileListDiv.innerHTML = ''; // build a box to add new item

    for (let i = 0; i < selectedFiles.length; i++) {
        const box = document.createElement('box'); // create box for image
        box.className = 'attachment-box';

        const img = document.createElement('img'); // create frame for image
        img.className = 'attachment-preview';
        img.src = URL.createObjectURL(selectedFiles[i]); // show image

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-attachment';
        removeBtn.innerHTML = '&times';
        removeBtn.addEventListener('click', () => {
            selectedFiles.splice(i, 1); // Removes file from list. splice(i, 1) means "at position i, remove 1 item"
            updateFileInput();
            showFileList();
        });
        
        box.appendChild(removeBtn); // add remove button inside box
        box.appendChild(img);
        fileListDiv.appendChild(box);
    }
}

document.getElementById('createTaskForm').addEventListener('submit', function(e) {
    const requiredInputs = this.querySelectorAll('[required]');
    let hasError = false;

    // Reset red highlights
    requiredInputs.forEach(input => input.classList.remove('is-invalid'));

    // Check each required input
    requiredInputs.forEach(input => {
        if (input.type === 'file') {
            if (!input.files || input.files.length === 0) {
                // If editing and attachment already exists, file input is optional
                if (!<?= $isEdit ? 'true' : 'false' ?>) {
                    input.classList.add('is-invalid');
                    hasError = true;
                }
            }
        } else if (!input.value.trim()) {
            input.classList.add('is-invalid');
            hasError = true;
        }
    });

    // Client-side image validation
    const fileInput = document.querySelector('input[name="attachments[]"]'); // Finds file input on the page so can read what the user picked
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const maxSize = 5 * 1024 * 1024;

    if (fileInput && fileInput.files.length > 0) { // check if input exists and picked at least one file. files[0]
        for (let i = 0; i < fileInput.files.length; i++) { // files[1], files[2],...
            const file = fileInput.files[i];

            if (!allowedTypes.includes(file.type)) {
                input.classList.add('is-invalid'); // adds CSS class named is-invalid to the input. it gives the input a red border and a light red background
                hasError = true;
                alert(file.name + ' is not allowed. Only JPG, PNG, GIF, and WebP image files are allowed.');
                break;
            }

            if (file.size > maxSize) {
                fileInput.classList.add('is-invalid');
                hasError = true;
                alert(file.name + ' exceeds 5 MB.');
                break;
            }
        }
    }

    if (hasError) {
        e.preventDefault();
        /*e.preventDefault() cancels browser’s default action for that event. For a form submit, the default action is to send the form to the server. 
        When there’s an error, call preventDefault() so the form is not sent. User stays on the page, sees the red fields. Without it, the form would go to the server even when the data is wrong*/
        if (fileInput.files.length === 0) {
            alert('All required fields must be filled out.');
        }
    }
});

// Remove red highlight dynamically when input changes
document.querySelectorAll('#createTaskForm [required]').forEach(input => {
    input.addEventListener('input', function() {
        if (this.value.trim()) {
            this.classList.remove('is-invalid');
        }
    });
    input.addEventListener('change', function() {
        if (this.value.trim()) {
            this.classList.remove('is-invalid');
        }
    });
});

// Helper for setup of cascade listener
function setupCascade(orgSel, locSel, deptSel, empSel) {
    function filterLocs() {                                // org -> loc
        const selectedOrgId = orgSel.value;
        if (!selectedOrgId) {
            locSel.disabled = true; locSel.value = '';
            deptSel.disabled = true; deptSel.value = '';
            empSel.disabled = true; empSel.value = '';
            return;
        }
        locSel.disabled = false;
        Array.from(locSel.options).forEach(opt => {
            if (!opt.value) return;
            opt.hidden = (opt.dataset.orgId !== selectedOrgId);
        });
    }

    function filterDepts() {                               // loc -> dept
        const selectedLocId = locSel.value;
        if (!selectedLocId) {
            deptSel.disabled = true; deptSel.value = '';
            empSel.disabled = true; empSel.value = '';
            return;
        }
        deptSel.disabled = false;
        Array.from(deptSel.options).forEach(opt => {
            if (!opt.value) return;
            opt.hidden = (opt.dataset.locationId !== selectedLocId);
        });
    }

    function filterEmps() {                              // dept -> emp
        const selectedDeptId = deptSel.value;
        if (!selectedDeptId) {
            empSel.disabled = true; empSel.value = '';
            return;
        }
        empSel.disabled = false;
        Array.from(empSel.options).forEach(opt => {
            if (!opt.value) return;
            opt.hidden = (opt.dataset.deptId !== selectedDeptId);
        });
    }

    orgSel.addEventListener('change', function () {
        locSel.value = ''; deptSel.value = ''; empSel.value = '';
        deptSel.disabled = true; empSel.disabled = true;
        filterLocs();
    });

    locSel.addEventListener('change', function () {
        deptSel.value = ''; empSel.value = '';
        empSel.disabled = true;
        filterDepts();
    });

    deptSel.addEventListener('change', function () {
        empSel.value = '';
        filterEmps();
    });

    // Run on init for edit mode
    if (orgSel.value) filterLocs();
    if (locSel.value) filterDepts();
    if (deptSel.value) filterEmps();
}

// Requestor Cascade
setupCascade(
    document.getElementById('reqOrganizationSelect'),
    document.getElementById('reqLocationSelect'),
    document.getElementById('reqDepartmentSelect'),
    document.getElementById('reqEmployeeSelect')
);

// Target Cascade
setupCascade(
    document.getElementById('targetOrganizationSelect'),
    document.getElementById('targetLocationSelect'),
    document.getElementById('targetDepartmentSelect'),
    document.getElementById('targetEmployeeSelect')
);

function removeAttachment(button) {
    const attachmentBox = button.closest('.attachment-box');
    const attachmentId = attachmentBox.dataset.attachmentId;

    if (!attachmentId) {
        return;
    }

    // Hide/remove from screen
    attachmentBox.remove();

    // Add attachment ID to hidden fields
    const hiddenInput = document.createElement('input');

    hiddenInput.type = 'hidden';
    hiddenInput.name = 'delete_attachments[]';
    hiddenInput.value = attachmentId;

    document.getElementById('deletedAttachments').appendChild(hiddenInput);
}
</script>
</body>
</html>
