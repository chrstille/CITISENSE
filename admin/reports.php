<?php
session_start();

if (
    empty($_SESSION["admin_id"]) ||
    empty($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$barangayID = (string) $_SESSION["barangay_id"];
$adminID = (string) $_SESSION["admin_id"];

/* =========================================================
   HELPERS
========================================================= */

function reportsEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function reportsPrepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Reports SQL prepare error: " . $conn->error);
        http_response_code(500);
        exit("Unable to load reports. Please check the server error log.");
    }

    return $stmt;
}

/* =========================================================
   FILTER INPUTS
========================================================= */

/* ==========================================
   APPLY FILTERS ONLY WHEN SUBMITTED
========================================== */

$filtersApplied =
    ($_GET["filters_applied"] ?? "") === "1";

$startDate = $filtersApplied
    ? trim((string) ($_GET["start_date"] ?? ""))
    : "";

$endDate = $filtersApplied
    ? trim((string) ($_GET["end_date"] ?? ""))
    : "";

$selectedCategory = $filtersApplied
    ? trim((string) ($_GET["categoryID"] ?? ""))
    : "";

$selectedStatus = $filtersApplied
    ? trim((string) ($_GET["statusID"] ?? ""))
    : "";

$searchQuery = $filtersApplied
    ? trim((string) ($_GET["search"] ?? ""))
    : "";


/* Validate date format: YYYY-MM-DD */

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if (
    $startDate !== "" &&
    (
        !preg_match($datePattern, $startDate) ||
        !checkdate(
            (int) substr($startDate, 5, 2),
            (int) substr($startDate, 8, 2),
            (int) substr($startDate, 0, 4)
        )
    )
) {
    $startDate = "";
}

if (
    $endDate !== "" &&
    (
        !preg_match($datePattern, $endDate) ||
        !checkdate(
            (int) substr($endDate, 5, 2),
            (int) substr($endDate, 8, 2),
            (int) substr($endDate, 0, 4)
        )
    )
) {
    $endDate = "";
}

/* Swap dates if the start date is later than the end date */

if (
    $startDate !== "" &&
    $endDate !== "" &&
    $startDate > $endDate
) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

/* Limit search input length */

$searchQuery = mb_substr($searchQuery, 0, 100);

/* =========================================================
   BARANGAY INFORMATION
========================================================= */

$stmt = reportsPrepare(
    $conn,
    "SELECT barangayName
     FROM barangay
     WHERE barangayID = ?
     LIMIT 1"
);

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$barangayData = $stmt->get_result()->fetch_assoc();
$barangayName = $barangayData["barangayName"] ?? "Unknown Barangay";

$stmt->close();

/* =========================================================
   CATEGORY OPTIONS
========================================================= */

$categories = [];

$stmt = reportsPrepare(
    $conn,
    "SELECT categoryID, categoryName
        FROM categories
        WHERE categoryName <> 'Blocked Drainage'
        ORDER BY categoryName ASC"
);

$stmt->execute();
$categoryResult = $stmt->get_result();

while ($row = $categoryResult->fetch_assoc()) {
    $categories[] = $row;
}

$stmt->close();

/* =========================================================
   STATUS OPTIONS

   The filter excludes Rejected, including if it exists in
   an older database.
========================================================= */

$statuses = [];

$stmt = reportsPrepare(
    $conn,
    "SELECT statusID, statusName
     FROM stats
     WHERE LOWER(TRIM(statusName)) <> 'rejected'
     ORDER BY statusName ASC"
);

$stmt->execute();
$statusResult = $stmt->get_result();

while ($row = $statusResult->fetch_assoc()) {
    $statuses[] = $row;
}

$stmt->close();

/* Validate selected category and status against database options. */

$validCategoryIDs = array_column($categories, "categoryID");
$validStatusIDs = array_column($statuses, "statusID");

if (
    $selectedCategory !== "" &&
    !in_array($selectedCategory, $validCategoryIDs, true)
) {
    $selectedCategory = "";
}

if (
    $selectedStatus !== "" &&
    $selectedStatus !== "all" &&
    !in_array($selectedStatus, $validStatusIDs, true)
) {
    $selectedStatus = "";
}

/* =========================================================
   BUILD FILTER CONDITIONS

   All filters are applied to individual report submissions
   before counts and latest report dates are calculated.
========================================================= */

// Base condition: only show reports belonging to the
// logged-in administrator's barangay.
$conditions = ["r.barangayID = ?"];
$types = "s";
$params = [$barangayID];

// Start date
if ($startDate !== "") {
    $conditions[] = "r.dateReported >= ?";
    $types .= "s";
    $params[] = $startDate . " 00:00:00";
}

// End date: include the entire selected day.
if ($endDate !== "") {
    $conditions[] = "r.dateReported < DATE_ADD(?, INTERVAL 1 DAY)";
    $types .= "s";
    $params[] = $endDate;
}

// Category
if ($selectedCategory !== "") {
    $conditions[] = "r.categoryID = ?";
    $types .= "s";
    $params[] = $selectedCategory;
}

// Status
if (
    $selectedStatus !== "" &&
    $selectedStatus !== "all"
) {
    $conditions[] = "r.statusID = ?";
    $types .= "s";
    $params[] = $selectedStatus;
}

// Search across category, location, title, description,
// and report ID.
if ($searchQuery !== "") {
    $conditions[] = "(
        EXISTS (
            SELECT 1
            FROM categories sc
            WHERE sc.categoryID = r.categoryID
              AND sc.categoryName LIKE ?
        )
        OR EXISTS (
            SELECT 1
            FROM location sl
            WHERE sl.locationID = r.locationID
              AND sl.address LIKE ?
        )
        OR COALESCE(r.title, '') LIKE ?
        OR COALESCE(r.description, '') LIKE ?
        OR CAST(r.reportID AS CHAR) LIKE ?
    )";

    $searchLike = "%" . $searchQuery . "%";

    $types .= "sssss";

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}

// Final WHERE clause for queries using these conditions.
$whereSQL = implode(" AND ", $conditions);


/* =========================================
   CATEGORY ROW DATA

   Keep all three category rows visible.
   Ignore only the category dropdown when
   retrieving each category's report summary.
========================================= */

$groupConditions = $conditions;
$groupTypes = $types;
$groupParams = $params;

