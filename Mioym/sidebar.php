<!-- Sidebar Component -->
<?php 
$current_page = basename($_SERVER['PHP_SELF']); 
$adminUsername = $_SESSION['admin_username'] ?? null;
$adminSessionId = $_SESSION['admin_session_id'] ?? null;

if (isset($_SESSION['admin_logged_in']) && $adminUsername && isset($pdo)) {
    if (isset($_GET['mark_notifications_read']) && $_GET['mark_notifications_read'] === '1') {
        try {
            $pdo->exec("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
        } catch (Throwable $e) {
        }
        $dest = strtok($_SERVER['REQUEST_URI'], '?');
        $qs = $_GET;
        unset($qs['mark_notifications_read']);
        $redir = $dest . (count($qs) ? '?' . http_build_query($qs) : '');
        header('Location: ' . $redir);
        exit;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(120) NOT NULL,
                session_id VARCHAR(128) NOT NULL,
                ip_address VARCHAR(64) NULL,
                user_agent TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP NULL
            )
        ");
    } catch (Throwable $e) {
    }

    if (is_string($adminSessionId) && $adminSessionId !== '') {
        try {
            $stmt = $pdo->prepare("SELECT is_active FROM admin_sessions WHERE username = ? AND session_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$adminUsername, $adminSessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['is_active'] !== 1) {
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
                }
                session_destroy();
                header('Location: registration.php');
                exit;
            }
            $upd = $pdo->prepare("UPDATE admin_sessions SET last_seen_at = NOW() WHERE username = ? AND session_id = ?");
            $upd->execute([$adminUsername, $adminSessionId]);
        } catch (Throwable $e) {
        }
    }
}

$page_titles = [
    'admin.php' => 'Dashboard',
    'webinars.php' => 'Webinars',
    'registrants.php' => 'Registrants',
    'emails.php' => 'Email Automation',
    'admin_profile.php' => 'Profile'
];
$current_title = $page_titles[$current_page] ?? 'Admin';
?>

