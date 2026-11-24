<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

$headerUsername = $_SESSION['username'] ?? '';

$headerName = $_SESSION['full_name'] ?? 'User';
$headerRole = $_SESSION['role'] ?? 'resident';
$pageTitle = $pageTitle ?? 'Dashboard';
$activePage = $activePage ?? 'dashboard';

$isAdmin = $headerRole === 'admin';
$dashboardUrl = $isAdmin ? 'admin_dashboard.php' : 'resident_dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | FloodWatch</title>
    <!-- Bootstrap CSS Offline -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">

    <!-- SweetAlert2 CSS Offline -->
    <link rel="stylesheet" href="assets/css/sweetalert2.min.css">

    <!-- Your Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">

    <?php if ($activePage === 'residents'): ?>
        <link rel="stylesheet" href="assets/css/residents.css?v=<?= filemtime(__DIR__ . '/../assets/css/residents.css') ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'dashboard.css') ?>">
</head>

<body>

    <div class="app-layout">

        <aside class="sidebar" id="sidebar">

            <a href="<?= $dashboardUrl ?>" class="brand">
                <span class="brand-icon">
                    <img src="440822582_1709411063139196_8456949290582981759_n.png" alt="Eastern Samar State University logo">
                </span>
                <span>Flood<span>Watch</span></span>
            </a>

            <nav class="sidebar-nav">

                <a href="<?= $dashboardUrl ?>"
                    class="<?= $activePage === 'dashboard' ? 'active' : '' ?>"
                    <?= $activePage === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon">▦</span>
                    Dashboard
                </a>

                <?php if ($isAdmin): ?>

                    <a href="residents.php"
                        class="<?= $activePage === 'residents' ? 'active' : '' ?>"
                        <?= $activePage === 'residents' ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon">♙</span>
                        Residents
                    </a>

                    <a href="admin_reports.php"
                        class="<?= $activePage === 'reports' ? 'active' : '' ?>"
                        <?= $activePage === 'reports' ? 'aria-current="page"' : '' ?>>

                        <span class="nav-icon">⚑</span>
                        <span>Flood Reports</span>

                    </a>

                    <a href="admin_emergency_alerts.php"
                        class="nav-link <?= $activePage === 'emergency_alerts' ? 'active' : '' ?>"
                        <?= $activePage === 'emergency_alerts' ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon">♢</span>
                        <span>Emergency Alerts</span>

                        <span id="criticalAlertBadge"
                            class="critical-alert-badge"
                            style="display: none;">
                            0
                        </span>
                    </a>

                    <a href="admin_emergency_announcements.php"
                        class="<?= ($activePage ?? '') === 'emergency_announcements' ? 'active' : '' ?>">
                        <span class="nav-icon">♢</span>
                        Emergency Announcements
                    </a>

                <?php else: ?>


                    <a href="resident_submit_report.php"
                        class="<?= $activePage === 'resident_submit' ? 'active' : '' ?>"
                        <?= $activePage === 'resident_submit' ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon">＋</span>
                        Submit Flood Report
                    </a>

                    <a href="resident_my_reports.php"
                        class="<?= $activePage === 'my_reports' ? 'active' : '' ?>"
                        <?= $activePage === 'my_reports' ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon">⚑</span>
                        My Submissions
                    </a>

                    <a href="emergency_alert.php"
                        class="<?= ($activePage ?? '') === 'emergency_alert' ? 'active' : '' ?>">
                        <span class="nav-icon">♢</span>
                        Emergency Alerts
                    </a>

                <?php endif; ?>

            </nav>

            <div class="sidebar-bottom">
                <div class="sidebar-status">
                    <span class="status-dot"></span>
                    <div>
                        <strong>FloodWatch System</strong>
                        <small>Community Monitoring</small>
                    </div>
                </div>
            </div>

        </aside>

        <div class="main-area">

            <header class="topbar">

                <div class="topbar-left">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                        ☰
                    </button>

                    <div>
                        <span class="topbar-title">
                            <?= htmlspecialchars($pageTitle) ?>
                        </span>
                        <span class="topbar-subtitle">
                            FloodWatch Management System
                        </span>
                    </div>
                </div>

                <div class="topbar-right">

                    <div
                        class="critical-alert-card"
                        id="criticalAlertSoundCard"
                        role="status"
                        aria-live="polite"
                        aria-hidden="true">
                        <strong>Critical flood alert</strong>
                        <span>An active alert needs your attention.</span>
                    </div>

                    <?php if ($isAdmin && $activePage === 'emergency_alerts'): ?>
                        <button
                            type="button"
                            id="soundToggle"
                            class="alert-sound-toggle"
                            aria-label="Enable alert sound"
                            aria-pressed="false"
                            title="Enable alert sound">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M11 5 6 9H3v6h3l5 4V5Z"></path>
                                <path d="M15.5 8.5a5 5 0 0 1 0 7"></path>
                                <path d="M19 5a10 10 0 0 1 0 14"></path>
                                <path class="sound-muted-slash" d="m4 4 16 16"></path>
                            </svg>
                        </button>
                    <?php elseif ($isAdmin): ?>
                        <button
                            type="button"
                            id="criticalAlertSoundToggle"
                            class="alert-sound-toggle"
                            aria-label="Enable alert sound"
                            aria-pressed="false"
                            title="Enable alert sound">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M11 5 6 9H3v6h3l5 4V5Z"></path>
                                <path d="M15.5 8.5a5 5 0 0 1 0 7"></path>
                                <path d="M19 5a10 10 0 0 1 0 14"></path>
                                <path class="sound-muted-slash" d="m4 4 16 16"></path>
                            </svg>
                        </button>
                    <?php endif; ?>

                    <div class="header-notifications" id="headerNotifications"
                        data-csrf="<?= htmlspecialchars($_SESSION['profile_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <button
                            type="button"
                            class="notification-button"
                            id="notificationButton"
                            aria-label="Notifications"
                            aria-expanded="false"
                            aria-controls="notificationPanel">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                                <path d="M10 21h4"></path>
                            </svg>
                            <span class="notification-badge" id="notificationBadge" hidden>0</span>
                        </button>

                        <section class="notification-panel" id="notificationPanel" aria-label="Notifications">
                            <div class="notification-panel-heading">
                                <strong>Notifications</strong>
                                <span><?= $isAdmin ? 'Resident submissions' : 'Admin announcements' ?></span>
                            </div>
                            <div class="notification-list" id="notificationList" aria-live="polite">
                                <p class="notification-empty">Loading notifications...</p>
                            </div>
                        </section>
                    </div>

                    <div class="user-details">
                        <strong><?= htmlspecialchars($headerName) ?></strong>
                        <small><?= htmlspecialchars(ucfirst($headerRole)) ?></small>
                    </div>

                    <div class="profile-dropdown">

                        <button class="profile-button" id="profileButton"
                            aria-label="Profile menu" aria-expanded="false">
                            <span class="profile-avatar">
                                <?= strtoupper(substr($headerName, 0, 1)) ?>
                            </span>
                            <span class="dropdown-arrow"></span>
                        </button>

                        <div class="dropdown-menu" id="profileMenu">

                            <div class="dropdown-heading">
                                <strong><?= htmlspecialchars($headerName) ?></strong>
                                <small><?= htmlspecialchars(ucfirst($headerRole)) ?> Account</small>
                            </div>

                            <div class="dropdown-divider"></div>

                            <button type="button" class="dropdown-action" onclick="openModal('profileModal')">
                                <span>♙</span> Change Profile
                            </button>

                            <button type="button" class="dropdown-action" onclick="openModal('aboutModal')">
                                <span>ⓘ</span> About FloodWatch
                            </button>

                            <div class="dropdown-divider"></div>

                            <a href="logout.php" class="logout-link" id="logoutLink">
                                <span>↪</span> Logout
                            </a>

                        </div>
                    </div>

                </div>

            </header>

            <main class="page-content">