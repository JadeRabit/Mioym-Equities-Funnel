<?php
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: registration.php');
    exit;
}

// CSRF Protection
if (!isset($_SESSION['csrf']) || !isset($_SESSION['csrf']['value']) || time() >= (int)($_SESSION['csrf']['expires'] ?? 0)) {
    $_SESSION['csrf'] = [
        'value' => bin2hex(random_bytes(32)),
        'expires' => time() + 900
    ];
}

function validate_csrf_webinars($token) {
    if (!isset($_SESSION['csrf']['value']) || !isset($_SESSION['csrf']['expires'])) return false;
    if (time() >= (int)$_SESSION['csrf']['expires']) return false;
    return hash_equals($_SESSION['csrf']['value'], (string)$token);
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

$hasHostDescription = true;
try {
    $pdo->query("SELECT host_description FROM webinar_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasHostDescription = false;
}
if (!$hasHostDescription) {
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN host_description TEXT NULL");
    } catch (Throwable $e) {
    }
    try {
        $pdo->query("SELECT host_description FROM webinar_tbl LIMIT 1");
        $hasHostDescription = true;
    } catch (Throwable $e) {
        $hasHostDescription = false;
    }
}

$hasSubheading = true;
try {
    $pdo->query("SELECT subheading, subheading_size, subheading_bold FROM webinar_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasSubheading = false;
}
// Optional color column
$hasSubheadingColor = true;
try {
    $pdo->query("SELECT subheading_color FROM webinar_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasSubheadingColor = false;
}
$hasSubheadingItemsJson = true;
try {
    $pdo->query("SELECT subheading_items_json FROM webinar_tbl LIMIT 1");
} catch (Throwable $e) {
    $hasSubheadingItemsJson = false;
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
if (!$hasSubheadingColor) {
    try { $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN subheading_color VARCHAR(16) NULL DEFAULT '#ffffff'"); } catch (Throwable $e) {}
    try {
        $pdo->query("SELECT subheading_color FROM webinar_tbl LIMIT 1");
        $hasSubheadingColor = true;
    } catch (Throwable $e) {
        $hasSubheadingColor = false;
    }
}
if (!$hasSubheadingItemsJson) {
    try { $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN subheading_items_json LONGTEXT NULL"); } catch (Throwable $e) {}
    try {
        $pdo->query("SELECT subheading_items_json FROM webinar_tbl LIMIT 1");
        $hasSubheadingItemsJson = true;
    } catch (Throwable $e) {
        $hasSubheadingItemsJson = false;
    }
}

// Handle CRUD & Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_webinars($csrf)) {
        $_SESSION['di'] = ['type' => 'error', 'title' => 'Security', 'message' => 'Security check failed. Please try again.'];
        header('Location: webinars.php');
        exit;
    }
    
    $action = $_POST['action'];
    $uploadError = '';
    
    // File upload helper function with validation
    function handleUpload($fileInputName, $uploadDir = 'uploads/', $allowedTypes = []) {
        global $uploadError;
        
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        if ($_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
            $uploadError = "Upload error code: " . $_FILES[$fileInputName]['error'];
            return null;
        }
        
        $fileExt = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        
        if (!empty($allowedTypes) && !in_array($fileExt, $allowedTypes)) {
            $uploadError = "Invalid file type. Allowed: " . implode(', ', $allowedTypes);
            return null;
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
        $hostDescription = trim($_POST['host_description'] ?? '');
        $subheading = trim($_POST['subheading'] ?? '');
        $subheadingSize = (int)($_POST['subheading_size'] ?? 20);
        if ($subheadingSize < 10) $subheadingSize = 10;
        if ($subheadingSize > 80) $subheadingSize = 80;
        $subheadingBold = isset($_POST['subheading_bold']) ? 1 : 0;
        $subheadingColor = trim((string)($_POST['subheading_color'] ?? '#ffffff'));
        if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $subheadingColor)) $subheadingColor = '#ffffff';
        if ($subheadingColor !== '' && $subheadingColor[0] !== '#') $subheadingColor = '#' . $subheadingColor;
        $subheadingItemsJson = trim((string)($_POST['subheading_items_json'] ?? ''));
        $subheadingItems = [];
        if ($subheadingItemsJson !== '') {
            $decoded = json_decode($subheadingItemsJson, true);
            if (is_array($decoded)) $subheadingItems = $decoded;
        }
        $allowedFonts = ['system_sans','system_serif','system_mono','arial','georgia','times','courier','verdana','trebuchet'];
        $cleanItems = [];
        foreach ($subheadingItems as $it) {
            if (!is_array($it)) continue;
            $text = trim((string)($it['text'] ?? ''));
            if ($text === '') continue;
            $size = (int)($it['size'] ?? 20);
            if ($size < 10) $size = 10;
            if ($size > 80) $size = 80;
            $spacing = (int)($it['spacing'] ?? 8);
            if ($spacing < 0) $spacing = 0;
            if ($spacing > 64) $spacing = 64;
            $color = trim((string)($it['color'] ?? '#ffffff'));
            if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color)) $color = '#ffffff';
            if ($color !== '' && $color[0] !== '#') $color = '#' . $color;
            $bold = !empty($it['bold']) ? 1 : 0;
            $font = (string)($it['font'] ?? 'system_sans');
            if (!in_array($font, $allowedFonts, true)) $font = 'system_sans';
            $cleanItems[] = ['text' => $text, 'size' => $size, 'spacing' => $spacing, 'color' => $color, 'bold' => $bold, 'font' => $font];
            if (count($cleanItems) >= 12) break;
        }
        if (!empty($cleanItems)) {
            $subheading = implode("\n", array_map(static fn ($x) => (string)$x['text'], $cleanItems));
            $subheadingSize = (int)$cleanItems[0]['size'];
            $subheadingColor = (string)$cleanItems[0]['color'];
            $subheadingBold = (int)$cleanItems[0]['bold'];
        }
        $finalSubheadingItemsJson = !empty($cleanItems) ? json_encode($cleanItems, JSON_UNESCAPED_UNICODE) : null;
        
        $host_pic_path = handleUpload('host_pic', 'uploads/', ['jpg', 'jpeg', 'png']);
        $webinar_vid_path = handleUpload('webinar_vid', 'uploads/', ['mp4']);
        $duration = trim($_POST['duration'] ?? '60-minute');
        $timezone = trim($_POST['timezone'] ?? 'America/New_York');
        
        if (!empty($uploadError)) {
            $_SESSION['di'] = ['type' => 'error', 'title' => 'Upload Error', 'message' => $uploadError];
            header('Location: webinars.php');
            exit;
        }
        
        $columns = ['title', 'hostname', 'host_pic', 'webinar_vid', 'webinar_id', 'webinar_link', '`schedule_date&time`', 'status', 'duration', 'timezone'];
        $values = [$_POST['title'], $_POST['hostname'], $host_pic_path, $webinar_vid_path, $new_webinar_id, $_POST['meeting_link'], $_POST['schedule_date_time'], $status, $duration, $timezone];

        if ($hasDescription) {
            array_splice($columns, 1, 0, ['description']);
            array_splice($values, 1, 0, [$description]);
        }
        if ($hasHostDescription) {
            $idx = array_search('hostname', $columns, true);
            $v = $hostDescription !== '' ? $hostDescription : null;
            if ($idx !== false) {
                array_splice($columns, $idx + 1, 0, ['host_description']);
                array_splice($values, $idx + 1, 0, [$v]);
            } else {
                $columns[] = 'host_description';
                $values[] = $v;
            }
        }
        if ($hasSubheading) {
            $columns[] = 'subheading';
            $columns[] = 'subheading_size';
            $columns[] = 'subheading_bold';
            $values[] = $subheading !== '' ? $subheading : null;
            $values[] = $subheadingSize;
            $values[] = $subheadingBold;
        }
        if ($hasSubheadingColor) {
            $columns[] = 'subheading_color';
            $values[] = $subheadingColor;
        }
        if ($hasSubheadingItemsJson) {
            $columns[] = 'subheading_items_json';
            $values[] = $finalSubheadingItemsJson;
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
        $host_pic_path = handleUpload('host_pic', 'uploads/', ['jpg', 'jpeg', 'png']);
        $webinar_vid_path = handleUpload('webinar_vid', 'uploads/', ['mp4']);
        
        if (!empty($uploadError)) {
            $_SESSION['di'] = ['type' => 'error', 'title' => 'Upload Error', 'message' => $uploadError];
            header('Location: webinars.php');
            exit;
        }
        
        // Build update query dynamically based on whether files were uploaded
        $description = trim($_POST['description'] ?? '');
        $hostDescription = trim($_POST['host_description'] ?? '');
        $subheading = trim($_POST['subheading'] ?? '');
        $subheadingSize = (int)($_POST['subheading_size'] ?? 20);
        if ($subheadingSize < 10) $subheadingSize = 10;
        if ($subheadingSize > 80) $subheadingSize = 80;
        $subheadingBold = isset($_POST['subheading_bold']) ? 1 : 0;
        $subheadingColor = trim((string)($_POST['subheading_color'] ?? '#ffffff'));
        if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $subheadingColor)) $subheadingColor = '#ffffff';
        if ($subheadingColor !== '' && $subheadingColor[0] !== '#') $subheadingColor = '#' . $subheadingColor;
        $subheadingItemsJson = trim((string)($_POST['subheading_items_json'] ?? ''));
        $subheadingItems = [];
        if ($subheadingItemsJson !== '') {
            $decoded = json_decode($subheadingItemsJson, true);
            if (is_array($decoded)) $subheadingItems = $decoded;
        }
        $allowedFonts = ['system_sans','system_serif','system_mono','arial','georgia','times','courier','verdana','trebuchet'];
        $cleanItems = [];
        foreach ($subheadingItems as $it) {
            if (!is_array($it)) continue;
            $text = trim((string)($it['text'] ?? ''));
            if ($text === '') continue;
            $size = (int)($it['size'] ?? 20);
            if ($size < 10) $size = 10;
            if ($size > 80) $size = 80;
            $spacing = (int)($it['spacing'] ?? 8);
            if ($spacing < 0) $spacing = 0;
            if ($spacing > 64) $spacing = 64;
            $color = trim((string)($it['color'] ?? '#ffffff'));
            if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color)) $color = '#ffffff';
            if ($color !== '' && $color[0] !== '#') $color = '#' . $color;
            $bold = !empty($it['bold']) ? 1 : 0;
            $font = (string)($it['font'] ?? 'system_sans');
            if (!in_array($font, $allowedFonts, true)) $font = 'system_sans';
            $cleanItems[] = ['text' => $text, 'size' => $size, 'spacing' => $spacing, 'color' => $color, 'bold' => $bold, 'font' => $font];
            if (count($cleanItems) >= 12) break;
        }
        if (!empty($cleanItems)) {
            $subheading = implode("\n", array_map(static fn ($x) => (string)$x['text'], $cleanItems));
            $subheadingSize = (int)$cleanItems[0]['size'];
            $subheadingColor = (string)$cleanItems[0]['color'];
            $subheadingBold = (int)$cleanItems[0]['bold'];
        }
        $finalSubheadingItemsJson = !empty($cleanItems) ? json_encode($cleanItems, JSON_UNESCAPED_UNICODE) : null;
        $duration = trim($_POST['duration'] ?? '60-minute');
        $timezone = trim($_POST['timezone'] ?? 'America/New_York');

        if ($hasDescription) {
            $updateQuery = "UPDATE webinar_tbl SET title=?, description=?, hostname=?, `schedule_date&time`=?, webinar_link=?, status=?, duration=?, timezone=?";
            $params = [$_POST['title'], $description, $_POST['hostname'], $_POST['schedule_date_time'], $_POST['meeting_link'], $_POST['status'], $duration, $timezone];
        } else {
            $updateQuery = "UPDATE webinar_tbl SET title=?, hostname=?, `schedule_date&time`=?, webinar_link=?, status=?, duration=?, timezone=?";
            $params = [$_POST['title'], $_POST['hostname'], $_POST['schedule_date_time'], $_POST['meeting_link'], $_POST['status'], $duration, $timezone];
        }
        if ($hasHostDescription) {
            $updateQuery .= ", host_description=?";
            $params[] = $hostDescription !== '' ? $hostDescription : null;
        }

        if ($hasSubheading) {
            $updateQuery .= ", subheading=?, subheading_size=?, subheading_bold=?";
            $params[] = $subheading !== '' ? $subheading : null;
            $params[] = $subheadingSize;
            $params[] = $subheadingBold;
        }
        if ($hasSubheadingColor) {
            $updateQuery .= ", subheading_color=?";
            $params[] = $subheadingColor;
        }
        if ($hasSubheadingItemsJson) {
            $updateQuery .= ", subheading_items_json=?";
            $params[] = $finalSubheadingItemsJson;
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
$offset = (int)(($page - 1) * $limit);

// Sanitize limit and offset as extra security
$limit = max(1, min((int)$limit, 100)); // Between 1 and 100
$offset = max(0, (int)$offset);

// Fetch Data
$total_webinars = $pdo->query("SELECT COUNT(*) FROM webinar_tbl")->fetchColumn();
$total_pages = ceil($total_webinars / $limit);

$stmt = $pdo->prepare("SELECT * FROM webinar_tbl ORDER BY `schedule_date&time` DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$webinars = $stmt->fetchAll(PDO::FETCH_ASSOC);

function webinar_subheading_font_stack($key) {
    $key = (string)$key;
    $map = [
        'system_sans' => "ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif",
        'system_serif' => "ui-serif, Georgia, Cambria, 'Times New Roman', Times, serif",
        'system_mono' => "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace",
        'arial' => "Arial, Helvetica, sans-serif",
        'georgia' => "Georgia, Cambria, 'Times New Roman', Times, serif",
        'times' => "'Times New Roman', Times, serif",
        'courier' => "'Courier New', Courier, monospace",
        'verdana' => "Verdana, Geneva, sans-serif",
        'trebuchet' => "'Trebuchet MS', 'Segoe UI', sans-serif"
    ];
    return $map[$key] ?? $map['system_sans'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webinars · Mioym Equities</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
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
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen overflow-hidden">

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
                                                <?php if (!empty($w['host_description'])): ?>
                                                    <div class="mt-1 text-xs text-slate-500 line-clamp-2 max-w-md">
                                                        <?php echo htmlspecialchars((string)$w['host_description']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php
                                                    $subPreview = [];
                                                    $rawJson = (string)($w['subheading_items_json'] ?? '');
                                                    if ($rawJson !== '') {
                                                        $decoded = json_decode($rawJson, true);
                                                        if (is_array($decoded)) $subPreview = $decoded;
                                                    }
                                                    if (empty($subPreview)) {
                                                        $rawText = trim((string)($w['subheading'] ?? ''));
                                                        if ($rawText !== '') {
                                                            $lines = preg_split("/\r\n|\r|\n/", $rawText);
                                                            $lines = array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));
                                                            foreach ($lines as $ln) {
                                                                $subPreview[] = [
                                                                    'text' => $ln,
                                                                    'size' => (int)($w['subheading_size'] ?? 16),
                                                                    'color' => (string)($w['subheading_color'] ?? '#475569'),
                                                                    'bold' => (int)($w['subheading_bold'] ?? 0),
                                                                    'font' => 'system_sans'
                                                                ];
                                                            }
                                                        }
                                                    }
                                                    $subPreview = array_slice($subPreview, 0, 2);
                                                ?>
                                                <?php if (!empty($subPreview)): ?>
                                                    <div class="mt-2 space-y-1 max-w-md">
                                                        <?php foreach ($subPreview as $it): ?>
                                                            <?php
                                                                $t = trim((string)($it['text'] ?? ''));
                                                                if ($t === '') continue;
                                                                $sz = (int)($it['size'] ?? 16);
                                                                if ($sz < 10) $sz = 10;
                                                                if ($sz > 18) $sz = 18;
                                                                $col = trim((string)($it['color'] ?? '#475569'));
                                                                if (!preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $col)) $col = '#475569';
                                                                if ($col !== '' && $col[0] !== '#') $col = '#' . $col;
                                                                $bold = !empty($it['bold']) ? 700 : 500;
                                                                $fontKey = (string)($it['font'] ?? 'system_sans');
                                                            ?>
                                                            <div class="text-xs leading-snug line-clamp-1" style="font-size: <?php echo (int)$sz; ?>px; font-weight: <?php echo (int)$bold; ?>; color: <?php echo htmlspecialchars($col); ?>; font-family: <?php echo htmlspecialchars(webinar_subheading_font_stack($fontKey)); ?>;">
                                                                <?php echo htmlspecialchars($t); ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
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
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value']); ?>">
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
                                                data-subheading-color="<?php echo htmlspecialchars((string)($w['subheading_color'] ?? '#ffffff')); ?>"
                                                data-subheading-items-json="<?php echo htmlspecialchars((string)($w['subheading_items_json'] ?? '')); ?>"
                                                data-hostname="<?php echo htmlspecialchars($w['hostname'] ?? ''); ?>"
                                                data-host-description="<?php echo htmlspecialchars((string)($w['host_description'] ?? '')); ?>"
                                                data-host-pic="<?php echo htmlspecialchars(str_replace('\\', '/', $w['host_pic'] ?? '')); ?>"
                                                data-webinar-vid="<?php echo htmlspecialchars(str_replace('\\', '/', $w['webinar_vid'] ?? '')); ?>"
                                                data-schedule="<?php echo date('Y-m-d\\TH:i', strtotime($w['schedule_date&time'])); ?>"
                                                data-link="<?php echo htmlspecialchars($w['webinar_link'] ?? ''); ?>"
                                                data-status="<?php echo strtolower($w['status'] ?? 'inactive'); ?>"
                                                data-duration="<?php echo htmlspecialchars($w['duration'] ?? '60-minute'); ?>"
                                                title="Edit Webinar"
                                            >
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            
                                            <!-- Delete Form -->
                                            <form method="POST" class="inline js-confirm" data-message="Are you sure you want to delete this webinar?" data-kind="danger">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value']); ?>">
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
                            <div x-show="showAddModal" @click.away="showAddModal = false" x-transition:enter="transform transition ease-in-out duration-500 sm:duration-700" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-500 sm:duration-700" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="pointer-events-auto w-screen max-w-lg">
                                <div class="flex h-full flex-col overflow-y-scroll bg-white dark:bg-slate-900 shadow-2xl border-l border-slate-100 dark:border-slate-800">
                                    
                                    <!-- Header -->
                                    <div class="px-6 py-8 border-b border-slate-100 dark:border-slate-800 sm:px-8 sticky top-0 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md z-10 flex items-center justify-between">
                                        <div>
                                            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight" id="slide-over-title">Add New Webinar</h2>
                                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400 mt-1">Configure your event details and broadcast settings.</p>
                                        </div>
                                        <button @click="showAddModal = false" type="button" class="relative rounded-xl p-2.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-slate-100 transition-all focus:outline-none">
                                            <i class="fas fa-times text-xl"></i>
                                        </button>
                                    </div>

                                    <!-- Form Body -->
                                    <div class="relative flex-1 px-6 pt-10 pb-32 sm:px-8">
                                        <form method="POST" enctype="multipart/form-data" class="space-y-12" id="addWebinarForm">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value']); ?>">
                                            <input type="hidden" name="action" value="add_webinar">
                                            
                                            <!-- Section 1: Basic Info -->
                                            <div class="p-6 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-6">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="w-1.5 h-4 bg-blue-600 rounded-full"></span>
                                                    <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Basic Information</h3>
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Webinar Title <span class="text-rose-500">*</span></label>
                                                    <input type="text" name="title" required placeholder="e.g., Q3 2026 Investment Strategy" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 placeholder-slate-400 font-medium">
                                                </div>

                                                <div>
                                                    <div class="flex items-center justify-between mb-2">
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300">Description</label>
                                                    </div>
                                                    <textarea name="description" id="addWebinarDesc" rows="4" placeholder="Provide a brief overview of the webinar agenda..." class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 placeholder-slate-400 resize-none font-medium leading-relaxed"></textarea>
                                                </div>
                                            </div>

                                            <!-- Section 2: Dynamic Subheadings -->
                                            <div class="space-y-6">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="w-1.5 h-4 bg-indigo-600 rounded-full"></span>
                                                    <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Dynamic Subheadings</h3>
                                                </div>
                                                
                                                <input type="hidden" name="subheading" id="addSubheadingHidden">
                                                <input type="hidden" name="subheading_items_json" id="addSubheadingItemsJson">
                                                
                                                <div id="addSubheadingItems" class="space-y-4">
                                                    <!-- Subheading items will be injected here via JS -->
                                                </div>

                                                <button type="button" class="group w-full py-4 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 hover:border-indigo-500/50 hover:bg-indigo-50/30 dark:hover:bg-indigo-900/20 transition-all flex flex-col items-center justify-center gap-1" data-sub-item-add>
                                                    <div class="w-8 h-8 rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-400 group-hover:text-indigo-600 group-hover:border-indigo-200 transition-all">
                                                        <i class="fas fa-plus text-xs"></i>
                                                    </div>
                                                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 group-hover:text-indigo-600 uppercase tracking-wider transition-all">Add Subheading</span>
                                                </button>
                                            </div>

                                            <!-- Section 3: Logistics -->
                                            <div class="p-6 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-6">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="w-1.5 h-4 bg-emerald-600 rounded-full"></span>
                                                    <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Logistics & Schedule</h3>
                                                </div>

                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Schedule <span class="text-rose-500">*</span></label>
                                                        <div class="relative">
                                                            <input type="datetime-local" name="schedule_date_time" required class="w-full pl-4 pr-10 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 font-medium">
                                                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                                                                <i class="far fa-calendar-alt text-sm"></i>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Duration</label>
                                                        <input type="text" name="duration" placeholder="e.g., 60-minute" value="60-minute" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 font-medium">
                                                    </div>
                                                </div>

                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Status</label>
                                                        <select name="status" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 font-bold appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20fill%3D%27none%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20stroke%3D%27%236b7280%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%20stroke-width%3D%271.5%27%20d%3D%27m6%208%204%204%204-4%27%2F%3E%3C%2Fsvg%3E')] bg-[length:1.25rem_1.25rem] bg-[right_0.5rem_center] bg-no-repeat">
                                                            <option value="upcoming">Upcoming</option>
                                                            <option value="active">Active (Live)</option>
                                                            <option value="inactive">Inactive</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Meeting Link <span class="text-rose-500">*</span></label>
                                                        <div class="relative group">
                                                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                                                <i class="fas fa-link text-slate-400 text-sm group-focus-within:text-emerald-500 transition-colors"></i>
                                                            </div>
                                                            <input type="url" name="meeting_link" required placeholder="https://zoom.us/j/..." class="w-full pl-11 pr-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 placeholder-slate-400 font-medium">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Host & Media -->
                                            <div class="p-6 mb-5 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-6">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="w-1.5 h-4 bg-slate-600 rounded-full"></span>
                                                    <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Host & Media Assets</h3>
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Host Name</label>
                                                    <div class="relative group">
                                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                                            <i class="fas fa-id-badge text-slate-400 text-sm group-focus-within:text-slate-600 transition-colors"></i>
                                                        </div>
                                                        <input type="text" name="hostname" placeholder="e.g., John Doe" class="w-full pl-11 pr-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-slate-500/10 focus:border-slate-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 placeholder-slate-400 font-medium">
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Host Description</label>
                                                    <textarea name="host_description" rows="3" placeholder="Provide a brief bio or professional description of the host..." class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-slate-500/10 focus:border-slate-500 transition-all outline-none text-sm text-slate-800 dark:text-slate-200 placeholder-slate-400 resize-none font-medium leading-relaxed"></textarea>
                                                </div>
                                                
                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Host Avatar</label>
                                                        <label class="group relative flex justify-center rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 px-4 py-6 bg-white dark:bg-slate-800 hover:border-slate-400 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-all cursor-pointer overflow-hidden">
                                                            <div class="text-center relative z-10">
                                                                <i class="fas fa-image text-2xl text-slate-300 dark:text-slate-600 mb-2 group-hover:text-slate-400 transition-colors"></i>
                                                                <div class="text-xs font-bold text-slate-600 dark:text-slate-400">Upload Image</div>
                                                                <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-tight">PNG, JPG (2MB)</p>
                                                            </div>
                                                            <input type="file" name="host_pic" id="addHostPic" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                                        </label>
                                                        <div id="addHostPicPreview" class="mt-4 hidden p-3 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center gap-4 animate-in fade-in slide-in-from-top-2">
                                                            <img id="addHostPicImg" class="h-12 w-12 rounded-xl object-cover border border-slate-200 dark:border-slate-700 shadow-sm" alt="Preview">
                                                            <div class="flex flex-col">
                                                                <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Image Ready</span>
                                                                <span id="addHostPicName" class="text-xs font-bold text-slate-700 dark:text-slate-200 truncate max-w-[120px]">filename.jpg</span>
                                                            </div>
                                                            <button type="button" id="addHostPicRemove" class="ml-auto w-8 h-8 flex items-center justify-center text-slate-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-lg transition-all">
                                                                <i class="fas fa-times-circle"></i>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Webinar Video</label>
                                                        <label class="group relative flex justify-center rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 px-4 py-6 bg-white dark:bg-slate-800 hover:border-rose-400 hover:bg-rose-50/30 dark:hover:bg-rose-900/10 transition-all cursor-pointer overflow-hidden">
                                                            <div class="text-center relative z-10">
                                                                <i class="fas fa-play-circle text-2xl text-slate-300 dark:text-slate-600 mb-2 group-hover:text-rose-500 transition-colors"></i>
                                                                <div class="text-xs font-bold text-slate-600 dark:text-slate-400 group-hover:text-rose-700 transition-colors">Upload MP4</div>
                                                                <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-tight">MP4 (50MB)</p>
                                                            </div>
                                                            <input type="file" name="webinar_vid" id="addWebinarVid" accept="video/mp4,video/x-m4v,video/*" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                                        </label>
                                                        <div id="addWebinarVidPreview" class="mt-4 hidden p-3 rounded-2xl bg-white dark:bg-slate-800 border border-rose-100 dark:border-rose-900/30 flex items-center gap-4 animate-in fade-in slide-in-from-top-2">
                                                            <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-900/20 border border-rose-100 dark:border-rose-900/30 flex items-center justify-center text-rose-500">
                                                                <i class="fas fa-file-video text-xl"></i>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span class="text-[10px] font-bold text-rose-600 uppercase tracking-widest">Video Ready</span>
                                                                <span id="addWebinarVidName" class="text-xs font-bold text-slate-700 dark:text-slate-200 truncate max-w-[120px]">video.mp4</span>
                                                            </div>
                                                            <button type="button" id="addWebinarVidRemove" class="ml-auto w-8 h-8 flex items-center justify-center text-slate-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded-lg transition-all">
                                                                <i class="fas fa-times-circle"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- Footer Actions -->
                                    <div class="px-6 py-6 border-t border-slate-100 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md sticky bottom-0 z-10 flex items-center justify-end gap-4 shadow-[0_-10px_30px_rgba(0,0,0,0.03)]">
                                        <button @click="showAddModal = false" type="button" class="px-6 py-3 text-sm font-bold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 transition-colors">
                                            Cancel
                                        </button>
                                        <button type="submit" form="addWebinarForm" class="rounded-2xl bg-blue-600 px-8 py-3 text-sm font-bold text-white shadow-xl shadow-blue-600/20 hover:bg-blue-700 hover:-translate-y-0.5 active:translate-y-0 transition-all flex items-center gap-2">
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
         class="relative z-[9999]" 
         aria-labelledby="modal-title" role="dialog" aria-modal="true" x-data="{ showEditModal: false }" @open-edit-modal.window="showEditModal = true" @close-edit-modal.window="showEditModal = false" x-show="showEditModal" x-cloak>
        
        <div x-show="showEditModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"></div>

        <div class="fixed inset-0 overflow-hidden">
            <div class="absolute inset-0 overflow-hidden">
                <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                    <!-- Slide-over panel -->
                    <div x-show="showEditModal" @click.away="showEditModal = false" x-transition:enter="transform transition ease-in-out duration-500 sm:duration-700" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-500 sm:duration-700" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="pointer-events-auto w-screen max-w-lg">
                        <div class="flex h-full flex-col overflow-y-scroll bg-white dark:bg-slate-900 shadow-2xl border-l border-slate-100 dark:border-slate-800">
                            
                            <div class="px-6 py-8 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between sticky top-0 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md z-10">
                                <div class="flex items-center gap-4">
                                    <div class="flex items-center justify-center w-12 h-12 rounded-2xl bg-blue-600/10 border border-blue-600/20 text-blue-600">
                                        <i class="fas fa-pen text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100" id="modal-title">Edit Webinar</h3>
                                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mt-1">Update broadcast settings and media assets.</p>
                                    </div>
                                </div>
                                <button type="button" id="editCloseBtn" class="w-10 h-10 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 flex items-center justify-center transition-all"><i class="fas fa-times"></i></button>
                            </div>

                            <div class="relative flex-1 px-6 pt-10 pb-32 sm:px-8">
                                <form method="POST" id="editWebinarForm" enctype="multipart/form-data" class="space-y-12">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf']['value']); ?>">
                                    <input type="hidden" name="action" value="edit_webinar">
                                    <input type="hidden" name="webinar_id" id="modalWebinarId">
                                    
                                    <!-- Section 1: Basic Info -->
                                    <div class="p-6 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-6">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="w-1.5 h-4 bg-blue-600 rounded-full"></span>
                                            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Basic Information</h3>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Webinar Title <span class="text-rose-500">*</span></label>
                                            <input type="text" name="title" id="modalTitle" required class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium text-slate-800 dark:text-slate-200" placeholder="e.g., Q3 2026 Investment Strategy">
                                        </div>

                                        <div>
                                            <div class="flex items-center justify-between mb-2">
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300">Description</label>
                                            </div>
                                            <textarea name="description" id="modalDescription" rows="4" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all resize-none text-sm font-medium text-slate-800 dark:text-slate-200 leading-relaxed" placeholder="Provide a brief overview..."></textarea>
                                        </div>
                                    </div>

                                    <!-- Section 2: Dynamic Subheadings -->
                                    <div class="space-y-6">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="w-1.5 h-4 bg-indigo-600 rounded-full"></span>
                                            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Dynamic Subheadings</h3>
                                        </div>
                                        
                                        <input type="hidden" name="subheading" id="modalSubheadingHidden">
                                        <input type="hidden" name="subheading_items_json" id="modalSubheadingItemsJson">
                                        
                                        <div id="modalSubheadingItems" class="space-y-4"></div>

                                        <button type="button" class="group w-full py-4 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 hover:border-indigo-500/50 hover:bg-indigo-50/30 transition-all flex flex-col items-center justify-center gap-1" data-sub-item-add>
                                            <div class="w-8 h-8 rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-400 group-hover:text-indigo-600 group-hover:border-indigo-200 transition-all">
                                                <i class="fas fa-plus text-xs"></i>
                                            </div>
                                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400 group-hover:text-indigo-600 uppercase tracking-wider transition-all">Add Subheading</span>
                                        </button>
                                    </div>

                                    <!-- Section 3: Host Details -->
                                    <div class="p-6 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-6">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="w-1.5 h-4 bg-slate-600 rounded-full"></span>
                                            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Host Profile</h3>
                                        </div>

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Host Name</label>
                                                <input type="text" name="hostname" id="modalHostname" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-slate-500/10 focus:border-slate-500 outline-none text-sm font-medium text-slate-800 dark:text-slate-200" placeholder="e.g., Jane Doe">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Host Avatar</label>
                                                <label class="group relative flex justify-center rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 px-4 py-6 bg-white dark:bg-slate-800 hover:border-slate-400 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-all cursor-pointer overflow-hidden">
                                                    <div class="text-center relative z-10">
                                                        <i class="fas fa-image text-2xl text-slate-300 dark:text-slate-600 mb-2 group-hover:text-slate-400 transition-colors"></i>
                                                        <div class="text-xs font-bold text-slate-600 dark:text-slate-400">Update Avatar</div>
                                                        <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-tight">PNG, JPG (2MB)</p>
                                                    </div>
                                                    <input type="file" name="host_pic" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                                </label>
                                                <div id="currentHostPic" class="mt-4 flex items-center gap-4 hidden p-3 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700">
                                                    <img id="hostPicPreview" class="h-12 w-12 rounded-xl object-cover border border-slate-200 dark:border-slate-700 shadow-sm" alt="Current Host">
                                                    <div class="flex flex-col">
                                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Current Active</span>
                                                        <a id="hostPicLink" target="_blank" class="text-xs font-bold text-blue-600 dark:text-blue-400 hover:underline">Preview Image</a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Host Description</label>
                                            <textarea name="host_description" id="modalHostDescription" rows="3" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-slate-500/10 focus:border-slate-500 outline-none transition-all resize-none text-sm font-medium text-slate-800 dark:text-slate-200" placeholder="Short bio or professional description..."></textarea>
                                        </div>
                                    </div>

                                    <!-- Section 4: Assets & Logistics -->
                                    <div class="p-6 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-6">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="w-1.5 h-4 bg-emerald-600 rounded-full"></span>
                                            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-200 uppercase tracking-widest">Broadcast Assets</h3>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Webinar Video</label>
                                            <label class="group relative flex justify-center rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 px-4 py-6 bg-white dark:bg-slate-800 hover:border-rose-400 hover:bg-rose-50/30 dark:hover:bg-rose-900/10 transition-all cursor-pointer overflow-hidden">
                                                <div class="text-center relative z-10">
                                                    <i class="fas fa-play-circle text-2xl text-slate-300 dark:text-slate-600 mb-2 group-hover:text-rose-500 transition-colors"></i>
                                                    <div class="text-xs font-bold text-slate-600 dark:text-slate-400 group-hover:text-rose-700 transition-colors">Update MP4 Asset</div>
                                                    <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-tight">MP4 (50MB)</p>
                                                </div>
                                                <input type="file" name="webinar_vid" accept="video/mp4,video/x-m4v,video/*" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                            </label>
                                            <div id="currentWebinarVid" class="mt-4 hidden">
                                                <a id="webinarVidLink" target="_blank" class="inline-flex items-center gap-2 text-xs font-bold text-rose-600 dark:text-rose-400 hover:text-rose-700 dark:hover:text-rose-300 hover:underline bg-rose-50 dark:bg-rose-900/20 px-4 py-2 rounded-xl transition-all border border-rose-100 dark:border-rose-900/30">
                                                    <i class="fas fa-play-circle"></i> View Current Video Asset
                                                </a>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Schedule <span class="text-rose-500">*</span></label>
                                                <div class="relative">
                                                    <input type="datetime-local" name="schedule_date_time" id="modalSchedule" required class="w-full pl-4 pr-10 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 outline-none text-sm font-medium text-slate-800 dark:text-slate-200">
                                                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                                                        <i class="far fa-calendar-alt text-sm"></i>
                                                    </div>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Duration</label>
                                                <input type="text" name="duration" id="modalDuration" placeholder="e.g., 60-minute" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 outline-none text-sm font-medium text-slate-800 dark:text-slate-200">
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Status</label>
                                                <select name="status" id="modalStatus" class="w-full px-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 outline-none text-sm font-bold text-slate-800 dark:text-slate-200 appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20fill%3D%27none%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20stroke%3D%27%236b7280%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%20stroke-width%3D%271.5%27%20d%3D%27m6%208%204%204%204-4%27%2F%3E%3C%2Fsvg%3E')] bg-[length:1.25rem_1.25rem] bg-[right_0.5rem_center] bg-no-repeat">
                                                    <option value="upcoming">Upcoming</option>
                                                    <option value="active">Active</option>
                                                    <option value="inactive">Inactive</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900 dark:text-slate-300 mb-2">Webinar Link <span class="text-rose-500">*</span></label>
                                                <div class="relative group">
                                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                                        <i class="fas fa-link text-slate-400 text-sm group-focus-within:text-emerald-500 transition-colors"></i>
                                                    </div>
                                                    <input type="url" name="meeting_link" id="modalLink" required class="w-full pl-11 pr-4 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/10 focus:border-emerald-500 outline-none text-sm font-medium text-slate-800 dark:text-slate-200" placeholder="https://zoom.us/j/...">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="px-6 py-6 border-t border-slate-100 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md sticky bottom-0 z-10 flex items-center justify-end gap-4 shadow-[0_-10px_30px_rgba(0,0,0,0.03)]">
                                <button type="button" id="modalCancelBtn" class="px-6 py-3 text-sm font-bold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 transition-colors">Cancel</button>
                                <button type="submit" id="modalSaveBtn" form="editWebinarForm" class="rounded-2xl bg-blue-600 px-8 py-3 text-sm font-bold text-white shadow-xl shadow-blue-600/20 hover:bg-blue-700 hover:-translate-y-0.5 active:translate-y-0 transition-all flex items-center gap-2">
                                    <span id="modalSaveSpinner" class="hidden w-4 h-4 border-2 border-white/80 border-t-transparent rounded-full animate-spin"></span>
                                    <span>Save Changes</span>
                                </button>
                            </div>
                        </div>
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
        
        // Close modal when clicking cancel button
        const cancelBtn = document.getElementById('modalCancelBtn');
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
        
        const focusablesSelector = 'a[href], button:not([disabled]), textarea, input, select';
        function trapFocus(e) {
            if (modal && modal.classList.contains('hidden')) return;
            if (e.key !== 'Tab') return;
            const panel = modal.querySelector('.pointer-events-auto'); // Get the actual panel
            if (!panel) return;
            const nodes = panel.querySelectorAll(focusablesSelector);
            const list = Array.prototype.slice.call(nodes);
            if (!list.length) return;
            const first = list[0];
            const last = list[list.length-1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
        if (modal) modal.addEventListener('keydown', trapFocus);

        const form = document.getElementById('editWebinarForm');
        if (form) {
            form.addEventListener('submit', () => {
                if (saveBtn) saveBtn.disabled = true;
                if (saveSpinner) saveSpinner.classList.remove('hidden');
            });
        }

        function openModal(button) {
            // Populate form fields from data attributes
            document.getElementById('modalWebinarId').value = button.dataset.id;
            document.getElementById('modalTitle').value = button.dataset.title;
            const modalDesc = document.getElementById('modalDescription');
            if (modalDesc) {
                modalDesc.value = button.dataset.description || '';
                // Trigger character counter update
                modalDesc.dispatchEvent(new Event('input'));
            }
            let items = [];
            const rawItems = button.dataset.subheadingItemsJson || '';
            if (rawItems) {
                try {
                    const parsed = JSON.parse(rawItems);
                    if (Array.isArray(parsed)) items = parsed;
                } catch (e) {
                    items = [];
                }
            }
            if (!items.length) {
                const raw = (button.dataset.subheading || '').toString();
                const lines = raw.split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean);
                const size = parseInt(button.dataset.subheadingSize || '20', 10) || 20;
                const color = (button.dataset.subheadingColor || '#ffffff').toString() || '#ffffff';
                const bold = (button.dataset.subheadingBold || '0') === '1';
                items = lines.map(t => ({ text: t, size, color, bold, font: 'system_sans' }));
            }
            if (window.WebinarSubheadingUI && typeof window.WebinarSubheadingUI.setModalItems === 'function') {
                window.WebinarSubheadingUI.setModalItems(items);
            }
            document.getElementById('modalHostname').value = button.dataset.hostname;
            const hostDescField = document.getElementById('modalHostDescription');
            if (hostDescField) hostDescField.value = button.dataset.hostDescription || '';
            document.getElementById('modalSchedule').value = button.dataset.schedule;
            document.getElementById('modalDuration').value = button.dataset.duration || '60-minute';
            document.getElementById('modalLink').value = button.dataset.link;
            document.getElementById('modalStatus').value = button.dataset.status;
            
            // Handle host picture preview
            const hostPic = button.dataset.hostPic;
            const hostPicContainer = document.getElementById('currentHostPic');
            const hostPicImg = document.getElementById('hostPicPreview');
            const hostPicLink = document.getElementById('hostPicLink');
            
            if (hostPic && hostPicContainer && hostPicImg && hostPicLink) {
                hostPicImg.src = hostPic;
                hostPicLink.href = hostPic;
                hostPicContainer.classList.remove('hidden');
            } else if (hostPicContainer) {
                hostPicContainer.classList.add('hidden');
            }
            
            // Handle webinar video preview
            const webinarVid = button.dataset.webinarVid;
            const webinarVidContainer = document.getElementById('currentWebinarVid');
            const webinarVidLink = document.getElementById('webinarVidLink');
            
            if (webinarVid && webinarVidContainer && webinarVidLink) {
                webinarVidLink.href = webinarVid;
                webinarVidContainer.classList.remove('hidden');
            } else if (webinarVidContainer) {
                webinarVidContainer.classList.add('hidden');
            }
            
            // Show modal using custom event for Alpine.js
            window.dispatchEvent(new CustomEvent('open-edit-modal'));
            setTimeout(() => {
                const firstField = document.getElementById('modalTitle');
                if (firstField) firstField.focus();
            }, 100);
        }
        
        function closeModal() {
            // Hide modal using custom event for Alpine.js
            window.dispatchEvent(new CustomEvent('close-edit-modal'));
            if (saveBtn) saveBtn.disabled = false;
            if (saveSpinner) saveSpinner.classList.add('hidden');
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
        const WebinarSubheadingUI = (() => {
          const fontOptions = [
            { value: 'system_sans', label: 'System Sans' },
            { value: 'system_serif', label: 'System Serif' },
            { value: 'system_mono', label: 'System Mono' },
            { value: 'arial', label: 'Arial' },
            { value: 'georgia', label: 'Georgia' },
            { value: 'times', label: 'Times New Roman' },
            { value: 'courier', label: 'Courier New' },
            { value: 'verdana', label: 'Verdana' },
            { value: 'trebuchet', label: 'Trebuchet MS' }
          ];

          function safeColor(v) {
            const s = String(v || '').trim();
            if (!/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/.test(s)) return '#ffffff';
            return s[0] === '#' ? s : ('#' + s);
          }

          function safeSize(v) {
            const n = parseInt(v, 10);
            if (!isFinite(n)) return 20;
            return Math.max(10, Math.min(80, n));
          }

          function safeSpacing(v) {
            const n = parseInt(v, 10);
            if (!isFinite(n)) return 8;
            return Math.max(0, Math.min(64, n));
          }

          function safeFont(v) {
            const s = String(v || 'system_sans');
            return fontOptions.some(o => o.value === s) ? s : 'system_sans';
          }
          
          function fontFamilyForKey(key) {
            const k = safeFont(key);
            if (k === 'system_serif') return "ui-serif, Georgia, Cambria, 'Times New Roman', Times, serif";
            if (k === 'system_mono') return "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace";
            if (k === 'arial') return "Arial, Helvetica, sans-serif";
            if (k === 'georgia') return "Georgia, Cambria, 'Times New Roman', Times, serif";
            if (k === 'times') return "'Times New Roman', Times, serif";
            if (k === 'courier') return "'Courier New', Courier, monospace";
            if (k === 'verdana') return "Verdana, Geneva, sans-serif";
            if (k === 'trebuchet') return "'Trebuchet MS', 'Segoe UI', sans-serif";
            return "ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif";
          }

          function escapeAttr(v) {
            return String(v ?? '')
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#39;');
          }

          function itemTemplate(item, theme) {
            const font = safeFont(item.font);
            const size = safeSize(item.size);
            const spacing = safeSpacing(item.spacing);
            const color = safeColor(item.color);
            const bold = !!item.bold;
            
            return `
              <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 subheading-item transition-all hover:border-slate-300 dark:hover:border-slate-600 shadow-sm">
                <div class="flex items-center gap-3 mb-4">
                  <div class="flex-1">
                    <input type="text" class="subheading-item-text w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl outline-none text-sm text-slate-800 dark:text-slate-200 placeholder-slate-400 font-bold transition-all focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500" 
                           placeholder="Enter subheading text..." value="${escapeAttr(item.text || '')}">
                  </div>
                  <button type="button" class="subheading-item-remove w-11 h-11 shrink-0 inline-flex items-center justify-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-400 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 dark:hover:bg-rose-900/20 disabled:opacity-30 disabled:cursor-not-allowed transition-all" aria-label="Remove subheading" title="Remove">
                    <i class="fas fa-trash-alt text-sm"></i>
                  </button>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                  <!-- Font Style Toggle -->
                  <div class="flex items-center p-1 bg-slate-100 dark:bg-slate-900 rounded-xl">
                    <button type="button" data-font="system_sans" class="font-toggle-btn px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider transition-all ${font === 'system_sans' ? 'bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'}">Sans</button>
                    <button type="button" data-font="system_serif" class="font-toggle-btn px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider transition-all ${font === 'system_serif' ? 'bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'}">Serif</button>
                    <button type="button" data-font="system_mono" class="font-toggle-btn px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider transition-all ${font === 'system_mono' ? 'bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'}">Mono</button>
                    <input type="hidden" class="subheading-item-font" value="${font}">
                  </div>

                  <!-- Size Controls -->
                  <div class="flex items-center gap-2 px-3 py-1.5 bg-slate-100 dark:bg-slate-900 rounded-xl">
                    <button type="button" class="size-decrement text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><i class="fas fa-minus-circle"></i></button>
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-200 min-w-[24px] text-center">${size}px</span>
                    <button type="button" class="size-increment text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><i class="fas fa-plus-circle"></i></button>
                    <input type="hidden" class="subheading-item-size" value="${size}">
                  </div>

                  <!-- Spacing Controls -->
                  <div class="flex items-center gap-2 px-3 py-1.5 bg-slate-100 dark:bg-slate-900 rounded-xl">
                    <button type="button" class="spacing-decrement text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><i class="fas fa-minus-circle"></i></button>
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-200 min-w-[36px] text-center">${spacing}px</span>
                    <button type="button" class="spacing-increment text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><i class="fas fa-plus-circle"></i></button>
                    <input type="hidden" class="subheading-item-spacing" value="${spacing}">
                  </div>

                  <!-- Bold Toggle -->
                  <button type="button" class="bold-toggle-btn w-10 h-10 inline-flex items-center justify-center rounded-xl border-2 transition-all ${bold ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white dark:bg-slate-800 border-slate-100 dark:border-slate-700 text-slate-400 hover:border-slate-200 dark:hover:border-slate-600'}">
                    <i class="fas fa-bold"></i>
                    <input type="checkbox" class="subheading-item-bold hidden" ${bold ? 'checked' : ''}>
                  </button>

                  <!-- Color Picker -->
                  <div class="relative flex items-center gap-2 pl-4 border-l border-slate-200 dark:border-slate-700">
                    <div class="w-6 h-6 rounded-full border border-slate-200 dark:border-slate-700 overflow-hidden shadow-inner">
                      <input type="color" class="subheading-item-color absolute inset-0 w-full h-full cursor-pointer opacity-0" value="${color}">
                      <div class="color-preview w-full h-full" style="background-color: ${color}"></div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest color-hex">${color}</span>
                  </div>
                </div>
              </div>
            `;
          }

          function readItems(container) {
            const items = [];
            container.querySelectorAll('.subheading-item').forEach(el => {
              const text = el.querySelector('.subheading-item-text')?.value?.trim() || '';
              if (!text) return;
              const font = safeFont(el.querySelector('.subheading-item-font')?.value);
              const size = safeSize(el.querySelector('.subheading-item-size')?.value);
              const spacing = safeSpacing(el.querySelector('.subheading-item-spacing')?.value);
              const color = safeColor(el.querySelector('.subheading-item-color')?.value);
              const bold = !!el.querySelector('.subheading-item-bold')?.checked;
              items.push({ text, font, size, spacing, color, bold });
            });
            return items.slice(0, 12);
          }

          function sync(container, hiddenText, hiddenJson) {
            if (!container) return;
            const items = readItems(container);
            container.querySelectorAll('.subheading-item').forEach(el => {
              const textEl = el.querySelector('.subheading-item-text');
              const font = safeFont(el.querySelector('.subheading-item-font')?.value);
              const size = safeSize(el.querySelector('.subheading-item-size')?.value);
              const spacing = safeSpacing(el.querySelector('.subheading-item-spacing')?.value);
              const color = safeColor(el.querySelector('.subheading-item-color')?.value);
              const bold = !!el.querySelector('.subheading-item-bold')?.checked;
              if (textEl) {
                textEl.style.fontFamily = fontFamilyForKey(font);
                textEl.style.fontSize = size + 'px';
                textEl.style.color = color;
                textEl.style.fontWeight = bold ? 800 : 600;
                textEl.style.marginBottom = spacing + 'px';
              }
            });
            if (hiddenText) hiddenText.value = items.map(i => i.text).join('\n');
            if (hiddenJson) hiddenJson.value = items.length ? JSON.stringify(items) : '';
            refreshRemoveButtons(container);
          }

          function refreshRemoveButtons(container) {
            const items = Array.from(container.querySelectorAll('.subheading-item'));
            const canRemove = items.length > 1;
            items.forEach(el => {
              const btn = el.querySelector('.subheading-item-remove');
              if (btn) btn.disabled = !canRemove;
            });
          }

          function addItem(container, theme, item) {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = itemTemplate(item || { text: '', font: 'system_sans', size: 20, spacing: 8, color: '#ffffff', bold: true }, theme);
            const node = wrapper.firstElementChild;
            container.appendChild(node);
            refreshRemoveButtons(container);
            node.querySelector('.subheading-item-text')?.focus();
          }

          function clearAndSet(container, theme, items) {
            container.innerHTML = '';
            const list = Array.isArray(items) && items.length ? items : [{ text: '', font: 'system_sans', size: 20, spacing: 8, color: '#ffffff', bold: true }];
            list.forEach(it => addItem(container, theme, it));
          }

          function getSectionFromTarget(target) {
            const form = target.closest('form');
            if (!form) return null;
            if (form.id === 'addWebinarForm') {
              return {
                theme: 'add',
                container: document.getElementById('addSubheadingItems'),
                hiddenText: document.getElementById('addSubheadingHidden'),
                hiddenJson: document.getElementById('addSubheadingItemsJson')
              };
            }
            if (form.id === 'editWebinarForm') {
              return {
                theme: 'modal',
                container: document.getElementById('modalSubheadingItems'),
                hiddenText: document.getElementById('modalSubheadingHidden'),
                hiddenJson: document.getElementById('modalSubheadingItemsJson')
              };
            }
            return null;
          }

          function syncForm(form) {
            if (!form) return;
            if (form.id === 'addWebinarForm') {
              sync(document.getElementById('addSubheadingItems'), document.getElementById('addSubheadingHidden'), document.getElementById('addSubheadingItemsJson'));
            } else if (form.id === 'editWebinarForm') {
              sync(document.getElementById('modalSubheadingItems'), document.getElementById('modalSubheadingHidden'), document.getElementById('modalSubheadingItemsJson'));
            }
          }

          function setModalItems(items) {
            const container = document.getElementById('modalSubheadingItems');
            const hiddenText = document.getElementById('modalSubheadingHidden');
            const hiddenJson = document.getElementById('modalSubheadingItemsJson');
            if (!container) return;
            clearAndSet(container, 'modal', items);
            sync(container, hiddenText, hiddenJson);
          }

          function init() {
            const addContainer = document.getElementById('addSubheadingItems');
            if (addContainer) {
              sync(addContainer, document.getElementById('addSubheadingHidden'), document.getElementById('addSubheadingItemsJson'));
            }

            // File Upload Previews for Add Webinar
            const addHostPic = document.getElementById('addHostPic');
            const addHostPicPreview = document.getElementById('addHostPicPreview');
            const addHostPicImg = document.getElementById('addHostPicImg');
            const addHostPicName = document.getElementById('addHostPicName');
            const addHostPicRemove = document.getElementById('addHostPicRemove');

            if (addHostPic) {
                addHostPic.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            addHostPicImg.src = e.target.result;
                            addHostPicName.textContent = file.name;
                            addHostPicPreview.classList.remove('hidden');
                        }
                        reader.readAsDataURL(file);
                    }
                });
            }

            if (addHostPicRemove) {
                addHostPicRemove.addEventListener('click', function() {
                    addHostPic.value = '';
                    addHostPicPreview.classList.add('hidden');
                });
            }

            const editHostPic = document.querySelector('#editWebinarForm input[type="file"][name="host_pic"]');
            const editHostPicContainer = document.getElementById('currentHostPic');
            const editHostPicImg = document.getElementById('hostPicPreview');
            const editHostPicLink = document.getElementById('hostPicLink');

            if (editHostPic) {
                editHostPic.addEventListener('change', function() {
                    const file = this.files && this.files[0] ? this.files[0] : null;
                    if (!file || !editHostPicContainer || !editHostPicImg) return;
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const src = (e && e.target) ? e.target.result : '';
                        if (typeof src === 'string' && src !== '') {
                            editHostPicImg.src = src;
                            if (editHostPicLink) editHostPicLink.href = src;
                            editHostPicContainer.classList.remove('hidden');
                        }
                    };
                    reader.readAsDataURL(file);
                });
            }

            const addWebinarVid = document.getElementById('addWebinarVid');
            const addWebinarVidPreview = document.getElementById('addWebinarVidPreview');
            const addWebinarVidName = document.getElementById('addWebinarVidName');
            const addWebinarVidRemove = document.getElementById('addWebinarVidRemove');

            if (addWebinarVid) {
                addWebinarVid.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        addWebinarVidName.textContent = file.name;
                        addWebinarVidPreview.classList.remove('hidden');
                    }
                });
            }

            if (addWebinarVidRemove) {
                addWebinarVidRemove.addEventListener('click', function() {
                    addWebinarVid.value = '';
                    addWebinarVidPreview.classList.add('hidden');
                });
            }

            document.addEventListener('click', (e) => {
              // Add subheading
              const addBtn = e.target.closest('[data-sub-item-add]');
              if (addBtn) {
                const section = getSectionFromTarget(addBtn);
                if (section && section.container) {
                  addItem(section.container, section.theme);
                  sync(section.container, section.hiddenText, section.hiddenJson);
                }
                return;
              }

              // Remove subheading
              const rmBtn = e.target.closest('.subheading-item-remove');
              if (rmBtn) {
                const section = getSectionFromTarget(rmBtn);
                if (section && section.container) {
                  const items = Array.from(section.container.querySelectorAll('.subheading-item'));
                  if (items.length > 1) {
                    rmBtn.closest('.subheading-item')?.remove();
                    sync(section.container, section.hiddenText, section.hiddenJson);
                  }
                }
                return;
              }

              // Font toggle
              const fontBtn = e.target.closest('.font-toggle-btn');
              if (fontBtn) {
                  const val = fontBtn.dataset.font;
                  const container = fontBtn.closest('.flex');
                  const hidden = container.querySelector('.subheading-item-font');
                  if (hidden) {
                      hidden.value = val;
                      container.querySelectorAll('.font-toggle-btn').forEach(b => {
                          const isActive = b.dataset.font === val;
                          b.classList.toggle('bg-white', isActive);
                          b.classList.toggle('dark:bg-slate-800', isActive);
                          b.classList.toggle('text-blue-600', isActive);
                          b.classList.toggle('dark:text-blue-400', isActive);
                          b.classList.toggle('shadow-sm', isActive);
                          b.classList.toggle('text-slate-500', !isActive);
                          b.classList.toggle('dark:text-slate-300', !isActive);
                      });
                      syncForm(fontBtn.closest('form'));
                  }
                  return;
              }

              // Size controls
              const sizeInc = e.target.closest('.size-increment');
              const sizeDec = e.target.closest('.size-decrement');
              if (sizeInc || sizeDec) {
                  const item = (sizeInc || sizeDec).closest('.flex');
                  const hidden = item.querySelector('.subheading-item-size');
                  const display = item.querySelector('span');
                  let val = parseInt(hidden.value, 10);
                  if (sizeInc) val = Math.min(80, val + 2);
                  else val = Math.max(10, val - 2);
                  hidden.value = val;
                  display.textContent = val + 'px';
                  syncForm((sizeInc || sizeDec).closest('form'));
                  return;
              }

              // Spacing controls
              const spacingInc = e.target.closest('.spacing-increment');
              const spacingDec = e.target.closest('.spacing-decrement');
              if (spacingInc || spacingDec) {
                  const item = (spacingInc || spacingDec).closest('.flex');
                  const hidden = item.querySelector('.subheading-item-spacing');
                  const display = item.querySelector('span');
                  let val = parseInt(hidden.value, 10);
                  if (spacingInc) val = Math.min(64, val + 2);
                  else val = Math.max(0, val - 2);
                  hidden.value = val;
                  display.textContent = val + 'px';
                  syncForm((spacingInc || spacingDec).closest('form'));
                  return;
              }

              // Bold toggle
              const boldBtn = e.target.closest('.bold-toggle-btn');
              if (boldBtn) {
                  const checkbox = boldBtn.querySelector('input');
                  checkbox.checked = !checkbox.checked;
                  boldBtn.classList.toggle('bg-blue-600', checkbox.checked);
                  boldBtn.classList.toggle('border-blue-600', checkbox.checked);
                  boldBtn.classList.toggle('text-white', checkbox.checked);
                  boldBtn.classList.toggle('bg-white', !checkbox.checked);
                  boldBtn.classList.toggle('dark:bg-slate-800', !checkbox.checked);
                  boldBtn.classList.toggle('border-slate-100', !checkbox.checked);
                  boldBtn.classList.toggle('dark:border-slate-700', !checkbox.checked);
                  boldBtn.classList.toggle('text-slate-400', !checkbox.checked);
                  boldBtn.classList.toggle('dark:hover:border-slate-600', !checkbox.checked);
                  syncForm(boldBtn.closest('form'));
                  return;
              }
            });

            document.addEventListener('input', (e) => {
              const colorPicker = e.target.closest('.subheading-item-color');
              if (colorPicker) {
                  const container = colorPicker.closest('.relative');
                  const preview = container.querySelector('.color-preview');
                  const hex = container.querySelector('.color-hex');
                  preview.style.backgroundColor = colorPicker.value;
                  hex.textContent = colorPicker.value.toUpperCase();
                  // No return, fall through to sync
              }

              const section = getSectionFromTarget(e.target);
              if (section && section.container) {
                sync(section.container, section.hiddenText, section.hiddenJson);
              }
            });
          }

          return { init, syncForm, setModalItems };
        })();

        window.WebinarSubheadingUI = WebinarSubheadingUI;
        WebinarSubheadingUI.init();

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
          if (window.WebinarSubheadingUI && typeof window.WebinarSubheadingUI.syncForm === 'function') {
            window.WebinarSubheadingUI.syncForm(form);
          }
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
            if (window.WebinarSubheadingUI && typeof window.WebinarSubheadingUI.syncForm === 'function') {
              window.WebinarSubheadingUI.syncForm(form);
            }
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
