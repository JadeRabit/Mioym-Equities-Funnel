<?php
// thankyou.php - Thank You / Confirmation Page (FIXED - SCROLLABLE)
require_once 'db.php';
require_once 'config.php';

$name = isset($_GET['fullname']) ? htmlspecialchars($_GET['fullname']) : (isset($_GET['name']) ? htmlspecialchars($_GET['name']) : 'Valued Investor');

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
$webinarTitle = $latestWebinar['title'] ?? 'Exclusive Webinar';
$webinarDuration = $latestWebinar['duration'] ?? get_setting('webinar_duration', '60-minute');
$meetingLink = $latestWebinar['webinar_link'] ?? '#';
$webinarVid = $latestWebinar['webinar_vid'] ?? null;

// Dynamic schedule formatting
$scheduleDateStr = 'Date to be announced';
$scheduleTimeStr = '';
$scheduleDateTimeCardStr = 'Date to be announced';

if ($latestWebinar && !empty($latestWebinar['schedule_date&time'])) {
    $base = $latestWebinar['schedule_date&time'];
    $tzString = $latestWebinar['timezone'] ?? 'America/New_York';
    
    try {
        $dateObj = new DateTime($base, new DateTimeZone($tzString));
    } catch (Exception $e) {
        $dateObj = new DateTime($base, new DateTimeZone('America/New_York'));
    }
    
    $scheduleDateStr = $dateObj->format('l, F j, Y');
    $scheduleTimeStr = strtolower($dateObj->format('g:i A'));
    $scheduleDateTimeCardStr = $dateObj->format('l, F j, g:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You · Mioym Equities Webinar</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <!-- Tailwind + Font Awesome -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .confetti-bg {
            background: linear-gradient(145deg, #f8fafd, #ffffff);
            position: relative;
            overflow-x: hidden;
        }
        .confetti-bg::before {
            content: '';
            position: absolute;
            top: -10px;
            left: 0;
            width: 100%;
            height: 10px;
            background: repeating-linear-gradient(
                45deg,
                #ffd966 0px,
                #ffd966 20px,
                #1e4a7a 20px,
                #1e4a7a 40px,
                #ffb347 40px,
                #ffb347 60px
            );
            opacity: 0.3;
        }
    </style>
</head>
<body class="confetti-bg text-slate-800 antialiased min-h-screen p-5">

    <main class="max-w-2xl mx-auto py-4 sm:py-8">
        <!-- Main confirmation card -->
        <div class="bg-white rounded-[1.5rem] sm:rounded-[2.5rem] shadow-2xl border border-slate-100 p-6 sm:p-10 md:p-12 relative overflow-hidden">
            <!-- Success checkmark animation -->
             <div class="text-center mb-8 sm:mb-10">
                <div class="bg-green-100 text-green-600 w-20 h-20 sm:w-24 sm:h-24 rounded-full flex items-center justify-center text-4xl sm:text-5xl mx-auto mb-6 shadow-lg border-4 border-white">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1 class="text-3xl sm:text-4xl md:text-5xl font-extrabold text-slate-900 mb-4 tracking-tight leading-tight">
                    Thank You, <span class="text-[#1e4a7a]"><?php echo $name; ?></span>!
                </h1>
                <p class="text-lg sm:text-xl text-slate-600 font-medium">You're successfully registered.</p>
             </div>
             <!-- Confirmation message box -->
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl p-6 sm:p-8 mb-8 sm:mb-10 shadow-lg border border-blue-100">
                <div class="flex items-start gap-5">
                    <div class="w-12 h-12 bg-gradient-to-br from-[#1e4a7a] to-blue-600 rounded-2xl flex items-center justify-center text-white flex-shrink-0 shadow-lg shadow-blue-900/20">
                        <i class="fas fa-envelope-open-text text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-extrabold text-slate-900 text-xl mb-2 tracking-tight">Email sent!</h3>
                        <p class="text-sm sm:text-base text-slate-700 leading-relaxed font-medium">Check your inbox for the access link and calendar invite.</p>
                    </div>
                </div>
            </div>
            <div class="absolute top-0 right-0 w-32 h-32 sm:w-40 sm:h-40 bg-green-50 rounded-bl-full -z-5"></div>
            <p class="text-base sm:text-lg font-black text-slate-900 tracking-tight text-center mb-5">
                MIOYM - <?php echo htmlspecialchars($webinarTitle); ?>
            </p>

            <div class="mb-5 sm:mb-10 overflow-hidden rounded-2xl border border-slate-200 shadow-sm">
                <img
                    src="img/Screenshot%202026-04-18%20023607.png"
                    alt="MIOYM briefing banner"
                    class="w-full h-auto object-cover"
                >
            </div>
            
            <div class="text-center mb-8 sm:mb-10">
                <div class="mb-8 sm:mb-10 rounded-2xl border border-blue-100 bg-blue-50/60 p-5 sm:p-6">
                    <p class="text-base sm:text-lg font-black text-slate-900 tracking-tight">
                        MIOYM - <?php echo htmlspecialchars($webinarTitle); ?>
                    </p>
                    <p class="mt-2 text-sm sm:text-base font-bold text-[#1e4a7a]">
                        <?php echo htmlspecialchars($scheduleDateTimeCardStr); ?>
                    </p>
                    <p class="mt-3 text-sm sm:text-base text-slate-700 leading-relaxed">
                        This 30-minute technical briefing is designed for institutional partners and sophisticated investors seeking a transparent evaluation of our asset management framework. We will conduct a comprehensive breakdown of the operational and financial structures that define our investment model, focusing on the interplay between capital security and project velocity.
                    </p>
                </div>
            </div>
            

            <!-- Webinar Video -->
            <div class="mb-8 sm:mb-10">
                <div class="bg-slate-50/80 rounded-2xl border border-slate-100 overflow-hidden shadow-sm">
                    <?php if (!empty($webinarVid)): ?>
                        <div class="aspect-video bg-black">
                            <video class="w-full h-full" controls controlsList="nodownload" autoplay muted playsinline>
                                <source src="<?php echo htmlspecialchars($webinarVid); ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    <?php else: ?>
                        <div class="aspect-video flex items-center justify-center text-slate-400 gap-3">
                            <i class="fas fa-video-slash text-xl"></i>
                            <span class="font-semibold">Video coming soon</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Webinar Details -->
            <div class="mb-8 sm:mb-10">
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900 mb-6 flex items-center gap-3">
                    <i class="fas fa-video text-[#1e4a7a]"></i> Webinar Details
                </h2>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-5">
                    <!-- Date & Time -->
                    <div class="bg-slate-50/80 rounded-2xl p-5 border border-slate-100 transition-all hover:shadow-md">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center text-[#1e4a7a] shadow-sm">
                                <i class="fas fa-calendar-alt text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Date & Time</p>
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($scheduleDateStr); ?></p>
                                <p class="text-sm text-slate-600 font-medium"><?php echo htmlspecialchars($scheduleTimeStr); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Duration -->
                    <div class="bg-slate-50/80 rounded-2xl p-5 border border-slate-100 transition-all hover:shadow-md">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center text-[#1e4a7a] shadow-sm">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Duration</p>
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($webinarDuration); ?></p>
                                <p class="text-sm text-slate-600 font-medium">+ 15 min Q&A</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Platform -->
                    <div class="bg-slate-50/80 rounded-2xl p-5 border border-slate-100 transition-all hover:shadow-md">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center text-[#1e4a7a] shadow-sm">
                                <i class="fas fa-laptop text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Platform</p>
                                <p class="font-bold text-slate-900">Zoom Webinar</p>
                                <p class="text-sm text-slate-600 font-medium">Registered via Zoom</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Host -->
                    <div class="bg-slate-50/80 rounded-2xl p-5 border border-slate-100 transition-all hover:shadow-md">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center text-[#1e4a7a] shadow-sm">
                                <i class="fas fa-user-tie text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Host</p>
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($hostName); ?></p>
                                <p class="text-sm text-slate-600 font-medium">Institutional Real Estate Expert</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Next Steps Instructions -->
            <div class="mb-10">
                <h2 class="text-2xl font-bold text-slate-900 mb-6 flex items-center gap-3">
                    <i class="fas fa-list-check text-[#1e4a7a]"></i> Next Steps
                </h2>
                
                <div class="space-y-4">
                    <div class="flex items-start gap-4 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm transition-all hover:shadow-md hover:border-blue-100">
                        <div class="w-8 h-8 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 shadow-lg shadow-blue-900/20">1</div>
                        <div>
                            <p class="font-bold text-slate-900">Check your email</p>
                            <p class="text-sm text-slate-600 leading-relaxed">An email should send your webinar confirmation and reminder emails. If you don't see them, check spam.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-4 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm transition-all hover:shadow-md hover:border-blue-100">
                        <div class="w-8 h-8 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 shadow-lg shadow-blue-900/20">2</div>
                        <div>
                            <p class="font-bold text-slate-900">Add to your calendar</p>
                            <p class="text-sm text-slate-600 leading-relaxed">Use the email invite to save the event so you get on-time reminders.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-4 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm transition-all hover:shadow-md hover:border-blue-100">
                        <div class="w-8 h-8 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 shadow-lg shadow-blue-900/20">3</div>
                        <div>
                            <p class="font-bold text-slate-900">Prepare your questions</p>
                            <p class="text-sm text-slate-600 leading-relaxed">The session includes live Q&A. Prepare your investment questions for <?php echo explode(' ', $hostName)[0]; ?>.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center mt-12">
                <a href="index.php" class="bg-[#1e4a7a] hover:bg-[#123a5e] text-white font-bold px-10 py-4 rounded-2xl shadow-xl shadow-blue-900/20 transition-all hover:-translate-y-1 inline-flex items-center justify-center gap-3">
                    <i class="fas fa-home"></i> Back to Homepage
                </a>
                <a href="<?php echo htmlspecialchars($meetingLink); ?>" target="_blank" rel="noopener noreferrer" class="bg-amber-400 hover:bg-amber-300 text-[#0f2b44] font-bold px-10 py-4 rounded-2xl shadow-xl shadow-amber-500/20 transition-all hover:-translate-y-1 inline-flex items-center justify-center gap-3">
                    <i class="fas fa-video"></i> Open Webinar Link
                </a>
            </div>

            <!-- Share Section -->
            <div class="mt-12 pt-8 border-t border-slate-100 text-center">
                <p class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-6">Share with your network</p>
                <div class="flex justify-center gap-5">
                    <a href="https://www.facebook.com/mioymrenttoown/" target="_blank" class="w-12 h-12 bg-[#1877f2]/10 text-[#1877f2] rounded-2xl flex items-center justify-center hover:bg-[#1877f2] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm" aria-label="Facebook"><i class="fab fa-facebook-f text-lg"></i></a>
                    <a href="https://mysig.io/bwjx9dVn" target="_blank" class="w-12 h-12 bg-[#E1306C]/10 text-[#E1306C] rounded-2xl flex items-center justify-center hover:bg-[#E1306C] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm" aria-label="Instagram"><i class="fab fa-instagram text-lg"></i></a>
                    <a href="https://x.com/mioymAF2900" target="_blank" class="w-12 h-12 bg-[#1da1f2]/10 text-[#1da1f2] rounded-2xl flex items-center justify-center hover:bg-[#1da1f2] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm" aria-label="Twitter"><i class="fab fa-twitter text-lg"></i></a>
                    <a href="https://www.tiktok.com/@mioym.rent2own" target="_blank" class="w-12 h-12 bg-[#000000]/10 text-[#000000] rounded-2xl flex items-center justify-center hover:bg-[#000000] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm" aria-label="TikTok"><i class="fab fa-tiktok text-lg"></i></a>
                    <a href="https://www.linkedin.com/company/mioym-group/" target="_blank" class="w-12 h-12 bg-[#0a66c2]/10 text-[#0a66c2] rounded-2xl flex items-center justify-center hover:bg-[#0a66c2] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm" aria-label="LinkedIn"><i class="fab fa-linkedin-in text-lg"></i></a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-xs font-bold text-slate-400 text-center mt-10 uppercase tracking-widest">
            © 2026 Mioym Equities · 
            <a href="index.php" class="text-[#1e4a7a] hover:underline">Return Home</a>
        </p>
    </main>
</body>
</html>
