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

        WHERE c.categoryName <> 'Blocked Drainage'

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



/* ==========================================
   GET HEATMAP DATA
   SAME REPORT DATA AS heatmap.php
========================================== */

$sql = "
    SELECT
        r.reportID,
        r.locationID,
        l.latitude,
        l.longitude,
        l.address

    FROM reports r

    INNER JOIN location l
        ON r.locationID = l.locationID

    WHERE r.barangayID = ?
      AND l.latitude IS NOT NULL
      AND l.longitude IS NOT NULL

    ORDER BY r.dateReported DESC
";

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
        "reportID" => $row["reportID"],
        "locationID" => $row["locationID"],
        "latitude" => (float) $row["latitude"],
        "longitude" => (float) $row["longitude"],
        "address" => $row["address"]
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

    <link rel="stylesheet" href="../css/dashboard-v2.css?v=2">
<?php include __DIR__ . "/partial/head.php"; ?>
</head>


<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

    <!-- ==========================================
         MAIN CONTENT
    =========================================== -->

    <?php
    $hour = (int) date("G");
    $greeting = $hour < 12 ? "Good morning" : ($hour < 18 ? "Good afternoon" : "Good evening");

    $adminFirstName = trim(explode(" ", (string) ($_SESSION["admin_name"] ?? "Admin"))[0]);
    if ($adminFirstName === "") {
        $adminFirstName = "Admin";
    }

    $statCards = [
        ["Total Reports", $totalReports, "bi-file-earmark-text", "total", "All reports in your barangay"],
        ["Pending", $pendingReports, "bi-hourglass-split", "pending", "Waiting for review"],
        ["In Progress", $inProgressReports, "bi-arrow-repeat", "progress", "Being worked on"],
        ["Resolved", $resolvedReports, "bi-check2-circle", "resolved", "Completed reports"],
    ];

    function dashCategoryIcon(string $name): string
    {
        $name = strtolower($name);
        if (str_contains($name, "waste") || str_contains($name, "garbage")) return "bi-trash3";
        if (str_contains($name, "pothole") || str_contains($name, "road")) return "bi-cone-striped";
        if (str_contains($name, "drain") || str_contains($name, "flood")) return "bi-water";
        return "bi-three-dots";
    }

    $categoryTotal = array_sum(array_map(static fn($c) => (int) $c["reportCount"], $categories));
    ?>

    <main class="main-content dash">

        <!-- HEADER -->
        <header class="page-header">

            <div>
                <h1><?= htmlspecialchars($greeting . ", " . $adminFirstName) ?> 👋</h1>
                <p>
                    Here's what's happening in
                    <strong><?= htmlspecialchars($barangayName) ?></strong>
                    today.
                </p>
            </div>

            <div class="page-header-actions">
                <span class="dash-date">
                    <i class="bi bi-calendar3"></i>
                    <?= date("l, F j, Y") ?>
                </span>

                <?php include __DIR__ . "/partial/notification_bell.php"; ?>
            </div>

        </header>

        <!-- STATISTICS -->
        <section class="dash-stats" aria-label="Report statistics">

            <?php foreach ($statCards as [$label, $value, $icon, $tone, $hint]): ?>

                <?php
                $share = ($tone !== "total" && $totalReports > 0)
                    ? round($value / $totalReports * 100)
                    : null;
                ?>

                <article class="dash-stat dash-stat-<?= $tone ?>">
                    <div class="dash-stat-top">
                        <span class="dash-stat-label"><?= $label ?></span>
                        <span class="dash-stat-icon"><i class="bi <?= $icon ?>"></i></span>
                    </div>

                    <strong class="dash-stat-value"><?= number_format($value) ?></strong>

                    <span class="dash-stat-hint">
                        <?php if ($share !== null): ?>
                            <b><?= $share ?>%</b> of all reports
                        <?php else: ?>
                            <?= $hint ?>
                        <?php endif; ?>
                    </span>
                </article>

            <?php endforeach; ?>

        </section>

        <!-- CATEGORIES + HEATMAP -->
        <section class="dash-grid">

            <div class="dash-card">

                <header class="dash-card-header">
                    <div>
                        <h3>Reports by Category</h3>
                    </div>
                    <a href="reports.php" class="dash-card-link">View reports <i class="bi bi-arrow-right"></i></a>
                </header>

                <?php if (count($categories) > 0): ?>

                    <div class="dash-categories">

                        <?php foreach ($categories as $category): ?>

                            <?php
                            $reportCount = (int) $category["reportCount"];
                            $percentage = $categoryTotal > 0
                                ? round($reportCount / $categoryTotal * 100)
                                : 0;
                            ?>

                            <a
                                class="dash-category"
                                href="reportStatusOverview.php?categoryID=<?= urlencode((string) $category["categoryID"]) ?>"
                            >
                                <span class="dash-category-icon">
                                    <i class="bi <?= dashCategoryIcon((string) $category["categoryName"]) ?>"></i>
                                </span>

                                <span class="dash-category-body">
                                    <span class="dash-category-row">
                                        <strong><?= htmlspecialchars($category["categoryName"]) ?></strong>
                                        <span><?= $reportCount ?> <small>· <?= $percentage ?>%</small></span>
                                    </span>

                                    <span class="dash-progress">
                                        <span style="width: <?= $percentage ?>%"></span>
                                    </span>
                                </span>
                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="dash-empty">
                        <i class="bi bi-inbox"></i>
                        <p>No categories available.</p>
                    </div>

                <?php endif; ?>

            </div>

            <div class="dash-card dash-map-card">

                <header class="dash-card-header">
                    <div>
                        <h3>Report Heatmap</h3>
                    </div>
                    <a href="heatmap.php" class="dash-card-link">Full map <i class="bi bi-arrows-angle-expand"></i></a>
                </header>

                <div class="dash-map">
                    <div id="reportHeatmap"></div>

                    <div class="dash-map-legend">
                        <span>Low</span>
                        <div class="legend-gradient"></div>
                        <span>High</span>
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
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
></script>



