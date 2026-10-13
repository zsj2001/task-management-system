<?php
require_once 'db.php';
require_once 'admin_auth.php';
include_once 'nav_header.php';

$message = '';
$error = '';
$editEmp = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $eID      = (int)($_POST['eID'] ?? 0);
        $deptID   = (int)($_POST['deptID'] ?? 0);
        $eName    = trim($_POST['eName'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($eName !== '' && $deptID > 0) {
            try {
                $pdo->beginTransaction();
                if ($eID > 0) {
                    // (edit) Check if another employee already has the same name
                    $stmtCheck = $pdo->prepare("SELECT eID FROM employee WHERE eName = :eName AND deptID = :deptID AND eID != :eID");
                    $stmtCheck->execute(['eName' => $eName, 'deptID' => $deptID, 'eID' => $eID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This employee already exist in the selected department.';
                    } else {
                        // Update employee
                        $stmt = $pdo->prepare("UPDATE employee SET deptID = :deptID, eName = :eName, is_active = :is_active WHERE eID = :eID");
                        $stmt->execute(['deptID' => $deptID, 'eName' => $eName, 'is_active' => $isActive, 'eID' => $eID]);

                        // Update party display name
                        $stmtParty = $pdo->prepare("UPDATE party SET display_name = :eName WHERE eID = :eID AND pType = 'EMPLOYEE'");
                        $stmtParty->execute(['eName' => $eName, 'eID' => $eID]);

                        $message = 'Employee updated successfully.';
                    }
                } else {
                    // (add) Check if employee already exists
                    $stmtCheck = $pdo->prepare("SELECT eID FROM employee WHERE eName = :eName AND deptID = :deptID");
                    $stmtCheck->execute(['eName' => $eName, 'deptID' => $deptID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This employee already exist.';
                    } else {
                        // Create employee
                        $stmt = $pdo->prepare("INSERT INTO employee (deptID, eName, is_active) VALUES (:deptID, :eName, :is_active)");
                        $stmt->execute(['deptID' => $deptID, 'eName' => $eName, 'is_active' => $isActive]);
                        $newEID = (int)$pdo->lastInsertId();

                        // Create party link for target assignees
                        $stmtParty = $pdo->prepare("INSERT INTO party (pType, eID, display_name) VALUES ('EMPLOYEE', :eID, :display_name)");
                        $stmtParty->execute(['eID' => $newEID, 'display_name' => $eName]);

                        $message = 'Employee created successfully.';
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = ($e->getCode() == 23000 ? 'Employee already exist.' : $e->getMessage());
            }
        } else {
            $error = 'Please fill in all required fields.';
        }
    } elseif ($action === 'toggle_status') {
        $eID = (int)($_POST['eID'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        $stmt = $pdo->prepare("UPDATE employee SET is_active = :status WHERE eID = :eID");
        $stmt->execute(['status' => $status, 'eID' => $eID]);
        $message = 'Employee status updated.';
    }
}

if (isset($_GET['edit'])) {
    $editEID = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM employee WHERE eID = :eID");
    $stmt->execute(['eID' => $editEID]);
    $editEmp = $stmt->fetch();
}

$departments = $pdo->query("
    SELECT d.deptID, d.deptName, l.lName, o.oName 
    FROM department d 
    INNER JOIN location l ON d.lID = l.lID 
    INNER JOIN organization o ON l.oID = o.oID 
    ORDER BY o.oName, l.lName, d.deptName
")->fetchAll();

$employees = $pdo->query("
    SELECT e.eID, e.eName, e.is_active, d.deptName, l.lName, o.oName 
    FROM employee e 
    INNER JOIN department d ON e.deptID = d.deptID 
    INNER JOIN location l ON d.lID = l.lID 
    INNER JOIN organization o ON l.oID = o.oID 
    ORDER BY e.eName ASC
")->fetchAll();
?>

<title>Manage Employees</title>

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
        <h3><?= $editEmp ? 'Edit Employee' : 'Add New Employee' ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="eID" value="<?= $editEmp['eID'] ?? 0 ?>">

            <div class="form-group">
                <label>Department (Org & Location) <span class="required-asterisk">*</span></label>
                <select name="deptID" required>
                    <option value=""></option>
                    <?php foreach ($departments as $dept): ?>
                        <?php
                            $deptLabel = $dept['oName'] . ' (' . $dept['lName'] . ') - ' . $dept['deptName'];
                            $selected = isset($editEmp['deptID']) && $editEmp['deptID'] == $dept['deptID'];
                        ?>
                        <option value="<?= htmlspecialchars($dept['deptID']) ?>"<?= $selected ? ' selected' : '' ?>><?= htmlspecialchars($deptLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Employee Full Name <span class="required-asterisk">*</span></label>
                <input type="text" name="eName" value="<?= htmlspecialchars($editEmp['eName'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label style="font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1" <?= (!isset($editEmp) || $editEmp['is_active'] == 1) ? 'checked' : '' ?>>
                    Active Employee
                </label>
            </div>

            <button type="submit" class="btn"><?= $editEmp ? 'Update' : 'Save' ?></button>
            <?php if ($editEmp): ?>
                <a href="manage_employees.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Employees List</h3>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th style="width: 140px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="4" style="text-align:center;">No employees found.</td></tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($emp['eName']) ?></strong></td>
                            <td><?= htmlspecialchars($emp['oName'] . ' - ' . $emp['deptName']) ?></td>
                            <td>
                                <span class="badge <?= $emp['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $emp['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <a href="manage_employees.php?edit=<?= $emp['eID'] ?>" class="btn btn-sm">Edit</a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="eID" value="<?= $emp['eID'] ?>">
                                    <input type="hidden" name="status" value="<?= $emp['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= $emp['is_active'] ? 'btn-secondary' : 'btn' ?>">
                                        <?= $emp['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
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