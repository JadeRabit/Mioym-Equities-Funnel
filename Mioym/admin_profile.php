<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: registration.php');
    exit;
}

if (!isset($_SESSION['csrf']) || !isset($_SESSION['csrf']['value']) || !isset($_SESSION['csrf']['expires']) || time() >= (int)$_SESSION['csrf']['expires']) {
    $_SESSION['csrf']['value'] = bin2hex(random_bytes(32));
    $_SESSION['csrf']['expires'] = time() + 900;
}

function validate_csrf_post_admin() {
    if (!isset($_SESSION['csrf']['value']) || !isset($_SESSION['csrf']['expires'])) return false;
    if (time() >= (int)$_SESSION['csrf']['expires']) return false;
    $t = $_POST['csrf_token'] ?? '';
    if (!is_string($t) || $t === '') return false;
    $ok = hash_equals($_SESSION['csrf']['value'], $t);
    if ($ok) {
        $_SESSION['csrf']['value'] = bin2hex(random_bytes(32));
        $_SESSION['csrf']['expires'] = time() + 900;
    }
    return $ok;
}

$adminUsername = $_SESSION['admin_username'] ?? 'Admin';

$hasProfileCols = true;
try {
    $pdo->query("SELECT display_name, email, avatar_path, twofa_enabled, twofa_method, twofa_secret FROM admin_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasProfileCols = false;
}
if (!$hasProfileCols) {
    try { $pdo->exec("ALTER TABLE admin_tbl ADD COLUMN display_name VARCHAR(120) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE admin_tbl ADD COLUMN email VARCHAR(255) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE admin_tbl ADD COLUMN avatar_path VARCHAR(255) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE admin_tbl ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE admin_tbl ADD COLUMN twofa_method VARCHAR(20) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE admin_tbl ADD COLUMN twofa_secret VARCHAR(128) NULL"); } catch (Throwable $e) {}
    try {
        $pdo->query("SELECT display_name, email, avatar_path, twofa_enabled, twofa_method, twofa_secret FROM admin_tbl LIMIT 1");
        $hasProfileCols = true;
    } catch (Throwable $e) {
        $hasProfileCols = false;
    }
}

$admin = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM admin_tbl WHERE username = ? LIMIT 1");
    $stmt->execute([$adminUsername]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $admin = [];
}

$displayName = $admin['display_name'] ?? $adminUsername;
$email = $admin['email'] ?? '';
$avatarPath = $admin['avatar_path'] ?? '';
$twofaEnabled = isset($admin['twofa_enabled']) ? (int)$admin['twofa_enabled'] : 0;
$twofaMethod = $admin['twofa_method'] ?? '';
$twofaSecret = $admin['twofa_secret'] ?? '';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_login_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(120) NOT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Throwable $e) {
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(120) NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NULL
        )
    ");
} catch (Throwable $e) {
}

