<?php
/*
|--------------------------------------------------------------------------
| ACCEPT OR DECLINE A PENDING REPORT
|--------------------------------------------------------------------------
| POST JSON: { reportID, action: "accept" | "decline", reason }
|
| accept  - While the case is Pending, moves only this report to
|           In Progress; its duplicates stay Pending. If the Original is
|           already In Progress or Overdue, every Pending report in the
|           case joins it with the Original's status.
| decline - Marks only this report as Declined, records the reason in
|           reportHistory and notifies the resident with the reason.
|           Declined reports leave the reporting cycle, so the next
|           report in line becomes the Original.
|--------------------------------------------------------------------------
*/
session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function reviewJsonResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Find the "Declined" status, creating it the first time it is needed.
 */
function reviewDeclinedStatusID(mysqli $conn): string
{
    $result = $conn->query(
        "SELECT statusID
         FROM stats
         WHERE LOWER(TRIM(statusName)) = 'declined'
         LIMIT 1"
    );

    $row = $result->fetch_assoc();

    if ($row) {
        return (string) $row["statusID"];
    }

    $highest = 0;
    $result = $conn->query("SELECT statusID FROM stats");

    while ($existing = $result->fetch_assoc()) {
        if (preg_match('/^STAT(\d+)$/', (string) $existing["statusID"], $match)) {
            $highest = max($highest, (int) $match[1]);
        }
    }

    $statusID = "STAT" . str_pad((string) ($highest + 1), 3, "0", STR_PAD_LEFT);
    $statusName = "Declined";

    $stmt = $conn->prepare(
        "INSERT INTO stats (statusID, statusName) VALUES (?, ?)"
    );
    $stmt->bind_param("ss", $statusID, $statusName);
    $stmt->execute();
    $stmt->close();

    return $statusID;
}

function reviewStatusName(mysqli $conn, string $statusID): string
{
    $stmt = $conn->prepare(
        "SELECT statusName FROM stats WHERE statusID = ? LIMIT 1"
    );
    $stmt->bind_param("s", $statusID);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) $row["statusName"] : $statusID;
}

/**
 * Load the reporting cycles for one category + location.
 * Same rules as update_report_status.php; Declined reports are excluded.
 */
function reviewLoadCycles(
    mysqli $conn,
    string $barangayID,
    string $categoryID,
    string $locationID
): array {
    $stmt = $conn->prepare(
        "SELECT
            reportID,
            userID,
            statusID,
            dateReported
         FROM reports
         WHERE barangayID = ?
           AND categoryID = ?
           AND locationID = ?
           AND statusID NOT IN (
               SELECT statusID
               FROM stats
               WHERE LOWER(TRIM(statusName)) = 'declined'
           )
         ORDER BY dateReported ASC, reportID ASC
         FOR UPDATE"
    );

    $stmt->bind_param("sss", $barangayID, $categoryID, $locationID);
    $stmt->execute();
    $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$reports) {
        return [];
    }

    $historyStmt = $conn->prepare(
        "SELECT MIN(dateChanged) AS resolvedAt
         FROM reportHistory
         WHERE reportID = ?
           AND statusID = 'STAT003'"
    );

    $cycles = [];
    $currentCycle = null;
    $currentResolvedAt = null;

    foreach ($reports as $report) {
        $startNewCycle = ($currentCycle === null);

        if ($currentCycle !== null) {
            $currentOriginal = $currentCycle[0];

            if ((string) $currentOriginal["statusID"] === "STAT003") {
                $boundary = $currentResolvedAt;

                if ($boundary === null || $boundary === "") {
                    $boundary = (string) $currentOriginal["dateReported"];
                }

                if (strcmp((string) $report["dateReported"], (string) $boundary) > 0) {
                    $startNewCycle = true;
                }
            }
        }

        if ($startNewCycle) {
            if ($currentCycle !== null) {
                $cycles[] = $currentCycle;
            }

            $currentCycle = [];

            $originalID = (string) $report["reportID"];
            $historyStmt->bind_param("s", $originalID);
            $historyStmt->execute();

            $history = $historyStmt->get_result()->fetch_assoc();
            $currentResolvedAt = $history["resolvedAt"] ?? null;
        }

        $currentCycle[] = $report;
    }

    if ($currentCycle !== null) {
        $cycles[] = $currentCycle;
    }

    $historyStmt->close();

    return $cycles;
}

