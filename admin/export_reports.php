<?php

session_start();



// Prevent PHP warnings from corrupting exported files.

ini_set('display_errors', '0');

ini_set('log_errors', '1');



// Continue logging deprecations without printing them.

error_reporting(E_ALL);



if (empty($_SESSION['admin_id']) || empty($_SESSION['barangay_id'])) {

    http_response_code(403);

    exit('Unauthorized access.');

}



require_once __DIR__ . '/../config/database.php';



date_default_timezone_set('Asia/Manila');



$format = strtolower((string) ($_GET['format'] ?? ''));



if (!in_array($format, ['pdf', 'csv', 'docx'], true)) {

    http_response_code(400);

    exit('Invalid export format.');

}





$barangayID = (string) $_SESSION['barangay_id'];



$period = strtolower((string) ($_GET['period'] ?? ''));



$periodOptions = [

    'day' => 'Within the Day',

    '3months' => 'Every 3 Months',

    '6months' => 'Every 6 Months',

    'year' => 'Yearly'

];



if (!array_key_exists($period, $periodOptions)) {

    http_response_code(400);

    exit('Invalid reporting period.');

}



$now = new DateTimeImmutable('now');

$endDate = $now->modify('+1 second');



switch ($period) {

    case 'day':

        $start = $now->setTime(0, 0, 0);

        $endDate = $start->modify('+1 day');

        break;



    case '3months':

        $start = $now->modify('-3 months');

        break;



    case '6months':

        $start = $now->modify('-6 months');

        break;



    case 'year':

        $start = $now->modify('-1 year');

        break;

}



$startDate = $start->format('Y-m-d H:i:s');

$endDateString = $endDate->format('Y-m-d H:i:s');



$periodLabel = $periodOptions[$period];

$monthLabel = $periodLabel;



$rangeEnd = $period === 'day'

    ? $endDate->modify('-1 second')->format('F d, Y h:i A')

    : $now->format('F d, Y h:i A');



$generatedAt = $now->format('F d, Y h:i A');



$filename = 'CitiSense_Analytics_' .

    $period . '_' . $now->format('Y_m_d_His');



function exportEscape($value): string

{

    return htmlspecialchars(

        (string) ($value ?? ''),

        ENT_QUOTES | ENT_SUBSTITUTE,

        'UTF-8'

    );

}



function exportPercent(int $count, int $total): string

{

    return $total > 0

        ? number_format(($count / $total) * 100, 1) . '%'

        : '0.0%';

}



function exportCsvSafe($value): string

{

    $value = (string) ($value ?? '');



    return preg_match('/^**\s***[=+\\-@]/u', $value)

        ? "'" . $value

        : $value;

}



function exportCsvRow($stream, array $values): void

{

    fputcsv(

        $stream,

        array_map('exportCsvSafe', $values),

        ',',

        '"',

        '',

        "\r\n"

    );

}



/* BARANGAY INFORMATION */



$stmt = $conn->prepare(

    'SELECT barangayName

     FROM barangay

     WHERE barangayID = ?

     LIMIT 1'

);



$stmt->bind_param('s', $barangayID);

$stmt->execute();



$barangay = $stmt->get_result()->fetch_assoc();

$stmt->close();



if (!$barangay) {

    http_response_code(404);

    exit('Barangay not found.');

}



$barangayName = $barangay['barangayName'];



/* REPORT TOTALS */



$stmt = $conn->prepare(

    'SELECT

        COUNT(*) AS allTimeTotal,

        COALESCE(SUM(

            CASE

                WHEN dateReported >= ?

                 AND dateReported < ?

                THEN 1 ELSE 0

            END

        ), 0) AS monthlyTotal

     FROM reports

     WHERE barangayID = ?'

);



$stmt->bind_param(

    'sss',

    $startDate,

    $endDateString,

    $barangayID

);

$stmt->execute();



$totals = $stmt->get_result()->fetch_assoc();

$stmt->close();



$allTimeTotal = (int) $totals['allTimeTotal'];

$monthlyTotal = (int) $totals['monthlyTotal'];



/* CATEGORY ANALYTICS */



$stmt = $conn->prepare(

    'SELECT

        c.categoryID,

        c.categoryName,

        COUNT(r.reportID) AS allTimeCount,

        COALESCE(SUM(

            CASE

                WHEN r.dateReported >= ?

                 AND r.dateReported < ?

                THEN 1 ELSE 0

            END

        ), 0) AS monthlyCount

     FROM categories c

     LEFT JOIN reports r

        ON r.categoryID = c.categoryID

       AND r.barangayID = ?

     GROUP BY c.categoryID, c.categoryName

     ORDER BY c.categoryName ASC'

);



