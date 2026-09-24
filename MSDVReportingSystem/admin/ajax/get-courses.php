<?php
session_start();
require_once '../../db/connection.php';
header('Content-Type: application/json');
$dept = (int)($_GET['dept'] ?? 0);
$stmt = $pdo->prepare("SELECT id, name FROM courses WHERE department_id=?");
$stmt->execute([$dept]);
echo json_encode($stmt->fetchAll());
?>