if ($selectedCategory !== "") {

    $conditionIndex = array_search(
        "r.categoryID = ?",
        $groupConditions,
        true
    );

    if ($conditionIndex !== false) {

        array_splice(
            $groupConditions,
            $conditionIndex,
            1
        );

        $parameterIndex = 1
            + ($startDate !== "" ? 1 : 0)
            + ($endDate !== "" ? 1 : 0);

        array_splice(
            $groupParams,
            $parameterIndex,
            1
        );

        $groupTypes = substr_replace(
            $groupTypes,
            "",
            $parameterIndex,
            1
        );
    }
}

$groupWhereSQL = implode(
    " AND ",
    $groupConditions
);


/* =========================================================
   REPORT GROUPS - ONE ROW PER CATEGORY

   - One row for each displayed category
   - Count all matching submitted reports
   - Show the latest report's location and date
   - No duplicate rows for different locations
========================================================= */

// Get report counts per category
$sql = "
    SELECT
        c.categoryID,
        c.categoryName,
        COALESCE(g.totalReports, 0) AS totalReports

    FROM categories c

    LEFT JOIN (
        SELECT
            r.categoryID,
            COUNT(r.reportID) AS totalReports
        FROM reports r
        WHERE $groupWhereSQL
        GROUP BY r.categoryID
    ) g
        ON g.categoryID = c.categoryID

    WHERE c.categoryName IN (
        'Waste Accumulation',
        'Potholes',
        'Others'
    )

    ORDER BY FIELD(
        c.categoryName,
        'Waste Accumulation',
        'Potholes',
        'Others'
    )
";

$stmt = reportsPrepare($conn, $sql);
$stmt->bind_param($groupTypes, ...$groupParams);
$stmt->execute();

$reportGroups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


$groupTotalReports = array_sum(
    array_map(
        static fn($group) =>
            (int) $group["totalReports"],
        $reportGroups
    )
);



// Prepare lookup for each category
$categoryIndexes = [];

foreach ($reportGroups as $index => &$group) {

    $group["locationID"] = "";
    $group["address"] = null;
    $group["latestReport"] = null;

    $categoryIndexes[(string) $group["categoryID"]] = $index;
}
unset($group);


// Get the latest matching submission for each category
$sql = "
    SELECT
        r.categoryID,
        r.locationID,
        l.address,
        r.dateReported

    FROM reports r

    LEFT JOIN location l
        ON l.locationID = r.locationID

    WHERE $groupWhereSQL

    ORDER BY
        r.dateReported DESC,
        r.reportID DESC
";

$stmt = reportsPrepare($conn, $sql);
$stmt->bind_param($groupTypes, ...$groupParams);
$stmt->execute();

$latestResults = $stmt->get_result();

$foundLatest = [];

while ($row = $latestResults->fetch_assoc()) {

    $categoryID = (string) $row["categoryID"];

    if (
        !isset($categoryIndexes[$categoryID]) ||
        isset($foundLatest[$categoryID])
    ) {
        continue;
    }

    $index = $categoryIndexes[$categoryID];

    $reportGroups[$index]["locationID"] =
        $row["locationID"] ?? "";

    $reportGroups[$index]["address"] =
        $row["address"] ?? null;

    $reportGroups[$index]["latestReport"] =
        $row["dateReported"];

    $foundLatest[$categoryID] = true;
}

$stmt->close();

/* =========================================================
   TOTAL MATCHING REPORT SUBMISSIONS

   This total respects the selected filters.
========================================================= */

$sql = "
    SELECT COUNT(*) AS totalReports
    FROM reports r
    WHERE $whereSQL
";

