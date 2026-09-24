<?php
session_start();
require_once '../../db/connection.php';
header('Content-Type: application/json');
$sid = $_GET['student_id'] ?? '';
$stmt = $pdo->prepare("
    SELECT v.*, da.sanction
    FROM violations v
    LEFT JOIN disciplinary_actions da ON da.violation_id = v.id
    WHERE v.student_id = ?
    ORDER BY v.date_submitted DESC");
$stmt->execute([$sid]);
echo json_encode($stmt->fetchAll());
?>