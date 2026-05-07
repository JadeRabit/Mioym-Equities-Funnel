<?php
require_once 'db.php';
require_once 'config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$recaptcha_site_key = get_setting('recaptcha_site_key', '') ?: (getenv('RECAPTCHA_SITE_KEY') ?: '6Lf4IKosAAAAAE1BEwPDNyI4xfqXcXE1gXYQ_Hop');
$recaptcha_secret_key = get_setting('recaptcha_secret_key', '') ?: (getenv('RECAPTCHA_SECRET_KEY') ?: '');

// --- REGISTRATION MODAL LOGIC ---
$regError = '';

// Check if CSRF exists in session, if not create it
if (!isset($_SESSION['csrf']) || !isset($_SESSION['csrf']['value'])) {
    $_SESSION['csrf'] = [
        'value' => bin2hex(random_bytes(32)),
        'expires' => time() + 3600 // Increase to 1 hour for better production stability
    ];
}

function validate_csrf($t) {
    if (!isset($_SESSION['csrf']) || !isset($_SESSION['csrf']['value'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf']['value'], (string)$t);
}

function build_teams_registration_link($webinarLink, $fullName, $email) {
    // Teams registration pages can add extra steps and may ignore prefill params.
    // Keep the URL clean to avoid exposing email/name in query strings.
    $webinarLink = trim((string)$webinarLink);
    return ($webinarLink === '' || $webinarLink === '#') ? '#' : $webinarLink;
}

function ensure_registrants_columns(PDO $pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $requiredColumns = [
        'liquid_50000' => "ALTER TABLE registrants_tbl ADD COLUMN liquid_50000 VARCHAR(10) NULL AFTER is_accredited",
        'deploy_timeline' => "ALTER TABLE registrants_tbl ADD COLUMN deploy_timeline VARCHAR(100) NULL AFTER liquid_50000",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM registrants_tbl LIKE ?");
        $stmt->execute([$columnName]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $pdo->exec($alterSql);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($csrf)) {
        $regError = 'Security check failed. Please reload the page and try again.';
    } else {
        $recaptcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $phone_raw = $phone;
        $phone = preg_replace('/\D+/', '', (string)$phone);
        $phone = substr($phone, 0, 25);
        $country_code = '';
        
        // US Phone Validation - Strict: requires +1 country code + valid US format
        $phone_valid = false;
        $phone_formatted = '';
        if (strlen($phone) >= 10) {
            // Must have +1 or 1 country code (US)
            if (strpos($phone, '1') === 0 && strlen($phone) >= 10) {
                $phone_digits = substr($phone, 1); // Strip leading 1
                // Must be exactly 10 digits + start with 2-9 (valid US area code)
                if (strlen($phone_digits) === 10 && preg_match('/^[2-9]\d{9}$/', $phone_digits)) {
                    $phone_valid = true;
                    $area_code = substr($phone_digits, 0, 3);
                    $exchange = substr($phone_digits, 3, 3);
                    $subscriber = substr($phone_digits, 6, 4);
                    $phone_formatted = '+1 (' . $area_code . ') ' . $exchange . '-' . $subscriber;
                }
            }
        }
        
        $is_accredited = isset($_POST['is_accredited']) ? 1 : 0;
        $liquid_50000 = trim((string)($_POST['liquid_50000'] ?? ''));
        $deploy_timeline = $_POST['deploy_timeline'] ?? [];
        if (!is_array($deploy_timeline)) {
            $deploy_timeline = [$deploy_timeline];
        }
        $deploy_timeline = implode(', ', array_filter(array_map('trim', $deploy_timeline)));
        
        if ($fullname === '' || $email === '' || $phone === '') {
            record_registration_attempt($pdo, $email);
            $regError = "Please fill in Name, Email and Phone Number.";
        } elseif (!$phone_valid) {
            record_registration_attempt($pdo, $email);
            $regError = "Please enter a valid US phone number.";
        } elseif (!isset($_POST['is_accredited'])) {
            record_registration_attempt($pdo, $email);
            $regError = "Please confirm your investor status.";
        } elseif ($recaptcha_secret_key === '') {
            record_registration_attempt($pdo, $email);
            $regError = 'Captcha is not configured. Please contact support.';
        } elseif ($recaptcha_response === '') {
            record_registration_attempt($pdo, $email);
            $regError = 'Please complete the captcha.';
        } else {
            $rateLimitCheck = check_registration_rate_limit($pdo, $email);
            if ($rateLimitCheck['blocked']) {
                $minutes = ceil($rateLimitCheck['remaining_seconds'] / 60);
                $regError = "Too many registration attempts. Please try again in " . $minutes . " minute(s).";
            } else {
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
                    record_registration_attempt($pdo, $email);
                    $regError = 'Captcha verification failed. Please try again.';
                } else {
                    $activeWebinar = $pdo->query("SELECT webinar_id, title, description, host_description, hostname, webinar_link, `schedule_date&time`, duration FROM webinar_tbl WHERE is_published = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if (!$activeWebinar) {
                        $activeWebinar = $pdo->query("SELECT webinar_id, title, description, host_description, hostname, webinar_link, `schedule_date&time`, duration FROM webinar_tbl WHERE status IN ('active', 'upcoming') ORDER BY `schedule_date&time` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    }
                    $webinar_id = $activeWebinar ? $activeWebinar['webinar_id'] : null;
                    $webinar_title = $activeWebinar ? $activeWebinar['title'] : 'General Webinar';
                    $webinar_description = $activeWebinar ? (string)($activeWebinar['description'] ?? '') : '';
                    $webinar_host_name = $activeWebinar ? (string)($activeWebinar['hostname'] ?? '') : '';
                    $webinar_host_description = $activeWebinar ? (string)($activeWebinar['host_description'] ?? '') : '';
                    $webinar_link = $activeWebinar ? $activeWebinar['webinar_link'] : '#';
                    $webinar_duration = $activeWebinar ? $activeWebinar['duration'] : get_setting('webinar_duration', '60-minute');

                    $currentCount = 0;
                    if ($webinar_id) {
                        try {
                            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM registrants_tbl WHERE webinar_id = ?");
                            $stmtCount->execute([$webinar_id]);
                            $currentCount = $stmtCount->fetchColumn();
                        } catch (Exception $e) { }
                    }

                    $emailScheduleStr = 'TBD';
                    $ctaUrl = $webinar_link;
                    $isUpcoming = false;

                    if ($activeWebinar && !empty($activeWebinar['schedule_date&time'])) {
                        try {
                            $base = $activeWebinar['schedule_date&time'];
                            $tzString = $activeWebinar['timezone'] ?? 'America/New_York';
                            
                            try {
                                $eventTime = new DateTime($base, new DateTimeZone($tzString));
                                $now = new DateTime('now', new DateTimeZone($tzString));
                            } catch (Exception $e) {
                                $eventTime = new DateTime($base, new DateTimeZone('America/New_York'));
                                $now = new DateTime('now', new DateTimeZone('America/New_York'));
                                $tzString = 'America/New_York';
                            }
                            
                            $isUpcoming = ($now < $eventTime);

                            $dateTitle = $eventTime->format('l j F');
                            $timeStr = strtolower($eventTime->format('g:i a'));
                            $emailScheduleStr = $dateTitle . ' · ' . $timeStr;

                            if ($isUpcoming) {
                                $title = urlencode($webinar_title);
                                $utcTime = clone $eventTime;
                                $utcTime->setTimezone(new DateTimeZone('UTC'));
                                $startTime = $utcTime->format('Ymd\THis\Z');
                                $endTime = clone $utcTime;
                                $endTime->modify('+1 hour');
                                $endTimeStr = $endTime->format('Ymd\THis\Z');
                                $detailsText = "Join our webinar: " . $webinar_title . "\nSchedule: " . $emailScheduleStr . "\n\nNote: This calendar event is automatically adjusted to your local timezone.\n\nLearn about our " . get_setting('annual_return', '15%') . " return strategy.";
                                $details = urlencode($detailsText);
                                $ctaUrl = "https://www.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$startTime}/{$endTimeStr}&details={$details}&location=" . urlencode($webinar_link) . "&ctz={$tzString}";
                            }
                        } catch (Exception $e) { }
                    }
                    
                    // Extra safeguard: ensure formatted phone is complete (+1 (XXX) XXX-XXXX = 14 chars)
                    if ($phone_valid && strlen($phone_formatted) !== 14) {
                        record_registration_attempt($pdo, $email);
                        $regError = "Please enter a valid US phone number.";
                        $phone_valid = false;
                    }

                    ensure_registrants_columns($pdo);
                    $stmt = $pdo->prepare("INSERT INTO registrants_tbl (fullname, email, phone, country_code, is_accredited, liquid_50000, deploy_timeline, webinar_id, registration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$fullname, $email, $phone_formatted, $country_code, $is_accredited, $liquid_50000 !== '' ? $liquid_50000 : null, $deploy_timeline !== '' ? $deploy_timeline : null, $webinar_id]);

                    if (function_exists('send_dual_registration_emails')) {
                        send_dual_registration_emails($pdo, [
                            'fullname' => $fullname,
                            'email' => $email,
                            'is_accredited' => $is_accredited ? 'Yes' : 'No',
                            'title' => $webinar_title,
                            'webinar_id' => $webinar_id,
                            'description' => $webinar_description,
                            'host_name' => $webinar_host_name,
                            'host_description' => $webinar_host_description,
                            'schedule' => $emailScheduleStr,
                            'webinar_link' => $webinar_link,
                            'cta_url' => $ctaUrl,
                            'is_upcoming' => $isUpcoming,
                            'duration' => $webinar_duration
                        ]);
                    }

                    if (function_exists('admin_notify')) {
                        $msg = "Name: " . $fullname . "\n\n" .
                              "Email: " . $email . "\n\n" .
                              "Phone: " . $phone_formatted . "\n\n" .
                              "Accredited: " . ($is_accredited ? 'Yes' : 'No') . "\n\n" .
                              "Webinar: " . $webinar_title . "\n\n" .
                              "Duration: " . $webinar_duration . "\n\n" .
                              "Registration Time: " . date('Y-m-d h:i A');
                        admin_notify($pdo, 'registrants', 'New Registration', $msg, 'registrants.php?search=' . urlencode($email ?: $fullname));
                    }
                    header("Location: thankyou.php?fullname=" . urlencode($fullname));
                    exit;
                }
            }
        }
    }
}

$feedbackTableReady = true;
try {
    $pdo->query("SELECT name, email, message, rating, is_visible, created_at FROM feedback LIMIT 1");
} catch (Throwable $e) {
    $feedbackTableReady = false;
}

$reviewFlash = '';
$reviewError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['feedback_submit'])) {
    $recaptcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));
    $name = trim((string)($_POST['r_name'] ?? ''));
    $email = trim((string)($_POST['r_email'] ?? ''));
    $message = trim((string)($_POST['r_message'] ?? ''));
    $rating = (int)($_POST['r_rating'] ?? 0);

    if ($name === '' || $message === '' || $rating < 1 || $rating > 5) {
        $reviewError = 'Please provide your name, a message, and a rating (1–5).';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reviewError = 'Please enter a valid email address or leave it blank.';
    } elseif ($recaptcha_secret_key === '') {
        $reviewError = 'Captcha is not configured. Please contact support.';
    } elseif ($recaptcha_response === '') {
        $reviewError = 'Please complete the captcha.';
    } elseif (!$feedbackTableReady) {
        $reviewError = 'Feedback storage is not available right now.';
    } else {
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
            $reviewError = 'Captcha verification failed. Please try again.';
        } else {
        $stmt = $pdo->prepare("INSERT INTO feedback (name, email, message, rating) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email !== '' ? $email : null, $message, $rating]);
        if (function_exists('admin_notify')) {
            admin_notify($pdo, 'feedback', 'New Review', $name . ' left a ' . $rating . '/5 review.', 'index.php#reviews');
        }
        header('Location: index.php?review=success#reviews');
        exit;
        }
    }
}

if (isset($_GET['review']) && $_GET['review'] === 'success') {
    $reviewFlash = 'Thank you! Your review has been submitted.';
}

$contactTableReady = true;
try {
    $pdo->query("SELECT name, email, subject, message FROM contactus_tbl LIMIT 1");
} catch (Throwable $e) {
    $contactTableReady = false;
}
if (!$contactTableReady) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contactus_tbl (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->query("SELECT name, email, subject, message FROM contactus_tbl LIMIT 1");
        $contactTableReady = true;
    } catch (Throwable $e) {
        $contactTableReady = false;
    }
}

$contactFlash = '';
$contactError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $recaptcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));
    $cName = trim((string)($_POST['c_name'] ?? ''));
    $cEmail = trim((string)($_POST['c_email'] ?? ''));
    $cSubject = trim((string)($_POST['c_subject'] ?? ''));
    $cMessage = trim((string)($_POST['c_message'] ?? ''));

if ($cName === '' || $cEmail === '' || $cMessage === '') {
        record_contact_attempt($pdo, $cEmail);
        $contactError = 'Please fill in your name, email, and message.';
    } elseif (!filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
        record_contact_attempt($pdo, $cEmail);
        $contactError = 'Please enter a valid email address.';
    } elseif ($recaptcha_secret_key === '') {
        record_contact_attempt($pdo, $cEmail);
        $contactError = 'Captcha is not configured. Please contact support.';
    } elseif ($recaptcha_response === '') {
        record_contact_attempt($pdo, $cEmail);
        $contactError = 'Please complete the captcha.';
    } else {
        $contactRateLimit = check_contact_rate_limit($pdo, $cEmail);
        if ($contactRateLimit['blocked']) {
            $minutes = ceil($contactRateLimit['remaining_seconds'] / 60);
            $contactError = "Too many submissions. Please try again in " . $minutes . " minute(s).";
        } else {
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
            record_contact_attempt($pdo, $cEmail);
            $contactError = 'Captcha verification failed. Please try again.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO contactus_tbl (name, email, subject, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$cName, $cEmail, $cSubject !== '' ? $cSubject : null, $cMessage]);
        
        if (function_exists('admin_notify')) {
            $title = $cSubject !== '' ? $cSubject : 'Contact request';
            admin_notify($pdo, 'contact', 'New Contact Message', $cName . ': ' . $title, 'index.php#contact');
        }

        $safeSubject = $cSubject !== '' ? $cSubject : 'New Contact Us Message';
        $adminEmail = get_setting('support_email', 'Robert@mioymmequities.com');
        $replyHref = 'mailto:' . rawurlencode($cEmail) . '?subject=' . rawurlencode('Re: ' . $safeSubject);
        
        $body = '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us · Mioym Equities</title>
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse !important; }
    @media screen and (max-width: 620px) {
      .container { width: 100% !important; }
      .px { padding-left: 18px !important; padding-right: 18px !important; }
      .stack { display:block !important; width:100% !important; }
      .center { text-align:center !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f6f8fb;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f8fb;">
    <tr>
      <td align="center" style="padding:28px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="container" style="width:600px;max-width:600px;">
          <tr>
            <td class="px" style="padding:0 24px 14px 24px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td class="center" style="font-family:sans-serif;font-size:14px;line-height:20px;color:#475569;">
                    <span style="font-weight:800;font-size:18px;color:#0f172a;">Mioym Equities</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 12px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;">
                <tr>
                  <td class="px" style="padding:18px 24px;background:#0f2b44;">
                    <div style="font-family:sans-serif;font-size:12px;color:rgba(255,255,255,0.85);font-weight:700;text-transform:uppercase;">New Inquiry Received</div>
                    <div style="margin-top:10px;font-family:sans-serif;font-size:18px;color:#ffffff;font-weight:800;">' . htmlspecialchars($safeSubject, ENT_QUOTES, "UTF-8") . '</div>
                  </td>
                </tr>
                <tr>
                  <td class="px" style="padding:22px 24px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td class="stack" style="width:50%;padding-right:10px;vertical-align:top;">
                          <div style="font-family:sans-serif;font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;">Name</div>
                          <div style="margin-top:6px;font-family:sans-serif;font-size:14px;color:#0f172a;font-weight:800;">' . htmlspecialchars($cName, ENT_QUOTES, "UTF-8") . '</div>
                        </td>
                        <td class="stack" style="width:50%;padding-left:10px;vertical-align:top;">
                          <div style="font-family:sans-serif;font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;">Email</div>
                          <div style="margin-top:6px;font-family:sans-serif;font-size:14px;">
                            <a href="mailto:' . htmlspecialchars($cEmail, ENT_QUOTES, "UTF-8") . '" style="color:#1e4a7a;text-decoration:underline;font-weight:800;">' . htmlspecialchars($cEmail, ENT_QUOTES, "UTF-8") . '</a>
                          </div>
                        </td>
                      </tr>
                    </table>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                      <tr>
                        <td style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;">
                          <div style="font-family:sans-serif;font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;">Message</div>
                          <div style="margin-top:8px;font-family:sans-serif;font-size:14px;line-height:22px;color:#0f172a;white-space:pre-line;">' . htmlspecialchars($cMessage, ENT_QUOTES, "UTF-8") . '</div>
                        </td>
                      </tr>
                    </table>
                    <div style="margin-top:18px;text-align:center;">
                        <a href="' . htmlspecialchars($replyHref, ENT_QUOTES, "UTF-8") . '" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#f59e0b;color:#0f2b44;font-family:sans-serif;font-size:16px;font-weight:800;text-decoration:none;">Reply to Sender</a>
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

 </html>';

        $res = send_brevo_api_email($adminEmail, 'Admin', 'Contact Us: ' . $safeSubject, $body, $cEmail, $cName);
        
        if ($res['success']) {
                header('Location: index.php?contact=success#contact');
                exit;
            } else {
                $contactError = 'Message saved, but email sending failed. Please try again later.';
            }
            }
        }
    }
}

