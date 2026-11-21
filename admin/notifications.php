<?php
/*
 * Admin notifications for newly submitted resident reports.
 *
 *   GET  notifications.php               -> { unread, items: [...] }
 *   POST notifications.php  action=read      reportID=...  -> mark one as read
 *   POST notifications.php  action=read_all                -> mark all as read
 *
 * "New report" notifications are read straight from the reports table,
 * so they work no matter which app (mobile/web) the resident used.
 * Read/unread state is kept per admin in two small tables that are
 * created automatically (see database/admin_ui_upgrade.sql).
 */

session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized."]);
    exit;
}

require_once __DIR__ . "/../config/database.php";

$adminID = (string) $_SESSION["admin_id"];
$barangayID = (string) $_SESSION["barangay_id"];

/* ---------- Tables ---------- */

$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_notification_state (
        adminID        VARCHAR(50) PRIMARY KEY,
        clearedBefore  DATETIME    NOT NULL
    )"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_notification_reads (
        adminID   VARCHAR(50) NOT NULL,
        reportID  VARCHAR(50) NOT NULL,
        readAt    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (adminID, reportID)
    )"
);

/* First visit: treat reports older than 3 days as already seen */
$stmt = $conn->prepare(
    "INSERT IGNORE INTO admin_notification_state (adminID, clearedBefore)
     VALUES (?, NOW() - INTERVAL 3 DAY)"
);
$stmt->bind_param("s", $adminID);
$stmt->execute();
$stmt->close();

function notifRespond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Actions ---------- */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = json_decode((string) file_get_contents("php://input"), true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = (string) ($input["action"] ?? "");

    if ($action === "read_all") {

        $stmt = $conn->prepare(
            "UPDATE admin_notification_state
                SET clearedBefore = NOW()
              WHERE adminID = ?"
        );
        $stmt->bind_param("s", $adminID);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM admin_notification_reads WHERE adminID = ?");
        $stmt->bind_param("s", $adminID);
        $stmt->execute();
        $stmt->close();

        notifRespond(["success" => true]);
    }

    if ($action === "read") {

        $reportID = substr(trim((string) ($input["reportID"] ?? "")), 0, 50);

        if ($reportID === "") {
            notifRespond(["success" => false, "message" => "Missing report."], 400);
        }

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO admin_notification_reads (adminID, reportID)
             VALUES (?, ?)"
        );
        $stmt->bind_param("ss", $adminID, $reportID);
        $stmt->execute();
        $stmt->close();

        notifRespond(["success" => true]);
    }

    notifRespond(["success" => false, "message" => "Unknown action."], 400);
}

/* ---------- List ---------- */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS unread
       FROM reports r
       INNER JOIN admin_notification_state st
               ON st.adminID = ?
       LEFT JOIN admin_notification_reads rd
              ON rd.adminID = st.adminID
             AND rd.reportID = r.reportID
      WHERE r.barangayID = ?
        AND r.dateReported > st.clearedBefore
        AND rd.reportID IS NULL"
);
$stmt->bind_param("ss", $adminID, $barangayID);
$stmt->execute();
$unread = (int) ($stmt->get_result()->fetch_assoc()["unread"] ?? 0);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT
        r.reportID,
        r.categoryID,
        r.title,
        r.dateReported,
        TIMESTAMPDIFF(SECOND, r.dateReported, NOW()) AS secondsAgo,
        c.categoryName,
        l.address,
        u.firstName,
        u.lastName,
        (
            SELECT ri.imagePath
              FROM reportImages ri
             WHERE ri.reportID = r.reportID
             ORDER BY ri.uploadedAt ASC
             LIMIT 1
        ) AS imagePath,
        (
            r.dateReported > st.clearedBefore
            AND rd.reportID IS NULL
        ) AS isUnread
     FROM reports r
     INNER JOIN admin_notification_state st
             ON st.adminID = ?
     LEFT JOIN admin_notification_reads rd
            ON rd.adminID = st.adminID
           AND rd.reportID = r.reportID
     LEFT JOIN categories c ON c.categoryID = r.categoryID
     LEFT JOIN location l ON l.locationID = r.locationID
     LEFT JOIN users u ON u.userID = r.userID
     WHERE r.barangayID = ?
     ORDER BY r.dateReported DESC, r.reportID DESC
     LIMIT 20"
);
$stmt->bind_param("ss", $adminID, $barangayID);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$items = [];

foreach ($rows as $row) {
    $stamp = strtotime((string) $row["dateReported"]);
    $resident = trim(($row["firstName"] ?? "") . " " . ($row["lastName"] ?? ""));

    $items[] = [
        "reportID"   => (string) $row["reportID"],
        "categoryID" => (string) $row["categoryID"],
        "category"   => (string) ($row["categoryName"] ?? "Report"),
        "title"      => (string) ($row["title"] ?? ""),
        "address"    => (string) ($row["address"] ?? ""),
        "resident"   => $resident !== "" ? $resident : "A resident",
        "image"      => (string) ($row["imagePath"] ?? ""),
        "date"       => $stamp ? date("M j, Y · g:i A", $stamp) : "",
        "secondsAgo" => $row["secondsAgo"] !== null ? max(0, (int) $row["secondsAgo"]) : null,
        "unread"     => (bool) $row["isUnread"],
    ];
}

notifRespond([
    "success" => true,
    "unread"  => $unread,
    "items"   => $items,
]);