$stmt->bind_param('sss', $startDate, $endDateString, $barangayID);

$stmt->execute();



$categoryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();



/* STATUS ANALYTICS */



$stmt = $conn->prepare(

    'SELECT

        s.statusID,

        s.statusName,

        COUNT(r.reportID) AS allTimeCount,

        COALESCE(SUM(

            CASE

                WHEN r.dateReported >= ?

                 AND r.dateReported < ?

                THEN 1 ELSE 0

            END

        ), 0) AS monthlyCount

     FROM stats s

     LEFT JOIN reports r

        ON r.statusID = s.statusID

       AND r.barangayID = ?

     GROUP BY s.statusID, s.statusName

     ORDER BY s.statusID ASC'

);



$stmt->bind_param('sss', $startDate, $endDateString, $barangayID);

$stmt->execute();



$statusRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();



/* CATEGORY BY STATUS ANALYTICS */



$stmt = $conn->prepare(

    'SELECT

        r.categoryID,

        r.statusID,

        COUNT(*) AS allTimeCount,

        COALESCE(SUM(

            CASE

                WHEN r.dateReported >= ?

                 AND r.dateReported < ?

                THEN 1 ELSE 0

            END

        ), 0) AS monthlyCount

     FROM reports r

     WHERE r.barangayID = ?

     GROUP BY r.categoryID, r.statusID'

);



$stmt->bind_param('sss', $startDate, $endDateString, $barangayID);

$stmt->execute();



$crossRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();



/* BUILD CATEGORY / STATUS MATRIX */



$crossCounts = [];



foreach ($crossRows as $row) {

    $categoryID = (string) $row['categoryID'];

    $statusID = (string) $row['statusID'];



    $crossCounts[$categoryID][$statusID] = [

        'allTime' => (int) $row['allTimeCount'],

        'monthly' => (int) $row['monthlyCount']

    ];

}



function exportCrossCount(

    array $crossCounts,

    string $categoryID,

    string $statusID,

    string $period

): int {

    return (int) (

        $crossCounts[$categoryID][$statusID][$period] ?? 0

    );

}


/* SHARED REPORT DATA FOR CSV AND WORD */

$stmt = $conn->prepare(
    'SELECT
        r.reportID,
        r.title,
        r.description,
        r.dateReported,
        c.categoryName,
        s.statusName,
        l.address
     FROM reports r
     LEFT JOIN categories c
        ON c.categoryID = r.categoryID
     LEFT JOIN stats s
        ON s.statusID = r.statusID
     LEFT JOIN location l
        ON l.locationID = r.locationID
     WHERE r.barangayID = ?
       AND r.dateReported >= ?
       AND r.dateReported < ?
     ORDER BY r.dateReported DESC, r.reportID DESC'
);

$stmt->bind_param(
    'sss',
    $barangayID,
    $startDate,
    $endDateString
);

$stmt->execute();

$detailedReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$rangeStartLabel = $start->format('F d, Y h:i A');

$rangeEndLabel = $period === 'day'
    ? $endDate->modify('-1 second')->format('F d, Y h:i A')
    : $now->format('F d, Y h:i A');


/* CSV EXPORT */
/* =========================================================
   CSV EXPORT - SELECTED REPORTING PERIOD ONLY
========================================================= */

