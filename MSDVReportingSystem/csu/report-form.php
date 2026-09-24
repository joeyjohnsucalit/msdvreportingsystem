<?php
session_start();
require_once '../db/connection.php';
$allowedRoles = ['teacher','csu','jassu'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../index.php'); exit;
}

$role = $_SESSION['role'];
$msg = $err = '';

// FIRST LOGIN PASSWORD CHANGE
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
    header('Location: report-form.php'); exit;
}

// SUBMIT REPORT
if (isset($_POST['action']) && $_POST['action'] === 'submit_report') {
    $sid         = trim($_POST['student_id']);
    $category    = $_POST['category'];
    $violation   = trim($_POST['violation']);
    $description = trim($_POST['description']);

    $sCheck = $pdo->prepare("SELECT * FROM students WHERE student_id=?");
    $sCheck->execute([$sid]);
    $student = $sCheck->fetch();

    if (!$student) {
        $err = 'Student ID not found in records.';
    } else {
        $evidencePath = null;
        if (!empty($_FILES['evidence']['name'])) {
            $ext = pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION);
            $fname = 'uploads/evidence_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['evidence']['tmp_name'], '../' . $fname);
            $evidencePath = $fname;
        }

        $facePath = null;
        if (!empty($_POST['face_capture_data'])) {
            $imgData = str_replace('data:image/png;base64,', '', $_POST['face_capture_data']);
            $imgData = base64_decode($imgData);
            $facePath = 'uploads/face_' . time() . '.png';
            file_put_contents('../' . $facePath, $imgData);
        }

        $sigPath = null;
        if (!empty($_POST['signature_data'])) {
            $sigData = str_replace('data:image/png;base64,', '', $_POST['signature_data']);
            $sigData = base64_decode($sigData);
            $sigPath = 'uploads/sig_' . time() . '.png';
            file_put_contents('../' . $sigPath, $sigData);
        }

        $pdo->prepare("INSERT INTO violations (student_id,reporter_id,category,violation,description,evidence_path,face_capture_path,signature_path) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$sid, $_SESSION['user_id'], $category, $violation, $description, $evidencePath, $facePath, $sigPath]);

        $violationId = $pdo->lastInsertId();

        $minorCount = (int)$pdo->query("SELECT COUNT(*) FROM violations WHERE student_id='$sid' AND category='minor'")->fetchColumn();
        $majorCount = (int)$pdo->query("SELECT COUNT(*) FROM violations WHERE student_id='$sid' AND category='major'")->fetchColumn();

        $sanction = '';
        if ($category === 'minor') {
            $offense = $minorCount;
            if ($offense == 1)      $sanction = 'First Offense: Verbal Warning and Counseling';
            elseif ($offense == 2)  $sanction = 'Second Offense: Written Warning and Reflective Essay';
            elseif ($offense == 3)  $sanction = 'Third Offense: Community Service 5-10 hours and Parental Notification';
            elseif ($offense == 4)  $sanction = 'Fourth Offense: Short-term Suspension 1-3 days and Mandatory Workshop';
            elseif ($offense >= 5)  $sanction = 'Fifth Offense: Long-term Suspension 1 week and Disciplinary Probation';
        } else {
            $offense = $majorCount;
            if ($offense == 1) {
                $sanction = $violation === 'Academic Dishonesty'
                    ? 'First Major Offense (Academic Dishonesty): Failing grade for the course and Mandatory Ethics Workshop'
                    : 'First Major Offense: Suspension (1 week to 1 month) and Mandatory Counseling';
            } elseif ($offense == 2) {
                $sanction = $violation === 'Academic Dishonesty'
                    ? 'Second Major Offense (Academic Dishonesty): Suspension for 1 Semester'
                    : 'Second Major Offense: Suspension (1 month to 1 semester) and Extended Counseling';
            } elseif ($offense >= 3) {
                $sanction = $violation === 'Academic Dishonesty'
                    ? 'Third Major Offense (Academic Dishonesty): Expulsion'
                    : 'Third Major Offense: Expulsion and Notification to Authorities if Applicable';
            }
        }

        $pdo->prepare("INSERT INTO disciplinary_actions (violation_id,student_id,sanction,status) VALUES (?,?,?,'pending')")
            ->execute([$violationId, $sid, $sanction]);

        $adminId = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,?)")
            ->execute([$adminId, "New violation reported for student $sid by " . $_SESSION['full_name'], '../admin/violation-records.php']);

        $_SESSION['report_success'] = true;
        header('Location: report-form.php'); exit;
    }
}

