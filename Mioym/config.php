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
 * Send dual email notifications upon registration
 */
if (!function_exists('send_dual_registration_emails')) {
    function send_dual_registration_emails($pdo, $userData) {
        if (get_setting('enable_email_notifications', '1') !== '1') return;

        require_once __DIR__ . '/vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            // SMTP Settings (Hardcoded for security/cleanliness as requested)
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'a61f36001@smtp-brevo.com';
        $mail->Password   = 'jUmI9RMntaAkbqKN';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom('mioymequities1@gmail.com', 'Mioym Equities');

        $timestamp = date('Y-m-d h:i A');

        // 1. Send Confirmation Email to User
        try {
            $mail->addAddress($userData['email'], $userData['fullname']);
            $mail->isHTML(true);
            $webinar_title = $userData['title'] ?? 'Our Webinar';
            $webinar_schedule = $userData['schedule'] ?? 'TBD';
            $webinar_link = $userData['webinar_link'] ?? '#';
            $cta_url = $userData['cta_url'] ?? $webinar_link;
            $is_upcoming = $userData['is_upcoming'] ?? false;
            $annual_return = get_setting('annual_return', '15%');
            
            $mail->Subject = 'Registration Confirmed: ' . $webinar_title;
            
            $cta_text = $is_upcoming ? "Add to Calendar" : "Join Webinar Now";
            $cta_note = $is_upcoming ? "(Save the event to your calendar so you won't miss it!)" : "(The session is live! Click the button to join now.)";
            
            // Enhanced Template for Registrant
            $mail->Body = "
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 20px; overflow: hidden; color: #1e293b; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);'>
                    <!-- Header/Banner -->
                    <div style='background-color: #0f2b44; padding: 40px 30px; text-align: center;'>
                        <div style='display: inline-block; background: rgba(255,255,255,0.1); padding: 8px 16px; border-radius: 50px; color: #f59e0b; font-size: 12px; font-weight: bold; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 1px;'>
                            " . $annual_return . " Annual Return Model
                        </div>
                        <h1 style='color: #ffffff; margin: 0; font-size: 28px; font-weight: 800; line-height: 1.2;'>Registration Confirmed!</h1>
                    </div>
                    
                    <div style='padding: 40px 35px; background-color: #ffffff;'>
                        <p style='font-size: 18px; margin-bottom: 24px;'>Hi <strong>" . htmlspecialchars($userData['fullname']) . "</strong>,</p>
                        <p style='line-height: 1.6; margin-bottom: 30px; color: #475569;'>Success! You've secured your spot for our upcoming session. We're looking forward to sharing our <strong>" . $annual_return . " return strategy</strong> with you.</p>
                        
                        <!-- Webinar Info Card -->
                        <div style='background-color: #f8fafc; padding: 25px; border-radius: 16px; border: 1px solid #f1f5f9; margin-bottom: 35px;'>
                            <table width='100%' cellpadding='0' cellspacing='0'>
                                <tr>
                                    <td style='padding-bottom: 15px;'>
                                        <div style='color: #64748b; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Webinar Title</div>
                                        <div style='font-weight: 700; font-size: 16px; color: #0f2b44;'>" . htmlspecialchars($webinar_title) . "</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding-top: 15px; border-top: 1px solid #e2e8f0;'>
                                        <div style='color: #64748b; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Schedule (Date & Time)</div>
                                        <div style='font-weight: 700; font-size: 16px; color: #0f2b44;'>" . htmlspecialchars($webinar_schedule) . "</div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- CTA Button -->
                        <div style='text-align: center; margin-bottom: 35px;'>
                            <a href='" . htmlspecialchars($cta_url) . "' style='display: inline-block; background-color: #f59e0b; color: #0f2b44; padding: 18px 35px; border-radius: 12px; text-decoration: none; font-weight: 800; font-size: 16px; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);'>{$cta_text}</a>
                            <p style='font-size: 12px; color: #94a3b8; margin-top: 12px;'>{$cta_note}</p>
                        </div>
                        
                        <div style='border-top: 1px solid #f1f5f9; pt-30px; margin-top: 30px; padding-top: 30px;'>
                            <p style='line-height: 1.6; font-size: 14px; color: #64748b;'><strong>What's next?</strong> Keep an eye on your inbox. We'll send you the direct access link and some exclusive preparation materials 24 hours before we go live.</p>
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div style='background-color: #0f2b44; padding: 30px; text-align: center; color: #94a3b8; font-size: 13px;'>
                        <div style='color: #ffffff; font-weight: bold; margin-bottom: 10px; font-size: 15px;'>
                            Mioym Equities · <span style='color: #f59e0b;'>" . $annual_return . " Target Return</span>
                        </div>
                        <p style='margin-bottom: 20px; opacity: 0.8;'>2900 Westchester Ave Purchase, NY 10577</p>
                        <div style='border-top: 1px solid rgba(255,255,255,0.1); padding-top: 20px;'>
                            &copy; " . date('Y') . " Mioym Equities. All rights reserved.
                        </div>
                    </div>
                </div>
            ";
            
            $mail->send();
            log_email_notification($pdo, $userData['email'], $mail->Subject, 'sent');
        } catch (Exception $e) {
            log_email_notification($pdo, $userData['email'], 'User Confirmation Email', 'failed', $mail->ErrorInfo);
        }

        // 2. Send Admin Notification Email
        try {
            $mail->clearAddresses();
            $mail->addAddress('jeswaaa1803@gmail.com', 'Admin Notification');
            // $mail->addAddress('Robert@mioymmequities.com', 'Production Admin');
            
            $webinar_title = $userData['title'] ?? 'General Webinar';
            $webinar_schedule = $userData['schedule'] ?? 'TBD';
            $webinar_link = $userData['webinar_link'] ?? 'Not set';
            $mail->Subject = "New Registration: " . $userData['fullname'] . " [" . $webinar_title . "]";
            
            // Enhanced Template for Admin (Removed IP)
            $mail->Body = "
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; color: #1e293b;'>
                    <div style='background-color: #0f2b44; padding: 25px; border-bottom: 4px solid #f59e0b;'>
                        <h2 style='color: #ffffff; margin: 0; font-size: 20px;'>New Lead Registered</h2>
                    </div>
                    <div style='padding: 30px;'>
                        <p style='margin-bottom: 20px; font-size: 15px;'>A new user has just registered for a webinar.</p>
                        
                        <table width='100%' style='border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px; width: 35%;'>Full Name</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600;'>" . htmlspecialchars($userData['fullname']) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Email Address</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600; color: #2563eb;'>" . htmlspecialchars($userData['email']) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Accredited Investor</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600;'>" . ($userData['is_accredited'] ?? 'No') . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Webinar Title</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600;'>" . htmlspecialchars($webinar_title) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Webinar Schedule</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600;'>" . htmlspecialchars($webinar_schedule) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Webinar Link</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600; color: #2563eb;'>" . htmlspecialchars($webinar_link) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Registered at</td>
                                <td style='padding: 12px; border-bottom: 1px solid #f1f5f9; font-weight: 600;'>" . $timestamp . "</td>
                            </tr>
                        </table>
                        
                        <div style='margin-top: 30px; text-align: center;'>
                            <a href='http://localhost/Mioym-Equities-Funnel/Mioym/admin.php' style='display: inline-block; background-color: #0f2b44; color: #ffffff; padding: 12px 25px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px;'>View in Dashboard</a>
                        </div>
                    </div>
                    <div style='background-color: #f8fafc; padding: 15px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9;'>
                        Mioym Equities Admin System • " . get_setting('annual_return', '15%') . " Return Model
                    </div>
                </div>
            ";
            
            $mail->send();
            log_email_notification($pdo, 'jeswaaa1803@gmail.com', $mail->Subject, 'sent');
        } catch (Exception $e) {
            log_email_notification($pdo, 'jeswaaa1803@gmail.com', 'Admin Notification Email', 'failed', $mail->ErrorInfo);
        }

    } catch (Exception $e) {
        log_email_notification($pdo, $userData['email'], 'SMTP Connection/Auth', 'failed', $e->getMessage());
    }
    }
}
?>