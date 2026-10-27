
<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function overdueResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/**
 * Load reports and divide them into reporting cycles.
 *
 * Same barangay + category + location:
 * - Earliest report is the original.
 * - Later reports belong to that cycle until the original is resolved.
 * - A report submitted after the original's recorded resolution starts
 *   a new cycle.
 */
function overdueLoadCycles(
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
            dateReported,
            timeline
         FROM reports
         WHERE barangayID = ?
           AND categoryID = ?
           AND locationID = ?
         ORDER BY dateReported ASC, reportID ASC
         FOR UPDATE"
    );

    $stmt->bind_param(
        "sss",
        $barangayID,
        $categoryID,
        $locationID
    );

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
    $cycleIndex = -1;
    $resolvedAt = null;

    foreach ($reports as $report) {
        $submittedAt = $report["dateReported"];

        $startNewCycle = (
            $cycleIndex === -1 ||
            (
                $resolvedAt !== null &&
                $submittedAt !== null &&
                strcmp((string) $submittedAt, (string) $resolvedAt) > 0
            )
        );

        if ($startNewCycle) {
            $cycleIndex++;

            $cycles[$cycleIndex] = [
                "originalID" => $report["reportID"],
                "reports" => []
            ];

            // Determine when this original report was first resolved.
            $originalID = $report["reportID"];

            $historyStmt->bind_param("s", $originalID);
            $historyStmt->execute();

            $history = $historyStmt->get_result()->fetch_assoc();
            $resolvedAt = $history["resolvedAt"] ?? null;
        }

        $cycles[$cycleIndex]["reports"][] = $report;
    }

    $historyStmt->close();

    return $cycles;
}

/**
 * Record history and notify the resident after a report
 * has actually changed to Overdue.
 */
function overdueRecordChange(
    mysqli $conn,
    string $reportID,
    string $residentID,
    string $adminID
): void {
    $historyID = "H" . bin2hex(random_bytes(16));

    $historyStmt = $conn->prepare(
        "INSERT INTO reportHistory
            (historyID, reportID, userID, statusID, remarks)
         VALUES (?, ?, ?, 'STAT004', ?)"
    );

    $remarks = "Automatically marked Overdue because the expected completion deadline passed.";

    $historyStmt->bind_param(
        "ssss",
        $historyID,
        $reportID,
        $adminID,
        $remarks
    );

    $historyStmt->execute();
    $historyStmt->close();

    $notifID = "N" . bin2hex(random_bytes(16));

    $message =
        "Your report " . $reportID .
        " is now Overdue because its expected completion deadline has passed.";

    $notifStmt = $conn->prepare(
        "INSERT INTO notification
            (notifID, userID, reportID, message, isRead)
         VALUES (?, ?, ?, ?, 0)"
    );

    $notifStmt->bind_param(
        "ssss",
        $notifID,
        $residentID,
        $reportID,
        $message
    );

    $notifStmt->execute();
    $notifStmt->close();
}

/**
 * Validate the HTTP method.
 */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    overdueResponse(405, [
        "success" => false,
        "message" => "Method not allowed."
    ]);
}

/**
 * Validate administrator session.
 */
$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    overdueResponse(401, [
        "success" => false,
        "message" => "Your session has expired. Please log in again."
    ]);
}

/**
 * Database connection.
 */
require_once __DIR__ . "/../config/database.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    overdueResponse(500, [
        "success" => false,
        "message" => "Database connection unavailable."
    ]);
}

$transactionStarted = false;

