<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/admin_activity.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================
// ADMIN AUTHENTICATION
// =====================================

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: resident_dashboard.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// =====================================
// HELPERS
// =====================================

function adminReportEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminReportRedirect()
{
    header('Location: admin_reports.php');
    exit;
}

function adminReportImagePath($image)
{
    $image = basename((string) $image);

    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/i', $image)) {
        return '';
    }

    return 'uploads/flood_reports/' . $image;
}

function adminReportStatusClass($status)
{
    switch ($status) {
        case 'Verified':
            return 'verified';

        case 'Rescue Dispatched':
            return 'dispatched';

        case 'Resolved':
            return 'resolved';

        default:
            return 'pending';
    }
}

function adminReportWaterLevelClass($status)
{
    switch ($status) {
        case 'Critical':
            return 'water-critical';

        case 'Warning':
            return 'water-warning';

        default:
            return 'water-normal';
    }
}

function adminReportTokenValid()
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals(
            $_SESSION['csrf_token'],
            (string) $_POST['csrf_token']
        );
}

// =====================================
// CSRF TOKEN
// =====================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =====================================
// DATABASE TABLE
// =====================================

$conn->query("
    CREATE TABLE IF NOT EXISTS flood_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        location VARCHAR(255) NOT NULL,
        latitude DECIMAL(10,7) DEFAULT NULL,
        longitude DECIMAL(10,7) DEFAULT NULL,
        description TEXT NOT NULL,
        image VARCHAR(255) NOT NULL,
        water_level_cm DECIMAL(7,2) NOT NULL DEFAULT 0,
        water_level_status ENUM(
            'Normal',
            'Warning',
            'Critical'
        ) NOT NULL DEFAULT 'Normal',

        status ENUM(
            'Pending',
            'Verified',
            'Rescue Dispatched',
            'Resolved'
        ) NOT NULL DEFAULT 'Pending',

        admin_response TEXT DEFAULT NULL,
        responded_by INT DEFAULT NULL,
        responded_at DATETIME DEFAULT NULL,

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at)
    )
");

$reportColumns = $conn->query('SHOW COLUMNS FROM flood_reports');
if (!$reportColumns) {
    die('Unable to verify flood report fields: ' . $conn->error);
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
        die('Unable to add water depth to flood reports: ' . $conn->error);
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
        die('Unable to add water level status to flood reports: ' . $conn->error);
    }
}

