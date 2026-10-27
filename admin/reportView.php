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

$barangayID = (string) $_SESSION["barangay_id"];

/* =========================================================
   BARANGAY INFORMATION
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
$barangayName = $barangayData["barangayName"] ?? "Unknown Barangay";

$stmt->close();

/* =========================================================
   REPORT GROUPS
   Keep one row per required category.
   The JavaScript filters each report before recalculating
   the count and latest matching report.
========================================================= */

$sql = "
    SELECT
        c.categoryID,
        c.categoryName,
        (
            SELECT r2.locationID
            FROM reports r2
            WHERE r2.categoryID = c.categoryID
              AND r2.barangayID = ?
            ORDER BY r2.dateReported DESC, r2.reportID DESC
            LIMIT 1
        ) AS locationID,
        (
            SELECT l2.address
            FROM reports r2
            LEFT JOIN location l2
                ON l2.locationID = r2.locationID
            WHERE r2.categoryID = c.categoryID
              AND r2.barangayID = ?
            ORDER BY r2.dateReported DESC, r2.reportID DESC
            LIMIT 1
        ) AS address,
        COUNT(DISTINCT r.reportID) AS totalReports,
        MAX(r.dateReported) AS latestReport,
        GROUP_CONCAT(
            CONCAT_WS('~',
                COALESCE(r.reportID, ''),
                COALESCE(
                    DATE_FORMAT(r.dateReported, '%Y-%m-%d %H:%i:%s'),
                    ''
                ),
                COALESCE(s.statusName, ''),
                COALESCE(r.title, ''),
                COALESCE(r.description, ''),
                COALESCE(r.locationID, ''),
                COALESCE(l.address, '')
            )
            SEPARATOR '||'
        ) AS reportFilterData
    FROM categories c
    LEFT JOIN reports r
        ON r.categoryID = c.categoryID
        AND r.barangayID = ?
    LEFT JOIN location l
        ON r.locationID = l.locationID
    LEFT JOIN stats s
        ON r.statusID = s.statusID
    WHERE LOWER(TRIM(c.categoryName)) IN (
        'waste accumulation',
        'potholes',
        'blocked drainage',
        'others'
    )
    GROUP BY
        c.categoryID,
        c.categoryName
    ORDER BY
        CASE LOWER(TRIM(c.categoryName))
            WHEN 'waste accumulation' THEN 1
            WHEN 'potholes' THEN 2
            WHEN 'blocked drainage' THEN 3
            WHEN 'others' THEN 4
            ELSE 5
        END,
        CASE WHEN MAX(r.dateReported) IS NULL THEN 1 ELSE 0 END,
        latestReport DESC,
        c.categoryName ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sss",
    $barangayID,
    $barangayID,
    $barangayID
);
$stmt->execute();

$reportGroups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   TOTAL REPORT SUBMISSIONS
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(reportID) AS totalReports
     FROM reports
     WHERE barangayID = ?"
);

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$totalData = $stmt->get_result()->fetch_assoc();
$totalReports = (int) ($totalData["totalReports"] ?? 0);

$stmt->close();

/* =========================================================
   STATUS COUNTS
========================================================= */

$sql = "
    SELECT
        s.statusID,
        s.statusName,
        COUNT(r.reportID) AS reportCount
    FROM stats s
    LEFT JOIN reports r
        ON r.statusID = s.statusID
        AND r.barangayID = ?
    WHERE s.statusID IN (
        'STAT001',
        'STAT002',
        'STAT003',
        'STAT004'
    )
    GROUP BY
        s.statusID,
        s.statusName
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$statusResult = $stmt->get_result();

$statusCounts = [
    "Pending" => 0,
    "In Progress" => 0,
    "Overdue" => 0,
    "Resolved" => 0
];

while ($row = $statusResult->fetch_assoc()) {
    $statusName = $row["statusName"];

    if (array_key_exists($statusName, $statusCounts)) {
        $statusCounts[$statusName] = (int) $row["reportCount"];
    }
}

$stmt->close();

/* =========================================================
   STATUS PERCENTAGES
========================================================= */

$pendingPercent = 0;
$inProgressPercent = 0;
$overduePercent = 0;
$resolvedPercent = 0;

if ($totalReports > 0) {
    $pendingPercent = round(
        $statusCounts["Pending"] / $totalReports * 100
    );

    $inProgressPercent = round(
        $statusCounts["In Progress"] / $totalReports * 100
    );

    $overduePercent = round(
        $statusCounts["Overdue"] / $totalReports * 100
    );

    $resolvedPercent = round(
        $statusCounts["Resolved"] / $totalReports * 100
    );
}

/* =========================================================
   RECENT REPORT
========================================================= */

$sql = "
    SELECT
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
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $barangayID);
$stmt->execute();

$recentReport = $stmt->get_result()->fetch_assoc();

$stmt->close();

