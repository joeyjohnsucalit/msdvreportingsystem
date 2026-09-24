<?php
session_start();
require_once '../../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { http_response_code(403); exit; }

header('Content-Type: application/json');
$type = $_GET['type'] ?? '';
$year = (int)($_GET['year'] ?? date('Y'));

$deptColorMap = [
    'School of Technology' => '#dc3545',
    'School of Education'  => '#0d6efd',
    'School of Business'   => '#fd7e14'
];
$courseColorMap = [
    'School of Technology' => '#dc3545',
    'School of Education'  => '#0d6efd',
    'School of Business'   => '#fd7e14'
];

if ($type === 'vpm') {
    $stmt = $pdo->prepare("SELECT MONTH(date_submitted) m, COUNT(*) c FROM violations WHERE YEAR(date_submitted)=? GROUP BY m");
    $stmt->execute([$year]);
    $data = array_fill(0,12,0);
    foreach($stmt->fetchAll() as $r) $data[$r['m']-1] = (int)$r['c'];
    echo json_encode($data);

} elseif ($type === 'mvm') {
    $minor = array_fill(0,12,0); $major = array_fill(0,12,0);
    $s1 = $pdo->prepare("SELECT MONTH(date_submitted) m, COUNT(*) c FROM violations WHERE YEAR(date_submitted)=? AND category='minor' GROUP BY m");
    $s1->execute([$year]);
    foreach($s1->fetchAll() as $r) $minor[$r['m']-1] = (int)$r['c'];
    $s2 = $pdo->prepare("SELECT MONTH(date_submitted) m, COUNT(*) c FROM violations WHERE YEAR(date_submitted)=? AND category='major' GROUP BY m");
    $s2->execute([$year]);
    foreach($s2->fetchAll() as $r) $major[$r['m']-1] = (int)$r['c'];
    echo json_encode(['minor'=>$minor,'major'=>$major]);

} elseif ($type === 'dept') {
    $stmt = $pdo->prepare("SELECT d.name, COUNT(v.id) c FROM violations v JOIN students s ON v.student_id=s.student_id JOIN departments d ON s.department_id=d.id WHERE YEAR(v.date_submitted)=? GROUP BY d.id");
    $stmt->execute([$year]);
    $labels=[]; $data=[]; $colors=[];
    foreach($stmt->fetchAll() as $r){
        $labels[]=$r['name']; $data[]=(int)$r['c'];
        $colors[]=$deptColorMap[$r['name']] ?? '#6c757d';
    }
    echo json_encode(['labels'=>$labels,'data'=>$data,'colors'=>$colors]);

} elseif ($type === 'course') {
    $stmt = $pdo->prepare("SELECT c.name cn, d.name dn, COUNT(v.id) c FROM violations v JOIN students s ON v.student_id=s.student_id JOIN courses c ON s.course_id=c.id JOIN departments d ON s.department_id=d.id WHERE YEAR(v.date_submitted)=? GROUP BY c.id ORDER BY d.id");
    $stmt->execute([$year]);
    $labels=[]; $data=[]; $colors=[];
    foreach($stmt->fetchAll() as $r){
        $labels[]=$r['cn']; $data[]=(int)$r['c'];
        $colors[]=$courseColorMap[$r['dn']] ?? '#6c757d';
    }
    echo json_encode(['labels'=>$labels,'data'=>$data,'colors'=>$colors]);

} elseif ($type === 'sv') {
    $violation = $_GET['violation'] ?? '';
    $depts = ['SOT','SOE','SOB'];
    $result = ['sot'=>array_fill(0,12,0),'soe'=>array_fill(0,12,0),'sob'=>array_fill(0,12,0)];
    $keys = ['SOT'=>'sot','SOE'=>'soe','SOB'=>'sob'];
    $stmt = $pdo->prepare("SELECT d.code, MONTH(v.date_submitted) m, COUNT(*) c FROM violations v JOIN students s ON v.student_id=s.student_id JOIN departments d ON s.department_id=d.id WHERE YEAR(v.date_submitted)=? AND v.violation=? GROUP BY d.code, m");
    $stmt->execute([$year,$violation]);
    foreach($stmt->fetchAll() as $r){
        $k = $keys[$r['code']] ?? null;
        if($k) $result[$k][$r['m']-1] = (int)$r['c'];
    }
    echo json_encode($result);
}
?>