function admin_verify_password($input, $stored) {
    $stored = (string)$stored;
    if ($stored === '' || $input === '') return false;
    if (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

function admin_avatar_upload($fieldName) {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return null;
    if (!isset($_FILES[$fieldName]['error']) || (int)$_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return null;
    $tmp = $_FILES[$fieldName]['tmp_name'] ?? '';
    $name = $_FILES[$fieldName]['name'] ?? '';
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) return null;

    $info = @getimagesize($tmp);
    if (!$info || !isset($info['mime'])) return null;
    $mime = (string)$info['mime'];
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) return null;
    $ext = $allowed[$mime];

    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'admin';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $safeBase = bin2hex(random_bytes(16));
    $filePath = $dir . DIRECTORY_SEPARATOR . $safeBase . '.' . $ext;
    if (!@move_uploaded_file($tmp, $filePath)) return null;

    return 'uploads/admin/' . $safeBase . '.' . $ext;
}

function base32_decode_secret($secret) {
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', (string)$secret));
    if ($secret === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($secret); $i++) {
        $v = strpos($alphabet, $secret[$i]);
        if ($v === false) return '';
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function totp_verify_code($secretBase32, $code, $window = 1) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return false;
    $secret = base32_decode_secret($secretBase32);
    if ($secret === '') return false;
    $time = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $counter = $time + $i;
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = substr($hash, $offset, 4);
        $val = unpack('N', $truncated)[1] & 0x7FFFFFFF;
        $otp = str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
        if (hash_equals($otp, $code)) return true;
    }
    return false;
}

$shakeTarget = $_SESSION['profile_shake'] ?? '';
unset($_SESSION['profile_shake']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validate_csrf_post_admin()) {
        $_SESSION['di'] = ['type' => 'error', 'title' => 'Security', 'message' => 'Security check failed. Please try again.'];
        header('Location: admin_profile.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $newDisplayName = trim((string)($_POST['display_name'] ?? ''));
        $newEmail = trim((string)($_POST['email'] ?? ''));

        if ($newDisplayName === '') $newDisplayName = (string)$displayName;
        if ($newEmail === '') $newEmail = (string)$email;

        if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['di'] = ['type' => 'warn', 'title' => 'Profile', 'message' => 'Please enter a valid email address or leave it blank.'];
            $_SESSION['profile_shake'] = 'profile';
            header('Location: admin_profile.php');
            exit;
        }

        try {
            if ($hasProfileCols) {
                $newAvatarPath = admin_avatar_upload('avatar');
                $hasAnyChange = false;
                if ($newAvatarPath) $hasAnyChange = true;
                if ((string)$newDisplayName !== (string)$displayName) $hasAnyChange = true;
                if ((string)$newEmail !== (string)$email) $hasAnyChange = true;

                if (!$hasAnyChange) {
                    $_SESSION['di'] = ['type' => 'info', 'title' => 'Profile', 'message' => 'No changes to save.'];
                    header('Location: admin_profile.php');
                    exit;
                }
                if ($newAvatarPath) {
                    if (is_string($avatarPath) && $avatarPath !== '' && file_exists(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $avatarPath))) {
                        @unlink(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $avatarPath));
                    }
                    $stmt = $pdo->prepare("UPDATE admin_tbl SET display_name = ?, email = ?, avatar_path = ? WHERE username = ?");
                    $stmt->execute([$newDisplayName, $newEmail !== '' ? $newEmail : null, $newAvatarPath, $adminUsername]);
                } else {
                    $stmt = $pdo->prepare("UPDATE admin_tbl SET display_name = ?, email = ? WHERE username = ?");
                    $stmt->execute([$newDisplayName, $newEmail !== '' ? $newEmail : null, $adminUsername]);
                }
                $_SESSION['di'] = ['type' => 'success', 'title' => 'Profile', 'message' => 'Profile updated successfully.'];
            } else {
                $_SESSION['di'] = ['type' => 'warn', 'title' => 'Profile', 'message' => 'Profile fields are not available in the database.'];
            }
        } catch (Throwable $e) {
            $_SESSION['di'] = ['type' => 'error', 'title' => 'Profile', 'message' => 'Failed to update profile.'];
            $_SESSION['profile_shake'] = 'profile';
        }

        header('Location: admin_profile.php');
        exit;
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $_SESSION['di'] = ['type' => 'warn', 'title' => 'Security', 'message' => 'Please fill in all password fields.'];
            $_SESSION['profile_shake'] = 'password';
            header('Location: admin_profile.php');
            exit;
        }
        if ($new !== $confirm) {
            $_SESSION['di'] = ['type' => 'warn', 'title' => 'Security', 'message' => 'New password and confirmation do not match.'];
            $_SESSION['profile_shake'] = 'password';
            header('Location: admin_profile.php');
            exit;
        }
        if (strlen($new) < 8) {
            $_SESSION['di'] = ['type' => 'warn', 'title' => 'Security', 'message' => 'New password must be at least 8 characters.'];
            $_SESSION['profile_shake'] = 'password';
            header('Location: admin_profile.php');
            exit;
        }

        $stored = (string)($admin['password'] ?? '');
        $ok = false;
        if ($stored !== '') {
            $ok = password_verify($current, $stored) || hash_equals($stored, $current);
        }
        if (!$ok) {
            $_SESSION['di'] = ['type' => 'error', 'title' => 'Security', 'message' => 'Current password is incorrect.'];
            $_SESSION['profile_shake'] = 'password';
            header('Location: admin_profile.php');
            exit;
        }

        try {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admin_tbl SET password = ? WHERE username = ?");
            $stmt->execute([$hashed, $adminUsername]);
            $_SESSION['di'] = ['type' => 'success', 'title' => 'Security', 'message' => 'Password updated successfully.'];
        } catch (Throwable $e) {
            $_SESSION['di'] = ['type' => 'error', 'title' => 'Security', 'message' => 'Failed to update password.'];
            $_SESSION['profile_shake'] = 'password';
        }

        header('Location: admin_profile.php');
        exit;
    }

    if ($action === 'signout_others') {
        $sid = $_SESSION['admin_session_id'] ?? '';
        if (!is_string($sid) || $sid === '') {
            $_SESSION['di'] = ['type' => 'warn', 'title' => 'Sessions', 'message' => 'Session tracking is not available.'];
            header('Location: admin_profile.php');
            exit;
        }
        try {
            $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE username = ? AND session_id <> ?");
            $stmt->execute([$adminUsername, $sid]);
            $_SESSION['di'] = ['type' => 'success', 'title' => 'Sessions', 'message' => 'Signed out of all other sessions.'];
        } catch (Throwable $e) {
            $_SESSION['di'] = ['type' => 'error', 'title' => 'Sessions', 'message' => 'Failed to sign out other sessions.'];
        }
        header('Location: admin_profile.php');
        exit;
    }

    if ($action === 'start_2fa') {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $raw = random_bytes(20);
        $bits = '';
        for ($i = 0; $i < strlen($raw); $i++) {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $secret .= $alphabet[bindec($chunk)];
        }
        $_SESSION['twofa_pending_secret'] = $secret;
        $_SESSION['di'] = ['type' => 'info', 'title' => '2FA', 'message' => 'Scan or enter the secret in your authenticator, then verify the 6-digit code.'];
        header('Location: admin_profile.php#twofa');
        exit;
    }

    if ($action === 'cancel_2fa') {
        unset($_SESSION['twofa_pending_secret']);
        $_SESSION['di'] = ['type' => 'warn', 'title' => '2FA', 'message' => '2FA setup cancelled.'];
        header('Location: admin_profile.php#twofa');
        exit;
    }

    if ($action === 'confirm_2fa') {
        $code = (string)($_POST['twofa_code'] ?? '');
        $pending = (string)($_SESSION['twofa_pending_secret'] ?? '');
        if ($pending === '') {
            $_SESSION['di'] = ['type' => 'warn', 'title' => '2FA', 'message' => 'No pending 2FA setup found.'];
            header('Location: admin_profile.php#twofa');
            exit;
        }
        if (!totp_verify_code($pending, $code, 1)) {
            $_SESSION['di'] = ['type' => 'error', 'title' => '2FA', 'message' => 'Invalid code. Please try again.'];
            $_SESSION['profile_shake'] = 'twofa';
            header('Location: admin_profile.php#twofa');
            exit;
        }
        try {
            $stmt = $pdo->prepare("UPDATE admin_tbl SET twofa_enabled = 1, twofa_method = 'totp', twofa_secret = ? WHERE username = ?");
            $stmt->execute([$pending, $adminUsername]);
            unset($_SESSION['twofa_pending_secret']);
            $_SESSION['di'] = ['type' => 'success', 'title' => '2FA', 'message' => 'Two-factor authentication enabled.'];
        } catch (Throwable $e) {
            $_SESSION['di'] = ['type' => 'error', 'title' => '2FA', 'message' => 'Failed to enable 2FA.'];
        }
        header('Location: admin_profile.php#twofa');
        exit;
    }

    if ($action === 'disable_2fa') {
        $reauth = (string)($_POST['twofa_current_password'] ?? '');
        if (!admin_verify_password($reauth, $admin['password'] ?? '')) {
            $_SESSION['di'] = ['type' => 'error', 'title' => '2FA', 'message' => 'Re-authentication failed.'];
            $_SESSION['profile_shake'] = 'twofa';
            header('Location: admin_profile.php#twofa');
            exit;
        }
        try {
            $stmt = $pdo->prepare("UPDATE admin_tbl SET twofa_enabled = 0, twofa_method = NULL, twofa_secret = NULL WHERE username = ?");
            $stmt->execute([$adminUsername]);
            $_SESSION['di'] = ['type' => 'success', 'title' => '2FA', 'message' => 'Two-factor authentication disabled.'];
        } catch (Throwable $e) {
            $_SESSION['di'] = ['type' => 'error', 'title' => '2FA', 'message' => 'Failed to disable 2FA.'];
        }
        header('Location: admin_profile.php#twofa');
        exit;
    }

    $_SESSION['di'] = ['type' => 'warn', 'title' => 'Profile', 'message' => 'Unknown action.'];
    header('Location: admin_profile.php');
    exit;
}

