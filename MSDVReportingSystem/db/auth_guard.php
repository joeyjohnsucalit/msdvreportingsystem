<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent back button on every protected page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

function requireLogin($role = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }
    if ($role !== null) {
        $allowed = is_array($role) ? $role : [$role];
        if (!in_array($_SESSION['role'], $allowed)) {
            header('Location: ../login.php');
            exit;
        }
    }
}
?>