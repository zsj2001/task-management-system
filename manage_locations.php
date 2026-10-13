<?php
require_once 'db.php';
require_once 'admin_auth.php';
include_once 'nav_header.php';

$message = '';
$error = '';
$editLoc = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $lID = (int)($_POST['lID'] ?? 0);
        $oID = (int)($_POST['oID'] ?? 0);
        $lName = trim($_POST['lName'] ?? '');

        if ($lName !== '' && $oID > 0) {
            try {
                if ($lID > 0) {
                    // (edit) Check if another location already has the same name
                    $stmtCheck = $pdo->prepare("SELECT lID FROM location WHERE lName = :lName AND oID = :oID AND lID != :lID");
                    $stmtCheck->execute(['lName' => $lName, 'lID' => $lID, 'oID' => $oID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This location already exist for this organization.';
                    } else {
                        // Update location
                        $stmt = $pdo->prepare("UPDATE location SET oID = :oID, lName = :lName WHERE lID = :lID");
                        $stmt->execute(['oID' => $oID, 'lName' => $lName, 'lID' => $lID]);
                        $message = 'Location updated successfully.';
                    }
                } else {
                    // (add) Check if location already exist under this organization
                    $stmtCheck = $pdo->prepare("SELECT lID FROM location WHERE lName = :lName AND oID = :oID");
                    $stmtCheck->execute(['lName' => $lName, 'oID' => $oID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This location already exist for this organization.';
                    } else {
                        // Create location
                        $stmt = $pdo->prepare("INSERT INTO location (oID, lName) VALUES (:oID, :lName)");
                        $stmt->execute(['oID' => $oID, 'lName' => $lName]);
                        $message = 'Location added successfully.';
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = ($e->getCode() == 23000 ? 'Location already exist for this Organization.' : $e->getMessage());
            }
        } else {
            $error = 'Please fill in all required fields.';
        }
    } elseif ($action === 'delete') {
        $lID = (int)($_POST['lID'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM location WHERE lID = :lID");
            $stmt->execute(['lID' => $lID]);
            $message = 'Location deleted successfully.';
        } catch (Exception $e) {
            $error = 'Cannot delete location. It is linked to existing departments or tasks.';
        }
    }
}

if (isset($_GET['edit'])) {
    $editLID = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM location WHERE lID = :lID");
    $stmt->execute(['lID' => $editLID]);
    $editLoc = $stmt->fetch();
}

$organizations = $pdo->query("SELECT oID, oName FROM organization ORDER BY oName ASC")->fetchAll();
$locations = $pdo->query("
    SELECT l.lID, l.lName, l.oID, o.oName 
    FROM location l 
    INNER JOIN organization o ON l.oID = o.oID 
    ORDER BY o.oName ASC, l.lName ASC
")->fetchAll();
?>

<title>Manage Locations</title>

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
        <h3><?= $editLoc ? 'Edit Location' : 'Add New Location' ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="lID" value="<?= $editLoc['lID'] ?? 0 ?>">

            <div class="form-group">
                <label>Organization <span class="required-asterisk">*</span></label>
                <select name="oID" required>
                    <option value=""></option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= $org['oID'] ?>" <?= (isset($editLoc['oID']) && $editLoc['oID'] == $org['oID']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($org['oName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Location <span class="required-asterisk">*</span></label>
                <input type="text" name="lName" value="<?= htmlspecialchars($editLoc['lName'] ?? '') ?>" required>
            </div>

            <button type="submit" class="btn"><?= $editLoc ? 'Update' : 'Save' ?></button>
            <?php if ($editLoc): ?>
                <a href="manage_locations.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Locations List</h3>
        <table>
            <thead>
                <tr>
                    <th>Organization</th>
                    <th>Location</th>
                    <th style="width: 120px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($locations)): ?>
                    <tr><td colspan="3" style="text-align:center;">No locations found.</td></tr>
                <?php else: ?>
                    <?php foreach ($locations as $loc): ?>
                        <tr>
                            <td><?= htmlspecialchars($loc['oName']) ?></td>
                            <td><strong><?= htmlspecialchars($loc['lName']) ?></strong></td>
                            <td style="text-align: center;">
                                <a href="manage_locations.php?edit=<?= $loc['lID'] ?>" class="btn btn-sm">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this location?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="lID" value="<?= $loc['lID'] ?>">
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