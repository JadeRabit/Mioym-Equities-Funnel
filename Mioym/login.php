<?php
require_once 'db.php';
require_once 'config.php';

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
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
        header('Location: ' . $dest);
        exit;
    }

    // Rate limiting check
    $rateLimit = check_login_rate_limit($pdo, $_POST['username'] ?? null);
    if ($rateLimit['blocked']) {
        $minutes = ceil($rateLimit['remaining_seconds'] / 60);
        $_SESSION['di'] = ['type' => 'error', 'title' => 'Rate Limited', 'message' => "Too many failed attempts. Please try again in {$minutes} minute(s)."];
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
        header('Location: ' . $dest);
        exit;
    }

    $recaptcha_secret_key = get_setting('recaptcha_secret_key', '') ?: (getenv('RECAPTCHA_SECRET_KEY') ?: '');
    $recaptcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));
    if ($recaptcha_secret_key === '' || $recaptcha_response === '') {
        $_SESSION['di'] = ['type' => 'warn', 'title' => 'Captcha', 'message' => 'Please complete the captcha.'];
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
        header('Location: ' . $dest);
        exit;
    }
    $captcha_ok = false;
    try {
        $payload = http_build_query([
            'secret' => $recaptcha_secret_key,
            'response' => $recaptcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        $resp = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            $resp = (string)curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 8
                ]
            ]);
            $resp = (string)@file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
        }
        $data = json_decode((string)$resp, true);
        $captcha_ok = is_array($data) && !empty($data['success']);
    } catch (Throwable $e) {
        $captcha_ok = false;
    }
    if (!$captcha_ok) {
        $_SESSION['di'] = ['type' => 'warn', 'title' => 'Captcha', 'message' => 'Captcha verification failed. Please try again.'];
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
        header('Location: ' . $dest);
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM admin_tbl WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify password using only secure hashes
    if ($admin && password_verify($password, $admin['password'])) {
        // Clear rate limit on successful login
        clear_login_rate_limit($pdo, $username);
        
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin['username'];

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
        // Record failed login attempt for rate limiting
        record_failed_login($pdo, $username);
        
        $_SESSION['di'] = ['type' => 'warn', 'title' => 'Login Failed', 'message' => 'Invalid credentials. Please check your username and password.'];
        $dest = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
        $connector = (strpos($dest, '?') !== false) ? '&' : '?';
        header('Location: ' . $dest . $connector . 'login_error=1');
        exit;
    }
} else {
    header('Location: index.php');
    exit;
}
?>