function initials_from_name($name) {
    $name = trim((string)$name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name);
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if (count($parts) >= 2) return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1));
    return strtoupper(substr($parts[0], 0, 2));
}

$avatarInitials = initials_from_name($displayName);
$pendingTwofa = (string)($_SESSION['twofa_pending_secret'] ?? '');

$passwordStored = (string)($admin['password'] ?? '');
$passwordScore = 0;
if (strpos($passwordStored, '$2y$') === 0 || strpos($passwordStored, '$argon2') === 0) $passwordScore = 35;
elseif ($passwordStored !== '') $passwordScore = 20;

$secureScore = 0;
$secureScore += $displayName !== '' ? 10 : 0;
$secureScore += $email !== '' ? 15 : 0;
$secureScore += $passwordScore;
$secureScore += $twofaEnabled === 1 ? 40 : 0;
if ($secureScore > 100) $secureScore = 100;

$loginActivity = [];
try {
    $stmt = $pdo->prepare("SELECT ip_address, user_agent, created_at FROM admin_login_activity WHERE username = ? ORDER BY id DESC LIMIT 6");
    $stmt->execute([$adminUsername]);
    $loginActivity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loginActivity = [];
}

$sessions = [];
$currentSid = $_SESSION['admin_session_id'] ?? '';
try {
    $stmt = $pdo->prepare("SELECT session_id, ip_address, user_agent, created_at, last_seen_at, is_active FROM admin_sessions WHERE username = ? ORDER BY COALESCE(last_seen_at, created_at) DESC LIMIT 6");
    $stmt->execute([$adminUsername]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $sessions = [];
}

function device_label($ua) {
    $ua = strtolower((string)$ua);
    if ($ua === '') return 'Unknown device';
    if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'Apple mobile';
    if (strpos($ua, 'android') !== false) return 'Android mobile';
    if (strpos($ua, 'mac os') !== false) return 'Mac';
    if (strpos($ua, 'windows') !== false) return 'Windows';
    if (strpos($ua, 'linux') !== false) return 'Linux';
    return 'Device';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account & Security Center · Mioym Equities</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="dynamic-island.js"></script>
    <style>
        body { font-family: 'Inter', 'Plus Jakarta Sans', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
        .card-glow { box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.06), 0 16px 40px rgba(15, 23, 42, 0.08); }
        .card-glow-active { box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.25), 0 18px 50px rgba(59, 130, 246, 0.10); }
        .field { transition: box-shadow 180ms ease, border-color 180ms ease, background-color 180ms ease; }
        .field:focus { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.14); border-color: #3b82f6; background-color: #ffffff; outline: none; }
        .field.field-success { border-color: rgba(34, 197, 94, 0.55); box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.10); background-color: #ffffff; }
        .shake { animation: shake 320ms ease-in-out 0s 1; }
        @keyframes shake {
            0% { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(6px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
            100% { transform: translateX(0); }
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen">
<?php if (!empty($_SESSION['di'])): $___di = $_SESSION['di']; unset($_SESSION['di']); ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var payload = <?php echo json_encode($___di, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  function show(){
    if (!window.DynamicIsland) return setTimeout(show, 30);
    var t = payload.title || '';
    var m = payload.message || '';
    var ty = payload.type || 'info';
    if (ty === 'error') DynamicIsland.error(m, t);
    else if (ty === 'warn') DynamicIsland.warn(m, t);
    else if (ty === 'success') DynamicIsland.success(m, t);
    else DynamicIsland.info(m, t);
  }
  show();
});
</script>
<?php endif; ?>
    <div class="flex h-screen overflow-hidden">
        <?php include 'sidebar.php'; ?>

        <main class="flex-1 overflow-y-auto bg-slate-50 p-6 lg:p-10">
            <div class="max-w-6xl mx-auto">
                <div class="bg-white border border-slate-100 rounded-[2rem] p-6 lg:p-8 card-glow relative overflow-hidden">
                    <div class="absolute inset-0 pointer-events-none" style="background: radial-gradient(800px 220px at 20% 0%, rgba(59,130,246,0.10), transparent 60%), radial-gradient(700px 240px at 85% 10%, rgba(245,158,11,0.12), transparent 55%);"></div>
                    <div class="relative flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="relative group">
                                <label for="profileAvatar" class="block cursor-pointer">
                                    <div class="w-20 h-20 rounded-3xl bg-slate-50 border border-slate-200 overflow-hidden flex items-center justify-center relative">
                                        <?php if (is_string($avatarPath) && $avatarPath !== '' && file_exists(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $avatarPath))): ?>
                                            <img id="avatarPreview" src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Admin avatar" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div id="avatarPreviewText" class="text-2xl font-extrabold text-[#1e4a7a]"><?php echo htmlspecialchars($avatarInitials); ?></div>
                                        <?php endif; ?>
                                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center">
                                            <div class="text-white text-xs font-bold flex items-center gap-2 px-3 py-2 rounded-xl bg-white/10 border border-white/20">
                                                <i class="fas fa-camera"></i> Change Photo
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div>
                                <div class="text-2xl font-extrabold text-slate-900 tracking-tight"><?php echo htmlspecialchars($displayName); ?></div>
                                <div class="text-sm text-slate-500 font-medium mt-1"><?php echo $email ? htmlspecialchars($email) : 'Add an email to improve your security score'; ?></div>
                                <div class="mt-3 inline-flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-full px-3 py-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">Active Session</span>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($adminUsername); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="w-full lg:w-[420px]">
                            <div class="flex items-center justify-between">
                                <div class="text-xs font-extrabold text-slate-400 uppercase tracking-widest">Security Health</div>
                                <div class="text-sm font-extrabold text-slate-900"><?php echo (int)$secureScore; ?>%</div>
                            </div>
                            <div class="mt-3 h-3 w-full bg-slate-100 rounded-full overflow-hidden border border-slate-200">
                                <div class="h-full rounded-full" style="width: <?php echo (int)$secureScore; ?>%; background: linear-gradient(90deg, #f59e0b, #3b82f6);"></div>
                            </div>
                            <div class="mt-3 text-xs text-slate-500 flex items-center justify-between">
                                <span class="font-semibold">Profile <?php echo $twofaEnabled ? 'secured with 2FA' : 'needs 2FA'; ?></span>
                                <a href="#twofa" class="text-[#1e4a7a] font-extrabold hover:underline">Manage 2FA</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-8 grid grid-cols-1 xl:grid-cols-12 gap-8 items-start">
                    <div class="xl:col-span-5 space-y-6">
                        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 card-glow">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-extrabold text-slate-900">Recent Login Activity</div>
                                    <div class="text-sm text-slate-500 mt-1">Last sign-ins to this admin account.</div>
                                </div>
                                <div class="w-10 h-10 rounded-2xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600">
                                    <i class="fas fa-history"></i>
                                </div>
                            </div>
                            <div class="mt-5 space-y-3">
                                <?php if (!empty($loginActivity)): ?>
                                    <?php foreach ($loginActivity as $row): ?>
                                        <div class="flex items-center justify-between gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                            <div class="min-w-0">
                                                <div class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars(device_label($row['user_agent'] ?? '')); ?></div>
                                                <div class="text-xs text-slate-500 mt-1 truncate">
                                                    <?php echo htmlspecialchars((string)($row['ip_address'] ?? 'Unknown IP')); ?> · <?php echo htmlspecialchars(date('M d, Y g:ia', strtotime($row['created_at'] ?? 'now'))); ?>
                                                </div>
                                            </div>
                                            <div class="text-xs font-extrabold text-slate-400 uppercase tracking-widest">Unknown</div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 text-sm text-slate-500">No login activity recorded yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 card-glow">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <div class="text-lg font-extrabold text-slate-900">Session Management</div>
                                    <div class="text-sm text-slate-500 mt-1">Review active sessions and revoke access.</div>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="signout_others">
                                    <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-4 py-2.5 rounded-2xl font-bold text-sm shadow-sm transition inline-flex items-center gap-2" data-loading>
                                        <i class="fas fa-right-from-bracket"></i> Sign out others
                                    </button>
                                </form>
                            </div>
                            <div class="mt-5 space-y-3">
                                <?php if (!empty($sessions)): ?>
                                    <?php foreach ($sessions as $row): ?>
                                        <?php
                                            $sid = (string)($row['session_id'] ?? '');
                                            $isCurrent = is_string($currentSid) && $currentSid !== '' && hash_equals($currentSid, $sid);
                                            $active = (int)($row['is_active'] ?? 0) === 1;
                                        ?>
                                        <div class="p-4 rounded-2xl border <?php echo $isCurrent ? 'border-blue-200 bg-blue-50/50' : 'border-slate-100 bg-slate-50'; ?>">
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="min-w-0">
                                                    <div class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars(device_label($row['user_agent'] ?? '')); ?><?php echo $isCurrent ? ' · This device' : ''; ?></div>
                                                    <div class="text-xs text-slate-500 mt-1 truncate">
                                                        <?php echo htmlspecialchars((string)($row['ip_address'] ?? 'Unknown IP')); ?> · Last seen <?php echo htmlspecialchars(date('M d, Y g:ia', strtotime($row['last_seen_at'] ?? $row['created_at'] ?? 'now'))); ?>
                                                    </div>
                                                </div>
                                                <div class="text-xs font-extrabold uppercase tracking-widest <?php echo $active ? 'text-emerald-600' : 'text-slate-400'; ?>">
                                                    <?php echo $active ? 'Active' : 'Revoked'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 text-sm text-slate-500">No sessions recorded yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="xl:col-span-7 space-y-6">
                        <div id="twofa" class="bg-white border border-slate-100 rounded-[2rem] p-6 card-glow <?php echo $shakeTarget === 'twofa' ? 'shake' : ''; ?>">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-lg font-extrabold text-slate-900 flex items-center gap-2">
                                        <span class="w-9 h-9 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-700">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                        Two-Factor Authentication (2FA)
                                    </div>
                                    <div class="text-sm text-slate-500 mt-2">Add an extra verification step using a TOTP app like Google Authenticator.</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-extrabold uppercase tracking-widest text-slate-400">Status</div>
                                    <div class="mt-1 inline-flex items-center gap-2 px-3 py-1.5 rounded-full border <?php echo $twofaEnabled ? 'bg-emerald-50 border-emerald-200/60 text-emerald-700' : 'bg-slate-50 border-slate-200 text-slate-600'; ?> text-xs font-bold">
                                        <span class="w-1.5 h-1.5 rounded-full <?php echo $twofaEnabled ? 'bg-emerald-500' : 'bg-slate-400'; ?>"></span>
                                        <?php echo $twofaEnabled ? 'Enabled' : 'Not enabled'; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($twofaEnabled): ?>
                                <div class="mt-6 grid grid-cols-1 gap-4">
                                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                        <div class="text-xs font-extrabold uppercase tracking-widest text-slate-400">Method</div>
                                        <div class="text-sm font-bold text-slate-900 mt-1"><?php echo htmlspecialchars($twofaMethod ?: 'totp'); ?></div>
                                    </div>
                                    <form method="POST" class="p-4 rounded-2xl bg-amber-50 border border-amber-100">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="disable_2fa">
                                        <div class="text-sm font-extrabold text-amber-900">Disable 2FA (Re-auth required)</div>
                                        <div class="mt-3 relative">
                                            <input type="password" name="twofa_current_password" class="field w-full px-4 py-3 rounded-2xl border border-amber-200 bg-white font-semibold text-slate-800 pr-11" placeholder="Current password">
                                            <button type="button" class="toggle-visibility absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl hover:bg-amber-100 text-amber-700" data-target="twofa_current_password">
                                                <i class="far fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="mt-4 flex justify-end">
                                            <button type="submit" class="bg-amber-500 hover:bg-amber-400 text-[#0f2b44] px-5 py-2.5 rounded-2xl font-extrabold transition inline-flex items-center gap-2" data-loading>
                                                <i class="fas fa-shield-alt"></i> Disable 2FA
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php if ($pendingTwofa === ''): ?>
                                    <div class="mt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                        <div class="text-sm text-slate-600 font-medium">Enable 2FA to protect sensitive actions and prevent unauthorized access.</div>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="start_2fa">
                                            <button type="submit" class="bg-[#1e4a7a] hover:bg-[#15365a] text-white px-5 py-2.5 rounded-2xl font-extrabold transition inline-flex items-center gap-2" data-loading>
                                                <i class="fas fa-wand-magic-sparkles"></i> Start Setup
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <?php $issuer = 'Mioym%20Equities'; $label = rawurlencode($adminUsername); $otpUri = "otpauth://totp/{$issuer}:{$label}?secret={$pendingTwofa}&issuer={$issuer}&digits=6&period=30"; ?>
                                    <div class="mt-6 grid grid-cols-1 gap-4">
                                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                            <div class="text-xs font-extrabold uppercase tracking-widest text-slate-400">Step 1</div>
                                            <div class="text-sm font-bold text-slate-900 mt-1">Add this secret to your authenticator app</div>
                                            <div class="mt-3 font-mono text-sm bg-white border border-slate-200 rounded-2xl p-4 break-all"><?php echo htmlspecialchars($pendingTwofa); ?></div>
                                            <div class="mt-3 text-xs text-slate-500">If your app supports QR, use the URI: <span class="font-mono"><?php echo htmlspecialchars($otpUri); ?></span></div>
                                        </div>
                                        <form method="POST" class="p-4 rounded-2xl bg-white border border-slate-100 card-glow-active">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="confirm_2fa">
                                            <div class="text-xs font-extrabold uppercase tracking-widest text-slate-400">Step 2</div>
                                            <div class="text-sm font-bold text-slate-900 mt-1">Verify the 6‑digit code</div>
                                            <div class="mt-3">
                                                <input type="text" name="twofa_code" inputmode="numeric" autocomplete="one-time-code" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 tracking-[0.35em] text-center" placeholder="123456" maxlength="6">
                                            </div>
                                            <div class="mt-4 flex flex-col sm:flex-row gap-3 justify-end">
                                                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-2xl font-extrabold transition inline-flex items-center gap-2" data-loading>
                                                    <i class="fas fa-check"></i> Enable 2FA
                                                </button>
                                            </div>
                                        </form>
                                        <form method="POST" class="px-4 pb-4 -mt-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="cancel_2fa">
                                            <div class="flex justify-end">
                                                <button type="submit" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-5 py-2.5 rounded-2xl font-extrabold transition inline-flex items-center gap-2">
                                                    <i class="fas fa-xmark"></i> Cancel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 card-glow <?php echo $shakeTarget === 'profile' ? 'shake' : ''; ?>">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-extrabold text-slate-900">Account Details</h3>
                                    <p class="text-sm text-slate-500 mt-1">Update your admin details and avatar.</p>
                                </div>
                                <div class="w-10 h-10 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-700">
                                    <i class="fas fa-user-gear"></i>
                                </div>
                            </div>
                            <form method="POST" enctype="multipart/form-data" class="mt-6 space-y-4" id="profileForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-extrabold text-slate-500 uppercase tracking-widest mb-2">Display Name</label>
                                        <input type="text" name="display_name" value="<?php echo htmlspecialchars($displayName); ?>" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800" required>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-extrabold text-slate-500 uppercase tracking-widest mb-2">Email</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars((string)$email); ?>" placeholder="name@company.com" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-extrabold text-slate-500 uppercase tracking-widest mb-2">Avatar</label>
                                    <input type="file" id="profileAvatar" name="avatar" accept="image/png,image/jpeg,image/webp" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800">
                                </div>
                                <div class="flex justify-end pt-2">
                                    <button type="submit" class="bg-[#1e4a7a] hover:bg-[#15365a] text-white px-6 py-3 rounded-2xl font-extrabold shadow-sm transition inline-flex items-center gap-2" data-loading>
                                        <span class="btn-icon"><i class="fas fa-save"></i></span>
                                        <span class="btn-text">Save Changes</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 card-glow <?php echo $shakeTarget === 'password' ? 'shake' : ''; ?>">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-extrabold text-slate-900">Change Password</h3>
                                    <p class="text-sm text-slate-500 mt-1">Your current password is required for verification.</p>
                                </div>
                                <div class="w-10 h-10 rounded-2xl bg-rose-50 border border-rose-100 flex items-center justify-center text-rose-600">
                                    <i class="fas fa-key"></i>
                                </div>
                            </div>
                            <form method="POST" class="mt-6 space-y-4" id="passwordForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="change_password">
                                <div>
                                    <label class="block text-xs font-extrabold text-slate-500 uppercase tracking-widest mb-2">Current Password</label>
                                    <div class="relative">
                                        <input type="password" name="current_password" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 pr-11" required>
                                        <button type="button" class="toggle-visibility absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl hover:bg-slate-100 text-slate-500" data-target="current_password">
                                            <i class="far fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-extrabold text-slate-500 uppercase tracking-widest mb-2">New Password</label>
                                        <div class="relative">
                                            <input type="password" name="new_password" id="newPassword" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 pr-11" required>
                                            <button type="button" class="toggle-visibility absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl hover:bg-slate-100 text-slate-500" data-target="new_password">
                                                <i class="far fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-extrabold text-slate-500 uppercase tracking-widest mb-2">Confirm New Password</label>
                                        <div class="relative">
                                            <input type="password" name="confirm_password" id="confirmPassword" class="field w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 font-semibold text-slate-800 pr-11" required>
                                            <button type="button" class="toggle-visibility absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl hover:bg-slate-100 text-slate-500" data-target="confirm_password">
                                                <i class="far fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="text-xs font-extrabold uppercase tracking-widest text-slate-400">Password Strength</div>
                                        <div id="strengthLabel" class="text-xs font-extrabold text-slate-600">—</div>
                                    </div>
                                    <div class="mt-3 h-2.5 w-full bg-white rounded-full overflow-hidden border border-slate-200">
                                        <div id="strengthBar" class="h-full rounded-full" style="width:0%;background:#e2e8f0;"></div>
                                    </div>
                                    <div class="mt-3 text-xs text-slate-500">Use 12+ characters with letters, numbers, and symbols.</div>
                                </div>

                                <div class="flex justify-end pt-2">
                                    <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-3 rounded-2xl font-extrabold shadow-sm transition inline-flex items-center gap-2" data-loading>
                                        <span class="btn-icon"><i class="fas fa-shield-halved"></i></span>
                                        <span class="btn-text">Update Password</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                (function () {
                    var avatarInput = document.getElementById('profileAvatar');
                    if (avatarInput) {
                        avatarInput.addEventListener('change', function () {
                            if (!avatarInput.files || !avatarInput.files[0]) return;
                            var file = avatarInput.files[0];
                            if (!file.type || file.type.indexOf('image/') !== 0) return;
                            var url = URL.createObjectURL(file);
                            var img = document.getElementById('avatarPreview');
                            if (img) img.src = url;
                        });
                    }

                    var toggles = document.querySelectorAll('.toggle-visibility');
                    toggles.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var name = btn.getAttribute('data-target');
                            if (!name) return;
                            var input = document.querySelector('input[name="' + name + '"]');
                            if (!input) return;
                            var isPw = input.getAttribute('type') === 'password';
                            input.setAttribute('type', isPw ? 'text' : 'password');
                            var icon = btn.querySelector('i');
                            if (icon) {
                                icon.classList.toggle('fa-eye');
                                icon.classList.toggle('fa-eye-slash');
                            }
                        });
                    });

                    var fields = document.querySelectorAll('.field');
                    fields.forEach(function (el) {
                        el.addEventListener('blur', function () {
                            try {
                                if (el.value && el.checkValidity()) el.classList.add('field-success');
                                else el.classList.remove('field-success');
                            } catch (e) {
                            }
                        });
                        el.addEventListener('input', function () {
                            el.classList.remove('field-success');
                        });
                    });

                    function scorePassword(pw) {
                        var s = 0;
                        if (!pw) return 0;
                        if (pw.length >= 8) s += 20;
                        if (pw.length >= 12) s += 20;
                        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s += 20;
                        if (/\d/.test(pw)) s += 20;
                        if (/[^A-Za-z0-9]/.test(pw)) s += 20;
                        return Math.min(100, s);
                    }

                    var newPw = document.getElementById('newPassword');
                    var bar = document.getElementById('strengthBar');
                    var label = document.getElementById('strengthLabel');
                    function renderStrength() {
                        if (!newPw || !bar || !label) return;
                        var v = newPw.value || '';
                        var s = scorePassword(v);
                        bar.style.width = s + '%';
                        var color = '#ef4444';
                        var txt = 'Weak';
                        if (s >= 80) { color = '#22c55e'; txt = 'Strong'; }
                        else if (s >= 60) { color = '#84cc16'; txt = 'Good'; }
                        else if (s >= 40) { color = '#f59e0b'; txt = 'Fair'; }
                        bar.style.background = color;
                        label.textContent = v ? txt : '—';
                        label.style.color = color;
                    }
                    if (newPw) {
                        newPw.addEventListener('input', renderStrength);
                        renderStrength();
                    }

                    var loadingButtons = document.querySelectorAll('button[data-loading]');
                    loadingButtons.forEach(function (btn) {
                        var form = btn.closest('form');
                        if (!form) return;
                        form.addEventListener('submit', function () {
                            btn.disabled = true;
                            btn.classList.add('opacity-90', 'cursor-not-allowed');
                            var iconWrap = btn.querySelector('.btn-icon');
                            var textWrap = btn.querySelector('.btn-text');
                            if (iconWrap) iconWrap.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
                            if (textWrap) textWrap.textContent = 'Saving...';
                        });
                    });
                })();
            </script>
        </main>
    </div>
</body>
</html>