$stmt = reportsPrepare($conn, $sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$totalData = $stmt->get_result()->fetch_assoc();
$totalReports = (int) ($totalData["totalReports"] ?? 0);

$stmt->close();

/* =========================================================
   STATUS COUNTS

   Use the same date/category filters, but do not apply the
   selected status filter. This keeps the overview useful
   when one status is selected.
========================================================= */

$statusConditions = ["r.barangayID = ?"];
$statusTypes = "s";
$statusParams = [$barangayID];

if ($startDate !== "") {
    $statusConditions[] = "r.dateReported >= ?";
    $statusTypes .= "s";
    $statusParams[] = $startDate . " 00:00:00";
}

if ($endDate !== "") {
    $statusConditions[] = "r.dateReported < DATE_ADD(?, INTERVAL 1 DAY)";
    $statusTypes .= "s";
    $statusParams[] = $endDate;
}

if ($selectedCategory !== "") {
    $statusConditions[] = "r.categoryID = ?";
    $statusTypes .= "s";
    $statusParams[] = $selectedCategory;
}

$statusWhereSQL = implode(" AND ", $statusConditions);

$sql = "
    SELECT
        s.statusID,
        s.statusName,
        COUNT(r.reportID) AS reportCount
    FROM stats s
    LEFT JOIN reports r
        ON r.statusID = s.statusID
        AND $statusWhereSQL
    WHERE LOWER(TRIM(s.statusName)) <> 'rejected'
    GROUP BY
        s.statusID,
        s.statusName
";

$stmt = reportsPrepare($conn, $sql);
$stmt->bind_param($statusTypes, ...$statusParams);
$stmt->execute();

$statusResult = $stmt->get_result();

$statusCounts = [
    "Pending" => 0,
    "In Progress" => 0,
    "Overdue" => 0,
    "Resolved" => 0
];

while ($row = $statusResult->fetch_assoc()) {
    $name = trim((string) $row["statusName"]);

    if (array_key_exists($name, $statusCounts)) {
        $statusCounts[$name] = (int) $row["reportCount"];
    }
}

$stmt->close();

/* =========================================================
   STATUS PERCENTAGES
========================================================= */

$overviewTotal = array_sum($statusCounts);

$pendingPercent = 0;
$inProgressPercent = 0;
$overduePercent = 0;
$resolvedPercent = 0;

if ($overviewTotal > 0) {
    $pendingPercent = round(
        $statusCounts["Pending"] / $overviewTotal * 100
    );

    $inProgressPercent = round(
        $statusCounts["In Progress"] / $overviewTotal * 100
    );

    $overduePercent = round(
        $statusCounts["Overdue"] / $overviewTotal * 100
    );

    $resolvedPercent = round(
        $statusCounts["Resolved"] / $overviewTotal * 100
    );
}

/* =========================================================
   RECENT REPORT

   Respects the logged-in admin's barangay and selected
   date/category/status filters.
========================================================= */

$sql = "
    SELECT
        r.reportID,
        r.title,
        r.description,
        r.dateReported,
        c.categoryName,
        l.address,
        u.firstName,
        u.lastName
    FROM reports r
    INNER JOIN categories c
        ON c.categoryID = r.categoryID
    LEFT JOIN location l
        ON l.locationID = r.locationID
    LEFT JOIN users u
        ON u.userID = r.userID
    WHERE $whereSQL
    ORDER BY r.dateReported DESC, r.reportID DESC
    LIMIT 1
";

$stmt = reportsPrepare($conn, $sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$recentReport = $stmt->get_result()->fetch_assoc();

$stmt->close();

/* =========================================================
   UNREAD ADMIN NOTIFICATIONS
========================================================= */

$stmt = reportsPrepare(
    $conn,
    "SELECT COUNT(*) AS unreadNotifications
     FROM notification
     WHERE userID = ?
       AND isRead = FALSE"
);

$stmt->bind_param("s", $adminID);
$stmt->execute();

$notificationData = $stmt->get_result()->fetch_assoc();

$unreadNotifications = (int) (
    $notificationData["unreadNotifications"] ?? 0
);

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reports · CitiSense</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/reports.css?v=2">
    <link rel="stylesheet" href="../css/reportView.css?v=4">
<?php include __DIR__ . "/partial/head.php"; ?>
</head>

<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<?php
function reportsCategoryIcon(string $name): string
{
    $name = strtolower($name);
    if (str_contains($name, "waste") || str_contains($name, "garbage")) return "bi-trash3";
    if (str_contains($name, "pothole") || str_contains($name, "road")) return "bi-cone-striped";
    if (str_contains($name, "drain") || str_contains($name, "flood")) return "bi-water";
    return "bi-three-dots";
}

$activeFilterCount = count(array_filter([
    $startDate,
    $endDate,
    $selectedCategory,
    $selectedStatus
], static fn($value) => $value !== ""));
?>

<main class="main-content">

    <!-- HEADER -->
    <header class="page-header">
        <div>
            <h1>Reports</h1>
            <p>
                View and manage reports submitted by the community in
                <strong><?= reportsEscape($barangayName) ?></strong>.
            </p>
        </div>

        <div class="page-header-actions">
            <?php include __DIR__ . "/partial/notification_bell.php"; ?>

            <button type="button" class="btn btn-primary" id="generateReportButton">
                <i class="bi bi-file-earmark-arrow-down"></i>
                Generate Report
            </button>
        </div>
    </header>

    <!-- STATUS SUMMARY -->
    <section class="rp-summary" aria-label="Report status summary">
        <?php
        $summaryItems = [
            ["Pending", $statusCounts["Pending"], "pending", "bi-hourglass-split"],
            ["In Progress", $statusCounts["In Progress"], "progress", "bi-arrow-repeat"],
            ["Overdue", $statusCounts["Overdue"], "overdue", "bi-exclamation-triangle"],
            ["Resolved", $statusCounts["Resolved"], "resolved", "bi-check2-circle"],
        ];
        ?>

        <?php foreach ($summaryItems as [$label, $count, $tone, $icon]): ?>
            <div class="rp-summary-item rp-<?= $tone ?>">
                <span class="rp-summary-icon"><i class="bi <?= $icon ?>"></i></span>
                <div>
                    <strong><?= (int) $count ?></strong>
                    <small><?= $label ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- REPORTS CARD -->
    <section class="rp-card">

        <div class="rp-card-header">
            <h2>
                Reports by issue
                <span><?= $groupTotalReports ?> total</span>
            </h2>

            <p class="rp-help">
                <i class="bi bi-info-circle"></i>
                Click a row for its full overview, or
                <i class="bi bi-eye"></i> to review pending reports.
            </p>
        </div>

        <div class="reports-table-wrapper">
            <table class="reports-table rp-table">

                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>Latest location</th>
                        <th>Reports</th>
                        <th>Latest report</th>
                        <th class="rp-actions-col">Pending</th>
                    </tr>
                </thead>

                <tbody id="reportsTableBody">

                <?php if (!empty($reportGroups)): ?>

                    <?php foreach ($reportGroups as $report): ?>

                        <?php
                        $categoryName = (string) $report["categoryName"];

                        $categoryBoardURL =
                            "reportStatusOverview.php?" .
                            http_build_query(["categoryID" => (string) $report["categoryID"]]);

                        $address = (string) ($report["address"] ?? "");
                        $locationID = (string) ($report["locationID"] ?? "");
                        $latestDate = $report["latestReport"] ?? null;
                        $latestTimestamp = $latestDate ? strtotime($latestDate) : false;
                        $groupCount = (int) $report["totalReports"];
                        $searchText = strtolower($categoryName . " " . $address);
                        ?>

                        <tr
                            class="report-row clickable-report-row"
                            data-search="<?= reportsEscape($searchText) ?>"
                            data-category-id="<?= reportsEscape($report["categoryID"]) ?>"
                            data-location-id="<?= reportsEscape($locationID) ?>"
                            data-overview-url="<?= reportsEscape($categoryBoardURL) ?>"
                            tabindex="0"
                            aria-label="View <?= reportsEscape($categoryName) ?> report overview"
                        >

                            <td>
                                <div class="issue-cell">
                                    <span class="issue-icon">
                                        <i class="bi <?= reportsCategoryIcon($categoryName) ?>"></i>
                                    </span>
                                    <div>
                                        <strong><?= reportsEscape($categoryName) ?></strong>
                                        <small>Open overview <i class="bi bi-arrow-right"></i></small>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="location-cell">
                                    <i class="bi bi-geo-alt"></i>
                                    <span><?= reportsEscape($address !== "" ? $address : "No reports yet") ?></span>
                                </div>
                            </td>

                            <td>
                                <span class="report-count">
                                    <strong><?= $groupCount ?></strong>
                                    <?= $groupCount === 1 ? "report" : "reports" ?>
                                </span>
                            </td>

                            <td>
                                <div class="latest-report">
                                    <strong><?= $latestTimestamp ? date("M j, Y", $latestTimestamp) : "—" ?></strong>
                                    <span><?= $latestTimestamp ? date("g:i A", $latestTimestamp) : "" ?></span>
                                </div>
                            </td>

                            <td class="rp-actions-col">
                                <button
                                    type="button"
                                    class="view-button view-report-btn"
                                    title="Review pending <?= reportsEscape($categoryName) ?> reports"
                                    aria-label="Review pending <?= reportsEscape($categoryName) ?> reports"
                                    data-category-id="<?= reportsEscape($report["categoryID"]) ?>"
                                    data-location-id="<?= reportsEscape($locationID) ?>"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="empty-table">
                            <i class="bi bi-inbox"></i>
                            <strong>No reports found</strong>
                            <span>Try changing or clearing your filters.</span>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

</main>

<!-- EXPORT MODAL -->
<div id="exportModal" class="export-modal-overlay" hidden>
    <div class="export-modal" role="dialog" aria-modal="true" aria-labelledby="exportModalTitle">

        <div class="export-modal-header">
            <span class="export-modal-icon"><i class="bi bi-download"></i></span>
            <div>
                <h2 id="exportModalTitle">Choose a format</h2>
                <p id="exportPeriodLabel">Download your generated report.</p>
            </div>
            <button type="button" id="closeExportModal" class="modal-close" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="export-modal-options">
            <a href="export_reports.php?format=pdf" class="export-option">
                <span class="export-option-icon pdf"><i class="bi bi-file-earmark-pdf"></i></span>
                <span><strong>PDF</strong><small>Best for printing and sharing</small></span>
                <i class="bi bi-chevron-right"></i>
            </a>

            <a href="export_reports.php?format=csv" class="export-option">
                <span class="export-option-icon csv"><i class="bi bi-filetype-csv"></i></span>
                <span><strong>CSV</strong><small>Open in Excel or Google Sheets</small></span>
                <i class="bi bi-chevron-right"></i>
            </a>

            <a href="export_reports.php?format=docx" class="export-option">
                <span class="export-option-icon docx"><i class="bi bi-file-earmark-word"></i></span>
                <span><strong>Word</strong><small>Editable document</small></span>
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>

        <button type="button" class="btn btn-light export-back" id="exportBackButton">
            <i class="bi bi-arrow-left"></i> Change period
        </button>

    </div>
</div>

<!-- GENERATE REPORT MODAL -->
<div id="generateReportModal" class="export-modal-overlay" hidden>
    <div class="export-modal" role="dialog" aria-modal="true" aria-labelledby="generateModalTitle">

        <div class="export-modal-header">
            <span class="export-modal-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
            <div>
                <h2 id="generateModalTitle">Generate Report</h2>
                <p>Pick the reporting period you want to export.</p>
            </div>
            <button type="button" id="closeGenerateReportModal" class="modal-close" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form id="generateReportForm">

            <fieldset class="period-options">
                <legend class="sr-only">Reporting period</legend>

                <?php
                $periods = [
                    "day"     => ["Today", "Reports submitted within the day", "bi-sun"],
                    "3months" => ["Last 3 months", "Quarterly summary", "bi-calendar3"],
                    "6months" => ["Last 6 months", "Half-year summary", "bi-calendar2-range"],
                    "year"    => ["Last 12 months", "Yearly summary", "bi-calendar-check"],
                ];
                ?>

                <?php foreach ($periods as $value => [$label, $hint, $icon]): ?>
                    <label class="period-option">
                        <input type="radio" name="period" value="<?= $value ?>" <?= $value === "day" ? "checked" : "" ?>>
                        <span class="period-card">
                            <i class="bi <?= $icon ?>"></i>
                            <strong><?= $label ?></strong>
                            <small><?= $hint ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <button type="submit" class="btn btn-primary export-continue">
                Continue
                <i class="bi bi-arrow-right"></i>
            </button>
        </form>
    </div>
</div>

<!-- DECLINE REPORT MODAL -->
<div id="declineReportModal" class="export-modal-overlay decline-modal-overlay" hidden>
    <div class="export-modal" role="dialog" aria-modal="true" aria-labelledby="declineModalTitle">

        <div class="export-modal-header">
            <span class="export-modal-icon decline-modal-icon"><i class="bi bi-x-octagon"></i></span>
            <div>
                <h2 id="declineModalTitle">Decline report</h2>
                <p id="declineModalSubtitle">Tell the resident why this report was not accepted.</p>
            </div>
            <button type="button" id="closeDeclineModal" class="modal-close" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form id="declineReportForm" novalidate>
            <div class="rv-field">
                <label for="declineReason">Reason <span>(sent to the resident)</span></label>
                <textarea
                    id="declineReason"
                    rows="4"
                    minlength="5"
                    maxlength="1000"
                    placeholder="e.g. The photo does not show the reported issue. Please submit a clearer photo of the location."
                    required
                ></textarea>
                <small class="decline-error" id="declineError" hidden>Please write a reason (at least 5 characters).</small>
            </div>

            <div class="decline-modal-actions">
                <button type="button" class="btn btn-light" id="cancelDeclineModal">Cancel</button>
                <button type="submit" class="btn decline-submit" id="confirmDecline">
                    <i class="bi bi-send"></i>
                    Decline
                </button>
            </div>
        </form>
    </div>
</div>

<!-- REPORT DRAWER -->
<div class="report-drawer-overlay" id="reportDrawerOverlay"></div>

<aside class="report-drawer" id="reportDrawer" aria-label="Report details" aria-hidden="true">
    <div id="reportDrawerContent" class="report-drawer-loading"></div>
</aside>

<!-- TOAST -->
<div id="reportToast" class="report-toast" role="status" aria-live="polite" hidden>
    <i class="bi bi-check-circle-fill" id="reportToastIcon"></i>
    <span id="reportToastText"></span>
</div>

<script>
"use strict";

/* =========================================================
   CLICKABLE CATEGORY ROWS -> REPORT OVERVIEW
========================================================= */

document.querySelectorAll(".clickable-report-row").forEach(row => {

    function openCategoryOverview() {
        const url = row.dataset.overviewUrl;
        if (url) window.location.href = url;
    }

    row.addEventListener("click", function (event) {
        if (event.target.closest(".view-report-btn, a, button, input, select")) return;
        openCategoryOverview();
    });

    row.addEventListener("keydown", function (event) {
        if (event.target === row && (event.key === "Enter" || event.key === " ")) {
            event.preventDefault();
            openCategoryOverview();
        }
    });
});

/* =========================================================
   HELPERS
========================================================= */

function reportsSafeMessage(value) {
    const div = document.createElement("div");
    div.textContent = String(value || "");
    return div.innerHTML;
}

function statusClass(name) {
    return String(name || "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-|-$/g, "");
}

function formatReportTimeline(value) {
    if (!value) return "";

    const date = new Date(value.replace(" ", "T"));

    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true
    });
}

/* =========================================================
   DRAWER
========================================================= */

const reportDrawer = document.getElementById("reportDrawer");
const reportDrawerOverlay = document.getElementById("reportDrawerOverlay");
const reportDrawerContent = document.getElementById("reportDrawerContent");

let currentCategoryID = "";
let currentLocationID = "";
let activeManageCategoryID = "";
let activeOriginalID = "";
let detailReturnScreen = null;
let lastDrawerTrigger = null;

function drawerSkeleton(message) {
    return `
        <div class="rv-loading">
            <div class="rv-skel rv-skel-head"></div>
            <div class="rv-skel rv-skel-bar"></div>
            <div class="rv-skel rv-skel-card"></div>
            <div class="rv-skel rv-skel-card"></div>
            <div class="rv-skel rv-skel-card"></div>
            <p>${reportsSafeMessage(message)}</p>
        </div>
    `;
}

function openReportDrawer() {
    reportDrawer.classList.add("open");
    reportDrawerOverlay.classList.add("open");
    reportDrawer.setAttribute("aria-hidden", "false");
    document.body.classList.add("drawer-open");
}

function closeReportDrawer() {
    reportDrawer.classList.remove("open");
    reportDrawerOverlay.classList.remove("open");
    reportDrawer.setAttribute("aria-hidden", "true");
    document.body.classList.remove("drawer-open");

    /* Accepted/declined reports change the counts on this page */
    if (reportsChanged) {
        window.location.reload();
        return;
    }

    if (lastDrawerTrigger && lastDrawerTrigger.isConnected) {
        lastDrawerTrigger.focus({ preventScroll: true });
    }
}

reportDrawerOverlay.addEventListener("click", closeReportDrawer);

/* =========================================================
   TOAST
========================================================= */

let reportToastTimer = null;

function showReportToast(message, isError = false) {
    const toast = document.getElementById("reportToast");
    const text = document.getElementById("reportToastText");
    const icon = document.getElementById("reportToastIcon");

    if (!toast || !text || !icon) {
        console.error(message);
        return;
    }

    if (reportToastTimer !== null) clearTimeout(reportToastTimer);

    text.textContent = message;
    icon.className = isError ? "bi bi-exclamation-circle-fill" : "bi bi-check-circle-fill";

    toast.classList.toggle("error", isError);
    toast.classList.toggle("success", !isError);
    toast.hidden = false;

    reportToastTimer = setTimeout(hideReportToast, isError ? 5000 : 3200);
}

function hideReportToast() {
    const toast = document.getElementById("reportToast");

    if (reportToastTimer !== null) {
        clearTimeout(reportToastTimer);
        reportToastTimer = null;
    }

    if (toast) toast.hidden = true;
}

/* =========================================================
   LOAD REPORT DRAWER CONTENT (reportView.php)
========================================================= */

async function loadReportDrawer(categoryID, locationID, keepContent = false) {
    currentCategoryID = categoryID;
    currentLocationID = locationID;

    if (!keepContent) {
        reportDrawerContent.innerHTML = drawerSkeleton("Loading reports…");
    } else {
        reportDrawerContent.classList.add("is-refreshing");
    }

    try {
        const endpoint = new URL("reportView.php", window.location.href);

        endpoint.search = new URLSearchParams({
            categoryID: categoryID || "",
            locationID: locationID || ""
        }).toString();

        const response = await fetch(endpoint.href, {
            method: "GET",
            cache: "no-store",
            headers: { "Accept": "text/html" }
        });

        const html = await response.text();

        if (!response.ok) {
            throw new Error(`Unable to load report details (HTTP ${response.status}).`);
        }

        if (!html.trim()) {
            throw new Error("The report details page returned an empty response.");
        }

        reportDrawerContent.innerHTML = html;
        return true;

    } catch (error) {
        console.error("Report drawer error:", error);

        reportDrawerContent.innerHTML = `
            <div class="rv-empty rv-error">
                <span class="rv-empty-icon"><i class="bi bi-exclamation-triangle"></i></span>
                <h3>Couldn't load reports</h3>
                <p>${reportsSafeMessage(error.message)}</p>
                <button type="button" class="btn btn-light" data-close-report-view>Close</button>
            </div>
        `;

        showReportToast(error.message || "Unable to load report details.", true);
        return false;

    } finally {
        reportDrawerContent.classList.remove("is-refreshing");
    }
}

/* =========================================================
   OPEN A CATEGORY'S PENDING REPORTS (eye button)
========================================================= */

async function openCategoryDrawer(categoryID, reportID = "") {
    activeManageCategoryID = "";
    activeOriginalID = "";
    detailReturnScreen = null;

    openReportDrawer();

    const loaded = await loadReportDrawer(categoryID, "");

    if (!loaded) return;

    showGroupScreen(false);

    if (reportID) {
        const root = getReportRoot();
        const card = root && Array.from(
            root.querySelectorAll(".duplicate-report-card[data-report-id]")
        ).find(item => item.dataset.reportId === reportID && item.dataset.variant !== "summary");

        if (card) {
            openReadOnlyReport(card);
        }
    }
}

document.querySelectorAll(".view-report-btn").forEach(button => {
    button.addEventListener("click", function () {
        lastDrawerTrigger = this;
        openCategoryDrawer(this.dataset.categoryId);
    });
});

/* =========================================================
   DRAWER SCREEN NAVIGATION
========================================================= */

function getReportRoot() {
    return reportDrawerContent.querySelector(".report-view-content");
}

function hideAllReportScreens(root) {
    if (!root) return;

    root.querySelectorAll(
        "#reportGroupScreen, #manageCategoriesScreen, .manage-category-screen, " +
        ".manage-original-screen, #individualReportScreen"
    ).forEach(screen => {
        screen.hidden = true;
    });
}

function showReportScreen(screen, direction = "forward") {
    const root = getReportRoot();

    if (!root || !screen) return;

    hideAllReportScreens(root);

    screen.classList.remove("enter-forward", "enter-back");
    screen.hidden = false;
    void screen.offsetWidth;                       // restart the animation
    screen.classList.add(direction === "back" ? "enter-back" : "enter-forward");

    reportDrawer.scrollTop = 0;
}

function showGroupScreen(animate = true) {
    const root = getReportRoot();
    if (!root) return;

    const screen = root.querySelector("#reportGroupScreen");

    if (screen) {
        activeManageCategoryID = "";
        activeOriginalID = "";
        detailReturnScreen = null;
        showReportScreen(screen, animate ? "back" : "forward");
    }
}

function showManageCategories() {
    const root = getReportRoot();
    if (!root) return;

    activeOriginalID = "";
    showReportScreen(root.querySelector("#manageCategoriesScreen"), "back");
}

function showManageCategory(categoryID, direction = "forward") {
    const root = getReportRoot();
    if (!root) return;

    const screen = Array.from(root.querySelectorAll(".manage-category-screen"))
        .find(item => item.dataset.categoryScreen === categoryID);

    if (!screen) return;

    activeManageCategoryID = categoryID;
    activeOriginalID = "";

    showReportScreen(screen, direction);
}

function showManageOriginal(reportID) {
    const root = getReportRoot();
    if (!root) return;

    const screen = Array.from(root.querySelectorAll(".manage-original-screen"))
        .find(item => item.dataset.originalScreen === reportID);

    if (!screen) return;

    activeOriginalID = reportID;
    activeManageCategoryID = screen.dataset.parentCategory || "";

    showReportScreen(screen, "forward");
}

/* =========================================================
   READ-ONLY REPORT DETAILS
========================================================= */

function setDetailImages(root, images) {
    const link = root.querySelector("#detailImageLink");
    const image = root.querySelector("#detailImage");
    const empty = root.querySelector("#detailImageEmpty");
    const thumbs = root.querySelector("#detailImageThumbs");

    if (!link || !image || !empty || !thumbs) return;

    thumbs.replaceChildren();

    if (!images.length) {
        link.hidden = true;
        empty.hidden = false;
        image.removeAttribute("src");
        return;
    }

    function select(index) {
        image.src = images[index];
        link.href = images[index];

        thumbs.querySelectorAll("button").forEach((button, i) => {
            button.classList.toggle("active", i === index);
        });
    }

    link.hidden = false;
    empty.hidden = true;

    image.onerror = function () {
        link.hidden = true;
        empty.hidden = false;
        empty.querySelector("span").textContent = "The photo could not be loaded";
    };

    if (images.length > 1) {
        images.forEach((src, index) => {
            const button = document.createElement("button");
            button.type = "button";
            button.setAttribute("aria-label", "Show photo " + (index + 1));

            const thumb = document.createElement("img");
            thumb.src = src;
            thumb.alt = "";
            thumb.loading = "lazy";

            button.appendChild(thumb);
            button.addEventListener("click", () => select(index));
            thumbs.appendChild(button);
        });
    }

    select(0);
}

function openReadOnlyReport(card) {
    const root = getReportRoot();
    if (!root) return;

    const detailScreen = root.querySelector("#individualReportScreen");
    if (!detailScreen) return;

    detailReturnScreen = Array.from(
        root.querySelectorAll("#reportGroupScreen, .manage-category-screen")
    ).find(screen => !screen.hidden) || null;

    const data = card.dataset;

    const fields = {
        detailCategory: data.category,
        detailResident: data.resident,
        detailEmail: data.email,
        detailContact: data.contact,
        detailReportID: data.reportId,
        detailLocation: data.location,
        detailDescription: data.description,
        detailDate: formatReportTimeline(data.date),
        detailTitle: (data.title || "").trim() || (data.category + " report")
    };

    Object.entries(fields).forEach(([id, value]) => {
        const element = root.querySelector("#" + id);
        if (element) element.textContent = value || "Not provided";
    });

    const initial = root.querySelector("#detailResidentInitial");
    if (initial) initial.textContent = (data.resident || "R").trim().charAt(0).toUpperCase();

    /* Status pill (reuse the card's pill so Overdue labels match) */
    const status = root.querySelector("#detailStatus");
    if (status) {
        const cardPill = card.querySelector(".pill");
        status.replaceChildren(
            cardPill
                ? cardPill.cloneNode(true)
                : Object.assign(document.createElement("span"), {
                    className: "pill pill-" + statusClass(data.statusName),
                    textContent: data.statusName || ""
                })
        );
    }

    /* Timeline */
    const timelineContainer = root.querySelector("#detailTimelineContainer");
    const timelineText = root.querySelector("#detailTimeline");

    if (timelineContainer && timelineText) {
        timelineText.textContent = formatReportTimeline(data.timeline || "");
        timelineContainer.hidden = !data.timeline;
    }

    /* Photos */
    let images = [];
    try {
        images = JSON.parse(data.images || "[]");
    } catch {
        images = data.image ? [data.image] : [];
    }
    setDetailImages(root, Array.isArray(images) ? images.filter(Boolean) : []);

    /* Status history (hidden for Pending) */
    const isPending = data.statusId === "STAT001";
    const historyPanel = root.querySelector("#detailStatusHistoryPanel");
    const historyTarget = root.querySelector("#detailStatusHistory");

    if (historyPanel) historyPanel.hidden = isPending;

    if (historyTarget) {
        historyTarget.replaceChildren();

        if (!isPending) {
            const template = Array.from(root.querySelectorAll("[data-history-report-id]"))
                .find(node => node.dataset.historyReportId === data.reportId);

            if (template && template.children.length) {
                historyTarget.append(
                    ...Array.from(template.childNodes).map(node => node.cloneNode(true))
                );
            } else {
                historyTarget.textContent = "No status history recorded for this report.";
            }
        }
    }

    setupDetailReview(root, data);

    showReportScreen(detailScreen, "forward");
}

/* =========================================================
   ACCEPT / DECLINE PENDING REPORTS
   Accept  -> In Progress (its Original and Pending duplicates follow)
   Decline -> Declined, with a reason that is sent to the resident
========================================================= */

const declineReportModal = document.getElementById("declineReportModal");
const declineReportForm = document.getElementById("declineReportForm");
const declineReason = document.getElementById("declineReason");
const declineError = document.getElementById("declineError");
const declineSubtitle = document.getElementById("declineModalSubtitle");
const confirmDeclineButton = document.getElementById("confirmDecline");

let declineReportID = "";
let reportsChanged = false;

function setupDetailReview(root, data) {
    const panel = root.querySelector("#detailReviewPanel");
    if (!panel) return;

    const isPending = data.statusId === "STAT001";
    panel.hidden = !isPending;

    panel.querySelectorAll("[data-review-action]").forEach(button => {
        button.dataset.reviewReport = data.reportId;
        setButtonBusy(button, false);
    });
}

async function sendReportReview(reportID, action, reason = "") {
    const response = await fetch("review_report.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reportID: reportID, action: action, reason: reason })
    });

    let data;

    try {
        data = await response.json();
    } catch {
        throw new Error("The server returned an invalid response.");
    }

    if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to review the report.");
    }

    return data;
}

