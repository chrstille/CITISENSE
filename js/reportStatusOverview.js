
"use strict";

/* =========================================================
   CITISENSE - REPORT STATUS OVERVIEW

   Individual Report Cards -> Report Details Drawer

   - Read-only report details
   - Right-side drawer
   - Click outside to close
   - Pending reports have no status history
   - Other statuses display recorded history
   - Existing Reports eye action remains unchanged
========================================================= */

(() => {

    function initializeReportDetails() {

        /* =============================================
           GET HTML ELEMENTS
        ============================================= */

        const payload =
            document.getElementById("reportBoardPayload");

        const overlay =
            document.getElementById("reportBoardOverlay");

        const drawer =
            document.getElementById("reportBoardDrawer");

        const closeButton =
            document.getElementById("boardDrawerClose");

        const historyPanel =
            document.getElementById("boardHistoryPanel");

        const historyList =
            document.getElementById("boardHistoryList");

        const timelineRow =
            document.getElementById("boardDetailTimelineRow");


        /* =============================================
           CHECK REQUIRED ELEMENTS
        ============================================= */

        const missing = [
            ["reportBoardPayload", payload],
            ["reportBoardOverlay", overlay],
            ["reportBoardDrawer", drawer],
            ["boardDrawerClose", closeButton],
            ["boardHistoryPanel", historyPanel],
            ["boardHistoryList", historyList]
        ]
        .filter(([, element]) => !element)
        .map(([id]) => id);

        if (missing.length > 0) {
            console.error(
                "Report Details: Missing HTML elements:",
                missing.join(", ")
            );
            return;
        }


        /* =============================================
           LOAD REPORT DATA FROM PHP
        ============================================= */

        let records = {};

        try {

            records = JSON.parse(
                payload.textContent || "{}"
            );

            if (
                records === null ||
                typeof records !== "object" ||
                Array.isArray(records)
            ) {
                throw new Error(
                    "Invalid report data format."
                );
            }

        } catch (error) {

            console.error(
                "Report Details: Unable to load report data:",
                error
            );

            return;
        }


        /* =============================================
           TRACK PREVIOUSLY SELECTED CARD
        ============================================= */

        let lastCard = null;


        /* =============================================
           SET REPORT DETAIL FIELD
        ============================================= */

        function setField(id, value) {

            const element =
                document.getElementById(id);

            if (!element) return;

            const content =
                value === null || value === undefined
                    ? ""
                    : String(value).trim();

            element.textContent =
                content || "Not provided";
        }


        /* =============================================
           CREATE SAFE TEXT ELEMENT
        ============================================= */

        function appendText(
            parent,
            tagName,
            content,
            className
        ) {

            const element =
                document.createElement(tagName);

            if (className) {
                element.className = className;
            }

            element.textContent = String(content);

            parent.appendChild(element);

            return element;
        }


        /* =============================================
           DISPLAY REPORT STATUS HISTORY
        ============================================= */

        function renderHistory(history) {

            historyList.replaceChildren();

            if (
                !Array.isArray(history) ||
                history.length === 0
            ) {

                appendText(
                    historyList,
                    "p",
                    "No status history recorded.",
                    "report-board-history-empty"
                );

                return;
            }

            history.forEach((entry) => {

                const item =
                    document.createElement("article");

                item.className =
                    "report-board-history-item";

                appendText(
                    item,
                    "strong",
                    entry.status || "Status updated"
                );

                if (entry.dateChanged) {

                    appendText(
                        item,
                        "time",
                        entry.dateChanged
                    );
                }

                if (entry.changedBy) {

                    appendText(
                        item,
                        "small",
                        `Updated by ${entry.changedBy}`
                    );
                }

                if (entry.remarks) {

                    appendText(
                        item,
                        "p",
                        entry.remarks
                    );
                }

                historyList.appendChild(item);
            });
        }


        /* =============================================
           OPEN REPORT DETAILS DRAWER
        ============================================= */

        function openDetails(card) {

            const reportID =
                card.dataset.reportId;

            const report =
                records[reportID];

            if (!report) {

                console.error(
                    "Report Details: No data found for",
                    reportID
                );

                return;
            }

            lastCard = card;


            /* REPORT INFORMATION */

            setField(
                "boardDetailCategory",
                report.category
            );

            setField(
                "boardDetailReportID",
                report.reportID
            );

            setField(
                "boardDetailLocation",
                report.location
            );

            setField(
                "boardDetailDescription",
                report.description
            );

            setField(
                "boardDetailStatus",
                report.status
            );

            setField(
                "boardDetailDate",
                report.dateReported
            );


            /* RESIDENT INFORMATION */

            setField(
                "boardDetailResident",
                report.resident
            );

            setField(
                "boardDetailEmail",
                report.email
            );

            setField(
                "boardDetailContact",
                report.contact
            );


            /* REPORT TIMELINE */

            if (timelineRow) {

                timelineRow.hidden = !report.timeline;

                setField(
                    "boardDetailTimeline",
                    report.timeline
                );
            }


            /* =========================================
               STATUS HISTORY

               Pending: hidden
               In Progress: visible
               Overdue: visible
               Resolved: visible
            ========================================= */

            const isPending =
                String(report.statusID) === "STAT001";

            historyPanel.hidden = isPending;

            if (isPending) {

                historyList.replaceChildren();

            } else {

                renderHistory(
                    report.history || []
                );
            }


            /* =========================================
               SHOW RIGHT-SIDE DRAWER
            ========================================= */

            overlay.hidden = false;

            document.body.classList.add(
                "report-board-drawer-open"
            );

            drawer.focus({
                preventScroll: true
            });
        }


        /* =============================================
           CLOSE REPORT DETAILS DRAWER
        ============================================= */

        function closeDetails() {

            if (overlay.hidden) return;

            overlay.hidden = true;

            document.body.classList.remove(
                "report-board-drawer-open"
            );

            if (
                lastCard &&
                lastCard.isConnected
            ) {

                lastCard.focus({
                    preventScroll: true
                });
            }
        }


        /* =============================================
           CLICK INDIVIDUAL REPORT CARDS
        ============================================= */

        document.addEventListener(
            "click",
            function (event) {

                const card =
                    event.target.closest(
                        ".report-board-card[data-report-id]"
                    );

                if (!card) return;

                openDetails(card);
            }
        );


        /* =============================================
           CLICK OUTSIDE DRAWER TO CLOSE
        ============================================= */

        overlay.addEventListener(
            "click",
            function (event) {

                if (event.target === overlay) {

                    closeDetails();
                }
            }
        );


        /* =============================================
           CLOSE BUTTON
        ============================================= */

        closeButton.addEventListener(
            "click",
            closeDetails
        );


        /* =============================================
           ESCAPE KEY TO CLOSE
        ============================================= */

        document.addEventListener(
            "keydown",
            function (event) {

                if (
                    event.key === "Escape" &&
                    !overlay.hidden
                ) {

                    closeDetails();
                }
            }
        );


        /* =============================================
           DEBUG INFORMATION
        ============================================= */

        console.info(
            "CitiSense Report Details ready:",
            document.querySelectorAll(
                ".report-board-card[data-report-id]"
            ).length,
            "cards,",
            Object.keys(records).length,
            "report records"
        );

    }


    /* =============================================
       INITIALIZE AFTER HTML LOADS
    ============================================= */

    if (document.readyState === "loading") {

        document.addEventListener(
            "DOMContentLoaded",
            initializeReportDetails,
            { once: true }
        );

    } else {

        initializeReportDetails();
    }

})();
