<?php

session_start();


// ==========================================================
// ADMIN SESSION CHECK
// ==========================================================

if (
    !isset($_SESSION["admin_id"]) ||
    !isset($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}


// ==========================================================
// DATABASE CONNECTION
// ==========================================================

require_once __DIR__ . "/../config/database.php";

// ==========================================================
// SESSION DATA
// ==========================================================

$adminID = $_SESSION["admin_id"];
$barangayID = $_SESSION["barangay_id"];


// ==========================================================
// ANNOUNCEMENT TABLE
// ==========================================================

$announcementTable = "announcements";


// ==========================================================
// GET BARANGAY NAME
// ==========================================================

$barangayName = "Barangay";

$stmt = $conn->prepare("
    SELECT barangayName
    FROM barangay
    WHERE barangayID = ?
    LIMIT 1
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $barangay = $result->fetch_assoc();

    $barangayName = $barangay["barangayName"];
}

$stmt->close();


// ==========================================================
// CREATE ANNOUNCEMENT
// ==========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "create"
) {

    $title = trim($_POST["title"] ?? "");
    $content = trim($_POST["content"] ?? "");


    if ($title === "" || $content === "") {

        header("Location: announcements.php?error=empty");
        exit;
    }


    $stmt = $conn->prepare("
        INSERT INTO $announcementTable
        (
            userID,
            barangayID,
            title,
            content
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssss",
        $adminID,
        $barangayID,
        $title,
        $content
    );

    $stmt->execute();

    $stmt->close();


    header("Location: announcements.php?success=created");
    exit;
}


// ==========================================================
// UPDATE ANNOUNCEMENT
// ==========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "update"
) {

    $announceID = (int) ($_POST["announceID"] ?? 0);

    $title = trim($_POST["title"] ?? "");
    $content = trim($_POST["content"] ?? "");


    if (
        $announceID <= 0 ||
        $title === "" ||
        $content === ""
    ) {

        header("Location: announcements.php?error=empty");
        exit;
    }


    $stmt = $conn->prepare("
        UPDATE $announcementTable

        SET
            title = ?,
            content = ?

        WHERE announceID = ?
        AND barangayID = ?
    ");

    $stmt->bind_param(
        "ssis",
        $title,
        $content,
        $announceID,
        $barangayID
    );

    $stmt->execute();

    $stmt->close();


    header("Location: announcements.php?success=updated");
    exit;
}


// ==========================================================
// DELETE ANNOUNCEMENT
// ==========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "delete"
) {

    $announceID = (int) ($_POST["announceID"] ?? 0);


    if ($announceID > 0) {

        $stmt = $conn->prepare("
            DELETE FROM $announcementTable

            WHERE announceID = ?

            AND barangayID = ?
        ");

        $stmt->bind_param(
            "is",
            $announceID,
            $barangayID
        );

        $stmt->execute();

        $stmt->close();
    }


    header("Location: announcements.php?success=deleted");
    exit;
}


// ==========================================================
// SEARCH
// ==========================================================

$search = trim($_GET["search"] ?? "");


// ==========================================================
// GET ANNOUNCEMENTS
// ==========================================================

$announcements = [];


if ($search !== "") {

    $searchValue = "%" . $search . "%";


    $stmt = $conn->prepare("
        SELECT
            a.announceID,
            a.userID,
            a.barangayID,
            a.title,
            a.content,
            a.datePosted,

            u.firstName,
            u.lastName

        FROM $announcementTable a

        LEFT JOIN users u
            ON a.userID = u.userID

        WHERE a.barangayID = ?

        AND (
            a.title LIKE ?
            OR a.content LIKE ?
        )

        ORDER BY a.datePosted DESC
    ");


    $stmt->bind_param(
        "sss",
        $barangayID,
        $searchValue,
        $searchValue
    );

} else {

    $stmt = $conn->prepare("
        SELECT
            a.announceID,
            a.userID,
            a.barangayID,
            a.title,
            a.content,
            a.datePosted,

            u.firstName,
            u.lastName

        FROM $announcementTable a

        LEFT JOIN users u
            ON a.userID = u.userID

        WHERE a.barangayID = ?

        ORDER BY a.datePosted DESC
    ");


    $stmt->bind_param(
        "s",
        $barangayID
    );
}


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $announcements[] = $row;
}


$stmt->close();


// ==========================================================
// UNREAD NOTIFICATIONS
// ==========================================================

$unreadNotifications = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS unreadNotifications

    FROM notification

    WHERE userID = ?

    AND isRead = FALSE
");

$stmt->bind_param(
    "s",
    $adminID
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows === 1) {

    $notificationData = $result->fetch_assoc();

    $unreadNotifications =
        (int) $notificationData["unreadNotifications"];
}


$stmt->close();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>CitiSense - Announcements</title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Dashboard CSS -->

    <link
        rel="stylesheet"
        href="../css/dashboard.css"
    >


    <!-- Announcements CSS -->

    <link
        rel="stylesheet"
        href="../css/announcements.css"
    >

</head>


<body>


<div class="admin-layout">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <?php include __DIR__ . "/partial/sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- =================================================
             HEADER
        ================================================== -->

        <header class="announcements-header">


            <div class="announcements-title">

                <h1>
                    Announcements
                </h1>

                <p>
                    Stay updated with the latest news and important updates.
                </p>

            </div>

        </header>



        <!-- =================================================
             BARANGAY
        ================================================== -->

        <div class="announcement-location">

            <i class="bi bi-geo-alt"></i>

            <span>

                Showing announcements for

                <strong>
                    <?php
                    echo htmlspecialchars($barangayName);
                    ?>
                </strong>

            </span>

        </div>



        <!-- =================================================
             TOOLBAR
        ================================================== -->

        <section class="announcement-toolbar">


            <!-- SEARCH -->

            <form
                method="GET"
                action="announcements.php"
                class="announcement-search"
            >

                <i class="bi bi-search"></i>

                <input
                    type="text"
                    name="search"
                    placeholder="Search announcements..."
                    value="<?php
                        echo htmlspecialchars($search);
                    ?>"
                >

            </form>


            <!-- NEW ANNOUNCEMENT -->

            <button
                type="button"
                class="new-announcement-button"
                id="openCreateModal"
            >

                <i class="bi bi-plus-lg"></i>

                New Announcement

            </button>


        </section>

        <!-- =================================================
             ERROR MESSAGE
        ================================================== -->

        <?php if (isset($_GET["error"])): ?>

            <div class="announcement-message error">

                <i class="bi bi-exclamation-circle"></i>

                Please complete all required fields.

            </div>

        <?php endif; ?>


        <!-- =================================================
             ANNOUNCEMENT LIST
        ================================================== -->

        <section class="announcements-list">


            <?php if (count($announcements) > 0): ?>


                <?php foreach ($announcements as $announcement): ?>


                    <article class="announcement-card">


                        <!-- ICON -->

                        <div class="announcement-icon blue">

                            <i class="bi bi-megaphone"></i>

                        </div>


                        <!-- CONTENT -->

                        <div class="announcement-content">


                            <h2>

                                <?php
                                echo htmlspecialchars(
                                    $announcement["title"]
                                );
                                ?>

                            </h2>


                            <p>

                                <?php
                                echo nl2br(
                                    htmlspecialchars(
                                        $announcement["content"]
                                    )
                                );
                                ?>

                            </p>


                            <div class="announcement-meta">


                                <span>

                                    <i class="bi bi-calendar3"></i>

                                    <?php

                                    if (
                                        !empty(
                                            $announcement["datePosted"]
                                        )
                                    ) {

                                        echo date(
                                            "M d, Y",
                                            strtotime(
                                                $announcement["datePosted"]
                                            )
                                        );

                                    } else {

                                        echo "No date";

                                    }

                                    ?>

                                </span>


                                <span>

                                    <i class="bi bi-person"></i>

                                    <?php

                                    $adminName =
                                        trim(
                                            ($announcement["firstName"] ?? "")
                                            . " "
                                            . ($announcement["lastName"] ?? "")
                                        );


                                    echo htmlspecialchars(
                                        $adminName !== ""
                                            ? $adminName
                                            : "Admin"
                                    );

                                    ?>

                                </span>


                            </div>


                        </div>



                        <!-- ACTION MENU -->

                        <div class="announcement-menu-wrapper">


                            <button
                                type="button"
                                class="announcement-menu"
                                onclick="toggleMenu(
                                    <?php
                                    echo (int)
                                        $announcement["announceID"];
                                    ?>
                                )"
                            >

                                <i class="bi bi-three-dots"></i>

                            </button>


                            <div
                                class="announcement-dropdown"
                                id="menu-<?php
                                    echo (int)
                                        $announcement["announceID"];
                                ?>"
                                style="display:none;"
                            >


                                <!-- EDIT -->

                                <button
                                    type="button"
                                    onclick="openEditModal(
                                        <?php
                                        echo htmlspecialchars(
                                            json_encode(
                                                $announcement,
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT |
                                                JSON_HEX_AMP
                                            )
                                        );
                                        ?>
                                    )"
                                >

                                    <i class="bi bi-pencil"></i>

                                    Edit

                                </button>


                                <!-- DELETE -->

                                <button
                                    type="button"
                                    class="delete-action"
                                    onclick="openDeleteModal(
                                        <?php
                                        echo (int)
                                            $announcement["announceID"];
                                        ?>
                                    )"
                                >

                                    <i class="bi bi-trash"></i>

                                    Delete

                                </button>


                            </div>


                        </div>


                    </article>


                <?php endforeach; ?>


                <?php else: ?>

                    <div class="no-announcements">

                        <div class="no-announcements-icon">
                            <i class="bi bi-megaphone"></i>
                        </div>

                        <h2>
                            No announcements yet
                        </h2>

                        <p>
                            Create an announcement to share updates with the community.
                        </p>

                    </div>

                <?php endif; ?>


        </section>



        <!-- =================================================
             FOOTER
        ================================================== -->

        


    </main>

