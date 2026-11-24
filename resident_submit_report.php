
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
// WATER LEVEL THRESHOLDS
// =====================================

function getWaterLevelStatus($waterLevel)
{
    if ($waterLevel >= 60) {
        return 'Critical';
    }

    if ($waterLevel >= 30) {
        return 'Warning';
    }

    return 'Normal';
}

// =====================================
// HELPERS
// =====================================

function submitReportEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function submitReportRedirect()
{
    header('Location: resident_submit_report.php');
    exit;
}

function submitReportMessage($message, $type = 'error')
{
    $_SESSION['report_message'] = $message;
    $_SESSION['report_message_type'] = $type;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =====================================
// CREATE TABLE IF NOT EXISTS
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
// HANDLE REPORT SUBMISSION
// =====================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF validation
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
    ) {
        submitReportMessage('Your session expired. Please refresh and try again.');
        submitReportRedirect();
    }

    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $latitudeInput = trim($_POST['latitude'] ?? '');
    $longitudeInput = trim($_POST['longitude'] ?? '');

    // =================================
    // VALIDATE WATER LEVEL
    // =================================

    $waterLevelInput = trim($_POST['water_level_cm'] ?? '');

    if (
        $waterLevelInput === '' ||
        !is_numeric($waterLevelInput) ||
        !is_finite((float) $waterLevelInput)
    ) {
        submitReportMessage('Please enter a valid water depth in centimeters.');
        submitReportRedirect();
    }

    $waterLevel = (float) $waterLevelInput;

    if ($waterLevel < 0 || $waterLevel > 99999.99) {
        submitReportMessage('Water depth must be between 0 and 99,999.99 cm.');
        submitReportRedirect();
    }

    // Automatically determine water level category
    $waterLevelStatus = getWaterLevelStatus($waterLevel);

    // =================================
    // VALIDATE REQUIRED FIELDS
    // =================================

    if ($location === '' || strlen($location) > 255) {
        submitReportMessage('Please enter a valid flood location.');
        submitReportRedirect();
    }

    if ($description === '' || strlen($description) > 5000) {
        submitReportMessage('Please enter a report description. Maximum length is 5,000 characters.');
        submitReportRedirect();
    }

    // =================================
    // VALIDATE OPTIONAL GPS COORDINATES
    // =================================

    $latitude = null;
    $longitude = null;

    if ($latitudeInput !== '' || $longitudeInput !== '') {

        if (
            $latitudeInput === '' ||
            $longitudeInput === '' ||
            !is_numeric($latitudeInput) ||
            !is_numeric($longitudeInput)
        ) {
            submitReportMessage('Please provide both valid GPS coordinates.');
            submitReportRedirect();
        }

        $latitudeValue = (float) $latitudeInput;
        $longitudeValue = (float) $longitudeInput;

        if (
            $latitudeValue < -90 || $latitudeValue > 90 ||
            $longitudeValue < -180 || $longitudeValue > 180
        ) {
            submitReportMessage('The GPS coordinates are outside the valid range.');
            submitReportRedirect();
        }

        $latitude = number_format($latitudeValue, 7, '.', '');
        $longitude = number_format($longitudeValue, 7, '.', '');
    }

    // =================================
    // VALIDATE IMAGE UPLOAD
    // =================================

    if (
        !isset($_FILES['image']) ||
        $_FILES['image']['error'] !== UPLOAD_ERR_OK
    ) {
        submitReportMessage('Please upload an image as proof of the flood incident.');
        submitReportRedirect();
    }

    $uploadedFile = $_FILES['image'];

    $maxFileSize = 5 * 1024 * 1024;

    if ($uploadedFile['size'] <= 0 || $uploadedFile['size'] > $maxFileSize) {
        submitReportMessage('The image must not exceed 5 MB.');
        submitReportRedirect();
    }

    if (!is_uploaded_file($uploadedFile['tmp_name'])) {
        submitReportMessage('Invalid image upload.');
        submitReportRedirect();
    }

    // Check actual MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($uploadedFile['tmp_name']);

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedMimeTypes[$mimeType])) {
        submitReportMessage('Only JPG, PNG, and WEBP images are allowed.');
        submitReportRedirect();
    }

    if (getimagesize($uploadedFile['tmp_name']) === false) {
        submitReportMessage('The uploaded file is not a valid image.');
        submitReportRedirect();
    }

    // =================================
    // SAVE IMAGE
    // =================================

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR .
        'uploads' . DIRECTORY_SEPARATOR .
        'flood_reports';

    if (
        !is_dir($uploadDirectory) &&
        !mkdir($uploadDirectory, 0755, true) &&
        !is_dir($uploadDirectory)
    ) {
        submitReportMessage('Unable to create the image upload folder.');
        submitReportRedirect();
    }

    $extension = $allowedMimeTypes[$mimeType];
    $imageName = bin2hex(random_bytes(16)) . '.' . $extension;
    $imageDestination = $uploadDirectory . DIRECTORY_SEPARATOR . $imageName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $imageDestination)) {
        submitReportMessage('Unable to save the uploaded image. Please try again.');
        submitReportRedirect();
    }

    // =================================
    // INSERT REPORT INTO DATABASE
    // =================================

    $stmt = $conn->prepare("
        INSERT INTO flood_reports (
            user_id,
            location,
            latitude,
            longitude,
            description,
            image,
            water_level_cm,
            water_level_status,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");

    if (!$stmt) {

        if (is_file($imageDestination)) {
            unlink($imageDestination);
        }

        error_log('Flood report insert prepare error: ' . $conn->error);

        submitReportMessage('Unable to prepare your report. Please try again.');
        submitReportRedirect();
    }

    $stmt->bind_param(
        'isssssds',
        $userId,
        $location,
        $latitude,
        $longitude,
        $description,
        $imageName,
        $waterLevel,
        $waterLevelStatus
    );

    $success = $stmt->execute();

    if (!$success) {
        error_log('Flood report insert error: ' . $stmt->error);
    }

    $stmt->close();

    if (!$success) {

        if (is_file($imageDestination)) {
            unlink($imageDestination);
        }

        submitReportMessage('Unable to submit your report. Please try again.');
        submitReportRedirect();
    }

    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    submitReportMessage(
        'Your flood report has been submitted successfully. Water level: ' .
        number_format($waterLevel, 2) . ' cm (' . $waterLevelStatus . ').',
        'success'
    );
    $_SESSION['report_message_title'] = 'Report Submitted!';

    header('Location: resident_my_reports.php');
    exit;
}

// =====================================
// FLASH MESSAGE
// =====================================

$reportMessage = $_SESSION['report_message'] ?? '';
$reportMessageType = $_SESSION['report_message_type'] ?? '';

unset($_SESSION['report_message'], $_SESSION['report_message_type']);

$pageTitle = 'Submit Flood Report';
$activePage = 'resident_submit';

require_once __DIR__ . '/includes/header.php';
?>

<div class="reports-page">

    <div class="page-heading">
        <div>
            
        </div>
    </div>

    <section class="report-card">

        <div class="card-heading">
            <div>
                <h2>Flood Incident Information</h2>
                <p>Provide accurate information and image proof of the flooding.</p>
            </div>
            <span class="required-note"><span>*</span> Required fields</span>
        </div>

        <form
            method="POST"
            action="resident_submit_report.php"
            enctype="multipart/form-data"
            id="reportForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= submitReportEscape($_SESSION['csrf_token']) ?>"
            >

            <!-- FLOOD LOCATION -->

            <div class="form-group">
                <label for="location">Flood Location <span>*</span></label>

                <input
                    type="text"
                    name="location"
                    id="location"
                    maxlength="255"
                    placeholder="Enter the complete location or address"
                    required
                >
            </div>

            <!-- GPS COORDINATES -->

            <div class="gps-section">
                <div>
                    <strong>GPS Coordinates</strong>
                    <p>GPS on your phone requires a secure HTTPS connection.</p>
                </div>

                <button type="button" id="getLocationBtn" class="gps-button">
                    Get My Location
                </button>
            </div>

            <div class="coordinate-grid">

                <div class="form-group">
                    <label for="latitude">Latitude</label>
                    <input
                        type="text"
                        name="latitude"
                        id="latitude"
                        placeholder="Latitude"
                        readonly
                    >
                </div>

                <div class="form-group">
                    <label for="longitude">Longitude</label>
                    <input
                        type="text"
                        name="longitude"
                        id="longitude"
                        placeholder="Longitude"
                        readonly
                    >
                </div>

            </div>

            <!-- WATER LEVEL INPUT -->

            <div class="water-level-section">

                <div class="water-heading">
                    <div>
                        <h3>Water Level Measurement</h3>
                        <p>Enter the measured floodwater depth in centimeters.</p>
                    </div>

                </div>

                <div class="form-group water-input-group">
                    <label for="water_level_cm">
                        Water Depth (cm) <span>*</span>
                    </label>

                    <div class="water-input-wrapper">
                        <input
                            type="number"
                            name="water_level_cm"
                            id="water_level_cm"
                            min="0"
                            max="99999.99"
                            step="0.01"
                            placeholder="Example: 45"
                            required
                        >
                        <span>CM</span>
                    </div>

                    <small>Measure the water depth from the ground to the water surface.</small>
                </div>

                <div class="water-result" id="waterResult">

                    <div>
                        <span class="result-label">AUTOMATIC WATER LEVEL STATUS</span>
                        <strong id="waterStatusText">Waiting for measurement</strong>
                    </div>

                    <span class="water-status-badge" id="waterStatusBadge">
                        Not Set
                    </span>

                </div>

                <div class="water-guide">

                    <div class="guide-item normal">
                        <div>
                            <strong>Normal</strong>
                            <small>0–29.99 cm</small>
                        </div>
                    </div>

                    <div class="guide-item warning">
                        <div>
                            <strong>Warning</strong>
                            <small>30–59.99 cm</small>
                        </div>
                    </div>

                    <div class="guide-item critical">
                        <div>
                            <strong>Critical</strong>
                            <small>60 cm and above</small>
                        </div>
                    </div>

                </div>

                <p class="water-disclaimer">
                    The water-level category is automatically calculated based on
                    the entered measurement. These are demonstration thresholds
                    and should be confirmed by your local disaster risk reduction office.
                </p>

            </div>

            <!-- DESCRIPTION -->

            <div class="form-group">
                <label for="description">Report Description <span>*</span></label>

                <textarea
                    name="description"
                    id="description"
                    rows="5"
                    maxlength="5000"
                    placeholder="Describe the flooding situation, affected areas, or people needing assistance..."
                    required
                ></textarea>
            </div>

            <!-- IMAGE PROOF -->

            <div class="form-group">
                <label for="image">Image Proof <span>*</span></label>

                <div class="upload-area">

                    <input
                        type="file"
                        name="image"
                        id="image"
                        accept="image/jpeg,image/png,image/webp"
                        required
                    >

                    <p>Accepted formats: JPG, PNG, WEBP. Maximum file size: 5 MB.</p>

                    <div id="imagePreviewContainer" class="image-preview-container">
                        <img id="imagePreview" src="" alt="Flood image preview">
                    </div>

                </div>
            </div>

            <div class="form-note">
                <strong>Important:</strong>
                Your report will be marked as Pending after submission.
                The administrator will review your report and send a response.
            </div>

            <div class="form-actions">

                <button
                    type="submit"
                    name="submit_report"
                    class="btn-primary"
                    id="submitReportBtn"
                >
                    Submit Flood Report
                </button>

            </div>

        </form>

    </section>

</div>


<style>
.reports-page {
    box-sizing: border-box;
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 28px;
    color: #334155;
}

.reports-page .page-heading {
    margin-bottom: 22px;
}

.reports-page .eyebrow {
    display: block;
    margin-bottom: 7px;
    color: #64748b;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
}

.reports-page .page-heading h1 {
    margin: 0 0 7px;
    color: #1e293b;
    font-size: 25px;
}

.reports-page .page-heading p,
.reports-page .card-heading p {
    margin: 0;
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
}

.reports-page .report-card {
    padding: 24px;
    border: 1px solid #e2e8f0;
    background: #fff;
}

.reports-page .card-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 18px;
    margin-bottom: 22px;
    border-bottom: 1px solid #e8edf2;
}

