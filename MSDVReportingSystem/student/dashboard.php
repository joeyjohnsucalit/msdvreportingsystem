<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../index.php'); exit;
}

// FIRST LOGIN change password
if (isset($_POST['action']) && $_POST['action'] === 'first_change_pass') {
    $new  = trim($_POST['new_password']  ?? '');
    $conf = trim($_POST['confirm_password'] ?? '');

    if (empty($new) || empty($conf)) {
        $_SESSION['cp_err'] = 'Both fields are required.';
    } elseif ($new !== $conf) {
        $_SESSION['cp_err'] = 'Passwords do not match.';
    } elseif (strlen($new) < 6) {
        $_SESSION['cp_err'] = 'Minimum 6 characters required.';
    } else {
        $pdo->prepare("UPDATE users SET password=?, is_first_login=0 WHERE id=?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        $pdo->prepare("UPDATE users SET remember_token=NULL, remember_token_expires=NULL WHERE id=?")
            ->execute([$_SESSION['user_id']]);
        setcookie('remember_token', '', time() - 3600, '/');
        session_destroy();
        header('Location: ../login.php?changed=1'); exit;
    }
    header('Location: dashboard.php'); exit;
}

// SUBMIT APPEAL
if (isset($_POST['action']) && $_POST['action'] === 'submit_appeal') {
    $violationId = (int)$_POST['violation_id'];
    $explanation = trim($_POST['explanation']);

    $stuRow = $pdo->prepare("SELECT student_id FROM students WHERE user_id=?");
    $stuRow->execute([$_SESSION['user_id']]);
    $stu = $stuRow->fetch();

    if ($stu) {
        $existing = $pdo->prepare("SELECT id FROM appeals WHERE violation_id=? AND student_id=?");
        $existing->execute([$violationId, $stu['student_id']]);
        if ($existing->fetch()) {
            $_SESSION['err'] = 'You have already submitted an appeal for this violation.';
        } else {
            $pdo->prepare("INSERT INTO appeals (violation_id,student_id,explanation) VALUES (?,?,?)")
                ->execute([$violationId, $stu['student_id'], $explanation]);
            $adminId = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
            $pdo->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,?)")
                ->execute([$adminId, "Student {$stu['student_id']} submitted an appeal.", '../admin/student-appeals.php']);
            $_SESSION['msg'] = 'Appeal submitted successfully.';
        }
    }
    header('Location: dashboard.php'); exit;
}

