<?php
/**
 * Global Configuration System
 * Fetches settings from settings_tbl and provides them as a global array $GLOBAL_SETTINGS
 */

require_once __DIR__ . '/db.php';

$GLOBAL_SETTINGS = [];

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings_tbl");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $GLOBAL_SETTINGS[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Fallback defaults if query fails
    $GLOBAL_SETTINGS = [
        'webinar_duration' => '60-minute',
        'annual_return'    => '15%',
        'support_email'    => 'Robert@mioymmequities.com',
        'office_phone'     => '914 566 8292 x 199',
        'mobile_phone'     => '914 400 7980',
        'office_address'   => '2900 Westchester Ave Purchase, NY 10577'
    ];
}

/**
 * Helper function to get a setting with a fallback
 */
if (!function_exists('get_setting')) {
    function get_setting($key, $default = '') {
        global $GLOBAL_SETTINGS;
        return isset($GLOBAL_SETTINGS[$key]) ? $GLOBAL_SETTINGS[$key] : $default;
    }
}

/**
 * Log email notification attempt
 */
if (!function_exists('log_email_notification')) {
    function log_email_notification($pdo, $recipient, $subject, $status, $error = null, $attempts = 1) {
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_email_notifications_log (recipient_email, subject, status, error_message, attempts) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$recipient, $subject, $status, $error, $attempts]);
        } catch (PDOException $e) {
            // Silent fail for logging
        }
    }
}

/**
 * Send email using Brevo API (Bypasses SMTP port blocking on GoDaddy)
 */
if (!function_exists('send_brevo_api_email')) {
    function send_brevo_api_email($toEmail, $toName, $subject, $htmlContent, $replyToEmail = null, $replyToName = null) {
        $apiKey = trim((string)(get_setting('brevo_api_key', '') ?: (getenv('BREVO_API_KEY') ?: '')));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'Brevo API key is missing. Set settings_tbl.brevo_api_key or BREVO_API_KEY.', 'code' => 0];
        }
        $url = 'https://api.brevo.com/v3/smtp/email';

        $data = [
            'sender' => ['name' => 'Mioym Equities', 'email' => 'invest@mioymequities.com'],
            'to' => [['email' => $toEmail, 'name' => $toName]],
            'subject' => $subject,
            'htmlContent' => $htmlContent
        ];

        if ($replyToEmail) {
            $data['replyTo'] = ['email' => $replyToEmail, 'name' => $replyToName ?: $replyToEmail];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        // SSL verification enabled for security
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'response' => json_decode($response, true)];
        } else {
            return ['success' => false, 'error' => $error ?: $response, 'code' => $httpCode];
        }
    }
}

/**
 * Send bulk email using Brevo API (messageVersions for personalization)
 */
if (!function_exists('send_brevo_batch_email')) {
    function send_brevo_batch_email($subject, $htmlContent, $messageVersions) {
        $apiKey = trim((string)(get_setting('brevo_api_key', '') ?: (getenv('BREVO_API_KEY') ?: '')));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'Brevo API key is missing.', 'code' => 0];
        }
        $url = 'https://api.brevo.com/v3/smtp/email';

        $data = [
            'sender' => ['name' => 'Mioym Equities', 'email' => 'invest@mioymequities.com'],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
            'messageVersions' => $messageVersions
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        // SSL verification enabled for security
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'response' => json_decode($response, true)];
        } else {
            return ['success' => false, 'error' => $error ?: $response, 'code' => $httpCode];
        }
    }
}

/**
 * Get cron secret key - from settings_tbl or environment variable
 */
if (!function_exists('get_cron_secret')) {
    function get_cron_secret($purpose = 'default') {
        // Try to get from database settings table
        $secret = get_setting('cron_secret_' . $purpose, '');
        
        // Fall back to environment variable
        if (empty($secret)) {
            $secret = getenv('CRON_SECRET_' . strtoupper($purpose));
        }
        
        // Fall back to legacy key if nothing configured (for backwards compatibility)
        if (empty($secret)) {
            $legacyKeys = [
                'send_emails' => 'mioym-cron-123',
                'reminders' => 'manual_trigger_492',
                'default' => 'mioym-cron-123'
            ];
            $secret = $legacyKeys[$purpose] ?? $legacyKeys['default'];
        }
        
        return $secret;
    }
}