.reports-page .card-heading h2 {
    margin: 0 0 5px;
    color: #1e293b;
    font-size: 16px;
}

.reports-page .required-note {
    color: #64748b;
    font-size: 11px;
    white-space: nowrap;
}

.reports-page .required-note span {
    color: #dc2626;
    font-weight: 700;
}

.reports-page .form-group {
    margin-bottom: 18px;
}

.reports-page .form-group label {
    display: block;
    margin-bottom: 7px;
    color: #334155;
    font-size: 12px;
    font-weight: 600;
}

.reports-page .form-group label span {
    color: #dc2626;
}

.reports-page .form-group input,
.reports-page .form-group textarea {
    box-sizing: border-box;
    width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    outline: none;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: 13px;
}

.reports-page .form-group textarea {
    min-height: 120px;
    resize: vertical;
    line-height: 1.6;
}

.reports-page .form-group input:focus,
.reports-page .form-group textarea:focus {
    border-color: #64748b;
    box-shadow: 0 0 0 2px rgb(100 116 139 / 12%);
}

.reports-page .gps-section {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 0;
    margin-bottom: 16px;
    border-block: 1px solid #e8edf2;
}

.reports-page .gps-section strong {
    color: #334155;
    font-size: 12px;
}

.reports-page .gps-section p {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 11px;
}

