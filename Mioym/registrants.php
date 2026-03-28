<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: registration.php');
    exit;
}

// Handle Bulk Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bulk') {
    $ids = explode(',', $_POST['bulk_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM registrants_tbl WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    if (function_exists('admin_notify')) {
        admin_notify($pdo, 'registrants', 'Bulk Delete', count($ids) . ' registrants deleted.', 'registrants.php');
    }
    $_SESSION['flash'] = count($ids) . " registrants deleted successfully.";
    $_SESSION['di'] = ['type'=>'warn','title'=>'Deleted','message'=>$_SESSION['flash']];
    header('Location: registrants.php');
    exit;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="registrants_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Full Name', 'Email', 'Phone', 'Registration Date', 'Webinar Title', 'Email Sent']);
    
    $stmt = $pdo->query("SELECT r.id, r.fullname, r.email, r.phone, r.registration_date, w.title as webinar_title, r.email_sent 
                         FROM registrants_tbl r 
                         LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id 
                         ORDER BY r.id DESC");
                         
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['email_sent'] = $row['email_sent'] ? 'Yes' : 'No';
        $row['webinar_title'] = $row['webinar_title'] ?? 'General/Old';
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Search & Filter Params
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'all';

// Build WHERE clause
$where_clauses = [];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(r.fullname LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category !== '') {
    if ($category === 'General/Old') {
        $where_clauses[] = "w.title IS NULL";
    } else {
        $where_clauses[] = "w.title = ?";
        $params[] = $category;
    }
}

if ($status_filter === 'sent') {
    $where_clauses[] = "r.email_sent = 1";
} elseif ($status_filter === 'pending') {
    $where_clauses[] = "r.email_sent = 0";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Pagination Logic
$limit = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Fetch Data
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM registrants_tbl r LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id $where_sql");
$total_stmt->execute($params);
$total_registrants = $total_stmt->fetchColumn();
$total_pages = ceil($total_registrants / $limit);

$query = "SELECT r.*, w.title as webinar_title 
          FROM registrants_tbl r 
          LEFT JOIN webinar_tbl w ON r.webinar_id = w.webinar_id 
          $where_sql 
          ORDER BY r.id DESC 
          LIMIT $limit OFFSET $offset";
$registrants = $pdo->prepare($query);
$registrants->execute($params);
$registrants = $registrants->fetchAll(PDO::FETCH_ASSOC);

// Fetch Webinars for Filtering
$webinars = $pdo->query("SELECT DISTINCT title FROM webinar_tbl ORDER BY title ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrants · Mioym Equities</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .sticky-header th { position: sticky; top: 0; background: #f8fafc; z-index: 10; }
        .zebra-stripes tr:nth-child(even) { background-color: #f8fafc; }
        .loading-spinner { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        [x-cloak] { display: none !important; }
        
        /* Specific dark mode fixes for registrants */
        body.dark-theme .bg-blue-50\/30 { background-color: rgba(30, 58, 138, 0.15) !important; }
        body.dark-theme .hover\:bg-blue-50\/30:hover { background-color: rgba(30, 58, 138, 0.25) !important; }
        body.dark-theme .bg-emerald-100\/50 { background-color: rgba(16, 185, 129, 0.15) !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen">

    <div class="flex h-screen overflow-hidden">
        
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto bg-slate-50 p-8">
            <div class="flex flex-col gap-6 mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800 tracking-tight">Registrant Management</h2>
                        <p class="text-slate-500 text-sm mt-1">Manage, track, and engage with your webinar registrants.</p>
                    </div>
                    
                    <div class="flex items-center gap-3">
                        <!-- Bulk Actions Toolbar (Hidden by default) -->
                        <div id="bulk-toolbar" class="hidden flex items-center gap-2 bg-emerald-50 px-3 py-1.5 rounded-xl border border-emerald-100 mr-2">
                            <span class="text-xs font-semibold text-emerald-700"><span id="selected-count">0</span> Selected</span>
                            <div class="h-4 w-px bg-emerald-200 mx-1"></div>
                            <button onclick="handleBulkAction('send')" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 transition flex items-center gap-1.5">
                                <i class="fas fa-paper-plane text-[10px]"></i> Bulk Send
                            </button>
                            <button onclick="handleBulkAction('delete')" class="text-xs font-bold text-rose-600 hover:text-rose-700 transition flex items-center gap-1.5">
                                <i class="fas fa-trash-alt text-[10px]"></i> Bulk Delete
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search & Filtering Suite -->
                <form method="GET" action="registrants.php" class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100 flex flex-wrap items-center gap-4">
                    <div class="relative flex-1 min-w-[300px]">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" name="search" id="table-search" placeholder="Search by name, email or phone..." 
                               value="<?php echo htmlspecialchars($search); ?>"
                               class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-100 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
                    </div>

                    <div class="flex items-center gap-4">
                        <select name="category" id="filter-category" onchange="this.form.submit()" class="px-4 py-2.5 bg-slate-50 border border-slate-100 rounded-xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition">
                            <option value="">All Webinars</option>
                            <?php foreach($webinars as $w): ?>
                                <option value="<?php echo htmlspecialchars($w); ?>" <?php echo $category === $w ? 'selected' : ''; ?>><?php echo htmlspecialchars($w); ?></option>
                            <?php endforeach; ?>
                            <option value="General/Old" <?php echo $category === 'General/Old' ? 'selected' : ''; ?>>General/Old</option>
                        </select>

                        <div class="flex bg-slate-50 p-1 rounded-xl border border-slate-100">
                            <input type="hidden" name="status" id="status-input" value="<?php echo htmlspecialchars($status_filter); ?>">
                            <button type="button" onclick="filterStatus('all')" class="status-btn px-4 py-1.5 rounded-lg text-xs font-bold transition <?php echo $status_filter === 'all' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500 hover:text-slate-700'; ?>">All</button>
                            <button type="button" onclick="filterStatus('sent')" class="status-btn px-4 py-1.5 rounded-lg text-xs font-bold transition <?php echo $status_filter === 'sent' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500 hover:text-slate-700'; ?>">Sent</button>
                            <button type="button" onclick="filterStatus('pending')" class="status-btn px-4 py-1.5 rounded-lg text-xs font-bold transition <?php echo $status_filter === 'pending' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500 hover:text-slate-700'; ?>">Pending</button>
                        </div>
                        
                        <button type="submit" class="hidden"></button> <!-- For enter key -->
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="max-h-[calc(100vh-320px)] overflow-y-auto">
                    <table id="registrants-table" class="w-full text-left border-collapse <?php echo empty($registrants) ? 'hidden' : ''; ?>">
                        <thead class="sticky-header">
                            <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                <th class="p-4 w-12">
                                    <div class="flex items-center justify-center">
                                        <input type="checkbox" id="select-all" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                    </div>
                                </th>
                                <th class="p-4">Registrant Name</th>
                                <th class="p-4">Contact Info</th>
                                <th class="p-4">Webinar Category</th>
                                <th class="p-4">Reg. Date</th>
                                <th class="p-4 text-center">Status</th>
                                <th class="p-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm zebra-stripes">
                            <?php foreach($registrants as $r): 
                                $status = $r['email_sent'] ? 'sent' : 'pending';
                                $regDate = strtotime($r['registration_date']);
                                $formattedDate = ($regDate > 0 && date('Y', $regDate) != '1969' && date('Y', $regDate) != '-0001') 
                                    ? date('M d, Y', $regDate) 
                                    : '<span class="text-slate-300 text-xs font-medium bg-slate-50 px-2 py-0.5 rounded-md">N/A</span>';
                            ?>
                            <tr class="hover:bg-blue-50/30 transition-colors group">
                                
                                <td class="p-4 text-center">
                                    <input type="checkbox" class="row-select w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" data-id="<?php echo $r['id']; ?>">
                                </td>
                                
                                <td class="p-4">
                                    <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($r['fullname']); ?></div>
                                    <div class="text-[10px] text-slate-400 font-bold uppercase mt-0.5">ID: #<?php echo str_pad($r['id'], 5, '0', STR_PAD_LEFT); ?></div>
                                </td>
                                
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex flex-col">
                                            <span class="text-slate-600 text-xs font-medium"><?php echo htmlspecialchars($r['email']); ?></span>
                                            <?php if($r['phone']): ?>
                                                <span class="text-slate-400 text-[11px]"><?php echo htmlspecialchars($r['phone']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <a href="mailto:<?php echo $r['email']; ?>" class="w-7 h-7 flex items-center justify-center bg-blue-50 text-blue-500 rounded-lg hover:bg-blue-100 transition" title="Email Registrant">
                                                <i class="far fa-envelope text-xs"></i>
                                            </a>
                                            <?php if($r['phone']): ?>
                                                <a href="tel:<?php echo $r['phone']; ?>" class="w-7 h-7 flex items-center justify-center bg-emerald-50 text-emerald-500 rounded-lg hover:bg-emerald-100 transition" title="Call Registrant">
                                                    <i class="fas fa-phone-alt text-xs"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="p-4">
                                    <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-600 text-xs font-semibold">
                                        <?php echo $r['webinar_title'] ? htmlspecialchars($r['webinar_title']) : 'General/Old'; ?>
                                    </span>
                                </td>
                                
                                <td class="p-4 text-slate-500 font-medium whitespace-nowrap">
                                    <?php echo $formattedDate; ?>
                                </td>
                                
                                <td class="p-4 text-center">
                                    <?php if($r['email_sent']): ?>
                                        <span class="inline-flex items-center gap-1.5 bg-emerald-100/50 text-emerald-600 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">
                                            <i class="fas fa-check-circle"></i> Sent
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 bg-slate-100 text-slate-500 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="p-4 text-right">
                                    <form method="POST" action="emails.php" class="send-email-form inline" onsubmit="return handleSingleSend(this, '<?php echo htmlspecialchars(addslashes($r['fullname'])); ?>');">
                                        <input type="hidden" name="action" value="send_emails">
                                        <input type="hidden" name="registrant_id" value="<?php echo $r['id']; ?>">
                                        <input type="hidden" name="email_template" value="Hi [Name],&#10;&#10;Here is your webinar link: [Link]&#10;&#10;See you there!">
                                        <button type="submit" class="send-btn inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-xl text-xs font-bold transition-all shadow-sm hover:shadow-blue-200" title="Send Email">
                                            <i class="fas fa-paper-plane text-[10px]"></i>
                                            <span>Send</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Empty State -->
                <div id="empty-state" class="<?php echo empty($registrants) ? '' : 'hidden'; ?> py-20 text-center">
                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-search text-slate-300 text-3xl"></i>
                    </div>
                    <h3 class="text-slate-800 font-bold text-lg">No registrants found</h3>
                    <p class="text-slate-500 text-sm mt-1">Try adjusting your filters or search terms.</p>
                    <button onclick="resetFilters()" class="mt-4 text-blue-600 font-bold text-sm hover:underline">Clear all filters</button>
                </div>

                <!-- Pagination UI -->
                <?php if($total_pages > 1): ?>
                <div class="p-5 border-t border-slate-100 flex items-center justify-between bg-slate-50/30">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-widest">
                        Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $total_registrants); ?> of <?php echo $total_registrants; ?> registrants
                    </div>
                    <div class="flex items-center gap-2">
                        <?php 
                        $query_params = $_GET;
                        function getPageUrl($p, $params) {
                            $params['page'] = $p;
                            return '?' . http_build_query($params);
                        }
                        ?>
                        <?php if($page > 1): ?>
                            <a href="<?php echo getPageUrl($page - 1, $query_params); ?>" class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition shadow-sm">
                                <i class="fas fa-chevron-left text-xs"></i>
                            </a>
                        <?php endif; ?>

                        <?php 
                        $start_range = max(1, $page - 2);
                        $end_range = min($total_pages, $page + 2);
                        for($i = $start_range; $i <= $end_range; $i++): 
                        ?>
                            <a href="<?php echo getPageUrl($i, $query_params); ?>" class="w-9 h-9 flex items-center justify-center rounded-xl border <?php echo $i === $page ? 'bg-[#1e4a7a] border-[#1e4a7a] text-white shadow-md shadow-blue-900/20' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition shadow-sm'; ?> text-xs font-bold">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if($page < $total_pages): ?>
                            <a href="<?php echo getPageUrl($page + 1, $query_params); ?>" class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition shadow-sm">
                                <i class="fas fa-chevron-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Hidden Forms for Bulk Actions -->
    <form id="bulk-send-form" method="POST" action="emails.php" class="hidden">
        <input type="hidden" name="action" value="send_emails">
        <input type="hidden" name="bulk_ids" id="bulk-send-ids">
        <input type="hidden" name="email_template" value="Hi [Name],&#10;&#10;Here is your webinar link: [Link]&#10;&#10;See you there!">
    </form>

    <form id="bulk-delete-form" method="POST" action="registrants.php" class="hidden">
        <input type="hidden" name="action" value="delete_bulk">
        <input type="hidden" name="bulk_ids" id="bulk-delete-ids">
    </form>

    <script>
        const tableSearch = document.getElementById('table-search');
        const filterCategory = document.getElementById('filter-category');
        const statusInput = document.getElementById('status-input');
        const statusBtns = document.querySelectorAll('.status-btn');
        const selectAll = document.getElementById('select-all');
        const rowCheckboxes = document.querySelectorAll('.row-select');
        const bulkToolbar = document.getElementById('bulk-toolbar');
        const selectedCount = document.getElementById('selected-count');

        window.filterStatus = (status) => {
            statusInput.value = status;
            statusInput.form.submit();
        };

        window.resetFilters = () => {
            window.location.href = 'registrants.php';
        };

        // Selection Logic
        function updateBulkToolbar() {
            const selected = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
            selectedCount.textContent = selected;
            bulkToolbar.classList.toggle('hidden', selected === 0);
        }

        selectAll.addEventListener('change', () => {
            const isChecked = selectAll.checked;
            rowCheckboxes.forEach(cb => {
                cb.checked = isChecked;
            });
            updateBulkToolbar();
        });

        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkToolbar);
        });

        // Action Handlers
        window.handleSingleSend = async (form, name) => {
            if (!confirm(`Send email specifically to ${name}?`)) return false;
            
            const btn = form.querySelector('.send-btn');
            
            // Loading State
            btn.disabled = true;
            btn.classList.add('opacity-80', 'cursor-not-allowed');
            btn.innerHTML = `<i class="fas fa-circle-notch loading-spinner text-[10px]"></i> <span>Sending...</span>`;
            
            setTimeout(() => {
                form.submit();
            }, 600);
            
            return false; // Prevent immediate submission
        };

        window.handleBulkAction = (action) => {
            const selectedIds = Array.from(rowCheckboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.getAttribute('data-id'));

            if (selectedIds.length === 0) return;

            if (action === 'delete') {
                if (confirm(`Are you sure you want to delete ${selectedIds.length} registrants?`)) {
                    document.getElementById('bulk-delete-ids').value = selectedIds.join(',');
                    document.getElementById('bulk-delete-form').submit();
                }
            } else if (action === 'send') {
                if (confirm(`Send emails to ${selectedIds.length} registrants?`)) {
                    document.getElementById('bulk-send-ids').value = selectedIds.join(',');
                    document.getElementById('bulk-send-form').submit();
                }
            } else if (action === 'export_pdf') {
                window.print();
            }
        };
    </script>
</body>
</html>
