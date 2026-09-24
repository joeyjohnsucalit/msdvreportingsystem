<?php
session_start();
require_once '../../db/connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$uid    = $_SESSION['user_id'];

if ($action === 'read') {
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare(
        "UPDATE notifications SET is_read = 1
         WHERE id = ? AND user_id = ?"
    )->execute([$id, $uid]);
    echo json_encode(['ok' => true]);

} elseif ($action === 'read_all') {
    $pdo->prepare(
        "UPDATE notifications SET is_read = 1
         WHERE user_id = ?"
    )->execute([$uid]);
    echo json_encode(['ok' => true]);

} elseif ($action === 'poll') {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND is_read = 0"
    );
    $countStmt->execute([$uid]);
    $unread = (int)$countStmt->fetchColumn();

    $notifStmt = $pdo->prepare(
        "SELECT * FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $notifStmt->execute([$uid]);
    $notifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'unread'        => $unread,
        'notifications' => $notifs
    ]);

} else {
    echo json_encode(['error' => 'invalid action']);
}
?>