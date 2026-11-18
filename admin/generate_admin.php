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

date_default_timezone_set("Asia/Manila");

$barangayID = (string) $_SESSION["barangay_id"];

/* =========================================================
   VALIDATE REPORTING PERIOD
========================================================= */

$period = $_GET["period"] ?? "";

$periodLabels = [
    "day"      => "Within the Day",
    "3months"  => "Every 3 Months",
    "6months"  => "Every 6 Months",
    "year"     => "Yearly"
];

if (!array_key_exists($period, $periodLabels)) {
    http_response_code(400);
    exit("Invalid reporting period.");
}

/* =========================================================
   SET REPORT DATE RANGE

   day     = Today
   3months = Rolling last 3 months
   6months = Rolling last 6 months
   year    = Rolling last 12 months
========================================================= */

$now = new DateTimeImmutable("now");

$endDate = $now->modify("+1 second");

switch ($period) {
    case "day":
        $startDate = $now->setTime(0, 0, 0);
        $endDate = $startDate->modify("+1 day");
        break;

    case "3months":
        $startDate = $now->modify("-3 months");
        break;

    case "6months":
        $startDate = $now->modify("-6 months");
        break;

    case "year":
        $startDate = $now->modify("-12 months");
        break;
}

$startDateSQL = $startDate->format("Y-m-d H:i:s");
$endDateSQL = $endDate->format("Y-m-d H:i:s");

/* =========================================================
   GET BARANGAY NAME
========================================================= */

$stmt = $conn->prepare(
    "SELECT barangayName
     FROM barangay
     WHERE barangayID = ?
     LIMIT 1"
);

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$barangayData = $stmt->get_result()->fetch_assoc();

$stmt->close();

$barangayName = $barangayData["barangayName"] ?? "Unknown Barangay";

/* =========================================================
   GET REPORTS FROM DATABASE

   Only reports belonging to the logged-in admin's
   barangay and selected reporting period are retrieved.
========================================================= */

$sql = "
    SELECT
        r.reportID,
        r.title,
        r.description,
        r.dateReported,

        c.categoryName,
        s.statusName,
        l.address,

        u.firstName,
        u.lastName

    FROM reports r

    LEFT JOIN categories c
        ON r.categoryID = c.categoryID

    LEFT JOIN stats s
        ON r.statusID = s.statusID

    LEFT JOIN location l
        ON r.locationID = l.locationID

    LEFT JOIN users u
        ON r.userID = u.userID

    WHERE r.barangayID = ?
      AND r.dateReported >= ?
      AND r.dateReported < ?

    ORDER BY r.dateReported DESC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sss",
    $barangayID,
    $startDateSQL,
    $endDateSQL
);

$stmt->execute();

$result = $stmt->get_result();

$reports = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   REPORT SUMMARY
========================================================= */

$totalReports = count($reports);

$statusCounts = [
    "Pending" => 0,
    "In Progress" => 0,
    "Overdue" => 0,
    "Resolved" => 0
];

$categoryCounts = [];

foreach ($reports as $report) {
    $status = $report["statusName"] ?? "Unknown";
    $category = $report["categoryName"] ?? "Uncategorized";

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }

    if (!isset($categoryCounts[$category])) {
        $categoryCounts[$category] = 0;
    }

    $categoryCounts[$category]++;
}

arsort($categoryCounts);

/* =========================================================
   HTML ESCAPING
========================================================= */

function reportEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$periodLabel = $periodLabels[$period];

