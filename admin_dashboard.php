<?php
require_once 'config.php';
require_once __DIR__ . '/includes/admin_activity.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: resident_dashboard.php");
    exit;
}

if (!adminActivityEnsureTable($conn)) {
    die('Unable to load admin activity. Please check the server error log.');
}

$activitySearch = trim($_GET['search'] ?? '');
$activityPage = max(1, (int) ($_GET['page'] ?? 1));
$activityLimit = 5;
$activityOffset = 0;
$activityTotal = 0;
$activityTotalPages = 1;
$activities = [];
$activityTerm = '%' . $activitySearch . '%';

$activityCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM admin_activity
    WHERE action <> 'Viewed page'
    AND (admin_name LIKE ? OR action LIKE ? OR details LIKE ?)
");
if (!$activityCountStmt) {
    die('Unable to prepare admin activity count: ' . $conn->error);
}
$activityCountStmt->bind_param(
    'sss',
    $activityTerm,
    $activityTerm,
    $activityTerm
);
$activityCountStmt->execute();
$activityTotal = (int) $activityCountStmt->get_result()->fetch_assoc()['total'];
$activityCountStmt->close();

$activityTotalPages = max(1, (int) ceil($activityTotal / $activityLimit));
$activityPage = min($activityPage, $activityTotalPages);
$activityOffset = ($activityPage - 1) * $activityLimit;

$activityStmt = $conn->prepare("
    SELECT admin_name, action, details, created_at
    FROM admin_activity
    WHERE action <> 'Viewed page'
    AND (admin_name LIKE ? OR action LIKE ? OR details LIKE ?)
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
");
if (!$activityStmt) {
    die('Unable to prepare admin activity list: ' . $conn->error);
}
$activityStmt->bind_param(
    'sssii',
    $activityTerm,
    $activityTerm,
    $activityTerm,
    $activityLimit,
    $activityOffset
);
$activityStmt->execute();
$activityResult = $activityStmt->get_result();
while ($activity = $activityResult->fetch_assoc()) {
    $activities[] = $activity;
}
$activityStmt->close();

$activityShowingFrom = $activityTotal > 0 ? $activityOffset + 1 : 0;
$activityShowingTo = min($activityOffset + $activityLimit, $activityTotal);

$residentResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'resident'
");
if (!$residentResult) {
    die('Unable to load resident count: ' . $conn->error);
}
$totalResidents = (int) $residentResult->fetch_assoc()['total'];

$floodReportCount = 0;
$activeAlertCount = 0;
$reportTable = $conn->query("SHOW TABLES LIKE 'flood_reports'");
if (!$reportTable) {
    die('Unable to check flood report data: ' . $conn->error);
}

if ($reportTable->num_rows > 0) {
    $reportResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM flood_reports
    ");
    if (!$reportResult) {
        die('Unable to load flood report count: ' . $conn->error);
    }
    $floodReportCount = (int) $reportResult->fetch_assoc()['total'];

    $reportColumns = $conn->query('SHOW COLUMNS FROM flood_reports');
    if (!$reportColumns) {
        die('Unable to check flood report fields: ' . $conn->error);
    }

    $existingReportColumns = [];
    while ($column = $reportColumns->fetch_assoc()) {
        $existingReportColumns[] = $column['Field'];
    }

    if (
        in_array('water_level_status', $existingReportColumns, true) &&
        in_array('responded_at', $existingReportColumns, true) &&
        in_array('admin_response', $existingReportColumns, true)
    ) {
        $alertResult = $conn->query("
            SELECT COUNT(*) AS total
            FROM flood_reports
            WHERE water_level_status = 'Critical'
            AND responded_at IS NULL
            AND (admin_response IS NULL OR TRIM(admin_response) = '')
        ");
        if (!$alertResult) {
            die('Unable to load active alert count: ' . $conn->error);
        }
        $activeAlertCount = (int) $alertResult->fetch_assoc()['total'];
    }
}

$announcementCount = 0;
$announcementTable = $conn->query("SHOW TABLES LIKE 'emergency_announcements'");
if (!$announcementTable) {
    die('Unable to check emergency announcement data: ' . $conn->error);
}

if ($announcementTable->num_rows > 0) {
    $announcementResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM emergency_announcements
    ");
    if (!$announcementResult) {
        die('Unable to load emergency announcement count: ' . $conn->error);
    }
    $announcementCount = (int) $announcementResult->fetch_assoc()['total'];
}

$pageTitle = "Admin Dashboard";
$activePage = "dashboard";
$playAdminLoginCriticalSound = !empty($_SESSION['admin_login_alert_sound']);
unset($_SESSION['admin_login_alert_sound']);

require_once 'includes/header.php';
?>

<section class="page-heading">
 
</section>

<section class="welcome-banner">
    <span class="welcome-tag">ADMINISTRATOR PANEL</span>

    <h2>
        Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Administrator') ?>!
    </h2>

    <p>
        Manage community flood monitoring, review resident reports,
        and provide timely emergency announcements through FloodWatch.
    </p>
</section>

<section class="dashboard-grid">

    <div class="dashboard-card">
        <div class="card-top">
            <h3>Total Residents</h3>
            <div class="card-icon icon-blue">♙</div>
        </div>
        <div class="card-value"><?= number_format($totalResidents) ?></div>
        <p class="card-description">Registered resident accounts</p>
    </div>

    <div class="dashboard-card">
        <div class="card-top">
            <h3>Flood Reports</h3>
            <div class="card-icon icon-orange">⚑</div>
        </div>
        <div class="card-value"><?= number_format($floodReportCount) ?></div>
        <p class="card-description">Reports submitted by residents</p>
    </div>

    <div class="dashboard-card">
        <div class="card-top">
            <h3>Active Alerts</h3>
            <div class="card-icon icon-red">!</div>
        </div>
        <div class="card-value"><?= number_format($activeAlertCount) ?></div>
        <p class="card-description">Unresponded critical flood reports</p>
    </div>

    <div class="dashboard-card">
        <div class="card-top">
            <h3>Emergency Announcements</h3>
            <div class="card-icon icon-green">⌖</div>
        </div>
        <div class="card-value"><?= number_format($announcementCount) ?></div>
        <p class="card-description">Published announcements for residents</p>
    </div>

</section>

<style>
.admin-activity {
    margin-top: 24px;
    overflow: hidden;
    border: 1px solid #e9edf3;
    border-radius: 8px;
    background: #fff;
}
.admin-activity-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 22px;
    border-bottom: 1px solid #edf0f4;
}
.admin-activity-heading h2 {
    margin: 0 0 5px;
    color: #263445;
    font-size: 15px;
    font-weight: 700;
}
.admin-activity-heading p {
    margin: 0;
    color: #8a96a6;
    font-size: 12px;
}
.admin-activity-heading strong {
    color: #2563eb;
}
.admin-activity-search {
    width: 100%;
    max-width: 330px;
}
.admin-activity-search input {
    box-sizing: border-box;
    width: 100%;
    height: 39px;
    padding: 0 12px;
    border: 1px solid #e1e6ee;
    border-radius: 6px;
    color: #263445;
    font-size: 12px;
}
.admin-activity-table-wrap {
    overflow-x: auto;
}
.admin-activity-table {
    width: 100%;
    min-width: 700px;
    border-collapse: collapse;
}
.admin-activity-table th {
    padding: 13px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #edf0f4;
    color: #8491a3;
    font-size: 10px;
    letter-spacing: .7px;
    text-align: left;
    text-transform: uppercase;
}
.admin-activity-table td {
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f6;
    color: #526174;
    font-size: 12px;
    vertical-align: middle;
}
.admin-activity-table tbody tr:last-child td {
    border-bottom: 0;
}
.admin-activity-table td strong {
    color: #334155;
}
.admin-activity-empty {
    padding: 38px 20px !important;
    color: #94a3b8 !important;
    text-align: center;
}
.admin-activity-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 20px;
    border-top: 1px solid #edf0f4;
    color: #94a3b8;
    font-size: 11px;
}
.admin-activity-pagination {
    display: flex;
    align-items: center;
    gap: 5px;
}
.admin-activity-pagination a,
.admin-activity-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 31px;
    height: 31px;
    padding: 0 9px;
    border: 1px solid #e5eaf0;
    border-radius: 5px;
    color: #64748b;
    font-size: 11px;
    text-decoration: none;
}
.admin-activity-pagination a:hover {
    border-color: #2563eb;
    background: #eff6ff;
    color: #2563eb;
}
.admin-activity-pagination .current {
    border-color: #2563eb;
    background: #2563eb;
    color: #fff;
}
@media (max-width: 700px) {
    .admin-activity-toolbar,
    .admin-activity-footer {
        align-items: flex-start;
        flex-direction: column;
    }
    .admin-activity-search {
        max-width: none;
    }
    .admin-activity-pagination {
        flex-wrap: wrap;
    }
}
</style>