// Get student record
$stuStmt = $pdo->prepare("
    SELECT s.*, c.name as cname, d.name as dname
    FROM students s
    JOIN courses c ON s.course_id = c.id
    JOIN departments d ON s.department_id = d.id
    WHERE s.user_id = ?");
$stuStmt->execute([$_SESSION['user_id']]);
$student = $stuStmt->fetch();
if (!$student) { echo "Student record not found. Contact admin."; exit; }

// Get violations
$viols = $pdo->prepare("
    SELECT v.*, da.sanction, da.status as da_status,
           ap.id as appeal_id, ap.status as appeal_status
    FROM violations v
    LEFT JOIN disciplinary_actions da ON da.violation_id = v.id
    LEFT JOIN appeals ap ON ap.violation_id = v.id AND ap.student_id = v.student_id
    WHERE v.student_id = ?
    ORDER BY v.date_submitted DESC");
$viols->execute([$student['student_id']]);
$violations = $viols->fetchAll();


$minorCount = count(array_filter($violations, fn($v) => $v['category'] === 'minor'));
$majorCount = count(array_filter($violations, fn($v) => $v['category'] === 'major'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Student Dashboard — MDSV</title>
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
    --accent-border: #fca5a5;
    --danger: #b91c1c;
    --danger-light: #fef2f2;
    --danger-border: #fca5a5;
    --success: #2d6a4f;
    --success-light: #e8f5ee;
    --warn: #b7791f;
    --warn-light: #fef9ee;
    --minor-bg: #eff6ff;
    --minor-color: #1d4ed8;
    --radius: 12px;
    --radius-sm: 8px;
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
    margin-bottom: 20px;
  }

  /* ── ALERTS ── */
  .alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    margin-bottom: 16px;
    animation: slideDown 0.3s ease;
  }
  .alert-success { background: var(--success-light); color: var(--success); border: 1px solid #b7dfc9; }
  .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger-border); }
  .alert svg { flex-shrink: 0; width: 16px; height: 16px; margin-top: 1px; }
  .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; background: none; border: none; font-size: 16px; line-height: 1; color: inherit; }
  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* ── STUDENT PROFILE CARD ── */
  .profile-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .profile-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: var(--accent-light);
    border: 2px solid var(--accent-border);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--accent);
  }
  .profile-name {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 2px;
  }
  .profile-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 12px;
    color: var(--text-secondary);
  }
  .profile-meta span {
    display: flex;
    align-items: center;
    gap: 3px;
  }
  .profile-meta svg { width: 11px; height: 11px; }
  .profile-id {
    font-family: 'DM Mono', monospace;
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
  }

  /* ── STATS ROW ── */
  .stats-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
    margin-bottom: 12px;
  }
  .stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 14px;
    text-align: center;
  }
  .stat-value {
    font-size: 22px;
    font-weight: 600;
    line-height: 1.2;
  }
  .stat-label {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }
  .stat-card.total .stat-value { color: var(--text-primary); }
  .stat-card.minor .stat-value { color: var(--minor-color); }
  .stat-card.major .stat-value { color: var(--danger); }

  /* clickable filter cards */
  .stat-card {
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    transition: border-color 0.15s, background 0.15s, box-shadow 0.15s, transform 0.1s;
  }
  .stat-card:hover { border-color: var(--text-muted); }
  .stat-card:active { transform: scale(0.97); }
  .stat-card:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .stat-card.total.active { border-color: var(--text-primary); box-shadow: 0 0 0 1px var(--text-primary); background: var(--bg); }
  .stat-card.minor.active { border-color: var(--minor-color); box-shadow: 0 0 0 1px var(--minor-color); background: var(--minor-bg); }
  .stat-card.major.active { border-color: var(--danger); box-shadow: 0 0 0 1px var(--danger); background: var(--danger-light); }

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

  /* ── VIOLATION CARD ── */
  .viol-card {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    transition: background 0.1s;
  }
  .viol-card:last-child { border-bottom: none; }
  .viol-card:hover { background: var(--bg); }

  .viol-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
  }
  .viol-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
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
    white-space: nowrap;
  }
  .badge-minor   { background: var(--minor-bg); color: var(--minor-color); }
  .badge-major   { background: var(--danger-light); color: var(--danger); }
  .badge-pending   { background: var(--bg); color: var(--text-secondary); border: 1px solid var(--border); }
  .badge-ongoing   { background: var(--warn-light); color: var(--warn); }
  .badge-completed { background: var(--success-light); color: var(--success); }
  .badge-appeal-pending  { background: var(--warn-light); color: var(--warn); }
  .badge-appeal-approved { background: var(--success-light); color: var(--success); }
  .badge-appeal-rejected { background: var(--danger-light); color: var(--danger); }

  .badge-row {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 7px;
  }

  .viol-date {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 3px;
    margin-bottom: 6px;
  }
  .viol-date svg { width: 11px; height: 11px; }

  .sanction-box {
    background: var(--danger-light);
    border: 1px solid var(--danger-border);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    font-size: 12px;
    color: var(--danger);
    margin-bottom: 8px;
    display: flex;
    gap: 6px;
    align-items: flex-start;
  }
  .sanction-box svg { width: 13px; height: 13px; flex-shrink: 0; margin-top: 1px; }

  .viol-desc {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 8px;
  }

  .appeal-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 500;
    padding: 5px 12px;
    border: 1.5px solid var(--accent);
    border-radius: var(--radius-sm);
    background: none;
    color: var(--accent);
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
  }
  .appeal-btn:hover { background: var(--accent-light); }
  .appeal-btn svg { width: 13px; height: 13px; }

  /* ── EMPTY STATE ── */
  .empty-state {
    padding: 48px 24px;
    text-align: center;
  }
  .empty-icon {
    width: 48px; height: 48px;
    background: var(--success-light);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    border: 1px solid #b7dfc9;
  }
  .empty-icon svg { width: 22px; height: 22px; color: var(--success); }
  .empty-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
  .empty-sub { font-size: 13px; color: var(--text-secondary); }

  /* ── MODAL ── */
  .modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 500;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(2px);
    align-items: flex-end;
    justify-content: center;
    animation: fadein 0.2s ease;
    height: 100vh;
  }
  .modal-overlay.active { display: flex; }
  .modal-overlay.modal-center { align-items: center; }
  @keyframes fadein { from { opacity: 0; } to { opacity: 1; } }

  .modal-sheet {
    background: var(--surface);
    border-radius: var(--radius) var(--radius) 0 0;
    width: 100%;
    max-width: 560px;
    padding: 20px 20px 32px;
    animation: slideUp 0.25s ease;
    max-height: 90vh;
    overflow-y: auto;
  }
  .modal-overlay.modal-center .modal-sheet {
    border-radius: var(--radius);
    margin: 0 16px;
    max-width: 420px;
  }
  @keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
  }
  .modal-handle {
    width: 36px; height: 4px;
    background: var(--border);
    border-radius: 99px;
    margin: 0 auto 16px;
  }
  .modal-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
  .modal-sub { font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; }

  .field { margin-bottom: 14px; }
  .field:last-child { margin-bottom: 0; }
  .field label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 6px;
  }
  textarea, input[type="password"] {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 15px;
    color: var(--text-primary);
    background: var(--surface);
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    resize: vertical;
  }
  textarea:focus, input[type="password"]:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(26,26,26,0.06);
  }

  .modal-row { display: flex; gap: 8px; margin-top: 14px; }
  .modal-row .icon-btn { flex: 1; justify-content: center; }
  .modal-row .submit-btn { flex: 2; }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 14px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.15s;
  }
  .icon-btn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }

  .submit-btn {
    width: 100%;
    padding: 13px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
  }
  .submit-btn:hover { background: var(--accent-hover); }

  /* first login */
  .warn-badge {
    width: 48px; height: 48px;
    background: #fef3cd;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
  }
  .warn-badge svg { width: 22px; height: 22px; color: #b7791f; }
  .warn-btn {
    width: 100%;
    padding: 13px;
    background: #b7791f;
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 4px;
    transition: background 0.15s;
  }
  .warn-btn:hover { background: #9c6617; }

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

<!-- ── FIRST LOGIN MODAL ── -->
<?php if ($_SESSION['is_first_login'] == 1): ?>
<div class="modal-overlay modal-center active" id="firstLoginOverlay">
  <form method="POST" class="modal-sheet" style="border-radius:var(--radius)">
    <input type="hidden" name="action" value="first_change_pass">
    <div class="warn-badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <h2 class="modal-title" style="text-align:center">Set your password</h2>
    <p class="modal-sub" style="text-align:center">Your default password is your last 5 student ID digits. Please set a new one to continue.</p>
    <?php if (isset($_SESSION['cp_err'])): ?>
      <div class="alert alert-danger" style="margin-bottom:12px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= $_SESSION['cp_err'] ?><?php unset($_SESSION['cp_err']); ?>
      </div>
    <?php endif; ?>
    <div class="field"><label>New Password</label>
      <input type="password" name="new_password" placeholder="Min. 6 characters" required></div>
    <div class="field"><label>Confirm Password</label>
      <input type="password" name="confirm_password" placeholder="Repeat password" required></div>
    <button type="submit" class="warn-btn">Change Password &amp; Continue</button>
  </form>
</div>
<?php endif; ?>

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
    <a href="dashboard.php" class="sb-link active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Dashboard
    </a>
    <a href="report-form.php" class="sb-link">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Report
    </a>

    <div class="sb-label" style="margin-top:10px">Help</div>
    <a href="violation-guidelines.php" class="sb-link">
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

<!-- ── ALERTS ── -->
<div style="max-width:560px;margin:0 auto;padding:16px 16px 0">
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><?= $_SESSION['msg'] ?></span>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['err'])): ?>
  <div class="alert alert-danger">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><?= $_SESSION['err'] ?></span>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
  </div>
  <?php unset($_SESSION['err']); ?>
