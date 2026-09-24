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

if (isset($_POST['action']) && in_array($_POST['action'],['approve','reject'])) {
    $appealId=(int)$_POST['appeal_id'];
    $status=$_POST['action']==='approve'?'approved':'rejected';
    $ap=$pdo->prepare("SELECT * FROM appeals WHERE id=?");
    $ap->execute([$appealId]); $appeal=$ap->fetch();
    if($appeal){
        $pdo->prepare("UPDATE appeals SET status=? WHERE id=?")->execute([$status,$appealId]);
        if($status==='approved'){
            $vid=$appeal['violation_id'];
            $pdo->prepare("DELETE FROM appeals WHERE violation_id=?")->execute([$vid]);
            $pdo->prepare("DELETE FROM disciplinary_actions WHERE violation_id=?")->execute([$vid]);
            $pdo->prepare("DELETE FROM violations WHERE id=?")->execute([$vid]);
            $notifMsg='Your appeal has been APPROVED. The violation has been removed from your record.';
        }else{$notifMsg='Your appeal has been REJECTED. The violation remains on your record.';}
        $stuUser=$pdo->prepare("SELECT u.id FROM students s JOIN users u ON s.user_id=u.id WHERE s.student_id=?");
        $stuUser->execute([$appeal['student_id']]); $stuRow=$stuUser->fetch();
        if($stuRow){$pdo->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,?)")->execute([$stuRow['id'],$notifMsg,'../student/dashboard.php']);}
        $_SESSION['msg']='Appeal '.ucfirst($status).'.';
    }
    header('Location: student-appeals.php'); exit;
}

