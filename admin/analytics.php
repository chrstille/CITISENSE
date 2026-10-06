<?php

session_start();


// ==========================================================
// ADMIN SESSION CHECK
// ==========================================================

if (!isset($_SESSION["admin_id"], $_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}


// ==========================================================
// DATABASE CONNECTION
// ==========================================================

require_once __DIR__ . "/../config/database.php";


// ==========================================================
// LOGGED-IN ADMIN BARANGAY
// ==========================================================

$barangayID = $_SESSION["barangay_id"];


// ==========================================================
// GET BARANGAY NAME
// ==========================================================

$barangayName = "Barangay";

$stmt = $conn->prepare("
    SELECT barangayName
    FROM barangay
    WHERE barangayID = ?
    LIMIT 1
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $barangay = $result->fetch_assoc();

    $barangayName = $barangay["barangayName"];
}

$stmt->close();


// ==========================================================
// GET AVAILABLE CATEGORIES
// ==========================================================

$categories = [];

$stmt = $conn->prepare("
    SELECT
        c.categoryID,
        c.categoryName

    FROM categories c

    LEFT JOIN reports r
        ON r.categoryID = c.categoryID
        AND r.barangayID = ?

    GROUP BY
        c.categoryID,
        c.categoryName

    ORDER BY
        c.categoryID ASC
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $categories[] = [
        "categoryID"   => $row["categoryID"],
        "categoryName" => $row["categoryName"]
    ];

}

$stmt->close();


// ==========================================================
// GET STATUSES
// ==========================================================
//
// Your database uses the stats table.
// No report status is hardcoded here.
// The statuses displayed come from the database.
//
// ==========================================================

$statuses = [];

$stmt = $conn->prepare("
    SELECT
        statusID,
        statusName

    FROM stats

    ORDER BY statusID ASC
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $statuses[] = $row;
}

$stmt->close();


// ==========================================================
// GET AVAILABLE DATE RANGE
// ==========================================================

$databaseStartDate = "";
$databaseEndDate = "";

$stmt = $conn->prepare("
    SELECT
        MIN(DATE(dateReported)) AS startDate,
        MAX(DATE(dateReported)) AS endDate

    FROM reports

    WHERE barangayID = ?
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $databaseStartDate = $row["startDate"] ?? "";
    $databaseEndDate = $row["endDate"] ?? "";
}

$stmt->close();


// ==========================================================
// FILTER VALUES
// ==========================================================

$startDate = trim($_GET["start_date"] ?? "");
$endDate = trim($_GET["end_date"] ?? "");
$selectedCategory = trim($_GET["categoryID"] ?? "");
$selectedStatus = trim($_GET["statusID"] ?? "");


// ==========================================================
// DEFAULT DATE RANGE
// ==========================================================
//
// If no date filter was selected,
// use the actual earliest and latest
// report dates from the database.
//
// ==========================================================

if ($startDate === "" && $databaseStartDate !== "") {
    $startDate = $databaseStartDate;
}

if ($endDate === "" && $databaseEndDate !== "") {
    $endDate = $databaseEndDate;
}


// ==========================================================
// BUILD REPORT FILTER
// ==========================================================

$where = [
    "r.barangayID = ?"
];

$types = "s";
$params = [
    $barangayID
];


// DATE FILTER

if ($startDate !== "") {

    $where[] = "DATE(r.dateReported) >= ?";

    $types .= "s";

    $params[] = $startDate;
}


if ($endDate !== "") {

    $where[] = "DATE(r.dateReported) <= ?";

    $types .= "s";

    $params[] = $endDate;
}


// CATEGORY FILTER

if ($selectedCategory !== "") {

    $where[] = "r.categoryID = ?";

    $types .= "s";

    $params[] = $selectedCategory;
}


// STATUS FILTER

if ($selectedStatus !== "") {

    $where[] = "r.statusID = ?";

    $types .= "s";

    $params[] = $selectedStatus;
}


$whereSQL = implode(" AND ", $where);


// ==========================================================
// HELPER FOR BINDING DYNAMIC PARAMETERS
// ==========================================================

function bindDynamicParameters($stmt, $types, &$params)
{
    if ($types === "") {
        return;
    }

    $bindValues = [];

    $bindValues[] = $types;

    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }

    call_user_func_array(
        [$stmt, "bind_param"],
        $bindValues
    );
}


// ==========================================================
// SUMMARY TOTAL REPORTS
// ==========================================================
//
// COUNT(reportID) counts every submitted record.
// Duplicate submissions are therefore included.
//
// ==========================================================

$totalReports = 0;

$sql = "
    SELECT COUNT(r.reportID) AS totalReports

    FROM reports r

    WHERE $whereSQL
