<?php

session_start();


// ==========================================
// ADMIN SESSION CHECK
// ==========================================

if (!isset($_SESSION["admin_id"], $_SESSION["barangay_id"])) {

    header("Location: login.php");
    exit;

}


// ==========================================
// DATABASE CONNECTION
// ==========================================

require_once __DIR__ . "/../config/database.php";


// ==========================================
// GET LOGGED-IN ADMIN BARANGAY
// ==========================================

$barangayID = $_SESSION["barangay_id"];


// ==========================================
// GET BARANGAY NAME
// ==========================================

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


// ==========================================
// GET REPORT LOCATIONS
// ==========================================
//
// Each resident report is connected to:
//
// reports
//      ↓
// locationID
//      ↓
// location
//      ↓
// latitude / longitude
//
// Every report becomes a heatmap point.
//
// There are NO MAP PINS.
// ==========================================

$reports = [];

$sql = "
    SELECT
        r.reportID,
        r.categoryID,
        c.categoryName,
        r.statusID,
        s.statusName,
        r.locationID,
        l.latitude,
        l.longitude,
        l.address,
        r.dateReported

    FROM reports r

    INNER JOIN location l
        ON r.locationID = l.locationID

    INNER JOIN categories c
        ON r.categoryID = c.categoryID

    INNER JOIN stats s
        ON r.statusID = s.statusID

    WHERE r.barangayID = ?

    ORDER BY r.dateReported DESC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param("s", $barangayID);

$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    if (
        $row["latitude"] === null ||
        $row["longitude"] === null
    ) {
        continue;
    }

    $reports[] = [

        "reportID" => $row["reportID"],

        "categoryID" => $row["categoryID"],

        "categoryName" => $row["categoryName"],

        "statusID" => $row["statusID"],

        "statusName" => $row["statusName"],

        "locationID" => $row["locationID"],

        "latitude" => (float) $row["latitude"],

        "longitude" => (float) $row["longitude"],

        "address" => $row["address"],

        "dateReported" => $row["dateReported"]

    ];

}

$stmt->close();


// ==========================================
// CONVERT REPORT DATA TO JSON
// ==========================================

$reportsJSON = json_encode(
    $reports,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
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
        CitiSense - Heatmap
    </title>


    <!-- ==========================================
         BOOTSTRAP ICONS
    =========================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- ==========================================
         LEAFLET CSS
    =========================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
    >


    <!-- ==========================================
         DASHBOARD CSS
    =========================================== -->

    <link
        rel="stylesheet"
        href="../css/dashboard.css"
    >


    <!-- ==========================================
         HEATMAP CSS
    =========================================== -->

    <link
        rel="stylesheet"
        href="../css/heatmap.css"
    >

</head>


<body>


<div class="admin-layout">


    <!-- ==========================================
         SIDEBAR
    =========================================== -->

    <?php include __DIR__ . "/partial/sidebar.php"; ?>


    <!-- ==========================================
         MAIN CONTENT
    =========================================== -->

    <main class="main-content">


        <!-- ==========================================
             HEADER
        =========================================== -->

        <header class="dashboard-header">

            <div class="welcome-section">

                <h1>
                    Heatmap
                </h1>

                <p>

                    View report distribution in

                    <strong>
                        <?php echo htmlspecialchars($barangayName); ?>
                    </strong>

                </p>

            </div>


            <div class="header-right">

                <div class="live-indicator">

                    <span class="live-dot"></span>

                    Live Data

                </div>

            </div>

        </header>


        <!-- ==========================================
             MAP CARD
        =========================================== -->

        <section class="heatmap-card">


            <div class="heatmap-card-header">

                <div>

                    <h2>
                        Community Issue Map
                    </h2>

                    <p>
                        Report concentration based on resident submissions
                    </p>

                </div>


                <div class="report-count">

                    <i class="bi bi-geo-alt"></i>

                    <span id="reportCount">
                        <?php echo count($reports); ?>
                    </span>

                    Reports

                </div>

            </div>


            <!-- ======================================
                 MAP
            ======================================= -->

            <div
                id="heatmapMap"
                class="heatmap-map"
            ></div>



            <!-- ======================================
                 MAP INFO
            ======================================= -->

            <!-- ======================================
                 HEATMAP LEGEND
            ======================================= -->

            <div class="heatmap-legend">

                    <div class="legend-title">
                        Report Concentration
                    </div>


                    <div class="legend-scale">

                        <div class="legend-color very-low"></div>

                        <div class="legend-color low"></div>

                        <div class="legend-color moderate"></div>

                        <div class="legend-color high"></div>

                    </div>


                    <div class="legend-labels">

                        <span>
                            Very Low
                        </span>

                        <span>
                            Low
                        </span>

                        <span>
                            Moderate
                        </span>

                        <span>
                            High
                        </span>

                    </div>

                </div>

            </div>


        </section>


    </main>

</div>


<!-- ==========================================
     LEAFLET JS
=========================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js">
</script>


<!-- ==========================================
     LEAFLET.HEAT JS
=========================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js">
</script>



<script>

// ==========================================
// REPORT DATA FROM PHP / MYSQL
// ==========================================

const reports = <?php echo $reportsJSON ?: "[]"; ?>;


// ==========================================
// CREATE MAP
// ==========================================

const map = L.map("heatmapMap").setView(
    [0, 0],
    2
);


// ==========================================
// OPENSTREETMAP
// ==========================================

L.tileLayer(
    "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    {
        maxZoom: 19,

        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }
).addTo(map);


