<?php

session_start();


// ==========================================
// CHECK IF ADMIN IS LOGGED IN
// ==========================================

if (!isset($_SESSION["admin_id"])) {

    header("Location: login.php");
    exit;

}


// ==========================================
// DATABASE CONNECTION
// ==========================================

require_once __DIR__ . "/../config/database.php";


// ==========================================
// GET LOGGED-IN ADMIN'S BARANGAY
// ==========================================

$sql = "SELECT barangayName
        FROM barangay
        WHERE barangayID = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "s",
    $_SESSION["barangay_id"]
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $barangay = $result->fetch_assoc();

    $barangayName = $barangay["barangayName"];

} else {

    $barangayName = "Unknown Barangay";

}

$stmt->close();


// ==========================================
// REPORT STATISTICS
// ==========================================

$sql = "SELECT

            COUNT(*) AS totalReports,

            SUM(
                CASE
                    WHEN s.statusName = 'Pending'
                    THEN 1
                    ELSE 0
                END
            ) AS pendingReports,

            SUM(
                CASE
                    WHEN s.statusName = 'In Progress'
                    THEN 1
                    ELSE 0
                END
            ) AS inProgressReports,

            SUM(
                CASE
                    WHEN s.statusName = 'Resolved'
                    THEN 1
                    ELSE 0
                END
            ) AS resolvedReports

        FROM reports r

        LEFT JOIN stats s
            ON r.statusID = s.statusID

        WHERE r.barangayID = ?";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "s",
    $_SESSION["barangay_id"]
);

$stmt->execute();

$result = $stmt->get_result();

$statistics = $result->fetch_assoc();

$stmt->close();


// ==========================================
// STORE STATISTICS
// ==========================================

$totalReports =
    (int) ($statistics["totalReports"] ?? 0);

$pendingReports =
    (int) ($statistics["pendingReports"] ?? 0);

$inProgressReports =
    (int) ($statistics["inProgressReports"] ?? 0);

$resolvedReports =
    (int) ($statistics["resolvedReports"] ?? 0);


// ==========================================
// GET REPORTS BY CATEGORY
// ==========================================

$sql = "SELECT

            c.categoryID,
            c.categoryName,
            COUNT(r.reportID) AS reportCount

        FROM categories c

        LEFT JOIN reports r
            ON c.categoryID = r.categoryID
            AND r.barangayID = ?

        GROUP BY
            c.categoryID,
            c.categoryName

        ORDER BY
            reportCount DESC";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "s",
    $_SESSION["barangay_id"]
);

$stmt->execute();

$categoryResult = $stmt->get_result();

$categories = [];

while ($row = $categoryResult->fetch_assoc()) {

    $categories[] = $row;

}

$stmt->close();


// ==========================================
// GET HEATMAP DATA
// ==========================================

$sql = "SELECT

            r.reportID,
            r.title,
            r.noOfReports,

            l.latitude,
            l.longitude,
            l.address,

            c.categoryName

        FROM reports r

        INNER JOIN location l
            ON r.locationID = l.locationID

        LEFT JOIN categories c
            ON r.categoryID = c.categoryID

        WHERE r.barangayID = ?

        AND l.latitude IS NOT NULL
        AND l.longitude IS NOT NULL

        ORDER BY r.dateReported DESC";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "s",
    $_SESSION["barangay_id"]
);

$stmt->execute();

$heatmapResult = $stmt->get_result();

$heatmapData = [];

while ($row = $heatmapResult->fetch_assoc()) {

    $heatmapData[] = [

        "reportID" =>
            $row["reportID"],

        "title" =>
            $row["title"],

        "categoryName" =>
            $row["categoryName"],

        "latitude" =>
            (float) $row["latitude"],

        "longitude" =>
            (float) $row["longitude"],

        "noOfReports" =>
            (int) $row["noOfReports"],

        "address" =>
            $row["address"]

    ];

}

$stmt->close();


// ==========================================
// GET UNREAD NOTIFICATIONS
// ==========================================

$sql = "SELECT COUNT(*) AS unreadNotifications

        FROM notification

        WHERE userID = ?

        AND isRead = FALSE";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "s",
    $_SESSION["admin_id"]
);

$stmt->execute();

$result = $stmt->get_result();

$notificationData = $result->fetch_assoc();

$stmt->close();