// =====================================
// HANDLE ADMIN ACTIONS
// =====================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!adminReportTokenValid()) {

        $_SESSION['report_message'] = 'Your session expired. Please refresh and try again.';
        $_SESSION['report_message_type'] = 'error';

        adminReportRedirect();
    }

    $reportId = filter_var(
        $_POST['report_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    // =================================
    // RESPOND TO RESIDENT REPORT
    // =================================

    if (isset($_POST['respond_report'])) {

        $allowedStatuses = [
            'Pending',
            'Verified',
            'Rescue Dispatched',
            'Resolved'
        ];

        $status = trim($_POST['status'] ?? '');
        $response = trim($_POST['admin_response'] ?? '');

        if (
            $reportId === false ||
            !in_array($status, $allowedStatuses, true) ||
            $response === '' ||
            strlen($response) > 5000
        ) {
            $_SESSION['report_message'] =
                'Please provide a valid report status and response message.';

            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        // Check if report exists
        $checkStmt = $conn->prepare("
            SELECT id
            FROM flood_reports
            WHERE id = ?
            LIMIT 1
        ");

        if (!$checkStmt) {
            error_log('Report check error: ' . $conn->error);

            $_SESSION['report_message'] = 'Unable to verify the selected report.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        $checkStmt->bind_param('i', $reportId);
        $checkStmt->execute();

        $existingReport = $checkStmt->get_result()->fetch_assoc();

        $checkStmt->close();

        if (!$existingReport) {

            $_SESSION['report_message'] = 'The selected flood report does not exist.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        // Update response
        $stmt = $conn->prepare("
            UPDATE flood_reports
            SET
                status = ?,
                admin_response = ?,
                responded_by = ?,
                responded_at = NOW()
            WHERE id = ?
        ");

        if (!$stmt) {

            error_log('Response prepare error: ' . $conn->error);

            $_SESSION['report_message'] = 'Unable to prepare the response.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        $stmt->bind_param(
            'ssii',
            $status,
            $response,
            $userId,
            $reportId
        );

        $success = $stmt->execute();

        if (!$success) {
            error_log('Response execution error: ' . $stmt->error);
        }

        $stmt->close();

        if ($success) {
            adminActivityLog(
                $conn,
                $userId,
                (string) ($_SESSION['full_name'] ?? 'Administrator'),
                'Responded to flood report',
                'Updated flood report #' . $reportId . ' status to ' . $status
            );

            $_SESSION['report_message'] =
                'Your response has been successfully sent to the resident.';

            $_SESSION['report_message_type'] = 'success';
        } else {

            $_SESSION['report_message'] =
                'Unable to send your response. Please try again.';

            $_SESSION['report_message_type'] = 'error';
        }

        adminReportRedirect();
    }

    // =================================
    // DELETE REPORT
    // =================================

    if (isset($_POST['delete_report'])) {

        if ($reportId === false) {

            $_SESSION['report_message'] = 'Invalid report selected.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        $stmt = $conn->prepare("
            SELECT image
            FROM flood_reports
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $_SESSION['report_message'] = 'Unable to retrieve the report.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        $stmt->bind_param('i', $reportId);
        $stmt->execute();

        $report = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$report) {

            $_SESSION['report_message'] = 'The report could not be found.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        $stmt = $conn->prepare("
            DELETE FROM flood_reports
            WHERE id = ?
        ");

        if (!$stmt) {

            $_SESSION['report_message'] = 'Unable to prepare report deletion.';
            $_SESSION['report_message_type'] = 'error';

            adminReportRedirect();
        }

        $stmt->bind_param('i', $reportId);

        $success = $stmt->execute();

        $stmt->close();

        if ($success) {
            adminActivityLog(
                $conn,
                $userId,
                (string) ($_SESSION['full_name'] ?? 'Administrator'),
                'Deleted flood report',
                'Deleted flood report #' . $reportId
            );

            $imagePath = adminReportImagePath($report['image']);

            if ($imagePath !== '') {

                $absolutePath = __DIR__ . DIRECTORY_SEPARATOR .
                    str_replace('/', DIRECTORY_SEPARATOR, $imagePath);

                if (is_file($absolutePath)) {
                    unlink($absolutePath);
                }
            }

            $_SESSION['report_message'] = 'Flood report deleted successfully.';
            $_SESSION['report_message_type'] = 'success';
        } else {

            $_SESSION['report_message'] = 'Unable to delete the report.';
            $_SESSION['report_message_type'] = 'error';
        }

        adminReportRedirect();
    }

    $_SESSION['report_message'] = 'Invalid report action.';
    $_SESSION['report_message_type'] = 'error';

    adminReportRedirect();
}

// =====================================
// FLASH MESSAGE
// =====================================

$reportMessage = $_SESSION['report_message'] ?? '';
$reportMessageType = $_SESSION['report_message_type'] ?? '';

unset(
    $_SESSION['report_message'],
    $_SESSION['report_message_type']
);

// =====================================
// SEARCH AND PAGINATION
// =====================================

$limit = 5;

$search = trim($_GET['search'] ?? '');

$waterLevelStatusFilter = trim($_GET['water_level_status'] ?? 'All');

if (!in_array($waterLevelStatusFilter, ['All', 'Normal', 'Warning', 'Critical'], true)) {
    $waterLevelStatusFilter = 'All';
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$searchValue = '%' . $search . '%';

$whereClauses = [];
$whereParams = [];
$whereTypes = '';

if ($search !== '') {
    $whereClauses[] = '(
        reports.location LIKE ?
        OR reports.description LIKE ?
        OR reports.status LIKE ?
        OR reports.water_level_status LIKE ?
        OR CAST(reports.water_level_cm AS CHAR) LIKE ?
        OR users.full_name LIKE ?
        OR users.username LIKE ?
    )';

    $whereParams = array_merge(
        $whereParams,
        [$searchValue, $searchValue, $searchValue, $searchValue, $searchValue, $searchValue, $searchValue]
    );
    $whereTypes .= 'sssssss';
}

if ($waterLevelStatusFilter !== 'All') {
    $whereClauses[] = 'reports.water_level_status = ?';
    $whereParams[] = $waterLevelStatusFilter;
    $whereTypes .= 's';
}

$whereSql = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

// Count reports
$countSql = "
    SELECT COUNT(*) AS total
    FROM flood_reports AS reports
    LEFT JOIN users ON users.id = reports.user_id
    $whereSql
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die('Unable to retrieve report count.');
}

if (!empty($whereParams)) {
    $countRefs = [];

    foreach ($whereParams as $key => $value) {
        $countRefs[$key] = &$whereParams[$key];
    }

    $countBindValues = array_merge([$whereTypes], $countRefs);
    call_user_func_array([$countStmt, 'bind_param'], $countBindValues);
}

$countStmt->execute();

$totalReports = (int) $countStmt
    ->get_result()
    ->fetch_assoc()['total'];

$countStmt->close();

$totalPages = max(1, (int) ceil($totalReports / $limit));

$page = min($page, $totalPages);

$offset = ($page - 1) * $limit;

// Retrieve reports
$stmt = $conn->prepare("
    SELECT
        reports.id,
        reports.user_id,
        reports.location,
        reports.latitude,
        reports.longitude,
        reports.description,
        reports.image,
        reports.water_level_cm,
        reports.water_level_status,
        reports.status,
        reports.admin_response,
        reports.responded_at,
        reports.created_at,

        users.full_name AS submitted_by,
        users.username AS submitted_username

    FROM flood_reports AS reports

    LEFT JOIN users
        ON users.id = reports.user_id

    $whereSql

    ORDER BY reports.created_at DESC, reports.id DESC

    LIMIT ? OFFSET ?
");

if (!$stmt) {
    die('Unable to retrieve flood reports.');
}

if (!empty($whereParams)) {
    $stmtParams = array_merge($whereParams, [$limit, $offset]);
    $stmtRefs = [];

    foreach ($stmtParams as $key => $value) {
        $stmtRefs[$key] = &$stmtParams[$key];
    }

    $stmtBindValues = array_merge([$whereTypes . 'ii'], $stmtRefs);
    call_user_func_array([$stmt, 'bind_param'], $stmtBindValues);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}

$stmt->execute();

$result = $stmt->get_result();

$reports = [];

while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

$stmt->close();

// =====================================
// RENDER REPORT TABLE ROWS
// =====================================

function renderAdminReportRows($reports)
{
    if (empty($reports)) {

        echo '
            <tr>
                <td colspan="7" class="empty-cell">
                    <div class="empty-state">
                        <div>🌊</div>
                        <h3>No flood reports found</h3>
                        <p>Resident reports will appear here.</p>
                    </div>
                </td>
            </tr>
        ';

        return;
    }

    foreach ($reports as $report) {

        $id = (int) $report['id'];

        $location = adminReportEscape($report['location']);

        $description = adminReportEscape($report['description']);

        $status = adminReportEscape($report['status']);

        $waterLevel = number_format((float) $report['water_level_cm'], 2);
        $waterLevelStatus = $report['water_level_status'] ?? 'Normal';

        $image = adminReportEscape(
            adminReportImagePath($report['image'])
        );

        $response = trim($report['admin_response'] ?? '');

        $resident = adminReportEscape(
            $report['submitted_by'] ?: 'Unknown Resident'
        );

        $username = adminReportEscape(
            $report['submitted_username'] ?: 'unknown'
        );

        $date = date(
            'M d, Y h:i A',
            strtotime($report['created_at'])
        );

        $respondedAt = !empty($report['responded_at'])
            ? date('M d, Y h:i A', strtotime($report['responded_at']))
            : '';

        echo '<tr>';

        // RESIDENT
        echo '
            <td>
                <div class="resident-cell">
                    <div class="resident-avatar">' .
            adminReportEscape(
                strtoupper(substr($report['submitted_by'] ?? 'U', 0, 1))
            ) .
            '</div>

                    <div>
                        <strong>' . $resident . '</strong>
                        <small>@' . $username . '</small>
                    </div>
                </div>
            </td>
        ';

        // LOCATION AND DESCRIPTION
        echo '
            <td>
                <div class="report-location">' . $location . '</div>

                <div class="report-description">' .
            nl2br($description) .
            '</div>
            </td>
        ';

        // WATER LEVEL
        echo '
            <td>
                <strong>' . $waterLevel . ' cm</strong>
                <div>
                    <span class="water-level-badge ' .
            adminReportWaterLevelClass($waterLevelStatus) .
            '">' . adminReportEscape($waterLevelStatus) . '</span>
                </div>
            </td>
        ';

        // IMAGE
        echo '<td>';

        if ($image !== '') {

            echo '
                <div class="report-image-frame" aria-busy="true">
                    <img
                        src="' . $image . '"
                        class="report-image"
                        alt="Flood report proof"
                        width="58"
                        height="48"
                        loading="lazy"
                        onload="this.parentElement.classList.add(\'is-loaded\'); this.parentElement.setAttribute(\'aria-busy\', \'false\')"
                        onerror="this.parentElement.classList.add(\'has-error\'); this.parentElement.setAttribute(\'aria-busy\', \'false\')">
                </div>
            ';
        } else {

            echo '<span class="no-image">No image</span>';
        }

        echo '</td>';

        // STATUS
        echo '
            <td>
                <span class="status-badge ' .
            adminReportStatusClass($report['status']) .
            '">' . $status . '</span>
            </td>
        ';

        // DATE AND RESPONSE
        echo '
            <td>
                <div class="report-date">' .
            adminReportEscape($date) .
            '</div>
        ';

        if ($response !== '') {

            echo '
                <div class="admin-response">
                    <strong>Admin Response</strong>

                    <p>' .
                nl2br(adminReportEscape($response)) .
                '</p>

                    <small>' .
                adminReportEscape($respondedAt) .
                '</small>
                </div>
            ';
        } else {

            echo '<span class="waiting-response">Awaiting response</span>';
        }

        echo '</td>';

        // ACTIONS
        echo '
            <td>
                <div class="action-group">

                    <button
                        type="button"
                        class="action-btn view-btn"

                        data-id="' . $id . '"
                        data-resident="' . $resident . '"
                        data-username="' . $username . '"
                        data-location="' . $location . '"
                        data-latitude="' . adminReportEscape($report['latitude']) . '"
                        data-longitude="' . adminReportEscape($report['longitude']) . '"
                        data-water-level="' . adminReportEscape($waterLevel) . '"
                        data-water-status="' . adminReportEscape($waterLevelStatus) . '"
                        data-description="' . $description . '"
                        data-image="' . $image . '"
                        data-status="' . $status . '"
                        data-date="' . adminReportEscape($date) . '"
                        data-response="' . adminReportEscape($response) . '"
                        data-responded-at="' . adminReportEscape($respondedAt) . '"

                        title="View report"
                        aria-label="View report"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>

                    <button
                        type="button"
                        class="action-btn respond-btn"

                        data-id="' . $id . '"
                        data-status="' . $status . '"
                        data-response="' . adminReportEscape($response) . '"

                        title="Respond to report"
                        aria-label="Respond to report"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 9 9 0 0 1-4-.9L3 21l1.9-5.5a9 9 0 0 1-.9-4A8.5 8.5 0 0 1 12.5 3H13a8.5 8.5 0 0 1 8 8v.5Z"/><path d="M8 12h8M12 8v8"/></svg>
                    </button>

                    <button
                        type="button"
                        class="action-btn delete-btn"
                        data-id="' . $id . '"

                        title="Delete report"
                        aria-label="Delete report"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg>
                    </button>

                </div>
            </td>
        ';

        echo '</tr>';
    }
}

$pageTitle = 'Flood Reports';
$activePage = 'reports';
$sweetAlertLoaded = true;

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .reports-page {
        box-sizing: border-box;
        width: 100%;
        max-width: 1600px;
        min-width: 0;
        margin: 0 auto;
        padding: 28px;
    }

    .report-card {
        min-width: 0;
    }

    .table-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        min-width: 0;
        margin: 0 0 14px;
    }

    #reportSearchInput {
        flex: 1 1 320px;
        min-width: 0;
    }

    .water-status-filter {
        position: relative;
        flex: 0 0 auto;
    }

    .table-responsive {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        -webkit-overflow-scrolling: touch;
    }

    .reports-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
    }

    .report-image-frame {
        position: relative;
        box-sizing: border-box;
        width: 58px;
        height: 48px;
        overflow: hidden;
    }

    .report-image {
        position: absolute;
        inset: 0;
        display: block;
        box-sizing: border-box;
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        object-fit: cover;
    }

    @media (max-width: 700px) {
        .reports-page {
            padding: 16px 12px;
        }

        .table-toolbar {
            flex-wrap: wrap;
        }

        #reportSearchInput,
        .water-status-filter {
            flex-basis: 100%;
        }
    }
</style>

<!-- SWEETALERT2 -->
<script src="assets/css/sweetalert.all.min.js"></script>

<main class="reports-page">

    <div class="page-heading">
        <div>
         
        </div>
    </div>

    <section class="report-card">

        <div class="card-heading">
            <div>
              
            </div>

            <div class="report-count">
                <strong id="adminReportTotal"><?= $totalReports ?></strong> Reports
            </div>
        </div>

        <form method="GET" class="table-toolbar" id="reportSearchForm" role="search">
                <input type="hidden" name="water_level_status" value="<?= adminReportEscape($waterLevelStatusFilter) ?>">
                <input
                    type="search"
                    name="search"
                    id="reportSearchInput"
                    value="<?= adminReportEscape($search) ?>"
                    autocomplete="off"
                    aria-label="Search flood reports"
                    placeholder="Search resident, location, description, water level, or status..."
                    data-live-search
                    data-live-search-targets="#adminReportRows|#adminReportPagination|#adminReportTotal">

                <details class="water-status-filter">
                    <summary aria-label="Filter by water level status">
                        Water Level: <?= adminReportEscape($waterLevelStatusFilter) ?>
                        <span aria-hidden="true">▾</span>
                    </summary>
                    <div class="water-status-options">
                        <?php foreach (['All', 'Normal', 'Warning', 'Critical'] as $waterStatusOption): ?>
                            <a
                                href="?search=<?= urlencode($search) ?>&water_level_status=<?= urlencode($waterStatusOption) ?>"
                                class="<?= $waterLevelStatusFilter === $waterStatusOption ? 'active' : '' ?>"
                                <?= $waterLevelStatusFilter === $waterStatusOption ? 'aria-current="true"' : '' ?>>
                                <?= adminReportEscape($waterStatusOption) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>

        </form>

        <div class="table-responsive">

            <table class="reports-table">

                <thead>
                    <tr>
                        <th>RESIDENT</th>
                        <th>LOCATION / DESCRIPTION</th>
                        <th>WATER LEVEL</th>
                        <th>IMAGE PROOF</th>
                        <th>STATUS</th>
                        <th>DATE / RESPONSE</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>

                <tbody id="adminReportRows">
                    <?php renderAdminReportRows($reports); ?>
                </tbody>

            </table>

        </div>

        <div id="adminReportPagination">
        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&water_level_status=<?= urlencode($waterLevelStatusFilter) ?>">
                        Previous
                    </a>
                <?php endif; ?>

                <?php
                for (
                    $i = max(1, $page - 2);
                    $i <= min($totalPages, $page + 2);
                    $i++
                ):
                ?>

                    <a
                        class="<?= $i === $page ? 'active' : '' ?>"
                        href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&water_level_status=<?= urlencode($waterLevelStatusFilter) ?>">
                        <?= $i ?>
                    </a>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&water_level_status=<?= urlencode($waterLevelStatusFilter) ?>">
                        Next
                    </a>
                <?php endif; ?>

            </div>

        <?php endif; ?>
        </div>

    </section>

</main>

<!-- =====================================
     VIEW REPORT MODAL
====================================== -->

<div class="custom-modal-overlay" id="viewModal">

    <div class="custom-modal">

        <div class="modal-header">
            <div>
                <span class="eyebrow">REPORT INFORMATION</span>
                <h2>Flood Report Details</h2>
            </div>

            <button type="button" class="modal-close" data-close="viewModal">
                &times;
            </button>
        </div>

        <div class="modal-body">

            <div class="detail-grid">

                <div>
                    <label>Report ID</label>
                    <p id="vId"></p>
                </div>

                <div>
                    <label>Resident</label>
                    <p id="vResident"></p>
                </div>

                <div>
                    <label>Username</label>
                    <p id="vUsername"></p>
                </div>

                <div>
                    <label>Date Submitted</label>
                    <p id="vDate"></p>
                </div>

                <div>
                    <label>Location</label>
                    <p id="vLocation"></p>
                </div>

                <div>
                    <label>Latitude</label>
                    <p id="vLatitude"></p>
                </div>

                <div>
                    <label>Longitude</label>
                    <p id="vLongitude"></p>
                </div>

                <div>
                    <label>Water Depth</label>
                    <p id="vWaterLevel"></p>
                </div>

                <div>
                    <label>Water Level Status</label>
                    <p id="vWaterStatus"></p>
                </div>

                <div>
                    <label>Status</label>
                    <p id="vStatus"></p>
                </div>

            </div>

            <div class="detail-block">
                <label>Description</label>
                <p id="vDescription"></p>
            </div>

            <div class="detail-block">
                <label>Image Proof</label>

                <a id="vImageLink" href="#" target="_blank" rel="noopener noreferrer">
                    <img id="vImage" src="" alt="Flood report proof">
                </a>
            </div>

            <div class="detail-block">
                <label>Admin Response</label>
                <p id="vResponse"></p>
                <small id="vRespondedAt"></small>
            </div>

        </div>

        <div class="modal-footer">
            <button type="button" class="btn-secondary" data-close="viewModal">
                Close
            </button>
        </div>

    </div>

</div>

<!-- =====================================
     RESPOND TO REPORT MODAL
====================================== -->

<div class="custom-modal-overlay" id="respondModal">

    <div class="custom-modal">

        <div class="modal-header">

            <div>
                <span class="eyebrow">INCIDENT RESPONSE</span>
                <h2>Respond to Flood Report</h2>
                <p>Send an update directly to the resident.</p>
            </div>

            <button type="button" class="modal-close" data-close="respondModal">
                &times;
            </button>

        </div>

        <form method="POST" action="admin_reports.php" id="responseForm">

            <!-- CSRF -->
            <input
                type="hidden"
                name="csrf_token"
                value="<?= adminReportEscape($_SESSION['csrf_token']) ?>">

            <!-- SELECTED REPORT -->
            <input
                type="hidden"
                name="report_id"
                id="responseId">

            <!-- IMPORTANT: DO NOT REMOVE THIS HIDDEN INPUT -->
            <input
                type="hidden"
                name="respond_report"
                value="1">

            <div class="modal-body">

                <div class="selected-report">
                    <strong>Responding to report:</strong>
                    <span id="selectedReportNumber">#---</span>
                </div>

                <div class="form-group">

                    <label for="responseStatus">
                        Update Report Status <span>*</span>
                    </label>

                    <select name="status" id="responseStatus" required>

                        <option value="Pending">Pending</option>
                        <option value="Verified">Verified</option>
                        <option value="Rescue Dispatched">Rescue Dispatched</option>
                        <option value="Resolved">Resolved</option>
                    </select>

                </div>

                <div class="form-group">

                    <label for="adminResponse">
                        Response Message <span>*</span>
                    </label>

                    <textarea
                        name="admin_response"
                        id="adminResponse"
                        rows="5"
                        maxlength="5000"
                        required
                        placeholder="Enter your response to the resident..."></textarea>

                    <small class="character-count">
                        <span id="responseCharCount">0</span> / 5000 characters
                    </small>

                </div>

                <div class="templates">

                    <strong>Quick Response Messages</strong>

                    <div class="template-buttons">

                        <button
                            type="button"
                            data-status="Verified"
                            data-message="Your flood report has been received and is currently being verified by our team.">
                            Report Received
                        </button>

                        <button
                            type="button"
                            data-status="Rescue Dispatched"
                            data-message="Our rescue team has been dispatched to your reported location. Please remain in a safe area and wait for assistance.">
                            Rescue Dispatched
                        </button>

                        <button
                            type="button"
                            data-status="Resolved"
                            data-message="The reported incident has been addressed. Please continue to observe safety precautions.">
                            Incident Resolved
                        </button>

                    </div>

                </div>

                <div class="response-note">
                    <strong>Important:</strong>
                    Your response and updated status will be visible to the resident
                    in their My Reports page.
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-secondary"
                    data-close="respondModal">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn-primary"
                    id="sendResponseBtn">
                    Send Response
                </button>

            </div>

        </form>

    </div>

</div>

<style>
    /* =====================================
   GENERAL LAYOUT
===================================== */

    .reports-page {
        box-sizing: border-box;
        width: 100%;
        max-width: 1600px;
        margin: auto;
        padding: 28px;
        color: #253247;
    }

    .page-heading {
        margin-bottom: 24px;
    }

    .eyebrow {
        display: block;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 1.2px;
        color: #64748b;
        margin-bottom: 8px;
    }

    .page-heading h1 {
        font-size: 27px;
        margin: 0 0 7px;
        color: #1e293b;
    }

    .page-heading p,
    .card-heading p {
        font-size: 13px;
        color: #78869a;
        margin: 0;
        line-height: 1.6;
    }

    .report-card {
        min-width: 0;
        background: #fff;
        border: 1px solid #e5eaf0;
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .card-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .card-heading h2 {
        font-size: 17px;
        margin: 0 0 6px;
        color: #263449;
    }

    .report-count {
        background: #eff6ff;
        color: #1d4ed8;
        padding: 9px 12px;
        font-size: 12px;
    }

    .report-count strong {
        font-size: 15px;
    }

    /* =====================================
   BUTTONS AND ALERTS
===================================== */

    .btn-primary,
    .btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 15px;
        border: 1px solid;
        border-radius: 3px;
        font-size: 12px;
        font-weight: 650;
        cursor: pointer;
        text-decoration: none;
        transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease;
    }

    .btn-primary {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .btn-primary:hover {
        background: #1e40af;
        border-color: #1e40af;
        transform: translateY(-1px);
    }

    .btn-secondary:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        color: #334155;
        transform: translateY(-1px);
    }

    .btn-primary:active,
    .btn-secondary:active {
        transform: translateY(0);
    }

    .btn-primary:focus-visible,
    .btn-secondary:focus-visible,
    .action-btn:focus-visible,
    .template-buttons button:focus-visible,
    .modal-close:focus-visible {
        outline: 3px solid rgb(59 130 246 / 30%);
        outline-offset: 2px;
    }

    .btn-primary:disabled,
    .btn-secondary:disabled {
        opacity: .65;
        cursor: not-allowed;
        transform: none;
    }

    .btn-secondary {
        background: #fff;
        border-color: #d1d5db;
        color: #475569;
    }

    /* =====================================
   SEARCH
===================================== */

    .table-toolbar {
        display: flex;
        gap: 8px;
        margin-bottom: 17px;
    }

    .table-toolbar input {
        width: 100%;
        max-width: 420px;
        height: 40px;
        padding: 9px 12px;
        border: 1px solid #d6dde6;
        border-radius: 3px;
        outline: none;
    }

    .table-toolbar input:focus {
        border-color: #3b82f6;
    }

    .table-toolbar {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
        margin: 0 0 14px;
        text-align: left;
    }

    .table-toolbar input {
        width: 100%;
        max-width: 460px;
        height: 42px;
        padding: 10px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        background: #fff;
        color: #334155;
        font-size: 12px;
        transition: border-color .18s ease, box-shadow .18s ease;
    }

    .table-toolbar input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px #3b82f61a;
        outline: none;
    }

    #reportSearchInput {
        flex: 1 1 320px;
        min-width: 220px;
    }

    .water-status-filter {
        position: relative;
        flex: 0 0 auto;
    }

    .water-status-filter summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 42px;
        padding: 10px 14px;
        border: 1px solid #d6dde6;
        border-radius: 6px;
        background: #fff;
        color: #334155;
        cursor: pointer;
        font-size: 12px;
        font-weight: 700;
        list-style: none;
        white-space: nowrap;
    }

    .water-status-filter summary::-webkit-details-marker {
        display: none;
    }

    .water-status-filter summary:hover,
    .water-status-filter[open] summary {
        border-color: #93c5fd;
        color: #1d4ed8;
    }

    .water-status-filter summary:focus-visible,
    .water-status-options a:focus-visible {
        outline: 3px solid rgb(59 130 246 / 30%);
        outline-offset: 2px;
    }

    .water-status-options {
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        z-index: 20;
        min-width: 100%;
        padding: 5px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        background: #fff;
        box-shadow: 0 10px 25px rgb(15 23 42 / 12%);
    }

    .water-status-options a {
        display: block;
        padding: 9px 12px;
        border-radius: 4px;
        color: #475569;
        font-size: 12px;
        text-decoration: none;
        white-space: nowrap;
    }

    .water-status-options a:hover,
    .water-status-options a.active {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .action-btn {
        border-radius: 5px;
        transition: background .18s ease, border-color .18s ease;
    }

    .view-btn,
    .respond-btn,
    .delete-btn {
        background: #fff1f2;
        color: #be123c;
        border-color: #ffe4e6;
    }

    .view-btn:hover,
    .respond-btn:hover {
        background: #1e40af;
        border-color: #1e40af;
    }

    .delete-btn:hover {
        background: #be123c;
        border-color: #be123c;
    }

    /* =====================================
   TABLE
===================================== */

    .table-responsive {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .reports-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
    }

    .reports-table th,
    .reports-table td {
        box-sizing: border-box;
    }

    .reports-table td {
        overflow-wrap: anywhere;
    }

    .reports-table th {
        background: #f5f7fa;
        color: #64748b;
        font-size: 10px;
        text-align: left;
        padding: 14px 12px;
        border-block: 1px solid #e8edf2;
        white-space: nowrap;
    }

    .reports-table td {
        padding: 14px 12px;
        border-bottom: 1px solid #edf0f4;
        vertical-align: middle;
        font-size: 12px;
    }

    .reports-table tbody tr:hover {
        background: #fafcff;
    }

    /* =====================================
   RESIDENT
===================================== */

    .resident-cell {
        display: flex;
        align-items: center;
        gap: 9px;
        min-width: 140px;
    }

    .resident-avatar {
        width: 32px;
        height: 32px;
        display: grid;
        place-items: center;
        background: #eaf1ff;
        color: #1d4ed8;
        font-weight: 800;
        flex-shrink: 0;
    }

    .resident-cell strong,
    .resident-cell small {
        display: block;
    }

    .resident-cell strong {
        font-size: 11px;
        color: #334155;
    }

    .resident-cell small {
        font-size: 10px;
        color: #94a3b8;
        margin-top: 4px;
    }

    /* =====================================
   REPORT INFORMATION
===================================== */

    .report-location {
        font-weight: 700;
        color: #263449;
        margin-bottom: 5px;
    }

    .report-description {
        font-size: 11px;
        line-height: 1.6;
        color: #8591a2;
        max-width: 260px;
        overflow-wrap: anywhere;
    }

    .report-image-frame {
        position: relative;
        box-sizing: border-box;
        width: 58px;
        height: 48px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        background: linear-gradient(100deg, #edf1f6 25%, #f8fafc 45%, #edf1f6 65%);
        background-size: 250% 100%;
        animation: report-image-loading 1.2s ease-in-out infinite;
    }

    @keyframes report-image-loading {
        to {
            background-position: -150% 0;
        }
    }

    .report-image-frame.is-loaded,
    .report-image-frame.has-error {
        animation: none;
        background: #f1f5f9;
    }

    .report-image {
        position: absolute;
        inset: 0;
        display: block;
        box-sizing: border-box;
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        object-fit: cover;
        object-position: center;
        opacity: 0;
        transition: opacity .2s ease;
    }

    .report-image-frame.is-loaded .report-image {
        opacity: 1;
    }

    .report-image-frame.has-error::after {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        color: #94a3b8;
        content: "—";
        font-size: 18px;
    }

    @media (prefers-reduced-motion: reduce) {
        .report-image-frame {
            animation: none;
        }
    }

    .no-image,
    .waiting-response {
        font-size: 10px;
        color: #94a3b8;
    }

    /* =====================================
   STATUS
===================================== */

    .status-badge {
        display: inline-block;
        padding: 6px 9px;
        font-size: 10px;
        font-weight: 750;
        white-space: nowrap;
    }

    .pending {
        background: #fff7df;
        color: #a16207;
    }

    .verified {
        background: #eaf2ff;
        color: #1d4ed8;
    }

    .dispatched {
        background: #fff0e4;
        color: #c2410c;
    }

    .resolved {
        background: #e8f8ee;
        color: #15803d;
    }

    .water-level-badge {
        display: inline-block;
        margin-top: 5px;
        padding: 4px 7px;
        font-size: 9px;
        font-weight: 750;
        white-space: nowrap;
    }

    .water-normal {
        background: #e8f8ee;
        color: #15803d;
    }

    .water-warning {
        background: #fff7df;
        color: #a16207;
    }

    .water-critical {
        background: #fef2f2;
        color: #b91c1c;
    }

    /* =====================================
   ADMIN RESPONSE
===================================== */

    .report-date {
        font-size: 10px;
        color: #64748b;
        margin-bottom: 8px;
        white-space: nowrap;
    }

    .admin-response {
        background: #f0f6ff;
        border-left: 2px solid #3b82f6;
        padding: 9px;
        max-width: 220px;
        overflow-wrap: anywhere;
    }

    .admin-response strong {
        font-size: 10px;
        color: #1d4ed8;
    }

    .admin-response p {
        font-size: 10px;
        line-height: 1.5;
        color: #475569;
        margin: 5px 0;
    }

    .admin-response small {
        font-size: 9px;
        color: #94a3b8;
    }

    /* =====================================
   ACTION BUTTONS
===================================== */

    .action-group {
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 130px;
        white-space: nowrap;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border: 1px solid #dbeafe;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 0;
        cursor: pointer;
        border-radius: 3px;
        transition: .2s ease;
    }

    .action-btn:hover {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .action-btn:active {
        transform: translateY(1px);
    }

    .action-btn svg {
        display: block;
        flex: 0 0 auto;
    }

    /* =====================================
   EMPTY STATE
===================================== */

    .empty-cell {
        text-align: center;
        padding: 45px !important;
    }

    .empty-state div {
        font-size: 30px;
    }

    .empty-state h3 {
        font-size: 15px;
        color: #334155;
        margin: 10px 0 5px;
    }

    .empty-state p {
        font-size: 11px;
        color: #94a3b8;
    }

    /* =====================================
   PAGINATION
===================================== */

    .pagination {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
        margin-top: 18px;
    }

    .pagination a {
        padding: 8px 11px;
        border: 1px solid #dbe1e9;
        color: #64748b;
        text-decoration: none;
        font-size: 11px;
    }

    .pagination a.active {
        background: #1d4ed8;
        color: white;
        border-color: #1d4ed8;
    }

    /* =====================================
   CUSTOM MODALS
===================================== */

    .custom-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: #0f172a99;
        padding: 20px;
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }

    .swal2-container {
        z-index: 10000 !important;
    }

    .custom-modal-overlay.show {
        display: flex;
        align-items: flex-start;
        justify-content: center;
    }

    .custom-modal {
        display: flex;
        flex-direction: column;
        width: 100%;
        max-width: 680px;
        max-height: calc(100vh - 40px);
        max-height: calc(100dvh - 40px);
        min-height: 0;
        margin: auto;
        overflow: hidden;
        background: #fff;
        border: 1px solid #e2e8f0;
    }

    .custom-modal form {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
    }

    .modal-header,
    .modal-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 18px 22px;
        border-bottom: 1px solid #e8edf2;
    }

    .modal-header h2 {
        font-size: 18px;
        margin: 0;
        color: #263449;
    }

    .modal-header p {
        font-size: 11px;
        color: #8491a3;
        margin: 5px 0 0;
    }

    .modal-close {
        border: 0;
        background: none;
        font-size: 27px;
        color: #64748b;
        cursor: pointer;
    }

    .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        padding: 22px;
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }

    .modal-footer {
        flex: 0 0 auto;
        justify-content: flex-end;
        gap: 8px;
        border-top: 1px solid #e8edf2;
        border-bottom: 0;
    }

    /* =====================================
   VIEW DETAILS
===================================== */

    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .detail-grid label,
    .detail-block label {
        display: block;
        color: #94a3b8;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .detail-grid p,
    .detail-block p {
        font-size: 12px;
        line-height: 1.6;
        color: #334155;
        overflow-wrap: anywhere;
    }

    .detail-block {
        margin-top: 18px;
    }

    #vImageLink {
        display: flex;
        align-items: center;
        justify-content: center;
        width: min(100%, 372px);
        height: 212px;
        max-width: 100%;
        padding: 6px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        background: #f8fafc;
    }

    #vImage {
        display: block;
        width: auto;
        height: auto;
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    /* =====================================
   RESPONSE FORM
===================================== */

    .selected-report {
        display: flex;
        justify-content: space-between;
        background: #eff6ff;
        border-left: 3px solid #2563eb;
        padding: 12px;
        margin-bottom: 20px;
        font-size: 12px;
        color: #334155;
    }

    .selected-report span {
        color: #1d4ed8;
        font-weight: 800;
    }

    .form-group {
        margin-bottom: 17px;
    }

    .form-group label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 7px;
        color: #334155;
    }

    .form-group label span {
        color: #dc2626;
    }

    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d6dde6;
        border-radius: 3px;
        font-family: Arial, sans-serif;
        font-size: 13px;
        outline: none;
        color: #334155;
        background: #fff;
    }

    .form-group select:focus,
    .form-group textarea:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 2px #3b82f61a;
    }

    .form-group textarea {
        resize: vertical;
        line-height: 1.6;
    }

    .character-count {
        display: block;
        text-align: right;
        font-size: 10px;
        color: #94a3b8;
        margin-top: 5px;
    }

    /* =====================================
   QUICK RESPONSE TEMPLATES
===================================== */

    .templates {
        margin-top: 18px;
    }

    .templates>strong {
        display: block;
        font-size: 11px;
        color: #475569;
        margin-bottom: 9px;
    }

    .template-buttons {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .template-buttons button {
        padding: 8px 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-size: 10px;
        cursor: pointer;
        border-radius: 2px;
        transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease;
    }

    .template-buttons button:hover {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
        transform: translateY(-1px);
    }

    .template-buttons button:active {
        transform: translateY(0);
    }

    .response-note {
        margin-top: 20px;
        padding: 12px;
        background: #f0f6ff;
        border-left: 3px solid #3b82f6;
        color: #475569;
        font-size: 11px;
        line-height: 1.6;
    }

    .response-note strong {
        color: #1d4ed8;
    }

    /* =====================================
   RESPONSIVE
===================================== */

    @media(max-width:900px) {
        .reports-page {
            padding: 22px 18px;
        }

        .report-card {
            padding: 18px;
        }
    }

    @media(max-width:700px) {

        .reports-page {
            padding: 16px 12px;
        }

        .report-card {
            padding: 14px;
            border-radius: 6px;
        }

        .table-toolbar {
            flex-wrap: wrap;
        }

        .table-toolbar input {
            max-width: none;
        }

        #reportSearchInput {
            flex-basis: 100%;
            min-width: 0;
        }

        .water-status-filter {
            width: 100%;
        }

        .water-status-filter summary {
            width: 100%;
        }

        .reports-table {
            min-width: 1050px;
        }

        .pagination {
            justify-content: center;
            flex-wrap: wrap;
        }

        .detail-grid {
            grid-template-columns: 1fr;
        }

        .custom-modal-overlay {
            align-items: flex-start;
            padding: 10px;
        }

        .custom-modal {
            max-height: calc(100vh - 20px);
            max-height: calc(100dvh - 20px);
        }

        .modal-body {
            padding: 16px;
        }

        #vImage {
            max-height: 180px;
        }

        .selected-report {
            flex-direction: column;
            gap: 7px;
        }

        .modal-header {
            padding: 15px;
        }

    }

    @media(max-width:420px) {
        .reports-page {
            padding: 12px 8px;
        }

        .report-card {
            padding: 10px;
        }

        .report-count {
            padding: 7px 9px;
            white-space: nowrap;
        }

        .custom-modal-overlay {
            padding: 6px;
        }

        .custom-modal {
            max-height: calc(100vh - 12px);
            max-height: calc(100dvh - 12px);
        }
    }
