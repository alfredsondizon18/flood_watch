
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/admin_activity.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ADMIN AUTHENTICATION
if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: resident_dashboard.php');
    exit;
}

// CSRF TOKEN
if (empty($_SESSION['announcement_token'])) {
    $_SESSION['announcement_token'] = bin2hex(random_bytes(32));
}

$successMessage = '';
$errorMessage = '';

// =====================================
// ADD, EDIT OR DELETE ANNOUNCEMENT
// =====================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['announcement_token'] ?? '';

    if (!hash_equals($_SESSION['announcement_token'], $submittedToken)) {
        $errorMessage = 'Invalid request. Please refresh the page.';
    } else {

        $action = $_POST['action'] ?? '';

        // PUBLISH ANNOUNCEMENT
        if ($action === 'add') {

            $title = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $adminId = (int) $_SESSION['user_id'];

            if ($title === '' || $message === '') {
                $errorMessage = 'Please complete the announcement title and message.';
            } else {

                $stmt = $conn->prepare("
                    INSERT INTO emergency_announcements
                    (title, message, created_by)
                    VALUES (?, ?, ?)
                ");

                if ($stmt) {
                    $stmt->bind_param("ssi", $title, $message, $adminId);

                    if ($stmt->execute()) {
                        adminActivityLog(
                            $conn,
                            $adminId,
                            (string) ($_SESSION['full_name'] ?? 'Administrator'),
                            'Published announcement',
                            'Published emergency announcement: ' . $title
                        );

                        $_SESSION['announcement_flash'] = [
                            'type' => 'success',
                            'title' => 'Announcement Published!',
                            'message' => 'Emergency announcement published successfully.'
                        ];

                        $stmt->close();

                        header('Location: admin_emergency_announcements.php');
                        exit;
                    } else {
                        $errorMessage = 'Unable to publish announcement.';
                    }

                    $stmt->close();
                } else {
                    $errorMessage = 'Database error. Please try again.';
                }
            }
        }

        // EDIT ANNOUNCEMENT
        elseif ($action === 'edit') {

            $announcementId = filter_var(
                $_POST['announcement_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $title = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');

            if ($announcementId === false || $title === '' || $message === '') {
                $errorMessage = 'Please provide a valid announcement title and message.';
            } else {
                $checkStmt = $conn->prepare("
                    SELECT id
                    FROM emergency_announcements
                    WHERE id = ?
                    LIMIT 1
                ");

                if ($checkStmt) {
                    $checkStmt->bind_param("i", $announcementId);
                    $checkStmt->execute();
                    $announcementExists = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();

                    if ($announcementExists) {
                        $stmt = $conn->prepare("
                            UPDATE emergency_announcements
                            SET title = ?, message = ?
                            WHERE id = ?
                        ");

                        if ($stmt) {
                            $stmt->bind_param("ssi", $title, $message, $announcementId);

                            if ($stmt->execute()) {
                                adminActivityLog(
                                    $conn,
                                    (int) $_SESSION['user_id'],
                                    (string) ($_SESSION['full_name'] ?? 'Administrator'),
                                    'Edited announcement',
                                    'Edited emergency announcement #' . $announcementId
                                );

                                $_SESSION['announcement_flash'] = [
                                    'type' => 'success',
                                    'title' => 'Announcement Updated!',
                                    'message' => 'Emergency announcement updated successfully.'
                                ];

                                $stmt->close();
                                header('Location: admin_emergency_announcements.php');
                                exit;
                            }

                            $stmt->close();
                        }
                    }
                }

                $errorMessage = 'Unable to update announcement.';
            }
        }

        // DELETE ANNOUNCEMENT
        elseif ($action === 'delete') {

            $announcementId = (int) ($_POST['announcement_id'] ?? 0);

            if ($announcementId > 0) {

                $stmt = $conn->prepare("
                    DELETE FROM emergency_announcements
                    WHERE id = ?
                ");

                if ($stmt) {
                    $stmt->bind_param("i", $announcementId);

                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            adminActivityLog(
                                $conn,
                                (int) $_SESSION['user_id'],
                                (string) ($_SESSION['full_name'] ?? 'Administrator'),
                                'Deleted announcement',
                                'Deleted emergency announcement #' . $announcementId
                            );
                        }

                        $_SESSION['announcement_flash'] = [
                            'type' => 'success',
                            'title' => 'Announcement Deleted!',
                            'message' => 'Announcement deleted successfully.'
                        ];

                        $stmt->close();

                        header('Location: admin_emergency_announcements.php');
                        exit;
                    }

                    $stmt->close();
                }

                $errorMessage = 'Unable to delete announcement.';
            }
        }
    }
}

// =====================================
// FLASH MESSAGE
// =====================================

$flash = $_SESSION['announcement_flash'] ?? null;
unset($_SESSION['announcement_flash']);

// =====================================
// SEARCH AND PAGINATION
// =====================================

$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 5;
$offset = ($page - 1) * $limit;

$totalAnnouncements = 0;
$totalPages = 1;
$announcements = [];

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
        SELECT *
        FROM emergency_announcements
        WHERE title LIKE ? OR message LIKE ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param("ssii", $term, $term, $limit, $offset);

} else {

    $stmt = $conn->prepare("
        SELECT *
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

function announcementEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Emergency Announcements';
$activePage = 'emergency_announcements';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.announcement-page {
    padding: 20px;
}

.announcement-heading {
    margin-bottom: 22px;
}

.announcement-heading h1 {
    margin: 0 0 5px;
    font-size: 25px;
    font-weight: 700;
    color: #1f2937;
}

.announcement-heading p {
    margin: 0;
    color: #6b7280;
    font-size: 13px;
}

.announcement-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    padding: 22px;
    margin-bottom: 22px;
}

.announcement-card h2 {
    font-size: 16px;
    color: #1f2937;
    margin: 0 0 18px;
    font-weight: 700;
}

.announcement-form label {
    display: block;
    margin-bottom: 7px;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
}

.announcement-form input,
.announcement-form textarea {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #d1d5db;
    outline: none;
    font-size: 13px;
    margin-bottom: 15px;
    background: #fff;
    color: #1f2937;
}

.announcement-form input:focus,
.announcement-form textarea:focus {
    border-color: #2563eb;
}

.announcement-form textarea {
    min-height: 120px;
    resize: vertical;
}

.publish-btn {
    border: 0;
    background: #2563eb;
    color: #fff;
    padding: 11px 17px;
    font-size: 13px;
    cursor: pointer;
}

.publish-btn:hover {
    background: #1d4ed8;
}

.announcement-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 15px;
}

.announcement-search {
    position: relative;
    width: 100%;
    max-width: 350px;
}

.announcement-search span {
    position: absolute;
    left: 13px;
    top: 11px;
    color: #9ca3af;
}

.announcement-search input {
    width: 100%;
    height: 42px;
    padding: 0 12px 0 38px;
    border: 1px solid #d1d5db;
    outline: none;
    font-size: 13px;
}

.announcement-search input:focus {
    border-color: #2563eb;
}

.announcement-table-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    overflow-x: auto;
}