/**
 * Validate cron secret key
 */
if (!function_exists('validate_cron_secret')) {
    function validate_cron_secret($providedSecret, $purpose = 'default') {
        $expected = get_cron_secret($purpose);
        return !empty($expected) && hash_equals($expected, (string)$providedSecret);
    }
}

/**
 * Rate Limiting for Login Protection
 * Limits: 5 attempts per 15 minutes per IP, 5 attempts per 15 minutes per username
 */
if (!function_exists('check_login_rate_limit')) {
    function check_login_rate_limit(PDO $pdo, $username = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Create rate limit table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS login_rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(128) NOT NULL,
                    identifier_type ENUM('ip', 'username') NOT NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    locked_until TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_identifier (identifier),
                    INDEX idx_locked (locked_until)
                )
            ");
        } catch (Throwable $e) { }
        
        $blocked = false;
        $remaining_seconds = 0;
        
        // Check IP-based rate limit
        $stmt = $pdo->prepare("SELECT attempts, locked_until FROM login_rate_limits WHERE identifier = ? AND identifier_type = 'ip'");
        $stmt->execute([$ip]);
        $ipRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ipRecord) {
            $lockedUntil = strtotime($ipRecord['locked_until']);
            if ($ipRecord['locked_until'] && $lockedUntil > time()) {
                $blocked = true;
                $remaining_seconds = $lockedUntil - time();
            } elseif ($ipRecord['attempts'] >= 5) {
                // Block for 15 minutes
                $pdo->prepare("UPDATE login_rate_limits SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE), attempts = attempts + 1 WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
                $blocked = true;
                $remaining_seconds = 900;
            }
        }
        
        // Check username-based rate limit (if username provided)
        if ($username && !$blocked) {
            $stmt = $pdo->prepare("SELECT attempts, locked_until FROM login_rate_limits WHERE identifier = ? AND identifier_type = 'username'");
            $stmt->execute([$username]);
            $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userRecord) {
                $lockedUntil = strtotime($userRecord['locked_until']);
                if ($userRecord['locked_until'] && $lockedUntil > time()) {
                    $blocked = true;
                    $remaining_seconds = min($remaining_seconds, $lockedUntil - time());
                } elseif ($userRecord['attempts'] >= 5) {
                    $pdo->prepare("UPDATE login_rate_limits SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE), attempts = attempts + 1 WHERE identifier = ? AND identifier_type = 'username'")->execute([$username]);
                    $blocked = true;
                    $remaining_seconds = min($remaining_seconds, 900);
                }
            }
        }
        
        return ['blocked' => $blocked, 'remaining_seconds' => $remaining_seconds];
    }
}

/**
 * Record failed login attempt
 */
if (!function_exists('record_failed_login')) {
    function record_failed_login(PDO $pdo, $username = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            // Update or insert IP-based record
            $stmt = $pdo->prepare("SELECT id FROM login_rate_limits WHERE identifier = ? AND identifier_type = 'ip'");
            $stmt->execute([$ip]);
            
            if ($stmt->fetch()) {
                $pdo->prepare("UPDATE login_rate_limits SET attempts = attempts + 1, locked_until = NULL WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
            } else {
                $pdo->prepare("INSERT INTO login_rate_limits (identifier, identifier_type, attempts) VALUES (?, 'ip', 1)")->execute([$ip]);
            }
            
            // Update or insert username-based record
            if ($username) {
                $stmt = $pdo->prepare("SELECT id FROM login_rate_limits WHERE identifier = ? AND identifier_type = 'username'");
                $stmt->execute([$username]);
                
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE login_rate_limits SET attempts = attempts + 1, locked_until = NULL WHERE identifier = ? AND identifier_type = 'username'")->execute([$username]);
                } else {
                    $pdo->prepare("INSERT INTO login_rate_limits (identifier, identifier_type, attempts) VALUES (?, 'username', 1)")->execute([$username]);
                }
            }
        } catch (Throwable $e) { }
    }
}

/**
 * Clear rate limit after successful login
 */
