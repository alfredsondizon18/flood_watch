<?php
require_once __DIR__ . '/config.php';

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
// HELPERS
// =====================================

function alertEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function alertImagePath($filename)
{
    $filename = basename((string) $filename);

    if (
        $filename !== '' &&
        preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/i', $filename)
    ) {
        return 'uploads/flood_reports/' . $filename;
    }

    return '';
}

// =====================================
// SEARCH AND PAGINATION
// =====================================

$search = trim($_GET['search'] ?? '');

$page = max(1, (int) ($_GET['page'] ?? 1));

$limit = 5;
$offset = ($page - 1) * $limit;

$totalReports = 0;
$totalPages = 1;
$reports = [];

// =====================================
// COUNT CRITICAL REPORTS
// =====================================

if ($search !== '') {

    $searchTerm = "%{$search}%";

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM flood_reports fr
        LEFT JOIN users u ON u.id = fr.user_id
        WHERE fr.water_level_status = 'Critical'
        AND fr.responded_at IS NULL
        AND (fr.admin_response IS NULL OR TRIM(fr.admin_response) = '')
        AND (
            fr.location LIKE ?
            OR fr.description LIKE ?
            OR fr.water_level_status LIKE ?
            OR CAST(fr.water_level_cm AS CHAR) LIKE ?
            OR u.full_name LIKE ?
        )
    ");

    $countStmt->bind_param(
        "sssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
} else {

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM flood_reports
        WHERE water_level_status = 'Critical'
        AND responded_at IS NULL
        AND (admin_response IS NULL OR TRIM(admin_response) = '')
    ");
}

if (!$countStmt) {
    die('Unable to prepare critical report count: ' . $conn->error);
}

$countStmt->execute();

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();

$totalReports = (int) ($countRow['total'] ?? 0);

$countStmt->close();

$notificationCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM flood_reports
    WHERE water_level_status = 'Critical'
    AND responded_at IS NULL
    AND (admin_response IS NULL OR TRIM(admin_response) = '')
");

if (!$notificationCountStmt) {
    die('Unable to prepare unresponded critical report count: ' . $conn->error);
}

$notificationCountStmt->execute();
$notificationCountResult = $notificationCountStmt->get_result();
$notificationCriticalCount = (int) (
    $notificationCountResult->fetch_assoc()['total'] ?? 0
);
$notificationCountStmt->close();

$totalPages = max(1, (int) ceil($totalReports / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// =====================================
// FETCH CRITICAL REPORTS
// =====================================

if ($search !== '') {

    $searchTerm = "%{$search}%";

    $stmt = $conn->prepare("
        SELECT
            fr.id,
            fr.user_id,
            fr.location,
            fr.latitude,
            fr.longitude,
            fr.description,
            fr.image,
            fr.water_level_cm,
            fr.water_level_status,
            fr.status,
            fr.admin_response,
            fr.responded_at,
            fr.created_at,
            u.full_name
        FROM flood_reports fr
        LEFT JOIN users u ON u.id = fr.user_id
        WHERE fr.water_level_status = 'Critical'
        AND fr.responded_at IS NULL
        AND (fr.admin_response IS NULL OR TRIM(fr.admin_response) = '')
        AND (
            fr.location LIKE ?
            OR fr.description LIKE ?
            OR fr.water_level_status LIKE ?
            OR CAST(fr.water_level_cm AS CHAR) LIKE ?
            OR u.full_name LIKE ?
        )
        ORDER BY fr.created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param(
        "sssssii",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $limit,
        $offset
    );
} else {

    $stmt = $conn->prepare("
        SELECT
            fr.id,
            fr.user_id,
            fr.location,
            fr.latitude,
            fr.longitude,
            fr.description,
            fr.image,
            fr.water_level_cm,
            fr.water_level_status,
            fr.status,
            fr.admin_response,
            fr.responded_at,
            fr.created_at,
            u.full_name
        FROM flood_reports fr
        LEFT JOIN users u ON u.id = fr.user_id
        WHERE fr.water_level_status = 'Critical'
        AND fr.responded_at IS NULL
        AND (fr.admin_response IS NULL OR TRIM(fr.admin_response) = '')
        ORDER BY fr.created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param("ii", $limit, $offset);
}

if (!$stmt) {
    die('Unable to prepare critical reports: ' . $conn->error);
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

$stmt->close();

// =====================================
// PAGE SETTINGS
// =====================================

$pageTitle = "Emergency Alerts";
$activePage = "emergency_alerts";

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .emergency-page {
        padding: 20px;
    }

    .emergency-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 22px;
    }

    .emergency-heading h1 {
        font-size: 25px;
        font-weight: 700;
        color: #1f2937;
        margin: 0 0 5px;
    }

    .emergency-heading p {
        color: #6b7280;
        font-size: 13px;
        margin: 0;
    }

    .emergency-count {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
        font-weight: 700;
        font-size: 13px;
    }

    .emergency-toolbar {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 16px;
    }

    .emergency-search {
        position: relative;
        width: 100%;
        max-width: 350px;
    }

    .emergency-search input {
        width: 100%;
        height: 42px;
        padding: 0 14px 0 40px;
        border: 1px solid #d1d5db;
        outline: none;
        background: #fff;
        font-size: 13px;
    }

    .emergency-search input:focus {
        border-color: #2563eb;
    }

    .emergency-search span {
        position: absolute;
        left: 13px;
        top: 11px;
        color: #9ca3af;
    }

    .emergency-table-container {
        background: #fff;
        border: 1px solid #e5e7eb;
        overflow-x: auto;
    }

    .emergency-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 750px;
    }

    .emergency-table th {
        background: #f9fafb;
        color: #4b5563;
        padding: 14px 16px;
        font-size: 11px;
        text-align: left;
        text-transform: uppercase;
        letter-spacing: .4px;
        border-bottom: 1px solid #e5e7eb;
    }

    .emergency-table td {
        padding: 15px 16px;
        font-size: 13px;
        color: #374151;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .emergency-table tbody tr:hover {
        background: #fffafa;
    }

    .critical-status {
        display: inline-block;
        padding: 5px 10px;
        background: #fee2e2;
        color: #b91c1c;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .view-alert-btn {
        padding: 7px 13px;
        background: #2563eb;
        border: none;
        color: #fff;
        font-size: 12px;
        cursor: pointer;
    }

    .view-alert-btn:hover {
        background: #1d4ed8;
    }

    .no-alerts {
        padding: 45px 15px !important;
        text-align: center;
        color: #9ca3af !important;
    }

    .emergency-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        padding: 15px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-top: none;
        font-size: 12px;
        color: #6b7280;
    }

    .pagination-links {
        display: flex;
        gap: 5px;
    }

    .pagination-links a,
    .pagination-links span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 9px;
        border: 1px solid #e5e7eb;
        color: #374151;
        text-decoration: none;
    }

    .pagination-links a:hover,
    .pagination-links .current-page {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    /* MODAL */

    .alert-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(15, 23, 42, .65);
        align-items: center;
        justify-content: center;
        padding: 15px;
    }

    .alert-modal.show {
        display: flex;
    }

    .alert-modal-content {
        background: #fff;
        width: 100%;
        max-width: 650px;
        max-height: 90vh;
        overflow-y: auto;
        padding: 25px;
    }

    .alert-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 15px;
        margin-bottom: 20px;
    }

    .alert-modal-header h2 {
        font-size: 19px;
        margin: 0;
        color: #1f2937;
    }

    .alert-close {
        border: none;
        background: none;
        font-size: 25px;
        color: #6b7280;
        cursor: pointer;
    }

    .alert-detail {
        margin-bottom: 16px;
    }

    .alert-detail label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        margin-bottom: 5px;
    }

    .alert-detail p {
        margin: 0;
        font-size: 14px;
        color: #374151;
        overflow-wrap: anywhere;
    }

    .alert-proof {
        width: 100%;
        max-height: 300px;
        object-fit: contain;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
    }

    .enable-sound-note {
        font-size: 11px;
        color: #6b7280;
        margin-top: 5px;
    }

    @media (max-width: 600px) {
        .emergency-page {
            padding: 12px;
        }

        .emergency-heading {
            align-items: flex-start;
        }

        .emergency-search {
            max-width: none;
        }

        .emergency-pagination {
            align-items: flex-start;
        }
    }

