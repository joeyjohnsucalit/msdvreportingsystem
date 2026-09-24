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

if(isset($_POST['action'])&&$_POST['action']==='add_user'){
    $full=trim($_POST['full_name']);$uname=trim($_POST['username']);$email=trim($_POST['email']);$pass=$_POST['password'];$pass2=$_POST['confirm_password'];$role=$_POST['role'];
    if($pass!==$pass2){$_SESSION['err']='Passwords do not match.';}
    else{try{$pdo->prepare("INSERT INTO users (full_name,username,email,password,role,is_first_login) VALUES (?,?,?,?,?,1)")->execute([$full,$uname,$email,password_hash($pass,PASSWORD_DEFAULT),$role]);$_SESSION['msg']='User added successfully.';}catch(Exception $e){$_SESSION['err']='Username already exists.';}}
    header('Location: user-management.php');exit;
}
if(isset($_POST['action'])&&$_POST['action']==='edit_user'){
    $uid=(int)$_POST['user_id'];$full=trim($_POST['full_name']);$uname=trim($_POST['username']);$email=trim($_POST['email']);$role=$_POST['role'];
    $pdo->prepare("UPDATE users SET full_name=?,username=?,email=?,role=? WHERE id=?")->execute([$full,$uname,$email,$role,$uid]);
    $_SESSION['msg']='User updated.';header('Location: user-management.php');exit;
}
if(isset($_POST['action'])&&$_POST['action']==='change_password'){
    $uid=(int)$_POST['user_id'];$pass=$_POST['new_password'];$pass2=$_POST['confirm_new_password'];
    if($pass!==$pass2){$_SESSION['err']='Passwords do not match.';}
    else{$pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pass,PASSWORD_DEFAULT),$uid]);$_SESSION['msg']='Password changed successfully.';}
    header('Location: user-management.php');exit;
}
if(isset($_POST['action'])&&$_POST['action']==='delete_user'){
    $uid=(int)$_POST['user_id'];$pass=trim($_POST['del_password']);
    if($pass===DELETE_PASSWORD){$pdo->prepare("DELETE FROM users WHERE id=? AND role NOT IN ('admin','student')")->execute([$uid]);$_SESSION['msg']='User deleted.';}
    else{$_SESSION['err']='Incorrect deletion password.';}
    header('Location: user-management.php');exit;
}

$searchStaff=trim($_GET['search_staff']??'');$filterRole=$_GET['role']??'';
$whereStaff="WHERE role NOT IN ('admin','student')";$paramsStaff=[];
if($searchStaff){$whereStaff.=" AND (full_name LIKE ? OR username LIKE ?)";$paramsStaff[]="%$searchStaff%";$paramsStaff[]="%$searchStaff%";}
if($filterRole&&$filterRole!=='student'){$whereStaff.=" AND role=?";$paramsStaff[]=$filterRole;}
$staffStmt=$pdo->prepare("SELECT * FROM users $whereStaff ORDER BY role,full_name");$staffStmt->execute($paramsStaff);$staffUsers=$staffStmt->fetchAll();

$searchStu=trim($_GET['search_stu']??'');$whereStu="WHERE u.role='student'";$paramsStu=[];
if($searchStu){$whereStu.=" AND (u.full_name LIKE ? OR u.username LIKE ? OR s.student_id LIKE ?)";$paramsStu[]="%$searchStu%";$paramsStu[]="%$searchStu%";$paramsStu[]="%$searchStu%";}
$stuStmt=$pdo->prepare("SELECT u.*,s.student_id as sid FROM users u LEFT JOIN students s ON s.user_id=u.id $whereStu ORDER BY u.full_name");$stuStmt->execute($paramsStu);$studentUsers=$stuStmt->fetchAll();

$activeTab=$_GET['tab']??'staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | User Management</title>
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

/* ── SIDEBAR ── */
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

