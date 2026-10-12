<?php
session_start();
$allowedRoles = ['teacher','csu','jassu','nurse','student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../index.php'); exit;
}
$role = $_SESSION['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Violation Guidelines — MSDV</title>
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
    --accent: #b91c1c;
    --accent-light: #fef2f2;
    --accent-hover: #991b1b;
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
    position: sticky; top: 0; z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 16px; height: 52px;
  }
  .topbar-brand {
    font-size: 13px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--accent);
  }
  .topbar-right { display: flex; align-items: center; gap: 10px; }
  .topbar-user {
    font-size: 12px; color: var(--text-secondary);
    max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  /* ── PAGE LAYOUT ── */
  .page { max-width: 560px; margin: 0 auto; padding: 20px 16px 40px; }
  .page-title { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
  .page-subtitle { font-size: 13px; color: var(--text-secondary); margin-bottom: 24px; }

  /* ── SECTION ── */
  .section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 12px;
    overflow: hidden;
  }
  .section-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
  }
  .section-title {
    font-size: 13px; font-weight: 600;
    letter-spacing: 0.02em; text-transform: uppercase;
    color: var(--text-secondary);
  }
  .section-body { padding: 16px; }

  /* ── GUIDELINES ── */
  .pill {
    font-size: 11px; font-weight: 600; padding: 2px 8px;
    border-radius: 99px; text-transform: none; letter-spacing: 0;
  }
  .pill.minor { background: #e8f0fe; color: #2d62c8; }
  .pill.major { background: var(--danger-light); color: var(--danger); }

  .sub-h {
    font-size: 12px; font-weight: 500; color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.03em;
    margin-bottom: 8px;
  }
  .sub-h:not(:first-child) { margin-top: 18px; }

  .tags { display: flex; flex-wrap: wrap; gap: 6px; }
  .tag {
    font-size: 12px; padding: 4px 10px;
    border: 1px solid var(--border); border-radius: 99px;
    color: var(--text-primary); background: var(--bg);
  }

  .offense-list { list-style: none; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
  .offense-list li {
    display: flex; gap: 10px;
    padding: 10px 12px;
    font-size: 13px; line-height: 1.45;
    border-bottom: 1px solid var(--border);
  }
  .offense-list li:last-child { border-bottom: none; }
  .offense-num {
    flex-shrink: 0;
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--accent-light); color: var(--accent);
    font-size: 11px; font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    margin-top: 1px;
  }
  .offense-list strong { display: block; font-size: 12px; color: var(--text-muted); font-weight: 500; margin-bottom: 1px; }

  .note {
    font-size: 12px; color: var(--warn);
    background: var(--warn-light);
    border: 1px solid #f5dba0;
    border-radius: var(--radius-sm);
    padding: 10px 12px;
  }

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
    <?php if ($role === 'student'): ?>
    <a href="dashboard.php" class="sb-link">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Dashboard
    </a>
    <?php endif; ?>
    <a href="report-form.php" class="sb-link ">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Report
    </a>
    <?php if ($role !== 'student'): ?>
    <a href="my-reports.php" class="sb-link ">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      My Reports
    </a>
    <?php endif; ?>

    <div class="sb-label" style="margin-top:10px">Help</div>
    <a href="violation-guidelines.php" class="sb-link active">
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
  <h1 class="page-title">Violation Guidelines</h1>
  <p class="page-subtitle">Violation types and the sanction applied for each offense.</p>

  <!-- MINOR -->
  <div class="section">
    <div class="section-header">
      <span class="pill minor">Minor</span>
      <span class="section-title">Minor Violations</span>
    </div>
    <div class="section-body">
      <div class="sub-h">Violation types</div>
      <div class="tags">
          <span class="tag">Disruptive Behavior</span>
          <span class="tag">Littering</span>
          <span class="tag">Dress Code</span>
          <span class="tag">Unapproved Absences</span>
          <span class="tag">Inappropriate Language</span>
          <span class="tag">Unauthorized Use of College Property</span>
          <span class="tag">Smoking on Campus</span>
          <span class="tag">Failure to Display ID</span>
          <span class="tag">Noise Violations</span>
          <span class="tag">Minor Vandalism</span>
      </div>
      <div class="sub-h">Sanctions</div>
      <ul class="offense-list">
          <li><span class="offense-num">1</span><div><strong>First offense</strong>Verbal warning and counseling</div></li>
          <li><span class="offense-num">2</span><div><strong>Second offense</strong>Written warning and reflective essay</div></li>
          <li><span class="offense-num">3</span><div><strong>Third offense</strong>Community service (5–10 hours) and parental notification</div></li>
          <li><span class="offense-num">4</span><div><strong>Fourth offense</strong>Short-term suspension (1–3 days) and mandatory workshop</div></li>
          <li><span class="offense-num">5</span><div><strong>Fifth offense and beyond</strong>Long-term suspension (1 week) and disciplinary probation</div></li>
      </ul>
    </div>
  </div>

  <!-- MAJOR -->
  <div class="section">
    <div class="section-header">
      <span class="pill major">Major</span>
      <span class="section-title">Major Violations</span>
    </div>
    <div class="section-body">
      <div class="sub-h">Violation types</div>
      <div class="tags">
          <span class="tag">Academic Dishonesty</span>
          <span class="tag">Theft</span>
          <span class="tag">Physical Violence</span>
          <span class="tag">Substance Abuse</span>
          <span class="tag">Harassment</span>
          <span class="tag">Unauthorized Entry</span>
          <span class="tag">Forgery</span>
          <span class="tag">Moral Infractions</span>
          <span class="tag">Weapons Possession</span>
          <span class="tag">Cyber Bullying</span>
          <span class="tag">Hazing</span>
          <span class="tag">Major Dishonesty</span>
          <span class="tag">Extortion</span>
          <span class="tag">Sexual Misconduct</span>
      </div>
      <div class="sub-h">Sanctions</div>
      <ul class="offense-list">
          <li><span class="offense-num">1</span><div><strong>First offense</strong>Suspension (1 week to 1 month) and mandatory counseling</div></li>
          <li><span class="offense-num">2</span><div><strong>Second offense</strong>Suspension (1 month to 1 semester) and extended counseling</div></li>
          <li><span class="offense-num">3</span><div><strong>Third offense</strong>Expulsion and notification to authorities if applicable</div></li>
      </ul>
    </div>
  </div>

  <!-- ACADEMIC DISHONESTY -->
  <div class="section">
    <div class="section-header">
      <span class="pill major">Major</span>
      <span class="section-title">Academic Dishonesty</span>
    </div>
    <div class="section-body">
      <ul class="offense-list" style="margin-bottom:12px">
          <li><span class="offense-num">1</span><div><strong>First offense</strong>Failing grade for the course and mandatory ethics workshop</div></li>
          <li><span class="offense-num">2</span><div><strong>Second offense</strong>Suspension for 1 semester</div></li>
          <li><span class="offense-num">3</span><div><strong>Third offense</strong>Expulsion</div></li>
      </ul>
      <div class="note">Offenses are counted per category (minor and major separately) for each student.</div>
    </div>
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
