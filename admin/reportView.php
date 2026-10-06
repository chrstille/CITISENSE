<?php

session_start();


// ======================================================
// ADMIN SESSION CHECK
// ======================================================

if (
    !isset($_SESSION["admin_id"]) ||
    !isset($_SESSION["barangay_id"])
) {
    http_response_code(401);
    echo "Unauthorized.";
    exit;
}


// ======================================================
// DATABASE CONNECTION
// ======================================================

require_once __DIR__ . "/../config/database.php";


// ======================================================
// GET LOGGED-IN ADMIN'S BARANGAY
// ======================================================
//
// IMPORTANT:
// We do NOT accept barangayID from the URL.
//
// The barangay comes from the authenticated session.
// This prevents an admin from manually changing a URL
// to access another barangay's reports.
// ======================================================

$barangayID = $_SESSION["barangay_id"];


// ======================================================
// GET SELECTED REPORT GROUP
// ======================================================

$categoryID = trim($_GET["categoryID"] ?? "");
$locationID = trim($_GET["locationID"] ?? "");


if ($categoryID === "" || $locationID === "") {

    http_response_code(400);

    echo "Invalid report selection.";

    exit;
}


// ======================================================
// GET REPORT SUBMISSIONS
// ======================================================
//
// A report group is determined by:
//
//     categoryID + locationID + barangayID
//
// Every matching row in reports represents a submitted
// report for that issue/location group.
//
// No report information is hard-coded here.
// ======================================================

$sql = "
    SELECT

        r.reportID,
        r.userID,
        r.categoryID,
        r.statusID,
        r.locationID,

        r.title,
        r.description,
        r.dateReported,
        r.timeline,

        c.categoryName,

        s.statusName,

        l.address,
        l.latitude,
        l.longitude,

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
        ON r.categoryID = c.categoryID

    INNER JOIN stats s
        ON r.statusID = s.statusID

    INNER JOIN location l
        ON r.locationID = l.locationID

    INNER JOIN users u
        ON r.userID = u.userID

    WHERE r.barangayID = ?
      AND r.categoryID = ?
      AND r.locationID = ?

    ORDER BY r.dateReported DESC
";


$stmt = $conn->prepare($sql);


if (!$stmt) {

    http_response_code(500);

    echo "Unable to prepare report query.";

    exit;
}


$stmt->bind_param(
    "sss",
    $barangayID,
    $categoryID,
    $locationID
);


$stmt->execute();


$result = $stmt->get_result();


$reports = [];


while ($row = $result->fetch_assoc()) {

    $reports[] = $row;

}


$stmt->close();


// ======================================================
// CHECK IF REPORTS EXIST
// ======================================================

if (count($reports) === 0) {

    http_response_code(404);

    echo "No reports found.";

    exit;
}


// ======================================================
// GROUP INFORMATION
// ======================================================

$firstReport = $reports[0];

$categoryName = $firstReport["categoryName"];

$address = $firstReport["address"];

$totalReports = count($reports);


// ======================================================
// DETERMINE GROUP STATUS
// ======================================================
//
// We do not hard-code the status names.
// The status values come from the stats table.
//
// If every submission has the same status,
// display that status.
//
// Otherwise, display a UI-level "Mixed" state.
// ======================================================

$statusNames = [];

foreach ($reports as $report) {

    $statusNames[] = $report["statusName"];

}

$statusNames = array_unique($statusNames);


if (count($statusNames) === 1) {

    $groupStatus = reset($statusNames);

} else {

    $groupStatus = "Mixed";

}


// ======================================================
// GET AVAILABLE STATUSES FROM DATABASE
// ======================================================
//
// These are used by the status dropdown.
//
// No Pending/In Progress/Resolved values are hard-coded.
// ======================================================

$statusList = [];

$statusQuery = "
    SELECT
        statusID,
        statusName

    FROM stats

    ORDER BY statusID
";


$statusResult = $conn->query($statusQuery);


if ($statusResult) {

    while ($statusRow = $statusResult->fetch_assoc()) {

        $statusList[] = $statusRow;

    }

}


// ======================================================
// HELPER: STATUS CSS CLASS
// ======================================================
//
// The status name itself still comes from the database.
// This only converts its text into a safe CSS class.
// ======================================================

