
<?php
session_start();

if (
    !isset($_SESSION["admin_id"]) ||
    !isset($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}

require_once "../config/database.php";

date_default_timezone_set("Asia/Manila");

$barangayID = $_SESSION["barangay_id"];

$periods = [
    "day" => "Within the Day",
    "3months" => "Every 3 Months",
    "6months" => "Every 6 Months",
    "year" => "Yearly"
];

$period = $_GET["period"] ?? "";

if (!array_key_exists($period, $periods)) {
    exit("Invalid reporting period.");
}

$now = new DateTime();
$endDate = clone $now;
$startDate = clone $now;

switch ($period) {
    case "day":
        $startDate->setTime(0, 0, 0);
        $endDate = clone $startDate;
        $endDate->modify("+1 day");
        break;

    case "3months":
        $startDate->modify("-3 months");
        break;

    case "6months":
        $startDate->modify("-6 months");
        break;

    case "year":
        $startDate->modify("-1 year");
        break;
}

$start = $startDate->format("Y-m-d H:i:s");
$end = $endDate->format("Y-m-d H:i:s");

// Get barangay name
$barangayQuery = $conn->prepare(
    "SELECT barangayName FROM barangay WHERE barangayID = ?"
);
$barangayQuery->bind_param("s", $barangayID);
$barangayQuery->execute();

$barangayResult = $barangayQuery->get_result();
$barangay = $barangayResult->fetch_assoc();

if (!$barangay) {
    exit("Barangay information not found.");
}

// Retrieve reports for this barangay and period
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
    LEFT JOIN categories c ON r.categoryID = c.categoryID
    LEFT JOIN stats s ON r.statusID = s.statusID
    LEFT JOIN location l ON r.locationID = l.locationID
    LEFT JOIN users u ON r.userID = u.userID
    WHERE r.barangayID = ?
      AND r.dateReported >= ?
      AND r.dateReported < ?
    ORDER BY r.dateReported DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $barangayID, $start, $end);
$stmt->execute();

$result = $stmt->get_result();

$reports = [];
$statusSummary = [];
$categorySummary = [];

while ($row = $result->fetch_assoc()) {
    $reports[] = $row;

    $status = $row["statusName"] ?? "Unspecified";
    $category = $row["categoryName"] ?? "Uncategorized";

    $statusSummary[$status] = ($statusSummary[$status] ?? 0) + 1;
    $categorySummary[$category] = ($categorySummary[$category] ?? 0) + 1;
}

$totalReports = count($reports);

function escape($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generated Reports - CitiSense</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 35px;
        }

        h1, h2 {
            margin-bottom: 8px;
        }

        .report-header {
            border-bottom: 2px solid #1d4ed8;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .metadata {
            color: #555;
            line-height: 1.7;
        }

        .summary {
            display: flex;
            gap: 30px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .summary-section {
            margin-bottom: 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 9px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f1f5f9;
        }

        .print-button {
            background: #1d4ed8;
            color: white;
            border: none;
            padding: 11px 18px;
            border-radius: 6px;
            cursor: pointer;
            margin-bottom: 25px;
        }

        .empty {
            padding: 20px;
            background: #f8fafc;
            border: 1px solid #ddd;
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
        }
    </style>
</head>

<body>

<button class="print-button" onclick="window.print()">
    Print / Save as PDF
</button>

<div class="report-header">
    <h1>CitiSense Report Summary</h1>

    <div class="metadata">
        <strong>Barangay:</strong>
        <?= escape($barangay["barangayName"]) ?><br>

        <strong>Reporting Period:</strong>
        <?= escape($periods[$period]) ?><br>

        <strong>Date Range:</strong>
        <?= escape($start) ?> to
        <?= escape($period === "day"
            ? (new DateTime($end))->modify("-1 second")->format("Y-m-d H:i:s")
            : $now->format("Y-m-d H:i:s")) ?><br>

        <strong>Date Generated:</strong>
        <?= date("F d, Y h:i A") ?>
    </div>
</div>

<h2>Overall Summary</h2>

<div class="summary">
    <div>
        <strong>Total Reports</strong><br>
        <?= $totalReports ?>
    </div>

    <?php foreach ($statusSummary as $status => $count): ?>
        <div>
            <strong><?= escape($status) ?></strong><br>
            <?= $count ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="summary-section">
    <h2>Reports by Category</h2>

    <?php if (empty($categorySummary)): ?>
        <p class="empty">No reports found for this reporting period.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Total Reports</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categorySummary as $category => $count): ?>
                    <tr>
                        <td><?= escape($category) ?></td>
                        <td><?= $count ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="summary-section">
    <h2>Detailed Report Records</h2>

    <?php if (empty($reports)): ?>
        <p class="empty">No reports found for this reporting period.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Reported By</th>
                    <th>Status</th>
                    <th>Date Reported</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($reports as $report): ?>
                    <tr>
                        <td><?= escape($report["reportID"]) ?></td>
                        <td><?= escape($report["title"]) ?></td>
                        <td><?= escape($report["categoryName"]) ?></td>
                        <td><?= escape($report["address"]) ?></td>
                        <td>
                            <?= escape(
                                trim(
                                    ($report["firstName"] ?? "") . " " .
                                    ($report["lastName"] ?? "")
                                )
                            ) ?>
                        </td>
                        <td><?= escape($report["statusName"]) ?></td>
                        <td><?= escape($report["dateReported"]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