try {
    /**
     * Confirm that the current user is an administrator
     * assigned to this barangay.
     */
    $adminStmt = $conn->prepare(
        "SELECT userID
         FROM users
         WHERE userID = ?
           AND barangayID = ?
           AND LOWER(role) = 'admin'
         LIMIT 1"
    );

    $adminStmt->bind_param("ss", $adminID, $barangayID);
    $adminStmt->execute();

    $admin = $adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if (!$admin) {
        overdueResponse(403, [
            "success" => false,
            "message" => "Administrator access denied."
        ]);
    }

    /**
     * Use database time so deadline comparisons use the
     * same clock as the MySQL database.
     */
    $timeResult = $conn->query(
        "SELECT NOW() AS currentDatabaseTime"
    );

    $timeRow = $timeResult->fetch_assoc();
    $currentDatabaseTime = $timeRow["currentDatabaseTime"];
    $timeResult->free();

    /**
     * Begin one transaction for the entire check.
     * Either all report/history/notification changes commit,
     * or the transaction rolls back.
     */
    $conn->begin_transaction();
    $transactionStarted = true;

    /**
     * IMPORTANT:
     * Do not filter candidate groups by a duplicate report's
     * status or deadline. Evaluate the original report's
     * deadline after reconstructing each reporting cycle.
     */
    $pairStmt = $conn->prepare(
        "SELECT DISTINCT categoryID, locationID
         FROM reports
         WHERE barangayID = ?
         ORDER BY categoryID, locationID"
    );

    $pairStmt->bind_param("s", $barangayID);
    $pairStmt->execute();

    $pairs = $pairStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pairStmt->close();

    $updatedReportIDs = [];
    $updatedOriginalIDs = [];

    /**
     * Prepare the update once and reuse it.
     *
     * Only Pending or In Progress reports are changed to
     * Overdue. Already Resolved reports are never reopened.
     */
    $updateStmt = $conn->prepare(
        "UPDATE reports
         SET statusID = 'STAT004'
         WHERE reportID = ?
           AND barangayID = ?
           AND statusID IN ('STAT001', 'STAT002')"
    );

    foreach ($pairs as $pair) {
        $categoryID = (string) $pair["categoryID"];
        $locationID = (string) $pair["locationID"];

        $cycles = overdueLoadCycles(
            $conn,
            $barangayID,
            $categoryID,
            $locationID
        );

        foreach ($cycles as $cycle) {
            if (empty($cycle["reports"])) {
                continue;
            }

            // The first report in this cycle is the original.
            $original = $cycle["reports"][0];

            /**
             * Only an In Progress original can trigger
             * the automatic overdue process.
             */
            if ($original["statusID"] !== "STAT002") {
                continue;
            }

            $deadline = $original["timeline"] ?? null;

            if (
                $deadline === null ||
                trim((string) $deadline) === "" ||
                strtotime((string) $deadline) === false
            ) {
                // No valid expected completion deadline.
                continue;
            }

            if (strcmp(
                (string) $deadline,
                (string) $currentDatabaseTime
            ) > 0) {
                // Deadline has not passed yet.
                continue;
            }

            $cycleChanged = false;

            /**
             * Mark eligible reports in this cycle Overdue.
             * A report is logged and notified only if MySQL
             * confirms that its status actually changed.
             */
            foreach ($cycle["reports"] as $report) {
                $oldStatusID = (string) $report["statusID"];

                if (!in_array(
                    $oldStatusID,
                    ["STAT001", "STAT002"],
                    true
                )) {
                    continue;
                }

                $affectedReportID = (string) $report["reportID"];
                $residentID = (string) $report["userID"];

                $updateStmt->bind_param(
                    "ss",
                    $affectedReportID,
                    $barangayID
                );

                $updateStmt->execute();

                if ($updateStmt->affected_rows !== 1) {
                    continue;
                }

                overdueRecordChange(
                    $conn,
                    $affectedReportID,
                    $residentID,
                    $adminID
                );

                $updatedReportIDs[] = $affectedReportID;
                $cycleChanged = true;
            }

            if ($cycleChanged) {
                $updatedOriginalIDs[] = (string) $original["reportID"];
            }
        }
    }

    $updateStmt->close();

    $conn->commit();
    $transactionStarted = false;

    overdueResponse(200, [
        "success" => true,
        "message" => "Overdue report check completed successfully.",
        "checkedAt" => $currentDatabaseTime,
        "updatedCount" => count($updatedReportIDs),
        "updatedOriginalCount" => count($updatedOriginalIDs),
        "updatedReportIDs" => $updatedReportIDs,
        "updatedOriginalIDs" => $updatedOriginalIDs
    ]);

} catch (Throwable $error) {
    if ($transactionStarted) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                "Overdue-check rollback failed: " .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        "Automatic overdue check failed: " .
        $error->getMessage()
    );

    overdueResponse(500, [
        "success" => false,
        "message" => "Unable to complete the overdue check. Check the PHP error log."
    ]);
}
