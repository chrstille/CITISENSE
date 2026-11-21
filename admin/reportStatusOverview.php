<?php

/**
 * CitiSense: Read-only Report Status Overview.
 *
 * Opened by clicking a category row on the Reports page (or the dashboard).
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
        'title' => trim((string) ($report['title'] ?? '')) !== '' ? $report['title'] : $report['categoryName'] . ' report',
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
        'images' => [],
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
   ALL PHOTOS FOR THE REPORTS ON THIS PAGE
============================================ */

if ($details) {
    $imageStmt = $conn->prepare(
        'SELECT ri.reportID, ri.imagePath
           FROM reportImages ri
           INNER JOIN reports r ON r.reportID = ri.reportID
          WHERE r.barangayID = ?
          ORDER BY ri.uploadedAt ASC'
    );

    if ($imageStmt) {
        $imageStmt->bind_param('s', $barangayID);
        $imageStmt->execute();
        $imageResult = $imageStmt->get_result();

        while ($row = $imageResult->fetch_assoc()) {
            $id = (string) $row['reportID'];
            $path = trim((string) $row['imagePath']);

            if ($path !== '' && isset($details[$id]) && !in_array($path, $details[$id]['images'] ?? [], true)) {
                $details[$id]['images'][] = $path;
            }
        }

        $imageStmt->close();
    }
}

foreach ($details as $id => &$detail) {
    if (empty($detail['images'])) {
        $detail['images'] = $detail['image'] !== '' ? [$detail['image']] : [];
    }
}
unset($detail);

/* ============================================
   VIEW HELPERS
============================================ */

function boardStatusClass(string $status): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($status)), '-');
}

function boardTimeAgo($value, $secondsAgo = null): string
{
    $stamp = $value ? strtotime((string) $value) : false;

    if ($stamp === false) {
        return '';
    }

    $seconds = $secondsAgo !== null ? max(0, (int) $secondsAgo) : max(0, time() - $stamp);

    if ($seconds < 3600) return max(1, (int) floor($seconds / 60)) . 'm ago';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h ago';
    if ($seconds < 604800) return floor($seconds / 86400) . 'd ago';

    return date('M j, Y', $stamp);
}

function boardCategoryIcon(string $name): string
{
    $name = strtolower($name);
    if (str_contains($name, 'waste') || str_contains($name, 'garbage')) return 'bi-trash3';
    if (str_contains($name, 'pothole') || str_contains($name, 'road')) return 'bi-cone-striped';
    if (str_contains($name, 'drain') || str_contains($name, 'flood')) return 'bi-water';
    return 'bi-three-dots';
}

/* Sections, in the order an admin should look at them */
$displayStatuses = ['STAT004', 'STAT002', 'STAT003'];

$statusHints = [
    'STAT004' => 'Past their target completion date',
    'STAT002' => 'Accepted and being worked on',
    'STAT003' => 'Completed and closed',
];

$selectedCategoryName = '';
foreach ($allMatchingReports as $report) {
    if ($categoryID !== '' && (string) $report['categoryID'] === $categoryID) {
        $selectedCategoryName = (string) $report['categoryName'];
        break;
    }
}

if ($categoryID !== '' && $selectedCategoryName === '') {
    $nameStmt = $conn->prepare('SELECT categoryName FROM categories WHERE categoryID = ? LIMIT 1');
    if ($nameStmt) {
        $nameStmt->bind_param('s', $categoryID);
        $nameStmt->execute();
        $selectedCategoryName = (string) ($nameStmt->get_result()->fetch_assoc()['categoryName'] ?? '');
        $nameStmt->close();
    }
}

$pageTitle = $selectedCategoryName !== '' ? $selectedCategoryName : 'All categories';

$initialTab = in_array($selectedStatus, ['STAT004', 'STAT002', 'STAT003'], true)
    ? $selectedStatus
    : 'all';

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
    <title><?= boardEscape($pageTitle) ?> overview · CitiSense</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/reportStatusOverview.css?v=3">
    <script src="../js/reportStatusOverview.js?v=4" defer></script>
