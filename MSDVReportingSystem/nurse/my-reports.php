<?php
session_start();
require_once '../db/connection.php';
$allowedRoles = ['teacher','csu','jassu','nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../index.php'); exit;
}

$role = $_SESSION['role'];
$reports = $pdo->prepare("
    SELECT v.*, s.full_name, s.student_id as sid,
           c.name as cname, d.name as dname
    FROM violations v
    JOIN students s ON v.student_id = s.student_id
    JOIN courses c ON s.course_id = c.id
    JOIN departments d ON s.department_id = d.id
    WHERE v.reporter_id = ?
    ORDER BY v.date_submitted DESC");
$reports->execute([$_SESSION['user_id']]);
$myReports = $reports->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>My Reports — MSDV</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="manifest" href="../manifest.json">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #f5f4f0;
    --surface: #ffffff;
    --border: #e2e0db;
    --border-focus: #1a1a1a;
    --text-primary: #1a1a1a;
    --text-secondary: #6b6860;
    --text-muted: #9c9a95;
    --accent: #1d4ed8;
    --accent-light: #eff6ff;
    --accent-hover: #1e40af;
    --success: #2d6a4f;
    --success-light: #e8f5ee;
    --danger: #c0392b;
    --danger-light: #fdf2f1;
    --warn: #b7791f;
    --warn-light: #fef9ee;
    --radius: 12px;
    --radius-sm: 8px;
    --shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
  }

  html, body {
    background: var(--bg);
    color: var(--text-primary);
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    line-height: 1.5;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
  }

  /* ── TOP BAR ── */
  .topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    height: 52px;
  }
  .topbar-brand {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--accent);
  }
  .topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .topbar-user {
    font-size: 12px;
    color: var(--text-secondary);
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* ── PAGE LAYOUT ── */
  .page {
    max-width: 560px;
    margin: 0 auto;
    padding: 20px 16px 40px;
  }

  .page-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 4px;
  }
  .page-subtitle {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 24px;
  }

  /* ── SECTION ── */
  .section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 12px;
    overflow: hidden;
  }
  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
  }
  .section-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .section-title {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--text-secondary);
  }
  .count-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 99px;
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
  }

  /* ── REPORT CARD ── */
  .report-card {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    transition: background 0.1s;
  }
  .report-card:last-child { border-bottom: none; }
  .report-card:hover { background: var(--bg); }

  .report-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
  }
  .report-student-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1.3;
  }
  .report-student-id {
    font-family: 'DM Mono', monospace;
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
  }

  .badge-row {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 8px;
  }
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 500;
    padding: 3px 8px;
    border-radius: 99px;
    line-height: 1.4;
  }
  .badge-minor   { background: #e8f0fe; color: #2d62c8; }
  .badge-major   { background: var(--danger-light); color: var(--danger); }
  .badge-pending   { background: var(--bg); color: var(--text-secondary); border: 1px solid var(--border); }
  .badge-ongoing   { background: var(--warn-light); color: var(--warn); }
  .badge-completed { background: var(--success-light); color: var(--success); }

  .report-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 6px;
  }
  .report-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .report-meta svg { width: 12px; height: 12px; flex-shrink: 0; }

  .report-description {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.5;
  }

  .report-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
  }
  .report-date {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .report-date svg { width: 11px; height: 11px; }

  .view-evidence-btn {
    font-size: 11px;
    font-weight: 500;
    color: var(--accent);
    text-decoration: none;
    padding: 4px 10px;
    border: 1px solid var(--accent);
    border-radius: 6px;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .view-evidence-btn:hover { background: var(--accent-light); }
  .view-evidence-btn svg { width: 12px; height: 12px; }

  /* ── VIOLATION NAME ── */
  .violation-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 4px;
  }

  /* ── EMPTY STATE ── */
  .empty-state {
    padding: 48px 24px;
    text-align: center;
  }
  .empty-icon {
    width: 48px; height: 48px;
    background: var(--bg);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    border: 1px solid var(--border);
  }
  .empty-icon svg { width: 22px; height: 22px; color: var(--text-muted); }
  .empty-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
  .empty-sub { font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; }
  .empty-cta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    color: var(--accent);
    text-decoration: none;
    padding: 8px 16px;
    border: 1.5px solid var(--accent);
    border-radius: var(--radius-sm);
    transition: all 0.15s;
  }
  .empty-cta:hover { background: var(--accent-light); }
  .empty-cta svg { width: 14px; height: 14px; }

  /* ── BURGER BUTTON ── */
  .topbar-left { display: flex; align-items: center; gap: 10px; }
  .burger-btn {
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    background: none;
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    color: var(--text-primary);
    transition: background 0.15s;
  }
  .burger-btn:hover { background: var(--bg); }
  .burger-btn svg { width: 20px; height: 20px; }

  /* ── SIDEBAR ── */
  .sb-overlay {
    position: fixed; inset: 0;
    z-index: 200;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(2px);
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s ease;
  }
  .sb-overlay.open { opacity: 1; pointer-events: auto; }

  .sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    z-index: 210;
    width: 280px; max-width: 82vw;
    background: var(--surface);
    border-right: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    display: flex; flex-direction: column;
    transform: translateX(-100%);
    transition: transform 0.28s ease;
  }
  .sidebar.open { transform: translateX(0); }

  .sb-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 16px; height: 52px;
    border-bottom: 1px solid var(--border);
  }
  .sb-close {
    width: 32px; height: 32px;
    background: none; border: none; cursor: pointer;
    color: var(--text-secondary); border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
  }
  .sb-close:hover { background: var(--bg); color: var(--text-primary); }
  .sb-close svg { width: 18px; height: 18px; }

  .sb-user { padding: 16px; border-bottom: 1px solid var(--border); }
  .sb-user-name { font-size: 14px; font-weight: 600; }
  .sb-user-role {
    font-size: 11px; font-weight: 500; color: var(--accent);
    text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px;
  }

  .sb-nav { flex: 1; overflow-y: auto; padding: 12px 10px; }
  .sb-label {
    font-size: 11px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.05em;
    padding: 8px 10px 6px;
  }
  .sb-link {
    display: flex; align-items: center; gap: 12px;
    width: 100%;
    padding: 11px 12px;
    margin-bottom: 2px;
    border: none; background: none;
    border-radius: var(--radius-sm);
    font-family: inherit; font-size: 14px; font-weight: 500;
    color: var(--text-secondary);
    text-decoration: none; text-align: left;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
  }
  .sb-link svg { width: 18px; height: 18px; flex-shrink: 0; }
  .sb-link:hover { background: var(--bg); color: var(--text-primary); }
  .sb-link.active { background: var(--accent-light); color: var(--accent); }

  .sb-foot { padding: 12px 10px; border-top: 1px solid var(--border); }
  .sb-link.logout:hover { background: var(--danger-light); color: var(--danger); }