</style>

<script>
    // =====================================
    // MODAL FUNCTIONS
    // =====================================

    function showModal(id) {

        document.getElementById(id).classList.add('show');

        document.body.style.overflow = 'hidden';
    }

    function hideModal(id) {

        document.getElementById(id).classList.remove('show');

        if (!document.querySelector('.custom-modal-overlay.show')) {
            document.body.style.overflow = '';
        }
    }

    // Close modal buttons
    document.querySelectorAll('[data-close]').forEach(button => {

        button.addEventListener('click', () => {
            hideModal(button.dataset.close);
        });

    });

    // Click outside modal
    document.querySelectorAll('.custom-modal-overlay').forEach(modal => {

        modal.addEventListener('click', event => {

            if (event.target === modal) {
                hideModal(modal.id);
            }

        });

    });

    // ESC key
    document.addEventListener('keydown', event => {

        if (event.key === 'Escape') {

            document.querySelectorAll('.custom-modal-overlay.show')
                .forEach(modal => hideModal(modal.id));

        }

    });

    // =====================================
    // VIEW REPORT DETAILS
    // =====================================

    // Delegate table actions so buttons continue working after live search replaces rows.
    document.addEventListener('click', event => {
        const clickedElement = event.target instanceof Element
            ? event.target
            : event.target.parentElement;
        const button = clickedElement && clickedElement.closest(
            '.view-btn, .respond-btn, .delete-btn'
        );

        if (!button) {
            return;
        }

        if (button.matches('.view-btn')) {
            const d = button.dataset;

            document.getElementById('vId').textContent = '#' + d.id;
            document.getElementById('vResident').textContent = d.resident;
            document.getElementById('vUsername').textContent = '@' + d.username;
            document.getElementById('vDate').textContent = d.date;
            document.getElementById('vLocation').textContent = d.location;
            document.getElementById('vLatitude').textContent = d.latitude || 'Not provided';
            document.getElementById('vLongitude').textContent = d.longitude || 'Not provided';
            document.getElementById('vWaterLevel').textContent = d.waterLevel + ' cm';
            document.getElementById('vWaterStatus').textContent = d.waterStatus;
            document.getElementById('vStatus').textContent = d.status;
            document.getElementById('vDescription').textContent = d.description;
            document.getElementById('vResponse').textContent =
                d.response || 'No response sent yet.';
            document.getElementById('vRespondedAt').textContent =
                d.respondedAt ? 'Responded: ' + d.respondedAt : '';

            const image = document.getElementById('vImage');
            const link = document.getElementById('vImageLink');

            if (
                d.image &&
                /^uploads\/flood_reports\/[a-f0-9]{32}\.(jpg|png|webp)$/i.test(d.image)
            ) {
                image.src = d.image;
                link.href = d.image;
                link.style.display = 'inline-block';
            } else {
                image.removeAttribute('src');
                link.removeAttribute('href');
                link.style.display = 'none';
            }

            showModal('viewModal');
            return;
        }

        if (button.matches('.respond-btn')) {
            const reportId = button.dataset.id;
            document.getElementById('responseId').value = reportId;
            document.getElementById('selectedReportNumber').textContent = '#' + reportId;
            document.getElementById('responseStatus').value = button.dataset.status;
            document.getElementById('adminResponse').value = button.dataset.response || '';
            document.getElementById('responseCharCount').textContent =
                document.getElementById('adminResponse').value.length;
            document.getElementById('sendResponseBtn').disabled = false;
            document.getElementById('sendResponseBtn').textContent = 'Send Response';
            showModal('respondModal');
            return;
        }

        confirmDeleteReport(button);
    });

    // =====================================
    // QUICK RESPONSE TEMPLATES
    // =====================================

    document.querySelectorAll('.template-buttons button').forEach(button => {

        button.addEventListener('click', () => {

            document.getElementById('responseStatus').value =
                button.dataset.status;

            document.getElementById('adminResponse').value =
                button.dataset.message;

            document.getElementById('responseCharCount').textContent =
                button.dataset.message.length;

        });

    });

    // =====================================
    // RESPONSE CHARACTER COUNTER
    // =====================================

    document.getElementById('adminResponse').addEventListener('input', function() {

        document.getElementById('responseCharCount').textContent =
            this.value.length;

    });

    // =====================================
    // SEND RESPONSE WITH SWEETALERT
    // =====================================

    const responseForm = document.getElementById('responseForm');

    responseForm.addEventListener('submit', function(event) {

        event.preventDefault();

        const reportId = document.getElementById('responseId').value;

        const status = document.getElementById('responseStatus').value;

        const message = document.getElementById('adminResponse').value.trim();

        const button = document.getElementById('sendResponseBtn');

        if (!reportId) {

            Swal.fire({
                icon: 'error',
                title: 'Invalid Report',
                text: 'Please select a valid flood report.',
                confirmButtonColor: '#1d4ed8'
            });

            return;
        }

        if (!message) {

            Swal.fire({
                icon: 'warning',
                title: 'Response Required',
                text: 'Please enter a response message before sending.',
                confirmButtonColor: '#1d4ed8'
            });

            return;
        }

        if (message.length > 5000) {

            Swal.fire({
                icon: 'warning',
                title: 'Message Too Long',
                text: 'Your response must not exceed 5,000 characters.',
                confirmButtonColor: '#1d4ed8'
            });

            return;
        }

        // Confirmation before sending
        Swal.fire({

            title: 'Send Response?',

            text: 'Your response will be sent to the resident and the report status will be updated to ' + status + '.',

            icon: 'question',

            showCancelButton: true,

            confirmButtonText: 'Yes, Send Response',

            cancelButtonText: 'Review Again',

            confirmButtonColor: '#1d4ed8',

            cancelButtonColor: '#64748b',

            reverseButtons: true,

            allowOutsideClick: false

        }).then(result => {

            if (result.isConfirmed) {

                button.disabled = true;

                button.textContent = 'Sending...';

                Swal.fire({

                    title: 'Sending Response',

                    text: 'Please wait while we save your response.',

                    allowOutsideClick: false,

                    allowEscapeKey: false,

                    showConfirmButton: false,

                    didOpen: () => {
                        Swal.showLoading();
                    }

                });

                // Submit the form with the hidden respond_report field
                // Native submit avoids triggering this handler again.
                responseForm.submit();

            }

        });

    });

    // =====================================
    // DELETE REPORT WITH SWEETALERT
    // =====================================

    function confirmDeleteReport(button) {
        Swal.fire({
            title: 'Delete Flood Report?',
            text: 'This will permanently delete the report and its uploaded image.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(result => {
            if (!result.isConfirmed) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'admin_reports.php';

            const fields = {
                delete_report: '1',
                report_id: button.dataset.id,
                csrf_token: <?= json_encode($_SESSION['csrf_token']) ?>
            };

            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        });
    }

    // =====================================
    // SWEETALERT FLASH NOTIFICATIONS
    // =====================================

    <?php if ($reportMessage !== ''): ?>

        Swal.fire({

            icon: <?= json_encode($reportMessageType === 'success' ? 'success' : 'error') ?>,

            title: <?= json_encode($reportMessageType === 'success' ? 'Success!' : 'Unable to Complete') ?>,

            text: <?= json_encode($reportMessage) ?>,

            confirmButtonColor: '#1d4ed8',

            confirmButtonText: 'OK'

        });

    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>