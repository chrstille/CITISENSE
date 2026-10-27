<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/
function statusJsonResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD REPORTING CYCLES
|--------------------------------------------------------------------------
| Must match the cycle-classification rules in reportView.php:
| - Reports are grouped by barangay, category, and location.
| - The earliest report in a cycle is the Original.
| - Later reports are duplicates while that Original is open.
| - A report submitted after a resolved Original's recorded resolution
|   timestamp starts a new cycle.
| - If resolution history is missing, use the Original's submission time
|   only as the same grouping fallback used by reportView.php.
|--------------------------------------------------------------------------
*/
function statusLoadCycles(
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
    $currentCycle = null;
    $currentResolvedAt = null;

    foreach ($reports as $report) {
        $startNewCycle = ($currentCycle === null);

        if ($currentCycle !== null) {
            $currentOriginal = $currentCycle["reports"][0];

            if ((string) $currentOriginal["statusID"] === "STAT003") {
                $cycleBoundary = $currentResolvedAt;

                if ($cycleBoundary === null || $cycleBoundary === "") {
                    $cycleBoundary =
                        (string) $currentOriginal["dateReported"];
                }

                if (
                    strcmp(
                        (string) $report["dateReported"],
                        (string) $cycleBoundary
                    ) > 0
                ) {
                    $startNewCycle = true;
                }
            }
        }

        if ($startNewCycle) {
            if ($currentCycle !== null) {
                $cycles[] = $currentCycle;
            }

            $currentCycle = [
                "originalID" => (string) $report["reportID"],
                "reports" => []
            ];

            $originalID = (string) $report["reportID"];

            $historyStmt->bind_param("s", $originalID);
            $historyStmt->execute();

            $history = $historyStmt->get_result()->fetch_assoc();
            $currentResolvedAt = $history["resolvedAt"] ?? null;
        }

        $currentCycle["reports"][] = $report;
    }

    if ($currentCycle !== null) {
        $cycles[] = $currentCycle;
    }

    $historyStmt->close();

    return $cycles;
}