";

$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $types,
    $params
);

$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $totalReports =
        (int) ($row["totalReports"] ?? 0);
}

$stmt->close();


// ==========================================================
// SUMMARY STATUS COUNTS
// ==========================================================

$statusSummary = [];

$sql = "
    SELECT
        s.statusID,
        s.statusName,
        COUNT(r.reportID) AS reportCount

    FROM stats s

    LEFT JOIN reports r
        ON r.statusID = s.statusID
        AND r.barangayID = ?
";

$statusWhere = [];

$statusTypes = "s";

$statusParams = [
    $barangayID
];


// Date filters for summary

if ($startDate !== "") {

    $statusWhere[] =
        "DATE(r.dateReported) >= ?";

    $statusTypes .= "s";

    $statusParams[] = $startDate;
}

if ($endDate !== "") {

    $statusWhere[] =
        "DATE(r.dateReported) <= ?";

    $statusTypes .= "s";

    $statusParams[] = $endDate;
}

if ($selectedCategory !== "") {

    $statusWhere[] =
        "r.categoryID = ?";

    $statusTypes .= "s";

    $statusParams[] = $selectedCategory;
}

if ($selectedStatus !== "") {

    $statusWhere[] =
        "r.statusID = ?";

    $statusTypes .= "s";

    $statusParams[] = $selectedStatus;
}


if (count($statusWhere) > 0) {

    $sql .= "
        AND " . implode(
            " AND ",
            $statusWhere
        );
}

$sql .= "
    GROUP BY
        s.statusID,
        s.statusName

    ORDER BY s.statusID ASC
";


$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $statusTypes,
    $statusParams
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $statusSummary[] = [

        "statusID" =>
            $row["statusID"],

        "statusName" =>
            $row["statusName"],

        "reportCount" =>
            (int) $row["reportCount"]

    ];
}

$stmt->close();


// ==========================================================
// REPORTS BY CATEGORY
// ==========================================================

$categorySummary = [];

$sql = "
    SELECT
        c.categoryID,
        c.categoryName,
        COUNT(r.reportID) AS reportCount

    FROM categories c

    INNER JOIN reports r
        ON r.categoryID = c.categoryID

    WHERE $whereSQL

    GROUP BY
        c.categoryID,
        c.categoryName

    ORDER BY reportCount DESC
";

$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $types,
    $params
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $categorySummary[] = [

        "categoryID" =>
            $row["categoryID"],

        "categoryName" =>
            $row["categoryName"],

        "reportCount" =>
            (int) $row["reportCount"]

    ];
}

$stmt->close();


// ==========================================================
// REPORTS OVER TIME
// ==========================================================
//
// Every reportID is counted.
// Duplicate submissions are included.
//
// ==========================================================

$dailyReports = [];

$sql = "
    SELECT
        DATE(r.dateReported) AS reportDate,
        s.statusID,
        s.statusName,
        COUNT(r.reportID) AS reportCount

    FROM reports r

    INNER JOIN stats s
        ON r.statusID = s.statusID

    WHERE $whereSQL

    GROUP BY
        DATE(r.dateReported),
        s.statusID,
        s.statusName

    ORDER BY
        reportDate ASC
";


$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $types,
    $params
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $date = $row["reportDate"];

    if (!isset($dailyReports[$date])) {

        $dailyReports[$date] = [];

    }

    $dailyReports[$date][
        $row["statusName"]
    ] = (int) $row["reportCount"];
}

$stmt->close();


// ==========================================================
// CREATE CHART DATA
// ==========================================================

$chartLabels = [];

$chartTotal = [];


// Build dates from actual database result

foreach ($dailyReports as $date => $statusData) {

    $chartLabels[] = $date;

    $dailyTotal = 0;

    foreach ($statusData as $count) {

        $dailyTotal += (int) $count;

    }

    $chartTotal[] = $dailyTotal;
}


// ==========================================================
// STATUS DATA FOR CHART
// ==========================================================

$chartStatusData = [];

foreach ($statuses as $status) {

    $statusName = $status["statusName"];

    $values = [];

    foreach ($dailyReports as $date => $statusData) {

        $values[] =
            (int) (
                $statusData[$statusName]
                ?? 0
            );
    }

    $chartStatusData[] = [

        "statusID" =>
            $status["statusID"],

        "statusName" =>
            $statusName,

        "values" =>
            $values

    ];
}


// ==========================================================
// NOTIFICATION COUNT
// ==========================================================

$unreadNotifications = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS unreadNotifications

    FROM notification

    WHERE userID = ?

    AND isRead = FALSE
");