// AJAX: get student info
if (isset($_GET['get_student'])) {
    header('Content-Type: application/json');
    $sid = trim($_GET['get_student']);
    $s = $pdo->prepare("SELECT s.*,c.name as cname,d.name as dname FROM students s JOIN courses c ON s.course_id=c.id JOIN departments d ON s.department_id=d.id WHERE s.student_id=?");
    $s->execute([$sid]);
    echo json_encode($s->fetch() ?: null);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Report Violation — MSDV</title>
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
    --step-inactive: #d4d2cd;
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
  .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7dfc9; }
  .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f0c4bf; }
  .alert-warn    { background: var(--warn-light); color: var(--warn); border: 1px solid #f5dba0; }
  .alert svg { flex-shrink: 0; width: 16px; height: 16px; margin-top: 1px; }
  .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; background: none; border: none; font-size: 16px; line-height: 1; color: inherit; padding: 0; }

  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
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
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
  }
  .step-badge {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .section-title {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--text-secondary);
  }
  .section-body { padding: 16px; }

  /* ── FORM ELEMENTS ── */
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

  input[type="text"],
  select,
  textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 15px;
    color: var(--text-primary);
    background: var(--surface);
    transition: border-color 0.15s, box-shadow 0.15s;
    appearance: none;
    -webkit-appearance: none;
    outline: none;
  }
  input[type="text"]::placeholder,
  textarea::placeholder { color: var(--text-muted); }
  input[type="text"]:focus,
  select:focus,
  textarea:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(26,26,26,0.06);
  }
  select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b6860' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
  }
  textarea { resize: vertical; min-height: 80px; line-height: 1.5; }

  /* student ID mono */
  #studentIDInput {
    font-family: 'DM Mono', monospace;
    font-size: 16px;
    letter-spacing: 0.05em;
  }

  /* ── RADIO TOGGLE ── */
  .radio-group {
    display: flex;
    gap: 8px;
  }
  .radio-group input { display: none; }
  .radio-group label {
    flex: 1;
    text-align: center;
    padding: 9px 12px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    color: var(--text-secondary);
    text-transform: none;
    letter-spacing: 0;
  }
  .radio-group input:checked + label {
    border-color: var(--accent);
    background: var(--accent-light);
    color: var(--accent);
  }

  /* ── STUDENT INFO CARD ── */
  .student-card {
    display: none;
    background: var(--accent-light);
    border: 1px solid #b7dfc9;
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    margin-top: 10px;
    animation: slideDown 0.2s ease;
  }
  .student-card .s-name {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 4px;
  }
  .student-card .s-meta {
    font-size: 12px;
    color: var(--accent);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .s-meta span { display: flex; align-items: center; gap: 3px; }

  /* ── FILE INPUT ── */
  .file-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1.5px dashed var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
    color: var(--text-secondary);
    font-size: 13px;
  }
  .file-label:hover { border-color: var(--accent); color: var(--accent); }
  .file-label svg { width: 18px; height: 18px; flex-shrink: 0; }
  input[type="file"] { display: none; }
  .file-name { font-size: 12px; color: var(--accent); margin-top: 6px; }

  .divider-text {
    text-align: center;
    font-size: 11px;
    color: var(--text-muted);
    margin: 10px 0;
    position: relative;
  }
  .divider-text::before, .divider-text::after {
    content: '';
    position: absolute;
    top: 50%;
    width: 40%;
    height: 1px;
    background: var(--border);
  }
  .divider-text::before { left: 0; }
  .divider-text::after { right: 0; }

  /* ── ICON BUTTONS ── */
  .icon-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 14px;
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
  .icon-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
  .icon-btn.active { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }

  /* ── PREVIEW IMAGE ── */
  .img-preview {
    display: none;
    margin-top: 10px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--border);
    position: relative;
  }
  .img-preview img { width: 100%; display: block; max-height: 160px; object-fit: cover; }
  .img-preview-remove {
    position: absolute;
    top: 6px; right: 6px;
    background: rgba(0,0,0,0.5);
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 24px; height: 24px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    font-size: 14px;
  }

  /* ── SIGNATURE ── */
  .sig-wrap {
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: #fff;
    position: relative;
  }
  .sig-wrap canvas { display: block; width: 100%; height: 110px; touch-action: none; }
  .sig-hint {
    position: absolute;
    top: 50%; left: 50%; transform: translate(-50%,-50%);
    font-size: 12px;
    color: var(--text-muted);
    pointer-events: none;
    transition: opacity 0.2s;
  }
  .sig-actions { display: flex; justify-content: flex-end; padding: 6px 10px; border-top: 1px solid var(--border); }
  .clear-btn {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-muted);
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px 6px;
    letter-spacing: 0.03em;
    text-transform: uppercase;
  }
  .clear-btn:hover { color: var(--danger); }

  /* ── SUBMIT BUTTON ── */
  .submit-btn {
    width: 100%;
    padding: 14px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: var(--radius);
    font-family: inherit;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 4px;
  }
  .submit-btn:hover { background: var(--accent-hover); }
  .submit-btn:active { transform: scale(0.98); }
  .submit-btn svg { width: 18px; height: 18px; }

  /* ── MODAL OVERLAY ── */
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
  }
  .modal-overlay.active { display: flex; }
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
  .modal-center .modal-sheet {
    border-radius: var(--radius);
    margin: 0 16px;
    max-width: 420px;
  }
  .modal-overlay.modal-center {
    align-items: center;
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
  .modal-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
  }
  .modal-sub {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 16px;
  }

  /* camera */
  #cameraStream {
    width: 100%;
    border-radius: var(--radius-sm);
    background: #000;
    aspect-ratio: 4/3;
    object-fit: cover;
  }
  #capturedImgEl {
    width: 100%;
    border-radius: var(--radius-sm);
    object-fit: cover;
    aspect-ratio: 4/3;
  }
  .modal-row {
    display: flex;
    gap: 8px;
    margin-top: 12px;
  }
  .modal-row .icon-btn { flex: 1; justify-content: center; }
  .modal-row .submit-btn { flex: 1; }

  /* confirm modal */
  .confirm-icon {
    width: 48px; height: 48px;
    background: var(--accent-light);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
  }
  .confirm-icon svg { width: 22px; height: 22px; color: var(--accent); }
  .confirm-text { text-align: center; font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }
  .confirm-text strong { display: block; font-size: 16px; color: var(--text-primary); margin-bottom: 6px; }

  /* first login modal */
  .first-login-badge {
    width: 48px; height: 48px;
    background: #fef3cd;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
  }
  .first-login-badge svg { width: 22px; height: 22px; color: #b7791f; }

  input[type="password"] {
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
  }
  input[type="password"]:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(26,26,26,0.06);
  }
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

  /* captured face thumb in step 4 */
  .face-thumb {
    width: 72px; height: 72px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    border: 2px solid var(--accent);
  }
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

