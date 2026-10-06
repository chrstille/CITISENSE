<?php

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$barangayID = $_SESSION["barangay_id"];


/*
|--------------------------------------------------------------------------
| GET BARANGAY NAME
|--------------------------------------------------------------------------
*/

$sql = "SELECT barangayName
        FROM barangay
        WHERE barangayID = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

$barangayName = "Unknown Barangay";

if ($result->num_rows === 1) {
    $barangay = $result->fetch_assoc();
    $barangayName = $barangay["barangayName"];
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| REPORT TABLE
|--------------------------------------------------------------------------
|
| No. of Reports:
| COUNT(reportID)
|
| This counts ALL submitted report records, including duplicates.
|
| Latest Report:
| MAX(dateReported)
|
| The result is grouped by category and location.
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            c.categoryID,
            c.categoryName,

            l.locationID,
            l.address,

            COUNT(r.reportID) AS totalReports,

            MAX(r.dateReported) AS latestReport

        FROM reports r

        INNER JOIN categories c
            ON r.categoryID = c.categoryID

        INNER JOIN location l
            ON r.locationID = l.locationID

        WHERE r.barangayID = ?

        GROUP BY
            c.categoryID,
            c.categoryName,
            l.locationID,
            l.address

        ORDER BY
            latestReport DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

$reportGroups = [];

while ($row = $result->fetch_assoc()) {
    $reportGroups[] = $row;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| TOTAL REPORT SUBMISSIONS
|--------------------------------------------------------------------------
|
| This is the total number of report records submitted
| by residents in this barangay.
|
| Duplicates are included because every reportID is counted.
|--------------------------------------------------------------------------
*/

$sql = "SELECT COUNT(reportID) AS totalReports
        FROM reports
        WHERE barangayID = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$totalReports = (int) ($row["totalReports"] ?? 0);

$stmt->close();


/*
|--------------------------------------------------------------------------
| STATUS COUNTS FOR PIE CHART
|--------------------------------------------------------------------------
|
| Only:
| Pending
| In Progress
| Resolved
|
| These counts are separate from the table's No. of Reports.
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            s.statusName,
            COUNT(r.reportID) AS reportCount

        FROM stats s

        LEFT JOIN reports r
            ON r.statusID = s.statusID
            AND r.barangayID = ?

        WHERE s.statusName IN
            ('Pending', 'In Progress', 'Resolved')

        GROUP BY
            s.statusID,
            s.statusName

        ORDER BY
            FIELD(
                s.statusName,
                'Pending',
                'In Progress',
                'Resolved'
            )";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

$statusCounts = [
    "Pending" => 0,
    "In Progress" => 0,
    "Resolved" => 0
];

while ($row = $result->fetch_assoc()) {

    $statusName = $row["statusName"];

    if (isset($statusCounts[$statusName])) {
        $statusCounts[$statusName] =
            (int) $row["reportCount"];
    }
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| STATUS PERCENTAGES
|--------------------------------------------------------------------------
*/

if ($totalReports > 0) {

    $pendingPercent =
        round(
            ($statusCounts["Pending"] / $totalReports) * 100
        );

    $inProgressPercent =
        round(
            ($statusCounts["In Progress"] / $totalReports) * 100
        );

    $resolvedPercent =
        round(
            ($statusCounts["Resolved"] / $totalReports) * 100
        );

} else {

    $pendingPercent = 0;
    $inProgressPercent = 0;
    $resolvedPercent = 0;
}


/*
|--------------------------------------------------------------------------
| RECENT REPORT
|--------------------------------------------------------------------------
|
| This gets the newest actual resident submission
| in the barangay.
|--------------------------------------------------------------------------
*/

$sql = "SELECT
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
            ON r.categoryID = c.categoryID

        INNER JOIN location l
            ON r.locationID = l.locationID

        INNER JOIN users u
            ON r.userID = u.userID

        WHERE r.barangayID = ?

        ORDER BY r.dateReported DESC

        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

$recentReport = null;

if ($result->num_rows === 1) {
    $recentReport = $result->fetch_assoc();
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| UNREAD ADMIN NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$sql = "SELECT COUNT(*) AS unreadNotifications
        FROM notification
        WHERE userID = ?
        AND isRead = FALSE";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $_SESSION["admin_id"]);
$stmt->execute();

$result = $stmt->get_result();

$notificationData = $result->fetch_assoc();

$unreadNotifications =
    (int) ($notificationData["unreadNotifications"] ?? 0);

$stmt->close();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>CitiSense - Reports</title>


    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/reports.css">
    <link rel="stylesheet" href="../css/reportView.css">

</head>


<body>


<?php include __DIR__ . "/partial/sidebar.php"; ?>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">


    <header class="reports-header">

        <div>

            <h1>
                Reports
            </h1>

            <p>
                View and manage all reports submitted by the community.
            </p>

        </div>

    </header>



    <!-- =====================================================
         FILTER BAR
    ====================================================== -->

    <section class="report-filters">


        <div class="filter-control">

            <i class="bi bi-calendar3"></i>

            <span>
                All Dates
            </span>

            <i class="bi bi-chevron-down"></i>

        </div>


        <div class="filter-control">

            <span>
                All Statuses
            </span>

            <i class="bi bi-chevron-down"></i>

        </div>


        <div class="search-control">

            <i class="bi bi-search"></i>

            <input
                type="text"
                id="reportSearch"
                placeholder="Search reports..."
            >

        </div>


        <button
            type="button"
            class="filter-button"
        >

            <i class="bi bi-sliders"></i>

            Filters

        </button>


    </section>



    <!-- =====================================================
         REPORTS LAYOUT
    ====================================================== -->

    <section class="reports-layout">


        <!-- =================================================
             REPORT TABLE
        ================================================== -->

        <div class="reports-list-card">


            <div class="reports-card-header">

                <h2>

                    All Reports

                    <span>
                        (<?php echo $totalReports; ?>)
                    </span>

                </h2>

            </div>



            <div class="reports-table-wrapper">

                <table class="reports-table">


                    <thead>

                        <tr>

                            <th>
                                Issue
                            </th>

                            <th>
                                Location
                            </th>

                            <th>
                                No. of Reports
                            </th>

                            <th>
                                Latest Report
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>



                    <tbody>


                    <?php if (count($reportGroups) > 0): ?>


                        <?php foreach ($reportGroups as $report): ?>


                            <tr
                                class="report-row"
                                data-search="
                                    <?php
                                    echo htmlspecialchars(
                                        strtolower(
                                            $report["categoryName"]
                                            . " "
                                            . ($report["address"] ?? "")
                                        )
                                    );
                                    ?>
                                "
                            >


                                <!-- ISSUE -->

                                <td>

                                    <div class="issue-cell">

                                        <div class="issue-icon">

                                            <i class="bi bi-exclamation-circle"></i>

                                        </div>


                                        <span>

                                            <?php
                                            echo htmlspecialchars(
                                                $report["categoryName"]
                                            );
                                            ?>

                                        </span>

                                    </div>

                                </td>



                                <!-- LOCATION -->

                                <td>

                                    <div class="location-cell">

                                        <i class="bi bi-geo-alt"></i>


                                        <span>

                                            <?php

                                            echo htmlspecialchars(
                                                $report["address"]
                                                ?: "Location unavailable"
                                            );

                                            ?>

                                        </span>

                                    </div>

                                </td>



                                <!-- NO. OF REPORTS -->

                                <td>

                                    <div class="report-count">

                                        <strong>

                                            <?php
                                            echo (int)
                                                $report["totalReports"];
                                            ?>

                                        </strong>

                                        <span>
                                            reports
                                        </span>

                                    </div>

                                </td>



                                <!-- LATEST REPORT -->

                                <td>

                                    <div class="latest-report">

                                        <?php

                                        $latestTimestamp =
                                            strtotime(
                                                $report["latestReport"]
                                            );

                                        ?>

                                        <strong>

                                            <?php

                                            echo date(
                                                "M d, Y",
                                                $latestTimestamp
                                            );

                                            ?>

                                        </strong>


                                        <span>

                                            <?php

                                            echo date(
                                                "g:i A",
                                                $latestTimestamp
                                            );

                                            ?>

                                        </span>

                                    </div>

                                </td>



                                <!-- ACTION -->

                                <td>

                                    <button
                                        type="button"
                                        class="view-button view-report-btn"
                                        title="View reports"
                                        data-category-id="<?php echo htmlspecialchars($report["categoryID"]); ?>"
                                        data-location-id="<?php echo htmlspecialchars($report["locationID"]); ?>"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="5"
                                class="empty-table"
                            >

                                <i class="bi bi-inbox"></i>

                                <strong>
                                    No reports yet
                                </strong>

                                <span>
                                    Resident submissions will appear here.
                                </span>

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>


                </table>

            </div>



            <div class="reports-card-footer">

                <span>

                    Showing
                    <?php echo count($reportGroups); ?>
                    issue group<?php echo count($reportGroups) === 1 ? "" : "s"; ?>

                </span>


                <div class="pagination">

                    <button
                        type="button"
                        disabled
                    >
                        <i class="bi bi-chevron-left"></i>
                    </button>


                    <button
                        type="button"
                        class="active"
                    >
                        1
                    </button>


                    <button
                        type="button"
                        disabled
                    >
                        <i class="bi bi-chevron-right"></i>
                    </button>

                </div>

            </div>


        </div>



        <!-- =================================================
             RIGHT COLUMN
        ================================================== -->

        <div class="reports-right-column">


            <!-- REPORTS OVERVIEW -->

            <div class="overview-card">


                <div class="side-card-header">

                    <h2>
                        Reports Overview
                    </h2>


                    <span>
                        All Time
                    </span>

                </div>



                <div class="overview-content">


                    <div
                        class="donut-chart"
                        style="
                            --pending: <?php echo $pendingPercent; ?>%;
                            --progress: <?php echo $inProgressPercent; ?>%;
                            --resolved: <?php echo $resolvedPercent; ?>%;
                        "
                    >

                        <div class="donut-center">

                            <strong>
                                <?php echo $totalReports; ?>
                            </strong>

                            <span>
                                Total
                            </span>

                        </div>

                    </div>



                    <div class="overview-legend">


                        <div class="legend-item">

                            <span class="legend-dot pending"></span>

                            <div>

                                <strong>
                                    Pending
                                </strong>

                                <small>

                                    <?php
                                    echo $statusCounts["Pending"];
                                    ?>

                                    (<?php
                                    echo $pendingPercent;
                                    ?>%)

                                </small>

                            </div>

                        </div>



                        <div class="legend-item">

                            <span class="legend-dot progress"></span>

                            <div>

                                <strong>
                                    In Progress
                                </strong>

                                <small>

                                    <?php
                                    echo $statusCounts["In Progress"];
                                    ?>

                                    (<?php
                                    echo $inProgressPercent;
                                    ?>%)

                                </small>

                            </div>

                        </div>



                        <div class="legend-item">

                            <span class="legend-dot resolved"></span>

                            <div>

                                <strong>
                                    Resolved
                                </strong>

                                <small>

                                    <?php
                                    echo $statusCounts["Resolved"];
                                    ?>

                                    (<?php
                                    echo $resolvedPercent;
                                    ?>%)

                                </small>

                            </div>

                        </div>


                    </div>

                </div>

            </div>

            <!-- QUICK ACTIONS -->

            <div class="quick-actions-card">

                <h2>
                    Quick Actions
                </h2>


                <div class="quick-actions">


                    <button type="button">

                        <i class="bi bi-download"></i>

                        <span>
                            Export Reports
                        </span>

                    </button>


                    <button type="button">

                        <i class="bi bi-file-earmark-text"></i>

                        <span>
                            Generate Report
                        </span>

                    </button>


                    <button type="button">

                        <i class="bi bi-grid"></i>

                        <span>
                            Manage Reports
                        </span>

                    </button>


                </div>

            </div>


        </div>


    </section>

</main>



<script>

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById("reportSearch");


searchInput.addEventListener(
    "input",
    function () {

        const searchValue =
            this.value.toLowerCase().trim();


        const rows =
            document.querySelectorAll(
                ".report-row"
            );


        rows.forEach(function (row) {

            const searchableText =
                row.getAttribute(
                    "data-search"
                ) || "";


            row.style.display =
                searchableText.includes(
                    searchValue
                )
                    ? ""
                    : "none";

        });

    }
);

</script>

<div class="report-drawer-overlay" id="reportDrawerOverlay"></div>

<aside class="report-drawer" id="reportDrawer">

    <div
        id="reportDrawerContent"
        class="report-drawer-loading"
    >
        <div class="drawer-spinner"></div>

        <p>
            Loading report details...
        </p>
    </div>

</aside>

<script>

const viewButtons =
    document.querySelectorAll(".view-report-btn");

const reportDrawer =
    document.getElementById("reportDrawer");

const reportDrawerOverlay =
    document.getElementById("reportDrawerOverlay");

const reportDrawerContent =
    document.getElementById("reportDrawerContent");


viewButtons.forEach(function (button) {

    button.addEventListener("click", function () {

        const categoryID =
            this.dataset.categoryId;

        const locationID =
            this.dataset.locationId;


        reportDrawer.classList.add("open");

        reportDrawerOverlay.classList.add("open");


        reportDrawerContent.innerHTML = `
            <div class="drawer-spinner"></div>
            <p>Loading report details...</p>
        `;


        fetch(
            "reportView.php?categoryID="
            + encodeURIComponent(categoryID)
            + "&locationID="
            + encodeURIComponent(locationID)
        )

        .then(function (response) {

            if (!response.ok) {
                throw new Error(
                    "Unable to load report details."
                );
            }

            return response.text();

        })

        .then(function (html) {

            reportDrawerContent.innerHTML = html;

            initializeReportDrawer();

        })

        .catch(function (error) {

            reportDrawerContent.innerHTML = `
                <div class="report-view-error">

                    <i class="bi bi-exclamation-circle"></i>

                    <p>
                        ${error.message}
                    </p>

                </div>
            `;

        });

    });

});


function closeReportDrawer() {

    reportDrawer.classList.remove("open");

    reportDrawerOverlay.classList.remove("open");

}


reportDrawerOverlay.addEventListener(
    "click",
    closeReportDrawer
);


function initializeReportDrawer() {

    const closeButton =
        document.getElementById("closeReportView");

    if (closeButton) {

        closeButton.addEventListener(
            "click",
            closeReportDrawer
        );

    }


    const tabs =
        document.querySelectorAll(".report-tab");


    tabs.forEach(function (tab) {

        tab.addEventListener("click", function () {

            const tabName =
                this.dataset.tab;


            tabs.forEach(function (item) {

                item.classList.remove("active");

            });


            document
                .querySelectorAll(".report-tab-content")
                .forEach(function (content) {

                    content.classList.remove("active");

                });


            this.classList.add("active");


            const selectedContent =
                document.getElementById(
                    tabName === "duplicates"
                        ? "duplicatesTab"
                        : "detailsTab"
                );


            if (selectedContent) {

                selectedContent.classList.add("active");

            }

        });

    });

}


document.addEventListener(
    "keydown",
    function (event) {

        if (event.key === "Escape") {

            closeReportDrawer();

        }

    }
);

</script>

</body>

</html>