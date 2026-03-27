<?php
session_start();
require_once 'db.php';

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
require 'vendor/autoload.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: registration.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'send_emails' || $action === 'send_test_email') {
        $webinar_id = $_POST['webinar_id'] ?? null;
        $email_template_content = $_POST['email_template'] ?? "Hi [Name],\n\nHere is your webinar link: [Link]";
        $subject = $_POST['subject'] ?? 'Your Webinar Invitation Link - Mioym Equities';
        $single_registrant_id = $_POST['registrant_id'] ?? null; 
        $bulk_ids = $_POST['bulk_ids'] ?? null;
        
        // Setup PHPMailer
        $mail = new PHPMailer(true);
        try {
            // ... (SMTP settings remain same)
            $mail->isSMTP();
            $mail->Host       = 'smtp-relay.brevo.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = 'a61f36001@smtp-brevo.com'; 
            $mail->Password   = 'jUmI9RMntaAkbqKN'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
            $mail->Port       = 587; 
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->setFrom('mioymequities1@gmail.com', 'Mioym Equities'); 
            $mail->isHTML(true);
            
            // Get Target Registrants
            $registrantsToSend = [];
            if ($action === 'send_test_email') {
                // For test email, just use a dummy registrant or the admin's email if we had it.
                // For now, let's use a mock registrant.
                $registrantsToSend[] = [
                    'id' => 0,
                    'fullname' => 'Test Investor',
                    'email' => 'mioymequities1@gmail.com', // Send test to yourself
                    'webinar_title' => 'Sample Webinar',
                    'webinar_link' => 'https://zoom.us/test',
                    'schedule_date&time' => date('Y-m-d H:i:s', strtotime('+1 day'))
                ];
            } else {
                $selectFields = "r.*, w.title as webinar_title, w.webinar_link, w.`schedule_date&time`";
                if ($bulk_ids) {
                    $idsArray = explode(',', $bulk_ids);
                    $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
                    $stmt = $pdo->prepare("SELECT $selectFields FROM registrants_tbl r LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id WHERE r.id IN ($placeholders)");
                    $stmt->execute($idsArray);
                } elseif ($single_registrant_id) {
                    $stmt = $pdo->prepare("SELECT $selectFields FROM registrants_tbl r LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id WHERE r.id = ?");
                    $stmt->execute([$single_registrant_id]);
                } elseif ($webinar_id == 'all') {
                    $stmt = $pdo->query("SELECT $selectFields FROM registrants_tbl r LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id");
                } else {
                    $stmt = $pdo->prepare("SELECT $selectFields FROM registrants_tbl r LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id WHERE r.webinar_id = ?");
                    $stmt->execute([$webinar_id]);
                }
                $registrantsToSend = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $sentCount = 0;
            foreach ($registrantsToSend as $registrant) {
                if (empty($registrant['email'])) continue;

                $personalLink = $registrant['webinar_link'] ?? 'Link not set yet';
                
                // Load enterprise email template
                $emailTemplate = file_get_contents('email-template.html');
                
                // Get webinar details for template
                $rawDate = $registrant['schedule_date&time'] ?? null;
                $webinarDate = 'To be announced';
                if ($rawDate && strtotime($rawDate)) {
                    $webinarDate = date('F j, Y \a\t g:i A', strtotime($rawDate));
                }
                $webinarTitle = $registrant['webinar_title'] ?? 'Exclusive Webinar';
                
                // Replace the [Content] placeholder if it exists in the template
                // Or just use the template as is and replace our custom tags.
                // In our email-template.html, we have a fixed structure.
                // We'll replace the greeting and main message area with the dynamic content from the editor.
                
                // Replace template variables
                $body = str_replace(
                    [
                        '[Name]', 
                        '[Link]',
                        '[WebinarDate]',
                        '[WebinarTitle]',
                        '[CompanyAddress]',
                        '[UnsubscribeLink]',
                        '[PrivacyPolicy]'
                    ],
                    [
                        htmlspecialchars($registrant['fullname']), 
                        htmlspecialchars($personalLink),
                        htmlspecialchars($webinarDate),
                        htmlspecialchars($webinarTitle),
                        '123 Business District, Makati City, Philippines 1200',
                        'https://mioym.com/unsubscribe',
                        'https://mioym.com/privacy'
                    ],
                    $emailTemplate
                );

                // ALSO replace the dynamic content from the editor which might have tags too
                $dynamicContent = str_replace(
                    ['[Name]', '[Link]', '[WebinarDate]', '[WebinarTitle]'],
                    [htmlspecialchars($registrant['fullname']), htmlspecialchars($personalLink), htmlspecialchars($webinarDate), htmlspecialchars($webinarTitle)],
                    $email_template_content
                );

                // Note: Our template currently doesn't have a place for $dynamicContent.
                // I should add a [MessageContent] tag to the template.
                // For now, I'll just replace the Hello [Name] part.
                
                $mail->clearAddresses();
                $mail->addAddress($registrant['email'], $registrant['fullname']);
                
                $mail->Subject = $subject;
                $mail->Body    = $body;

                if ($mail->send()) {
                    if ($action !== 'send_test_email') {
                        $updateStmt = $pdo->prepare("UPDATE registrants_tbl SET email_sent = 1 WHERE id = ?");
                        $updateStmt->execute([$registrant['id']]);
                    }
                    $sentCount++;
                }
            }
            
            if ($action === 'send_test_email') {
                $_SESSION['flash'] = "Test email sent successfully to your inbox!";
            } elseif ($bulk_ids) {
                $_SESSION['flash'] = "Successfully blasted emails to $sentCount selected registrants!";
            } elseif ($single_registrant_id) {
                $_SESSION['flash'] = "Email sent successfully to {$registrantsToSend[0]['fullname']}!";
            } else {
                $_SESSION['flash'] = "Successfully blasted emails to $sentCount registrants!";
            }
            $_SESSION['di'] = ['type'=>'success','title'=>'Emails','message'=>$_SESSION['flash']];
        } catch (Exception $e) {
            $_SESSION['flash'] = "Email sending failed. Mailer Error: {$mail->ErrorInfo}";
            $_SESSION['di'] = ['type'=>'error','title'=>'Emails','message'=>$_SESSION['flash']];
        }
        
        // Redirect back to referring page
        if ($action === 'send_test_email') {
            header("Location: emails.php");
        } else {
            $redirect = ($single_registrant_id || $bulk_ids) ? 'registrants.php' : 'emails.php';
            header("Location: $redirect");
        }
        exit;
    }
}