<!-- ── FIRST LOGIN MODAL ── -->
<?php if($_SESSION['is_first_login'] == 1): ?>
<div class="modal-overlay modal-center active" id="firstLoginOverlay">
  <form method="POST" class="modal-sheet" style="border-radius:var(--radius)">
    <input type="hidden" name="action" value="first_change_pass">
    <div class="first-login-badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <h2 class="modal-title" style="text-align:center">Set your password</h2>
    <p class="modal-sub" style="text-align:center">For your security, please set a new password before continuing.</p>
    <?php if(isset($_SESSION['cp_err'])): ?>
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

<!-- ── SUCCESS / ERROR ALERTS ── -->
<div style="max-width:560px;margin:0 auto;padding:16px 16px 0">
<?php if(isset($_SESSION['report_success'])): unset($_SESSION['report_success']); ?>
  <div class="alert alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span>Report submitted successfully.</span>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
  </div>
<?php endif; ?>
<?php if($err): ?>
  <div class="alert alert-danger">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><?= htmlspecialchars($err) ?></span>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
  </div>
<?php endif; ?>
</div>

<!-- ── MAIN PAGE ── -->
<div class="page">
  <h1 class="page-title">Report Violation</h1>
  <p class="page-subtitle">Fill out all steps to submit a student violation report.</p>

  <form method="POST" enctype="multipart/form-data" id="reportForm">
    <input type="hidden" name="action" value="submit_report">
    <input type="hidden" name="face_capture_data" id="faceCaptureData">
    <input type="hidden" name="signature_data" id="signatureData">

    <!-- STEP 1 -->
    <div class="section">
      <div class="section-header">
        <span class="step-badge">1</span>
        <span class="section-title">Student Information</span>
      </div>
      <div class="section-body">
        <div class="field">
          <label>Student ID</label>
          <input type="text" name="student_id" id="studentIDInput" maxlength="9" placeholder="000-00000" required autocomplete="off">
        </div>
        <div class="student-card" id="studentInfoBox">
          <div class="s-name" id="siName"></div>
          <div class="s-meta">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg> <span id="siCourse"></span></span>
            <span>Year <span id="siYear"></span></span>
            <span id="siDept"></span>
          </div>
        </div>
      </div>
    </div>

    <!-- STEP 2 -->
    <div class="section">
      <div class="section-header">
        <span class="step-badge">2</span>
        <span class="section-title">Violation Details</span>
      </div>
      <div class="section-body">
        <div class="field">
          <label>Category</label>
          <div class="radio-group">
            <input type="radio" name="category" id="catMinor" value="minor" required onchange="loadViolations('minor')">
            <label for="catMinor">Minor</label>
            <input type="radio" name="category" id="catMajor" value="major" onchange="loadViolations('major')">
            <label for="catMajor">Major</label>
          </div>
        </div>
        <div class="field">
          <label>Violation Type</label>
          <select name="violation" id="violationSelect" required>
            <option value="">Select category first</option>
          </select>
        </div>
        <div class="field">
          <label>Description</label>
          <textarea name="description" placeholder="Provide additional details about the incident…"></textarea>
        </div>
      </div>
    </div>

    <!-- STEP 3 -->
    <div class="section">
      <div class="section-header">
        <span class="step-badge">3</span>
        <span class="section-title">Evidence</span>
      </div>
      <div class="section-body">
        <div class="field">
          <label>Upload Image</label>
          <label class="file-label" for="evidenceFile">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <span id="fileNameLabel">Choose an image…</span>
          </label>
          <input type="file" id="evidenceFile" name="evidence" accept="image/*" onchange="showFileName(this)">
          <div class="img-preview" id="evidencePreview">
            <img id="evidencePreviewImg" src="" alt="Evidence">
            <button type="button" class="img-preview-remove" onclick="clearFile()">×</button>
          </div>
        </div>
        <div class="divider-text">or</div>
        <button type="button" class="icon-btn" onclick="openCamera('evidence')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Take Photo as Evidence
        </button>
        <div class="img-preview" id="evidenceCamPreview">
          <img id="evidenceCamImg" src="" alt="Evidence">
          <button type="button" class="img-preview-remove" onclick="clearCamEvidence()">×</button>
        </div>
      </div>
    </div>

    <!-- STEP 4 -->
    <div class="section">
      <div class="section-header">
        <span class="step-badge">4</span>
        <span class="section-title">Student Verification</span>
      </div>
      <div class="section-body">
        <div class="field">
          <label>Face Capture</label>
          <button type="button" class="icon-btn" id="faceCaptureBtn" onclick="openCamera('face')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Capture Student Face
          </button>
          <div id="faceCapturePreview" style="margin-top:10px"></div>
        </div>
        <div class="field" style="margin-top:18px">
          <label>Student Signature</label>
          <div class="sig-wrap">
            <canvas id="signatureCanvas" width="600" height="110"></canvas>
            <div class="sig-hint" id="sigHint">Sign here</div>
            <div class="sig-actions">
              <button type="button" class="clear-btn" onclick="clearSignature()">Clear</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <button type="button" class="submit-btn" onclick="confirmSubmit()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      Submit Report
    </button>
  </form>
