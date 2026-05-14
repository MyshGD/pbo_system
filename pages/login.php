<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user($pdo)) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        set_flash('error', 'Invalid form token. Please try again.');
        redirect('login.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        set_flash('error', 'Username and password are required.');
        redirect('login.php');
    }

    $ipAddress = client_ip_address();
    $rateLimit = login_rate_limit_status($pdo, $username, $ipAddress);
    if ($rateLimit['locked']) {
        audit_log($pdo, null, 'login_lockout', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
        set_flash('error', 'Too many failed login attempts. Try again in ' . format_wait_time((int) $rateLimit['seconds_remaining']) . '.');
        redirect('login.php');
    }

    if (!attempt_login($pdo, $username, $password)) {
        record_login_attempt($pdo, $username, $ipAddress, false);
        audit_log($pdo, null, 'login_failed', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
        $rateLimit = login_rate_limit_status($pdo, $username, $ipAddress);

        if ($rateLimit['locked']) {
            audit_log($pdo, null, 'login_lockout', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
            set_flash('error', 'Too many failed login attempts. Try again in ' . format_wait_time((int) $rateLimit['seconds_remaining']) . '.');
            redirect('login.php');
        }

        set_flash('error', 'Invalid credentials. Attempts remaining: ' . (string) $rateLimit['attempts_remaining'] . '.');
        redirect('login.php');
    }

    record_login_attempt($pdo, $username, $ipAddress, true);
    audit_log($pdo, current_user($pdo), 'login_success', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
    set_flash('success', 'Welcome back!');
    redirect('dashboard.php');
}

$flash = get_flash();
$org = organization_profile($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($org['system_name']) ?> | Login</title>
    <style>
        :root {
            --color-primary: #1B5E20;
            --color-primary-dark: #0D3818;
            --color-accent: #FFB81C;
            --color-accent-dark: #F59E0B;
            --color-success: #C8E6C9;
            --color-bg-light: #F7F9F6;
            --color-text-primary: #1B3A26;
            --color-text-secondary: #5A6B63;
            --color-text-light: #8B9B93;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            width: 100%;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: white;
            color: var(--color-text-primary);
            line-height: 1.6;
        }

        body {
            display: flex;
        }

        .auth-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
            min-height: 100vh;
        }

        /* Left Panel - Form */
        .auth-form-panel {
            background: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 2rem;
            overflow-y: auto;
            padding-top: 3rem;
        }

        .auth-form-container {
            width: 100%;
            max-width: min(380px, 90%);
        }

        .auth-logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .auth-logo {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            background: var(--color-primary);
            border: 2px solid var(--color-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.25rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(27, 94, 32, 0.15);
        }

        .auth-logo img {
            width: 85%;
            height: 85%;
            object-fit: contain;
        }

        .auth-org-info h1 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--color-primary);
            margin: 0;
            line-height: 1.2;
        }

        .auth-org-info p {
            font-size: 0.8125rem;
            color: var(--color-text-secondary);
            margin: 0.375rem 0 0 0;
            line-height: 1.3;
        }

        .auth-welcome {
            margin-bottom: 0.5rem;
        }

        .auth-welcome h2 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--color-primary);
            margin: 0 0 0.5rem 0;
            letter-spacing: -0.5px;
        }

        .auth-welcome p {
            font-size: 0.9375rem;
            color: var(--color-text-secondary);
            margin: 0;
        }

        .form-divider {
            height: 1px;
            background: #E5E7EB;
            margin: 1.5rem 0;
        }

        .alert {
            margin-bottom: 1rem;
            padding: 0.875rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-left: 3px solid;
        }

        .alert-error {
            background-color: #FEF2F2;
            border-left-color: #DC2626;
            color: #991B1B;
        }

        .alert-success {
            background-color: #F0FDF4;
            border-left-color: #16A34A;
            color: #166534;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-text-primary);
        }

        input[type="text"],
        input[type="password"],
        input[type="email"] {
            width: 100%;
            padding: 0.75rem;
            border: 1.5px solid #D1D5DB;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            background: white;
            color: var(--color-text-primary);
            transition: all 0.2s ease;
            font-family: inherit;
        }

        input[type="text"]:hover,
        input[type="password"]:hover,
        input[type="email"]:hover {
            border-color: #B0B9B3;
        }

        input[type="text"]:focus,
        input[type="password"]:focus,
        input[type="email"]:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(27, 94, 32, 0.1);
        }

        .btn-auth {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--color-primary);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 0.75rem;
            box-shadow: 0 2px 4px rgba(27, 94, 32, 0.2);
        }

        .btn-auth:hover {
            background-color: var(--color-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(27, 94, 32, 0.25);
        }

        .btn-auth:active {
            transform: translateY(0);
        }

        .btn-auth:focus-visible {
            outline: 2px solid var(--color-accent);
            outline-offset: 2px;
        }

        .auth-footer-text {
            font-size: 0.8125rem;
            color: var(--color-text-secondary);
            line-height: 1.5;
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid #E5E7EB;
        }

        .auth-footer-text strong {
            display: block;
            color: var(--color-primary);
            font-weight: 600;
            margin-bottom: 0.375rem;
        }

        .auth-link {
            margin-top: 1.25rem;
            text-align: center;
            font-size: 0.875rem;
        }

        .auth-link a {
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .auth-link a:hover {
            color: var(--color-primary-dark);
            text-decoration: underline;
        }

        .auth-link a:focus-visible {
            outline: 2px solid var(--color-accent);
            outline-offset: 2px;
        }

        /* Right Panel - Visual */
        .auth-visual-panel {
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            color: white;
            position: relative;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .auth-visual-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="white" opacity="0.1"/><circle cx="80" cy="80" r="3" fill="white" opacity="0.1"/><circle cx="80" cy="20" r="2" fill="white" opacity="0.05"/></svg>');
            background-size: 200px 200px;
            opacity: 0.3;
        }

        .auth-visual-content {
            position: relative;
            z-index: 10;
            text-align: center;
            max-width: min(320px, 85%);
        }

        .auth-visual-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
        }

        .auth-visual-content h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0 0 1rem 0;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }

        .auth-visual-content p {
            font-size: 1rem;
            margin: 0;
            line-height: 1.6;
            opacity: 0.95;
        }

        .auth-visual-accent {
            display: inline-block;
            width: 40px;
            height: 4px;
            background: var(--color-accent);
            border-radius: 2px;
            margin: 1.5rem auto;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .auth-wrapper {
                grid-template-columns: 1fr;
            }

            .auth-visual-panel {
                display: none;
            }

            .auth-form-panel {
                min-height: 100vh;
                padding: 2rem 1.5rem;
            }
        }

        @media (max-width: 640px) {
            .auth-form-panel {
                padding: 1.5rem 1rem;
            }

            .auth-form-container {
                max-width: 100%;
            }

            .auth-logo-section {
                margin-bottom: 1.5rem;
            }

            .auth-logo {
                width: 3rem;
                height: 3rem;
            }

            .auth-org-info h1 {
                font-size: 1rem;
            }

            .auth-welcome h2 {
                font-size: 1.5rem;
            }

            .auth-welcome p {
                font-size: 0.875rem;
            }

            .form-group {
                margin-bottom: 1rem;
            }

            label {
                font-size: 0.8125rem;
            }

            input[type="text"],
            input[type="password"],
            input[type="email"] {
                padding: 0.625rem;
                font-size: 0.875rem;
            }

            .btn-auth {
                padding: 0.625rem;
                font-size: 0.875rem;
            }
        }

        @media (max-width: 480px) {
            .auth-form-panel {
                padding: 1.25rem 1rem;
                min-height: auto;
            }

            .auth-logo-section {
                gap: 0.75rem;
                margin-bottom: 1.25rem;
            }

            .auth-org-info h1 {
                font-size: 0.9375rem;
            }

            .auth-org-info p {
                font-size: 0.75rem;
            }

            .form-divider {
                margin: 1.25rem 0;
            }

            .auth-welcome h2 {
                font-size: 1.375rem;
            }

            .auth-footer-text {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <!-- Left Panel: Form -->
        <div class="auth-form-panel">
            <div class="auth-form-container">
                <!-- Logo and Organization -->
                <div class="auth-logo-section">
                    <div class="auth-logo">
                        <img src="<?= h($org['logo_path']) ?>" alt="<?= h($org['campus_display_name']) ?> logo">
                    </div>
                    <div class="auth-org-info">
                        <h1><?= h($org['campus_display_name']) ?></h1>
                        <p><?= h($org['system_name']) ?></p>
                    </div>
                </div>

                <!-- Welcome Section -->
                <div class="auth-welcome">
                    <h2>Welcome back</h2>
                    <p>Sign in using your assigned campus account.</p>
                </div>

                <div class="form-divider"></div>

                <!-- Flash Messages -->
                <?php if ($flash): ?>
                    <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
                        <?= h($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" autocomplete="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn-auth">Log In</button>
                </form>

                <!-- Help Text -->
                <div class="auth-footer-text">
                    <strong>Account Security:</strong>
                    After 3 failed login attempts, your account will be temporarily locked for 15 minutes.
                </div>

                <!-- Register Link -->
                <div class="auth-link">
                    <a href="register.php">Create a new account</a>
                </div>
            </div>
        </div>

        <!-- Right Panel: Visual Branding -->
        <div class="auth-visual-panel">
            <div class="auth-visual-content">
                <div class="auth-visual-icon">🏢</div>
                <h3>Production and Business Operations</h3>
                <div class="auth-visual-accent"></div>
                <p>Manage records, sales, rentals, inventory, and reports in one place. Secure access for campus staff.</p>
            </div>
        </div>
    </div>
</body>
</html>
