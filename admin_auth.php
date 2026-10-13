<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DEFINE ADMIN ACCESS CODE
define('ADMIN_PASSCODE', 'your desire password here'); // Change password

// Inactivity timeout: 5 minutes
define('ADMIN_IDLE_TIMEOUT', 300);

// Handle logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout_admin') {
    unset($_SESSION['is_admin']);   // 1. Clears the admin session
    unset($_SESSION['admin_last_activity']);
    // Store logout reason so the login page can display a message
    $_SESSION['admin_logout_message'] = 'You have been logged out due to inactivity.';
    header('Location: index.php');  // 2. Tells the browser to go to index.php
    exit;
}

// CHECK INACTIVITY
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {

    // If last activity exists and 5 minutes have passed
    if (
        isset($_SESSION['admin_last_activity']) &&
        (time() - $_SESSION['admin_last_activity']) >= ADMIN_IDLE_TIMEOUT
    ) {
        // Log the user out
        unset($_SESSION['is_admin']);
        unset($_SESSION['admin_last_activity']);

        // Message to display on login screen
        $_SESSION['admin_logout_message'] = 'Your session had expired.';

        // Redirect to the same page without exposing anything else
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // Update last activity time
    $_SESSION['admin_last_activity'] = time();
}

// Handle login submission
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pin'])) {
    if ($_POST['admin_pin'] === ADMIN_PASSCODE) {
        session_regenerate_id(true);    // Regenerate session ID after successful authentication
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_last_activity'] = time();
        unset($_SESSION['admin_logout_message']);    // Clear any previous logout message
        // Redirect to clean the POST request
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $login_error = 'Invalid passcode. Access denied.';
    }
}

// Lockout screen displayed if not authenticated
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    // Get logout/timeout message
    $logout_message = $_SESSION['admin_logout_message'] ?? '';
    unset($_SESSION['admin_logout_message']);
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Authorization Required</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
            .auth-card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 360px; text-align: center; }
            .auth-card h2 { margin-top: 0; color: #333; font-size: 20px; }
            .auth-card p { color: #666; font-size: 14px; margin-bottom: 20px; }
            .auth-card input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; margin-bottom: 15px; font-size: 14px; text-align: center; }
            .auth-card button { width: 100%; background: #007bff; color: white; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 14px; }
            .auth-card button:hover { background: #0056b3; }
            .error-msg { color: #d9534f; font-size: 13px; margin-bottom: 15px; }
            .back-link { display: inline-block; margin-top: 15px; color: #666; text-decoration: none; font-size: 13px; }
            .timeout-msg { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 12px 15px; border-radius: 5px; font-size: 13px; margin-bottom: 18px; text-align: left; }
        </style>
    </head>
    <body>
        <div class="auth-card">
            <h2>Restricted Access</h2>
            <p>Enter passcode to access management tool.</p>

            <?php if ($logout_message): ?>
                <div class="timeout-msg">
                    <?php echo htmlspecialchars($logout_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($login_error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="password" name="admin_pin" placeholder="Enter Access Code" required autofocus>
                <button type="submit">Unlock Access</button>
            </form>

            <a href="index.php" class="back-link">Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>