</div>

<!-- ── CAMERA MODAL ── -->
<div class="modal-overlay" id="cameraOverlay">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <h2 class="modal-title" id="cameraModalTitle">Camera</h2>
    <p class="modal-sub" id="cameraModalSub">Position and capture.</p>
    <div id="cameraLive">
      <video id="cameraStream" autoplay playsinline muted></video>
      <div class="modal-row">
        <button type="button" class="icon-btn" onclick="stopCamera()">Cancel</button>
        <button type="button" class="submit-btn" onclick="capturePhoto()" style="flex:2">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Capture
        </button>
      </div>
    </div>
    <div id="cameraReview" style="display:none">
      <img id="capturedImgEl" src="" alt="Captured">
      <div class="modal-row">
        <button type="button" class="icon-btn" onclick="retakePhoto()">Retake</button>
        <button type="button" class="submit-btn" onclick="confirmPhoto()" style="flex:2">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Use Photo
        </button>
      </div>
    </div>
    <canvas id="cameraCanvas" style="display:none"></canvas>
  </div>
</div>

<!-- ── CONFIRM MODAL ── -->
<div class="modal-overlay modal-center" id="confirmOverlay">
  <div class="modal-sheet" style="border-radius:var(--radius)">
    <div class="confirm-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <p class="confirm-text">
      <strong>Submit this report?</strong>
      By submitting, you confirm that all information provided is accurate and truthful.
    </p>
    <div style="display:flex;gap:8px">
      <button type="button" class="icon-btn" style="flex:1;justify-content:center" onclick="closeConfirm()">Cancel</button>
      <button type="button" class="submit-btn" style="flex:2" onclick="doSubmit()">Confirm &amp; Submit</button>
    </div>
  </div>