if (isset($_GET['contact']) && $_GET['contact'] === 'success') {
    $contactFlash = 'Thanks! Your message has been sent.';
}

// Fetch the explicitly published webinar
$latestWebinar = $pdo->query("
    SELECT * FROM webinar_tbl 
    WHERE is_published = 1 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// If no webinar is explicitly published, fall back to the most recent active/upcoming one
if (!$latestWebinar) {
    $latestWebinar = $pdo->query("
        SELECT * FROM webinar_tbl 
        WHERE status IN ('active', 'upcoming') 
        ORDER BY `schedule_date&time` ASC 
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

// Set default values if no webinar is found at all
$hostName = $latestWebinar['hostname'] ?? 'Elena Marchetti';
$hostPic = $latestWebinar['host_pic'] ?? null;
$webinarTitle = $latestWebinar['title'] ?? 'How to deploy €50k–€500k into institutional real estate (without connections)';
$webinarDuration = $latestWebinar['duration'] ?? get_setting('webinar_duration', '60-minute');
$webinarSubheading = trim((string)($latestWebinar['subheading'] ?? ''));
$webinarSubheadingSize = (int)($latestWebinar['subheading_size'] ?? 20);
if ($webinarSubheadingSize < 10) $webinarSubheadingSize = 10;
if ($webinarSubheadingSize > 80) $webinarSubheadingSize = 80;
$webinarSubheadingBold = isset($latestWebinar['subheading_bold']) && (int)$latestWebinar['subheading_bold'] === 1;
$webinarSubheadingColor = trim((string)($latestWebinar['subheading_color'] ?? ''));
if ($webinarSubheadingColor === '' || !preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $webinarSubheadingColor)) {
    $webinarSubheadingColor = '#ffffff';
} elseif ($webinarSubheadingColor[0] !== '#') {
    $webinarSubheadingColor = '#' . $webinarSubheadingColor;
}
$webinarSubheadingItems = [];
$rawSubItems = trim((string)($latestWebinar['subheading_items_json'] ?? ''));
if ($rawSubItems !== '') {
    $decoded = json_decode($rawSubItems, true);
    if (is_array($decoded)) {
        $allowedFonts = ['system_sans','system_serif','system_mono','arial','georgia','times','courier','verdana','trebuchet'];
        foreach ($decoded as $it) {
            if (!is_array($it)) continue;
            $text = trim((string)($it['text'] ?? ''));
            if ($text === '') continue;
            $size = (int)($it['size'] ?? 20);
            if ($size < 10) $size = 10;
            if ($size > 80) $size = 80;
            $spacing = (int)($it['spacing'] ?? 8);
            if ($spacing < 0) $spacing = 0;
            if ($spacing > 64) $spacing = 64;
            $color = trim((string)($it['color'] ?? '#ffffff'));
            if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color)) $color = '#ffffff';
            if ($color !== '' && $color[0] !== '#') $color = '#' . $color;
            $bold = !empty($it['bold']);
            $font = (string)($it['font'] ?? 'system_sans');
            if (!in_array($font, $allowedFonts, true)) $font = 'system_sans';
            $webinarSubheadingItems[] = ['text' => $text, 'size' => $size, 'spacing' => $spacing, 'color' => $color, 'bold' => $bold, 'font' => $font];
            if (count($webinarSubheadingItems) >= 12) break;
        }
    }
}
$hasAnySubheading = !empty($webinarSubheadingItems) || $webinarSubheading !== '';

function landing_subheading_font_stack($key) {
    $key = (string)$key;
    $map = [
        'system_sans' => "ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif",
        'system_serif' => "ui-serif, Georgia, Cambria, 'Times New Roman', Times, serif",
        'system_mono' => "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace",
        'arial' => "Arial, Helvetica, sans-serif",
        'georgia' => "Georgia, Cambria, 'Times New Roman', Times, serif",
        'times' => "'Times New Roman', Times, serif",
        'courier' => "'Courier New', Courier, monospace",
        'verdana' => "Verdana, Geneva, sans-serif",
        'trebuchet' => "'Trebuchet MS', 'Segoe UI', sans-serif"
    ];
    return $map[$key] ?? $map['system_sans'];
}

function hero_split_title($title) {
    $title = trim(preg_replace('/\s+/', ' ', (string)$title));
    if ($title === '') return ['', ''];
    $len = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    if ($len <= 56) return [$title, ''];
    $mid = (int)floor($len / 2);
    $best = null;
    $window = min(24, $len - 1);
    for ($i = 0; $i <= $window; $i++) {
        $left = $mid - $i;
        $right = $mid + $i;
        if ($left > 0) {
            $ch = function_exists('mb_substr') ? mb_substr($title, $left, 1) : substr($title, $left, 1);
            if ($ch === ' ') { $best = $left; break; }
        }
        if ($right < $len - 1) {
            $ch = function_exists('mb_substr') ? mb_substr($title, $right, 1) : substr($title, $right, 1);
            if ($ch === ' ') { $best = $right; break; }
        }
    }
    if ($best === null) return [$title, ''];
    $a = trim(function_exists('mb_substr') ? mb_substr($title, 0, $best) : substr($title, 0, $best));
    $b = trim(function_exists('mb_substr') ? mb_substr($title, $best + 1) : substr($title, $best + 1));
    return [$a, $b];
}

function hero_title_html($text, $annualReturn) {
    $text = (string)$text;
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
$webinarDescriptionRaw = trim((string)($latestWebinar['description'] ?? ''));
$webinarDescriptionLines = preg_split("/\r\n|\r|\n/", $webinarDescriptionRaw);
$webinarDescriptionLines = array_values(array_filter(array_map('trim', $webinarDescriptionLines), static fn ($l) => $l !== ''));
$webinarDescriptionLead = $webinarDescriptionLines[0] ?? '';
$webinarDescriptionBullets = array_slice($webinarDescriptionLines, 1);
$scheduleDate = isset($latestWebinar['schedule_date&time']) 
    ? date('F j, Y · g:i A', strtotime($latestWebinar['schedule_date&time'])) 
    : 'April 24, 2025 · 6:00 PM';
$countdownTargetMs = 0;
if (!empty($latestWebinar['schedule_date&time'])) {
    try {
        // Force countdown reference to America/New_York (EST/EDT).
        $countdownDate = new DateTime($latestWebinar['schedule_date&time'], new DateTimeZone('America/New_York'));
        $countdownTargetMs = ((int)$countdownDate->format('U')) * 1000;
    } catch (Exception $e) {
        $countdownTargetMs = 0;
    }
}
$heroBgUrl = (string)get_setting('hero_bg_url', 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7a/View_of_Empire_State_Building_from_Rockefeller_Center_New_York_City_dllu_%28cropped%29.jpg/1920px-View_of_Empire_State_Building_from_Rockefeller_Center_New_York_City_dllu_%28cropped%29.jpg');
$annualReturn = (string)get_setting('annual_return', '15%');
$annualEsc = htmlspecialchars($annualReturn, ENT_QUOTES, 'UTF-8');

// Fetch Dynamic Social Proof Data
$webinarId = $latestWebinar['webinar_id'] ?? null;
$avatars = [];

// 1. Total count of registrants for THIS specific webinar
if ($webinarId) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM registrants_tbl WHERE webinar_id = ?");
    $stmtCount->execute([$webinarId]);
    $registrantCount = (int)$stmtCount->fetchColumn();

    // 2. Fetch last 3 registrants for initials for THIS specific webinar
    $stmtRecent = $pdo->prepare("SELECT fullname FROM registrants_tbl WHERE webinar_id = ? ORDER BY registration_date DESC LIMIT 3");
    $stmtRecent->execute([$webinarId]);
    $recentRegistrants = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recentRegistrants as $r) {
        $parts = explode(' ', trim($r['fullname']));
        $initials = (count($parts) >= 2) ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1)) : strtoupper(substr($parts[0], 0, 2));
        $avatars[] = $initials;
    }
} else {
    $registrantCount = 0;
    $recentRegistrants = [];
}

$defaults = ['JM', 'AB', 'RT'];
while (count($avatars) < 3) { $avatars[] = $defaults[count($avatars)]; }

function getInitials($name) {
     $words = explode(' ', trim($name));
     $initials = '';
     if (count($words) >= 2) {
         $initials = strtoupper(substr($words[0], 0, 1) . substr($words[count($words)-1], 0, 1));
     } else {
         $initials = strtoupper(substr($words[0], 0, 2));
     }
     return $initials;
 }

