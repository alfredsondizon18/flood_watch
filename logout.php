<?php
require_once 'config.php';

if (
    ($_SESSION['role'] ?? '') === 'admin' &&
    !empty($_SESSION['user_id'])
) {
    require_once __DIR__ . '/includes/admin_activity.php';
    adminActivityLog(
        $conn,
        (int) $_SESSION['user_id'],
        (string) ($_SESSION['full_name'] ?? 'Administrator'),
        'Logged out',
        'Administrator signed out'
    );
}

if (
    ($_SESSION['role'] ?? '') === 'resident' &&
    !empty($_SESSION['user_id'])
) {
    require_once __DIR__ . '/includes/resident_activity.php';
    residentActivityLog(
        $conn,
        (int) $_SESSION['user_id'],
        (string) ($_SESSION['full_name'] ?? 'Resident'),
        'Logged out'
    );
}

$_SESSION = [];

session_destroy();

header("Location: index.php");
exit;