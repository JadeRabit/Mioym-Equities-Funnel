<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$success = $_GET['success'] ?? '';

try {
    $pdo->exec("DELETE FROM admin_notifications WHERE type = 'registrants' AND created_at < (NOW() - INTERVAL 3 HOUR)");
} catch (Throwable $e) { }

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        $pdo->exec("UPDATE admin_notifications SET is_read = 1 WHERE type = 'registrants'");
        header('Location: admin_notifications_list.php?success=All marked as read');
        exit;
    } elseif ($action === 'delete_selected') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (!$ids) {
            header('Location: admin_notifications_list.php?success=No notifications selected');
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE type = 'registrants' AND id IN ($placeholders)");
        $stmt->execute($ids);
        header('Location: admin_notifications_list.php?success=Selected notifications deleted');
        exit;
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header('Location: admin_notifications_list.php?success=Notification deleted');
        exit;
    } elseif ($action === 'mark_read' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header('Location: admin_notifications_list.php');
        exit;
    }
}

// Filtering
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all'; // all, accredited, regular

$where = ["type = 'registrants'"];
$params = [];

if ($search !== '') {
    $where[] = "(title LIKE ? OR message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter === 'accredited') {
    $where[] = "message LIKE '%(Accredited)%'";
} elseif ($status_filter === 'regular') {
    $where[] = "message NOT LIKE '%(Accredited)%'";
}

