<?php
session_start();

// Log the logout activity (optional)
if (isset($_SESSION['user_id'])) {
    // You can add logging functionality here if needed
    // For example: log user logout time, IP address, etc.
    
    // Optional: Update last_logout timestamp in database
    try {
        require_once 'config/database.php';
        
        $stmt = $db->query(
            "UPDATE users SET last_logout = NOW() WHERE id = ?", 
            [$_SESSION['user_id']]
        );
    } catch (Exception $e) {
        // Silently handle database errors during logout
        // Don't prevent logout if database update fails
    }
}

// Destroy all session data
session_unset();
session_destroy();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Optional: Delete any remember me cookies if implemented
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Redirect to login page with logout message
header("Location: login.php?logout=success");
exit();
?>
