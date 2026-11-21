<?php

session_start();

if (isset($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

$errorMessages = [
    "empty"    => "Please enter your email and password.",
    "invalid"  => "Invalid email or password.",
    "notadmin" => "This account does not have admin access.",
];

$successMessages = [
    "reset" => "Your password has been updated. You can now sign in.",
];

$error = $errorMessages[$_GET["error"] ?? ""] ?? "";
$success = $successMessages[$_GET["success"] ?? ""] ?? "";

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sign in · CitiSense Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/png" href="../css/images/brand.png">
    <link rel="stylesheet" href="../css/login.css?v=2">
</head>

<body>

<main class="auth-page">

    <?php include __DIR__ . "/partial/auth_brand.php"; ?>

    <section class="auth-panel">

        <div class="auth-card">

            <img src="../css/images/brand.png" alt="" class="auth-card-logo" aria-hidden="true">

            <header class="auth-header">
                <h2>Welcome back</h2>
                <p>Sign in to manage your community reports.</p>
            </header>

            <?php if ($error !== ""): ?>
                <div class="auth-alert auth-alert-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php elseif ($success !== ""): ?>
                <div class="auth-alert auth-alert-success" role="status">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <form action="../api/auth/login.php" method="POST" class="auth-form" id="loginForm" novalidate>

                <div class="form-group">
                    <label for="email">Email address</label>

                    <div class="input-wrap">
                        <i class="bi bi-envelope input-icon"></i>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="admin@barangay.gov.ph"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-group">
                    <div class="label-row">
                        <label for="password">Password</label>
                        <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <div class="input-wrap">
                        <i class="bi bi-lock input-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            id="passwordToggle"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>

                </div>

                <button type="submit" class="auth-button" id="loginButton">
                    <span class="auth-button-text">Sign in</span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>

            </form>

        </div>

    </section>

</main>

<script src="../js/auth.js?v=2"></script>

<?php if ($error !== "" || $success !== ""): ?>
<script>
    // Remove ?error / ?success from the address bar so a refresh doesn't show it again
    window.history.replaceState({}, document.title, window.location.pathname);
</script>
<?php endif; ?>

</body>
</html>
