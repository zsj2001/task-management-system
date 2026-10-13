<?php
require_once 'db.php';
require_once 'admin_auth.php';
include_once 'nav_header.php';

$message = '';
$error = '';
$editOrg = null;

// Handle Form Submit (Create / Update / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $oID = (int)($_POST['oID'] ?? 0);
        $oName = trim($_POST['oName'] ?? '');

        if ($oName !== '') {
            try {
                $pdo->beginTransaction();
                if ($oID > 0) {
                    // (edit) Check if another organization already has the same name
                    $stmtCheck = $pdo->prepare("SELECT oID FROM organization WHERE oName = :oName AND oID != :oID");
                    $stmtCheck->execute(['oID' => $oID, 'oName' => $oName]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This organization already exist.';
                    } else {
                        // Update
                        $stmt = $pdo->prepare("UPDATE organization SET oName = :oName WHERE oID = :oID");
                        $stmt->execute(['oName' => $oName, 'oID' => $oID]);

                        // Update linked party record
                        $stmtParty = $pdo->prepare("UPDATE party SET display_name = :oName WHERE oID = :oID AND pType = 'ORGANIZATION'");
                        $stmtParty->execute(['oName' => $oName, 'oID' => $oID]);

                        $message = 'Organization updated successfully.';
                    }
                } else {
                    // (add) Check if organization already exist
                    $stmtCheck = $pdo->prepare("SELECT oID FROM organization WHERE oName = :oName");
                    $stmtCheck->execute(['oName' => $oName]);
                    if ($stmtCheck->fetch()) {
                        $error = 'This organization already exist.';
                    } else {
                        // Create Organization
                        $stmt = $pdo->prepare("INSERT INTO organization (oName) VALUES (:oName)");
                        $stmt->execute(['oName' => $oName]);
                        $newOID = (int)$pdo->lastInsertId();

                        // Create linked party record automatically
                        $stmtParty = $pdo->prepare("INSERT INTO party (pType, oID, display_name) VALUES ('ORGANIZATION', :oID, :display_name)");
                        $stmtParty->execute(['oID' => $newOID, 'display_name' => $oName]);

                        $message = 'Organization created successfully.';
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = ($e->getCode() == 23000 ? 'Organization already exist.' : $e->getMessage());
            }
        } else {
            $error = 'Organization cannot be empty.';
        }
    } elseif ($action === 'delete') {
        $oID = (int)($_POST['oID'] ?? 0);
        try {
            $pdo->beginTransaction();
            // Delete associated party link
            $pdo->prepare("DELETE FROM party WHERE oID = :oID AND pType = 'ORGANIZATION'")->execute(['oID' => $oID]);
            // Delete organization
            $pdo->prepare("DELETE FROM organization WHERE oID = :oID")->execute(['oID' => $oID]);
            $pdo->commit();
            $message = 'Organization deleted successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Cannot delete organization. It is linked to existing locations or records.';
        }
    }
}

// Check edit request
if (isset($_GET['edit'])) {
    $editOID = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM location WHERE oID = :oID");
    $stmt->execute(['lID' => $editOID]);
    $editOrg = $stmt->fetch();
}

$organizations = $pdo->query("SELECT * FROM organization ORDER BY oName ASC")->fetchAll();
?>

<title>Manage Organizations</title>

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
    <!-- Form Card -->
    <div class="card">
        <h3><?= $editOrg ? 'Edit Organization' : 'Add New Organization' ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="oID" value="<?= $editOrg['oID'] ?? 0 ?>">
            
            <div class="form-group">
                <label>Organization <span class="required-asterisk">*</span></label>
                <input type="text" name="oName" value="<?= htmlspecialchars($editOrg['oName'] ?? '') ?>" required>
            </div>

            <button type="submit" class="btn"><?= $editOrg ? 'Update' : 'Save' ?></button>
            <?php if ($editOrg): ?>
                <a href="manage_organizations.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <h3>Organizations List</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th style="width: 120px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($organizations)): ?>
                    <tr><td colspan="3" style="text-align:center;">No organizations found.</td></tr>
                <?php else: ?>
                    <?php foreach ($organizations as $org): ?>
                        <tr>
                            <td><?= (int)$org['oID'] ?></td>
                            <td><strong><?= htmlspecialchars($org['oName']) ?></strong></td>
                            <td style="text-align: center;">
                                <a href="manage_organizations.php?oID=<?= $org['oID'] ?>" class="btn btn-sm">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this organization?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="oID" value="<?= $org['oID'] ?>">
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