async function afterReview(message) {
    reportsChanged = true;

    const loaded = await loadReportDrawer(currentCategoryID, currentLocationID, true);

    if (!loaded) {
        showReportToast(message + " Reopen the list to see the latest data.", true);
        return;
    }

    showGroupScreen();
    showReportToast(message);
}

async function acceptReport(button) {
    const reportID = button.dataset.reviewReport;
    if (!reportID) return;

    setButtonBusy(button, true, "Accepting…");

    try {
        const data = await sendReportReview(reportID, "accept");
        await afterReview(data.message || "Report accepted.");
    } catch (error) {
        showReportToast(error.message || "Unable to accept the report.", true);
        setButtonBusy(button, false);
    }
}

function openDeclineModal(reportID) {
    if (!declineReportModal || !reportID) return;

    declineReportID = reportID;
    declineReason.value = "";
    declineError.hidden = true;
    declineSubtitle.textContent = "Report #" + reportID + ". Tell the resident why it was not accepted.";
    setButtonBusy(confirmDeclineButton, false);

    openModal(declineReportModal);
    setTimeout(() => declineReason.focus(), 60);
}

document.getElementById("closeDeclineModal")?.addEventListener("click", () => closeModal(declineReportModal));
document.getElementById("cancelDeclineModal")?.addEventListener("click", () => closeModal(declineReportModal));

