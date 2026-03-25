<?php
// registration.php - Webinar Registration Page with CRM (text file)

$leadsFile = 'leads.txt';
$error = '';
$success = false;

// Function to save lead to text file (CRM)
function saveLeadToCRM($name, $email, $phone) {
    global $leadsFile;
    
    // Create lead data string
    $timestamp = date('Y-m-d H:i:s');
    $leadData = "Name: $name | Email: $email | Phone: $phone | Registered: $timestamp" . PHP_EOL;
    
    // Append to leads.txt file
    file_put_contents($leadsFile, $leadData, FILE_APPEND | LOCK_EX);
    
    return true;
}

// Function to get all leads (for display)
function getLeadsFromCRM() {
    global $leadsFile;
    
    if (!file_exists($leadsFile)) {
        return [];
    }
    
    $content = file_get_contents($leadsFile);
    $lines = explode(PHP_EOL, trim($content));
    
    $leads = [];
    foreach ($lines as $line) {
        if (!empty($line)) {
            $leads[] = $line;
        }
    }
    
    return array_reverse($leads); // Show newest first
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($name) || empty($email)) {
        $error = "Please fill in both Name and Email.";
    } else {
        // Save to CRM (leads.txt)
        saveLeadToCRM($name, $email, $phone);
        
        // Redirect to index.php with success message
        header("Location: thankyou.php?name=" . urlencode($name));
        exit;
    }
}

// Get leads for display
$leads = getLeadsFromCRM();
$lastLead = !empty($leads) ? $leads[0] : null;
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
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100 text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center p-5">

    <main class="w-full max-w-md">
        <!-- back link to index.php -->
        <div class="mb-4 text-sm">
            <a href="index.php" class="text-slate-500 hover:text-slate-800 inline-flex items-center gap-1 transition">
                <i class="fas fa-arrow-left text-xs"></i> back to webinar info
            </a>
        </div>

        <!-- registration card -->
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 p-8 md:p-9">
            <div class="text-center mb-6">
                <div class="bg-[#1e4a7a] w-16 h-16 rounded-2xl flex items-center justify-center text-white text-3xl mx-auto mb-4 shadow-md">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h2 class="text-3xl font-bold text-slate-800">Webinar Registration</h2>
                <p class="text-slate-500 mt-1 text-sm flex items-center justify-center gap-2">
                    <i class="far fa-clock text-[#1e4a7a]"></i> Thursday 24 April · 6pm BST / 1pm EST
                </p>
            </div>

            <!-- ===== WORKING FORM ===== -->
            <!-- Fields: Name, Email, Phone Number -->
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="space-y-5">
                <!-- Name field (required) -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">
                        <i class="far fa-user mr-1 text-slate-400"></i> Full Name <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="name" required
                           placeholder="Juan Dela Cruz"
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                           class="w-full px-5 py-3.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] outline-none transition bg-slate-50/50">
                </div>

                <!-- Email field (required) -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">
                        <i class="far fa-envelope mr-1 text-slate-400"></i> Email Address <span class="text-rose-500">*</span>
                    </label>
                    <input type="email" name="email" required
                           placeholder="juan@example.com"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           class="w-full px-5 py-3.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] outline-none transition bg-slate-50/50">
                </div>

                <!-- Phone Number field (optional) -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">
                        <i class="fas fa-phone-alt mr-1 text-slate-400"></i> Phone Number <span class="text-slate-400 text-xs font-normal">(optional)</span>
                    </label>
                    <input type="tel" name="phone"
                           placeholder="+63 912 345 6789"
                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                           class="w-full px-5 py-3.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#1e4a7a]/20 focus:border-[#1e4a7a] outline-none transition bg-slate-50/50">
                </div>

                <!-- Error message if validation fails -->
                <?php if (!empty($error)): ?>
                <div class="bg-red-50 text-red-600 p-3 rounded-lg text-sm flex items-center gap-2">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
                <?php endif; ?>

                <!-- Submit button -->
                <button type="submit"
                        class="w-full bg-[#1e4a7a] hover:bg-[#123a5e] text-white font-semibold text-lg py-4 rounded-full shadow-xl shadow-[#1e4a7a]/20 border border-[#1d4a7a] transition flex items-center justify-center gap-3 mt-5">
                    <i class="fas fa-ticket-alt"></i> Register for Webinar
                </button>

                <!-- Data privacy notice -->
                <p class="text-xs text-center text-slate-400 pt-2">
                    <i class="fas fa-lock mr-1 text-[0.6rem]"></i> Your data is saved to our CRM (leads.txt) and never shared.
                </p>
            </form>

            <!-- ===== CRM PREVIEW (shows last saved lead) ===== -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200"></div></div>
                <div class="relative flex justify-center text-xs"><span class="bg-white px-3 text-slate-400">✓ CRM Preview</span></div>
            </div>
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                <h4 class="text-xs font-semibold text-slate-500 flex items-center gap-1 mb-2">
                    <i class="fas fa-database"></i> Last Registration Saved:
                </h4>
                <div class="text-sm text-slate-700 space-y-1 min-h-[40px]">
                    <?php if ($lastLead): ?>
                        <div class="border-l-2 border-[#1e4a7a] pl-3 py-2 bg-white/50 rounded">
                            <?php echo htmlspecialchars($lastLead); ?>
                        </div>
                    <?php else: ?>
                        <span class="italic text-slate-400">No registrations yet.</span>
                    <?php endif; ?>
                </div>
                <p class="text-[0.6rem] text-slate-400 mt-2">*Saved in leads.txt file - <?php echo file_exists($leadsFile) ? 'File exists' : 'File will be created on first registration'; ?></p>
            </div>
        </div>

        <!-- footer -->
        <p class="text-xs text-slate-400 text-center mt-6">
            © 2025 Mioym Equities · Webinar Registration Demo
        </p>
    </main>
</body>
</html>