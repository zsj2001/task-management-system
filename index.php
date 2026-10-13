<?php
session_start();

$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

require_once 'db.php';

// Fetch options for filters
$locations = $pdo->query("
    SELECT
        l.lID,
        l.lName,
        o.oName
    FROM location l
    INNER JOIN organization o ON l.oID = o.oID
    ORDER BY o.oName, l.lName
")->fetchAll();
$statuses = $pdo->query("SELECT sID, sName FROM status ORDER BY sID")->fetchAll();
$categories = $pdo->query("SELECT tcID, tcName FROM task_category ORDER BY tcName")->fetchAll();
$urgencies = $pdo->query("SELECT urgID, urgName FROM urgency ORDER BY urgName")->fetchAll();

// Get Search & Filter Inputs
$search_status   = $_GET['status'] ?? '';
$search_location = $_GET['location'] ?? '';
$search_category = $_GET['category'] ?? '';
$search_urgency = $_GET['urgency'] ?? '';
$search_date = $_GET['request_date'] ?? '';
$search_query    = trim($_GET['q'] ?? '');

// Build Query for data query and count query
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

if ($search_status !== '') {
    $fromSql .= " AND t.current_sID = :status";
    $params['status'] = $search_status;
}

if ($search_location !== '') {
    $fromSql .= " AND creator_loc.lID = :location";
    $params['location'] = $search_location;
}

if ($search_category !== '') {
    $fromSql .= " AND t.tcID = :category";
    $params['category'] = $search_category;
}

if ($search_urgency !== '') {
    $fromSql .= " AND u.urgID = :urgency";
    $params['urgency'] = $search_urgency;
}

if ($search_date !== '') {
    $fromSql .= " AND t.request_date = :request_date";
    $params['request_date'] = $search_date;
}

if ($search_query !== '') {
    $fromSql .= "
        AND (
            t.tTitle LIKE :q_title
            OR t.tDesc LIKE :q_desc
            OR t.tNo LIKE :q_no
            OR target_p.display_name LIKE :q_person
        )
    ";
    $searchValue = '%' . $search_query . '%';

    $params['q_title']  = $searchValue;
    $params['q_desc']   = $searchValue;
    $params['q_no']     = $searchValue;
    $params['q_person'] = $searchValue;
}

$sql = "
    SELECT 
        t.tID,
        t.tNo,
        t.tTitle,
        t.request_date,
        t.created_at,
        creator_org.oName AS organization_name,
        creator_loc.lName AS location_name,
        u.urgName,
        s.sName AS status_name,
        e_creator.eName AS creator_name,
        target_p.display_name AS target_person_name,
        tc.tcName as category_name
    " . $fromSql;

// Count total records (pagination) before apply LIMIT/OFFSET
$countSql = "SELECT COUNT(DISTINCT t.tID) AS total " . $fromSql;
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();

$perPage = 10;
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY t.created_at DESC, t.tID DESC LIMIT :limit OFFSET :offset";

// Current page state, so View/Action links can bring user back current page
$backParam = urlencode(http_build_query($_GET));

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Task Management</title>

    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f9; color: #333; }
        .header { display: flex; justify-content: space-between; align-items: center; }
        .header-nav { background: rgba(33, 37, 41, 0.9); padding: 8px 15px; border-radius: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.2); display: flex; gap: 12px; font-size: 12px; align-items: center; }
        .header-nav a { color: #fff; text-decoration: none; }
        .btn { padding: 8px 16px; text-decoration: none; border-radius: 4px; color: #fff; background: #28a745; display: inline-block; border: none; cursor: pointer; }
        .btn-primary { background: #2ba3b6; }
        .btn-secondary { background: #6c757d; }
        .card { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #e9ecef; }
        
        .filter-section { display: flex; flex-direction: column; gap: 15px; }
        .search-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-row input, .search-row select { padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; }
        
        .status-pills { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .status-pill { display: inline-flex; align-items: center; padding: 8px 18px; border-radius: 30px; border: 1.5px solid #2ba3b6; color: #2ba3b6; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s ease-in-out; background: #fff; }
        .status-pill:hover { background: #e8f6f8; }
        .status-pill.active { background: #2ba3b6; color: #fff; border-color: #2ba3b6; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-CRITICAL { background: #dc3545; color: #fff; }
        .badge-HIGH { background: #fd7e14; color: #fff; }
        .badge-MEDIUM { background: #ffc107; color: #212529; }
        .badge-LOW { background: #6c757d; color: #fff; }

        .pagination { display: flex; align-items: center; margin-top: 16px; }
        .pagination a { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; height: 38px; padding: 0 12px; box-sizing: border-box; background: #fff; color: #111; text-decoration: none; border: 1px solid #dee2e6; border-right: none; font-size: 14px; }
        .pagination a:first-child { border-radius: 4px 0 0 4px; }
        .pagination a:last-child { border-right: 1px solid #dee2e6; border-radius: 0 4px 4px 0; }
        .pagination a:hover { background: #f2f2f2; }
        .pagination a.active { background: #2ba3b6; color: #fff; border-color: #2ba3b6; }
        .success-message { display: flex; align-items: center; justify-content: space-between; gap: 20px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 12px 16px; border-radius: 5px; margin-bottom: 20px; }
        .success-text { font-weight: 500; }
        .success-ok-btn { background-color: #28a745; color: white; border: none; padding: 7px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .success-ok-btn:hover { background-color: #218838; }
    </style>
</head>

<body>
<div class="header">
    <h2>Task Management</h2>

    <div class="header-nav">
        <a href="manage_organizations.php">Organizations</a>
        <a href="manage_locations.php">Locations</a>
        <a href="manage_departments.php">Departments</a>
        <a href="manage_employees.php">Employees</a>
        <a href="manage_categories.php">Categories</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="success-message" id="successMessage">
        <div class="success-text">
            <?= ($successMessage) ?>
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
    <form method="GET" class="filter-section">
        <!-- Filter Row -->
        <div class="search-row">
            <input type="text" name="q" placeholder="Search title, person, task No" value="<?= ($search_query) ?>" style="min-width: 250px;">
            
            <select name="location">
                <option value="">-- Select organization - location --</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= (int)$loc['lID'] ?>"
                        <?= $search_location == $loc['lID'] ? 'selected' : '' ?>>
                        <?= ($loc['oName'] . ' - ' . $loc['lName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="category">
                <option value="">-- Select category --</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['tcID'] ?>" 
                      <?= $search_category == $cat['tcID'] ? 'selected' : '' ?>>
                        <?= ($cat['tcName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="urgency">
                <option value="">-- Select urgency --</option>
                <?php foreach ($urgencies as $urg): ?>
                    <option value="<?= $urg['urgID'] ?>" 
                      <?= $search_urgency == $urg['urgID'] ? 'selected' : '' ?>>
                        <?= ($urg['urgName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="request_date" value="<?= ($search_date) ?>" title="Request date">

            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="index.php" style="color: #666; font-size: 14px; text-decoration: none;">Reset</a>
            <a href="create_task.php" class="btn btn-primary" style="margin-left: auto;">Create Task</a>
        </div>

        <!-- Status Filter Pills -->
        <div class="status-pills">
            <a href="index.php?<?= http_build_query(array_merge($_GET, ['status' => '', 'page' => 1])) ?>" 
               class="status-pill <?= $search_status === '' ? 'active' : '' ?>">
               All
            </a>
            
            <?php foreach ($statuses as $st): ?>
                <?php 
                    $isActive = ($search_status == $st['sID']); 
                    // Format display text (e.g., IN_PROGRESS -> In Progress)
                    $displayName = ucwords(strtolower(str_replace('_', ' ', $st['sName'])));
                ?>
                <a href="index.php?<?= http_build_query(array_merge($_GET, ['status' => $st['sID'], 'page' => 1])) ?>" 
                   class="status-pill <?= $isActive ? 'active' : '' ?>">
                   <?= ($displayName) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>Task No</th>
                <th>Title</th>
                <th>Category</th>
                <th>Organization</th>
                <th>Location</th>
                <th>Urgency</th>
                <th>Requestor</th>
                <th>Target</th>
                <th>Status</th>
                <th>Date</th>
                <th style="text-align: center;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
                <tr><td colspan="11" style="text-align: center;">No tasks found.</td></tr>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><strong><?= ($task['tNo']) ?></strong></td>
                        <td><?= ($task['tTitle']) ?></td>
                        <td><?= ($task['category_name'] ?? 'Uncategorized') ?></td>
                        <td><?= ($task['organization_name']) ?></td>
                        <td><?= ($task['location_name']) ?></td>
                        <td><span class="badge badge-<?= $task['urgName'] ?>"><?= ($task['urgName']) ?></span></td> 
                        <td><?= ($task['creator_name']) ?></td>
                        <td><?= ($task['target_person_name'] ?? 'Unassigned') ?></td>
                        <td><strong><?= ($task['status_name']) ?></strong></td>
                        <td><?= ($task['request_date']) ?></td>
                        <td>
                            <div style="display: flex; gap: 6px; justify-content: center;">
                              <?php if ($task['status_name'] === 'CLOSED'): ?>
                                  <!-- Closed task: read-only view -->
                                  <a href="task_detail.php?tID=<?= $task['tID'] ?>&back=<?= $backParam ?>" class="btn btn-secondary" style="font-size: 12px; padding: 5px 10px;">View</a>
                                  <span class="btn btn-primary" style="font-size: 12px; padding: 5px 10px; opacity: 0.5; cursor: not-allowed; pointer-events: none;" title="Task is closed">Action</span>
                              <?php else: ?>
                                  <!-- View Button: read-only view screen -->
                                  <a href="view_task.php?tID=<?= $task['tID'] ?>&back=<?= $backParam ?>" class="btn btn-secondary" style="font-size: 12px; padding: 5px 10px;">View</a>

                                  <a href="task_detail.php?tID=<?= $task['tID'] ?>&back=<?= $backParam ?>" class="btn btn-primary" style="font-size: 12px; padding: 5px 10px;">Action</a>
                              <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="index.php?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">First</a>
      <?php endif; ?>

      <!-- page no. -->
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="index.php?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
          class="<?= $p === $page ? 'active' : '' ?>">
          <?= $p ?>
        </a>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="index.php?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>">Last</a>
      <?php endif; ?>
    </div>
</div>
</body>
</html>