<?php endif; ?>
</div>

<!-- ── MAIN PAGE ── -->
<div class="page">
  <h1 class="page-title">Student Dashboard</h1>
  <p class="page-subtitle">View your violation records and submit appeals.</p>

  <!-- Profile Card -->
  <div class="profile-card">
    <div class="profile-avatar"><?= mb_strtoupper(mb_substr($student['full_name'], 0, 1)) ?></div>
    <div>
      <div class="profile-name"><?= htmlspecialchars($student['full_name']) ?></div>
      <div class="profile-id"><?= htmlspecialchars($student['student_id']) ?></div>
      <div class="profile-meta">
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
          <?= htmlspecialchars($student['cname']) ?>
        </span>
        <span>Year <?= $student['year_level'] ?></span>
        <span><?= htmlspecialchars($student['dname']) ?></span>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card total active" data-filter="all" role="button" tabindex="0" aria-pressed="true">
      <div class="stat-value"><?= count($violations) ?></div>
      <div class="stat-label">Total</div>
    </div>
    <div class="stat-card minor" data-filter="minor" role="button" tabindex="0" aria-pressed="false">
      <div class="stat-value"><?= $minorCount ?></div>
      <div class="stat-label">Minor</div>
    </div>
    <div class="stat-card major" data-filter="major" role="button" tabindex="0" aria-pressed="false">
      <div class="stat-value"><?= $majorCount ?></div>
      <div class="stat-label">Major</div>
    </div>
  </div>

  <!-- Violations -->
  <div class="section">
    <div class="section-header">
      <span class="section-title" id="listTitle">Violation Records</span>
      <span class="count-badge" id="listCount"><?= count($violations) ?></span>
    </div>

    <?php if (empty($violations)): ?>
    <div class="empty-state">
      <div class="empty-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div class="empty-title">Clean record!</div>
      <div class="empty-sub">No violations on record. Keep it up.</div>
    </div>

    <?php else: foreach ($violations as $v):
      $catClass = $v['category'] === 'minor' ? 'badge-minor' : 'badge-major';
      $statusClass = match($v['da_status'] ?? $v['status']) {
        'completed' => 'badge-completed',
        'ongoing'   => 'badge-ongoing',
        default     => 'badge-pending',
      };
    ?>
    <div class="viol-card" data-category="<?= htmlspecialchars($v['category']) ?>">
      <div class="viol-top">
        <div class="viol-name"><?= htmlspecialchars($v['violation']) ?></div>
        <span class="badge <?= $statusClass ?>"><?= ucfirst($v['da_status'] ?? $v['status']) ?></span>
      </div>

      <div class="badge-row">
        <span class="badge <?= $catClass ?>"><?= ucfirst($v['category']) ?></span>
      </div>

      <div class="viol-date">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <?= date('M d, Y', strtotime($v['date_submitted'])) ?>
      </div>

      <?php if (!empty($v['description'])): ?>
      <div class="viol-desc"><?= htmlspecialchars(substr($v['description'], 0, 120)) ?><?= strlen($v['description']) > 120 ? '…' : '' ?></div>
      <?php endif; ?>

      <?php if (!empty($v['sanction'])): ?>
      <div class="sanction-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span><?= htmlspecialchars($v['sanction']) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($v['appeal_id']): 
        $apClass = match($v['appeal_status']) {
          'approved' => 'badge-appeal-approved',
          'rejected' => 'badge-appeal-rejected',
          default    => 'badge-appeal-pending',
        };
      ?>
        <span class="badge <?= $apClass ?>">Appeal: <?= ucfirst($v['appeal_status']) ?></span>
      <?php else: ?>
        <button type="button" class="appeal-btn" onclick='openAppeal(<?= json_encode(['id'=>$v['id'],'violation'=>$v['violation']]) ?>)'>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          Submit Appeal
        </button>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>

    <div class="empty-state" id="filterEmpty" style="display:none;padding:32px 24px">
      <div class="empty-title">No <span id="filterEmptyLabel"></span> violations</div>
      <div class="empty-sub">Tap Total to see all records.</div>
    </div>
  </div>
