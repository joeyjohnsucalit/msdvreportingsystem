<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

define('DELETE_PASSWORD','delete123');

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();

if(isset($_POST['action'])&&$_POST['action']==='add'){
    $sid=trim($_POST['student_id']);$fname=trim($_POST['full_name']);$course=(int)$_POST['course_id'];$year=(int)$_POST['year_level'];$dept=(int)$_POST['department_id'];
    $dup=$pdo->prepare("SELECT student_id FROM students WHERE student_id=?");$dup->execute([$sid]);
if($dup->fetch()){$_SESSION['err']='Student ID <strong>'.htmlspecialchars($sid).'</strong> is already taken.';header('Location: student-records.php');exit;}
$dupUser=$pdo->prepare("SELECT id FROM users WHERE username=?");$dupUser->execute([$sid]);
if($dupUser->fetch()){$_SESSION['err']='A user account with ID <strong>'.htmlspecialchars($sid).'</strong> already exists.';header('Location: student-records.php');exit;}
    $uname=$sid;$lastFive=substr(str_replace('-','',$sid),-5);$hashed=password_hash($lastFive,PASSWORD_DEFAULT);
    try{
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users (full_name,username,email,password,role,is_first_login) VALUES (?,?,'',?,'student',1)")->execute([$fname,$uname,$hashed]);
        $uid=$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO students (student_id,full_name,course_id,year_level,department_id,user_id) VALUES (?,?,?,?,?,?)")->execute([$sid,$fname,$course,$year,$dept,$uid]);
        $pdo->commit();
        $_SESSION['msg']='Student added. <strong>Username:</strong> '.$sid.' | <strong>Default Password:</strong> '.$lastFive;
    }catch(Exception $e){$pdo->rollBack();$_SESSION['err']='Error: '.$e->getMessage();}
    header('Location: student-records.php');exit;
}
if(isset($_POST['action'])&&$_POST['action']==='edit'){
    $sid=trim($_POST['student_id']);$fname=trim($_POST['full_name']);$course=(int)$_POST['course_id'];$year=(int)$_POST['year_level'];$dept=(int)$_POST['department_id'];
    $pdo->prepare("UPDATE students SET full_name=?,course_id=?,year_level=?,department_id=? WHERE student_id=?")->execute([$fname,$course,$year,$dept,$sid]);
    $pdo->prepare("UPDATE users SET full_name=? WHERE id=(SELECT user_id FROM students WHERE student_id=?)")->execute([$fname,$sid]);
    $_SESSION['msg']='Student updated.';header('Location: student-records.php');exit;
}
if(isset($_POST['action'])&&$_POST['action']==='delete'){
    $sid=trim($_POST['student_id']);$pass=trim($_POST['del_password']);
    if($pass===DELETE_PASSWORD){
        try {
            $pdo->beginTransaction();
            
            // Get user_id from students table
            $uid=$pdo->prepare("SELECT user_id FROM students WHERE student_id=?");
            $uid->execute([$sid]);$row=$uid->fetch();
            
            // Also try to find user by username (fallback)
            $uidByUsername=$pdo->prepare("SELECT id FROM users WHERE username=?");
            $uidByUsername->execute([$sid]);$rowByUsername=$uidByUsername->fetch();
            
            $userIdToDelete = $row['user_id'] ?? $rowByUsername['id'] ?? null;

            // Delete child records first
            $pdo->prepare("DELETE FROM disciplinary_actions WHERE student_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM appeals WHERE student_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM violations WHERE student_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM students WHERE student_id=?")->execute([$sid]);

            // Delete user account and their notifications
            if($userIdToDelete){
                $pdo->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$userIdToDelete]);
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userIdToDelete]);
            }

            $pdo->commit();
            $_SESSION['msg']='Student and user account deleted successfully.';
        } catch(Exception $e){
            $pdo->rollBack();
            $_SESSION['err']='Delete failed: '.$e->getMessage();
        }
    }else{$_SESSION['err']='Incorrect deletion password.';}
    header('Location: student-records.php');exit;
}