<!-- LEAFLET HEAT PLUGIN -->
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>


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
// DULJO-FATIMA MAP - APPROXIMATE LOCAL VIEW
// ==========================================
// These bounds constrain navigation; they are not official
// barangay boundary coordinates.
const duljoCenter = [10.29234, 123.89350];
const duljoBounds = L.latLngBounds(
    [10.2840, 123.8800],
    [10.3010, 123.9080]
);

const map = L.map("reportHeatmap", {
    zoomControl: true,
    minZoom: 16,
    maxZoom: 19,
    maxBounds: duljoBounds,
    maxBoundsViscosity: 1.0
}).setView(duljoCenter, 17);


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



/* ==========================================
   GROUP SUBMISSIONS BY LOCATION
========================================== */

const locationGroups = {};

heatmapReports.forEach(report => {

    const latitude = Number(report.latitude);
    const longitude = Number(report.longitude);

    if (
        !Number.isFinite(latitude) ||
        !Number.isFinite(longitude) ||
        !duljoBounds.contains([latitude, longitude])
    ) {
        // Invalid or distant GPS positions must not distort
        // the local heatmap. Database records are unchanged.
        return;
    }

    const locationID = String(report.locationID);

    if (!locationGroups[locationID]) {

        locationGroups[locationID] = {
            latitude: latitude,
            longitude: longitude,
            count: 0
        };
    }

    // One database record = one submission
    locationGroups[locationID].count++;
});


/* ==========================================
   CREATE HEAT POINTS
========================================== */

const heatPoints = [];

Object.values(locationGroups).forEach(location => {

    const intensity = Math.min(location.count, 4);

    heatPoints.push([
        location.latitude,
        location.longitude,
        intensity
    ]);

});


/* ==========================================
   CREATE VISIBLE DASHBOARD HEATMAP
========================================== */

if (heatPoints.length > 0) {

    L.heatLayer(heatPoints, {

        // Larger spots for the smaller dashboard
        radius: 44,

        // Soft feathered edges to keep streets readable
        blur: 30,

        // Preserve intensity at wider map zooms
        maxZoom: 17,

        // Maximum represented concentration
        max: 4,

        // Reduce the minimum color opacity
        minOpacity: 0.28,

        // Softened color palette matching heatmap.php
        gradient: {
            0.00: "#64cf98",  // Soft green - very low
            0.25: "#c8e980",  // Soft lime - low
            0.50: "#f8da80",  // Warm yellow - moderate
            0.75: "#f4aa79",  // Muted orange - elevated
            1.00: "#ec7f79"   // Soft coral - highest
        }

    }).addTo(map);
}


/* ==========================================
   KEEP THE MAP INSIDE THE DULJO-FATIMA VIEW
========================================== */

setTimeout(() => {
    map.invalidateSize();

    // Make sure the whole visible viewport fits within
    // the approximate bounds, even on a wide display.
    const localMinZoom = Math.max(
        16,
        map.getBoundsZoom(duljoBounds, true)
    );
    map.setMinZoom(localMinZoom);
    map.setView(duljoCenter, Math.max(17, localMinZoom));
}, 150);


</script>


</body>

</html>