/*
|--------------------------------------------------------------------------
| FIND A CYCLE BY ITS ORIGINAL REPORT ID
|--------------------------------------------------------------------------
*/
function statusFindOriginalCycle(
    array $cycles,
    string $reportID
): ?array {
    foreach ($cycles as $cycle) {
        if ((string) $cycle["originalID"] === $reportID) {
            return $cycle;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| RECORD STATUS HISTORY AND NOTIFY THE RESIDENT
|--------------------------------------------------------------------------
*/
function statusRecordChange(
    mysqli $conn,
    string $reportID,
    string $residentID,
    string $adminID,
    string $statusID,
    string $statusName,
    ?string $remarks
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

    $message = "Your report " . $reportID .
        " is now " . $statusName . ".";

    if ($remarks !== null && $remarks !== "") {
        $message .= " The administrator also left a remark.";
    }

    $stmt = $conn->prepare(
        "INSERT INTO notification
            (notifID, userID, reportID, message, isRead)
         VALUES (?, ?, ?, ?, 0)"
    );

    $stmt->bind_param(
        "ssss",
        $notifID,
        $residentID,
        $reportID,
        $message
    );

    $stmt->execute();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| REQUEST METHOD
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    statusJsonResponse(405, [
        "success" => false,
        "message" => "Invalid request method."
    ]);
}

/*
|--------------------------------------------------------------------------
| ADMIN SESSION
|--------------------------------------------------------------------------
*/
$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    statusJsonResponse(401, [
        "success" => false,
        "message" => "Your session has expired. Please log in again."
    ]);
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION AND STATUS UPDATE
|--------------------------------------------------------------------------
*/
$transactionStarted = false;

try {
    require_once __DIR__ . "/../config/database.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException("Database connection unavailable.");
    }

    /*
    |--------------------------------------------------------------------------
    | READ JSON REQUEST
    |--------------------------------------------------------------------------
    */
    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        statusJsonResponse(400, [
            "success" => false,
            "message" => "Invalid request data. Expected JSON."
        ]);
    }

    $reportID = trim((string) ($input["reportID"] ?? ""));
    $statusID = trim((string) ($input["statusID"] ?? ""));
    $remarks = trim((string) ($input["remarks"] ?? ""));

    if (
        $reportID === "" ||
        strlen($reportID) > 50 ||
        !in_array($statusID, ["STAT001", "STAT002", "STAT003"], true) ||
        strlen($remarks) > 10000
    ) {
        statusJsonResponse(400, [
            "success" => false,
            "message" => "Invalid report ID, status, or remarks."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY THAT THE SESSION BELONGS TO AN ADMIN IN THIS BARANGAY
    |--------------------------------------------------------------------------
    */
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
        statusJsonResponse(403, [
            "success" => false,
            "message" => "Administrator access denied."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | BEGIN TRANSACTION
    |--------------------------------------------------------------------------
    */
    $conn->begin_transaction();
    $transactionStarted = true;

    /*
    |--------------------------------------------------------------------------
    | FIND THE SELECTED REPORT
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT reportID, categoryID, locationID
         FROM reports
         WHERE reportID = ?
           AND barangayID = ?
         LIMIT 1"
    );

    $stmt->bind_param("ss", $reportID, $barangayID);
    $stmt->execute();

    $reportKey = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reportKey) {
        throw new RuntimeException(
            "Report not found in your barangay."
        );
    }

    $categoryID = (string) $reportKey["categoryID"];
    $locationID = (string) $reportKey["locationID"];

    /*
    |--------------------------------------------------------------------------
    | FIND THE TARGET STATUS NAME FROM THE DATABASE
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT statusName
         FROM stats
         WHERE statusID = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $statusID);
    $stmt->execute();

    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        throw new RuntimeException(
            "The selected status does not exist."
        );
    }

    $targetStatusName = (string) $target["statusName"];

    /*
    |--------------------------------------------------------------------------
    | LOAD CYCLES AND CONFIRM THE SELECTED REPORT IS THE ORIGINAL
    |--------------------------------------------------------------------------
    */
    $cycles = statusLoadCycles(
        $conn,
        $barangayID,
        $categoryID,
        $locationID
    );

    $cycle = statusFindOriginalCycle($cycles, $reportID);

    if ($cycle === null || empty($cycle["reports"])) {
        throw new RuntimeException(
            "Only the Original Report can be updated. Duplicate Reports follow their Original Report."
        );
    }

    /*
     * The cycle is ordered by dateReported and reportID.
     * Its first report is the Original.
     */
    $originalReport = $cycle["reports"][0];

    if ((string) $originalReport["reportID"] !== $reportID) {
        throw new RuntimeException(
            "Only the Original Report can be updated. Duplicate Reports follow their Original Report."
        );
    }

    $currentStatusID = (string) $originalReport["statusID"];

    /*
    |--------------------------------------------------------------------------
    | ALLOWED STATUS TRANSITIONS
    |--------------------------------------------------------------------------
    */
    $allowedTransitions = [
        "STAT001" => ["STAT002", "STAT003"],
        "STAT002" => ["STAT003"],
        "STAT003" => [],
        "STAT004" => ["STAT003"]
    ];

    if ($currentStatusID === "STAT003") {
        if ($statusID !== "STAT003") {
            throw new RuntimeException(
                "This reporting cycle is already resolved and closed."
            );
        }

        $conn->commit();
        $transactionStarted = false;

        statusJsonResponse(200, [
            "success" => true,
            "changed" => false,
            "message" => "This reporting cycle is already resolved.",
            "updatedReportIDs" => []
        ]);
    }

    if (
        $currentStatusID !== $statusID &&
        !in_array(
            $statusID,
            $allowedTransitions[$currentStatusID] ?? [],
            true
        )
    ) {
        throw new RuntimeException(
            "This status transition is not allowed."
        );
    }

    if ($currentStatusID === $statusID) {
        $conn->commit();
        $transactionStarted = false;

        statusJsonResponse(200, [
            "success" => true,
            "changed" => false,
            "message" => "The Original Report already has this status.",
            "updatedReportIDs" => []
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE THE ORIGINAL AND ITS DUPLICATES
    |--------------------------------------------------------------------------
    | Record history and create a notification for each report whose status
    | changes. Any failure rolls back all changes in this transaction.
    |--------------------------------------------------------------------------
    */
    $updatedReportIDs = [];
    $remarksValue = $remarks === "" ? null : $remarks;

    $updateStmt = $conn->prepare(
        "UPDATE reports
         SET statusID = ?
         WHERE reportID = ?
           AND barangayID = ?"
    );

    foreach ($cycle["reports"] as $report) {
        $affectedReportID = (string) $report["reportID"];
        $residentID = (string) $report["userID"];
        $oldStatusID = (string) $report["statusID"];

        if ($oldStatusID === $statusID) {
            continue;
        }

        $updateStmt->bind_param(
            "sss",
            $statusID,
            $affectedReportID,
            $barangayID
        );

        $updateStmt->execute();

        if ($updateStmt->affected_rows !== 1) {
            throw new RuntimeException(
                "A report could not be updated. No changes were saved."
            );
        }

        statusRecordChange(
            $conn,
            $affectedReportID,
            $residentID,
            $adminID,
            $statusID,
            $targetStatusName,
            $remarksValue
        );

        $updatedReportIDs[] = $affectedReportID;
    }

    $updateStmt->close();

    /*
    |--------------------------------------------------------------------------
    | COMMIT SUCCESSFUL CHANGES
    |--------------------------------------------------------------------------
    */
    $conn->commit();
    $transactionStarted = false;

    statusJsonResponse(200, [
        "success" => true,
        "changed" => count($updatedReportIDs) > 0,
        "message" => $statusID === "STAT003"
            ? "The Original Report and its duplicates have been resolved."
            : "The Original Report and its duplicates have been updated.",
        "reportID" => $reportID,
        "statusID" => $statusID,
        "statusName" => $targetStatusName,
        "updatedReportIDs" => $updatedReportIDs
    ]);

} catch (Throwable $error) {
    if (
        $transactionStarted &&
        isset($conn) &&
        $conn instanceof mysqli
    ) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                "Status rollback failed: " .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        "Report status update failed: " . $error->getMessage()
    );

    $clientErrors = [
        "Report not found in your barangay.",
        "The selected status does not exist.",
        "This reporting cycle is already resolved and closed.",
        "Only the Original Report can be updated. Duplicate Reports follow their Original Report.",
        "This status transition is not allowed.",
        "A report could not be updated. No changes were saved."
    ];

    $isClientError = in_array(
        $error->getMessage(),
        $clientErrors,
        true
    );

    statusJsonResponse($isClientError ? 409 : 500, [
        "success" => false,
        "message" => $isClientError
            ? $error->getMessage()
            : "Unable to update report status. Check the PHP error log for details."
    ]);
}

