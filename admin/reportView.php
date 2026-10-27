<?php
session_start();

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    http_response_code(401);
    exit("Unauthorized.");
}

require_once __DIR__ . "/../config/database.php";

$barangayID = (string) $_SESSION["barangay_id"];
$categoryID = trim((string) ($_GET["categoryID"] ?? ""));
$locationID = trim((string) ($_GET["locationID"] ?? ""));

if (
    $categoryID === "" ||
    $locationID === "" ||
    strlen($categoryID) > 50 ||
    strlen($locationID) > 50
) {
    http_response_code(400);
    exit("Invalid report selection.");
}

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function rvEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function rvDate(
    $value,
    string $format = "M j, Y g:i A"
): string {
    if (
        !$value ||
        strtotime((string) $value) === false
    ) {
        return "";
    }

    return date(
        $format,
        strtotime((string) $value)
    );
}

function rvStatusClass($status): string
{
    return trim(
        preg_replace(
            "/[^a-z0-9]+/",
            "-",
            strtolower((string) $status)
        ),
        "-"
    );
}

function rvOverdueLabel($timeline): string
{
    if (
        !$timeline ||
        strtotime((string) $timeline) === false
    ) {
        return "Overdue";
    }

    $seconds = time() - strtotime((string) $timeline);

    if ($seconds < 86400) {
        return "Overdue · Today";
    }

    $days = (int) floor($seconds / 86400);

    return "Overdue · " . $days .
        ($days === 1 ? " day" : " days");
}

/* =========================================================
   FETCH REPORTS
========================================================= */

$sql = "
    SELECT
        r.reportID,
        r.userID,
        r.categoryID,
        r.locationID,
        r.statusID,
        r.title,
        r.description,
        r.dateReported,
        r.timeline,

        c.categoryName,
        s.statusName,
        l.address,

        u.firstName,
        u.lastName,
        u.email,
        u.contactNo,

        (
            SELECT ri.imagePath
            FROM reportImages ri
            WHERE ri.reportID = r.reportID
            ORDER BY ri.uploadedAt ASC
            LIMIT 1
        ) AS imagePath

    FROM reports r

    INNER JOIN categories c
        ON c.categoryID = r.categoryID

    INNER JOIN stats s
        ON s.statusID = r.statusID

    INNER JOIN location l
        ON l.locationID = r.locationID

    INNER JOIN users u
        ON u.userID = r.userID

    WHERE r.barangayID = ?

    ORDER BY
        r.dateReported ASC,
        r.reportID ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$allReports = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   FETCH ACTUAL RESOLUTION DATES

   Resolution dates are taken from reportHistory.

   No fabricated resolution timestamps.
========================================================= */

$resolvedAt = [];

$sql = "
    SELECT
        h.reportID,
        MIN(h.dateChanged) AS resolvedAt

    FROM reportHistory h

    INNER JOIN reports r
        ON r.reportID = h.reportID

    WHERE
        r.barangayID = ?
        AND h.statusID = 'STAT003'

    GROUP BY h.reportID
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $resolvedAt[$row["reportID"]] = $row["resolvedAt"];
}

$stmt->close();

/* =========================================================
   ORIGINAL AND DUPLICATE CLASSIFICATION

   Same:
   - Barangay
   - Category
   - Location

   Original:
   Earliest submitted report in a reporting cycle.

   Duplicate:
   Later submissions while that cycle remains open.

   When the Original is Resolved:
   Hide the Original and all its duplicates from
   Manage Reports.

   A submission after the recorded resolution date
   starts a new reporting cycle.
========================================================= */

$reportBuckets = [];

foreach ($allReports as $report) {

    $key =
        $report["categoryID"] . "\0" .
        $report["locationID"];

    if (!isset($reportBuckets[$key])) {
        $reportBuckets[$key] = [];
    }

    $reportBuckets[$key][] = $report;
}

$cyclesByCategory = [];
$originalReportIDs = [];