if (!function_exists('clear_login_rate_limit')) {
    function clear_login_rate_limit(PDO $pdo, $username = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            $pdo->prepare("DELETE FROM login_rate_limits WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
            
            if ($username) {
                $pdo->prepare("DELETE FROM login_rate_limits WHERE identifier = ? AND identifier_type = 'username'")->execute([$username]);
            }
        } catch (Throwable $e) { }
    }
}

/**
 * Rate Limiting for Registration Protection
 * Limits: 3 submissions per hour per IP, 3 submissions per hour per email
 */
if (!function_exists('check_registration_rate_limit')) {
    function check_registration_rate_limit(PDO $pdo, $email = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS registration_rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(128) NOT NULL,
                    identifier_type ENUM('ip', 'email') NOT NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    locked_until TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_identifier (identifier),
                    INDEX idx_locked (locked_until)
                )
            ");
        } catch (Throwable $e) { }
        
        $blocked = false;
        $status = 'allowed';
        $remaining_seconds = 0;
        
        $stmt = $pdo->prepare("SELECT attempts, locked_until FROM registration_rate_limits WHERE identifier = ? AND identifier_type = 'ip'");
        $stmt->execute([$ip]);
        $ipRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ipRecord) {
            $lockedUntil = strtotime($ipRecord['locked_until']);
            if ($ipRecord['locked_until'] && $lockedUntil > time()) {
                $blocked = true;
                $status = 'blocked';
                $remaining_seconds = $lockedUntil - time();
            } elseif ($ipRecord['attempts'] >= 3) {
                $pdo->prepare("UPDATE registration_rate_limits SET locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR), attempts = attempts + 1 WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
                $blocked = true;
                $status = 'blocked';
                $remaining_seconds = 3600;
            }
        }
        
        if ($email && !$blocked) {
            $stmt = $pdo->prepare("SELECT attempts, locked_until FROM registration_rate_limits WHERE identifier = ? AND identifier_type = 'email'");
            $stmt->execute([$email]);
            $emailRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($emailRecord) {
                $lockedUntil = strtotime($emailRecord['locked_until']);
                if ($emailRecord['locked_until'] && $lockedUntil > time()) {
                    $blocked = true;
                    $status = 'blocked';
                    $remaining_seconds = min($remaining_seconds, $lockedUntil - time());
                } elseif ($emailRecord['attempts'] >= 3) {
                    $pdo->prepare("UPDATE registration_rate_limits SET locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR), attempts = attempts + 1 WHERE identifier = ? AND identifier_type = 'email'")->execute([$email]);
                    $blocked = true;
                    $status = 'blocked';
                    $remaining_seconds = min($remaining_seconds, 3600);
                }
            }
        }
        
        return ['blocked' => $blocked, 'status' => $status, 'remaining_seconds' => $remaining_seconds];
    }
}

if (!function_exists('record_registration_attempt')) {
    function record_registration_attempt(PDO $pdo, $email = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            $stmt = $pdo->prepare("SELECT id FROM registration_rate_limits WHERE identifier = ? AND identifier_type = 'ip'");
            $stmt->execute([$ip]);
            
            if ($stmt->fetch()) {
                $pdo->prepare("UPDATE registration_rate_limits SET attempts = attempts + 1, locked_until = NULL WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
            } else {
                $pdo->prepare("INSERT INTO registration_rate_limits (identifier, identifier_type, attempts) VALUES (?, 'ip', 1)")->execute([$ip]);
            }
            
            if ($email) {
                $stmt = $pdo->prepare("SELECT id FROM registration_rate_limits WHERE identifier = ? AND identifier_type = 'email'");
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE registration_rate_limits SET attempts = attempts + 1, locked_until = NULL WHERE identifier = ? AND identifier_type = 'email'")->execute([$email]);
                } else {
                    $pdo->prepare("INSERT INTO registration_rate_limits (identifier, identifier_type, attempts) VALUES (?, 'email', 1)")->execute([$email]);
                }
            }
        } catch (Throwable $e) { }
    }
}

/**
 * Rate Limiting for Contact Form
 * Limits: 3 submissions per hour per IP, 3 submissions per hour per email
 */