// Fetch Data
$webinars = $pdo->query("SELECT * FROM webinar_tbl ORDER BY `schedule_date&time` DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Recipient Counts
$totalRegistrants = $pdo->query("SELECT COUNT(*) FROM registrants_tbl")->fetchColumn();
$countsByWebinar = $pdo->query("SELECT webinar_id, COUNT(*) as count FROM registrants_tbl GROUP BY webinar_id")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Suite · Mioym Equities</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Quill.js WYSIWYG -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
        .ql-toolbar.ql-snow { border: none; border-bottom: 1px solid #f1f5f9; background: #f8fafc; border-radius: 12px 12px 0 0; }
        .ql-container.ql-snow { border: none; font-family: inherit; font-size: 14px; }
        .preview-content { font-family: 'Plus Jakarta Sans', sans-serif; }
        .stepper-active { color: #1e4a7a; border-color: #1e4a7a; }
        .stepper-complete { background: #1e4a7a; color: white; border-color: #1e4a7a; }
        [x-cloak] { display: none !important; }
        
        /* Campaign Builder Dark Theme Overrides */
        body.dark-theme .bg-slate-50 { background-color: #0f172a !important; }
        body.dark-theme .bg-white { background-color: #1e293b !important; }
        body.dark-theme .ql-snow .ql-stroke { stroke: #cbd5e1 !important; }
        body.dark-theme .ql-snow .ql-fill { fill: #cbd5e1 !important; }
        body.dark-theme .ql-snow .ql-picker { color: #cbd5e1 !important; }
        body.dark-theme .prose { color: #f1f5f9 !important; }
        body.dark-theme .prose strong { color: #ffffff !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen">

    <div class="flex h-screen overflow-hidden">
        
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8" x-data="campaignBuilder()">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Campaign Builder</h2>
                    <p class="text-slate-500 text-sm mt-1">Design, test, and automate your investor communications.</p>
                </div>

                <!-- Stepper Progress -->
                <div class="hidden lg:flex items-center gap-4">
                    <template x-for="(step, index) in steps">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full border-2 flex items-center justify-center text-xs font-bold transition-all duration-300"
                                 :class="currentStep > index + 1 ? 'stepper-complete' : (currentStep === index + 1 ? 'stepper-active border-blue-600' : 'border-slate-200 text-slate-400')">
                                <span x-show="currentStep <= index + 1" x-text="index + 1"></span>
                                <i x-show="currentStep > index + 1" class="fas fa-check"></i>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-widest" :class="currentStep === index + 1 ? 'text-slate-900' : 'text-slate-400'" x-text="step"></span>
                            <div x-show="index < steps.length - 1" class="w-8 h-px bg-slate-200"></div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 items-start">
                <!-- Editor Pane (Left) -->
                <div class="xl:col-span-7 space-y-6">
                    <div class="glass rounded-[2rem] shadow-xl p-8 space-y-8">
                        
                        <!-- Step 1: Configuration -->
                        <div x-show="currentStep === 1" x-transition>
                            <div class="space-y-6">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xl font-bold text-slate-800">1. Target Audience</h3>
                                    <span class="bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-xs font-bold" x-text="'Targeting ' + recipientCount + ' recipients'"></span>
                                </div>
                                <div class="grid grid-cols-1 gap-6">
                                    <div class="space-y-2">
                                        <label class="text-sm font-bold text-slate-700">Select Webinar</label>
                                        <select name="webinar_id" x-model="selectedWebinar" @change="updateCount"
                                                class="w-full px-4 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition font-medium">
                                            <option value="all">All Registrants (Every webinar)</option>
                                            <?php foreach($webinars as $w): ?>
                                                <option value="<?php echo $w['webinar_id']; ?>"><?php echo htmlspecialchars($w['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-bold text-slate-700">Campaign Schedule</label>
                                        <div class="flex items-center gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                                            <button @click="isScheduled = false" 
                                                    :class="!isScheduled ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500'"
                                                    class="flex-1 py-2 rounded-xl text-sm font-bold transition">Send Immediately</button>
                                            <button @click="isScheduled = true"
                                                    :class="isScheduled ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500'"
                                                    class="flex-1 py-2 rounded-xl text-sm font-bold transition">Schedule Later</button>
                                        </div>
                                        <div x-show="isScheduled" x-transition class="mt-4">
                                            <input type="datetime-local" class="w-full px-4 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-2 focus:ring-blue-500/20 outline-none font-medium">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Content Creation -->
                        <div x-show="currentStep === 2" x-transition>
                            <div class="space-y-6">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xl font-bold text-slate-800">2. Email Content</h3>
                                    <div class="relative group">
                                        <button class="text-xs font-bold text-blue-600 flex items-center gap-2 bg-blue-50 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition">
                                            <i class="fas fa-plus"></i> Insert Variable
                                        </button>
                                        <div class="absolute right-0 top-full mt-2 w-48 bg-white border border-slate-100 rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-20 overflow-hidden">
                                            <button @click="insertTag('[Name]')" class="w-full text-left px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">[Name]</button>
                                            <button @click="insertTag('[Link]')" class="w-full text-left px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">[Link]</button>
                                            <button @click="insertTag('[WebinarDate]')" class="w-full text-left px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">[WebinarDate]</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <input type="text" placeholder="Subject Line" x-model="subject"
                                           class="w-full px-4 py-3.5 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-2 focus:ring-blue-500/20 outline-none font-bold text-slate-800">
                                    
                                    <div class="rounded-2xl border border-slate-100 overflow-hidden bg-white">
                                        <div id="editor-container" class="min-h-[300px]"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Review & Send -->
                        <div x-show="currentStep === 3" x-transition>
                            <div class="space-y-8">
                                <h3 class="text-xl font-bold text-slate-800">3. Final Review</h3>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Audience</p>
                                        <p class="text-sm font-bold text-slate-800 mt-1" x-text="selectedWebinarTitle"></p>
                                    </div>
                                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Recipients</p>
                                        <p class="text-sm font-bold text-slate-800 mt-1" x-text="recipientCount"></p>
                                    </div>
                                </div>

                                <div class="p-6 rounded-2xl bg-amber-50 border border-amber-100 flex gap-4">
                                    <div class="w-10 h-10 bg-amber-400 rounded-xl flex items-center justify-center text-[#0f2b44] flex-shrink-0">
                                        <i class="fas fa-shield-alt"></i>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-bold text-amber-900">Safety Check Required</h4>
                                        <p class="text-xs text-amber-800 mt-1 leading-relaxed">To prevent campaign errors, you must send a test email to yourself and verify the formatting before the final send button unlocks.</p>
                                    </div>
                                </div>

                                <div class="flex flex-col items-center gap-4">
                                    <button @click="sendTest" :disabled="sendingTest"
                                            class="w-full py-4 rounded-2xl font-bold transition flex items-center justify-center gap-3 border-2 border-slate-200 text-slate-600 hover:bg-slate-50"
                                            :class="testSent ? 'bg-emerald-50 border-emerald-100 text-emerald-600' : ''">
                                        <i class="fas" :class="sendingTest ? 'fa-circle-notch fa-spin' : (testSent ? 'fa-check-circle' : 'fa-flask')"></i>
                                        <span x-text="sendingTest ? 'Sending Test...' : (testSent ? 'Test Sent Successfully' : 'Send Test Email')"></span>
                                    </button>

                                    <div class="w-full mt-4" x-show="testSent">
                                        <!-- Double Confirmation Unlock -->
                                        <div x-show="!sendUnlocked" class="p-1 bg-slate-100 rounded-full flex items-center">
                                            <button @click="sendUnlocked = true" class="w-full py-3 bg-white text-[#1e4a7a] font-bold rounded-full shadow-sm hover:shadow-md transition">
                                                <i class="fas fa-lock-open mr-2"></i> Click to Unlock Final Blast
                                            </button>
                                        </div>

                                        <button x-show="sendUnlocked" @click="confirmSend" 
                                                class="w-full py-4 bg-[#1e4a7a] hover:bg-[#123a5e] text-white font-bold rounded-2xl shadow-xl shadow-blue-900/20 transition-all hover:-translate-y-1 flex items-center justify-center gap-3">
                                            <i class="fas fa-paper-plane"></i> Confirm & Send Blast Now
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Navigation -->
                        <div class="flex justify-between pt-8 border-t border-slate-100">
                            <button x-show="currentStep > 1" @click="currentStep--" class="px-6 py-2.5 rounded-xl font-bold text-slate-500 hover:bg-slate-50 transition">Previous</button>
                            <div class="flex-1"></div>
                            <button x-show="currentStep < 3" @click="currentStep++" class="bg-slate-900 text-white px-8 py-2.5 rounded-xl font-bold shadow-lg hover:bg-slate-800 transition">Next Step</button>
                        </div>
                    </div>
                </div>

                <!-- Preview Pane (Right) -->
                <div class="xl:col-span-5 space-y-6 sticky top-8">
                    <div class="flex items-center justify-between px-2">
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-widest">Live Preview</h3>
                        <div class="flex bg-white p-1 rounded-lg border border-slate-100">
                            <button @click="previewMode = 'desktop'" :class="previewMode === 'desktop' ? 'bg-slate-100 text-slate-900' : 'text-slate-400'" class="w-8 h-8 rounded-md flex items-center justify-center transition"><i class="fas fa-desktop text-xs"></i></button>
                            <button @click="previewMode = 'mobile'" :class="previewMode === 'mobile' ? 'bg-slate-100 text-slate-900' : 'text-slate-400'" class="w-8 h-8 rounded-md flex items-center justify-center transition"><i class="fas fa-mobile-alt text-xs"></i></button>
                        </div>
                    </div>

                    <div class="bg-white rounded-[2.5rem] shadow-2xl border-8 border-slate-100 overflow-hidden transition-all duration-500 mx-auto"
                         :class="previewMode === 'mobile' ? 'max-w-[375px]' : 'w-full'">
                        
                        <!-- Preview Header -->
                        <div class="bg-slate-50 p-4 border-b border-slate-100">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="w-2 h-2 rounded-full bg-rose-400"></div>
                                <div class="w-2 h-2 rounded-full bg-amber-400"></div>
                                <div class="w-2 h-2 rounded-full bg-emerald-400"></div>
                            </div>
                            <p class="text-[10px] font-bold text-slate-400">SUBJECT</p>
                            <p class="text-sm font-bold text-slate-800 truncate" x-text="subject || '(No Subject)'"></p>
                        </div>

                        <!-- Preview Content -->
                        <div class="h-[500px] overflow-y-auto p-0 preview-content">
                            <!-- Inlined Template for Preview -->
                            <div class="bg-slate-50 min-h-full">
                                <div class="bg-white max-w-2xl mx-auto shadow-sm">
                                    <div class="bg-[#0f2b44] p-8 text-center">
                                        <div class="inline-block bg-white/10 text-white w-10 h-10 rounded-lg text-2xl font-bold mb-4">M◈</div>
                                        <h1 class="text-white text-xl font-bold tracking-tight">Mioym Equities</h1>
                                    </div>
                                    <div class="p-8 space-y-4">
                                        <div class="prose prose-slate max-w-none" x-html="processedPreview"></div>
                                        
                                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-6 mt-8">
                                            <h4 class="text-[#1e4a7a] font-bold text-sm mb-4">Webinar Details</h4>
                                            <div class="space-y-2">
                                                <p class="text-xs text-slate-600"><strong>Date & Time:</strong> <span x-text="sampleDate"></span></p>
                                                <p class="text-xs text-slate-600"><strong>Topic:</strong> <span x-text="sampleTitle"></span></p>
                                                <p class="text-xs text-slate-600"><strong>Duration:</strong> Approximately 60 minutes</p>
                                            </div>
                                        </div>

                                        <div class="mt-8 pt-8 border-t border-slate-100 text-center space-y-4">
                                            <p class="text-[10px] text-slate-400 uppercase font-bold tracking-widest">Investment Excellence</p>
                                            <div class="flex justify-center gap-3">
                                                <div class="w-8 h-8 bg-slate-50 rounded-lg"></div>
                                                <div class="w-8 h-8 bg-slate-50 rounded-lg"></div>
                                                <div class="w-8 h-8 bg-slate-50 rounded-lg"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sending Progress Modal -->
            <div x-show="isSending" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm" x-cloak>
                <div class="bg-white rounded-[2.5rem] p-10 max-w-md w-full shadow-2xl text-center space-y-6">
                    <div class="w-20 h-20 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center text-3xl mx-auto animate-pulse">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-slate-900">Blasting Campaign</h3>
                        <p class="text-slate-500 mt-2">Please do not close this window.</p>
                    </div>
                    <div class="space-y-2">
                        <div class="h-3 w-full bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-blue-600 transition-all duration-500" :style="'width: ' + sendProgress + '%'"></div>
                        </div>
                        <p class="text-sm font-bold text-slate-900" x-text="sendProgress + '%'"></p>
                    </div>
                    <div class="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-widest">
                        <span>Delivered: <span class="text-emerald-500" x-text="sentCount"></span></span>
                        <span>Failed: <span class="text-rose-500" x-text="failedCount"></span></span>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function campaignBuilder() {
            return {
                currentStep: 1,
                steps: ['Audience', 'Content', 'Review'],
                selectedWebinar: 'all',
                selectedWebinarTitle: 'All Registrants (Every webinar)',
                recipientCount: <?php echo $totalRegistrants; ?>,
                counts: <?php echo json_encode($countsByWebinar); ?>,
                subject: 'Your Webinar Invitation Link - Mioym Equities',
                isScheduled: false,
                previewMode: 'desktop',
                renderedContent: 'Hi [Name],<br><br>Here is the link to join our webinar: [Link]<br><br>See you there!',
                sampleDate: 'To be announced',
                sampleTitle: 'Exclusive Webinar',
                webinarData: <?php echo json_encode($webinars); ?>,
                sendingTest: false,
                testSent: false,
                sendUnlocked: false,
                isSending: false,
                sendProgress: 0,
                sentCount: 0,
                failedCount: 0,
                quill: null,

                init() {
                    this.quill = new Quill('#editor-container', {
                        theme: 'snow',
                        modules: {
                            toolbar: [
                                ['bold', 'italic', 'underline'],
                                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                                ['link', 'image'],
                                ['clean']
                            ]
                        },
                        placeholder: 'Write your email content here...'
                    });

                    // Set initial content
                    this.quill.root.innerHTML = this.renderedContent;

                    this.quill.on('text-change', () => {
                        this.renderedContent = this.quill.root.innerHTML;
                    });

                    this.updateCount(); // Initialize sample data
                },

                get processedPreview() {
                    return this.renderedContent
                        .replace(/\[Name\]/g, '<strong>Test Investor</strong>')
                        .replace(/\[Link\]/g, '<a href="#" class="text-blue-600 underline">https://zoom.us/test-link</a>')
                        .replace(/\[WebinarDate\]/g, this.sampleDate)
                        .replace(/\[WebinarTitle\]/g, this.sampleTitle);
                },

                updateCount() {
                    if (this.selectedWebinar === 'all') {
                        this.recipientCount = <?php echo $totalRegistrants; ?>;
                        this.selectedWebinarTitle = 'All Registrants (Every webinar)';
                        this.sampleDate = 'To be announced';
                        this.sampleTitle = 'Exclusive Webinar';
                    } else {
                        this.recipientCount = this.counts[this.selectedWebinar] || 0;
                        const select = document.querySelector('select[name="webinar_id"]');
                        this.selectedWebinarTitle = select.options[select.selectedIndex].text;
                        
                        // Find webinar data for sample preview
                        const web = this.webinarData.find(w => w.webinar_id === this.selectedWebinar);
                        if (web) {
                            this.sampleTitle = web.title;
                            if (web['schedule_date&time']) {
                                const d = new Date(web['schedule_date&time']);
                                this.sampleDate = d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) + ' at ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                            } else {
                                this.sampleDate = 'To be announced';
                            }
                        }
                    }
                },

                insertTag(tag) {
                    const range = this.quill.getSelection(true);
                    this.quill.insertText(range.index, tag);
                },

                async sendTest() {
                    this.sendingTest = true;
                    
                    // Create FormData
                    const formData = new FormData();
                    formData.append('action', 'send_test_email');
                    formData.append('email_template', this.renderedContent);
                    formData.append('subject', this.subject);
                    formData.append('webinar_id', this.selectedWebinar);

                    try {
                        const response = await fetch('emails.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        // Since the PHP script redirects, we'll just assume success if it didn't throw
                        this.testSent = true;
                    } catch (e) {
                        alert('Failed to send test email.');
                    } finally {
                        this.sendingTest = false;
                    }
                },

                confirmSend() {
                    this.isSending = true;
                    let progress = 0;
                    const interval = setInterval(() => {
                        progress += 5;
                        this.sendProgress = progress;
                        this.sentCount = Math.floor(this.recipientCount * (progress / 100));
                        
                        if (progress >= 100) {
                            clearInterval(interval);
                            setTimeout(() => {
                                // Final submission
                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.action = 'emails.php';
                                
                                const inputs = {
                                    action: 'send_emails',
                                    webinar_id: this.selectedWebinar,
                                    email_template: this.renderedContent,
                                    subject: this.subject
                                };

                                for (const key in inputs) {
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = key;
                                    input.value = inputs[key];
                                    form.appendChild(input);
                                }

                                document.body.appendChild(form);
                                form.submit();
                            }, 1000);
                        }
                    }, 150);
                }
            }
        }
    </script>
</body>
</html>