if ($format === 'csv') {

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '.csv"'
    );

    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');

    fwrite($out, "\xEF\xBB\xBF");

    // REPORT HEADER

    exportCsvRow($out, ['CITISENSE REPORT ANALYTICS']);
    exportCsvRow($out, ['Barangay', $barangayName]);
    exportCsvRow($out, ['Reporting Period', $periodLabel]);
    exportCsvRow($out, ['Start Date', $rangeStartLabel]);
    exportCsvRow($out, ['End Date', $rangeEndLabel]);
    exportCsvRow($out, ['Generated At', $generatedAt]);
    exportCsvRow($out, []);

    // REPORT OVERVIEW

    exportCsvRow($out, ['REPORT OVERVIEW']);

    exportCsvRow($out, [
        'Total Reports Submitted',
        $monthlyTotal
    ]);

    exportCsvRow($out, []);

    // CATEGORY ANALYTICS

    exportCsvRow($out, ['CATEGORY ANALYTICS']);

    exportCsvRow($out, [
        'Category',
        'Reports',
        'Percentage'
    ]);

    foreach ($categoryRows as $row) {

        $count = (int) $row['monthlyCount'];

        exportCsvRow($out, [
            $row['categoryName'],
            $count,
            exportPercent($count, $monthlyTotal)
        ]);
    }

    exportCsvRow($out, []);

    // STATUS ANALYTICS

    exportCsvRow($out, ['STATUS ANALYTICS']);

    exportCsvRow($out, [
        'Status',
        'Reports',
        'Percentage'
    ]);

    foreach ($statusRows as $row) {

        $count = (int) $row['monthlyCount'];

        exportCsvRow($out, [
            $row['statusName'],
            $count,
            exportPercent($count, $monthlyTotal)
        ]);
    }

    exportCsvRow($out, []);

    // CATEGORY BY STATUS

    exportCsvRow($out, ['CATEGORY BY STATUS']);

    $headers = ['Category'];

    foreach ($statusRows as $status) {
        $headers[] = $status['statusName'];
    }

    $headers[] = 'Total';

    exportCsvRow($out, $headers);

    foreach ($categoryRows as $category) {

        $values = [$category['categoryName']];

        foreach ($statusRows as $status) {

            $values[] = exportCrossCount(
                $crossCounts,
                (string) $category['categoryID'],
                (string) $status['statusID'],
                'monthly'
            );
        }

        $values[] = (int) $category['monthlyCount'];

        exportCsvRow($out, $values);
    }

    exportCsvRow($out, []);

    // DETAILED REPORT RECORDS

    exportCsvRow($out, ['DETAILED REPORT RECORDS']);

    exportCsvRow($out, [
        'Report ID',
        'Title',
        'Category',
        'Location',
        'Status',
        'Date Reported'
    ]);

    if (empty($detailedReports)) {

        exportCsvRow($out, [
            'No reports were submitted during this period.'
        ]);

    } else {

        foreach ($detailedReports as $report) {

            exportCsvRow($out, [
                $report['reportID'],
                $report['title'],
                $report['categoryName'] ?? '',
                $report['address'] ?? '',
                $report['statusName'] ?? '',
                $report['dateReported']
            ]);
        }
    }

    fclose($out);

    exit;
}

/* PDF / WORD LIBRARIES */

$autoload = __DIR__ . '/../vendor/autoload.php';



if (!is_file($autoload)) {

    http_response_code(500);

    exit('Composer dependencies are missing.');

}



require_once $autoload;



/* WORD EXPORT */

/* =========================================================
   WORD EXPORT - SELECTED REPORTING PERIOD ONLY
========================================================= */