declineReportModal?.addEventListener("click", function (event) {
    if (event.target === declineReportModal) closeModal(declineReportModal);
});

declineReason?.addEventListener("input", function () {
    if (declineReason.value.trim().length >= 5) declineError.hidden = true;
});

declineReportForm?.addEventListener("submit", async function (event) {
    event.preventDefault();

    const reason = declineReason.value.trim();

    if (reason.length < 5) {
        declineError.hidden = false;
        declineReason.focus();
        return;
    }

    setButtonBusy(confirmDeclineButton, true, "Declining…");

    try {
        const data = await sendReportReview(declineReportID, "decline", reason);
        closeModal(declineReportModal);
        await afterReview(data.message || "Report declined.");
    } catch (error) {
        showReportToast(error.message || "Unable to decline the report.", true);
        setButtonBusy(confirmDeclineButton, false);
    }
});

document.addEventListener("click", function (event) {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const button = target.closest("[data-review-action]");
    if (!button || button.disabled) return;

    event.preventDefault();

    if (button.dataset.reviewAction === "accept") {
        acceptReport(button);
    } else {
        openDeclineModal(button.dataset.reviewReport);
    }
});

/* =========================================================
   DRAWER CLICK HANDLERS
========================================================= */

function handleCardActivation(card) {
    const variant = card.dataset.variant;

    if (variant === "summary") return;

    if (variant === "original") {
        showManageOriginal(card.dataset.reportId);
    } else {
        openReadOnlyReport(card);
    }
}

