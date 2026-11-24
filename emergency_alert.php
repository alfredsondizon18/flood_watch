
<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// RESIDENT AUTHENTICATION

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'resident') {
    header('Location: admin_dashboard.php');
    exit;
}

function residentAnnouncementEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// SEARCH AND PAGINATION

$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$limit = 5;
$offset = ($page - 1) * $limit;

$totalAnnouncements = 0;
$totalPages = 1;
$announcements = [];

// COUNT ANNOUNCEMENTS

if ($search !== '') {

    $term = "%{$search}%";

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM emergency_announcements
        WHERE title LIKE ? OR message LIKE ?
    ");

    $countStmt->bind_param("ss", $term, $term);

} else {

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM emergency_announcements
    ");
}

$countStmt->execute();

$countResult = $countStmt->get_result();
$totalAnnouncements = (int) $countResult->fetch_assoc()['total'];

$countStmt->close();

$totalPages = max(1, (int) ceil($totalAnnouncements / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// FETCH ANNOUNCEMENTS

if ($search !== '') {

    $term = "%{$search}%";

    $stmt = $conn->prepare("
        SELECT id, title, message, created_at
        FROM emergency_announcements
        WHERE title LIKE ? OR message LIKE ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param("ssii", $term, $term, $limit, $offset);

} else {

    $stmt = $conn->prepare("
        SELECT id, title, message, created_at
        FROM emergency_announcements
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}

$stmt->close();

$pageTitle = 'Emergency Alerts';
$activePage = 'emergency_alert';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.resident-announcement-page {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 28px;
    color: #263449;
}

.resident-announcement-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 24px;
}

.resident-announcement-eyebrow {
    display: block;
    margin-bottom: 8px;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.2px;
}

.resident-announcement-heading h1 {
    margin: 0 0 7px;
    color: #1e293b;
    font-size: 26px;
    font-weight: 700;
}

.resident-announcement-heading p {
    margin: 0;
    color: #8491a3;
    font-size: 13px;
    line-height: 1.6;
}

.resident-announcement-count {
    flex: 0 0 auto;
    color: #64748b;
    font-size: 12px;
}

.resident-announcement-count strong {
    margin-right: 5px;
    color: #1d4ed8;
    font-size: 21px;
}

.resident-announcement-section {
    padding: 24px;
    border: 1px solid #e5eaf0;
    background: #fff;
}

.resident-announcement-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.resident-announcement-section-heading h2 {
    margin: 0 0 5px;
    color: #263449;
    font-size: 17px;
    font-weight: 700;
}

.resident-announcement-section-heading p {
    margin: 0;
    color: #8793a4;
    font-size: 12px;
}

.resident-announcement-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 16px;
}

.resident-announcement-search {
    position: relative;
    width: 100%;
    max-width: 350px;
}

.resident-announcement-search span {
    position: absolute;
    top: 50%;
    left: 13px;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 19px;
    pointer-events: none;
}

.resident-announcement-search input {
    box-sizing: border-box;
    width: 100%;
    height: 40px;
    padding: 0 12px 0 38px;
    border: 1px solid #dce2e9;
    border-radius: 3px;
    outline: none;
    background: #fff;
    color: #334155;
    font-size: 12px;
}

.resident-announcement-search input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgb(59 130 246 / 10%);
}

.resident-announcement-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.resident-announcement-table {
    width: 100%;
    min-width: 900px;
    table-layout: fixed;
    border-collapse: collapse;
}

.resident-announcement-table th:nth-child(1),
.resident-announcement-table td:nth-child(1) {
    width: 20%;
}

.resident-announcement-table th:nth-child(2),
.resident-announcement-table td:nth-child(2) {
    width: 42%;
}

.resident-announcement-table th:nth-child(3),
.resident-announcement-table td:nth-child(3) {
    width: 18%;
}

.resident-announcement-table th:nth-child(4),
.resident-announcement-table td:nth-child(4) {
    width: 11%;
}

.resident-announcement-table th:nth-child(5),
.resident-announcement-table td:nth-child(5) {
    width: 9%;
}

.resident-announcement-table th {
    padding: 14px 15px;
    border-top: 1px solid #e8edf2;
    border-bottom: 1px solid #e8edf2;
    background: #f7f9fc;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .45px;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

.resident-announcement-table td {
    padding: 16px 15px;
    border-bottom: 1px solid #edf0f4;
    color: #334155;
    font-size: 12px;
    vertical-align: middle;
}

.resident-announcement-table tbody tr {
    transition: background .15s ease;
}

.resident-announcement-table tbody tr:hover {
    background: #f8faff;
}

.resident-announcement-title {
    color: #263449;
    font-size: 12px;
    font-weight: 750;
}

.resident-announcement-message {
    max-height: 5.1em;
    overflow: hidden;
    color: #475569;
    font-size: 12px;
    line-height: 1.6;
    white-space: normal;
    overflow-wrap: anywhere;
}

.resident-announcement-date {
    color: #64748b;
    font-size: 11px;
    line-height: 1.5;
}

.resident-published {
    display: inline-flex;
    align-items: center;
    padding: 6px 9px;
    background: #eaf2ff;
    color: #1d4ed8;
    font-size: 10px;
    font-weight: 750;
}

.resident-view-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    padding: 0;
    border: 1px solid #dbeafe;
    border-radius: 3px;
    background: #eff6ff;
    color: #1d4ed8;
    cursor: pointer;
    transition: .2s ease;
}

.resident-announcement-table td:last-child {
    text-align: center;
}

.resident-view-btn svg {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.resident-view-btn:hover {
    border-color: #1d4ed8;
    background: #1d4ed8;
    color: #fff;
}

.resident-view-btn:active {
    transform: translateY(1px);
}

.resident-view-btn:focus-visible {
    outline: 3px solid rgb(59 130 246 / 30%);
    outline-offset: 2px;
}

.resident-empty {
    padding: 48px 15px !important;
    color: #64748b !important;
    text-align: center;
}

.resident-announcement-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 15px 0 0;
    color: #64748b;
    font-size: 11px;
}

.resident-announcement-pagination a,
.resident-announcement-pagination span {
    display: inline-flex;
    min-width: 32px;
    height: 32px;
    align-items: center;
    justify-content: center;
    padding: 0 9px;
    border: 1px solid #e2e8f0;
    border-radius: 3px;
    background: #fff;
    color: #475569;
    font-size: 11px;
    text-decoration: none;
}

.resident-announcement-pagination a:hover {
    border-color: #1d4ed8;
    color: #1d4ed8;
}

.resident-announcement-pagination-links {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.resident-announcement-pagination .current {
    border-color: #334155;
    background: #334155;
    color: #fff;
}

.resident-announcement-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    inset: 0;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgb(15 23 42 / 55%);
    backdrop-filter: blur(2px);
}

.resident-announcement-modal.show {
    display: flex;
}

.resident-announcement-modal-content {
    width: 100%;
    max-width: 560px;
    max-height: 85vh;
    overflow-y: auto;
    padding: 24px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: #fff;
    box-shadow: 0 20px 55px rgb(15 23 42 / 20%);
}

.resident-announcement-modal-content h2 {
    margin: 0;
    color: #1e293b;
    font-size: 18px;
    line-height: 1.4;
}

.resident-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding-bottom: 15px;
    margin-bottom: 17px;
    border-bottom: 1px solid #e8edf2;
}

