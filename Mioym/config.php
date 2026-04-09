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
        $apiKey = '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';
        $url = 'https://api.brevo.com/v3/smtp/email';

        $data = [
            'sender' => ['name' => 'Mioym Equities', 'email' => 'mioymequities1@gmail.com'],
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
        
        // GoDaddy fix for SSL
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

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

        // 1. User Confirmation Email
        $userSubject = 'Registration Confirmed: ' . $webinar_title;
        
        // Load and populate the HTML template
        $templatePath = __DIR__ . '/email-template.html';
        if (file_exists($templatePath)) {
            $userBody = file_get_contents($templatePath);
            $userBody = str_replace('[Name]', htmlspecialchars($userData['fullname']), $userBody);
            $userBody = str_replace('[WebinarTitle]', htmlspecialchars($webinar_title), $userBody);
            $userBody = str_replace('[WebinarDate]', htmlspecialchars($webinar_schedule), $userBody);
            $userBody = str_replace('[WebinarDuration]', htmlspecialchars($webinar_duration), $userBody);
            $userBody = str_replace('[Link]', htmlspecialchars($cta_url), $userBody);
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

        $resUser = send_brevo_api_email($userData['email'], $userData['fullname'], $userSubject, $userBody);
        if ($resUser['success']) {
            log_email_notification($pdo, $userData['email'], $userSubject, 'sent');
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

        $resAdmin = send_brevo_api_email($adminEmail, 'Admin', $adminSubject, $adminBody);
        if ($resAdmin['success']) {
            log_email_notification($pdo, $adminEmail, $adminSubject, 'sent');
        } else {
            log_email_notification($pdo, $adminEmail, $adminSubject, 'failed', $resAdmin['error']);
        }
    }
}
?>