document.addEventListener("click", function (event) {
    const target = event.target;

    if (!(target instanceof Element)) return;

    /* Close buttons work in every state, including error states */
    if (target.closest("[data-close-report-view], #closeReportView")) {
        closeReportDrawer();
        return;
    }

    const root = target.closest(".report-view-content");
    if (!root) return;

    if (target.closest("#manageGroupReports")) {
        const categoryID = root.dataset.categoryId;
        if (categoryID) showManageCategory(categoryID);
        return;
    }

    const categoryButton = target.closest("[data-manage-category]");
    if (categoryButton) {
        showManageCategory(categoryButton.dataset.manageCategory);
        return;
    }

    const backButton = target.closest("[data-manage-back]");
    if (backButton) {
        const destination = backButton.dataset.manageBack;

        if (destination === "group") {
            showGroupScreen();
        } else if (destination === "categories") {
            showManageCategories();
        } else if (destination === "category") {
            showManageCategory(backButton.dataset.categoryId, "back");
        }
        return;
    }

    if (target.closest("#backToReportList")) {
        if (detailReturnScreen) {
            showReportScreen(detailReturnScreen, "back");
        } else {
            showGroupScreen();
        }
        return;
    }

    /* Don't treat clicks on links/inputs/buttons inside a card as card clicks */
    if (target.closest("a, input, select, textarea, summary, [data-review-action]")) return;

    const card = target.closest(".duplicate-report-card");
    if (card) handleCardActivation(card);
});