</div>



<!-- =========================================================
     CREATE ANNOUNCEMENT MODAL
========================================================= -->

<div
    class="announcement-modal-overlay"
    id="createModal"
>


    <div class="announcement-modal">


        <!-- MODAL HEADER -->

        <div class="announcement-modal-header">


            <div>

                <h2>
                    New Announcement
                </h2>

                <p>

                    Create an announcement for

                    <?php
                    echo htmlspecialchars($barangayName);
                    ?>.

                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeCreateModal()"
                aria-label="Close"
            >

                <i class="bi bi-x-lg"></i>

            </button>


        </div>



        <!-- CREATE FORM -->

        <form
            method="POST"
            action="announcements.php"
            class="announcement-form"
        >


            <input
                type="hidden"
                name="action"
                value="create"
            >


            <!-- TITLE -->

            <div class="form-group">


                <label for="createTitle">

                    Announcement Title

                </label>


                <input
                    type="text"
                    id="createTitle"
                    name="title"
                    placeholder="Enter announcement title"
                    maxlength="255"
                    required
                >


            </div>



            <!-- CONTENT -->

            <div class="form-group">


                <label for="createContent">

                    Announcement Content

                </label>


                <textarea
                    id="createContent"
                    name="content"
                    rows="7"
                    placeholder="Write your announcement here..."
                    required
                ></textarea>


            </div>



            <!-- BUTTONS -->

            <div class="announcement-form-actions">


                <button
                    type="button"
                    class="cancel-announcement"
                    onclick="closeCreateModal()"
                >

                    Cancel

                </button>


                <button
                    type="submit"
                    class="publish-announcement"
                >

                    <i class="bi bi-send"></i>

                    Publish Announcement

                </button>


            </div>


        </form>


    </div>

