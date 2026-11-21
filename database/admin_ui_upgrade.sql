-- =============================================================
-- CitiSense admin UI upgrade
-- These tables are also created automatically the first time
-- they're needed, so running this file is optional.
-- =============================================================

-- Forgot password: one row per reset link (only a hash of the token is stored)
CREATE TABLE IF NOT EXISTS password_resets (
    resetID     INT AUTO_INCREMENT PRIMARY KEY,
    userID      VARCHAR(50)  NOT NULL,
    tokenHash   CHAR(64)     NOT NULL,
    expiresAt   DATETIME     NOT NULL,
    usedAt      DATETIME     NULL,
    createdAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (tokenHash),
    KEY idx_user (userID)
);

-- Notification bell: everything submitted before clearedBefore counts as read
CREATE TABLE IF NOT EXISTS admin_notification_state (
    adminID        VARCHAR(50) PRIMARY KEY,
    clearedBefore  DATETIME    NOT NULL
);

-- Notification bell: individual reports an admin has opened
CREATE TABLE IF NOT EXISTS admin_notification_reads (
    adminID   VARCHAR(50) NOT NULL,
    reportID  VARCHAR(50) NOT NULL,
    readAt    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (adminID, reportID)
);
