
<?php
// =================================
// PROFILE NOTIFICATION MESSAGES
// =================================

$profileMessage = $_SESSION['profile_message'] ?? '';
$profileMessageType = $_SESSION['profile_message_type'] ?? '';
$profileFieldError = $_SESSION['profile_field_error'] ?? '';

$profileHasCurrentPasswordError =
    $profileMessageType === 'error' &&
    $profileFieldError === 'current_password';

$profileHasPasswordMismatchError =
    $profileMessageType === 'error' &&
    $profileFieldError === 'password_mismatch';

$profileHasPasswordFieldError =
    $profileHasCurrentPasswordError ||
    $profileHasPasswordMismatchError;

// Remove messages after retrieving them
unset(
    $_SESSION['profile_message'],
    $_SESSION['profile_message_type'],
    $_SESSION['profile_field_error']
);
?>

</main>

<!-- =================================
     FOOTER
================================= -->

<footer class="main-footer">
    <span>&copy; <?= date('Y') ?> FloodWatch. All rights reserved.</span>
    <span>Community Flood Monitoring System</span>
</footer>

</div>
</div>

<!-- =================================
     CHANGE PROFILE MODAL
================================= -->

<div
    class="modal-overlay<?= $profileHasPasswordFieldError ? ' show profile-error-state' : '' ?>"
    id="profileModal">

    <div class="modal-container">

        <div class="modal-header">

            <div>
                <h2>Change Profile</h2>
                <p>Update your account information.</p>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('profileModal')">
                &times;
            </button>

        </div>

        <div class="modal-body">

            <form method="POST" action="change_profile.php">

                <!-- FULL NAME -->
                <div class="modal-form-group">

                    <label for="fullName">Full Name</label>

                    <input
                        type="text"
                        id="fullName"
                        name="full_name"
                        value="<?= htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required>

                </div>


                <!-- USERNAME -->
                <div class="modal-form-group">

                    <label for="username">Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        minlength="4"
                        required>

                </div>


                <!-- PASSWORD SECTION -->
                <h3 class="modal-section-title">
                    Change password <span>(optional)</span>
                </h3>


                <!-- CURRENT PASSWORD -->
                <div class="modal-form-group <?= $profileHasCurrentPasswordError ? 'has-error' : '' ?>">

                    <label for="currentPassword">
                        Current Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="currentPassword"
                            name="current_password"
                            autocomplete="current-password"
                            class="<?= $profileHasCurrentPasswordError ? 'input-error' : '' ?>"
                            aria-invalid="<?= $profileHasCurrentPasswordError ? 'true' : 'false' ?>"
                            <?= $profileHasCurrentPasswordError ? 'aria-describedby="currentPasswordError"' : '' ?>>

                        <button
                            type="button"
                            class="toggle-password"
                            onclick="toggleProfilePassword('currentPassword', this)"
                            aria-label="Show password">

                            <svg viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round">

                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>

                            </svg>

                        </button>

                    </div>

                    <?php if ($profileHasCurrentPasswordError): ?>

                        <small
                            class="error-text"
                            id="currentPasswordError"
                            role="alert">

                            <i class="fas fa-exclamation-circle"></i>

                            <?= htmlspecialchars($profileMessage, ENT_QUOTES, 'UTF-8') ?>

                        </small>

                    <?php endif; ?>

                </div>


                <!-- NEW PASSWORD -->
                <div class="modal-form-group <?= $profileHasPasswordMismatchError ? 'has-error' : '' ?>">

                    <label for="newPassword">
                        New Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="newPassword"
                            name="new_password"
                            minlength="8"
                            autocomplete="new-password"
                            class="<?= $profileHasPasswordMismatchError ? 'input-error' : '' ?>"
                            aria-invalid="<?= $profileHasPasswordMismatchError ? 'true' : 'false' ?>"
                            <?= $profileHasPasswordMismatchError ? 'aria-describedby="passwordMismatchError"' : '' ?>>

                        <button
                            type="button"
                            class="toggle-password"
                            onclick="toggleProfilePassword('newPassword', this)"
                            aria-label="Show password">

                            <svg viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round">

                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>

                            </svg>

                        </button>

                    </div>

                </div>


                <!-- CONFIRM PASSWORD -->
                <div class="modal-form-group <?= $profileHasPasswordMismatchError ? 'has-error' : '' ?>">

                    <label for="confirmPassword">
                        Confirm New Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="confirmPassword"
                            name="confirm_password"
                            minlength="8"
                            autocomplete="new-password"
                            class="<?= $profileHasPasswordMismatchError ? 'input-error' : '' ?>"
                            aria-invalid="<?= $profileHasPasswordMismatchError ? 'true' : 'false' ?>"
                            <?= $profileHasPasswordMismatchError ? 'aria-describedby="passwordMismatchError"' : '' ?>>

                        <button
                            type="button"
                            class="toggle-password"
                            onclick="toggleProfilePassword('confirmPassword', this)"
                            aria-label="Show password">

                            <svg viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round">

                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>

                            </svg>

                        </button>

                    </div>

                    <?php if ($profileHasPasswordMismatchError): ?>

                        <small
                            class="error-text"
                            id="passwordMismatchError"
                            role="alert">

                            <i class="fas fa-exclamation-circle"></i>

                            <?= htmlspecialchars($profileMessage, ENT_QUOTES, 'UTF-8') ?>

                        </small>

                    <?php endif; ?>

                </div>


                <!-- CSRF TOKEN -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['profile_csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <input
                    type="hidden"
                    name="modal_profile"
                    value="1">


                <!-- SAVE BUTTON -->
                <button type="submit" class="modal-save-btn">
                    Save Changes
                </button>

            </form>

        </div>

    </div>

</div>


<!-- =================================
     ABOUT MODAL
================================= -->

<div class="modal-overlay" id="aboutModal">

    <div class="modal-container about-container">

        <div class="modal-header">

            <div>
                <h2>About FloodWatch</h2>
                <p>Community Flood Monitoring System</p>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('aboutModal')">
                &times;
            </button>

        </div>

        <div class="modal-body">

            <div class="about-logo">

                <div class="about-logo-icon">F</div>

                <h2>Flood<span>Watch</span></h2>

            </div>

            <p class="about-description">
                FloodWatch is a community flood monitoring and alert
                system designed to help residents stay informed about
                flood conditions and important emergency announcements.
            </p>

            <div class="about-feature">
                <strong>Flood Monitoring</strong>
                <p>Access flood condition updates in your community.</p>
            </div>

            <div class="about-feature">
                <strong>Emergency Alerts</strong>
                <p>Stay informed about important flood warnings.</p>
            </div>

            <div class="about-feature">
                <strong>Community Awareness</strong>
                <p>Help residents become prepared for possible flooding.</p>
            </div>

            <div class="about-version">
                FloodWatch System &bull; Version 1.0 &bull; 2026
            </div>

        </div>

    </div>

</div>


<!-- =================================
     JAVASCRIPT LIBRARIES
================================= -->

<!-- Bootstrap JS -->
<script src="assets/css/bootstrap.bundle.min.js"></script>

<!-- SweetAlert2 JS -->
<?php if (empty($sweetAlertLoaded)): ?>
    <script src="assets/css/sweetalert.all.min.js"></script>
<?php endif; ?>


<!-- =================================
     MAIN JAVASCRIPT
================================= -->

<script>

// =================================
// SIDEBAR TOGGLE
// =================================

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');

menuToggle?.addEventListener('click', function() {

    sidebar?.classList.toggle('sidebar-open');

});


// =================================
// PROFILE DROPDOWN
// =================================

const profileButton = document.getElementById('profileButton');
const profileMenu = document.getElementById('profileMenu');

profileButton?.addEventListener('click', function(event) {

    event.stopPropagation();

    if (!profileMenu) return;

    const isOpen = profileMenu.classList.toggle('show');

    profileButton.setAttribute('aria-expanded', String(isOpen));

});


// =================================
// CLOSE DROPDOWN OUTSIDE CLICK
// =================================

document.addEventListener('click', function(event) {

    if (
        !profileMenu?.contains(event.target) &&
        !profileButton?.contains(event.target)
    ) {

        profileMenu?.classList.remove('show');

        profileButton?.setAttribute('aria-expanded', 'false');

    }

});


// =================================
// OPEN MODAL
// =================================

function openModal(modalId) {

    const modal = document.getElementById(modalId);

    if (modal) {

        modal.classList.add('show');

        document.body.style.overflow = 'hidden';

    }

    profileMenu?.classList.remove('show');

    profileButton?.setAttribute('aria-expanded', 'false');

}


// =================================
// CLOSE MODAL
// =================================

function closeModal(modalId) {

    const modal = document.getElementById(modalId);

    if (modal) {

        modal.classList.remove('show');

    }

    if (!document.querySelector('.modal-overlay.show')) {

        document.body.style.overflow = '';

    }

}


// =================================
// CLOSE MODAL OUTSIDE CLICK
// =================================

document.querySelectorAll('.modal-overlay').forEach(function(modal) {

    modal.addEventListener('click', function(event) {

        if (event.target === modal) {

            closeModal(modal.id);

        }

    });

});


// =================================
// CLOSE WITH ESCAPE KEY
// =================================

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {

        document.querySelectorAll('.modal-overlay.show').forEach(function(modal) {

            closeModal(modal.id);

        });

        profileMenu?.classList.remove('show');

        profileButton?.setAttribute('aria-expanded', 'false');

    }

});


