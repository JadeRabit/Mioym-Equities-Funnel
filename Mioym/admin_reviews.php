<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

// Handle Bulk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'hide_selected') {
            $stmt = $pdo->prepare("UPDATE feedback SET is_visible = 0 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $success = count($ids) . " reviews hidden.";
        } elseif ($action === 'show_selected') {
            $stmt = $pdo->prepare("UPDATE feedback SET is_visible = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $success = count($ids) . " reviews shown.";
        } elseif ($action === 'delete_selected') {
            $stmt = $pdo->prepare("DELETE FROM feedback WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $success = count($ids) . " reviews deleted.";
        }
    }
    header('Location: admin_reviews.php?success=' . urlencode($success));
    exit;
}

// Handle Single Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['bulk_action'])) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_visibility' && $id > 0) {
        $stmt = $pdo->prepare("UPDATE feedback SET is_visible = NOT is_visible WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Review visibility updated.";
    } elseif ($action === 'delete' && $id > 0) {
        $stmt = $pdo->prepare("DELETE FROM feedback WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Review deleted successfully.";
    }
    header('Location: admin_reviews.php?success=' . urlencode($success));
    exit;
}

$success = $_GET['success'] ?? '';

// Filtering & Sorting
$search = trim($_GET['search'] ?? '');
$rating_filter = $_GET['rating'] ?? '';
$visibility_filter = $_GET['visibility'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'newest';

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(name LIKE ? OR message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($rating_filter !== '') {
    $where[] = "rating = ?";
    $params[] = (int)$rating_filter;
}

if ($visibility_filter !== '') {
    $where[] = "is_visible = ?";
    $params[] = (int)$visibility_filter;
}

$orderBy = "created_at DESC";
if ($sort_by === 'oldest') $orderBy = "created_at ASC";
elseif ($sort_by === 'highest') $orderBy = "rating DESC, created_at DESC";
elseif ($sort_by === 'lowest') $orderBy = "rating ASC, created_at DESC";

$whereSql = implode(" AND ", $where);

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE $whereSql");
    $countStmt->execute($params);
    $total_items = $countStmt->fetchColumn();
    $total_pages = ceil($total_items / $limit);

    $stmt = $pdo->prepare("SELECT * FROM feedback WHERE $whereSql ORDER BY $orderBy LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbacks = [];
    $total_pages = 0;
}

function getInitials($name) {
    $parts = explode(' ', trim($name));
    return (count($parts) >= 2) ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1)) : strtoupper(substr($parts[0], 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews - Admin Dashboard</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.dark-theme'],
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="dynamic-island.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .theme-transition { transition: all 0.3s ease; }
        
        /* Custom Toggle Switch */
        .toggle-switch { position: relative; display: inline-block; width: 38px; height: 20px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e2e8f0; transition: .4s; border-radius: 20px; }
        .toggle-slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        input:checked + .toggle-slider { background-color: #10b981; }
        input:checked + .toggle-slider:before { transform: translateX(18px); }
        
        .dark-theme .toggle-slider { background-color: #334155; }
        
        /* Custom Scrollbar */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #cbd5e1; }
        .dark-theme .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }

        /* Tooltip style ellipsis menu */
        .ellipsis-menu { display: none; }
        .ellipsis-container:hover .ellipsis-menu { display: block; }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 theme-transition overflow-hidden">

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 p-4 md:p-8 transition-all duration-300 overflow-y-auto custom-scrollbar">
            
            <div class="w-full max-w-[1600px] mx-auto">
                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-10">
                    <div>
                        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Manage Reviews</h1>
                        <p class="text-slate-500 font-medium mt-1">Audit, filter, and control user testimonials.</p>
                    </div>
                </div>

                <!-- Advanced Filter & Actions Bar -->
                <div class="bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 rounded-[2rem] p-4 mb-8 shadow-sm flex flex-col gap-4">
                    <form method="GET" class="flex flex-wrap items-center gap-3">
                        <!-- Integrated Search Bar -->
                        <div class="relative flex-1 min-w-[300px]">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <i class="fas fa-search text-sm"></i>
                            </span>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search by name or message content..." 
                                   class="w-full pl-11 pr-4 py-3 bg-white border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all text-sm font-medium">
                        </div>

                        <!-- Dropdowns -->
                        <div class="flex items-center gap-2">
                            <select name="rating" onchange="this.form.submit()" class="pl-4 pr-10 py-3 bg-white border border-slate-100 rounded-2xl outline-none focus:ring-4 focus:ring-blue-500/5 text-sm font-bold text-slate-600 appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20fill%3D%27none%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20stroke%3D%27%236b7280%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%20stroke-width%3D%271.5%27%20d%3D%27m6%208%204%204%204-4%27%2F%3E%3C%2Fsvg%3E')] bg-[length:1.25rem_1.25rem] bg-[right_0.5rem_center] bg-no-repeat">
                                <option value="">All Ratings</option>
                                <option value="5" <?php echo $rating_filter == '5' ? 'selected' : ''; ?>>5 Stars</option>
                                <option value="4" <?php echo $rating_filter == '4' ? 'selected' : ''; ?>>4 Stars</option>
                                <option value="3" <?php echo $rating_filter == '3' ? 'selected' : ''; ?>>3 Stars</option>
                                <option value="2" <?php echo $rating_filter == '2' ? 'selected' : ''; ?>>2 Stars</option>
                                <option value="1" <?php echo $rating_filter == '1' ? 'selected' : ''; ?>>1 Star</option>
                            </select>

                            <select name="sort_by" onchange="this.form.submit()" class="pl-4 pr-10 py-3 bg-white border border-slate-100 rounded-2xl outline-none focus:ring-4 focus:ring-blue-500/5 text-sm font-bold text-slate-600 appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20fill%3D%27none%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20stroke%3D%27%236b7280%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%20stroke-width%3D%271.5%27%20d%3D%27m6%208%204%204%204-4%27%2F%3E%3C%2Fsvg%3E')] bg-[length:1.25rem_1.25rem] bg-[right_0.5rem_center] bg-no-repeat">
                                <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                <option value="highest" <?php echo $sort_by === 'highest' ? 'selected' : ''; ?>>Highest Rated</option>
                                <option value="lowest" <?php echo $sort_by === 'lowest' ? 'selected' : ''; ?>>Lowest Rated</option>
                            </select>
                        </div>

                        <a href="admin_reviews.php" class="w-12 h-12 flex items-center justify-center bg-white border border-slate-100 rounded-2xl text-slate-400 hover:text-slate-600 transition-colors">
                            <i class="fas fa-undo text-sm"></i>
                        </a>
                    </form>

                    <!-- Bulk Actions Row (Conditional) -->
                    <div id="bulkActions" class="hidden items-center gap-3 pt-4 border-t border-slate-50 animate-in fade-in slide-in-from-left-4 duration-300">
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 ml-2">Bulk Actions:</span>
                        <div class="flex gap-2">
                            <button type="button" onclick="submitBulk('show_selected')" class="px-4 py-2 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-xl hover:bg-emerald-100 transition-all border border-emerald-100">Show Selected</button>
                            <button type="button" onclick="submitBulk('hide_selected')" class="px-4 py-2 bg-slate-50 text-slate-600 text-xs font-bold rounded-xl hover:bg-slate-100 transition-all border border-slate-100">Hide Selected</button>
                            <button type="button" onclick="submitBulk('delete_selected')" class="px-4 py-2 bg-rose-50 text-rose-600 text-xs font-bold rounded-xl hover:bg-rose-100 transition-all border border-rose-100">Delete Selected</button>
                        </div>
                    </div>
                </div>

                <!-- Main Data Table -->
                <div class="bg-white border border-slate-100 rounded-[2.5rem] overflow-hidden shadow-sm">
                    <div class="overflow-x-auto custom-scrollbar">
                        <form id="reviewsForm" method="POST">
                            <input type="hidden" name="bulk_action" id="bulkActionInput">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50/50 border-b border-slate-50">
                                        <th class="py-5 px-6 w-10">
                                            <input type="checkbox" id="selectAll" class="w-5 h-5 rounded-lg border-2 border-slate-200 text-blue-600 focus:ring-0 cursor-pointer transition-all">
                                        </th>
                                        <th class="py-5 px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Reviewer</th>
                                        <th class="py-5 px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Rating</th>
                                        <th class="py-5 px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Review Snippet</th>
                                        <th class="py-5 px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Date</th>
                                        <th class="py-5 px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 text-center">Visibility</th>
                                        <th class="py-5 px-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php if (empty($feedbacks)): ?>
                                    <tr>
                                        <td colspan="7" class="py-24 px-6 text-center">
                                            <div class="max-w-xs mx-auto">
                                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6">
                                                    <i class="fas fa-comment-slash text-3xl text-slate-200"></i>
                                                </div>
                                                <h3 class="text-lg font-bold text-slate-900">No testimonials found</h3>
                                                <p class="text-slate-400 text-sm mt-2 font-medium">When users leave feedback on the landing page, they will appear here for audit.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($feedbacks as $fb): ?>
                                        <tr class="hover:bg-slate-50/30 transition-colors group">
                                            <td class="py-5 px-6">
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo $fb['id']; ?>" class="row-checkbox w-5 h-5 rounded-lg border-2 border-slate-200 text-blue-600 focus:ring-0 cursor-pointer transition-all">
                                            </td>
                                            <td class="py-5 px-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-9 h-9 rounded-xl bg-slate-100 text-[#0f2b44] flex items-center justify-center font-extrabold text-[11px] shrink-0 border border-slate-200/50">
                                                        <?php echo getInitials($fb['name']); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="text-sm font-bold text-slate-900 truncate"><?php echo htmlspecialchars($fb['name']); ?></div>
                                                        <div class="text-[10px] font-medium text-slate-400 truncate"><?php echo htmlspecialchars($fb['email'] ?? 'Anonymous'); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-5 px-4">
                                                <?php 
                                                    $r = (int)$fb['rating'];
                                                    $sentimentClass = ($r >= 4) ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : (($r <= 2) ? 'bg-rose-50 text-rose-600 border-rose-100' : 'bg-amber-50 text-amber-600 border-amber-100');
                                                ?>
                                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border <?php echo $sentimentClass; ?>">
                                                    <span class="text-xs font-black"><?php echo $r; ?></span>
                                                    <i class="fas fa-star text-[8px]"></i>
                                                </div>
                                            </td>
                                            <td class="py-5 px-4 max-w-xs">
                                                <p class="text-sm text-slate-600 font-medium line-clamp-1 italic" title="<?php echo htmlspecialchars($fb['message']); ?>">
                                                    "<?php echo htmlspecialchars($fb['message']); ?>"
                                                </p>
                                            </td>
                                            <td class="py-5 px-4">
                                                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-tight">
                                                    <?php echo date('M d, Y', strtotime($fb['created_at'])); ?>
                                                </div>
                                                <div class="text-[10px] font-medium text-slate-400 mt-0.5">
                                                    <?php echo date('h:i A', strtotime($fb['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td class="py-5 px-4">
                                                <div class="flex justify-center">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" onchange="submitToggle(<?php echo $fb['id']; ?>)" <?php echo $fb['is_visible'] ? 'checked' : ''; ?>>
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6 text-right">
                                                <div class="flex items-center justify-end gap-2">
                                                    <!-- Ghost Delete Button -->
                                                    <button type="button" 
                                                            onclick="openDeleteModal(<?php echo $fb['id']; ?>, '<?php echo addslashes(htmlspecialchars($fb['name'])); ?>')"
                                                            class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-300 hover:text-rose-600 hover:bg-rose-50 transition-all group/btn">
                                                        <i class="far fa-trash-alt text-sm group-hover/btn:scale-110"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="bg-slate-50/50 border-t border-slate-50 px-6 py-5 flex items-center justify-between">
                        <div class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                            Showing <?php echo count($feedbacks); ?> of <?php echo $total_items; ?> results
                        </div>
                        <div class="flex gap-1.5">
                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&rating=<?php echo urlencode($rating_filter); ?>&sort_by=<?php echo urlencode($sort_by); ?>" 
                               class="w-10 h-10 flex items-center justify-center rounded-xl text-xs font-black transition-all <?php echo $page == $i ? 'bg-slate-900 text-white shadow-lg' : 'bg-white border border-slate-100 text-slate-500 hover:bg-slate-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm animate-in fade-in duration-300"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-[2.5rem] w-full max-w-md overflow-hidden shadow-2xl animate-in zoom-in-95 duration-300">
                <div class="p-8 text-center">
                    <div class="w-20 h-20 bg-rose-50 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-exclamation-triangle text-3xl text-rose-500"></i>
                    </div>
                    <h3 class="text-2xl font-black text-slate-900 tracking-tight mb-2">Delete Review?</h3>
                    <p class="text-slate-500 font-medium leading-relaxed mb-8">
                        Are you sure you want to delete the review from <span id="deleteReviewName" class="font-bold text-slate-900"></span>? This action is permanent and cannot be undone.
                    </p>
                    
                    <div class="flex flex-col gap-3">
                        <form method="POST">
                            <input type="hidden" name="id" id="deleteReviewId">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="w-full py-4 bg-rose-600 hover:bg-rose-700 text-white font-black rounded-2xl transition-all shadow-lg shadow-rose-200">
                                Yes, Delete Permanently
                            </button>
                        </form>
                        <button type="button" onclick="closeDeleteModal()" class="w-full py-4 bg-slate-100 hover:bg-slate-200 text-slate-600 font-black rounded-2xl transition-all">
                            No, Keep Review
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Selection Logic
        const selectAll = document.getElementById('selectAll');
        const rowCheckboxes = document.querySelectorAll('.row-checkbox');
        const bulkActions = document.getElementById('bulkActions');
        const bulkActionInput = document.getElementById('bulkActionInput');
        const reviewsForm = document.getElementById('reviewsForm');

        function updateBulkVisibility() {
            const checkedCount = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
            if (checkedCount > 0) {
                bulkActions.classList.remove('hidden');
                bulkActions.classList.add('flex');
            } else {
                bulkActions.classList.add('hidden');
                bulkActions.classList.remove('flex');
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                rowCheckboxes.forEach(cb => cb.checked = selectAll.checked);
                updateBulkVisibility();
            });
        }

        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkVisibility);
        });

        function submitBulk(action) {
            if (action === 'delete_selected' && !confirm('Are you sure you want to delete all selected reviews?')) return;
            bulkActionInput.value = action;
            reviewsForm.submit();
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                if (window.DynamicIsland) {
                    DynamicIsland.success("Review text copied to clipboard!");
                } else {
                    alert('Review text copied to clipboard!');
                }
            });
        }

        // Delete Modal Logic
        const deleteModal = document.getElementById('deleteModal');
        const deleteReviewId = document.getElementById('deleteReviewId');
        const deleteReviewName = document.getElementById('deleteReviewName');

        function openDeleteModal(id, name) {
            deleteReviewId.value = id;
            deleteReviewName.innerText = name;
            deleteModal.classList.remove('hidden');
        }

        function closeDeleteModal() {
            deleteModal.classList.add('hidden');
        }

        // Toggle Visibility Logic
        function submitToggle(id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="action" value="toggle_visibility">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Initialize Dynamic Island Success
        <?php if ($success): ?>
        window.addEventListener('DOMContentLoaded', () => {
            if (window.DynamicIsland) {
                DynamicIsland.success("<?php echo addslashes($success); ?>");
            }
        });
        <?php endif; ?>

        // Close on Esc
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeDeleteModal();
        });
    </script>
</body>
</html>
