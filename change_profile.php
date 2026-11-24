
<?php

require_once 'config.php';
require_once __DIR__ . '/includes/admin_activity.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =================================
// REQUIRE LOGIN
// =================================

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// =================================
// REDIRECT PAGE
// =================================

$redirectPage = ($_SESSION['role'] ?? '') === 'admin'
    ? 'admin_dashboard.php'
    : 'resident_dashboard.php';

// =================================
// ALLOW POST REQUEST ONLY
// =================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $redirectPage");
    exit;
}

// =================================
// NOTIFICATION HELPER
// =================================

function profileRedirect($message, $type = 'error', $fieldError = '')
{
    global $redirectPage;

    $_SESSION['profile_message'] = $message;
    $_SESSION['profile_message_type'] = $type;

    if ($fieldError !== '') {
        $_SESSION['profile_field_error'] = $fieldError;
    } else {
        unset($_SESSION['profile_field_error']);
    }

    header("Location: $redirectPage");
    exit;
}

// =================================
// CSRF VALIDATION
// =================================

$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['profile_csrf']) ||
    !hash_equals($_SESSION['profile_csrf'], $token)
) {

    profileRedirect(
        'Invalid request. Please refresh the page and try again.'
    );

}

// =================================
// GET FORM DATA
// =================================

$userId = (int) $_SESSION['user_id'];

$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// =================================
// VALIDATE REQUIRED FIELDS
// =================================

if ($fullName === '' || $username === '') {

    profileRedirect(
        'Full name and username are required.'
    );

}

// =================================
// VALIDATE USERNAME LENGTH
// =================================

if (strlen($username) < 4) {

    profileRedirect(
        'Username must be at least 4 characters.'
    );

}

// =================================
// CHECK USERNAME DUPLICATION
// =================================

try {

    $check = $conn->prepare(
        "SELECT id
         FROM users
         WHERE username = ? AND id != ?
         LIMIT 1"
    );

    $check->bind_param("si", $username, $userId);
    $check->execute();

    $usernameExists = $check->get_result()->num_rows > 0;

    $check->close();

    if ($usernameExists) {

        profileRedirect(
            'Username is already taken.'
        );

    }

} catch (mysqli_sql_exception $e) {

    error_log('Profile username check failed: ' . $e->getMessage());

    profileRedirect(
        'Unable to verify username. Please try again.'
    );

}

// =================================
// CHECK PASSWORD CHANGE REQUEST
// =================================

$wantsPasswordChange =
    $currentPassword !== '' ||
    $newPassword !== '' ||
    $confirmPassword !== '';

if ($wantsPasswordChange) {

    // =================================
    // REQUIRE ALL PASSWORD FIELDS
    // =================================

    if (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {

        profileRedirect(
            'Please complete all password fields.'
        );

    }

    // =================================
    // PASSWORD MINIMUM LENGTH
    // =================================

    if (strlen($newPassword) < 8) {

        profileRedirect(
            'New password must be at least 8 characters.'
        );

    }

    // =================================
    // PASSWORD CONFIRMATION
    // =================================

    if ($newPassword !== $confirmPassword) {

        profileRedirect(
            'New password and confirmation do not match.',
            'error',
            'password_mismatch'
        );

    }

    // =================================
    // GET CURRENT PASSWORD HASH
    // =================================

    try {

        $stmt = $conn->prepare(
            "SELECT password_hash
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $user = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$user) {

            profileRedirect(
                'User account not found.'
            );

        }

    } catch (mysqli_sql_exception $e) {

        error_log('Password retrieval failed: ' . $e->getMessage());

        profileRedirect(
            'Unable to verify your account. Please try again.'
        );

    }

    // =================================
    // VERIFY CURRENT PASSWORD
    // =================================

    if (!password_verify($currentPassword, $user['password_hash'])) {

        profileRedirect(
            'Incorrect current password. Please enter your correct password.',
            'error',
            'current_password'
        );

    }

    // =================================
    // HASH NEW PASSWORD
    // =================================

    $newPasswordHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    if ($newPasswordHash === false) {

        profileRedirect(
            'Unable to secure your new password. Please try again.'
        );

    }

}

// =================================
// UPDATE DATABASE
// =================================

try {

    if ($wantsPasswordChange) {

        // Update profile and password

        $update = $conn->prepare(
            "UPDATE users
             SET full_name = ?,
                 username = ?,
                 password_hash = ?
             WHERE id = ?"
        );

        $update->bind_param(
            "sssi",
            $fullName,
            $username,
            $newPasswordHash,
            $userId
        );

    } else {

        // Update profile only

        $update = $conn->prepare(
            "UPDATE users
             SET full_name = ?,
                 username = ?
             WHERE id = ?"
        );

        $update->bind_param(
            "ssi",
            $fullName,
            $username,
            $userId
        );

    }

    // =================================
    // EXECUTE UPDATE
    // =================================

    $update->execute();

    $update->close();

    if (($_SESSION['role'] ?? '') === 'admin') {
        adminActivityLog(
            $conn,
            $userId,
            (string) ($_SESSION['full_name'] ?? 'Administrator'),
            'Updated admin profile',
            $wantsPasswordChange
                ? 'Updated admin profile and password'
                : 'Updated admin profile details'
        );
    }

    // =================================
    // UPDATE SESSION INFORMATION
    // =================================

    $_SESSION['full_name'] = $fullName;
    $_SESSION['username'] = $username;

    // =================================
    // SUCCESS NOTIFICATION
    // =================================

    profileRedirect(
        $wantsPasswordChange
            ? 'Profile and password updated successfully!'
            : 'Profile updated successfully!',
        'success'
    );

} catch (mysqli_sql_exception $e) {

    error_log('Profile update failed: ' . $e->getMessage());

    profileRedirect(
        'Unable to update your profile. Please try again.'
    );

}
