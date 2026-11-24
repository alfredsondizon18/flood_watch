<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$pageTitle = "About FloodWatch";
$activePage = "";

require_once 'includes/header.php';
?>

<section class="page-heading">
    <h1>About FloodWatch</h1>
    <p>Learn more about our community flood monitoring system.</p>
</section>

<section class="content-panel">

    <h2>FloodWatch System</h2>

    <p style="line-height:1.8; color:#64748b;">
        FloodWatch is a community flood monitoring and alert system
        designed to help residents stay informed about flood conditions
        and important emergency announcements.
    </p>

    <h2 style="margin-top:25px;">Our Purpose</h2>

    <p style="line-height:1.8; color:#64748b;">
        Our purpose is to provide a platform where administrators can
        manage flood-related information and residents can access
        important updates to help them prepare for possible flooding.
    </p>

    <h2 style="margin-top:25px;">System Features</h2>

    <ul style="padding-left:22px;line-height:2;color:#64748b;">
        <li>Flood condition monitoring</li>
        <li>Emergency alerts and announcements</li>
        <li>Community flood reporting</li>
        <li>Resident account management</li>
        <li>Flood safety information</li>
    </ul>

</section>

<?php require_once 'includes/footer.php'; ?>