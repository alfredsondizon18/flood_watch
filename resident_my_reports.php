
<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================
// RESIDENT AUTHENTICATION
// =====================================

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'resident') {
    header('Location: admin_reports.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// =====================================
// HELPERS
// =====================================

function myReportEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function myReportImagePath($image)
{
    $image = basename((string) $image);

    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/i', $image)) {
        return '';
    }

    return 'uploads/flood_reports/' . $image;
}

function myReportStatusClass($status)
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

function myReportRedirectAfterAction($search, $page)
{
    $params = [];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($page > 1) {
        $params['page'] = $page;
    }

    $query = http_build_query($params);
    header('Location: resident_my_reports.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function myReportSetActionMessage($message, $type, $title = '')
{
    $_SESSION['report_message'] = $message;
    $_SESSION['report_message_type'] = $type;

    if ($title !== '') {
        $_SESSION['report_message_title'] = $title;
    } else {
        unset($_SESSION['report_message_title']);
    }
}

// =====================================
// EDIT OR DELETE A RESIDENT REPORT
// =====================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnSearch = trim($_POST['return_search'] ?? '');
    $returnPage = max(1, (int) ($_POST['return_page'] ?? 1));
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string) $submittedToken)
    ) {
        myReportSetActionMessage(
            'Your session expired. Please refresh and try again.',
            'error'
        );
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    $reportId = filter_var(
        $_POST['report_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $reportAction = $_POST['report_action'] ?? '';

    if ($reportId === false || !in_array($reportAction, ['edit', 'delete'], true)) {
        myReportSetActionMessage('Invalid report action.', 'error');
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    $existingStmt = $conn->prepare("
        SELECT image, status, responded_at
        FROM flood_reports
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");

    if (!$existingStmt) {
        error_log('Resident report action lookup prepare error: ' . $conn->error);
        myReportSetActionMessage('Unable to access this report. Please try again.', 'error');
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    $existingStmt->bind_param('ii', $reportId, $userId);
    $existingStmt->execute();
    $existingReport = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if (
        !$existingReport ||
        $existingReport['status'] !== 'Pending' ||
        $existingReport['responded_at'] !== null
    ) {
        myReportSetActionMessage(
            'Only your pending reports without an administrator response can be edited or deleted.',
            'error'
        );
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    if ($reportAction === 'delete') {
        $deleteStmt = $conn->prepare("
            DELETE FROM flood_reports
            WHERE id = ?
              AND user_id = ?
              AND status = 'Pending'
              AND responded_at IS NULL
        ");

        if (!$deleteStmt) {
            error_log('Resident report delete prepare error: ' . $conn->error);
            myReportSetActionMessage('Unable to delete your report. Please try again.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $deleteStmt->bind_param('ii', $reportId, $userId);
        $deleted = $deleteStmt->execute();
        $deletedRows = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if (!$deleted || $deletedRows !== 1) {
            myReportSetActionMessage(
                'This report is no longer pending and cannot be deleted.',
                'error'
            );
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $oldImagePath = myReportImagePath($existingReport['image']);
        if ($oldImagePath !== '') {
            $oldImageFile = __DIR__ . DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $oldImagePath);
            if (is_file($oldImageFile) && !unlink($oldImageFile)) {
                error_log('Unable to remove deleted flood report image: ' . $oldImageFile);
            }
        }

        myReportSetActionMessage(
            'Your flood report was deleted successfully.',
            'success',
            'Report Deleted!'
        );
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $waterLevelInput = trim($_POST['water_level_cm'] ?? '');
    $latitudeInput = trim($_POST['latitude'] ?? '');
    $longitudeInput = trim($_POST['longitude'] ?? '');

    if ($location === '' || strlen($location) > 255) {
        myReportSetActionMessage('Please enter a valid flood location.', 'error');
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    if ($description === '' || strlen($description) > 5000) {
        myReportSetActionMessage(
            'Please enter a report description of no more than 5,000 characters.',
            'error'
        );
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    if (
        $waterLevelInput === '' ||
        !is_numeric($waterLevelInput) ||
        !is_finite((float) $waterLevelInput) ||
        (float) $waterLevelInput < 0 ||
        (float) $waterLevelInput > 99999.99
    ) {
        myReportSetActionMessage('Please enter a valid water depth in centimeters.', 'error');
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    $latitude = null;
    $longitude = null;

    if ($latitudeInput !== '' || $longitudeInput !== '') {
        if (
            $latitudeInput === '' ||
            $longitudeInput === '' ||
            !is_numeric($latitudeInput) ||
            !is_numeric($longitudeInput) ||
            (float) $latitudeInput < -90 ||
            (float) $latitudeInput > 90 ||
            (float) $longitudeInput < -180 ||
            (float) $longitudeInput > 180
        ) {
            myReportSetActionMessage('Please enter valid GPS coordinates.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $latitude = number_format((float) $latitudeInput, 7, '.', '');
        $longitude = number_format((float) $longitudeInput, 7, '.', '');
    }

    $waterLevel = (float) $waterLevelInput;
    $waterLevelStatus = $waterLevel >= 60
        ? 'Critical'
        : ($waterLevel >= 30 ? 'Warning' : 'Normal');
    $imageName = basename((string) $existingReport['image']);
    $newImageFile = '';

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadedFile = $_FILES['image'];

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            myReportSetActionMessage('Unable to upload the replacement image.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        if ($uploadedFile['size'] <= 0 || $uploadedFile['size'] > 5 * 1024 * 1024) {
            myReportSetActionMessage('The image must not exceed 5 MB.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            myReportSetActionMessage('Invalid image upload.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedFile['tmp_name']);
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (
            !isset($allowedMimeTypes[$mimeType]) ||
            getimagesize($uploadedFile['tmp_name']) === false
        ) {
            myReportSetActionMessage('Only valid JPG, PNG, and WEBP images are allowed.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR .
            'uploads' . DIRECTORY_SEPARATOR . 'flood_reports';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true) &&
            !is_dir($uploadDirectory)
        ) {
            myReportSetActionMessage('Unable to access the image upload folder.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $imageName = bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
        $newImageFile = $uploadDirectory . DIRECTORY_SEPARATOR . $imageName;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $newImageFile)) {
            myReportSetActionMessage('Unable to save the replacement image.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }
    }

    $updateStmt = $conn->prepare("
        UPDATE flood_reports
        SET location = ?,
            latitude = ?,
            longitude = ?,
            description = ?,
            image = ?,
            water_level_cm = ?,
            water_level_status = ?
        WHERE id = ?
          AND user_id = ?
          AND status = 'Pending'
          AND responded_at IS NULL
    ");

    if (!$updateStmt) {
        if ($newImageFile !== '' && is_file($newImageFile)) {
            unlink($newImageFile);
        }
        error_log('Resident report update prepare error: ' . $conn->error);
        myReportSetActionMessage('Unable to update your report. Please try again.', 'error');
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    $updateStmt->bind_param(
        'sssssdsii',
        $location,
        $latitude,
        $longitude,
        $description,
        $imageName,
        $waterLevel,
        $waterLevelStatus,
        $reportId,
        $userId
    );
    $updated = $updateStmt->execute();
    $updatedRows = $updateStmt->affected_rows;
    $updateStmt->close();

    if (!$updated) {
        if ($newImageFile !== '' && is_file($newImageFile)) {
            unlink($newImageFile);
        }
        myReportSetActionMessage('Unable to update your report. Please try again.', 'error');
        myReportRedirectAfterAction($returnSearch, $returnPage);
    }

    if ($updatedRows === 0) {
        $stateStmt = $conn->prepare("
            SELECT status, responded_at
            FROM flood_reports
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");

        if (!$stateStmt) {
            if ($newImageFile !== '' && is_file($newImageFile)) {
                unlink($newImageFile);
            }
            error_log('Resident report state check prepare error: ' . $conn->error);
            myReportSetActionMessage('Unable to confirm your report update.', 'error');
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }

        $stateStmt->bind_param('ii', $reportId, $userId);
        $stateStmt->execute();
        $currentState = $stateStmt->get_result()->fetch_assoc();
        $stateStmt->close();

        if (
            !$currentState ||
            $currentState['status'] !== 'Pending' ||
            $currentState['responded_at'] !== null
        ) {
            if ($newImageFile !== '' && is_file($newImageFile)) {
                unlink($newImageFile);
            }
            myReportSetActionMessage(
                'This report is no longer pending and cannot be edited.',
                'error'
            );
            myReportRedirectAfterAction($returnSearch, $returnPage);
        }
    }

    if ($newImageFile !== '') {
        $oldImagePath = myReportImagePath($existingReport['image']);
        if ($oldImagePath !== '') {
            $oldImageFile = __DIR__ . DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $oldImagePath);
            if (is_file($oldImageFile) && !unlink($oldImageFile)) {
                error_log('Unable to remove replaced flood report image: ' . $oldImageFile);
            }
        }
    }

    myReportSetActionMessage(
        'Your flood report was updated successfully.',
        'success',
        'Report Updated!'
    );
    myReportRedirectAfterAction($returnSearch, $returnPage);
}

// =====================================
// FLASH MESSAGE
// =====================================

$reportMessage = $_SESSION['report_message'] ?? '';
$reportMessageType = $_SESSION['report_message_type'] ?? '';
$reportMessageTitle = $_SESSION['report_message_title'] ?? '';

unset(
    $_SESSION['report_message'],
    $_SESSION['report_message_type'],
    $_SESSION['report_message_title']
);

// =====================================
// SEARCH AND PAGINATION
// =====================================

$limit = 5;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$searchValue = '%' . $search . '%';

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM flood_reports
    WHERE user_id = ?
    AND (
        location LIKE ?
        OR description LIKE ?
        OR status LIKE ?
    )
");

$countStmt->bind_param(
    'isss',
    $userId,
    $searchValue,
    $searchValue,
    $searchValue
);

$countStmt->execute();

$totalReports = (int) $countStmt->get_result()->fetch_assoc()['total'];

$countStmt->close();

$totalPages = max(1, (int) ceil($totalReports / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

// =====================================
// FETCH RESIDENT REPORTS
// =====================================

$stmt = $conn->prepare("
    SELECT
        id,
        location,
        latitude,
        longitude,
        description,
        image,
        water_level_cm,
        status,
        admin_response,
        responded_at,
        created_at
    FROM flood_reports
    WHERE user_id = ?
    AND (
        location LIKE ?
        OR description LIKE ?
        OR status LIKE ?
    )
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
");

$stmt->bind_param(
    'isssii',
    $userId,
    $searchValue,
    $searchValue,
    $searchValue,
    $limit,
    $offset
);

$stmt->execute();

$result = $stmt->get_result();
$reports = [];

while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

$stmt->close();

$reportModalsHtml = '';

$pageTitle = 'My Submissions';
$activePage = 'my_reports';

require_once __DIR__ . '/includes/header.php';
?>

<main class="reports-page">

    <!-- PAGE HEADING -->

    <div class="page-heading">

        

     

    </div>

    <!-- NOTIFICATION -->

    <?php if ($reportMessage !== '' && $reportMessageType !== 'success'): ?>

        <div class="report-alert <?= myReportEscape($reportMessageType) ?>">
            <?= myReportEscape($reportMessage) ?>
        </div>

    <?php endif; ?>


    <!-- REPORTS SECTION -->

    <section class="reports-section">
           <div class="heading-info">
            <span class="heading-count">
                <span id="residentReportsTotal"><?= $totalReports ?></span>
            </span>
            <span>Submitted Reports</span>
        </div>

        <div class="section-heading">

       

        </div>


        <!-- RIGHT ALIGNED AUTOMATIC SEARCH -->

        <form method="GET"
              action="resident_my_reports.php"
              class="table-toolbar"
              id="reportSearchForm">

            <div class="search-box">

                <span class="search-icon">⌕</span>

                <input
                    type="search"
                    name="search"
                    id="reportSearch"
                    value="<?= myReportEscape($search) ?>"
                    placeholder="Search reports..."
                    autocomplete="off"
                    data-live-search
                    data-live-search-targets="#myReportsResults|#residentReportsTotal">

            </div>

            <?php if ($search !== ''): ?>

                <a href="resident_my_reports.php" class="clear-button">
                    Clear
                </a>

            <?php endif; ?>

        </form>


        <!-- REPORTS TABLE -->

        <div id="myReportsResults">
        <div class="table-responsive">

            <table class="reports-table">

                <thead>
                    <tr>
                        <th>LOCATION</th>
                        <th>STATUS</th>
                        <th>DATE SUBMITTED</th>
                        <th class="action-heading">ACTION</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($reports)): ?>

                        <tr>
                            <td colspan="4" class="empty-cell">

                                <div class="empty-state">

                                    <div class="empty-icon">🌊</div>

                                    <h3>No flood reports found</h3>

                                    <?php if ($search !== ''): ?>

                                        <p>
                                            No reports matched your search.
                                            Try another keyword.
                                        </p>

                                    <?php else: ?>

                                        <p>
                                            You have not submitted any flood reports yet.
                                        </p>

                                        <a href="resident_submit_report.php"
                                           class="empty-link">
                                            Submit your first report
                                        </a>

                                    <?php endif; ?>

                                </div>

                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($reports as $report): ?>

                            <?php

                            $imagePath = myReportImagePath($report['image']);

                            $date = date(
                                'M d, Y h:i A',
                                strtotime($report['created_at'])
                            );

                            $responseDate = '';

                            if (!empty($report['responded_at'])) {
                                $responseDate = date(
                                    'M d, Y h:i A',
                                    strtotime($report['responded_at'])
                                );
                            }

                            $modalId = 'reportModal' . (int) $report['id'];
                            $canManageReport = $report['status'] === 'Pending'
                                && empty($report['responded_at']);

                            ?>

                            <tr>

                                <!-- LOCATION -->

                                <td>

                                    <div class="location-name">
                                        <?= myReportEscape($report['location']) ?>
                                    </div>

                                    <div class="location-subtitle">
                                        Flood Incident Report
                                    </div>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span class="status-badge <?= myReportStatusClass($report['status']) ?>">

                                        <span class="status-dot"></span>

                                        <?= myReportEscape($report['status']) ?>

                                    </span>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <div class="date-main">
                                        <?= myReportEscape($date) ?>
                                    </div>

                                </td>


                                <!-- VIEW ACTION -->

                                <td class="action-cell">

                                    <button
                                        type="button"
                                        class="view-button"
                                        onclick="openReportModal('<?= $modalId ?>')"
                                        aria-label="View report"
                                        title="View report">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path>
                                            <circle cx="12" cy="12" r="2.7"></circle>
                                        </svg>

                                    </button>

                                    <?php if ($canManageReport): ?>
                                        <button
                                            type="button"
                                            class="edit-button"
                                            onclick="openEditReport(this)"
                                            data-report-id="<?= (int) $report['id'] ?>"
                                            data-location="<?= myReportEscape($report['location']) ?>"
                                            data-latitude="<?= myReportEscape($report['latitude'] ?? '') ?>"
                                            data-longitude="<?= myReportEscape($report['longitude'] ?? '') ?>"
                                            data-description="<?= myReportEscape($report['description']) ?>"
                                            data-water-level="<?= myReportEscape($report['water_level_cm'] ?? '0') ?>"
                                            aria-label="Edit report"
                                            title="Edit report">
                                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M12 20h9"></path>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>
                                            </svg>
                                        </button>

                                        <form method="POST" class="delete-report-form">
                                            <input type="hidden" name="csrf_token" value="<?= myReportEscape($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="report_action" value="delete">
                                            <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                            <input type="hidden" name="return_search" value="<?= myReportEscape($search) ?>">
                                            <input type="hidden" name="return_page" value="<?= (int) $page ?>">
                                            <button
                                                type="submit"
                                                class="delete-button"
                                                aria-label="Delete report"
                                                title="Delete report">
                                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M4 7h16"></path>
                                                    <path d="M9 7V4h6v3"></path>
                                                    <path d="m6.5 7 .8 13h9.4l.8-13"></path>
                                                    <path d="M10 11v5M14 11v5"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </td>

                            </tr>


                            <?php ob_start(); ?>

                            <div class="report-modal"
                                 id="<?= $modalId ?>"
                                 aria-hidden="true">

                                <div class="report-modal-backdrop"
                                     onclick="closeReportModal('<?= $modalId ?>')">
                                </div>

                                <div class="modal-dialog">

                                    <div class="modal-header">

                                        <div>
                                            <span class="modal-eyebrow">
                                                FLOODWATCH REPORT DETAILS
                                            </span>

                                            <h2>Flood Report Details</h2>
                                        </div>

                                        <button
                                            type="button"
                                            class="modal-close"
                                            onclick="closeReportModal('<?= $modalId ?>')"
                                            aria-label="Close">
                                            &times;
                                        </button>

                                    </div>


                                    <div class="modal-body">

                                        <!-- STATUS -->

                                        <div class="detail-status">

                                            <span class="detail-label">
                                                CURRENT STATUS
                                            </span>

                                            <span class="status-badge <?= myReportStatusClass($report['status']) ?>">
                                                <span class="status-dot"></span>
                                                <?= myReportEscape($report['status']) ?>
                                            </span>

                                        </div>


                                        <!-- LOCATION -->

                                        <div class="detail-group">

                                            <label>Reported Location</label>

                                            <div class="detail-value">
                                                <?= myReportEscape($report['location']) ?>
                                            </div>

                                        </div>


                                        <!-- DESCRIPTION -->

                                        <div class="detail-group">

                                            <label>Incident Description</label>

                                            <div class="detail-description">
                                                <?= nl2br(myReportEscape($report['description'])) ?>
                                            </div>

                                        </div>


                                        <!-- GPS -->

                                        <?php if (
                                            $report['latitude'] !== null &&
                                            $report['longitude'] !== null
                                        ): ?>

                                            <div class="detail-group">

                                                <label>GPS Coordinates</label>

                                                <div class="detail-value coordinates-value">

                                                    <?= myReportEscape($report['latitude']) ?>,
                                                    <?= myReportEscape($report['longitude']) ?>

                                                </div>

                                            </div>

                                        <?php endif; ?>


                                        <!-- IMAGE PROOF -->

                                        <div class="detail-group">

                                            <label>Submitted Image Proof</label>

                                            <?php if ($imagePath !== ''): ?>

                                                <a href="<?= myReportEscape($imagePath) ?>"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="proof-link">

                                                    <img
                                                        src="<?= myReportEscape($imagePath) ?>"
                                                        class="proof-image"
                                                        alt="Submitted flood incident proof">

                                                    <span>
                                                        Click image to view full size
                                                    </span>

                                                </a>

                                            <?php else: ?>

                                                <div class="no-proof">
                                                    No image proof was submitted.
                                                </div>

                                            <?php endif; ?>

                                        </div>


                                        <!-- SUBMISSION DATE -->

                                        <div class="detail-group">

                                            <label>Date Submitted</label>

                                            <div class="detail-value">
                                                <?= myReportEscape($date) ?>
                                            </div>

                                        </div>


                                        <div class="response-divider"></div>


                                        <!-- ADMIN RESPONSE -->

                                        <div class="response-section">

                                            <div class="response-title">

                                                <div>
                                                    <span class="modal-eyebrow">
                                                        ADMINISTRATOR
                                                    </span>

                                                    <h3>Response / Updates</h3>
                                                </div>

                                                <span class="response-icon">✓</span>

                                            </div>


                                            <?php if (!empty($report['admin_response'])): ?>

                                                <div class="response-message">

                                                    <?= nl2br(myReportEscape($report['admin_response'])) ?>

                                                </div>

                                                <?php if ($responseDate !== ''): ?>

                                                    <div class="response-date">
                                                        Last updated:
                                                        <?= myReportEscape($responseDate) ?>
                                                    </div>

                                                <?php endif; ?>

                                            <?php else: ?>

                                                <div class="waiting-message">

                                                    <span class="waiting-dot"></span>

                                                    <div>
                                                        <strong>Awaiting administrator response</strong>

                                                        <p>
                                                            Your report has been submitted.
                                                            Please wait while the administrator
                                                            reviews your concern.
                                                        </p>
                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </div>


                                    <div class="modal-footer">

                                        <button
                                            type="button"
                                            class="close-modal-button"
                                            onclick="closeReportModal('<?= $modalId ?>')">

                                            Close

                                        </button>

                                    </div>

                                </div>

                            </div>

                            <?php $reportModalsHtml .= ob_get_clean(); ?>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- PAGINATION -->

        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <div class="pagination-info">

                    Showing
                    <?= $offset + 1 ?>
                    to
                    <?= min($offset + $limit, $totalReports) ?>
                    of
                    <?= $totalReports ?>
                    reports

                </div>

                <div class="pagination-links">

                    <?php if ($page > 1): ?>

                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">
                            Previous
                        </a>

                    <?php endif; ?>


                    <?php

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>

                        <a
                            href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                            class="<?= $i === $page ? 'active' : '' ?>">

                            <?= $i ?>

                        </a>

                    <?php endfor; ?>


                    <?php if ($page < $totalPages): ?>

                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">
                            Next
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>
        </div>

    </section>

</main>

<?= $reportModalsHtml ?>

<div class="report-modal" id="editReportModal" aria-hidden="true">
    <div class="report-modal-backdrop" onclick="closeReportModal('editReportModal')"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <span class="modal-eyebrow">UPDATE SUBMITTED REPORT</span>
                <h2>Edit Flood Report</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeReportModal('editReportModal')" aria-label="Close">
                &times;
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="editReportForm" class="edit-report-form">
            <input type="hidden" name="csrf_token" value="<?= myReportEscape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="report_action" value="edit">
            <input type="hidden" name="report_id" id="editReportId">
            <input type="hidden" name="return_search" value="<?= myReportEscape($search) ?>">
            <input type="hidden" name="return_page" value="<?= (int) $page ?>">

            <div class="modal-body">
                <div class="edit-report-fields">
                    <div class="form-group">
                        <label for="editReportLocation">Flood Location</label>
                        <input type="text" name="location" id="editReportLocation" maxlength="255" required>
                    </div>

                    <div class="coordinate-grid">
                        <div class="form-group">
                            <label for="editReportLatitude">Latitude</label>
                            <input type="number" name="latitude" id="editReportLatitude" min="-90" max="90" step="any">
                        </div>
                        <div class="form-group">
                            <label for="editReportLongitude">Longitude</label>
                            <input type="number" name="longitude" id="editReportLongitude" min="-180" max="180" step="any">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="editReportWaterLevel">Water Depth (cm)</label>
                        <input type="number" name="water_level_cm" id="editReportWaterLevel" min="0" max="99999.99" step="0.01" required>
                    </div>

                    <div class="form-group">
                        <label for="editReportDescription">Report Description</label>
                        <textarea name="description" id="editReportDescription" rows="5" maxlength="5000" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="editReportImage">Replace Image Proof (optional)</label>
                        <input type="file" name="image" id="editReportImage" accept="image/jpeg,image/png,image/webp">
                        <small>Leave empty to keep the current image. JPG, PNG, or WEBP up to 5 MB.</small>
                    </div>
                </div>
            </div>

            <div class="modal-footer edit-report-footer">
                <button type="button" class="close-modal-button" onclick="closeReportModal('editReportModal')">
                    Cancel
                </button>
                <button type="submit" class="save-report-button">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<style>

/* =====================================
   MAIN PAGE
===================================== */

.reports-page {
    max-width: 1500px;
    margin: auto;
    padding: 28px;
    color: #263449;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 28px;
}

.eyebrow {
    display: block;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.2px;
    margin-bottom: 8px;
}

.page-heading h1 {
    font-size: 26px;
    font-weight: 700;
    margin: 0 0 7px;
    color: #1e293b;
}

.page-heading p {
    font-size: 13px;
    color: #8491a3;
    margin: 0;
    line-height: 1.6;
}

.heading-info {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #64748b;
    font-size: 12px;
    white-space: nowrap;
}

.heading-count {
    font-size: 21px;
    font-weight: 750;
    color: #1d4ed8;
}


/* =====================================
   ALERT
===================================== */

.report-alert {
    padding: 13px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    border: 1px solid;
}

.report-alert.success {
    color: #166534;
    background: #f0fdf4;
    border-color: #bbf7d0;
}

.report-alert.error {
    color: #991b1b;
    background: #fef2f2;
    border-color: #fecaca;
}


/* =====================================
   REPORTS SECTION
===================================== */

.reports-section {
    background: #fff;
    border: 1px solid #e5eaf0;
    padding: 24px;
    margin-bottom: 25px;
}

.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 21px;
}

.section-heading h2 {
    font-size: 17px;
    font-weight: 700;
    color: #263449;
    margin: 0 0 6px;
}

.section-heading p {
    font-size: 12px;
    color: #8793a4;
    margin: 0;
}


/* =====================================
   SEARCH - RIGHT ALIGNED
===================================== */

.table-toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
}

.search-box {
    display: flex;
    align-items: center;
    width: 100%;
    max-width: 350px;
    height: 40px;
    border: 1px solid #dce2e9;
    padding: 0 12px;
    background: #fff;
}

.search-box:focus-within {
    border-color: #3b82f6;
}

.search-icon {
    font-size: 21px;
    color: #94a3b8;
    margin-right: 8px;
}

.search-box input {
    border: none;
    outline: none;
    width: 100%;
    height: 100%;
    font-size: 12px;
    color: #334155;
    background: transparent;
}

.clear-button {
    height: 40px;
    padding: 0 14px;
    border: 1px solid #dce2e9;
    background: #fff;
    color: #475569;
    font-size: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.clear-button:hover {
    background: #f1f5f9;
}


/* =====================================
   TABLE
===================================== */

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.reports-table {
    width: 100%;
    min-width: 750px;
    border-collapse: collapse;
}

.reports-table thead th {
    padding: 14px 15px;
    background: #f7f9fc;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    text-align: left;
    letter-spacing: .45px;
    border-top: 1px solid #e8edf2;
    border-bottom: 1px solid #e8edf2;
    white-space: nowrap;
}

.reports-table tbody td {
    padding: 17px 15px;
    border-bottom: 1px solid #edf0f4;
    vertical-align: middle;
    font-size: 12px;
}

.reports-table tbody tr {
    transition: background .15s ease;
}

.reports-table tbody tr:hover {
    background: #f8faff;
}

.location-name {
    font-size: 12px;
    color: #263449;
    font-weight: 750;
    margin-bottom: 5px;
}

.location-subtitle {
    color: #94a3b8;
    font-size: 10px;
}

.date-main {
    color: #64748b;
    font-size: 11px;
    white-space: nowrap;
}

.action-heading,
.action-cell {
    text-align: center !important;
}

.action-cell {
    white-space: nowrap;
}

.action-cell form {
    display: inline-flex;
    margin: 0;
}


/* =====================================
   STATUS
===================================== */

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 9px;
    font-size: 10px;
    font-weight: 750;
    white-space: nowrap;
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
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


/* =====================================
   VIEW BUTTON
===================================== */

.view-button,
.edit-button,
.delete-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    padding: 0;
    cursor: pointer;
    transition: .2s ease;
}

.view-button svg,
.edit-button svg,
.delete-button svg {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.view-button,
.edit-button,
.delete-button {
    background: #eff6ff;
    border: 1px solid #dbeafe;
    color: #1d4ed8;
}

.edit-button,
.delete-button {
    margin-left: 4px;
}

.view-button:hover,
.edit-button:hover,
.delete-button:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
}

.view-button:focus-visible,
.edit-button:focus-visible,
.delete-button:focus-visible {
    outline: 3px solid rgb(59 130 246 / 30%);
    outline-offset: 2px;
}


/* =====================================
   EMPTY STATE
===================================== */

.empty-cell {
    text-align: center;
    padding: 55px 15px !important;
}

.empty-icon {
    font-size: 32px;
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 15px;
    color: #334155;
    margin: 0 0 7px;
}

.empty-state p {
    font-size: 12px;
    color: #94a3b8;
    margin: 0 0 12px;
}

.empty-link {
    display: inline-block;
    font-size: 12px;
    color: #1d4ed8;
    font-weight: 650;
    text-decoration: none;
}

.empty-link:hover {
    text-decoration: underline;
}


/* =====================================
   REPORT DETAILS MODAL
===================================== */

.report-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

.swal2-container {
    z-index: 10000 !important;
}

.report-modal.show {
    display: flex;
}

.report-modal-backdrop {
    position: absolute;
    inset: 0;
    z-index: 0;
    background: rgba(15, 23, 42, .65);
    backdrop-filter: blur(2px);
}

.modal-dialog {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    pointer-events: auto;
    width: 100%;
    max-width: 650px;
    height: min(90vh, 900px);
    height: min(90dvh, 900px);
    max-height: calc(100vh - 40px);
    max-height: calc(100dvh - 40px);
    min-height: 0;
    overflow: hidden;
    background: #fff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
    animation: modalOpen .2s ease;
}

@keyframes modalOpen {
    from {
        opacity: 0;
        transform: translateY(10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 23px;
    border-bottom: 1px solid #edf0f4;
}

.modal-eyebrow {
    display: block;
    color: #64748b;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    margin-bottom: 6px;
}

.modal-header h2 {
    font-size: 19px;
    color: #1e293b;
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border: none;
    background: #f1f5f9;
    color: #64748b;
    font-size: 23px;
    cursor: pointer;
}

.modal-close:hover {
    background: #e2e8f0;
    color: #1e293b;
}

.modal-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 22px 23px;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

.detail-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding-bottom: 17px;
    margin-bottom: 19px;
    border-bottom: 1px solid #edf0f4;
}

.detail-label,
.detail-group label {
    display: block;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .5px;
}

.detail-group {
    margin-bottom: 19px;
}

.detail-group label {
    margin-bottom: 8px;
}

.detail-value {
    color: #334155;
    font-size: 12px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.detail-description {
    color: #475569;
    font-size: 12px;
    line-height: 1.8;
    padding: 12px;
    background: #f8fafc;
    border: 1px solid #edf0f4;
    overflow-wrap: anywhere;
}

.coordinates-value {
    font-family: monospace;
}

.proof-link {
    display: inline-flex;
    flex-direction: column;
    gap: 7px;
    color: #64748b;
    font-size: 10px;
    text-decoration: none;
}

.proof-image {
    display: block;
    width: 100%;
    max-width: 300px;
    max-height: 220px;
    object-fit: cover;
    border: 1px solid #e2e8f0;
    padding: 3px;
    background: #fff;
}

.no-proof {
    padding: 13px;
    background: #f8fafc;
    border: 1px solid #edf0f4;
    color: #94a3b8;
    font-size: 11px;
}


/* =====================================
   ADMIN RESPONSE
===================================== */

.response-divider {
    height: 1px;
    background: #edf0f4;
    margin: 22px 0;
}

.response-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.response-title h3 {
    color: #263449;
    font-size: 15px;
    margin: 0;
}

.response-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    background: #eaf2ff;
    color: #1d4ed8;
    font-size: 15px;
    font-weight: 800;
}

.response-message {
    padding: 14px;
    background: #f0f6ff;
    border-left: 3px solid #3b82f6;
    color: #334155;
    font-size: 12px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.response-date {
    color: #94a3b8;
    font-size: 10px;
    margin-top: 9px;
}

.waiting-message {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px;
    background: #fffbeb;
    border: 1px solid #fef3c7;
}

.waiting-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #eab308;
    margin-top: 4px;
    flex-shrink: 0;
}

.waiting-message strong {
    color: #92400e;
    font-size: 11px;
}

.waiting-message p {
    color: #a16207;
    font-size: 11px;
    line-height: 1.6;
    margin: 5px 0 0;
}


/* =====================================
   MODAL FOOTER
===================================== */

.modal-footer {
    display: flex;
    justify-content: flex-end;
    padding: 15px 23px;
    border-top: 1px solid #edf0f4;
}

.close-modal-button {
    padding: 9px 20px;
    border: 1px solid #dbe1e9;
    background: #fff;
    color: #475569;
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
}

.close-modal-button:hover {
    background: #f1f5f9;
}

.edit-report-form {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
}

.edit-report-fields {
    display: grid;
    gap: 16px;
}

.edit-report-fields .form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin: 0;
}

.edit-report-fields label {
    color: #475569;
    font-size: 12px;
    font-weight: 650;
}

.edit-report-fields input,
.edit-report-fields textarea {
    box-sizing: border-box;
    width: 100%;
    min-height: 40px;
    padding: 10px 11px;
    border: 1px solid #dbe1e9;
    border-radius: 4px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: 12px;
}

.edit-report-fields textarea {
    min-height: 110px;
    resize: vertical;
}

.edit-report-fields small {
    color: #94a3b8;
    font-size: 10px;
}

.edit-report-fields .coordinate-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.edit-report-footer {
    flex: 0 0 auto;
    gap: 9px;
}

.save-report-button {
    padding: 9px 16px;
    border: 1px solid #1d4ed8;
    border-radius: 4px;
    background: #1d4ed8;
    color: #fff;
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
}

.save-report-button:hover {
    background: #1e40af;
}

.save-report-button:focus-visible,
.close-modal-button:focus-visible {
    outline: 3px solid rgb(59 130 246 / 30%);
    outline-offset: 2px;
}


/* =====================================
   PAGINATION
===================================== */

.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-top: 19px;
    flex-wrap: wrap;
}

.pagination-info {
    color: #94a3b8;
    font-size: 11px;
}

.pagination-links {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.pagination-links a {
    padding: 8px 11px;
    border: 1px solid #dbe1e9;
    color: #64748b;
    text-decoration: none;
    font-size: 11px;
}

.pagination-links a:hover {
    background: #eff6ff;
    color: #1d4ed8;
}

.pagination-links a.active {
    background: #36456c;
    color: #fff;
    border-color: #33426b;
}


/* =====================================
   RESPONSIVE
===================================== */

@media(max-width: 650px) {

    .reports-page {
        padding: 15px;
    }

    .page-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .heading-info {
        margin-top: 5px;
    }

    .reports-section {
        padding: 15px;
    }

    .table-toolbar {
        width: 100%;
    }

    .search-box {
        max-width: none;
    }

    .pagination {
        align-items: flex-start;
        flex-direction: column;
    }

    .pagination-links {
        width: 100%;
        justify-content: center;
    }

    .report-modal {
        padding: 10px;
    }

    .modal-dialog {
        height: min(94vh, 900px);
        height: min(94dvh, 900px);
        max-height: calc(100vh - 20px);
        max-height: calc(100dvh - 20px);
    }

    .modal-header,
    .modal-body {
        padding: 17px;
    }

    .edit-report-fields .coordinate-grid {
        grid-template-columns: 1fr;
    }

}

</style>


<script>
<?php if ($reportMessage !== '' && $reportMessageType === 'success'): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal === 'undefined') {
        console.error('SweetAlert2 is not loaded; report success message cannot be displayed.');
        return;
    }

    Swal.fire({
        icon: 'success',
        title: <?= json_encode(
            $reportMessageTitle !== '' ? $reportMessageTitle : 'Success!',
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?>,
        text: <?= json_encode(
            $reportMessage,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?>,
        confirmButtonText: 'OK',
        confirmButtonColor: '#1d4ed8',
        heightAuto: false
    });
});
<?php endif; ?>

// =====================================
// OPEN REPORT MODAL
// =====================================

function openReportModal(modalId) {

    const modal = document.getElementById(modalId);

    if (!modal) {
        return;
    }

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');

    document.body.style.overflow = 'hidden';

}


// =====================================
// CLOSE REPORT MODAL
// =====================================

function closeReportModal(modalId) {

    const modal = document.getElementById(modalId);

    if (!modal) {
        return;
    }

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');

    document.body.style.overflow = '';

}

function openEditReport(button) {
    const form = document.getElementById('editReportForm');
    if (!form) {
        console.error('The edit report form is unavailable.');
        return;
    }

    document.getElementById('editReportId').value = button.dataset.reportId || '';
    document.getElementById('editReportLocation').value = button.dataset.location || '';
    document.getElementById('editReportLatitude').value = button.dataset.latitude || '';
    document.getElementById('editReportLongitude').value = button.dataset.longitude || '';
    document.getElementById('editReportDescription').value = button.dataset.description || '';
    document.getElementById('editReportWaterLevel').value = button.dataset.waterLevel || '';
    document.getElementById('editReportImage').value = '';

    openReportModal('editReportModal');
    document.getElementById('editReportLocation').focus();
}

document.addEventListener('submit', function(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('.delete-report-form')) {
        return;
    }

    event.preventDefault();

    if (form.dataset.confirmed === 'true') {
        return;
    }

    if (typeof Swal === 'undefined') {
        console.error('SweetAlert2 is not loaded; report deletion was cancelled.');
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: 'Delete this report?',
        text: 'This pending flood report and its image proof will be permanently deleted.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#be123c',
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

document.getElementById('editReportForm').addEventListener('submit', function(event) {
    event.preventDefault();

    const form = this;
    if (form.dataset.confirmed === 'true') {
        return;
    }

    if (typeof Swal === 'undefined') {
        console.error('SweetAlert2 is not loaded; report update was cancelled.');
        return;
    }

    Swal.fire({
        icon: 'question',
        title: 'Save report changes?',
        text: 'Your updated flood report will be sent to the administrator.',
        showCancelButton: true,
        confirmButtonText: 'Save Changes',
        cancelButtonText: 'Continue Editing',
        confirmButtonColor: '#1d4ed8',
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


// =====================================
// CLOSE WITH ESCAPE KEY
// =====================================

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {

        const openModal = document.querySelector('.report-modal.show');

        if (openModal) {
            closeReportModal(openModal.id);
        }

    }

});


</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
