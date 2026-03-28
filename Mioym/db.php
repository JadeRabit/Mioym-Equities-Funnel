<?php
$host = 'localhost';
$dbname = 'webinar_db';
$username = 'root'; // Default XAMPP username
$password = '';     // Default XAMPP password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Auto-create is_published column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE webinar_tbl ADD COLUMN is_published TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) {
        // Column likely already exists, ignore error
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(160) NOT NULL,
                message TEXT NOT NULL,
                link_url VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch(PDOException $e) {
    }

} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function admin_notify(PDO $pdo, $type, $title, $message, $link_url = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_notifications (type, title, message, link_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([(string)$type, (string)$title, (string)$message, $link_url !== null ? (string)$link_url : null]);
    } catch (Throwable $e) {
    }
}
?>