/* ===== Minimalist emergency alerts UI refresh ===== */
.emergency-page {
    max-width: 1240px;
    margin: 0 auto;
    padding: 28px 30px 40px;
    color: #172033;
}
.emergency-heading {
    margin-bottom: 24px;
}
.emergency-heading h1 {
    color: #172033;
    font-size: 25px;
    font-weight: 700;
    letter-spacing: -.65px;
}
.emergency-heading p {
    color: #778196;
    font-size: 13px;
}
.emergency-count {
    gap: 8px;
    padding: 10px 14px;
    border: 1px solid #f2d4d2;
    border-radius: 7px;
    background: #fff6f5;
    color: #ad4944;
    font-size: 12px;
    font-weight: 650;
}
.emergency-table-container {
    overflow: hidden;
    border: 1px solid #e7ebf1;
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(22,34,55,.025);
}
.emergency-table-toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid #edf0f4;
    background: #fff;
}
.emergency-table-toolbar .emergency-search {
    width: 100%;
    max-width: 360px;
}
.emergency-table-toolbar .emergency-search input {
    height: 39px;
    border: 1px solid #e1e6ee;
    border-radius: 7px;
    background: #fbfcfe;
    color: #263247;
    transition: border-color .18s ease, box-shadow .18s ease;
}
.emergency-table-toolbar .emergency-search input:focus {
    border-color: #8294b3;
    box-shadow: 0 0 0 3px rgba(38,59,99,.08);
    outline: none;
}
.emergency-table-toolbar .emergency-search span {
    top: 9px;
    color: #8b95a5;
    font-size: 18px;
    line-height: 1;
}
.emergency-table {
    min-width: 900px;
}
.emergency-table th {
    padding: 14px 18px;
    background: #f8f9fb;
    color: #7a8495;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .65px;
    border-bottom: 1px solid #e9edf2;
}
.emergency-table td {
    padding: 16px 18px;
    color: #4a5568;
    font-size: 13px;
    border-bottom: 1px solid #f0f2f5;
}
.emergency-table tbody tr:last-child td { border-bottom: 0; }
.emergency-table tbody tr:hover { background: #fbfcfe; }
.emergency-table td strong {
    color: #263247;
    font-weight: 650;
}
.critical-status {
    padding: 5px 9px;
    border-radius: 20px;
    background: #fff0ef;
    color: #b54742;
    font-size: 10px;
    font-weight: 700;
}
.response-status {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}
.response-status.responded {
    background: #eaf7ef;
    color: #28734a;
}
.response-status.pending {
    background: #fff5e8;
    color: #a45b08;
}
.view-alert-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    padding: 0;
    border: 1px solid #dbeafe;
    border-radius: 3px;
    background: #eff6ff;
    color: #1d4ed8;
    cursor: pointer;
    transition: .2s ease;
}
.view-alert-btn:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
}
.view-alert-btn:active {
    transform: translateY(1px);
}
.view-alert-btn:focus-visible,
.alert-close:focus-visible {
    outline: 3px solid rgb(59 130 246 / 30%);
    outline-offset: 2px;
}
.view-alert-btn svg {
    display: block;
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.emergency-pagination {
    padding: 14px 17px;
    border: 1px solid #e7ebf1;
    border-top: 0;
    border-radius: 0 0 9px 9px;
    background: #fff;
    color: #7a8495;
}
.pagination-links a,
.pagination-links span {
    min-width: 33px;
    height: 33px;
    border-radius: 6px;
    border-color: #e5e9ef;
    color: #536075;
}
.pagination-links a:hover,
.pagination-links .current-page {
    background: #263b63;
    border-color: #263b63;
    color: #fff;
}
.alert-modal {
    background: rgba(17, 27, 44, .48);
    backdrop-filter: blur(3px);
}
.alert-modal-content {
    max-width: 650px;
    padding: 25px;
    border: 1px solid rgba(230,234,241,.9);
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(15,23,42,.18);
}
.alert-modal-header {
    align-items: flex-start;
    border-bottom-color: #edf0f4;
}
.alert-modal-header h2 {
    color: #202c40;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -.3px;
}
.alert-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: #f4f6f8;
    color: #5d6879;
    font-size: 22px;
}
.alert-close {
    transition: background .16s ease, color .16s ease, transform .16s ease;
}
.alert-close:hover { background: #e9edf2; color: #202c40; transform: translateY(-1px); }
.alert-close:active { transform: translateY(0); }
.alert-detail label {
    color: #8791a1;
    font-size: 10px;
    letter-spacing: .5px;
}
.alert-detail p {
    color: #3f4b5f;
    line-height: 1.6;
}
.alert-proof {
    border: 1px solid #e7ebf1;
    border-radius: 7px;
}
@media (max-width: 600px) {
    .emergency-page { padding: 18px 14px 28px; }
    .emergency-table-toolbar {
        justify-content: stretch;
        padding: 12px;
    }
    .emergency-table-toolbar .emergency-search { max-width: none; }
    .emergency-modal-content { padding: 19px; }
}

</style>

<div class="emergency-page">

    <div class="emergency-heading">
        <div>
           
        </div>

        <div class="emergency-count">
            <span>⚠</span>
            <span id="criticalTotal">
                <?= number_format($totalReports) ?>
            </span>
            Critical Reports
        </div>
    </div>

    <div class="emergency-table-container">

        <div class="emergency-table-toolbar">
            <div class="emergency-search">
                <span aria-hidden="true">⌕</span>
                <input
                    type="search"
                    id="alertSearch"
                    placeholder="Search resident, location, description or water level..."
                    value="<?= alertEscape($search) ?>"
                    autocomplete="off"
                    aria-label="Search emergency alerts"
                    data-live-search
                    data-live-search-targets="#emergencyTableBody|#emergencyPagination">
            </div>
        </div>

        <table class="emergency-table">
            <thead>
                <tr>
                    <th>Resident</th>
                    <th>Location</th>
                    <th>Water Level</th>
                    <th>Date Submitted</th>
                    <th>Admin Response</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody id="emergencyTableBody">

                <?php if (count($reports) > 0): ?>

                    <?php foreach ($reports as $report): ?>

                        <?php
                        $imagePath = alertImagePath($report['image'] ?? '');
                        $hasAdminResponse = !empty($report['responded_at'])
                            || trim((string) ($report['admin_response'] ?? '')) !== '';
                        ?>

                        <tr>
                            <td>
                                <?= alertEscape($report['full_name'] ?? 'Unknown Resident') ?>
                            </td>

                            <td>
                                <?= alertEscape($report['location']) ?>
                            </td>

                            <td>
                                <span class="critical-status">
                                    <?= alertEscape(number_format((float) $report['water_level_cm'], 2)) ?> cm
                                    (<?= alertEscape($report['water_level_status']) ?>)
                                </span>
                            </td>

                            <td>
                                <?= alertEscape(date(
                                    'M d, Y h:i A',
                                    strtotime($report['created_at'])
                                )) ?>
                            </td>

                            <td>
                                <span class="response-status <?= $hasAdminResponse ? 'responded' : 'pending' ?>">
                                    <?= $hasAdminResponse ? 'Responded' : 'Pending' ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="view-alert-btn"
                                    onclick="openAlertModal(this)"

                                    data-id="<?= (int) $report['id'] ?>"
                                    data-resident="<?= alertEscape($report['full_name'] ?? 'Unknown Resident') ?>"
                                    data-location="<?= alertEscape($report['location']) ?>"
                                    data-description="<?= alertEscape($report['description']) ?>"
                                    data-water-level="<?= alertEscape(number_format((float) $report['water_level_cm'], 2)) ?>"
                                    data-water-status="<?= alertEscape($report['water_level_status']) ?>"
                                    data-latitude="<?= alertEscape($report['latitude']) ?>"
                                    data-longitude="<?= alertEscape($report['longitude']) ?>"
                                    data-image="<?= alertEscape($imagePath) ?>"
                                    data-date="<?= alertEscape(date(
                                                    'M d, Y h:i A',
                                                    strtotime($report['created_at'])
                                                )) ?>"
                                    data-response="<?= alertEscape($report['admin_response'] ?? '') ?>"
                                    aria-label="View report"
                                    title="View report">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path>
                                        <circle cx="12" cy="12" r="2.7"></circle>
                                    </svg>
                                </button>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="no-alerts">
                            <?php if ($search !== ''): ?>
                                No critical reports matched your search.
                            <?php else: ?>
                                No critical flood reports at the moment.
                            <?php endif; ?>
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>

    </div>

    <div class="emergency-pagination" id="emergencyPagination">

        <div>
            Showing
            <?= $totalReports > 0 ? $offset + 1 : 0 ?>
            to
            <?= min($offset + $limit, $totalReports) ?>
            of
            <?= number_format($totalReports) ?>
            critical reports
        </div>

        <div class="pagination-links">

            <?php
            $queryString = $search !== ''
                ? '&search=' . urlencode($search)
                : '';
            ?>

            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $queryString ?>">
                    Previous
                </a>
            <?php endif; ?>

            <?php for (
                $i = max(1, $page - 2);
                $i <= min($totalPages, $page + 2);
                $i++
            ): ?>

                <?php if ($i === $page): ?>
                    <span class="current-page"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?><?= $queryString ?>">
                        <?= $i ?>
                    </a>
                <?php endif; ?>

            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $queryString ?>">
                    Next
                </a>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- =====================================
     VIEW REPORT MODAL