// =================================
// PROFILE PASSWORD SHOW / HIDE
// =================================

function toggleProfilePassword(inputId, button) {

    const passwordInput = document.getElementById(inputId);

    if (!passwordInput) return;

    if (passwordInput.type === 'password') {

        passwordInput.type = 'text';

        button.setAttribute('aria-label', 'Hide password');

    } else {

        passwordInput.type = 'password';

        button.setAttribute('aria-label', 'Show password');

    }

}


// =================================
// PROFILE NOTIFICATION DATA
// =================================

const profileMessage = <?= json_encode(
    $profileMessage,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const profileMessageType = <?= json_encode(
    $profileMessageType,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const profileFieldError = <?= json_encode(
    $profileFieldError,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;


// =================================
// CONFIRM LOGOUT
// =================================

const logoutLink = document.getElementById('logoutLink');

logoutLink?.addEventListener('click', function(event) {

    event.preventDefault();

    if (typeof Swal === 'undefined') {

        console.error('SweetAlert2 is not loaded; using browser confirmation.');

        if (window.confirm('Are you sure you want to log out?')) {

            window.location.assign(logoutLink.href);

        }

        return;

    }

    Swal.fire({

        icon: 'warning',
        title: 'Log out?',
        text: 'Are you sure you want to log out of FloodWatch?',
        showCancelButton: true,
        confirmButtonText: 'Yes, log out',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#22306f',
        cancelButtonColor: '#64748b',
        allowOutsideClick: false,
        heightAuto: false

    }).then(function(result) {

        if (result.isConfirmed) {

            window.location.assign(logoutLink.href);

        }

    });

});


// =================================
// SHOW PROFILE SWEETALERT
// =================================

function showProfileNotification() {

    if (
        !profileMessage ||
        profileFieldError === 'current_password' ||
        profileFieldError === 'password_mismatch'
    ) {
        return;
    }

    if (typeof Swal === 'undefined') {

        console.error(
            'SweetAlert2 is not loaded. Check assets/sweetalert2.all.min.js'
        );

        return;

    }

    Swal.fire({

        icon: profileMessageType === 'success'
            ? 'success'
            : 'error',

        title: profileMessageType === 'success'
            ? 'Success!'
            : 'Something went wrong!',

        text: profileMessage,

        confirmButtonText: 'OK',

        confirmButtonColor: '#2563eb',

        allowOutsideClick: false,

        allowEscapeKey: true,

        heightAuto: false

    });

}


// =================================
// PRESERVE PROFILE FIELD ERRORS
// =================================

function preserveProfileErrors() {

    if (
        profileMessageType === 'error' &&
        (
            profileFieldError === 'current_password' ||
            profileFieldError === 'password_mismatch'
        )
    ) {

        document.body.style.overflow = 'hidden';

    }

}


// =================================
// INITIALIZE PROFILE NOTIFICATIONS
// =================================

function initializeProfileNotifications() {

    showProfileNotification();

    preserveProfileErrors();

}

if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        initializeProfileNotifications
    );

} else {

    initializeProfileNotifications();

}

const isEmergencyAlertsPage =
    <?= json_encode(($activePage ?? '') === 'emergency_alerts') ?>;
const autoPlayAdminCriticalAlerts =
    <?= json_encode(!empty($playAdminLoginCriticalSound)) ?>;
let currentCriticalAlertCount = 0;
let criticalAlertSoundMutedByUser = false;
const criticalAlertAudio = new Audio('alert.mp3');
criticalAlertAudio.preload = 'auto';
criticalAlertAudio.volume = 1;
const criticalAlertSoundButton = document.getElementById('criticalAlertSoundToggle');
const criticalAlertSoundCard = document.getElementById('criticalAlertSoundCard');

function updateCriticalAlertSoundCard(isPlaying) {
    window.floodAlertSoundPlaying = isPlaying;
    criticalAlertSoundCard?.classList.toggle('show', isPlaying);
    criticalAlertSoundCard?.setAttribute('aria-hidden', String(!isPlaying));
}

window.addEventListener('flood-alert-sound-state', function(event) {
    updateCriticalAlertSoundCard(event.detail.playing);
});

updateCriticalAlertSoundCard(Boolean(window.floodAlertSoundPlaying));
criticalAlertAudio.addEventListener('play', () => updateCriticalAlertSoundCard(true));
criticalAlertAudio.addEventListener('pause', () => updateCriticalAlertSoundCard(false));
criticalAlertAudio.addEventListener('ended', () => updateCriticalAlertSoundCard(false));
criticalAlertAudio.addEventListener('error', () => updateCriticalAlertSoundCard(false));

const headerNotifications = document.getElementById('headerNotifications');
const notificationButton = document.getElementById('notificationButton');
const notificationPanel = document.getElementById('notificationPanel');
const notificationBadge = document.getElementById('notificationBadge');
const notificationList = document.getElementById('notificationList');
let latestNotificationId = 0;
let notificationRequestPending = false;

function renderHeaderNotifications(items) {
    notificationList.replaceChildren();

    if (!items.length) {
        const empty = document.createElement('p');
        empty.className = 'notification-empty';
        empty.textContent = 'No notifications yet.';
        notificationList.appendChild(empty);
        return;
    }

    items.forEach(item => {
        const link = document.createElement('a');
        link.className = `notification-item${item.unread ? ' unread' : ''}`;
        link.href = item.url;

        const title = document.createElement('strong');
        title.textContent = item.title;

        const message = document.createElement('p');
        message.textContent = item.message;

        const time = document.createElement('span');
        time.className = 'notification-time';
        const date = new Date(item.created_at.replace(' ', 'T'));
        time.textContent = Number.isNaN(date.getTime())
            ? item.created_at
            : date.toLocaleString();

        link.append(title, message, time);
        notificationList.appendChild(link);
    });
}

async function loadHeaderNotifications(markRead = false) {
    if (!headerNotifications || notificationRequestPending) return;

    notificationRequestPending = true;
    try {
        const response = await fetch('header_notifications.php');
        if (!response.ok) {
            throw new Error('Unable to load notifications.');
        }

        const data = await response.json();
        if (!Array.isArray(data.items) || !Number.isFinite(Number(data.unread))) {
            throw new Error(data.error || 'Invalid notification response.');
        }

        latestNotificationId = Number(data.latest_id) || 0;
        const unreadCount = Number(data.unread);
        notificationBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        notificationBadge.hidden = unreadCount === 0;
        renderHeaderNotifications(data.items);

        if (markRead && unreadCount > 0) {
            const readResponse = await fetch('header_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: headerNotifications.dataset.csrf,
                    latest_id: latestNotificationId
                })
            });
            if (!readResponse.ok) {
                throw new Error('Unable to mark notifications as read.');
            }

            notificationBadge.hidden = true;
            notificationBadge.textContent = '0';
            notificationList.querySelectorAll('.notification-item.unread')
                .forEach(item => item.classList.remove('unread'));
        }
    } catch (error) {
        console.error('Header notification error:', error);
    } finally {
        notificationRequestPending = false;
    }
}

