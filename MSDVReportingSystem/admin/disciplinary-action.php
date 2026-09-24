<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();

if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $daId=$_POST['da_id']; $newStatus=$_POST['new_status'];
    $flow=['pending'=>0,'ongoing'=>1,'completed'=>2];
    $curr=$pdo->prepare("SELECT * FROM disciplinary_actions WHERE id=?");
    $curr->execute([$daId]); $da=$curr->fetch();
    if($da){
        if(($flow[$newStatus]??-1)<=($flow[$da['status']]??0)){$_SESSION['err']='Cannot move status backwards.';}
        else{
            $startDate=$da['start_date']; $endDate=$da['end_date'];
            if($newStatus==='ongoing'&&!$startDate)$startDate=date('Y-m-d');
            if($newStatus==='completed'&&!$endDate)$endDate=date('Y-m-d');
            $pdo->prepare("UPDATE disciplinary_actions SET status=?,start_date=?,end_date=? WHERE id=?")->execute([$newStatus,$startDate,$endDate,$daId]);
            $pdo->prepare("UPDATE violations SET status=? WHERE id=?")->execute([$newStatus,$da['violation_id']]);
            $stuUser=$pdo->prepare("SELECT u.id FROM students s JOIN users u ON s.user_id=u.id WHERE s.student_id=?");
            $stuUser->execute([$da['student_id']]); $stuRow=$stuUser->fetch();
            if($stuRow){$pdo->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,?)")->execute([$stuRow['id'],"Your disciplinary case status has been updated to: ".strtoupper($newStatus),'../student/dashboard.php']);}
            $_SESSION['msg']='Status updated to '.ucfirst($newStatus).'.';
        }
    }
    header('Location: disciplinary-action.php'); exit;
}