if (!function_exists('check_contact_rate_limit')) {
    function check_contact_rate_limit(PDO $pdo, $email = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS contact_rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(128) NOT NULL,
                    identifier_type ENUM('ip', 'email') NOT NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    locked_until TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_identifier (identifier),
                    INDEX idx_locked (locked_until)
                )
            ");
        } catch (Throwable $e) { }
        
        $blocked = false;
        $status = 'allowed';
        $remaining_seconds = 0;
        
        $stmt = $pdo->prepare("SELECT attempts, locked_until FROM contact_rate_limits WHERE identifier = ? AND identifier_type = 'ip'");
        $stmt->execute([$ip]);
        $ipRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ipRecord) {
            $lockedUntil = strtotime($ipRecord['locked_until']);
            if ($ipRecord['locked_until'] && $lockedUntil > time()) {
                $blocked = true;
                $status = 'blocked';
                $remaining_seconds = $lockedUntil - time();
            } elseif ($ipRecord['attempts'] >= 3) {
                $pdo->prepare("UPDATE contact_rate_limits SET locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR), attempts = attempts + 1 WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
                $blocked = true;
                $status = 'blocked';
                $remaining_seconds = 3600;
            }
        }
        
        if ($email && !$blocked) {
            $stmt = $pdo->prepare("SELECT attempts, locked_until FROM contact_rate_limits WHERE identifier = ? AND identifier_type = 'email'");
            $stmt->execute([$email]);
            $emailRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($emailRecord) {
                $lockedUntil = strtotime($emailRecord['locked_until']);
                if ($emailRecord['locked_until'] && $lockedUntil > time()) {
                    $blocked = true;
                    $status = 'blocked';
                    $remaining_seconds = min($remaining_seconds, $lockedUntil - time());
                } elseif ($emailRecord['attempts'] >= 3) {
                    $pdo->prepare("UPDATE contact_rate_limits SET locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR), attempts = attempts + 1 WHERE identifier = ? AND identifier_type = 'email'")->execute([$email]);
                    $blocked = true;
                    $status = 'blocked';
                    $remaining_seconds = min($remaining_seconds, 3600);
                }
            }
        }
        
        return ['blocked' => $blocked, 'status' => $status, 'remaining_seconds' => $remaining_seconds];
    }
}

if (!function_exists('record_contact_attempt')) {
    function record_contact_attempt(PDO $pdo, $email = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            $stmt = $pdo->prepare("SELECT id FROM contact_rate_limits WHERE identifier = ? AND identifier_type = 'ip'");
            $stmt->execute([$ip]);
            
            if ($stmt->fetch()) {
                $pdo->prepare("UPDATE contact_rate_limits SET attempts = attempts + 1, locked_until = NULL WHERE identifier = ? AND identifier_type = 'ip'")->execute([$ip]);
            } else {
                $pdo->prepare("INSERT INTO contact_rate_limits (identifier, identifier_type, attempts) VALUES (?, 'ip', 1)")->execute([$ip]);
            }
            
            if ($email) {
                $stmt = $pdo->prepare("SELECT id FROM contact_rate_limits WHERE identifier = ? AND identifier_type = 'email'");
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE contact_rate_limits SET attempts = attempts + 1, locked_until = NULL WHERE identifier = ? AND identifier_type = 'email'")->execute([$email]);
                } else {
                    $pdo->prepare("INSERT INTO contact_rate_limits (identifier, identifier_type, attempts) VALUES (?, 'email', 1)")->execute([$email]);
                }
            }
        } catch (Throwable $e) { }
    }
}

/**
 * Queue email for delayed/background sending (solves the 300 daily limit constraint)
 */