$stmt->bind_param(
    "s",
    $_SESSION["admin_id"]
);

$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $unreadNotifications =
        (int) (
            $row["unreadNotifications"]
            ?? 0
        );
}

$stmt->close();


// ==========================================================
// JSON DATA
// ==========================================================

$chartLabelsJSON =
    json_encode(
        $chartLabels,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

$chartTotalJSON =
    json_encode(
        $chartTotal,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

$chartStatusJSON =
    json_encode(
        $chartStatusData,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

$categoryChartJSON =
    json_encode(
        $categorySummary,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        CitiSense - Analytics
    </title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Chart.js -->

    <script
        src="https://cdn.jsdelivr.net/npm/chart.js">
    </script>


    <!-- Existing dashboard styles -->

    <link
        rel="stylesheet"
        href="../css/dashboard.css"
    >


    <!-- Analytics styles -->

    <link
        rel="stylesheet"
        href="../css/analytics.css"
    >

</head>


<body>


<div class="admin-layout">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <?php include __DIR__ . "/partial/sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- =================================================
             HEADER
        ================================================== -->

        <header class="analytics-header">


            <div>

                <h1>
                    Analytics
                </h1>

                <p>
                    Track trends and performance over time.
                </p>

                <small class="barangay-label">

                    <i class="bi bi-geo-alt"></i>

                    <?php
                    echo htmlspecialchars(
                        $barangayName
                    );
                    ?>

                </small>

            </div>

        </header>



        <!-- =================================================
             FILTERS
        ================================================== -->

        <form
            class="analytics-filters"
            method="GET"
        >


            <div class="analytics-filter">

                <i class="bi bi-calendar3"></i>

                <input
                    type="date"
                    name="start_date"
                    value="<?php
                        echo htmlspecialchars(
                            $startDate
                        );
                    ?>"
                >

                <span>
                    -
                </span>

                <input
                    type="date"
                    name="end_date"
                    value="<?php
                        echo htmlspecialchars(
                            $endDate
                        );
                    ?>"
                >

            </div>

            <div class="analytics-filter">

                <i class="bi bi-tags"></i>

                <select
                    name="categoryID"
                >

                    <option value="">
                        All Categories
                    </option>


                    <?php foreach ($categories as $category): ?>

                        <option
                            value="<?php
                                echo htmlspecialchars(
                                    $category["categoryID"]
                                );
                            ?>"
                            <?php
                            echo
                                $selectedCategory
                                === $category["categoryID"]
                                    ? "selected"
                                    : "";
                            ?>
                        >

                            <?php
                            echo htmlspecialchars(
                                $category["categoryName"]
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>



            <div class="analytics-filter">

                <i class="bi bi-filter"></i>

                <select
                    name="statusID"
                >

                    <option value="">
                        All Statuses
                    </option>


                    <?php foreach ($statuses as $status): ?>

                        <?php
                        // Do not display rejected
                        // if it exists in an older database.
                        if (
                            strtolower(
                                trim(
                                    $status["statusName"]
                                )
                            ) === "rejected"
                        ) {
                            continue;
                        }
                        ?>

                        <option
                            value="<?php
                                echo htmlspecialchars(
                                    $status["statusID"]
                                );
                            ?>"
                            <?php
                            echo
                                $selectedStatus
                                === $status["statusID"]
                                    ? "selected"
                                    : "";
                            ?>
                        >

                            <?php
                            echo htmlspecialchars(
                                $status["statusName"]
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>



            <button
                type="submit"
                class="analytics-filter-button"
            >

                <i class="bi bi-sliders"></i>

                Apply Filters

            </button>


            <a
                href="analytics.php"
                class="analytics-reset-button"
            >

                Reset

            </a>


        </form>



        <!-- =================================================
             SUMMARY CARDS
        ================================================== -->

        <section class="analytics-summary">


            <!-- TOTAL -->

            <div class="analytics-summary-card">

                <div class="summary-icon purple">

                    <i class="bi bi-file-earmark-text"></i>

                </div>

                <div>

                    <strong>
                        <?php
                        echo number_format(
                            $totalReports
                        );
                        ?>
                    </strong>

                    <span>
                        Total Reports
                    </span>

                </div>

            </div>



            <!-- STATUS CARDS -->

            <?php foreach ($statusSummary as $status): ?>

                <?php

                $statusName =
                    $status["statusName"];

                $statusClass =
                    strtolower(
                        str_replace(
                            " ",
                            "-",
                            $statusName
                        )
                    );

                ?>

                <div
                    class="analytics-summary-card"
                >

                    <div
                        class="
                            summary-icon
                            <?php
                            echo htmlspecialchars(
                                $statusClass
                            );
                            ?>
                        "
                    >

                        <?php if (
                            stripos(
                                $statusName,
                                "pending"
                            ) !== false
                        ): ?>

                            <i class="bi bi-clock"></i>

                        <?php elseif (
                            stripos(
                                $statusName,
                                "progress"
                            ) !== false
                        ): ?>

                            <i class="bi bi-circle"></i>

                        <?php elseif (
                            stripos(
                                $statusName,
                                "resolved"
                            ) !== false
                        ): ?>

                            <i class="bi bi-check-circle"></i>

                        <?php else: ?>

                            <i class="bi bi-bar-chart"></i>

                        <?php endif; ?>

                    </div>


                    <div>

                        <strong>

                            <?php
                            echo number_format(
                                $status["reportCount"]
                            );
                            ?>

                        </strong>

                        <span>

                            <?php
                            echo htmlspecialchars(
                                $statusName
                            );
                            ?>

                        </span>

                    </div>

                </div>

            <?php endforeach; ?>


        </section>



        <!-- =================================================
             CHARTS
        ================================================== -->

        <section class="analytics-chart-grid">


            <!-- =================================================
                 REPORTS OVER TIME
            ================================================== -->

            <div class="analytics-card reports-over-time-card">


                <div class="analytics-card-header">

                    <div>

                        <h2>
                            Reports Over Time
                        </h2>

                        <p>
                            Daily report activity based on resident submissions.
                        </p>

                    </div>


                    <span class="chart-period">

                        Daily

                    </span>

                </div>


                <div class="chart-container">

                    <?php if (count($chartLabels) > 0): ?>

                        <canvas
                            id="reportsOverTimeChart"
                        ></canvas>

                    <?php else: ?>

                        <div class="analytics-empty">

                            <i class="bi bi-bar-chart"></i>

                            <strong>
                                No report data yet
                            </strong>

                            <span>
                                Reports will appear here once residents submit reports.
                            </span>

                        </div>

                    <?php endif; ?>

                </div>


            </div>



            <!-- =================================================
                 REPORTS BY CATEGORY
            ================================================== -->

            <div class="analytics-card category-card">


                <div class="analytics-card-header">

                    <div>

                        <h2>
                            Reports by Category
                        </h2>

                        <p>
                            Distribution of submitted reports.
                        </p>

                    </div>


                    <span class="chart-period">

                        Current Filter

                    </span>

                </div>


                <div class="category-chart-container">


                    <?php if (
                        count($categorySummary) > 0
                    ): ?>

                        <div class="category-donut-wrapper">

                            <canvas
                                id="categoryChart"
                            ></canvas>


                            <div class="category-donut-center">

                                <strong>
                                    <?php
                                    echo number_format(
                                        $totalReports
                                    );
                                    ?>
                                </strong>

                                <span>
                                    Total
                                </span>

                            </div>

                        </div>


                        <div
                            id="categoryLegend"
                            class="category-legend"
                        ></div>


                    <?php else: ?>

                        <div class="analytics-empty">

                            <i class="bi bi-pie-chart"></i>

                            <strong>
                                No category data yet
                            </strong>

                            <span>
                                Category statistics will appear here.
                            </span>

                        </div>

                    <?php endif; ?>


                </div>


            </div>


        </section>

    </main>

</div>



<script>


// ==========================================================
// DATABASE DATA PASSED FROM PHP
// ==========================================================

const chartLabels =
    <?php echo $chartLabelsJSON ?: "[]"; ?>;

const chartTotal =
    <?php echo $chartTotalJSON ?: "[]"; ?>;

const chartStatusData =
    <?php echo $chartStatusJSON ?: "[]"; ?>;

const categoryData =
    <?php echo $categoryChartJSON ?: "[]"; ?>;


// ==========================================================
// STATUS COLORS
// ==========================================================
//
// These are only visual colors.
// They are NOT report data.
//
// ==========================================================

function getStatusColor(statusName)
{

    const name =
        statusName.toLowerCase();


    if (name.includes("pending")) {

        return "#ff8a00";

    }


    if (name.includes("progress")) {

        return "#146df5";

    }


    if (name.includes("resolved")) {

        return "#16b86a";

    }


    return "#6c63ff";
}


// ==========================================================
// REPORTS OVER TIME CHART
// ==========================================================

const reportsCanvas =
    document.getElementById(
        "reportsOverTimeChart"
    );


if (reportsCanvas) {

    const datasets = [];


    // TOTAL LINE

    datasets.push({

        label: "Total",

        data: chartTotal,

        borderColor: "#6c3df4",

        backgroundColor:
            "rgba(108, 61, 244, 0.08)",

        borderWidth: 3,

        tension: 0.35,

        pointRadius: 3,

        pointHoverRadius: 5,

        fill: false

    });


    // STATUS LINES

    chartStatusData.forEach(
        function (status)
        {

            datasets.push({

                label:
                    status.statusName,

                data:
                    status.values,

                borderColor:
                    getStatusColor(
                        status.statusName
                    ),

                backgroundColor:
                    getStatusColor(
                        status.statusName
                    ),

                borderWidth: 2,

                tension: 0.35,

                pointRadius: 3,

                pointHoverRadius: 5,

                fill: false

            });

        }
    );


    new Chart(
        reportsCanvas,
        {

            type: "line",

            data: {

                labels:
                    chartLabels,

                datasets:
                    datasets

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                interaction: {

                    mode: "index",

                    intersect: false

                },

                plugins: {

                    legend: {

                        position: "bottom",

                        labels: {

                            usePointStyle: true,

                            padding: 20

                        }

                    },

                    tooltip: {

                        mode: "index",

                        intersect: false

                    }

                },

                scales: {

                    x: {

                        grid: {

                            display: false

                        },

                        ticks: {

                            maxTicksLimit: 8

                        }

                    },

                    y: {

                        beginAtZero: true,

                        ticks: {

                            precision: 0

                        }

                    }

                }

            }

        }
    );

}


// ==========================================================
// CATEGORY DONUT
// ==========================================================

const categoryCanvas =
    document.getElementById(
        "categoryChart"
    );


if (
    categoryCanvas &&
    categoryData.length > 0
) {


    const categoryLabels =
        categoryData.map(
            function (item)
            {
                return item.categoryName;
            }
        );


    const categoryValues =
        categoryData.map(
            function (item)
            {
                return item.reportCount;
            }
        );


    /*
     * These colors are only presentation.
     * The category names and counts still
     * come directly from MySQL.
     */

    const categoryColors = [
        "#6c3df4",
        "#ff8a00",
        "#146df5",
        "#16b86a",
        "#8b93a6",
        "#e85d75",
        "#00a8a8",
        "#9b59b6"
    ];


    new Chart(
        categoryCanvas,
        {

            type: "doughnut",

            data: {

                labels:
                    categoryLabels,

                datasets: [

                    {

                        data:
                            categoryValues,

                        backgroundColor:
                            categoryData.map(
                                function (
                                    item,
                                    index
                                )
                                {

                                    return categoryColors[
                                        index %
                                        categoryColors.length
                                    ];

                                }
                            ),

                        borderWidth: 2,

                        borderColor: "#ffffff"

                    }

                ]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                cutout: "65%",

                plugins: {

                    legend: {

                        display: false

                    },

                    tooltip: {

                        callbacks: {

                            label:
                                function (
                                    context
                                )
                                {

                                    const value =
                                        context.raw;

                                    return (
                                        " " +
                                        value +
                                        " reports"
                                    );

                                }

                        }

                    }

                }

            }

        }
    );


    // ======================================================
    // CATEGORY LEGEND
    // ======================================================

    const legend =
        document.getElementById(
            "categoryLegend"
        );


    categoryData.forEach(
        function (
            item,
            index
        )
        {

            const color =
                categoryColors[
                    index %
                    categoryColors.length
                ];


            const percentage =
                <?php
                echo $totalReports > 0
                    ? $totalReports
                    : 1;
                ?>;


            const percentValue =
                (
                    (
                        Number(
                            item.reportCount
                        ) /
                        percentage
                    ) *
                    100
                ).toFixed(0);


            const legendItem =
                document.createElement(
                    "div"
                );


            legendItem.className =
                "category-legend-item";


            legendItem.innerHTML = `

                <span
                    class="category-legend-dot"
                    style="background:${color};"
                ></span>

                <div class="category-legend-text">

                    <strong>
                        ${escapeHTML(
                            item.categoryName
                        )}
                    </strong>

                    <span>
                        ${Number(
                            item.reportCount
                        ).toLocaleString()}
                        (${percentValue}%)
                    </span>

                </div>

            `;


            legend.appendChild(
                legendItem
            );

        }
    );

}


// ==========================================================
// HTML ESCAPE
// ==========================================================

function escapeHTML(value)
{

    return String(value)

        .replace(
            /&/g,
            "&amp;"
        )

        .replace(
            /</g,
            "&lt;"
        )

        .replace(
            />/g,
            "&gt;"
        )

        .replace(
            /"/g,
            "&quot;"
        )

        .replace(
            /'/g,
            "&#039;"
        );

}

</script>


</body>

</html>