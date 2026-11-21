<?php
// TEMPORARY: Show PHP errors while debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * CitiSense: Read-only Report Status Overview.
 *
 * Navigated to from the Reports page's Quick Actions > Manage Reports.
 * The eye-action drawer and original/duplicate management are NOT changed.
 *
 * GET parameters (from reports.php):
 *   start_date, end_date, categoryID, statusID
 *   statusID=""    => Default: Overdue (when present), In Progress, Resolved
 *   statusID="all" => All: Overdue, Pending, In Progress, Resolved
 *   other statusID => Only the selected status (STAT001-STAT004)
 */

session_start();

if (empty($_SESSION['admin_id']) || empty($_SESSION['barangay_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function boardEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function boardDate($value, string $format = 'M j, Y g:i A'): string
{
    $stamp = $value ? strtotime((string) $value) : false;
    return $stamp === false ? '' : date($format, $stamp);
}

function boardValidDate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

$barangayID = (string) $_SESSION['barangay_id'];
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$categoryID = trim((string) ($_GET['categoryID'] ?? ''));
$selectedStatus = trim((string) ($_GET['statusID'] ?? ''));

if ($startDate !== '' && !boardValidDate($startDate)) {
    $startDate = '';
}
if ($endDate !== '' && !boardValidDate($endDate)) {
    $endDate = '';
}
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$statusLabels = [
    'STAT004' => 'Overdue',
    'STAT001' => 'Pending',
    'STAT002' => 'In Progress',
    'STAT003' => 'Resolved',
];

if (!in_array($selectedStatus, ['', 'all', ...array_keys($statusLabels)], true)) {
    $selectedStatus = '';
}

// Only the three categories still supported by the Reports page.
$categoryNames = ['Waste Accumulation', 'Potholes', 'Others'];

// Always preserve the original page's filtering when navigating back.
$backQuery = array_filter([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'categoryID' => $categoryID,
    'statusID' => $selectedStatus,
], static fn($value) => $value !== '');
$backUrl = 'reports.php' . ($backQuery ? '?' . http_build_query($backQuery) : '');

// Retrieve barangay name to preserve the Reports-page context.
$barangayName = 'Your Barangay';
$stmt = $conn->prepare('SELECT barangayName FROM barangay WHERE barangayID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $barangayID);
    $stmt->execute();
    $barangayRow = $stmt->get_result()->fetch_assoc();
    $barangayName = (string) ($barangayRow['barangayName'] ?? $barangayName);
    $stmt->close();
}

// Base filters. Never include reports belonging to another barangay.

$where = [
    'r.barangayID = ?',
    "c.categoryName IN ('Waste Accumulation', 'Potholes', 'Others')",
    "r.statusID IN ('STAT004', 'STAT002', 'STAT003')"
];

$types = 's';
$params = [$barangayID];

// Only show reports from the selected category.
if ($categoryID !== '') {
    $where[] = 'r.categoryID = ?';
    $types .= 's';
    $params[] = $categoryID;
}


// Use the same actual statusID that the existing report workflow saves.
// Do not recalculate or fabricate overdue status from r.timeline here.
$sql = '
    SELECT
        r.reportID,
        r.categoryID,
        r.userID,
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
    INNER JOIN categories c ON c.categoryID = r.categoryID
    INNER JOIN stats s ON s.statusID = r.statusID
    LEFT JOIN location l ON l.locationID = r.locationID
    LEFT JOIN users u ON u.userID = r.userID
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY r.dateReported DESC, r.reportID DESC
';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('CitiSense report status overview SQL error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load reports.');
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$allMatchingReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$grouped = array_fill_keys(array_keys($statusLabels), []);
$details = [];

foreach ($allMatchingReports as $report) {
    $statusID = (string) $report['statusID'];
    if (!isset($grouped[$statusID])) {
        continue;
    }

    $reportID = (string) $report['reportID'];
    $resident = trim((string) ($report['firstName'] ?? '') . ' ' . (string) ($report['lastName'] ?? ''));
    if ($resident === '') {
        $resident = 'Resident unavailable';
    }
    $report['residentName'] = $resident;
    $grouped[$statusID][] = $report;

    $details[$reportID] = [
        'reportID' => $reportID,
        'category' => $report['categoryName'],
        'location' => $report['address'] ?: 'Location unavailable',
        'description' => $report['description'] ?: 'No description provided.',
        'statusID' => $statusID,
        'status' => $report['statusName'],
        'dateReported' => boardDate($report['dateReported']),
        'timeline' => boardDate($report['timeline']),
        'resident' => $resident,
        'userID' => $report['userID'],
        'email' => $report['email'] ?: 'Not provided',
        'contact' => $report['contactNo'] ?: 'Not provided',
        'image' => $report['imagePath'] ?: '',
        'history' => [],
    ];
}

// Retrieve real reportHistory records, scoped to the admin's barangay.
// Only copy histories belonging to a report in this board.
if ($details) {
    $historySQL = '
        SELECT
            h.reportID,
            h.statusID,
            h.remarks,
            h.dateChanged,
            s.statusName,
            u.firstName,
            u.lastName
        FROM reportHistory h
        INNER JOIN reports r ON r.reportID = h.reportID
        INNER JOIN stats s ON s.statusID = h.statusID
        LEFT JOIN users u ON u.userID = h.userID
        WHERE r.barangayID = ?
        ORDER BY h.dateChanged ASC, h.historyID ASC
    ';

    $historyStmt = $conn->prepare($historySQL);
    if (!$historyStmt) {
        error_log('CitiSense report history SQL error: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load report status history.');
    }
    $historyStmt->bind_param('s', $barangayID);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    while ($entry = $historyResult->fetch_assoc()) {
        $id = (string) $entry['reportID'];
        if (!isset($details[$id])) {
            continue;
        }
        $changedBy = trim((string) ($entry['firstName'] ?? '') . ' ' . (string) ($entry['lastName'] ?? ''));
        $details[$id]['history'][] = [
            'status' => $entry['statusName'],
            'dateChanged' => boardDate($entry['dateChanged']),
            'remarks' => $entry['remarks'] ?? '',
            'changedBy' => $changedBy,
        ];
    }
    $historyStmt->close();
}

/* ============================================
   REPORT STATUS COLUMN DISPLAY
============================================ */

$displayStatuses = [];

// Show Overdue first, only when reports exist.
if (!empty($grouped['STAT004'])) {
    $displayStatuses[] = 'STAT004';
}

// Always show In Progress and Resolved.
$displayStatuses[] = 'STAT002';
$displayStatuses[] = 'STAT003';

$jsonDetails = json_encode(
    $details,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CitiSense - Report Status Overview</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/reportStatusOverview.css">
    <script src="../js/reportStatusOverview.js?v=3" defer></script>
</head>
<body>
    <?php include __DIR__ . '/partial/sidebar.php'; ?>

    <main class="main-content report-board-main">
        <header class="report-board-header">
            <div>
                <a class="report-board-back" href="<?= boardEscape($backUrl) ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Back to Reports
                </a>
                <h1>Report Overview</h1>
                <span class="report-board-barangay">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    <?= boardEscape($barangayName) ?>
                </span>
            </div>
            <span class="report-board-total">
                <?= count($allMatchingReports) ?> <?= count($allMatchingReports) === 1 ? 'report' : 'reports' ?>
            </span>
        </header>

        <section class="report-board-columns report-board-columns-<?= count($displayStatuses) ?>"
                 aria-label="Reports grouped by current status">
            <?php foreach ($displayStatuses as $statusID): ?>
                <?php $items = $grouped[$statusID]; ?>
                <div class="report-board-column report-board-<?= boardEscape(strtolower(str_replace(' ', '-', $statusLabels[$statusID]))) ?>">
                    <header class="report-board-column-header">
                        <div class="report-board-column-heading">
                            <span class="report-board-status-dot" aria-hidden="true"></span>
                            <h2><?= boardEscape($statusLabels[$statusID]) ?></h2>
                        </div>
                        <span class="report-board-column-count"><?= count($items) ?></span>
                    </header>
                    <div class="report-board-stack">
                        <?php if (!$items): ?>
                            <p class="report-board-empty">No <?= boardEscape(strtolower($statusLabels[$statusID])) ?> reports.</p>
                        <?php endif; ?>

                        <?php foreach ($items as $report): ?>
                            <button type="button" class="report-board-card"
                                    data-report-id="<?= boardEscape($report['reportID']) ?>"
                                    aria-label="View details of report <?= boardEscape($report['reportID']) ?>">
                                <span class="report-board-card-top">
                                    <strong><?= boardEscape($report['reportID']) ?></strong>
                                    <span class="report-board-view-icon" aria-hidden="true"><i class="bi bi-arrow-up-right"></i></span>
                                </span>
                                <span class="report-board-card-category"><?= boardEscape($report['categoryName']) ?></span>
                                <?php if (!empty($report['title'])): ?>
                                    <span class="report-board-card-title"><?= boardEscape($report['title']) ?></span>
                                <?php endif; ?>
                                <span class="report-board-card-line"><i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    <?= boardEscape($report['address'] ?: 'Location unavailable') ?>
                                </span>
                                <span class="report-board-card-line"><i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <?= boardEscape(boardDate($report['dateReported'])) ?>
                                </span>
                                <span class="report-board-card-line"><i class="bi bi-person" aria-hidden="true"></i>
                                    <?= boardEscape($report['residentName']) ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </main>

    <!-- Separate, read-only report details drawer. No Back button. -->
    <div class="report-board-drawer-overlay" id="reportBoardOverlay" hidden>
        <aside class="report-board-drawer" id="reportBoardDrawer" role="dialog" aria-modal="true"
               aria-labelledby="boardDrawerHeading" tabindex="-1">
            <div class="report-board-drawer-heading">
                <h2 id="boardDrawerHeading">Report Details</h2>
                <button type="button" id="boardDrawerClose" class="report-board-drawer-close"
                        aria-label="Close report details"><i class="bi bi-x-lg"></i></button>
            </div>

            <section class="report-board-details-section" aria-labelledby="boardInfoHeading">
                <h3 id="boardInfoHeading"><i class="bi bi-file-earmark-text"></i> Report Information</h3>
                <dl class="report-board-detail-list">
                    <div><dt>Category</dt><dd id="boardDetailCategory"></dd></div>
                    <div><dt>Report ID</dt><dd id="boardDetailReportID"></dd></div>
                    <div><dt>Location</dt><dd id="boardDetailLocation"></dd></div>
                    <div><dt>Description</dt><dd id="boardDetailDescription"></dd></div>
                    <div><dt>Status</dt><dd id="boardDetailStatus"></dd></div>
                </dl>
            </section>

            <section class="report-board-detail-box">
                <dl class="report-board-detail-list">
                    <div><dt>Date Reported</dt><dd id="boardDetailDate"></dd></div>
                    <div id="boardDetailTimelineRow" hidden><dt>Expected Completion</dt><dd id="boardDetailTimeline"></dd></div>
                </dl>
            </section>

            <section class="report-board-detail-box" aria-labelledby="boardResidentHeading">
                <h3 id="boardResidentHeading"><i class="bi bi-person"></i> Resident Information</h3>
                <dl class="report-board-detail-list">
                    <div><dt>Resident Name</dt><dd id="boardDetailResident"></dd></div>
                    <div><dt>Email Address</dt><dd id="boardDetailEmail"></dd></div>
                    <div><dt>Contact Number</dt><dd id="boardDetailContact"></dd></div>
                </dl>
            </section>

            <section class="report-board-detail-box" id="boardHistoryPanel" hidden
                     aria-labelledby="boardHistoryHeading">
                <h3 id="boardHistoryHeading"><i class="bi bi-clock-history"></i> Report Status History</h3>
                <div class="report-board-history-list" id="boardHistoryList"></div>
            </section>
        </aside>
    </div>
    <script type="application/json" id="reportBoardPayload"><?= $jsonDetails ?: '{}' ?></script>
</body>
</html>