notificationButton?.addEventListener('click', async function() {
    const isOpen = notificationPanel.classList.toggle('show');
    this.setAttribute('aria-expanded', String(isOpen));
    if (isOpen) {
        await loadHeaderNotifications(true);
    }
});

document.addEventListener('click', function(event) {
    if (headerNotifications && !headerNotifications.contains(event.target)) {
        notificationPanel?.classList.remove('show');
        notificationButton?.setAttribute('aria-expanded', 'false');
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && notificationPanel?.classList.contains('show')) {
        notificationPanel.classList.remove('show');
        notificationButton?.setAttribute('aria-expanded', 'false');
    }
});

if (headerNotifications) {
    loadHeaderNotifications();
    setInterval(loadHeaderNotifications, 30000);
}

function isCriticalAlertSoundEnabled() {
    return localStorage.getItem('floodAlertSound') === 'enabled';
}

function updateCriticalAlertSoundButton() {
    if (!criticalAlertSoundButton) return;

    const enabled = isCriticalAlertSoundEnabled();
    const label = enabled
        ? 'Alert sound enabled'
        : 'Enable alert sound';
    criticalAlertSoundButton.setAttribute('aria-label', label);
    criticalAlertSoundButton.setAttribute('title', label);
    criticalAlertSoundButton.setAttribute('aria-pressed', String(enabled));
    criticalAlertSoundButton.classList.toggle('enabled', enabled);
}

