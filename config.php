
<?php
session_start();

date_default_timezone_set('Asia/Manila');

$host = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "flood_watch_db";



// Connect to MySQL
$conn = new mysqli($host, $dbUser, $dbPass);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Automatically create database
$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName`");

$conn->select_db($dbName);
$conn->set_charset("utf8mb4");

// Create users table if it does not exist
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'resident') NOT NULL DEFAULT 'resident',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Table creation failed: " . $conn->error);
}

// Check existing table columns
$columns = $conn->query("SHOW COLUMNS FROM users");

$existingColumns = [];

while ($column = $columns->fetch_assoc()) {
    $existingColumns[] = $column['Field'];
}

// Add missing full_name column
if (!in_array('full_name', $existingColumns)) {
    $conn->query("
        ALTER TABLE users
        ADD COLUMN full_name VARCHAR(150) NOT NULL DEFAULT ''
    ");
}

// Add missing username column
if (!in_array('username', $existingColumns)) {
    $conn->query("
        ALTER TABLE users
        ADD COLUMN username VARCHAR(50) NOT NULL DEFAULT ''
    ");
}

// Add missing password_hash column
if (!in_array('password_hash', $existingColumns)) {
    $conn->query("
        ALTER TABLE users
        ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT ''
    ");
}

// Add missing role column
if (!in_array('role', $existingColumns)) {
    $conn->query("
        ALTER TABLE users
        ADD COLUMN role ENUM('admin', 'resident')
        NOT NULL DEFAULT 'resident'
    ");
}

// Add missing created_at column
if (!in_array('created_at', $existingColumns)) {
    $conn->query("
        ALTER TABLE users
        ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ");
}

// Check if administrator already exists
$result = $conn->query("
    SELECT id FROM users
    WHERE role = 'admin'
    LIMIT 1
");

if (!$result) {
    die("Administrator check failed: " . $conn->error);
}

// Create default administrator
if ($result->num_rows === 0) {

    $name = "System Administrator";
    $username = "admin";
    $password = password_hash("Admin@12345", PASSWORD_DEFAULT);
    $role = "admin";

    $stmt = $conn->prepare("
        INSERT INTO users
        (full_name, username, password_hash, role)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssss",
        $name,
        $username,
        $password,
        $role
    );

    if (!$stmt->execute()) {
        die("Admin creation failed: " . $stmt->error);
    }

    $stmt->close();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
