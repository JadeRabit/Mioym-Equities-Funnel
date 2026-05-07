<?php
$host = 'localhost';
$dbname = 'webinar_db';
$username = 'root';
$password = '';
// $username = 'mioymadmin'; // Default XAMPP username
// $password = 'Mioym$1234!!!';     // Default XAMPP password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // In production, sessions can fail if the domain/SSL isn't properly handled.
    // We ensure sessions work by setting proper cookie params.
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = session_get_cookie_params();
        // Use SERVER_NAME to avoid port issues in domain field
        $domain = $_SERVER['SERVER_NAME'] ?? $cookieParams['domain'];
        
        // Check if running on HTTPS (production) or force secure flag
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        
        // For production, ensure secure flag is always true
        // In production environment, HTTPS should always be enabled
        // Use environment variable - config.php will override after it's loaded
        $forceSecure = getenv('FORCE_SECURE_COOKIE') === '1';
        
        if ($forceSecure && !$isSecure) {
            // If force secure is enabled but no HTTPS, still set secure (will fail silently if not HTTPS)
            $isSecure = true;
        }
        
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookieParams['path'],
            'domain' => $domain,
            'secure' => $isSecure,
            'httponly' => true, // Prevent JavaScript access to session cookie
            'samesite' => 'Lax' // Protect against CSRF
        ]);
        session_start(); // Initialize the session
    }

    // Auto-create is_published column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN is_published TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) {
        // Column likely already exists, ignore error
    }

    // Auto-create is_accredited column for registrants
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN is_accredited TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) { }

    // Auto-create webinar_id column for registrants if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN webinar_id VARCHAR(50) NULL");
    } catch(PDOException $e) { }

    // Auto-create duration column for webinar_tbl if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN duration VARCHAR(50) NULL DEFAULT '60-minute'");
    } catch(PDOException $e) { }

    // Auto-create timezone column for webinar_tbl if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN timezone VARCHAR(100) NULL DEFAULT 'America/New_York'");
    } catch(PDOException $e) { }

    // Auto-create phone column for registrants
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN phone VARCHAR(25) NULL");
    } catch(PDOException $e) { }

    // Auto-create country_code column for registrants
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN country_code VARCHAR(10) NULL");
    } catch(PDOException $e) { }

    // Reminder tracking columns for registrants
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN reminded_1w TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) { }
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN reminded_1d TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) { }
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN reminded_1h TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) { }
    try {
        $pdo->exec("ALTER TABLE registrants_tbl ADD COLUMN reminded_1m TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) { }

    // Add indexes for optimization (vital for 100k+ rows)
    try {
        $pdo->exec("CREATE INDEX idx_webinar_id ON registrants_tbl(webinar_id)");
    } catch(PDOException $e) { }
    try {
        $pdo->exec("CREATE INDEX idx_email ON registrants_tbl(email)");
    } catch(PDOException $e) { }
    try {
        $pdo->exec("CREATE INDEX idx_fullname ON registrants_tbl(fullname)");
    } catch(PDOException $e) { }

    // Auto-create is_visible column for feedback
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS feedback (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(255) NULL,
                message TEXT NOT NULL,
                rating TINYINT NOT NULL,
                is_visible TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch(PDOException $e) {
        // Table might already exist, try adding columns individually
        try { $pdo->exec("ALTER TABLE feedback ADD COLUMN is_visible TINYINT(1) DEFAULT 1"); } catch(Exception $ex) {}
        try { $pdo->exec("ALTER TABLE feedback ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch(Exception $ex) {}
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(160) NOT NULL,
                message TEXT NOT NULL,
                link_url VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch(PDOException $e) {
    }

    // Email Notifications Log Table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_email_notifications_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                status ENUM('sent', 'failed', 'retrying') NOT NULL DEFAULT 'sent',
                attempts TINYINT NOT NULL DEFAULT 1,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch(PDOException $e) {
    }

    // Email Queue Table (for Drip/Batch sending 300 per day via Cron)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(255) NULL,
                subject VARCHAR(255) NOT NULL,
                html_content LONGTEXT NOT NULL,
                status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL
            )
        ");
        $pdo->exec("CREATE INDEX idx_status ON email_queue(status)");
    } catch(PDOException $e) {}

    // Settings Table for Global Configuration
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings_tbl (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        // Initialize default settings if they don't exist
        $defaults = [
            'webinar_duration' => '60-minute',
            'annual_return'    => '15%',
            'support_email'    => 'Robert@mioymmequities.com',
            'office_phone'     => '914 566 8292 x 199',
            'mobile_phone'     => '914 400 7980',
            'office_address'   => '2900 Westchester Ave Purchase, NY 10577',
            'enable_email_notifications' => '1', // 1 for enabled, 0 for disabled
            'brevo_api_key' => '',
            'cron_secret_send_emails' => 'mioym-cron-' . bin2hex(random_bytes(8)), // Auto-generated secure key
            'cron_secret_reminders' => 'mioym-reminder-' . bin2hex(random_bytes(8)),
            'force_secure_cookie' => '1' // Enforce secure cookies in production (requires HTTPS)
        ];
        
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM settings_tbl WHERE setting_key = ?");
        $insertStmt = $pdo->prepare("INSERT INTO settings_tbl (setting_key, setting_value) VALUES (?, ?)");
        
        foreach ($defaults as $key => $val) {
            $checkStmt->execute([$key]);
            if ($checkStmt->fetchColumn() == 0) {
                $insertStmt->execute([$key, $val]);
            }
        }

        // Cleanup: Remove SMTP settings from DB if they were added
        $pdo->exec("DELETE FROM settings_tbl WHERE setting_key LIKE 'smtp_%'");
    } catch(PDOException $e) {
        // Table likely already exists, ignore error
    }

} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function admin_notify(PDO $pdo, $type, $title, $message, $link_url = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_notifications (type, title, message, link_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([(string)$type, (string)$title, (string)$message, $link_url !== null ? (string)$link_url : null]);
    } catch (Throwable $e) {
    }
}
?>
