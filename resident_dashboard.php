<?php
require_once 'config.php';
require_once __DIR__ . '/includes/resident_activity.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'resident') {
    header("Location: admin_dashboard.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$residentName = (string) ($_SESSION['full_name'] ?? 'Resident');

if (!residentActivityEnsureTable($conn)) {
    die('Unable to load resident activity. Please check the server error log.');
}

$activitySearch = trim($_GET['search'] ?? '');
$activityPage = max(1, (int) ($_GET['page'] ?? 1));
$activityLimit = 5;
$activityTerm = '%' . $activitySearch . '%';

$activityCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM resident_activity
    WHERE resident_id = ?
      AND (action LIKE ? OR resident_name LIKE ?)
");
if (!$activityCountStmt) {
    die('Unable to prepare resident activity count: ' . $conn->error);
}
$activityCountStmt->bind_param('iss', $userId, $activityTerm, $activityTerm);
$activityCountStmt->execute();
$activityTotal = (int) $activityCountStmt->get_result()->fetch_assoc()['total'];
$activityCountStmt->close();

$activityTotalPages = max(1, (int) ceil($activityTotal / $activityLimit));
$activityPage = min($activityPage, $activityTotalPages);
$activityOffset = ($activityPage - 1) * $activityLimit;

$activityStmt = $conn->prepare("
    SELECT resident_name, action, created_at
    FROM resident_activity
    WHERE resident_id = ?
      AND (action LIKE ? OR resident_name LIKE ?)
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
");
if (!$activityStmt) {
    die('Unable to prepare resident activity list: ' . $conn->error);
}
$activityStmt->bind_param(
    'issii',
    $userId,
    $activityTerm,
    $activityTerm,
    $activityLimit,
    $activityOffset
);
$activityStmt->execute();
$activityResult = $activityStmt->get_result();
$residentActivities = [];
while ($activity = $activityResult->fetch_assoc()) {
    $residentActivities[] = $activity;
}
$activityStmt->close();

$activityShowingFrom = $activityTotal > 0 ? $activityOffset + 1 : 0;
$activityShowingTo = min($activityOffset + $activityLimit, $activityTotal);
$residentReportCount = 0;
$reportTable = $conn->query("SHOW TABLES LIKE 'flood_reports'");
if (!$reportTable) {
    die('Unable to check flood report data: ' . $conn->error);
}

if ($reportTable->num_rows > 0) {
    $reportStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM flood_reports
        WHERE user_id = ?
    ");
    if (!$reportStmt) {
        die('Unable to prepare resident report count: ' . $conn->error);
    }

    $reportStmt->bind_param('i', $userId);
    if (!$reportStmt->execute()) {
        die('Unable to load resident report count: ' . $reportStmt->error);
    }
    $residentReportCount = (int) $reportStmt->get_result()->fetch_assoc()['total'];
    $reportStmt->close();
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

$pageTitle = "Resident Dashboard";
$activePage = "dashboard";

require_once 'includes/header.php';
?>

<section class="page-heading">
   
</section>

<section class="welcome-banner">
    <span class="welcome-tag">RESIDENT PORTAL</span>

    <h2>
        Hello, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Resident') ?>!
    </h2>

    <p>
        Welcome to FloodWatch. Access flood updates, review emergency
        announcements, and help your community by reporting flooding
        incidents in your area.
    </p>
</section>

<section class="dashboard-grid">


    <div class="dashboard-card">
        <div class="card-top">
            <h3>Emergency Announcements</h3>
            <div class="card-icon icon-red">!</div>
        </div>
        <div class="card-value"><?= number_format($announcementCount) ?></div>
        <p class="card-description">Published announcements from administrators</p>
    </div>

    <div class="dashboard-card">
        <div class="card-top">
            <h3>My Reports</h3>
            <div class="card-icon icon-orange">⚑</div>
        </div>
        <div class="card-value"><?= number_format($residentReportCount) ?></div>
        <p class="card-description">Your submitted flood reports</p>
    </div>

</section>

<style>
.resident-history {
    margin: 0 0 22px;
    overflow: hidden;
    border: 1px solid #e9edf3;
    border-radius: 7px;
    background: #fff;
}
.resident-history-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 22px;
    border-bottom: 1px solid #edf0f4;
}
.resident-history-heading h2 {
    margin: 0 0 5px;
    color: #263445;
    font-size: 15px;
    font-weight: 700;
}
.resident-history-heading p {
    margin: 0;
    color: #8a96a6;
    font-size: 12px;
}
.resident-history-heading strong {
    color: #2563eb;
}
.resident-history-search {
    width: 100%;
    max-width: 320px;
}
.resident-history-search input {
    box-sizing: border-box;
    width: 100%;
    height: 39px;
    padding: 0 12px;
    border: 1px solid #e1e6ee;
    border-radius: 5px;
    font-size: 12px;
}
.resident-history-table-wrap {
    overflow-x: auto;
}
.resident-history-table {
    width: 100%;
    min-width: 500px;
    border-collapse: collapse;
}
.resident-history-table th {
    padding: 13px 20px;
    border-bottom: 1px solid #edf0f4;
    background: #f8fafc;
    color: #8491a3;
    font-size: 10px;
    letter-spacing: .7px;
    text-align: left;
}
.resident-history-table td {
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f6;
    color: #526174;
    font-size: 12px;
}
.resident-history-table tbody tr:last-child td {
    border-bottom: 0;
}
.resident-history-empty {
    padding: 36px 20px !important;
    color: #94a3b8 !important;
    text-align: center;
}
.resident-history-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 20px;
    border-top: 1px solid #edf0f4;
    color: #94a3b8;
    font-size: 11px;
}
.resident-history-pagination {
    display: flex;
    align-items: center;
    gap: 5px;
}
.resident-history-pagination a,
.resident-history-pagination span {
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
.resident-history-pagination a:hover {
    border-color: #2563eb;
    background: #eff6ff;
    color: #2563eb;
}
.resident-history-pagination .current {
    border-color: #2563eb;
    background: #2563eb;
    color: #fff;
}
@media (max-width: 700px) {
    .resident-history-toolbar,
    .resident-history-footer {
        align-items: flex-start;
        flex-direction: column;
    }
    .resident-history-search {
        max-width: none;
    }
    .resident-history-pagination {
        flex-wrap: wrap;
    }
}
</style>

<section class="resident-history">
    <div class="resident-history-toolbar">
        <div class="resident-history-heading">
            <h2>Login &amp; Logout History</h2>
            <p><strong><?= number_format($activityTotal) ?></strong> account events</p>
        </div>

        <form class="resident-history-search" method="GET" role="search">
            <input
                type="search"
                name="search"
                placeholder="Search history..."
                value="<?= htmlspecialchars($activitySearch, ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Search login and logout history"
            >
        </form>
    </div>

    <div class="resident-history-table-wrap">
        <table class="resident-history-table">
            <thead>
                <tr>
                    <th>RESIDENT</th>
                    <th>ACTIVITY</th>
                    <th>DATE &amp; TIME</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($residentActivities): ?>
                    <?php foreach ($residentActivities as $activity): ?>
                        <tr>
                            <td><?= htmlspecialchars($activity['resident_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($activity['action'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($activity['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="resident-history-empty">
                            <?= $activitySearch !== ''
                                ? 'No matching login or logout history found.'
                                : 'No login or logout history has been recorded yet.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="resident-history-footer">
        <div>
            Showing <?= $activityShowingFrom ?>–<?= $activityShowingTo ?>
            of <?= number_format($activityTotal) ?> events
        </div>

        <?php if ($activityTotalPages > 1): ?>
            <?php
            $activitySearchQuery = $activitySearch !== ''
                ? '&search=' . urlencode($activitySearch)
                : '';
            ?>
            <nav class="resident-history-pagination" aria-label="Login history pages">
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