async function playCriticalAlertSound(loop = false) {
    try {
        criticalAlertAudio.loop = loop;
        if (!criticalAlertAudio.paused) {
            return true;
        }
        criticalAlertAudio.currentTime = 0;
        await criticalAlertAudio.play();
        return true;
    } catch (error) {
        console.error('Alert sound error:', error);
        return false;
    }
}

function stopCriticalAlertSound() {
    criticalAlertAudio.pause();
    criticalAlertAudio.currentTime = 0;
    criticalAlertAudio.loop = false;
}

async function syncCriticalAlertSound() {
    if (isEmergencyAlertsPage) return;

    const shouldLoop = currentCriticalAlertCount > 0 &&
        !criticalAlertSoundMutedByUser &&
        (autoPlayAdminCriticalAlerts || isCriticalAlertSoundEnabled());
    if (!shouldLoop) {
        if (currentCriticalAlertCount === 0 || !isCriticalAlertSoundEnabled()) {
            stopCriticalAlertSound();
        }
        return;
    }

    if (!(await playCriticalAlertSound(true)) && criticalAlertSoundButton) {
        criticalAlertSoundButton.setAttribute('aria-label', 'Click to enable alert sound');
        criticalAlertSoundButton.setAttribute('title', 'Click to enable alert sound');
    }
}

