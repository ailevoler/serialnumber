<?php
require_once __DIR__ . '/includes/core.php';
if (currentDispatcher()) { header('Location: ' . APP_URL . '/home.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = normalizePhone($_POST['phone'] ?? '');
    $pin   = (string)($_POST['pin'] ?? '');

    if (!verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Invalid session. I-refresh ang page at subukan ulit.';
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = 'Ilagay ang tamang mobile number (09XXXXXXXXX).';
    } elseif (!preg_match('/^\d{6}$/', $pin)) {
        $error = '6 digits ang PIN.';
    } else {
        $d = DB::row(
            "SELECT ds.*, t.is_active AS terminal_active FROM tl_dispatchers ds
             JOIN tl_terminals t ON t.id=ds.terminal_id WHERE ds.phone=?",
            [$phone]
        );
        $locked = $d && $d['pin_locked_until'] && strtotime($d['pin_locked_until']) > time();
        if (!$d) {
            usleep(300000);
            $error = 'Hindi naka-register ang number na ito bilang dispatcher.';
        } elseif ($d['status'] !== 'active') {
            $error = 'Naka-suspend ang account mo. Makipag-ugnayan sa admin.';
        } elseif (!(int)$d['terminal_active']) {
            $error = 'Hindi active ang terminal mo. Makipag-ugnayan sa admin.';
        } elseif ($locked) {
            $error = 'Masyadong maraming maling PIN. Naka-lock hanggang ' . date('h:i A', strtotime($d['pin_locked_until'])) . '.';
        } elseif (!password_verify($pin, $d['pin_hash'])) {
            $attempts = (int)$d['pin_attempts'] + 1;
            $max  = (int)setting('max_pin_attempts', MAX_PIN_ATTEMPTS);
            $mins = (int)setting('pin_lock_minutes', PIN_LOCK_MINUTES);
            if ($attempts >= $max) {
                DB::run("UPDATE tl_dispatchers SET pin_attempts=0, pin_locked_until=NOW() + INTERVAL {$mins} MINUTE WHERE id=?", [$d['id']]);
                $error = "Naka-lock ang account nang {$mins} minuto dahil sa maling PIN.";
            } else {
                DB::run("UPDATE tl_dispatchers SET pin_attempts=? WHERE id=?", [$attempts, $d['id']]);
                $error = 'Maling PIN. ' . ($max - $attempts) . ' attempt(s) na lang.';
            }
        } else {
            session_regenerate_id(true);
            $_SESSION['tl_dispatcher_id'] = (int)$d['id'];
            DB::run("UPDATE tl_dispatchers SET pin_attempts=0, pin_locked_until=NULL, last_login=NOW() WHERE id=?", [$d['id']]);
            header('Location: ' . APP_URL . '/home.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dispatcher Login – TriLink</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:linear-gradient(160deg,#0F1A3A,#1A2B5E 60%,#2E7D32);font-family:'Inter',sans-serif;}
    .card{width:100%;max-width:380px;background:#fff;border-radius:20px;padding:28px 22px;box-shadow:0 20px 50px rgba(0,0,0,.3);}
    .brand{display:flex;align-items:center;gap:10px;font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#1A2B5E;}
    .brand i{width:40px;height:40px;border-radius:12px;background:#E8F5E9;color:#2E7D32;display:flex;align-items:center;justify-content:center;}
    .pill{display:inline-block;margin:12px 0 4px;font-size:11px;font-weight:800;letter-spacing:.6px;color:#E65100;background:#FFF3E0;padding:4px 10px;border-radius:10px;}
    p.sub{font-size:13px;color:#5F6368;margin-bottom:18px;}
    label{display:block;font-size:12px;font-weight:700;color:#3C4043;margin:12px 0 6px;}
    input{width:100%;padding:13px 14px;border-radius:12px;border:1.5px solid #EAECF0;font-size:16px;font-family:inherit;outline:none;}
    input:focus{border-color:#2E7D32;}
    button{width:100%;margin-top:18px;padding:15px;border:none;border-radius:14px;background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;font-weight:800;font-size:15px;cursor:pointer;font-family:inherit;}
    .err{margin-bottom:6px;padding:11px 12px;border-radius:10px;background:#FFEBEE;color:#C62828;font-size:13px;}
  </style>
</head>
<body>
  <form class="card" method="POST" autocomplete="off">
    <div class="brand"><i class="fas fa-traffic-light"></i> TriLink</div>
    <span class="pill">DISPATCHING PORTAL</span>
    <p class="sub">Para sa terminal dispatcher — hawakan ang pila ng tricycle.</p>
    <?php if ($error): ?><div class="err"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= csrfToken() ?>">
    <label>Mobile Number</label>
    <input type="tel" name="phone" placeholder="09XXXXXXXXX" maxlength="13" value="<?= e($_POST['phone'] ?? '') ?>" required>
    <label>6-digit PIN</label>
    <input type="password" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" required>
    <button type="submit"><i class="fas fa-sign-in-alt"></i> Login</button>
  </form>
</body>
</html>