====================================== -->

<div class="alert-modal" id="alertModal">

    <div class="alert-modal-content">

        <div class="alert-modal-header">
            <h2>Critical Flood Report</h2>

            <button
                type="button"
                class="alert-close"
                onclick="closeAlertModal()">
                &times;
            </button>
        </div>

        <div class="alert-detail">
            <label>Report ID</label>
            <p id="modalReportId"></p>
        </div>

        <div class="alert-detail">
            <label>Resident</label>
            <p id="modalResident"></p>
        </div>

        <div class="alert-detail">
            <label>Location</label>
            <p id="modalLocation"></p>
        </div>

        <div class="alert-detail">
            <label>Water Level</label>
            <p id="modalWaterLevel"></p>
        </div>

        <div class="alert-detail">
            <label>Description / Concern</label>
            <p id="modalDescription"></p>
        </div>

        <div class="alert-detail">
            <label>GPS Coordinates</label>
            <p id="modalCoordinates"></p>
            <a
                href="#"
                id="modalMapLink"
                target="_blank"
                rel="noopener"
                style="display:none; font-size:12px; margin-top:7px;">
                View on Google Maps
            </a>
        </div>

        <div class="alert-detail">
            <label>Image Proof</label>

            <img
                id="modalImage"
                class="alert-proof"
                src=""
                alt="Resident's flood report image"
                style="display:none;">

            <p id="noModalImage" style="display:none;">
                No image proof was attached.
            </p>
        </div>

        <div class="alert-detail">
            <label>Date Submitted</label>
            <p id="modalDate"></p>
        </div>

        <div class="alert-detail">
            <label>Admin Response</label>
            <p id="modalResponse"></p>
        </div>

    </div>