<?php include __DIR__ . "/partial/head.php"; ?>
</head>
<body>
    <?php include __DIR__ . '/partial/sidebar.php'; ?>

    <main class="main-content ov-main">

        <a class="ov-back" href="<?= boardEscape($backUrl) ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back to Reports
        </a>

        <header class="page-header ov-header">
            <div class="ov-title">
                <span class="ov-title-icon"><i class="bi <?= boardCategoryIcon($pageTitle) ?>"></i></span>
                <div>
                    <h1><?= boardEscape($pageTitle) ?></h1>
                    <p>
                        Report overview for <strong><?= boardEscape($barangayName) ?></strong>
                        · <?= count($allMatchingReports) ?> <?= count($allMatchingReports) === 1 ? 'report' : 'reports' ?>
                    </p>
                </div>
            </div>

        </header>

        <!-- STATUS TABS -->
        <nav class="ov-tabs" role="tablist" aria-label="Filter by status">
            <button type="button" class="ov-tab<?= $initialTab === 'all' ? ' active' : '' ?>" data-tab="all" role="tab">
                All <span><?= count($allMatchingReports) ?></span>
            </button>

            <?php foreach ($displayStatuses as $statusID): ?>
                <?php $tone = boardStatusClass($statusLabels[$statusID]); ?>
                <button
                    type="button"
                    class="ov-tab ov-tab-<?= $tone ?><?= $initialTab === $statusID ? ' active' : '' ?>"
                    data-tab="<?= $statusID ?>"
                    role="tab"
                >
                    <i class="ov-dot"></i>
                    <?= boardEscape($statusLabels[$statusID]) ?>
                    <span><?= count($grouped[$statusID]) ?></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <p class="ov-note">
            <i class="bi bi-info-circle"></i>
            New <b>Pending</b> reports aren't listed here. Review them from the Reports page using the
            <i class="bi bi-eye"></i> button.
        </p>

        <!-- STATUS SECTIONS -->
        <div class="ov-sections" id="ovSections" data-initial-tab="<?= boardEscape($initialTab) ?>">

            <?php foreach ($displayStatuses as $statusID): ?>
                <?php
                $items = $grouped[$statusID];
                $label = $statusLabels[$statusID];
                $tone = boardStatusClass($label);
                ?>

                <section
                    class="ov-section ov-section-<?= $tone ?>"
                    data-section="<?= $statusID ?>"
                    data-empty="<?= $items ? '0' : '1' ?>"
                >

                    <header class="ov-section-header">
                        <span class="pill pill-<?= $tone ?>"><?= boardEscape($label) ?></span>
                        <small><?= boardEscape($statusHints[$statusID]) ?></small>
                        <span class="ov-section-count"><?= count($items) ?></span>
                    </header>

                    <?php if (!$items): ?>
                        <p class="ov-section-empty">No <?= boardEscape(strtolower($label)) ?> reports.</p>
                    <?php else: ?>

                        <div class="ov-grid">
                            <?php foreach ($items as $report): ?>
                                <?php
                                $reportID = (string) $report['reportID'];
                                $images = $details[$reportID]['images'] ?? [];
                                $title = trim((string) ($report['title'] ?? '')) !== ''
                                    ? $report['title']
                                    : $report['categoryName'] . ' report';
                                $search = strtolower(implode(' ', [
                                    $reportID,
                                    $title,
                                    $report['address'] ?? '',
                                    $report['residentName'],
                                    $report['categoryName'],
                                ]));
                                ?>

                                <button
                                    type="button"
                                    class="report-board-card ov-card"
                                    data-report-id="<?= boardEscape($reportID) ?>"
                                    data-search="<?= boardEscape($search) ?>"
                                    aria-label="View details of report <?= boardEscape($reportID) ?>"
                                >
                                    <span class="ov-card-media">
                                        <?php if ($images): ?>
                                            <img
                                                src="<?= boardEscape($images[0]) ?>"
                                                alt=""
                                                loading="lazy"
                                                onerror="this.parentElement.classList.add('is-broken'); this.remove();"
                                            >
                                            <?php if (count($images) > 1): ?>
                                                <span class="ov-card-count"><i class="bi bi-images"></i> <?= count($images) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="ov-card-noimg"><i class="bi bi-image"></i> No photo</span>
                                        <?php endif; ?>

                                        <span class="pill pill-<?= $tone ?> ov-card-pill"><?= boardEscape($label) ?></span>
                                    </span>

                                    <span class="ov-card-body">
                                        <span class="ov-card-top">
                                            <span class="ov-card-id">#<?= boardEscape($reportID) ?></span>
                                            <span class="ov-card-time"><?= boardEscape(boardTimeAgo($report['dateReported'], $report['secondsAgo'] ?? null)) ?></span>
                                        </span>

                                        <strong class="ov-card-title"><?= boardEscape($title) ?></strong>

                                        <span class="ov-card-line">
                                            <i class="bi bi-geo-alt"></i>
                                            <?= boardEscape($report['address'] ?: 'Location unavailable') ?>
                                        </span>

                                        <span class="ov-card-line">
                                            <i class="bi bi-person"></i>
                                            <?= boardEscape($report['residentName']) ?>
                                        </span>

                                        <?php if (boardDate($report['timeline']) !== ''): ?>
                                            <span class="ov-card-target ov-target-<?= $tone ?>">
                                                <i class="bi bi-flag"></i>
                                                Target <?= boardEscape(boardDate($report['timeline'], 'M j, Y')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>

                </section>

            <?php endforeach; ?>

            <div class="ov-no-results" id="ovNoResults" hidden>
                <i class="bi bi-search"></i>
                <strong>No matching reports</strong>
                <span>Try a different search or status.</span>
            </div>

        </div>

    </main>

    <!-- REPORT DETAILS DRAWER (read-only) -->
    <div class="report-board-drawer-overlay" id="reportBoardOverlay" hidden>
        <aside class="report-board-drawer" id="reportBoardDrawer" role="dialog" aria-modal="true"
               aria-labelledby="boardDetailTitle" tabindex="-1">

            <header class="ovd-header">
                <span class="ovd-header-label">Report details</span>
                <button type="button" id="boardDrawerClose" class="ovd-close" aria-label="Close report details">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="ovd-body">

                <!-- Photos -->
                <div class="ovd-gallery">
                    <a class="ovd-gallery-main" id="boardImageLink" href="#" target="_blank" rel="noopener">
                        <img id="boardImage" src="" alt="Report photo">
                        <span class="ovd-zoom"><i class="bi bi-arrows-fullscreen"></i> Open full size</span>
                    </a>
                    <div class="ovd-gallery-empty" id="boardImageEmpty" hidden>
                        <i class="bi bi-image"></i>
                        <span>No photo was attached to this report</span>
                    </div>
                    <div class="ovd-thumbs" id="boardImageThumbs"></div>
                </div>

                <div class="ovd-title">
                    <div class="ovd-tags">
                        <span class="ovd-tag" id="boardDetailCategory"></span>
                        <span id="boardDetailStatus"></span>
                    </div>
                    <h2 id="boardDetailTitle"></h2>
                </div>

                <!-- Progress -->
                <ol class="ovd-steps" id="boardSteps">
                    <li data-step="0"><span class="ovd-step-dot">1</span><span>Pending</span></li>
                    <li data-step="1"><span class="ovd-step-dot">2</span><span id="boardStepMiddle">In Progress</span></li>
                    <li data-step="2"><span class="ovd-step-dot">3</span><span>Resolved</span></li>
                </ol>

                <!-- Key facts -->
                <dl class="ovd-facts">
                    <div><dt><i class="bi bi-hash"></i> Report ID</dt><dd id="boardDetailReportID"></dd></div>
                    <div><dt><i class="bi bi-calendar3"></i> Date reported</dt><dd id="boardDetailDate"></dd></div>
                    <div class="ovd-wide"><dt><i class="bi bi-geo-alt"></i> Location</dt><dd id="boardDetailLocation"></dd></div>
                    <div class="ovd-wide" id="boardDetailTimelineRow" hidden><dt><i class="bi bi-flag"></i> Target completion</dt><dd id="boardDetailTimeline"></dd></div>
                </dl>

                <section class="ovd-panel">
                    <h3><i class="bi bi-chat-left-text"></i> Description</h3>
                    <p id="boardDetailDescription" class="ovd-description"></p>
                </section>

                <section class="ovd-panel">
                    <h3><i class="bi bi-person"></i> Reported by</h3>
                    <div class="ovd-resident">
                        <span class="ovd-avatar" id="boardDetailInitial">R</span>
                        <div>
                            <strong id="boardDetailResident"></strong>
                            <div class="ovd-contacts">
                                <span><i class="bi bi-envelope"></i> <span id="boardDetailEmail"></span></span>
                                <span><i class="bi bi-telephone"></i> <span id="boardDetailContact"></span></span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ovd-panel" id="boardHistoryPanel" hidden>
                    <h3><i class="bi bi-clock-history"></i> Status history</h3>
                    <div class="ovd-history" id="boardHistoryList"></div>
                </section>

            </div>
        </aside>
    </div>

    <script type="application/json" id="reportBoardPayload"><?= $jsonDetails ?: '{}' ?></script>
</body>
</html>
