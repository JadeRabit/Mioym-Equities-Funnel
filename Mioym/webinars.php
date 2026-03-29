<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: registration.php');
    exit;
}

$hasDescription = true;
try {
    $pdo->query("SELECT description FROM webinar_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasDescription = false;
}
if (!$hasDescription) {
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN description TEXT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->query("SELECT description FROM webinar_tbl LIMIT 1");
        $hasDescription = true;
    } catch (Throwable $e) {
        $hasDescription = false;
    }
}

    $hasSubheading = true;
try {
    $pdo->query("SELECT subheading, subheading_size, subheading_bold FROM webinar_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasSubheading = false;
}
if (!$hasSubheading) {
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN subheading TEXT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN subheading_size INT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN subheading_bold TINYINT(1) DEFAULT 1");
    } catch (Throwable $e) {
    }
    try {
        $pdo->query("SELECT subheading, subheading_size, subheading_bold FROM webinar_tbl LIMIT 1");
        $hasSubheading = true;
    } catch (Throwable $e) {
        $hasSubheading = false;
    }
}

// Handle CRUD & Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // File upload helper function
    function handleUpload($fileInputName, $uploadDir = 'uploads/') {
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] === UPLOAD_ERR_OK) {
            $fileName = time() . '_' . basename($_FILES[$fileInputName]['name']);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $targetPath)) {
                return $targetPath;
            }
        }
        return null;
    }

    if ($action === 'add_webinar') {
        $new_webinar_id = 'WB-' . mt_rand(10000, 99999);
        $status = $_POST['status'] ?? 'active';
        $description = trim($_POST['description'] ?? '');
        $subheading = trim($_POST['subheading'] ?? '');
        $subheadingSize = (int)($_POST['subheading_size'] ?? 20);
        if ($subheadingSize < 10) $subheadingSize = 10;
        if ($subheadingSize > 80) $subheadingSize = 80;
        $subheadingBold = isset($_POST['subheading_bold']) ? 1 : 0;
        
        $host_pic_path = handleUpload('host_pic');
        $webinar_vid_path = handleUpload('webinar_vid');
        
        $columns = ['title', 'hostname', 'host_pic', 'webinar_vid', 'webinar_id', 'webinar_link', '`schedule_date&time`', 'status'];
        $values = [$_POST['title'], $_POST['hostname'], $host_pic_path, $webinar_vid_path, $new_webinar_id, $_POST['meeting_link'], $_POST['schedule_date_time'], $status];

        if ($hasDescription) {
            array_splice($columns, 1, 0, ['description']);
            array_splice($values, 1, 0, [$description]);
        }
        if ($hasSubheading) {
            $columns[] = 'subheading';
            $columns[] = 'subheading_size';
            $columns[] = 'subheading_bold';
            $values[] = $subheading !== '' ? $subheading : null;
            $values[] = $subheadingSize;
            $values[] = $subheadingBold;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO webinar_tbl (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        if (function_exists('admin_notify')) {
            admin_notify($pdo, 'webinars', 'Webinar Created', (string)$_POST['title'], 'webinars.php');
        }
        $_SESSION['flash'] = "Webinar created successfully!";
        $_SESSION['di'] = ['type'=>'success','title'=>'Webinar','message'=>$_SESSION['flash']];
    } elseif ($action === 'delete_webinar') {
        // Optionally delete files from server before deleting record
        $stmt = $pdo->prepare("SELECT host_pic, webinar_vid FROM webinar_tbl WHERE webinar_id=?");
        $stmt->execute([$_POST['id']]);
        $webinar = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($webinar) {
            if ($webinar['host_pic'] && file_exists($webinar['host_pic'])) unlink($webinar['host_pic']);
            if ($webinar['webinar_vid'] && file_exists($webinar['webinar_vid'])) unlink($webinar['webinar_vid']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM webinar_tbl WHERE webinar_id=?");
        $stmt->execute([$_POST['id']]);
        if (function_exists('admin_notify')) {
            admin_notify($pdo, 'webinars', 'Webinar Deleted', (string)$_POST['id'], 'webinars.php');
        }
        $_SESSION['flash'] = "Webinar deleted successfully!";
        $_SESSION['di'] = ['type'=>'warn','title'=>'Webinar','message'=>$_SESSION['flash']];
    } elseif ($action === 'edit_webinar') {
        // Fetch old webinar data to optionally delete old files
        $stmt = $pdo->prepare("SELECT host_pic, webinar_vid FROM webinar_tbl WHERE webinar_id=?");
        $stmt->execute([$_POST['webinar_id']]);
        $old_webinar = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle optional new file uploads
        $host_pic_path = handleUpload('host_pic');
        $webinar_vid_path = handleUpload('webinar_vid');
        
        // Build update query dynamically based on whether files were uploaded
        $description = trim($_POST['description'] ?? '');
        $subheading = trim($_POST['subheading'] ?? '');
        $subheadingSize = (int)($_POST['subheading_size'] ?? 20);
        if ($subheadingSize < 10) $subheadingSize = 10;
        if ($subheadingSize > 80) $subheadingSize = 80;
        $subheadingBold = isset($_POST['subheading_bold']) ? 1 : 0;
        if ($hasDescription) {
            $updateQuery = "UPDATE webinar_tbl SET title=?, description=?, hostname=?, `schedule_date&time`=?, webinar_link=?, status=?";
            $params = [$_POST['title'], $description, $_POST['hostname'], $_POST['schedule_date_time'], $_POST['meeting_link'], $_POST['status']];
        } else {
            $updateQuery = "UPDATE webinar_tbl SET title=?, hostname=?, `schedule_date&time`=?, webinar_link=?, status=?";
            $params = [$_POST['title'], $_POST['hostname'], $_POST['schedule_date_time'], $_POST['meeting_link'], $_POST['status']];
        }

        if ($hasSubheading) {
            $updateQuery .= ", subheading=?, subheading_size=?, subheading_bold=?";
            $params[] = $subheading !== '' ? $subheading : null;
            $params[] = $subheadingSize;
            $params[] = $subheadingBold;
        }
        
        if ($host_pic_path) {
            if ($old_webinar && $old_webinar['host_pic'] && file_exists($old_webinar['host_pic'])) {
                unlink($old_webinar['host_pic']);
            }
            $updateQuery .= ", host_pic=?";
            $params[] = $host_pic_path;
        }
        if ($webinar_vid_path) {
            if ($old_webinar && $old_webinar['webinar_vid'] && file_exists($old_webinar['webinar_vid'])) {
                unlink($old_webinar['webinar_vid']);
            }
            $updateQuery .= ", webinar_vid=?";
            $params[] = $webinar_vid_path;
        }
        
        $updateQuery .= " WHERE webinar_id=?";
        $params[] = $_POST['webinar_id'];
        
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($params);
        if (function_exists('admin_notify')) {
            admin_notify($pdo, 'webinars', 'Webinar Updated', (string)$_POST['title'], 'webinars.php');
        }
        $_SESSION['flash'] = "Webinar updated successfully!";
        $_SESSION['di'] = ['type'=>'success','title'=>'Webinar','message'=>$_SESSION['flash']];
    } elseif ($action === 'publish_webinar') {
        // First, unpublish all webinars
        $pdo->query("UPDATE webinar_tbl SET is_published = 0");
        
        // Then, publish the selected one
        $stmt = $pdo->prepare("UPDATE webinar_tbl SET is_published = 1 WHERE webinar_id = ?");
        $stmt->execute([$_POST['id']]);
        
        if (function_exists('admin_notify')) {
            admin_notify($pdo, 'webinars', 'Webinar Published', (string)$_POST['id'] . ' is now live on the landing page.', 'index.php');
        }
        $_SESSION['flash'] = "Webinar published to Landing Page successfully!";
        $_SESSION['di'] = ['type'=>'success','title'=>'Webinar','message'=>$_SESSION['flash']];
    }
    header("Location: webinars.php");
    exit;
}

// Pagination Logic
$limit = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Fetch Data
$total_webinars = $pdo->query("SELECT COUNT(*) FROM webinar_tbl")->fetchColumn();
$total_pages = ceil($total_webinars / $limit);

$stmt = $pdo->prepare("SELECT * FROM webinar_tbl ORDER BY `schedule_date&time` DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$webinars = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webinars · Mioym Equities</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        
        /* Dark Theme Fixes */
        body.dark-theme .bg-white { background-color: #1e293b !important; border-color: #334155 !important; }
        body.dark-theme .bg-slate-50\/80 { background-color: #0f172a !important; }
        body.dark-theme .bg-slate-50\/50 { background-color: #0f172a !important; }
        body.dark-theme .hover\:bg-slate-50\/50:hover { background-color: #334155 !important; }
        body.dark-theme thead tr.bg-slate-50\/80 th { color: #e2e8f0 !important; }
        
        /* Slide-over & Modal Theme Fixes */
        body.dark-theme .pointer-events-auto.bg-white { background-color: #1e293b !important; }
        body.dark-theme .sticky.top-0.bg-slate-50 { background-color: #0f172a !important; border-color: #334155 !important; }
        body.dark-theme .sticky.bottom-0.bg-white { background-color: #1e293b !important; border-color: #334155 !important; }
        body.dark-theme .bg-slate-50 { background-color: #0f172a !important; }
        body.dark-theme .border-slate-100 { border-color: #334155 !important; }
        body.dark-theme .border-slate-200 { border-color: #334155 !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen">

    <div class="flex h-screen overflow-hidden">
        
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto bg-[#f8fafc] p-6 lg:p-8" x-data="{ showAddModal: false }">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800 tracking-tight">Webinar Management</h2>
                    <p class="text-sm text-slate-500 mt-1">Manage schedules, hosts, and video content.</p>
                </div>
                <button @click="showAddModal = true" class="bg-[#1e4a7a] text-white px-5 py-2.5 rounded-xl font-medium hover:bg-[#15365a] transition-all shadow-[0_2px_10px_-3px_rgba(30,74,122,0.4)] hover:shadow-[0_8px_20px_-6px_rgba(30,74,122,0.5)] flex items-center gap-2">
                    <i class="fas fa-plus"></i> Create Webinar
                </button>
            </div>

            <!-- List -->
            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] border border-slate-100 overflow-hidden">
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-100 text-xs font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">
                                <th class="p-5">Webinar Details</th>
                                <th class="p-5">Description</th>
                                <th class="p-5">Schedule</th>
                                <th class="p-5">Resources</th>
                                <th class="p-5 text-center">Status</th>
                                <th class="p-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100/80 text-sm">
                            <?php foreach($webinars as $w): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors group">
                                    <td class="p-5">
                                        <div class="flex items-center gap-4">
                                            <?php if(!empty($w['host_pic'])): ?>
                                                <div class="relative group cursor-pointer shrink-0">
                                                    <img src="<?php echo htmlspecialchars(str_replace('\\', '/', $w['host_pic'])); ?>" alt="Host" class="w-12 h-12 rounded-xl object-cover border border-slate-200 shadow-sm group-hover:shadow transition-all">
                                                    <div class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-emerald-500 rounded-full border-2 border-white flex items-center justify-center shadow-sm" title="Displays in Landing Page">
                                                        <i class="fas fa-check text-[10px] text-white"></i>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="relative group cursor-pointer shrink-0" title="No host picture for Landing Page">
                                                    <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-slate-400 border border-slate-200 group-hover:bg-slate-200 transition-colors shadow-sm">
                                                        <i class="fas fa-user"></i>
                                                    </div>
                                                    <div class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-amber-500 rounded-full border-2 border-white flex items-center justify-center shadow-sm">
                                                        <i class="fas fa-exclamation text-[10px] text-white"></i>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-bold text-slate-800 text-base line-clamp-1 mb-1" title="<?php echo htmlspecialchars($w['title']); ?>"><?php echo htmlspecialchars($w['title']); ?></div>
                                                <div class="text-xs flex items-center gap-1.5 group cursor-pointer w-max" title="Displays as Host Name in Landing Page">
                                                    <span class="w-5 h-5 rounded bg-indigo-50 flex items-center justify-center text-indigo-500 group-hover:bg-indigo-100 transition-colors">
                                                        <i class="fas fa-id-badge text-[10px]"></i>
                                                    </span>
                                                    <span class="text-slate-600 group-hover:text-indigo-600 transition-colors font-medium"><?php echo htmlspecialchars($w['hostname'] ?? 'No Host'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-5">
                                        <div class="text-slate-700 font-medium line-clamp-2 max-w-md" title="<?php echo htmlspecialchars($w['description'] ?? ''); ?>">
                                            <?php echo !empty($w['description']) ? htmlspecialchars($w['description']) : '<span class="text-slate-400 italic">No description</span>'; ?>
                                        </div>
                                    </td>
                                    <td class="p-5">
                                        <?php $dateStr = strtotime($w['schedule_date&time']); ?>
                                        <div class="text-slate-700 font-medium"><?php echo date('M d, Y', $dateStr); ?></div>
                                        <div class="text-xs text-slate-500 mt-1"><?php echo date('h:i A', $dateStr); ?></div>
                                    </td>
                                    <td class="p-5">
                                        <div class="flex flex-col gap-2.5">
                                            <a href="<?php echo htmlspecialchars($w['webinar_link']); ?>" target="_blank" class="inline-flex items-center gap-2 text-xs font-semibold text-blue-600 bg-blue-50/80 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors w-max border border-blue-100/50">
                                                <i class="fas fa-external-link-alt"></i> Join Link
                                            </a>
                                            <?php if(!empty($w['webinar_vid'])): ?>
                                            <a href="<?php echo htmlspecialchars(str_replace('\\', '/', $w['webinar_vid'])); ?>" target="_blank" class="inline-flex items-center gap-2 text-xs font-semibold text-rose-600 bg-rose-50/80 hover:bg-rose-100 px-3 py-1.5 rounded-lg transition-all w-max border border-rose-100/50 group" title="Displays as Main Video in Landing Page">
                                                <i class="fas fa-film group-hover:scale-110 transition-transform"></i> Play Video
                                                <span class="ml-0.5 w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                                            </a>
                                            <?php else: ?>
                                            <span class="inline-flex items-center gap-2 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200/60 px-3 py-1.5 rounded-lg w-max" title="No video for Landing Page">
                                                <i class="fas fa-video-slash"></i> No Video
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-5 text-center">
                                        <?php 
                                        $status = strtolower($w['status'] ?? 'inactive');
                                        if($status === 'active' || $status === 'live'): 
                                        ?>
                                            <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200/60 px-3 py-1.5 rounded-full text-xs font-bold capitalize">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> <?php echo htmlspecialchars($w['status']); ?>
                                            </span>
                                        <?php elseif($status === 'upcoming'): ?>
                                            <span class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-700 border border-blue-200/60 px-3 py-1.5 rounded-full text-xs font-bold capitalize">
                                                <i class="far fa-calendar-alt text-[10px]"></i> <?php echo htmlspecialchars($w['status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 bg-slate-100 text-slate-600 border border-slate-200 px-3 py-1.5 rounded-full text-xs font-bold capitalize">
                                                <i class="fas fa-archive text-[10px]"></i> <?php echo htmlspecialchars($w['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-5 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2 opacity-100 transition-opacity duration-200">
                                            <!-- Publish Button -->
                                            <?php if(isset($w['is_published']) && $w['is_published'] == 1): ?>
                                                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-200/60 shadow-sm cursor-default tooltip-trigger" title="Currently Published on Landing Page">
                                                    <i class="fas fa-globe animate-pulse"></i>
                                                </span>
                                            <?php else: ?>
                                                <form method="POST" class="inline js-confirm" data-message="Publish this webinar to the Landing Page? This will replace the currently published webinar." data-kind="info">
                                                    <input type="hidden" name="action" value="publish_webinar">
                                                    <input type="hidden" name="id" value="<?php echo $w['webinar_id']; ?>">
                                                    <button type="submit" class="w-10 h-10 flex items-center justify-center text-indigo-600 hover:text-white bg-indigo-50 hover:bg-indigo-600 rounded-xl transition-all border border-transparent hover:border-indigo-600 shadow-sm hover:shadow tooltip-trigger" title="Publish to Landing Page">
                                                        <i class="fas fa-upload"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <div class="w-px h-6 bg-slate-200 mx-1"></div>

                                            <!-- Edit Button -->
                                            <button 
                                                class="edit-webinar-btn w-10 h-10 flex items-center justify-center text-blue-600 hover:text-white bg-blue-50 hover:bg-blue-600 rounded-xl transition-all border border-transparent hover:border-blue-600 shadow-sm hover:shadow tooltip-trigger"
                                                data-id="<?php echo $w['webinar_id']; ?>"
                                                data-title="<?php echo htmlspecialchars($w['title']); ?>"
                                                data-description="<?php echo htmlspecialchars($w['description'] ?? ''); ?>"
                                                data-subheading="<?php echo htmlspecialchars($w['subheading'] ?? ''); ?>"
                                                data-subheading-size="<?php echo htmlspecialchars((string)($w['subheading_size'] ?? 20)); ?>"
                                                data-subheading-bold="<?php echo htmlspecialchars((string)($w['subheading_bold'] ?? 1)); ?>"
                                                data-hostname="<?php echo htmlspecialchars($w['hostname'] ?? ''); ?>"
                                                data-host-pic="<?php echo htmlspecialchars(str_replace('\\', '/', $w['host_pic'] ?? '')); ?>"
                                                data-webinar-vid="<?php echo htmlspecialchars(str_replace('\\', '/', $w['webinar_vid'] ?? '')); ?>"
                                                data-schedule="<?php echo date('Y-m-d\\TH:i', strtotime($w['schedule_date&time'])); ?>"
                                                data-link="<?php echo htmlspecialchars($w['webinar_link'] ?? ''); ?>"
                                                data-status="<?php echo strtolower($w['status'] ?? 'inactive'); ?>"
                                                title="Edit Webinar"
                                            >
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            
                                            <!-- Delete Form -->
                                            <form method="POST" class="inline js-confirm" data-message="Are you sure you want to delete this webinar?" data-kind="danger">
                                                <input type="hidden" name="action" value="delete_webinar">
                                                <input type="hidden" name="id" value="<?php echo $w['webinar_id']; ?>">
                                                <button type="submit" class="w-10 h-10 flex items-center justify-center text-rose-600 hover:text-white bg-rose-50 hover:bg-rose-600 rounded-xl transition-all border border-transparent hover:border-rose-600 shadow-sm hover:shadow tooltip-trigger" title="Delete Webinar">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php if(empty($webinars)): ?>
                            <tr>
                                <td colspan="6" class="p-16 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                                            <i class="fas fa-video-slash text-3xl text-slate-300"></i>
                                        </div>
                                        <h3 class="text-lg font-bold text-slate-700">No Webinars Found</h3>
                                        <p class="text-slate-500 mt-1 mb-6 max-w-sm">You haven't scheduled any webinars yet. Create your first webinar to start engaging with your audience.</p>
                                        <button @click="showAddModal = true" class="bg-white border-2 border-slate-200 text-slate-600 hover:text-slate-800 hover:border-slate-300 px-6 py-2.5 rounded-xl font-medium transition-all shadow-sm flex items-center gap-2">
                                            <i class="fas fa-plus"></i> Create First Webinar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if($total_pages > 1): ?>
                <div class="p-5 border-t border-slate-100 flex items-center justify-between bg-slate-50/30">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-widest">
                        Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $total_webinars); ?> of <?php echo $total_webinars; ?> webinars
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>" class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition shadow-sm">
                                <i class="fas fa-chevron-left text-xs"></i>
                            </a>
                        <?php endif; ?>

                        <?php 
                        $start_range = max(1, $page - 2);
                        $end_range = min($total_pages, $page + 2);
                        for($i = $start_range; $i <= $end_range; $i++): 
                        ?>
                            <a href="?page=<?php echo $i; ?>" class="w-9 h-9 flex items-center justify-center rounded-xl border <?php echo $i === $page ? 'bg-[#1e4a7a] border-[#1e4a7a] text-white shadow-md shadow-blue-900/20' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition shadow-sm'; ?> text-xs font-bold">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition shadow-sm">
                                <i class="fas fa-chevron-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add New Webinar Modal / Slide-over -->
            <div x-show="showAddModal" class="relative z-[200]" aria-labelledby="slide-over-title" role="dialog" aria-modal="true" x-cloak>
                <!-- Background overlay -->
                <div x-show="showAddModal" x-transition:enter="ease-in-out duration-500" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in-out duration-500" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"></div>

                <div class="fixed inset-0 overflow-hidden">
                    <div class="absolute inset-0 overflow-hidden">
                        <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10 sm:pl-16">
                            <!-- Slide-over panel -->
                            <div x-show="showAddModal" @click.away="showAddModal = false" x-transition:enter="transform transition ease-in-out duration-500 sm:duration-700" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-500 sm:duration-700" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="pointer-events-auto w-screen max-w-md">
                                <div class="flex h-full flex-col overflow-y-scroll bg-white shadow-2xl">
                                    
                                    <!-- Header -->
                                    <div class="bg-slate-50 px-6 py-6 border-b border-slate-100 sm:px-8 sticky top-0 z-10 flex items-center justify-between">
                                        <div>
                                            <h2 class="text-xl font-bold text-slate-800" id="slide-over-title">Add New Webinar</h2>
                                            <p class="text-sm text-slate-500 mt-1">Fill in the details to schedule a new event.</p>
                                        </div>
                                        <button @click="showAddModal = false" type="button" class="relative rounded-xl p-2 text-slate-400 hover:bg-slate-200 hover:text-slate-600 transition-colors focus:outline-none">
                                            <i class="fas fa-times text-xl"></i>
                                        </button>
                                    </div>

                                    <!-- Form Body -->
                                    <div class="relative flex-1 px-6 py-6 sm:px-8">
                                        <form method="POST" enctype="multipart/form-data" class="space-y-6" id="addWebinarForm">
                                            <input type="hidden" name="action" value="add_webinar">
                                            
                                            <!-- Core Info -->
                                            <div class="space-y-4">
                                                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Core Details</h3>
                                                
                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Webinar Title <span class="text-rose-500">*</span></label>
                                                    <input type="text" name="title" required placeholder="e.g., Q3 Market Analysis" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800 placeholder-slate-400">
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subheading</label>
                                                    <textarea name="subheading" rows="2" placeholder="Optional subheading (supports line breaks)" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800 placeholder-slate-400 resize-none"></textarea>
                                                    <div class="mt-3 grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Font Size (px)</label>
                                                            <input type="number" name="subheading_size" value="20" min="10" max="80" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800">
                                                        </div>
                                                        <div class="flex items-end">
                                                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                                                <input type="checkbox" name="subheading_bold" value="1" checked class="w-4 h-4 rounded border-slate-300 text-[#1e4a7a] focus:ring-[#1e4a7a]/30">
                                                                Bold
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5"></label>Description</label>
                                                    <textarea name="description" rows="4" placeholder="Short summary or agenda for this webinar..." class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800 placeholder-slate-400 resize-none"></textarea>
                                                </div>

                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Schedule <span class="text-rose-500">*</span></label>
                                                        <input type="datetime-local" name="schedule_date_time" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800">
                                                    </div>
                                                    <div>
                                                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Status</label>
                                                        <select name="status" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800 appearance-none">
                                                            <option value="upcoming">Upcoming</option>
                                                            <option value="active">Active (Live)</option>
                                                            <option value="inactive">Inactive</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Meeting Link <span class="text-rose-500">*</span></label>
                                                    <div class="relative">
                                                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                                            <i class="fas fa-link text-slate-400 text-sm"></i>
                                                        </div>
                                                        <input type="url" name="meeting_link" required placeholder="https://zoom.us/j/..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800 placeholder-slate-400">
                                                    </div>
                                                </div>
                                            </div>

                                            <hr class="border-slate-100">

                                            <!-- Host & Media -->
                                            <div class="space-y-4">
                                                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Host & Media</h3>
                                                
                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Host Name</label>
                                                    <div class="relative">
                                                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                                            <i class="fas fa-id-badge text-slate-400 text-sm"></i>
                                                        </div>
                                                        <input type="text" name="hostname" placeholder="John Doe" class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] focus:bg-white transition-all outline-none text-sm text-slate-800 placeholder-slate-400">
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Host Avatar <span class="text-xs font-normal text-slate-400">(Optional)</span></label>
                                                    <div class="mt-1 flex justify-center rounded-xl border border-dashed border-slate-300 px-6 py-6 bg-slate-50 hover:bg-slate-100 transition-colors">
                                                        <div class="text-center">
                                                            <i class="fas fa-image text-3xl text-slate-300 mb-2"></i>
                                                            <div class="flex text-sm leading-6 text-slate-600 justify-center">
                                                                <label class="relative cursor-pointer rounded-md font-semibold text-[#1e4a7a] focus-within:outline-none hover:text-[#15365a]">
                                                                    <span>Upload a file</span>
                                                                    <input type="file" name="host_pic" accept="image/*" class="sr-only">
                                                                </label>
                                                            </div>
                                                            <p class="text-xs text-slate-500">PNG, JPG up to 2MB</p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Webinar Video <span class="text-xs font-normal text-slate-400">(Optional MP4)</span></label>
                                                    <div class="mt-1 flex justify-center rounded-xl border border-dashed border-slate-300 px-6 py-6 bg-slate-50 hover:bg-slate-100 transition-colors">
                                                        <div class="text-center">
                                                            <i class="fas fa-file-video text-3xl text-slate-300 mb-2"></i>
                                                            <div class="flex text-sm leading-6 text-slate-600 justify-center">
                                                                <label class="relative cursor-pointer rounded-md font-semibold text-rose-500 focus-within:outline-none hover:text-rose-600">
                                                                    <span>Upload video</span>
                                                                    <input type="file" name="webinar_vid" accept="video/mp4,video/x-m4v,video/*" class="sr-only">
                                                                </label>
                                                            </div>
                                                            <p class="text-xs text-slate-500">MP4 up to 50MB</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- Footer Actions -->
                                    <div class="border-t border-slate-100 px-6 py-5 sm:px-8 bg-white sticky bottom-0 z-10 flex items-center justify-end gap-3">
                                        <button @click="showAddModal = false" type="button" class="rounded-xl px-5 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 transition-colors">
                                            Cancel
                                        </button>
                                        <button type="submit" form="addWebinarForm" class="rounded-xl bg-[#1e4a7a] px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#15365a] transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 flex items-center gap-2">
                                            <i class="fas fa-save"></i> Save Webinar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Edit Webinar Modal -->
    <div id="editWebinarModal" 
         class="fixed inset-0 z-[150] overflow-y-auto hidden" 
         aria-labelledby="modal-title" role="dialog" aria-modal="true">
        
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div id="modalOverlay" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <!-- Modal panel -->
            <div id="modalPanel" class="inline-block align-bottom bg-white dark:bg-slate-900 rounded-3xl text-left overflow-hidden shadow-[0_24px_64px_rgba(2,6,23,0.35)] border border-slate-200/70 dark:border-slate-800/60 transform transition-all sm:my-8 sm:align-middle sm:max-w-xl w-full opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                
                <div class="bg-gradient-to-b from-white/70 to-white dark:from-slate-900/60 dark:to-slate-900 px-6 pt-6 pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-600">
                            <i class="fas fa-pen"></i>
                        </div>
                        <div>
                            <h3 class="text-[1.1rem] font-extrabold tracking-tight text-slate-800 dark:text-slate-100" id="modal-title">Edit Webinar</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Update details and media then save changes</p>
                        </div>
                    </div>
                    <button type="button" id="editCloseBtn" class="w-9 h-9 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-500 flex items-center justify-center"><i class="fas fa-times"></i></button>
                </div>
                <div class="px-6 py-5">
                    <form method="POST" id="editWebinarForm" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="action" value="edit_webinar">
                        <input type="hidden" name="webinar_id" id="modalWebinarId">
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Webinar Title</label>
                            <input type="text" name="title" id="modalTitle" required class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition" placeholder="Enter webinar title">
                            <p class="text-xs text-slate-400 mt-1">Use a clear, concise title to help attendees identify your event</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Subheading</label>
                            <textarea name="subheading" id="modalSubheading" rows="2" class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition resize-none" placeholder="Optional subheading (supports line breaks)"></textarea>
                            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Font Size (px)</label>
                                    <input type="number" name="subheading_size" id="modalSubheadingSize" min="10" max="80" class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition" value="20">
                                </div>
                                <div class="flex items-end">
                                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                        <input type="checkbox" name="subheading_bold" id="modalSubheadingBold" value="1" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500/30">
                                        Bold
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Description</label>
                            <textarea name="description" id="modalDescription" rows="4" class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition resize-none" placeholder="Short summary or agenda for this webinar"></textarea>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Host Name</label>
                                <input type="text" name="hostname" id="modalHostname" class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition" placeholder="e.g., Jane Doe">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Host Picture</label>
                                <div class="flex items-center gap-3">
                                    <input type="file" name="host_pic" accept="image/*" class="w-full px-4 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm bg-slate-50 hover:bg-white transition file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                </div>
                                <div id="currentHostPic" class="mt-3 flex items-center gap-3 hidden">
                                    <img id="hostPicPreview" class="h-12 w-12 rounded-xl object-cover border border-slate-200 shadow-sm" alt="Current Host">
                                    <a id="hostPicLink" target="_blank" class="text-xs text-blue-600 hover:underline">View Current Image</a>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Webinar Video</label>
                            <input type="file" name="webinar_vid" accept="video/mp4,video/x-m4v,video/*" class="w-full px-4 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-sm bg-slate-50 hover:bg-white transition file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100">
                            <div id="currentWebinarVid" class="mt-3 hidden">
                                <a id="webinarVidLink" target="_blank" class="inline-flex items-center gap-2 text-xs text-rose-600 hover:text-rose-700 hover:underline bg-rose-50 px-3 py-1.5 rounded-xl">
                                    <i class="fas fa-play-circle"></i> View Current Video
                                </a>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Schedule</label>
                            <div class="relative">
                                <input type="datetime-local" name="schedule_date_time" id="modalSchedule" required class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition">
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="far fa-calendar-alt"></i></span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Webinar Link</label>
                            <input type="url" name="meeting_link" id="modalLink" required class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50 hover:bg-white transition" placeholder="https://zoom.us/j/...">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Status</label>
                            <select name="status" id="modalStatus" class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-white">
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="bg-slate-50 dark:bg-slate-900/70 px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                    <button type="button" id="modalCancelBtn" class="px-5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white/80 dark:bg-slate-900/60 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">Cancel</button>
                    <button type="submit" id="modalSaveBtn" form="editWebinarForm" class="px-6 py-2 rounded-xl bg-[#1e4a7a] text-white font-semibold hover:bg-[#15365a] transition shadow-sm inline-flex items-center gap-2">
                        <span id="modalSaveSpinner" class="hidden w-4 h-4 border-2 border-white/80 border-t-transparent rounded-full animate-spin"></span>
                        <span>Save Changes</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="uploadProgressOverlay" class="fixed inset-0 z-[260] hidden">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-md bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 rounded-3xl shadow-[0_24px_64px_rgba(2,6,23,0.45)] border border-slate-200/70 dark:border-slate-800/60 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-600 flex items-center justify-center shrink-0">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-extrabold tracking-tight truncate">Uploading…</div>
                            <div id="uploadProgressSub" class="text-xs text-slate-500 dark:text-slate-400 truncate">Please keep this tab open.</div>
                        </div>
                    </div>
                    <button type="button" id="uploadProgressCancel" class="px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white/70 dark:bg-slate-900/60 hover:bg-slate-50 dark:hover:bg-slate-800 transition shadow-sm text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Cancel
                    </button>
                </div>
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between gap-3">
                        <div id="uploadProgressLabel" class="text-sm font-semibold text-slate-700 dark:text-slate-200">0%</div>
                        <div id="uploadProgressBytes" class="text-xs text-slate-500 dark:text-slate-400"></div>
                    </div>
                    <div class="mt-3 w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div id="uploadProgressBar" class="h-full bg-gradient-to-r from-blue-500 to-emerald-500 rounded-full" style="width:0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Modal functionality using vanilla JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('editWebinarModal');
        const overlay = document.getElementById('modalOverlay');
        const panel = document.getElementById('modalPanel');
        const cancelBtn = document.getElementById('modalCancelBtn');
        const closeBtn = document.getElementById('editCloseBtn');
        const saveBtn = document.getElementById('modalSaveBtn');
        const saveSpinner = document.getElementById('modalSaveSpinner');
        
        // Get all edit buttons
        const editButtons = document.querySelectorAll('.edit-webinar-btn');
        
        // Add click event to all edit buttons
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                openModal(this);
            });
        });
        
        // Close modal when clicking overlay or cancel button
        overlay.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
        
        const focusablesSelector = 'a[href], button:not([disabled]), textarea, input, select';
        function trapFocus(e) {
            if (modal.classList.contains('hidden')) return;
            if (e.key !== 'Tab') return;
            const nodes = panel.querySelectorAll(focusablesSelector);
            const list = Array.prototype.slice.call(nodes);
            if (!list.length) return;
            const first = list[0];
            const last = list[list.length-1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
        panel.addEventListener('keydown', trapFocus);

        const form = document.getElementById('editWebinarForm');
        form.addEventListener('submit', () => {
            if (saveBtn) saveBtn.disabled = true;
            if (saveSpinner) saveSpinner.classList.remove('hidden');
        });

        function openModal(button) {
            // Populate form fields from data attributes
            document.getElementById('modalWebinarId').value = button.dataset.id;
            document.getElementById('modalTitle').value = button.dataset.title;
            document.getElementById('modalDescription').value = button.dataset.description || '';
            const subheadingField = document.getElementById('modalSubheading');
            const subheadingSizeField = document.getElementById('modalSubheadingSize');
            const subheadingBoldField = document.getElementById('modalSubheadingBold');
            if (subheadingField) subheadingField.value = button.dataset.subheading || '';
            if (subheadingSizeField) subheadingSizeField.value = button.dataset.subheadingSize || '20';
            if (subheadingBoldField) subheadingBoldField.checked = (button.dataset.subheadingBold || '1') === '1';
            document.getElementById('modalHostname').value = button.dataset.hostname;
            document.getElementById('modalSchedule').value = button.dataset.schedule;
            document.getElementById('modalLink').value = button.dataset.link;
            document.getElementById('modalStatus').value = button.dataset.status;
            
            // Handle host picture preview
            const hostPic = button.dataset.hostPic;
            const hostPicContainer = document.getElementById('currentHostPic');
            const hostPicImg = document.getElementById('hostPicPreview');
            const hostPicLink = document.getElementById('hostPicLink');
            
            if (hostPic) {
                hostPicImg.src = hostPic;
                hostPicLink.href = hostPic;
                hostPicContainer.classList.remove('hidden');
            } else {
                hostPicContainer.classList.add('hidden');
            }
            
            // Handle webinar video preview
            const webinarVid = button.dataset.webinarVid;
            const webinarVidContainer = document.getElementById('currentWebinarVid');
            const webinarVidLink = document.getElementById('webinarVidLink');
            
            if (webinarVid) {
                webinarVidLink.href = webinarVid;
                webinarVidContainer.classList.remove('hidden');
            } else {
                webinarVidContainer.classList.add('hidden');
            }
            
            // Show modal with animation
            modal.classList.remove('hidden');
            setTimeout(() => {
                overlay.classList.remove('opacity-0');
                overlay.classList.add('opacity-100');
                panel.classList.remove('opacity-0', 'translate-y-4', 'sm:scale-95');
                panel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
                const firstField = document.getElementById('modalTitle');
                if (firstField) firstField.focus();
            }, 10);
        }
        
        function closeModal() {
            // Hide modal with animation
            overlay.classList.remove('opacity-100');
            overlay.classList.add('opacity-0');
            panel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
            panel.classList.add('opacity-0', 'translate-y-4', 'sm:scale-95');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                if (saveBtn) saveBtn.disabled = false;
                if (saveSpinner) saveSpinner.classList.add('hidden');
            }, 300);
        }
    });
    </script>
    <script>
    // Admin Confirm Modal (Enhanced UI/UX)
    (function() {
      function createModal() {
        let modal = document.getElementById('diConfirmModal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'diConfirmModal';
        modal.className = 'fixed inset-0 z-[200] hidden';
        modal.innerHTML = `
          <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"></div>
          <div class="absolute inset-0 flex items-center justify-center p-4">
            <div role="dialog" aria-modal="true" aria-labelledby="diConfirmTitle" aria-describedby="diConfirmMessage" class="w-full max-w-md bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 rounded-3xl shadow-[0_20px_60px_rgba(2,6,23,0.35)] border border-slate-200/70 dark:border-slate-800/60 overflow-hidden transform scale-95 opacity-0 transition-all">
              <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
                <div id="diConfirmIcon" class="w-9 h-9 rounded-xl flex items-center justify-center text-amber-600 bg-amber-500/10 border border-amber-500/20">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M11 7h2v6h-2V7zm0 8h2v2h-2v-2z"/></svg>
                </div>
                <h3 id="diConfirmTitle" class="text-[1.05rem] font-extrabold tracking-tight">Confirm Action</h3>
              </div>
              <div class="px-6 py-5">
                <p id="diConfirmMessage" class="text-sm leading-relaxed"></p>
              </div>
              <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                <button type="button" id="diConfirmCancel" class="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white/70 dark:bg-slate-900/60 hover:bg-slate-50 dark:hover:bg-slate-800 transition shadow-sm">Cancel</button>
                <button type="button" id="diConfirmOk" class="px-5 py-2 rounded-xl bg-[#1e4a7a] text-white font-semibold hover:bg-[#15365a] transition shadow-sm">OK</button>
              </div>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
        return modal;
      }
      function palette(kind) {
        if (kind === 'danger') return { text:'#dc2626', bg:'bg-rose-500/10', br:'border-rose-500/20', btn:'bg-rose-600 hover:bg-rose-700' };
        if (kind === 'success') return { text:'#059669', bg:'bg-emerald-500/10', br:'border-emerald-500/20', btn:'bg-emerald-600 hover:bg-emerald-700' };
        return { text:'#2563eb', bg:'bg-blue-500/10', br:'border-blue-500/20', btn:'bg-[#1e4a7a] hover:bg-[#15365a]' };
      }
      function confirmModal(message, kind) {
        return new Promise(resolve => {
          const modal = createModal();
          const msg = modal.querySelector('#diConfirmMessage');
          const ok = modal.querySelector('#diConfirmOk');
          const cancel = modal.querySelector('#diConfirmCancel');
          const icon = modal.querySelector('#diConfirmIcon');
          const pal = palette(kind);
          msg.textContent = message || 'Are you sure?';
          icon.style.color = pal.text;
          icon.className = `w-9 h-9 rounded-xl flex items-center justify-center ${pal.bg} border ${pal.br}`;
          ok.className = `px-5 py-2 rounded-xl text-white font-semibold transition shadow-sm ${pal.btn}`;
          modal.classList.remove('hidden');
          const panel = modal.querySelector('[role="dialog"]');
          const overlay = modal.firstElementChild;
          requestAnimationFrame(() => {
            overlay.classList.add('opacity-100');
            panel.classList.remove('scale-95','opacity-0');
            panel.classList.add('scale-100','opacity-100');
          });
          const activeBefore = document.activeElement;
          ok.focus();
          function cleanup(val) {
            const panel = modal.querySelector('[role="dialog"]');
            const overlay = modal.firstElementChild;
            overlay.classList.remove('opacity-100');
            panel.classList.remove('scale-100','opacity-100');
            panel.classList.add('scale-95','opacity-0');
            setTimeout(()=>{ modal.classList.add('hidden'); if (activeBefore) activeBefore.focus(); }, 160);
            ok.removeEventListener('click', onOk);
            cancel.removeEventListener('click', onCancel);
            resolve(val);
          }
          function onOk(){ cleanup(true); }
          function onCancel(){ cleanup(false); }
          ok.addEventListener('click', onOk);
          cancel.addEventListener('click', onCancel);
          function onKey(e){
            if (e.key === 'Escape') { e.preventDefault(); onCancel(); }
            if (e.key === 'Enter') { e.preventDefault(); onOk(); }
            if (e.key === 'Tab') {
              const focusables = [cancel, ok];
              const idx = focusables.indexOf(document.activeElement);
              const next = e.shiftKey ? (idx <= 0 ? focusables.length-1 : idx-1) : (idx >= focusables.length-1 ? 0 : idx+1);
              focusables[next].focus();
              e.preventDefault();
            }
          }
          modal.addEventListener('keydown', onKey, { once:false });
        });
      }
      document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form.js-confirm').forEach(form => {
          form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = form.getAttribute('data-message') || 'Are you sure?';
            const kind = form.getAttribute('data-kind') || 'info';
            const ok = await confirmModal(msg, kind);
            if (ok) form.submit();
          });
        });
      });
    })();
    </script>

    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const overlay = document.getElementById('uploadProgressOverlay');
        const bar = document.getElementById('uploadProgressBar');
        const label = document.getElementById('uploadProgressLabel');
        const bytes = document.getElementById('uploadProgressBytes');
        const sub = document.getElementById('uploadProgressSub');
        const cancelBtn = document.getElementById('uploadProgressCancel');

        if (!overlay || !bar || !label || !bytes || !sub || !cancelBtn) return;

        let xhr = null;
        let locked = false;
        let activeFormId = null;

        function formatBytes(n) {
          const v = Number(n || 0);
          if (!isFinite(v) || v <= 0) return '0 B';
          const units = ['B', 'KB', 'MB', 'GB', 'TB'];
          const i = Math.min(units.length - 1, Math.floor(Math.log(v) / Math.log(1024)));
          const val = v / Math.pow(1024, i);
          return (i === 0 ? String(Math.round(val)) : val.toFixed(1)) + ' ' + units[i];
        }

        function setSubmitting(formId, isSubmitting) {
          if (!formId) return;
          const selector = `button[type="submit"][form="${formId}"], #${formId} button[type="submit"]`;
          document.querySelectorAll(selector).forEach(btn => {
            btn.disabled = isSubmitting;
          });
        }

        function show() {
          overlay.classList.remove('hidden');
          document.body.style.overflow = 'hidden';
        }

        function hide() {
          overlay.classList.add('hidden');
          document.body.style.overflow = '';
          if (activeFormId) setSubmitting(activeFormId, false);
          activeFormId = null;
          locked = false;
          xhr = null;
        }

        function setProgress(pct, loaded, total) {
          const clamped = Math.max(0, Math.min(100, pct));
          bar.style.width = clamped + '%';
          label.textContent = Math.round(clamped) + '%';
          if (typeof loaded === 'number' && typeof total === 'number' && total > 0) {
            bytes.textContent = `${formatBytes(loaded)} / ${formatBytes(total)}`;
          } else {
            bytes.textContent = '';
          }
        }

        function setMode(mode, message) {
          if (mode === 'uploading') {
            cancelBtn.textContent = 'Cancel';
            cancelBtn.disabled = false;
            sub.textContent = message || 'Please keep this tab open.';
          } else if (mode === 'finalizing') {
            cancelBtn.textContent = 'Close';
            cancelBtn.disabled = true;
            sub.textContent = message || 'Finalizing…';
          } else if (mode === 'error') {
            cancelBtn.textContent = 'Close';
            cancelBtn.disabled = false;
            sub.textContent = message || 'Upload failed. Please try again.';
          } else if (mode === 'cancelled') {
            cancelBtn.textContent = 'Close';
            cancelBtn.disabled = false;
            sub.textContent = message || 'Upload cancelled.';
          }
        }

        cancelBtn.addEventListener('click', () => {
          if (cancelBtn.textContent.trim() === 'Close') {
            hide();
            return;
          }
          if (xhr) {
            xhr.abort();
          } else {
            hide();
          }
        });

        function uploadForm(form, formId) {
          xhr = new XMLHttpRequest();
          activeFormId = formId;
          setSubmitting(formId, true);
          setProgress(0, 0, 0);
          setMode('uploading');
          show();

          xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
              setProgress((e.loaded / e.total) * 100, e.loaded, e.total);
            } else {
              setProgress(0, 0, 0);
            }
          });

          xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 400) {
              setProgress(100, 1, 1);
              setMode('finalizing', 'Finishing up…');
              window.location.reload();
              return;
            }
            setMode('error');
            setSubmitting(formId, false);
          });

          xhr.addEventListener('error', () => {
            setMode('error');
            setSubmitting(formId, false);
          });

          xhr.addEventListener('abort', () => {
            setMode('cancelled');
            setSubmitting(formId, false);
          });

          xhr.open('POST', form.getAttribute('action') || window.location.href, true);
          xhr.send(new FormData(form));
        }

        function wireForm(formId) {
          const form = document.getElementById(formId);
          if (!form) return;
          form.addEventListener('submit', (e) => {
            const hasFiles = Array.from(form.querySelectorAll('input[type="file"]')).some(i => i.files && i.files.length > 0);
            if (!hasFiles) return;
            if (locked) { e.preventDefault(); return; }
            locked = true;
            e.preventDefault();
            uploadForm(form, formId);
          });
        }

        wireForm('addWebinarForm');
        wireForm('editWebinarForm');
      });
    </script>

</body>
</html>