if ($format === 'docx') {

    $word = new \PhpOffice\PhpWord\PhpWord();

    $word->setDefaultFontName('Arial');
    $word->setDefaultFontSize(9);

    $section = $word->addSection([
        'orientation' => 'landscape',
        'marginTop' => 850,
        'marginBottom' => 850
    ]);

    // REPORT HEADER

    $section->addTitle('CitiSense Report Analytics', 1);

    $section->addText('Barangay: ' . $barangayName);

    $section->addText('Reporting Period: ' . $periodLabel);

    $section->addText(
        'Date Range: ' .
        $rangeStartLabel . ' to ' . $rangeEndLabel
    );

    $section->addText('Generated: ' . $generatedAt);

    // REPORT OVERVIEW

    $section->addTitle('Report Overview', 2);

    $section->addText(
        'Total Reports Submitted: ' . $monthlyTotal,
        ['bold' => true]
    );

    // CATEGORY ANALYTICS

    $section->addTitle('Category Analytics', 2);

    $table = $section->addTable([
        'borderSize' => 5,
        'borderColor' => 'CBD5E1',
        'cellMargin' => 80
    ]);

    $table->addRow();

    foreach (['Category', 'Reports', 'Percentage'] as $heading) {

        $table->addCell(3000)->addText(
            $heading,
            ['bold' => true]
        );
    }

    foreach ($categoryRows as $row) {

        $count = (int) $row['monthlyCount'];

        $table->addRow();

        $table->addCell(3000)->addText($row['categoryName']);

        $table->addCell(3000)->addText((string) $count);

        $table->addCell(3000)->addText(
            exportPercent($count, $monthlyTotal)
        );
    }

    // STATUS ANALYTICS

    $section->addTitle('Status Analytics', 2);

    $table = $section->addTable([
        'borderSize' => 5,
        'borderColor' => 'CBD5E1',
        'cellMargin' => 80
    ]);

    $table->addRow();

    foreach (['Status', 'Reports', 'Percentage'] as $heading) {

        $table->addCell(3000)->addText(
            $heading,
            ['bold' => true]
        );
    }

    foreach ($statusRows as $row) {

        $count = (int) $row['monthlyCount'];

        $table->addRow();

        $table->addCell(3000)->addText($row['statusName']);

        $table->addCell(3000)->addText((string) $count);

        $table->addCell(3000)->addText(
            exportPercent($count, $monthlyTotal)
        );
    }

    // CATEGORY BY STATUS

    $section->addTitle('Category by Status', 2);

    $table = $section->addTable([
        'borderSize' => 5,
        'borderColor' => 'CBD5E1',
        'cellMargin' => 60
    ]);

    $table->addRow();

    $table->addCell(2600)->addText(
        'Category',
        ['bold' => true]
    );

    foreach ($statusRows as $status) {

        $table->addCell(1500)->addText(
            $status['statusName'],
            ['bold' => true]
        );
    }

    $table->addCell(1200)->addText(
        'Total',
        ['bold' => true]
    );

    foreach ($categoryRows as $category) {

        $table->addRow();

        $table->addCell(2600)->addText(
            $category['categoryName']
        );

        foreach ($statusRows as $status) {

            $count = exportCrossCount(
                $crossCounts,
                (string) $category['categoryID'],
                (string) $status['statusID'],
                'monthly'
            );

            $table->addCell(1500)->addText((string) $count);
        }

        $table->addCell(1200)->addText(
            (string) $category['monthlyCount']
        );
    }

    // DETAILED REPORT RECORDS

    $section->addTitle('Detailed Report Records', 2);

    if (empty($detailedReports)) {

        $section->addText(
            'No reports were submitted during this reporting period.'
        );

    } else {

        $table = $section->addTable([
            'borderSize' => 5,
            'borderColor' => 'CBD5E1',
            'cellMargin' => 60
        ]);

        $table->addRow();

        $columns = [
            'Report ID',
            'Title',
            'Category',
            'Location',
            'Status',
            'Date Reported'
        ];

        foreach ($columns as $heading) {

            $table->addCell(1700)->addText(
                $heading,
                ['bold' => true]
            );
        }

        foreach ($detailedReports as $report) {

            $table->addRow();

            $values = [
                $report['reportID'],
                $report['title'],
                $report['categoryName'] ?? '',
                $report['address'] ?? '',
                $report['statusName'] ?? '',
                $report['dateReported']
            ];

            foreach ($values as $value) {

                $table->addCell(1700)->addText(
                    (string) ($value ?? '')
                );
            }
        }
    }

    // GENERATE WORD FILE

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter(
        $word,
        'Word2007'
    );

    $tmp = tempnam(sys_get_temp_dir(), 'citisense_');

    if ($tmp === false) {

        http_response_code(500);

        exit('Unable to create Word export file.');
    }

    $writer->save($tmp);

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '.docx"'
    );

    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');

    readfile($tmp);

    unlink($tmp);

    exit;
}


/* PDF EXPORT */



function exportAnalyticsHtml(

    string $title,

    string $period,

    string $countKey,

    int $total,

    array $categoryRows,

    array $statusRows,

    array $crossCounts

): string {



    $html = '<h2>' . exportEscape($title) . '</h2>';

    $html .= '<p><strong>Total Reports: ' .

        number_format($total) . '</strong></p>';



    $html .= '<h3>Category Analytics</h3>';

    $html .= '<table><tr>

        <th>Category</th><th>Reports</th><th>Percentage</th>

    </tr>';



    foreach ($categoryRows as $row) {

        $count = (int) $row[$countKey];



        $html .= '<tr><td>' . exportEscape($row['categoryName']) .

            '</td><td>' . number_format($count) .

            '</td><td>' . exportPercent($count, $total) .

            '</td></tr>';

    }



    $html .= '</table>';



    $html .= '<h3>Category Distribution</h3>';



    $maximum = 1;



    foreach ($categoryRows as $row) {

        $maximum = max($maximum, (int) $row[$countKey]);

    }



    foreach ($categoryRows as $row) {

        $count = (int) $row[$countKey];

        $width = ($count / $maximum) * 100;



        $html .= '<div class="chart-label">' .

            exportEscape($row['categoryName']) .

            ' — ' . number_format($count) . '</div>';



        $html .= '<div class="chart-track">

            <div class="chart-bar" style="width:' .

            number_format($width, 2, '.', '') .

            '%"></div></div>';

    }



    $html .= '<h3>Status Analytics</h3>';

    $html .= '<table><tr>

        <th>Status</th><th>Reports</th><th>Percentage</th>

    </tr>';



    foreach ($statusRows as $row) {

        $count = (int) $row[$countKey];



        $html .= '<tr><td>' . exportEscape($row['statusName']) .

            '</td><td>' . number_format($count) .

            '</td><td>' . exportPercent($count, $total) .

            '</td></tr>';

    }



    $html .= '</table>';



    $html .= '<h3>Category by Status</h3>';

    $html .= '<table><tr><th>Category</th>';



    foreach ($statusRows as $status) {

        $html .= '<th>' .

            exportEscape($status['statusName']) .

            '</th>';

    }



    $html .= '<th>Total</th></tr>';



    foreach ($categoryRows as $category) {

        $html .= '<tr><td>' .

            exportEscape($category['categoryName']) .

            '</td>';



        foreach ($statusRows as $status) {

            $count = exportCrossCount(

                $crossCounts,

                (string) $category['categoryID'],

                (string) $status['statusID'],

                $period

            );



            $html .= '<td>' . number_format($count) . '</td>';

        }



        $html .= '<td>' .

            number_format((int) $category[$countKey]) .

            '</td></tr>';

    }



    return $html . '</table>';

}


