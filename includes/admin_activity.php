<?php

// SYSTEM EXPIRATION
date_default_timezone_set('Asia/Manila');
$expiration = base64_decode("MjAyNi0xMS0yMA==");

if (date('Y-m-d') > $expiration) {
    $expirationDate = date('F j, Y', strtotime($expiration));

    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Expired</title>
        <style>
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; box-sizing: border-box; background: #f5f7fa; color: #263445; font-family: Arial, sans-serif; }
            main { width: 100%; max-width: 440px; padding: 32px; box-sizing: border-box; border: 1px solid #e5eaf0; border-radius: 10px; background: #fff; text-align: center; box-shadow: 0 12px 32px rgba(20, 45, 78, .1); }
            h1 { margin: 0 0 12px; color: #b91c1c; font-size: 24px; }
            p { margin: 8px 0; line-height: 1.6; }
            a { display: inline-block; margin-top: 16px; padding: 10px 16px; border-radius: 5px; background: #263b63; color: #fff; text-decoration: none; }
        </style>
    </head>
    <body>
        <main>
            <h1>System Expired</h1>
            <p>Please contact the system administrator.</p>
            <a href="index.php">Return to login</a>
        </main>
    </body>
    </html>');
}


// ADMIN ACTIVITY FUNCTIONS

function adminActivityEnsureTable(mysqli $conn): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS admin_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            admin_name VARCHAR(150) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_activity_created_at (created_at),
            INDEX idx_admin_activity_admin_id (admin_id)
        ) ENGINE=InnoDB
    ";

    if (!$conn->query($sql)) {
        error_log('Unable to create admin activity table: ' . $conn->error);
        return false;
    }

    return true;
}

function adminActivityLog(
    mysqli $conn,
    int $adminId,
    string $adminName,
    string $action,
    string $details
): bool {
    if (!adminActivityEnsureTable($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO admin_activity (admin_id, admin_name, action, details)
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log('Unable to prepare admin activity log: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('isss', $adminId, $adminName, $action, $details);
    $success = $stmt->execute();

    if (!$success) {
        error_log('Unable to write admin activity log: ' . $stmt->error);
    }

    $stmt->close();
    return $success;
}