<section class="admin-activity">
    <div class="admin-activity-toolbar">
        <div class="admin-activity-heading">
            <h2>Admin Activity</h2>
            <p><strong><?= number_format($activityTotal) ?></strong> recorded actions</p>
        </div>

        <form class="admin-activity-search" method="GET" role="search">
            <input
                type="search"
                name="search"
                placeholder="Search admin activity..."
                value="<?= htmlspecialchars($activitySearch, ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Search admin activity"
            >
        </form>
    </div>

    <div class="admin-activity-table-wrap">
        <table class="admin-activity-table">
            <thead>
                <tr>
                    <th>Administrator</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($activities): ?>
                    <?php foreach ($activities as $activity): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($activity['admin_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= htmlspecialchars($activity['action'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($activity['details'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($activity['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="admin-activity-empty">
                            <?= $activitySearch !== ''
                                ? 'No matching admin activity found.'
                                : 'No admin activity has been recorded yet.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="admin-activity-footer">
        <div>
            Showing <?= $activityShowingFrom ?>–<?= $activityShowingTo ?>
            of <?= number_format($activityTotal) ?> activities
        </div>

        <?php if ($activityTotalPages > 1): ?>
            <?php
            $activitySearchQuery = $activitySearch !== ''
                ? '&search=' . urlencode($activitySearch)
                : '';
            ?>
            <nav class="admin-activity-pagination" aria-label="Admin activity pages">
                <?php if ($activityPage > 1): ?>
                    <a href="?page=<?= $activityPage - 1 ?><?= $activitySearchQuery ?>">Previous</a>
                <?php endif; ?>

                <?php for (
                    $i = max(1, $activityPage - 2);
                    $i <= min($activityTotalPages, $activityPage + 2);
                    $i++
                ): ?>
                    <?php if ($i === $activityPage): ?>
                        <span class="current" aria-current="page"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?><?= $activitySearchQuery ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($activityPage < $activityTotalPages): ?>
                    <a href="?page=<?= $activityPage + 1 ?><?= $activitySearchQuery ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>