<?php
/* Notification bell + dropdown. Place inside a page header. */
?>
<link rel="stylesheet" href="../css/notifications.css?v=2">

<div class="notif" id="notif">

    <button
        type="button"
        class="notif-bell"
        id="notifBell"
        aria-label="Notifications"
        aria-haspopup="true"
        aria-expanded="false"
        aria-controls="notifPanel"
    >
        <i class="bi bi-bell"></i>
        <span class="notif-count" id="notifCount" hidden>0</span>
    </button>

    <div class="notif-panel" id="notifPanel" role="dialog" aria-label="Notifications">

        <header class="notif-panel-header">
            <div>
                <h3>Notifications</h3>
                <small id="notifSummary">Loading…</small>
            </div>

            <button type="button" class="notif-mark-all" id="notifMarkAll" disabled>
                <i class="bi bi-check2-all"></i>
                Mark all as read
            </button>
        </header>

        <div class="notif-list" id="notifList">
            <div class="notif-skeleton"></div>
            <div class="notif-skeleton"></div>
            <div class="notif-skeleton"></div>
        </div>

        <footer class="notif-panel-footer">
            <a href="reports.php">View all reports <i class="bi bi-arrow-right"></i></a>
        </footer>

    </div>

</div>

<div class="notif-toast-stack" id="notifToasts" aria-live="polite"></div>

<script src="../js/notifications.js?v=2" defer></script>
