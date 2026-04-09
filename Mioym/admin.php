<?php
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: registration.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Admin identity (for header UI)
$adminUsername = $_SESSION['admin_username'] ?? 'Admin';
try {
    $stmt = $pdo->prepare("SELECT * FROM admin_tbl WHERE username = ? LIMIT 1");
    $stmt->execute([$adminUsername]);
    $adminInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $adminInfo = [];
}
$adminDisplayName = $adminInfo['display_name'] ?? $adminUsername;
$adminEmail = $adminInfo['email'] ?? null;
$adminAvatarPath = $adminInfo['avatar_path'] ?? '';

function admin_initials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name);
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if (count($parts) >= 2) return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1));
    return strtoupper(substr($parts[0], 0, 2));
}

$adminAvatarFileOk = false;
if (is_string($adminAvatarPath) && $adminAvatarPath !== '') {
    $candidate = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $adminAvatarPath);
    if (file_exists($candidate)) $adminAvatarFileOk = true;
}
$adminAvatarInitials = admin_initials($adminDisplayName);

$notifUnread = 0;
$notifItems = [];
try {
    $notifUnread = (int)$pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0")->fetchColumn();
    $stmtN = $pdo->query("SELECT type, title, message, link_url, is_read, created_at FROM admin_notifications ORDER BY id DESC LIMIT 2");
    $notifItems = $stmtN->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $notifUnread = 0;
    $notifItems = [];
}

function admin_days_param() {
    $v = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $allowed = [7, 30, 90];
    if (!in_array($v, $allowed, true)) $v = 30;
    return $v;
}
function admin_build_url(array $overrides = [], array $removeKeys = []) {
    $params = $_GET;
    foreach ($removeKeys as $k) {
        unset($params[$k]);
    }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return '?' . http_build_query($params);
}
function admin_build_url_with_days($days) {
    return admin_build_url(['days' => (int)$days], ['mark_notifications_read', 'logout']);
}

$rangeDays = admin_days_param();
$rangeLabel = $rangeDays === 7 ? 'Last 7 Days' : ($rangeDays === 90 ? 'Last 90 Days' : 'Last 30 Days');
$rangeWhereSql = "registration_date >= DATE_SUB(CURDATE(), INTERVAL " . ($rangeDays - 1) . " DAY)";

// Summary KPIs
$totalRegistrants = (int)$pdo->query("SELECT COUNT(*) FROM registrants_tbl WHERE $rangeWhereSql")->fetchColumn();
$totalEmailsSent = (int)$pdo->query("SELECT COUNT(*) FROM registrants_tbl WHERE email_sent = 1 AND $rangeWhereSql")->fetchColumn();
$webinarsCount = (int)$pdo->query("SELECT COUNT(*) FROM webinar_tbl WHERE LOWER(COALESCE(status,'')) IN ('active','live')")->fetchColumn();