.reports-page .gps-button {
    padding: 8px 11px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    background: #fff;
    color: #334155;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: .2s ease;
}

.reports-page .gps-button:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}

.reports-page .gps-button:active {
    transform: translateY(1px);
}

.reports-page .gps-button:focus-visible,
.reports-page .btn-primary:focus-visible {
    outline: 3px solid rgb(59 130 246 / 30%);
    outline-offset: 2px;
}

.reports-page .gps-button:disabled {
    opacity: .6;
    cursor: wait;
}

.reports-page .coordinate-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.reports-page .water-level-section {
    padding: 18px 0;
    margin: 4px 0 22px;
    border-block: 1px solid #e8edf2;
}

.reports-page .water-heading {
    margin-bottom: 17px;
}

.reports-page .water-heading h3 {
    margin: 0 0 5px;
    color: #263449;
    font-size: 14px;
}

.reports-page .water-heading p,
.reports-page .water-input-group small {
    margin: 0;
    color: #64748b;
    font-size: 11px;
}

.reports-page .water-input-wrapper {
    position: relative;
}

.reports-page .water-input-wrapper input {
    padding-right: 52px;
}

.reports-page .water-input-wrapper > span {
    position: absolute;
    top: 50%;
    right: 12px;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    pointer-events: none;
}

