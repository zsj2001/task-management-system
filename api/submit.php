<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

// JSON response helper
function response(bool $success, string $message, array $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(
        false,
        'Only POST requests are allowed.'
    );
}

// Read input
$urgID = isset($_POST['urgID'])? (int)$_POST['urgID'] : 0;
$requestDate = trim($_POST['request_date'] ?? '');
$title = trim($_POST['tTitle'] ?? '');
$description = trim($_POST['tDesc'] ?? '');
$creatorEID = isset($_POST['created_by_eID']) ? (int)$_POST['created_by_eID'] : 0;
$targetPID = isset($_POST['target_pID']) ? (int)$_POST['target_pID'] : 0;
$targetLID = isset($_POST['target_lID']) ? (int)$_POST['target_lID'] : 0;
$targetDeptID = isset($_POST['target_deptID']) ? (int)$_POST['target_deptID'] : 0;
$categoryID = !empty($_POST['tcID']) ? (int)$_POST['tcID'] : null;

// Validate required fields
$errors = [];

if ($urgID <= 0) {
    $errors[] = 'urgID is required.';
}

if ($requestDate === '') {
    $errors[] = 'request_date is required.';
}

if ($title === '') {
    $errors[] = 'tTitle is required.';
}

if ($description === '') {
    $errors[] = 'tDesc is required.';
}

if ($creatorEID <= 0) {
    $errors[] = 'created_by_eID is required.';
}

if ($targetPID <= 0) {
    $errors[] = 'target_pID is required.';
}

if ($targetLID <= 0) {
    $errors[] = 'target_lID is required.';
}

if ($targetDeptID <= 0) {
    $errors[] = 'target_deptID is required.';
}

if (!empty($errors)) {
    response(
        false,
        'Validation failed.',
        ['errors' => $errors]
    );
}

//Validate request date
$date = DateTime::createFromFormat('Y-m-d', $requestDate);
if (!$date || $date->format('Y-m-d') !== $requestDate) {
    response(
        false,
        'Invalid request date. Expected YYYY-MM-DD.'
    );
}

