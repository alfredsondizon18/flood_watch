
<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    http_response_code(403);
    echo json_encode(['count' => 0]);
    exit;
}

$reportColumns = $conn->query('SHOW COLUMNS FROM flood_reports');
if (!$reportColumns) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to verify flood report fields.']);
    exit;
}

$existingReportColumns = [];
while ($column = $reportColumns->fetch_assoc()) {
    $existingReportColumns[] = $column['Field'];
}

if (!in_array('water_level_cm', $existingReportColumns, true)) {
    if (!$conn->query("
        ALTER TABLE flood_reports
        ADD COLUMN water_level_cm DECIMAL(7,2) NOT NULL DEFAULT 0 AFTER image
    ")) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to add water depth to flood reports.']);
        exit;
    }
}

if (!in_array('water_level_status', $existingReportColumns, true)) {
    if (!$conn->query("
        ALTER TABLE flood_reports
        ADD COLUMN water_level_status ENUM(
            'Normal',
            'Warning',
            'Critical'
        ) NOT NULL DEFAULT 'Normal' AFTER water_level_cm
    ")) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to add water level status to flood reports.']);
        exit;
    }
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM flood_reports
    WHERE water_level_status = 'Critical'
    AND responded_at IS NULL
    AND (admin_response IS NULL OR TRIM(admin_response) = '')
");

if (!$stmt) {
    error_log('Critical flood report count prepare error: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to count critical flood reports.']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$count = (int) ($row['total'] ?? 0);
$stmt->close();

echo json_encode(['count' => $count]);
