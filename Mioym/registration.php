<?php
session_start();
require_once 'db.php';
$error = '';

if (!isset($_SESSION['csrf']) || !isset($_SESSION['csrf']['value']) || !isset($_SESSION['csrf']['expires']) || time() >= (int)$_SESSION['csrf']['expires']) {
    $_SESSION['csrf']['value'] = bin2hex(random_bytes(32));
    $_SESSION['csrf']['expires'] = time() + 900;
}
function validate_csrf($t) {
    if (!isset($_SESSION['csrf']['value']) || !isset($_SESSION['csrf']['expires'])) return false;
    if (time() >= (int)$_SESSION['csrf']['expires']) return false;
    $ok = hash_equals($_SESSION['csrf']['value'], (string)$t);
    if ($ok) {
        $_SESSION['csrf']['value'] = bin2hex(random_bytes(32));
        $_SESSION['csrf']['expires'] = time() + 900;
    }
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($csrf)) {
        $error = 'Security check failed. Please reload the form and try again.';
    } else {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if ($fullname === '' || $email === '') {
        $error = "Please fill in both Name and Email.";
    } else {
        // Fetch the active webinar ID to associate with the registrant
        $activeWebinar = $pdo->query("SELECT webinar_id FROM webinar_tbl WHERE is_published = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$activeWebinar) {
            $activeWebinar = $pdo->query("SELECT webinar_id FROM webinar_tbl WHERE status IN ('active', 'upcoming') ORDER BY `schedule_date&time` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        $webinar_id = $activeWebinar ? $activeWebinar['webinar_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO registrants_tbl (fullname, email, phone, webinar_id, registration_date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$fullname, $email, $phone, $webinar_id]);
        header("Location: thankyou.php?fullname=" . urlencode($fullname));
        exit;
    }
    }
}

$lastLead = null;
try {
    $stmt = $pdo->query("SELECT fullname, email, phone, registration_date FROM registrants_tbl ORDER BY id DESC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ts = isset($row['registration_date']) ? date('Y-m-d H:i:s', strtotime($row['registration_date'])) : '';
        $lastLead = "Name: {$row['fullname']} | Email: {$row['email']} | Phone: {$row['phone']} | Registered: {$ts}";
    }
} catch (PDOException $e) {
    // ignore preview if table not ready
}

// Dynamic schedule text
$scheduleStr = 'Schedule to be announced';
try {
    $row = $pdo->query("SELECT `schedule_date&time` FROM webinar_tbl WHERE is_published = 1 ORDER BY `schedule_date&time` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $row = $pdo->query("SELECT `schedule_date&time` FROM webinar_tbl WHERE LOWER(COALESCE(status,'')) IN ('active','upcoming','live') ORDER BY `schedule_date&time` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if ($row && !empty($row['schedule_date&time'])) {
        $base = $row['schedule_date&time'];
        $ldn = new DateTime($base, new DateTimeZone('Europe/London'));
        $ny  = new DateTime($base, new DateTimeZone('America/New_York'));
        $dateTitle = $ldn->format('l j F');
        $timeL = strtolower($ldn->format('ga')) . ' ' . $ldn->format('T');
        $timeN = strtolower($ny->format('ga')) . ' ' . $ny->format('T');
        $scheduleStr = $dateTitle . ' · ' . $timeL . ' / ' . $timeN;
    }
} catch (Exception $e) {
    // ignore and keep default
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webinar Registration · Mioym Equities</title>
    <!-- Tailwind + Font Awesome -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script defer src="dynamic-island.js"></script>
</head>
<body class="bg-gradient-to-b from-slate-50 to-white text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center p-4 md:p-6">
<?php if (!empty($_SESSION['di'])): $___di = $_SESSION['di']; unset($_SESSION['di']); ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var payload = <?php echo json_encode($___di, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  function show(){
    if (!window.DynamicIsland) return setTimeout(show, 30);
    var t = payload.title || '';
    var m = payload.message || '';
    var ty = payload.type || 'info';
    if (ty === 'error') DynamicIsland.error(m, t);
    else if (ty === 'warn') DynamicIsland.warn(m, t);
    else if (ty === 'success') DynamicIsland.success(m, t);
    else DynamicIsland.info(m, t);
  }
  show();
});
</script>
<?php endif; ?>

    <main class="w-full max-w-5xl">
        <div class="bg-white rounded-[2rem] shadow-2xl border border-slate-100 overflow-hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2">
                <div class="p-8 md:p-10">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <a href="index.php" class="text-sm text-slate-500 hover:text-slate-800 inline-flex items-center gap-1 transition">
                            <i class="fas fa-arrow-left text-xs"></i>Back to Webinar Info
                        </a>
                        <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm">
                            <i class="fas fa-rocket text-amber-500 text-xs"></i> Start a Project
                        </span>
                    </div>

                    <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-slate-900 mt-6 leading-tight">
                        Let’s Build Your Digital Future
                    </h1>
                    <p class="text-slate-600 mt-4 max-w-xl text-sm md:text-base">
                        Ready to join the webinar? Fill out the form to reserve your seat. Your registration is saved to our CRM and you’ll be redirected to the confirmation page.
                    </p>

                    <div class="mt-7 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-[#0f2b44] text-white flex items-center justify-center">
                                <i class="fas fa-phone text-sm"></i>
                            </div>
                            <div class="text-sm">
                                <div class="font-semibold text-slate-800">+63 914 566 9050</div>
                                <div class="text-slate-500">Office hotline</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-[#0f2b44] text-white flex items-center justify-center">
                                <i class="fas fa-map-marker-alt text-sm"></i>
                            </div>
                            <div class="text-sm">
                                <div class="font-semibold text-slate-800 leading-tight">2900 Westchester Ave.<br>Ste. 302 Purchase, NY 10577</div>
                                <div class="text-slate-500 mt-0.5">Mayfair office</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 sm:col-span-2">
                            <div class="w-10 h-10 rounded-full bg-[#0f2b44] text-white flex items-center justify-center">
                                <i class="fas fa-envelope text-sm"></i>
                            </div>
                            <div class="text-sm">
                                <div class="font-semibold text-slate-800">Oscar@mioym.com</div>
                                <div class="text-slate-500">Support email</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-12 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <i class="far fa-clock text-amber-500"></i> <?php echo htmlspecialchars($scheduleStr); ?>
                        </div>
                        <div class="text-xs text-slate-500 mt-2">60‑minute live training • replay available</div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-[#0f2b44] to-[#1e4a7a] p-7 md:p-9">
                    <div class="max-w-sm mx-auto">
                        <div class="text-white font-extrabold text-xl md:text-2xl tracking-tight">Webinar Registration</div>
                        <div class="text-white/80 text-sm mt-2">Complete the form to secure your spot.</div>

                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="mt-6 space-y-4.5">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div>
                                <label class="block text-sm font-semibold text-white mb-2">Your name <span class="text-white/70">*</span></label>
                                <input type="text" name="fullname" required
                                       placeholder="Name"
                                       value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>"
                                       class="w-full px-5 py-3.5 rounded-2xl bg-white/10 border border-white/20 text-white placeholder:text-white/60 focus:outline-none focus:ring-2 focus:ring-amber-400/40 focus:border-amber-400/50 transition">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-white mb-2">Your email <span class="text-white/70">*</span></label>
                                <input type="email" name="email" required
                                       placeholder="Email"
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                       class="w-full px-5 py-3.5 rounded-2xl bg-white/10 border border-white/20 text-white placeholder:text-white/60 focus:outline-none focus:ring-2 focus:ring-amber-400/40 focus:border-amber-400/50 transition">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-white mb-2">Your phone <span class="text-white/70 text-xs font-normal">(optional)</span></label>
                                <input type="tel" name="phone"
                                       placeholder="Phone"
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                       class="w-full px-5 py-3.5 rounded-2xl bg-white/10 border border-white/20 text-white placeholder:text-white/60 focus:outline-none focus:ring-2 focus:ring-amber-400/40 focus:border-amber-400/50 transition">
                            </div>

                            <?php if (!empty($error)): ?>
                            <div class="bg-white/15 text-rose-100 p-3 rounded-xl text-sm flex items-center gap-2 border border-white/20">
                                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                            </div>
                            <?php endif; ?>

                            <button type="submit" class="w-full max-w-xs mx-auto mt-3 flex items-center justify-center gap-3 rounded-full bg-amber-500 hover:bg-amber-400 text-[#0f2b44] font-semibold text-base py-3 shadow-xl shadow-amber-500/20 transition">
                                <span class="w-8 h-8 rounded-full bg-[#0f2b44] text-white flex items-center justify-center">
                                    <i class="fas fa-paper-plane"></i>
                                </span>
                                <span>Submit</span>
                            </button>
                        </form>

                        <div class="mt-7 bg-white/10 rounded-2xl p-4 border border-white/20 backdrop-blur-sm">
                            <h4 class="text-xs font-semibold text-white/90 flex items-center gap-2 mb-2">
                                <i class="fas fa-database text-amber-300"></i> Last Registration Saved:
                            </h4>
                            <div class="text-sm text-white space-y-1 min-h-[40px]">
                                <?php if ($lastLead): ?>
                                    <div class="border-l-2 border-amber-400 pl-3 py-2 bg-white/10 rounded-xl">
                                        <?php echo htmlspecialchars($lastLead); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="italic text-white/70">No registrations yet.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-xs text-slate-400 text-center mt-6">© 2025 Mioym Equities · Webinar Registration Demo</p>
    </main>

    <div id="adminLoginModal" class="fixed inset-0 z-[150] hidden opacity-0 transition-opacity">
        <div id="adminLoginOverlay" class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div id="adminLoginPanel" class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden transform opacity-0 translate-y-4 transition-all">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#1e4a7a]/10 text-[#1e4a7a] flex items-center justify-center">
                            <i class="fas fa-fingerprint"></i>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800">Admin Login</h3>
                    </div>
                    <button id="adminLoginClose" class="w-9 h-9 rounded-lg hover:bg-slate-100 text-slate-500 flex items-center justify-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST" action="login.php" class="px-6 py-5 space-y-4" id="adminLoginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="far fa-user"></i></span>
                            <input type="text" name="username" id="adminLoginUsername" required class="w-full pl-9 pr-3 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" id="adminLoginPassword" required class="w-full pl-9 pr-10 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] outline-none">
                            <button type="button" id="adminLoginToggle" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 w-8 h-8 rounded-lg flex items-center justify-center">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="pt-2 flex items-center justify-end gap-2">
                        <button type="button" id="adminLoginCancel" class="px-4 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="px-5 py-2 rounded-lg bg-[#1e4a7a] text-white font-semibold hover:bg-[#15365a]">Sign in</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('adminLoginModal');
        const overlay = document.getElementById('adminLoginOverlay');
        const panel = document.getElementById('adminLoginPanel');
        const closeBtn = document.getElementById('adminLoginClose');
        const cancelBtn = document.getElementById('adminLoginCancel');
        const userInput = document.getElementById('adminLoginUsername');
        const passInput = document.getElementById('adminLoginPassword');
        const toggleBtn = document.getElementById('adminLoginToggle');
        function openModal() {
            if (!modal) return;
            modal.classList.remove('hidden');
            requestAnimationFrame(() => {
                modal.classList.remove('opacity-0');
                panel.classList.remove('opacity-0','translate-y-4');
                panel.classList.add('opacity-100','translate-y-0');
            });
            setTimeout(() => { if (userInput) userInput.focus(); }, 150);
        }
        function closeModal() {
            if (!modal) return;
            modal.classList.add('opacity-0');
            panel.classList.add('opacity-0','translate-y-4');
            setTimeout(() => { modal.classList.add('hidden'); }, 200);
        }
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.shiftKey && (e.key === 'L' || e.key === 'l')) {
                e.preventDefault();
                openModal();
            }
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
        });
        if (overlay) overlay.addEventListener('click', closeModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if (toggleBtn) toggleBtn.addEventListener('click', () => {
            const isPw = passInput.getAttribute('type') === 'password';
            passInput.setAttribute('type', isPw ? 'text' : 'password');
            toggleBtn.firstElementChild.classList.toggle('fa-eye');
            toggleBtn.firstElementChild.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>
