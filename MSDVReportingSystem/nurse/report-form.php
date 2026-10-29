<?php
session_start();
require_once '../db/connection.php';
$allowedRoles = ['teacher','csu','jassu','nurse','student'];
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

// Student reporters: find their own student ID so they can't report themselves
$ownSid = null;
if ($role === 'student') {
    $own = $pdo->prepare("SELECT student_id FROM students WHERE user_id=?");
    $own->execute([$_SESSION['user_id']]);
    $ownSid = $own->fetchColumn();
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
    } elseif ($ownSid && $ownSid === $sid) {
        $err = 'You cannot report yourself.';
    } else {
        // Evidence can come from either the file picker OR the camera.
        // Store both in uploads/ and save a project-relative path in the database.
        $uploadDir = dirname(__DIR__) . '/uploads/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $err = 'The uploads folder could not be created. Please contact the administrator.';
        }

        $evidencePath = null;

        // Prefer a successfully uploaded file when one was selected.
        $uploadError = $_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!$err && $uploadError !== UPLOAD_ERR_NO_FILE && $uploadError !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'The selected image is larger than the server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'The selected image is too large.',
                UPLOAD_ERR_PARTIAL    => 'The image upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload folder.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
                UPLOAD_ERR_EXTENSION  => 'The server blocked the image upload.'
            ];
            $err = $uploadErrors[$uploadError] ?? 'The image could not be uploaded. Please try again.';
        }

        if (!$err && $uploadError === UPLOAD_ERR_OK) {
            if (empty($_FILES['evidence']['tmp_name']) || !is_uploaded_file($_FILES['evidence']['tmp_name'])) {
                $err = 'The selected evidence image did not reach the server. Please choose it again and retry.';
            } else {
            $imageInfo = @getimagesize($_FILES['evidence']['tmp_name']);
            if ($imageInfo === false) {
                $err = 'Evidence must be a valid image file.';
            } else {
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp'
                ];
                $mime = $imageInfo['mime'] ?? '';
                if (!isset($mimeToExt[$mime])) {
                    $err = 'Please upload a JPG, PNG, GIF, or WEBP image.';
                } else {
                    $filename = 'evidence_' . bin2hex(random_bytes(12)) . '.' . $mimeToExt[$mime];
                    if (move_uploaded_file($_FILES['evidence']['tmp_name'], $uploadDir . $filename)) {
                        $evidencePath = 'uploads/' . $filename;
                    } else {
                        $err = 'The evidence image could not be saved. Check the uploads folder permissions.';
                    }
                }
            }
            }
        } elseif (!$err && !empty($_POST['evidence_capture_data'])) {
            // Camera photos are sent as a base64 data URL in a hidden form field.
            $dataUrl = $_POST['evidence_capture_data'];
            if (preg_match('/^data:image\\/(png|jpeg|webp);base64,([A-Za-z0-9+\\/]+=*)$/', $dataUrl, $matches)) {
                $imageBytes = base64_decode($matches[2], true);
                if ($imageBytes === false || strlen($imageBytes) > 10 * 1024 * 1024) {
                    $err = 'The captured evidence image is invalid or too large.';
                } else {
                    $imageCheck = @getimagesizefromstring($imageBytes);
                    if ($imageCheck === false) {
                        $err = 'The captured evidence is not a valid image.';
                    } else {
                        $extension = ($matches[1] === 'jpeg') ? 'jpg' : $matches[1];
                        $filename = 'evidence_' . bin2hex(random_bytes(12)) . '.' . $extension;
                        if (file_put_contents($uploadDir . $filename, $imageBytes) !== false) {
                            $evidencePath = 'uploads/' . $filename;
                        } else {
                            $err = 'The captured evidence could not be saved. Check the uploads folder permissions.';
                        }
                    }
                }
            } else {
                $err = 'The captured evidence image data is invalid.';
            }
        }

        if (!$err && $evidencePath === null) {
            $err = 'No evidence image was received. Please choose an image or take a photo, confirm the preview, and submit again.';
        }

        if (!$err) {
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

        // Offense counts (prepared statements instead of string interpolation)
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM violations WHERE student_id=? AND category=?");
        $cntStmt->execute([$sid, 'minor']);
        $minorCount = (int)$cntStmt->fetchColumn();
        $cntStmt->execute([$sid, 'major']);
        $majorCount = (int)$cntStmt->fetchColumn();

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
        } // end if (!$err): evidence could be saved successfully
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
    --accent: #1d4ed8;
    --accent-light: #eff6ff;
    --accent-hover: #1e40af;
    --success: #2d6a4f;
    --success-light: #e8f5ee;
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
    border: 1px solid #bfdbfe;
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
  .sig-wrap canvas { display: block; width: 100%; height: auto; aspect-ratio: 1 / 1; touch-action: none; cursor: crosshair; }
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
  /* Full-screen camera only when capturing the student's face */
  #cameraOverlay.face-capture-mode {
    align-items: stretch;
    justify-content: stretch;
    background: #050914;
    backdrop-filter: none;
    padding: 0;
  }
  #cameraOverlay.face-capture-mode .modal-sheet {
    width: 100%;
    max-width: none;
    height: 100%;
    max-height: 100dvh;
    min-height: 100vh;
    box-sizing: border-box;
    border-radius: 0;
    padding: max(18px, env(safe-area-inset-top)) 16px max(18px, env(safe-area-inset-bottom));
    overflow-y: auto;
    background: #050914;
    color: #fff;
    display: flex;
    flex-direction: column;
  }
  #cameraOverlay.face-capture-mode .modal-title { color: #fff; font-size: 20px; }
  #cameraOverlay.face-capture-mode .modal-sub { color: #c4cada; }
  #cameraOverlay.face-capture-mode .modal-handle { display: none; }
  #cameraOverlay.face-capture-mode #cameraLive,
  #cameraOverlay.face-capture-mode #cameraReview { flex: 1; min-height: 0; }
  #cameraOverlay.face-capture-mode #cameraLive { display: flex; flex-direction: column; }
  #cameraOverlay.face-capture-mode .camera-preview-stage {
    position: relative;
    flex: 1;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #000;
    border-radius: 16px;
  }
  #cameraOverlay.face-capture-mode #cameraStream {
    width: 100%;
    height: 100%;
    max-height: calc(100dvh - 190px);
    aspect-ratio: auto;
    object-fit: contain;
    border-radius: 16px;
  }
  #cameraOverlay.face-capture-mode .face-guide-overlay {
    position: absolute;
    width: min(70vw, 280px);
    height: auto;
    max-height: 65%;
    object-fit: contain;
    opacity: .8;
    pointer-events: none;
    filter: drop-shadow(0 0 2px rgba(0,0,0,.8));
  }
  #cameraOverlay.face-capture-mode #capturedImgEl {
    max-height: calc(100dvh - 190px);
    height: auto;
    aspect-ratio: auto;
    object-fit: contain;
  }
  #cameraOverlay.face-capture-mode .modal-row { padding-bottom: 4px; }
  #cameraOverlay.face-capture-mode .modal-row button { min-height: 48px; }

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
    <a href="report-form.php" class="sb-link active">
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
        <?= htmlspecialchars($_SESSION['cp_err']) ?><?php unset($_SESSION['cp_err']); ?>
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
    <input type="hidden" name="evidence_capture_data" id="evidenceCaptureData">
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
            <canvas id="signatureCanvas" width="600" height="600"></canvas>
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
      <div class="camera-preview-stage">
        <video id="cameraStream" autoplay playsinline muted></video>
        <img class="face-guide-overlay" id="faceGuideOverlay" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGQAAABvCAYAAAAaJWWXAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAEzgSURBVHhe1b13gFRF1j78dE/ODDCBnHNGAZVoBBUzigoYMOy6urpmXV3FDa5rfHXXrJgzgmJAYUkqUXLOOcfJebp/z3Pq9kxPQnD9/vgO1PTte6tOnTrn1Al16972IaNfMHn4rShoPRg+fykCviD85ZHwBQM4HgggBsHIUsTPmYC8eRMRRDn8QSDoV/sIXosCEushIrkByiMiEVmcj/LD+4DSQl5nxUAAsTGxKC4u1rf/X0NUZlv4LrgNpVGpiPD54fNxhCziRMBvowX4HeCXgI+H9oXnyowPFADPpPUJJg2/DQVtBiI2ew/8O5aiDNEo9+rWDpUX/UTsZ6/+zYtQuHMpe3VslWB9pCghuR4KCssQUS8DpQkx8OVlIXDkAAVeylqSnD6O2dlxQQhDpVArj8Lp/f8SIlPSEdd9KHxUwgDHFLR/6pufko6O7CtFEaRQPLqC/jL44zJR0r4rz2R0DyaddxdnyClI2LQQOd+9QSYXk6HHGkQlstDAfYFSE4DPF7ArAaujvnmOQouKS0QgPR1lOUcRzD0CXzk1Qk2NQKv6K8DNYsqeGuk0rowKEdAU9c5XgqPnt4Ag+/D7a+ILBsvJXI/Z1HY3uLr4yPMefeJRXItuiLjgjhCVXiOJj535AuWmwVVLWS2fnGokwopGT2FUQohZ7nwpTZW/sBD+Us4M4o+MpFn0mHhioFEIN/uneYygdiGiDOdcNhSjbxoFfwRNJq/rmsxniI7fEkR7XeAjL9S3eIQwPtUsshBeURue09AkSh540jSJ2Z9a4EQZVxU0ecuyshFJoftpD8tLScAxwHTDK5Ug5gaIiQOgIGLj/bjksuF4+bUXcMsDd+CG22/G62++gisuuRD1kuKpxdRktpeuaFTOfAiqID1hCMje1wJSMO+IJsvrkwpZd+FYjN2q7M7RZPWmybqHPqQfEjfOQ/b3ryCyrIQ+RNrlTaATAvVQc8Dqyh8Vg8iEWJRk55oWh0CEVAX3XbPLhMK6keRsWlpDdOzUAQ3r10NySjIiqKnLli3DmhWr0advX8QkJuCnH35A504d0b17dzKlHNk5WTh8JAsbVq/DgUNHIUvp6NPYQv3+mnHWBsLncAZl0nio2KaqUvEcr8XExqCwoIDX1Lcf8U27ABfdI4GcRIHcSx8igcxF9tSXKJAyCkQaHE6ojmvXjOMBI5MaIS3Ssd8IcVBVIM7kxJPgCNljVht0xhCcM/Rs7NixA1O/m4at23aisLAUpTR/FBkiy4Po0bMLImOisXjxUtM6+bCoqEgkxEWhRYt0DL1wOJo2bYapX3+PWbPnICWlPkpLyrFvPwMMX4TX928Dfo6xadvW2L5pM60BKdQ0DQMfBRIVHYWSkhKjtVIg90ogvZxA2vRF4oYF1QQSjqiamE8Y3BSNJAFlHL8flXbYCURcpG2N9CElOR5Pv/R/aN66Fb769DOsWLICixcuRl5+AX1ElA0iGIzyTATNEtt17tQKCSmJWLpkNUi+CcQBK3O2a4bFxkaje49u6NKtM6658UZs27Idf7jlNmQfOWpmzSmdyv821iBNmj86ksORfyW2ClMWBjrH/yGBJFAgwYvv4ZEcsZmn2rRftUPl188OB9R2amK5P4KDD9dI4VXhzKC0zj9/GP7y6MNYtnQlDtHEvPrym/jhh3nIK5CTjkF5OXOboISpQaqQgcQboJOMjonidceEStCoI1HGdrl5pZgzdyHefP0dLF60GNNnT8dDj9yLs4YOpumQAooOGtfaGHgCoBkQKK1OR1WoqweNhh+hciwIadxvBaJWilCOxKRYDBnUD3+8/RZO42Lcf++DeOmZFzDnm6no1o2xuccgnxKnCmY5QToDyCCBQlZ4rW9O62qC2vqYrKY1bIBc+pX33ngHD9/3COIiY3HrLX/Aqaf0QZRFUCElCS8nBtYXy7G4ZoFpNVr9zmuqWW1NQ+ePhfbEwaKLchf+nnZaXzz33BPIpTl67bXxmDp1Ok0OGUsGvzX+HV4/BRERpNEor8ogmTpFM5SDiTaKQYOEUc7rlhNUK6YExHMeZ+H4N99GWUkAJcVl+Oab7/Deex/Sr9TDv//zDFq1aGoRmoWj/HTMVf8nBmoXEVHVP9UQAutUfPI/OS1m86h6KKBzVaD69xMBUSCG0Ef4Ssi4UvQ9pRtuvmUMmjRNpx2/FUuWLKWjDgUSrhTTBu/ZtQtpqSn8Xp0hoe+iy9EWERHJs+64ZuTmziUmJDA682PLxs084drSC9Gc5eP776fhvvv+jDPOGoIRI0agNR2zljTcvGNN42RNvHWBfIkCjyoQRfNKGSkYqQAj2dHi+X9vBnj+xEnQXamE4yekJrAt8SYnxeCu++/A/73wHBnTAB9/+AUmTPia9p0Tlaak6mylHab6L1u2HBdddnGFIlVCOD3M0MukyWrjnanZwAabmZmOHdu30+1E8YxMGPtSnywKQXNyczF+/Nv4afZsnHP2mbjz7tvRoUNrtqWjtvW9E+ADafBVmyH1GmfAx+BCU1sKEhK2sZuFIyBJvBBkh4EIZpac7pShq2S1BCdISA1QWzrsSy7A8LGjaSKmYiYHnJ1TQKslZjBrZ9Qlp+/z6VMCEfiwbetO1K+fjpYtWvB8iC4nMAfCHUAZNTEmNtYYJzNTA6xtAOefNwyrmZNI65wpUp/eJ3HqWDNm78GDNKFvYMOGDXjzqw9x7fWjaMGqR54nDkd27kGwQOEuw/WK1QoFLFLKSMmiDPHFe9GgYBfiSgpJZwRKImReqnf8awXihBHJyGNQ/wH45t1PqaHbeCqEL9RPiMn6rqLrynj9jLRes+jLVy2e13WdEf/LGesmJCdaFq3B1jBZ1O7GjTKQyDr79h306ogM1Qvv1wPNGjJrztw5WLFgKU4d1J/mxu+ZlGq4TwDMh+iT/FBEGLBlKoblpUVIKd7J3Cu+2bj8TWuQv2Q28rdotbaExYZpCCqhGsEnALK9pw8ZREHswJRJX+Oee+/GtP/+lwQ5ZnDcqqU/YRAatA9FhcVo3bo5GbkfBQVatg/RouVqHfkQHxeLdu1bY9XqVWQyz5rmCVwNzZwrr74cEz6dhJycHAsGXA3+NQI0XvVZ2a+c+g03Xo9P3v8Ie3bvQcuWLbB181bWcJgrofp3+TOF5yFcxwBWiWApKjiMvCUzbOUcQc4In6/IJMUvrFULcbV0ejwgYcRER6NFq5b49ptvsf/QIUyfMRMnndxbF1XDGOg0VtrtSnjfms4yMwMHDuB3V0/mx68oyKsbpHBjopQsygl7wpA6epCaWs+c7O69uymMyvMC13doxogW54saNcpkdt8IG9ZuxPdTpqENE9WYWPketQ8vtYBD5n2pCRXCIg/KZaKpIT7OmOrT4DcGjorO/PQhA7F50zaUkGli3+TJk9GpYztjtWPusUAk+rBmzVr06XsSmtDsRJhJLSauAmLI5+jyER8bpNCbIC4uxpRA2s0pSBLKEc2suVfP7ti4aTPH7UxaTah6TpH2oMH98X9PP4cyMisQ8OPHH37E735/I3OVX2abhC6z5PxTLYV1tMRSHXz+9L61irF2ok8EOHCUUsvSzEQ9+MBjDAGLvWvluO6aqzBx0jfIzS1iXzUHWBn3O6L9FOwDjz6A9j26YN2KZUBJqTEtkn8ioiKR2bINWnTtgDVLliFrx372QJwccASddUq9VLTp3Rlvv/QqJn86kXRICWTunLAd6NhTDvYdFxWNK66+FO+98x4tOJNFWg4FTMOHD0Nefg6mTfuBLUMRVFX6ncWsxmytanrnJAg/kcUmxHP8uYw3Knkd4UtoMs47dq6jGp5fD0HmG8Dll1+MN157G/l5sv1ikq75+L0AN918LbVuDgVSs9NKgTDqo9c++aQeGDJkMFIy0vDKs89j+n9nYt78hZg7dyEd7wKsX7sebdq1xyMPPIxZU2di4Q9zMW/2XMyZ9QOKigvQtXdPxDCqObJ3Pw4eOuI02PCH+g5XwDL05WwsLirGlm07+F11GGDQjm1YtwFnnzME62jGSituIVQViKqH+xAJRyu84UKSupYwELEVbX4PXWGU5eynCNQ/SS0mPtYJpwaEzMsvmRkHaQ3qk/G5OHL4cKU512xg2bx5C0qL5axbsX/hU4WwogYsiYmxuOPOW5DRqCH+8uBDePc/r6CsoBQ5WfnIzS5GXm4QhdkBHNi0C+t+XIisvUcpgCDyGJvkl/Ia85MuXTrhgVvuxNN/fRLNm7fA1VePdMpqfVQfCyNCzrrrbxhLYS8gbd5pAy3RAPOoBKOuvoJM1qkQ7VUhMTHRCYGX5LuCnAUhAVVYHzmqauCcuhHnTgSZHWsyR9MRR1PFK2JlFtMpxn0eWvtbG6hedIQf1/3uBsyd8zNPGOUsoU+FsxF48cXXMXDQAJvCFUQKLweprKBls6a4/rpr8M3X0/Dl5Kk4eDAXy5ausplieOQMSXyQ9r08kjj9nqPn4JVbyeGnJCWimFHanh17mPTl48uvv8Punbtw3713olnTJmwvW89iY1ROAgw5fSCjsc+Qp/sVvB7U2oy4Ytf9WLZiDeITEtGzRzdHh/Gikh8SQk5WNt2YAgxC5SUDmzHuitOJsOsMJ8K+8bCoqAgF+fl2R09xcpnWsm3wtJfRMYiv3wARsXHuXC1gHXDKDzyjP9at2oBduw/yu7O17praOcFkZ+Xwr0wbB8pTZqakcWTqOcPOxIDBA/HZhC+YnG1iPdpxfxR279qHpq2aoSFnXwUjPC315OMBhcLjJk0zsZYBgd3CF2NZYRbN2Qfvf4wLL7zAAg5R4yBIU+NHixbNLRI0ZJUIDSQQP3nxyiuvo2+/kxEXybGR3iCDh+pgwgw/9koIwgURAiqnOq12hecsweKhWxyTljCLLylGSU4WAkXyBzWnm9Wnlrbr1Aqde3bC159PJq0akBuUjS9MIP7IaNr+NRh+4XmMxanZdMUNGqTi5puvtyn/EeP//fuZxFlrzSIlUgF89slEnHve0EoBGqiWxxSet00aND2nDeiPzVu2u/PWt2ZnELv37CVTX0OrNq1x0UXDqfGMztQ/w2PNpFJTRIGjvRL0PYKKWopc8uHNz95DmzYtxTKSUAtPPN4an0MQflwN/DWz30oQMjHApphfgpHu64LaVOqVwLrl6Yy0+nj1nZcolHY0G65tOfsoN4MbAhHOQmYu+XkpTdBpqN8gGZ06tMGYMVfS0c+zmSH2Gl6aPI1B99J1W3Y1NT6lXjLi42M8XKojmqSRZDgFop0cEm5aRn3sO7CP7WWWPOaIdtHFsb399rvYtmUL7rrrdnTt3gG/u+1mLP55iY1XvKnCSA90pry0HFFx0cho2xJJWvw0HRP+mkIRSL0d24iTpeJ7NfAnJicjLj7evqjz8BICCUbf/Yy/fTQvGnb41AuHgwcPYvP6jVgwe44JImCDku0N/5R5UXsyhQObPWceXp/4AUbfcK0tk6xleymbTEy40oVIUnqxYd1GNGnSiBiEx804CSSkKFKG4Redj88/m0RtN4q96wLSIC9FeuRXFy9fiWefeQp3//1hDLzgXAqdOMitEB9UKpTIWBlAJEPtIvqJzVSodevWePWkOFXbVS9R9MsJ8QnGv9p46C+i4yplTG9jVQWvhBqEimZKpHxIdKyrUwE6ll6qXYBRSBC7tu3ElMlTSLbjoBTHKaerGzoK8F+zti0w9OLhSKqfildffRXF9F2mwdbWlRANxiRe08C2sY/ePXt4gvWA1UN9JackIT4mDps2b7b6Wk1Wxu/wEZVX3OxjUMDxbVq5BvNmzsZFl16A2Bhm/aKykvgqEBMdhdR69RgsbEdDflaCG3M47xztLPTXpSUljDzz6kILv4ShhTnjUHipBkJaWlCI/CNZZIJz0pUQ1ohmKCM9E1m0wxJT1U5DX4KIiYk2hl525Qj88aZb8ORDjyE1Jdm7LsaFCyUE3ncyccuWbeh1Ul/vDh/PyqNzwGI+JyUaN26EVatXk24yXLh0vQo+4dGnzjNeoeZuXbMOj93/EKZ8NQVPPv0PdOjQjlfCxuaBBHXjzWMtD1q0cBGat2rJKiHcIfzVoHLoptx1gaPmOMFwcsDuo2bHOhMbF4cyarmcucvAQ0SqSEQBJMXHYex1Y9AwtQFeeOY/KM4rxtyZP/HcdUhge9Moqx9Ono4rSxkTgqnffUfnfo4bJNuUULmsHbXg5JN7YCuduUJixwv3tya482edfRbWrltvJnIjZ9UD949D1y6dceuttyA+llaBiianr6LlmxTOijUrVmHL5q3ITE8jzTRldXVxAhA+4t8EGtD07Nmj7NbdAHIgSrXL0GXczz7/OCbTpE2dPguFRczgSEZubgHeGf8+zjjzdH5X6KAS0qSaZGrwM2fNpiPuiKjoQvakBcFIY0xcXJTt39q9aw9rUiCWlFX6AEcPP81ulCE5MQFDhw3FsiXLNclY14f8gnx8PvFLfPv1t7j99tuQ3jDVdq5EsOPTTjkZb77+Fsqo6Vu270BGRoYpaG1KWh1qM1Ph8BsLhD6hZSusXLaWg/J6tnC2BA0bJuOOP92MhhkNqHX3YPfuPcZEMyc23SOxdPlqNExLN4bWpFx1KotzoBFYunQ5GjdtzGPlHXTUZPall16MyV98RQGprvM5VSGEW59BW5964bmXKAzv5pjnq2RZtm7djrffeQ9nDz0Hw+nr4pPjkMwcaN/+/bScESjK1wKnM5W/BG6YVBq2q00u4tlvKhAR1piRz+aNG0igNLEMcQl+vDT+RTz7n2fw1vh3MeXb71BspkXgmOsKzVBpEHt37UaLZs34XSQLRwjC67qiXGwbfUn79u3ZH08RR1xcrC2P/LxoCRkaGrZdrAFiQHxcjEWZ69Zs4BkledX6IIo9e/fhw48+Qym/fPXjdGS0buFCa808dlxaXIRI3c8nvgpFrAOiExIQlZwCn3wfUwG1D4F8ny2daEumVkwtRmaF45F2DSCBapWenoHte3YTRznSmFvc+LuxaNa5Pbbu3o0jzMwrdUCf4YU6GRmF+QsW4eZbbka0CLbBhQ9QAgoVnueM2LJ5D0479TTLsLUTsEmzpvh5wWJjrIbh+CPKqvfnZ97oQ0fStn3bNjLY5Vk6r5nnwM0uzRZl4gvmz0c2Z0QqZ8jwYWdT2XmdqPLyCxGj++SEYwnFR5pKtCCZmILI9BaIadgMvqSGQDzNYVJ9BCNijTKjI7RmdTxSrg3UIiI6gngiKGU/Bg/uj3/86++Y8NEkjBh2IZYzIhFBzkyF8pHw4hiSRaHNnv0DBg4ayMGKQTzr1akKjsai4mKsXbMeffv2VUV04mzZvGUrRSbjpTrhsywcqBoc/fXXX4OFCylAA8cOBxKGE46ZMeLLPZKNfVu34p5b/oTo6DjccccdSE2tb1l9lEdeTTqrA6/7ohCISUZJfD34UhoyENLNM+2Y0bIMaVbYa7kIBaGbKpo14eV4oWF6A7Skxl0zdqwRdtutd+LA/oMIFJUxf9FeXTE9fNDh4Bisv998OwXt2rfioMWMkILUVgQ+fEPHO/CswUBSLLr27IZdNHvHBhcxDRxwGmbP+gG5zAtcHyHhhfCHvosJQc6+Rlg6T4oFTJw0GZO//AqXjbgYPU7rh94D+7FWtS0/NYCRGFkQoCXQphJfaSH8uUdQdGQHyvL208IXeNxhB7YfVRpBraz+MMrxCEVVzj5nKHr364OjzGBnzpiNUmbhMgVlpQFEc0oL97FnoDNF5WXl5vTTGirh0jmV0EJK1aJ/+YWF2EBfMvSSCzGPDCtlKs85xet1ADkaRWFrn+/ECZPNT1TiF4T3EQIf2rRriRnff0/6XUi/adMmvPHia+jUvQseeuxRJCclenWPAfQ1WolGUQHKcw+j9Og+8t4WpAyqcN5IKNcNoQjLJ04I2Hj611Pw03fTMGXSV/yqGzQ8zUhHzjc3Jx+NM9M9NnEW1hCK992mfCTt9c+48abf0Tfwu1SSxQIFr9i9cx1TWEoyy8tKmA+kc3bsooPVsMRgCibUj6HQsWuT2TgDW5llC8zM1JCfcITYw3ZkWnJSMnbvZXRlGqprnMEx8Zj6xbd48YlnUZCd5+rWBcEojoyuIScLOMCQPO8o0WjzoIvSVKreMSTBmh0uc6fuUXXUdQ1aawMjshzNGWXNn7+IbWQPnZ7KmkcQb99T+mHFitU8I1NkjcKKBzwvVPkFBWjaoikapKVh544diObsiuIMTkxMQntq6pAzBuDKUSMweswoXHTx+baJIi0l1Rh9xVUjMWzYEEZfrVEvNR7lumVLrYig0IQnPiUeY24cY7M462g2O3SmtNL+hwSh7zqm9fAHbEatWrnGdtc7EBPL0SgjDfNn/YSc4pDJ0nhCuCohIioCibFRKMg6TFaVGF8Erqb315fWJ4wblWgio6K8eyGVULup0Tl3PjY2Etdcezlef+U9DqAyUtH12JhIvPDic7jpxtu9eD/cRIQB+4iMYHDAaw8+9Rj6Dx6ICW+8gyNHj/AiI6miUmRnH8XBw4exf89ezrxc2yTXOLMxzj//PLz2+qsUKMPfhFjUr18PDejXMjMzkJCQaHvDIuPiMezSiyxSuuOG32Pz2k1UnEgmeWKGRi8mi2Z37GikFgcKMfq6a/DRR5+jpFizk6cJutd/7vChWPzTAuylcKWI3hXvsyqYwsukegjkKgT2naVGK1tur+ZDjhdMYETquhIOmib+1W3PfGbkeXnZSEjU0ogjogZwcNp325Aa949nHsfWdVuwnRHUl19OxOefT8Knn07ApEkTMf2/s7Bi6WrsP5iDAib6JYhCPmdzVnEBSinMMtKfW1CK7TsOYMnidcx9fsDnE77Fl59+jUnvf4KdG7Zg4U9zcNNNN+J3N491W3vM/IlaFUHo2JWIGLeRu6RYKwvh4EwNpW3HvwT2uIQHsgSJKclU5FjvDGdRuMkyUKjJ/44M/lUf7tSxgXZQcuzTtw8W0bHKD0jeSnzs8S5CFGPD6Jg42/AmM+Cwuj7iYqJw+ukDMHjIacjIaIgP3vsQ8+fOx9LFi3HVVVfip5/menW194qzz8LRkEkEEpjctW3TAitXrCAp6tuFq1asRpB6Xo7k1BRGfFF48fmXMGPaDBQXl7PfwejVqwdysrNYaMLCQEvtsvEnn9STCW0xtm7bYbgrgPjbtm1hm8LzCoq9vuyC91kVjGbxxLss5dcz+iZUQq1TQZoepVuTJwgafEx0PP0FHTkHEaDWqWN1JeHMnTvPsvBgBM/TJgcDxaxbjg5tW+Lf/3kSBVT38W9+gM8+m4wDB4/QZJZjx47dFnV17tShYjAuN6g5YHvIk307QXh1wxgUwTH1798f69ZpaUdZuB8rVq3Gex98wmhrIsbeeC0efvReOu9Yti2jtdAeXGo0Z/Sw887Bxg0bq+I2GrRyzbxCPkGK4lmG4wFFneYWwoZSoyX5KInY9JQttwOd+yVgHW2LUbYcUGREzbJmas8jObCS3AJGbzGIZCSXnBiP4XTG1147Gl26tsddd92HuT/O49il0epXOFj4+QZ9yJDTB9GvacC1E2O1vdngfF21woHVS07BSSf3wpp1m6xuOOQXFeHRR/+KT+kjLhlxCUZdfTk6d27HZuWc2ZFo37Ej9u3dR1TVza14xdknu2z9Hj84Mqu2qV2UrFPC2L60qJh8dR3V7tDDQSxxdeS4zNy5Hu1TiVh0iQ+Dzz0HDz/5dzz+xN+wZeMGfPjhh5jw+Rd0zgUe/71++CkFlCbn5uZj/759SEvTxgZn68O11DRVn0KgY9UwNF7/BkFcPnIE3nz97cpl+jCQbw0EIrCO/uX9dz9i4vcVhp07FE8/808MGjIQ2YXFVieErQIUfvOfZptbgq9Ro24QCeKNR7OgVoHYIDk77NmGsMq/BAqTxbDQ7VljqnIBOutE5jX9zz0LmS2bonmb5li08GfOjG7Mlvuje5euqF+vHt2X6qstTY+aiw7r38dweRXOG3qOjaEuihwrnGBk9yshiPpM2jR77dkQw+ld10wMFXJcN6oyM9LRpXNHbFy/Adt37sGdD9+Pbv16oU+/fqyjp7HCZwnD8ehoaWElnjoprASjgfwSyxw9Dmo69f8FKO1+p56MRQt0X4GJIc0vgzw0aZyOhx95AAsWLsbLz76Aryd8iRUrV2Hrls3mH7r36InLrxiBMdePQc+eXREd6WNElosS5iKBYBn8UX4cOXIYF19wITZs3IS8bC1S8p8JXqYxwEglCq07tMXKJcuUDplQOFJeL0ckhdO1a1cc2L8XuznT7HFrXhFtMbHM2Lt2xIiRl+D6saNwxchLkdEoHUcOH8Xqteswd9Zs/DT7JyyeMw9DOFPSG6a5PcKeTERBZ5qzTVu2o5hmT4ij6FP0rEppKSMy0eD+VwIbR+h9KEw2tatHUZZCd0GNPOTEQc09FJwJN908GhM+nMywswDt2rTGKRRQVHQkJn0+GYeOHLGaYoc+bbMxi8ySZdFkcEZaKlq1a4MWLZogLj7BktTC/CI6vyDSGjbEmZdcgA9eG4+9e3aZZkkxoyMi0bpdW4xkCPvN5K8wb8aPFmuXMbnQrGjcqhlGMln8buKXFijERkXbSkRsfAwjnDLso6C2ksm79+5FYUEhaRNdfnusWSsNfgYD7n4L0H/gqejYoSPW08HPmzff/OZlI0ZgypTvkJuda6OLIP4YhsnFDJHtdi3HZkm2KYhyPK2uR6GEAtNEllBKvXD6NxWIjxHTNdePxNGDeejQoRWJLcJ7736Io0dzRSajLqNNNe0tQvrkMG2WuwhFMbobgISrKEQOVRFfJGdcVzrkp15/Hu+++AbWLF1IjSxAMTWrnCW1fn388b678c4bb2P/rn0Mo6MtxE5MScHwa65Gk+bN8fxDj2EeQ+kABaV7G2UlZXTGnGFkVHUnL1/g/KB3QuAt1cRzNp56al+cfc7Z+NcTT+Giyy61507ycgsdHibF4WbIjYd+xhJt9cVxu+FX4A9Z2N9OIMSYnBSPx196Cp26d8W/7nkIM2bMMA2zEDMUu4fZWFkca20MqRyAHLsjUHjlKFnIQM2GDh06MAq6GK++/BKymDP46IjL7datsEqIWuaguZRGsrRs3QJDh52FzcwftLJbrFvGrKxHqDULZLj0Ee4VdE5Q1UHLDqqW03S1adqoEZ7599NIb5yJa68ai+2bd/GqC4sdqJ7b0WL7vDQejsUeiXCXbYh26H3W7kNEiIgmMuu8GsNqBV7WFNTOv/20p927dcHa1RuQS18QsH1RHIFG4RVidASy6OZP6N6HwG1O1kxxbw2S57MEky1k23fv2InRY67EggVLOFjik8kzvMLhsEpQeir31ttuwQcffIjly1eaudI4XPCgPtWEuOWk7Zxr74quafwhHuhs6DwLcQ09d5jlWronM/mLb5jFC78UT0qoeqoos8qONB67QnHzeywT2Sj6ET2lGxKGoFaBRNH+6V0ccrgeDccFmgmZ9AFbN27Bl5O+xiUXD0fr1q1x+MhRWyw0Ir1/AjnXdp3b48LLLsDRrKNITIi1LL1R4wy0ZjTWvmNbPPXcU2hPp/vD9Jk2QJWcnGyk1ktFYnwi9u8/4Gk3B23F4deGhEsvuZC2fj3WrFYiqLUDrRZEYOwtN+LSkZfRv+SjfsP6zN6TUC8lkb5Fm8sj0Pe0U4we2XX5gJD6uP6D6Eh/denlF9ut3XfeHI+G6fWweOEyKpHrX0qmInlolsXRX5VTqURE6GpA+6ZZNJOZJ5vhUKlVIJpQgXJmqjwWIccHItuPRk3TEBsdh7XrNmPx4iWm0b+75XdIoE3fwghJGYojO0CnHYt7H30AQymQls2bkCkpSE1KQhyDAN1313JEr1P6ID0zE19MmGC0GDV02Jolf/jjzVi4YJHlS3pOrwI3mdaqbTM0bdmcTv4bG4/aacdIUr1E/P6uW+0+/Izv/mur0BkZmWjVrBn69uuL8y89D5cwY+/duRPmzJpl/km7ILUtQQo0avRVaEuBfPLxp1i9Zo3hPrl3b9uVX10goaJoS/f3zQR6QpGgK55R4Z+QJa/bh7BzVdJ0Ck2p2pMenVNxGPv060oH2gJfaqM1ByIm6Z7GeeeejRYd29OslWLjxo1o1iANDRo0wAIKrXXrlpj8yWQmX0XsU9M9RKH8RgCnDjiZuUsLTGAWXVZSzoCAjGUkc9qw03HDHbfi8/c/xu5du81Ol1Gr27KfoVddjOfHPYFFs+dZMCFl0Qrw1WTosqXLsI32/vChI7ygpM7e/cDuAvY8yg13/AHL5y1AT5rdAwcPo4g0N2rU1K5rc/iMmT+xncwqGcrA5aY/3orxr76LMvoyFxzwWg1e6bzjlZkyD3Qmin3q3Siivc48xNDxjwmEh/o0Hv0CJNGxt+vUAcuXLKEmlyMpORGDBw9iDD8ELWmCBg0fhv1MtiZ+/BlmzfoR23fswrJlqzkjyjgIJaIhLRPwk9zcsXMHWjRvSmH3RQ5zkDPOHIKxN10HHxOyPoNOw8IZM7Fy1WocpWnMys5C566d0KfPKUhNTMKws85AFv3Y4UMHcfPtt2D6TNZdugKFecoZ2Be7cHrqdoGUUOCL5izAzm3bsIhj2MtQ+NJRI3EJs/zsnBxM/W4qso5m2dMZwbJS5jhAr0EDsGT+UopBeGTWjHiCcZEQPqaqoBoSXkDPS8h8+RObjguXWAXwVGhmuG7YsHacYeBj9t2JPuFCC1e1itr7pN72XPinn0zABAphw5qN6MQ8Y+fW7fZCMRHr0+5C0SDnVwEymGQVz6nb5i0a4Q/jHkSvPidhwcwfMX78W1i5ehV68vurz7+B/bv3I/tojgls6ZLlOOec0/HQvQ/iJ9btwajv5jtvx6BhQ7Hgp3nYaY8n0ISwO5uMNjBnEDWTDKT9pOmkPn0RExWDSRO+wLQpU3HWmWdgQP/+aJhKH5YYTxOYgnZdu+DnnxaaQjk/41BUQvi4qoJVZV9sbMcVM6S6UKrjPJ7ZoX5HXj0Spw4cgHk/zMLnEz63ZXPtwy1U4kNtP7BpOxb8vAgjr7gMu/butweEZDJM9vzjYikVCoQaIed80YXnMJvvjAU/zsOEdz/GUpo55RH96F86d++O6V/PIB7lMATSKVM5cvRIY75M2apNG7B2hYuyutA35OfmUPP3WJ82LPZjwuAXt+RCU1tealFU40aN8f4772Pj2g0UeDYWL1pqS/w5Rw6jectmePiff0PTpk0x7atpHKOW0RWVCWk41C2Q6lDFh+hxAz1fZ3uHpCG8YhokQqVOtYKaU9u841bNG+Ohv92PxfN/xhtM0hSocSJa3K/8wlY7OOA0hsevT3gPkTQ7Tz/2D9v1In+jPrVdyM+I54prr0Z6Rjo+fON1fP7Fl+Y07Ukqjlg9Pvjg3Twsx7//7w3k5CrTlRDL6bOC+HDSB3jhyRcoxAUo80iX0kUzuLj+2lE459ILsIomadrX39mmNe0C8WqxfRSuumY0DnHWPfbgIyhlOyW2It0ERoErL2naLBMPjXuYAs7HX+4ZZ880aoa5UFq1bbAEl4OZ0lPQoftDtUHFFTUtZ0fRKUmmYepXoZmmkl2vIfWaoA5zjtKOHz2CDes3YuTlIwyPa0yhmFBZ+P0A7f0br7yFLavXYc/2vdi3+yCjqsPYzU/NnJ0MKYMxsdi6ay++nTKLtOhNcto4QWEwx9BySWJcDHJzsxlSUpicSRU5A/9l085rkdB2v1v/ru9S+qpPP/kCJYUBFOSUYd/Ow9i78xD27jhCOg6zHERpYTk69OpOIeexHz0UJGWSVkoRHB4tdwwePBj/evwJW4TUTSZWOyZYgis8xwA3Q1SJjPdYb8fSxYr3AnKw6s2YWwOM4/ZXaJJiIvDE80/g9ltux1133oOvvv4K6zdtY44nLXF+QYuB6kM+I4I92XqPX9tj1Ie0LGBMNh9PxgdLOBBPq9x4fGjZIh0DTj0ZaU0zmLW/i4J8T8N9WrAL4pnnn7Qw+z/PvooA2+o+uxsF+6WeaTNflJdUFmmTpOHlNfXP9in0DUVFBXYbQluZnEm3ShxGGTq0b4F+p/XD5K8mY8Qll+Ht1z9AqT/KhO/Y6dFjnHG0O9D3OoANbSuppnEUi/Vn9Z1GmOnSQIxROv/LIJ+gh/XF4zdeewODBg9kmOrZdwM3rd2MYT0R4EZAMVAQ7KiM34P2+iQyUg4vQprvSmhw6Y0ymOuspUwl0JCJcKBjvRMlMTnFVl41HjdTdY242aacprkoKoiiaCmDbtOqkDahZ52crFwUF+olmwqnXHtTFpbU1BT84bZbmfxORlJcvN3a9STqQfVjr3MrdYNeJsDxRDA6da83sunulROFUAstecgm6zVL2ZzyOxnWnnfeUArJq+CBY24I6uqPdaoMtBKSkhJxcP9BWzopYxIrmuWfbBMax1JaVMIZshHdGPU5YQlPSKB19VcVnBKQSVVoDeJMht3PPvkcZ2UB4mL0ulfmT8JptP4y4+uCco6DSSg1hMVoljb+D2CkkDGKmbTZTgOa+cMPaNm2JTp0bcsRhs8UQYhJMmchJoVoqE6LvquO7HAxmjbJRBFnQWycHrHjlTDaNaO1u2P54mUYMGQAtU4V1LfMyK8smkHE06B+MuolJ2HX7r2230uv7dDbUhmzEESD6lcf5/GBZiFtgjfdTSL/O0gICgZC2PTQ/hvj30Zf5guRUYqjdOVYfemaBlUbOA3UjcyM9HRzukFbzBNUbac9JvsZGDRu2kyuyg3P7E5duI8F1H+201O/f/37XzB58tdmJjVWPWtoi6GyLFY3hF+fxxpn7VDxao0KwfxP4Bx0cWER3Btz+J2lML/QNkBfcsmF7EfCErEhgsOPjwM46mZNGtsuebuBZH161zzQMPLy8tm9D1u3bkHDBg3YjA3VRZVuxMJwc3QMINKzzxqM1159A4cOH7IZqTAhLjYeJUXKP4hNQjlOdHWBvSJNps/un3vHdZjtXwASY2EtzUVJIfOLKNsgp3Oyr7NmzkbXLl3Rpm0bV70GiFPhnHUCrVqED+g/YACWLVlqd/JkqaQE1rW1J/G6G1dcZqvWa1YuR2bjTHe+Br7QQKufVwmBjn1IYTqgm2BrVq/id3bKSEy3jfucNRBNWmgWythoDP8bhPX8q6RQFUQPfUcuTYmex3aLhIrS6HjLAnj2qWdxhrbzqCvdcLcG4UWgi3XTIi3s3bs3Vq9aQyWSjuqfG0aA6qkubc6VBelHgszI9yM5KYk1asN77L4c6AVsUfjLow9g+rRpVLZyy95HjB6BP/35Ppx+0XBGkkNYR/mKlIMtTDDHg7smcCRsbO1PvHFVUHt3dywvr9DWspxzE37NHiA/Jw8FxSW499GH0KJlMzbhdbPpIiAcaqdFpi4xMQ7Z2YfttX8mhgocDty6lCIW2XgqR04B0tIakATRUlnPgfqt3rfAO0e8CgiuvPYqbGXW3ouK8Pd//gNXjxqFLcs34dm//hNTP/sS418fj0JtcFC7CnS/jp/+SElTYtUmtF9nqzwQJSwUrN4z5R73IhNUJA2vRhmPh15+Ic44+3TTAbd2pCL2qtRFg3AH0aRpIyxc+DMCTBhlYpWkuSRM7dRe43CPt2m5IyenGJmMyOx3OiqiPNUPFUdbJXjnKQzR16J5I4z9ww3ow5m9es16/OPvj+OpJ5/FkhVr7NVPkcz8d+3YQRqEW0oQouPXAYfECc+pn56R4Z36H4AM061YZbh6CNKcHOkzInleLxDWzZ4n/vwIEhPiUZ/ZsIXdxwkapt7BtXnTFg5dyyh0rMaA6qBO/bazQwliQkKye9ZcyldjRlYXiEBez4fMjDTcd/+d+OqTiXj6scexed1m5OdzJpjDUnTnI+44lxh6SueoPD6BmEWvRr4/SE3WWvzhPfuInoSI5tporBOEMVTc3wJFWcz8IxSJhPE7ismiHln571ffY8KESbjz3nuRkZlJoYS0XB2HOg8dh4pypTKkNmiIXD0Yo1M0SXrASAot4YTj0IqBPRLBwejdu926dqaMyCjjgKO1EsL7IfBybHQ0Lr74Ejz00GP4+L2PMPKii0yeCm8tvuKXiEA5Ysg/0RVSPJdMVsdfC1BBtV/AvW2pEvzaEqOncI8DxTEg1FoaC3To3AkDmM2agbBR8Dw/UlJSUErbr5ek7dm1B089/oTdd482hxjGEIPQd69IszmbGmU2th8Z0ymtT5lA1EdYXfFDZjM02KWLfsagMwaLY/xW20jD28MerX7gITrx6f/FkaPZZpL0uqj0RlQeq0JzhhKkNKyHtGYtEZfoXt7jcNeGvxbgWBT4VBeexEk6gvYGuf8dtBM8DkNOPx3njbwMN912A9p0bEttcvePR40djSW6kyhNpXZn52Rj+Yq1uPGmm0hGKA9SkabXBO3w04aBvHzmGBy4Xj+r91YZVDFFPgq+kGNSyA3s2bkbmekZ0E/ZHQuk+7r9cOUVIzBx4iSs37DJrbVFROH5Z1/AgMGnWb0ohondu7fD3Q/fh2bdumHosPNMUMcd9rKenyZdK8ShHYshiIiMyxzn89OUxCWyQtHxyrdO0HrMtG+mIP/IYUybNgMXXnA+rr3uGlxxxWXo3f80vPPqm/bGH5c3RFD7djHRS0e7Di2waQN9g8ZU25SnwHp0724/+LJ6zTpjXv36ifaCmIULl7GCs+khs9G8aX3k5OfYpr0IzqL2Xdpj0+YtKJAPqAMiiPuyEZfak8Pz5s/nGWfi5BcLcrLw2NP/RNce3XDRBUMRFRuHeXPmITrgx5uvcUx6irnCd4j+uoVje4E50NCuHvmrEPgDtOtiYkH2EaILRQm/HpSoHTichQapDXGQfunl51/EfXc/gO379wLU2KTkJKtnxMvk8GjSF1/RnKWia7cubvKQ+ZXFMTkpKQHX/v56FBbkkwkyjU6bRXvleHTgmFis+91kpJZqtLKbRxPWf9AAdclqJDK8eC2Hn3cONbYEM2bNJL94xi4Jn3LNSEQz5A5E+fDgXX/Gm6+/geTkRLz64ouM5PTu4F/gm5tClkCWlclnqm/9r9rOH5PICCQ5maedtAR1CUX0GY11goavDXPl2L59B/qd2o/MAHILC/DX+/+Cv/1lHNq2Y6Zu6PWHhX1JKJ9+PAG33X4TGjdpXHHJioH2NsUgjeFr157d6XC1pC4TlkCN14MyFRUrQG+6tmSQDI+gT+jTvx9at9UCp12tUnSqC2eQXuam5891q9cuVUDQ3nXy5Li/Ytyd9yGPwtY+lfoN0pCVleuEV0lsrWBL655PC2X11YUh8JeWFqCkrMSey9MKrSrXCaZdNsaqQOTaxh8fn8BrbuLO/Wk+rrzyMgtL1a1evfjzzB/QvGVz1pdTdFuEFAQogZMZu+++RzH8gmFI1GtqpUEGjmi9ru/z9z7A4nnzcO7FtNk8p7cpFBSWVOGdywfcS9DUd3x8NMaOvQ4TPvwUu7Zus0grNOtC5aSTe+BM5kVffDGZpBFbhbaL9gDSG9bHoEH9MffHuewrwiiqn5rMGep3m7PVhG3cp3yhhTMVoD6EknGi27gtgYjqUDdh4C9lB0qidLVMzre2vICdCGlmC70UphY8vKaTWsqwfmhXs3JysYa2vlnTZrzsbr1qp7n2ZTXW2pI3CDV0jImgtmVjxvQZuOf+uzjgFF5RcqYBBtCkcWPMnzsHs378kfY7ChcOH4Z4zm7NxurCE2i3fExktO3rXbFiBWbN/BGNGmUwj/QeUyNu/UhYk8wG6H9qT/z7hZe92ebu5Tjts8Fg0OmD8c9/PYfyEvVDQfPvyKuuxPx581FuzrAGRww0LnsKjWB8JZ2aJU4ghr0G+GOSG5KR0dRsDr5iYNVADCccslyFiIyBlUWgV9cV5ObQWctmazk6iG8nf4PuPbqyQTnbkQgyferUabjvwbssAnJNXXtl1drAsJGOPScvH29/9RnG3DAGbVo0R3JCLFq3ak782cbMjz74yF743IcmMUiN02PNEiip8XBqycOHiy69BEUF2Vi0YB7KSgpte2irVs2g1Qm9D/76P9yEZ958EfMXLSZeNfTw0NG4sUmoQBJ9h/Z1mZFhtYSkeHTp3J35zVajvDoYs8VPVrafYmWxBJYXSpij6QXSsjI1LA0hIj6t47hImiptzdc0qok+DDidNejqlUwo1p9mksyRm1El7Lj3ySdj1arV1BA3SP3ol5mBzHRs36Z3kgiZKNMntYlUBgJFOHlIf3z/2ZcYQlNy5/33oMdJPdGSPuCUU/ui/2mnol69ejhj2FBGpBH4ccZsY4BMlR4mjYqJwJibr0dXttm0ZTNiOYvASItBJs4671zUS0jEgw/di8atW6E+w+EJ73+IrKO5JIPc11iMFmorcV1x1eVYv249du7Wy9Dc+V49u2LD2u3Ytn0XT2mGVANWS0xKslFJx+1R6FqYXxv4kN43GBEZi6iEJBRnH+IJ17LSDFSCMb42UFUTd1gbCkZaMWr0SHynn6k4mM32mr40kpEBXHPNtXj77Y/ZRDgNAQuvB4twzdirsHL5GvvpPM2uVq1aonvPbmjVugX27d2LebTl5cyS9XhcJk1Z44ZpNB/zsHHTFvTrdzJat2mDnTu2Y+/eA4yays0HdOrYCX2Hnon4pDi88q9/Y8mcn1FMIT75/JO45/bbOcNFm7v/7ugJoGnTDNz2x5vw5wfG2a0Ed41CuvwSfDtlOvJyCzgm56CrAPHG0p8WMY0IXattNoSDLgs7sQVoGwtQdPSgIaprKgkMOUsU7aCOQ8Vun5qJlGPzzgcYt/Gc3p8++pox7ExLH9JiharKpIvoFzI5oFBn+qQQ6YfOPutsLFu8zKMlAls3b8OXn3+D//vXs/ZS/ytGX4mDh45ixc8rMOOL7/DZ+x/jytFjMPun2ejVo5ctdcyaNhub1m7A5o0b0SAjE526dMG9N/8RV587AjMZXOQXFNN8FGPJvIUmwMqbZrrppQdrdEPqdPxt3D/sVX4GpD+K5ic5hcxm4un007sWDhSSrptFsTF45+sA00npon0kaudiyPbakX3WiYPM1hJ7RQNCwLs1qzOaRbpkn/yenZVlzw/+vHARChn+6qKuHTx0CPfeeydmzphF7ZOTdfiaN29sz4kvmK+XkIXMgR96U7VejadnDPfu3Yd7Hr6f5uccJDCvadOuA9avXYfVK1dj65at6NGrJ7r264MxN16P7r17YfnSFRj/xtv2fkeSjwjhFZGErKOH7QcB9PZrFx/qkn7NLQ7tmXQuYVu1sfFQGB07dUR8XALWrl1vdR3dDled4BDww5ny6qDn56MYmgfoNiIQ32ScqmityQU9/HMs/Ha9soIOG3dqh3x7mkli1DUV75iJ2/79+2humtlbQk0VCPn5eUiOT0JK/WT7jVsTJxlxyikn2wsGso7qzTp64wkHInT6JL+E9ciRo1i/cg1WLVqKH3/8CStWr7JnBPViGJktRXdrl61Ew+R6+GLCJKxfs1ZLR0RvwTAx6Nay+tMsLkPXLp1saT2kjKLjd7+7jnT8jAMHD7lTGmiwDI+MewBvv/mubRu189bGa1cHaBFR201zc3K8M9WAgtIPK2unjNuQwaKJ5wTC/9QEbSLW91CpCyT8PWs3IlBUal/0vEX4knqAWr1u/QaGnAx1iTcE0pZJX060343Si79kzjTPkpNTcGj/EVZw2ur65/yzZWOJww1fvwm1fuNm8Uh74yz8LCnTmpl0QM9kBPD+ux9ir5wxzwlbiOHCYnh5UjfMklNSeUazlMVXjjatm3CGpGDdOr2HUe2cPdH7JI8ezsERPdxp2lE5nmOBHszZu2+fR31N0IaJ/CN6KxHDYtu8F0lSaXa0OqpGipkL8/N/0fZVgGwlNUgLiHrdnQ1YkhKQaK3zJDBniOa4qOesr4Foc4LP7lc0qNdAFe2anlNUvhL6WWsDI4QC4WyL5vRuzVBYP2FnWsv/bt+U2K3cnIV9Kgnzsa7eCPTPp/+F5PqpFkTZyysNKYEHcvp6C0N6WhpRKFQFzqQPe+a55+nrRJMLg/XYXd8+vfHmm+PZ8PgEEQ41fmAyDMwcescRkSktx0XHxNhTPiJQY1eRcEJJYqiy3iJt+31F9TEgdNk60hcKR2+tbtq0sQsVhZGd+CP0zOAh/OmeO/Dj7Ln2BFXrNu2wlOZGwhBzA3Kwtj3Ujx49uuOKkVfgXiaOetS6TdvWaJjWkAECaWc/KexDrwXs2q0TTuvfFz17dEHnrl2QmpZOk9mKiWcWjhw6bDRZ3qH8hZqun64744z+jOqWI7VBAzRr1hjLSIMEEVKKSOLt1bsHc5pF9iongY3NHXmfxwFS1Ip2NaHK7ncjVHXZKCoi0rLqyk5dtxZB6fgYSB24xCgEMVE+vPHWK7jumt/bjniFvxqvZsXoUVdiEyOpzrTlevmZfgDMAjKC1thiE2Jwz313Y//e/UwKPzSTWFys5fUYJmjdcN7woejTdwDi4+OwcuUSfPP1N1i4YKG9fICcpBXyI4YZnl6IrAd6zMFzFjpmawaU4e13XsMN11+Le+6/HxM+/hwbt2xj3/J3KrIAJbh+7HV46633qZRVx++Syl8GLUvpHohtzA4D46iHr8oTVHoqVKuZCZzmZbqRpLi1OuP5/ZeFIXBmLARldJ4NiVe/Oauf5ZZ0dV2rAytXrcGwYeeg36mn4r333qWpKEGLlk0YqrbDwEGnok+vHpj8xZeYwYhM+431w/TlNHelpfodkH2YPetHrFu9kse78a8nnsQGCreQ9GubqZgnJdLjDvqVNTnxs88+A40ZzQUCxbZTX5agOf1GSmoyZ3Iqpnw/lW0oLFNVjTeICy86z14nu3PXHk9ZJExdczPoeEB0qC8bd1hRlCXlku+uOkNYtBQh8yAfGqQ/CPDY3oBWUet4IWyGeDePUhNjceawc/HZpxMlDUcM/Y+WW/7y7BM4uUs3XH7xpXS0hQyJ78JFIy/DNkZNuYcO2A+7bNywjcI8jP2792LPPr11oQglxQXQA/lt2rdBr5N7Ubs/Je4IxMQlISE2DpnMQdIy0u3+eLNWTZHBz+R69ZHRuiX2M7q7ecwNtAo+9KGJu/2OP2LsVdehyH7FjfR5TJdn/WjSJxg18ip75E0O3QlCY6B/sbD9+CDEE+bG9txKeqtmOLhrr43BCaj6Q5+srwUw3bLMoc11SsKKJygQM9FsZO08gSihvPrqK2ge3uE5ZsXUFvklLYtc/8db0KNTe4y4YAT2HthnD4T2YS6h5PH7r6dwtpajflqmbbJO5UyTs9bPZ+htcG2ZL8QnJSEtMwN79x9glHYAu9ZvRXlpEfOgXJqpXBuL/FVeUSEGDBxAATXC9Okz7H0rivDe+fh9tGvXHlddepW9XtzIFjNY5Iu6du2MDz78hCTrnHP0VoO8cQnv8TEoJBDxxQIqFhMq7bhuXJlAQpUEIR9iHdmh6+iXBGICYF1XzXphKbf8xgTCT6EaffXlmDJtCg4fzra6Y8ZcTS/hx3ffTEFnJl2ZmfXxxedfsK6cOtCubVvc9Pub8I+//RVZOfk25eVD9Fs/jRlK33r77fYbIV99+ZXdx9DUv3LMKDSk6Xnhhedx9GgW+9fI3ZjGXDMGhw8cxI/fTkcBY+bSCN06iMQrb72GD9/5AEPPPgdff/MdZs36gf1wPCTiylGkeeJXyGH27cyVBKIVYQ1TAtFI7MIvQojXJleBR5fGpBHXnCFhUNlZ7VDFl7FeBKnVHUhffKJtrC7JzWIXZB6Zy25ZP4h2LZri8jGX49sp36Nfnz5M2jZgzo9zbelfpuG6saMx8bMvbO+uNFS8VHR24YXn4dtvvkc2E9CWLZujY8f2DKXj8e2339ualbTUMYv0av2rTSuGr2fau7bWb92K2IgodOjcwX6hbfrUWeYfbRWWjnbEFZdg3YYNFt1F0dxdfPGFiEtMwszp07F73x5cMWokPn3/M5oVdqBcSmpt5uz4QfeLFCQJ5NgVBouzlXJxfP6fBCLi7DqL6voYFPhjKYxEBgXFeSjL0mIlbWOFQPxokBCLr2d/jY27tuOOMb9DNjNy94QS8XFGdaLZOouMfPnfr7o2xuWA/ZbtI88+jqZtWiOHWr9p+XJ7eFNvGlVuohfU+KMoUtHhj0QcI644KkZyvVQ0oKlTrPTgvX/GskXLHCuFl3Q3aNgAQxlQfPDRx6RTzJYaBNimAX7PmdmoZUusWLsGLz/3IqNvzZi6hWH8oi+gE/bOVIIEH0ojlLnrLUchzobzuBaBhGRGZ0abFiijJBWqVAPRnpqaai+N1NqW3t1RHhMLf2IqzUA0/MVFKD+8Dyhhgmk4JRAfEmkeHnnyEUTTR9170212I8l+p0p1OIN0r/z3v/89Fs5bQI3VOpJblNSv+pzc9ySGxl2wYC5nVEkRkzpGUhyMnrrSAO0FAKRVmhxkWFtKIsWC6KgYtGvTHsuWr3ABCunQLVi9gPmO2/6AiYzgtmzaavSZTNQf6/Xq2RP/fvNVTJs2FX/9y9/phSUQjaV2gWiVIo0Jpn7KQqCax1BnA12PjI+xqNZn219rmSHSTKFLSElCcWEByhkyhoOIVi17dE0Ms7MEao8vPgm+pPo0WQzjGPOX5OpXPnVHUtdl+7VBzI8BA061ZfW3xr9jTHVL8w70q883jr0W4996hzG72la9bm+sY/hav2FDHDp4hPzRs328QIHYjTYexjJxLGQdrdRqhmrV2KskdTUbftFF5yNPr0Wf/aOtmkgxbGykR6too64bhSVzf8aOPXuQk1No50kIK9QxQ1R43V6ZwU+9M0sb9o4F6i8urR6KDmVzBtJsV3+Tg7llW+BijqwtkrK1RkQY2FcSz38aoxFixxyVhMfEh2NGdGwyeVTKXKDIG4KrqZm7desOdOnUiSFpBrbTxrM1O1ctvXgsaLtMtCHi8OEjHJTbyGD8JCg6GTb8PLw8/lVGWfHmS+RXWlPArdu2Qitm8P985Xn0oIb/OH0W+/OI5Id2yMtXdezaCe3at8NEBhCix68nfOUfjEQfBg4caLN13vyfGV7rvr0uhMJdR2etIDpZZIbKZL5+AYS1TC8fJjp1HxGR3HRckImYtujoHSIatJyvQ6xORaRYL5LU3CHxTldA6JqdomYGmB+Ulyqb5iyiA9OgidJpoBUfs+qVOOPMwcwr9tkDNuKbw6S7E0E89Ozf0aJdayz8YQ5RagWR8RjbtqQfueraq+xRsk8/+hTb12/EoX37cfAAy/592M8cpT0jNnUz6LS+WLF0ORNDJrpUNBo3nHH2mfYizpU0iSt5TTtUrFc1YM89enTFkNMH44N3P0IJ+3SZuKdSGgSxyHeZT7DvxwHerKle+Ic9+xFZxmNNYqT3DNYfchWKW3RD1LZ1yJ49Ef7yQtu+Yw2ISHmJNnW5RKkmhEgSP92gKiE6NsZMm36ez9BVVCZzOKNSkpNx//134/XX3sTWbTutT23IbtQ0Ey+9+yq2MSdYt3g5bfwmZtKpaMgkT/7krTfH26v/ZCZZvYIGgZu5pIZJbdPGjWwn4tH8LPsxlmT6uvppDdGxWzf8MHUGPnrrfXsrhAiTVUtOTWRofiVefvl1E5RE5YgOF4jzF3Zr1vt+PKCdlqFIqxLYBzU7rmEnJJw9mjMkrum4yK7noyStI/x5+1C+wd3wlzapc00965JGVwyvOBdGSDhJOrbv3kkRre2SLh+RNlT+Eyj8WzR/Pi67/BKs4IyRY9bYOzLz3rpuE9586TV7sYyeVrpn3IOoz9zjqX/8k+FvHtGxojFLQgxhVaQlbdJb5vR8SB4OHz2AR1540pTjpSefwWzOuJ9m/oRTe/XCgnnzKAgKlaiiGaU9Ou4RfPrJpzh8iPmLh9uB+wyN2yKjMB7UBUIRqmU5lMe7EGpZmaCfSWH9RijuPozUx2aOi+rYB+WprRBx5CBKNi8m7ylFVjZrqwbWNwdrDOV5zhi9yDFY6hyoIa8OXmcmRYL0S4lPeF03niCKSkvs/vPll11qZkxrWUOGDMD3U6cjJ7/Qlu/3MHLZtH69vbxy9kzmEfQzNmPJSRO29eCQuz44bXRME1VUkIfenbviqb89iX0HDvOSe+4xIjKIM88ejOUrl1vry6+4DHN+moPVq9zdQBOwjVthuTs+XtBPSGnni0hzeGQRJBDRFUR8OqPRMvpoLWYTbXRyOgIdTuFYJDGrwr/8r1ultpDlQYgQvflAoAhCpkBRCpNcRPKaljVMY8LAhKFPrwgqstNqIPyrV6/FHIazI0ZcjPT0NHtpf16ubgSFtMmPpUtWYteWbUhJiDf8Drc60pETudFvIDYoICnH6Wedjo8/pK/Rj9WbAHXf0GcP/kTGMGHs2A7devdEYVExftbv4LJDCVsz0BXHgxOBKNIfGV/5kn1BOJ6iozlUaNJufHKzWfTzHwdSpa9KYYSY7C57x0QYKKXTZrGXujC81IMxvwSudV1AtrLCqqUraUOj8dHnn9vLzOTYK0nzobigCNs2b0ULJms1McreV56ToNRWe3I7de6IufPmWx8VQInqtXtfTJqMMTfehLv/fDemMOs3c20tNTh9hlNw/FB8NBfF2vNLcN2GWjucwZJyRFjewa9WwZ1X0OJJzjvlzhNoV6uMIPyY37xQUsvaoSXlY4G0XPiqFgmCA5dGEl2QQl61cjXymfT1OeVUJGnPsXyZNF3dMd/Q5gL9Drrh4xXHPImC/2xaOjrl1FUaMhPX/XblM5X9OrMhJ3v5iCvQt/9piExMoqnUeeE0DF6pCnok4pjbbT1wOAiiww70t3LWWTAS+tRgvP4c5lBrLQLayiwLEWkbZOi2bkUHxwESTm1EGxPDSlXwo4x9LVq8BBefcx7+/cQzuOvuOzDkzCFISaJgSIAMiV5SdvDAQXSm1tsYqkEIr+iV3W7D3GT79m0mCLKCEZ8P8Qlx9iKDO26/3fZ+/Wnsrbjr2lv15iRvRUWKEhJeWCFOe7+Xwt3jBPdDaL8EjiGi0K/7zlomqGB46ICElwUYIbHUzsC6ITSAENhR2Awy5lZeNtBXy5R5UFpUhm3bduFvf30cR7OycOefbkffk3uzje61B/HRx5/g3HPPIUoxRnirCt9mDk/rul6kv3HjZmcK6EE7dW6PW27VA0I+vPTSq5jPxE+PWO/bs8+juaYi1QmsL8VLqMcEWG2rjcmA50P8UHE/4xF+zk0AG4tbHVeF0ABCGCuZF4ITEYhAnQmMZ+RGFON7A532rgnC0bruRYhCVz1HEcSyRcvxxONPoxvzhitGjrC3OGRnZePAwQNoUF+/llA7A7XmpJ+5K2VympeXZ+9svG7stThr2Jl4+ZXX8DNnon4QMuQzFC+GjMuJgjZ2qGltD6DaHrYw0Cyv8bQaBaG1G/E/wpfQeFxsu1NQWr85Io9ut7BXF22g1Tr4VeSGGtE+KxpTOq7QVfmJwlXrIgxxhS/ip1lU1tFioX4doYBR15lnn4VOHdtj69at0A+EHdh3hExV0uqgQtTUhDHM5r+d/DUFeSUy0jLxA/OPqVP/a68nd2+nc630iIGIcL40jJhfAtZV1GkPDbFjmUPTertUOx49jqDxa8O1+jTl5IcLe08jjsQm42La9qVAWiA6aydKNi1iJTlRR254+bWgtnb/Qf9IqGW4OscLjoHhvbiivyFQPQ38cPZRLF++wp4VufCKi3D+6JHg2HBk305m/HGoV49ZeEo8UpPjccqQU3HBqMvRqnkrfDnpG8yY9QOysrVRjbi9EN5Mm76TpuqlBrB/mSdjtjtTCWojXMbcqniqF41Dm9D5zfyzhKho0gTS8bTw+U7SzLZ5X38laK3/RJ49rxucYMKLsnAtFM6aNQcfvfIu4thX585d0KF9e3Tq0p6frdCubXO0bNUYvU7qbpvz3h7/jtvw5kVzVqrgrQRjmLJpleqMEEO9QCXcNIVjsjmneiy6LxLCE15sSccDKaZ+BDMcfL6MPsHkYbchv+0A+NfNRMmM90m0Vh+rElsJIYTepww/67owUz8PFMXMugTxVF33uonfEuT4NHD2RTMRlxyL0oIAyoJaLNc1zexyamE5EqJiEBkXi9zDWQjYY9fVw/hKEAMFYmBkTLQxNvyHugTGZK+9TK+9rMcDCchWinW/JFL+gYJgCB+bmMCorOqWn5pAnORhfNOuKL/gjkqB5LYdhIjCI4jasw7RdkNKndcmFA6djrKkKAdRyRkkRMEabfLGFchdO6+iSfhgfjtwM0+5iTFHiqB/1H5R6yaAzjsyKknwTNQvCESN7JEz/itjfqXZXvYLS+jqLpGML9BOypj6iOsznJ/qS8tOYQaoDpAZ9NGclyU3QGFGJ/w/zXUbjw+piogAAAAASUVORK5CYII=" alt="Face alignment guide" style="display:none">
      </div>
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
        evidenceCamData = null;
        document.getElementById('evidenceCaptureData').value = '';
        document.getElementById('evidenceCamPreview').style.display = 'none';
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
    document.getElementById('evidencePreviewImg').src = '';
    document.getElementById('fileNameLabel').textContent = 'Choose an image…';
    document.getElementById('evidencePreview').style.display = 'none';
}
function clearCamEvidence() {
    evidenceCamData = null;
    document.getElementById('evidenceCaptureData').value = '';
    document.getElementById('evidenceCamImg').src = '';
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
    const overlay = document.getElementById('cameraOverlay');
    overlay.classList.toggle('face-capture-mode', mode === 'face');
    document.getElementById('faceGuideOverlay').style.display = mode === 'face' ? 'block' : 'none';

    // Use the front camera for face capture and the back camera for evidence.
    const constraints = {
        video: {
            facingMode: { ideal: mode === 'face' ? 'user' : 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 960 }
        }
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
        document.getElementById('evidenceCaptureData').value = data;
        // Keep the evidence source unambiguous: using the camera clears any chosen file.
        document.getElementById('evidenceFile').value = '';
        document.getElementById('fileNameLabel').textContent = 'Choose an image…';
        document.getElementById('evidencePreview').style.display = 'none';
        document.getElementById('evidenceCamImg').src = data;
        document.getElementById('evidenceCamPreview').style.display = 'block';
    }
    stopCamera();
}