/* =========================================================
   UNREAD ADMIN NOTIFICATIONS
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS unreadNotifications
     FROM notification
     WHERE userID = ?
     AND isRead = FALSE"
);

$adminID = (string) $_SESSION["admin_id"];

$stmt->bind_param("s", $adminID);
$stmt->execute();

$notificationData = $stmt->get_result()->fetch_assoc();

$unreadNotifications = (int) (
    $notificationData["unreadNotifications"] ?? 0
);

$stmt->close();

/* =========================================================
   HTML ESCAPING
========================================================= */

function reportsEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

        if (!response.ok) {
            throw new Error("Unable to load report details.");
        }

        const html = await response.text();

        reportDrawerContent.innerHTML = html;

        // Resolve report images against the CitiSense project root.
        reportDrawerContent.querySelectorAll("img[src]").forEach(function (img) {
            const originalSrc = img.getAttribute("src") || "";
            const match = originalSrc.match(/^(?:\.?\.?\/)*uploads\/reports\/(.+)$/i);

            if (match) {
                img.src = "/citisense/uploads/reports/" + match[1];
            }
        });

        initializeReportDrawer();

        return true;

    } catch (error) {
        reportDrawerContent.innerHTML = `
            <div class="report-view-error">
                <i class="bi bi-exclamation-circle"></i>
                <p>Unable to load report details.</p>
            </div>
        `;

        showReportToast(
            error.message || "Unable to load report details.",
            true
        );

        return false;
    }
}

/* =========================================================
   OPEN REPORT GROUP
========================================================= */

document.querySelectorAll(".view-report-btn").forEach(button => {
    button.addEventListener("click", async function () {
        activeManageCategoryID = "";
        activeOriginalID = "";
        detailReturnScreen = null;

        openReportDrawer();

        const loaded = await loadReportDrawer(
            this.dataset.categoryId,
            this.dataset.locationId
        );

        if (loaded) {
            showGroupScreen();
        }
    });
});

reportDrawerOverlay.addEventListener("click", closeReportDrawer);

/* =========================================================
   SCREEN NAVIGATION
========================================================= */

function getReportRoot() {
    return reportDrawerContent.querySelector(
        ".report-view-content"
    );
}

function hideAllReportScreens(root) {
    root.querySelectorAll(
        "#reportGroupScreen, " +
        "#manageCategoriesScreen, " +
        ".manage-category-screen, " +
        ".manage-original-screen, " +
        "#individualReportScreen"
    ).forEach(screen => {
        screen.hidden = true;
    });
}

function showReportScreen(screen) {
    const root = getReportRoot();

    if (!root || !screen) {
        return;
    }

    hideAllReportScreens(root);

    screen.hidden = false;

    reportDrawer.scrollTop = 0;
}

function showGroupScreen() {
    const root = getReportRoot();

    if (!root) {
        return;
    }

    const screen = root.querySelector("#reportGroupScreen");

    if (screen) {
        activeManageCategoryID = "";
        activeOriginalID = "";
        detailReturnScreen = null;

        showReportScreen(screen);
    }
}

function showManageCategories() {
    const root = getReportRoot();

    if (!root) {
        return;
    }

    activeOriginalID = "";

    showReportScreen(
        root.querySelector("#manageCategoriesScreen")
    );
}

function showManageCategory(categoryID) {
    const root = getReportRoot();

    if (!root) {
        return;
    }

    const screen = Array.from(
        root.querySelectorAll(".manage-category-screen")
    ).find(item =>
        item.dataset.categoryScreen === categoryID
    );

    if (!screen) {
        return;
    }

    activeManageCategoryID = categoryID;
    activeOriginalID = "";

    showReportScreen(screen);
}

function showManageOriginal(reportID) {
    const root = getReportRoot();

    if (!root) {
        return;
    }

    const screen = Array.from(
        root.querySelectorAll(".manage-original-screen")
    ).find(item =>
        item.dataset.originalScreen === reportID
    );

    if (!screen) {
        return;
    }

    activeOriginalID = reportID;
    activeManageCategoryID = screen.dataset.parentCategory || "";

    showReportScreen(screen);
}

/* =========================================================
   DATE FORMATTING
========================================================= */

function formatReportTimeline(value) {
    if (!value) {
        return "";
    }

    const normalizedValue = value.replace(" ", "T");
    const date = new Date(normalizedValue);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true
    });
}

/* =========================================================
   READ-ONLY REPORT DETAILS
========================================================= */

