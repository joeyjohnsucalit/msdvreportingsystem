<?php
session_start();
require_once '../db/connection.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php'); exit;
}

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!password_verify($current, $row['password'])) {
        $err = 'Current password is incorrect.';
    } elseif ($new !== $confirm) {
        $err = 'New passwords do not match.';
    } elseif (strlen($new) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $_SESSION['user_id']]);
        $msg = 'Password changed successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Admin Password</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-5 col-11">
      <div class="card shadow">
        <div class="card-body p-4">
          <h5 class="mb-3">Change Admin Password</h5>
          <?php if($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
          <?php if($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>
          <form method="POST">
            <div class="mb-3"><label class="form-label">Current Password</label>
              <input type="password" name="current_password" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">New Password</label>
              <input type="password" name="new_password" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary w-100">Change Password</button>
            <a href="dashboard.php" class="btn btn-secondary w-100 mt-2">Back to Dashboard</a>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>