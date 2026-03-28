<?php
session_start();
require_once 'db.php';

function validate_csrf_post() {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_post()) {
        $_SESSION['di'] = ['type' => 'error', 'title' => 'Security', 'message' => 'Security check failed. Please try again.'];
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'registration.php';
        header('Location: ' . $dest);
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM admin_tbl WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($admin && (password_verify($password, $admin['password']) || $password === $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin['username'];
        if ($password === $admin['password']) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE admin_tbl SET password = ? WHERE username = ?");
            $updateStmt->execute([$hashed, $username]);
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (is_string($ip) && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

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
            $stmtLog = $pdo->prepare("INSERT INTO admin_login_activity (username, ip_address, user_agent) VALUES (?, ?, ?)");
            $stmtLog->execute([$admin['username'], $ip !== '' ? $ip : null, $ua !== '' ? $ua : null]);
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
            $sid = session_id();
            $_SESSION['admin_session_id'] = $sid;
            $stmtSess = $pdo->prepare("INSERT INTO admin_sessions (username, session_id, ip_address, user_agent, is_active, last_seen_at) VALUES (?, ?, ?, ?, 1, NOW())");
            $stmtSess->execute([$admin['username'], $sid, $ip !== '' ? $ip : null, $ua !== '' ? $ua : null]);
        } catch (Throwable $e) {
        }

        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['di'] = ['type' => 'warn', 'title' => 'Login Failed', 'message' => 'Invalid credentials. Please check your username and password.'];
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'registration.php';
        header('Location: ' . $dest);
        exit;
    }
} else {
    header('Location: registration.php');
    exit;
}
?>