$unreadNotifications =
    (int) (
        $notificationData["unreadNotifications"] ?? 0
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

    <title>CitiSense Admin Dashboard</title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Leaflet CSS -->

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >


    <!-- Dashboard CSS -->

    <link
        rel="stylesheet"
        href="../css/dashboard.css"
    >
</head>


<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

    <!-- ==========================================
         MAIN CONTENT
    =========================================== -->

    <main class="main-content">


        <!-- HEADER -->

        <header class="dashboard-header">


            <div class="welcome-section">

                <h1>
                    Welcome Back, Admin!
                </h1>


                <p>

                    Here's what's happening in

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            $barangayName
                        );

                        ?>

                    </strong>

                    today.

                </p>

            </div>


            <div class="header-right">

                <button
                    class="notification-button"
                    type="button"
                >

                    <i class="bi bi-bell"></i>


                    <?php if (
                        $unreadNotifications > 0
                    ): ?>

                        <span
                            class="notification-badge"
                        >

                            <?php
                            echo $unreadNotifications;
                            ?>

                        </span>

                    <?php endif; ?>


                </button>

            </div>


        </header>


        <!-- DATE -->

        <div class="date-box">

            <i class="bi bi-calendar3"></i>

            <span>

                <?php
                echo date("F j, Y");
                ?>

            </span>

        </div>


        <!-- ==========================================
             REPORT STATISTICS
        =========================================== -->

        <section class="statistics-section">


            <h3>
                Report Statistics
            </h3>


            <div class="statistics-grid">


                <!-- TOTAL REPORTS -->

                <div class="stat-card">

                    <div class="stat-icon purple">

                        <i
                            class="bi bi-file-earmark-text"
                        ></i>

                    </div>


                    <div class="stat-info">

                        <h2>
                            <?php
                            echo $totalReports;
                            ?>
                        </h2>

                        <p>
                            Total Reports
                        </p>

                    </div>

                </div>


                <!-- PENDING -->

                <div class="stat-card">

                    <div class="stat-icon orange">

                        <i class="bi bi-clock"></i>

                    </div>


                    <div class="stat-info">

                        <h2>
                            <?php
                            echo $pendingReports;
                            ?>
                        </h2>

                        <p>
                            Pending
                        </p>

                    </div>

                </div>


                <!-- IN PROGRESS -->

                <div class="stat-card">

                    <div class="stat-icon blue">

                        <i class="bi bi-circle"></i>

                    </div>


                    <div class="stat-info">

                        <h2>
                            <?php
                            echo $inProgressReports;
                            ?>
                        </h2>

                        <p>
                            In Progress
                        </p>

                    </div>

                </div>


                <!-- RESOLVED -->

                <div class="stat-card">

                    <div class="stat-icon green">

                        <i
                            class="bi bi-check-circle-fill"
                        ></i>

                    </div>


                    <div class="stat-info">

                        <h2>
                            <?php
                            echo $resolvedReports;
                            ?>
                        </h2>

                        <p>
                            Resolved
                        </p>

                    </div>

                </div>


            </div>

        </section>


        <!-- ==========================================
             REPORTS BY CATEGORY + HEATMAP
        =========================================== -->

        <section class="dashboard-lower-grid">


            <!-- ======================================
                 REPORTS BY CATEGORY
            ======================================= -->

            <div class="dashboard-card categories-card">


                <div class="card-header">

                    <h3>
                        Reports by Category
                    </h3>

                </div>


                <div class="category-grid">


                    <?php if (
                        count($categories) > 0
                    ): ?>


                        <?php foreach (
                            $categories
                            as $category
                        ): ?>


                            <?php

                            $categoryName =
                                $category["categoryName"];

                            $reportCount =
                                (int)
                                $category["reportCount"];


                            if (
                                $totalReports > 0
                            ) {

                                $percentage =
                                    round(
                                        (
                                            $reportCount
                                            /
                                            $totalReports
                                        ) * 100
                                    );

                            } else {

                                $percentage = 0;

                            }

                            ?>


                            <div class="category-item">


                                <div
                                    class="category-icon blue"
                                >

                                    <i
                                        class="bi bi-tag"
                                    ></i>

                                </div>


                                <h2>

                                    <?php
                                    echo $reportCount;
                                    ?>

                                </h2>


                                <p>

                                    <?php

                                    echo htmlspecialchars(
                                        $categoryName
                                    );

                                    ?>

                                </p>


                                <span>

                                    <?php
                                    echo $percentage;
                                    ?>%

                                </span>


                                <div class="progress">

                                    <div
                                        class="progress-bar blue-bar"
                                        style="
                                            width:
                                            <?php
                                            echo $percentage;
                                            ?>%;">
                                    </div>
                        
                                </div>


                            </div>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <div class="no-data">

                            <i
                                class="bi bi-inbox"
                            ></i>

                            <p>
                                No categories available.
                            </p>

                        </div>


                    <?php endif; ?>


                </div>


                <div class="view-categories">

                    <a href="#">

                        View All Categories

                        <i
                            class="bi bi-arrow-right"
                        ></i>

                    </a>

                </div>


            </div>


            <!-- ======================================
                 REPORT HEATMAP
            ======================================= -->

            <div class="dashboard-card heatmap-card">


                <div class="card-header">

                    <h3>
                        Report Heatmap
                    </h3>

                </div>


                <div class="heatmap-container">


                    <div
                        id="reportHeatmap"
                    ></div>


                    <!-- LEGEND -->

                    <div class="heatmap-legend">

                        <span>
                            Low
                        </span>


                        <div
                            class="legend-gradient"
                        ></div>


                        <span>
                            High
                        </span>

                    </div>


                    <!-- VIEW FULL HEATMAP -->

                    <a
                        href="#"
                        class="full-heatmap"
                    >

                        View Full Heatmap

                        <i
                            class="bi bi-box-arrow-up-right"
                        ></i>

                    </a>


                </div>


            </div>


        </section>


        <!-- ==========================================
             FOOTER
        =========================================== -->



    </main>


