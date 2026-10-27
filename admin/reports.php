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

$startDate = trim((string) ($_GET["start_date"] ?? ""));
$endDate = trim((string) ($_GET["end_date"] ?? ""));
$selectedCategory = trim((string) ($_GET["categoryID"] ?? ""));
$selectedStatus = trim((string) ($_GET["statusID"] ?? ""));
$searchQuery = trim((string) ($_GET["search"] ?? ""));

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if ($startDate !== "" && !preg_match($datePattern, $startDate)) {
    $startDate = "";
}

if ($endDate !== "" && !preg_match($datePattern, $endDate)) {
    $endDate = "";
}

if ($startDate !== "" && $endDate !== "" && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

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
    !in_array($selectedStatus, $validStatusIDs, true)
) {
    $selectedStatus = "";
}

/* =========================================================
   BUILD FILTER CONDITIONS

   All filters are applied to individual report submissions
   before counts and latest report dates are calculated.
========================================================= */

// Conditions shared by queries that select individual reports.
$conditions = ["r.barangayID = ?"];
$types = "s";
$params = [$barangayID];

if ($startDate !== "") {
    $conditions[] = "r.dateReported >= ?";
    $types .= "s";
    $params[] = $startDate . " 00:00:00";
}

if ($endDate !== "") {
    $conditions[] = "r.dateReported < DATE_ADD(?, INTERVAL 1 DAY)";
    $types .= "s";
    $params[] = $endDate;
}

if ($selectedCategory !== "") {
    $conditions[] = "r.categoryID = ?";
    $types .= "s";
    $params[] = $selectedCategory;
}

if ($selectedStatus !== "") {
    $conditions[] = "r.statusID = ?";
    $types .= "s";
    $params[] = $selectedStatus;
}

// Search the database instead of filtering only the visible HTML rows.
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

    array_push(
        $params,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
}

$whereSQL = implode(" AND ", $conditions);

// Keep your existing date, category, and status filter conditions here.

// ADD THE SEARCH CONDITION HERE
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

    array_push(
        $params,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
}

// Keep this AFTER all filter conditions.

$whereSQL = implode(" AND ", $conditions);

/* =========================================================
   REPORT GROUPS

   Each row represents a category at a location.
   COUNT(r.reportID) counts actual submissions.
   MAX(r.dateReported) finds the latest matching submission.

   The filtered report IDs are passed to the unchanged
   reportView.php through the category/location buttons.
========================================================= */

$sql = "
    SELECT
        c.categoryID,
        c.categoryName,
        l.locationID,
        l.address,
        COUNT(r.reportID) AS totalReports,
        MAX(r.dateReported) AS latestReport,
        GROUP_CONCAT(
            r.reportID
            ORDER BY r.dateReported DESC, r.reportID DESC
            SEPARATOR ','
        ) AS reportIDs
    FROM categories c
    LEFT JOIN reports r
        ON r.categoryID = c.categoryID
        AND r.barangayID = ?
        AND (? = '' OR r.dateReported >= ?)
        AND (? = '' OR r.dateReported < DATE_ADD(?, INTERVAL 1 DAY))
        AND (? = '' OR r.categoryID = ?)
        AND (? = '' OR r.statusID = ?)
        AND (
            ? = ''
            OR EXISTS (
                SELECT 1
                FROM location sl
                WHERE sl.locationID = r.locationID
                  AND sl.address LIKE ?
            )
            OR EXISTS (
                SELECT 1
                FROM categories sc
                WHERE sc.categoryID = r.categoryID
                  AND sc.categoryName LIKE ?
            )
            OR COALESCE(r.title, '') LIKE ?
            OR COALESCE(r.description, '') LIKE ?
            OR CAST(r.reportID AS CHAR) LIKE ?
        )
    LEFT JOIN location l
        ON l.locationID = r.locationID
    GROUP BY
        c.categoryID,
        c.categoryName,
        l.locationID,
        l.address
    ORDER BY
        c.categoryID ASC,
        l.locationID ASC
";

$startDateValue = $startDate;
$endDateValue = $endDate;
$categoryValue = $selectedCategory;
$statusValue = $selectedStatus;
$searchLike = "%" . $searchQuery . "%";

$stmt = reportsPrepare($conn, $sql);