$whereSql = implode(" AND ", $where);

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_notifications WHERE $whereSql");
    $countStmt->execute($params);
    $total_items = $countStmt->fetchColumn();
    $total_pages = ceil($total_items / $limit);

    $stmt = $pdo->prepare("SELECT * FROM admin_notifications WHERE $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notifications = [];
    $total_pages = 0;
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'y',
        'm' => 'mo',
        'w' => 'w',
        'd' => 'd',
        'h' => 'h',
        'i' => 'm',
        's' => 's',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications · Mioym Admin</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="dynamic-island.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .notification-row:hover .quick-actions { opacity: 1; transform: translateX(0); }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 overflow-hidden">

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col min-w-0 bg-white md:bg-[#f8fafc] overflow-hidden">
            
            <!-- Refined Header & Filter Bar -->
            <div class="bg-white border-b border-slate-100 px-6 py-6 md:px-10">
                <div class="w-full max-w-[1600px] mx-auto">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-3">
                                Registration Feed
                                <?php 
                                    $unreadCount = 0;
                                    foreach($notifications as $n) if(!$n['is_read']) $unreadCount++;
                                    if($unreadCount > 0): 
                                ?>
                                <span class="bg-blue-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full uppercase tracking-tighter animate-pulse">New</span>
                                <?php endif; ?>
                            </h1>
                            <p class="text-slate-400 text-sm font-medium">Real-time activity stream of webinar sign-ups.</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="text-[11px] font-black uppercase tracking-widest text-slate-400 hover:text-blue-600 transition-colors flex items-center gap-2 px-3 py-2 rounded-xl hover:bg-blue-50">
                                    <i class="fas fa-check-double"></i> Mark all read
                                </button>
                            </form>

                            <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-50 border border-slate-100">
                                <input id="selectAllNotifications" type="checkbox" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <label for="selectAllNotifications" class="text-[11px] font-black uppercase tracking-widest text-slate-400 cursor-pointer">Select all</label>
                            </div>

                            <form id="bulkDeleteForm" method="POST" class="inline" onsubmit="return confirm('Delete selected notifications?');">
                                <input type="hidden" name="action" value="delete_selected">
                                <button id="bulkDeleteBtn" type="submit" disabled class="text-[11px] font-black uppercase tracking-widest text-slate-300 transition-colors flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-50 border border-slate-100 disabled:opacity-60 disabled:cursor-not-allowed hover:bg-rose-50 hover:text-rose-600">
                                    <i class="far fa-trash-alt"></i> Delete selected
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Compact Filter Row -->
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <form method="GET" class="flex-1 flex flex-wrap items-center gap-3">
                            <div class="relative flex-1 min-w-[240px]">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search registrants..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-medium focus:ring-4 focus:ring-blue-500/5 outline-none transition-all">
                            </div>
                            
                            <select name="status" onchange="this.form.submit()" class="pl-4 pr-10 py-2.5 bg-slate-50 border border-slate-100 rounded-2xl text-xs font-bold text-slate-500 appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20fill%3D%27none%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20stroke%3D%27%2394a3b8%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%20stroke-width%3D%271.5%27%20d%3D%27m6%208%204%204%204-4%27%2F%3E%3C%2Fsvg%3E')] bg-[length:1.25rem_1.25rem] bg-[right_0.5rem_center] bg-no-repeat">
                                <option value="all">All Registrants</option>
                                <option value="accredited" <?php echo $status_filter === 'accredited' ? 'selected' : ''; ?>>Accredited Only</option>
                                <option value="regular" <?php echo $status_filter === 'regular' ? 'selected' : ''; ?>>Regular Only</option>
                            </select>

                            <a href="admin_notifications_list.php" class="w-10 h-10 flex items-center justify-center bg-slate-50 border border-slate-100 rounded-2xl text-slate-400 hover:text-slate-600 transition-all">
                                <i class="fas fa-undo text-xs"></i>
                            </a>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Notification Stream -->
            <div class="flex-1 overflow-y-auto custom-scrollbar p-4 md:p-10">
                <div class="w-full max-w-[1600px] mx-auto">
                    <div class="space-y-2">
                        <?php if (empty($notifications)): ?>
                        <div class="py-20 text-center">
                            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-bell-slash text-slate-200 text-xl"></i>
                            </div>
                            <h3 class="text-slate-900 font-bold">Inbox zero</h3>
                            <p class="text-slate-400 text-sm mt-1">No registration notifications found.</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): 
                                $isAccredited = strpos($n['message'], '(Accredited)') !== false;
                                $cleanMessage = str_replace(['New registration: ', '(Accredited)'], '', $n['message']);
                                $parts = explode(' registered ', $cleanMessage);
                                $name = $parts[0] ?? 'Someone';
                                $email = str_replace(['(', ')'], '', $parts[1] ?? '');
                            ?>
                            <div class="notification-row group relative bg-white border border-slate-100 rounded-2xl p-4 transition-all duration-300 hover:shadow-lg hover:shadow-slate-200/50 hover:-translate-y-0.5 <?php echo !$n['is_read'] ? 'bg-blue-50/40 border-blue-100/50' : ''; ?>">
                                <div class="flex items-center gap-4">
                                    <input type="checkbox" class="notif-checkbox w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" form="bulkDeleteForm" name="ids[]" value="<?php echo (int)$n['id']; ?>">
                                    <!-- Status Dot -->
                                    <div class="w-2 h-2 rounded-full <?php echo !$n['is_read'] ? 'bg-blue-600 shadow-[0_0_8px_rgba(37,99,235,0.5)]' : 'bg-transparent'; ?> shrink-0"></div>
                                    
                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-sm font-black text-slate-900"><?php echo htmlspecialchars($name); ?></span>
                                            <?php if($isAccredited): ?>
                                            <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-600 border border-amber-100 px-2 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-tighter">
                                                <i class="fas fa-crown text-[8px]"></i> Accredited
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[11px] font-medium text-slate-400 truncate mt-0.5">
                                            <?php echo htmlspecialchars($email); ?> · <?php echo htmlspecialchars($n['title']); ?>
                                        </div>
                                    </div>

                                    <!-- Time & Actions -->
                                    <div class="flex items-center gap-6 shrink-0">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tight whitespace-nowrap">
                                            <?php echo time_elapsed_string($n['created_at']); ?>
                                        </span>

                                        <!-- Quick Actions (Hover Only) -->
                                        <div class="quick-actions opacity-0 translate-x-4 transition-all duration-300 flex items-center gap-1">
                                            <?php if(!$n['is_read']): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <button type="submit" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-all" title="Mark as read">
                                                    <i class="fas fa-check text-xs"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <a href="<?php echo htmlspecialchars($n['link_url'] ?: '#'); ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition-all" title="View Details">
                                                <i class="fas fa-external-link-alt text-xs"></i>
                                            </a>

                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this notification?');">
                                                <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-300 hover:text-rose-600 hover:bg-rose-50 transition-all" title="Delete">
                                                    <i class="far fa-trash-alt text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <!-- Pagination / Load More -->
                            <?php if ($total_pages > 1): ?>
                            <div class="pt-10 flex justify-center">
                                <div class="flex items-center gap-1.5 p-1.5 bg-white border border-slate-100 rounded-2xl shadow-sm">
                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="w-9 h-9 flex items-center justify-center rounded-xl text-[11px] font-black transition-all <?php echo $page == $i ? 'bg-slate-900 text-white shadow-lg' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-600'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Dynamic Island Success
        window.addEventListener('DOMContentLoaded', () => {
            if (window.DynamicIsland && "<?php echo $success; ?>") {
                DynamicIsland.success("<?php echo addslashes($success); ?>");
            }

            const selectAll = document.getElementById('selectAllNotifications');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const checkboxes = Array.from(document.querySelectorAll('.notif-checkbox'));

            function updateBulkState() {
                const selectedCount = checkboxes.filter(cb => cb.checked).length;
                if (bulkDeleteBtn) bulkDeleteBtn.disabled = selectedCount === 0;
                if (selectAll) selectAll.checked = selectedCount > 0 && selectedCount === checkboxes.length;
            }

            selectAll?.addEventListener('change', () => {
                const checked = !!selectAll.checked;
                checkboxes.forEach(cb => { cb.checked = checked; });
                updateBulkState();
            });

            checkboxes.forEach(cb => cb.addEventListener('change', updateBulkState));
            updateBulkState();
        });
    </script>
</body>
</html>
