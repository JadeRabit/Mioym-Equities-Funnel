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
    <div class="max-w-6xl mx-auto px-5 sm:px-8 py-6 md:py-8">

        <!-- ========== SECTION 4.1: HEADER ========== -->
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-2">
                <div class="bg-[#0f2b44] text-white w-9 h-9 rounded-lg flex items-center justify-center text-xl font-semibold">M◈</div>
                <span class="font-medium text-slate-600">mioym equities</span>
            </div>
            <span class="text-sm bg-white border border-slate-200 px-4 py-2 rounded-full shadow-sm">
                <i class="far fa-calendar-alt text-amber-500 mr-1"></i> 24 Apr 2025 · 6pm BST
            </span>
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
                        How to deploy €50k–€500k into <span class="text-amber-300">institutional real estate</span> (without connections)
                    </h1>
                    <p class="text-lg md:text-xl text-slate-200 mt-6 max-w-lg">
                        Join our free 60‑minute webinar and learn the exact framework used by family offices to source off‑market multifamily & industrial assets.
                    </p>
                    <ul class="mt-8 space-y-3">
                        <li class="flex items-start gap-3">
                            <i class="fas fa-check-circle text-amber-400 text-xl mt-0.5"></i>
                            <span class="text-slate-100"><strong class="text-white">9‑figure deal sourcing:</strong> how we find deals before they hit the market</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fas fa-check-circle text-amber-400 text-xl mt-0.5"></i>
                            <span class="text-slate-100"><strong class="text-white">co‑investment terms:</strong> negotiate like a pro (even with smaller tickets)</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fas fa-check-circle text-amber-400 text-xl mt-0.5"></i>
                            <span class="text-slate-100"><strong class="text-white">tax & legal hacks:</strong> structure your holdings for maximum efficiency</span>
                        </li>
                    </ul>
                    <div class="flex flex-wrap items-center gap-4 mt-10">
                        <a href="registration.php" class="bg-amber-500 hover:bg-amber-400 text-[#0f2b44] font-bold text-lg px-8 py-4 rounded-full shadow-xl transition inline-flex items-center gap-3">
                            <i class="fas fa-calendar-check"></i> Register now — it's free
                        </a>
                        <a href="#" class="text-white font-medium hover:text-amber-300 transition flex items-center gap-2 group">
                            <i class="far fa-clock text-amber-300 group-hover:scale-105"></i> limited seats
                        </a>
                    </div>
                    <p class="text-sm text-slate-200 mt-6 flex items-center gap-2">
                        <span class="flex -space-x-1">
                            <span class="w-6 h-6 rounded-full bg-amber-400 border-2 border-white text-[10px] text-[#0f2b44] flex items-center justify-center font-bold">JD</span>
                            <span class="w-6 h-6 rounded-full bg-amber-300 border-2 border-white text-[10px] text-[#0f2b44] flex items-center justify-center font-bold">ML</span>
                            <span class="w-6 h-6 rounded-full bg-amber-200 border-2 border-white text-[10px] text-[#0f2b44] flex items-center justify-center font-bold">RK</span>
                        </span>
                        <span><span class="font-semibold text-white">187+ investors</span> already registered this week</span>
                    </p>
                </div>
                <!-- Right side: Video placeholder -->
                <div class="video-placeholder relative shadow-2xl rounded-2xl overflow-hidden group cursor-pointer border border-white/20">
                    <div class="absolute inset-0 bg-black/20 flex items-center justify-center group-hover:bg-black/30 transition">
                        <div class="w-20 h-20 bg-white/90 rounded-full flex items-center justify-center shadow-2xl group-hover:scale-110 transition">
                            <i class="fas fa-play text-3xl text-[#1e4a7a] ml-1"></i>
                        </div>
                    </div>
                    <div class="absolute bottom-3 left-4 text-white text-xs bg-black/40 px-3 py-1 rounded-full backdrop-blur-sm">
                        <i class="far fa-clock mr-1"></i> 60 min · replay available
                    </div>
                </div>
            </div>
        </div>
    </section>

        <!-- ========== SECTION 4.3: BENEFITS SECTION ========== -->
    <main class="max-w-6xl mx-auto px-5 sm:px-8 pb-10 md:pb-16">
        <div class="flex flex-col sm:flex-row items-center justify-center gap-8 mt-12 mb-16">
            <div class="w-28 h-28 rounded-full bg-slate-300 overflow-hidden flex items-center justify-center text-5xl text-white shadow-md">👩‍💼</div>
            <div class="text-center">
                <h3 class="text-2xl font-bold text-slate-800">Meet your host — Elena Marchetti</h3>
                <p class="text-slate-600 mt-2 max-w-2xl">Former Head of Acquisitions at Heitman, Elena has deployed over €1.2B in European real estate.</p>
            </div>
        </div>
        <div class="my-24 text-center">
            <span class="text-[#1e4a7a] font-semibold text-sm tracking-wider uppercase bg-slate-100 px-4 py-1.5 rounded-full">why attend</span>
            <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mt-5 mb-12 max-w-2xl mx-auto">In one session you'll discover how to:</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 text-left">
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">🔑</div><h3 class="text-xl font-bold text-slate-800 mb-2">Access off‑market deals</h3><p class="text-slate-600">We reveal the exact relationships and platforms that let you see deals 48h before institutions.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">📊</div><h3 class="text-xl font-bold text-slate-800 mb-2">Model returns like a pro</h3><p class="text-slate-600">Step‑by‑step walkthrough of the 10‑minute underwriting template used by our analysts.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">⚖️</div><h3 class="text-xl font-bold text-slate-800 mb-2">Legal & tax shortcuts</h3><p class="text-slate-600">Common structures for non‑US investors to minimise withholding and optimize carry.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">🤝</div><h3 class="text-xl font-bold text-slate-800 mb-2">Co‑investment negotiation</h3><p class="text-slate-600">How to negotiate reduced fees and promoted interest even with €50k tickets.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">📈</div><h3 class="text-xl font-bold text-slate-800 mb-2">Portfolio diversification</h3><p class="text-slate-600">Learn why industrial/logistics assets currently offer better risk‑adjusted returns than residential.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">🎯</div><h3 class="text-xl font-bold text-slate-800 mb-2">Actionable Q&A session</h3><p class="text-slate-600">Live Q&A with our investment team — bring your specific questions.</p></div>
            </div>
        </div>
        <div class="my-20">
            <div class="text-center mb-10">
                <span class="text-[#1e4a7a] font-semibold text-sm tracking-wider uppercase bg-slate-100 px-4 py-1.5 rounded-full">reviews</span>
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mt-5">What investors say</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white/80 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-amber-200 text-[#0f2b44] flex items-center justify-center font-bold">AR</div>
                            <div class="text-sm font-semibold text-slate-700">Angel R.</div>
                        </div>
                        <div class="flex text-amber-400">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                        </div>
                    </div>
                    <p class="text-slate-700 mt-4">Clear, actionable and professional. The framework helped me screen deals in minutes and ask sharper questions.</p>
                </div>
                <div class="bg-white/80 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-amber-300 text-[#0f2b44] flex items-center justify-center font-bold">LM</div>
                            <div class="text-sm font-semibold text-slate-700">Lina M.</div>
                        </div>
                        <div class="flex text-amber-400">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                        </div>
                    </div>
                    <p class="text-slate-700 mt-4">Great insider perspective on co‑investment terms. I used the checklist to negotiate my first ticket.</p>
                </div>
                <div class="bg-white/80 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-amber-100 text-[#0f2b44] flex items-center justify-center font-bold">DK</div>
                            <div class="text-sm font-semibold text-slate-700">Dmitri K.</div>
                        </div>
                        <div class="flex text-amber-400">
                            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                        </div>
                    </div>
                    <p class="text-slate-700 mt-4">Concise and practical. The underwriting template alone was worth it.</p>
                </div>
            </div>

        <!-- ========== SECTION 4.4: SECOND CTA SECTION ========== -->
        <div class="bg-[#0f2b44] text-white rounded-3xl p-10 md:p-14 shadow-2xl mt-16 mb-20">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Reserve your virtual seat now</h2>
                <p class="text-lg text-slate-300 mb-8">Thursday 24 April · 6pm BST | 1pm EST · free live stream + replay</p>
                <a href="registration.php" class="inline-flex items-center gap-3 bg-amber-400 hover:bg-amber-300 text-[#0f2b44] font-bold text-xl px-10 py-5 rounded-full shadow-xl transition transform hover:scale-105">
                    <i class="fas fa-ticket-alt"></i> Register now — it's free
                </a>
                <p class="text-sm text-slate-400 mt-6 flex items-center justify-center gap-2"><i class="fas fa-check-circle text-amber-400"></i> No spam, unsubscribe anytime.</p>
            </div>
        </div>

        <div class="mt-16 bg-white border border-slate-100 rounded-3xl p-8 md:p-12 shadow-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <div class="space-y-4">
                    <h2 class="text-3xl font-bold text-slate-900">Contact us</h2>
                    <p class="text-slate-600">Questions about the webinar or co‑investing with us? Reach out and our team will respond within 24h.</p>
                    <div class="space-y-3">
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-envelope text-amber-500"></i><span>hello@mioym.com</span></div>
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-phone text-amber-500"></i><span>+44 20 1234 5678</span></div>
                        <div class="flex items-center gap-3 text-slate-700"><i class="fas fa-map-marker-alt text-amber-500"></i><span>Mayfair, London • EU office in Milan</span></div>
                    </div>
                </div>
                <form action="#" method="post" data-demo class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" name="c_name" placeholder="Your name" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required>
                        <input type="email" name="c_email" placeholder="Email" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required>
                    </div>
                    <input type="text" name="c_subject" placeholder="Subject" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400">
                    <textarea name="c_message" rows="5" placeholder="Your message" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-400" required></textarea>
                    <button type="submit" class="bg-[#0f2b44] hover:bg-[#1e4a7a] text-white font-bold px-6 py-3 rounded-full shadow-md transition">Send message</button>
                </form>
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
            });
        });
    </script>
</body>
</html>
