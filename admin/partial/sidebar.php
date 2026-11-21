<?php

$currentPage = basename($_SERVER["PHP_SELF"]);

/* Pages that live "under" another nav item */
$navAliases = [
    "reportStatusOverview.php" => "reports.php",
    "duplicate_report.php"     => "reports.php",
];

$activePage = $navAliases[$currentPage] ?? $currentPage;

/* Pending reports badge (only if a DB connection is available) */
$sidebarPendingCount = 0;

if (
    isset($conn) &&
    $conn instanceof mysqli &&
    !empty($_SESSION["barangay_id"])
) {
    $sidebarStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
           FROM reports
          WHERE barangayID = ?
            AND statusID = 'STAT001'"
    );

    if ($sidebarStmt) {
        $sidebarBarangay = (string) $_SESSION["barangay_id"];
        $sidebarStmt->bind_param("s", $sidebarBarangay);
        $sidebarStmt->execute();
        $sidebarRow = $sidebarStmt->get_result()->fetch_assoc();
        $sidebarPendingCount = (int) ($sidebarRow["total"] ?? 0);
        $sidebarStmt->close();
    }
}

$sidebarAdminName = trim((string) ($_SESSION["admin_name"] ?? "Administrator"));
$sidebarAdminEmail = (string) ($_SESSION["admin_email"] ?? "");

$sidebarInitials = "";
foreach (preg_split("/\s+/", $sidebarAdminName) as $part) {
    if ($part !== "" && mb_strlen($sidebarInitials) < 2) {
        $sidebarInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}
if ($sidebarInitials === "") {
    $sidebarInitials = "A";
}

$navSections = [
    "Overview" => [
        ["dashboard.php", "bi-grid-1x2", "bi-grid-1x2-fill", "Dashboard"],
        ["reports.php", "bi-file-earmark-text", "bi-file-earmark-text-fill", "Reports"],
        ["heatmap.php", "bi-geo-alt", "bi-geo-alt-fill", "Heatmap"],
        ["analytics.php", "bi-bar-chart", "bi-bar-chart-fill", "Analytics"],
    ],
    "Manage" => [
        ["announcements.php", "bi-megaphone", "bi-megaphone-fill", "Announcements"],
        ["users.php", "bi-people", "bi-people-fill", "Users"],
        ["settings.php", "bi-gear", "bi-gear-fill", "Settings"],
    ],
];

if (!function_exists("sidebarEscape")) {
    function sidebarEscape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    }
}

?>

<link rel="stylesheet" href="../css/sidebar.css?v=2">

<div class="dashboard-container">

    <!-- Mobile top bar -->
    <div class="mobile-topbar">
        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Open navigation"
            aria-controls="appSidebar"
            aria-expanded="false"
        >
            <i class="bi bi-list"></i>
        </button>

        <span class="mobile-brand">Citi<span>Sense</span></span>
    </div>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <aside class="sidebar" id="appSidebar">

        <!-- BRAND -->
        <a href="dashboard.php" class="sidebar-brand">
            <img
                src="../css/images/brand.png"
                alt="CitiSense Logo"
                class="sidebar-logo"
            >

            <div class="brand-text">
                <h2>Citi<span>Sense</span></h2>
                <p>Self. Report. Improve.</p>
            </div>
        </a>

        <!-- NAVIGATION -->
        <nav class="sidebar-nav" aria-label="Main navigation">

            <?php foreach ($navSections as $sectionLabel => $items): ?>

                <span class="nav-section-label"><?= sidebarEscape($sectionLabel) ?></span>

                <?php foreach ($items as [$href, $icon, $iconActive, $label]): ?>

                    <?php $isActive = $activePage === $href; ?>

                    <a
                        href="<?= sidebarEscape($href) ?>"
                        class="nav-item<?= $isActive ? " active" : "" ?>"
                        <?= $isActive ? 'aria-current="page"' : "" ?>
                    >
                        <i class="bi <?= sidebarEscape($isActive ? $iconActive : $icon) ?>"></i>
                        <span class="nav-label"><?= sidebarEscape($label) ?></span>

                        <?php if ($href === "reports.php" && $sidebarPendingCount > 0): ?>
                            <span
                                class="nav-badge"
                                title="<?= $sidebarPendingCount ?> pending report<?= $sidebarPendingCount === 1 ? "" : "s" ?>"
                            ><?= $sidebarPendingCount > 99 ? "99+" : $sidebarPendingCount ?></span>
                        <?php endif; ?>
                    </a>

                <?php endforeach; ?>

            <?php endforeach; ?>

        </nav>

        <!-- ADMIN PROFILE + LOGOUT -->
        <div class="sidebar-footer">

            <div class="sidebar-profile">
                <span class="sidebar-avatar"><?= sidebarEscape($sidebarInitials) ?></span>

                <div class="sidebar-profile-text">
                    <strong><?= sidebarEscape($sidebarAdminName) ?></strong>
                    <small><?= sidebarEscape($sidebarAdminEmail !== "" ? $sidebarAdminEmail : "Barangay Admin") ?></small>
                </div>
            </div>

            <a
                href="logout.php"
                class="logout-link"
                title="Log out"
                aria-label="Log out"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Log out</span>
            </a>

        </div>

    </aside>

    <script>
    (function () {
        const sidebar = document.getElementById("appSidebar");
        const toggle = document.getElementById("sidebarToggle");
        const backdrop = document.getElementById("sidebarBackdrop");

        if (!sidebar || !toggle || !backdrop) return;

        function setOpen(open) {
            document.body.classList.toggle("sidebar-open", open);
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
        }

        toggle.addEventListener("click", function () {
            setOpen(!document.body.classList.contains("sidebar-open"));
        });

        backdrop.addEventListener("click", function () {
            setOpen(false);
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") setOpen(false);
        });
    })();
    </script>
