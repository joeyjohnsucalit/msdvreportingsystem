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

$allStudents = $pdo->query("SELECT s.student_id,s.full_name,SUM(CASE WHEN v.category='minor' THEN 1 ELSE 0 END) as minor_count,SUM(CASE WHEN v.category='major' THEN 1 ELSE 0 END) as major_count,COUNT(v.id) as total FROM students s LEFT JOIN violations v ON s.student_id=v.student_id GROUP BY s.student_id")->fetchAll();

/* ── POINT SYSTEM: minor = 1 point, major = 2 points ── */
function getScore($minor, $major){
    return ((int)$minor * 1) + ((int)$major * 2);
}

/* 0-2 Low | 3-5 Moderate | 6-8 High | 9+ Critical */
function getRisk($minor, $major){
    $score = getScore($minor, $major);
    if($score >= 9) return ['label'=>'Critical','class'=>'danger', 'color'=>'#dc2626','bg'=>'#fff0f0'];
    if($score >= 6) return ['label'=>'High',    'class'=>'warning','color'=>'#d97706','bg'=>'#fffbeb'];
    if($score >= 3) return ['label'=>'Moderate','class'=>'info',   'color'=>'#2563eb','bg'=>'#eff4ff'];
    return               ['label'=>'Low',     'class'=>'success','color'=>'#16a34a','bg'=>'#f0fdf4'];
}

$moderate=0;$high=0;$critical=0;
foreach($allStudents as $s){
    $r = getRisk($s['minor_count'], $s['major_count']);
    if($r['label']==='Moderate') $moderate++;
    if($r['label']==='High')     $high++;
    if($r['label']==='Critical') $critical++;
}

$search=trim($_GET['search']??'');$filterRisk=$_GET['risk']??'';
$filtered=array_filter($allStudents,function($s)use($search,$filterRisk){
    $r = getRisk($s['minor_count'], $s['major_count']);
    $ms=!$search||stripos($s['full_name'],$search)!==false||stripos($s['student_id'],$search)!==false;
    $mr=!$filterRisk||$r['label']===$filterRisk;
    return $ms&&$mr;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Risk Level Indicator</title>
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

/* Summary cards */
.summary-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
.summary-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); padding:18px 20px; display:flex; align-items:center; gap:14px; }
.summary-icon { width:42px; height:42px; border-radius:var(--r-sm); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.summary-icon.moderate { background:#eff4ff; color:#2563eb; }
.summary-icon.high     { background:#fffbeb; color:#d97706; }
.summary-icon.critical { background:#fff0f0; color:#dc2626; }
.summary-label { font-size:12px; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:3px; }
.summary-value { font-size:26px; font-weight:600; color:var(--text); line-height:1; }
.summary-sub { font-size:11.5px; color:var(--text-3); margin-top:2px; }

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
.empty-row td { padding:40px 16px; text-align:center; color:var(--text-3); font-size:13px; }

/* Risk badge */
.risk-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; }
.risk-badge .dot { width:5px; height:5px; border-radius:50%; }

/* Tally pips */
.tally-wrap { display:flex; gap:4px; align-items:center; }
.pip { width:14px; height:14px; border-radius:3px; flex-shrink:0; }
.pip.minor-filled  { background:#2563eb; }
.pip.minor-empty   { background:transparent; border:2px solid #2563eb; }
.pip.major-filled  { background:#dc2626; }
.pip.major-empty   { background:transparent; border:2px solid #dc2626; }
.pip-extra { font-size:11px; font-weight:600; margin-left:2px; }
.pip-extra.minor { color:#2563eb; }
.pip-extra.major { color:#dc2626; }
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
      <li><a href="risk-level.php" class="active"><i class="fas fa-exclamation-triangle"></i><span>Risk Level Indicator</span></a></li>
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
    <h1>Risk Level Indicator</h1>
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

    <!-- Summary cards -->
    <div class="summary-grid">
      <div class="summary-card">
        <div class="summary-icon moderate"><i class="fas fa-info-circle"></i></div>
        <div>
          <div class="summary-label">Moderate Risk</div>
          <div class="summary-value"><?= $moderate ?></div>
          
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-icon high"><i class="fas fa-exclamation-circle"></i></div>
        <div>
          <div class="summary-label">High Risk</div>
          <div class="summary-value"><?= $high ?></div>
          
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-icon critical"><i class="fas fa-skull-crossbones"></i></div>
        <div>
          <div class="summary-label">Critical</div>
          <div class="summary-value"><?= $critical ?></div>
       
        </div>
      </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <input type="text" name="search" class="filter-input" placeholder="Search by ID or name…" value="<?= htmlspecialchars($search) ?>">
      <select name="risk" class="filter-select">
        <option value="">All Risk Levels</option>
        <option value="Low"      <?=$filterRisk==='Low'     ?'selected':''?>>Low</option>
        <option value="Moderate" <?=$filterRisk==='Moderate'?'selected':''?>>Moderate</option>
        <option value="High"     <?=$filterRisk==='High'    ?'selected':''?>>High</option>
        <option value="Critical" <?=$filterRisk==='Critical'?'selected':''?>>Critical</option>
      </select>
      <button type="submit" class="filter-btn primary"><i class="fas fa-search" style="font-size:11px;"></i> Search</button>
      <a href="risk-level.php" class="filter-btn secondary">Reset</a>
    </form>

    <!-- Count -->
    <div class="count-badge">Showing <strong><?= count($filtered) ?></strong> student<?= count($filtered)!==1?'s':'' ?></div>

    <!-- Table -->
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Full Name</th>
            <th>Minor Tally</th>
            <th>Major Tally</th>
            <th>Risk Level</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($filtered)): ?>
          <tr class="empty-row"><td colspan="5"><i class="fas fa-users" style="font-size:20px;display:block;margin-bottom:8px;"></i>No students found</td></tr>
          <?php else: foreach($filtered as $s):
              $risk  = getRisk($s['minor_count'], $s['major_count']);
              $minorCount = (int)$s['minor_count'];
              $majorCount = (int)$s['major_count'];
          ?>
          <tr>
            <td class="sid"><?= htmlspecialchars($s['student_id']) ?></td>
            <td class="name"><?= htmlspecialchars($s['full_name']) ?></td>
            <td>
              <div class="tally-wrap">
                <?php for($i=1;$i<=5;$i++): ?>
                <div class="pip <?= $i<=$minorCount?'minor-filled':'minor-empty' ?>"></div>
                <?php endfor; ?>
                <?php if($minorCount>5): ?><span class="pip-extra minor">+<?= $minorCount-5 ?></span><?php endif; ?>
              </div>
            </td>
            <td>
              <div class="tally-wrap">
                <?php for($i=1;$i<=3;$i++): ?>
                <div class="pip <?= $i<=$majorCount?'major-filled':'major-empty' ?>"></div>
                <?php endfor; ?>
                <?php if($majorCount>3): ?><span class="pip-extra major">+<?= $majorCount-3 ?></span><?php endif; ?>
              </div>
            </td>
            <td>
              <span class="risk-badge" style="background:<?= $risk['bg'] ?>;color:<?= $risk['color'] ?>;">
                <span class="dot" style="background:<?= $risk['color'] ?>;"></span>
                <?= $risk['label'] ?>
              </span>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