</div>

<!-- ── BOTTOM NAV ── -->
<nav class="bottom-nav">
  <a href="report-form.php" class="active">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    Report
  </a>
  <a href="my-reports.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    My Reports
  </a>
</nav>

<script>
/* ── STUDENT ID FORMAT ── */
document.getElementById('studentIDInput').addEventListener('input', function(){
    let v = this.value.replace(/[^0-9]/g,'');
    if (v.length > 3) v = v.substring(0,3)+'-'+v.substring(3,8);
    this.value = v;
});
document.getElementById('studentIDInput').addEventListener('blur', function(){
    const sid = this.value.trim();
    if (sid.length < 5) return;
    fetch('report-form.php?get_student=' + encodeURIComponent(sid))
        .then(r => r.json()).then(s => {
            const box = document.getElementById('studentInfoBox');
            if (s) {
                document.getElementById('siName').textContent = s.full_name;
                document.getElementById('siCourse').textContent = s.cname;
                document.getElementById('siYear').textContent = s.year_level;
                document.getElementById('siDept').textContent = s.dname;
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
            }
        });
});

/* ── VIOLATIONS ── */
const minorViolations = ['Disruptive Behavior','Littering','Dress Code','Unapproved Absences',
    'Inappropriate Language','Unauthorized Use of College Property','Smoking on Campus',
    'Failure to Display ID','Noise Violations','Minor Vandalism'];
const majorViolations = ['Academic Dishonesty','Theft','Physical Violence','Substance Abuse',
    'Harassment','Unauthorized Entry','Forgery','Moral Infractions','Weapons Possession',
    'Cyber Bullying','Hazing','Major Dishonesty','Extortion','Sexual Misconduct'];
function loadViolations(cat) {
    const sel = document.getElementById('violationSelect');
    sel.innerHTML = '<option value="">Select violation</option>';
    (cat === 'minor' ? minorViolations : majorViolations)
        .forEach(v => sel.innerHTML += `<option value="${v}">${v}</option>`);
}

/* ── FILE INPUT ── */
function showFileName(input) {
    const label = document.getElementById('fileNameLabel');
    if (input.files[0]) {
        label.textContent = input.files[0].name;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('evidencePreviewImg').src = e.target.result;
            document.getElementById('evidencePreview').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function clearFile() {
    document.getElementById('evidenceFile').value = '';
    document.getElementById('fileNameLabel').textContent = 'Choose an image…';
    document.getElementById('evidencePreview').style.display = 'none';
}
function clearCamEvidence() {
    evidenceCamData = null;
    document.getElementById('evidenceCamPreview').style.display = 'none';
}

/* ── CAMERA ── */
let stream = null, cameraMode = '', evidenceCamData = null;

function openCamera(mode) {
    cameraMode = mode;
    document.getElementById('cameraModalTitle').textContent =
        mode === 'face' ? 'Capture Student Face' : 'Take Photo Evidence';
    document.getElementById('cameraModalSub').textContent =
        mode === 'face' ? 'Frame the student\'s face clearly.' : 'Capture the evidence clearly.';
    document.getElementById('cameraLive').style.display = 'block';
    document.getElementById('cameraReview').style.display = 'none';

    // Use back camera (environment) — falls back to any camera
    const constraints = {
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 960 } }
    };
    navigator.mediaDevices.getUserMedia(constraints)
        .then(s => {
            stream = s;
            document.getElementById('cameraStream').srcObject = s;
            document.getElementById('cameraOverlay').classList.add('active');
        })
        .catch(() => alert('Camera not accessible. Please allow camera permission.'));
}