.reports-page .water-input-group small {
    display: block;
    margin-top: 6px;
}

.reports-page .water-result {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 0;
    margin-bottom: 12px;
    border-block: 1px solid #e8edf2;
}

.reports-page .result-label {
    display: block;
    margin-bottom: 4px;
    color: #64748b;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .5px;
}

.reports-page .water-result strong {
    color: #334155;
    font-size: 12px;
}

.reports-page .water-status-badge {
    padding: 5px 8px;
    background: #f1f5f9;
    color: #475569;
    font-size: 10px;
    font-weight: 700;
}

.reports-page .water-status-badge.normal {
    background: #f0fdf4;
    color: #166534;
}

.reports-page .water-status-badge.warning {
    background: #fffbeb;
    color: #92400e;
}

.reports-page .water-status-badge.critical {
    background: #fef2f2;
    color: #991b1b;
}

.reports-page .water-guide {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

.reports-page .guide-item {
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    background: #fff;
}

.reports-page .guide-item strong {
    display: block;
    color: #334155;
    font-size: 11px;
}

.reports-page .guide-item small {
    display: block;
    margin-top: 3px;
    color: #64748b;
    font-size: 10px;
}

.reports-page .water-disclaimer {
    margin: 12px 0 0;
    color: #64748b;
    font-size: 10px;
    line-height: 1.5;
}

.reports-page .upload-area {
    padding: 14px;
    border: 1px dashed #cbd5e1;
    background: #f8fafc;
}

.reports-page .upload-area input {
    width: 100%;
    border: 0;
    background: transparent;
}

.reports-page .upload-area p {
    margin: 8px 0 0;
    color: #64748b;
    font-size: 10px;
}

.reports-page .image-preview-container {
    display: none;
    margin-top: 14px;
}

.reports-page .image-preview-container img {
    max-width: 250px;
    max-height: 190px;
    border: 1px solid #e2e8f0;
    object-fit: cover;
}

.reports-page .form-note {
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    line-height: 1.6;
}

.reports-page .form-note strong {
    color: #334155;
}

.reports-page .form-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
}

.reports-page .btn-primary {
    padding: 10px 16px;
    border: 1px solid #334155;
    border-radius: 4px;
    background: #334155;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: .2s ease;
}

.reports-page .btn-primary:hover {
    border-color: #1e293b;
    background: #1e293b;
}