// Fetch Recent Webinars
$recentWebinars = $pdo->query("
    SELECT w.*, 
           (SELECT COUNT(*) FROM registrants_tbl r WHERE r.webinar_id = w.webinar_id) as participant_count
    FROM webinar_tbl w 
    ORDER BY `schedule_date&time` DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Dynamic trends
$currentRegistrations = (int)$pdo->query("
    SELECT COUNT(*) FROM registrants_tbl
    WHERE $rangeWhereSql
")->fetchColumn();
$previousRegistrations = (int)$pdo->query("
    SELECT COUNT(*) FROM registrants_tbl
    WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL " . ((2 * $rangeDays) - 1) . " DAY)
      AND registration_date < DATE_SUB(CURDATE(), INTERVAL " . ($rangeDays - 1) . " DAY)
")->fetchColumn();
$regDelta = $currentRegistrations - $previousRegistrations;
$registrantsTrendVal = $previousRegistrations > 0 ? ($regDelta / $previousRegistrations) * 100 : ($currentRegistrations > 0 ? 100 : 0);
$registrantsTrend = ($registrantsTrendVal >= 0 ? '+' : '') . number_format($registrantsTrendVal, 1) . '%';

// Emails delivered: selected range vs previous range
$currentEmails = (int)$pdo->query("
    SELECT COUNT(*) FROM registrants_tbl
    WHERE email_sent = 1
      AND $rangeWhereSql
")->fetchColumn();
$previousEmails = (int)$pdo->query("
    SELECT COUNT(*) FROM registrants_tbl
    WHERE email_sent = 1
      AND registration_date >= DATE_SUB(CURDATE(), INTERVAL " . ((2 * $rangeDays) - 1) . " DAY)
      AND registration_date < DATE_SUB(CURDATE(), INTERVAL " . ($rangeDays - 1) . " DAY)
")->fetchColumn();
$emailDelta = $currentEmails - $previousEmails;
$emailsTrendVal = $previousEmails > 0 ? ($emailDelta / $previousEmails) * 100 : ($currentEmails > 0 ? 100 : 0);
$emailsTrend = ($emailsTrendVal >= 0 ? '+' : '') . number_format($emailsTrendVal, 1) . '%';

// Webinars: upcoming next range vs previous range
$currentWebinars30 = (int)$pdo->query("
    SELECT COUNT(*) FROM webinar_tbl 
    WHERE LOWER(COALESCE(status,'')) IN ('active','live')
      AND `schedule_date&time` >= NOW()
      AND `schedule_date&time` < DATE_ADD(NOW(), INTERVAL " . $rangeDays . " DAY)
")->fetchColumn();
$previousWebinars30 = (int)$pdo->query("
    SELECT COUNT(*) FROM webinar_tbl 
    WHERE LOWER(COALESCE(status,'')) IN ('active','live')
      AND `schedule_date&time` >= DATE_SUB(NOW(), INTERVAL " . $rangeDays . " DAY)
      AND `schedule_date&time` < NOW()
")->fetchColumn();
$webinarsTrendNum = $currentWebinars30 - $previousWebinars30;
$webinarsTrend = ($webinarsTrendNum >= 0 ? '+' : '') . $webinarsTrendNum;

// Area chart data: selected range registrations
$rows = $pdo->query("
    SELECT DATE(registration_date) AS d, COUNT(*) AS c
    FROM registrants_tbl
    WHERE $rangeWhereSql
    GROUP BY DATE(registration_date)
    ORDER BY d
")->fetchAll(PDO::FETCH_KEY_PAIR);

$areaLabels = [];
$areaData = [];
if ($rangeDays <= 30) {
    for ($i = $rangeDays - 1; $i >= 0; $i--) {
        $date = new DateTime();
        $date->modify("-$i day");
        $key = $date->format('Y-m-d');
        $areaLabels[] = $date->format('M j');
        $areaData[] = isset($rows[$key]) ? (int)$rows[$key] : 0;
    }
} else {
    $weekBuckets = [];
    for ($i = $rangeDays - 1; $i >= 0; $i--) {
        $date = new DateTime();
        $date->modify("-$i day");
        $key = $date->format('Y-m-d');
        $weekKey = $date->format('o-\WW');
        if (!isset($weekBuckets[$weekKey])) {
            $weekBuckets[$weekKey] = 0;
        }
        $weekBuckets[$weekKey] += isset($rows[$key]) ? (int)$rows[$key] : 0;
    }
    foreach ($weekBuckets as $wk => $count) {
        $areaLabels[] = $wk;
        $areaData[] = (int)$count;
    }
}

// Doughnut chart data: attendee sources if available; else sensible fallback
$hasSource = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'registrants_tbl' 
      AND column_name = 'source'
")->fetchColumn() > 0;

$doughnutLabels = [];
$doughnutData = [];
$doughnutColors = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b'];

if ($hasSource) {
    $sourceRows = $pdo->query("
        SELECT LOWER(TRIM(source)) AS s, COUNT(*) AS c
        FROM registrants_tbl
        WHERE $rangeWhereSql
        GROUP BY s
    ")->fetchAll(PDO::FETCH_ASSOC);
    $map = [
        'social' => ['Social Media', 0],
        'facebook' => ['Social Media', 0],
        'instagram' => ['Social Media', 0],
        'email' => ['Email Campaign', 1],
        'campaign' => ['Email Campaign', 1],
        'direct' => ['Direct', 2],
        'organic' => ['Direct', 2],
        'referral' => ['Referrals', 3],
        'referrals' => ['Referrals', 3]
    ];
    $buckets = [0,0,0,0];
    foreach ($sourceRows as $r) {
        $s = $r['s'];
        $count = (int)$r['c'];
        if (isset($map[$s])) {
            $buckets[$map[$s][1]] += $count;
        } else {
            // Unmapped -> Direct
            $buckets[2] += $count;
        }
    }
    $doughnutLabels = ['Social Media','Email Campaign','Direct','Referrals'];
    $doughnutData = $buckets;
} else {
    $linkedToWebinar = (int)$pdo->query("SELECT COUNT(*) FROM registrants_tbl WHERE webinar_id IS NOT NULL AND $rangeWhereSql")->fetchColumn();
    $emailOnly = (int)$pdo->query("SELECT COUNT(*) FROM registrants_tbl WHERE webinar_id IS NULL AND email_sent = 1 AND $rangeWhereSql")->fetchColumn();
    $general = (int)$pdo->query("SELECT COUNT(*) FROM registrants_tbl WHERE webinar_id IS NULL AND (email_sent = 0 OR email_sent IS NULL) AND $rangeWhereSql")->fetchColumn();
    $other = max(0, $totalRegistrants - $linkedToWebinar - $emailOnly - $general);
    $doughnutLabels = ['Linked to Webinar','Email Sent','General','Other'];
    $doughnutData = [$linkedToWebinar, $emailOnly, $general, $other];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard · Mioym Equities</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* Custom scrollbar for table container */
        .custom-scrollbar::-webkit-scrollbar { height: 6px; width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #94a3b8; }
        
        /* Glassmorphism & Flat 2.0 Utilities */
        .glass-panel {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        .flat-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.03), 0 0 3px rgba(0,0,0,0.02);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .flat-card:hover {
            box-shadow: 0 10px 30px -4px rgba(0, 0, 0, 0.06), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            transform: translateY(-2px);
        }
        
        /* Chart specific */
        .chart-container { position: relative; height: 100%; width: 100%; }
        
        /* Font setup */
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        .font-jakarta { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#f4f7f9] text-slate-800 font-jakarta antialiased min-h-screen flex overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden relative">
        
        <!-- Subtle Grid Background -->
        <div class="absolute inset-0 z-0 pointer-events-none opacity-[0.03]" style="background-image: radial-gradient(#1e293b 1px, transparent 1px); background-size: 32px 32px;"></div>

        <!-- Top Bar (Glassmorphic) -->
        <header class="h-20 glass-panel border-b border-slate-200/60 flex items-center justify-between px-8 z-20 shrink-0 hidden md:flex sticky top-0">
            <!-- Search -->
            <div class="relative w-[400px] group">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-search text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                </div>
                <input type="text" placeholder="Search webinars, registrants, campaigns..." class="w-full pl-11 pr-4 py-2.5 bg-slate-100/50 hover:bg-white border border-transparent focus:border-blue-500/30 focus:bg-white rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-blue-500/10 transition-all text-slate-700 placeholder-slate-400 shadow-sm">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <span class="text-[10px] font-semibold text-slate-400 bg-slate-200/50 px-2 py-1 rounded-md">⌘K</span>
                </div>
            </div>

            <!-- Right Actions (Control Center) -->
            <div class="flex items-center gap-2 bg-white p-1.5 rounded-2xl shadow-sm border border-slate-100">
                <!-- Notifications -->
                <div x-data="{ notifyOpen: false }" class="relative">
                    <button @click="notifyOpen = !notifyOpen" @click.away="notifyOpen = false" class="relative p-2.5 text-slate-500 hover:text-blue-600 transition-colors rounded-xl hover:bg-blue-50 focus:outline-none">
                        <i class="far fa-bell text-[1.1rem]"></i>
                        <?php if ($notifUnread > 0): ?>
                        <span class="absolute top-2 right-2 w-2 h-2 bg-rose-500 rounded-full border-2 border-white animate-pulse"></span>
                        <?php endif; ?>
                    </button>
                    <!-- Notification Dropdown Mockup -->
                    <div x-show="notifyOpen" x-transition.opacity.duration.200ms class="absolute right-0 mt-3 w-80 bg-white rounded-2xl shadow-xl border border-slate-100 py-2 z-50" style="display: none;">
                        <div class="px-4 py-2 border-b border-slate-50 flex justify-between items-center">
                            <span class="font-bold text-sm text-slate-800">Notifications</span>
                            <a href="<?php echo htmlspecialchars(admin_build_url(['mark_notifications_read' => 1], ['logout'])); ?>" class="text-xs text-blue-600 font-bold hover:underline">Mark all read</a>
                        </div>
                        <?php if (!empty($notifItems)): ?>
                            <?php foreach ($notifItems as $n): ?>
                                <?php
                                    $type = (string)($n['type'] ?? '');
                                    $icon = 'fa-bell';
                                    $bg = 'bg-slate-100';
                                    $fg = 'text-slate-600';
                                    if ($type === 'registrants') { $icon = 'fa-user-plus'; $bg = 'bg-blue-100'; $fg = 'text-blue-600'; }
                                    elseif ($type === 'emails') { $icon = 'fa-paper-plane'; $bg = 'bg-emerald-100'; $fg = 'text-emerald-600'; }
                                    elseif ($type === 'webinars') { $icon = 'fa-video'; $bg = 'bg-purple-100'; $fg = 'text-purple-600'; }
                                    elseif ($type === 'contact') { $icon = 'fa-envelope-open-text'; $bg = 'bg-amber-100'; $fg = 'text-amber-700'; }
                                    elseif ($type === 'feedback') { $icon = 'fa-star'; $bg = 'bg-amber-100'; $fg = 'text-amber-700'; }

                                    $href = (string)($n['link_url'] ?? '');
                                    $isRead = (int)($n['is_read'] ?? 0) === 1;
                                    $ts = $n['created_at'] ?? null;
                                    $timeStr = $ts ? date('M d, g:ia', strtotime($ts)) : '';
                                ?>
                                <a href="<?php echo htmlspecialchars($href !== '' ? $href : '#'); ?>" class="px-4 py-3 hover:bg-slate-50 cursor-pointer flex gap-3 <?php echo $isRead ? '' : 'bg-blue-50/30'; ?>">
                                    <div class="w-8 h-8 rounded-full <?php echo $bg; ?> <?php echo $fg; ?> flex items-center justify-center shrink-0">
                                        <i class="fas <?php echo $icon; ?> text-xs"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm text-slate-700">
                                            <span class="font-semibold"><?php echo htmlspecialchars((string)($n['title'] ?? '')); ?></span>
                                            <?php if (!empty($n['message'])): ?>
                                                <span class="text-slate-600">— <?php echo htmlspecialchars((string)$n['message']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($timeStr); ?></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="px-4 py-10 text-center">
                                <div class="w-12 h-12 rounded-2xl bg-slate-50 border border-slate-100 text-slate-400 mx-auto flex items-center justify-center">
                                    <i class="far fa-bell"></i>
                                </div>
                                <div class="mt-3 text-sm font-bold text-slate-800">No notifications</div>
                                <div class="text-xs text-slate-500 mt-1">Activity will appear here as it happens.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="h-6 w-px bg-slate-200 mx-1"></div>
                
                <!-- Profile Dropdown -->
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" @click.away="open = false" class="flex items-center gap-3 focus:outline-none group px-2 py-1 rounded-xl hover:bg-slate-50 transition-colors">
                        <div class="relative">
                            <?php if ($adminAvatarFileOk): ?>
                                <img src="<?php echo htmlspecialchars($adminAvatarPath); ?>" alt="Admin" class="w-8 h-8 rounded-full border-2 border-white shadow-sm group-hover:border-blue-100 transition-colors object-cover">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-full border-2 border-white shadow-sm group-hover:border-blue-100 transition-colors bg-slate-900 text-white flex items-center justify-center text-[11px] font-extrabold tracking-tight">
                                    <?php echo htmlspecialchars($adminAvatarInitials); ?>
                                </div>
                            <?php endif; ?>
                            <div class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-emerald-500 border-2 border-white rounded-full"></div>
                        </div>
                        <div class="text-left hidden lg:block pr-1">
                            <p class="text-sm font-bold text-slate-700 tracking-tight"><?php echo htmlspecialchars($adminDisplayName); ?> <span class="text-slate-400 font-normal ml-1">/ Admin</span></p>
                        </div>
                        <i class="fas fa-chevron-down text-[10px] text-slate-400 group-hover:text-slate-600 transition-transform duration-200" :class="{'rotate-180': open}"></i>
                    </button>
                    
                    <div x-show="open" x-transition.opacity.duration.200ms class="absolute right-0 mt-3 w-56 bg-white rounded-2xl shadow-xl border border-slate-100 py-2 z-50" style="display: none;">
                        <div class="px-4 py-3 border-b border-slate-50 mb-1">
                            <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($adminDisplayName); ?></p>
                            <?php if (!empty($adminEmail)): ?>
                            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($adminEmail); ?></p>
                            <?php endif; ?>
                        </div>
                        <a href="admin_profile.php" class="flex items-center gap-3 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-blue-600 transition-colors"><i class="far fa-user w-4 text-center"></i> My Profile</a>
                        <div class="border-t border-slate-50 my-1"></div>
                        <a href="admin.php?logout=true" class="flex items-center gap-3 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50 transition-colors"><i class="fas fa-sign-out-alt w-4 text-center"></i> Sign out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto p-6 lg:p-8 relative z-10">
            <!-- Header & Date Picker -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end mb-8 gap-4">
                <div>
                    <h2 class="text-[28px] font-extrabold text-slate-800 tracking-tight leading-tight">Dashboard Overview</h2>
                    <p class="text-[15px] text-slate-500 mt-1 font-medium">Here's what's happening with your webinars today.</p>
                </div>
                <div x-data="{ open: false }" class="hidden sm:block relative">
                    <button @click="open = !open" @click.away="open = false" class="bg-white rounded-xl shadow-sm border border-slate-200/80 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition flex items-center gap-2">
                        <i class="far fa-calendar text-blue-500"></i> <?php echo htmlspecialchars($rangeLabel); ?> <i class="fas fa-chevron-down text-[10px] ml-1 text-slate-400" :class="{'rotate-180': open}"></i>
                    </button>
                    <div x-show="open" x-transition.opacity.duration.160ms class="absolute right-0 mt-2 w-44 bg-white rounded-2xl shadow-xl border border-slate-100 py-2 z-50" style="display:none;">
                        <a href="<?php echo htmlspecialchars(admin_build_url_with_days(7)); ?>" class="flex items-center justify-between px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <span>Last 7 Days</span>
                            <?php if ($rangeDays === 7): ?><i class="fas fa-check text-[10px] text-emerald-500"></i><?php endif; ?>
                        </a>
                        <a href="<?php echo htmlspecialchars(admin_build_url_with_days(30)); ?>" class="flex items-center justify-between px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <span>Last 30 Days</span>
                            <?php if ($rangeDays === 30): ?><i class="fas fa-check text-[10px] text-emerald-500"></i><?php endif; ?>
                        </a>
                        <a href="<?php echo htmlspecialchars(admin_build_url_with_days(90)); ?>" class="flex items-center justify-between px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <span>Last 90 Days</span>
                            <?php if ($rangeDays === 90): ?><i class="fas fa-check text-[10px] text-emerald-500"></i><?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Stat Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Card 1: Registrations -->
                <div class="flat-card p-6 relative overflow-hidden group">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl group-hover:scale-110 transition-transform duration-300 shadow-sm border border-blue-100/50">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 bg-emerald-50/80 border border-emerald-100 px-2.5 py-1 rounded-lg">
                                <i class="fas fa-arrow-up"></i> <?php echo $registrantsTrend; ?>
                            </span>
                        </div>
                    </div>
                    <div class="relative z-10">
                        <h3 class="text-4xl font-extrabold text-slate-800 tracking-tight"><?php echo number_format($totalRegistrants); ?></h3>
                        <p class="text-sm font-semibold text-slate-500 mt-1 uppercase tracking-wider">Total Registrations</p>
                    </div>
                    <!-- Decorative Element -->
                    <div class="absolute -bottom-10 -right-10 w-40 h-40 bg-gradient-to-br from-blue-50 to-transparent rounded-full opacity-50 group-hover:scale-125 transition-transform duration-700"></div>
                </div>

                <!-- Card 2: Emails -->
                <div class="flat-card p-6 relative overflow-hidden group">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-500 flex items-center justify-center text-2xl group-hover:scale-110 transition-transform duration-300 shadow-sm border border-emerald-100/50">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 bg-emerald-50/80 border border-emerald-100 px-2.5 py-1 rounded-lg">
                                <i class="fas fa-arrow-up"></i> <?php echo $emailsTrend; ?>
                            </span>
                        </div>
                    </div>
                    <div class="relative z-10">
                        <h3 class="text-4xl font-extrabold text-slate-800 tracking-tight"><?php echo number_format($totalEmailsSent); ?></h3>
                        <p class="text-sm font-semibold text-slate-500 mt-1 uppercase tracking-wider">Emails Delivered</p>
                    </div>
                    <!-- Decorative Element -->
                    <div class="absolute -bottom-10 -right-10 w-40 h-40 bg-gradient-to-br from-emerald-50 to-transparent rounded-full opacity-50 group-hover:scale-125 transition-transform duration-700"></div>
                </div>

                <!-- Card 3: Webinars -->
                <div class="flat-card p-6 relative overflow-hidden group">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-2xl group-hover:scale-110 transition-transform duration-300 shadow-sm border border-purple-100/50">
                            <i class="fas fa-video"></i>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 bg-emerald-50/80 border border-emerald-100 px-2.5 py-1 rounded-lg">
                                <i class="fas fa-plus"></i> <?php echo $webinarsTrend; ?>
                            </span>
                        </div>
                    </div>
                    <div class="relative z-10">
                        <h3 class="text-4xl font-extrabold text-slate-800 tracking-tight"><?php echo number_format($webinarsCount); ?></h3>
                        <p class="text-sm font-semibold text-slate-500 mt-1 uppercase tracking-wider">Active Webinars</p>
                    </div>
                    <!-- Decorative Element -->
                    <div class="absolute -bottom-10 -right-10 w-40 h-40 bg-gradient-to-br from-purple-50 to-transparent rounded-full opacity-50 group-hover:scale-125 transition-transform duration-700"></div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Area Chart -->
                <div class="flat-card p-6 lg:col-span-2 flex flex-col relative z-10">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-[17px] font-bold text-slate-800 tracking-tight">Registration Trends</h3>
                            <p class="text-xs text-slate-500 font-medium mt-0.5">
                                <?php if ($rangeDays <= 30): ?>
                                    Daily new registrations over the last <?php echo (int)$rangeDays; ?> days
                                <?php else: ?>
                                    Weekly new registrations over the last <?php echo (int)$rangeDays; ?> days
                                <?php endif; ?>
                            </p>
                        </div>
                        <button class="w-8 h-8 rounded-xl hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-700 transition-colors">
                            <i class="fas fa-ellipsis-h"></i>
                        </button>
                    </div>
                    <div class="flex-1 chart-container min-h-[300px]">
                        <canvas id="registrationChart"></canvas>
                    </div>
                </div>

                <!-- Doughnut Chart -->
                <div class="flat-card p-6 flex flex-col relative z-10">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="text-[17px] font-bold text-slate-800 tracking-tight">Attendee Sources</h3>
                            <p class="text-xs text-slate-500 font-medium mt-0.5">Distribution overview</p>
                        </div>
                        <button class="w-8 h-8 rounded-xl hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-700 transition-colors">
                            <i class="fas fa-ellipsis-h"></i>
                        </button>
                    </div>
                    <div class="flex-1 flex flex-col">
                        <div class="relative flex-1 min-h-[220px] flex items-center justify-center">
                            <canvas id="demographicsChart"></canvas>
                        </div>
                        
                        <!-- Custom Legend -->
                        <div id="doughnutLegend" class="grid grid-cols-2 gap-y-3 gap-x-2 mt-4 pt-4 border-t border-slate-100/80"></div>
                    </div>
                </div>
            </div>

            <!-- Actionable Table Section -->
            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] border border-slate-100 overflow-hidden mb-8">
                <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">Recent Webinars</h3>
                        <p class="text-sm text-slate-500">Manage and track your latest events.</p>
                    </div>
                    <a href="webinars.php" class="inline-flex items-center justify-center gap-2 bg-[#1e4a7a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#15365a] transition-colors shadow-sm">
                        <i class="fas fa-plus text-xs"></i> New Webinar
                    </a>
                </div>
                
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/50 text-slate-500 text-xs uppercase tracking-wider">
                                <th class="p-4 font-semibold w-1/3">Webinar Title</th>
                                <th class="p-4 font-semibold">Date & Time</th>
                                <th class="p-4 font-semibold text-center">Status</th>
                                <th class="p-4 font-semibold text-center">Participants</th>
                                <th class="p-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-slate-100">
                            <?php if(empty($recentWebinars)): ?>
                            <tr>
                                <td colspan="5" class="p-8 text-center text-slate-500">
                                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-100 text-slate-400 mb-3">
                                        <i class="fas fa-video-slash text-xl"></i>
                                    </div>
                                    <p>No webinars found. Create your first one!</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($recentWebinars as $webinar): 
                                    $status = strtolower($webinar['status'] ?? 'inactive');
                                    $statusClass = '';
                                    $statusIcon = '';
                                    if ($status === 'active' || $status === 'live') {
                                        $statusClass = 'bg-emerald-100 text-emerald-700';
                                        $statusIcon = 'fa-circle text-[8px] mr-1 text-emerald-500 animate-pulse';
                                        $statusText = 'Live';
                                    } elseif ($status === 'upcoming') {
                                        $statusClass = 'bg-blue-100 text-blue-700';
                                        $statusIcon = 'fa-calendar-alt mr-1';
                                        $statusText = 'Upcoming';
                                    } else {
                                        $statusClass = 'bg-slate-100 text-slate-600';
                                        $statusIcon = 'fa-archive mr-1';
                                        $statusText = 'Draft / Inactive';
                                    }
                                    
                                    $dateStr = strtotime($webinar['schedule_date&time']);
                                ?>
                                <tr class="hover:bg-slate-50/80 transition-colors group">
                                    <td class="p-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center text-indigo-500 shrink-0">
                                                <i class="fas fa-play"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-800 text-sm line-clamp-1"><?php echo htmlspecialchars($webinar['title']); ?></p>
                                                <p class="text-xs text-slate-500 mt-0.5">Host: <?php echo htmlspecialchars($webinar['hostname'] ?? 'TBA'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <p class="text-slate-700 font-medium"><?php echo date('M d, Y', $dateStr); ?></p>
                                        <p class="text-xs text-slate-500"><?php echo date('g:i A', $dateStr); ?></p>
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $statusClass; ?>">
                                            <i class="fas <?php echo $statusIcon; ?>"></i> <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-center">
                                        <div class="inline-flex items-center justify-center gap-1.5 bg-slate-50 border border-slate-200 px-3 py-1 rounded-lg">
                                            <i class="fas fa-user-friends text-slate-400 text-xs"></i>
                                            <span class="font-bold text-slate-700"><?php echo $webinar['participant_count']; ?></span>
                                        </div>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <a href="webinars.php" class="w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-500 flex items-center justify-center hover:text-blue-600 hover:border-blue-200 hover:bg-blue-50 transition-colors" title="Edit">
                                                <i class="fas fa-edit text-sm"></i>
                                            </a>
                                            <button class="w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-500 flex items-center justify-center hover:text-emerald-600 hover:border-emerald-200 hover:bg-emerald-50 transition-colors" title="Share Link" onclick="alert('Link copied: <?php echo htmlspecialchars($webinar['webinar_link'] ?? ''); ?>')">
                                                <i class="fas fa-link text-sm"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-slate-100 bg-slate-50/50 text-center">
                    <a href="webinars.php" class="text-sm font-semibold text-blue-600 hover:text-blue-800 transition-colors inline-flex items-center gap-1">
                        View All Webinars <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                </div>
            </div>

        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function getThemeConfig() {
                const isDark = document.body.classList.contains('dark-theme');
                return {
                    gridColor: isDark ? 'rgba(51, 65, 85, 0.4)' : '#f1f5f9',
                    textColor: isDark ? '#94a3b8' : '#64748b',
                    tooltipBg: isDark ? '#1e293b' : '#1e293b',
                    chartLineColor: '#3b82f6',
                    chartGradientStart: isDark ? 'rgba(59, 130, 246, 0.3)' : 'rgba(30, 74, 122, 0.5)',
                    chartGradientEnd: 'rgba(59, 130, 246, 0)'
                };
            }

            let theme = getThemeConfig();

            // Data from server
            const areaLabels = <?php echo json_encode($areaLabels); ?>;
            const areaData = <?php echo json_encode($areaData); ?>;
            const doughnutLabels = <?php echo json_encode($doughnutLabels); ?>;
            const doughnutData = <?php echo json_encode($doughnutData); ?>;
            const doughnutColors = <?php echo json_encode($doughnutColors); ?>;

            // Chart Defaults
            Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
            Chart.defaults.color = theme.textColor;

            let registrationChart, demographicsChart;

            function initCharts() {
                theme = getThemeConfig();
                
                // 1. Area Chart
                const ctxArea = document.getElementById('registrationChart');
                if (ctxArea) {
                    const ctx = ctxArea.getContext('2d');
                    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, theme.chartGradientStart);
                    gradient.addColorStop(1, theme.chartGradientEnd);

                    if (registrationChart) registrationChart.destroy();
                    registrationChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: areaLabels,
                            datasets: [{
                                label: 'Registrations',
                                data: areaData,
                                borderColor: theme.chartLineColor,
                                backgroundColor: gradient,
                                borderWidth: 3,
                                pointBackgroundColor: '#ffffff',
                                pointBorderColor: theme.chartLineColor,
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: theme.tooltipBg,
                                    padding: 12,
                                    titleFont: { size: 13, weight: 'bold' },
                                    bodyFont: { size: 14 },
                                    displayColors: false,
                                    cornerRadius: 8,
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    border: { display: false },
                                    grid: { color: theme.gridColor },
                                    ticks: { color: theme.textColor, maxTicksLimit: 6, padding: 10 }
                                },
                                x: {
                                    border: { display: false },
                                    grid: { display: false },
                                    ticks: { color: theme.textColor, padding: 10 }
                                }
                            }
                        }
                    });
                }

                // 2. Doughnut Chart
                const ctxDoughnut = document.getElementById('demographicsChart');
                if (ctxDoughnut) {
                    if (demographicsChart) demographicsChart.destroy();
                    demographicsChart = new Chart(ctxDoughnut.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: doughnutLabels,
                            datasets: [{
                                data: doughnutData,
                                backgroundColor: doughnutColors,
                                borderWidth: 0,
                                hoverOffset: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '75%',
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: theme.tooltipBg,
                                    padding: 12,
                                    cornerRadius: 8,
                                }
                            }
                        }
                    });

                    // Custom legend
                    const legendEl = document.getElementById('doughnutLegend');
                    if (legendEl) {
                        const total = doughnutData.reduce((a,b)=>a+b,0) || 1;
                        legendEl.innerHTML = doughnutLabels.map((label, i) => {
                            const value = doughnutData[i] || 0;
                            const pct = Math.round((value / total) * 100);
                            const color = doughnutColors[i % doughnutColors.length];
                            return `<div class="flex items-center gap-2">
                                   <span class="w-2.5 h-2.5 rounded-full" style="background-color:${color}"></span>
                                   <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">${label} <span class="text-slate-400 dark:text-slate-500 font-normal ml-1">${pct}%</span></span>
                                   </div>`;
                        }).join('');
                    }
                }
            }

            initCharts();

            // Watch for theme changes
            const themeToggleBtn = document.getElementById('themeToggleBtn');
            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', () => {
                    setTimeout(initCharts, 200); // Wait for transition
                });
            }
        });
    </script>
</body>
</html>
