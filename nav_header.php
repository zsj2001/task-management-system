<?php
// Make sure the session is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f9; color: #333; }
        header { background: #ffffff; padding: 12px 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; }
        header a { text-decoration: none; }
        .nav-links a { margin-right: 10px; color: #333;  padding: 6px 10px; border-radius: 4px; transition: background 0.2s, color 0.2s; }
        .nav-links a:hover { background: #2ba3b6; color: #fff; }
        .logout-btn { color: #d9534f; font-size: 13px; font-weight: bold; padding: 5px 10px;  border: 1px solid #d9534f; border-radius: 4px; transition: background 0.2s, color 0.2s; }
        .logout-btn:hover { background: #d9534f; color: #fff; }
        .card { background: #fff; padding: 22px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .grid-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        @media (max-width: 900px) { .grid-layout { grid-template-columns: 1fr; } }
        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 14px; }
        input[type="text"], select { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; color: #fff; background: #2ba3b6; cursor: pointer; text-decoration: none; display: inline-block; font-weight: 500; font-size: 14px; line-height: 1.5; box-sizing: border-box; }
        .btn:hover { background: #238999; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #bd2130; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .actions-column { width: 250px; text-align: center; white-space: nowrap; }
        .actions-cell { text-align: center; white-space: nowrap; vertical-align: middle; }
        .action-form { display: inline-block; margin: 0 2px; padding: 0; vertical-align: middle; }
        .actions-cell .btn { margin: 0; vertical-align: middle; }
        .actions-cell .btn-sm { padding: 5px 9px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: auto; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; font-size: 14px; vertical-align: middle; }
        th { background: #e9ecef; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-active { background: #28a745; color: #fff; }
        .badge-inactive { background: #6c757d; color: #fff; }
        .alert { padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .required-asterisk { color: red; }
    </style>
</head>
<body>
<header>
    <div class="nav-links">
        <a href="manage_organizations.php">Organizations</a>
        <a href="manage_locations.php">Locations</a>
        <a href="manage_departments.php">Departments</a>
        <a href="manage_employees.php">Employees</a>
        <a href="manage_categories.php">Categories</a>
    </div>

    <div>
        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true): ?>
            <!-- Go to Handle logout action in admin_auth.php to know how it return to index.php -->
            <a href="admin_auth.php?action=logout_admin" class="logout-btn">
                Log out
            </a>
        <?php endif; ?>
    </div>
</header>

<?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true): ?>
    <script>
        (function () {
            const IDLE_TIMEOUT = 5 * 60 * 1000;
            let idleTimer = null;
            let hasLoggedOut = false;

            function resetIdleTimer() {
            // Do nothing if logout has already started
            if (hasLoggedOut) {
                return;
            }

            clearTimeout(idleTimer);

            idleTimer = setTimeout(function () {
                if (hasLoggedOut) {
                    return;
                }
                hasLoggedOut = true;

                // Show message
                alert("Session has expired due to inactive.");

                // Redirect to logout handler
                window.location.href = "admin_auth.php?action=logout_admin";
            }, IDLE_TIMEOUT);
        }

        // Reset the timer whenever there are interacts with the page
        const activityEvents = ["mousemove", "keydown", "scroll", "click"];

        activityEvents.forEach(function (eventName) {
            document.addEventListener(eventName, resetIdleTimer, { passive: true });
        });

        // Start the timer when the page loads
        resetIdleTimer();
        })();
    </script>
<?php endif; ?>