$courses=$pdo->query("SELECT c.id,c.name,c.department_id,d.name as dept FROM courses c JOIN departments d ON c.department_id=d.id")->fetchAll();
$departments=$pdo->query("SELECT * FROM departments")->fetchAll();
$search=trim($_GET['search']??'');$filterCourse=$_GET['course']??'';$filterYear=$_GET['year_level']??'';$filterDept=$_GET['dept']??'';
$where="WHERE 1=1";$params=[];
if($search){$where.=" AND (s.student_id LIKE ? OR s.full_name LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($filterCourse){$where.=" AND s.course_id=?";$params[]=$filterCourse;}
if($filterYear){$where.=" AND s.year_level=?";$params[]=$filterYear;}
if($filterDept){$where.=" AND s.department_id=?";$params[]=$filterDept;}
$stmt=$pdo->prepare("SELECT s.*,c.name as course_name,d.name as dept_name,(SELECT COUNT(*) FROM violations v WHERE v.student_id=s.student_id) as total_violations FROM students s JOIN courses c ON s.course_id=c.id JOIN departments d ON s.department_id=d.id $where ORDER BY s.student_id");
$stmt->execute($params);$students=$stmt->fetchAll();
function getRiskLevel($t){if($t>=5)return['label'=>'Critical','class'=>'danger','color'=>'#dc2626','bg'=>'#fff0f0'];if($t>=4)return['label'=>'High','class'=>'warning','color'=>'#d97706','bg'=>'#fffbeb'];if($t>=2)return['label'=>'Moderate','class'=>'info','color'=>'#2563eb','bg'=>'#eff4ff'];return['label'=>'Low','class'=>'success','color'=>'#16a34a','bg'=>'#f0fdf4'];}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Student Records</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg:#f6f7f9; --surface:#fff; --border:#eaecf0; --border-soft:#f2f4f7;
    --text:#111827; --text-2:#6b7280; --text-3:#9ca3af;
    --accent:#2563eb; --accent-light:#eff4ff;
    --sidebar:#091b4b; --sidebar-red:#c8322b;
    --r-sm:8px; --r-md:12px; --r-lg:16px;
}
body { display:flex; background:var(--bg); min-height:100vh; font-family:'Plus Jakarta Sans',sans-serif; color:var(--text); }
::-webkit-scrollbar { width:0; }

