<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE settings_tbl SET setting_value = ? WHERE setting_key = ?");
        
        foreach ($_POST['settings'] as $key => $value) {
            $stmt->execute([$value, $key]);
        }
        
        $pdo->commit();
        
        // Log notification
        admin_notify($pdo, 'settings', 'Settings Updated', 'Global configuration was updated by ' . $_SESSION['admin_username'], 'admin_settings.php');
        
        // Refresh global settings array
        require __DIR__ . '/config.php';
        
        $success = 'Settings updated successfully!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error updating settings: ' . $e->getMessage();
    }
}

// Get current settings
$stmt = $pdo->query("SELECT * FROM settings_tbl ORDER BY setting_key ASC");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Settings - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="dynamic-island.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .flat-card { @apply bg-white border border-slate-200 rounded-3xl transition-all duration-300; }
        .dark-theme .flat-card { @apply bg-slate-800 border-slate-700; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 theme-transition overflow-hidden">

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 p-4 md:p-8 lg:p-12 transition-all duration-300 overflow-y-auto custom-scrollbar">
            
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
                    <div>
                        <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Global Settings</h1>
                        <p class="text-slate-500 font-medium mt-1">Manage website-wide configurations and business variables.</p>
                    </div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="update_settings" value="1">
                    
                    <div class="flat-card overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                <i class="fas fa-sliders-h"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-800">Configuration Variables</h3>
                                <p class="text-xs text-slate-500">Update these values to reflect everywhere on the site.</p>
                            </div>
                        </div>
                        
                        <div class="p-6 space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <?php foreach ($settings as $setting): ?>
                                <div class="space-y-2">
                                    <label class="block text-sm font-semibold text-slate-700 capitalize">
                                        <?php echo str_replace('_', ' ', $setting['setting_key']); ?>
                                    </label>
                                    <div class="relative group">
                                        <?php if ($setting['setting_key'] === 'enable_email_notifications'): ?>
                                            <select name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-slate-800 font-medium group-hover:bg-white">
                                                <option value="1" <?php echo $setting['setting_value'] == '1' ? 'selected' : ''; ?>>Enabled</option>
                                                <option value="0" <?php echo $setting['setting_value'] == '0' ? 'selected' : ''; ?>>Disabled</option>
                                            </select>
                                        <?php else: ?>
                                            <input type="text" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                                   class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-slate-800 font-medium group-hover:bg-white"
                                            >
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[10px] text-slate-400 font-medium italic">
                                        Last updated: <?php echo date('M d, Y H:i', strtotime($setting['updated_at'])); ?>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="p-6 bg-slate-50/50 border-t border-slate-100 flex justify-end">
                            <button type="submit" class="bg-[#0f2b44] hover:bg-[#1e4a7a] text-white font-bold px-8 py-3 rounded-2xl shadow-lg shadow-blue-900/10 transition-all flex items-center gap-2 transform active:scale-95">
                                <i class="fas fa-save"></i>
                                Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>

        </main>
    </div>

    <script>
        // Theme Management
        const body = document.body;
        const themeToggleBtn = document.getElementById('themeToggleBtn');
        const themeIcon = document.getElementById('themeIcon');
        const themeText = document.getElementById('themeText');

        function updateThemeUI(isDark) {
            if (isDark) {
                body.classList.add('dark-theme');
                themeIcon.classList.replace('fa-moon', 'fa-sun');
                themeText.textContent = 'Light Mode';
            } else {
                body.classList.remove('dark-theme');
                themeIcon.classList.replace('fa-sun', 'fa-moon');
                themeText.textContent = 'Dark Mode';
            }
        }

        // Initialize theme
        const savedTheme = localStorage.getItem('admin-theme') || 'light';
        updateThemeUI(savedTheme === 'dark');

        themeToggleBtn.addEventListener('click', () => {
            const isDark = body.classList.toggle('dark-theme');
            localStorage.setItem('admin-theme', isDark ? 'dark' : 'light');
            updateThemeUI(isDark);
        });

        // Dynamic Island Alerts
        window.addEventListener('DOMContentLoaded', () => {
            if (window.DynamicIsland) {
                <?php if ($success): ?>
                DynamicIsland.success("<?php echo addslashes($success); ?>");
                <?php endif; ?>
                <?php if ($error): ?>
                DynamicIsland.error("<?php echo addslashes($error); ?>");
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>