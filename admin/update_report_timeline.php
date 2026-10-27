<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

function timelineJsonResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function timelineFail(mysqli $conn, bool &$transactionStarted, int $code, string $message): void
{
    if ($transactionStarted) {
        $conn->rollback();
        $transactionStarted = false;
    }

    timelineJsonResponse($code, [
        "success" => false,
        "message" => $message
    ]);
}

/*
|--------------------------------------------------------------------------
| LOAD REPORTS FOR THE SAME CATEGORY AND LOCATION
|--------------------------------------------------------------------------
| Earliest dateReported is the Original Report.
| This follows the existing grouping rule used by the project.
|--------------------------------------------------------------------------
*/
function timelineLoadCycles(
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

    $stmt->bind_param("sss", $barangayID, $categoryID, $locationID);
    $stmt->execute();

    $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$reports) {
        return [];
    }

    /*
     * A new cycle starts only when the previous Original Report
     * was resolved and the next report was submitted after that
     * resolution timestamp.
     */
    $resolutionStmt = $conn->prepare(
        "SELECT MAX(dateChanged) AS resolvedAt
         FROM reportHistory
         WHERE reportID = ?
           AND statusID = 'STAT003'"
    );

    $cycles = [];
    $cycleIndex = -1;
    $resolvedAt = null;

    foreach ($reports as $report) {
        $submittedAt = (string) $report["dateReported"];

        $startNewCycle = (
            $cycleIndex === -1 ||
            (
                $resolvedAt !== null &&
                $submittedAt > $resolvedAt
            )
        );

        if ($startNewCycle) {
            $cycleIndex++;

            $cycles[$cycleIndex] = [
                "originalID" => (string) $report["reportID"],
                "reports" => []
            ];

            $originalID = (string) $report["reportID"];

            $resolutionStmt->bind_param("s", $originalID);
            $resolutionStmt->execute();

            $resolution = $resolutionStmt->get_result()->fetch_assoc();
            $resolvedAt = $resolution["resolvedAt"] ?? null;
        }

        $cycles[$cycleIndex]["reports"][] = $report;
    }

    $resolutionStmt->close();

    return $cycles;
}

function timelineFindOriginalCycle(array $cycles, string $reportID): ?array
{
    foreach ($cycles as $cycle) {
        if ($cycle["originalID"] === $reportID) {
            return $cycle;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| RECORD STATUS CHANGE
|--------------------------------------------------------------------------
| reportHistory stores every actual status transition.
| dateChanged uses its database default.
|--------------------------------------------------------------------------
*/
function timelineRecordStatusChange(
    mysqli $conn,
    string $reportID,
    string $residentID,
    string $adminID,
    string $statusID,
    string $statusName
): void {
    $historyID = "H" . bin2hex(random_bytes(16));

    $stmt = $conn->prepare(
        "INSERT INTO reportHistory
            (historyID, reportID, userID, statusID, remarks)
         VALUES (?, ?, ?, ?, NULL)"
    );

    $stmt->bind_param(
        "ssss",
        $historyID,
        $reportID,
        $adminID,
        $statusID
    );

    $stmt->execute();
    $stmt->close();

    $notifID = "N" . bin2hex(random_bytes(16));
    $message = "Your report " . $reportID . " is now " . $statusName . ".";

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
| VALIDATE REQUEST
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    timelineJsonResponse(405, [
        "success" => false,
        "message" => "Invalid request method. Use POST."
    ]);
}

$adminID = (string) ($_SESSION["admin_id"] ?? "");
$barangayID = (string) ($_SESSION["barangay_id"] ?? "");

if ($adminID === "" || $barangayID === "") {
    timelineJsonResponse(401, [
        "success" => false,
        "message" => "Your session has expired. Please log in again."
    ]);
}

require_once __DIR__ . "/../config/database.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    timelineJsonResponse(500, [
        "success" => false,
        "message" => "Database connection unavailable."
    ]);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    timelineJsonResponse(400, [
        "success" => false,
        "message" => "Invalid request data."
    ]);
}

$reportID = trim((string) ($input["reportID"] ?? ""));
$timeline = trim((string) ($input["timeline"] ?? ""));

if (
    $reportID === "" ||
    strlen($reportID) > 50 ||
    $timeline === ""
) {
    timelineJsonResponse(400, [
        "success" => false,
        "message" => "Report ID and expected completion date and time are required."
    ]);
}

/*
|--------------------------------------------------------------------------
| VALIDATE DATETIME-LOCAL INPUT
|--------------------------------------------------------------------------
*/
$format = strlen($timeline) === 19
    ? "!Y-m-d\TH:i:s"
    : "!Y-m-d\TH:i";

$expectedFormat = strlen($timeline) === 19
    ? "Y-m-d\TH:i:s"
    : "Y-m-d\TH:i";

$date = DateTimeImmutable::createFromFormat($format, $timeline);
$dateErrors = DateTimeImmutable::getLastErrors();

$validDate = (
    $date !== false &&
    $date->format($expectedFormat) === $timeline &&
    (
        $dateErrors === false ||
        (
            $dateErrors["warning_count"] === 0 &&
            $dateErrors["error_count"] === 0
        )
    )
);

if (!$validDate) {
    timelineJsonResponse(400, [
        "success" => false,
        "message" => "Please select a valid completion date and time."
    ]);
}

$mysqlTimeline = $date->format("Y-m-d H:i:s");
$transactionStarted = false;

