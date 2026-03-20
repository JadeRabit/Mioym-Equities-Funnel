<?php
// thankyou.php - Thank You / Confirmation Page (FIXED - SCROLLABLE)
$name = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : 'Valued Investor';
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
    <style>
        .confetti-bg {
            background: linear-gradient(145deg, #f8fafd, #ffffff);
            position: relative;
            overflow-x: hidden; /* Hide horizontal scroll, allow vertical */
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
<body class="confetti-bg text-slate-800 font-sans antialiased min-h-screen p-5">
    <!-- REMOVED: flex items-center justify-center - ito ang pumipigil sa scrolling -->
    <!-- Instead, normal block layout na may padding -->

    <main class="max-w-2xl mx-auto py-8">
        <!-- Main confirmation card -->
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 p-8 md:p-10 relative overflow-hidden">
            <!-- Success checkmark animation -->
            <div class="absolute top-0 right-0 w-32 h-32 bg-green-50 rounded-bl-full -z-5"></div>
            
            <div class="text-center mb-8">
                <div class="bg-green-100 w-24 h-24 rounded-full flex items-center justify-center text-green-600 text-5xl mx-auto mb-6 shadow-lg border-4 border-white">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 mb-3">
                    Thank You, <span class="text-[#1e4a7a]"><?php echo $name; ?></span>! 
                </h1>
                <p class="text-xl text-slate-600">You're successfully registered for the webinar.</p>
            </div>

            <!-- Confirmation message box -->
            <div class="bg-[#e8f0fe] border-l-4 border-[#1e4a7a] rounded-r-xl p-5 mb-8">
                <div class="flex items-start gap-3">
                    <i class="fas fa-envelope-open-text text-[#1e4a7a] text-xl mt-1"></i>
                    <div>
                        <h3 class="font-bold text-slate-800">Confirmation email sent!</h3>
                        <p class="text-slate-600">We've sent a confirmation to your email with the webinar access link and calendar invite. Please check your inbox (and spam folder).</p>
                    </div>
                </div>
            </div>

            <!-- Webinar Details (placeholder) -->
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-video text-[#1e4a7a]"></i> Webinar Details
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Date & Time -->
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-[#1e4a7a]/10 rounded-lg flex items-center justify-center text-[#1e4a7a]">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Date & Time</p>
                                <p class="font-semibold text-slate-800">Thursday, April 24, 2025</p>
                                <p class="text-sm text-slate-600">6:00 PM BST | 1:00 PM EST</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Duration -->
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-[#1e4a7a]/10 rounded-lg flex items-center justify-center text-[#1e4a7a]">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Duration</p>
                                <p class="font-semibold text-slate-800">60 minutes</p>
                                <p class="text-sm text-slate-600">+ 15 min Q&A</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Platform -->
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-[#1e4a7a]/10 rounded-lg flex items-center justify-center text-[#1e4a7a]">
                                <i class="fas fa-laptop"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Platform</p>
                                <p class="font-semibold text-slate-800">Zoom Webinar</p>
                                <p class="text-sm text-slate-600">Link will be sent via email</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Host -->
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-[#1e4a7a]/10 rounded-lg flex items-center justify-center text-[#1e4a7a]">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Host</p>
                                <p class="font-semibold text-slate-800">Elena Marchetti</p>
                                <p class="text-sm text-slate-600">Former Heitman Acquisitions</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Next Steps Instructions -->
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-list-check text-[#1e4a7a]"></i> Next Steps
                </h2>
                
                <div class="space-y-3">
                    <div class="flex items-start gap-3 bg-white p-3 rounded-lg border border-slate-100">
                        <div class="w-6 h-6 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">1</div>
                        <div>
                            <p class="font-semibold text-slate-800">Check your email</p>
                            <p class="text-sm text-slate-600">We sent a confirmation with calendar invite. If you don't see it within 10 minutes, check your spam folder.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-3 bg-white p-3 rounded-lg border border-slate-100">
                        <div class="w-6 h-6 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">2</div>
                        <div>
                            <p class="font-semibold text-slate-800">Add to calendar</p>
                            <p class="text-sm text-slate-600">Click the calendar link in the email to save the event and avoid missing it.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-3 bg-white p-3 rounded-lg border border-slate-100">
                        <div class="w-6 h-6 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">3</div>
                        <div>
                            <p class="font-semibold text-slate-800">Prepare your questions</p>
                            <p class="text-sm text-slate-600">The session includes a live Q&A. Think about your investment goals and write down any questions for Elena.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-3 bg-white p-3 rounded-lg border border-slate-100">
                        <div class="w-6 h-6 bg-[#1e4a7a] text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">4</div>
                        <div>
                            <p class="font-semibold text-slate-800">Test your connection</p>
                            <p class="text-sm text-slate-600">Ensure you have a stable internet connection and Zoom installed. Join 5-10 minutes early.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add to Calendar Buttons (placeholders) -->
            <div class="flex flex-wrap gap-3 justify-center mb-8">
                <button class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-medium px-5 py-3 rounded-full shadow-sm transition flex items-center gap-2">
                    <i class="fab fa-google text-[#1e4a7a]"></i> Google Calendar
                </button>
                <button class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-medium px-5 py-3 rounded-full shadow-sm transition flex items-center gap-2">
                    <i class="fas fa-calendar-plus text-[#1e4a7a]"></i> Outlook
                </button>
                <button class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-medium px-5 py-3 rounded-full shadow-sm transition flex items-center gap-2">
                    <i class="fas fa-calendar-alt text-[#1e4a7a]"></i> Apple Calendar
                </button>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="index.php" class="bg-[#1e4a7a] hover:bg-[#123a5e] text-white font-semibold px-8 py-4 rounded-full shadow-lg transition inline-flex items-center justify-center gap-2">
                    <i class="fas fa-home"></i> Back to Homepage
                </a>
                <a href="#" class="bg-amber-400 hover:bg-amber-300 text-[#0f2b44] font-semibold px-8 py-4 rounded-full shadow-lg transition inline-flex items-center justify-center gap-2">
                    <i class="fas fa-download"></i> Download Slides (Coming Soon)
                </a>
            </div>

            <!-- Share Section -->
            <div class="mt-8 pt-6 border-t border-slate-200 text-center">
                <p class="text-sm text-slate-500 mb-3">Know someone who should attend? Share this webinar:</p>
                <div class="flex justify-center gap-4">
                    <a href="#" class="w-10 h-10 bg-[#1877f2] text-white rounded-full flex items-center justify-center hover:scale-110 transition"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="w-10 h-10 bg-[#1da1f2] text-white rounded-full flex items-center justify-center hover:scale-110 transition"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="w-10 h-10 bg-[#0a66c2] text-white rounded-full flex items-center justify-center hover:scale-110 transition"><i class="fab fa-linkedin-in"></i></a>
                    <a href="#" class="w-10 h-10 bg-[#25D366] text-white rounded-full flex items-center justify-center hover:scale-110 transition"><i class="fab fa-whatsapp"></i></a>
                    <a href="#" class="w-10 h-10 bg-[#c32aa3] text-white rounded-full flex items-center justify-center hover:scale-110 transition"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-xs text-slate-400 text-center mt-6">
            © 2025 Mioym Equities · Webinar Confirmation Page · 
            <a href="index.php" class="text-[#1e4a7a] hover:underline">Back to Homepage</a>
        </p>
    </main>
</body>
</html>git remote add origin https://github.com/JadeRabit/Mioym-Equities-Funnel.git
git branch -M main
git push -u origin main