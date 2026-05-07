<?php
/**
 * Cron Job to process the email queue and send up to 300 emails per day.
 * It uses the Brevo API and respects the daily limit.
 * You should run this script via a cron job (e.g., at 1:00 AM every day).
 * Command: php /path/to/cron_send_emails.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Prevent accessing this script from a web browser unless a secret key is provided
$secret = get_cron_secret('send_emails');
if (php_sapi_name() !== 'cli' && (!isset($_GET['secret']) || $_GET['secret'] !== $secret)) {
    die("Forbidden");
}

echo "Starting email queue processor...\n";

$dailyLimit = 300;

// Check how many emails have already been sent today
try {
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
    $sentToday = $stmtCount->fetchColumn();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}

echo "Emails already sent today: $sentToday\n";

if ($sentToday >= $dailyLimit) {
    echo "Daily limit of $dailyLimit reached. Exiting.\n";
    exit;
}

$remainingQuota = $dailyLimit - $sentToday;
echo "Remaining quota for today: $remainingQuota\n";

// Fetch pending emails
try {
    $stmt = $pdo->prepare("SELECT id, to_email, to_name, subject, html_content FROM email_queue WHERE status = 'pending' ORDER BY id ASC LIMIT ?");
    $stmt->bindValue(1, $remainingQuota, PDO::PARAM_INT);
    $stmt->execute();
    $pendingEmails = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}

if (empty($pendingEmails)) {
    echo "No pending emails in the queue.\n";
    exit;
}

$countToSend = count($pendingEmails);
echo "Processing $countToSend pending emails...\n";

// We will use send_brevo_batch_email to send them all in one API request (or chunked)
$chunkSize = 100; // Safe chunk size for Brevo API
$chunks = array_chunk($pendingEmails, $chunkSize);

$totalSent = 0;
$totalFailed = 0;

foreach ($chunks as $chunk) {
    $messageVersions = [];
    $updateIds = [];

    foreach ($chunk as $emailData) {
        $messageVersions[] = [
            'to' => [
                ['email' => $emailData['to_email'], 'name' => $emailData['to_name'] ?: $emailData['to_email']]
            ],
            'subject' => $emailData['subject'],
            'htmlContent' => $emailData['html_content']
        ];
        $updateIds[] = $emailData['id'];
    }

    if (!empty($messageVersions)) {
        // Since the batch API requires a base HTML content but we provide full HTML in each version,
        // we use a generic placeholder body.
        $res = send_brevo_batch_email($chunk[0]['subject'], '<html><body>{{params.body}}</body></html>', $messageVersions);
        
        $placeholders = implode(',', array_fill(0, count($updateIds), '?'));
        
        if ($res['success']) {
            $totalSent += count($updateIds);
            $stmtUpdate = $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
            $stmtUpdate->execute($updateIds);
        } else {
            $totalFailed += count($updateIds);
            $errorMsg = is_string($res['error']) ? $res['error'] : json_encode($res['error']);
            $stmtUpdate = $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = ? WHERE id IN ($placeholders)");
            
            // Prepare parameters for execute
            $params = [$errorMsg];
            foreach($updateIds as $id) { $params[] = $id; }
            $stmtUpdate->execute($params);
        }
    }
}

echo "Processing complete.\n";
echo "Successfully sent: $totalSent\n";
echo "Failed: $totalFailed\n";