/* =========================================================
   KEYBOARD SUPPORT
========================================================= */

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        if (declineReportModal && !declineReportModal.hidden) {
            closeModal(declineReportModal);
        } else if (!generateReportModal.hidden || !exportModal.hidden) {
            closeModal(generateReportModal);
            closeModal(exportModal);
        } else if (reportDrawer.classList.contains("open")) {
            closeReportDrawer();
        }
        hideReportToast();
        return;
    }

    if (event.key !== "Enter" && event.key !== " ") return;

    const target = event.target;
    if (!(target instanceof Element)) return;

    if (!target.matches(".duplicate-report-card[role='button']")) return;

    event.preventDefault();
    handleCardActivation(target);
});

/* =========================================================
   REFRESH AFTER SAVING
========================================================= */

async function refreshManageOriginal(reportID) {
    const categoryID = activeManageCategoryID;

    const loaded = await loadReportDrawer(currentCategoryID, currentLocationID, true);
    if (!loaded) return;

    const root = getReportRoot();
    if (!root) return;

    const originalScreen = reportID && Array.from(root.querySelectorAll(".manage-original-screen"))
        .find(screen => screen.dataset.originalScreen === reportID);

    if (originalScreen) {
        showManageOriginal(reportID);
    } else if (categoryID) {
        showManageCategory(categoryID, "back");
    } else {
        showGroupScreen(false);
    }
}

