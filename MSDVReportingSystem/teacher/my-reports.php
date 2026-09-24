<?php
session_start();
require_once '../db/connection.php';
$allowedRoles = ['teacher','csu','jassu'];
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
    --accent: #2d6a4f;
    --accent-light: #e8f5ee;
    --accent-hover: #235c43;
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
  .btn-logout {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    text-decoration: none;
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    transition: all 0.15s;
    white-space: nowrap;
  }
  .btn-logout:hover { background: var(--bg); color: var(--text-primary); }

  /* ── BOTTOM NAV ── */
  .bottom-nav {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 100;
    background: var(--surface);
    border-top: 1px solid var(--border);
    display: flex;
  }
  .bottom-nav a {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    padding: 10px 0 12px;
    text-decoration: none;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 500;
    transition: color 0.15s;
  }
  .bottom-nav a.active, .bottom-nav a:hover { color: var(--accent); }
  .bottom-nav svg { width: 20px; height: 20px; }

  /* ── PAGE LAYOUT ── */
  .page {
    max-width: 560px;
    margin: 0 auto;
    padding: 20px 16px 100px;
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
  .badge-completed { background: var(--accent-light); color: var(--accent); }

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
</style>
</head>
<body>

<!-- ── TOP BAR ── -->
<header class="topbar">
  <span class="topbar-brand">MSDV</span>
  <div class="topbar-right">
    <span class="topbar-user"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
    <a href="../logout.php" class="btn-logout">Logout</a>
  </div>
</header>

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

<!-- ── BOTTOM NAV ── -->
<nav class="bottom-nav">
  <a href="report-form.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    Report
  </a>
  <a href="my-reports.php" class="active">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    My Reports
  </a>
</nav>

</body>
</html>