function stopCamera() {
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    const overlay = document.getElementById('cameraOverlay');
    overlay.classList.remove('active', 'face-capture-mode');
    document.getElementById('faceGuideOverlay').style.display = 'none';
}

/* ── SIGNATURE ── */
const sigCanvas = document.getElementById('signatureCanvas');
const sigCtx = sigCanvas.getContext('2d');
sigCtx.strokeStyle = '#1a1a1a';
sigCtx.lineWidth = 3;
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
async function doSubmit() {
    const form = document.getElementById('reportForm');
    const fileInput = document.getElementById('evidenceFile');
    const selectedFile = fileInput && fileInput.files ? fileInput.files[0] : null;

    // Mobile phones can produce very large photos. Compress selected images
    // before the browser sends them, aiming for a small JPEG upload.
    if (selectedFile) {
        const looksLikeImage = (selectedFile.type || '').startsWith('image/') ||
            /\.(jpe?g|png|webp|gif|heic|heif|bmp|avif)$/i.test(selectedFile.name || '');
        if (!looksLikeImage) {
            alert('Please choose an image file (JPG, PNG, or WEBP).');
            return;
        }
        try {
            const compressedFile = await compressEvidenceImage(selectedFile);
            // Replace the picker file with the compressed JPEG so PHP receives
            // the smaller version rather than the original mobile photo.
            const transfer = new DataTransfer();
            transfer.items.add(compressedFile);
            fileInput.files = transfer.files;
            if (fileInput.files[0].size > 2 * 1024 * 1024) {
                alert('This photo is still too large after compression. Please crop it or choose a smaller photo.');
                return;
            }
        } catch (error) {
            alert('Your phone/browser could not convert this image. If it is a HEIC/HEIF photo, choose a JPG/PNG version from your gallery, or use Take Photo.');
            return;
        }
    }
    form.submit();
}

