<?php
/**
 * cron_reminders.php
 * Run this file periodically (e.g., every 1 minute) via a cron job to send automated reminders.
 * Usage: php /path/to/cron_reminders.php
 * Test Mode: php cron_reminders.php "force_id=50"
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Setup logging mechanism
ob_start();
register_shutdown_function(function() {
    $logOutput = ob_get_clean();
    if (trim($logOutput) !== '') {
        echo $logOutput; // Print output for CLI / manual testing
        
        $logFile = __DIR__ . '/cron_reminders.log';
        
        // Rotate log file if it exceeds 5MB to save disk space
        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            rename($logFile, $logFile . '.old');
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "==================================================\n";
        $logEntry .= "RUN TIME: {$timestamp}\n";
        $logEntry .= "==================================================\n";
        $logEntry .= trim($logOutput) . "\n\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
});

if (php_sapi_name() !== 'cli') {
    $secret = get_cron_secret('reminders');
    if (!isset($_GET['token']) || $_GET['token'] !== $secret) { 
        die('Unauthorized access.'); 
    }
}

echo "Starting reminder cron job...\n";

// Check for command line arguments (for CLI testing)
$force_id = isset($_GET['force_id']) ? (int)$_GET['force_id'] : 0;
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    parse_str($argv[1], $args);
    if (isset($args['force_id'])) {
        $force_id = (int)$args['force_id'];
        echo "TEST MODE: Forcing ID {$force_id}\n";
    }
}

// ─────────────────────────────────────────────────────
// DEBUG: Show all registrants in database
// ─────────────────────────────────────────────────────
echo "\n=== DEBUG: Checking Database Records ===\n";
$debugQuery = "
    SELECT r.id, r.email, r.reminded_1w, r.reminded_1d, r.reminded_1h,
           w.status, w.title, w.`schedule_date&time`
    FROM registrants_tbl r
    JOIN webinar_tbl w ON r.webinar_id = w.webinar_id
    ORDER BY r.id DESC
    LIMIT 10
";
$debugStmt = $pdo->query($debugQuery);
$foundRecords = false;
while ($row = $debugStmt->fetch(PDO::FETCH_ASSOC)) {
    $foundRecords = true;
    $now = new DateTime('now');
    $eventTime = new DateTime($row['schedule_date&time']);
    $diff = round(($eventTime->getTimestamp() - $now->getTimestamp()) / 60, 2);
    echo "ID={$row['id']} | Email={$row['email']} | 1w={$row['reminded_1w']} 1d={$row['reminded_1d']} 1h={$row['reminded_1h']} | Status={$row['status']} | Diff={$diff}min | Title={$row['title']}\n";
}
if (!$foundRecords) {
    echo "No records found in registrants_tbl!\n";
}
echo "=======================================\n\n";

// ─────────────────────────────────────────────────────
// FIX #1: Query checks all active reminder columns (1w, 1d, 1h)
// ─────────────────────────────────────────────────────
$where_clause = "LOWER(COALESCE(w.status, '')) IN ('active', 'upcoming', 'live') AND (r.reminded_1w = 0 OR r.reminded_1d = 0 OR r.reminded_1h = 0)";
if ($force_id > 0) {
    $where_clause = "r.id = " . (int)$force_id;
    echo "FORCE MODE: Processing only ID {$force_id}\n";
}

$query = "
    SELECT r.*, w.title as webinar_title, w.`schedule_date&time`, w.webinar_link, w.duration, w.description, w.hostname, w.host_description, w.status as webinar_status
    FROM registrants_tbl r
    JOIN webinar_tbl w ON r.webinar_id = w.webinar_id
    WHERE {$where_clause}
";
$stmt = $pdo->query($query);
$registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($registrants)) {
    echo "No pending reminders to process.\n";
    echo "Hint: All reminders may already be sent, or no active webinars exist.\n";
    exit;
}

echo "Found " . count($registrants) . " registrant(s) to process.\n\n";

$now = new DateTime('now');

foreach ($registrants as $r) {
    try {
        $eventTime = new DateTime($r['schedule_date&time']);
    } catch (Exception $e) {
        echo "[ERROR] Invalid date for ID={$r['id']}\n";
        continue;
    }
    
    $diffMinutes = ($eventTime->getTimestamp() - $now->getTimestamp()) / 60;
    
    echo "[DEBUG] ID={$r['id']} Email={$r['email']} diff=" . round($diffMinutes, 2) . "min 1w={$r['reminded_1w']} 1d={$r['reminded_1d']} 1h={$r['reminded_1h']}\n";
    
    $reminderToSend = null;
    $reminderType = '';
    $reminderText = '';
    $columnToUpdate = '';
    $skipColumns = [];

    if ($force_id > 0) {
        // ═══════════════════════════════════════════
        // MANUAL OVERRIDE MODE (force_id)
        // FIX #2: Find the NEXT unsent reminder in sequence
        // regardless of timing — manual trigger = send now
        // ═══════════════════════════════════════════
        
        if ($r['reminded_1w'] == 0) {
            // 1w is next in sequence — send it
            $reminderToSend = '1w';
            $reminderType = '1 Week Away';
            $reminderText = 'Just a heads up! The webinar you registered for is exactly one week away.';
            $columnToUpdate = 'reminded_1w';
        }
        elseif ($r['reminded_1d'] == 0) {
            // 1d is next — send it, mark 1w as skipped if needed
            $reminderToSend = '1d';
            $reminderType = 'Starting Tomorrow';
            $reminderText = "Don't forget! The webinar is scheduled for tomorrow. Make sure you have it on your calendar.";
            $columnToUpdate = 'reminded_1d';
            // FIX #3: Only skip PRIOR reminders, not future ones
            if ($r['reminded_1w'] == 0) $skipColumns[] = 'reminded_1w';
        }
        elseif ($r['reminded_1h'] == 0) {
            // 1h is next — send it, mark prior as skipped
            $reminderToSend = '1h';
            $reminderType = 'Starting in 1 Hour';
            $reminderText = 'Get ready! The webinar is starting in just 1 hour.';
            $columnToUpdate = 'reminded_1h';
            // Only skip prior reminders (1w, 1d)
            if ($r['reminded_1w'] == 0) $skipColumns[] = 'reminded_1w';
            if ($r['reminded_1d'] == 0) $skipColumns[] = 'reminded_1d';
        }
        
    } else {
        // ============================================
        // AUTOMATED CRON MODE (strict stage windows)
        // ============================================

        if ($diffMinutes > 10080) {
            // More than 1 week away: not time yet for any reminder.
            echo "[WAIT] Too early for reminders for {$r['email']} (diff=" . round($diffMinutes, 2) . "min)\n";
            
        } elseif ($diffMinutes > 1440) {
            // 1-week window: > 1440 min (1 day) before event
            if ($r['reminded_1w'] == 0) {
                $reminderToSend = '1w';
                $reminderType = '1 Week Away';
                $reminderText = 'Just a heads up! The webinar you registered for is exactly one week away.';
                $columnToUpdate = 'reminded_1w';
            }
            
        } elseif ($diffMinutes > 60) {
            // 1-day window: > 60 min before event
            if ($r['reminded_1d'] == 0) {
                $reminderToSend = '1d';
                $reminderType = 'Starting Tomorrow';
                $reminderText = "Don't forget! The webinar is scheduled for tomorrow. Make sure you have it on your calendar.";
                $columnToUpdate = 'reminded_1d';
            }
            // Mark 1w as skipped if not sent yet
            if ($r['reminded_1w'] == 0) {
                $skipColumns[] = 'reminded_1w';
            }
            
        } elseif ($diffMinutes > 1) {
            // 1-hour window: > 1 min and <= 60 min before event
            if ($r['reminded_1h'] == 0) {
                $reminderToSend = '1h';
                $reminderType = 'Starting in 1 Hour';
                $reminderText = 'Get ready! The webinar is starting in just 1 hour.';
                $columnToUpdate = 'reminded_1h';
            }
            // Mark prior reminders as skipped if not sent
            if ($r['reminded_1w'] == 0) $skipColumns[] = 'reminded_1w';
            if ($r['reminded_1d'] == 0) $skipColumns[] = 'reminded_1d';
            
        } elseif ($diffMinutes >= -15) {
            // Final grace window (<= 1 min before start up to 15 min after start):
            // 1m reminder has been removed, so mark any unsent prior reminders as skipped.
            if ($r['reminded_1w'] == 0) $skipColumns[] = 'reminded_1w';
            if ($r['reminded_1d'] == 0) $skipColumns[] = 'reminded_1d';
            if ($r['reminded_1h'] == 0) $skipColumns[] = 'reminded_1h';
            echo "[DONE] Webinar start window reached for {$r['email']} - no 1m reminder configured\n";
            
        } else {
            // Webinar has passed beyond the 15-minute grace period
            echo "[EXPIRED] Webinar past 15min grace period for {$r['email']} (diff=" . round($diffMinutes, 2) . "min) - marking all as sent\n";
            
            // Mark ALL unsent reminders as skipped
            if ($r['reminded_1h'] == 0) $skipColumns[] = 'reminded_1h';
            if ($r['reminded_1d'] == 0) $skipColumns[] = 'reminded_1d';
            if ($r['reminded_1w'] == 0) $skipColumns[] = 'reminded_1w';
        }
    }

    if ($reminderToSend) {
        echo "[SEND] Preparing {$reminderToSend} reminder for {$r['email']}...\n";
        
        $timestamp = date('Y-m-d h:i A');
        $scheduleStr = $eventTime->format('l, F j, Y') . ' · ' . strtolower($eventTime->format('g:i a'));
        $duration = $r['duration'] ?? get_setting('webinar_duration', '60-minute');
        $subject = "Reminder: " . ($r['webinar_title'] ?: 'Our Webinar') . " (" . $reminderType . ")";
        
        $templateFile = 'reminder-email.html';
        $templatePath = __DIR__ . '/' . $templateFile;
        
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('[Name]', htmlspecialchars($r['fullname']), $body);
            $body = str_replace('[WebinarTitle]', htmlspecialchars($r['webinar_title'] ?: 'Our Webinar'), $body);
            $body = str_replace('[WebinarDate]', htmlspecialchars($scheduleStr), $body);
            $body = str_replace('[WebinarDuration]', htmlspecialchars($duration), $body);
            $body = str_replace('[Link]', htmlspecialchars($r['webinar_link'] ?: '#'), $body);
            
            $body = str_replace('[ReminderType]', htmlspecialchars($reminderType), $body);
            $body = str_replace('[ReminderText]', htmlspecialchars($reminderText), $body);
            
            $isUpcoming = ($diffMinutes > 0);
            
            $calendarLinksHtml = '';
            if ($isUpcoming) {
                // Uncomment for production
                // $calendarBase = "https://mioymequities.com/calendar.php?id=" . urlencode($r['webinar_id']);
                $calendarBase = "http://localhost/Mioym-Equities-Funnel/Mioym/calendar.php?id=" . urlencode($r['webinar_id']);
                
                $calendarLinksHtml = '
                <div style="margin-top:20px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#475569;">
                    <strong style="display:block;margin-bottom:8px;">Add to your Calendar:</strong>
                    <a href="' . $calendarBase . '&type=google" style="color:#2563eb;text-decoration:none;margin:0 8px;font-weight:600;">Google</a> &bull;
                    <a href="' . $calendarBase . '&type=outlook" style="color:#2563eb;text-decoration:none;margin:0 8px;font-weight:600;">Outlook</a> &bull;
                    <a href="' . $calendarBase . '&type=yahoo" style="color:#2563eb;text-decoration:none;margin:0 8px;font-weight:600;">Yahoo</a> &bull;
                    <a href="' . $calendarBase . '&type=ics" style="color:#2563eb;text-decoration:none;margin:0 8px;font-weight:600;">Apple (.ics)</a>
                </div>';
            }
            $body = str_replace('[CalendarLinks]', $calendarLinksHtml, $body);
            
            $body = str_replace('[CtaText]', $isUpcoming ? "Join Webinar Now" : "Join Webinar Now", $body);
            $body = str_replace('[CtaNote]', $isUpcoming ? "(Keep this email handy and click the button when it's time to join.)" : "(The session is live! Click the button to join now.)", $body);
            
            $body = str_replace('[CompanyAddress]', htmlspecialchars(get_setting('office_address', '2900 Westchester Ave Purchase, NY 10577')), $body);
            $body = str_replace('[UnsubscribeLink]', '#', $body);
            $body = str_replace('[PrivacyPolicy]', '#', $body);
            
            $res = send_brevo_api_email($r['email'], $r['fullname'], $subject, $body);
            
            if ($res['success']) {
                echo "[OK] Successfully sent {$reminderToSend} reminder to {$r['email']}\n";
                $updateStmt = $pdo->prepare("UPDATE registrants_tbl SET {$columnToUpdate} = 1 WHERE id = ?");
                $updateStmt->execute([$r['id']]);
                
                if (!empty($skipColumns)) {
                    foreach ($skipColumns as $col) {
                        $skipStmt = $pdo->prepare("UPDATE registrants_tbl SET {$col} = 1 WHERE id = ?");
                        $skipStmt->execute([$r['id']]);
                    }
                    echo "[SKIP] Marked as sent: " . implode(', ', $skipColumns) . " for ID={$r['id']}\n";
                }
            } else {
                echo "[FAIL] Failed to send {$reminderToSend} reminder to {$r['email']}: " . print_r($res['error'], true) . "\n";
            }
        } else {
            echo "[ERROR] Template file missing: {$templateFile}\n";
        }
    } else {
        // No email to send, but mark skipped columns
        if (!empty($skipColumns)) {
            foreach ($skipColumns as $col) {
                $skipStmt = $pdo->prepare("UPDATE registrants_tbl SET {$col} = 1 WHERE id = ?");
                $skipStmt->execute([$r['id']]);
            }
            echo "[SKIP] Silently marked as sent: " . implode(', ', $skipColumns) . " for ID={$r['id']}\n";
        }
    }
    
    echo "\n";
}

echo "Reminder cron job finished.\n";