function capturePhoto() {
    const video = document.getElementById('cameraStream');
    const canvas = document.getElementById('cameraCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    document.getElementById('capturedImgEl').src = canvas.toDataURL('image/png');
    document.getElementById('cameraLive').style.display = 'none';
    document.getElementById('cameraReview').style.display = 'block';
}

function retakePhoto() {
    document.getElementById('cameraLive').style.display = 'block';
    document.getElementById('cameraReview').style.display = 'none';
}

function confirmPhoto() {
    const data = document.getElementById('cameraCanvas').toDataURL('image/png');
    if (cameraMode === 'face') {
        document.getElementById('faceCaptureData').value = data;
        document.getElementById('faceCapturePreview').innerHTML =
            `<img src="${data}" class="face-thumb" alt="Face capture">`;
        document.getElementById('faceCaptureBtn').classList.add('active');
    } else {
        evidenceCamData = data;
        document.getElementById('evidenceCamImg').src = data;
        document.getElementById('evidenceCamPreview').style.display = 'block';
    }
    stopCamera();
}

function stopCamera() {
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    document.getElementById('cameraOverlay').classList.remove('active');
}

/* ── SIGNATURE ── */
const sigCanvas = document.getElementById('signatureCanvas');
const sigCtx = sigCanvas.getContext('2d');
sigCtx.strokeStyle = '#1a1a1a';
sigCtx.lineWidth = 2;
sigCtx.lineCap = 'round';
sigCtx.lineJoin = 'round';
let signing = false, hasSigned = false;

function getPos(e) {
    const rect = sigCanvas.getBoundingClientRect();
    const scaleX = sigCanvas.width / rect.width;
    const scaleY = sigCanvas.height / rect.height;
    const touch = e.touches ? e.touches[0] : e;
    return { x: (touch.clientX - rect.left) * scaleX, y: (touch.clientY - rect.top) * scaleY };
}
sigCanvas.addEventListener('mousedown', e => { signing=true; sigCtx.beginPath(); const p=getPos(e); sigCtx.moveTo(p.x,p.y); });
sigCanvas.addEventListener('mousemove', e => { if(!signing) return; const p=getPos(e); sigCtx.lineTo(p.x,p.y); sigCtx.stroke(); markSigned(); });
sigCanvas.addEventListener('mouseup', () => signing = false);
sigCanvas.addEventListener('touchstart', e => { e.preventDefault(); signing=true; sigCtx.beginPath(); const p=getPos(e); sigCtx.moveTo(p.x,p.y); });
sigCanvas.addEventListener('touchmove', e => { e.preventDefault(); if(!signing) return; const p=getPos(e); sigCtx.lineTo(p.x,p.y); sigCtx.stroke(); markSigned(); });
sigCanvas.addEventListener('touchend', () => signing = false);

function markSigned() {
    if (!hasSigned) {
        hasSigned = true;
        document.getElementById('sigHint').style.opacity = '0';
    }
}
function clearSignature() {
    sigCtx.clearRect(0,0,sigCanvas.width,sigCanvas.height);
    hasSigned = false;
    document.getElementById('sigHint').style.opacity = '1';
}

/* ── SUBMIT ── */
function confirmSubmit() {
    document.getElementById('signatureData').value = sigCanvas.toDataURL('image/png');
    document.getElementById('confirmOverlay').classList.add('active');
}
function closeConfirm() {
    document.getElementById('confirmOverlay').classList.remove('active');
}
function doSubmit() {
    document.getElementById('reportForm').submit();
}

/* close modals on backdrop tap */
document.getElementById('cameraOverlay').addEventListener('click', function(e){
    if (e.target === this) stopCamera();
});
document.getElementById('confirmOverlay').addEventListener('click', function(e){
    if (e.target === this) closeConfirm();
});
</script>
</body>
</html>