.resident-modal-close {
    flex: 0 0 auto;
    width: 32px;
    height: 32px;
    border: 1px solid #e2e8f0;
    border-radius: 3px;
    background: #fff;
    color: #64748b;
    font-size: 21px;
    line-height: 1;
    cursor: pointer;
}

.resident-modal-close:hover {
    background: #f8fafc;
    color: #1e293b;
}

.resident-modal-message {
    color: #475569;
    font-size: 13px;
    line-height: 1.75;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

@media (max-width: 700px) {
    .resident-announcement-page {
        padding: 20px 16px;
    }

    .resident-announcement-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .resident-announcement-section {
        padding: 17px;
    }

    .resident-announcement-section-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .resident-announcement-search {
        max-width: none;
    }

    .resident-announcement-pagination {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="resident-announcement-page">

    <div class="resident-announcement-heading">
        <div>
          
        </div>
        
    </div>

    <section class="resident-announcement-section">
        <div class="resident-announcement-section-heading">
            <div class="resident-announcement-count">
            <strong><?= number_format($totalAnnouncements) ?></strong>
            <?= $totalAnnouncements === 1 ? 'Announcement' : 'Announcements' ?>
        </div>
          
        </div>

        <div class="resident-announcement-toolbar">

            <div class="resident-announcement-search">
                <span aria-hidden="true">⌕</span>

                <input
                    type="search"
                    id="residentAnnouncementSearch"
                    placeholder="Search announcements..."
                    value="<?= residentAnnouncementEscape($search) ?>"
                    autocomplete="off"
                    aria-label="Search announcements"
                    data-live-search
                    data-live-search-targets="#residentAnnouncementRows|#residentAnnouncementPagination"
                >
            </div>
        </div>

        <div class="resident-announcement-table-wrap">

            <table class="resident-announcement-table">

                <thead>
                    <tr>
                        <th>Announcement Title</th>
                        <th>Message</th>
                        <th>Date Published</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody id="residentAnnouncementRows">

                    <?php if (count($announcements) > 0): ?>

                        <?php foreach ($announcements as $announcement): ?>

                            <tr>

                                <td>
                                    <strong class="resident-announcement-title">
                                        <?= residentAnnouncementEscape($announcement['title']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <div class="resident-announcement-message">
                                        <?= nl2br(residentAnnouncementEscape($announcement['message'])) ?>
                                    </div>
                                </td>

                                <td class="resident-announcement-date">
                                    <?= residentAnnouncementEscape(date(
                                        'M d, Y h:i A',
                                        strtotime($announcement['created_at'])
                                    )) ?>
                                </td>

                                <td>
                                    <span class="resident-published">Published</span>
                                </td>

                                <td>
                                    <button
                                        type="button"
                                        class="resident-view-btn"
                                        onclick="openResidentAnnouncement(this)"
                                        data-title="<?= residentAnnouncementEscape($announcement['title']) ?>"
                                        data-message="<?= residentAnnouncementEscape($announcement['message']) ?>"
                                        aria-label="Read announcement"
                                        title="Read announcement"
                                    >
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
                            <td colspan="5" class="resident-empty">

                                <?php if ($search !== ''): ?>
                                    No announcements matched your search.
                                <?php else: ?>
                                    There are currently no emergency announcements.
                                <?php endif; ?>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="resident-announcement-pagination" id="residentAnnouncementPagination">

            <div>
                Showing <?= $totalAnnouncements > 0 ? $offset + 1 : 0 ?>
                to <?= min($offset + $limit, $totalAnnouncements) ?>
                of <?= number_format($totalAnnouncements) ?>
            </div>

            <div class="resident-announcement-pagination-links">

                <?php
                $searchQuery = $search !== ''
                    ? '&search=' . urlencode($search)
                    : '';
                ?>

                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $searchQuery ?>">Previous</a>
                <?php endif; ?>

                <?php for (
                    $i = max(1, $page - 2);
                    $i <= min($totalPages, $page + 2);
                    $i++
                ): ?>

                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?><?= $searchQuery ?>"><?= $i ?></a>
                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $searchQuery ?>">Next</a>
                <?php endif; ?>

            </div>
        </div>
    </section>

</div>

<!-- READ ANNOUNCEMENT MODAL -->

<div class="resident-announcement-modal" id="residentAnnouncementModal">

    <div class="resident-announcement-modal-content">

        <div class="resident-modal-header">

            <h2 id="residentAnnouncementTitle"></h2>

            <button
                type="button"
                class="resident-modal-close"
                onclick="closeResidentAnnouncement()"
            >
                &times;
            </button>

        </div>

        <div
            id="residentAnnouncementMessage"
            class="resident-modal-message"
        ></div>

    </div>

</div>

<script>
// OPEN ANNOUNCEMENT

function openResidentAnnouncement(button) {

    document.getElementById('residentAnnouncementTitle').textContent =
        button.dataset.title;

    document.getElementById('residentAnnouncementMessage').textContent =
        button.dataset.message;

    document.getElementById('residentAnnouncementModal')
        .classList.add('show');
}

function closeResidentAnnouncement() {
    document.getElementById('residentAnnouncementModal')
        .classList.remove('show');
}

document.getElementById('residentAnnouncementModal')
    .addEventListener('click', function(e) {

        if (e.target === this) {
            closeResidentAnnouncement();
        }
    });

document.addEventListener('keydown', function(e) {

    if (e.key === 'Escape') {
        closeResidentAnnouncement();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