/* ── SIDEBAR (unchanged) ── */
.sidebar { width:260px; height:100vh; background:var(--sidebar); color:#fff; position:fixed; top:0; left:0; display:flex; flex-direction:column; z-index:999; box-shadow:1px 0 0 rgba(255,255,255,0.04); }
.adm_logo { display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:3px solid var(--sidebar-red); flex-shrink:0; }
.adm_logo img { width:52px; height:52px; border-radius:50%; border:2px solid rgba(255,255,255,0.15); object-fit:cover; }
.logotext h2 { font-size:15px; font-weight:600; color:#fff; line-height:1.2; }
.logotext p  { font-size:10px; color:#8a90b0; margin-top:2px; }
.sidebar_content { flex:1; overflow-y:auto; padding:8px 0; }
.sidebar_content ul { list-style:none; padding:0; margin:0; }
.sidebar_content h3 { padding:14px 18px 5px; font-size:9.5px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; color:#b8960c; }
.sidebar_content ul li a { display:flex; align-items:center; gap:10px; padding:10px 18px; color:#c8cce0; text-decoration:none; font-size:13px; transition:all 0.18s; margin:1px 8px; border-radius:6px; }
.sidebar_content ul li a i { font-size:14px; width:18px; text-align:center; flex-shrink:0; }
.sidebar_content ul li a:hover { background:rgba(255,255,255,0.06); color:#fff; }
.sidebar_content ul li a.active { background:rgba(255,255,255,0.07); color:#fff; border-left:3px solid var(--sidebar-red); padding-left:15px; margin-left:8px; }

/* ── MAIN ── */
.main-content { margin-left:260px; width:calc(100% - 260px); min-height:100vh; display:flex; flex-direction:column; }

/* ── NAVBAR (unchanged structure) ── */
.navbar { background:var(--sidebar); padding:0 28px; height:60px; position:fixed; left:260px; right:0; top:0; display:flex; align-items:center; justify-content:space-between; border-bottom:3px solid var(--sidebar-red); z-index:1000; }
.navbar h1 { color:#fff; font-size:18px; font-weight:600; letter-spacing:-0.2px; }
.navbar-right { display:flex; align-items:center; gap:12px; }
.bell-wrap { position:relative; }
.bell-btn { background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); color:#fff; width:36px; height:36px; border-radius:var(--r-sm); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; position:relative; transition:background 0.15s; }
.bell-btn:hover { background:rgba(255,255,255,0.13); }
.notif-badge { position:absolute; top:-4px; right:-4px; background:#e53e3e; color:#fff; font-size:9px; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; border:2px solid var(--sidebar); pointer-events:none; }

/* ── NOTIFICATION DROPDOWN ── */
.notif-dropdown { width:360px; border-radius:var(--r-lg); border:1px solid var(--border); box-shadow:0 8px 32px rgba(0,0,0,0.10),0 1px 4px rgba(0,0,0,0.06); background:var(--surface); overflow:hidden; }
.notif-head { padding:16px 20px 14px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.notif-head-left { display:flex; align-items:center; gap:8px; }
.notif-head-left span { font-size:14px; font-weight:600; color:var(--text); }
.unread-pill { background:#eff4ff; color:#2563eb; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px; line-height:1.6; display:none; }
.unread-pill.visible { display:inline-flex; }
.mark-all-btn { background:none; border:none; color:var(--text-3); font-size:11.5px; font-weight:500; cursor:pointer; padding:4px 8px; border-radius:6px; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; }
.mark-all-btn:hover { background:var(--border-soft); color:var(--text-2); }
.notif-scroll { max-height:380px; overflow-y:auto; }
.notif-scroll::-webkit-scrollbar { width:3px; }
.notif-scroll::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
.notif-item { display:flex; align-items:flex-start; gap:12px; padding:14px 20px; border-bottom:1px solid var(--border-soft); text-decoration:none; color:inherit; transition:background 0.12s; cursor:pointer; }
.notif-item:last-child { border-bottom:none; }
.notif-item:hover { background:#fafbfc; }
.notif-item.unread { background:#fafcff; }
.notif-item.unread:hover { background:#f4f8ff; }
.notif-avatar { width:32px; height:32px; border-radius:50%; background:#f0f4ff; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; }
.notif-avatar i { font-size:12px; color:#4f8ef7; }
.notif-item.unread .notif-avatar { background:#dbeafe; }
.notif-item.unread .notif-avatar i { color:#2563eb; }
.notif-body-text { flex:1; min-width:0; }
.notif-msg { font-size:12.5px; line-height:1.5; color:var(--text-2); font-weight:400; margin-bottom:4px; }
.notif-item.unread .notif-msg { color:var(--text); font-weight:500; }
.notif-ts { font-size:10.5px; color:var(--text-3); }
.notif-dot-ind { width:6px; height:6px; border-radius:50%; background:#2563eb; flex-shrink:0; margin-top:6px; }
.notif-empty { padding:40px 20px; text-align:center; }
.notif-empty i { font-size:24px; color:var(--text-3); display:block; margin-bottom:8px; }
.notif-empty p { font-size:13px; color:var(--text-3); margin:0; }

/* User chip */
.user-chip { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.1); padding:5px 12px 5px 6px; border-radius:20px; }
.user-avatar { width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; color:#fff; flex-shrink:0; }
.user-name { font-size:12.5px; color:#c8cce0; font-weight:500; }

/* ── CONTENT ── */
.content { margin-top:60px; padding:28px 32px 40px; flex:1; }

/* Page top */
.page-top { display:flex; align-items:center; justify-content:flex-end; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.page-top h2 { font-size:22px; font-weight:600; color:var(--text); letter-spacing:-0.4px; margin:0 0 3px; }
.page-top p  { font-size:13px; color:var(--text-3); margin:0; }

/* Add button */
.btn-add { display:flex; align-items:center; gap:7px; background:var(--accent); color:#fff; border:none; padding:9px 18px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:background 0.15s, transform 0.1s; white-space:nowrap; left: 10px; }
.btn-add:hover { background:#1d4ed8; color:#fff; }
.btn-add:active { transform:scale(0.98); }

/* Alert banners */
.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:16px; display:flex; align-items:center; gap:10px; border:none; }
.alert-banner.success { background:#f0fdf4; color:#15803d; }
.alert-banner.error   { background:#fff0f0; color:#dc2626; }
.alert-banner i { flex-shrink:0; }

/* Filter bar */
.filter-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); padding:16px 20px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.filter-input, .filter-select {
    font-size:13px; padding:8px 12px; border-radius:var(--r-sm);
    border:1px solid var(--border); background:#fff; color:var(--text);
    font-family:'Plus Jakarta Sans',sans-serif; outline:none;
    transition:border-color 0.15s; height:36px;
}
.filter-input:focus, .filter-select:focus { border-color:var(--accent); }
.filter-input { min-width:200px; }
.filter-select { appearance:none; -webkit-appearance:none; padding-right:28px; cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 8px center; background-color:#fff;
}
.filter-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all 0.15s; height:36px; border:1px solid; }
.filter-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.filter-btn.primary:hover { background:#1d4ed8; }
.filter-btn.secondary { background:#fff; color:var(--text-2); border-color:var(--border); text-decoration:none; }
.filter-btn.secondary:hover { background:var(--bg); }

/* Count badge */
.count-badge { font-size:12px; color:var(--text-3); margin-bottom:10px; }
.count-badge strong { color:var(--text); }

/* ── TABLE ── */
.table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); overflow:hidden; }
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table thead tr { border-bottom:1px solid var(--border); }
.data-table thead th { padding:11px 16px; font-size:10.5px; font-weight:600; letter-spacing:0.5px; text-transform:uppercase; color:var(--text-3); background:var(--bg); white-space:nowrap; }
.data-table tbody tr { border-bottom:1px solid var(--border-soft); transition:background 0.1s; }
.data-table tbody tr:last-child { border-bottom:none; }
.data-table tbody tr:hover { background:#fafbfc; }
.data-table td { padding:13px 16px; color:var(--text); vertical-align:middle; }
.data-table td.sid { font-weight:600; font-family:monospace; font-size:12.5px; color:var(--accent); letter-spacing:0.2px; }
.data-table td.name { font-weight:500; }
.data-table td.muted { color:var(--text-2); }
.empty-row td { padding:40px 16px; text-align:center; color:var(--text-3); font-size:13px; }

/* Risk badge */
.risk-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; }
.risk-badge .dot { width:5px; height:5px; border-radius:50%; }

/* Action btn */
.view-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--r-sm); font-size:12px; font-weight:500; border:1px solid var(--border); background:#fff; color:var(--text-2); cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; }
.view-btn:hover { background:var(--accent-light); color:var(--accent); border-color:#c7d7f8; }

/* ── MODALS — redesigned ── */
.modal-content { border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 16px 48px rgba(0,0,0,0.12); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; }
.modal-header { padding:20px 24px 16px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.modal-header .modal-title { font-size:16px; font-weight:600; color:var(--text); letter-spacing:-0.2px; }
.modal-header.danger-head { background:#fff0f0; }
.modal-header.danger-head .modal-title { color:#dc2626; }
.modal-header .btn-close { opacity:0.4; }
.modal-header .btn-close:hover { opacity:0.7; }
.modal-body { padding:20px 24px; }
.modal-footer { padding:14px 24px; border-top:1px solid var(--border-soft); display:flex; justify-content:flex-end; gap:8px; background:#fafbfc; }

/* Form elements inside modals */
.f-group { margin-bottom:16px; }
.f-group:last-child { margin-bottom:0; }
.f-label { display:block; font-size:12px; font-weight:600; color:var(--text-2); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
.f-input, .f-select {
    width:100%; padding:9px 12px; border-radius:var(--r-sm);
    border:1px solid var(--border); background:#fff; color:var(--text);
    font-size:13.5px; font-family:'Plus Jakarta Sans',sans-serif;
    outline:none; transition:border-color 0.15s; box-sizing:border-box;
}
.f-input:focus, .f-select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.08); }
.f-hint { font-size:11px; color:var(--text-3); margin-top:4px; }
.f-select { appearance:none; -webkit-appearance:none; cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 10px center; padding-right:30px;
}

/* Modal buttons */
.m-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all 0.15s; border:1px solid; }
.m-btn.ghost   { background:#fff; color:var(--text-2); border-color:var(--border); }
.m-btn.ghost:hover { background:var(--bg); }
.m-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.m-btn.primary:hover { background:#1d4ed8; }
.m-btn.warn    { background:#fffbeb; color:#92400e; border-color:#fcd34d; }
.m-btn.warn:hover { background:#fef3c7; }
.m-btn.danger  { background:#dc2626; color:#fff; border-color:#dc2626; }
.m-btn.danger:hover { background:#b91c1c; }
.m-btn.info-btn { background:#eff4ff; color:var(--accent); border-color:#c7d7f8; }
.m-btn.info-btn:hover { background:#dbeafe; }

/* View modal info grid */
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--r-md); overflow:hidden; margin-bottom:20px; }
.info-cell { padding:11px 14px; border-bottom:1px solid var(--border-soft); }
.info-cell:nth-last-child(-n+2) { border-bottom:none; }
.info-key { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-3); margin-bottom:3px; }
.info-val { font-size:13.5px; color:var(--text); font-weight:500; }
.info-val.sid-val { font-family:monospace; color:var(--accent); letter-spacing:0.5px; }

/* View modal section label */
.sec-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-3); margin-bottom:10px; }

/* Violation mini-table */
.viol-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.viol-table th { padding:8px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-3); background:var(--bg); border-bottom:1px solid var(--border); }
.viol-table td { padding:9px 10px; border-bottom:1px solid var(--border-soft); color:var(--text-2); vertical-align:middle; }
.viol-table tr:last-child td { border-bottom:none; }
.viol-table tbody tr:hover { background:#fafbfc; }

/* Mini badge */
.mini-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:20px; font-size:10.5px; font-weight:600; }
.mini-badge.minor  { background:#eff4ff; color:#2563eb; }
.mini-badge.major  { background:#fff0f0; color:#dc2626; }
.mini-badge.completed { background:#f0fdf4; color:#16a34a; }
.mini-badge.ongoing   { background:#fffbeb; color:#d97706; }
.mini-badge.pending   { background:#f4f5f7; color:#6b7280; }
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
  <div class="adm_logo">
    <img src="../assets/images/mccLogo.png" alt="MCC Logo">
    <div class="logotext"><h2>Admin Panel</h2><p>MCC Student Violation System</p></div>
  </div>
  <div class="sidebar_content">
    <ul>
      <h3>Overview</h3>
      <li><a href="dashboard.php"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></li>
      <h3>Students</h3>
      <li><a href="student-records.php" class="active"><i class="fas fa-user-graduate"></i><span>Student Records</span></a></li>
      <li><a href="violation-records.php"><i class="fas fa-history"></i><span>Violation Records</span></a></li>
      <li><a href="student-appeals.php"><i class="fa-solid fa-pen"></i><span>Student Appeals</span></a></li>
      <h3>Discipline</h3>
      <li><a href="disciplinary-action.php"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
      <li><a href="risk-level.php"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>
      <h3>Backup &amp; Reports</h3>
      <li><a href="data-backup.php"><i class="fas fa-cloud-download-alt"></i><span>Data Backup</span></a></li>
      <h3>System</h3>
      <li><a href="user-management.php"><i class="fas fa-user-cog"></i><span>User Management</span></a></li>
      <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a></li>
    </ul>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <!-- NAVBAR -->
  <nav class="navbar">
    <h1>Student Records</h1>
    <div class="navbar-right">
      <div class="dropdown bell-wrap">
        <button class="bell-btn" id="bellBtn" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <?php if($unreadCount>0): ?><span class="notif-badge" id="notifBadge"><?= $unreadCount ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">
          <div class="notif-head">
            <div class="notif-head-left">
              <span>Notifications</span>
              <span class="unread-pill <?= $unreadCount>0?'visible':'' ?>" id="notifPill"><?= $unreadCount ?> new</span>
            </div>
            <button class="mark-all-btn" onclick="markAllRead()">Mark all read</button>
          </div>
          <div class="notif-scroll" id="notifList">
            <?php if(empty($notifList)): ?>
            <div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You're all caught up</p></div>
            <?php else: foreach($notifList as $n): ?>
            <a class="notif-item <?= !$n['is_read']?'unread':'' ?>" href="<?= htmlspecialchars($n['link']??'#') ?>" onclick="markRead(<?= $n['id'] ?>,this);return true;">
              <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>
              <div class="notif-body-text">
                <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                <div class="notif-ts"><i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i><?= date('M d, Y · h:i A',strtotime($n['created_at'])) ?></div>
              </div>
              <?php if(!$n['is_read']): ?><div class="notif-dot-ind"></div><?php endif; ?>
            </a>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <div class="user-chip">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
        <span class="user-name"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
      </div>
    </div>
  </nav>

  <!-- CONTENT -->
  <div class="content">

    <?php if(isset($_SESSION['msg'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i><?= $_SESSION['msg'] ?><?php unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <?php if(isset($_SESSION['err'])): ?>
    <div class="alert-banner error"><i class="fas fa-exclamation-circle"></i><?= $_SESSION['err'] ?><?php unset($_SESSION['err']); ?></div>
    <?php endif; ?>

    <!-- Page top -->
    <div class="page-top">
      
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addStudentModal">
        <i class="fas fa-plus" style="font-size:11px;"></i> Add Student
      </button>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <input type="text" name="search" class="filter-input" placeholder="Search by ID or name…" value="<?= htmlspecialchars($search) ?>">
      <select name="dept" class="filter-select">
        <option value="">All Departments</option>
        <?php foreach($departments as $d): ?><option value="<?=$d['id']?>" <?=$filterDept==$d['id']?'selected':''?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="course" class="filter-select">
        <option value="">All Courses</option>
        <?php foreach($courses as $c): ?><option value="<?=$c['id']?>" <?=$filterCourse==$c['id']?'selected':''?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="year_level" class="filter-select">
        <option value="">All Years</option>
        <?php for($i=1;$i<=4;$i++): ?><option value="<?=$i?>" <?=$filterYear==$i?'selected':''?>>Year <?=$i?></option><?php endfor; ?>
      </select>
      <button type="submit" class="filter-btn primary"><i class="fas fa-search" style="font-size:11px;"></i> Search</button>
      <a href="student-records.php" class="filter-btn secondary">Reset</a>
    </form>

    <!-- Table -->
    <div class="count-badge">Showing <strong><?= count($students) ?></strong> student<?= count($students)!==1?'s':'' ?></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Full Name</th>
            <th>Department</th>
            <th>Course</th>
            <th>Year</th>
            <th>Risk Level</th>
            <th style="width:80px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($students)): ?>
          <tr class="empty-row"><td colspan="7"><i class="fas fa-users" style="font-size:20px;display:block;margin-bottom:8px;"></i>No students found</td></tr>
          <?php else: foreach($students as $st): $risk=getRiskLevel($st['total_violations']); ?>
          <tr>
            <td class="sid"><?= htmlspecialchars($st['student_id']) ?></td>
            <td class="name"><?= htmlspecialchars($st['full_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($st['dept_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($st['course_name']) ?></td>
            <td class="muted">Year <?= $st['year_level'] ?></td>
            <td>
              <span class="risk-badge" style="background:<?= $risk['bg'] ?>;color:<?= $risk['color'] ?>;">
                <span class="dot" style="background:<?= $risk['color'] ?>;"></span>
                <?= $risk['label'] ?>
              </span>
            </td>
            <td><button class="view-btn" onclick='viewStudent(<?= json_encode($st) ?>)'><i class="fas fa-eye" style="font-size:11px;"></i> View</button></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── ADD STUDENT MODAL ── -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="add">
      <div class="modal-header">
        <span class="modal-title">Add New Student</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group">
          <label class="f-label">Student ID</label>
          <input type="text" name="student_id" id="addSID" class="f-input" maxlength="9" placeholder="000-00000" required>
          <div class="f-hint">Format: 000-00000 — last 5 digits become default password</div>
        </div>
        <div class="f-group">
          <label class="f-label">Full Name</label>
          <input type="text" name="full_name" class="f-input" placeholder="Last, First Middle" required>
        </div>
        <div class="f-group">
          <label class="f-label">Department</label>
          <select name="department_id" id="addDept" class="f-select" required onchange="filterCoursesByDept(this.value,'addCourse')">
            <option value="">Select department…</option>
            <?php foreach($departments as $d): ?><option value="<?=$d['id']?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="f-group">
          <label class="f-label">Course</label>
          <select name="course_id" id="addCourse" class="f-select" required>
            <option value="">Select department first</option>
          </select>
        </div>
        <div class="f-group">
          <label class="f-label">Year Level</label>
          <select name="year_level" class="f-select" required>
            <?php for($i=1;$i<=4;$i++): ?><option value="<?=$i?>">Year <?=$i?></option><?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn primary"><i class="fas fa-plus" style="font-size:11px;"></i> Save Student</button>
      </div>
    </form>
  </div>
</div>

<!-- ── VIEW STUDENT MODAL ── -->
<div class="modal fade" id="viewStudentModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title">Student Profile</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewModalBody" style="padding:20px 24px;">
        <div style="text-align:center;padding:30px;color:var(--text-3);">Loading…</div>
      </div>
      <div class="modal-footer">
        <button class="m-btn info-btn" id="editBtnModal"><i class="fas fa-pencil-alt" style="font-size:11px;"></i> Edit</button>
        <button class="m-btn danger"   id="deleteBtnModal"><i class="fas fa-trash" style="font-size:11px;"></i> Delete</button>
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── EDIT STUDENT MODAL ── -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="student_id" id="editSID">
      <div class="modal-header">
        <span class="modal-title">Edit Student</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group">
          <label class="f-label">Full Name</label>
          <input type="text" name="full_name" id="editName" class="f-input" required>
        </div>
        <div class="f-group">
          <label class="f-label">Department</label>
          <select name="department_id" id="editDept" class="f-select" required onchange="filterCoursesByDept(this.value,'editCourse')">
            <?php foreach($departments as $d): ?><option value="<?=$d['id']?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="f-group">
          <label class="f-label">Course</label>
          <select name="course_id" id="editCourse" class="f-select" required>
            <?php foreach($courses as $c): ?><option value="<?=$c['id']?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="f-group">
          <label class="f-label">Year Level</label>
          <select name="year_level" id="editYear" class="f-select" required>
            <?php for($i=1;$i<=4;$i++): ?><option value="<?=$i?>">Year <?=$i?></option><?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn warn"><i class="fas fa-save" style="font-size:11px;"></i> Update</button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE STUDENT MODAL ── -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="student_id" id="deleteSID">
      <div class="modal-header danger-head">
        <span class="modal-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;font-size:14px;"></i>Delete Student</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-2);margin-bottom:16px;line-height:1.6;">This will permanently remove the student and their user account. This action cannot be undone.</p>
        <div class="f-group" style="margin:0;">
          <label class="f-label">Deletion Password</label>
          <input type="password" name="del_password" class="f-input" placeholder="Enter deletion password…" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn danger"><i class="fas fa-trash" style="font-size:11px;"></i> Confirm Delete</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const allCourses = <?= json_encode($courses) ?>;

// Student ID auto-format
document.getElementById('addSID').addEventListener('input', function(){
    let v = this.value.replace(/[^0-9]/g,'');
    if(v.length > 3) v = v.substring(0,3) + '-' + v.substring(3,8);
    this.value = v;
});

function filterCoursesByDept(deptId, selectId){
    const sel = document.getElementById(selectId);
    sel.innerHTML = '<option value="">Select course…</option>';
    allCourses.filter(c => c.department_id == deptId).forEach(c => {
        sel.innerHTML += `<option value="${c.id}">${c.name}</option>`;
    });
}

function getRiskBadge(totalViolations){
    const t = parseInt(totalViolations)||0;
    if(t>=5) return {label:'Critical',color:'#dc2626',bg:'#fff0f0'};
    if(t>=4) return {label:'High',    color:'#d97706',bg:'#fffbeb'};
    if(t>=2) return {label:'Moderate',color:'#2563eb',bg:'#eff4ff'};
    return            {label:'Low',   color:'#16a34a',bg:'#f0fdf4'};
}

function viewStudent(st){
    const modal = new bootstrap.Modal(document.getElementById('viewStudentModal'));
    document.getElementById('viewModalBody').innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-3);">Loading…</div>';
    modal.show();

    fetch('ajax/student-violations.php?student_id=' + encodeURIComponent(st.student_id))
        .then(r => r.json()).then(viols => {
            const risk = getRiskBadge(st.total_violations);
            const vRows = viols.length === 0
                ? '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-3);font-size:12.5px;">No violations recorded.</td></tr>'
                : viols.map(v => `
                    <tr>
                      <td>${v.date_submitted}</td>
                      <td><span class="mini-badge ${v.category}">${v.category}</span></td>
                      <td style="font-weight:500;">${v.violation}</td>
                      <td style="color:var(--text-3);">${v.description||'—'}</td>
                      <td><span class="mini-badge ${v.status}">${v.status}</span></td>
                      <td style="color:var(--text-3);">${v.sanction||'—'}</td>
                    </tr>`).join('');

            document.getElementById('viewModalBody').innerHTML = `
                <div class="info-grid">
                  <div class="info-cell">
                    <div class="info-key">Student ID</div>
                    <div class="info-val sid-val">${st.student_id}</div>
                  </div>
                  <div class="info-cell">
                    <div class="info-key">Full Name</div>
                    <div class="info-val">${st.full_name}</div>
                  </div>
                  <div class="info-cell">
                    <div class="info-key">Department</div>
                    <div class="info-val">${st.dept_name}</div>
                  </div>
                  <div class="info-cell">
                    <div class="info-key">Course</div>
                    <div class="info-val">${st.course_name}</div>
                  </div>
                  <div class="info-cell">
                    <div class="info-key">Year Level</div>
                    <div class="info-val">Year ${st.year_level}</div>
                  </div>
                  <div class="info-cell">
                    <div class="info-key">Risk Level</div>
                    <div class="info-val">
                      <span class="risk-badge" style="background:${risk.bg};color:${risk.color};">
                        <span class="dot" style="background:${risk.color};"></span>
                        ${risk.label}
                      </span>
                    </div>
                  </div>
                </div>
                <div class="sec-lbl">Violation Records</div>
                <div style="border:1px solid var(--border);border-radius:var(--r-md);overflow:hidden;">
                  <table class="viol-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Violation</th><th>Description</th><th>Status</th><th>Sanction</th></tr></thead>
                    <tbody>${vRows}</tbody>
                  </table>
                </div>`;

            document.getElementById('editBtnModal').onclick   = () => openEdit(st);
            document.getElementById('deleteBtnModal').onclick = () => openDelete(st.student_id);
        });
}

function openEdit(st){
    bootstrap.Modal.getInstance(document.getElementById('viewStudentModal'))?.hide();
    document.getElementById('editSID').value  = st.student_id;
    document.getElementById('editName').value = st.full_name;
    document.getElementById('editDept').value = st.department_id;
    document.getElementById('editYear').value = st.year_level;
    filterCoursesByDept(st.department_id, 'editCourse');
    setTimeout(() => document.getElementById('editCourse').value = st.course_id, 100);
    setTimeout(() => new bootstrap.Modal(document.getElementById('editStudentModal')).show(), 450);
}

function openDelete(sid){
    bootstrap.Modal.getInstance(document.getElementById('viewStudentModal'))?.hide();
    document.getElementById('deleteSID').value = sid;
    setTimeout(() => new bootstrap.Modal(document.getElementById('deleteStudentModal')).show(), 450);
}

// ── NOTIFICATIONS ──
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s){ const d=new Date(s); return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' · '+d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }

function renderNotifs(data){
    const badge  = document.getElementById('notifBadge');
    const pill   = document.getElementById('notifPill');
    const listEl = document.getElementById('notifList');
    const btn    = document.getElementById('bellBtn');
    const isOpen = btn?.closest('.dropdown')?.classList.contains('show');

    if(data.unread > 0){
        if(badge){ badge.textContent=data.unread; badge.style.display=''; }
        else if(btn){ const nb=document.createElement('span'); nb.id='notifBadge'; nb.className='notif-badge'; nb.textContent=data.unread; btn.appendChild(nb); }
        if(pill){ pill.textContent=data.unread+' new'; pill.classList.add('visible'); }
    } else {
        if(badge) badge.style.display='none';
        if(pill)  pill.classList.remove('visible');
    }
    if(!listEl||isOpen) return;
    if(!data.notifications.length){ listEl.innerHTML='<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>You\'re all caught up</p></div>'; return; }
    listEl.innerHTML = data.notifications.map(n=>`
        <a class="notif-item${n.is_read==0?' unread':''}" href="${escHtml(n.link||'#')}" onclick="markRead(${n.id},this);return true;">
          <div class="notif-avatar"><i class="fas fa-exclamation"></i></div>
          <div class="notif-body-text">
            <div class="notif-msg">${escHtml(n.message)}</div>
            <div class="notif-ts"><i class="fas fa-clock" style="margin-right:3px;font-size:9px;"></i>${fmtDate(n.created_at)}</div>
          </div>
          ${n.is_read==0?'<div class="notif-dot-ind"></div>':''}
        </a>`).join('');
}
function markRead(id,el){
    fetch('ajax/notifications.php?action=read&id='+id);
    el.classList.remove('unread'); el.querySelector('.notif-dot-ind')?.remove();
    const badge=document.getElementById('notifBadge'), pill=document.getElementById('notifPill');
    if(badge){ const n=parseInt(badge.textContent)-1; n<=0?badge.style.display='none':badge.textContent=n; }
    if(pill) { const n=parseInt(pill.textContent)-1;  n<=0?pill.classList.remove('visible'):pill.textContent=n+' new'; }
}
function markAllRead(){
    fetch('ajax/notifications.php?action=read_all');
    document.querySelectorAll('#notifList .notif-item').forEach(el=>{ el.classList.remove('unread'); el.querySelector('.notif-dot-ind')?.remove(); });
    document.getElementById('notifBadge')?.style && (document.getElementById('notifBadge').style.display='none');
    document.getElementById('notifPill')?.classList.remove('visible');
}
document.addEventListener('DOMContentLoaded', () => {
    fetch('ajax/notifications.php?action=poll').then(r=>r.ok?r.json():null).then(d=>{ if(d&&!d.error) renderNotifs(d); });
    setInterval(()=>{ fetch('ajax/notifications.php?action=poll').then(r=>r.ok?r.json():null).then(d=>{ if(d&&!d.error) renderNotifs(d); }); }, 15000);
});
</script>
</body>
</html>