$search=trim($_GET['search']??''); $filterStatus=$_GET['status']??'';
$where="WHERE 1=1"; $params=[];
if($search){$where.=" AND (s.full_name LIKE ? OR da.student_id LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($filterStatus){$where.=" AND da.status=?";$params[]=$filterStatus;}
$stmt=$pdo->prepare("SELECT da.*,s.full_name,v.violation,v.category FROM disciplinary_actions da JOIN students s ON da.student_id=s.student_id JOIN violations v ON da.violation_id=v.id $where ORDER BY da.id DESC");
$stmt->execute($params); $daList=$stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Disciplinary Actions</title>
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
.page-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.page-top h2 { font-size:22px; font-weight:600; color:var(--text); letter-spacing:-0.4px; margin:0 0 3px; }
.page-top p  { font-size:13px; color:var(--text-3); margin:0; }

/* Alert banners */
.alert-banner { border-radius:var(--r-md); padding:12px 16px; font-size:13px; font-weight:500; margin-bottom:16px; display:flex; align-items:center; gap:10px; border:none; }
.alert-banner.success { background:#f0fdf4; color:#15803d; }
.alert-banner.error   { background:#fff0f0; color:#dc2626; }
.alert-banner i { flex-shrink:0; }

/* Filter bar */
.filter-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); padding:16px 20px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; position:sticky; top:0; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
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

/* Mini badge */
.mini-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:20px; font-size:10.5px; font-weight:600; }
.mini-badge.minor     { background:#eff4ff; color:#2563eb; }
.mini-badge.major     { background:#fff0f0; color:#dc2626; }
.mini-badge.completed { background:#f0fdf4; color:#16a34a; }
.mini-badge.ongoing   { background:#fffbeb; color:#d97706; }
.mini-badge.pending   { background:#f4f5f7; color:#6b7280; }

/* Action buttons */
.view-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--r-sm); font-size:12px; font-weight:500; border:1px solid var(--border); background:#fff; color:var(--text-2); cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; }
.view-btn:hover { background:var(--accent-light); color:var(--accent); border-color:#c7d7f8; }
.update-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--r-sm); font-size:12px; font-weight:500; border:1px solid #c7d7f8; background:var(--accent-light); color:var(--accent); cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; }
.update-btn:hover { background:#dbeafe; border-color:#93c5fd; }
.done-txt { font-size:12px; color:var(--text-3); font-style:italic; }

/* ── MODALS ── */
.modal-content { border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 16px 48px rgba(0,0,0,0.12); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; }
.modal-header { padding:20px 24px 16px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.modal-header .modal-title { font-size:16px; font-weight:600; color:var(--text); letter-spacing:-0.2px; }
.modal-header .btn-close { opacity:0.4; }
.modal-header .btn-close:hover { opacity:0.7; }
.modal-body { padding:20px 24px; }
.modal-footer { padding:14px 24px; border-top:1px solid var(--border-soft); display:flex; justify-content:flex-end; gap:8px; background:#fafbfc; }

/* Form elements inside modals */
.f-group { margin-bottom:16px; }
.f-group:last-child { margin-bottom:0; }
.f-label { display:block; font-size:12px; font-weight:600; color:var(--text-2); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
.f-select { width:100%; padding:9px 12px; border-radius:var(--r-sm); border:1px solid var(--border); background:#fff; color:var(--text); font-size:13.5px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; transition:border-color 0.15s; box-sizing:border-box; appearance:none; -webkit-appearance:none; cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 10px center; padding-right:30px;
}
.f-select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.08); }

/* Modal buttons */
.m-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all 0.15s; border:1px solid; }
.m-btn.ghost   { background:#fff; color:var(--text-2); border-color:var(--border); }
.m-btn.ghost:hover { background:var(--bg); }
.m-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.m-btn.primary:hover { background:#1d4ed8; }

/* Info grid inside modal */
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--r-md); overflow:hidden; margin-bottom:20px; }
.info-cell { padding:11px 14px; border-bottom:1px solid var(--border-soft); }
.info-cell.full { grid-column:span 2; }
.info-cell:nth-last-child(-n+2) { border-bottom:none; }
.info-cell.full:last-child { border-bottom:none; }
.info-key { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-3); margin-bottom:3px; }
.info-val { font-size:13.5px; color:var(--text); font-weight:500; }
.info-val.sid-val { font-family:monospace; color:var(--accent); letter-spacing:0.5px; }
.info-val.sanction-val { font-weight:400; color:var(--text-2); font-size:13px; line-height:1.5; }
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
      <li><a href="disciplinary-action.php" class="active"><i class="fas fa-gavel"></i><span>Disciplinary Actions</span></a></li>
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
    <h1>Disciplinary Actions</h1>
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
    

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <input type="text" name="search" class="filter-input" placeholder="Search by name or ID…" value="<?= htmlspecialchars($search) ?>">
      <select name="status" class="filter-select">
        <option value="">All Status</option>
        <option value="pending"   <?=$filterStatus==='pending'  ?'selected':''?>>Pending</option>
        <option value="ongoing"   <?=$filterStatus==='ongoing'  ?'selected':''?>>Ongoing</option>
        <option value="completed" <?=$filterStatus==='completed'?'selected':''?>>Completed</option>
      </select>
      <button type="submit" class="filter-btn primary"><i class="fas fa-search" style="font-size:11px;"></i> Search</button>
      <a href="disciplinary-action.php" class="filter-btn secondary">Reset</a>
    </form>

    <!-- Count -->
    <div class="count-badge">Showing <strong><?= count($daList) ?></strong> record<?= count($daList)!==1?'s':'' ?></div>

    <!-- Table -->
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Full Name</th>
            <th>Violation</th>
            <th>Sanction</th>
            <th>Status</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th style="width:100px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($daList)): ?>
          <tr class="empty-row"><td colspan="8"><i class="fas fa-gavel" style="font-size:20px;display:block;margin-bottom:8px;"></i>No records found</td></tr>
          <?php else: foreach($daList as $da): ?>
          <tr>
            <td class="sid"><?= htmlspecialchars($da['student_id']) ?></td>
            <td class="name"><?= htmlspecialchars($da['full_name']) ?></td>
            <td>
              <span class="mini-badge <?= $da['category'] ?>"><?= ucfirst($da['category']) ?></span>
              <span class="muted" style="margin-left:6px;font-size:12.5px;color:var(--text-2);"><?= htmlspecialchars($da['violation']) ?></span>
            </td>
            <td class="muted" style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($da['sanction']) ?></td>
            <td><span class="mini-badge <?= $da['status'] ?>"><?= ucfirst($da['status']) ?></span></td>
            <td class="muted"><?= $da['start_date'] ? date('M d, Y', strtotime($da['start_date'])) : '—' ?></td>
            <td class="muted"><?= $da['end_date']   ? date('M d, Y', strtotime($da['end_date']))   : '—' ?></td>
            <td>
              <?php if($da['status']!=='completed'): ?>
              <button class="update-btn" onclick='openUpdateStatus(<?= json_encode($da) ?>)'>
                <i class="fas fa-sync-alt" style="font-size:10px;"></i> Update
              </button>
              <?php else: ?>
              <span class="done-txt"><i class="fas fa-check-double" style="margin-right:4px;color:#16a34a;"></i>Done</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── UPDATE STATUS MODAL ── -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="da_id" id="daID">
      <div class="modal-header">
        <span class="modal-title">Update Disciplinary Status</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="info-grid" style="margin-bottom:20px;">
          <div class="info-cell">
            <div class="info-key">Student ID</div>
            <div class="info-val sid-val" id="daStudentID"></div>
          </div>
          <div class="info-cell">
            <div class="info-key">Full Name</div>
            <div class="info-val" id="daStudentName"></div>
          </div>
          <div class="info-cell">
            <div class="info-key">Category</div>
            <div class="info-val" id="daCategory"></div>
          </div>
          <div class="info-cell">
            <div class="info-key">Current Status</div>
            <div class="info-val" id="daCurrentStatus"></div>
          </div>
          <div class="info-cell full">
            <div class="info-key">Violation</div>
            <div class="info-val" id="daViolation"></div>
          </div>
          <div class="info-cell full" style="border-bottom:none;">
            <div class="info-key">Sanction</div>
            <div class="info-val sanction-val" id="daSanction"></div>
          </div>
        </div>
        <div class="f-group" style="margin:0;">
          <label class="f-label">Move Status To</label>
          <select name="new_status" id="daNewStatus" class="f-select"></select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="m-btn primary"><i class="fas fa-sync-alt" style="font-size:11px;"></i> Update Status</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openUpdateStatus(da){
    document.getElementById('daID').value          = da.id;
    document.getElementById('daStudentID').textContent  = da.student_id;
    document.getElementById('daStudentName').textContent = da.full_name;
    document.getElementById('daViolation').textContent  = da.violation;
    document.getElementById('daSanction').textContent   = da.sanction;

    // Category badge
    const catEl = document.getElementById('daCategory');
    catEl.innerHTML = `<span class="mini-badge ${da.category}">${da.category.charAt(0).toUpperCase()+da.category.slice(1)}</span>`;

    // Current status badge
    const stEl = document.getElementById('daCurrentStatus');
    stEl.innerHTML = `<span class="mini-badge ${da.status}">${da.status.charAt(0).toUpperCase()+da.status.slice(1)}</span>`;

    // Build forward-only status options
    const flow = {pending:0, ongoing:1, completed:2};
    const curr = flow[da.status] ?? 0;
    const sel  = document.getElementById('daNewStatus');
    sel.innerHTML = '';
    Object.entries(flow).forEach(([s,v]) => {
        if(v > curr) sel.innerHTML += `<option value="${s}">${s.charAt(0).toUpperCase()+s.slice(1)}</option>`;
    });

    new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
}

/* ── NOTIFICATIONS ── */
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