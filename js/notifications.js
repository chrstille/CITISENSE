"use strict";

/* =========================================================
   CITISENSE ADMIN NOTIFICATIONS
   - Polls admin/notifications.php every 20 seconds
   - Shows a badge, a dropdown list and a toast for new reports
========================================================= */

(function () {

    const POLL_MS = 20000;
    const ENDPOINT = "notifications.php";

    const root = document.getElementById("notif");
    const bell = document.getElementById("notifBell");
    const panel = document.getElementById("notifPanel");
    const countBadge = document.getElementById("notifCount");
    const list = document.getElementById("notifList");
    const summary = document.getElementById("notifSummary");
    const markAll = document.getElementById("notifMarkAll");
    const toasts = document.getElementById("notifToasts");

    if (!root || !bell || !panel || !list) return;

    const baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, "");

    let knownIDs = null;        // Set of report IDs seen so far (null = first load)
    let lastData = null;
    let pollTimer = null;

    /* ---------- Helpers ---------- */

    function timeAgo(seconds) {
        if (seconds === null || seconds === undefined) return "";
        if (seconds < 45) return "Just now";

        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return minutes + "m ago";

        const hours = Math.round(minutes / 60);
        if (hours < 24) return hours + "h ago";

        const days = Math.round(hours / 24);
        if (days < 7) return days + "d ago";

        return Math.round(days / 7) + "w ago";
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function categoryIcon(category) {
        const name = String(category || "").toLowerCase();
        if (name.includes("waste") || name.includes("garbage")) return "bi-trash3";
        if (name.includes("pothole") || name.includes("road")) return "bi-cone-striped";
        if (name.includes("drain") || name.includes("flood")) return "bi-water";
        return "bi-exclamation-diamond";
    }

    function reportUrl(item) {
        const params = new URLSearchParams({
            open: item.categoryID,
            report: item.reportID
        });
        return "reports.php?" + params.toString();
    }

    /* ---------- Render ---------- */

    function render(data) {
        const unread = Number(data.unread) || 0;

        countBadge.hidden = unread === 0;
        countBadge.textContent = unread > 99 ? "99+" : String(unread);
        bell.classList.toggle("has-unread", unread > 0);

        document.title = unread > 0
            ? "(" + (unread > 99 ? "99+" : unread) + ") " + baseTitle
            : baseTitle;

        summary.textContent = unread === 0
            ? "You're all caught up"
            : unread + " new report" + (unread === 1 ? "" : "s");

        markAll.disabled = unread === 0;

        list.replaceChildren();

        if (!data.items || data.items.length === 0) {
            const empty = el("div", "notif-empty");
            empty.appendChild(el("i", "bi bi-bell-slash"));
            empty.appendChild(el("strong", "", "No notifications yet"));
            empty.appendChild(el("span", "", "New reports from residents will show up here."));
            list.appendChild(empty);
            return;
        }

        data.items.forEach(function (item) {
            const link = el("a", "notif-item" + (item.unread ? " is-unread" : ""));
            link.href = reportUrl(item);
            link.dataset.reportId = item.reportID;

            const thumb = el("span", "notif-thumb");

            if (item.image) {
                const img = document.createElement("img");
                img.src = item.image;
                img.alt = "";
                img.loading = "lazy";
                img.addEventListener("error", function () {
                    img.remove();
                    thumb.appendChild(el("i", "bi " + categoryIcon(item.category)));
                });
                thumb.appendChild(img);
            } else {
                thumb.appendChild(el("i", "bi " + categoryIcon(item.category)));
            }

            const body = el("span", "notif-body");

            const line = el("span", "notif-line");
            line.appendChild(el("strong", "", item.resident));
            line.appendChild(document.createTextNode(" submitted a new "));
            line.appendChild(el("strong", "", item.category));
            line.appendChild(document.createTextNode(" report"));

            body.appendChild(line);

            if (item.address) {
                const where = el("span", "notif-meta");
                where.appendChild(el("i", "bi bi-geo-alt"));
                where.appendChild(document.createTextNode(" " + item.address));
                body.appendChild(where);
            }

            const when = el("span", "notif-time", timeAgo(item.secondsAgo));
            when.title = item.date;
            body.appendChild(when);

            link.appendChild(thumb);
            link.appendChild(body);

            if (item.unread) {
                link.appendChild(el("span", "notif-dot"));
            }

            list.appendChild(link);
        });
    }

    function showToast(item) {
        if (!toasts) return;

        const toast = el("a", "notif-toast");
        toast.href = reportUrl(item);

        const icon = el("span", "notif-toast-icon");
        icon.appendChild(el("i", "bi bi-bell-fill"));

        const text = el("span", "notif-toast-text");
        text.appendChild(el("strong", "", "New " + item.category + " report"));
        text.appendChild(el("span", "", item.resident + (item.address ? " · " + item.address : "")));

        const close = el("button", "notif-toast-close");
        close.type = "button";
        close.setAttribute("aria-label", "Dismiss");
        close.appendChild(el("i", "bi bi-x-lg"));

        toast.appendChild(icon);
        toast.appendChild(text);
        toast.appendChild(close);

        toast.addEventListener("click", function (event) {
            if (event.target.closest(".notif-toast-close")) {
                event.preventDefault();
                dismiss();
            } else {
                markRead(item.reportID);
            }
        });

        function dismiss() {
            toast.classList.add("is-leaving");
            setTimeout(function () { toast.remove(); }, 250);
        }

        toasts.appendChild(toast);
        setTimeout(dismiss, 7000);
    }

    /* ---------- Data ---------- */

    async function load() {
        try {
            const response = await fetch(ENDPOINT, {
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });

            if (response.status === 401) {
                window.location.href = "login.php";
                return;
            }

            const data = await response.json();

            if (!data.success) throw new Error(data.message || "Failed");

            const ids = new Set(data.items.map(function (item) { return item.reportID; }));

            if (knownIDs !== null) {
                const fresh = data.items.filter(function (item) {
                    return item.unread && !knownIDs.has(item.reportID);
                });

                if (fresh.length > 0) {
                    bell.classList.remove("ring");
                    void bell.offsetWidth;          // restart animation
                    bell.classList.add("ring");

                    fresh.slice(0, 3).reverse().forEach(showToast);
                }
            }

            knownIDs = ids;
            lastData = data;
            render(data);

        } catch (error) {
            console.warn("Notifications unavailable:", error);

            if (!lastData) {
                summary.textContent = "Couldn't load notifications";
                list.replaceChildren();
                const empty = el("div", "notif-empty");
                empty.appendChild(el("i", "bi bi-wifi-off"));
                empty.appendChild(el("strong", "", "Can't reach the server"));
                empty.appendChild(el("span", "", "We'll keep trying in the background."));
                list.appendChild(empty);
            }
        }
    }

    function post(body) {
        return fetch(ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body),
            keepalive: true
        }).catch(function () { /* ignore */ });
    }

    function markRead(reportID) {
        return post({ action: "read", reportID: reportID });
    }

    /* ---------- Panel open / close ---------- */

    function setOpen(open) {
        root.classList.toggle("open", open);
        bell.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) load();
    }

    bell.addEventListener("click", function (event) {
        event.stopPropagation();
        setOpen(!root.classList.contains("open"));
    });

    document.addEventListener("click", function (event) {
        if (!root.contains(event.target)) setOpen(false);
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && root.classList.contains("open")) {
            setOpen(false);
            bell.focus();
        }
    });

    list.addEventListener("click", function (event) {
        const item = event.target.closest(".notif-item");
        if (!item) return;

        event.preventDefault();

        const href = item.href;

        markRead(item.dataset.reportId).finally(function () {
            window.location.href = href;
        });
    });

    markAll.addEventListener("click", async function () {
        markAll.disabled = true;
        await post({ action: "read_all" });
        load();
    });

    /* ---------- Start ---------- */

    load();

    function startPolling() {
        clearInterval(pollTimer);
        pollTimer = setInterval(load, POLL_MS);
    }

    startPolling();

    // Keep polling in the background so the tab title shows the count,
    // and refresh right away when the admin comes back to the tab.
    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "visible") {
            load();
            startPolling();
        }
    });

})();
