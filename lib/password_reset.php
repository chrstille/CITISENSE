<?php
/*
 * Password reset helpers.
 * The table is created automatically the first time it is needed
 * (see also database/admin_ui_upgrade.sql).
 */

const CITISENSE_RESET_MINUTES = 30;

function citisenseEnsureResetTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS password_resets (
            resetID     INT AUTO_INCREMENT PRIMARY KEY,
            userID      VARCHAR(50)  NOT NULL,
            tokenHash   CHAR(64)     NOT NULL,
            expiresAt   DATETIME     NOT NULL,
            usedAt      DATETIME     NULL,
            createdAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_token (tokenHash),
            KEY idx_user (userID)
        )"
    );
}

function citisenseResetBaseUrl(): string
{
    $config = require __DIR__ . "/../config/mail.php";

    if (!empty($config["app_url"])) {
        return rtrim($config["app_url"], "/");
    }

    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (($_SERVER["SERVER_PORT"] ?? "") === "443");

    $scheme = $https ? "https" : "http";
    $host = $_SERVER["HTTP_HOST"] ?? "localhost";
    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["PHP_SELF"] ?? "/admin/")), "/");

    return $scheme . "://" . $host . $dir;
}

/* Returns the reset row (with user info) for a valid, unused, unexpired token */
function citisenseFindResetToken(mysqli $conn, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    citisenseEnsureResetTable($conn);

    $hash = hash("sha256", $token);

    $stmt = $conn->prepare(
        "SELECT pr.resetID, pr.userID, u.email, u.firstName
           FROM password_resets pr
           INNER JOIN users u ON u.userID = pr.userID
          WHERE pr.tokenHash = ?
            AND pr.usedAt IS NULL
            AND pr.expiresAt > NOW()
            AND u.role = 'admin'
          LIMIT 1"
    );

    $stmt->bind_param("s", $hash);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