<style>
    /* Theme transitions */
    .theme-transition { transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease; }
    
    /* Sidebar specific */
    :root {
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 80px;
    }
    
    body.dark-theme {
        background-color: #0f172a; /* slate-900 */
        color: #f8fafc; /* slate-50 */
    }
    
    body.dark-theme main {
        background-color: #0f172a;
    }
    
    /* Global Dark Theme Utilities */
    body.dark-theme .bg-white { background-color: #1e293b !important; border-color: #334155 !important; }
    body.dark-theme .text-slate-900 { color: #f8fafc !important; }
    body.dark-theme .text-slate-800 { color: #f1f5f9 !important; }
    body.dark-theme .text-slate-700 { color: #cbd5e1 !important; }
    body.dark-theme .text-slate-600 { color: #cbd5e1 !important; }
    body.dark-theme .text-slate-500 { color: #94a3b8 !important; }
    body.dark-theme .text-slate-400 { color: #a3b3c6 !important; }
    body.dark-theme .border-slate-100, 
    body.dark-theme .border-slate-200,
    body.dark-theme .border-slate-300 { border-color: #334155 !important; }
    body.dark-theme .bg-slate-50, 
    body.dark-theme .bg-slate-100,
    body.dark-theme .bg-slate-200 { background-color: #0f172a !important; }
    body.dark-theme .bg-slate-50\/50 { background-color: rgba(15, 23, 42, 0.5) !important; }
    body.dark-theme .bg-slate-50\/80 { background-color: rgba(15, 23, 42, 0.8) !important; }
    body.dark-theme .flat-card { background: #1e293b !important; border-color: #334155 !important; }
    body.dark-theme .glass-panel { background: rgba(30, 41, 59, 0.7) !important; border-color: rgba(51, 65, 85, 0.6) !important; }
    body.dark-theme .glass { background: rgba(30, 41, 59, 0.7) !important; border-color: rgba(51, 65, 85, 0.3) !important; }
    body.dark-theme input, 
    body.dark-theme select, 
    body.dark-theme textarea { background-color: #0f172a !important; border-color: #334155 !important; color: #f1f5f9 !important; }
    body.dark-theme .divide-slate-100 > * + *,
    body.dark-theme .divide-slate-200 > * + * { border-color: #334155 !important; }
    body.dark-theme .hover\:bg-slate-50:hover,
    body.dark-theme .hover\:bg-slate-50\/80:hover { background-color: #334155 !important; }
    body.dark-theme .bg-blue-50 { background-color: rgba(30, 58, 138, 0.2) !important; }
    body.dark-theme .bg-emerald-50 { background-color: rgba(6, 78, 59, 0.2) !important; }
    body.dark-theme .bg-rose-50 { background-color: rgba(159, 18, 57, 0.2) !important; }
    body.dark-theme .bg-amber-50 { background-color: rgba(120, 53, 15, 0.2) !important; }
    body.dark-theme .sticky-header th { background-color: #1e293b !important; }
    body.dark-theme .zebra-stripes tr:nth-child(even) { background-color: rgba(15, 23, 42, 0.4) !important; }
    body.dark-theme .ql-toolbar.ql-snow { background-color: #1e293b !important; border-color: #334155 !important; }
    body.dark-theme .ql-container.ql-snow { background-color: #0f172a !important; border-color: #334155 !important; }
    body.dark-theme .ql-editor { color: #f1f5f9 !important; }
    
    .sidebar-expanded { width: var(--sidebar-width); }
    .sidebar-collapsed { width: var(--sidebar-collapsed-width); }
    
    @media (max-width: 768px) {
        .sidebar-mobile-hidden { transform: translateX(-100%); }
        .sidebar-mobile-visible { transform: translateX(0); }
        .sidebar-expanded, .sidebar-collapsed { width: var(--sidebar-width); }
    }
    
    /* Custom Scrollbar for Sidebar */
    .sidebar-scroll::-webkit-scrollbar { width: 4px; }
    .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
    .sidebar-scroll:hover::-webkit-scrollbar-thumb { background: #475569; }
</style>

<!-- Mobile Header & Breadcrumb -->
<div class="md:hidden fixed top-0 left-0 right-0 h-16 bg-slate-900 text-white z-40 flex items-center justify-between px-4 shadow-md theme-transition">
    <div class="flex items-center gap-3">
        <button id="mobileMenuBtn" aria-label="Open menu" class="p-2 rounded-lg hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-[#1e4a7a]">
            <i class="fas fa-bars text-xl"></i>
        </button>
        <div class="font-bold tracking-wide">Mioym</div>
    </div>
    <div class="text-sm font-medium opacity-80" aria-current="page">
        <?php echo $current_title; ?>
    </div>
</div>

<!-- Mobile Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm z-40 hidden md:hidden transition-opacity duration-300 opacity-0" aria-hidden="true"></div>

<!-- Sidebar -->
<aside id="mainSidebar" class="fixed md:static inset-y-0 left-0 z-50 bg-slate-900 text-slate-300 flex flex-col sidebar-mobile-hidden sidebar-expanded transition-all duration-300 ease-in-out border-r border-slate-800 shadow-xl md:shadow-none" role="navigation" aria-label="Main Navigation">
    
    <!-- Header / Logo Area -->
    <div class="h-20 p-4 flex items-center justify-between border-b border-slate-800/50">
        <div class="flex items-center gap-3 overflow-hidden px-2 whitespace-nowrap flex-1">
            <div class="min-w-[32px] w-8 h-8 rounded-lg bg-gradient-to-br from-[#1e4a7a] to-[#2a66a7] flex items-center justify-center text-white shadow-lg">
                <i class="fas fa-chart-pie"></i>
            </div>
            <h1 class="text-white font-bold text-xl tracking-wide logo-text transition-opacity duration-300">Mioym Admin</h1>
        </div>
        
        <!-- Desktop Collapse Toggle -->
        <button id="desktopCollapseBtn" aria-label="Toggle sidebar" class="hidden md:flex items-center justify-center min-w-[32px] w-8 h-8 rounded-lg hover:bg-slate-800 transition-colors text-slate-400 hover:text-white focus:outline-none focus:ring-2 focus:ring-[#1e4a7a]">
            <i class="fas fa-chevron-left transition-transform duration-300" id="collapseIcon"></i>
        </button>
        
        <!-- Mobile Close Toggle -->
        <button id="mobileCloseBtn" aria-label="Close menu" class="md:hidden flex items-center justify-center min-w-[32px] w-8 h-8 rounded-lg hover:bg-slate-800 transition-colors text-slate-400 hover:text-white focus:outline-none focus:ring-2 focus:ring-[#1e4a7a]">
            <i class="fas fa-times text-lg"></i>
        </button>
    </div>
    
    <!-- Breadcrumb Context Area (Desktop) -->
    <div class="px-6 py-4 border-b border-slate-800/50 overflow-hidden whitespace-nowrap logo-text hidden md:block">
        <nav aria-label="Breadcrumb">
            <ol class="flex flex-col space-y-1">
                <li class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Current Section</li>
                <li class="text-sm font-medium text-slate-200 flex items-center gap-2">
                    <i class="fas fa-folder-open text-[#1e4a7a]"></i>
                    <span aria-current="page"><?php echo $current_title; ?></span>
                </li>
            </ol>
        </nav>
    </div>
    
    <!-- Navigation Links -->
    <div class="flex-1 overflow-y-auto py-4 sidebar-scroll">
        <ul class="px-3 space-y-1.5" role="menubar">
            
            <li role="none">
                <a href="admin.php" role="menuitem" class="nav-link group relative flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200 text-sm font-medium <?php echo $current_page == 'admin.php' ? 'bg-[#1e4a7a] text-white shadow-md' : 'hover:bg-slate-800 hover:text-white text-slate-400'; ?> focus:outline-none focus:ring-2 focus:ring-slate-400" aria-current="<?php echo $current_page == 'admin.php' ? 'page' : 'false'; ?>" title="Dashboard">
                    <div class="min-w-[24px] flex justify-center">
                        <i class="fas fa-home text-lg <?php echo $current_page == 'admin.php' ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'; ?> transition-colors"></i>
                    </div>
                    <span class="nav-text whitespace-nowrap transition-opacity duration-300">Dashboard</span>
                    <?php if($current_page == 'admin.php'): ?>
                    <div class="absolute left-0 top-2 bottom-2 w-1 bg-white rounded-r-full md:hidden"></div>
                    <?php endif; ?>
                </a>
            </li>

            <li role="none">
                <a href="webinars.php" role="menuitem" class="nav-link group relative flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200 text-sm font-medium <?php echo $current_page == 'webinars.php' ? 'bg-[#1e4a7a] text-white shadow-md' : 'hover:bg-slate-800 hover:text-white text-slate-400'; ?> focus:outline-none focus:ring-2 focus:ring-slate-400" aria-current="<?php echo $current_page == 'webinars.php' ? 'page' : 'false'; ?>" title="Webinars">
                    <div class="min-w-[24px] flex justify-center">
                        <i class="fas fa-video text-lg <?php echo $current_page == 'webinars.php' ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'; ?> transition-colors"></i>
                    </div>
                    <span class="nav-text whitespace-nowrap transition-opacity duration-300">Webinars</span>
                    <?php if($current_page == 'webinars.php'): ?>
                    <div class="absolute left-0 top-2 bottom-2 w-1 bg-white rounded-r-full md:hidden"></div>
                    <?php endif; ?>
                </a>
            </li>

            <li role="none">
                <a href="registrants.php" role="menuitem" class="nav-link group relative flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200 text-sm font-medium <?php echo $current_page == 'registrants.php' ? 'bg-[#1e4a7a] text-white shadow-md' : 'hover:bg-slate-800 hover:text-white text-slate-400'; ?> focus:outline-none focus:ring-2 focus:ring-slate-400" aria-current="<?php echo $current_page == 'registrants.php' ? 'page' : 'false'; ?>" title="Registrants">
                    <div class="min-w-[24px] flex justify-center">
                        <i class="fas fa-users text-lg <?php echo $current_page == 'registrants.php' ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'; ?> transition-colors"></i>
                    </div>
                    <span class="nav-text whitespace-nowrap transition-opacity duration-300">Registrants</span>
                    <?php if($current_page == 'registrants.php'): ?>
                    <div class="absolute left-0 top-2 bottom-2 w-1 bg-white rounded-r-full md:hidden"></div>
                    <?php endif; ?>
                </a>
            </li>

            <li role="none">
                <a href="emails.php" role="menuitem" class="nav-link group relative flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200 text-sm font-medium <?php echo $current_page == 'emails.php' ? 'bg-[#1e4a7a] text-white shadow-md' : 'hover:bg-slate-800 hover:text-white text-slate-400'; ?> focus:outline-none focus:ring-2 focus:ring-slate-400" aria-current="<?php echo $current_page == 'emails.php' ? 'page' : 'false'; ?>" title="Email Automation">
                    <div class="min-w-[24px] flex justify-center">
                        <i class="fas fa-paper-plane text-lg <?php echo $current_page == 'emails.php' ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'; ?> transition-colors"></i>
                    </div>
                    <span class="nav-text whitespace-nowrap transition-opacity duration-300">Email Automation</span>
                    <?php if($current_page == 'emails.php'): ?>
                    <div class="absolute left-0 top-2 bottom-2 w-1 bg-white rounded-r-full md:hidden"></div>
                    <?php endif; ?>
                </a>
            </li>

            <li role="none">
                <a href="admin_profile.php" role="menuitem" class="nav-link group relative flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200 text-sm font-medium <?php echo $current_page == 'admin_profile.php' ? 'bg-[#1e4a7a] text-white shadow-md' : 'hover:bg-slate-800 hover:text-white text-slate-400'; ?> focus:outline-none focus:ring-2 focus:ring-slate-400" aria-current="<?php echo $current_page == 'admin_profile.php' ? 'page' : 'false'; ?>" title="Profile">
                    <div class="min-w-[24px] flex justify-center">
                        <i class="fas fa-user-circle text-lg <?php echo $current_page == 'admin_profile.php' ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'; ?> transition-colors"></i>
                    </div>
                    <span class="nav-text whitespace-nowrap transition-opacity duration-300">Profile</span>
                    <?php if($current_page == 'admin_profile.php'): ?>
                    <div class="absolute left-0 top-2 bottom-2 w-1 bg-white rounded-r-full md:hidden"></div>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </div>

    <!-- Footer Actions: Theme Toggle & Logout -->
    <div class="p-4 border-t border-slate-800/50 space-y-2">
        <button id="themeToggleBtn" aria-label="Toggle dark/light mode" class="w-full flex items-center gap-3 px-3 py-3 rounded-xl hover:bg-slate-800 transition-colors text-sm font-medium text-slate-400 hover:text-white overflow-hidden focus:outline-none focus:ring-2 focus:ring-slate-400" title="Toggle Theme">
            <div class="min-w-[24px] flex justify-center">
                <i class="fas fa-moon text-lg transition-transform duration-300" id="themeIcon"></i>
            </div>
            <span class="nav-text whitespace-nowrap transition-opacity duration-300" id="themeText">Dark Mode</span>
        </button>
        
        <a href="admin.php?logout=true" role="menuitem" class="group flex items-center gap-3 px-3 py-3 rounded-xl hover:bg-rose-500/10 hover:text-rose-400 transition-colors text-sm font-medium text-slate-400 overflow-hidden focus:outline-none focus:ring-2 focus:ring-rose-400" title="Logout">
            <div class="min-w-[24px] flex justify-center">
                <i class="fas fa-sign-out-alt text-lg group-hover:text-rose-400 transition-colors"></i>
            </div>
            <span class="nav-text whitespace-nowrap transition-opacity duration-300">Logout</span>
        </a>
    </div>
</aside>

<!-- Spacer for mobile header to push content down -->
<div class="h-16 md:hidden"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const mobileCloseBtn = document.getElementById('mobileCloseBtn');
    const desktopCollapseBtn = document.getElementById('desktopCollapseBtn');
    const collapseIcon = document.getElementById('collapseIcon');
    const navTexts = document.querySelectorAll('.nav-text, .logo-text');
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');
    
    // Check local storage for preferences
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    const isDarkMode = localStorage.getItem('theme') === 'dark';
    
    // Apply initial theme
    if (isDarkMode) {
        document.body.classList.add('dark-theme');
        themeIcon.classList.replace('fa-moon', 'fa-sun');
        themeText.textContent = 'Light Mode';
    }
    
    // Apply initial sidebar state (desktop only)
    if (isCollapsed && window.innerWidth >= 768) {
        setCollapsedState(true, false);
    }
    
    // Mobile menu toggle
    function toggleMobileMenu() {
        const isHidden = sidebar.classList.contains('sidebar-mobile-hidden');
        if (isHidden) {
            sidebar.classList.remove('sidebar-mobile-hidden');
            sidebar.classList.add('sidebar-mobile-visible');
            overlay.classList.remove('hidden');
            // Small delay for transition
            setTimeout(() => overlay.classList.remove('opacity-0'), 10);
            document.body.style.overflow = 'hidden'; // Prevent scrolling
            
            // Set focus to close button for accessibility
            setTimeout(() => mobileCloseBtn.focus(), 300);
        } else {
            sidebar.classList.add('sidebar-mobile-hidden');
            sidebar.classList.remove('sidebar-mobile-visible');
            overlay.classList.add('opacity-0');
            setTimeout(() => overlay.classList.add('hidden'), 300);
            document.body.style.overflow = '';
            mobileMenuBtn.focus();
        }
    }
    
    mobileMenuBtn.addEventListener('click', toggleMobileMenu);
    mobileCloseBtn.addEventListener('click', toggleMobileMenu);
    overlay.addEventListener('click', toggleMobileMenu);
    
    // Handle escape key to close mobile menu
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && window.innerWidth < 768 && !sidebar.classList.contains('sidebar-mobile-hidden')) {
            toggleMobileMenu();
        }
    });
    
    // Desktop collapse toggle
    function setCollapsedState(collapsed, animate = true) {
        if (collapsed) {
            sidebar.classList.remove('sidebar-expanded');
            sidebar.classList.add('sidebar-collapsed');
            collapseIcon.classList.add('rotate-180');
            navTexts.forEach(el => {
                el.style.opacity = '0';
                if (!animate) {
                    el.style.display = 'none';
                } else {
                    setTimeout(() => {
                        if(sidebar.classList.contains('sidebar-collapsed')) el.style.display = 'none';
                    }, 300);
                }
            });
            desktopCollapseBtn.setAttribute('aria-expanded', 'false');
            localStorage.setItem('sidebarCollapsed', 'true');
        } else {
            sidebar.classList.add('sidebar-expanded');
            sidebar.classList.remove('sidebar-collapsed');
            collapseIcon.classList.remove('rotate-180');
            navTexts.forEach(el => {
                el.style.display = '';
                // Trigger reflow
                void el.offsetWidth;
                el.style.opacity = '1';
            });
            desktopCollapseBtn.setAttribute('aria-expanded', 'true');
            localStorage.setItem('sidebarCollapsed', 'false');
        }
    }
    
    desktopCollapseBtn.addEventListener('click', () => {
        const isCurrentlyCollapsed = sidebar.classList.contains('sidebar-collapsed');
        setCollapsedState(!isCurrentlyCollapsed);
    });
    
    // Set initial aria-expanded state
    desktopCollapseBtn.setAttribute('aria-expanded', !isCollapsed);
    
    // Theme toggle
    themeToggleBtn.addEventListener('click', () => {
        const isDark = document.body.classList.toggle('dark-theme');
        
        // Add a nice rotation animation to the icon
        themeIcon.style.transform = 'rotate(360deg)';
        setTimeout(() => {
            if (isDark) {
                themeIcon.classList.replace('fa-moon', 'fa-sun');
                themeText.textContent = 'Light Mode';
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.classList.replace('fa-sun', 'fa-moon');
                themeText.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
            themeIcon.style.transform = 'none';
        }, 150);
    });
    
    // Handle resize events to reset states if needed
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            // Reset mobile state
            if (!sidebar.classList.contains('sidebar-mobile-hidden')) {
                sidebar.classList.remove('sidebar-mobile-visible');
                sidebar.classList.add('sidebar-mobile-hidden');
                overlay.classList.add('hidden', 'opacity-0');
                document.body.style.overflow = '';
            }
            
            // Re-apply desktop state
            const shouldBeCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            setCollapsedState(shouldBeCollapsed, false);
        } else {
            // On mobile, always expanded visually within the drawer
            sidebar.classList.remove('sidebar-collapsed');
            sidebar.classList.add('sidebar-expanded');
            navTexts.forEach(el => {
                el.style.display = '';
                el.style.opacity = '1';
            });
        }
    });
});
</script>
<script defer src="dynamic-island.js"></script>
<?php if (!empty($_SESSION['di'])): $___di = $_SESSION['di']; unset($_SESSION['di']); ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var p = <?php echo json_encode($___di, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  function show(){
    if (!window.DynamicIsland) return setTimeout(show, 30);
    var t = p.title || '';
    var m = p.message || '';
    var ty = p.type || 'info';
    if (ty === 'error') DynamicIsland.error(m, t);
    else if (ty === 'warn') DynamicIsland.warn(m, t);
    else if (ty === 'success') DynamicIsland.success(m, t);
    else DynamicIsland.info(m, t);
  }
  show();
});
</script>
<?php endif; ?>
