<?php
session_start();
require_once 'db/connection.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

function redirectByRole($role) {
    switch ($role) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
        case 'jassu':
            header('Location: jassu/report-form.php');
            break;
        case 'csu':
            header('Location: csu/report-form.php');
            break;
        case 'teacher':
            header('Location: teacher/report-form.php');
            break;
        default:
            header('Location: index.html?error=' . urlencode('Unknown role. Please contact the administrator.'));
            break;
    }
    exit;
}

if (isset($_SESSION['user_id'])) {
    redirectByRole($_SESSION['role']);
}

if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt  = $pdo->prepare(
        "SELECT * FROM users
         WHERE remember_token = ?
         AND remember_token_expires > NOW()"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id']        = $user['id'];
        $_SESSION['username']       = $user['username'];
        $_SESSION['full_name']      = $user['full_name'];
        $_SESSION['role']           = $user['role'];
        $_SESSION['is_first_login'] = $user['is_first_login'];
        redirectByRole($user['role']);
    }
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember_me']);

if (empty($username) || empty($password)) {
    header('Location: index.html?error=' .
        urlencode('Please enter both username and password.'));
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    header('Location: index.html?error=' .
        urlencode('Incorrect username or password. Please try again.'));
    exit;
}


$_SESSION['user_id']        = $user['id'];
$_SESSION['username']       = $user['username'];
$_SESSION['full_name']      = $user['full_name'];
$_SESSION['role']           = $user['role'];
$_SESSION['is_first_login'] = $user['is_first_login'];


if ($remember) {
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + (86400 * 30));
    $pdo->prepare(
        "UPDATE users
         SET remember_token = ?,
             remember_token_expires = ?
         WHERE id = ?"
    )->execute([$token, $expires, $user['id']]);

    setcookie('remember_token', $token, [
        'expires'  => time() + (86400 * 30),
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

redirectByRole($user['role']);
?>