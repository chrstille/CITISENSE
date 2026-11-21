<?php

session_start();

if (isset($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../lib/mailer.php";
require_once __DIR__ . "/../lib/password_reset.php";

$sent = false;
$devResetLink = "";
$devNotice = "";   // what actually happened (shown only on localhost / debug mode)

$mailConfig = citisenseMailConfig();
$isLocal = in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);
$showDetails = $isLocal || !empty($mailConfig["debug"]);
$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim((string) ($_POST["email"] ?? ""));

    /* Basic CSRF protection */
    $csrfOk = isset($_SESSION["forgot_csrf"], $_POST["csrf"])
        && hash_equals($_SESSION["forgot_csrf"], (string) $_POST["csrf"]);

    if (!$csrfOk) {
        $error = "Your session expired. Please try again.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        citisenseEnsureResetTable($conn);

        $stmt = $conn->prepare(
            "SELECT userID, firstName, lastName, email
               FROM users
              WHERE email = ?
                AND role = 'admin'
              LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin) {
            $devNotice = "No email was sent: no admin account in the users table has the email \""
                . $email . "\". Check the spelling, or that this user's role is 'admin'.";
        }

        if ($admin) {

            /* Throttle: one email per account per minute */
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS recent
                   FROM password_resets
                  WHERE userID = ?
                    AND createdAt > (NOW() - INTERVAL 1 MINUTE)"
            );
            $stmt->bind_param("s", $admin["userID"]);
            $stmt->execute();
            $recent = (int) ($stmt->get_result()->fetch_assoc()["recent"] ?? 0);
            $stmt->close();

            if ($recent > 0) {
                $devNotice = "No new email was sent: a reset link was already requested less than a minute ago. "
                    . "Wait 60 seconds and try again (this stops people from spamming the inbox).";
            }

            if ($recent === 0) {

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash("sha256", $token);
                $minutes = CITISENSE_RESET_MINUTES;

                /* Invalidate older unused links */
                $stmt = $conn->prepare(
                    "UPDATE password_resets
                        SET usedAt = NOW()
                      WHERE userID = ?
                        AND usedAt IS NULL"
                );
                $stmt->bind_param("s", $admin["userID"]);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO password_resets (userID, tokenHash, expiresAt)
                     VALUES (?, ?, NOW() + INTERVAL ? MINUTE)"
                );
                $stmt->bind_param("ssi", $admin["userID"], $tokenHash, $minutes);
                $stmt->execute();
                $stmt->close();

                $resetLink = citisenseResetBaseUrl() . "/reset_password.php?token=" . $token;
                $name = trim($admin["firstName"] . " " . $admin["lastName"]);
                $safeName = htmlspecialchars($admin["firstName"] ?: "Admin", ENT_QUOTES, "UTF-8");
                $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, "UTF-8");

                $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#33415c;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:32px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8edf5;">
        <tr><td style="background:#1d6ff2;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:bold;">CitiSense</td></tr>
        <tr><td style="padding:28px;">
          <p style="margin:0 0 14px;font-size:16px;color:#0f1b3d;font-weight:bold;">Hi {$safeName},</p>
          <p style="margin:0 0 20px;font-size:14px;line-height:1.6;">We received a request to reset the password for your CitiSense admin account. Click the button below to choose a new password.</p>
          <p style="margin:0 0 24px;"><a href="{$safeLink}" style="display:inline-block;background:#1d6ff2;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:12px 22px;border-radius:10px;">Reset my password</a></p>
          <p style="margin:0 0 8px;font-size:13px;color:#7a879e;line-height:1.6;">This link expires in {$minutes} minutes and can only be used once. If you didn't request this, you can safely ignore this email; your password won't change.</p>
          <p style="margin:18px 0 0;font-size:12px;color:#a1abbd;word-break:break-all;">If the button doesn't work, copy this link into your browser:<br>{$safeLink}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

                $text = "Hi " . ($admin["firstName"] ?: "Admin") . ",\n\n"
                    . "We received a request to reset your CitiSense admin password.\n"
                    . "Open this link to choose a new password (valid for {$minutes} minutes):\n\n"
                    . $resetLink . "\n\n"
                    . "If you didn't request this, you can ignore this email.\n";

                [$ok, $mailError] = citisenseSendMail(
                    $admin["email"],
                    $name,
                    "Reset your CitiSense admin password",
                    $html,
                    $text
                );

                if ($ok) {
                    $sender = $mailConfig["from_email"] ?: $mailConfig["username"];

                    $devNotice = "Gmail accepted the email for " . $admin["email"] . ". "
                        . (strcasecmp($sender, $admin["email"]) === 0
                            ? "Because it was sent FROM and TO the same Gmail account, Gmail usually files it under \"Sent\" or \"All Mail\" instead of the Inbox. Search Gmail for \"Reset your CitiSense\"."
                            : "It can take a minute to arrive. Also check Spam and Promotions.");
                    $devResetLink = $resetLink;
                }

                if (!$ok) {
                    error_log("CitiSense forgot password mail error: " . $mailError);

                    /* On your own computer (localhost), always show the real reason
                       and the reset link so you can keep testing while email is set up. */
                    if ($showDetails) {
                        $error = "Email failed: " . $mailError;
                        $devResetLink = $resetLink;      // keep the link usable for testing
                    } else {
                        /* Remove the unsent link so the admin can retry right away */
                        $stmt = $conn->prepare("DELETE FROM password_resets WHERE tokenHash = ?");
                        $stmt->bind_param("s", $tokenHash);
                        $stmt->execute();
                        $stmt->close();

                        $error = "We couldn't send the email right now. Please contact your system administrator.";
                    }
                }
            }
        }

        /* Same message whether or not the email exists (prevents account guessing) */
        if ($error === "") {
            $sent = true;
        }
    }
}