foreach ($reportBuckets as $bucket) {

    usort($bucket, function ($a, $b) {

        $comparison = strcmp(
            $a["dateReported"],
            $b["dateReported"]
        );

        return $comparison !== 0
            ? $comparison
            : strcmp(
                $a["reportID"],
                $b["reportID"]
            );
    });

    $cycles = [];

    $currentCycle = null;
    $currentResolvedAt = null;

    foreach ($bucket as $report) {

        $startNew =
            $currentCycle === null ||
            (
                $currentResolvedAt !== null &&
                strcmp(
                    $report["dateReported"],
                    $currentResolvedAt
                ) > 0
            );

        if ($startNew) {

            if ($currentCycle !== null) {
                $cycles[] = $currentCycle;
            }

            $currentCycle = [
                "original" => $report,
                "duplicates" => []
            ];

            $originalReportIDs[
                $report["reportID"]
            ] = true;

            $currentResolvedAt =
                $resolvedAt[$report["reportID"]] ?? null;

        } else {

            $currentCycle["duplicates"][] = $report;
        }
    }

    if ($currentCycle !== null) {
        $cycles[] = $currentCycle;
    }

    foreach ($cycles as $cycle) {

        $original = $cycle["original"];

        /*
         * Resolved Originals and their duplicates
         * are excluded from Manage Reports.
         */

        if ($original["statusID"] === "STAT003") {
            continue;
        }

        $catID = $original["categoryID"];

        if (!isset($cyclesByCategory[$catID])) {
            $cyclesByCategory[$catID] = [];
        }

        $cyclesByCategory[$catID][] = [
            "originalID" => $original["reportID"],
            "categoryID" => $catID,
            "categoryName" => $original["categoryName"],
            "locationID" => $original["locationID"],
            "address" => $original["address"],
            "original" => $original,
            "duplicates" => $cycle["duplicates"]
        ];
    }
}

/* =========================================================
   FETCH ALL CATEGORIES
========================================================= */

$stmt = $conn->prepare(
    "SELECT categoryID, categoryName
     FROM categories
     ORDER BY categoryID ASC"
);

$stmt->execute();

$categories = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   FETCH STATUS HISTORY

   Use actual database history only.

   Do not generate artificial Pending,
   In Progress, Overdue, or Resolved entries.
========================================================= */

$historyByReport = [];

$sql = "
    SELECT
        h.historyID,
        h.reportID,
        h.statusID,
        h.remarks,
        h.dateChanged,

        s.statusName,

        u.firstName,
        u.lastName

    FROM reportHistory h

    INNER JOIN reports r
        ON r.reportID = h.reportID

    INNER JOIN stats s
        ON s.statusID = h.statusID

    LEFT JOIN users u
        ON u.userID = h.userID

    WHERE r.barangayID = ?

    ORDER BY
        h.dateChanged ASC,
        h.historyID ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$historyRows = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

foreach ($historyRows as $row) {

    $reportID = $row["reportID"];

    if (!isset($historyByReport[$reportID])) {
        $historyByReport[$reportID] = [];
    }

    $historyByReport[$reportID][] = $row;
}

/* =========================================================
   SELECT REPORTS FOR REGULAR REPORT VIEW

   Resolved reports remain visible here.

   Only Manage Reports excludes closed cycles.
========================================================= */

$selectedReports = [];

foreach ($allReports as $report) {

    if (
        (string) $report["categoryID"] === $categoryID &&
        (string) $report["locationID"] === $locationID
    ) {
        $selectedReports[] = $report;
    }
}

$categoryName = "";
$address = "";

// Use the selected reports when reports exist.
if (!empty($selectedReports)) {
    $categoryName = $selectedReports[0]["categoryName"];
    $address = $selectedReports[0]["address"];
} else {
    // Get the category name even if no reports have been submitted.
    $stmt = $conn->prepare(
        "SELECT categoryName
         FROM categories
         WHERE categoryID = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $categoryID);
    $stmt->execute();

    $categoryResult = $stmt->get_result()->fetch_assoc();

    if ($categoryResult) {
        $categoryName = $categoryResult["categoryName"];
    }

    $stmt->close();

    // Get the selected location's address.
    $stmt = $conn->prepare(
        "SELECT address
         FROM location
         WHERE locationID = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $locationID);
    $stmt->execute();

    $locationResult = $stmt->get_result()->fetch_assoc();

    if ($locationResult) {
        $address = $locationResult["address"];
    }

    $stmt->close();
}