</div>



<!-- =========================================================
     EDIT ANNOUNCEMENT MODAL
========================================================= -->

<div
    class="announcement-modal-overlay"
    id="editModal"
>


    <div class="announcement-modal">


        <div class="announcement-modal-header">


            <div>

                <h2>
                    Edit Announcement
                </h2>

                <p>
                    Update the announcement information.
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeEditModal()"
            >

                <i class="bi bi-x-lg"></i>

            </button>


        </div>



        <form
            method="POST"
            action="announcements.php"
            class="announcement-form"
        >


            <input
                type="hidden"
                name="action"
                value="update"
            >


            <input
                type="hidden"
                name="announceID"
                id="editAnnouncementID"
            >


            <div class="form-group">


                <label for="editTitle">

                    Announcement Title

                </label>


                <input
                    type="text"
                    id="editTitle"
                    name="title"
                    maxlength="255"
                    required
                >


            </div>


            <div class="form-group">


                <label for="editContent">

                    Announcement Content

                </label>


                <textarea
                    id="editContent"
                    name="content"
                    rows="7"
                    required
                ></textarea>


            </div>


            <div class="announcement-form-actions">


                <button
                    type="button"
                    class="cancel-announcement"
                    onclick="closeEditModal()"
                >

                    Cancel

                </button>


                <button
                    type="submit"
                    class="publish-announcement"
                >

                    <i class="bi bi-check-lg"></i>

                    Save Changes

                </button>


            </div>


        </form>


    </div>

