<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'resident'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'You are not authorized to view notifications.']);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$notificationType = $role === 'admin' ? 'report_submissions' : 'announcements';
$sourceTable = $role === 'admin' ? 'flood_reports' : 'emergency_announcements';

$tableResult = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($sourceTable) . "'");
if (!$tableResult) {
    error_log('Notification source table check failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load notifications.']);
    exit;
}

$latestId = 0;
if ($tableResult->num_rows > 0) {
    $latestResult = $conn->query("SELECT COALESCE(MAX(id), 0) AS latest_id FROM `$sourceTable`");
    if (!$latestResult) {
        error_log('Notification latest ID query failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $latestId = (int) $latestResult->fetch_assoc()['latest_id'];
}

if (!$conn->query("
    CREATE TABLE IF NOT EXISTS user_notification_reads (
        user_id INT NOT NULL,
        notification_type VARCHAR(32) NOT NULL,
        last_seen_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, notification_type)
    ) ENGINE=InnoDB
")) {
    error_log('Notification read-state table creation failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load notifications.']);
    exit;
}

$readStmt = $conn->prepare("
    SELECT last_seen_id
    FROM user_notification_reads
    WHERE user_id = ? AND notification_type = ?
");
if (!$readStmt) {
    error_log('Notification read-state query prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load notifications.']);
    exit;
}
$readStmt->bind_param('is', $userId, $notificationType);
$readStmt->execute();
$readResult = $readStmt->get_result();
$readState = $readResult->fetch_assoc();
$readStmt->close();

if (!$readState) {
    $lastSeenId = 0;
    $initializeStmt = $conn->prepare("
        INSERT INTO user_notification_reads (user_id, notification_type, last_seen_id)
        VALUES (?, ?, ?)
    ");
    if (!$initializeStmt) {
        error_log('Notification read-state initialization prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $initializeStmt->bind_param('isi', $userId, $notificationType, $lastSeenId);
    if (!$initializeStmt->execute()) {
        error_log('Notification read-state initialization failed: ' . $initializeStmt->error);
        $initializeStmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $initializeStmt->close();
} else {
    $lastSeenId = (int) $readState['last_seen_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $csrfToken = is_array($payload) ? (string) ($payload['csrf_token'] ?? '') : '';
    $seenId = filter_var(
        is_array($payload) ? ($payload['latest_id'] ?? null) : null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );

    if (
        empty($_SESSION['profile_csrf']) ||
        !hash_equals($_SESSION['profile_csrf'], $csrfToken) ||
        $seenId === false
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid notification request.']);
        exit;
    }

    $seenId = min((int) $seenId, $latestId);
    $updateStmt = $conn->prepare("
        INSERT INTO user_notification_reads (user_id, notification_type, last_seen_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id))
    ");
    if (!$updateStmt) {
        error_log('Notification read-state update prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to update notifications.']);
        exit;
    }
    $updateStmt->bind_param('isi', $userId, $notificationType, $seenId);
    if (!$updateStmt->execute()) {
        error_log('Notification read-state update failed: ' . $updateStmt->error);
        $updateStmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to update notifications.']);
        exit;
    }
    $updateStmt->close();

    echo json_encode(['success' => true]);
    exit;
}

$items = [];
$unreadCount = 0;

if ($tableResult->num_rows > 0 && $role === 'admin') {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM flood_reports WHERE id > ?');
    $listStmt = $conn->prepare("
        SELECT fr.id, fr.location, fr.created_at, u.full_name
        FROM flood_reports fr
        LEFT JOIN users u ON u.id = fr.user_id
        ORDER BY fr.id DESC
        LIMIT 8
    ");
    if (!$countStmt || !$listStmt) {
        error_log('Admin notification query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $countStmt->bind_param('i', $lastSeenId);
    $countStmt->execute();
    $unreadCount = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    if (!$listStmt->execute()) {
        error_log('Admin notification query failed: ' . $listStmt->error);
        $listStmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rowId = (int) $row['id'];
        $items[] = [
            'id' => $rowId,
            'title' => 'New flood report',
            'message' => ($row['full_name'] ?: 'A resident') . ' submitted a report at ' . $row['location'],
            'created_at' => $row['created_at'],
            'url' => 'admin_reports.php',
            'unread' => $rowId > $lastSeenId,
        ];
    }
    $listStmt->close();
} elseif ($tableResult->num_rows > 0) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM emergency_announcements WHERE id > ?');
    $listStmt = $conn->prepare("
        SELECT id, title, message, created_at
        FROM emergency_announcements
        ORDER BY id DESC
        LIMIT 8
    ");
    if (!$countStmt || !$listStmt) {
        error_log('Resident notification query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $countStmt->bind_param('i', $lastSeenId);
    $countStmt->execute();
    $unreadCount = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    if (!$listStmt->execute()) {
        error_log('Resident notification query failed: ' . $listStmt->error);
        $listStmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load notifications.']);
        exit;
    }
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rowId = (int) $row['id'];
        $items[] = [
            'id' => $rowId,
            'title' => $row['title'],
            'message' => $row['message'],
            'created_at' => $row['created_at'],
            'url' => 'emergency_alert.php',
            'unread' => $rowId > $lastSeenId,
        ];
    }
    $listStmt->close();
}

echo json_encode([
    'items' => $items,
    'unread' => $unreadCount,
    'latest_id' => $latestId,
]);