/* Overdue first, then newest reports. */

usort($selectedReports, function ($a, $b) {

    $aOverdue =
        $a["statusID"] === "STAT004" ? 0 : 1;

    $bOverdue =
        $b["statusID"] === "STAT004" ? 0 : 1;

    if ($aOverdue !== $bOverdue) {
        return $aOverdue <=> $bOverdue;
    }

    $comparison = strcmp(
        $b["dateReported"],
        $a["dateReported"]
    );

    return $comparison !== 0
        ? $comparison
        : strcmp(
            $b["reportID"],
            $a["reportID"]
        );
});

$categoryName = $selectedReports[0]["categoryName"];
$address = $selectedReports[0]["address"];

$statusOrder = [
    "Overdue",
    "In Progress",
    "Pending",
    "Resolved"
];

$statuses = array_unique(
    array_column(
        $selectedReports,
        "statusName"
    )
);

$groupStatuses = array_values(
    array_intersect(
        $statusOrder,
        $statuses
    )
);

/* =========================================================
   REUSABLE REPORT CARD
========================================================= */

function rvRenderCard(
    array $report,
    bool $isOriginal = false,
    bool $isManage = false
): void {

    $reportID = $report["reportID"];
    $statusName = $report["statusName"];

    $isOverdue =
        $report["statusID"] === "STAT004";

    $isReadOnlyDuplicate =
        $isManage && !$isOriginal;

    $residentName = trim(
        ($report["firstName"] ?? "") . " " .
        ($report["lastName"] ?? "")
    );

    $timelineInput = rvDate(
        $report["timeline"],
        "Y-m-d\TH:i"
    );

    $timelineDisplay = rvDate(
        $report["timeline"]
    );

    $displayStatus = $isOverdue
        ? rvOverdueLabel($report["timeline"])
        : $statusName;

    $classes = "duplicate-report-card";

    if ($isReadOnlyDuplicate) {
        $classes .= " is-readonly-duplicate";
    }

    if ($isManage && $isOriginal) {
        $classes .= " is-original-report";
    } elseif ($isOverdue) {
        $classes .= " is-overdue";
    }
    ?>

    <article
        class="<?= rvEscape($classes) ?>"

        data-report-id="<?= rvEscape($reportID) ?>"
        data-status-id="<?= rvEscape($report["statusID"]) ?>"
        data-status-name="<?= rvEscape($statusName) ?>"

        data-resident="<?= rvEscape($residentName) ?>"
        data-email="<?= rvEscape($report["email"]) ?>"
        data-contact="<?= rvEscape($report["contactNo"]) ?>"

        data-category="<?= rvEscape($report["categoryName"]) ?>"
        data-location="<?= rvEscape($report["address"]) ?>"
        data-description="<?= rvEscape($report["description"]) ?>"

        data-timeline="<?= rvEscape($timelineInput) ?>"
        data-date="<?= rvEscape($report["dateReported"]) ?>"
        data-image="<?= rvEscape($report["imagePath"]) ?>"

        data-original="<?= $isOriginal ? "1" : "0" ?>"
        data-manage="<?= $isManage ? "1" : "0" ?>"

        <?php if ($isReadOnlyDuplicate): ?>

            aria-disabled="true"
            aria-label="Duplicate report <?= rvEscape($reportID) ?>"

        <?php else: ?>

            tabindex="0"
            role="button"

            aria-label="<?= $isManage
                ? "Manage original report "
                : "View report " ?><?= rvEscape($reportID) ?>"

        <?php endif; ?>
    >

        <div class="duplicate-report-image">

            <?php if (!empty($report["imagePath"])): ?>

                <img
                    src="<?= rvEscape($report["imagePath"]) ?>"
                    alt="Resident report attachment"
                    loading="lazy"
                >

            <?php else: ?>

                <div class="no-report-image">
                    <i class="bi bi-image"></i>
                </div>

            <?php endif; ?>

        </div>

        <div class="duplicate-report-info">

            <?php if ($isManage): ?>

                <span class="manage-report-type">
                    <?= $isOriginal
                        ? "Original Report"
                        : "Duplicate Report" ?>
                </span>

            <?php endif; ?>

            <div class="reported-by">
                Reported by
                <strong><?= rvEscape($residentName) ?></strong>
            </div>

            <div class="report-meta">

                <div>
                    <i class="bi bi-calendar3"></i>

                    <?= rvEscape(
                        rvDate($report["dateReported"])
                    ) ?>

                    <span
                        class="report-card-timeline"
                        <?= $timelineDisplay === ""
                            ? "hidden"
                            : "" ?>
                    >

                        <span aria-hidden="true">→</span>

                        <span class="report-card-timeline-text">
                            <?= rvEscape($timelineDisplay) ?>
                        </span>

                    </span>
                </div>

                <div>
                    <i class="bi bi-card-text"></i>
                    ID: <?= rvEscape($reportID) ?>
                </div>

                <div>
                    <i class="bi bi-geo-alt"></i>
                    <?= rvEscape($report["address"]) ?>
                </div>

            </div>

            <span
                class="report-card-status status-<?= rvEscape(
                    rvStatusClass($statusName)
                ) ?>"
            >

                <?php if ($isOverdue): ?>
                    <i class="bi bi-exclamation-triangle"></i>
                <?php endif; ?>

                <?= rvEscape($displayStatus) ?>

            </span>

            <?php if (!empty($report["description"])): ?>

                <div class="report-description">
                    <?= rvEscape($report["description"]) ?>
                </div>

            <?php endif; ?>

        </div>

        <?php if (!$isReadOnlyDuplicate): ?>

            <span class="report-card-open-hint">
                <i class="bi bi-chevron-right"></i>
            </span>

        <?php endif; ?>

    </article>

    <?php
}
?>