/* =========================================================
   UPDATE ORIGINAL REPORT STATUS
========================================================= */

function setButtonBusy(button, busy, busyText) {
    if (!button) return;

    if (busy) {
        button.dataset.label = button.innerHTML;
        button.innerHTML = `<span class="btn-spinner"></span> ${busyText}`;
        button.disabled = true;
    } else {
        if (button.dataset.label) button.innerHTML = button.dataset.label;
        button.disabled = false;
    }
}

document.addEventListener("click", async function (event) {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const button = target.closest("[data-save-original-status]");
    if (!button || button.disabled) return;

    const reportID = button.dataset.saveOriginalStatus;
    const screen = button.closest(".manage-original-screen");
    if (!screen) return;

    const statusSelect = screen.querySelector(".manage-status-select");
    const remarksInput = screen.querySelector(".manage-remarks-input");

    if (!statusSelect || !reportID || !statusSelect.value) {
        showReportToast("Please select a valid status.", true);
        return;
    }

    setButtonBusy(button, true, "Updating…");

    try {
        const response = await fetch("update_report_status.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                reportID: reportID,
                statusID: statusSelect.value,
                remarks: remarksInput ? remarksInput.value.trim() : ""
            })
        });

        let data;

        try {
            data = await response.json();
        } catch {
            throw new Error("The server returned an invalid response while updating the status.");
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || "Unable to update report status.");
        }

        const categoryID = activeManageCategoryID;
        const loaded = await loadReportDrawer(currentCategoryID, currentLocationID, true);

        if (!loaded) {
            showReportToast("Status updated, but the drawer could not refresh. Reopen it to see the latest data.", true);
            return;
        }

        /* Stay where the admin was working */
        const root = getReportRoot();
        const stillActive = root && Array.from(root.querySelectorAll(".manage-original-screen"))
            .some(item => item.dataset.originalScreen === reportID);

        if (stillActive) {
            showManageOriginal(reportID);
        } else if (categoryID) {
            showManageCategory(categoryID, "back");
        } else {
            showGroupScreen();
        }

        showReportToast(data.message || "Report status updated successfully.");

    } catch (error) {
        showReportToast(error.message || "Unable to update report status.", true);
        setButtonBusy(button, false);
    }
});

/* =========================================================
   SAVE ORIGINAL REPORT TIMELINE
========================================================= */

document.addEventListener("submit", async function (event) {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const form = target.closest(".manage-timeline-form");
    if (!form) return;

    event.preventDefault();

    const reportID = form.dataset.reportId;
    const timelineInput = form.querySelector('input[name="timeline"]');
    const timeline = timelineInput?.value || "";
    const button = form.querySelector('button[type="submit"]');

    setButtonBusy(button, true, "Saving…");

    try {
        const response = await fetch("update_report_timeline.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ reportID: reportID, timeline: timeline })
        });

        let data;

        try {
            data = await response.json();
        } catch {
            throw new Error("The server returned an invalid response while saving the timeline.");
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || "Unable to save timeline.");
        }

        await refreshManageOriginal(reportID);
        showReportToast(data.message || "Report timeline saved successfully.");

    } catch (error) {
        showReportToast(error.message || "Unable to save timeline.", true);
        setButtonBusy(button, false);
    }
});

/* =========================================================
   MODALS: GENERATE REPORT -> EXPORT FORMAT
========================================================= */

const exportModal = document.getElementById("exportModal");
const generateReportModal = document.getElementById("generateReportModal");
const generateReportButton = document.getElementById("generateReportButton");
const generateReportForm = document.getElementById("generateReportForm");

function openModal(modal) {
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add("modal-open");

    const focusTarget = modal.querySelector("input:checked, .export-option, button");
    if (focusTarget) setTimeout(() => focusTarget.focus(), 50);
}

function closeModal(modal) {
    if (!modal || modal.hidden) return;

    modal.classList.add("is-closing");

    setTimeout(() => {
        modal.hidden = true;
        modal.classList.remove("is-closing");

        if (
            generateReportModal.hidden &&
            exportModal.hidden &&
            (!declineReportModal || declineReportModal.hidden)
        ) {
            document.body.classList.remove("modal-open");
        }
    }, 180);
}

generateReportButton?.addEventListener("click", () => openModal(generateReportModal));

document.getElementById("closeGenerateReportModal")?.addEventListener("click", () => closeModal(generateReportModal));
document.getElementById("closeExportModal")?.addEventListener("click", () => closeModal(exportModal));

document.getElementById("exportBackButton")?.addEventListener("click", function () {
    exportModal.hidden = true;
    openModal(generateReportModal);
});

[generateReportModal, exportModal].forEach(modal => {
    modal?.addEventListener("click", function (event) {
        if (event.target === modal) closeModal(modal);
    });
});

generateReportForm?.addEventListener("submit", function (event) {
    event.preventDefault();

    const selected = generateReportForm.querySelector('input[name="period"]:checked');
    if (!selected) return;

    const period = selected.value;
    const periodLabel = selected.closest(".period-option")?.querySelector("strong")?.textContent || "";

    exportModal.querySelectorAll(".export-modal-options a").forEach(function (link) {
        const format = link.getAttribute("href").match(/format=([^&]+)/);

        if (format) {
            link.href = "export_reports.php?format=" + format[1] + "&period=" + encodeURIComponent(period);
        }
    });

    const label = document.getElementById("exportPeriodLabel");
    if (label) label.textContent = "Period: " + periodLabel + ". Choose how you want to download it.";

    generateReportModal.hidden = true;
    openModal(exportModal);
});

exportModal?.querySelectorAll(".export-option").forEach(link => {
    link.addEventListener("click", () => {
        showReportToast("Preparing your download…");
        setTimeout(() => closeModal(exportModal), 300);
    });
});

/* =========================================================
   DEEP LINK FROM NOTIFICATIONS: reports.php?open=CAT&report=ID
========================================================= */

(function () {
    const params = new URLSearchParams(window.location.search);
    const openCategory = params.get("open");

    if (!openCategory) return;

    const reportID = params.get("report") || "";

    params.delete("open");
    params.delete("report");

    const clean = window.location.pathname + (params.toString() ? "?" + params.toString() : "");
    window.history.replaceState({}, document.title, clean);

    openCategoryDrawer(openCategory, reportID);
})();
</script>

</body>
</html>