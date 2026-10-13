<?php
require_once 'db.php';
require_once 'admin_auth.php';
include_once 'nav_header.php';

$message = '';
$error = '';
$editCat = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $tcID   = (int)($_POST['tcID'] ?? 0);
        $tcName = trim($_POST['tcName'] ?? '');

        if ($tcName !== '') {
            try {
                if ($tcID > 0) {
                    // (edit) Check if another category already has the same name
                    $stmtCheck = $pdo->prepare("SELECT tcID FROM task_category WHERE tcName = :tcName AND tcID != :tcID");
                    $stmtCheck->execute(['tcName' => $tcName, 'tcID' => $tcID]);
                    if ($stmtCheck->fetch()) {
                        $error = 'Category already exist.';
                    } else {
                        $stmt = $pdo->prepare("UPDATE task_category SET tcName = :tcName WHERE tcID = :tcID");
                        $stmt->execute(['tcName' => $tcName, 'tcID' => $tcID]);
                        $message = 'Category updated successfully.';
                    }    
                } else {
                    // (add) Check if category already exist
                    $stmtCheck = $pdo->prepare("SELECT tcID FROM task_category WHERE tcName = :tcName");
                    $stmtCheck->execute(['tcName' => $tcName]);
                    if ($stmtCheck->fetch()) {
                        $error = 'Category already exist.';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO task_category (tcName) VALUES (:tcName)");
                        $stmt->execute(['tcName' => $tcName]);
                        $message = 'Category added successfully.';
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = ($e->getCode() == 23000 ? 'Category already exist.' : $e->getMessage());
            }
        } else {
            $error = 'Category cannot be empty.';
        }
    } elseif ($action === 'delete') {
        $tcID = (int)($_POST['tcID'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM task_category WHERE tcID = :tcID");
            $stmt->execute(['tcID' => $tcID]);
            $message = 'Category deleted successfully.';
        } catch (Exception $e) {
            $error = 'Cannot delete category. Tasks are currently linked to this category.';
        }
    }
}

if (isset($_GET['edit'])) {
    $editTCID = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM task_category WHERE tcID = :tcID");
    $stmt->execute(['tcID' => $editTCID]);
    $editCat = $stmt->fetch();
}

$categories = $pdo->query("SELECT * FROM task_category ORDER BY tcName ASC")->fetchAll();
?>

<title>Manage Task Categories</title>

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
        <h3><?= $editCat ? 'Edit Category' : 'Add New Category' ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="tcID" value="<?= $editCat['tcID'] ?? 0 ?>">

            <div class="form-group">
                <label>Category <span class="required-asterisk">*</span></label>
                <input type="text" name="tcName" value="<?= htmlspecialchars($editCat['tcName'] ?? '') ?>" required>
            </div>

            <button type="submit" class="btn"><?= $editCat ? 'Update' : 'Save' ?></button>
            <?php if ($editCat): ?>
                <a href="manage_categories.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Task Categories List</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Category</th>
                    <th style="width: 120px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="3" style="text-align:center;">No categories found.</td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= (int)$cat['tcID'] ?></td>
                            <td><strong><?= htmlspecialchars($cat['tcName']) ?></strong></td>
                            <td style="text-align: center;">
                                <a href="manage_categories.php?edit=<?= $cat['tcID'] ?>" class="btn btn-sm">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="tcID" value="<?= $cat['tcID'] ?>">
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