<!-- ======================================================
     REPORT VIEW ROOT
====================================================== -->

<div
    class="report-view-content"
    data-category-id="<?= rvEscape($categoryID) ?>"
    data-location-id="<?= rvEscape($locationID) ?>"
>

    <!-- ==================================================
         SCREEN 1: REGULAR REPORT VIEW
    ================================================== -->

    <section id="reportGroupScreen">

        <header class="report-view-header">

            <div class="report-view-title-section">

                <div class="report-category-icon">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

                <div>

                    <h2><?= rvEscape($categoryName) ?></h2>

                    <p>
                        <i class="bi bi-geo-alt"></i>
                        <?= rvEscape($address) ?>
                    </p>

                </div>

            </div>

        </header>

        <div class="report-summary-manage-row">

            <div
                class="report-summary"
                id="groupStatusBadges"
            >

                <?php foreach ($groupStatuses as $status): ?>

                    <span
                        class="status-badge status-<?= rvEscape(
                            rvStatusClass($status)
                        ) ?>"
                    >
                        <?= rvEscape($status) ?>
                    </span>

                <?php endforeach; ?>

            </div>

            <button
                type="button"
                class="manage-group-reports-btn"
                id="manageGroupReports"
            >
                <i class="bi bi-grid"></i>
                Manage Reports
            </button>

        </div>

        <div
            class="report-tab-content active"
            id="duplicatesTab"
        >

            <div class="duplicate-list">

                <?php if (empty($selectedReports)): ?>

                    <div class="report-empty-state">

                        <div class="report-empty-icon">
                            <i class="bi bi-inbox"></i>
                        </div>

                        <h3>No Submitted Reports</h3>

                        <p>
                            There are currently no submitted reports
                            for this category and location.
                        </p>

                    </div>

                <?php else: ?>

                    <?php foreach ($selectedReports as $report): ?>

                        <?php rvRenderCard($report); ?>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </section>

    <!-- ==================================================
         SCREEN 2: MANAGE REPORT CATEGORIES
    ================================================== -->

    <section
        id="manageCategoriesScreen"
        class="manage-screen"
        hidden
    >

        <button
            type="button"
            class="manage-back-button"
            data-manage-back="group"
        >
            Back to Reports
        </button>

        <h2 class="manage-heading">
            Manage Reports
        </h2>

        <p class="manage-subtitle">
            Select a category to view Original Reports
            and their duplicates.
        </p>

        <div class="manage-category-buttons">

            <?php foreach ($categories as $category): ?>

                <button
                    type="button"
                    class="manage-category-button"
                    data-manage-category="<?= rvEscape(
                        $category["categoryID"]
                    ) ?>"
                >

                    <span>
                        <i class="bi bi-folder2-open"></i>
                        <?= rvEscape($category["categoryName"]) ?>
                    </span>

                    <i class="bi bi-chevron-right"></i>

                </button>

            <?php endforeach; ?>

        </div>

    </section>

    <!-- ==================================================
         SCREEN 3: ORIGINAL AND DUPLICATE REPORTS
    ================================================== -->

    <?php foreach ($categories as $category): ?>

        <?php

        $catID = $category["categoryID"];

        $categoryCycles =
            $cyclesByCategory[$catID] ?? [];

        usort($categoryCycles, function ($a, $b) {

            $comparison = strcmp(
                $b["original"]["dateReported"],
                $a["original"]["dateReported"]
            );

            return $comparison !== 0
                ? $comparison
                : strcmp(
                    $b["originalID"],
                    $a["originalID"]
                );
        });

        ?>

        <section
            class="manage-screen manage-category-screen"
            data-category-screen="<?= rvEscape($catID) ?>"
            hidden
        >

            <button
                type="button"
                class="manage-back-button"
                data-manage-back="categories"
            >
                Back to Categories
            </button>

            <h2 class="manage-heading">
                <?= rvEscape($category["categoryName"]) ?>
            </h2>

            <?php if (!$categoryCycles): ?>

                <p class="manage-empty">
                    No active reports in this category.
                </p>

            <?php endif; ?>

            <?php foreach ($categoryCycles as $cycle): ?>

                <div class="manage-cycle">

                    <h3 class="manage-cycle-heading">

                        <i class="bi bi-geo-alt"></i>

                        <?= rvEscape($cycle["address"]) ?>

                        —

                        <?= rvEscape(
                            date(
                                "F Y",
                                strtotime(
                                    $cycle["original"]["dateReported"]
                                )
                            )
                        ) ?>

                    </h3>

                    <?php
                    rvRenderCard(
                        $cycle["original"],
                        true,
                        true
                    );
                    ?>

                    <div class="manage-duplicates-heading">

                        Duplicate Reports

                        (<?= count($cycle["duplicates"]) ?>)

                    </div>

                    <?php if (!$cycle["duplicates"]): ?>

                        <p class="manage-empty">
                            No duplicate submissions yet.
                        </p>

                    <?php else: ?>

                        <div class="duplicate-list">

                            <?php foreach (
                                $cycle["duplicates"] as $duplicate
                            ): ?>

                                <?php
                                rvRenderCard(
                                    $duplicate,
                                    false,
                                    true
                                );
                                ?>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </section>

    <?php endforeach; ?>

    <!-- ==================================================
         SCREEN 4: MANAGE ORIGINAL REPORT

         No Status History here.
    ================================================== -->

    <?php foreach (
        $cyclesByCategory as $catID => $categoryCycles
    ): ?>

        <?php foreach ($categoryCycles as $cycle): ?>

            <?php

            $original = $cycle["original"];
            $originalID = $original["reportID"];
            $statusID = $original["statusID"];

            $isOverdue =
                $statusID === "STAT004";

            $canSetTimeline = in_array(
                $statusID,
                ["STAT002", "STAT004"],
                true
            );

            ?>

            <section
                class="manage-screen manage-original-screen"

                data-original-screen="<?= rvEscape($originalID) ?>"
                data-parent-category="<?= rvEscape($catID) ?>"

                hidden
            >

                <button
                    type="button"
                    class="manage-back-button"
                    data-manage-back="category"
                    data-category-id="<?= rvEscape($catID) ?>"
                >
                    Back to Original Reports
                </button>

                <h2 class="manage-heading">
                    Manage Original Report
                </h2>

                <?php rvRenderCard($original, true, true); ?>

                <!-- UPDATE REPORT STATUS -->

                <div class="manage-edit-panel">

                    <h3>
                        <i class="bi bi-arrow-repeat"></i>
                        Update Report Status
                    </h3>

                    <div class="manage-field">

                        <label>Report Status</label>

                        <select
                            class="manage-status-select"
                            data-report-id="<?= rvEscape($originalID) ?>"
                        >

                            <option
                                value="STAT001"

                                <?= $statusID === "STAT001"
                                    ? "selected"
                                    : "" ?>

                                <?= $statusID !== "STAT001"
                                    ? "disabled"
                                    : "" ?>
                            >
                                Pending
                            </option>

                            <option
                                value="STAT002"

                                <?= $statusID === "STAT002"
                                    ? "selected"
                                    : "" ?>

                                <?= $statusID !== "STAT001" &&
                                    $statusID !== "STAT002"
                                    ? "disabled"
                                    : "" ?>
                            >
                                In Progress
                            </option>

                            <option value="STAT003">
                                Resolved
                            </option>

                            <?php if ($isOverdue): ?>

                                <option
                                    value="STAT004"
                                    selected
                                >
                                    Overdue
                                </option>

                            <?php endif; ?>

                        </select>

                    </div>

                    <button
                        type="button"
                        class="manage-save-button"
                        data-save-original-status="<?= rvEscape(
                            $originalID
                        ) ?>"
                    >
                        Update Status
                    </button>

                </div>

                <!-- REPORT TIMELINE -->

                <div class="manage-edit-panel">

                    <h3>
                        <i class="bi bi-calendar-check"></i>
                        Report Timeline
                    </h3>

                    <?php if ($canSetTimeline): ?>

                        <form
                            class="manage-timeline-form"
                            data-report-id="<?= rvEscape($originalID) ?>"
                        >

                            <div class="manage-field">

                                <label>
                                    Expected Completion Date and Time
                                </label>

                                <input
                                    type="datetime-local"
                                    name="timeline"

                                    value="<?= rvEscape(
                                        rvDate(
                                            $original["timeline"],
                                            "Y-m-d\TH:i"
                                        )
                                    ) ?>"

                                    required
                                >

                            </div>

                            <button
                                type="submit"
                                class="manage-save-button"
                            >

                                <?= $isOverdue
                                    ? "Save Revised Timeline"
                                    : "Save Timeline" ?>

                            </button>

                        </form>

                    <?php else: ?>

                        <p class="manage-empty">
                            Accept the report by changing its status
                            to In Progress before setting a timeline.
                        </p>

                    <?php endif; ?>

                </div>

            </section>

        <?php endforeach; ?>

    <?php endforeach; ?>

        <!-- ==================================================
         ACTUAL STATUS HISTORY DATA

         Stored in hidden templates so the existing
         drawer JavaScript can display the selected
         report's own history.

         This does not create fake history entries.
    ================================================== -->

    <!-- ======================================================
         REPORT STATUS HISTORY DATA

         Current status comes from reports.statusID.
         Previous status changes come from reportHistory.
    ====================================================== -->

    <div id="reportHistoryTemplates" hidden>

        <?php foreach ($allReports as $historyReport): ?>

            <?php
            $historyReportID = $historyReport["reportID"];

            $currentStatusID = $historyReport["statusID"];
            $currentStatusName = $historyReport["statusName"];

            $entries = $historyByReport[$historyReportID] ?? [];

            $hasCurrentStatusInHistory = false;

            if (!empty($entries)) {
                $lastEntry = end($entries);

                $hasCurrentStatusInHistory =
                    $lastEntry["statusID"] === $currentStatusID;

                reset($entries);
            }
            ?>

            <div
                data-history-report-id="<?= rvEscape(
                    $historyReportID
                ) ?>"
            >

                <?php if (empty($entries)): ?>

                    <!-- CURRENT STATUS FROM reports.statusID -->

                    <div class="manage-history-item">

                        <strong>
                            <span
                                class="status-badge status-<?= rvEscape(
                                    rvStatusClass($currentStatusName)
                                ) ?>"
                            >
                                <?= rvEscape($currentStatusName) ?>
                            </span>
                        </strong>

                        <small>
                            Current Report Status
                        </small>

                    </div>

                <?php else: ?>

                    <!-- ACTUAL STATUS CHANGE HISTORY -->

                    <?php foreach ($entries as $entry): ?>

                        <?php
                        $changedBy = trim(
                            ($entry["firstName"] ?? "") . " " .
                            ($entry["lastName"] ?? "")
                        );

                        if ($changedBy === "") {
                            $changedBy = "User unavailable";
                        }
                        ?>

                        <div class="manage-history-item">

                            <strong>
                                <span
                                    class="status-badge status-<?= rvEscape(
                                        rvStatusClass(
                                            $entry["statusName"]
                                        )
                                    ) ?>"
                                >
                                    <?= rvEscape(
                                        $entry["statusName"]
                                    ) ?>
                                </span>
                            </strong>

                            <small>
                                <i class="bi bi-calendar3"></i>

                                <?= rvEscape(
                                    rvDate($entry["dateChanged"])
                                ) ?>

                                &nbsp;—&nbsp;

                                <i class="bi bi-person"></i>

                                <?= rvEscape($changedBy) ?>
                            </small>

                            <?php if (
                                trim(
                                    (string) ($entry["remarks"] ?? "")
                                ) !== ""
                            ): ?>

                                <p>
                                    <?= rvEscape($entry["remarks"]) ?>
                                </p>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                    <!-- CURRENT STATUS IF NOT IN LATEST HISTORY -->

                    <?php if (!$hasCurrentStatusInHistory): ?>

                        <div class="manage-history-item">

                            <strong>
                                <span
                                    class="status-badge status-<?= rvEscape(
                                        rvStatusClass($currentStatusName)
                                    ) ?>"
                                >
                                    <?= rvEscape($currentStatusName) ?>
                                </span>
                            </strong>

                            <small>
                                Current Report Status
                            </small>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- ==================================================
         SCREEN 5: READ-ONLY REPORT DETAILS

         Status History is displayed here.
    ================================================== -->

    <section
        id="individualReportScreen"
        class="individual-report-screen"
        hidden
    >

        <header class="individual-report-header">

            <button
                type="button"
                id="backToReportList"
                class="back-report-button"
            >

                <i class="bi bi-arrow-left"></i>
                Back to Reports

            </button>

        </header>

        <div class="individual-report-card">

            <!-- REPORT IMAGE -->

            <div
                class="individual-report-image"
                id="detailImageContainer"
                hidden
            >

                <img
                    id="detailImage"
                    src=""
                    alt="Report attachment"
                >

            </div>

            <h2>Report Details</h2>

            <h4>
                <i class="bi bi-file-earmark-text"></i>
                Report Information
            </h4>

            <!-- CATEGORY -->

            <div class="individual-report-field">

                <label>Category</label>

                <p id="detailCategory"></p>

            </div>

            <!-- REPORT ID -->

            <div class="individual-report-field">

                <label>Report ID</label>

                <p id="detailReportID"></p>

            </div>

            <!-- LOCATION -->

            <div class="individual-report-field">

                <label>Location</label>

                <p id="detailLocation"></p>

            </div>

            <!-- DESCRIPTION -->

            <div class="individual-report-field">

                <label>Description</label>

                <p
                    id="detailDescription"
                    class="individual-report-description"
                ></p>

            </div>

            <!-- STATUS -->

            <div class="individual-report-field">

                <label>Status</label>

                <p id="detailStatus"></p>

            </div>

            <!-- DATES -->

            <div class="individual-report-section">

                <div class="individual-report-field">

                    <label>Date Reported</label>

                    <p id="detailDate"></p>

                </div>

                <div
                    class="individual-report-field"
                    id="detailTimelineContainer"
                    hidden
                >

                    <label>
                        Expected Completion / Timeline
                    </label>

                    <p id="detailTimeline"></p>

                </div>

            </div>

            <!-- RESIDENT INFORMATION -->

            <div class="individual-report-section">

                <h4>
                    <i class="bi bi-person"></i>
                    Resident Information
                </h4>

                <div class="individual-report-field">

                    <label>Resident Name</label>

                    <p id="detailResident"></p>

                </div>

                <div class="individual-report-field">

                    <label>Email Address</label>

                    <p id="detailEmail"></p>

                </div>

                <div class="individual-report-field">

                    <label>Contact Number</label>

                    <p id="detailContact"></p>

                </div>

            </div>

            <!-- STATUS HISTORY -->

            <div class="manage-history-panel">

                <h3>
                    <i class="bi bi-clock-history"></i>
                    Report Status History
                </h3>

                <div id="detailStatusHistory"></div>

            </div>

        </div>

    </section>

</div>