</style>
</head>
<body>

<!-- ── TOP BAR ── -->
<header class="topbar">
  <div class="topbar-left">
    <button type="button" class="burger-btn" id="burgerBtn" aria-label="Open menu" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <span class="topbar-brand">MSDV</span>
  </div>
  <div class="topbar-right">
    <span class="topbar-user"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
  </div>
</header>

<!-- ── SIDEBAR ── -->
<div class="sb-overlay" id="sbOverlay"></div>
<aside class="sidebar" id="sidebar" aria-label="Main menu">
  <div class="sb-head">
    <span class="topbar-brand">MSDV</span>
    <button type="button" class="sb-close" id="sbClose" aria-label="Close menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>

  <div class="sb-user">
    <div class="sb-user-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
    <div class="sb-user-role"><?= htmlspecialchars($_SESSION['role']) ?></div>
  </div>

  <nav class="sb-nav">
    <div class="sb-label">Menu</div>
    <a href="report-form.php" class="sb-link ">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Report
    </a>
    <a href="my-reports.php" class="sb-link active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      My Reports
    </a>

    <div class="sb-label" style="margin-top:10px">Help</div>
    <a href="violation-guidelines.php" class="sb-link ">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      Violation Guidelines
    </a>
  </nav>

  <div class="sb-foot">
    <a href="../logout.php" class="sb-link logout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</aside>


<!-- ── MAIN PAGE ── -->
<div class="page">
  <h1 class="page-title">My Reports</h1>
  <p class="page-subtitle">Violation reports you have submitted.</p>

  <div class="section">
    <div class="section-header">
      <div class="section-header-left">
        <span class="section-title">Submitted Reports</span>
        <span class="count-badge"><?= count($myReports) ?></span>
      </div>
    </div>

    <?php if (empty($myReports)): ?>
    <div class="empty-state">
      <div class="empty-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      </div>
      <div class="empty-title">No reports yet</div>
      <div class="empty-sub">You haven't submitted any violation reports.</div>
      <a href="report-form.php" class="empty-cta">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Submit a Report
      </a>
    </div>

    <?php else: ?>
    <?php foreach ($myReports as $r):
      $statusClass = match($r['status']) {
        'completed' => 'badge-completed',
        'ongoing'   => 'badge-ongoing',
        default     => 'badge-pending',
      };
      $catClass = $r['category'] === 'minor' ? 'badge-minor' : 'badge-major';
    ?>
    <div class="report-card">
      <div class="report-card-top">
        <div>
          <div class="report-student-name"><?= htmlspecialchars($r['full_name']) ?></div>
          <div class="report-student-id"><?= htmlspecialchars($r['sid']) ?></div>
        </div>
        <span class="badge <?= $statusClass ?>"><?= ucfirst($r['status']) ?></span>
      </div>

      <div class="badge-row">
        <span class="badge <?= $catClass ?>"><?= ucfirst($r['category']) ?></span>
      </div>

      <div class="violation-name"><?= htmlspecialchars($r['violation']) ?></div>

      <div class="report-meta">
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
          <?= htmlspecialchars($r['cname']) ?>
        </span>
        <span><?= htmlspecialchars($r['dname']) ?></span>
      </div>

      <?php if (!empty($r['description'])): ?>
      <div class="report-description"><?= htmlspecialchars(substr($r['description'], 0, 100)) ?><?= strlen($r['description']) > 100 ? '…' : '' ?></div>
      <?php endif; ?>

      <div class="report-footer">
        <div class="report-date">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <?= date('M d, Y', strtotime($r['date_submitted'])) ?>
        </div>
        <?php if ($r['evidence_path']): ?>
        <a href="../<?= htmlspecialchars($r['evidence_path']) ?>" target="_blank" class="view-evidence-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          Evidence
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
/* ── SIDEBAR ── */
(function () {
  const sidebar   = document.getElementById('sidebar');
  const sbOverlay = document.getElementById('sbOverlay');
  const burger    = document.getElementById('burgerBtn');
  function openSidebar()  { sidebar.classList.add('open');    sbOverlay.classList.add('open');    burger.setAttribute('aria-expanded','true'); }
  function closeSidebar() { sidebar.classList.remove('open'); sbOverlay.classList.remove('open'); burger.setAttribute('aria-expanded','false'); }
  burger.addEventListener('click', openSidebar);
  document.getElementById('sbClose').addEventListener('click', closeSidebar);
  sbOverlay.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });
})();
</script>
</body>
</html>