function openReadOnlyReport(card) {
    const root = getReportRoot();

    if (!root || !card) {
        return;
    }

    const detailScreen =
        root.querySelector("#individualReportScreen");

    if (!detailScreen) {
        return;
    }

    detailReturnScreen = Array.from(
        root.querySelectorAll(
            "#reportGroupScreen, .manage-category-screen"
        )
    ).find(screen => !screen.hidden) || null;

    const fields = {
        detailCategory: card.dataset.category,
        detailResident: card.dataset.resident,
        detailEmail: card.dataset.email,
        detailContact: card.dataset.contact,
        detailReportID: card.dataset.reportId,
        detailLocation: card.dataset.location,
        detailDescription: card.dataset.description,
        detailDate: formatReportTimeline(card.dataset.date),
        detailStatus: card.dataset.statusName
    };

    Object.entries(fields).forEach(([id, value]) => {
        const element = root.querySelector("#" + id);

        if (element) {
            element.textContent = value || "Not provided";
        }
    });

    /* TIMELINE */

    const timelineContainer =
        root.querySelector("#detailTimelineContainer");

    const timelineText =
        root.querySelector("#detailTimeline");

    if (timelineContainer && timelineText) {
        const timeline = card.dataset.timeline || "";

        timelineText.textContent =
            formatReportTimeline(timeline);

        timelineContainer.hidden = !timeline;
    }

    /* REPORT IMAGE */

    const imageContainer =
        root.querySelector("#detailImageContainer");

    const image = root.querySelector("#detailImage");

    if (imageContainer && image) {
        if (card.dataset.image) {
            image.src = card.dataset.image;
            imageContainer.hidden = false;
        } else {
            image.removeAttribute("src");
            imageContainer.hidden = true;
        }
    }

    /* STATUS HISTORY */

    const historyTarget =
        root.querySelector("#detailStatusHistory");

    const reportID = card.dataset.reportId;

    const historyTemplate = Array.from(
        root.querySelectorAll("[data-history-report-id]")
    ).find(node =>
        node.dataset.historyReportId === reportID
    );

    if (historyTarget) {
        historyTarget.replaceChildren();

        if (historyTemplate) {
            const clonedNodes = Array.from(
                historyTemplate.childNodes
            ).map(node => node.cloneNode(true));

            historyTarget.append(...clonedNodes);
        } else {
            const emptyMessage = document.createElement("p");

            emptyMessage.className = "manage-empty";

            emptyMessage.textContent =
                "No status history recorded for this report.";

            historyTarget.appendChild(emptyMessage);
        }
    }

    showReportScreen(detailScreen);
}

/* =========================================================
   DRAWER CLICK HANDLERS
========================================================= */

document.addEventListener("click", function (event) {
    const root = event.target.closest(".report-view-content");

    if (!root) {
        return;
    }

    /* MANAGE REPORTS BUTTON */

    if (event.target.closest("#manageGroupReports")) {
        showManageCategories();
        return;
    }

    /* CATEGORY BUTTON */

    const categoryButton = event.target.closest(
        "[data-manage-category]"
    );

    if (categoryButton) {
        showManageCategory(
            categoryButton.dataset.manageCategory
        );
        return;
    }

    /* BACK BUTTONS */

    const backButton = event.target.closest("[data-manage-back]");

    if (backButton) {
        const destination = backButton.dataset.manageBack;

        if (destination === "group") {
            showGroupScreen();
        } else if (destination === "categories") {
            showManageCategories();
        } else if (destination === "category") {
            showManageCategory(
                backButton.dataset.categoryId
            );
        }

        return;
    }

    /* BACK FROM REPORT DETAILS */

    if (event.target.closest("#backToReportList")) {
        if (detailReturnScreen) {
            showReportScreen(detailReturnScreen);
        } else {
            showGroupScreen();
        }

        return;
    }

    /* REPORT CARDS */

    const card = event.target.closest(".duplicate-report-card");

    if (card) {
        if (
            card.dataset.manage === "1" &&
            card.dataset.original === "0"
        ) {
            return;
        }

        if (
            card.dataset.manage === "1" &&
            card.dataset.original === "1"
        ) {
            showManageOriginal(card.dataset.reportId);
        } else {
            openReadOnlyReport(card);
        }

        return;
    }

    /* CLOSE BUTTON */

    if (
        event.target.closest(
            "[data-close-report-view], #closeReportView"
        )
    ) {
        closeReportDrawer();
    }
});

/* =========================================================
   KEYBOARD SUPPORT
========================================================= */

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeReportDrawer();
        hideReportToast();

        if (exportModal) {
            exportModal.hidden = true;
        }

        return;
    }

    if (event.key !== "Enter" && event.key !== " ") {
        return;
    }

    const card = event.target.closest(".duplicate-report-card");

    if (!card) {
        return;
    }

    if (
        card.dataset.manage === "1" &&
        card.dataset.original === "0"
    ) {
        return;
    }

    event.preventDefault();
    card.click();
});

/* =========================================================
   REFRESH ORIGINAL REPORT MANAGEMENT SCREEN
   Used after saving a timeline.
========================================================= */

async function refreshManageOriginal(reportID) {
    const categoryID = activeManageCategoryID;
    const currentCategory = currentCategoryID;
    const currentLocation = currentLocationID;

    const loaded = await loadReportDrawer(
        currentCategory,
        currentLocation
    );

    if (!loaded) {
        return;
    }

    if (categoryID) {
        showManageCategory(categoryID);
    }

    if (reportID) {
        const root = getReportRoot();

        if (!root) {
            return;
        }

        const originalScreen = Array.from(
            root.querySelectorAll(".manage-original-screen")
        ).find(screen =>
            screen.dataset.originalScreen === reportID
        );

        if (originalScreen) {
            showManageOriginal(reportID);
        }
    }
}