criticalAlertSoundButton?.addEventListener('click', async function() {
    if (
        isCriticalAlertSoundEnabled() &&
        currentCriticalAlertCount > 0 &&
        criticalAlertAudio.paused
    ) {
        if (!(await playCriticalAlertSound(true))) {
            this.setAttribute('aria-label', 'Sound blocked by browser');
            this.setAttribute('title', 'Sound blocked by browser');
            this.setAttribute('aria-pressed', 'false');
        } else {
            updateCriticalAlertSoundButton();
        }
        return;
    }

    const enabled = !isCriticalAlertSoundEnabled();
    criticalAlertSoundMutedByUser = !enabled;
    localStorage.setItem('floodAlertSound', enabled ? 'enabled' : 'disabled');

    if (!enabled) {
        stopCriticalAlertSound();
        updateCriticalAlertSoundButton();
        return;
    }

    const played = await playCriticalAlertSound(currentCriticalAlertCount > 0);
    if (!played) {
        this.setAttribute('aria-label', 'Sound blocked by browser');
        this.setAttribute('title', 'Sound blocked by browser');
        this.setAttribute('aria-pressed', 'false');
        return;
    }
    updateCriticalAlertSoundButton();
});

updateCriticalAlertSoundButton();

function updateCriticalAlertBadge() {
    const badge = document.getElementById('criticalAlertBadge');
    if (!badge) return;

    fetch('emergency_alerts_count.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Unable to get alert count');
            }
            return response.json();
        })
        .then(data => {
            const count = Number(data.count);
            if (!Number.isFinite(count)) {
                throw new Error(data.error || 'Invalid alert count response');
            }

            currentCriticalAlertCount = count;

            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
            }

            syncCriticalAlertSound();
        })
        .catch(error => console.error(error));
}

updateCriticalAlertBadge();
setInterval(updateCriticalAlertBadge, 10000);

</script>

<script src="assets/js/live-search.js" defer></script>

</body>
</html>