$stmt->bind_param(
    "sssssssssssssss",
    $barangayID,
    $startDateValue,
    $startDateValue,
    $endDateValue,
    $endDateValue,
    $categoryValue,
    $categoryValue,
    $statusValue,
    $statusValue,
    $searchQuery,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike
);

$stmt->execute();

$reportGroups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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

    <title>CitiSense - Reports</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/reports.css">
    <link rel="stylesheet" href="../css/reportView.css">
</head>

<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="main-content">

    <header class="reports-header">
        <div>
            <h1>Reports</h1>
            <p>
                View and manage reports submitted by the community
                in <?= reportsEscape($barangayName) ?>.
            </p>
        </div>
    </header>

    <!-- FILTERS -->

    <form method="GET" action="reports.php" id="reportsFilterForm" class="analytics-filters reports-filters">

    <!-- Date range -->
    <div class="analytics-filter reports-date-filter">
            <i class="fa-regular fa-calendar-days"></i>

            <input
                type="date"
                name="startDate"
                value="<?= reportsEscape($startDate) ?>"
                aria-label="Start date"
            >

            <span class="date-separator">-</span>

            <input
                type="date"
                name="endDate"
                value="<?= reportsEscape($endDate) ?>"
                aria-label="End date"
            >
        </div>

        <!-- Category -->
        <div class="analytics-filter reports-select-filter">
            <i class="fa-solid fa-tags"></i>

            <select name="category" aria-label="Filter by category">
                <option value="">All Categories</option>

                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= reportsEscape($category['categoryID']) ?>"
                        <?= $selectedCategory === (string) $category['categoryID'] ? 'selected' : '' ?>
                    >
                        <?= reportsEscape($category['categoryName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Status -->
        <div class="analytics-filter reports-select-filter">
            <i class="fa-solid fa-filter"></i>

            <select name="status" aria-label="Filter by status">
                <option value="">All Statuses</option>

                <?php foreach ($statuses as $status): ?>
                    <option
                        value="<?= reportsEscape($status['statusID']) ?>"
                        <?= $selectedStatus === (string) $status['statusID'] ? 'selected' : '' ?>
                    >
                        <?= reportsEscape($status['statusName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Apply filters -->
        <button type="submit" class="analytics-filter-button">
            <i class="fa-solid fa-sliders"></i>
            Apply Filters
        </button>

        <!-- Reset filters -->
        <a href="reports.php" class="analytics-reset-button">
            Reset
        </a>
    </form>

    <!-- Search bar -->
    <form method="GET" action="reports.php" id="reportsSearchForm" class="reports-search-form">
        <input type="hidden" name="startDate" value="<?= reportsEscape($startDate) ?>">
        <input type="hidden" name="endDate" value="<?= reportsEscape($endDate) ?>">
        <input type="hidden" name="category" value="<?= reportsEscape($selectedCategory) ?>">
        <input type="hidden" name="status" value="<?= reportsEscape($selectedStatus) ?>">

        <i class="fa-solid fa-magnifying-glass"></i>

        <input
            type="search"
            id="reportSearch"
            name="search"
            value="<?= reportsEscape($searchQuery) ?>"
            placeholder="Search reports..."
            aria-label="Search reports"
        >
    </form>

    <!-- REPORTS LAYOUT -->

    <section class="reports-layout">

        <div class="reports-list-card">

            <div class="reports-card-header">
                <h2>
                    Matching Reports
                    <span>(<?= $totalReports ?>)</span>
                </h2>
            </div>

            <div class="reports-table-wrapper">
                <table class="reports-table">

                    <thead>
                        <tr>
                            <th>Issue</th>
                            <th>Location</th>
                            <th>No. of Reports</th>
                            <th>Latest Report</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="reportsTableBody">

                    <?php if (!empty($reportGroups)): ?>

                        <?php foreach ($reportGroups as $report): ?>

                            <?php
                            $categoryName = (string) $report["categoryName"];
                            $address = (string) ($report["address"] ?? "");
                            $locationID = (string) ($report["locationID"] ?? "");
                            $latestDate = $report["latestReport"] ?? null;

                            $latestTimestamp = $latestDate
                                ? strtotime($latestDate)
                                : false;

                            $searchText = strtolower(
                                $categoryName . " " . $address
                            );
                            ?>

                            <tr
                                class="report-row"
                                data-search="<?= reportsEscape($searchText) ?>"
                                data-category-id="<?= reportsEscape($report["categoryID"]) ?>"
                                data-location-id="<?= reportsEscape($locationID) ?>"
                            >

                                <td>
                                    <div class="issue-cell">
                                        <div class="issue-icon">
                                            <i class="bi bi-exclamation-circle"></i>
                                        </div>

                                        <span>
                                            <?= reportsEscape($categoryName) ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="location-cell">
                                        <i class="bi bi-geo-alt"></i>

                                        <span>
                                            <?= reportsEscape(
                                                $address !== ""
                                                    ? $address
                                                    : "Location unavailable"
                                            ) ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="report-count">
                                        <strong>
                                            <?= (int) $report["totalReports"] ?>
                                        </strong>
                                        <span>
                                            <?= (int) $report["totalReports"] === 1
                                                ? "report"
                                                : "reports" ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="latest-report">
                                        <strong>
                                            <?= $latestTimestamp
                                                ? date("M d, Y", $latestTimestamp)
                                                : "Unknown date" ?>
                                        </strong>

                                        <span>
                                            <?= $latestTimestamp
                                                ? date("g:i A", $latestTimestamp)
                                                : "" ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <button
                                        type="button"
                                        class="view-button view-report-btn"
                                        title="View reports"
                                        aria-label="View <?= reportsEscape($categoryName) ?> reports"
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
                                <span>
                                    Try changing or resetting your filters.
                                </span>
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>
            </div>

            <div class="reports-card-footer">
                <span id="reportsShowing">
                    Showing <?= count($reportGroups) ?>
                    group<?= count($reportGroups) === 1 ? "" : "s" ?>
                </span>
            </div>

        </div>

        <!-- RIGHT COLUMN -->

        <div class="reports-right-column">

            <div class="overview-card">

                <div class="side-card-header">
                    <h2>Reports Overview</h2>
                    <span>
                        <?= $startDate !== "" || $endDate !== ""
                            ? "Selected Dates"
                            : "All Time" ?>
                    </span>
                </div>

                <div class="overview-content">

                    <div
                        class="donut-chart"
                        style="
                            --pending: <?= $pendingPercent ?>%;
                            --progress: <?= $inProgressPercent ?>%;
                            --overdue: <?= $overduePercent ?>%;
                            --resolved: <?= $resolvedPercent ?>%;
                        "
                    >
                        <div class="donut-center">
                            <strong><?= $overviewTotal ?></strong>
                            <span>Total</span>
                        </div>
                    </div>

                    <div class="overview-legend">

                        <div class="legend-item">
                            <span class="legend-dot pending"></span>
                            <div>
                                <strong>Pending</strong>
                                <small>
                                    <?= $statusCounts["Pending"] ?>
                                    (<?= $pendingPercent ?>%)
                                </small>
                            </div>
                        </div>

                        <div class="legend-item">
                            <span class="legend-dot progress"></span>
                            <div>
                                <strong>In Progress</strong>
                                <small>
                                    <?= $statusCounts["In Progress"] ?>
                                    (<?= $inProgressPercent ?>%)
                                </small>
                            </div>
                        </div>

                        <div class="legend-item">
                            <span class="legend-dot overdue"></span>
                            <div>
                                <strong>Overdue</strong>
                                <small>
                                    <?= $statusCounts["Overdue"] ?>
                                    (<?= $overduePercent ?>%)
                                </small>
                            </div>
                        </div>

                        <div class="legend-item">
                            <span class="legend-dot resolved"></span>
                            <div>
                                <strong>Resolved</strong>
                                <small>
                                    <?= $statusCounts["Resolved"] ?>
                                    (<?= $resolvedPercent ?>%)
                                </small>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

            <!-- QUICK ACTIONS -->

            <div class="quick-actions-card">
                <h2>Quick Actions</h2>

                <div class="quick-actions">

                    <button type="button" id="openExportModal">
                        <i class="bi bi-download"></i>
                        <span>Export Reports</span>
                    </button>

                    <button
                        type="button"
                        id="generateReportButton"
                    >
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Generate Report</span>
                    </button>

                    <button
                        type="button"
                        id="manageReportsButton"
                    >
                        <i class="bi bi-grid"></i>
                        <span>Manage Reports</span>
                    </button>

                </div>
            </div>

        </div>

    </section>

</main>

<!-- EXPORT MODAL -->

<div
    id="exportModal"
    class="export-modal-overlay"
    hidden
>
    <div class="export-modal">

        <div class="export-modal-header">
            <h2>Export Reports</h2>

            <button
                type="button"
                id="closeExportModal"
                aria-label="Close"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <p>Select a format to export the reports.</p>

        <div class="export-modal-options">

            <a href="export_reports.php?format=pdf">
                <i class="bi bi-file-earmark-pdf"></i>
                Export as PDF
            </a>

            <a href="export_reports.php?format=csv">
                <i class="bi bi-filetype-csv"></i>
                Export as CSV
            </a>

            <a href="export_reports.php?format=docx">
                <i class="bi bi-file-earmark-word"></i>
                Export as Word
            </a>

        </div>

    </div>
</div>

<!-- REPORT DRAWER -->

<div
    class="report-drawer-overlay"
    id="reportDrawerOverlay"
></div>

<aside class="report-drawer" id="reportDrawer">
    <div
        id="reportDrawerContent"
        class="report-drawer-loading"
    >
        <div class="drawer-spinner"></div>
        <p>Select a report to view its details.</p>
    </div>
</aside>

<!-- TOAST -->

<div
    id="reportToast"
    class="report-toast"
    role="status"
    aria-live="polite"
    hidden
>
    <i class="bi bi-check-circle-fill" id="reportToastIcon"></i>
    <span id="reportToastText"></span>
</div>

<script>
"use strict";

/* =========================================================
   CLIENT-SIDE SEARCH

   The PHP filters already apply on the server. This search
   narrows the rows currently displayed without another reload.
========================================================= */

const searchInput = document.getElementById("reportSearch");

if (searchInput) {
    searchInput.addEventListener("input", function () {
        const searchValue = this.value.toLowerCase().trim();

        document.querySelectorAll(".report-row").forEach(row => {
            const text = row.dataset.search || "";

            row.style.display = text.includes(searchValue) ? "" : "none";
        });

        updateVisibleCount();
    });
}

function updateVisibleCount() {
    const rows = Array.from(document.querySelectorAll(".report-row"));
    const visibleRows = rows.filter(row => row.style.display !== "none");
    const label = document.getElementById("reportsShowing");

    if (label) {
        label.textContent =
            "Showing " + visibleRows.length +
            " of " + rows.length + " groups";
    }
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

function openReportDrawer() {
    reportDrawer.classList.add("open");
    reportDrawerOverlay.classList.add("open");
}

function closeReportDrawer() {
    reportDrawer.classList.remove("open");
    reportDrawerOverlay.classList.remove("open");
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

    if (reportToastTimer !== null) {
        clearTimeout(reportToastTimer);
    }

    text.textContent = message;

    icon.className = isError
        ? "bi bi-exclamation-circle-fill"
        : "bi bi-check-circle-fill";

    toast.classList.toggle("report-toast-error", isError);
    toast.classList.toggle("success", !isError);
    toast.classList.toggle("error", isError);

    toast.hidden = false;

    reportToastTimer = setTimeout(() => {
        hideReportToast();
    }, 1000);
}

function hideReportToast() {
    const toast = document.getElementById("reportToast");

    if (reportToastTimer !== null) {
        clearTimeout(reportToastTimer);
        reportToastTimer = null;
    }

    if (toast) {
        toast.hidden = true;
    }
}

/* =========================================================
   LOAD REPORT DETAILS

   reportView.php remains unchanged.
========================================================= */

async function loadReportDrawer(categoryID, locationID) {
    currentCategoryID = categoryID;
    currentLocationID = locationID;

    reportDrawerContent.innerHTML = `
        <div class="drawer-spinner"></div>
        <p>Loading report details...</p>
    `;

    try {
        const query = new URLSearchParams({
            categoryID: categoryID || "",
            locationID: locationID || ""
        });

        const response = await fetch(
            "reportView.php?" + query.toString(),
            { cache: "no-store" }
        );

        if (!response.ok) {
            throw new Error("Unable to load report details.");
        }

        const html = await response.text();
        reportDrawerContent.innerHTML = html;

        reportDrawerContent.querySelectorAll("img[src]").forEach(img => {
            const originalSrc = img.getAttribute("src") || "";

            const match = originalSrc.match(
                /^(?:\.\.?\/)*uploads\/reports\/(.+)$/i
            );

            if (match) {
                img.src = "/citisense/uploads/reports/" + match[1];
            }
        });

        if (typeof initializeReportDrawer === "function") {
            initializeReportDrawer();
        }

        return true;

    } catch (error) {
        reportDrawerContent.innerHTML = `
            <div class="report-view-error">
                <i class="bi bi-exclamation-circle"></i>
                <p>Unable to load report details.</p>
            </div>
        `;

        showReportToast(
            error.message || "Unable to load report details.",
            true
        );

        return false;
    }
}

/* =========================================================
   OPEN REPORT GROUP
========================================================= */

document.querySelectorAll(".view-report-btn").forEach(button => {
    button.addEventListener("click", async function () {
        activeManageCategoryID = "";
        activeOriginalID = "";
        detailReturnScreen = null;

        openReportDrawer();

        const loaded = await loadReportDrawer(
            this.dataset.categoryId,
            this.dataset.locationId
        );

        if (loaded) {
            showGroupScreen();
        }
    });
});

/* =========================================================
   DRAWER SCREEN NAVIGATION

   These selectors correspond to the existing reportView.php.
========================================================= */

function getReportRoot() {
    return reportDrawerContent.querySelector(".report-view-content");
}

function hideAllReportScreens(root) {
    if (!root) return;

    root.querySelectorAll(
        "#reportGroupScreen, " +
        "#manageCategoriesScreen, " +
        ".manage-category-screen, " +
        ".manage-original-screen, " +
        "#individualReportScreen"
    ).forEach(screen => {
        screen.hidden = true;
    });
}

function showReportScreen(screen) {
    const root = getReportRoot();

    if (!root || !screen) return;

    hideAllReportScreens(root);
    screen.hidden = false;
    reportDrawer.scrollTop = 0;
}

function showGroupScreen() {
    const root = getReportRoot();

    if (!root) return;

    const screen = root.querySelector("#reportGroupScreen");

    if (screen) {
        activeManageCategoryID = "";
        activeOriginalID = "";
        detailReturnScreen = null;
        showReportScreen(screen);
    }
}

function showManageCategories() {
    const root = getReportRoot();

    if (!root) return;

    activeOriginalID = "";

    showReportScreen(
        root.querySelector("#manageCategoriesScreen")
    );
}

function showManageCategory(categoryID) {
    const root = getReportRoot();

    if (!root) return;

    const screen = Array.from(
        root.querySelectorAll(".manage-category-screen")
    ).find(item => item.dataset.categoryScreen === categoryID);

    if (!screen) return;

    activeManageCategoryID = categoryID;
    activeOriginalID = "";

    showReportScreen(screen);
}

function showManageOriginal(reportID) {
    const root = getReportRoot();

    if (!root) return;

    const screen = Array.from(
        root.querySelectorAll(".manage-original-screen")
    ).find(item => item.dataset.originalScreen === reportID);

    if (!screen) return;

    activeOriginalID = reportID;
    activeManageCategoryID = screen.dataset.parentCategory || "";

    showReportScreen(screen);
}

/* =========================================================
   FORMAT DATES
========================================================= */

function formatReportTimeline(value) {
    if (!value) return "";

    const date = new Date(value.replace(" ", "T"));

    if (Number.isNaN(date.getTime())) {
        return value;
    }

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
   READ-ONLY INDIVIDUAL REPORT DETAILS
========================================================= */

function openReadOnlyReport(card) {
    const root = getReportRoot();

    if (!root || !card) return;

    const detailScreen = root.querySelector("#individualReportScreen");

    if (!detailScreen) return;

    detailReturnScreen = Array.from(
        root.querySelectorAll(
            "#reportGroupScreen, .manage-category-screen"
        )
    ).find(screen => !screen.hidden) || null;

    const fields = {
        detailCategory: card.dataset.category,
        detailResident: card.dataset.resident,
        detailEmail: card.dataset.email,
        detailContact: card.dataset.contact,
        detailReportID: card.dataset.reportId,
        detailLocation: card.dataset.location,
        detailDescription: card.dataset.description,
        detailDate: formatReportTimeline(card.dataset.date),
        detailStatus: card.dataset.statusName
    };

    Object.entries(fields).forEach(([id, value]) => {
        const element = root.querySelector("#" + id);

        if (element) {
            element.textContent = value || "Not provided";
        }
    });

    const timelineContainer =
        root.querySelector("#detailTimelineContainer");

    const timelineText = root.querySelector("#detailTimeline");

    if (timelineContainer && timelineText) {
        const timeline = card.dataset.timeline || "";

        timelineText.textContent = formatReportTimeline(timeline);
        timelineContainer.hidden = !timeline;
    }

    const imageContainer = root.querySelector("#detailImageContainer");
    const image = root.querySelector("#detailImage");

    if (imageContainer && image) {
        if (card.dataset.image) {
            image.src = card.dataset.image;
            imageContainer.hidden = false;
        } else {
            image.removeAttribute("src");
            imageContainer.hidden = true;
        }
    }

    const historyTarget = root.querySelector("#detailStatusHistory");
    const reportID = card.dataset.reportId;

    const historyTemplate = Array.from(
        root.querySelectorAll("[data-history-report-id]")
    ).find(node => node.dataset.historyReportId === reportID);

    if (historyTarget) {
        historyTarget.replaceChildren();

        if (historyTemplate) {
            const nodes = Array.from(historyTemplate.childNodes)
                .map(node => node.cloneNode(true));

            historyTarget.append(...nodes);
        } else {
            const emptyMessage = document.createElement("p");

            emptyMessage.className = "manage-empty";
            emptyMessage.textContent =
                "No status history recorded for this report.";

            historyTarget.appendChild(emptyMessage);
        }
    }

    showReportScreen(detailScreen);
}

/* =========================================================
   DRAWER BUTTON HANDLERS
========================================================= */

document.addEventListener("click", function (event) {
    const target = event.target;

    if (!(target instanceof Element)) return;

    const root = target.closest(".report-view-content");

    if (!root) return;

    if (target.closest("#manageGroupReports")) {
        showManageCategories();
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
            showManageCategory(backButton.dataset.categoryId);
        }

        return;
    }

    if (target.closest("#backToReportList")) {
        if (detailReturnScreen) {
            showReportScreen(detailReturnScreen);
        } else {
            showGroupScreen();
        }

        return;
    }

    const card = target.closest(".duplicate-report-card");

    if (card) {
        if (
            card.dataset.manage === "1" &&
            card.dataset.original === "0"
        ) {
            return;
        }

        if (
            card.dataset.manage === "1" &&
            card.dataset.original === "1"
        ) {
            showManageOriginal(card.dataset.reportId);
        } else {
            openReadOnlyReport(card);
        }

        return;
    }

    if (target.closest("[data-close-report-view], #closeReportView")) {
        closeReportDrawer();
    }
});

/* =========================================================
   KEYBOARD SUPPORT
========================================================= */

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeReportDrawer();
        hideReportToast();

        const exportModal = document.getElementById("exportModal");

        if (exportModal) {
            exportModal.hidden = true;
        }

        return;
    }

    if (event.key !== "Enter" && event.key !== " ") return;

    const target = event.target;

    if (!(target instanceof Element)) return;

    const card = target.closest(".duplicate-report-card");

    if (!card) return;

    if (
        card.dataset.manage === "1" &&
        card.dataset.original === "0"
    ) {
        return;
    }

    event.preventDefault();
    card.click();
});

/* =========================================================
   REFRESH ORIGINAL REPORT MANAGEMENT SCREEN
========================================================= */

async function refreshManageOriginal(reportID) {
    const categoryID = activeManageCategoryID;
    const currentCategory = currentCategoryID;
    const currentLocation = currentLocationID;

    const loaded = await loadReportDrawer(
        currentCategory,
        currentLocation
    );

    if (!loaded) return;

    if (categoryID) {
        showManageCategory(categoryID);
    }

    if (reportID) {
        const root = getReportRoot();

        if (!root) return;

        const originalScreen = Array.from(
            root.querySelectorAll(".manage-original-screen")
        ).find(screen => screen.dataset.originalScreen === reportID);

        if (originalScreen) {
            showManageOriginal(reportID);
        }
    }
}

/* =========================================================
   UPDATE ORIGINAL REPORT STATUS
========================================================= */

document.addEventListener("click", async function (event) {
    const target = event.target;

    if (!(target instanceof Element)) return;

    const button = target.closest("[data-save-original-status]");

    if (!button || button.disabled) return;

    const reportID = button.dataset.saveOriginalStatus;
    const screen = button.closest(".manage-original-screen");

    if (!screen) return;

    const statusSelect = screen.querySelector(".manage-status-select");

    if (!statusSelect || !reportID || !statusSelect.value) {
        showReportToast("Please select a valid status.", true);
        return;
    }

    button.disabled = true;

    try {
        const response = await fetch("update_report_status.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                reportID: reportID,
                statusID: statusSelect.value
            })
        });

        let data;

        try {
            data = await response.json();
        } catch {
            throw new Error(
                "The server returned an invalid response while updating the status."
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || "Unable to update report status."
            );
        }

        const loaded = await loadReportDrawer(
            currentCategoryID,
            currentLocationID
        );

        if (!loaded) {
            showReportToast(
                "Status updated, but the drawer could not refresh. Reopen the report to see the latest data.",
                true
            );
            return;
        }

        showGroupScreen();

        showReportToast(
            data.message || "Report status updated successfully."
        );

    } catch (error) {
        showReportToast(
            error.message || "Unable to update report status.",
            true
        );

    } finally {
        button.disabled = false;
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

    const timelineInput = form.querySelector(
        'input[name="timeline"]'
    );

    const timeline = timelineInput?.value || "";

    const button = form.querySelector('button[type="submit"]');

    if (button) button.disabled = true;

    try {
        const response = await fetch("update_report_timeline.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                reportID: reportID,
                timeline: timeline
            })
        });

        let data;

        try {
            data = await response.json();
        } catch {
            throw new Error(
                "The server returned an invalid response while saving the timeline."
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || "Unable to save timeline."
            );
        }

        await refreshManageOriginal(reportID);

        showReportToast(
            data.message || "Report timeline saved successfully."
        );

    } catch (error) {
        showReportToast(
            error.message || "Unable to save timeline.",
            true
        );

    } finally {
        if (button) button.disabled = false;
    }
});

