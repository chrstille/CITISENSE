<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../lib/password_reset.php";

$token = trim((string) ($_GET["token"] ?? $_POST["token"] ?? ""));
$reset = citisenseFindResetToken($conn, $token);
$error = "";

if ($reset && $_SERVER["REQUEST_METHOD"] === "POST") {

    $password = (string) ($_POST["password"] ?? "");
    $confirm = (string) ($_POST["confirm_password"] ?? "");

    if (strlen($password) < 8) {
        $error = "Your new password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("UPDATE users SET pass = ? WHERE userID = ? AND role = 'admin'");
            $stmt->bind_param("ss", $hash, $reset["userID"]);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE password_resets SET usedAt = NOW() WHERE userID = ? AND usedAt IS NULL");
            $stmt->bind_param("s", $reset["userID"]);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

        } catch (Throwable $exception) {
            $conn->rollback();
            error_log("CitiSense reset password error: " . $exception->getMessage());
            $error = "Something went wrong. Please try again.";
        }

        if ($error === "") {
            header("Location: login.php?success=reset");
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reset password · CitiSense Admin</title>

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

            <?php if (!$reset): ?>

                <div class="auth-state-icon is-error">
                    <i class="bi bi-link-45deg"></i>
                </div>

                <header class="auth-header">
                    <h2>This link is no longer valid</h2>
                    <p>Password reset links expire after <?= CITISENSE_RESET_MINUTES ?> minutes and can only be used once. Request a new one to continue.</p>
                </header>

                <a href="forgot_password.php" class="auth-button auth-button-link">
                    <span class="auth-button-text">Request a new link</span>
                </a>

                <p class="auth-footnote"><a href="login.php">Back to sign in</a></p>

            <?php else: ?>

                <div class="auth-state-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>

                <header class="auth-header">
                    <h2>Set a new password</h2>
                    <p>For <strong><?= htmlspecialchars($reset["email"]) ?></strong>. Use at least 8 characters.</p>
                </header>

                <?php if ($error !== ""): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" novalidate>

                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="form-group">
                        <label for="newPassword">New password</label>

                        <div class="input-wrap">
                            <i class="bi bi-lock input-icon"></i>
                            <input
                                type="password"
                                id="newPassword"
                                name="password"
                                placeholder="At least 8 characters"
                                autocomplete="new-password"
                                minlength="8"
                                required
                                autofocus
                            >
                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>

                        <div class="strength">
                            <div class="strength-meter" id="strengthMeter" data-level="0">
                                <span></span><span></span><span></span><span></span>
                            </div>
                            <small id="strengthLabel"></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirm new password</label>

                        <div class="input-wrap">
                            <i class="bi bi-lock input-icon"></i>
                            <input
                                type="password"
                                id="confirmPassword"
                                name="confirm_password"
                                placeholder="Re-enter your new password"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>

                    </div>

                    <button type="submit" class="auth-button">
                        <span class="auth-button-text">Update password</span>
                        <span class="auth-spinner" aria-hidden="true"></span>
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </section>

</main>

<script src="../js/auth.js?v=2"></script>

</body>
</html>