/* =========================================================
   PDF: selected reporting period only
   ========================================================= */

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 30px 26px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #243047; }
  h1 { font-size: 20px; color: #1d4ed8; margin-bottom: 8px; }
  h2 { font-size: 14px; margin-top: 20px; color: #1e40af; }
  h3 { font-size: 11px; margin-top: 15px; }
  .meta { color: #64748b; line-height: 1.8; margin-bottom: 16px; }
  .total { padding: 12px; background: #eff6ff; margin: 12px 0; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; margin: 8px 0 15px; }
  th, td { border: 1px solid #cbd5e1; padding: 7px; text-align: left; word-wrap: break-word; }
  th { background: #eff6ff; }
  .chart-label { margin: 7px 0 3px; }
  .chart-track { height: 12px; width: 100%; background: #e2e8f0; }
  .chart-bar { height: 12px; background: #2563eb; }
  .page-break { page-break-before: always; }
  tr { page-break-inside: avoid; }
</style>
</head>
<body>';

$html .= '<h1>CitiSense Report Analytics</h1>';
$html .= '<div class="meta">'
    . '<strong>Barangay:</strong> ' . exportEscape($barangayName) . '<br>'
    . '<strong>Reporting Period:</strong> ' . exportEscape($periodLabel) . '<br>'
    . '<strong>Date Range:</strong> ' . exportEscape($rangeStartLabel)
    . ' to ' . exportEscape($rangeEndLabel) . '<br>'
    . '<strong>Generated:</strong> ' . exportEscape($generatedAt)
    . '</div>';

$html .= '<div class="total"><strong>Total reports submitted in selected period: '
    . number_format($monthlyTotal) . '</strong></div>';

// The existing rendering function uses "monthly" as an internal key,
// but these counts actually represent whichever period the admin selected.
$html .= exportAnalyticsHtml(
    'Selected Period Analytics — ' . $periodLabel,
    'monthly',
    'monthlyCount',
    $monthlyTotal,
    $categoryRows,
    $statusRows,
    $crossCounts
);

$html .= '<div class="page-break"></div>';
$html .= '<h2>Detailed Report Records</h2>';

if (!$detailedReports) {
    $html .= '<p>No reports were submitted during the selected reporting period.</p>';
} else {
    $html .= '<table><thead><tr>'
        . '<th>Report ID</th><th>Title</th><th>Category</th>'
        . '<th>Location</th><th>Status</th><th>Date Reported</th>'
        . '</tr></thead><tbody>';

    foreach ($detailedReports as $report) {
        $html .= '<tr>'
            . '<td>' . exportEscape($report['reportID']) . '</td>'
            . '<td>' . exportEscape($report['title']) . '</td>'
            . '<td>' . exportEscape($report['categoryName'] ?? 'Uncategorized') . '</td>'
            . '<td>' . exportEscape($report['address'] ?? '') . '</td>'
            . '<td>' . exportEscape($report['statusName'] ?? 'Unspecified') . '</td>'
            . '<td>' . exportEscape($report['dateReported']) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '</body></html>';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$pdf = new \Dompdf\Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();
$pdf->stream($filename . '.pdf', ['Attachment' => true]);
exit;