function reviewRecordChange(
    mysqli $conn,
    string $reportID,
    string $residentID,
    string $adminID,
    string $statusID,
    ?string $remarks,
    string $message
): void {
    $historyID = "H" . bin2hex(random_bytes(16));
    $dateChanged = date("Y-m-d H:i:s");

    $stmt = $conn->prepare(
        "INSERT INTO reportHistory
            (historyID, reportID, userID, statusID, remarks, dateChanged)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "ssssss",
        $historyID,
        $reportID,
        $adminID,
        $statusID,
        $remarks,
        $dateChanged
    );
    $stmt->execute();
    $stmt->close();

    $notifID = "N" . bin2hex(random_bytes(16));

    $stmt = $conn->prepare(
        "INSERT INTO notification
            (notifID, userID, reportID, message, isRead)
         VALUES (?, ?, ?, ?, 0)"
    );
    $stmt->bind_param("ssss", $notifID, $residentID, $reportID, $message);
    $stmt->execute();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| REQUEST CHECKS
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    reviewJsonResponse(405, [
        "success" => false,
        "message" => "Invalid request method."
    ]);
}

$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    reviewJsonResponse(401, [
        "success" => false,
        "message" => "Your session has expired. Please log in again."
    ]);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    reviewJsonResponse(400, [
        "success" => false,
        "message" => "Invalid request data. Expected JSON."
    ]);
}

$reportID = trim((string) ($input["reportID"] ?? ""));
$action = trim((string) ($input["action"] ?? ""));
$reason = trim((string) ($input["reason"] ?? ""));

if ($reportID === "" || strlen($reportID) > 50 || !in_array($action, ["accept", "decline"], true)) {
    reviewJsonResponse(400, [
        "success" => false,
        "message" => "Invalid report or action."
    ]);
}

