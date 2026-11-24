<?php
require_once 'config.php';
require_once __DIR__ . '/includes/admin_activity.php';
require_once __DIR__ . '/includes/resident_activity.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: resident_dashboard.php");
    }
    exit;
}

$error = "";
$success = "";
$mode = "login";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";
        $mode = ($_POST['action'] ?? '') === 'signup' ? 'signup' : 'login';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            $stmt = $conn->prepare(
                "SELECT id, full_name, username, password_hash, role FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                if ($_SESSION['role'] === 'admin') {
                    adminActivityLog(
                        $conn,
                        (int) $user['id'],
                        (string) $user['full_name'],
                        'Logged in',
                        'Administrator signed in'
                    );
                    $_SESSION['admin_login_alert_sound'] = true;
                    header("Location: admin_dashboard.php");
                } else {
                    unset($_SESSION['admin_login_alert_sound']);
                    residentActivityLog(
                        $conn,
                        (int) $user['id'],
                        (string) $user['full_name'],
                        'Logged in'
                    );
                    header("Location: resident_dashboard.php");
                }
                exit;
            }

            $error = "Invalid username or password.";
        } elseif ($action === 'signup') {
            $mode = "signup";

            $fullName = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($fullName === '' || $username === '' || $password === '') {
                $error = "Please complete all required fields.";
            } elseif (strlen($username) < 4) {
                $error = "Username must contain at least 4 characters.";
            } elseif (strlen($password) < 8) {
                $error = "Password must contain at least 8 characters.";
            } elseif ($password !== $confirmPassword) {
                $error = "Passwords do not match.";
            } else {
                $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $check->bind_param("s", $username);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = "Username is already taken.";
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $role = "resident";

                    $stmt = $conn->prepare(
                        "INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->bind_param("ssss", $fullName, $username, $hashedPassword, $role);

                    if ($stmt->execute()) {
                        $success = "Registration successful! You can now log in.";
                        $mode = "login";
                    } else {
                        $error = "Registration failed. Please try again.";
                    }

                    $stmt->close();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>FloodWatch | Account Access</title>

    <!-- Local Custom CSS -->
    <link
        rel="stylesheet"
        href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">

    <!-- Password Eye Icon Styles -->
    <style>
        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 45px;
            box-sizing: border-box;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #777;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password svg {
            width: 19px;
            height: 19px;
        }

        .toggle-password:hover {
            color: #2563eb;
        }
    </style>
</head>

<body>

    <!-- FLOODWATCH LOADING SCREEN -->
    <div id="pageLoader">

        <img
            src="440822582_1709411063139196_8456949290582981759_n.png"
            alt="FloodWatch Logo"
            class="loader-logo">

        <div class="loader-brand">
            Flood<span>Watch</span>
        </div>

        <div class="loader-text">
            Community Flood Reporting System
        </div>

        <div class="loader-spinner"></div>

    </div>

    <div class="main-container">

        <section
            class="hero-section"
            style="background-image: url('flood.png?v=<?= filemtime(__DIR__ . '/flood.png') ?>')">
            <div class="hero-overlay"></div>

            <div class="hero-content">

                <div class="brand">
                    <div class="brand-icon">
                        <img src="440822582_1709411063139196_8456949290582981759_n.png" alt="Eastern Samar State University logo">
                    </div>
                    <span>Flood<span class="brand-highlight">Watch</span></span>
                </div>

                <div class="hero-text">

                    <span class="eyebrow">COMMUNITY FLOOD REPORTING AND EMERGENCY ALERT SYSTEM</span>

                    <h1>Stay Informed.<br>Stay Safe.</h1>

                    <p>
                        Monitor flood reports in your community, receive emergency
                        alerts and announcements, and stay informed about local
                        flood conditions through the Flood Watch system.
                    </p>

                    <div class="features">
                        <div class="feature">
                            <div class="feature-icon">01</div>
                            <div>
                                <h3>Flood Reporting</h3>
                                <p>Submit flood reports with location details and photo evidence.</p>
                            </div>
                        </div>

                        <div class="feature">
                            <div class="feature-icon">02</div>
                            <div>
                                <h3>Emergency Alerts</h3>
                                <p>Receive important alerts and updates about reported flood incidents.</p>
                            </div>
                        </div>

                        <div class="feature">
                            <div class="feature-icon">03</div>
                            <div>
                                <h3>Community Announcements</h3>
                                <p>Stay informed through important safety reminders and emergency announcements.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="hero-footer">
                    © 2026 FloodWatch System. All rights reserved.
                </div>

            </div>
        </section>


        <section class="form-section">

            <div class="form-wrapper">

                <div class="mobile-brand">
                    <div class="brand-icon">
                        <img src="440822582_1709411063139196_8456949290582981759_n.png" alt="Eastern Samar State University logo">
                    </div>
                    <span>Flood<span class="brand-highlight">Watch</span></span>
                </div>


                <div class="form-card">

                    <!-- LOGIN FORM -->
                    <div id="loginForm" class="form-content"
                        style="<?= $mode === 'login' ? '' : 'display:none;' ?>">

                        <div class="form-heading">
                            <span class="form-label">RESIDENT ACCESS</span>
                            <h2>Welcome to FloodWatch</h2>
                            <p>Stay informed. Stay prepared.</p>
                        </div>

                        <?php if ($error && $mode === 'login'): ?>
                            <div class="alert error">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert success">
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>


                        <form method="POST" action="index.php">

                            <input type="hidden" name="action" value="login">

                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">


                            <div class="input-group">
                                <label for="loginUsername">Username</label>

                                <input type="text"
                                    id="loginUsername"
                                    name="username"
                                    placeholder="Enter your username"
                                    required
                                    autocomplete="username">
                            </div>


                            <!-- LOGIN PASSWORD WITH EYE ICON -->
                            <div class="input-group">
                                <label for="loginPassword">Password</label>

                                <div class="password-wrapper">

                                    <input type="password"
                                        id="loginPassword"
                                        name="password"
                                        placeholder="Enter your password"
                                        required
                                        autocomplete="current-password">

                                    <button type="button"
                                        class="toggle-password"
                                        onclick="togglePassword('loginPassword', this)"
                                        aria-label="Show password">

                                        <svg viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            stroke-linecap="round"
                                            stroke-linejoin="round">

                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                                            <circle cx="12" cy="12" r="3" />

                                        </svg>

                                    </button>

                                </div>
                            </div>


                            <button type="submit" class="submit-btn">
                                Sign In <span></span>
                            </button>

                        </form>


                        <div class="form-switch">
                            Don't have an account?
                            <a href="#" onclick="showSignup(event)">Create account</a>
                        </div>

                    </div>


                    <!-- SIGNUP FORM -->
                    <div id="signupForm" class="form-content"
                        style="<?= $mode === 'signup' ? '' : 'display:none;' ?>">

                        <div class="form-heading">
                            <span class="form-label">RESIDENT REGISTRATION</span>
                            <h2>Create Your FloodWatch Account</h2>
                            <p>Register to receive flood alerts and stay informed in your community.</p>
                        </div>

                        <?php if ($error && $mode === 'signup'): ?>
                            <div class="alert error">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>


                        <form method="POST" action="index.php">

                            <input type="hidden" name="action" value="signup">

                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">


                            <div class="input-group">
                                <label for="fullName">Full Name</label>

                                <input type="text"
                                    id="fullName"
                                    name="full_name"
                                    placeholder="Enter your full name"
                                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                                    required
                                    autocomplete="name">
                            </div>


                            <div class="input-group">
                                <label for="signupUsername">Username</label>

                                <input type="text"
                                    id="signupUsername"
                                    name="username"
                                    placeholder="Choose a username"
                                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                    minlength="4"
                                    required
                                    autocomplete="username">
                            </div>


                            <!-- SIGNUP PASSWORD WITH EYE ICON -->
                            <div class="input-group">
                                <label for="signupPassword">Password</label>

                                <div class="password-wrapper">

                                    <input type="password"
                                        id="signupPassword"
                                        name="password"
                                        placeholder="Create a password"
                                        minlength="8"
                                        required
                                        autocomplete="new-password">

                                    <button type="button"
                                        class="toggle-password"
                                        onclick="togglePassword('signupPassword', this)"
                                        aria-label="Show password">

                                        <svg viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            stroke-linecap="round"
                                            stroke-linejoin="round">

                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                                            <circle cx="12" cy="12" r="3" />

                                        </svg>

                                    </button>

                                </div>
                            </div>


                            <!-- CONFIRM PASSWORD WITH EYE ICON -->
                            <div class="input-group">
                                <label for="confirmPassword">Confirm Password</label>

                                <div class="password-wrapper">

                                    <input type="password"
                                        id="confirmPassword"
                                        name="confirm_password"
                                        placeholder="Confirm your password"
                                        minlength="8"
                                        required
                                        autocomplete="new-password">

                                    <button type="button"
                                        class="toggle-password"
                                        onclick="togglePassword('confirmPassword', this)"
                                        aria-label="Show password">

                                        <svg viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            stroke-linecap="round"
                                            stroke-linejoin="round">

                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                                            <circle cx="12" cy="12" r="3" />

                                        </svg>

                                    </button>

                                </div>
                            </div>


                            <button type="submit" class="submit-btn">
                                Create Account <span></span>
                            </button>

                        </form>


                        <div class="form-switch">
                            Already have an account?
                            <a href="#" onclick="showLogin(event)">Sign in</a>
                        </div>

                    </div>

                </div>


                <p class="security-note">
                    <span>●</span> Secure access for administrators and residents
                </p>

            </div>

        </section>

    </div>


    <script>
        // SWITCH TO SIGNUP FORM
        function showSignup(event) {
            event.preventDefault();

            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('signupForm').style.display = 'block';
        }


        // SWITCH TO LOGIN FORM
        function showLogin(event) {
            event.preventDefault();

            document.getElementById('signupForm').style.display = 'none';
            document.getElementById('loginForm').style.display = 'block';
        }


        // SHOW AND HIDE PASSWORD
        function togglePassword(inputId, button) {

            const passwordInput = document.getElementById(inputId);

            if (passwordInput.type === "password") {

                passwordInput.type = "text";

                button.setAttribute("aria-label", "Hide password");

            } else {

                passwordInput.type = "password";

                button.setAttribute("aria-label", "Show password");

            }
        }

        window.addEventListener('load', () => {
            setTimeout(() => {
                const loader = document.getElementById('pageLoader');

                if (loader) {
                    loader.classList.add('hide');

                    setTimeout(() => {
                        loader.remove();
                    }, 700);
                }
            }, 3000);
        });
        
        if (document.readyState === 'complete') {
            setTimeout(hidePageLoader, 900);
        } else {
            window.addEventListener('load', () => {
                setTimeout(hidePageLoader, 900);
            }, {
                once: true
            });
        }
    </script>

</body>

</html>