function compressEvidenceImage(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('Could not read image'));
        reader.onload = () => {
            const image = new Image();
            image.onerror = () => reject(new Error('This format cannot be decoded by this browser'));
            image.onload = () => {
                const maxDimension = 1280;
                const scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(image.width * scale));
                canvas.height = Math.max(1, Math.round(image.height * scale));
                const context = canvas.getContext('2d');
                if (!context) return reject(new Error('Canvas unavailable'));
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                // Retry with lower JPEG quality if needed to keep phone uploads small.
                const makeBlob = quality => canvas.toBlob(blob => {
                    if (!blob) return reject(new Error('Compression failed'));
                    if (blob.size > 1.5 * 1024 * 1024 && quality > 0.4) {
                        makeBlob(quality - 0.15);
                        return;
                    }
                    const baseName = (file.name || 'evidence').replace(/\.[^.]+$/, '') || 'evidence';
                    resolve(new File([blob], baseName + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', quality);
                makeBlob(0.72);
            };
            image.src = reader.result;
        };
        reader.readAsDataURL(file);
    });
}

/* close modals on backdrop tap */
document.getElementById('cameraOverlay').addEventListener('click', function(e){
    if (e.target === this) stopCamera();
});
document.getElementById('confirmOverlay').addEventListener('click', function(e){
    if (e.target === this) closeConfirm();
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
</body>
</html>