if ($action === "decline") {
    $reasonLength = function_exists("mb_strlen") ? mb_strlen($reason, "UTF-8") : strlen($reason);

    if ($reasonLength < 5) {
        reviewJsonResponse(400, [
            "success" => false,
            "message" => "Please write a short reason for declining (at least 5 characters)."
        ]);
    }

    if ($reasonLength > 1000) {
        reviewJsonResponse(400, [
            "success" => false,
            "message" => "The reason must be 1000 characters or fewer."
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| ACCEPT / DECLINE
|--------------------------------------------------------------------------
*/
$transactionStarted = false;

try {
    require_once __DIR__ . "/../config/database.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException("Database connection unavailable.");
    }

    $stmt = $conn->prepare(
        "SELECT userID
         FROM users
         WHERE userID = ?
           AND barangayID = ?
           AND LOWER(role) = 'admin'
         LIMIT 1"
    );
    $stmt->bind_param("ss", $adminID, $barangayID);
    $stmt->execute();
    $authorized = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$authorized) {
        reviewJsonResponse(403, [
            "success" => false,
            "message" => "Administrator access denied."
        ]);
    }

    /* Make sure the Declined status exists before the transaction starts */
    $declinedStatusID = $action === "decline" ? reviewDeclinedStatusID($conn) : "";

    $conn->begin_transaction();
    $transactionStarted = true;

    $stmt = $conn->prepare(
        "SELECT reportID, userID, statusID, categoryID, locationID
         FROM reports
         WHERE reportID = ?
           AND barangayID = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param("ss", $reportID, $barangayID);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) {
        throw new DomainException("Report not found in your barangay.");
    }

    if ((string) $report["statusID"] !== "STAT001") {
        throw new DomainException("This report has already been reviewed.");
    }

    $updateStmt = $conn->prepare(
        "UPDATE reports
         SET statusID = ?
         WHERE reportID = ?
           AND barangayID = ?
           AND statusID = 'STAT001'"
    );

    /* ---------------- DECLINE ---------------- */
    if ($action === "decline") {
        $updateStmt->bind_param("sss", $declinedStatusID, $reportID, $barangayID);
        $updateStmt->execute();

        if ($updateStmt->affected_rows !== 1) {
            throw new DomainException("The report could not be declined. No changes were saved.");
        }

        $updateStmt->close();

        $shortReason = function_exists("mb_strimwidth")
            ? mb_strimwidth($reason, 0, 180, "…", "UTF-8")
            : (strlen($reason) > 180 ? substr($reason, 0, 177) . "..." : $reason);

        reviewRecordChange(
            $conn,
            $reportID,
            (string) $report["userID"],
            $adminID,
            $declinedStatusID,
            $reason,
            "Your report " . $reportID . " was declined. Reason: " . $shortReason
        );

        $conn->commit();
        $transactionStarted = false;

        reviewJsonResponse(200, [
            "success" => true,
            "message" => "Report declined. The resident has been notified.",
            "updatedReportIDs" => [$reportID]
        ]);
    }

    /* ---------------- ACCEPT ---------------- */
    $cycles = reviewLoadCycles(
        $conn,
        $barangayID,
        (string) $report["categoryID"],
        (string) $report["locationID"]
    );

    $cycle = null;

    foreach ($cycles as $candidate) {
        foreach ($candidate as $member) {
            if ((string) $member["reportID"] === $reportID) {
                $cycle = $candidate;
                break 2;
            }
        }
    }

    $originalStatusID = $cycle ? (string) $cycle[0]["statusID"] : "STAT001";

    if (in_array($originalStatusID, ["STAT002", "STAT004"], true)) {
        /* The case is already being worked on: join it */
        $targetStatusID = $originalStatusID;
        $toUpdate = array_filter($cycle, fn($member) => (string) $member["statusID"] === "STAT001");
    } else {
        /*
         * The case is still Pending: accept only this report.
         * Its duplicates stay Pending and are reviewed one by one.
         */
        $targetStatusID = "STAT002";
        $toUpdate = [$report];
    }

    $targetStatusName = reviewStatusName($conn, $targetStatusID);
    $updatedReportIDs = [];

    foreach ($toUpdate as $member) {
        $memberID = (string) $member["reportID"];

        $updateStmt->bind_param("sss", $targetStatusID, $memberID, $barangayID);
        $updateStmt->execute();

        if ($updateStmt->affected_rows !== 1) {
            throw new DomainException("A report could not be updated. No changes were saved.");
        }

        reviewRecordChange(
            $conn,
            $memberID,
            (string) $member["userID"],
            $adminID,
            $targetStatusID,
            null,
            "Your report " . $memberID . " has been accepted and is now " . $targetStatusName . "."
        );

        $updatedReportIDs[] = $memberID;
    }

    $updateStmt->close();

    $conn->commit();
    $transactionStarted = false;

    $count = count($updatedReportIDs);

    reviewJsonResponse(200, [
        "success" => true,
        "message" => $count > 1
            ? "Report accepted. " . $count . " related reports are now " . $targetStatusName . "."
            : "Report accepted and moved to " . $targetStatusName . ".",
        "updatedReportIDs" => $updatedReportIDs
    ]);

} catch (Throwable $error) {
    if ($transactionStarted && isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log("Review rollback failed: " . $rollbackError->getMessage());
        }
    }

    error_log("Report review failed: " . $error->getMessage());

    $isClientError = $error instanceof DomainException;

    reviewJsonResponse($isClientError ? 409 : 500, [
        "success" => false,
        "message" => $isClientError
            ? $error->getMessage()
            : "Unable to review the report. Check the PHP error log for details."
    ]);
}