$_SESSION["forgot_csrf"] = bin2hex(random_bytes(16));

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Forgot password · CitiSense Admin</title>

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

            <?php if ($sent): ?>

                <div class="auth-state-icon is-success">
                    <i class="bi bi-envelope-check"></i>
                </div>

                <header class="auth-header">
                    <h2>Check your email</h2>
                    <p>
                        If <strong><?= htmlspecialchars($email) ?></strong> belongs to an admin account,
                        we've sent a link to reset your password. The link expires in
                        <?= CITISENSE_RESET_MINUTES ?> minutes.
                    </p>
                </header>

                <?php if ($showDetails && $devNotice !== ""): ?>
                    <div class="auth-alert auth-dev-note" role="status">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            <strong>What happened (only shown on this computer):</strong><br>
                            <?= htmlspecialchars($devNotice) ?>
                            <?php if ($devResetLink !== ""): ?>
                                <br><a href="<?= htmlspecialchars($devResetLink) ?>">Open the reset link directly</a>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <a href="login.php" class="auth-button auth-button-link">
                    <span class="auth-button-text">Back to sign in</span>
                </a>

                <p class="auth-footnote">
                    Didn't get it? Check your spam folder or
                    <a href="forgot_password.php">try again</a>.
                </p>

            <?php else: ?>

                <a href="login.php" class="auth-back">
                    <i class="bi bi-arrow-left"></i> Back to sign in
                </a>

                <div class="auth-state-icon">
                    <i class="bi bi-key"></i>
                </div>

                <header class="auth-header">
                    <h2>Forgot your password?</h2>
                    <p>Enter the email address you use for your admin account and we'll email you a reset link.</p>
                </header>

                <?php if ($error !== ""): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($devResetLink !== ""): ?>
                    <div class="auth-alert auth-alert-success" role="status">
                        <i class="bi bi-tools"></i>
                        <span>
                            Testing on this computer: the email wasn't sent, but you can still
                            <a href="<?= htmlspecialchars($devResetLink) ?>"><strong>open the reset link</strong></a>.
                            This only appears on localhost.
                        </span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" novalidate>

                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["forgot_csrf"]) ?>">

                    <div class="form-group">
                        <label for="email">Email address</label>

                        <div class="input-wrap">
                            <i class="bi bi-envelope input-icon"></i>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars($email) ?>"
                                placeholder="admin@barangay.gov.ph"
                                autocomplete="email"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <button type="submit" class="auth-button">
                        <span class="auth-button-text">Send reset link</span>
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