$appeals=$pdo->query("SELECT a.*,s.full_name,v.violation,v.category,v.date_submitted as vdate FROM appeals a JOIN students s ON a.student_id=s.student_id JOIN violations v ON a.violation_id=v.id ORDER BY a.submitted_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDV | Student Appeals</title>
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
.mini-badge.minor    { background:#eff4ff; color:#2563eb; }
.mini-badge.major    { background:#fff0f0; color:#dc2626; }
.mini-badge.approved { background:#f0fdf4; color:#16a34a; }
.mini-badge.rejected { background:#fff0f0; color:#dc2626; }
.mini-badge.pending  { background:#f4f5f7; color:#6b7280; }

/* Action buttons */
.view-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--r-sm); font-size:12px; font-weight:500; border:1px solid var(--border); background:#fff; color:var(--text-2); cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:all 0.15s; }
.view-btn:hover { background:var(--accent-light); color:var(--accent); border-color:#c7d7f8; }
.action-btn { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:var(--r-sm); font-size:12px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all 0.15s; border:1px solid; }
.action-btn.approve { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
.action-btn.approve:hover { background:#dcfce7; }
.action-btn.reject  { background:#fff0f0; color:#dc2626; border-color:#fecaca; }
.action-btn.reject:hover  { background:#fee2e2; }
.resolved-txt { font-size:12px; color:var(--text-3); font-style:italic; }

/* ── MODALS ── */
.modal-content { border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 16px 48px rgba(0,0,0,0.12); font-family:'Plus Jakarta Sans',sans-serif; overflow:hidden; }
.modal-header { padding:20px 24px 16px; border-bottom:1px solid var(--border-soft); display:flex; align-items:center; justify-content:space-between; }
.modal-header .modal-title { font-size:16px; font-weight:600; color:var(--text); letter-spacing:-0.2px; }
.modal-header .btn-close { opacity:0.4; }
.modal-header .btn-close:hover { opacity:0.7; }
.modal-body { padding:20px 24px; }
.modal-footer { padding:14px 24px; border-top:1px solid var(--border-soft); display:flex; justify-content:flex-end; gap:8px; background:#fafbfc; }

/* Modal buttons */
.m-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:var(--r-sm); font-size:13px; font-weight:500; font-family:'Plus Jakarta Sans',sans-serif; cursor:pointer; transition:all 0.15s; border:1px solid; }
.m-btn.ghost { background:#fff; color:var(--text-2); border-color:var(--border); }
.m-btn.ghost:hover { background:var(--bg); }
.m-btn.success { background:#15803d; color:#fff; border-color:#15803d; }
.m-btn.success:hover { background:#166534; }
.m-btn.danger  { background:#dc2626; color:#fff; border-color:#dc2626; }
.m-btn.danger:hover  { background:#b91c1c; }

/* View modal info grid */
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--r-md); overflow:hidden; margin-bottom:20px; }
.info-cell { padding:11px 14px; border-bottom:1px solid var(--border-soft); }
.info-cell.full { grid-column:span 2; }
.info-cell:nth-last-child(-n+2) { border-bottom:none; }
.info-cell.full:last-child { border-bottom:none; }
.info-key { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-3); margin-bottom:3px; }
.info-val { font-size:13.5px; color:var(--text); font-weight:500; }
.info-val.sid-val { font-family:monospace; color:var(--accent); letter-spacing:0.5px; }
.info-val.explanation { font-weight:400; color:var(--text-2); line-height:1.6; font-size:13px; }
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
      <li><a href="student-appeals.php" class="active"><i class="fa-solid fa-pen"></i><span>Student Appeals</span></a></li>
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
    <h1>Student Appeals</h1>
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

    <!-- Page top -->
   

    <!-- Count -->
    <div class="count-badge">Showing <strong><?= count($appeals) ?></strong> appeal<?= count($appeals)!==1?'s':'' ?></div>

    <!-- Table -->
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Full Name</th>
            <th>Violation</th>
            <th>Explanation</th>
            <th>Submitted</th>
            <th>Status</th>
            <th style="width:160px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($appeals)): ?>
          <tr class="empty-row"><td colspan="7"><i class="fas fa-pen-square" style="font-size:20px;display:block;margin-bottom:8px;"></i>No appeals yet</td></tr>
          <?php else: foreach($appeals as $ap): ?>
          <tr>
            <td class="sid"><?= htmlspecialchars($ap['student_id']) ?></td>
            <td class="name"><?= htmlspecialchars($ap['full_name']) ?></td>
            <td>
              <span class="mini-badge <?= $ap['category'] ?>"><?= ucfirst($ap['category']) ?></span>
              <span class="muted" style="margin-left:6px;font-size:12.5px;color:var(--text-2);"><?= htmlspecialchars($ap['violation']) ?></span>
            </td>
            <td class="muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(substr($ap['explanation'],0,60)) ?>…</td>
            <td class="muted"><?= date('M d, Y',strtotime($ap['submitted_at'])) ?></td>
            <td><span class="mini-badge <?= $ap['status'] ?>"><?= ucfirst($ap['status']) ?></span></td>
            <td>
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <?php if($ap['status']==='pending'): ?>
                <form method="POST" style="display:contents;">
                  <input type="hidden" name="appeal_id" value="<?= $ap['id'] ?>">
                  <button name="action" value="approve" class="action-btn approve" onclick="return confirm('Approve this appeal? The violation will be removed.')">
                    <i class="fas fa-check" style="font-size:10px;"></i> Approve
                  </button>
                  <button name="action" value="reject" class="action-btn reject" onclick="return confirm('Reject this appeal?')">
                    <i class="fas fa-times" style="font-size:10px;"></i> Reject
                  </button>
                </form>
                <?php else: ?>
                <span class="resolved-txt">Resolved</span>
                <?php endif; ?>
                <button class="view-btn" onclick='viewAppeal(<?= json_encode($ap) ?>)'>
                  <i class="fas fa-eye" style="font-size:11px;"></i> View
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /content -->
</div><!-- /main-content -->

<!-- ── VIEW APPEAL MODAL ── -->
<div class="modal fade" id="viewAppealModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title">Appeal Details</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="vaBody">
        <div style="text-align:center;padding:30px;color:var(--text-3);">Loading…</div>
      </div>
      <div class="modal-footer" id="vaFooter">
        <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewAppeal(ap){
    const statusClass = ap.status === 'approved' ? 'approved' : ap.status === 'rejected' ? 'rejected' : 'pending';

    document.getElementById('vaBody').innerHTML = `
        <div class="info-grid">
          <div class="info-cell">
            <div class="info-key">Student ID</div>
            <div class="info-val sid-val">${ap.student_id}</div>
          </div>
          <div class="info-cell">
            <div class="info-key">Full Name</div>
            <div class="info-val">${ap.full_name}</div>
          </div>
          <div class="info-cell">
            <div class="info-key">Category</div>
            <div class="info-val"><span class="mini-badge ${ap.category}">${ap.category.charAt(0).toUpperCase()+ap.category.slice(1)}</span></div>
          </div>
          <div class="info-cell">
            <div class="info-key">Violation</div>
            <div class="info-val">${ap.violation}</div>
          </div>
          <div class="info-cell">
            <div class="info-key">Violation Date</div>
            <div class="info-val">${ap.vdate}</div>
          </div>
          <div class="info-cell">
            <div class="info-key">Appeal Submitted</div>
            <div class="info-val">${ap.submitted_at}</div>
          </div>
          <div class="info-cell">
            <div class="info-key">Status</div>
            <div class="info-val"><span class="mini-badge ${statusClass}">${ap.status.charAt(0).toUpperCase()+ap.status.slice(1)}</span></div>
          </div>
          <div class="info-cell full" style="border-bottom:none;">
            <div class="info-key">Explanation</div>
            <div class="info-val explanation">${ap.explanation}</div>
          </div>
        </div>`;

    const footer = document.getElementById('vaFooter');
    if(ap.status === 'pending'){
        footer.innerHTML = `
            <form method="POST" style="display:contents;">
              <input type="hidden" name="appeal_id" value="${ap.id}">
              <button name="action" value="approve" class="m-btn success" onclick="return confirm('Approve this appeal? The violation will be removed.')">
                <i class="fas fa-check" style="font-size:11px;"></i> Approve
              </button>
              <button name="action" value="reject" class="m-btn danger" onclick="return confirm('Reject this appeal?')">
                <i class="fas fa-times" style="font-size:11px;"></i> Reject
              </button>
            </form>
            <button type="button" class="m-btn ghost" data-bs-dismiss="modal">Close</button>`;
    } else {
        footer.innerHTML = `<button type="button" class="m-btn ghost" data-bs-dismiss="modal">Close</button>`;
    }

    new bootstrap.Modal(document.getElementById('viewAppealModal')).show();
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