function getStatusClass($statusName)
{
    $statusName = strtolower(trim($statusName));

    $statusName = preg_replace(
        "/[^a-z0-9]+/",
        "-",
        $statusName
    );

    return trim($statusName, "-");
}


// ======================================================
// HELPER: FORMAT DATE
// ======================================================

function formatReportDate($date)
{
    if (!$date) {
        return "";
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return htmlspecialchars($date);
    }

    return date("M j, Y", $timestamp);
}


// ======================================================
// HELPER: FORMAT TIME
// ======================================================

function formatReportTime($date)
{
    if (!$date) {
        return "";
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return "";
    }

    return date("g:i A", $timestamp);
}

?>


<!-- =====================================================
     REPORT VIEW CONTENT
====================================================== -->

<div
    class="report-view-content"
    data-category-id="<?php echo htmlspecialchars($categoryID); ?>"
    data-location-id="<?php echo htmlspecialchars($locationID); ?>"
>


    <!-- =================================================
         HEADER
    ================================================== -->

    <div class="report-view-header">


        <div class="report-view-title-section">


            <!-- Generic icon.
                 The actual category comes from MySQL. -->

            <div class="report-category-icon">

                <i class="bi bi-file-earmark-text"></i>

            </div>


            <div>

                <h2>

                    <?php echo htmlspecialchars($categoryName); ?>

                </h2>


                <p>

                    <i class="bi bi-geo-alt"></i>

                    <?php echo htmlspecialchars($address); ?>

                </p>

            </div>


        </div>


        <!-- CLOSE -->

        <button
            type="button"
            class="report-view-close"
            id="closeReportView"
            aria-label="Close report view"
        >

            <i class="bi bi-x-lg"></i>

        </button>


    </div>



    <!-- =================================================
         SUMMARY
    ================================================== -->

    <div class="report-summary">


        <strong>

            <?php echo $totalReports; ?>

            <?php echo $totalReports === 1 ? "report" : "reports"; ?>

        </strong>


        <?php if ($groupStatus !== "Mixed"): ?>

            <span
                class="status-badge status-<?php echo htmlspecialchars(
                    getStatusClass($groupStatus)
                ); ?>"
            >

                <?php echo htmlspecialchars($groupStatus); ?>

            </span>

        <?php else: ?>

            <span class="status-badge status-mixed">

                Mixed

            </span>

        <?php endif; ?>


    </div>



    <!-- =================================================
         TABS
    ================================================== -->

    <div class="report-view-tabs">


        <button
            type="button"
            class="report-tab active"
            data-tab="duplicates"
        >

            Reports
            (<?php echo $totalReports; ?>)

        </button>


        <button
            type="button"
            class="report-tab"
            data-tab="details"
        >

            Report Details

        </button>


    </div>



    <!-- =================================================
         REPORTS TAB
    ================================================== -->

    <div
        class="report-tab-content active"
        id="duplicatesTab"
    >


        <p class="duplicate-description">

            These reports are grouped under the same
            issue and location.

        </p>



        <!-- =============================================
             STATUS CONTROL
        ============================================== -->

        <div class="duplicate-actions">


            <label for="bulkStatus">

                Status for all reports

            </label>


            <div class="bulk-status-row">


                <select id="bulkStatus">


                    <?php foreach ($statusList as $status): ?>


                        <option
                            value="<?php echo htmlspecialchars(
                                $status["statusID"]
                            ); ?>"
                            <?php
                            if (
                                $groupStatus !== "Mixed" &&
                                $status["statusName"] === $groupStatus
                            ) {
                                echo "selected";
                            }
                            ?>
                        >

                            <?php echo htmlspecialchars(
                                $status["statusName"]
                            ); ?>

                        </option>


                    <?php endforeach; ?>


                </select>


                <button
                    type="button"
                    class="resolve-btn"
                    id="markResolvedBtn"
                >

                    <i class="bi bi-check-lg"></i>

                    Mark as Resolved

                </button>


            </div>


        </div>



        <!-- =================================================
             REPORT SUBMISSIONS
        ================================================== -->

        <div class="duplicate-list">


            <?php foreach ($reports as $report): ?>


                <article
                    class="duplicate-report-card"
                    data-report-id="<?php echo htmlspecialchars(
                        $report["reportID"]
                    ); ?>"
                >


                    <!-- =====================================
                         IMAGE
                    ====================================== -->

                    <div class="duplicate-report-image">


                        <?php if (!empty($report["imagePath"])): ?>


                            <img
                                src="<?php echo htmlspecialchars(
                                    $report["imagePath"]
                                ); ?>"
                                alt="Report image"
                            >


                        <?php else: ?>


                            <div class="no-report-image">

                                <i class="bi bi-image"></i>

                            </div>


                        <?php endif; ?>


                    </div>



                    <!-- =====================================
                         REPORT INFORMATION
                    ====================================== -->

                    <div class="duplicate-report-info">


                        <!-- REPORTER -->

                        <div class="reported-by">

                            Reported by

                            <strong>

                                <?php echo htmlspecialchars(
                                    $report["firstName"]
                                    . " "
                                    . $report["lastName"]
                                ); ?>

                            </strong>

                        </div>



                        <!-- DATE -->

                        <div class="report-meta">


                            <div>

                                <i class="bi bi-calendar3"></i>

                                <?php echo htmlspecialchars(
                                    formatReportDate(
                                        $report["dateReported"]
                                    )
                                ); ?>

                                •

                                <?php echo htmlspecialchars(
                                    formatReportTime(
                                        $report["dateReported"]
                                    )
                                ); ?>

                            </div>



                            <!-- REPORT ID -->

                            <div>

                                <i class="bi bi-card-text"></i>

                                ID:

                                <?php echo htmlspecialchars(
                                    $report["reportID"]
                                ); ?>

                            </div>



                            <!-- LOCATION -->

                            <div>

                                <i class="bi bi-geo-alt"></i>

                                <?php echo htmlspecialchars(
                                    $report["address"]
                                ); ?>

                            </div>


                        </div>



                        <!-- DESCRIPTION -->

                        <?php if (
                            !empty($report["description"])
                        ): ?>


                            <div class="report-description">

                                <strong>
                                    Description:
                                </strong>

                                <?php echo htmlspecialchars(
                                    $report["description"]
                                ); ?>

                            </div>


                        <?php endif; ?>


                    </div>


                </article>


            <?php endforeach; ?>


        </div>


    </div>



    <!-- =================================================
         REPORT DETAILS TAB
    ================================================== -->

    <div
        class="report-tab-content"
        id="detailsTab"
    >


        <div class="details-grid">


            <!-- CATEGORY -->

            <div class="detail-item">

                <span>
                    Category
                </span>

                <strong>

                    <?php echo htmlspecialchars(
                        $categoryName
                    ); ?>

                </strong>

            </div>



            <!-- LOCATION -->

            <div class="detail-item">

                <span>
                    Location
                </span>

                <strong>

                    <?php echo htmlspecialchars(
                        $address
                    ); ?>

                </strong>

            </div>



            <!-- TOTAL -->

            <div class="detail-item">

                <span>
                    Total Reports
                </span>

                <strong>

                    <?php echo $totalReports; ?>

                </strong>

            </div>



            <!-- LATEST REPORT -->

            <div class="detail-item">

                <span>
                    Latest Report
                </span>

                <strong>

                    <?php echo htmlspecialchars(
                        formatReportDate(
                            $reports[0]["dateReported"]
                        )
                    ); ?>

                    •

                    <?php echo htmlspecialchars(
                        formatReportTime(
                            $reports[0]["dateReported"]
                        )
                    ); ?>

                </strong>

            </div>



            <!-- LATITUDE -->

            <div class="detail-item">

                <span>
                    Latitude
                </span>

                <strong>

                    <?php echo htmlspecialchars(
                        $firstReport["latitude"]
                    ); ?>

                </strong>

            </div>



            <!-- LONGITUDE -->

            <div class="detail-item">

                <span>
                    Longitude
                </span>

                <strong>

                    <?php echo htmlspecialchars(
                        $firstReport["longitude"]
                    ); ?>

                </strong>

            </div>


        </div>


    </div>


</div>