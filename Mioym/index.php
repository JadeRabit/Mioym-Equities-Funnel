<?php
// index.php - Landing Page with link to registration
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
        .video-placeholder {
            background: linear-gradient(145deg, #0b1f30, #1d3b58);
            aspect-ratio: 16 / 9;
            border-radius: 1.5rem;
        }
    </style>
</head>
<body class="bg-gradient-to-b from-slate-50 to-white text-slate-800 font-sans antialiased">

    <!-- Success message if redirected from registration -->
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

    <main class="max-w-6xl mx-auto px-5 sm:px-8 py-10 md:py-16">
        <!-- header -->
        <div class="flex justify-between items-center mb-12">
            <div class="flex items-center gap-2">
                <div class="bg-[#0f2b44] text-white w-9 h-9 rounded-lg flex items-center justify-center text-xl font-semibold">M◈</div>
                <span class="font-medium text-slate-600">mioym equities</span>
            </div>
            <span class="text-sm bg-white border border-slate-200 px-4 py-2 rounded-full shadow-sm">
                <i class="far fa-calendar-alt text-amber-500 mr-1"></i> 24 Apr 2025 · 6pm BST
            </span>
        </div>

        <!-- HERO SECTION -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mb-20">
            <div>
                <p class="text-[#1e4a7a] font-semibold text-sm tracking-wide uppercase mb-3 flex items-center gap-2">
                    <span class="w-8 h-0.5 bg-amber-400"></span> exclusive live training
                </p>
                <h1 class="text-4xl md:text-5xl lg:text-5xl font-extrabold tracking-tight text-slate-900 leading-tight">
                    How to deploy €50k–€500k into <span class="text-[#1e4a7a]">institutional real estate</span> (without connections)
                </h1>
                <p class="text-lg md:text-xl text-slate-600 mt-6 max-w-lg">
                    Join our free 60‑minute webinar and learn the exact framework used by family offices to source off‑market multifamily & industrial assets.
                </p>
                <ul class="mt-8 space-y-3">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-amber-500 text-xl mt-0.5"></i>
                        <span class="text-slate-700"><strong class="text-slate-900">9‑figure deal sourcing:</strong> how we find deals before they hit the market</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-amber-500 text-xl mt-0.5"></i>
                        <span class="text-slate-700"><strong class="text-slate-900">co‑investment terms:</strong> negotiate like a pro (even with smaller tickets)</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-amber-500 text-xl mt-0.5"></i>
                        <span class="text-slate-700"><strong class="text-slate-900">tax & legal hacks:</strong> structure your holdings for maximum efficiency</span>
                    </li>
                </ul>
                <!-- CTA BUTTON linking to registration.php -->
                <div class="flex flex-wrap items-center gap-4 mt-10">
                    <a href="registration.php" class="bg-[#1e4a7a] hover:bg-[#123a5e] text-white font-bold text-lg px-8 py-4 rounded-full shadow-xl shadow-[#1e4a7a]/20 transition inline-flex items-center gap-3">
                        <i class="fas fa-calendar-check"></i> Register now — it's free
                    </a>
                    <a href="#" class="text-slate-700 font-medium hover:text-slate-900 transition flex items-center gap-2 group">
                        <i class="far fa-clock text-[#1e4a7a] group-hover:scale-105"></i> limited seats
                    </a>
                </div>
                <p class="text-sm text-slate-500 mt-6 flex items-center gap-2">
                    <span class="flex -space-x-1">
                        <span class="w-6 h-6 rounded-full bg-[#2b5f8a] border-2 border-white text-[10px] text-white flex items-center justify-center">JD</span>
                        <span class="w-6 h-6 rounded-full bg-[#3975a8] border-2 border-white text-[10px] text-white flex items-center justify-center">ML</span>
                        <span class="w-6 h-6 rounded-full bg-[#1f3b58] border-2 border-white text-[10px] text-white flex items-center justify-center">RK</span>
                    </span>
                    <span><span class="font-semibold text-slate-700">187+ investors</span> already registered this week</span>
                </p>
            </div>
            <div class="video-placeholder relative shadow-2xl rounded-2xl overflow-hidden group cursor-pointer border border-slate-200">
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

        <!-- BENEFITS SECTION -->
        <div class="my-24 text-center">
            <span class="text-[#1e4a7a] font-semibold text-sm tracking-wider uppercase bg-slate-100 px-4 py-1.5 rounded-full">why attend</span>
            <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mt-5 mb-12 max-w-2xl mx-auto">In one session you'll discover how to:</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 text-left">
                <!-- Benefits (shortened for brevity - same as before) -->
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">🔑</div><h3 class="text-xl font-bold text-slate-800 mb-2">Access off‑market deals</h3><p class="text-slate-600">We reveal the exact relationships and platforms that let you see deals 48h before institutions.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">📊</div><h3 class="text-xl font-bold text-slate-800 mb-2">Model returns like a pro</h3><p class="text-slate-600">Step‑by‑step walkthrough of the 10‑minute underwriting template used by our analysts.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">⚖️</div><h3 class="text-xl font-bold text-slate-800 mb-2">Legal & tax shortcuts</h3><p class="text-slate-600">Common structures for non‑US investors to minimise withholding and optimize carry.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">🤝</div><h3 class="text-xl font-bold text-slate-800 mb-2">Co‑investment negotiation</h3><p class="text-slate-600">How to negotiate reduced fees and promoted interest even with €50k tickets.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">📈</div><h3 class="text-xl font-bold text-slate-800 mb-2">Portfolio diversification</h3><p class="text-slate-600">Learn why industrial/logistics assets currently offer better risk‑adjusted returns than residential.</p></div>
                <div class="bg-white/70 backdrop-blur-sm border border-slate-100 rounded-3xl p-6 shadow-md hover:shadow-lg transition"><div class="w-12 h-12 bg-[#e1ecf9] rounded-xl flex items-center justify-center text-[#1e4a7a] text-2xl mb-5">🎯</div><h3 class="text-xl font-bold text-slate-800 mb-2">Actionable Q&A session</h3><p class="text-slate-600">Live Q&A with our investment team — bring your specific questions.</p></div>
            </div>
        </div>

        <!-- SECOND CTA -->
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

        <!-- SPEAKER -->
        <div class="flex flex-col sm:flex-row items-center gap-8 border-t border-slate-200 pt-12">
            <div class="w-28 h-28 rounded-full bg-slate-300 overflow-hidden flex items-center justify-center text-5xl text-white shadow-md">👩‍💼</div>
            <div class="text-center sm:text-left">
                <h3 class="text-2xl font-bold text-slate-800">Meet your host — Elena Marchetti</h3>
                <p class="text-slate-600 mt-2 max-w-2xl">Former Head of Acquisitions at Heitman, Elena has deployed over €1.2B in European real estate.</p>
            </div>
        </div>

        <!-- footer -->
        <div class="text-xs text-slate-500 flex flex-col sm:flex-row justify-between items-center mt-12 pt-6 border-t border-slate-100">
            <span>© 2025 Mioym Equities — Webinar landing. All rights reserved.</span>
            <span class="flex gap-4 mt-2 sm:mt-0">
                <a href="#" class="hover:text-slate-700 transition">Privacy</a>
                <a href="#" class="hover:text-slate-700 transition">Terms</a>
                <a href="#" class="hover:text-slate-700 transition">Contact</a>
            </span>
        </div>
    </main>

    <!-- script for placeholder links -->
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