.announcement-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

.announcement-table th {
    padding: 14px 16px;
    background: #f9fafb;
    color: #4b5563;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    border-bottom: 1px solid #e5e7eb;
}

.announcement-table td {
    padding: 15px 16px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #f1f5f9;
}

.announcement-table tr:hover {
    background: #f9fafb;
}

.published-label {
    display: inline-block;
    padding: 5px 9px;
    background: #dcfce7;
    color: #166534;
    font-size: 11px;
    font-weight: 700;
}

.announcement-actions {
    display: flex;
    gap: 7px;
}

.read-btn,
.edit-btn,
.delete-btn {
    border: 0;
    padding: 7px 11px;
    font-size: 12px;
    cursor: pointer;
}

.read-btn {
    background: #eff6ff;
    color: #1d4ed8;
}

.delete-btn {
    background: #fef2f2;
    color: #b91c1c;
}

.announcement-empty {
    text-align: center;
    padding: 35px !important;
    color: #9ca3af !important;
}

.announcement-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 15px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top: 0;
    font-size: 12px;
    color: #6b7280;
}

.announcement-pagination a,
.announcement-pagination span {
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

.announcement-pagination .current {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb;
}

.announcement-notice {
    padding: 12px 15px;
    margin-bottom: 15px;
    font-size: 13px;
}

.announcement-notice.success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.announcement-notice.error {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

@media (max-width: 600px) {
    .announcement-page {
        padding: 12px;
    }

    .announcement-card {
        padding: 16px;
    }

    .announcement-search {
        max-width: none;
    }
}

/* ===== Minimalist announcement UI refresh ===== */
.announcement-page {
    max-width: 1240px;
    margin: 0 auto;
    padding: 28px 30px 40px;
    color: #172033;
}
.announcement-heading { margin-bottom: 22px; }
.announcement-heading h1 {
    color: #172033;
    font-size: 25px;
    letter-spacing: -.65px;
    font-weight: 700;
}
.announcement-heading p,
.announcement-create-row p {
    color: #778196;
    font-size: 13px;
}
.announcement-create-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin: 26px 0 18px;
}
.announcement-create-row h2 {
    margin: 0 0 5px;
    color: #172033;
    font-size: 17px;
    font-weight: 650;
    letter-spacing: -.2px;
}
.announcement-create-row p { margin: 0; }
.create-announcement-btn,
.publish-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 0 17px;
    border: 1px solid #263b63;
    border-radius: 7px;
    background: #263b63;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.create-announcement-btn:hover,
.publish-btn:hover {
    background: #1d2e4d;
    border-color: #1d2e4d;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(29,46,77,.18);
}
.create-announcement-btn:active,
.publish-btn:active {
    transform: translateY(0);
    box-shadow: none;
}
.create-announcement-btn span { font-size: 19px; line-height: 1; }
.announcement-toolbar { margin: 0 0 14px; }
.announcement-search { max-width: 360px; }
.announcement-search input {
    height: 42px;
    border: 1px solid #e1e6ee;
    border-radius: 7px;
    background: #fff;
    color: #263247;
    transition: border-color .18s ease, box-shadow .18s ease;
}
.announcement-search input:focus,
.announcement-form input:focus,
.announcement-form textarea:focus {
    border-color: #8294b3;
    box-shadow: 0 0 0 3px rgba(38,59,99,.08);
    outline: none;
}
.announcement-table-wrap {
    overflow: hidden;
    border: 1px solid #e7ebf1;
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(22,34,55,.025);
}
.announcement-table { min-width: 650px; }
.announcement-table th {
    padding: 14px 18px;
    background: #f8f9fb;
    color: #7a8495;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .65px;
    border-bottom: 1px solid #e9edf2;
}
.announcement-table td {
    padding: 16px 18px;
    color: #4a5568;
    font-size: 13px;
    border-bottom: 1px solid #f0f2f5;
}
.announcement-table tbody tr:last-child td { border-bottom: 0; }
.announcement-table tr:hover { background: #fbfcfe; }
.announcement-table td strong { color: #263247; font-weight: 600; }
.published-label {
    padding: 5px 9px;
    border-radius: 20px;
    background: #edf7f1;
    color: #28734a;
    font-size: 10px;
    font-weight: 650;
}
.announcement-actions { align-items: center; gap: 7px; }
.read-btn, .delete-btn, .cancel-modal-btn {
    min-height: 32px;
    padding: 0 11px;
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background .18s ease;
}
.read-btn,
.edit-btn { background: #f0f3f8; color: #344969; }
.read-btn:hover { background: #e5eaf2; }
.edit-btn:hover { background: #e5eaf2; }
.delete-btn { background: #fff2f1; color: #b54742; }
.delete-btn:hover { background: #ffe5e3; }
.announcement-pagination {
    padding: 14px 17px;
    border: 1px solid #e7ebf1;
    border-top: 0;
    border-radius: 0 0 9px 9px;
    background: #fff;
    color: #7a8495;
}
.announcement-pagination a,
.announcement-pagination span {
    min-width: 33px;
    height: 33px;
    border-radius: 6px;
    border-color: #e5e9ef;
    color: #536075;
}
.announcement-pagination .current {
    background: #263b63;
    border-color: #263b63;
    color: #fff;
}
.announcement-notice { border-radius: 7px; }
.announcement-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(17, 27, 44, .48);
    backdrop-filter: blur(3px);
}
.announcement-modal-panel {
    width: 100%;
    max-width: 550px;
    max-height: 86vh;
    overflow-y: auto;
    padding: 25px;
    border: 1px solid rgba(230,234,241,.9);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 20px 60px rgba(15,23,42,.18);
    animation: announcementModalIn .18s ease-out;
}
.create-modal-panel { max-width: 590px; }
@keyframes announcementModalIn {
    from { opacity: 0; transform: translateY(8px) scale(.99); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.announcement-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 17px;
    margin-bottom: 21px;
    border-bottom: 1px solid #edf0f4;
}
.announcement-modal-header h2 {
    margin: 0 0 5px;
    color: #202c40;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -.3px;
}
.announcement-modal-header p {
    margin: 0;
    color: #8791a1;
    font-size: 12px;
}
.modal-close-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    border: 0;
    border-radius: 6px;
    background: #f4f6f8;
    color: #5d6879;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
}
.modal-close-btn:hover { background: #e9edf2; color: #202c40; }
.announcement-form label {
    margin: 0 0 7px;
    color: #39465a;
    font-size: 12px;
    font-weight: 650;
}
.announcement-form input,
.announcement-form textarea {
    box-sizing: border-box;
    margin: 0 0 18px;
    padding: 11px 12px;
    border: 1px solid #e0e5ec;
    border-radius: 7px;
    background: #fff;
    color: #263247;
    font: inherit;
    font-size: 13px;
}
.announcement-form input { min-height: 43px; }
.announcement-form textarea {
    min-height: 145px;
    line-height: 1.6;
    resize: vertical;
}
.announcement-form input::placeholder,
.announcement-form textarea::placeholder { color: #a0a8b5; }
.modal-form-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 9px;
    padding-top: 3px;
}
.cancel-modal-btn {
    min-height: 42px;
    padding: 0 16px;
    border-color: #e2e6ec;
    background: #fff;
    color: #5b6678;
}
.cancel-modal-btn:hover { background: #f6f7f9; }
.view-announcement-message {
    margin: 0;
    color: #4b5668;
    font-size: 14px;
    line-height: 1.8;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
@media (max-width: 600px) {
    .announcement-page { padding: 18px 14px 28px; }
    .announcement-create-row { align-items: flex-start; flex-direction: column; }
    .create-announcement-btn { width: 100%; }
    .announcement-search { max-width: none; }
    .announcement-modal-panel { padding: 19px; }
    .modal-form-actions { flex-direction: column-reverse; }
    .modal-form-actions button { width: 100%; }
}


/* ===== Table search and icon-only actions ===== */
.announcement-table-wrap {
    display: block;
}
.announcement-table-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 22px 24px;
    border-bottom: 1px solid #edf0f4;
    background: #fff;
}
.announcement-table-total {
    color: #8a96a6;
    font-size: 12px;
}
.announcement-table-total strong {
    color: #2563eb;
    font-weight: 700;
}
.announcement-table-toolbar .announcement-search {
    width: 100%;
    max-width: 330px;
}
.announcement-table-toolbar .announcement-search span {
    top: 10px;
    left: 12px;
    font-size: 18px;
    line-height: 1;
}
.announcement-table-toolbar .announcement-search input {
    height: 39px;
    border: 1px solid #e1e6ee;
    border-radius: 7px;
    background: #fbfcfe;
}
.announcement-actions {
    align-items: center;
    gap: 8px;
}
.announcement-actions form { margin: 0; }
.read-btn,
.edit-btn,
.delete-btn {
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
.read-btn:hover,
.edit-btn:hover,
.delete-btn:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
}
.read-btn:active,
.edit-btn:active,
.delete-btn:active {
    transform: translateY(1px);
}
.create-announcement-btn:focus-visible,
.publish-btn:focus-visible,
.read-btn:focus-visible,
.edit-btn:focus-visible,
.delete-btn:focus-visible,
.modal-close-btn:focus-visible,
.cancel-modal-btn:focus-visible {
    outline: 3px solid rgb(59 130 246 / 30%);
    outline-offset: 2px;
}
.modal-close-btn,
.cancel-modal-btn {
    transition: background .16s ease, border-color .16s ease, color .16s ease, transform .16s ease;
}
.read-btn svg,
.edit-btn svg,
.delete-btn svg {
    display: block;
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}
@media (max-width: 600px) {
    .announcement-table-toolbar {
        align-items: flex-start;
        flex-direction: column;
        padding: 19px;
    }
    .announcement-table-toolbar .announcement-search {
        max-width: none;
    }
}

</style>

<div class="announcement-page">

    <div class="announcement-heading">
     
    </div>

    <?php if ($flash && ($flash['type'] ?? '') !== 'success'): ?>
        <div class="announcement-notice <?= announcementEscape($flash['type']) ?>">
            <?= announcementEscape($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="announcement-notice error">
            <?= announcementEscape($errorMessage) ?>
        </div>
    <?php endif; ?>

    <!-- CREATE ANNOUNCEMENT ACTION -->

    <div class="announcement-create-row">
        <div>
          
        </div>
        <button type="button" class="create-announcement-btn" onclick="openCreateAnnouncement()">
            <span aria-hidden="true">+</span> Create Announcement
        </button>
    </div>

    <!-- ANNOUNCEMENT RECORDS -->

    <div class="announcement-table-wrap">

        <div class="announcement-table-toolbar">
            <div class="announcement-table-total" id="announcementTotal">
                <strong><?= number_format($totalAnnouncements) ?></strong>
                total announcements
            </div>

            <div class="announcement-search">
                <span aria-hidden="true">⌕</span>
                <input
                    type="search"
                    id="announcementSearch"
                    placeholder="Search announcements..."
                    value="<?= announcementEscape($search) ?>"
                    autocomplete="off"
                    aria-label="Search announcements"
                    data-live-search
                    data-live-search-targets="#announcementRows|#announcementPagination|#announcementTotal"
                >
            </div>
        </div>

        <table class="announcement-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Date Published</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody id="announcementRows">

            <?php if (count($announcements) > 0): ?>

                <?php foreach ($announcements as $announcement): ?>

                    <tr>
                        <td>
                            <strong>
                                <?= announcementEscape($announcement['title']) ?>
                            </strong>
                        </td>

                        <td>
                            <?= announcementEscape(date(
                                'M d, Y h:i A',
                                strtotime($announcement['created_at'])
                            )) ?>
                        </td>

                        <td>
                            <span class="published-label">Published</span>
                        </td>

                        <td>
                            <div class="announcement-actions">

                                <button
                                    type="button"
                                    class="read-btn"
                                    onclick="viewAnnouncement(this)"
                                    data-title="<?= announcementEscape($announcement['title']) ?>"
                                    data-message="<?= announcementEscape($announcement['message']) ?>"
                                    aria-label="View announcement"
                                    title="View announcement"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path>
                                        <circle cx="12" cy="12" r="2.7"></circle>
                                    </svg>
                                </button>

                                <button
                                    type="button"
                                    class="edit-btn"
                                    onclick="openEditAnnouncement(this)"
                                    data-id="<?= (int) $announcement['id'] ?>"
                                    data-title="<?= announcementEscape($announcement['title']) ?>"
                                    data-message="<?= announcementEscape($announcement['message']) ?>"
                                    aria-label="Edit announcement"
                                    title="Edit announcement"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 20h9"></path>
                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>
                                    </svg>
                                </button>

                                <form method="POST" class="delete-announcement-form">

                                    <input
                                        type="hidden"
                                        name="announcement_token"
                                        value="<?= announcementEscape($_SESSION['announcement_token']) ?>"
                                    >

                                    <input type="hidden" name="action" value="delete">

                                    <input
                                        type="hidden"
                                        name="announcement_id"
                                        value="<?= (int) $announcement['id'] ?>"
                                    >

                                    <button type="submit" class="delete-btn" aria-label="Delete announcement" title="Delete announcement">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M4 7h16"></path>
                                            <path d="M9 7V4h6v3"></path>
                                            <path d="m6.5 7 .8 13h9.4l.8-13"></path>
                                            <path d="M10 11v5M14 11v5"></path>
                                        </svg>
                                    </button>

                                </form>

                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="5" class="announcement-empty">
                        No announcements found.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>

    </div>

    <div class="announcement-pagination" id="announcementPagination">

        <div>
            Showing <?= $totalAnnouncements > 0 ? $offset + 1 : 0 ?>
            to <?= min($offset + $limit, $totalAnnouncements) ?>
            of <?= number_format($totalAnnouncements) ?>
        </div>

        <div style="display:flex; gap:5px;">

            <?php
            $searchQuery = $search !== ''
                ? '&search=' . urlencode($search)
                : '';
            ?>

            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $searchQuery ?>">
                    Previous
                </a>
            <?php endif; ?>

            <?php for (
                $i = max(1, $page - 2);
                $i <= min($totalPages, $page + 2);
                $i++
            ): ?>

                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?><?= $searchQuery ?>">
                        <?= $i ?>
                    </a>
                <?php endif; ?>

            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $searchQuery ?>">
                    Next
                </a>
            <?php endif; ?>

        </div>

    </div>

</div>

<!-- CREATE ANNOUNCEMENT MODAL -->

<div id="createAnnouncementModal" class="announcement-modal" aria-hidden="true">
    <div class="announcement-modal-panel create-modal-panel" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
        <div class="announcement-modal-header">
            <div>
                <h2 id="createModalTitle">Create announcement</h2>
                <p>Share an important update with residents.</p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeCreateAnnouncement()" aria-label="Close">&times;</button>
        </div>

        <form method="POST" class="announcement-form create-announcement-form">
            <input
                type="hidden"
                name="announcement_token"
                value="<?= announcementEscape($_SESSION['announcement_token']) ?>"
            >
            <input type="hidden" name="action" value="add">

            <label for="announcementTitle">Announcement title</label>
            <input
                type="text"
                id="announcementTitle"
                name="title"
                maxlength="255"
                placeholder="Enter announcement title"
                required
            >

            <label for="announcementMessage">Announcement message</label>
            <textarea
                id="announcementMessage"
                name="message"
                placeholder="Write the announcement details..."
                required
            ></textarea>

            <div class="modal-form-actions">
                <button type="button" class="cancel-modal-btn" onclick="closeCreateAnnouncement()">Cancel</button>
                <button type="submit" class="publish-btn">Publish announcement</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ANNOUNCEMENT MODAL -->

<div id="editAnnouncementModal" class="announcement-modal" aria-hidden="true">
    <div class="announcement-modal-panel create-modal-panel" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="announcement-modal-header">
            <div>
                <h2 id="editModalTitle">Edit announcement</h2>
                <p>Update the announcement residents will see.</p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeEditAnnouncement()" aria-label="Close">&times;</button>
        </div>

        <form method="POST" class="announcement-form">
            <input
                type="hidden"
                name="announcement_token"
                value="<?= announcementEscape($_SESSION['announcement_token']) ?>"
            >
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="announcement_id" id="editAnnouncementId">

            <label for="editAnnouncementTitle">Announcement title</label>
            <input
                type="text"
                id="editAnnouncementTitle"
                name="title"
                maxlength="255"
                placeholder="Enter announcement title"
                required
            >

            <label for="editAnnouncementMessage">Announcement message</label>
            <textarea
                id="editAnnouncementMessage"
                name="message"
                placeholder="Write the announcement details..."
                required
            ></textarea>

            <div class="modal-form-actions">
                <button type="button" class="cancel-modal-btn" onclick="closeEditAnnouncement()">Cancel</button>
                <button type="submit" class="publish-btn">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW ANNOUNCEMENT MODAL -->

<div id="announcementModal" class="announcement-modal" aria-hidden="true">
    <div class="announcement-modal-panel" role="dialog" aria-modal="true" aria-labelledby="viewTitle">
        <div class="announcement-modal-header">
            <div>
                <h2 id="viewTitle"></h2>
                <p>Published announcement</p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAnnouncement()" aria-label="Close">&times;</button>
        </div>
        <p id="viewMessage" class="view-announcement-message"></p>
    </div>
</div>

<script>
// =====================================
// VIEW ANNOUNCEMENT
// =====================================

function viewAnnouncement(button) {

    document.getElementById('viewTitle').textContent =
        button.dataset.title;

    document.getElementById('viewMessage').textContent =
        button.dataset.message;

    document.getElementById('announcementModal').style.display = 'flex';
}

function closeAnnouncement() {
    const modal = document.getElementById('announcementModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function openCreateAnnouncement() {
    const modal = document.getElementById('createAnnouncementModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('announcementTitle').focus();
}

function closeCreateAnnouncement() {
    const modal = document.getElementById('createAnnouncementModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function openEditAnnouncement(button) {
    document.getElementById('editAnnouncementId').value = button.dataset.id;
    document.getElementById('editAnnouncementTitle').value = button.dataset.title;
    document.getElementById('editAnnouncementMessage').value = button.dataset.message;

    const modal = document.getElementById('editAnnouncementModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('editAnnouncementTitle').focus();
}

function closeEditAnnouncement() {
    const modal = document.getElementById('editAnnouncementModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

document.getElementById('announcementModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAnnouncement();
    }
});

document.getElementById('createAnnouncementModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCreateAnnouncement();
    }
});

document.getElementById('editAnnouncementModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditAnnouncement();
    }
});

<?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal === 'undefined') {
        console.error('SweetAlert2 is not loaded; announcement success message cannot be displayed.');
        return;
    }

    Swal.fire({
        icon: 'success',
        title: <?= json_encode(
            $flash['title'] ?? 'Success!',
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?>,
        text: <?= json_encode(
            $flash['message'],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?>,
        confirmButtonText: 'OK',
        confirmButtonColor: '#263b63',
        heightAuto: false
    });
});
<?php endif; ?>

document.querySelectorAll('.delete-announcement-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        event.preventDefault();

        if (form.dataset.confirmed === 'true') {
            return;
        }

        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 is not loaded; announcement deletion was cancelled.');
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Delete announcement?',
            text: 'This announcement will be permanently deleted.',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b54742',
            cancelButtonColor: '#64748b',
            allowOutsideClick: false,
            heightAuto: false
        }).then(function(result) {
            if (result.isConfirmed) {
                form.dataset.confirmed = 'true';
                HTMLFormElement.prototype.submit.call(form);
            }
        });
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAnnouncement();
        closeCreateAnnouncement();
        closeEditAnnouncement();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