</div>

<!-- ── APPEAL MODAL ── -->
<div class="modal-overlay" id="appealOverlay">
  <form method="POST" class="modal-sheet">
    <div class="modal-handle"></div>
    <input type="hidden" name="action" value="submit_appeal">
    <input type="hidden" name="violation_id" id="appealViolID">
    <h2 class="modal-title">Submit Appeal</h2>
    <p class="modal-sub">Violation: <strong id="appealViolName"></strong></p>
    <div class="field">
      <label>Your Explanation</label>
      <textarea name="explanation" rows="5" placeholder="Explain why you are appealing this violation…" required></textarea>
    </div>
    <div class="modal-row">
      <button type="button" class="icon-btn" onclick="closeAppeal()">Cancel</button>
      <button type="submit" class="submit-btn">
        
        Submit Appeal
      </button>
    </div>
  </form>
</div>

<script>
function openAppeal(v) {
    document.getElementById('appealViolID').value = v.id;
    document.getElementById('appealViolName').textContent = v.violation;
    document.getElementById('appealOverlay').classList.add('active');
}
function closeAppeal() {
    document.getElementById('appealOverlay').classList.remove('active');
}
document.getElementById('appealOverlay').addEventListener('click', function(e){
    if (e.target === this) closeAppeal();
});
</script>
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
<script>
/* ── FILTER BY STAT CARD ── */
(function () {
  const cards   = document.querySelectorAll('.stat-card[data-filter]');
  const items   = document.querySelectorAll('.viol-card');
  const countEl = document.getElementById('listCount');
  const titleEl = document.getElementById('listTitle');
  const emptyEl = document.getElementById('filterEmpty');
  const emptyLb = document.getElementById('filterEmptyLabel');
  const titles  = { all: 'Violation Records', minor: 'Minor Violations', major: 'Major Violations' };

  function apply(filter) {
    let shown = 0, lastVisible = null;
    items.forEach(function (el) {
      const show = filter === 'all' || el.dataset.category === filter;
      el.style.display = show ? '' : 'none';
      el.style.borderBottom = '';
      if (show) { shown++; lastVisible = el; }
    });
    if (lastVisible) lastVisible.style.borderBottom = 'none';

    cards.forEach(function (c) {
      const on = c.dataset.filter === filter;
      c.classList.toggle('active', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    countEl.textContent = shown;
    titleEl.textContent = titles[filter];
    emptyLb.textContent = filter;
    emptyEl.style.display = (items.length && !shown) ? 'block' : 'none';
  }

  cards.forEach(function (c) {
    function go() {
      // tapping the active Minor/Major card again goes back to all
      const same = c.classList.contains('active') && c.dataset.filter !== 'all';
      apply(same ? 'all' : c.dataset.filter);
    }
    c.addEventListener('click', go);
    c.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
    });
  });

  apply('all');
})();
</script>
</body>
</html>
