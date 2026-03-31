<?php
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$feedbackTableReady = true;
try {
    $pdo->query("SELECT name, email, message, rating FROM feedback LIMIT 1");
} catch (Throwable $e) {
    $feedbackTableReady = false;
}
if (!$feedbackTableReady) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS feedback (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(255) NULL,
                message TEXT NOT NULL,
                rating TINYINT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->query("SELECT name, email, message, rating FROM feedback LIMIT 1");
        $feedbackTableReady = true;
    } catch (Throwable $e) {
        $feedbackTableReady = false;
    }
}

$reviewFlash = '';
$reviewError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['feedback_submit'])) {
    $name = trim((string)($_POST['r_name'] ?? ''));
    $email = trim((string)($_POST['r_email'] ?? ''));
    $message = trim((string)($_POST['r_message'] ?? ''));
    $rating = (int)($_POST['r_rating'] ?? 0);

    if ($name === '' || $message === '' || $rating < 1 || $rating > 5) {
        $reviewError = 'Please provide your name, a message, and a rating (1–5).';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reviewError = 'Please enter a valid email address or leave it blank.';
    } elseif (!$feedbackTableReady) {
        $reviewError = 'Feedback storage is not available right now.';
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
    $cName = trim((string)($_POST['c_name'] ?? ''));
    $cEmail = trim((string)($_POST['c_email'] ?? ''));
    $cSubject = trim((string)($_POST['c_subject'] ?? ''));
    $cMessage = trim((string)($_POST['c_message'] ?? ''));

    if ($cName === '' || $cEmail === '' || $cMessage === '') {
        $contactError = 'Please fill in your name, email, and message.';
    } elseif (!filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
        $contactError = 'Please enter a valid email address.';
    } elseif (!$contactTableReady) {
        $contactError = 'Contact storage is not available right now.';
    } elseif (!class_exists(PHPMailer::class)) {
        $contactError = 'Email service is not available right now.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO contactus_tbl (name, email, subject, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$cName, $cEmail, $cSubject !== '' ? $cSubject : null, $cMessage]);
        if (function_exists('admin_notify')) {
            $title = $cSubject !== '' ? $cSubject : 'Contact request';
            admin_notify($pdo, 'contact', 'New Contact Message', $cName . ': ' . $title, 'index.php#contact');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp-relay.brevo.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'a61f36001@smtp-brevo.com';
            $mail->Password   = 'jUmI9RMntaAkbqKN';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->SMTPDebug  = SMTP::DEBUG_OFF;

            $mail->setFrom('mioymequities1@gmail.com', 'Mioym Equities');
            $mail->addAddress('jeswaaa1803@gmail.com', 'Mioym Contact Test');
            // $mail->addAddress('Robert@mioymmequities.com', 'Mioym Equities');
            $mail->addReplyTo($cEmail, $cName);
            $mail->isHTML(true);

            $safeSubject = $cSubject !== '' ? $cSubject : 'New Contact Us Message';
            $mail->Subject = 'Contact Us: ' . $safeSubject;

            $plain = "New Contact Us Inquiry\n\nName: {$cName}\nEmail: {$cEmail}\nSubject: {$safeSubject}\n\nMessage:\n{$cMessage}\n";
            $mail->AltBody = $plain;

            $replyHref = 'mailto:' . rawurlencode($cEmail) . '?subject=' . rawurlencode('Re: ' . $safeSubject);
            $body = '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="x-apple-disable-message-reformatting" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>Contact Us · Mioym Equities</title>
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse !important; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; height: auto; line-height: 100%; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
    .preheader { display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; max-height:0; max-width:0; overflow:hidden; mso-hide:all; }
    @media screen and (max-width: 620px) {
      .container { width: 100% !important; }
      .px { padding-left: 18px !important; padding-right: 18px !important; }
      .stack { display:block !important; width:100% !important; }
      .center { text-align:center !important; }
      .btn { width: 100% !important; }
      .btn a { display:block !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f6f8fb;">
  <div class="preheader">New contact inquiry from ' . htmlspecialchars($cName, ENT_QUOTES, "UTF-8") . ' · ' . htmlspecialchars($safeSubject, ENT_QUOTES, "UTF-8") . '</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f8fb;">
    <tr>
      <td align="center" style="padding:28px 12px;">
        <!--[if (mso)|(IE)]>
        <table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="600">
        <tr>
        <td>
        <![endif]-->

        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="container" style="width:600px;max-width:600px;">
          <tr>
            <td class="px" style="padding:0 24px 14px 24px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td class="center" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#475569;">
                    <span style="font-weight:800;font-size:18px;letter-spacing:-0.02em;color:#0f172a;">Mioym Equities</span>
                  </td>
                  <td align="right" class="center stack" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#64748b;">
                    <span style="display:inline-block;padding:6px 10px;border:1px solid #e2e8f0;border-radius:999px;background:#ffffff;">
                      Contact Form
                    </span>
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
                    <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:rgba(255,255,255,0.85);font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">
                      New Inquiry Received
                    </div>
                    <div style="margin-top:10px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:18px;line-height:24px;color:#ffffff;font-weight:800;letter-spacing:-0.02em;">
                      ' . htmlspecialchars($safeSubject, ENT_QUOTES, "UTF-8") . '
                    </div>
                  </td>
                </tr>

                <tr>
                  <td class="px" style="padding:22px 24px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td class="stack" style="width:50%;padding-right:10px;vertical-align:top;">
                          <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;line-height:16px;color:#64748b;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;">Name</div>
                          <div style="margin-top:6px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#0f172a;font-weight:800;">' . htmlspecialchars($cName, ENT_QUOTES, "UTF-8") . '</div>
                        </td>
                        <td class="stack" style="width:50%;padding-left:10px;vertical-align:top;">
                          <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;line-height:16px;color:#64748b;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;">Email</div>
                          <div style="margin-top:6px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;">
                            <a href="mailto:' . htmlspecialchars($cEmail, ENT_QUOTES, "UTF-8") . '" style="color:#1e4a7a;text-decoration:underline;font-weight:800;">' . htmlspecialchars($cEmail, ENT_QUOTES, "UTF-8") . '</a>
                          </div>
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                      <tr>
                        <td style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;">
                          <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;line-height:16px;color:#64748b;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;">Message</div>
                          <div style="margin-top:8px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#0f172a;white-space:pre-line;">' . htmlspecialchars($cMessage, ENT_QUOTES, "UTF-8") . '</div>
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" class="btn" style="margin:18px auto 0 auto;">
                      <tr>
                        <td align="center" style="border-radius:12px;" bgcolor="#f59e0b">
                          <!--[if mso]>
                          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . htmlspecialchars($replyHref, ENT_QUOTES, "UTF-8") . '" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="22%" strokecolor="#f59e0b" fillcolor="#f59e0b">
                            <w:anchorlock/>
                            <center style="color:#0f2b44;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Reply to Sender</center>
                          </v:roundrect>
                          <![endif]-->
                          <!--[if !mso]><!-- -->
                          <a href="' . htmlspecialchars($replyHref, ENT_QUOTES, "UTF-8") . '" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#f59e0b;color:#0f2b44;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:800;text-decoration:none;">
                            Reply to Sender
                          </a>
                          <!--<![endif]-->
                        </td>
                      </tr>
                    </table>

                    <div style="margin-top:14px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#64748b;text-align:center;">
                      Tip: You can also reply directly to this email.
                    </div>
                  </td>
                </tr>

                <tr>
                  <td class="px" style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                    <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#64748b;">
                      <div style="font-weight:700;color:#475569;">© ' . date('Y') . ' Mioym Equities</div>
                      <div style="margin-top:6px;color:#94a3b8;">Automated Contact Notification</div>
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <!--[if (mso)|(IE)]>
        </td>
        </tr>
        </table>
        <![endif]-->
      </td>
    </tr>
  </table>
</body>
</html>';

            $mail->Body = $body;
            $mail->send();

            header('Location: index.php?contact=success#contact');
            exit;
        } catch (Throwable $e) {
            $contactError = 'Message saved, but email sending failed. Please try again.';
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
            $color = trim((string)($it['color'] ?? '#ffffff'));
            if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color)) $color = '#ffffff';
            if ($color !== '' && $color[0] !== '#') $color = '#' . $color;
            $bold = !empty($it['bold']);
            $font = (string)($it['font'] ?? 'system_sans');
            if (!in_array($font, $allowedFonts, true)) $font = 'system_sans';
            $webinarSubheadingItems[] = ['text' => $text, 'size' => $size, 'color' => $color, 'bold' => $bold, 'font' => $font];
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
$webinarDescriptionRaw = trim((string)($latestWebinar['description'] ?? ''));
$webinarDescriptionLines = preg_split("/\r\n|\r|\n/", $webinarDescriptionRaw);
$webinarDescriptionLines = array_values(array_filter(array_map('trim', $webinarDescriptionLines), static fn ($l) => $l !== ''));
$webinarDescriptionLead = $webinarDescriptionLines[0] ?? '';
$webinarDescriptionBullets = array_slice($webinarDescriptionLines, 1);
$webinarVid = $latestWebinar['webinar_vid'] ?? null;
$scheduleDate = isset($latestWebinar['schedule_date&time']) 
    ? date('d M Y · g:ia', strtotime($latestWebinar['schedule_date&time'])) 
    : '24 Apr 2025 · 6pm BST';

// Fetch Dynamic Social Proof Data
$webinarId = $latestWebinar['webinar_id'] ?? null;
$isPublished = isset($latestWebinar['is_published']) && $latestWebinar['is_published'] == 1;

// 1. Total count of registrants for THIS webinar
if ($webinarId && $isPublished) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM registrants_tbl WHERE webinar_id = ?");
    $stmtCount->execute([$webinarId]);
    $registrantCount = $stmtCount->fetchColumn();

    // 2. Fetch last 3 registrants for initials for THIS webinar
    $stmtRecent = $pdo->prepare("SELECT fullname FROM registrants_tbl WHERE webinar_id = ? ORDER BY registration_date DESC LIMIT 3");
    $stmtRecent->execute([$webinarId]);
    $recentRegistrants = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
} else {
    $registrantCount = 0;
    $recentRegistrants = [];
}

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
        $feedbacks = $pdo->query("SELECT name, email, message, rating FROM feedback ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
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
    <!-- Tailwind + Font Awesome -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ========== SECTION 1: CUSTOM STYLES ========== */
        /* Video placeholder styling */
        .video-placeholder {
            background: linear-gradient(145deg, #0b1f30, #1d3b58);
            aspect-ratio: 16 / 9;
            border-radius: 1.5rem;
        }
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
        /* HERO SECTION with background image - walang extrang container */
        .hero-section {
            position: relative;
            background-image: url('https://upload.wikimedia.org/wikipedia/commons/thumb/7/7a/View_of_Empire_State_Building_from_Rockefeller_Center_New_York_City_dllu_%28cropped%29.jpg/1920px-View_of_Empire_State_Building_from_Rockefeller_Center_New_York_City_dllu_%28cropped%29.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            border-radius: 0;
            margin-bottom: 5rem;
        }
        /* Dark overlay para sa hero section - gamit ang pseudo-element, walang extra div */
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(15, 43, 68, 0.85) 0%, rgba(30, 74, 122, 0.85) 100%);
            border-radius: 0;
            z-index: 1;
        }
        /* Content stays above overlay */
        .hero-content {
            position: relative;
            z-index: 2;
        }
        /* Banner content animation */
        .banner-content {
            animation: subtlePulse 2s infinite ease-in-out;
        }
        @keyframes subtlePulse {
            0%, 100% { opacity: 0.95; }
            50% { opacity: 1; }
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
            height: 420px;
        }
        .review-card {
            position: absolute;
            left: 50%;
            top: 0;
            width: min(100%, 22rem);
            height: 360px;
            display: flex;
            flex-direction: column;
            transition: transform 450ms ease, opacity 450ms ease, box-shadow 450ms ease;
            transform: translate(-50%, 28px) scale(0.92);
            opacity: 0.75;
            z-index: 10;
        }
        .review-card.is-prev {
            transform: translate(calc(-50% - 240px), 40px) scale(0.92);
            opacity: 0.85;
            z-index: 15;
        }
        .review-card.is-next {
            transform: translate(calc(-50% + 240px), 40px) scale(0.92);
            opacity: 0.85;
            z-index: 15;
        }
        .review-card.is-active {
            transform: translate(-50%, 0px) scale(1.05);
            opacity: 1;
            z-index: 25;
            box-shadow: 0 25px 60px rgba(0,0,0,0.28);
        }
        .review-card.is-hidden {
            opacity: 0;
            transform: translate(-50%, 60px) scale(0.9);
            z-index: 0;
            pointer-events: none;
        }
        .review-rating {
            display: inline-flex;
            flex-direction: row-reverse;
            gap: 0.25rem;
        }
        .review-rating input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .review-rating label {
            cursor: pointer;
            color: #cbd5e1;
            font-size: 1.25rem;
            line-height: 1;
            transition: transform 120ms ease, color 120ms ease;
        }
        .review-rating label:hover,
        .review-rating label:hover ~ label {
            color: #f59e0b;
            transform: translateY(-1px);
        }
        .review-rating input:checked ~ label {
            color: #f59e0b;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #94a3b8; }
        @media (max-width: 768px) {
            .reviews-stage { padding: 2rem 1rem; }
            .reviews-stack { height: 420px; }
            .review-card.is-prev,
            .review-card.is-next {
                opacity: 0;
                pointer-events: none;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-b from-slate-50 to-white text-slate-800 font-sans antialiased">

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
    <div class="max-w-6xl mx-auto py-4 md:py-5">

        <!-- ========== SECTION 4.1: HEADER ========== -->
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                <div class="text-white w-80 h-24 sm:h-28 md:h-32 flex items-center justify-center overflow-hidden">
                    <img src="img/logo2.png" alt="Mioym Group" class="w-full h-full object-contain">
                </div>
            </div>
            <span class="inline-flex items-center gap-2 text-xs sm:text-sm bg-[#0f2b44] text-white border border-white/15 px-3 sm:px-4 py-2 rounded-full shadow-sm backdrop-blur">
                <i class="far fa-calendar-alt text-amber-300"></i> <?php echo htmlspecialchars($scheduleDate); ?>
            </span>
        </div>
    </div>
    </div>

        <!-- ========== SECTION 4.2: HERO SECTION - WALANG EXTRANG CONTAINER, BACKGROUND LANG ========== -->
    <section class="hero-section">
        <div class="hero-content max-w-6xl mx-auto px-5 sm:px-8 py-10 md:py-16">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- Left side: Text content -->
                <div>
                    <p class="text-amber-300 font-semibold text-sm tracking-wide uppercase mb-3 flex items-center gap-2">
                        <span class="w-8 h-0.5 bg-amber-400"></span> exclusive live training
                    </p>
                    <h1 class="text-4xl md:text-5xl lg:text-5xl font-extrabold tracking-tight text-white leading-tight">
                        <?php echo nl2br(htmlspecialchars($webinarTitle, ENT_QUOTES, 'UTF-8')); ?>
                    </h1>
                    <?php if (!empty($webinarSubheadingItems)): ?>
                        <div class="mt-3 mb-5 space-y-2">
                            <?php foreach ($webinarSubheadingItems as $it): ?>
                                <div class="tracking-tight leading-snug" style="font-size: <?php echo (int)($it['size'] ?? 20); ?>px; font-weight: <?php echo !empty($it['bold']) ? 800 : 600; ?>; color: <?php echo htmlspecialchars((string)($it['color'] ?? '#ffffff')); ?>; font-family: <?php echo htmlspecialchars(landing_subheading_font_stack((string)($it['font'] ?? 'system_sans'))); ?>;">
                                    <?php echo htmlspecialchars((string)($it['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($webinarSubheading !== ''): ?>
                        <div class="mt-3 mb-5 tracking-tight leading-snug" style="font-size: <?php echo (int)$webinarSubheadingSize; ?>px; font-weight: <?php echo $webinarSubheadingBold ? 800 : 600; ?>; color: <?php echo htmlspecialchars($webinarSubheadingColor); ?>;">
                            <?php echo nl2br(htmlspecialchars($webinarSubheading, ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    <?php endif; ?>
                    <p class="text-lg md:text-xl text-slate-200 mt-6 max-w-lg">
                        <?php if ($webinarDescriptionLead !== ''): ?>
                            <?php echo htmlspecialchars($webinarDescriptionLead); ?>
                        <?php else: ?>
                            Join our free 60‑minute webinar and learn the exact framework used by family offices to source off‑market multifamily & industrial assets.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($webinarDescriptionBullets)): ?>
                    <ul class="mt-8 space-y-3">
                        <?php
                            $__bullets = array_values($webinarDescriptionBullets);
                            $__first = $__bullets[0] ?? null;
                            $__rest = array_slice($__bullets, 1);
                        ?>
                        <?php if ($__first !== null): ?>
                            <?php $__cleanFirst = ltrim($__first, "•- \t"); ?>
                            <li class="flex items-start gap-3">
                                <i class="fas fa-check-circle text-amber-400 text-xl mt-0.5"></i>
                                <span class="text-slate-100">
                                    <?php echo htmlspecialchars($__cleanFirst); ?>
                                    <?php if (!empty($__rest)): ?>
                                        <button type="button" data-model-expand aria-expanded="false" class="ml-2 text-amber-300 hover:text-amber-200 underline underline-offset-4 font-semibold">
                                            Read more ...
                                        </button>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endif; ?>
                        <?php foreach ($__rest as $line): ?>
                            <?php $clean = ltrim($line, "•- \t"); ?>
                            <li class="flex items-start gap-3 hidden" data-model-detail>
                                <i class="fas fa-check-circle text-amber-400 text-xl mt-0.5"></i>
                                <span class="text-slate-100"><?php echo htmlspecialchars($clean); ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!empty($__rest)): ?>
                            <li class="hidden pt-1" data-model-collapse-row>
                                <button type="button" data-model-collapse class="text-amber-300 hover:text-amber-200 underline underline-offset-4 font-semibold">
                                    Read less
                                </button>
                            </li>
                        <?php endif; ?>
                    </ul>
                    <?php endif; ?>
                    <div class="flex flex-wrap items-center gap-4 mt-10">
                        <a href="registration.php" class="bg-amber-500 hover:bg-amber-400 text-[#0f2b44] font-bold text-lg px-8 py-4 rounded-full shadow-xl transition inline-flex items-center gap-3">
                            <i class="fas fa-calendar-check"></i> Register now — it's free
                        </a>
                        <a href="#" class="text-white font-medium hover:text-amber-300 transition flex items-center gap-2 group">
                            <i class="far fa-clock text-amber-300 group-hover:scale-105"></i> limited seats
                        </a>
                    </div>
                    <?php if ($registrantCount > 0 && $isPublished): ?>
                    <p class="text-sm text-slate-200 mt-6 flex items-center gap-2">
                        <span class="flex -space-x-1">
                            <?php 
                            $colors = ['bg-amber-400', 'bg-amber-300', 'bg-amber-200'];
                            foreach($recentRegistrants as $i => $reg): 
                            ?>
                                <span class="w-6 h-6 rounded-full <?php echo $colors[$i % 3]; ?> border-2 border-white text-[10px] text-[#0f2b44] flex items-center justify-center font-bold">
                                    <?php echo getInitials($reg['fullname']); ?>
                                </span>
                            <?php endforeach; ?>
                        </span>
                        <span><span class="font-semibold text-white"><?php echo number_format($registrantCount); ?></span> total attendees for this published webinar</span>
                    </p>
                    <?php endif; ?>
                </div>
                <!-- Right side: Video placeholder -->
                <div class="video-placeholder relative shadow-2xl rounded-2xl overflow-hidden group cursor-pointer border border-white/20" onclick="openVideoModal()">
                    <?php if($webinarVid): ?>
                        <video id="previewVideo" class="absolute inset-0 w-full h-full object-cover opacity-80" muted loop playsinline autoplay preload="metadata">
                            <source src="<?php echo htmlspecialchars($webinarVid); ?>" type="video/mp4">
                        </video>
                    <?php endif; ?>
                    <div class="absolute bottom-3 left-4 text-white text-xs bg-black/40 px-3 py-1 rounded-full backdrop-blur-sm">
                        <i class="far fa-clock mr-1"></i> 60 min · replay available
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Video Modal -->
    <div id="videoModal" class="fixed inset-0 z-50 hidden bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 md:p-10 opacity-0 transition-opacity duration-300">
        <button onclick="closeVideoModal()" class="absolute top-6 right-6 text-white hover:text-amber-400 transition-colors bg-black/50 w-10 h-10 rounded-full flex items-center justify-center z-50">
            <i class="fas fa-times text-xl"></i>
        </button>
        <div class="w-full max-w-5xl aspect-video bg-black rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 transition-transform duration-300" id="videoContainer">
            <?php if($webinarVid): ?>
                <video id="mainVideo" class="w-full h-full" controls controlsList="nodownload">
                    <source src="<?php echo htmlspecialchars($webinarVid); ?>" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            <?php else: ?>
                <div class="absolute inset-0 flex items-center justify-center text-white flex-col gap-4">
                    <i class="fas fa-video-slash text-5xl text-slate-500"></i>
                    <p class="text-xl font-medium text-slate-400">Video coming soon</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="caseExamplesModal" class="fixed inset-0 z-50 hidden bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 md:p-10 opacity-0 transition-opacity duration-300">
        <button onclick="closeCaseExamplesModal()" class="absolute top-6 right-6 text-white hover:text-amber-400 transition-colors bg-black/50 w-10 h-10 rounded-full flex items-center justify-center z-50">
            <i class="fas fa-times text-xl"></i>
        </button>
        <div class="w-full max-w-7xl bg-black rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 transition-transform duration-300" id="caseExamplesContainer">
            <div class="relative">
                <div id="caseExamplesImagesGrid" class="grid grid-cols-1 md:grid-cols-2 gap-1 p-1 bg-black">
                    <img id="caseExamplesImageA" src="" alt="Case example" class="w-full max-h-[92vh] object-contain bg-black rounded-lg md:col-span-2">
                    <img id="caseExamplesImageB" src="" alt="Case example" class="w-full max-h-[92vh] object-contain bg-black rounded-lg hidden">
                </div>
                <button type="button" onclick="caseExamplesPrev()" class="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/50 text-white hover:text-amber-300 transition-colors flex items-center justify-center">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button type="button" onclick="caseExamplesNext()" class="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/50 text-white hover:text-amber-300 transition-colors flex items-center justify-center">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <div class="px-4 py-3 bg-black/60 text-white text-sm flex items-center justify-center">
                <span id="caseExamplesCounter"></span>
            </div>
        </div>
    </div>

    <div id="contractorPdfModal" class="fixed inset-0 z-50 hidden bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 md:p-10 opacity-0 transition-opacity duration-300">
        <button onclick="closeContractorPdfModal()" class="absolute top-6 right-6 text-white hover:text-amber-400 transition-colors bg-black/50 w-10 h-10 rounded-full flex items-center justify-center z-50">
            <i class="fas fa-times text-xl"></i>
        </button>
        <div class="w-full max-w-7xl bg-black rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 transition-transform duration-300" id="contractorPdfContainer">
            <div class="flex flex-wrap items-center justify-center gap-2 p-3 bg-black/60">
                <button type="button" id="contractorPdfBtn0" onclick="setContractorPdf(0)" class="px-3 py-2 rounded-full text-sm font-semibold bg-white text-[#0f2b44] hover:bg-amber-100 transition">
                    Scope of Work
                </button>
                <button type="button" id="contractorPdfBtn1" onclick="setContractorPdf(1)" class="px-3 py-2 rounded-full text-sm font-semibold bg-white/10 text-white hover:bg-white/20 transition">
                    Contractor License
                </button>
            </div>
            <iframe id="contractorPdfFrame" class="w-full h-[82vh] md:h-[86vh] bg-black" src="" title="Contractor PDF" loading="lazy"></iframe>
        </div>
    </div>

    <div id="statesPdfModal" class="fixed inset-0 z-50 hidden bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 md:p-10 opacity-0 transition-opacity duration-300">
        <button onclick="closeStatesPdfModal()" class="absolute top-6 right-6 text-white hover:text-amber-400 transition-colors bg-black/50 w-10 h-10 rounded-full flex items-center justify-center z-50">
            <i class="fas fa-times text-xl"></i>
        </button>
        <div class="w-full max-w-7xl bg-black rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 transition-transform duration-300" id="statesPdfContainer">
            <iframe id="statesPdfFrame" class="w-full h-[88vh] bg-black" src="" title="States we buy in" loading="lazy"></iframe>
        </div>
    </div>

    <div id="faqsPdfModal" class="fixed inset-0 z-50 hidden bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 md:p-10 opacity-0 transition-opacity duration-300">
        <button onclick="closeFaqsPdfModal()" class="absolute top-6 right-6 text-white hover:text-amber-400 transition-colors bg-black/50 w-10 h-10 rounded-full flex items-center justify-center z-50">
            <i class="fas fa-times text-xl"></i>
        </button>
        <div class="w-full max-w-7xl bg-black rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 transition-transform duration-300" id="faqsPdfContainer">
            <iframe id="faqsPdfFrame" class="w-full h-[88vh] bg-black" src="" title="MIOYM FAQs" loading="lazy"></iframe>
        </div>
    </div>

        <!-- ========== SECTION 4.3: BENEFITS SECTION ========== -->
    <main class="max-w-6xl mx-auto px-5 sm:px-8 pb-10 md:pb-16">
        <div class="flex flex-col sm:flex-row items-center justify-center gap-8 mt-12 mb-16">
            <div class="w-28 h-28 rounded-full bg-slate-300 overflow-hidden flex items-center justify-center text-5xl text-white shadow-md border-4 border-white">
                <?php if($hostPic): ?>
                    <img src="<?php echo htmlspecialchars($hostPic); ?>" alt="Host" class="w-full h-full object-cover">
                <?php else: ?>
                    👩‍💼
                <?php endif; ?>
            </div>
            <div class="text-center sm:text-left">
                <h3 class="text-2xl font-bold text-slate-800">Meet your host — <?php echo htmlspecialchars($hostName); ?></h3>
                <p class="text-slate-600 mt-2 max-w-2xl">President of Mioym Equities</p>
            </div>
        </div>
        <div class="my-24 text-center">
            <span class="text-[#1e4a7a] font-semibold text-sm tracking-wider uppercase bg-slate-100 px-4 py-1.5 rounded-full">why attend</span>
            <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mt-5 mb-12 max-w-2xl mx-auto">In one session you'll discover how we:</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 text-left">
                <a href="https://www.youtube.com/watch?v=D-BrVcxWSb0" target="_blank" rel="noopener noreferrer" class="group relative block bg-white/75 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 overflow-hidden h-[340px] sm:h-[360px]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="relative flex flex-col h-full">
                        <div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5 shadow-sm ring-1 ring-white/60 group-hover:scale-105 transition-transform duration-300">🔑</div>
                        <h3 class="text-xl font-bold text-slate-800 mb-2 tracking-tight">How we Access Opportunistic Undervalue Assets</h3>
                        <p class="text-slate-600 leading-relaxed text-sm flex-1 overflow-hidden"></p>
                        <span class="mt-auto inline-flex items-center gap-2 text-sm font-semibold text-[#0f2b44] bg-amber-100/80 border border-amber-200 px-3 py-1.5 rounded-full w-fit">
                            Click to view <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </a>

                <div role="button" tabindex="0" onclick="openCaseExamplesModal()" class="group relative bg-white/75 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-slate-200 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 overflow-hidden h-[340px] sm:h-[360px]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="relative flex flex-col h-full">
                        <div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5 shadow-sm ring-1 ring-white/60 group-hover:scale-105 transition-transform duration-300">📊</div>
                        <h3 class="text-xl font-bold text-slate-800 mb-2 tracking-tight">Case Examples of Returns</h3>
                        <p class="text-slate-600 leading-relaxed text-sm flex-1 overflow-hidden">Step‑by‑step walkthrough of the 10‑minute underwriting template used by our analysts.</p>
                        <span class="mt-auto inline-flex items-center gap-2 text-sm font-semibold text-[#0f2b44] bg-amber-100/80 border border-amber-200 px-3 py-1.5 rounded-full w-fit">
                            Click to view <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </div>

                <div role="button" tabindex="0" onclick="openAffordableHomesModal()" class="group relative bg-white/75 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-slate-200 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 overflow-hidden h-[340px] sm:h-[360px]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="relative flex flex-col h-full">
                        <div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5 shadow-sm ring-1 ring-white/60 group-hover:scale-105 transition-transform duration-300">⚖️</div>
                        <h3 class="text-xl font-bold text-slate-800 mb-2 tracking-tight">Sell/Liquidate to First time Home Buyers</h3>
                        <p class="text-slate-600 leading-relaxed text-sm flex-1 overflow-hidden">Own A Home For The Same Amount You Pay In Rent Downpayment and Closing Cost Provided 600 Minimum Credit Score to qualify Move in with first month’s payment and security deposit</p>
                        <span class="mt-auto inline-flex items-center gap-2 text-sm font-semibold text-[#0f2b44] bg-amber-100/80 border border-amber-200 px-3 py-1.5 rounded-full w-fit">
                            Click to view <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </div>

                <div role="button" tabindex="0" onclick="openContractorPdfModal()" class="group relative bg-white/75 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-slate-200 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 overflow-hidden h-[340px] sm:h-[360px]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="relative flex flex-col h-full">
                        <div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5 shadow-sm ring-1 ring-white/60 group-hover:scale-105 transition-transform duration-300">🤝</div>
                        <h3 class="text-xl font-bold text-slate-800 mb-2 tracking-tight">Contractor Management</h3>
                        <p class="text-slate-600 leading-relaxed text-sm flex-1 overflow-hidden">How to control boots on the ground and scopes of works.</p>
                        <span class="mt-auto inline-flex items-center gap-2 text-sm font-semibold text-[#0f2b44] bg-amber-100/80 border border-amber-200 px-3 py-1.5 rounded-full w-fit">
                            Click to view <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </div>

                <div role="button" tabindex="0" onclick="openStatesPdfModal()" class="group relative bg-white/75 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-slate-200 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 overflow-hidden h-[340px] sm:h-[360px]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="relative flex flex-col h-full">
                        <div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5 shadow-sm ring-1 ring-white/60 group-hover:scale-105 transition-transform duration-300">📈</div>
                        <h3 class="text-xl font-bold text-slate-800 mb-2 tracking-tight">Portfolio diversification</h3>
                        <p class="text-slate-600 leading-relaxed text-sm flex-1 overflow-hidden"></p>
                        <span class="mt-auto inline-flex items-center gap-2 text-sm font-semibold text-[#0f2b44] bg-amber-100/80 border border-amber-200 px-3 py-1.5 rounded-full w-fit">
                            Click to view <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </div>

                <div role="button" tabindex="0" onclick="openFaqsPdfModal()" class="group relative bg-white/75 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-slate-200 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 overflow-hidden h-[340px] sm:h-[360px]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="relative flex flex-col h-full">
                        <div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5 shadow-sm ring-1 ring-white/60 group-hover:scale-105 transition-transform duration-300">🎯</div>
                        <h3 class="text-xl font-bold text-slate-800 mb-2 tracking-tight">Actionable Q&A session</h3>
                        <p class="text-slate-600 leading-relaxed text-sm flex-1 overflow-hidden">Live Q&A with our investment team — bring your specific questions.</p>
                        <span class="mt-auto inline-flex items-center gap-2 text-sm font-semibold text-[#0f2b44] bg-amber-100/80 border border-amber-200 px-3 py-1.5 rounded-full w-fit">
                            Click to view <i class="fas fa-arrow-right text-xs"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="my-20 mb-5" id="reviews">
            <div class="text-center mb-10">
                <span class="text-[#1e4a7a] font-semibold text-sm tracking-wider uppercase bg-slate-100 px-4 py-1.5 rounded-full">reviews</span>
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mt-5">What investors say</h2>
            </div>
            <div class="reviews-stage relative">
                <button type="button" data-reviews-prev aria-label="Previous review" class="hidden md:flex absolute left-6 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/95 border border-white/30 shadow-md items-center justify-center text-slate-700 hover:text-slate-900 hover:shadow-lg transition z-30">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button type="button" data-reviews-next aria-label="Next review" class="hidden md:flex absolute right-6 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/95 border border-white/30 shadow-md items-center justify-center text-slate-700 hover:text-slate-900 hover:shadow-lg transition z-30">
                    <i class="fas fa-chevron-right"></i>
                </button>
                <div id="reviewsCarousel" class="reviews-stack">
                    <?php if (!empty($feedbacks)): ?>
                        <?php foreach ($feedbacks as $idx => $fb): ?>
                            <?php
                                $badgeColors = ['bg-amber-200', 'bg-amber-300', 'bg-amber-100'];
                                $badge = $badgeColors[$idx % 3];
                                $nm = (string)($fb['name'] ?? '');
                                $msg = (string)($fb['message'] ?? '');
                                $rt = (int)($fb['rating'] ?? 0);
                                if ($rt < 1) $rt = 1;
                                if ($rt > 5) $rt = 5;
                            ?>
                            <div class="review-card bg-white/80 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md h-[360px]">
                                <div class="flex items-center justify-between gap-4 shrink-0">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-10 h-10 rounded-full <?php echo $badge; ?> text-[#0f2b44] flex items-center justify-center font-bold shrink-0">
                                            <?php echo htmlspecialchars(getInitials($nm)); ?>
                                        </div>
                                        <div class="text-sm font-semibold text-slate-700 truncate"><?php echo htmlspecialchars($nm); ?></div>
                                    </div>
                                    <div class="flex items-center gap-0.5 whitespace-nowrap shrink-0 leading-none">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="fas fa-star <?php echo $s <= $rt ? 'text-amber-400' : 'text-slate-300'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="mt-4 flex-1 overflow-y-auto custom-scrollbar pr-1">
                                    <p class="text-slate-700 whitespace-pre-line"><?php echo htmlspecialchars($msg); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="review-card bg-white/80 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md h-[360px] mb-5">
                            <div class="flex items-center justify-between gap-4 shrink-0">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-10 h-10 rounded-full bg-slate-200 text-[#0f2b44] flex items-center justify-center font-bold shrink-0">★</div>
                                    <div class="text-sm font-semibold text-slate-700 truncate">No reviews yet</div>
                                </div>
                                <div class="flex items-center gap-0.5 text-slate-300 whitespace-nowrap shrink-0 leading-none">
                                    <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                                </div>
                            </div>
                            <div class="mt-4 flex-1">
                                <p class="text-slate-700">Be the first to leave feedback about this webinar.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- <div class="mt-[70px]  max-w-3xl mx-auto bg-white/80 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 md:p-8 shadow-md">
                <h3 class="text-2xl font-bold text-slate-900 text-center">Leave a review</h3>
                <p class="text-slate-600 text-sm text-center mt-2">Hover the stars to rate, then submit your review.</p>
                <?php if ($reviewFlash !== ''): ?>
                <div class="mt-6 bg-emerald-50 border border-emerald-100 text-emerald-700 p-4 rounded-2xl flex items-start gap-3">
                    <i class="fas fa-check-circle text-emerald-500 mt-0.5"></i>
                    <div class="text-sm font-medium"><?php echo htmlspecialchars($reviewFlash); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($reviewError !== ''): ?>
                <div class="mt-6 bg-rose-50 border border-rose-100 text-rose-700 p-4 rounded-2xl flex items-start gap-3">
                    <i class="fas fa-exclamation-circle text-rose-500 mt-0.5"></i>
                    <div class="text-sm font-medium"><?php echo htmlspecialchars($reviewError); ?></div>
                </div>
                <?php endif; ?>
                <form action="index.php#reviews" method="post" class="mt-6 space-y-4">
                    <input type="hidden" name="feedback_submit" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" name="r_name" placeholder="Your name" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required value="<?php echo htmlspecialchars((string)($_POST['r_name'] ?? '')); ?>">
                        <div class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-white flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-slate-700">Rating</span>
                            <div class="review-rating" role="radiogroup" aria-label="Rating">
                                <input type="radio" id="r_rating_5" name="r_rating" value="5" required <?php echo (string)($_POST['r_rating'] ?? '') === '5' ? 'checked' : ''; ?>>
                                <label for="r_rating_5" aria-label="5 stars"><i class="fas fa-star"></i></label>
                                <input type="radio" id="r_rating_4" name="r_rating" value="4" <?php echo (string)($_POST['r_rating'] ?? '') === '4' ? 'checked' : ''; ?>>
                                <label for="r_rating_4" aria-label="4 stars"><i class="fas fa-star"></i></label>
                                <input type="radio" id="r_rating_3" name="r_rating" value="3" <?php echo (string)($_POST['r_rating'] ?? '') === '3' ? 'checked' : ''; ?>>
                                <label for="r_rating_3" aria-label="3 stars"><i class="fas fa-star"></i></label>
                                <input type="radio" id="r_rating_2" name="r_rating" value="2" <?php echo (string)($_POST['r_rating'] ?? '') === '2' ? 'checked' : ''; ?>>
                                <label for="r_rating_2" aria-label="2 stars"><i class="fas fa-star"></i></label>
                                <input type="radio" id="r_rating_1" name="r_rating" value="1" <?php echo (string)($_POST['r_rating'] ?? '') === '1' ? 'checked' : ''; ?>>
                                <label for="r_rating_1" aria-label="1 star"><i class="fas fa-star"></i></label>
                            </div>
                        </div>
                    </div>
                    <input type="email" name="r_email" placeholder="Email (optional)" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" value="<?php echo htmlspecialchars((string)($_POST['r_email'] ?? '')); ?>">
                    <textarea name="r_message" rows="4" placeholder="Write your review..." class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required><?php echo htmlspecialchars((string)($_POST['r_message'] ?? '')); ?></textarea>
                    <div class="flex justify-center">
                        <button type="submit" class="bg-amber-500 hover:bg-amber-400 text-[#0f2b44] font-bold px-8 py-3 rounded-full shadow-md transition">Submit review</button>
                    </div>
                </form>
            </div> -->

        <!-- ========== SECTION 4.4: SECOND CTA SECTION ========== -->
        <div class="bg-[#0f2b44] text-white rounded-3xl p-10 md:p-14 shadow-2xl mt-16 mb-20">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Reserve your virtual seat now</h2>
                <p class="text-lg text-slate-300 mb-8"><?php echo htmlspecialchars($scheduleDate); ?> · free live stream + replay</p>
                <a href="registration.php" class="inline-flex items-center gap-3 bg-amber-400 hover:bg-amber-300 text-[#0f2b44] font-bold text-xl px-10 py-5 rounded-full shadow-xl transition transform hover:scale-105">
                    <i class="fas fa-ticket-alt"></i> Register now — it's free
                </a>
                <p class="text-sm text-slate-400 mt-6 flex items-center justify-center gap-2"><i class="fas fa-check-circle text-amber-400"></i> No spam, unsubscribe anytime.</p>
            </div>
        </div>

        <div class="mt-16 bg-white border border-slate-100 rounded-3xl p-8 md:p-12 shadow-md" id="contact">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <div class="space-y-4">
                    <h2 class="text-3xl font-bold text-slate-900">Contact us</h2>
                    <p class="text-slate-600">Questions about the webinar or co‑investing with us? Reach out and our team will respond within 24h.</p>
                    <div class="space-y-3">
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-envelope text-amber-500"></i><span>Robert@mioymequities.com</span></div>
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-phone text-amber-500"></i><span>Office: 914 566 8292</span></div>
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-map-marker-alt text-amber-500"></i><span>2900 Westchester Ave Purchase, NY 10577</span></div>
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
                    <button type="submit" class="bg-[#0f2b44] hover:bg-[#1e4a7a] text-white font-bold px-6 py-3 rounded-full shadow-md transition">Send message</button>
                </form>
                </div>
            </div>
        </div>

        <!-- ========== SECTION 4.6: FOOTER ========== -->
        <div class="text-xs text-slate-500 flex flex-col sm:flex-row justify-between items-center mt-12 pt-6 border-t border-slate-100">
            <span>© 2025 Mioym Equities — Webinar landing. All rights reserved.</span>
            <span class="flex gap-4 mt-2 sm:mt-0">
                <a href="#" class="hover:text-slate-700 transition">Privacy</a>
                <a href="#" class="hover:text-slate-700 transition">Terms</a>
                <a href="#" class="hover:text-slate-700 transition">Contact</a>
            </span>
        </div>
    </main>

    <!-- ========== SECTION 5: JAVASCRIPT ========== -->
    <script>
        document.querySelectorAll('a[href="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                alert('This is a demo placeholder.');
            });0
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

    <!-- Video Modal Script -->
    <script>
        const videoModal = document.getElementById('videoModal');
        const videoContainer = document.getElementById('videoContainer');
        const mainVideo = document.getElementById('mainVideo');
        const previewVideo = document.getElementById('previewVideo');
        const caseExamplesModal = document.getElementById('caseExamplesModal');
        const caseExamplesContainer = document.getElementById('caseExamplesContainer');
        const caseExamplesImagesGrid = document.getElementById('caseExamplesImagesGrid');
        const caseExamplesImageA = document.getElementById('caseExamplesImageA');
        const caseExamplesImageB = document.getElementById('caseExamplesImageB');
        const caseExamplesCounter = document.getElementById('caseExamplesCounter');
        const contractorPdfModal = document.getElementById('contractorPdfModal');
        const contractorPdfContainer = document.getElementById('contractorPdfContainer');
        const contractorPdfFrame = document.getElementById('contractorPdfFrame');
        const contractorPdfBtn0 = document.getElementById('contractorPdfBtn0');
        const contractorPdfBtn1 = document.getElementById('contractorPdfBtn1');
        const contractorPdfUrls = [
            'files/<?php echo rawurlencode('1045 Orange Street $127,201.00 Executed Scope of Work 1-6-2025.pdf'); ?>',
            'files/<?php echo rawurlencode('General Contractor License (2025-2026).pdf'); ?>'
        ];
        let contractorPdfIndex = 0;
        const statesPdfModal = document.getElementById('statesPdfModal');
        const statesPdfContainer = document.getElementById('statesPdfContainer');
        const statesPdfFrame = document.getElementById('statesPdfFrame');
        const statesPdfUrl = 'files/<?php echo rawurlencode('States we buy in.pdf'); ?>';
        const faqsPdfModal = document.getElementById('faqsPdfModal');
        const faqsPdfContainer = document.getElementById('faqsPdfContainer');
        const faqsPdfFrame = document.getElementById('faqsPdfFrame');
        const faqsPdfUrl = 'files/<?php echo rawurlencode('MIOYM FAQs.pdf'); ?>';
        const galleries = {
            caseExamples: {
                layout: 'single',
                images: [
                    'img/Screenshot%202026-03-30%20023657.png',
                    'img/Screenshot%202026-03-30%20023729.png',
                    'img/Screenshot%202026-03-30%20023742.png'
                ]
            },
            affordableHomes: {
                layout: 'pair',
                images: [
                    'img/1.png',
                    'img/2.png',
                    'img/3.png',
                    'img/4.png',
                    'img/5.png',
                    'img/6.png'
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
            if (videoModal && !videoModal.classList.contains('hidden')) closeVideoModal();
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
        }

        function openCaseExamplesModal(startIndex = 0) {
            openGalleryModal('caseExamples', startIndex);
        }

        function openAffordableHomesModal(startIndex = 0) {
            openGalleryModal('affordableHomes', startIndex);
        }

        function setContractorPdf(index) {
            if (!contractorPdfFrame) return;
            const next = Number(index) || 0;
            contractorPdfIndex = Math.min(Math.max(next, 0), contractorPdfUrls.length - 1);
            contractorPdfFrame.src = contractorPdfUrls[contractorPdfIndex] || '';

            const active = 'px-3 py-2 rounded-full text-sm font-semibold bg-white text-[#0f2b44] hover:bg-amber-100 transition';
            const inactive = 'px-3 py-2 rounded-full text-sm font-semibold bg-white/10 text-white hover:bg-white/20 transition';
            contractorPdfBtn0?.setAttribute('class', contractorPdfIndex === 0 ? active : inactive);
            contractorPdfBtn1?.setAttribute('class', contractorPdfIndex === 1 ? active : inactive);
        }

        function openContractorPdfModal(index = 0) {
            if (!contractorPdfModal || !contractorPdfFrame) return;
            if (videoModal && !videoModal.classList.contains('hidden')) closeVideoModal();
            if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) closeCaseExamplesModal();
            setContractorPdf(index);
            contractorPdfModal.classList.remove('hidden');
            setTimeout(() => {
                contractorPdfModal.classList.remove('opacity-0');
                contractorPdfContainer?.classList.remove('scale-95');
                contractorPdfContainer?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }

        function closeContractorPdfModal() {
            if (!contractorPdfModal) return;
            contractorPdfModal.classList.add('opacity-0');
            contractorPdfContainer?.classList.remove('scale-100');
            contractorPdfContainer?.classList.add('scale-95');
            setTimeout(() => {
                contractorPdfModal.classList.add('hidden');
                if (contractorPdfFrame) contractorPdfFrame.src = '';
            }, 300);
            if (
                (!videoModal || videoModal.classList.contains('hidden')) &&
                (!caseExamplesModal || caseExamplesModal.classList.contains('hidden'))
            ) {
                document.body.style.overflow = '';
            }
        }

        function openStatesPdfModal() {
            if (!statesPdfModal || !statesPdfFrame) return;
            if (videoModal && !videoModal.classList.contains('hidden')) closeVideoModal();
            if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) closeCaseExamplesModal();
            if (contractorPdfModal && !contractorPdfModal.classList.contains('hidden')) closeContractorPdfModal();
            statesPdfFrame.src = statesPdfUrl;
            statesPdfModal.classList.remove('hidden');
            setTimeout(() => {
                statesPdfModal.classList.remove('opacity-0');
                statesPdfContainer?.classList.remove('scale-95');
                statesPdfContainer?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }

        function closeStatesPdfModal() {
            if (!statesPdfModal) return;
            statesPdfModal.classList.add('opacity-0');
            statesPdfContainer?.classList.remove('scale-100');
            statesPdfContainer?.classList.add('scale-95');
            setTimeout(() => {
                statesPdfModal.classList.add('hidden');
                if (statesPdfFrame) statesPdfFrame.src = '';
            }, 300);
            if (
                (!videoModal || videoModal.classList.contains('hidden')) &&
                (!caseExamplesModal || caseExamplesModal.classList.contains('hidden')) &&
                (!contractorPdfModal || contractorPdfModal.classList.contains('hidden')) &&
                (!faqsPdfModal || faqsPdfModal.classList.contains('hidden'))
            ) {
                document.body.style.overflow = '';
            }
        }

        function openFaqsPdfModal() {
            if (!faqsPdfModal || !faqsPdfFrame) return;
            if (videoModal && !videoModal.classList.contains('hidden')) closeVideoModal();
            if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) closeCaseExamplesModal();
            if (contractorPdfModal && !contractorPdfModal.classList.contains('hidden')) closeContractorPdfModal();
            if (statesPdfModal && !statesPdfModal.classList.contains('hidden')) closeStatesPdfModal();
            faqsPdfFrame.src = faqsPdfUrl;
            faqsPdfModal.classList.remove('hidden');
            setTimeout(() => {
                faqsPdfModal.classList.remove('opacity-0');
                faqsPdfContainer?.classList.remove('scale-95');
                faqsPdfContainer?.classList.add('scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }

        function closeFaqsPdfModal() {
            if (!faqsPdfModal) return;
            faqsPdfModal.classList.add('opacity-0');
            faqsPdfContainer?.classList.remove('scale-100');
            faqsPdfContainer?.classList.add('scale-95');
            setTimeout(() => {
                faqsPdfModal.classList.add('hidden');
                if (faqsPdfFrame) faqsPdfFrame.src = '';
            }, 300);
            if (
                (!videoModal || videoModal.classList.contains('hidden')) &&
                (!caseExamplesModal || caseExamplesModal.classList.contains('hidden')) &&
                (!contractorPdfModal || contractorPdfModal.classList.contains('hidden')) &&
                (!statesPdfModal || statesPdfModal.classList.contains('hidden'))
            ) {
                document.body.style.overflow = '';
            }
        }

        function closeCaseExamplesModal() {
            if (!caseExamplesModal) return;
            caseExamplesModal.classList.add('opacity-0');
            caseExamplesContainer?.classList.remove('scale-100');
            caseExamplesContainer?.classList.add('scale-95');
            setTimeout(() => {
                caseExamplesModal.classList.add('hidden');
            }, 300);
            if (!videoModal || videoModal.classList.contains('hidden')) {
                document.body.style.overflow = '';
            }
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

        function openVideoModal() {
            if (!videoModal) return;
            
            // Show modal
            videoModal.classList.remove('hidden');
            
            // Trigger animation
            setTimeout(() => {
                videoModal.classList.remove('opacity-0');
                videoContainer.classList.remove('scale-95');
                videoContainer.classList.add('scale-100');
            }, 10);
            
            // Play main video if exists
            if (mainVideo) {
                mainVideo.play().catch(e => console.log("Autoplay prevented:", e));
            }
            
            // Pause preview video
            if (previewVideo) {
                previewVideo.pause();
            }
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }

        function closeVideoModal() {
            if (!videoModal) return;
            
            // Reverse animation
            videoModal.classList.add('opacity-0');
            videoContainer.classList.remove('scale-100');
            videoContainer.classList.add('scale-95');
            
            // Hide modal after animation
            setTimeout(() => {
                videoModal.classList.add('hidden');
            }, 300);
            
            // Pause main video if exists
            if (mainVideo) {
                mainVideo.pause();
            }
            
            // Play preview video again
            if (previewVideo) {
                previewVideo.play().catch(e => console.log("Autoplay prevented:", e));
            }
            
            // Restore body scroll
            document.body.style.overflow = '';
        }

        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (faqsPdfModal && !faqsPdfModal.classList.contains('hidden')) {
                    closeFaqsPdfModal();
                    return;
                }
                if (statesPdfModal && !statesPdfModal.classList.contains('hidden')) {
                    closeStatesPdfModal();
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
                if (videoModal && !videoModal.classList.contains('hidden')) {
                    closeVideoModal();
                }
                return;
            }
            if (caseExamplesModal && !caseExamplesModal.classList.contains('hidden')) {
                if (e.key === 'ArrowLeft') caseExamplesPrev();
                if (e.key === 'ArrowRight') caseExamplesNext();
            }
        });

        // Close on outside click
        if (videoModal) {
            videoModal.addEventListener('click', (e) => {
                if (e.target === videoModal) {
                    closeVideoModal();
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

        if (statesPdfModal) {
            statesPdfModal.addEventListener('click', (e) => {
                if (e.target === statesPdfModal) {
                    closeStatesPdfModal();
                }
            });
        }

        if (faqsPdfModal) {
            faqsPdfModal.addEventListener('click', (e) => {
                if (e.target === faqsPdfModal) {
                    closeFaqsPdfModal();
                }
            });
        }
    </script>
</body>
</html>