try {
    /*
    |--------------------------------------------------------------------------
    | VERIFY ADMINISTRATOR
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT userID
         FROM users
         WHERE userID = ?
           AND barangayID = ?
           AND role = 'admin'
         LIMIT 1"
    );

    $stmt->bind_param("ss", $adminID, $barangayID);
    $stmt->execute();

    $authorized = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$authorized) {
        timelineJsonResponse(403, [
            "success" => false,
            "message" => "Administrator access denied."
        ]);
    }

    $conn->begin_transaction();
    $transactionStarted = true;

    /*
    |--------------------------------------------------------------------------
    | GET SELECTED REPORT
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT categoryID, locationID
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
        timelineFail(
            $conn,
            $transactionStarted,
            404,
            "Report not found in your barangay."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND THE ORIGINAL REPORT'S CYCLE
    |--------------------------------------------------------------------------
    */
    $cycles = timelineLoadCycles(
        $conn,
        $barangayID,
        (string) $reportKey["categoryID"],
        (string) $reportKey["locationID"]
    );

    $cycle = timelineFindOriginalCycle($cycles, $reportID);

    if ($cycle === null) {
        timelineFail(
            $conn,
            $transactionStarted,
            403,
            "Only the Original Report timeline can be edited."
        );
    }

    $original = $cycle["reports"][0];
    $originalStatusID = (string) $original["statusID"];

    /*
    |--------------------------------------------------------------------------
    | GET CURRENT STATUS NAME FROM DATABASE
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT statusName
         FROM stats
         WHERE statusID = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $originalStatusID);
    $stmt->execute();

    $statusRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $currentStatusName = (string) ($statusRow["statusName"] ?? "");

    /*
    |--------------------------------------------------------------------------
    | ONLY IN-PROGRESS OR OVERDUE ORIGINALS CAN BE EDITED
    |--------------------------------------------------------------------------
    */
    if (!in_array($originalStatusID, ["STAT002", "STAT004"], true)) {
        timelineFail(
            $conn,
            $transactionStarted,
            409,
            "A timeline can only be changed for an In Progress or Overdue Original Report."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AN OVERDUE REPORT MUST RECEIVE A FUTURE DEADLINE
    |--------------------------------------------------------------------------
    */
    if (
        $originalStatusID === "STAT004" &&
        $date <= new DateTimeImmutable("now")
    ) {
        timelineFail(
            $conn,
            $transactionStarted,
            400,
            "A revised deadline for an Overdue report must be in the future."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE THE ORIGINAL AND EVERY DUPLICATE'S TIMELINE
    |--------------------------------------------------------------------------
    */
    $timelineStmt = $conn->prepare(
        "UPDATE reports
         SET timeline = ?
         WHERE reportID = ?
           AND barangayID = ?"
    );

    $updatedTimelineReportIDs = [];

    foreach ($cycle["reports"] as $report) {
        if (
            (string) ($report["timeline"] ?? "") === $mysqlTimeline
        ) {
            continue;
        }

        $affectedReportID = (string) $report["reportID"];

        $timelineStmt->bind_param(
            "sss",
            $mysqlTimeline,
            $affectedReportID,
            $barangayID
        );

        $timelineStmt->execute();

        $updatedTimelineReportIDs[] = $affectedReportID;
    }

    $timelineStmt->close();

    /*
    |--------------------------------------------------------------------------
    | OVERDUE -> IN PROGRESS FOR THE ENTIRE CYCLE
    |--------------------------------------------------------------------------
    | Do not assume duplicates have the same status as the Original.
    | Update every report that actually needs a status change.
    |--------------------------------------------------------------------------
    */
    $updatedStatusReportIDs = [];

    if ($originalStatusID === "STAT004") {
        $newStatusID = "STAT002";

        $statusUpdateStmt = $conn->prepare(
            "UPDATE reports
             SET statusID = ?
             WHERE reportID = ?
               AND barangayID = ?"
        );

        foreach ($cycle["reports"] as $report) {
            $oldStatusID = (string) $report["statusID"];

            if ($oldStatusID === $newStatusID) {
                continue;
            }

            /*
             * A closed/resolved report must not be reopened.
             */
            if ($oldStatusID === "STAT003") {
                continue;
            }

            $affectedReportID = (string) $report["reportID"];

            $statusUpdateStmt->bind_param(
                "sss",
                $newStatusID,
                $affectedReportID,
                $barangayID
            );

            $statusUpdateStmt->execute();

            timelineRecordStatusChange(
                $conn,
                $affectedReportID,
                (string) $report["userID"],
                $adminID,
                $newStatusID,
                "In Progress"
            );

            $updatedStatusReportIDs[] = $affectedReportID;
        }

        $statusUpdateStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */
    $conn->commit();
    $transactionStarted = false;

    timelineJsonResponse(200, [
        "success" => true,
        "message" => "The Original Report and its Duplicate Reports have been updated.",
        "reportID" => $reportID,
        "statusID" => $originalStatusID === "STAT004"
            ? "STAT002"
            : $originalStatusID,
        "statusName" => $originalStatusID === "STAT004"
            ? "In Progress"
            : $currentStatusName,
        "timeline" => $date->format("Y-m-d\TH:i"),
        "formattedTimeline" => $date->format("M j, Y g:i A"),
        "updatedTimelineReportIDs" => $updatedTimelineReportIDs,
        "updatedStatusReportIDs" => $updatedStatusReportIDs
    ]);

} catch (Throwable $error) {
    if ($transactionStarted) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    error_log("Report timeline update failed: " . $error->getMessage());

    timelineJsonResponse(500, [
        "success" => false,
        "message" => "Unable to save the expected completion timeline."
    ]);
}

