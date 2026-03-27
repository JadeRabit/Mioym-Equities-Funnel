<?php
// thankyou.php - Thank You / Confirmation Page (FIXED - SCROLLABLE)
require_once 'db.php';

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
$meetingLink = $latestWebinar['webinar_link'] ?? '#';

// Dynamic schedule formatting
$scheduleDateStr = 'Date to be announced';
$scheduleTimeStr = '';

if ($latestWebinar && !empty($latestWebinar['schedule_date&time'])) {
    $base = $latestWebinar['schedule_date&time'];
    $ldn = new DateTime($base, new DateTimeZone('Europe/London'));
    $ny  = new DateTime($base, new DateTimeZone('America/New_York'));
    
    $scheduleDateStr = $ldn->format('l, F j, Y');
    $timeL = strtolower($ldn->format('g:i A')) . ' ' . $ldn->format('T');
    $timeN = strtolower($ny->format('g:i A')) . ' ' . $ny->format('T');
    $scheduleTimeStr = $timeL . ' | ' . $timeN;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You · Mioym Equities Webinar</title>
    <!-- Tailwind + Font Awesome -->
    <script src="https://cdn.tailwindcss.com"></script>
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

    <main class="max-w-2xl mx-auto py-8">
        <!-- Main confirmation card -->
        <div class="bg-white rounded-[2.5rem] shadow-2xl border border-slate-100 p-8 md:p-12 relative overflow-hidden">
            <!-- Success checkmark animation -->
            <div class="absolute top-0 right-0 w-40 h-40 bg-green-50 rounded-bl-full -z-5"></div>
            
            <div class="text-center mb-10">
                <div class="bg-green-100 w-24 h-24 rounded-full flex items-center justify-center text-green-600 text-5xl mx-auto mb-6 shadow-lg border-4 border-white">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 mb-4 tracking-tight">
                    Thank You, <span class="text-[#1e4a7a]"><?php echo $name; ?></span>! 
                </h1>
                <p class="text-xl text-slate-600 font-medium">You're successfully registered for the webinar.</p>
            </div>

            <!-- Confirmation message box -->
            <div class="bg-blue-50/50 border-l-4 border-[#1e4a7a] rounded-r-2xl p-6 mb-10">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 bg-[#1e4a7a] rounded-xl flex items-center justify-center text-white flex-shrink-0">
                        <i class="fas fa-envelope-open-text text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-900 text-lg">Confirmation email sent!</h3>
                        <p class="text-slate-600 leading-relaxed">We've sent a confirmation to your email with the webinar access link and calendar invite. Please check your inbox (and spam folder).</p>
                    </div>
                </div>
            </div>

            <!-- Webinar Details -->
            <div class="mb-10">
                <h2 class="text-2xl font-bold text-slate-900 mb-6 flex items-center gap-3">
                    <i class="fas fa-video text-[#1e4a7a]"></i> Webinar Details
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
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
                                <p class="font-bold text-slate-900">60 minutes</p>
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
                                <p class="text-sm text-slate-600 font-medium">Link will be sent via email</p>
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
                            <p class="text-sm text-slate-600 leading-relaxed">We sent a confirmation with calendar invite. If you don't see it within 10 minutes, check your spam folder.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-4 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm transition-all hover:shadow-md hover:border-blue-100">
                        <div class="w-8 h-8 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 shadow-lg shadow-blue-900/20">2</div>
                        <div>
                            <p class="font-bold text-slate-900">Add to calendar</p>
                            <p class="text-sm text-slate-600 leading-relaxed">Click the calendar link in the email to save the event and avoid missing it.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-4 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm transition-all hover:shadow-md hover:border-blue-100">
                        <div class="w-8 h-8 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 shadow-lg shadow-blue-900/20">3</div>
                        <div>
                            <p class="font-bold text-slate-900">Prepare your questions</p>
                            <p class="text-sm text-slate-600 leading-relaxed">The session includes a live Q&A. Think about your investment goals and write down any questions for <?php echo explode(' ', $hostName)[0]; ?>.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center mt-12">
                <a href="index.php" class="bg-[#1e4a7a] hover:bg-[#123a5e] text-white font-bold px-10 py-4 rounded-2xl shadow-xl shadow-blue-900/20 transition-all hover:-translate-y-1 inline-flex items-center justify-center gap-3">
                    <i class="fas fa-home"></i> Back to Homepage
                </a>
                <a href="<?php echo htmlspecialchars($meetingLink); ?>" target="_blank" class="bg-amber-400 hover:bg-amber-300 text-[#0f2b44] font-bold px-10 py-4 rounded-2xl shadow-xl shadow-amber-500/20 transition-all hover:-translate-y-1 inline-flex items-center justify-center gap-3">
                    <i class="fas fa-video"></i> Access Webinar Link
                </a>
            </div>

            <!-- Share Section -->
            <div class="mt-12 pt-8 border-t border-slate-100 text-center">
                <p class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-6">Share with your network</p>
                <div class="flex justify-center gap-5">
                    <a href="#" class="w-12 h-12 bg-[#1877f2]/10 text-[#1877f2] rounded-2xl flex items-center justify-center hover:bg-[#1877f2] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm"><i class="fab fa-facebook-f text-lg"></i></a>
                    <a href="#" class="w-12 h-12 bg-[#1da1f2]/10 text-[#1da1f2] rounded-2xl flex items-center justify-center hover:bg-[#1da1f2] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm"><i class="fab fa-twitter text-lg"></i></a>
                    <a href="#" class="w-12 h-12 bg-[#0a66c2]/10 text-[#0a66c2] rounded-2xl flex items-center justify-center hover:bg-[#0a66c2] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm"><i class="fab fa-linkedin-in text-lg"></i></a>
                    <a href="#" class="w-12 h-12 bg-[#25D366]/10 text-[#25D366] rounded-2xl flex items-center justify-center hover:bg-[#25D366] hover:text-white hover:scale-110 transition-all duration-300 shadow-sm"><i class="fab fa-whatsapp text-lg"></i></a>
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