/* ── NAVBAR ── */
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
.btn-add { display:inline-flex; align-items:center; gap:7px; background:var(--accent); color:#fff; border:none; padding:9px 18px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:background 0.15s, transform 0.1s; white-space:nowrap; }
.btn-add:hover { background:#1d4ed8; color:#fff; }
.btn-add:active { transform:scale(0.98); }

/* Alert banners */
.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:16px; display:flex; align-items:center; gap:10px; border:none; }
.alert-banner.success { background:#f0fdf4; color:#15803d; }
.alert-banner.error   { background:#fff0f0; color:#dc2626; }
.alert-banner i { flex-shrink:0; }

/* ── TABS ── */
.tab-bar { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:20px; }
.tab-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 16px; font-size:13px; font-weight:500; color:var(--text-2); background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; border-radius:var(--r-sm) var(--r-sm) 0 0; }
.tab-btn:hover { color:var(--text); background:var(--border-soft); }
.tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); background:var(--accent-light); }
.tab-pill { background:var(--border-soft); color:var(--text-3); font-size:10px; font-weight:700; padding:1px 7px; border-radius:20px; }
.tab-btn.active .tab-pill { background:#dbeafe; color:var(--accent); }

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

/* Info banner */
.info-banner { background:#eff4ff; border:1px solid #c7d7f8; border-radius:var(--r-md); padding:11px 16px; font-size:12.5px; color:#2563eb; display:flex; align-items:center; gap:8px; margin-bottom:16px; }
.info-banner a { color:var(--accent); font-weight:600; }

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

/* Role badge */
.role-badge { display:inline-flex; align-items:center; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.role-badge.teacher { background:#eff4ff; color:#2563eb; }
.role-badge.csu     { background:#f0fdf4; color:#16a34a; }
.role-badge.jassu   { background:#fffbeb; color:#d97706; }
.role-badge.student { background:#f4f5f7; color:#374151; }

/* Action buttons */
.act-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 11px; border-radius:var(--r-sm); font-size:12px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; border:1px solid; transition:all 0.15s; white-space:nowrap; }
.act-btn.edit   { background:#fffbeb; color:#92400e; border-color:#fcd34d; }
.act-btn.edit:hover   { background:#fef3c7; }
.act-btn.pass   { background:#eff4ff; color:var(--accent); border-color:#c7d7f8; }
.act-btn.pass:hover   { background:#dbeafe; }
.act-btn.danger { background:#fff0f0; color:#dc2626; border-color:#fecaca; }
.act-btn.danger:hover { background:#fee2e2; }

/* ── MODALS ── */
.modal-content { border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 16px 48px rgba(0,0,0,0.12); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; }
.modal-header { padding:20px 24px 16px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.modal-header .modal-title { font-size:16px; font-weight:600; color:var(--text); letter-spacing:-0.2px; }
.modal-header.danger-head { background:#fff0f0; }
.modal-header.danger-head .modal-title { color:#dc2626; }
.modal-header .btn-close { opacity:0.4; }
.modal-header .btn-close:hover { opacity:0.7; }
.modal-body { padding:20px 24px; }
.modal-footer { padding:14px 24px; border-top:1px solid var(--border-soft); display:flex; justify-content:flex-end; gap:8px; background:#fafbfc; }

/* Form elements */
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
      <li><a href="student-records.php"><i class="fas fa-user-graduate"></i><span>Student Records</span></a></li>
      <li><a href="violation-records.php"><i class="fas fa-history"></i><span>Violation Records</span></a></li>
      <li><a href="student-appeals.php"><i class="fa-solid fa-pen"></i><span>Student Appeals</span></a></li>
      <h3>Discipline</h3>
      <li><a href="disciplinary-action.php"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
      <li><a href="risk-level.php"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>
      <h3>Backup &amp; Reports</h3>
      <li><a href="data-backup.php"><i class="fas fa-cloud-download-alt"></i><span>Data Backup</span></a></li>
      <h3>System</h3>
      <li><a href="user-management.php" class="active"><i class="fas fa-user-cog"></i><span>User Management</span></a></li>
      <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a></li>
    </ul>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <!-- NAVBAR -->
  <nav class="navbar">
    <h1>User Management</h1>
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
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fas fa-plus" style="font-size:11px;"></i> Add Staff User
      </button>
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <button class="tab-btn <?= $activeTab==='staff'?'active':'' ?>" id="staffTabBtn" onclick="switchTab('staff')">
        <i class="fas fa-id-badge" style="font-size:12px;"></i> Staff
        <span class="tab-pill"><?= count($staffUsers) ?></span>
      </button>
      <button class="tab-btn <?= $activeTab==='student'?'active':'' ?>" id="studentTabBtn" onclick="switchTab('student')">
        <i class="fas fa-user-graduate" style="font-size:12px;"></i> Student Accounts
        <span class="tab-pill"><?= count($studentUsers) ?></span>
      </button>
    </div>

    <!-- STAFF PANEL -->
    <div id="staffPanel" style="display:<?= $activeTab==='staff'?'block':'none' ?>;">
      <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="staff">
        <input type="text" name="search_staff" class="filter-input" placeholder="Search name or username…" value="<?= htmlspecialchars($searchStaff) ?>">
        <select name="role" class="filter-select">
          <option value="">All Roles</option>
          <option value="teacher" <?=$filterRole==='teacher'?'selected':''?>>Teacher</option>
          <option value="csu"     <?=$filterRole==='csu'    ?'selected':''?>>CSU</option>
          <option value="jassu"   <?=$filterRole==='jassu'  ?'selected':''?>>JASSU</option>
        </select>
        <button type="submit" class="filter-btn primary"><i class="fas fa-search" style="font-size:11px;"></i> Search</button>
        <a href="user-management.php?tab=staff" class="filter-btn secondary">Reset</a>
      </form>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th style="width:200px;"></th></tr>
          </thead>
          <tbody>
            <?php if(empty($staffUsers)): ?>
            <tr class="empty-row"><td colspan="5"><i class="fas fa-users" style="font-size:20px;display:block;margin-bottom:8px;"></i>No staff users found.</td></tr>
            <?php else: foreach($staffUsers as $u): ?>
            <tr>
              <td class="name"><?= htmlspecialchars($u['full_name']) ?></td>
              <td class="muted"><?= htmlspecialchars($u['username']) ?></td>
              <td class="muted"><?= htmlspecialchars($u['email']??'—') ?></td>
              <td><span class="role-badge <?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="act-btn edit"   onclick='openEditUser(<?= json_encode($u) ?>)'><i class="fas fa-pencil-alt" style="font-size:10px;"></i> Edit</button>
                <button class="act-btn pass"   onclick='openChangePass(<?= $u["id"] ?>)'><i class="fas fa-key" style="font-size:10px;"></i> Password</button>
                <button class="act-btn danger" onclick='openDeleteUser(<?= $u["id"] ?>)'><i class="fas fa-trash" style="font-size:10px;"></i> Delete</button>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- STUDENT PANEL -->
    <div id="studentPanel" style="display:<?= $activeTab==='student'?'block':'none' ?>;">
      
      <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="student">
        <input type="text" name="search_stu" class="filter-input" placeholder="Search name, username or student ID…" value="<?= htmlspecialchars($searchStu) ?>">
        <button type="submit" class="filter-btn primary"><i class="fas fa-search" style="font-size:11px;"></i> Search</button>
        <a href="user-management.php?tab=student" class="filter-btn secondary">Reset</a>
      </form>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Student ID</th><th>Full Name</th><th>Username</th><th>Role</th><th style="width:160px;"></th></tr>
          </thead>
          <tbody>
            <?php if(empty($studentUsers)): ?>
            <tr class="empty-row"><td colspan="5"><i class="fas fa-user-graduate" style="font-size:20px;display:block;margin-bottom:8px;"></i>No student accounts found.</td></tr>
            <?php else: foreach($studentUsers as $u): ?>
            <tr>
              <td class="sid"><?= htmlspecialchars($u['sid']??'—') ?></td>
              <td class="name"><?= htmlspecialchars($u['full_name']) ?></td>
              <td class="muted"><?= htmlspecialchars($u['username']) ?></td>
              <td><span class="role-badge student">Student</span></td>
              <td><button class="act-btn pass" onclick='openChangePass(<?= $u["id"] ?>)'><i class="fas fa-key" style="font-size:10px;"></i> Change Password</button></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── ADD USER MODAL ── -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="add_user">
      <div class="modal-header">
        <span class="modal-title">Add Staff User</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group"><label class="f-label">Full Name</label><input type="text" name="full_name" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Username</label><input type="text" name="username" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Email</label><input type="email" name="email" class="f-input"></div>
        <div class="f-group"><label class="f-label">Password</label><input type="password" name="password" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Confirm Password</label><input type="password" name="confirm_password" class="f-input" required></div>
        <div class="f-group">
          <label class="f-label">Role</label>
          <select name="role" class="f-select" required>
            <option value="">Select role…</option>
            <option value="teacher">Teacher</option>
            <option value="csu">CSU</option>
            <option value="jassu">JASSU</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn primary"><i class="fas fa-plus" style="font-size:11px;"></i> Save User</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="user_id" id="editUID">
      <div class="modal-header">
        <span class="modal-title">Edit User</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group"><label class="f-label">Full Name</label><input type="text" name="full_name" id="euName" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Username</label><input type="text" name="username" id="euUsername" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Email</label><input type="email" name="email" id="euEmail" class="f-input"></div>
        <div class="f-group">
          <label class="f-label">Role</label>
          <select name="role" id="euRole" class="f-select">
            <option value="teacher">Teacher</option>
            <option value="csu">CSU</option>
            <option value="jassu">JASSU</option>
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

<!-- ── CHANGE PASSWORD MODAL ── -->
<div class="modal fade" id="changePassModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="cpUID">
      <div class="modal-header">
        <span class="modal-title">Change Password</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="f-group"><label class="f-label">New Password</label><input type="password" name="new_password" class="f-input" required></div>
        <div class="f-group"><label class="f-label">Confirm New Password</label><input type="password" name="confirm_new_password" class="f-input" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn info-btn"><i class="fas fa-key" style="font-size:11px;"></i> Change Password</button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE USER MODAL ── -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="user_id" id="delUID">
      <div class="modal-header danger-head">
        <span class="modal-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;font-size:14px;"></i>Delete Staff User</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-2);margin-bottom:16px;line-height:1.6;">This will permanently remove the staff account. This action cannot be undone.</p>
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
// Tab switching (no Bootstrap tabs — pure JS to stay consistent)
function switchTab(tab){
    document.getElementById('staffPanel').style.display   = tab==='staff'   ? 'block' : 'none';
    document.getElementById('studentPanel').style.display = tab==='student' ? 'block' : 'none';
    document.getElementById('staffTabBtn').classList.toggle('active',   tab==='staff');
    document.getElementById('studentTabBtn').classList.toggle('active', tab==='student');
}
// Restore tab from URL on load
(function(){
    const tab = new URLSearchParams(window.location.search).get('tab');
    if(tab==='student') switchTab('student');
})();

function openEditUser(u){
    document.getElementById('editUID').value     = u.id;
    document.getElementById('euName').value      = u.full_name;
    document.getElementById('euUsername').value  = u.username;
    document.getElementById('euEmail').value     = u.email ?? '';
    document.getElementById('euRole').value      = u.role;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
function openChangePass(uid){ document.getElementById('cpUID').value = uid; new bootstrap.Modal(document.getElementById('changePassModal')).show(); }
function openDeleteUser(uid){ document.getElementById('delUID').value = uid; new bootstrap.Modal(document.getElementById('deleteUserModal')).show(); }

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