// ==========================================
// GROUP REPORTS BY LOCATION
// ==========================================
//
// Reports using the same locationID are
// treated as reports in the same location.
//
// Example:
//
// L001 → 1 report
// L002 → 3 reports
//
// This lets the heat intensity represent
// the number of resident submissions.
// ==========================================

const locationGroups = {};

reports.forEach(function (report) {

    const latitude = Number(report.latitude);
    const longitude = Number(report.longitude);

    if (
        !Number.isFinite(latitude) ||
        !Number.isFinite(longitude)
    ) {
        return;
    }


    const locationID = report.locationID;


    if (!locationGroups[locationID]) {

        locationGroups[locationID] = {

            latitude: latitude,

            longitude: longitude,

            count: 0

        };

    }


    locationGroups[locationID].count++;

});


// ==========================================
// CREATE HEATMAP POINTS
// ==========================================

const heatPoints = [];


Object.values(locationGroups).forEach(function (location) {

    /*
     * Maximum intensity is 4.
     *
     * 1 report = 1
     * 2 reports = 2
     * 3 reports = 3
     * 4+ reports = 4
     */

    const intensity =
        Math.min(location.count, 4);


    heatPoints.push([

        location.latitude,

        location.longitude,

        intensity

    ]);

});


// ==========================================
// CREATE HEATMAP
// ==========================================
//
// IMPORTANT:
//
// There are NO markers.
//
// The heat layer is based on the number
// of reports at each location.
//
// 1 report  → yellow
// 2 reports → orange
// 3 reports → orange/red
// 4+       → red
//
// The outer fading area becomes green.
// ==========================================

L.heatLayer(
    heatPoints,
    {

        radius: 40,

        blur: 30,

        maxZoom: 17,

        minOpacity: 0.35,

        max: 4,

        gradient: {

            0.00: "#00c853",   // Green - Very Low

            0.25: "#ffff00",   // Yellow - Low

            0.50: "#ff9800",   // Orange - Moderate

            0.75: "#ff5722",   // Orange/Red

            1.00: "#ff0000"    // Red - High

        }

    }
).addTo(map);


// ==========================================
// FIT MAP TO REPORT LOCATIONS
// ==========================================

if (heatPoints.length > 0) {

    const bounds = L.latLngBounds(

        heatPoints.map(function (point) {

            return [
                point[0],
                point[1]
            ];

        })

    );

    map.fitBounds(
        bounds.pad(0.15)
    );

}


// ==========================================
// REPORT COUNT
// ==========================================

document.getElementById(
    "reportCount"
).textContent = reports.length;


// ==========================================
// LAST UPDATED
// ==========================================

document.getElementById(
    "lastUpdated"
).textContent =
    new Date().toLocaleTimeString();
    
</script>

</body>

</html>