<?php
require_once 'db.php';
require_once 'admin_auth.php';
include_once 'nav_header.php';

$message = '';
$error = '';
$editDept = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $deptID   = (int)($_POST['deptID'] ?? 0);
        $lID      = (int)($_POST['lID'] ?? 0);
        $deptName = trim($_POST['deptName'] ?? '');

        if ($deptName !== '' && $lID > 0) {
            try {
                $pdo->beginTransaction();
                if ($deptID > 0) {
                    // (edit) Check if another department already has the same name
                    $stmtCheck = $pdo->prepare("SELECT deptID FROM department WHERE deptName = :deptName AND lID = :lID AND deptID != :deptID");
                    $stmtCheck->execute(['deptName' => $deptName, 'lID' => $lID, 'deptID' => $deptID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This department already exist at this location.';
                    } else {
                        // Update department
                        $stmt = $pdo->prepare("UPDATE department SET lID = :lID, deptName = :deptName WHERE deptID = :deptID");
                        $stmt->execute(['lID' => $lID, 'deptName' => $deptName, 'deptID' => $deptID]);

                        // Update linked party record
                        $stmtParty = $pdo->prepare("UPDATE party SET display_name = :dispName WHERE deptID = :deptID AND pType = 'DEPARTMENT'");
                        $stmtParty->execute(['dispName' => $deptName . ' Department', 'deptID' => $deptID]);

                        $message = 'Department updated successfully.';
                    }
                } else {
                    // (add) Check if department already exists at this location
                    $stmtCheck = $pdo->prepare("SELECT deptID FROM department WHERE deptName = :deptName AND lID = :lID");
                    $stmtCheck->execute(['deptName' => $deptName, 'lID' => $lID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This department already exist at this location.';
                    } else {
                        // Create department
                        $stmt = $pdo->prepare("INSERT INTO department (lID, deptName) VALUES (:lID, :deptName)");
                        $stmt->execute(['lID' => $lID, 'deptName' => $deptName]);
                        $newDeptID = (int)$pdo->lastInsertId();

                        // Create linked party record
                        $stmtParty = $pdo->prepare("INSERT INTO party (pType, deptID, display_name) VALUES ('DEPARTMENT', :deptID, :dispName)");
                        $stmtParty->execute(['deptID' => $newDeptID, 'dispName' => $deptName . ' Department']);

                        $message = 'Department created successfully.';
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = ($e->getCode() == 23000 ? 'Department already exist at this Location.' : $e->getMessage());
            }
        } else {
            $error = 'Please fill in all required fields.';
        }
    } elseif ($action === 'delete') {
        $deptID = (int)($_POST['deptID'] ?? 0);
        try {
            $pdo->beginTransaction();
            // Delete linked party link
            $pdo->prepare("DELETE FROM party WHERE deptID = :deptID AND pType = 'DEPARTMENT'")->execute(['deptID' => $deptID]);
            // Delete department
            $pdo->prepare("DELETE FROM department WHERE deptID = :deptID")->execute(['deptID' => $deptID]);
            $pdo->commit();
            $message = 'Department deleted successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Cannot delete department. It is linked to employees or other records.';
        }
    }
}

if (isset($_GET['edit'])) {
    $editDeptID = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM department WHERE deptID = :deptID");
    $stmt->execute(['deptID' => $editDeptID]);
    $editDept = $stmt->fetch();
}

$locations = $pdo->query("
    SELECT l.lID, l.lName, o.oName 
    FROM location l 
    INNER JOIN organization o ON l.oID = o.oID 
    ORDER BY o.oName, l.lName
")->fetchAll();

$departments = $pdo->query("
    SELECT d.deptID, d.deptName, d.lID, l.lName, o.oName
    FROM department d
    INNER JOIN location l ON d.lID = l.lID
    INNER JOIN organization o ON l.oID = o.oID
    ORDER BY o.oName ASC, l.lName ASC, d.deptName ASC
")->fetchAll();
?>

<title>Manage Departments</title>

<?php if ($message): ?>
    <div id="success-message" class="alert alert-success">
        <?= htmlspecialchars($message) ?>
    </div>
    <script>
        setTimeout(function () {
            const message = document.getElementById('success-message');
            if (message) {
                message.remove();
            }
        }, 5000);
    </script>
<?php endif; ?>

<?php if ($error): ?>
    <div id="error-message" class="alert alert-danger">
        <?= htmlspecialchars($error) ?>
    </div>
    <script>
        setTimeout(function () {
            const message = document.getElementById('error-message');
            if (message) {
                    message.remove();
                }
        }, 5000);
    </script>
<?php endif; ?>

<div class="grid-layout">
    <div class="card">
        <h3><?= $editDept ? 'Edit Department' : 'Add New Department' ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="deptID" value="<?= $editDept['deptID'] ?? 0 ?>">

            <div class="form-group">
                <label>Organization - Location <span class="required-asterisk">*</span></label>
                <select name="lID" required>
                    <option value=""></option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['lID'] ?>" <?= (isset($editDept['lID']) && $editDept['lID'] == $loc['lID']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['oName'] . ' - ' . $loc['lName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Department <span class="required-asterisk">*</span></label>
                <input type="text" name="deptName" value="<?= htmlspecialchars($editDept['deptName'] ?? '') ?>" required>
            </div>

            <button type="submit" class="btn"><?= $editDept ? 'Update' : 'Save' ?></button>
            <?php if ($editDept): ?>
                <a href="manage_departments.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Departments List</h3>
        <table>
            <thead>
                <tr>
                    <th>Organization (Location)</th>
                    <th>Department</th>
                    <th style="width: 120px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($departments)): ?>
                    <tr><td colspan="3" style="text-align:center;">No departments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td><?= htmlspecialchars($dept['oName'] . ' (' . $dept['lName'] . ')') ?></td>
                            <td><strong><?= htmlspecialchars($dept['deptName']) ?></strong></td>
                            <td style="text-align: center;">
                                <a href="manage_departments.php?edit=<?= $dept['deptID'] ?>" class="btn btn-sm">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this department?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="deptID" value="<?= $dept['deptID'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>