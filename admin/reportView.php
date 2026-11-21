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
        TIMESTAMPDIFF(SECOND, r.dateReported, NOW()) AS secondsAgo,

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

    /* Declined reports are closed and never part of a reporting cycle */
    if (strtolower(trim((string) $report["statusName"])) === "declined") {
        continue;
    }

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
   SELECT PENDING REPORTS FOR REGULAR REPORT VIEW

   - Only the selected category
   - Only Pending reports (STAT001)
   - All locations in the admin's barangay
   - Newest submissions first
========================================================= */

$selectedReports = [];

foreach ($allReports as $report) {

    if (
        (string) $report["categoryID"] === $categoryID &&
        (string) $report["statusID"] === "STAT001"
    ) {
        $selectedReports[] = $report;
    }
}

/* Get the selected category's name */

$categoryName = "";

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

/* Category-wide view, not location-specific */

$address = "All locations";

/* Display the newest Pending reports first */

usort($selectedReports, function ($a, $b) {

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

/* Only Pending is applicable to Screen 1 */

$groupStatuses = empty($selectedReports)
    ? []
    : ["Pending"];



/* =========================================================
   ALL IMAGES PER REPORT (for the gallery in report details)
========================================================= */

$imagesByReport = [];

$stmt = $conn->prepare(
    "SELECT ri.reportID, ri.imagePath
       FROM reportImages ri
       INNER JOIN reports r ON r.reportID = ri.reportID
      WHERE r.barangayID = ?
      ORDER BY ri.uploadedAt ASC"
);

if ($stmt) {
    $stmt->bind_param("s", $barangayID);
    $stmt->execute();
    $imageResult = $stmt->get_result();

    while ($row = $imageResult->fetch_assoc()) {
        if (trim((string) $row["imagePath"]) !== "") {
            $imagesByReport[$row["reportID"]][] = $row["imagePath"];
        }
    }

    $stmt->close();
}

/* =========================================================
   SMALL VIEW HELPERS
========================================================= */

/* Uses the database clock (secondsAgo) so it's right even if PHP and MySQL time zones differ */
function rvTimeAgo($value, $secondsAgo = null): string
{
    $stamp = $value ? strtotime((string) $value) : false;

    if ($stamp === false) {
        return "";
    }

    $seconds = $secondsAgo !== null
        ? max(0, (int) $secondsAgo)
        : max(0, time() - $stamp);

    if ($seconds < 60) return "Just now";
    if ($seconds < 3600) return floor($seconds / 60) . "m ago";
    if ($seconds < 86400) return floor($seconds / 3600) . "h ago";
    if ($seconds < 604800) return floor($seconds / 86400) . "d ago";

    return date("M j, Y", $stamp);
}

function rvCategoryIcon($name): string
{
    $name = strtolower((string) $name);

    if (str_contains($name, "waste") || str_contains($name, "garbage")) return "bi-trash3";
    if (str_contains($name, "pothole") || str_contains($name, "road")) return "bi-cone-striped";
    if (str_contains($name, "drain") || str_contains($name, "flood")) return "bi-water";

    return "bi-exclamation-diamond";
}

function rvPill(array $report): string
{
    $isOverdue = $report["statusID"] === "STAT004";
    $label = $isOverdue
        ? rvOverdueLabel($report["timeline"])
        : $report["statusName"];

    return '<span class="pill pill-' . rvEscape(rvStatusClass($report["statusName"])) . '">'
        . rvEscape($label)
        . '</span>';
}

/* =========================================================
   REUSABLE REPORT CARD

   $variant:
     "list"      Pending list (opens read-only details)
     "original"  Original report inside Manage (opens Manage screen)
     "duplicate" Duplicate inside Manage (opens read-only details)
     "summary"   Static summary at the top of the Manage Original screen
========================================================= */

function rvRenderCard(array $report, string $variant = "list"): void
{
    global $imagesByReport;

    $reportID = $report["reportID"];

    $isOriginal = in_array($variant, ["original", "summary"], true);
    $isManage = $variant !== "list";
    $isStatic = $variant === "summary";

    $residentName = trim(
        ($report["firstName"] ?? "") . " " .
        ($report["lastName"] ?? "")
    );

    if ($residentName === "") {
        $residentName = "Resident";
    }

    $images = $imagesByReport[$reportID] ?? [];

    if (!$images && !empty($report["imagePath"])) {
        $images = [$report["imagePath"]];
    }

    $classes = "duplicate-report-card rv-card rv-card-" . $variant;

    if ($report["statusID"] === "STAT004") {
        $classes .= " is-overdue";
    }
    ?>

    <article
        class="<?= rvEscape($classes) ?>"

        data-report-id="<?= rvEscape($reportID) ?>"
        data-status-id="<?= rvEscape($report["statusID"]) ?>"
        data-status-name="<?= rvEscape($report["statusName"]) ?>"

        data-resident="<?= rvEscape($residentName) ?>"
        data-email="<?= rvEscape($report["email"]) ?>"
        data-contact="<?= rvEscape($report["contactNo"]) ?>"

        data-category="<?= rvEscape($report["categoryName"]) ?>"
        data-title="<?= rvEscape($report["title"]) ?>"
        data-location="<?= rvEscape($report["address"]) ?>"
        data-description="<?= rvEscape($report["description"]) ?>"

        data-timeline="<?= rvEscape(rvDate($report["timeline"], "Y-m-d\TH:i")) ?>"
        data-date="<?= rvEscape($report["dateReported"]) ?>"
        data-image="<?= rvEscape($images[0] ?? "") ?>"
        data-images="<?= rvEscape(json_encode($images)) ?>"

        data-original="<?= $isOriginal ? "1" : "0" ?>"
        data-manage="<?= $isManage ? "1" : "0" ?>"
        data-variant="<?= rvEscape($variant) ?>"

        <?php if (!$isStatic): ?>
            tabindex="0"
            role="button"
            aria-label="<?= $variant === "original"
                ? "Manage original report "
                : "View report " ?><?= rvEscape($reportID) ?>"
        <?php endif; ?>
    >

        <div class="rv-card-thumb">
            <?php if ($images): ?>
                <img
                    src="<?= rvEscape($images[0]) ?>"
                    alt="Photo for report <?= rvEscape($reportID) ?>"
                    loading="lazy"
                    onerror="this.parentElement.classList.add('is-broken'); this.remove();"
                >
                <?php if (count($images) > 1): ?>
                    <span class="rv-thumb-count"><i class="bi bi-images"></i> <?= count($images) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span class="rv-thumb-empty">
                    <i class="bi bi-image"></i>
                    <small>No photo</small>
                </span>
            <?php endif; ?>
        </div>

        <div class="rv-card-body">

            <div class="rv-card-top">
                <?php if ($variant === "original" || $variant === "summary"): ?>
                    <span class="rv-tag rv-tag-original"><i class="bi bi-star-fill"></i> Original</span>
                <?php elseif ($variant === "duplicate"): ?>
                    <span class="rv-tag rv-tag-duplicate"><i class="bi bi-files"></i> Duplicate</span>
                <?php endif; ?>

                <?= rvPill($report) ?>

                <span class="rv-card-time" title="<?= rvEscape(rvDate($report["dateReported"])) ?>">
                    <?= rvEscape(rvTimeAgo($report["dateReported"], $report["secondsAgo"] ?? null)) ?>
                </span>
            </div>

            <h4 class="rv-card-title">
                <?= rvEscape(
                    trim((string) $report["title"]) !== ""
                        ? $report["title"]
                        : $report["categoryName"] . " report"
                ) ?>
            </h4>

            <?php if (trim((string) $report["description"]) !== "" && $variant !== "duplicate"): ?>
                <p class="rv-card-desc"><?= rvEscape($report["description"]) ?></p>
            <?php endif; ?>

            <div class="rv-card-meta">
                <span><i class="bi bi-geo-alt"></i> <?= rvEscape($report["address"]) ?></span>
                <span><i class="bi bi-person"></i> <?= rvEscape($residentName) ?></span>
                <span class="rv-card-id"><i class="bi bi-hash"></i><?= rvEscape($reportID) ?></span>
            </div>

            <?php if (rvDate($report["timeline"]) !== "" && $variant !== "duplicate"): ?>
                <div class="rv-card-deadline">
                    <i class="bi bi-flag"></i>
                    Target: <?= rvEscape(rvDate($report["timeline"])) ?>
                </div>
            <?php endif; ?>

            <?php if ($variant === "list" && $report["statusID"] === "STAT001"): ?>
                <div class="rv-review-actions">
                    <button
                        type="button"
                        class="rv-review-btn rv-review-accept"
                        data-review-action="accept"
                        data-review-report="<?= rvEscape($reportID) ?>"
                    >
                        <i class="bi bi-check-lg"></i> Accept
                    </button>

                    <button
                        type="button"
                        class="rv-review-btn rv-review-decline"
                        data-review-action="decline"
                        data-review-report="<?= rvEscape($reportID) ?>"
                    >
                        <i class="bi bi-x-lg"></i> Decline
                    </button>
                </div>
            <?php endif; ?>

        </div>

        <?php if (!$isStatic): ?>
            <span class="rv-card-action">
                <?php if ($variant === "original"): ?>
                    Manage <i class="bi bi-chevron-right"></i>
                <?php else: ?>
                    <i class="bi bi-chevron-right"></i>
                <?php endif; ?>
            </span>
        <?php endif; ?>

    </article>

    <?php
}

/* Count of active cases for the selected category (for the Manage button) */
$activeCaseCount = count($cyclesByCategory[$categoryID] ?? []);
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
         SCREEN 1: PENDING REPORTS
    ================================================== -->

    <section id="reportGroupScreen" class="rv-screen">

        <header class="rv-header">
            <div class="rv-header-title">
                <span class="rv-header-icon">
                    <i class="bi <?= rvCategoryIcon($categoryName) ?>"></i>
                </span>

                <div>
                    <small>Pending reports</small>
                    <h2><?= rvEscape($categoryName) ?></h2>
                </div>
            </div>

            <button type="button" class="rv-icon-btn" data-close-report-view aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <div class="rv-toolbar">
            <p>
                <strong><?= count($selectedReports) ?></strong>
                new report<?= count($selectedReports) === 1 ? "" : "s" ?> waiting for review.
                Accepted reports move to <b>In Progress</b>.
            </p>

            <button
                type="button"
                class="btn btn-primary rv-manage-btn"
                id="manageGroupReports"
            >
                <i class="bi bi-sliders"></i>
                Manage Reports
                <?php if ($activeCaseCount > 0): ?>
                    <span class="rv-btn-count"><?= $activeCaseCount ?></span>
                <?php endif; ?>
            </button>
        </div>

        <div class="rv-list duplicate-list">

            <?php if (empty($selectedReports)): ?>

                <div class="rv-empty">
                    <span class="rv-empty-icon"><i class="bi bi-check2-circle"></i></span>
                    <h3>No pending reports</h3>
                    <p>Every <?= rvEscape(strtolower($categoryName)) ?> report has been reviewed. New submissions will appear here.</p>
                </div>

            <?php else: ?>

                <?php foreach ($selectedReports as $report): ?>
                    <?php rvRenderCard($report, "list"); ?>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

    <!-- ==================================================
         SCREEN 2: MANAGE REPORT CATEGORIES (kept for navigation)
    ================================================== -->

    <section id="manageCategoriesScreen" class="rv-screen manage-screen" hidden>

        <header class="rv-header">
            <button type="button" class="rv-back" data-manage-back="group">
                <i class="bi bi-arrow-left"></i> Pending reports
            </button>

            <button type="button" class="rv-icon-btn" data-close-report-view aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <div class="rv-intro">
            <h2>Manage Reports</h2>
            <p>Select a category to see its original reports and their duplicates.</p>
        </div>

        <div class="rv-category-list">
            <?php foreach ($categories as $category): ?>
                <button
                    type="button"
                    class="rv-category-btn"
                    data-manage-category="<?= rvEscape($category["categoryID"]) ?>"
                >
                    <span class="rv-header-icon"><i class="bi <?= rvCategoryIcon($category["categoryName"]) ?>"></i></span>
                    <span><?= rvEscape($category["categoryName"]) ?></span>
                    <small><?= count($cyclesByCategory[$category["categoryID"]] ?? []) ?> active</small>
                    <i class="bi bi-chevron-right"></i>
                </button>
            <?php endforeach; ?>
        </div>

    </section>

    <!-- ==================================================
         SCREEN 3: ORIGINAL + DUPLICATE REPORTS (per category)
    ================================================== -->

    <?php foreach ($categories as $category): ?>

        <?php
        $catID = $category["categoryID"];
        $categoryCycles = $cyclesByCategory[$catID] ?? [];

        usort($categoryCycles, function ($a, $b) {
            $comparison = strcmp(
                $b["original"]["dateReported"],
                $a["original"]["dateReported"]
            );

            return $comparison !== 0
                ? $comparison
                : strcmp($b["originalID"], $a["originalID"]);
        });

        $duplicateTotal = array_sum(array_map(
            static fn($cycle) => count($cycle["duplicates"]),
            $categoryCycles
        ));
        ?>

        <section
            class="rv-screen manage-screen manage-category-screen"
            data-category-screen="<?= rvEscape($catID) ?>"
            hidden
        >

            <header class="rv-header">
                <button type="button" class="rv-back" data-manage-back="group">
                    <i class="bi bi-arrow-left"></i> Pending reports
                </button>

                <button type="button" class="rv-icon-btn" data-close-report-view aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="rv-intro">
                <small>Manage reports</small>
                <h2><?= rvEscape($category["categoryName"]) ?></h2>

                <div class="rv-intro-stats">
                    <span><b><?= count($categoryCycles) ?></b> active case<?= count($categoryCycles) === 1 ? "" : "s" ?></span>
                    <span><b><?= $duplicateTotal ?></b> duplicate<?= $duplicateTotal === 1 ? "" : "s" ?></span>
                </div>
            </div>

            <div class="rv-explainer">
                <i class="bi bi-info-circle-fill"></i>
                <p>
                    Reports about the <b>same issue at the same location</b> are grouped into one case.
                    The first submission is the <b>Original</b>; later ones are <b>Duplicates</b>.
                    Update the Original and its duplicates follow automatically.
                </p>
            </div>

            <?php if (!$categoryCycles): ?>

                <div class="rv-empty">
                    <span class="rv-empty-icon"><i class="bi bi-inbox"></i></span>
                    <h3>No active cases</h3>
                    <p>There are no open reports in this category right now.</p>
                </div>

            <?php endif; ?>

            <div class="rv-cases">

                <?php foreach ($categoryCycles as $cycle): ?>

                    <?php $dupCount = count($cycle["duplicates"]); ?>

                    <div class="rv-case manage-cycle">

                        <div class="rv-case-head">
                            <span class="rv-case-location">
                                <i class="bi bi-geo-alt-fill"></i>
                                <?= rvEscape($cycle["address"]) ?>
                            </span>

                            <span class="rv-case-meta">
                                Since <?= rvEscape(date("M j, Y", strtotime($cycle["original"]["dateReported"]))) ?>
                                · <?= 1 + $dupCount ?> submission<?= $dupCount === 0 ? "" : "s" ?>
                            </span>
                        </div>

                        <?php rvRenderCard($cycle["original"], "original"); ?>

                        <?php if ($dupCount > 0): ?>

                            <details class="rv-duplicates">
                                <summary>
                                    <span class="rv-dup-avatars">
                                        <?php foreach (array_slice($cycle["duplicates"], 0, 3) as $dup): ?>
                                            <span><?= rvEscape(mb_strtoupper(mb_substr((string) ($dup["firstName"] ?? "R"), 0, 1))) ?></span>
                                        <?php endforeach; ?>
                                    </span>

                                    <span class="rv-dup-label">
                                        <?= $dupCount ?> duplicate report<?= $dupCount === 1 ? "" : "s" ?>
                                    </span>

                                    <i class="bi bi-chevron-down rv-dup-chevron"></i>
                                </summary>

                                <div class="rv-dup-list duplicate-list">
                                    <?php foreach ($cycle["duplicates"] as $duplicate): ?>
                                        <?php rvRenderCard($duplicate, "duplicate"); ?>
                                    <?php endforeach; ?>
                                </div>
                            </details>

                        <?php else: ?>

                            <p class="rv-no-dups"><i class="bi bi-check2"></i> No duplicate submissions</p>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endforeach; ?>

    <!-- ==================================================
         SCREEN 4: MANAGE ORIGINAL REPORT
    ================================================== -->

    <?php foreach ($cyclesByCategory as $catID => $categoryCycles): ?>

        <?php foreach ($categoryCycles as $cycle): ?>

            <?php
            $original = $cycle["original"];
            $originalID = $original["reportID"];
            $statusID = $original["statusID"];
            $isOverdue = $statusID === "STAT004";
            $dupCount = count($cycle["duplicates"]);

            $canSetTimeline = in_array($statusID, ["STAT002", "STAT004"], true);

            /* Step indicator */
            $steps = [
                "STAT001" => "Pending",
                "STAT002" => "In Progress",
                "STAT003" => "Resolved",
            ];
            $stepIndex = ["STAT001" => 0, "STAT002" => 1, "STAT004" => 1, "STAT003" => 2][$statusID] ?? 0;
            ?>

            <section
                class="rv-screen manage-screen manage-original-screen"
                data-original-screen="<?= rvEscape($originalID) ?>"
                data-parent-category="<?= rvEscape($catID) ?>"
                hidden
            >

                <header class="rv-header">
                    <button
                        type="button"
                        class="rv-back"
                        data-manage-back="category"
                        data-category-id="<?= rvEscape($catID) ?>"
                    >
                        <i class="bi bi-arrow-left"></i> All cases
                    </button>

                    <button type="button" class="rv-icon-btn" data-close-report-view aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <div class="rv-intro">
                    <small>Manage original report</small>
                    <h2><?= rvEscape($original["categoryName"]) ?> · <?= rvEscape($original["address"]) ?></h2>
                </div>

                <?php rvRenderCard($original, "summary"); ?>

                <!-- Progress steps -->
                <ol class="rv-steps<?= $isOverdue ? " is-overdue" : "" ?>">
                    <?php $i = 0; foreach ($steps as $label): ?>
                        <li class="<?= $i < $stepIndex ? "done" : ($i === $stepIndex ? "current" : "") ?>">
                            <span class="rv-step-dot">
                                <?php if ($i < $stepIndex): ?><i class="bi bi-check-lg"></i><?php else: ?><?= $i + 1 ?><?php endif; ?>
                            </span>
                            <span class="rv-step-label">
                                <?= $i === 1 && $isOverdue ? "Overdue" : $label ?>
                            </span>
                        </li>
                    <?php $i++; endforeach; ?>
                </ol>

                <!-- UPDATE REPORT STATUS -->
                <div class="rv-panel manage-edit-panel">

                    <h3><i class="bi bi-arrow-repeat"></i> Update status</h3>

                    <div class="rv-field">
                        <label for="status-<?= rvEscape($originalID) ?>">New status</label>

                        <select
                            id="status-<?= rvEscape($originalID) ?>"
                            class="manage-status-select"
                            data-report-id="<?= rvEscape($originalID) ?>"
                        >
                            <option value="STAT001"
                                <?= $statusID === "STAT001" ? "selected" : "" ?>
                                <?= $statusID !== "STAT001" ? "disabled" : "" ?>
                            >Pending</option>

                            <option value="STAT002"
                                <?= $statusID === "STAT002" ? "selected" : "" ?>
                                <?= $statusID !== "STAT001" && $statusID !== "STAT002" ? "disabled" : "" ?>
                            >In Progress</option>

                            <option value="STAT003">Resolved</option>

                            <?php if ($isOverdue): ?>
                                <option value="STAT004" selected>Overdue</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="rv-field">
                        <label for="remarks-<?= rvEscape($originalID) ?>">
                            Remarks <span>(optional, shared with the resident)</span>
                        </label>
                        <textarea
                            id="remarks-<?= rvEscape($originalID) ?>"
                            class="manage-remarks-input"
                            rows="2"
                            maxlength="1000"
                            placeholder="e.g. Crew scheduled for Monday morning"
                        ></textarea>
                    </div>

                    <?php if ($dupCount > 0): ?>
                        <p class="rv-hint">
                            <i class="bi bi-files"></i>
                            This also updates its <?= $dupCount ?> duplicate report<?= $dupCount === 1 ? "" : "s" ?>.
                        </p>
                    <?php endif; ?>

                    <button
                        type="button"
                        class="btn btn-primary manage-save-button"
                        data-save-original-status="<?= rvEscape($originalID) ?>"
                    >
                        <i class="bi bi-check2"></i>
                        Update status
                    </button>

                </div>

                <!-- REPORT TIMELINE -->
                <div class="rv-panel manage-edit-panel">

                    <h3><i class="bi bi-calendar-check"></i> Target completion</h3>

                    <?php if ($canSetTimeline): ?>

                        <form class="manage-timeline-form" data-report-id="<?= rvEscape($originalID) ?>">

                            <div class="rv-field">
                                <label for="timeline-<?= rvEscape($originalID) ?>">Expected completion date and time</label>

                                <input
                                    id="timeline-<?= rvEscape($originalID) ?>"
                                    type="datetime-local"
                                    name="timeline"
                                    value="<?= rvEscape(rvDate($original["timeline"], "Y-m-d\TH:i")) ?>"
                                    required
                                >
                            </div>

                            <button type="submit" class="btn btn-light manage-save-button">
                                <i class="bi bi-calendar2-check"></i>
                                <?= $isOverdue ? "Save revised timeline" : "Save timeline" ?>
                            </button>

                        </form>

                    <?php else: ?>

                        <p class="rv-locked">
                            <i class="bi bi-lock"></i>
                            Set the status to <b>In Progress</b> first, then you can set a target completion date.
                        </p>

                    <?php endif; ?>

                </div>

            </section>

        <?php endforeach; ?>

    <?php endforeach; ?>

    <!-- ======================================================
         REPORT STATUS HISTORY DATA (hidden templates)

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
                $hasCurrentStatusInHistory = $lastEntry["statusID"] === $currentStatusID;
                reset($entries);
            }
            ?>

            <div data-history-report-id="<?= rvEscape($historyReportID) ?>">

                <?php foreach ($entries as $entry): ?>

                    <?php
                    $changedBy = trim(($entry["firstName"] ?? "") . " " . ($entry["lastName"] ?? ""));
                    if ($changedBy === "") {
                        $changedBy = "User unavailable";
                    }
                    ?>

                    <div class="manage-history-item rv-history-item rv-history-<?= rvEscape(rvStatusClass($entry["statusName"])) ?>">
                        <span class="rv-history-dot"></span>

                        <div>
                            <strong><?= rvEscape($entry["statusName"]) ?></strong>
                            <small>
                                <?= rvEscape(rvDate($entry["dateChanged"])) ?> · by <?= rvEscape($changedBy) ?>
                            </small>

                            <?php if (trim((string) ($entry["remarks"] ?? "")) !== ""): ?>
                                <p><?= rvEscape($entry["remarks"]) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php endforeach; ?>

                <?php if (!$hasCurrentStatusInHistory): ?>

                    <div class="manage-history-item rv-history-item rv-history-<?= rvEscape(rvStatusClass($currentStatusName)) ?> is-current">
                        <span class="rv-history-dot"></span>

                        <div>
                            <strong><?= rvEscape($currentStatusName) ?></strong>
                            <small>Current status</small>
                        </div>
                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- ==================================================
         SCREEN 5: READ-ONLY REPORT DETAILS
    ================================================== -->

    <section id="individualReportScreen" class="rv-screen individual-report-screen" hidden>

        <header class="rv-header">
            <button type="button" id="backToReportList" class="rv-back">
                <i class="bi bi-arrow-left"></i> Back
            </button>

            <button type="button" class="rv-icon-btn" data-close-report-view aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <!-- Photos -->
        <div class="rv-gallery" id="detailImageContainer">
            <a class="rv-gallery-main" id="detailImageLink" href="#" target="_blank" rel="noopener">
                <img id="detailImage" src="" alt="Report photo">
                <span class="rv-gallery-zoom"><i class="bi bi-arrows-fullscreen"></i> Open full size</span>
            </a>

            <div class="rv-gallery-empty" id="detailImageEmpty" hidden>
                <i class="bi bi-image"></i>
                <span>No photo was attached to this report</span>
            </div>

            <div class="rv-gallery-thumbs" id="detailImageThumbs"></div>
        </div>

        <div class="rv-detail-head">
            <div class="rv-detail-tags">
                <span class="rv-tag" id="detailCategory"></span>
                <span id="detailStatus"></span>
            </div>

            <h2 id="detailTitle">Report details</h2>
        </div>

        <!-- Key facts -->
        <dl class="rv-facts">
            <div>
                <dt><i class="bi bi-hash"></i> Report ID</dt>
                <dd id="detailReportID"></dd>
            </div>

            <div>
                <dt><i class="bi bi-calendar3"></i> Date reported</dt>
                <dd id="detailDate"></dd>
            </div>

            <div class="rv-facts-wide">
                <dt><i class="bi bi-geo-alt"></i> Location</dt>
                <dd id="detailLocation"></dd>
            </div>

            <div class="rv-facts-wide" id="detailTimelineContainer" hidden>
                <dt><i class="bi bi-flag"></i> Target completion</dt>
                <dd id="detailTimeline"></dd>
            </div>
        </dl>

        <!-- Description -->
        <div class="rv-panel">
            <h3><i class="bi bi-chat-left-text"></i> Description</h3>
            <p id="detailDescription" class="rv-description"></p>
        </div>

        <!-- Resident -->
        <div class="rv-panel">
            <h3><i class="bi bi-person"></i> Reported by</h3>

            <div class="rv-resident">
                <span class="rv-resident-avatar" id="detailResidentInitial">R</span>

                <div>
                    <strong id="detailResident"></strong>

                    <div class="rv-resident-contacts">
                        <span><i class="bi bi-envelope"></i> <span id="detailEmail"></span></span>
                        <span><i class="bi bi-telephone"></i> <span id="detailContact"></span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accept / decline (Pending reports only) -->
        <div class="rv-panel rv-review-panel" id="detailReviewPanel" hidden>
            <h3><i class="bi bi-clipboard-check"></i> Review this report</h3>
            <p class="rv-review-note">Accepting moves it to <b>In Progress</b>. Declining asks you for a reason that is sent to the resident.</p>

            <div class="rv-review-actions">
                <button type="button" class="rv-review-btn rv-review-accept" data-review-action="accept" id="detailAccept">
                    <i class="bi bi-check-lg"></i> Accept
                </button>

                <button type="button" class="rv-review-btn rv-review-decline" data-review-action="decline" id="detailDecline">
                    <i class="bi bi-x-lg"></i> Decline
                </button>
            </div>
        </div>

        <!-- Status history -->
        <div class="rv-panel manage-history-panel" id="detailStatusHistoryPanel" hidden>
            <h3><i class="bi bi-clock-history"></i> Status history</h3>
            <div class="rv-history" id="detailStatusHistory"></div>
        </div>

    </section>

</div>