</div>


<!-- ==========================================
     LEAFLET JS
=========================================== -->

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
></script>


<!-- ==========================================
     LEAFLET HEAT PLUGIN
=========================================== -->

<script
    src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"
></script>


<script>


// ==========================================
// DATABASE HEATMAP DATA
// ==========================================

const heatmapReports =
    <?php

    echo json_encode(
        $heatmapData,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    );

    ?>;


// ==========================================
// CREATE MAP
// ==========================================

let mapCenter = [
    10.2923404,
    123.8890404
];


// If reports exist,
// center the map on the first report.

if (
    heatmapReports.length > 0
) {

    mapCenter = [

        heatmapReports[0].latitude,

        heatmapReports[0].longitude

    ];

}


const map =
    L.map(
        "reportHeatmap",
        {
            zoomControl: true
        }
    ).setView(
        mapCenter,
        15
    );


// ==========================================
// OPENSTREETMAP
// ==========================================

L.tileLayer(

    "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",

    {

        maxZoom: 19,

        attribution:
            '&copy; OpenStreetMap contributors'

    }

).addTo(map);


// ==========================================
// GET HIGHEST DUPLICATE COUNT
// ==========================================

let maxReports = 1;


heatmapReports.forEach(
    report => {

        const count =
            Number(
                report.noOfReports
            ) || 1;


        if (
            count > maxReports
        ) {

            maxReports = count;

        }

    }
);


// ==========================================
// CREATE HEAT POINTS
// ==========================================

const heatPoints =
    heatmapReports.map(
        report => {

            const count =
                Number(
                    report.noOfReports
                ) || 1;


            let intensity;


            /*
             * A report with 1 occurrence
             * starts at the yellow range.
             *
             * Higher duplicate counts
             * gradually become hotter.
             */

            if (
                maxReports <= 1
            ) {

                intensity = 0.40;

            } else {

                intensity =
                    0.40 +
                    (
                        (count - 1)
                        /
                        (maxReports - 1)
                    ) * 0.60;

            }


            intensity =
                Math.min(
                    1,
                    Math.max(
                        0.40,
                        intensity
                    )
                );


            return [

                report.latitude,

                report.longitude,

                intensity

            ];

        }
    );


// ==========================================
// CREATE HEATMAP
// ==========================================

if (
    heatPoints.length > 0
) {


    L.heatLayer(

        heatPoints,

        {

            radius: 30,

            blur: 22,

            maxZoom: 17,

            minOpacity: 0.55,

            gradient: {

                0.40: "#dce51c",

                0.55: "#ffb300",

                0.70: "#ff7600",

                0.85: "#ff3d00",

                1.00: "#ff0000"

            }

        }

    ).addTo(map);


    // ======================================
    // CREATE CLICKABLE LOCATION MARKERS
    // ======================================

    heatmapReports.forEach(
        report => {


            const marker =
                L.circleMarker(

                    [
                        report.latitude,
                        report.longitude
                    ],

                    {

                        radius: 5,

                        fillColor:
                            "#1477f8",

                        color:
                            "#ffffff",

                        weight: 2,

                        fillOpacity: 0.9

                    }

                ).addTo(map);


            marker.bindPopup(`

                <div class="map-popup">

                    <strong>
                        ${escapeHtml(
                            report.title
                        )}
                    </strong>

                    <br>

                    <span>
                        Category:
                        ${escapeHtml(
                            report.categoryName ||
                            "N/A"
                        )}
                    </span>

                    <br>

                    <span>
                        No. of Reports:
                        ${Number(
                            report.noOfReports
                        ) || 1}
                    </span>

                    <br>

                    <small>
                        ${escapeHtml(
                            report.address ||
                            "No address"
                        )}
                    </small>

                </div>

            `);

        });


    // ======================================
    // FIT MAP TO ALL REPORT LOCATIONS
    // ======================================

    if (
        heatmapReports.length > 1
    ) {

        const bounds =
            L.latLngBounds(

                heatmapReports.map(
                    report => [

                        report.latitude,

                        report.longitude

                    ]
                )

            );


        map.fitBounds(

            bounds,

            {

                padding: [
                    20,
                    20
                ],

                maxZoom: 16

            }

        );

    }

}


// ==========================================
// ESCAPE HTML FOR POPUPS
// ==========================================

function escapeHtml(value) {

    return String(
        value ?? ""
    )

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


// ==========================================
// FIX MAP SIZE AFTER PAGE LOAD
// ==========================================

setTimeout(
    () => {

        map.invalidateSize();

    },
    100
);


</script>


</body>

</html>