</div>

<script>
    // =====================================
    // VIEW REPORT MODAL
    // =====================================

    function openAlertModal(button) {

        const data = button.dataset;

        document.getElementById('modalReportId').textContent =
            '#' + data.id;

        document.getElementById('modalResident').textContent =
            data.resident || 'Unknown Resident';

        document.getElementById('modalLocation').textContent =
            data.location || 'Not provided';

        document.getElementById('modalWaterLevel').textContent =
            (data.waterLevel || '0.00') + ' cm (' + (data.waterStatus || 'Critical') + ')';

        document.getElementById('modalDescription').textContent =
            data.description || 'No description provided';

        document.getElementById('modalDate').textContent =
            data.date || 'Not available';

        document.getElementById('modalResponse').textContent =
            data.response || 'No admin response yet.';

        const latitude = data.latitude;
        const longitude = data.longitude;

        const coordinates = document.getElementById('modalCoordinates');
        const mapLink = document.getElementById('modalMapLink');

        if (
            latitude &&
            longitude &&
            Number.isFinite(Number(latitude)) &&
            Number.isFinite(Number(longitude))
        ) {
            coordinates.textContent = latitude + ', ' + longitude;

            mapLink.href =
                'https://www.google.com/maps?q=' +
                encodeURIComponent(latitude + ',' + longitude);

            mapLink.style.display = 'inline-block';

        } else {
            coordinates.textContent = 'GPS coordinates unavailable.';
            mapLink.style.display = 'none';
        }

        const image = document.getElementById('modalImage');
        const noImage = document.getElementById('noModalImage');

        if (data.image) {
            image.src = data.image;
            image.style.display = 'block';
            noImage.style.display = 'none';
        } else {
            image.removeAttribute('src');
            image.style.display = 'none';
            noImage.style.display = 'block';
        }

        document.getElementById('alertModal').classList.add('show');
    }

    function closeAlertModal() {
        document.getElementById('alertModal').classList.remove('show');
    }

    document.getElementById('alertModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAlertModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAlertModal();
        }
    });

    // =====================================
    // ALERT SOUND
    // =====================================

    const alertAudio = new Audio('alert.mp3');
    alertAudio.preload = 'auto';
    alertAudio.volume = 1;
    function syncAlertSoundModal(playing) {
        window.floodAlertSoundPlaying = playing;
        window.dispatchEvent(new CustomEvent('flood-alert-sound-state', {
            detail: { playing }
        }));
    }

    alertAudio.addEventListener('play', () => syncAlertSoundModal(true));
    alertAudio.addEventListener('pause', () => syncAlertSoundModal(false));
    alertAudio.addEventListener('ended', () => syncAlertSoundModal(false));
    alertAudio.addEventListener('error', () => syncAlertSoundModal(false));

    let alertSoundEnabled = localStorage.getItem('floodAlertSound') === 'enabled';
    let activeCriticalAlertCount = <?= $notificationCriticalCount ?>;

    const soundToggle = document.getElementById('soundToggle');

    function updateSoundButton() {
        const label = alertSoundEnabled
            ? activeCriticalAlertCount > 0 && alertAudio.paused
                ? 'Click to enable alert sound'
                : 'Disable alert sound'
            : 'Enable alert sound';

        soundToggle.setAttribute('aria-label', label);
        soundToggle.setAttribute('title', label);
        soundToggle.setAttribute('aria-pressed', String(alertSoundEnabled));
        soundToggle.classList.toggle('enabled', alertSoundEnabled);
    }

    function showSoundButtonMessage(message) {
        soundToggle.setAttribute('aria-label', message);
        soundToggle.setAttribute('title', message);
    }

    function stopAlertSound() {
        alertAudio.pause();
        alertAudio.currentTime = 0;
        alertAudio.loop = false;
    }

    async function playAlertSound(loop = false) {
        if (!alertSoundEnabled && loop) return false;
        alertAudio.loop = loop;
        if (!alertAudio.paused) return true;
        alertAudio.currentTime = 0;
        try {
            await alertAudio.play();
            return true;
        } catch (error) {
            console.error('Alert sound error:', error);
            return false;
        }
    }

    soundToggle.addEventListener('click', async function() {
        if (alertSoundEnabled && activeCriticalAlertCount > 0 && alertAudio.paused) {
            await playAlertSound(true);
            updateSoundButton();
            return;
        }

        alertSoundEnabled = !alertSoundEnabled;

        localStorage.setItem(
            'floodAlertSound',
            alertSoundEnabled ? 'enabled' : 'disabled'
        );

        if (alertSoundEnabled) {
            const played = await playAlertSound(activeCriticalAlertCount > 0);
            if (!played && activeCriticalAlertCount > 0) {
                showSoundButtonMessage('Sound blocked by browser');
                return;
            }
        } else {
            stopAlertSound();
        }

        updateSoundButton();
    });

    updateSoundButton();
    if (activeCriticalAlertCount > 0 && alertSoundEnabled) {
        playAlertSound(true).then(played => {
            if (!played) {
                showSoundButtonMessage('Click to enable alert sound');
            }
        });
    }

    // =====================================
    // CHECK FOR NEW CRITICAL REPORTS
    // =====================================

    let previousCriticalCount = <?= $notificationCriticalCount ?>;
    let checkingNewAlerts = false;

    function checkNewCriticalReports() {

        if (checkingNewAlerts) return;

        checkingNewAlerts = true;

        fetch('emergency_alerts_count.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Unable to check alerts');
                }

                return response.json();
            })
            .then(data => {

                const newCount = Number(data.count) || 0;
                if (newCount < previousCriticalCount) {
                    window.location.reload();
                    return;
                }

                activeCriticalAlertCount = newCount;

                document.getElementById('criticalTotal').textContent =
                    newCount.toLocaleString();

                if (newCount > previousCriticalCount) {
                    playAlertSound(true).then(played => {
                        if (!played && alertSoundEnabled) {
                            showSoundButtonMessage('Click to enable alert sound');
                        }
                    });
                } else if (newCount === 0) {
                    stopAlertSound();
                }

                previousCriticalCount = newCount;
            })
            .catch(error => {
                console.error('Critical alert checking error:', error);
            })
            .finally(() => {
                checkingNewAlerts = false;
            });
    }

    setInterval(checkNewCriticalReports, 10000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>