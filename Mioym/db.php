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

} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>