if (!function_exists('queue_email')) {
    function queue_email($pdo, $toEmail, $toName, $subject, $htmlContent) {
        try {
            $stmt = $pdo->prepare("INSERT INTO email_queue (to_email, to_name, subject, html_content, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$toEmail, $toName, $subject, $htmlContent]);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to queue email: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Send dual email notifications upon registration
 */
if (!function_exists('send_dual_registration_emails')) {
    function send_dual_registration_emails($pdo, $userData) {
        if (get_setting('enable_email_notifications', '1') !== '1') return;

        $timestamp = date('Y-m-d h:i A');
        $webinar_title = $userData['title'] ?? 'Our Webinar';
        $webinar_schedule = $userData['schedule'] ?? 'TBD';
        $webinar_link = $userData['webinar_link'] ?? '#';
        $webinar_duration = $userData['duration'] ?? get_setting('webinar_duration', '60-minute');
        $cta_url = $userData['cta_url'] ?? $webinar_link;
        $is_upcoming = $userData['is_upcoming'] ?? false;
        $annual_return = get_setting('annual_return', '15%');
        
        $cta_text = $is_upcoming ? "Add to Calendar" : "Join Webinar Now";
        $cta_note = $is_upcoming ? "(Save the event to your calendar so you won't miss it!)" : "(The session is live! Click the button to join now.)";

        $calendarLinksHtml = '';
        $main_cta_url = $webinar_link; // Join link for webinar access
        
        if (!empty($userData['webinar_id'])) {
            // Production domain (commented for localhost testing)
            $calendarBase = "https://mioymequities.com/calendar.php?id=" . urlencode($userData['webinar_id']);
            // $calendarBase = "http://localhost/Mioym-Equities-Funnel/Mioym/calendar.php?id=" . urlencode($userData['webinar_id']);
            
            $appleCalendarUrl = preg_replace('/^https?:\/\//i', 'webcal://', $calendarBase . "&type=ics");
            
            $calendarLinksHtml = '
            <div style="margin-top:10px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#475569;">
                <a href="' . $calendarBase . '&type=google" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:7px 12px;border:1px solid #93c5fd;border-radius:8px;color:#1d4ed8;text-decoration:none;margin:0 6px 6px 0;font-weight:700;background:#eff6ff;">Google Calendar</a>
                <a href="' . $calendarBase . '&type=outlook&email=' . urlencode((string)($userData['email'] ?? '')) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:7px 12px;border:1px solid #93c5fd;border-radius:8px;color:#1d4ed8;text-decoration:none;margin:0 6px 6px 0;font-weight:700;background:#eff6ff;">Outlook Calendar</a>
                <a href="' . htmlspecialchars($appleCalendarUrl, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:7px 12px;border:1px solid #c4b5fd;border-radius:8px;color:#6d28d9;text-decoration:none;margin:0 6px 6px 0;font-weight:700;background:#f5f3ff;">Apple Calendar</a>
            </div>';
        }

        // 1. User Confirmation Email
        $userSubject = 'Registration Confirmed: ' . $webinar_title;
        
        // Load and populate the HTML template
        $templatePath = __DIR__ . '/confirmation-email.html';
        if (file_exists($templatePath)) {
            $userBody = file_get_contents($templatePath);
            $userBody = str_replace('[Timestamp]', htmlspecialchars($timestamp), $userBody);
            $userBody = str_replace('[Name]', htmlspecialchars($userData['fullname']), $userBody);
            $userBody = str_replace('[WebinarTitle]', htmlspecialchars($webinar_title), $userBody);
            $userBody = str_replace('[WebinarDate]', htmlspecialchars($webinar_schedule), $userBody);
            $userBody = str_replace('[WebinarDuration]', htmlspecialchars($webinar_duration), $userBody);
            $userBody = str_replace('[Link]', htmlspecialchars($main_cta_url), $userBody);
            $userBody = str_replace('[CtaText]', htmlspecialchars($cta_text), $userBody);
            $userBody = str_replace('[CtaNote]', htmlspecialchars($cta_note), $userBody);
            $userBody = str_replace('[CalendarLinks]', $calendarLinksHtml, $userBody);
            $userBody = str_replace('[WebinarId]', htmlspecialchars((string)($userData['webinar_id'] ?? '')), $userBody);
            $userBody = str_replace('[WebinarDescription]', nl2br(htmlspecialchars((string)($userData['description'] ?? ''))), $userBody);
            $userBody = str_replace('[HostName]', htmlspecialchars((string)($userData['host_name'] ?? '')), $userBody);
            $userBody = str_replace('[HostDescription]', nl2br(htmlspecialchars((string)($userData['host_description'] ?? ''))), $userBody);
            $userBody = str_replace('[CompanyAddress]', htmlspecialchars(get_setting('office_address', '2900 Westchester Ave Purchase, NY 10577')), $userBody);
            // Fallbacks for missing placeholders if any
            $userBody = str_replace('[UnsubscribeLink]', '#', $userBody);
            $userBody = str_replace('[PrivacyPolicy]', '#', $userBody);
        } else {
            // Fallback to basic style if template is missing
            $userBody = "
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 20px; overflow: hidden; color: #1e293b;'>
                    <div style='background-color: #0f2b44; padding: 40px 30px; text-align: center;'>
                        <div style='display: inline-block; background: rgba(255,255,255,0.1); padding: 8px 16px; border-radius: 50px; color: #f59e0b; font-size: 12px; font-weight: bold; margin-bottom: 16px; text-transform: uppercase;'>
                            " . $annual_return . " Annual Return Model
                        </div>
                        <h1 style='color: #ffffff; margin: 0; font-size: 28px;'>Registration Confirmed!</h1>
                    </div>
                    <div style='padding: 40px 30px;'>
                        <p style='font-size: 16px; line-height: 1.6;'>Hi " . htmlspecialchars($userData['fullname']) . ",</p>
                        <p style='font-size: 16px; line-height: 1.6;'>You're all set! You have successfully registered for our exclusive live training.</p>
                        <div style='background-color: #f8fafc; border-radius: 16px; padding: 25px; margin: 30px 0; border: 1px solid #f1f5f9;'>
                            <p style='margin: 0 0 10px 0; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: bold;'>Webinar Details</p>
                            <h3 style='margin: 0 0 15px 0; font-size: 18px; color: #0f2b44;'>" . htmlspecialchars($webinar_title) . "</h3>
                            <p style='margin: 0; font-size: 15px;'><strong>Schedule:</strong> " . htmlspecialchars($webinar_schedule) . "</p>
                            <p style='margin: 5px 0 0 0; font-size: 15px;'><strong>Duration:</strong> " . htmlspecialchars($webinar_duration) . "</p>
                        </div>
                        <div style='text-align: center; margin: 40px 0;'>
                            <a href='" . htmlspecialchars($cta_url) . "' style='background-color: #f59e0b; color: #0f2b44; padding: 18px 35px; border-radius: 12px; text-decoration: none; font-weight: bold; font-size: 16px; display: inline-block;'>" . $cta_text . "</a>
                            <p style='color: #64748b; font-size: 12px; margin-top: 15px;'>" . $cta_note . "</p>
                        </div>
                    </div>
                </div>";
        }

        // Send User Email Immediately (Transactional emails should bypass the daily queue)
        $resUser = send_brevo_api_email($userData['email'], $userData['fullname'], $userSubject, $userBody);
        if ($resUser['success']) {
            log_email_notification($pdo, $userData['email'], $userSubject, 'sent');
            try {
                $stmt = $pdo->prepare("UPDATE registrants_tbl SET email_sent = 1 WHERE email = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userData['email']]);
            } catch (Exception $e) {}
        } else {
            log_email_notification($pdo, $userData['email'], $userSubject, 'failed', $resUser['error']);
        }

        // 2. Admin Notification Email
        $adminEmail = get_setting('support_email', 'Robert@mioymmequities.com');
        $adminSubject = 'NEW LEAD: ' . $userData['fullname'];
        $adminBody = "
            <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 20px; overflow: hidden; color: #1e293b;'>
                <div style='background-color: #0f2b44; padding: 30px; text-align: center; color: white;'>
                    <h2 style='margin: 0;'>New Webinar Registration</h2>
                </div>
                <div style='padding: 30px;'>
                    <p><strong>Name:</strong> {$userData['fullname']}</p>
                    <p><strong>Email:</strong> {$userData['email']}</p>
                    <p><strong>Accredited:</strong> {$userData['is_accredited']}</p>
                    <p><strong>Webinar:</strong> {$userData['title']}</p>
                    <p><strong>Duration:</strong> {$webinar_duration}</p>
                    <p><strong>Registration Time:</strong> {$timestamp}</p>
                </div>
            </div>";

        // Send Admin Email Immediately
        $resAdmin = send_brevo_api_email($adminEmail, 'Admin', $adminSubject, $adminBody);
        if ($resAdmin['success']) {
            log_email_notification($pdo, $adminEmail, $adminSubject, 'sent');
        } else {
            log_email_notification($pdo, $adminEmail, $adminSubject, 'failed', $resAdmin['error']);
        }
    }
}
?>
