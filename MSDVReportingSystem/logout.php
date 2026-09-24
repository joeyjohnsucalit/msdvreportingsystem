<?php
session_start();
require_once 'db/connection.php';

// Clear remember token from DB
if (isset($_SESSION['user_id'])) {
    try {
        $pdo->prepare(
            "UPDATE users
             SET remember_token = NULL,
                 remember_token_expires = NULL
             WHERE id = ?"
        )->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {}
}

// Expire auth token cookie only
// remember_user cookie stays so username is still filled on login page
setcookie('remember_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Fully destroy session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_destroy();

// No-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
header('Location: index.html?logged_out=1');
exit;
?>