$displayStart = $startDate->format("F d, Y h:i A");
$displayEnd = $now->format("F d, Y h:i A");

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>CitiSense - Generated Report</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 35px;
        }

        .report-header {
            border-bottom: 2px solid #1e5b9f;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .report-header h1 {
            color: #1e5b9f;
            margin-bottom: 5px;
        }

        .report-header p {
            margin: 5px 0;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }

        .summary-card {
            border: 1px solid #ddd;
            padding: 14px;
            border-radius: 6px;
        }

        .summary-card strong {
            display: block;
            font-size: 22px;
            margin-top: 5px;
        }

        h2 {
            margin-top: 25px;
            color: #1e5b9f;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 12px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 9px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #edf3f9;
        }

        .no-reports {
            padding: 20px;
            text-align: center;
            border: 1px solid #ddd;
        }

        .print-button {
            background: #1e5b9f;
            color: white;
            border: none;
            padding: 11px 18px;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }

        @media print {
            body {
                margin: 15mm;
            }

            .print-button {
                display: none;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }
        }
    </style>
</head>

<body>

<button
    type="button"
    class="print-button"
    onclick="window.print()"
>
    Print / Save as PDF
</button>

<header class="report-header">

    <h1>CitiSense</h1>

    <h2>Community Issue Reports</h2>

    <p>
        <strong>Barangay:</strong>
        <?= reportEscape($barangayName) ?>
    </p>

    <p>
        <strong>Reporting Period:</strong>
        <?= reportEscape($periodLabel) ?>
    </p>

    <p>
        <strong>From:</strong>
        <?= reportEscape($displayStart) ?>
    </p>

    <p>
        <strong>Generated:</strong>
        <?= reportEscape($displayEnd) ?>
    </p>

</header>

<h2>Report Summary</h2>

<section class="summary">

    <div class="summary-card">
        Total Reports
        <strong><?= $totalReports ?></strong>
    </div>

    <div class="summary-card">
        Pending
        <strong><?= $statusCounts["Pending"] ?></strong>
    </div>

    <div class="summary-card">
        In Progress
        <strong><?= $statusCounts["In Progress"] ?></strong>
    </div>

    <div class="summary-card">
        Overdue
        <strong><?= $statusCounts["Overdue"] ?></strong>
    </div>

    <div class="summary-card">
        Resolved
        <strong><?= $statusCounts["Resolved"] ?></strong>
    </div>

</section>

<h2>Reports by Category</h2>

<?php if (count($categoryCounts) > 0): ?>

    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Total Reports</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($categoryCounts as $category => $count): ?>
                <tr>
                    <td><?= reportEscape($category) ?></td>
                    <td><?= (int) $count ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>

    <p class="no-reports">
        No reports were submitted during this period.
    </p>

<?php endif; ?>

<h2>Report Records</h2>

<?php if ($totalReports > 0): ?>

    <table>
        <thead>
            <tr>
                <th>Report ID</th>
                <th>Issue</th>
                <th>Category</th>
                <th>Location</th>
                <th>Reporter</th>
                <th>Status</th>
                <th>Date Reported</th>
            </tr>
        </thead>

        <tbody>

            <?php foreach ($reports as $report): ?>

                <tr>
                    <td>
                        <?= reportEscape($report["reportID"]) ?>
                    </td>

                    <td>
                        <?= reportEscape($report["title"]) ?>
                    </td>

                    <td>
                        <?= reportEscape($report["categoryName"]) ?>
                    </td>

                    <td>
                        <?= reportEscape($report["address"]) ?>
                    </td>

                    <td>
                        <?= reportEscape(
                            trim(
                                ($report["firstName"] ?? "") . " " .
                                ($report["lastName"] ?? "")
                            ) ?: "Unknown"
                        ) ?>
                    </td>

                    <td>
                        <?= reportEscape($report["statusName"]) ?>
                    </td>

                    <td>
                        <?= reportEscape(
                            $report["dateReported"]
                                ? date(
                                    "M d, Y h:i A",
                                    strtotime($report["dateReported"])
                                )
                                : "Not available"
                        ) ?>
                    </td>
                </tr>

            <?php endforeach; ?>

        </tbody>
    </table>

<?php else: ?>

    <p class="no-reports">
        No reports were submitted during the selected reporting period.
    </p>

<?php endif; ?>

</body>
</html>

<?php
$conn->close();
?>