//Validate requestor
$stmt = $pdo->prepare("
    SELECT e.eID, e.eName, e.deptID, d.lID, p.pID
    FROM employee e
    INNER JOIN department d
        ON e.deptID = d.deptID
    INNER JOIN party p
        ON p.eID = e.eID
       AND p.pType = 'EMPLOYEE'
    WHERE e.eID = :eID
      AND e.is_active = 1
    LIMIT 1
");
$stmt->execute(['eID' => $creatorEID]);
$requestor = $stmt->fetch();
if (!$requestor) {
    response(
        false,
        'Invalid or inactive requestor employee.'
    );
}

/*
 Validate target employee
 target_pID -> employee -> department -> location
*/
$stmt = $pdo->prepare("
    SELECT p.pID, e.eID, e.eName, e.deptID, d.lID, l.oID
    FROM party p
    INNER JOIN employee e
        ON p.eID = e.eID
    INNER JOIN department d
        ON e.deptID = d.deptID
    INNER JOIN location l
        ON d.lID = l.lID
    WHERE p.pID = :pID
      AND p.pType = 'EMPLOYEE'
      AND e.deptID = :deptID
      AND d.lID = :lID
      AND e.is_active = 1
    LIMIT 1
");
$stmt->execute([
    'pID'    => $targetPID,
    'deptID' => $targetDeptID,
    'lID'    => $targetLID
]);
$target = $stmt->fetch();
if (!$target) {
    response(
        false,
        'Invalid target employee, department, or location.'
    );
}

// Validate urgency
$stmt = $pdo->prepare("
    SELECT urgID
    FROM urgency
    WHERE urgID = :urgID
    LIMIT 1
");
$stmt->execute(['urgID' => $urgID]);
if (!$stmt->fetchColumn()) {
    response(
        false,
        'Invalid urgency.'
    );
}

// Validate category
if ($categoryID !== null) {
    $stmt = $pdo->prepare("
        SELECT tcID
        FROM task_category
        WHERE tcID = :tcID
        LIMIT 1
    ");
    $stmt->execute(['tcID' => $categoryID]);
    if (!$stmt->fetchColumn()) {
        response(
            false,
            'Invalid task category.'
        );
    }
}

//Validate attachment
$uploadedFile = null;
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadedFile = $_FILES['attachment'];
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        response(
            false,
            'Attachment upload failed.'
        );
    }

    // Maximum 5 MB
    if ($uploadedFile['size'] > 5 * 1024 * 1024) {
        response(
            false,
            'Attachment cannot exceed 5 MB.'
        );
    }

    // Detect actual MIME type
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($uploadedFile['tmp_name']);
    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        response(
            false,
            'Only JPG, PNG, GIF, and WebP images are allowed.'
        );
    }

    //Verify actual image
    if (getimagesize($uploadedFile['tmp_name']) === false) {
        response(
            false,
            'Uploaded file is not a valid image.'
        );
    }
}

// create task
$savedFileName = null;
try {
    $pdo->beginTransaction();
    // Get NEW status
    $stmt = $pdo->prepare("
        SELECT sID
        FROM status
        WHERE sName = 'NEW'
        LIMIT 1
    ");
    $stmt->execute();
    $statusID = $stmt->fetchColumn();
    if (!$statusID) {
        throw new RuntimeException('NEW status does not exist.');
    }
    $statusID = (int)$statusID;

    // Generate unique task number
    do {
        $taskNumber = 'TSK-' . date('Ymd') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("
            SELECT 1
            FROM task
            WHERE tNo = :tNo
            LIMIT 1
        ");
        $stmt->execute([ 'tNo' => $taskNumber]);
    } while ($stmt->fetchColumn());

    //Insert task
    $stmt = $pdo->prepare("
        INSERT INTO task (tNo, lID, urgID, tcID, created_by_eID, current_sID, request_date, tTitle, tDesc, created_at)
        VALUES (:tNo, :lID, :urgID, :tcID, :created_by_eID, :current_sID, :request_date, :tTitle, :tDesc, NOW())
    ");
    $stmt->execute([
        'tNo'            => $taskNumber,
        'lID'            => $targetLID,
        'urgID'          => $urgID,
        'tcID'           => $categoryID,
        'created_by_eID' => $creatorEID,
        'current_sID'    => $statusID,
        'request_date'   => $requestDate,
        'tTitle'         => $title,
        'tDesc'          => $description
    ]);
    $taskID = (int)$pdo->lastInsertId();

    // Insert REQUESTOR
    $stmt = $pdo->prepare("
        INSERT INTO task_party (tID,  pID, role)
        VALUES (:tID, :pID, 'REQUESTOR')
    ");
    $stmt->execute([
        'tID' => $taskID,
        'pID' => (int)$requestor['pID']
    ]);

    // Insert TARGET
    $stmt = $pdo->prepare("
        INSERT INTO task_party (tID, pID,  role)
        VALUES (:tID, :pID, 'TARGET')
    ");
    $stmt->execute([
        'tID' => $taskID,
        'pID' => $targetPID
    ]);

    // Create task action
    $stmt = $pdo->prepare("
        INSERT INTO task_action (tID, action_by_eID, sID, tactType, tactRemark, created_at)
        VALUES (:tID, :eID, :sID, 'CREATE', NULL, NOW())
    ");
    $stmt->execute([
        'tID' => $taskID,
        'eID' => $creatorEID,
        'sID' => $statusID
    ]);

    // Save attachment
    if ($uploadedFile !== null) {
        $uploadDir = dirname(__DIR__) . '/uploads/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new RuntimeException('Unable to create upload directory.');
            }
        }

        // Use server-generated filename.
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        ];
        $extension = $extensionMap[$mimeType];
        $savedFileName = bin2hex(random_bytes(4)) . '.' . $extension;
        $filePath = $uploadDir . $savedFileName;

        // Move uploaded file
        if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
            throw new RuntimeException('Failed to save attachment.');
        }

        //Insert attachment record
        $stmt = $pdo->prepare("
            INSERT INTO task_attachment (tID, uploaded_by_eID, file_name, uploaded_at)
            VALUES (:tID, :eID, :file_name, NOW())
        ");
        $stmt->execute([
            'tID'       => $taskID,
            'eID'       => $creatorEID,
            'file_name' => $savedFileName
        ]);
    }
    $pdo->commit();
    //Success response
    response(
        true,
        'Task created successfully.',
        [
            'tID' => $taskID,
            'tNo' => $taskNumber,
            'status' => 'NEW',
            'attachment' => $savedFileName
        ]
    );
} catch (Throwable $e) {
    // Rollback database
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Remove uploaded file if database failed
    if ($savedFileName !== null) {
        $filePath = dirname(__DIR__) . '/uploads/' . basename($savedFileName);

        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    // Error response
    response(
        false,
        'Failed to create task.',
        ['error' => $e->getMessage()]
    );
}