</div>



<!-- =========================================================
     DELETE ANNOUNCEMENT MODAL
========================================================= -->

<div
    class="announcement-modal-overlay"
    id="deleteModal"
>


    <div class="announcement-modal">


        <div class="announcement-modal-header">


            <div>

                <h2>
                    Delete Announcement?
                </h2>

                <p>
                    This announcement will be permanently removed.
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeDeleteModal()"
            >

                <i class="bi bi-x-lg"></i>

            </button>


        </div>


        <form
            method="POST"
            action="announcements.php"
            class="announcement-form"
        >


            <input
                type="hidden"
                name="action"
                value="delete"
            >


            <input
                type="hidden"
                name="announceID"
                id="deleteAnnouncementID"
            >


            <div class="announcement-form-actions">


                <button
                    type="button"
                    class="cancel-announcement"
                    onclick="closeDeleteModal()"
                >

                    Cancel

                </button>


                <button
                    type="submit"
                    class="publish-announcement"
                    style="background:#e5394f;"
                >

                    <i class="bi bi-trash"></i>

                    Delete

                </button>


            </div>


        </form>


    </div>

</div>



<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>


// ==========================================================
// CREATE MODAL
// ==========================================================

const createModal =
    document.getElementById("createModal");

const openCreateButton =
    document.getElementById("openCreateModal");


if (openCreateButton && createModal) {

    openCreateButton.addEventListener(
        "click",
        function () {

            createModal.classList.add("show");

            document.body.classList.add("modal-open");


            setTimeout(function () {

                const titleInput =
                    document.getElementById("createTitle");


                if (titleInput) {

                    titleInput.focus();

                }

            }, 100);

        }
    );

}


function closeCreateModal() {

    if (!createModal) {

        return;

    }


    createModal.classList.remove("show");

    document.body.classList.remove("modal-open");

}


// ==========================================================
// EDIT MODAL
// ==========================================================

const editModal =
    document.getElementById("editModal");


function openEditModal(announcement) {

    document.getElementById(
        "editAnnouncementID"
    ).value =
        announcement.announceID;


    document.getElementById(
        "editTitle"
    ).value =
        announcement.title;


    document.getElementById(
        "editContent"
    ).value =
        announcement.content;


    editModal.classList.add("show");

    document.body.classList.add("modal-open");

}


function closeEditModal() {

    editModal.classList.remove("show");

    document.body.classList.remove("modal-open");

}


// ==========================================================
// DELETE MODAL
// ==========================================================

const deleteModal =
    document.getElementById("deleteModal");


function openDeleteModal(announceID) {

    document.getElementById(
        "deleteAnnouncementID"
    ).value =
        announceID;


    deleteModal.classList.add("show");

    document.body.classList.add("modal-open");

}


function closeDeleteModal() {

    deleteModal.classList.remove("show");

    document.body.classList.remove("modal-open");

}


// ==========================================================
// THREE-DOT MENU
// ==========================================================

function toggleMenu(announceID) {

    const menu =
        document.getElementById(
            "menu-" + announceID
        );


    if (!menu) {

        return;

    }


    document
        .querySelectorAll(".announcement-dropdown")
        .forEach(function (item) {

            if (item !== menu) {

                item.style.display = "none";

            }

        });


    if (menu.style.display === "block") {

        menu.style.display = "none";

    } else {

        menu.style.display = "block";

    }

}


// ==========================================================
// CLICK OUTSIDE DROPDOWN
// ==========================================================

document.addEventListener(
    "click",
    function (event) {

        if (
            !event.target.closest(
                ".announcement-menu-wrapper"
            )
        ) {

            document
                .querySelectorAll(
                    ".announcement-dropdown"
                )
                .forEach(function (menu) {

                    menu.style.display = "none";

                });

        }

    }
);


// ==========================================================
// CLICK OUTSIDE MODAL
// ==========================================================

document.addEventListener(
    "click",
    function (event) {


        if (event.target === createModal) {

            closeCreateModal();

        }


        if (event.target === editModal) {

            closeEditModal();

        }


        if (event.target === deleteModal) {

            closeDeleteModal();

        }

    }
);


// ==========================================================
// ESC KEY
// ==========================================================

document.addEventListener(
    "keydown",
    function (event) {

        if (event.key === "Escape") {

            closeCreateModal();

            closeEditModal();

            closeDeleteModal();

        }

    }
);

</script>


</body>

</html>