.reports-page .btn-primary:active {
    transform: translateY(1px);
}

.reports-page .btn-primary:disabled {
    opacity: .65;
    cursor: not-allowed;
}

@media (max-width: 650px) {
    .reports-page {
        padding: 20px 16px;
    }

    .reports-page .report-card {
        padding: 17px;
    }

    .reports-page .card-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 8px;
    }

    .reports-page .coordinate-grid,
    .reports-page .water-guide {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .reports-page .gps-section {
        align-items: flex-start;
        flex-direction: column;
    }

    .reports-page .form-actions .btn-primary {
        width: 100%;
    }
}
</style>

<!-- SWEETALERT2 -->


<script>
// =====================================
// PHP FLASH MESSAGE
// =====================================

const reportMessage = <?= json_encode(
    $reportMessage,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const reportMessageType = <?= json_encode(
    $reportMessageType,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

if (reportMessage !== '') {

    Swal.fire({
        icon: reportMessageType === 'success' ? 'success' : 'error',
        title: reportMessageType === 'success'
            ? 'Report Submitted!'
            : 'Submission Failed',
        text: reportMessage,
        confirmButtonText: 'Okay',
        confirmButtonColor: '#1d4ed8',
        allowOutsideClick: false
    });

}

// =====================================
// AUTOMATIC WATER LEVEL PREVIEW
// =====================================

const waterLevelInput = document.getElementById('water_level_cm');
const waterStatusText = document.getElementById('waterStatusText');
const waterStatusBadge = document.getElementById('waterStatusBadge');
const waterResult = document.getElementById('waterResult');

waterLevelInput.addEventListener('input', function () {

    const value = this.value;

    waterStatusBadge.className = 'water-status-badge';

    if (value === '' || !Number.isFinite(Number(value)) || Number(value) < 0) {

        waterStatusText.textContent = 'Waiting for measurement';
        waterStatusBadge.textContent = 'Not Set';
        return;
    }

    const level = Number(value);

    if (level > 99999.99) {
        waterStatusText.textContent = 'Invalid measurement';
        waterStatusBadge.textContent = 'Invalid';
        return;
    }

    let status = '';

    if (level >= 60) {
        status = 'Critical';
    } else if (level >= 30) {
        status = 'Warning';
    } else {
        status = 'Normal';
    }

    waterStatusText.textContent = status + ' Water Level';
    waterStatusBadge.textContent = status.toUpperCase();
    waterStatusBadge.classList.add(status.toLowerCase());

});

// =====================================
// GPS LOCATION
// =====================================

const getLocationButton = document.getElementById('getLocationBtn');

getLocationButton.addEventListener('click', function () {

    if (!window.isSecureContext) {
        Swal.fire({
            icon: 'warning',
            title: 'Secure Connection Required',
            text: 'Your browser blocks GPS on this HTTP connection. Open FloodWatch over HTTPS to use GPS, or enter the flood location manually.',
            confirmButtonColor: '#1d4ed8'
        });

        return;
    }

    if (!navigator.geolocation) {

        Swal.fire({
            icon: 'error',
            title: 'Location Unavailable',
            text: 'Geolocation is not supported by your browser.',
            confirmButtonColor: '#1d4ed8'
        });

        return;
    }

    const button = this;

    button.disabled = true;
    button.textContent = 'Getting location...';

    navigator.geolocation.getCurrentPosition(

        function (position) {

            document.getElementById('latitude').value =
                position.coords.latitude.toFixed(7);

            document.getElementById('longitude').value =
                position.coords.longitude.toFixed(7);

            button.disabled = false;
            button.textContent = 'Location Captured';

            Swal.fire({
                icon: 'success',
                title: 'Location Captured',
                text: 'Your GPS coordinates have been added successfully.',
                confirmButtonColor: '#1d4ed8'
            });

        },

        function (error) {

            button.disabled = false;
            button.textContent = 'Get My Location';

            let message = 'Unable to get your location. Please try again or enter the location manually.';

            if (error.code === error.PERMISSION_DENIED) {
                message = 'Location permission was denied. Please allow location access in your browser.';
            } else if (error.code === error.TIMEOUT) {
                message = 'Getting your location took too long. Please try again.';
            } else if (error.code === error.POSITION_UNAVAILABLE) {
                message = 'Your device could not determine its location. Check that location services are enabled and try again.';
            }

            Swal.fire({
                icon: 'error',
                title: 'Location Error',
                text: message,
                confirmButtonColor: '#1d4ed8'
            });

            console.error(error);
        },

        {
            enableHighAccuracy: true,
            timeout: 30000,
            maximumAge: 60000
        }

    );

});

// =====================================
// IMAGE PREVIEW AND VALIDATION
// =====================================

const imageInput = document.getElementById('image');
const imagePreview = document.getElementById('imagePreview');
const imagePreviewContainer = document.getElementById('imagePreviewContainer');

imageInput.addEventListener('change', function () {

    const file = this.files[0];

    imagePreviewContainer.style.display = 'none';
    imagePreview.src = '';

    if (!file) return;

    const allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!allowedTypes.includes(file.type)) {

        Swal.fire({
            icon: 'error',
            title: 'Invalid Image',
            text: 'Only JPG, PNG, and WEBP images are allowed.',
            confirmButtonColor: '#1d4ed8'
        });

        this.value = '';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {

        Swal.fire({
            icon: 'error',
            title: 'File Too Large',
            text: 'The image must not exceed 5 MB.',
            confirmButtonColor: '#1d4ed8'
        });

        this.value = '';
        return;
    }

    const reader = new FileReader();

    reader.onload = function (event) {
        imagePreview.src = event.target.result;
        imagePreviewContainer.style.display = 'block';
    };

    reader.readAsDataURL(file);

});

// =====================================
// REPORT SUBMISSION CONFIRMATION
// =====================================

const reportForm = document.getElementById('reportForm');
const submitButton = document.getElementById('submitReportBtn');

let reportSubmitting = false;
let confirmedSubmission = false;

reportForm.addEventListener('submit', function (event) {

    if (confirmedSubmission) {

        confirmedSubmission = false;
        reportSubmitting = true;

        submitButton.disabled = true;
        submitButton.textContent = 'Submitting...';

        Swal.fire({
            title: 'Submitting Report',
            text: 'Please wait while we send your flood report...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        return;
    }

    event.preventDefault();

    if (reportSubmitting) return;

    const waterLevel = waterLevelInput.value;

    if (
        waterLevel === '' ||
        !Number.isFinite(Number(waterLevel)) ||
        Number(waterLevel) < 0 ||
        Number(waterLevel) > 99999.99
    ) {

        Swal.fire({
            icon: 'warning',
            title: 'Invalid Water Depth',
            text: 'Please enter a valid water depth in centimeters.',
            confirmButtonColor: '#1d4ed8'
        });

        waterLevelInput.focus();
        return;
    }

    const image = imageInput.files[0];

    if (!image) {

        Swal.fire({
            icon: 'warning',
            title: 'Image Required',
            text: 'Please upload an image as proof of the flood incident.',
            confirmButtonColor: '#1d4ed8'
        });

        return;
    }

    const allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!allowedTypes.includes(image.type)) {

        Swal.fire({
            icon: 'error',
            title: 'Invalid Image',
            text: 'Only JPG, PNG, and WEBP images are allowed.',
            confirmButtonColor: '#1d4ed8'
        });

        return;
    }

    if (image.size <= 0 || image.size > 5 * 1024 * 1024) {

        Swal.fire({
            icon: 'error',
            title: 'Invalid Image Size',
            text: 'Your image must be greater than 0 bytes and must not exceed 5 MB.',
            confirmButtonColor: '#1d4ed8'
        });

        return;
    }

    Swal.fire({

        title: 'Submit Flood Report?',
        text: 'Are you sure you want to send this flood incident report to the administrator?',
        icon: 'question',

        showCancelButton: true,

        confirmButtonText: 'Yes, Submit Report',
        cancelButtonText: 'Review Report',

        confirmButtonColor: '#1d4ed8',
        cancelButtonColor: '#64748b',

        reverseButtons: true,
        allowOutsideClick: false

    }).then((result) => {

        if (result.isConfirmed) {

            confirmedSubmission = true;

            if (typeof reportForm.requestSubmit === 'function') {
                reportForm.requestSubmit(submitButton);
            } else {

                confirmedSubmission = false;
                reportSubmitting = true;

                submitButton.disabled = true;
                submitButton.textContent = 'Submitting...';

                Swal.fire({
                    title: 'Submitting Report',
                    text: 'Please wait while we send your flood report...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });

                reportForm.submit();
            }

        }

    });

});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