/* =========================================================
   EXPORT MODAL
========================================================= */

const exportModal = document.getElementById("exportModal");
const openExportModal = document.getElementById("openExportModal");
const closeExportModal = document.getElementById("closeExportModal");

openExportModal?.addEventListener("click", function () {
    exportModal.hidden = false;
});

closeExportModal?.addEventListener("click", function () {
    exportModal.hidden = true;
});

exportModal?.addEventListener("click", function (event) {
    if (event.target === exportModal) {
        exportModal.hidden = true;
    }
});

/* =========================================================
   QUICK ACTIONS

   The first button opens the same report drawer. The other
   two show a message rather than silently doing nothing.
========================================================= */

document.getElementById("manageReportsButton")
    ?.addEventListener("click", function () {
        const firstViewButton = document.querySelector(".view-report-btn");

        if (firstViewButton) {
            firstViewButton.click();
        } else {
            showReportToast("There are no reports to manage.", true);
        }
    });

document.getElementById("generateReportButton")
    ?.addEventListener("click", function () {
        showReportToast(
            "Use Export Reports to download the available report formats."
        );
    });

/* =========================================================
   STATUS HISTORY HELPER
========================================================= */

function loadReportStatusHistory(reportID) {
    const historyContainer = document.getElementById(
        "detailStatusHistory"
    );

    const historyTemplates = document.getElementById(
        "reportHistoryTemplates"
    );

    if (!historyContainer || !historyTemplates) {
        console.error(
            "Status history container or templates not found."
        );
        return;
    }

    const reportIDString = String(reportID);

    const matchingTemplate = Array.from(
        historyTemplates.querySelectorAll("[data-history-report-id]")
    ).find(template =>
        template.dataset.historyReportId === reportIDString
    );

    historyContainer.replaceChildren();

    if (!matchingTemplate) {
        historyContainer.textContent =
            "No status history is available for this report.";
        return;
    }

    historyContainer.appendChild(matchingTemplate.cloneNode(true));
}
</script>

</body>
</html>