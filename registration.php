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
<body class="bg-gradient-to-b from-slate-50 to-white text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center p-4 md:p-6">

    <main class="w-full max-w-5xl">
        <div class="bg-white rounded-[2rem] shadow-2xl border border-slate-100 overflow-hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2">
                <div class="p-8 md:p-10">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <a href="index.php" class="text-sm text-slate-500 hover:text-slate-800 inline-flex items-center gap-1 transition">
                            <i class="fas fa-arrow-left text-xs"></i> back to webinar info
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
                                <div class="font-semibold text-slate-800">+44 20 1234 5678</div>
                                <div class="text-slate-500">Office hotline</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-[#0f2b44] text-white flex items-center justify-center">
                                <i class="fas fa-map-marker-alt text-sm"></i>
                            </div>
                            <div class="text-sm">
                                <div class="font-semibold text-slate-800">London, UK</div>
                                <div class="text-slate-500">Mayfair office</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 sm:col-span-2">
                            <div class="w-10 h-10 rounded-full bg-[#0f2b44] text-white flex items-center justify-center">
                                <i class="fas fa-envelope text-sm"></i>
                            </div>
                            <div class="text-sm">
                                <div class="font-semibold text-slate-800">hello@mioym.com</div>
                                <div class="text-slate-500">Support email</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <i class="far fa-clock text-amber-500"></i> Thursday 24 April · 6pm BST / 1pm EST
                        </div>
                        <div class="text-xs text-slate-500 mt-2">60‑minute live training • replay available</div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-[#0f2b44] to-[#1e4a7a] p-7 md:p-9">
                    <div class="max-w-sm mx-auto">
                        <div class="text-white font-extrabold text-xl md:text-2xl tracking-tight">Webinar Registration</div>
                        <div class="text-white/80 text-sm mt-2">Complete the form to secure your spot.</div>

                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="mt-6 space-y-4.5">
                            <div>
                                <label class="block text-sm font-semibold text-white mb-2">Your name <span class="text-white/70">*</span></label>
                                <input type="text" name="name" required
                                       placeholder="Name"
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
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

                            <p class="text-xs text-white/80 flex items-center gap-2 justify-center">
                                <i class="fas fa-lock text-[0.65rem]"></i> Saved to CRM (leads.txt). Never shared.
                            </p>
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
                            <p class="text-[0.65rem] text-white/70 mt-2">*Saved in leads.txt file - <?php echo file_exists($leadsFile) ? 'File exists' : 'File will be created on first registration'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-xs text-slate-400 text-center mt-6">© 2025 Mioym Equities · Webinar Registration Demo</p>
    </main>
</body>
</html>