$feedbacks = [];
if ($feedbackTableReady) {
    try {
        $feedbacks = $pdo->query("SELECT name, email, message, rating FROM feedback WHERE is_visible = 1 ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $feedbacks = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mioym Equities · Webinar</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <!-- Tailwind + Font Awesome -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php if (!empty($recaptcha_site_key)): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        /* ========== SECTION 1: CUSTOM STYLES ========== */
        html, body { overflow-x: hidden; }
        /* Top Banner with background image (walang image, gradient na lang) */
        .announcement-banner {
            background: linear-gradient(135deg, #0f2b44 0%, #1e4a7a 100%);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        /* Dark overlay para sa top banner (wala nang image, diretso na) */
        .banner-overlay {
            width: 100%;
            height: 100%;
        }
        /* Banner content animation */
        .banner-content {
            animation: subtlePulse 2s infinite ease-in-out;
        }
        @keyframes subtlePulse {
            0%, 100% { opacity: 0.95; }
            50% { opacity: 1; }
        }
        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }
        /* Countdown tag styling */
        .countdown-tag {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(4px);
        }
        .reviews-stage {
            background: transparent;
            border-radius: 2rem;
            padding: 3rem 1.5rem;
        }
        .reviews-stack {
            position: relative;
            height: 460px;
        }
        .review-card {
            position: absolute;
            left: 50%;
            top: 0;
            width: min(100%, 28rem);
            height: 400px;
            display: flex;
            flex-direction: column;
            transition: all 500ms cubic-bezier(0.4, 0, 0.2, 1);
            transform: translate(-50%, 30px) scale(0.9);
            opacity: 0;
            z-index: 10;
        }
        .review-card.is-prev {
            transform: translate(calc(-50% - 280px), 40px) scale(0.85);
            opacity: 0.4;
            z-index: 15;
            filter: blur(2px);
        }
        .review-card.is-next {
            transform: translate(calc(-50% + 280px), 40px) scale(0.85);
            opacity: 0.4;
            z-index: 15;
            filter: blur(2px);
        }
        .review-card.is-active {
            transform: translate(-50%, 0px) scale(1);
            opacity: 1;
            z-index: 25;
            box-shadow: 0 40px 80px rgba(15, 43, 68, 0.15);
        }
        .review-card.is-hidden {
            opacity: 0;
            transform: translate(-50%, 60px) scale(0.8);
            z-index: 0;
            pointer-events: none;
        }
        @media (max-width: 1024px) {
            .review-card.is-prev,
            .review-card.is-next {
                opacity: 0;
                pointer-events: none;
            }
        }
        @media (max-width: 640px) {
             .reviews-stack { height: 440px; }
             .review-card { width: calc(100% - 32px); padding: 1.5rem; height: 380px; }
         }
         .custom-scrollbar::-webkit-scrollbar { width: 4px; }
         .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
         .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
         .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #94a3b8; }
     </style>
</head>
<body class="bg-gradient-to-b from-slate-50 to-white text-slate-800 font-sans antialiased overflow-x-hidden">

    <!-- ========== SECTION 3: SUCCESS MESSAGE ========== -->
    <?php if (isset($_GET['registered']) && isset($_GET['name'])): ?>
    <div class="max-w-6xl mx-auto px-5 sm:px-8 pt-6">
        <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-lg shadow-md flex items-center gap-3">
            <i class="fas fa-check-circle text-green-500 text-xl"></i>
            <div>
                <strong>Registration successful!</strong> Thank you for registering, <span class="font-bold"><?php echo htmlspecialchars($_GET['name']); ?></span>! You will receive a confirmation email shortly.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========== SECTION 4: MAIN CONTENT AREA ========== -->
    <div class=" w-full border-b border-white/10 shadow-[0_10px_30px_rgba(0,0,0,0.25)]">
    <div class="max-w-[1300px] mx-auto px-4 sm:px-6">
        <!-- ========== SECTION 4.1: HEADER ========== -->
        <div class="flex flex-col sm:flex-row justify-between items-center py-4 sm:py-0 gap-4">
            <div class="flex items-center">
                <div class="text-white w-48 sm:w-64 md:w-80 h-16 sm:h-24 md:h-32 flex items-center justify-center overflow-hidden">
                    <img src="img/logo2.png" alt="Mioym Group" class="w-full h-full object-contain">
                </div>
            </div>
            <span id="header-schedule" class="inline-flex items-center gap-2 text-base sm:text-xl md:text-2xl font-black text-[#0f2b44] mb-4 sm:mb-0">
                 <?php echo htmlspecialchars($scheduleDate); ?>
            </span>
        </div>
    </div>
    </div>

        <!-- ========== SECTION 4.2: HERO SECTION ========== -->
    <section id="hero-section" class="hero-section group relative isolate overflow-hidden bg-slate-950 bg-cover bg-center bg-no-repeat" style="background-image: url('<?php echo htmlspecialchars($heroBgUrl, ENT_QUOTES, 'UTF-8'); ?>');">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-950/80 to-slate-900/95 backdrop-blur-[2px]"></div>
        <div class="pointer-events-none absolute -top-32 -left-32 h-80 w-80 rounded-full bg-amber-500/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-40 -right-40 h-[28rem] w-[28rem] rounded-full bg-blue-500/10 blur-3xl"></div>

        <div class="hero-content relative z-10 max-w-[1300px] mx-auto px-3 sm:px-4 lg:px-6 py-10 md:py-20">
            <div class="w-full lg:max-w-[100%]">
                <div class="inline-flex text-[10px] sm:text-xs font-extrabold uppercase tracking-[0.2em] text-yellow-500">
                    ACCREDITED INVESTORS ONLY
                </div>

                <h1 class="mt-4 font-black tracking-[-0.03em] text-white leading-[1.02] break-words transition-transform duration-200 group-hover:-translate-y-0.5">
                    <span class="block text-[clamp(2rem,4.8vw,5rem)] drop-shadow-[0_10px_30px_rgba(0,0,0,0.35)]"><?php echo nl2br(htmlspecialchars($webinarTitle, ENT_QUOTES, 'UTF-8')); ?></span>
                </h1>
                <?php if (!empty($webinarSubheadingItems)): ?>
                    <div class="mt-6 max-w-4xl space-y-2">
                        <?php foreach ($webinarSubheadingItems as $it): ?>
                            <p class="leading-relaxed" style="font-size: clamp(14px, 3vw, <?php echo (int)($it['size'] ?? 20); ?>px); margin-bottom: <?php echo (int)($it['spacing'] ?? 8); ?>px; font-weight: <?php echo !empty($it['bold']) ? 800 : 600; ?>; color: <?php echo htmlspecialchars((string)($it['color'] ?? '#cbd5e1')); ?>; font-family: <?php echo htmlspecialchars(landing_subheading_font_stack((string)($it['font'] ?? 'system_sans'))); ?>;">
                                <?php echo htmlspecialchars((string)($it['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($webinarSubheading !== ''): ?>
                    <p class="mt-6 max-w-4xl leading-relaxed" style="font-size: clamp(14px, 3vw, <?php echo (int)$webinarSubheadingSize; ?>px); font-weight: <?php echo $webinarSubheadingBold ? 800 : 600; ?>; color: <?php echo htmlspecialchars($webinarSubheadingColor); ?>; font-family: <?php echo htmlspecialchars(landing_subheading_font_stack('system_sans')); ?>;">
                        <?php echo nl2br(htmlspecialchars($webinarSubheading, ENT_QUOTES, 'UTF-8')); ?>
                    </p>
                <?php else: ?>
                    <p class="mt-6 text-sm sm:text-base md:text-lg text-slate-300 max-w-4xl leading-relaxed">
                        Learn about our 30 min webinar, how we buy below market, use conservative resale assumptions and keep short hold periods.
                    </p>
                <?php endif; ?>
                <div class="mt-10 max-w-5xl mr-auto text-left">
                    <div class="bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl p-5 sm:p-6 border-l-4 border-amber-400/70">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-white/10 border border-white/10 flex items-center justify-center text-amber-300 shrink-0">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div>
                                <div class="text-[20px] font-extrabold uppercase tracking-[0.2em] text-slate-300">About This Webinar</div>
                            </div>
                        </div>

                        <?php
                            $aboutLines = [];
                            if ($webinarDescriptionRaw !== '') {
                                $chunks = preg_split("/\r\n|\r|\n/", (string)$webinarDescriptionRaw);
                                $buffer = '';
                                foreach ((array)$chunks as $lineRaw) {
                                    $line = trim((string)$lineRaw);
                                    if ($line === '') {
                                        if ($buffer !== '') {
                                            $aboutLines[] = trim($buffer);
                                            $buffer = '';
                                        }
                                        continue;
                                    }
                                    $line = ltrim($line, "•- \t");
                                    $buffer = $buffer === '' ? $line : ($buffer . ' ' . $line);
                                }
                                if ($buffer !== '') $aboutLines[] = trim($buffer);
                            } elseif (!empty($webinarDescriptionBullets)) {
                                $aboutLines = array_values(array_filter(array_map(static fn($l) => ltrim((string)$l, "•- \t"), $webinarDescriptionBullets), static fn($l) => $l !== ''));
                            }
                            $aboutLead = $aboutLines[0] ?? '';
                            $aboutMore = array_slice($aboutLines, 1);
                        ?>
                        <?php if ($aboutLead !== ''): ?>
                            <ul class="mt-4 space-y-3 text-slate-100 text-base sm:text-lg leading-relaxed font-semibold list-disc pl-6">
                                <li class="font-bold">
                                    <?php echo str_replace($annualEsc, '<span class="text-amber-300 font-bold">' . $annualEsc . '</span>', htmlspecialchars($aboutLead, ENT_QUOTES, 'UTF-8')); ?>
                                    <?php if (!empty($aboutMore)): ?>
                                        <button type="button" data-model-expand aria-expanded="false" class="ml-2 text-amber-300 underline underline-offset-4 font-bold hover:text-amber-200 hover:drop-shadow-[0_0_10px_rgba(245,158,11,0.7)] transition-colors duration-200 whitespace-nowrap">
                                            Read more
                                        </button>
                                    <?php endif; ?>
                                </li>
                                <?php foreach ($aboutMore as $line): ?>
                                    <li class="hidden font-semibold" data-model-detail><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                                <?php if (!empty($aboutMore)): ?>
                                    <li class="hidden pt-1" data-model-collapse-row>
                                        <button type="button" data-model-collapse class="text-amber-300 underline underline-offset-4 font-bold hover:text-amber-200 hover:drop-shadow-[0_0_10px_rgba(245,158,11,0.7)] transition-colors duration-200">
                                            Read less
                                        </button>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-10 flex flex-col sm:flex-row items-stretch justify-start gap-4">
                    <button type="button" onclick="openRegistrationModal()" class="w-full sm:w-auto min-h-11 group relative overflow-hidden bg-gradient-to-r from-amber-500 to-orange-400 hover:from-amber-400 hover:to-orange-300 text-[#0f2b44] font-black text-base sm:text-lg px-7 py-4 rounded-2xl shadow-xl shadow-amber-500/20 transition-transform duration-200 hover:scale-[1.02] active:scale-[0.99] inline-flex items-center justify-center gap-3">
                        <i class="fas fa-calendar-check"></i>
                        <span>Register Now — It’s Free</span>
                        <span class="absolute inset-0 bg-gradient-to-r from-transparent via-white/25 to-transparent -translate-x-full group-hover:animate-[shimmer_1.6s_infinite]"></span>
                    </button>

                    <div id="webinarCountdown" class="hidden w-full sm:w-auto min-h-11 items-center justify-start bg-white/5 backdrop-blur-md border border-white/10 px-5 py-4 rounded-2xl shadow-lg">
                        <div class="flex items-center gap-4">
                            <div class="text-[10px] uppercase font-extrabold text-amber-400 tracking-[0.2em]" id="cd-label">Starts In</div>
                            <div id="cd-timer" class="flex items-center gap-3 text-white font-mono font-extrabold text-base sm:text-lg leading-none">
                                <span><span id="cd-days">00</span><span class="ml-1 text-[10px] text-white/50 font-sans">Days</span></span>
                                <span><span id="cd-hours">00</span><span class="ml-1 text-[10px] text-white/50 font-sans">Hrs</span></span>
                                <span><span id="cd-minutes">00</span><span class="ml-1 text-[10px] text-white/50 font-sans">Mins</span></span>
                                <span><span id="cd-seconds" class="text-amber-400">00</span><span class="ml-1 text-[10px] text-white/50 font-sans">Secs</span></span>
                            </div>
                            <div id="cd-live" class="hidden items-center gap-2 text-rose-500 font-extrabold text-sm uppercase tracking-[0.2em]">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                Live Now
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($registrantCount > 0): ?>
                <div class="mt-8 flex flex-col sm:flex-row items-start sm:items-center justify-start gap-3 text-xs sm:text-sm text-slate-200">
                    <div class="flex -space-x-2">
                        <?php 
                        $colors = ['bg-amber-400', 'bg-amber-300', 'bg-amber-200'];
                        foreach($recentRegistrants as $i => $reg): 
                        ?>
                            <span class="w-8 h-8 rounded-full <?php echo $colors[$i % 3]; ?> border-2 border-white text-xs text-[#0f2b44] flex items-center justify-center font-extrabold shadow-sm">
                                <?php echo getInitials($reg['fullname']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-left"><span class="font-bold text-white"><?php echo number_format($registrantCount); ?></span> total attendees for this webinar</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ========== SECTION 4.2.5: INSTITUTIONAL TRUST BAR ========== -->
    <div class="max-w-[1400px] mx-auto px-4 py-5 sm:px-6 relative z-30 -mt-12 sm:-mt-20">
        <div class="bg-white/95 backdrop-blur-xl rounded-[2.5rem] sm:rounded-[4rem] shadow-[0_30px_70px_rgba(15,43,68,0.12)] border border-white/40 p-6 sm:p-14 overflow-hidden relative group">
            <!-- Dynamic decorative background -->
            <div class="absolute top-0 right-0 w-64 h-64 bg-blue-400/10 rounded-full blur-[100px] -mr-32 -mt-32 opacity-0 group-hover:opacity-100 transition-opacity duration-1000"></div>
            <div class="absolute bottom-0 left-0 w-64 h-64 bg-amber-400/10 rounded-full blur-[100px] -ml-32 -mb-32 opacity-0 group-hover:opacity-100 transition-opacity duration-1000"></div>
            
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-6 sm:gap-16 relative z-10">
                <!-- Stat 1: Retention -->
                <div class="flex flex-col items-center lg:items-start text-center lg:text-left group/item cursor-default">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-11 h-11 rounded-2xl bg-blue-600/10 flex items-center justify-center text-blue-600 shadow-sm ring-1 ring-blue-600/20 transition-all duration-500 group-hover/item:bg-blue-600 group-hover/item:text-white group-hover/item:rotate-[10deg] group-hover/item:scale-110">
                            <i class="fas fa-chart-line text-base"></i>
                        </div>
                        <span class="text-[11px] font-black text-slate-400 uppercase tracking-[0.2em]">Stability</span>
                    </div>
                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl sm:text-5xl font-black text-slate-950 tracking-tighter leading-none">98%</span>
                        <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                    </div>
                    <p class="text-[13px] font-bold text-slate-500 mt-3 leading-tight tracking-tight">Investor Retention Rate</p>
                </div>

                <!-- Stat 2: Coverage -->
                <div class="flex flex-col items-center lg:items-start text-center lg:text-left group/item cursor-default">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-11 h-11 rounded-2xl bg-amber-600/10 flex items-center justify-center text-amber-600 shadow-sm ring-1 ring-amber-600/20 transition-all duration-500 group-hover/item:bg-amber-600 group-hover/item:text-white group-hover/item:rotate-[-10deg] group-hover/item:scale-110">
                            <i class="fas fa-map-marked-alt text-base"></i>
                        </div>
                        <span class="text-[11px] font-black text-slate-400 uppercase tracking-[0.2em]">Reach</span>
                    </div>
                    <div class="text-4xl sm:text-5xl font-black text-slate-950 tracking-tighter leading-none">36</div>
                    <p class="text-[13px] font-bold text-slate-500 mt-3 leading-tight tracking-tight">US States Covered</p>
                </div>

                <!-- Stat 3: Fees -->
                <div class="flex flex-col items-center lg:items-start text-center lg:text-left group/item cursor-default">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-11 h-11 rounded-2xl bg-emerald-600/10 flex items-center justify-center text-emerald-600 shadow-sm ring-1 ring-emerald-600/20 transition-all duration-500 group-hover/item:bg-emerald-600 group-hover/item:text-white group-hover/item:rotate-[10deg] group-hover/item:scale-110">
                            <i class="fas fa-hand-holding-usd text-base"></i>
                        </div>
                        <span class="text-[11px] font-black text-slate-400 uppercase tracking-[0.2em]">Efficiency</span>
                    </div>
                    <div class="text-4xl sm:text-5xl font-black text-slate-950 tracking-tighter leading-none">0%</div>
                    <p class="text-[13px] font-bold text-slate-500 mt-3 leading-tight tracking-tight">Management Fees</p>
                </div>

                <!-- Stat 4: Security -->
                <div class="flex flex-col items-center lg:items-start text-center lg:text-left group/item cursor-default">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-11 h-11 rounded-2xl bg-purple-600/10 flex items-center justify-center text-purple-600 shadow-sm ring-1 ring-purple-600/20 transition-all duration-500 group-hover/item:bg-purple-600 group-hover/item:text-white group-hover/item:rotate-[-10deg] group-hover/item:scale-110">
                            <i class="fas fa-shield-alt text-base"></i>
                        </div>
                        <span class="text-[11px] font-black text-slate-400 uppercase tracking-[0.2em]">Security</span>
                    </div>
                    <div class="text-4xl sm:text-5xl font-black text-slate-950 tracking-tighter leading-none">100%</div>
                    <p class="text-[13px] font-bold text-slate-500 mt-3 leading-tight tracking-tight">Asset-Backed Security</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Case Examples Modal: Premium Senior-Level -->
    <div id="caseExamplesModal" class="fixed inset-0 z-[100] hidden bg-slate-950/80 backdrop-blur-2xl flex items-center justify-center p-2 md:p-12 opacity-0 transition-all duration-500 overflow-hidden overflow-x-hidden" role="dialog" aria-modal="true" aria-labelledby="caseExamplesTitle">
        <!-- Close Overlay -->
        <div class="absolute inset-0 cursor-pointer" onclick="closeCaseExamplesModal()"></div>

        <div class="w-full min-w-0 max-w-[calc(100%-1rem)] sm:max-w-xl md:max-w-6xl bg-white/10 backdrop-blur-md rounded-[1.5rem] sm:rounded-[2.5rem] border border-white/10 shadow-[0_50px_100px_-20px_rgba(0,0,0,0.5)] relative flex flex-col max-h-[90vh] overflow-hidden transform scale-95 transition-all duration-500 z-10" id="caseExamplesContainer">
            <!-- Modal Header -->
            <div class="px-6 py-4 md:px-12 md:py-8 border-b border-white/5 flex items-center justify-between bg-white/5 shrink-0">
                <div>
                    <h3 id="caseExamplesTitle" class="text-xl md:text-3xl font-black text-amber-400 tracking-tight uppercase leading-tight">Real Performance</h3>
                </div>
                <button onclick="closeCaseExamplesModal()" class="w-10 h-10 md:w-12 md:h-12 rounded-xl md:rounded-2xl bg-white/5 hover:bg-white/10 text-white flex items-center justify-center transition-all hover:rotate-90 group border border-white/10 active:scale-95 shrink-0" aria-label="Close modal">
                    <i class="fas fa-times text-lg md:text-xl group-hover:text-amber-400 transition-colors"></i>
                </button>
            </div>

            <!-- Content Area -->
            <div class="relative w-full overflow-y-auto bg-slate-900/50 p-2 md:p-6 custom-scrollbar flex-grow">
                <div class="relative rounded-2xl md:rounded-3xl overflow-hidden shadow-inner group flex items-center justify-center bg-black/20 min-h-[300px] md:min-h-[60vh]">
                    <div id="caseExamplesImagesGrid" class="flex flex-col md:flex-row justify-center items-center gap-4 w-full h-full p-2">
                        <div class="w-full flex justify-center overflow-x-auto custom-scrollbar">
                            <img id="caseExamplesImageA" src="" alt="Case example" class="max-w-full h-auto object-contain rounded-xl md:rounded-2xl transition-all duration-500 shadow-2xl">
                        </div>
                        <div class="w-full flex justify-center hidden overflow-x-auto custom-scrollbar">
                            <img id="caseExamplesImageB" src="" alt="Case example" class="max-w-full h-auto object-contain rounded-xl md:rounded-2xl transition-all duration-500 shadow-2xl">
                        </div>
                    </div>
                    
                    <!-- Navigation Controls -->
                    <button type="button" onclick="caseExamplesPrev()" class="absolute left-6 top-1/2 -translate-y-1/2 w-14 h-14 rounded-2xl bg-amber-400 hover:bg-amber-300 text-[#0f2b44] border border-amber-200 backdrop-blur-md transition-all flex items-center justify-center group/nav z-30 shadow-xl shadow-amber-500/30">
                        <i class="fas fa-chevron-left group-hover/nav:-translate-x-1 transition-transform"></i>
                    </button>
                    <button type="button" onclick="caseExamplesNext()" class="absolute right-6 top-1/2 -translate-y-1/2 w-14 h-14 rounded-2xl bg-amber-400 hover:bg-amber-300 text-[#0f2b44] border border-amber-200 backdrop-blur-md transition-all flex items-center justify-center group/nav z-30 shadow-xl shadow-amber-500/30">
                        <i class="fas fa-chevron-right group-hover/nav:translate-x-1 transition-transform"></i>
                    </button>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-8 py-4 md:px-12 md:py-6 bg-white/5 border-t border-white/5 flex items-center justify-center">
                <div class="px-6 py-2 rounded-full bg-white/5 border border-white/10 text-white/70 text-[10px] md:text-xs font-black uppercase tracking-widest" id="caseExamplesCounter">
                    Loading...
                </div>
            </div>
        </div>
    </div>

    <!-- Contractor Modal: Premium Senior-Level -->
    <div id="contractorPdfModal" class="fixed inset-0 z-[100] hidden bg-slate-950/80 backdrop-blur-2xl flex items-center justify-center p-3 md:p-12 opacity-0 transition-all duration-500 overflow-hidden overflow-x-hidden" role="dialog" aria-modal="true" aria-labelledby="contractorTitle">
        <!-- Close Overlay -->
        <div class="absolute inset-0 cursor-pointer" onclick="closeContractorImageModal()"></div>

        <div class="w-full min-w-0 max-w-[calc(100%-1rem)] sm:max-w-xl md:max-w-5xl bg-white/10 backdrop-blur-md rounded-[1.5rem] sm:rounded-[2.5rem] border border-white/10 shadow-[0_50px_100px_-20px_rgba(0,0,0,0.5)] relative flex flex-col max-h-[95vh] md:max-h-[90vh] overflow-hidden transform scale-95 transition-all duration-500 z-10" id="contractorPdfContainer">
            <!-- Modal Header -->
            <div class="px-4 py-3 md:px-12 md:py-8 border-b border-white/5 flex items-center justify-between bg-white/5 shrink-0">
                <div>
                    <h3 id="contractorTitle" class="text-lg md:text-3xl font-black text-white tracking-tight uppercase leading-tight">Contractor Management</h3>
                    <p class="text-slate-400 text-[10px] md:text-sm font-medium mt-1 uppercase tracking-[0.2em]">Credentialing & Verification</p>
                </div>
                
                <button onclick="closeContractorImageModal()" class="w-10 h-10 md:w-12 md:h-12 rounded-xl md:rounded-2xl bg-white/5 hover:bg-white/10 text-white flex items-center justify-center transition-all hover:rotate-90 group border border-white/10 active:scale-95 shrink-0" aria-label="Close modal">
                    <i class="fas fa-times text-base md:text-xl group-hover:text-amber-400 transition-colors"></i>
                </button>
            </div>

            <!-- Content Area -->
            <div class="relative w-full overflow-y-auto bg-slate-900/50 p-3 md:p-6 custom-scrollbar flex-grow min-h-[300px] md:min-h-[60vh] flex items-center justify-center">
                <!-- Scope of Work Tab Content -->
                <div id="scopeOfWorkContent" class="absolute inset-0 flex items-center justify-center p-2 overflow-auto">
                    <img id="contractorDisplayImage" src="" alt="Project Management Document" class="max-w-full max-h-full object-contain transition-transform duration-100 ease-out">
                </div>

                <!-- License Tab Content -->
                <div id="licenseContent" class="absolute inset-0 hidden flex items-center justify-center p-2 overflow-auto">
                    <img id="licenseDisplayImage" src="" alt="License Document" class="max-w-full max-h-full object-contain transition-transform duration-100 ease-out">
                </div>
                
                <!-- Navigation Controls -->
                <button type="button" id="contractorPrevBtn" onclick="contractorPrevImage()" class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all z-10">
                    <i class="fas fa-chevron-left text-sm sm:text-base"></i>
                </button>
                <button type="button" id="contractorNextBtn" onclick="contractorNextImage()" class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all z-10">
                    <i class="fas fa-chevron-right text-sm sm:text-base"></i>
                </button>

                <!-- Zoom and Counter Controls (Desktop) -->
                <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-3 bg-white/90 backdrop-blur-md p-2 rounded-full border border-white/20 shadow-lg z-20 hidden sm:flex">
                    <button type="button" id="zoomOutBtn" onclick="zoomOutContractorImage()" class="w-10 h-10 rounded-full text-sm font-semibold bg-slate-100 text-slate-900 hover:bg-slate-200 transition flex items-center justify-center">
                        <i class="fas fa-search-minus"></i>
                    </button>
                    <span id="contractorImageCounter" class="text-sm font-bold text-slate-900 px-2">1 / 11</span>
                    <button type="button" id="zoomInBtn" onclick="zoomInContractorImage()" class="w-10 h-10 rounded-full text-sm font-semibold bg-slate-100 text-slate-900 hover:bg-slate-200 transition flex items-center justify-center">
                        <i class="fas fa-search-plus"></i>
                    </button>
                </div>
            </div>
            <!-- Modal Footer with Tabs -->
            <div class="px-4 py-3 md:px-12 md:py-6 bg-white/5 border-t border-white/5 flex flex-col sm:flex-row items-center justify-center gap-3">
                <div class="flex items-center rounded-full bg-white/5 border border-white/10 p-1">
                    <button type="button" id="scopeOfWorkTab" onclick="switchContractorTab('scopeOfWork')" class="px-3 py-1.5 sm:px-4 sm:py-2 rounded-full text-xs sm:text-sm font-semibold text-white bg-white/10">Scope of Work</button>
                    <button type="button" id="licenseTab" onclick="switchContractorTab('license')" class="px-3 py-1.5 sm:px-4 sm:py-2 rounded-full text-xs sm:text-sm font-semibold text-white/50 hover:bg-white/5">License</button>
                </div>
                <!-- Zoom and Counter Controls (Mobile) -->
                <div class="flex items-center gap-3 bg-white/90 backdrop-blur-md p-2 rounded-full border border-white/20 shadow-lg z-20 sm:hidden">
                    <button type="button" id="zoomOutBtn" onclick="zoomOutContractorImage()" class="w-8 h-8 rounded-full text-xs font-semibold bg-slate-100 text-slate-900 hover:bg-slate-200 transition flex items-center justify-center">
                        <i class="fas fa-search-minus"></i>
                    </button>
                    <span id="contractorImageCounter" class="text-xs font-bold text-slate-900 px-1">1 / 11</span>
                    <button type="button" id="zoomInBtn" onclick="zoomInContractorImage()" class="w-8 h-8 rounded-full text-xs font-semibold bg-slate-100 text-slate-900 hover:bg-slate-200 transition flex items-center justify-center">
                        <i class="fas fa-search-plus"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- States We Buy In: Premium Senior-Level Modal -->
    <div id="statesMapModal" class="fixed inset-0 z-[100] hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-2 md:p-12 opacity-0 transition-all duration-500 overflow-hidden overflow-x-hidden" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <!-- Close Overlay -->
        <div class="absolute inset-0 cursor-pointer" onclick="closeStatesMapModal()"></div>

        <div class="w-full min-w-0 max-w-[calc(100%-1rem)] sm:max-w-md md:max-w-4xl bg-white/10 backdrop-blur-md rounded-[1.5rem] sm:rounded-[2.5rem] border border-white/10 shadow-[0_50px_100px_-20px_rgba(0,0,0,0.5)] relative flex flex-col max-h-[90vh] overflow-hidden transform scale-95 transition-all duration-500 z-10" id="statesMapContainer">
            <!-- Modal Header -->
            <div class="px-6 py-4 md:px-12 md:py-8 border-b border-white/5 flex items-center justify-between bg-white/5 shrink-0">
                <div>
                    <h3 id="modalTitle" class="text-xl md:text-3xl font-black text-white tracking-tight uppercase leading-tight">States We Buy In</h3>
                </div>
                <button onclick="closeStatesMapModal()" class="w-10 h-10 md:w-12 md:h-12 rounded-xl md:rounded-2xl bg-white/5 hover:bg-white/10 text-white flex items-center justify-center transition-all hover:rotate-90 group border border-white/10 active:scale-95 shrink-0" aria-label="Close modal">
                    <i class="fas fa-times text-lg md:text-xl group-hover:text-amber-400 transition-colors"></i>
                </button>
            </div>

            <!-- Content Area -->
            <div class="relative w-full overflow-y-auto bg-slate-900/50 p-2 md:p-12 custom-scrollbar flex-grow flex items-center justify-center">
                <div class="relative rounded-2xl md:rounded-3xl overflow-hidden shadow-inner group w-full h-full flex items-center justify-center">
                    <!-- Main Map Image -->
                    <img id="portfolioMapImg" 
                         src="img/portfolio-map.png" 
                         alt="Geographic visualization of strategic investment states across the US" 
                         class="max-w-full max-h-full object-contain relative z-10 opacity-0 transition-opacity duration-700"
                         onload="this.classList.remove('opacity-0');">
                </div>
            </div>
        </div>
    </div>

    <!-- Actionable Q&A Modal: Premium Senior-Level -->
    <div id="qaSessionModal" class="fixed inset-0 z-[100] hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4 md:p-12 opacity-0 transition-all duration-500 overflow-hidden overflow-x-hidden" role="dialog" aria-modal="true" aria-labelledby="qaTitle">
        <!-- Close Overlay -->
        <div class="absolute inset-0 cursor-pointer" onclick="closeQaSessionModal()"></div>

        <div class="w-full min-w-0 max-w-[calc(100%-1rem)] sm:max-w-xl md:max-w-4xl bg-white/10 backdrop-blur-md rounded-[1.5rem] sm:rounded-[2.5rem] border border-white/10 shadow-[0_50px_100px_-20px_rgba(0,0,0,0.5)] relative flex flex-col max-h-[90vh] overflow-hidden transform scale-95 transition-all duration-500 z-10" id="qaSessionContainer">
            <!-- Modal Header -->
            <div class="px-6 py-4 md:px-12 md:py-8 border-b border-white/5 flex items-center justify-between bg-white/5 shrink-0">
                <div>
                    <h3 id="qaTitle" class="text-[30px] font-black text-white tracking-tight uppercase leading-tight">Actionable Q&A Session</h3>
                    <p class="text-slate-400 text-[30px] font-medium mt-1 uppercase tracking-[0.2em]">Institutional FAQ & Clarity</p>
                </div>
                <button onclick="closeQaSessionModal()" class="w-10 h-10 md:w-12 md:h-12 rounded-xl md:rounded-2xl bg-white/5 hover:bg-white/10 text-white flex items-center justify-center transition-all hover:rotate-90 group border border-white/10 active:scale-95 shrink-0" aria-label="Close modal">
                    <i class="fas fa-times text-lg md:text-xl group-hover:text-amber-400 transition-colors"></i>
                </button>
            </div>

            <!-- Content Area -->
            <div class="relative w-full overflow-y-auto bg-slate-900/50 p-4 md:p-12 custom-scrollbar flex-grow">
                <div class="space-y-12 max-w-3xl mx-auto">
                    <!-- MIOYM FAQ’s -->
                    <div class="space-y-12">
                        <!-- Logo & Branding Section -->
                        <div class="flex flex-col items-center justify-center space-y-4 mb-12">
                            <img src="img/logo.png" alt="MIOYM Logo" class="w-32 md:w-40 h-auto filter brightness-110 drop-shadow-[0_0_15px_rgba(251,191,36,0.2)]">
                            <span class="text-amber-400 text-[30px] font-black uppercase tracking-[0.4em] drop-shadow-sm">MIOYM Group</span>
                        </div>

                        <div class="flex items-center gap-3 opacity-50">
                            <div class="h-px flex-1 bg-white/20"></div>
                            <span class="text-[30px] font-black uppercase tracking-[0.3em] text-white">FAQ’s</span>
                            <div class="h-px flex-1 bg-white/20"></div>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">How long have you been in business?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">Our founder Marc Cox has been flipping homes since 1999<br>MIOYM has been incorporated since 2008</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">How many states do you purchase homes in?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">36 across the continental United States</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">How many investors are in each property?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">There is only one investor associated with each individual property. But investors can invest in multiple properties.</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">Do I get to pick the property I invest in?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">You will receive a “Deal Breakdown” of every property you invest in which is a financial analysis of every property we enter into prior to closing.</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">How long is my money tied up for?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">Generally, we look to have your investment liquidated at 12 months, however if we sell it prior to 12 months then you will get your principle plus targeted 15% per annum returned to you.</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">What is the taxable event?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">We have two programs, our LLC program and Promissory Note program.</p>
                            <ul class="list-none pl-0 space-y-2 text-slate-300 text-[30px] font-medium">
                                <li>• Our LLC program produces a K-1</li>
                                <li>• Our Promissory Note program produces a 1099</li>
                            </ul>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">Is my the 15% guaranteed?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">While no investment is ever completely without risk, your investment is secured by the asset itself, as you are listed as an owner on the property. Additionally, investors receive their principal investment back before any profits are distributed to the company. Since 2008, we have maintained a 100% success rate in returning investors' capital along with their expected ROI.</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">Why is your exit strategy different?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">Our exit strategy stands out because we take a multi-faceted approach to selling our properties, increasing the chances of a successful and timely exit. While we do work with local realtors to list each property on the market, we also heavily market the property ourselves through our internal network and investor channels, ensuring maximum exposure.</p>
                        </div>

                        <!-- Rent-to-Own Program Card -->
                        <div class="space-y-6 p-8 rounded-3xl bg-white/5 border border-white/10 relative overflow-hidden group/rto">
                            <div class="absolute top-0 right-0 p-8 opacity-10 group-hover/rto:opacity-20 transition-opacity">
                                <i class="fas fa-home-heart text-6xl text-amber-400"></i>
                            </div>
                            
                            <div class="relative z-10">
                                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[30px] font-black uppercase tracking-widest mb-4">
                                    <i class="fas fa-star text-[8px]"></i> Special Program
                                </div>
                                <p class="text-white text-[30px] leading-tight font-black mb-4">
                                    In addition to traditional sales, we leverage our Affordable Home Program to help individuals and families with less-than-stellar credit transition into homeownership. Through this program:
                                </p>
                                
                                <div class="space-y-4 mt-8">
                                    <div class="flex items-start gap-4">
                                        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center shrink-0 border border-amber-500/30">
                                            <span class="text-amber-400 text-[30px] font-black">01</span>
                                        </div>
                                        <p class="text-slate-300 text-[30px] font-medium leading-relaxed">We place qualified tenants into the property with a structured affordable home program</p>
                                    </div>
                                    <div class="flex items-start gap-4">
                                        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center shrink-0 border border-amber-500/30">
                                            <span class="text-amber-400 text-[30px] font-black">02</span>
                                        </div>
                                        <p class="text-slate-300 text-[30px] font-medium leading-relaxed">Once they are mortgage-ready, we refer them to an FHA lender to secure a first-time homebuyer mortgage.</p>
                                    </div>
                                    <div class="flex items-start gap-4">
                                        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center shrink-0 border border-amber-500/30">
                                            <span class="text-amber-400 text-[30px] font-black">03</span>
                                        </div>
                                        <p class="text-slate-300 text-[30px] font-medium leading-relaxed">As a firm we pay up to 6% toward closing cost as allowed by FHA and apply for first time down payment assistance from applicable state</p>
                                    </div>
                                </div>

                                <div class="mt-8 pt-8 border-t border-white/5">
                                    <p class="text-slate-400 text-[30px] leading-relaxed font-medium italic">
                                        By utilizing multiple strategies simultaneously, we increase our ability to exit properties efficiently—whether through a standard sale or by creating new homebuyers through our Affordable Home Program. This approach not only enhances our success rate but also provides opportunities for families who may not otherwise qualify for homeownership, making it a win-win for both investors and buyers.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MIOYM FAQ’s (cont’d) -->
                    <div class="space-y-12 pt-12 border-t border-white/5">
                        <div class="flex items-center gap-3 opacity-50">
                            <div class="h-px flex-1 bg-white/20"></div>
                            <div class="h-px flex-1 bg-white/20"></div>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">What happens if you can’t sell the property I am invested in?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">All properties are underwritten for income as well as appreciation. Our goal is always to sell at the highest and best possible price. Due to our experience and having navigated market highs and lows, we underwrite every property with a worst-case scenario mindset—assuming that it may not sell. This ensures we are already prepared for this event when conducting our analysis prior to purchasing. As such, if we need to rent out the property instead of selling it, we are fully prepared, having already factored in rental income and all related expenses.</p>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium mt-4">Generally, we are out of most properties within 12 months; however, real estate is unpredictable, and we cannot guarantee that all properties will be sold exactly at the 12-month mark. That being said, some properties may be sold sooner than anticipated, while others may be sold later. Regardless, our focus remains on maximizing returns and minimizing risks for our investors.</p>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-[30px] font-bold text-amber-400 leading-snug">Currently I am not liquid but I do have IRA money, can I use that?</h4>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium">Yes, we have relationships with a couple Self-Directed IRA companies (The Entrust Group & Equity Trust) and are listed on their platform. We can direct you to these companies and help you get set up.</p>
                            <p class="text-slate-300 text-[30px] leading-relaxed font-medium mt-4">If you currently have assets at a Self-Directed IRA company outside of the ones we are associated with we will work with you to get the necessary paperwork to them to get the investment “approved” with their compliance and due diligence teams.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Liquidation Strategies Modal: Premium Native HTML -->
    <div id="liquidationModal" class="fixed inset-0 z-[100] hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-2 md:p-12 opacity-0 transition-all duration-500 overflow-hidden overflow-x-hidden" role="dialog" aria-modal="true" aria-labelledby="liquidationTitle">
        <!-- Close Overlay -->
        <div class="absolute inset-0 cursor-pointer" onclick="closeLiquidationModal()"></div>

        <div class="w-full min-w-0 max-w-[calc(100%-1rem)] sm:max-w-xl md:max-w-7xl lg:max-w-[85vw] bg-white/10 backdrop-blur-md rounded-[1.5rem] sm:rounded-[2.5rem] border border-white/10 shadow-[0_50px_100px_-20px_rgba(0,0,0,0.5)] relative flex flex-col max-h-[90vh] overflow-hidden transform scale-95 transition-all duration-500 z-10" id="liquidationContainer">
            <!-- Modal Header -->
            <div class="px-6 py-4 md:px-12 md:py-8 border-b border-white/5 flex items-center justify-between bg-white/5 shrink-0">
                <div>
                    <h3 id="liquidationTitle" class="text-xl md:text-3xl font-black text-white tracking-tight uppercase leading-tight">Liquidation Strategies</h3>
                </div>
                <button onclick="closeLiquidationModal()" class="w-10 h-10 md:w-12 md:h-12 rounded-xl md:rounded-2xl bg-white/5 hover:bg-white/10 text-white flex items-center justify-center transition-all hover:rotate-90 group border border-white/10 active:scale-95 shrink-0" aria-label="Close modal">
                    <i class="fas fa-times text-lg md:text-xl group-hover:text-amber-400 transition-colors"></i>
                </button>
            </div>

            <!-- Content Area -->
            <div class="relative w-full overflow-y-auto bg-slate-900/50 p-4 md:p-12 custom-scrollbar flex-grow">
                <div class="relative max-w-6xl lg:max-w-7xl mx-auto">
                    <div class="liquidation-slides-container">
                        <!-- Slide 1 -->
                        <div class="liquidation-slide flex flex-row items-center justify-center gap-4">
                            <img src="img/1.png" alt="Slide 1 Image 1" class="w-1/2 h-auto rounded-lg shadow-lg object-contain">
                            <img src="img/2.png" alt="Slide 1 Image 2" class="w-1/2 h-auto rounded-lg shadow-lg object-contain">
                        </div>
                        <!-- Slide 2 -->
                        <div class="liquidation-slide hidden flex flex-row items-center justify-center gap-4">
                            <img src="img/3.png" alt="Slide 2 Image 1" class="w-1/2 h-auto rounded-lg shadow-lg object-contain">
                            <img src="img/4.png" alt="Slide 2 Image 2" class="w-1/2 h-auto rounded-lg shadow-lg object-contain">
                        </div>
                        <!-- Slide 3 -->
                        <div class="liquidation-slide hidden flex flex-row items-center justify-center gap-4">
                            <img src="img/5.png" alt="Slide 3 Image 1" class="w-1/2 h-auto rounded-lg shadow-lg object-contain">
                            <img src="img/6.png" alt="Slide 3 Image 2" class="w-1/2 h-auto rounded-lg shadow-lg object-contain">
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <button id="prevLiquidationSlide" class="absolute top-1/2 -left-12 -translate-y-1/2 bg-white/10 hover:bg-white/20 text-white p-3 rounded-full transition-colors">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button id="nextLiquidationSlide" class="absolute top-1/2 -right-12 -translate-y-1/2 bg-white/10 hover:bg-white/20 text-white p-3 rounded-full transition-colors">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

        <!-- ========== SECTION 4.3: BENEFITS SECTION ========== -->
    <main class="max-w-[1400px] mx-auto px-4 sm:px-6 pb-10 md:pb-20">
        <div class="flex flex-col sm:flex-row items-center justify-center gap-6 md:gap-10 mt-16 mb-20 px-4">
            <div class="w-24 h-24 md:w-32 md:h-32 rounded-3xl bg-slate-200 overflow-hidden flex items-center justify-center text-5xl text-white shadow-xl border-4 border-white shrink-0">
                <?php if($hostPic): ?>
                    <img src="<?php echo htmlspecialchars($hostPic); ?>" alt="Host" class="w-full h-full object-cover">
                <?php else: ?>
                    👩‍💼
                <?php endif; ?>
            </div>
            <div class="text-center sm:text-left">
                <span class="text-amber-600 font-bold text-xs uppercase tracking-[0.2em]">Your Session Host</span>
                <h3 class="text-2xl md:text-3xl font-black text-slate-900 mt-1"><?php echo htmlspecialchars($hostName); ?></h3>
                <p class="text-slate-600 mt-3 max-w-2xl text-base md:text-lg leading-relaxed"><?php echo htmlspecialchars($latestWebinar['host_description'] ?? 'President of Mioym Equities'); ?></p>
            </div>
        </div>
        <div class="my-24 text-center">
            <span class="text-[#1e4a7a] font-bold text-xs tracking-[0.2em] uppercase bg-blue-50 border border-blue-100 px-4 py-2 rounded-full">why attend</span>
            <h2 class="text-3xl md:text-5xl font-black text-slate-900 mt-8 mb-16 max-w-3xl mx-auto tracking-tight">In one session you'll discover:</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8 text-left">
                <div role="button" tabindex="0" onclick="openDealFinderModal()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openDealFinderModal();}" class="group relative block bg-white border border-slate-100 rounded-[2rem] p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-2xl hover:border-blue-100 flex flex-col h-full min-h-[320px] cursor-pointer">
                    <div class="w-14 h-14 bg-blue-50 rounded-2xl flex items-center justify-center text-3xl mb-6 group-hover:scale-110 transition-transform">🔑</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4 tracking-tight leading-snug">How We Access Opportunistic Undervalue Assets</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8 flex-grow">Housing shortage. Limited affordable inventory.</p>
                    <span class="inline-flex items-center gap-2 text-xs font-bold text-blue-600 bg-blue-50 px-4 py-2 rounded-full w-fit group-hover:bg-blue-600 group-hover:text-white transition-colors mt-auto">
                        Click to View <i class="fas fa-arrow-right text-[10px]"></i>
                    </span>
                </div>

                <div role="button" tabindex="0" onclick="openCaseExamplesModal()" class="group relative bg-white border border-slate-100 rounded-[2rem] p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-2xl hover:border-amber-100 flex flex-col h-full min-h-[320px] cursor-pointer">
                    <div class="w-14 h-14 bg-amber-50 rounded-2xl flex items-center justify-center text-3xl mb-6 group-hover:scale-110 transition-transform">📊</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4 tracking-tight leading-snug">Case Study of Returns</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8 flex-grow">Risk Management. Conservative ARV assumptions. Multiple exits resale, rental, wholesale</p>
                    <span class="inline-flex items-center gap-2 text-xs font-bold text-amber-600 bg-amber-50 px-4 py-2 rounded-full w-fit group-hover:bg-amber-600 group-hover:text-white transition-colors mt-auto">
                        Click to view <i class="fas fa-arrow-right text-[10px]"></i>
                    </span>
                </div>

                <div role="button" tabindex="0" onclick="openLiquidationModal()" class="group relative bg-white border border-slate-100 rounded-[2rem] p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-2xl hover:border-green-100 flex flex-col h-full min-h-[320px] cursor-pointer">
                    <div class="w-14 h-14 bg-green-50 rounded-2xl flex items-center justify-center text-3xl mb-6 group-hover:scale-110 transition-transform">⚖️</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4 tracking-tight leading-snug">First Time Home Buyer</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8 flex-grow">Step-by-step transition from renting to owning with institutional support and covered costs.</p>
                    <span class="inline-flex items-center gap-2 text-xs font-bold text-green-600 bg-green-50 px-4 py-2 rounded-full w-fit group-hover:bg-green-600 group-hover:text-white transition-colors mt-auto">
                        Click to view <i class="fas fa-arrow-right text-[10px]"></i>
                    </span>
                </div>

                <div role="button" tabindex="0" onclick="openContractorImageModal()" class="group relative bg-white border border-slate-100 rounded-[2rem] p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-2xl hover:border-purple-100 flex flex-col h-full min-h-[320px] cursor-pointer">
                    <div class="w-14 h-14 bg-purple-50 rounded-2xl flex items-center justify-center text-3xl mb-6 group-hover:scale-110 transition-transform">🤝</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4 tracking-tight leading-snug">Contractor Management</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8 flex-grow">How to control boots on the ground and scopes of works</p>
                    <span class="inline-flex items-center gap-2 text-xs font-bold text-purple-600 bg-purple-50 px-4 py-2 rounded-full w-fit group-hover:bg-purple-600 group-hover:text-white transition-colors mt-auto">
                        Click to view <i class="fas fa-arrow-right text-[10px]"></i>
                    </span>
                </div>

                <div role="button" tabindex="0" onclick="openStatesMapModal()" class="group relative bg-white border border-slate-100 rounded-[2rem] p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-2xl hover:border-blue-100 flex flex-col h-full min-h-[320px] cursor-pointer">
                    <div class="w-14 h-14 bg-blue-50 rounded-2xl flex items-center justify-center text-3xl mb-6 group-hover:scale-110 transition-transform">📈</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4 tracking-tight leading-snug">Portfolio Diversification</h3>
                    <span class="inline-flex items-center gap-2 text-xs font-bold text-blue-600 bg-blue-50 px-4 py-2 rounded-full w-fit group-hover:bg-blue-600 group-hover:text-white transition-colors mt-auto">
                        Click to view  <i class="fas fa-arrow-right text-[10px]"></i>
                    </span>
                </div>

                <div role="button" tabindex="0" onclick="openQaSessionModal()" class="group relative bg-white border border-slate-100 rounded-[2rem] p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-2xl hover:border-amber-100 flex flex-col h-full min-h-[320px] cursor-pointer">
                    <div class="w-14 h-14 bg-amber-50 rounded-2xl flex items-center justify-center text-3xl mb-6 group-hover:scale-110 transition-transform">🎯</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4 tracking-tight leading-snug">Actionable Q&A Session</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8 flex-grow">Live Q&A with our investment team - bring your specific question.</p>
                    <span class="inline-flex items-center gap-2 text-xs font-bold text-amber-600 bg-amber-50 px-4 py-2 rounded-full w-fit group-hover:bg-amber-600 group-hover:text-white transition-colors mt-auto">
                        Click to view <i class="fas fa-arrow-right text-[10px]"></i>
                    </span>
                </div>
            </div>
        </div>

        <!-- ========== SECTION 4.3.5: PROPRIETARY TECHNOLOGY ========== -->
        <div class="my-32 relative">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[90%] h-[90%] bg-blue-50/50 rounded-full blur-[100px] -z-10"></div>
            
            <div class="text-center mb-20 px-4">
                <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-[#0f2b44] text-white text-[10px] font-bold uppercase tracking-[0.2em] mb-8">
                    <i class="fas fa-microchip text-amber-400"></i> proprietary technology
                </span>
                <h2 class="text-3xl sm:text-4xl md:text-5xl font-black text-slate-900 tracking-tight mb-6">Automated Acquisition</h2>
                <p class="text-base sm:text-lg text-slate-500 max-w-2xl mx-auto leading-relaxed">We've automated the hardest part of real estate: finding undervalued assets before the competition.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 lg:gap-16 relative">
                <!-- Connector Line (Desktop) -->
                <div class="hidden md:block absolute top-12 left-[15%] right-[15%] h-px border-t-2 border-dashed border-slate-200 -z-10"></div>

                <!-- Step 1 -->
                <div class="flex flex-col items-center text-center group">
                    <div class="w-24 h-24 rounded-[2.5rem] bg-white shadow-xl flex items-center justify-center text-4xl mb-8 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500 ring-1 ring-slate-100 border-4 border-slate-50">
                        🔍
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 mb-4">Market Scanning</h4>
                    <p class="text-sm text-slate-500 leading-relaxed px-4">Our tech scans thousands of properties daily, identifying "off-market" opportunities instantly.</p>
                </div>

                <!-- Step 2 -->
                <div class="flex flex-col items-center text-center group">
                    <div class="w-24 h-24 rounded-[2.5rem] bg-[#0f2b44] shadow-2xl flex items-center justify-center text-4xl mb-8 group-hover:scale-110 transition-all duration-500 text-white border-4 border-blue-900/20">
                        ⚡
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 mb-4">ROI Analysis</h4>
                    <p class="text-sm text-slate-500 leading-relaxed px-4">The system filters for assets meeting our strict <span class="font-bold text-[#1e4a7a]">15%  annual return</span> criteria using live data.</p>
                </div>

                <!-- Step 3 -->
                <div class="flex flex-col items-center text-center group">
                    <div class="w-24 h-24 rounded-[2.5rem] bg-white shadow-xl flex items-center justify-center text-4xl mb-8 group-hover:scale-110 group-hover:-rotate-6 transition-all duration-500 ring-1 ring-slate-100 border-4 border-slate-50">
                        📩
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 mb-4">Automated Offers</h4>
                    <p class="text-sm text-slate-500 leading-relaxed px-4">Instantly sends data-backed offers to owners, securing properties at deep discounts 24/7.</p>
                </div>
            </div>

            <!-- Technology Stats Badge -->
            <div class="mt-24 bg-white border border-slate-100 rounded-[2.5rem] p-6 sm:p-10 max-w-5xl mx-auto shadow-2xl flex flex-wrap items-center justify-center md:justify-around gap-8 md:gap-4 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-600 via-amber-400 to-blue-600"></div>
                <div class="text-center min-w-[140px]">
                    <div class="text-4xl font-black text-[#0f2b44]">10k+</div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-2">Properties / Day</div>
                </div>
                <div class="w-px h-12 bg-slate-100 hidden md:block"></div>
                <div class="text-center min-w-[140px]">
                    <div class="text-4xl font-black text-[#0f2b44]">15%</div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-2">Target Return</div>
                </div>
                <div class="w-px h-12 bg-slate-100 hidden md:block"></div>
                <div class="text-center min-w-[140px]">
                    <div class="text-4xl font-black text-[#0f2b44]">24/7</div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-2">Monitoring</div>
                </div>
            </div>
        </div>
        <div class="my-20 mb-5" id="reviews">
            <div class="text-center mb-12">
                <span class="text-[#1e4a7a] font-bold text-xs tracking-[0.2em] uppercase bg-slate-100 px-4 py-2 rounded-full">reviews</span>
                <h2 class="text-3xl md:text-5xl font-black text-slate-900 mt-8 tracking-tight">What investors say</h2>
            </div>
            <div class="reviews-stage relative">
                <button type="button" data-reviews-prev aria-label="Previous review" class="hidden lg:flex absolute left-0 xl:-left-12 top-1/2 -translate-y-1/2 w-12 h-12 rounded-full bg-white border border-slate-100 shadow-xl items-center justify-center text-slate-700 hover:text-[#0f2b44] hover:scale-110 transition z-30">
                    <i class="fas fa-chevron-left text-lg"></i>
                </button>
                <button type="button" data-reviews-next aria-label="Next review" class="hidden lg:flex absolute right-0 xl:-right-12 top-1/2 -translate-y-1/2 w-12 h-12 rounded-full bg-white border border-slate-100 shadow-xl items-center justify-center text-slate-700 hover:text-[#0f2b44] hover:scale-110 transition z-30">
                    <i class="fas fa-chevron-right text-lg"></i>
                </button>
                <div id="reviewsCarousel" class="reviews-stack max-w-md mx-auto">
                    <?php if (!empty($feedbacks)): ?>
                        <?php foreach ($feedbacks as $idx => $fb): ?>
                            <?php
                                $badgeColors = ['bg-blue-100', 'bg-amber-100', 'bg-slate-100'];
                                $badge = $badgeColors[$idx % 3];
                                $nm = (string)($fb['name'] ?? '');
                                $msg = (string)($fb['message'] ?? '');
                                $rt = (int)($fb['rating'] ?? 0);
                                if ($rt < 1) $rt = 1;
                                if ($rt > 5) $rt = 5;
                            ?>
                            <div class="review-card bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-2xl h-[400px]">
                                <div class="flex flex-col h-full">
                                    <div class="flex items-center justify-between mb-8">
                                        <div class="flex items-center gap-4 min-w-0">
                                            <div class="w-12 h-12 rounded-2xl <?php echo $badge; ?> text-[#0f2b44] flex items-center justify-center font-black text-lg shrink-0">
                                                <?php echo htmlspecialchars(getInitials($nm)); ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-base font-black text-slate-900 truncate"><?php echo htmlspecialchars($nm); ?></div>
                                                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Verified Investor</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 mb-6">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="fas fa-star text-sm <?php echo $s <= $rt ? 'text-amber-400' : 'text-slate-100'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="flex-1 overflow-y-auto custom-scrollbar pr-2">
                                        <p class="text-slate-600 text-base leading-relaxed italic">"<?php echo htmlspecialchars($msg); ?>"</p>
                                    </div>
                                    <div class="mt-8 pt-6 border-t border-slate-50 flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Verified 2026</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="review-card bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-2xl h-[400px]">
                            <div class="flex flex-col items-center justify-center h-full text-center">
                                <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center text-slate-300 text-3xl mb-6">★</div>
                                <h3 class="text-xl font-bold text-slate-900 mb-2">No reviews yet</h3>
                                <p class="text-slate-500 text-sm">Be the first to share your experience with Mioym Equities.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ========== SECTION 4.4: FINAL RISK REVERSAL CTA ========== -->
        <div class="bg-slate-950 text-white rounded-[3rem] md:rounded-[5rem] py-20 md:py-32 px-6 sm:px-12 shadow-2xl mt-32 mb-20 relative overflow-hidden border border-white/5 max-w-[1400px] mx-auto">
            <div class="absolute -top-40 -right-40 w-96 h-96 bg-blue-600/10 rounded-full blur-[120px]"></div>
            <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-amber-500/10 rounded-full blur-[120px]"></div>
            
            <div class="max-w-4xl mx-auto text-center relative z-10">
                <div class="inline-flex items-center gap-2 bg-white/5 border border-white/10 px-4 py-2 rounded-full text-amber-400 text-[10px] font-bold uppercase tracking-[0.2em] mb-12">
                    <i class="fas fa-shield-alt"></i> institutional grade opportunity
                </div>
                
                <h2 class="text-4xl sm:text-5xl md:text-7xl font-black mb-12 tracking-tight leading-[1.1]">
                    The Definitive <br class="hidden sm:block"><span class="text-amber-400">Yes</span> <span class="text-white/40 font-medium">or No.</span>
                </h2>
                
                <div class="max-w-2xl mx-auto">
                    <p class="text-lg md:text-2xl text-slate-300 leading-relaxed font-medium">
                        You are <?php echo htmlspecialchars($webinarDuration); ?> away from seeing a real estate model that removes market volatility and fee burdens. 
                    </p>
                    <div class="mt-8 p-6 md:p-8 bg-white/5 border border-white/10 rounded-3xl backdrop-blur-sm">
                        <p class="text-base md:text-lg italic text-slate-200">
                            "Don't miss this <span class="text-amber-400 font-bold">15% targeted annualized return</span> opportunity. If you're looking for a definitive answer on where to deploy capital in 2026, this is it."
                        </p>
                    </div>
                </div>
                
                <div class="mt-16">
                    <button type="button" onclick="openRegistrationModal()" class="group relative inline-flex items-center justify-center gap-4 bg-amber-500 hover:bg-amber-400 text-[#0f2b44] font-black text-xl px-10 md:px-14 py-5 md:py-6 rounded-2xl shadow-2xl transition-all duration-300 transform hover:-translate-y-2 active:scale-95 overflow-hidden w-full sm:w-auto">
                        <span class="relative z-10 uppercase tracking-tight">Reserve Your Spot</span>
                        <i class="fas fa-arrow-right relative z-10 transition-transform group-hover:translate-x-2"></i>
                        <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/30 to-transparent -translate-x-full group-hover:animate-[shimmer_2s_infinite]"></div>
                    </button>
                    <p class="mt-6 text-[10px] font-bold text-white/30 uppercase tracking-[0.3em]">secure your free spot today</p>
                </div>
                
                <div class="mt-24 grid grid-cols-1 sm:grid-cols-3 gap-8 pt-12 border-t border-white/5">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-amber-400">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">No Volatility</span>
                    </div>
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-amber-400">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Zero Fee Burden</span>
                    </div>
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-amber-400">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Direct Access</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-16 bg-white border border-slate-100 rounded-3xl p-8 md:p-12 shadow-md" id="contact">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <div class="space-y-4">
                    <h2 class="text-3xl font-bold text-slate-900">Contact us</h2>
                    <p class="text-slate-600">Questions about the webinar or co‑investing with us? Reach out and our team will respond within 24h.</p>
                    <div class="space-y-3">
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-envelope text-amber-500"></i><span><?php echo htmlspecialchars(get_setting('support_email', 'Robert@mioymmequities.com')); ?></span></div>
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-phone text-amber-500"></i><span>Office: <?php echo htmlspecialchars(get_setting('office_phone', '914 566 8292')); ?></span></div>
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-map-marker-alt text-amber-500"></i><span><?php echo htmlspecialchars(get_setting('office_address', '2900 Westchester Ave Purchase, NY 10577')); ?></span></div>
                    </div>
                </div>
                <div class="space-y-4">
                    <?php if ($contactFlash !== ''): ?>
                    <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 p-4 rounded-2xl flex items-start gap-3">
                        <i class="fas fa-check-circle text-emerald-500 mt-0.5"></i>
                        <div class="text-sm font-medium"><?php echo htmlspecialchars($contactFlash); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($contactError !== ''): ?>
                    <div class="bg-rose-50 border border-rose-100 text-rose-700 p-4 rounded-2xl flex items-start gap-3">
                        <i class="fas fa-exclamation-circle text-rose-500 mt-0.5"></i>
                        <div class="text-sm font-medium"><?php echo htmlspecialchars($contactError); ?></div>
                    </div>
                    <?php endif; ?>
                    <form action="index.php#contact" method="post" class="space-y-4">
                        <input type="hidden" name="contact_submit" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" name="c_name" placeholder="Your name" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required value="<?php echo htmlspecialchars((string)($_POST['c_name'] ?? '')); ?>">
                        <input type="email" name="c_email" placeholder="Email" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required value="<?php echo htmlspecialchars((string)($_POST['c_email'] ?? '')); ?>">
                    </div>
                    <input type="text" name="c_subject" placeholder="Subject" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" value="<?php echo htmlspecialchars((string)($_POST['c_subject'] ?? '')); ?>">
                    <textarea name="c_message" rows="5" placeholder="Your message" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required><?php echo htmlspecialchars((string)($_POST['c_message'] ?? '')); ?></textarea>
                    <div class="flex justify-center sm:justify-start">
                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($recaptcha_site_key, ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>
                    <button type="submit" class="bg-[#0f2b44] hover:bg-[#1e4a7a] text-white font-bold px-6 py-3 rounded-full shadow-md transition">Send message</button>
                </form>
                </div>
            </div>
        </div>

        <!-- ========== SECTION 4.6: FOOTER ========== -->
        <div class="text-xs text-slate-500 flex flex-col sm:flex-row justify-between items-center mt-12 pt-6 border-t border-slate-100">
            <span>© 2026 Mioym Equities — All rights reserved.</span>
        </div>
    </main>

    <!-- Registration Modal -->
    <div id="registrationModal" class="fixed inset-0 z-[200] hidden overflow-y-auto overflow-x-hidden">
        <div class="flex min-h-screen w-full items-center justify-center p-4 text-center sm:p-0">
            <!-- Overlay -->
            <div id="registrationOverlay" class="fixed inset-0 bg-slate-900/80  transition-opacity opacity-0"></div>

            <!-- Modal Content -->
            <div id="registrationPanel" class="relative mx-auto w-full min-w-0 max-w-[21rem] transform overflow-hidden rounded-[1.5rem] bg-white text-left shadow-2xl transition-all sm:my-8 sm:max-w-[52rem] opacity-0 translate-y-4">
                <div class="grid grid-cols-1 lg:grid-cols-2">
                    <!-- Left Side: Info -->
                    <div id="modal-info-section" class="hidden lg:flex p-10 flex-col justify-between bg-slate-50/50 border-r border-slate-100">
                        <div>
                            <span class="inline-block px-4 py-1.5 rounded-full bg-amber-100 text-amber-700 text-xs font-bold uppercase tracking-widest mb-6">Exclusive Webinar</span>
                            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 leading-tight">Secure Your <span class="text-[#1e4a7a]">Financial Future</span> Today.</h2>
                            <p class="text-slate-600 mt-5 text-base leading-relaxed">Join us for an exclusive session where we reveal our proven <span class="font-bold text-slate-900">15% targeted annual return</span> strategy.</p>
                            
                            <div class="mt-10 space-y-6">
                                <div class="flex items-center gap-4 group">
                                    <div class="w-11 h-11 rounded-2xl bg-white shadow-sm border border-slate-100 flex items-center justify-center text-[#1e4a7a] shrink-0 group-hover:scale-110 transition-transform">
                                        <i class="far fa-clock text-lg"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($scheduleDate); ?></div>
                                        <div class="text-xs text-slate-500 font-medium">Live training + Q&A Session</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 group">
                                    <div class="w-11 h-11 rounded-2xl bg-white shadow-sm border border-slate-100 flex items-center justify-center text-[#1e4a7a] shrink-0 group-hover:scale-110 transition-transform">
                                        <i class="fas fa-video text-base"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-slate-900">Online Streaming</div>
                                        <div class="text-xs text-slate-500 font-medium">Accessible from any device</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-12 pt-8 border-t border-slate-200/60 flex items-center gap-6">
                            <div class="flex -space-x-3">
                                <div class="w-10 h-10 rounded-full border-2 border-white bg-slate-200 flex items-center justify-center text-[10px] font-bold text-slate-500 shadow-sm"><?php echo $avatars[0] ?? 'JM'; ?></div>
                                <div class="w-10 h-10 rounded-full border-2 border-white bg-slate-300 flex items-center justify-center text-[10px] font-bold text-slate-600 shadow-sm"><?php echo $avatars[1] ?? 'AB'; ?></div>
                                <div class="w-10 h-10 rounded-full border-2 border-white bg-slate-400 flex items-center justify-center text-[10px] font-bold text-slate-100 shadow-sm"><?php echo $avatars[2] ?? 'RT'; ?></div>
                            </div>
                            <div class="text-sm font-medium text-slate-500">
                                Join <span class="text-slate-900 font-bold"><?php echo number_format($registrantCount); ?></span> other investors
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: Form -->
                    <div class="bg-[#0f2b44] p-6 sm:p-10 relative overflow-hidden">
                        <button onclick="closeRegistrationModal()" class="absolute top-4 right-4 sm:top-6 sm:right-6 w-9 h-9 rounded-full bg-white/10 text-white flex items-center justify-center hover:bg-white/20 transition-colors z-20">
                            <i class="fas fa-times"></i>
                        </button>

                        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/5 rounded-full blur-3xl"></div>
                        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-amber-500/10 rounded-full blur-3xl"></div>

                        <div class="relative z-10 h-full flex flex-col justify-center">
                            <div class="mb-6 text-center sm:text-left">
                                <h2 class="text-xl sm:text-2xl font-black text-white tracking-tight leading-tight">Reserve Your Seat</h2>
                                <p class="text-white/60 mt-2 text-xs sm:text-sm">We partner with a limited number of invenstors per project. Please complete the short application below to determine fit.</p>
                            </div>

                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="space-y-5 sm:space-y-6">
                                <input type="hidden" name="register_submit" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                                
                                    <div class="space-y-2">
                                        <label class="text-xs sm:text-sm font-bold text-white/90 ml-1 tracking-wide uppercase">Full Name</label>
                                        <div class="relative group">
                                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-white/40 group-focus-within:text-amber-400 transition-colors"><i class="far fa-user"></i></span>
                                            <input type="text" name="fullname" required placeholder="John Doe" value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" class="w-full pl-12 pr-5 py-4 rounded-2xl bg-white/5 border border-white/10 text-white placeholder:text-white/20 focus:outline-none focus:ring-4 focus:ring-amber-400/10 focus:border-amber-400/40 focus:bg-white/10 transition-all text-base">
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-xs sm:text-sm font-bold text-white/90 ml-1 tracking-wide uppercase">Email Address</label>
                                        <div class="relative group">
                                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-white/40 group-focus-within:text-amber-400 transition-colors"><i class="far fa-envelope"></i></span>
                                            <input type="email" name="email" required placeholder="john@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="w-full pl-12 pr-5 py-4 rounded-2xl bg-white/5 border border-white/10 text-white placeholder:text-white/20 focus:outline-none focus:ring-4 focus:ring-amber-400/10 focus:border-amber-400/40 focus:bg-white/10 transition-all text-base">
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-xs sm:text-sm font-bold text-white/90 ml-1 tracking-wide uppercase">Mobile Number</label>
                                        <div class="relative group">
                                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-white/40 group-focus-within:text-amber-400 transition-colors"><i class="fas fa-phone"></i></span>
                                            <input type="text" id="phone" name="phone" required inputmode="numeric" autocomplete="tel" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="+1 (000) 000-0000" maxlength="25" class="w-full pl-12 pr-5 py-4 rounded-2xl bg-white/5 border border-white/10 text-white placeholder:text-white/20 focus:outline-none focus:ring-4 focus:ring-amber-400/10 focus:border-amber-400/40 focus:bg-white/10 transition-all text-base">
                                        </div>
                                        <p id="phone-error" class="hidden text-[10px] font-bold text-rose-400 uppercase tracking-widest ml-1 mt-1">
                                            <i class="fas fa-exclamation-circle mr-1"></i> Invalid phone number
                                        </p>
                                    </div>

                                    <div class="space-y-3">
                                        <label class="text-xs sm:text-sm font-bold text-white/90 ml-1 tracking-wide">Are you liquid for $50,000?</label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-1">
                                            <label class="flex items-center gap-3 text-white/85 text-sm font-semibold">
                                                <input type="radio" name="liquid_50000" value="Yes" <?php echo (isset($_POST['liquid_50000']) && $_POST['liquid_50000'] === 'Yes') ? 'checked' : ''; ?> class="w-4 h-4 border-white/30 bg-white/10 text-amber-400 focus:ring-amber-400/30">
                                                <span>Yes</span>
                                            </label>
                                            <label class="flex items-center gap-3 text-white/85 text-sm font-semibold">
                                                <input type="radio" name="liquid_50000" value="No" <?php echo (isset($_POST['liquid_50000']) && $_POST['liquid_50000'] === 'No') ? 'checked' : ''; ?> class="w-4 h-4 border-white/30 bg-white/10 text-amber-400 focus:ring-amber-400/30">
                                                <span>No</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="space-y-3">
                                        <label class="text-xs sm:text-sm font-bold text-white/90 ml-1 tracking-wide">How soon are you looking to deploy capital?</label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                                            <?php $selected_timeline = $_POST['deploy_timeline'] ?? []; ?>
                                            <label class="flex items-center gap-3 text-white/85 text-sm font-semibold">
                                                <input type="checkbox" name="deploy_timeline[]" value="Immediately" <?php echo in_array('Immediately', $selected_timeline) ? 'checked' : ''; ?> data-single-group="deploy_timeline" class="w-4 h-4 rounded border-white/30 bg-white/10 text-amber-400 focus:ring-amber-400/30">
                                                <span>Immediately</span>
                                            </label>
                                            <label class="flex items-center gap-3 text-white/85 text-sm font-semibold">
                                                <input type="checkbox" name="deploy_timeline[]" value="30-60 days" <?php echo in_array('30-60 days', $selected_timeline) ? 'checked' : ''; ?> data-single-group="deploy_timeline" class="w-4 h-4 rounded border-white/30 bg-white/10 text-amber-400 focus:ring-amber-400/30">
                                                <span>30–60 days</span>
                                            </label>
                                            <label class="flex items-center gap-3 text-white/85 text-sm font-semibold">
                                                <input type="checkbox" name="deploy_timeline[]" value="3-6 months" <?php echo in_array('3-6 months', $selected_timeline) ? 'checked' : ''; ?> data-single-group="deploy_timeline" class="w-4 h-4 rounded border-white/30 bg-white/10 text-amber-400 focus:ring-amber-400/30">
                                                <span>3–6 months</span>
                                            </label>
                                            <label class="flex items-center gap-3 text-white/85 text-sm font-semibold">
                                                <input type="checkbox" name="deploy_timeline[]" value="Just exploring" <?php echo in_array('Just exploring', $selected_timeline) ? 'checked' : ''; ?> data-single-group="deploy_timeline" class="w-4 h-4 rounded border-white/30 bg-white/10 text-amber-400 focus:ring-amber-400/30">
                                                <span>Just exploring</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="relative py-2 group/accredited">
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <div class="relative flex items-center justify-center mt-1">
                                                <input type="checkbox" name="is_accredited" <?php echo !empty($_POST['is_accredited']) ? 'checked' : ''; ?> class="peer sr-only">
                                                <div class="w-6 h-6 rounded-lg border-2 border-white/20 bg-white/5 peer-checked:bg-amber-500 peer-checked:border-amber-500 transition-all flex items-center justify-center">
                                                    <i class="fas fa-check text-[10px] text-[#0f2b44] opacity-0 peer-checked:opacity-100 transition-opacity"></i>
                                                </div>
                                            </div>
                                            <span class="text-xs sm:text-sm font-semibold text-white/80 group-hover/accredited:text-white transition-colors leading-snug">
                                                Are you an <span class="text-amber-400 underline underline-offset-4 decoration-amber-500/50">Accredited Investor</span>?
                                                <span class="block text-[10px] text-white/30 font-normal mt-1 uppercase tracking-widest">This qualifies you for priority access</span>
                                            </span>
                                        </label>
                                        <div class="mt-2 rounded-2xl border border-amber-400/30 bg-slate-900/95 p-4 text-xs sm:text-sm text-slate-200 leading-relaxed opacity-0 max-h-0 overflow-hidden pointer-events-none transition-all duration-200 group-hover/accredited:opacity-100 group-hover/accredited:max-h-[520px] group-hover/accredited:pointer-events-auto group-focus-within/accredited:opacity-100 group-focus-within/accredited:max-h-[520px] group-focus-within/accredited:pointer-events-auto group-has-[:checked]/accredited:!hidden">
                                            <p class="font-bold text-amber-300 mb-2">To qualify as an Accredited Investor, you must meet at least one of the following criteria:</p>
                                            <ul class="list-disc pl-5 space-y-1">
                                                <li><span class="font-semibold text-white">Income:</span> I have an annual income exceeding $200,000 (or $300,000 with a spouse or spousal equivalent) in each of the two most recent years and expect the same this year.</li>
                                                <li><span class="font-semibold text-white">Net Worth:</span> I have a net worth exceeding $1 million, either alone or with a spouse or spousal equivalent, excluding the value of my primary residence.</li>
                                                <li><span class="font-semibold text-white">Professional Certifications:</span> I am a natural person in good standing holding a Series 7, Series 65, or Series 82 license.</li>
                                                <li><span class="font-semibold text-white">Total Assets (Entities):</span> I represent an entity with total assets exceeding $5 million that was not formed specifically to purchase the subject securities.</li>
                                            </ul>
                                        </div>
                                    </div>

                                    <?php if (!empty($regError)): ?>
                                    <div class="bg-red-500/10 text-red-200 p-4 rounded-2xl text-xs flex items-center gap-3 border border-red-500/20">
                                        <i class="fas fa-exclamation-circle text-base"></i> <?php echo $regError; ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="flex justify-center sm:justify-start">
                                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($recaptcha_site_key, ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    </div>

                                    <div class="pt-4">
                                        <button type="submit" class="w-full group relative flex items-center justify-center gap-3 rounded-2xl bg-amber-500 hover:bg-amber-400 text-[#0f2b44] font-black text-base py-4 shadow-2xl transition-all transform hover:-translate-y-1 active:scale-[0.98] overflow-hidden">
                                            <span>Secure My Spot</span>
                                            <i class="fas fa-arrow-right text-sm transition-transform group-hover:translate-x-1"></i>
                                            <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_2s_infinite]"></div>
                                        </button>
                                        <p class="text-white/30 text-[9px] sm:text-[10px] text-center mt-6 uppercase tracking-[0.2em] font-bold">
                                            <i class="fas fa-lock mr-1"></i> Private & Confidential Access
                                        </p>
                                    </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Login Modal -->
    <div id="adminLoginModal" class="fixed inset-0 z-[250] hidden opacity-0 transition-opacity duration-300 overflow-y-auto overflow-x-hidden">
        <div id="adminLoginOverlay" class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div id="adminLoginPanel" class="mx-auto w-full min-w-0 max-w-[calc(100%-2rem)] sm:max-w-md bg-white rounded-3xl shadow-2xl overflow-hidden transform opacity-0 translate-y-8 transition-all duration-500 border border-slate-100">
                <div class="px-8 py-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-[#0f2b44] flex items-center justify-center text-white shadow-lg shadow-blue-900/20">
                            <i class="fas fa-user-shield text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-slate-900 tracking-tight">Admin Access</h3>
                            <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest">Secure Gateway</p>
                        </div>
                    </div>
                    <button id="closeAdminLogin" class="w-10 h-10 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-all">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form action="login.php" method="POST" class="p-8 space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value']); ?>">
                    
                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-slate-700 ml-1">Username</label>
                        <div class="relative group">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-[#0f2b44] transition-colors">
                                <i class="far fa-user"></i>
                            </span>
                            <input type="text" name="username" required placeholder="Admin username" 
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:outline-none focus:ring-4 focus:ring-[#0f2b44]/5 focus:border-[#0f2b44] focus:bg-white transition-all text-base text-slate-900 placeholder:text-slate-400">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-slate-700 ml-1">Password</label>
                        <div class="relative group">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-[#0f2b44] transition-colors">
                                <i class="fas fa-key"></i>
                            </span>
                            <button type="button" id="toggleAdminPassword" class="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition-colors" aria-label="Toggle password visibility">
                                <i id="toggleAdminPasswordIcon" class="fas fa-eye"></i>
                            </button>
                            <input type="password" name="password" required placeholder="••••••••" 
                                   class="w-full pl-12 pr-12 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:outline-none focus:ring-4 focus:ring-[#0f2b44]/5 focus:border-[#0f2b44] focus:bg-white transition-all text-base text-slate-900 placeholder:text-slate-400">
                        </div>
                        <?php if (isset($_GET['login_error']) && $_GET['login_error'] == 1): ?>
                        <div class="flex items-center gap-2 mt-2 ml-1 text-rose-500 animate-bounce">
                            <i class="fas fa-exclamation-triangle text-xs"></i>
                            <span class="text-xs font-bold uppercase tracking-widest">No Access.</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex justify-center">
                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($recaptcha_site_key, ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>

                    <button type="submit" class="w-full group relative flex items-center justify-center gap-3 rounded-2xl bg-[#0f2b44] hover:bg-[#1e4a7a] text-white font-extrabold text-lg py-5 shadow-2xl shadow-blue-900/30 transition-all transform hover:-translate-y-1 active:scale-[0.98]">
                        <i class="fas fa-lock-open transition-transform group-hover:scale-110"></i>
                        <span>Authenticate Access</span>
                    </button>
                    
                    <p class="text-center text-[10px] text-slate-400 uppercase tracking-widest font-bold">
                        <i class="fas fa-shield-alt mr-1 text-slate-300"></i> End-to-end encrypted session
                    </p>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== SECTION 5: JAVASCRIPT ========== -->
    <script>
        const phoneInput = document.querySelector("#phone");
        const phoneError = document.querySelector("#phone-error");
        const regForm = document.querySelector("#registrationModal form");

        function sanitizePhoneValue() {
            if (!phoneInput) return '';
            phoneInput.value = (phoneInput.value || '').replace(/\D+/g, '');
            return phoneInput.value;
        }

        phoneInput?.addEventListener('input', function() {
            sanitizePhoneValue();
            phoneError?.classList.add('hidden');
            phoneInput.classList.remove('border-rose-500');
        });
        
        phoneInput?.addEventListener('keyup', function(e) {
            let v = phoneInput.value.replace(/\D+/g, '');
            // Auto-format as +1 (000) 000-0000
            if (v.length > 0) {
                if (v.startsWith('1')) {
                    v = v.substring(1); // Remove leading 1 for formatting
                }
                let formatted = '+1 ';
                if (v.length > 0) {
                    formatted += '(' + v.substring(0, Math.min(3, v.length));
                    v = v.substring(Math.min(3, v.length));
                }
                if (v.length > 0) {
                    formatted += ') ' + v.substring(0, Math.min(3, v.length));
                    v = v.substring(Math.min(3, v.length));
                }
                if (v.length > 0) {
                    formatted += '-' + v.substring(0, Math.min(4, v.length));
                }
                phoneInput.value = formatted;
            }
        });

        phoneInput?.addEventListener('blur', function() {
            const v = sanitizePhoneValue();
            // Remove +1 and country code for validation
            let digits = v.replace(/\D+/g, '');
            if (digits.startsWith('1')) {
                digits = digits.substring(1);
            }
            if (digits !== '' && (digits.length < 10 || !preg_match(/^[2-9]/, digits))) {
                phoneError?.classList.remove('hidden');
                phoneInput.classList.add('border-rose-500');
            }
        });

        if (regForm) {
            regForm.addEventListener('submit', function(e) {
                const v = sanitizePhoneValue();
                let digits = v.replace(/\D+/g, '');
                if (digits.startsWith('1')) {
                    digits = digits.substring(1);
                }
                if (digits !== '' && (digits.length < 10 || !preg_match(/^[2-9]/, digits))) {
                    e.preventDefault();
                    phoneError?.classList.remove('hidden');
                    phoneInput?.classList.add('border-rose-500');
                    phoneInput?.focus();
                    return false;
                }
            });
        }

        const singleChoiceChecks = document.querySelectorAll('#registrationModal input[type="checkbox"][data-single-group]');
        singleChoiceChecks.forEach((input) => {
            input.addEventListener('change', function() {
                if (!this.checked) return;
                const group = this.getAttribute('data-single-group');
                if (!group) return;
                const peers = document.querySelectorAll('#registrationModal input[type="checkbox"][data-single-group="' + group + '"]');
                peers.forEach((peer) => {
                    if (peer !== this) peer.checked = false;
                });
            });
        });

        const adminPasswordInput = document.querySelector('#adminLoginModal input[name="password"]');
        const adminPasswordToggle = document.getElementById('toggleAdminPassword');
        const adminPasswordToggleIcon = document.getElementById('toggleAdminPasswordIcon');
        adminPasswordToggle?.addEventListener('click', function() {
            if (!adminPasswordInput) return;
            const isHidden = adminPasswordInput.getAttribute('type') === 'password';
            adminPasswordInput.setAttribute('type', isHidden ? 'text' : 'password');
            if (adminPasswordToggleIcon) {
                adminPasswordToggleIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
            }
        });

        const regModal = document.getElementById('registrationModal');
        const regOverlay = document.getElementById('registrationOverlay');
        const regPanel = document.getElementById('registrationPanel');

        function openRegistrationModal() {
            if (!regModal) return;
            regModal.classList.remove('hidden');
            setTimeout(() => {
                regOverlay.classList.remove('opacity-0');
                regOverlay.classList.add('opacity-100');
                regPanel.classList.remove('opacity-0', 'translate-y-4');
                regPanel.classList.add('opacity-100', 'translate-y-0');
            }, 10);
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function closeRegistrationModal() {
            if (!regModal) return;
            regOverlay.classList.remove('opacity-100');
            regOverlay.classList.add('opacity-0');
            regPanel.classList.add('opacity-0', 'translate-y-4');
            regPanel.classList.remove('opacity-100', 'translate-y-0');
            setTimeout(() => {
                regModal.classList.add('hidden');
            }, 300);
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        }

        if (regOverlay) regOverlay.addEventListener('click', closeRegistrationModal);

        // --- Admin Login Modal Logic ---
        const adminLoginModal = document.getElementById('adminLoginModal');
        const adminLoginPanel = document.getElementById('adminLoginPanel');
        const adminLoginOverlay = document.getElementById('adminLoginOverlay');
        const closeAdminLogin = document.getElementById('closeAdminLogin');

        function openAdminLogin() {
            adminLoginModal.classList.remove('hidden');
            setTimeout(() => {
                adminLoginModal.classList.remove('opacity-0');
                adminLoginPanel.classList.remove('opacity-0', 'translate-y-8');
                adminLoginPanel.classList.add('opacity-100', 'translate-y-0');
            }, 10);
        }

        function closeAdminLoginModal() {
            adminLoginModal.classList.add('opacity-0');
            adminLoginPanel.classList.remove('opacity-100', 'translate-y-0');
            adminLoginPanel.classList.add('opacity-0', 'translate-y-8');
            setTimeout(() => {
                adminLoginModal.classList.add('hidden');
            }, 300);
        }

        if (closeAdminLogin) closeAdminLogin.addEventListener('click', closeAdminLoginModal);
        if (adminLoginOverlay) adminLoginOverlay.addEventListener('click', closeAdminLoginModal);

        // Global Key Listener
        document.addEventListener('keydown', (e) => {
            // Esc to close all modals
            if (e.key === 'Escape') {
                if (!adminLoginModal.classList.contains('hidden')) closeAdminLoginModal();
                // (Existing Esc logic below will handle others)
            }
            
            // Ctrl + Shift + A to open admin login
            if (e.ctrlKey && e.shiftKey && e.key === 'L') {
                e.preventDefault();
                openAdminLogin();
            }
        });
        // --- End Admin Login Logic ---

        // Re-open modal if there was a registration error
        <?php if (!empty($regError)): ?>
        window.addEventListener('DOMContentLoaded', openRegistrationModal);
        <?php endif; ?>

        // Re-open admin login modal if there was a login error
        <?php if (isset($_GET['login_error']) && $_GET['login_error'] == 1): ?>
        window.addEventListener('DOMContentLoaded', openAdminLogin);
        <?php endif; ?>

        document.querySelectorAll('a[href="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                alert('This is a demo placeholder.');
            });
        });
        (() => {
            const expandBtn = document.querySelector('[data-model-expand]');
            if (!expandBtn) return;
            const ul = expandBtn.closest('ul');
            if (!ul) return;
            const details = Array.from(ul.querySelectorAll('[data-model-detail]'));
            if (details.length === 0) return;
            const collapseRow = ul.querySelector('[data-model-collapse-row]');
            const collapseBtn = ul.querySelector('[data-model-collapse]');

            const setExpanded = (expanded) => {
                details.forEach(li => li.classList.toggle('hidden', !expanded));
                if (collapseRow) collapseRow.classList.toggle('hidden', !expanded);
                expandBtn.classList.toggle('hidden', expanded);
                expandBtn.setAttribute('aria-expanded', String(expanded));
            };

            expandBtn.addEventListener('click', () => setExpanded(true));
            collapseBtn?.addEventListener('click', () => setExpanded(false));
        })();
        (() => {
            const carousel = document.getElementById('reviewsCarousel');
            if (!carousel) return;

            const cards = Array.from(carousel.querySelectorAll('.review-card'));
            if (cards.length === 0) return;

            const prevBtn = document.querySelector('[data-reviews-prev]');
            const nextBtn = document.querySelector('[data-reviews-next]');
            let index = 0;
            let timer = null;

            const updateClasses = () => {
                const prevIndex = (index - 1 + cards.length) % cards.length;
                const nextIndex = (index + 1) % cards.length;
                cards.forEach((card, i) => {
                    card.classList.remove('is-prev', 'is-active', 'is-next', 'is-hidden');
                    if (i === index) card.classList.add('is-active');
                    else if (i === prevIndex) card.classList.add('is-prev');
                    else if (i === nextIndex) card.classList.add('is-next');
                    else card.classList.add('is-hidden');
                });
            };

            const goTo = (nextIndex) => {
                index = (nextIndex + cards.length) % cards.length;
                updateClasses();
            };

            const start = () => {
                stop();
                timer = setInterval(() => goTo(index + 1), 4500);
            };

            const stop = () => {
                if (timer) clearInterval(timer);
                timer = null;
            };

            prevBtn?.addEventListener('click', () => {
                goTo(index - 1);
                start();
            });
            nextBtn?.addEventListener('click', () => {
                goTo(index + 1);
                start();
            });

            carousel.addEventListener('mouseenter', stop);
            carousel.addEventListener('mouseleave', start);
            carousel.addEventListener('focusin', stop);
            carousel.addEventListener('focusout', start);
            carousel.addEventListener('touchstart', stop, { passive: true });
            carousel.addEventListener('touchend', start);

            updateClasses();
            start();
        })();
    </script>

    <!-- Modal Scripts -->
    <script>
        const caseExamplesModal = document.getElementById('caseExamplesModal');
        const caseExamplesContainer = document.getElementById('caseExamplesContainer');
        const caseExamplesImagesGrid = document.getElementById('caseExamplesImagesGrid');
        const caseExamplesImageA = document.getElementById('caseExamplesImageA');
        const caseExamplesImageB = document.getElementById('caseExamplesImageB');
        const caseExamplesCounter = document.getElementById('caseExamplesCounter');
        const contractorPdfModal = document.getElementById('contractorPdfModal');
        const contractorPdfContainer = document.getElementById('contractorPdfContainer');
        const contractorDisplayImage = document.getElementById('contractorDisplayImage');
        const contractorImageCounter = document.getElementById('contractorImageCounter');
        const contractorPrevBtn = document.getElementById('contractorPrevBtn');
        const contractorNextBtn = document.getElementById('contractorNextBtn');
        const scopeOfWorkContent = document.getElementById('scopeOfWorkContent');
        const licenseContent = document.getElementById('licenseContent');
        const licenseDisplayImage = document.getElementById('licenseDisplayImage');
        const contractorImageUrls = [
            'files/scopeWork/1.png?v=' + new Date().getTime(),
            'files/scopeWork/2.png?v=' + new Date().getTime(),
            'files/scopeWork/3.png?v=' + new Date().getTime(),
            'files/scopeWork/4.png?v=' + new Date().getTime(),
            'files/scopeWork/5.png?v=' + new Date().getTime(),
            'files/scopeWork/6.png?v=' + new Date().getTime(),
            'files/scopeWork/7.png?v=' + new Date().getTime(),
            'files/scopeWork/8.png?v=' + new Date().getTime(),
            'files/scopeWork/9.png?v=' + new Date().getTime(),
            'files/scopeWork/10.png?v=' + new Date().getTime(),
            'files/scopeWork/11.png?v=' + new Date().getTime()
        ];
        const licenseImageUrls = [
            'files/license/6.png?v=' + new Date().getTime(),
            'files/license/7.png?v=' + new Date().getTime(),
            'files/license/8.png?v=' + new Date().getTime(),
            'files/license/9.png?v=' + new Date().getTime()
        ];
        let contractorImageIndex = 0; // Initialize properly
        let contractorImageZoomLevel = 1; // Initial zoom level
        let contractorImageOffsetX = 0; // For dragging
        let contractorImageOffsetY = 0; // For dragging
        let isDraggingContractorImage = false;
        let startDragX = 0;
        let startDragY = 0;



        let activeContractorTab = 'scopeOfWork'; // Default active tab

        function switchContractorTab(tabName) {
            activeContractorTab = tabName;

            const scopeOfWorkTab = document.getElementById('scopeOfWorkTab');
            const licenseTab = document.getElementById('licenseTab');

            // Update tab button styles
            scopeOfWorkTab.classList.toggle('bg-white/10', tabName === 'scopeOfWork');
            scopeOfWorkTab.classList.toggle('text-white', tabName === 'scopeOfWork');
            scopeOfWorkTab.classList.toggle('text-white/50', tabName !== 'scopeOfWork');
            scopeOfWorkTab.classList.toggle('hover:bg-white/5', tabName !== 'scopeOfWork');

            licenseTab.classList.toggle('bg-white/10', tabName === 'license');
            licenseTab.classList.toggle('text-white', tabName === 'license');
            licenseTab.classList.toggle('text-white/50', tabName !== 'license');
            licenseTab.classList.toggle('hover:bg-white/5', tabName !== 'license');

            // Update content visibility
            scopeOfWorkContent.classList.toggle('hidden', tabName !== 'scopeOfWork');
            licenseContent.classList.toggle('hidden', tabName !== 'license');

            // Reset image index and display for the new tab
            setContractorImage(0);
        }

        const qaSessionModal = document.getElementById('qaSessionModal');
        const qaSessionContainer = document.getElementById('qaSessionContainer');
        const liquidationModal = document.getElementById('liquidationModal');
        const liquidationContainer = document.getElementById('liquidationContainer');
        const statesMapModal = document.getElementById('statesMapModal');
        const statesMapContainer = document.getElementById('statesMapContainer');
        const galleries = {
            dealFinder: {
                layout: 'single',
                images: [
                    'img/Liquidation.jpeg',
                    'img/Liquidation2.jpeg',
                    'img/Liquidation3.jpeg',
                    'img/Liquidation4.jpeg'
                ]
            },
            caseExamples: {
                layout: 'single',
                images: [
                    'img/Screenshot%202026-03-30%20023657.png',
                    'img/Screenshot%202026-03-30%20023729.png',
                    'img/Screenshot%202026-03-30%20023742.png'
                ]
            }
        };
        let galleryKey = 'caseExamples';
        let galleryIndex = 0;

        function renderCaseExamplesImage() {
            if (!caseExamplesImageA || !caseExamplesCounter) return;
            const gallery = galleries[galleryKey] || { layout: 'single', images: [] };
            const layout = gallery.layout || 'single';
            const images = gallery.images || [];

            if (layout === 'pair') {
                const pageCount = Math.max(Math.ceil(images.length / 2), 1);
                const start = galleryIndex * 2;
                const srcA = images[start] || '';
                const srcB = images[start + 1] || '';
                const hasB = srcB !== '';

                caseExamplesImageA.src = srcA;
                if (caseExamplesImageB) {
                    caseExamplesImageB.src = srcB;
                    caseExamplesImageB.classList.toggle('hidden', !hasB);
                }
                caseExamplesImageA.classList.toggle('md:col-span-2', !hasB);
                caseExamplesImagesGrid?.classList.remove('md:grid-cols-1');
                caseExamplesCounter.textContent = `${Math.min(galleryIndex + 1, pageCount)} / ${pageCount}`;
                return;
            }

            caseExamplesImageA.src = images[galleryIndex] || '';
            if (caseExamplesImageB) {
                caseExamplesImageB.src = '';
                caseExamplesImageB.classList.add('hidden');
            }
            caseExamplesImageA.classList.add('md:col-span-2');
            caseExamplesImagesGrid?.classList.add('md:grid-cols-1');
            caseExamplesCounter.textContent = `${Math.min(galleryIndex + 1, images.length)} / ${images.length}`;
        }

        function openGalleryModal(nextGalleryKey, startIndex = 0) {
            if (!caseExamplesModal) return;
            galleryKey = nextGalleryKey;
            const gallery = galleries[galleryKey] || { layout: 'single', images: [] };
            const images = gallery.images || [];
            const layout = gallery.layout || 'single';
            const pageCount = layout === 'pair' ? Math.ceil(images.length / 2) : images.length;
            galleryIndex = Math.min(Math.max(Number(startIndex) || 0, 0), Math.max(pageCount - 1, 0));
            renderCaseExamplesImage();
            caseExamplesModal.classList.remove('hidden');
            setTimeout(() => {
                caseExamplesModal.classList.remove('opacity-0');
                caseExamplesContainer?.classList.remove('scale-95');
                caseExamplesContainer?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function openCaseExamplesModal(startIndex = 0) {
            openGalleryModal('caseExamples', startIndex);
        }

        function openDealFinderModal(startIndex = 0) {
            openGalleryModal('dealFinder', startIndex);
        }

        function openLiquidationModal() {
            if (!liquidationModal) return;
            liquidationModal.classList.remove('hidden');
            setTimeout(() => {
                liquidationModal.classList.remove('opacity-0');
                liquidationContainer?.classList.remove('scale-95');
                liquidationContainer?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function closeLiquidationModal() {
            if (!liquidationModal) return;
            liquidationModal.classList.add('opacity-0');
            liquidationContainer?.classList.remove('scale-100');
            liquidationContainer?.classList.add('scale-95');
            setTimeout(() => {
                liquidationModal.classList.add('hidden');
            }, 500);
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        }

        function setContractorImage(index) {
            let currentImageUrls;
            let currentDisplayImage;

            if (activeContractorTab === 'scopeOfWork') {
                currentImageUrls = contractorImageUrls;
                currentDisplayImage = contractorDisplayImage;
            } else {
                currentImageUrls = licenseImageUrls;
                currentDisplayImage = licenseDisplayImage;
            }

            if (!currentDisplayImage || !contractorImageCounter) return;
            const next = Number(index) || 0;
            contractorImageIndex = Math.min(Math.max(next, 0), currentImageUrls.length - 1);
            currentDisplayImage.src = currentImageUrls[contractorImageIndex] || '';
            contractorImageCounter.textContent = `${contractorImageIndex + 1} / ${currentImageUrls.length}`;
            resetContractorImageZoom(); // Reset zoom on image change
            resetContractorImageDrag(); // Reset drag position on image change
        }

        function contractorPrevImage() {
            setContractorImage(contractorImageIndex - 1);
        }

        function contractorNextImage() {
            setContractorImage(contractorImageIndex + 1);
        }

        function applyContractorImageTransform() {
            let currentDisplayImage;
            if (activeContractorTab === 'scopeOfWork') {
                currentDisplayImage = contractorDisplayImage;
            } else {
                currentDisplayImage = licenseDisplayImage;
            }
            if (!currentDisplayImage) return;
            
            let parent = currentDisplayImage.parentElement;

            if (contractorImageZoomLevel <= 1) {
                currentDisplayImage.style.width = '';
                currentDisplayImage.style.height = '';
                currentDisplayImage.style.maxWidth = '100%';
                currentDisplayImage.style.maxHeight = '100%';
                currentDisplayImage.style.transform = '';
                
                parent.classList.add('items-center', 'justify-center');
                parent.classList.remove('items-start', 'justify-start');
            } else {
                currentDisplayImage.style.maxWidth = 'none';
                currentDisplayImage.style.maxHeight = 'none';
                currentDisplayImage.style.width = `${contractorImageZoomLevel * 100}%`;
                currentDisplayImage.style.height = `${contractorImageZoomLevel * 100}%`;
                currentDisplayImage.style.transform = '';
                
                parent.classList.remove('items-center', 'justify-center');
                parent.classList.add('items-start', 'justify-start');
            }
        }

        function zoomInContractorImage() {
            let currentDisplayImage;
            if (activeContractorTab === 'scopeOfWork') {
                currentDisplayImage = contractorDisplayImage;
            } else {
                currentDisplayImage = licenseDisplayImage;
            }
            if (!currentDisplayImage) return;
            contractorImageZoomLevel = Math.min(contractorImageZoomLevel + 0.1, 3); // Max 3x zoom
            applyContractorImageTransform();
        }

        function zoomOutContractorImage() {
            let currentDisplayImage;
            if (activeContractorTab === 'scopeOfWork') {
                currentDisplayImage = contractorDisplayImage;
            } else {
                currentDisplayImage = licenseDisplayImage;
            }
            if (!currentDisplayImage) return;
            contractorImageZoomLevel = Math.max(contractorImageZoomLevel - 0.1, 1); // Min 1x zoom
            applyContractorImageTransform();
        }

        function resetContractorImageZoom() {
            let currentDisplayImage;
            if (activeContractorTab === 'scopeOfWork') {
                currentDisplayImage = contractorDisplayImage;
            } else {
                currentDisplayImage = licenseDisplayImage;
            }
            if (!currentDisplayImage) return;
            contractorImageZoomLevel = 1;
            applyContractorImageTransform();
        }

        function resetContractorImageDrag() {
            // Drag is no longer used, kept for backward compatibility if called elsewhere
            contractorImageOffsetX = 0;
            contractorImageOffsetY = 0;
        }



        function openContractorImageModal(startIndex = 0, initialTab = 'scopeOfWork') {
            if (!contractorPdfModal || !contractorDisplayImage || !licenseDisplayImage) return;
            if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) closeCaseExamplesModal();
            
            switchContractorTab(initialTab); // Set initial tab
            setContractorImage(startIndex);
            contractorPdfModal.classList.remove('hidden');
            setTimeout(() => {
                contractorPdfModal.classList.remove('opacity-0');
                contractorPdfContainer?.classList.remove('scale-95');
                contractorPdfContainer?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function closeContractorImageModal() {
            if (!contractorPdfModal) return;
            contractorPdfModal.classList.add('opacity-0');
            contractorPdfContainer?.classList.remove('scale-100');
            contractorPdfContainer?.classList.add('scale-95');
            setTimeout(() => {
                contractorPdfModal.classList.add('hidden');
                if (contractorDisplayImage) contractorDisplayImage.src = '';
                if (licenseDisplayImage) licenseDisplayImage.src = ''; // Clear license image too
                resetContractorImageZoom(); // Reset zoom when closing modal
                resetContractorImageDrag(); // Reset drag when closing modal
            }, 300);
            if (!caseExamplesModal || caseExamplesModal.classList.contains('hidden')) {
                document.body.style.overflow = '';
                document.documentElement.style.overflow = '';
            }
        }

        function openStatesMapModal() {
            const modal = document.getElementById('statesMapModal');
            const container = document.getElementById('statesMapContainer');
            if (!modal) return;
            
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                container?.classList.remove('scale-95');
                container?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function closeStatesMapModal() {
            const modal = document.getElementById('statesMapModal');
            const container = document.getElementById('statesMapContainer');
            if (!modal) return;
            
            modal.classList.add('opacity-0');
            container?.classList.remove('scale-100');
            container?.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 500);
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        }

        function openQaSessionModal() {
            const modal = document.getElementById('qaSessionModal');
            const container = document.getElementById('qaSessionContainer');
            if (!modal) return;
            
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                container?.classList.remove('scale-95');
                container?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function closeQaSessionModal() {
            const modal = document.getElementById('qaSessionModal');
            const container = document.getElementById('qaSessionContainer');
            if (!modal) return;
            
            modal.classList.add('opacity-0');
            container?.classList.remove('scale-100');
            container?.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 500);
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        }

        function closeCaseExamplesModal() {
            if (!caseExamplesModal) return;
            caseExamplesModal.classList.add('opacity-0');
            caseExamplesContainer?.classList.remove('scale-100');
            caseExamplesContainer?.classList.add('scale-95');
            setTimeout(() => {
                caseExamplesModal.classList.add('hidden');
            }, 300);
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        }

        function caseExamplesPrev() {
            const gallery = galleries[galleryKey] || { layout: 'single', images: [] };
            const images = gallery.images || [];
            const layout = gallery.layout || 'single';
            const pageCount = layout === 'pair' ? Math.ceil(images.length / 2) : images.length;
            if (pageCount <= 1) return;
            galleryIndex = (galleryIndex - 1 + pageCount) % pageCount;
            renderCaseExamplesImage();
        }

        function caseExamplesNext() {
            const gallery = galleries[galleryKey] || { layout: 'single', images: [] };
            const images = gallery.images || [];
            const layout = gallery.layout || 'single';
            const pageCount = layout === 'pair' ? Math.ceil(images.length / 2) : images.length;
            if (pageCount <= 1) return;
            galleryIndex = (galleryIndex + 1) % pageCount;
            renderCaseExamplesImage();
        }

        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (liquidationModal && !liquidationModal.classList.contains('hidden')) {
                    closeLiquidationModal();
                    return;
                }
                if (qaSessionModal && !qaSessionModal.classList.contains('hidden')) {
                    closeQaSessionModal();
                    return;
                }
                if (statesMapModal && !statesMapModal.classList.contains('hidden')) {
                    closeStatesMapModal();
                    return;
                }
                if (contractorPdfModal && !contractorPdfModal.classList.contains('hidden')) {
                    closeContractorPdfModal();
                    return;
                }
                if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) {
                    closeCaseExamplesModal();
                    return;
                }
                return;
            }
            if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) {
                if (e.key === 'ArrowLeft') caseExamplesPrev();
                if (e.key === 'ArrowRight') caseExamplesNext();
            }
            if (contractorPdfModal && !contractorPdfModal.classList.contains('hidden')) {
                if (e.key === 'ArrowLeft') contractorPrevImage();
                if (e.key === 'ArrowRight') contractorNextImage();
            }
        });

        // Close on outside click
        if (liquidationModal) {
            liquidationModal.addEventListener('click', (e) => {
                if (e.target === liquidationModal) {
                    closeLiquidationModal();
                }
            });
        }

        if (qaSessionModal) {
            qaSessionModal.addEventListener('click', (e) => {
                if (e.target === qaSessionModal) {
                    closeQaSessionModal();
                }
            });
        }

        if (caseExamplesModal) {
            caseExamplesModal.addEventListener('click', (e) => {
                if (e.target === caseExamplesModal) {
                    closeCaseExamplesModal();
                }
            });
        }

        if (contractorPdfModal) {
            contractorPdfModal.addEventListener('click', (e) => {
                if (e.target === contractorPdfModal) {
                    closeContractorPdfModal();
                }
            });
        }

        if (statesMapModal) {
            statesMapModal.addEventListener('click', (e) => {
                if (e.target === statesMapModal) {
                    closeStatesMapModal();
                }
            });
        }

        // --- Webinar Countdown Logic ---
        (() => {
            const countdownEl = document.getElementById('webinarCountdown');
            const targetDate = <?php echo (int)$countdownTargetMs; ?>;
            const webinarDuration = "<?php echo $latestWebinar['duration'] ?? '60-minute'; ?>";
            
            if (!countdownEl || !targetDate) return;
            
            const daysEl = document.getElementById('cd-days');
            const hoursEl = document.getElementById('cd-hours');
            const minutesEl = document.getElementById('cd-minutes');
            const secondsEl = document.getElementById('cd-seconds');
            const labelEl = document.getElementById('cd-label');
            const timerEl = document.getElementById('cd-timer');
            const liveEl = document.getElementById('cd-live');

            function updateCountdown() {
                const now = new Date().getTime();
                const distance = targetDate - now;
                
                // Parse duration to ms (fallback to 2 hours if not clear)
                let durationMs = 2 * 60 * 60 * 1000; 
                if (webinarDuration.includes('minute')) {
                    const mins = parseInt(webinarDuration);
                    if (!isNaN(mins)) durationMs = mins * 60 * 1000;
                }

                // State: Past (Webinar finished)
                if (distance < -durationMs) {
                    countdownEl.classList.add('hidden');
                    countdownEl.classList.remove('flex');
                    return;
                }

                // State: Live (Webinar is happening now)
                if (distance <= 0 && distance >= -durationMs) {
                    if (labelEl) labelEl.classList.add('hidden');
                    if (timerEl) timerEl.classList.add('hidden');
                    if (liveEl) {
                        liveEl.classList.remove('hidden');
                        liveEl.classList.add('flex');
                    }
                    countdownEl.classList.remove('hidden');
                    countdownEl.classList.add('flex');
                    return;
                }

                // State: Future (Counting down)
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                if (daysEl) daysEl.innerText = String(days).padStart(2, '0');
                if (hoursEl) hoursEl.innerText = String(hours).padStart(2, '0');
                if (minutesEl) minutesEl.innerText = String(minutes).padStart(2, '0');
                if (secondsEl) secondsEl.innerText = String(seconds).padStart(2, '0');

                if (labelEl) labelEl.classList.remove('hidden');
                if (timerEl) timerEl.classList.remove('hidden');
                if (liveEl) {
                    liveEl.classList.add('hidden');
                    liveEl.classList.remove('flex');
                }
                
                countdownEl.classList.remove('hidden');
                countdownEl.classList.add('flex');
            }

            updateCountdown();
            setInterval(updateCountdown, 1000);
        })();
    </script>


    <script>
        let currentLiquidationSlide = 0;
        const liquidationSlides = document.querySelectorAll('.liquidation-slide');
        const prevLiquidationBtn = document.getElementById('prevLiquidationSlide');
        const nextLiquidationBtn = document.getElementById('nextLiquidationSlide');

        function showLiquidationSlide(index) {
            liquidationSlides.forEach((slide, i) => {
                if (i === index) {
                    slide.classList.remove('hidden');
                } else {
                    slide.classList.add('hidden');
                }
            });
        }

        function nextLiquidationSlide() {
            currentLiquidationSlide = (currentLiquidationSlide + 1) % liquidationSlides.length;
            showLiquidationSlide(currentLiquidationSlide);
        }

        function prevLiquidationSlide() {
            currentLiquidationSlide = (currentLiquidationSlide - 1 + liquidationSlides.length) % liquidationSlides.length;
            showLiquidationSlide(currentLiquidationSlide);
        }

        if (prevLiquidationBtn && nextLiquidationBtn) {
            prevLiquidationBtn.addEventListener('click', prevLiquidationSlide);
            nextLiquidationBtn.addEventListener('click', nextLiquidationSlide);
        }
        
        // Initialize: show the first slide
        showLiquidationSlide(currentLiquidationSlide);
    </script>

    <!-- Real-Time Landing Page Updates (AJAX Polling every 20s) -->
    <script>
        setInterval(() => {
            // Do not refresh if any modal is open (so we don't interrupt the user)
            const isModalOpen = document.querySelectorAll('[role="dialog"]:not(.hidden), #registrationModal:not(.hidden), #adminLoginModal:not(.hidden)').length > 0;
            if (isModalOpen) return;

            fetch(window.location.href)
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    const currentTitle = document.querySelector('.hero-content h1')?.innerText.trim();
                    const newTitle = doc.querySelector('.hero-content h1')?.innerText.trim();
                    
                    const currentDate = document.getElementById('header-schedule')?.innerText.trim();
                    const newDate = doc.getElementById('header-schedule')?.innerText.trim();

                    // If the active webinar title or schedule changes, reload the page seamlessly
                    if (newTitle && newDate && (currentTitle !== newTitle || currentDate !== newDate)) {
                        window.location.reload();
                    }
                })
                .catch(err => console.error("Auto-refresh failed", err));
        }, 20000);
    </script>
</body>
</html>
