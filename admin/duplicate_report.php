<?php

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$barangayID = $_SESSION["barangay_id"];

$categoryID = trim(
    $_GET["categoryID"] ?? ""
);

$locationID = trim(
    $_GET["locationID"] ?? ""
);


/*
|--------------------------------------------------------------------------
| REQUIRE CATEGORY AND LOCATION
|--------------------------------------------------------------------------
*/

if ($categoryID === "" || $locationID === "") {

    header("Location: reports.php");
    exit;

}


/*
|--------------------------------------------------------------------------
| GET CATEGORY + LOCATION
|--------------------------------------------------------------------------
|
| We verify that the selected category/location exists
| within this admin's barangay.
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            c.categoryName,
            l.address

        FROM reports r

        INNER JOIN categories c
            ON r.categoryID = c.categoryID

        INNER JOIN location l
            ON r.locationID = l.locationID

        WHERE r.categoryID = ?
        AND r.locationID = ?
        AND r.barangayID = ?

        LIMIT 1";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sss",
    $categoryID,
    $locationID,
    $barangayID
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

    $stmt->close();

    header("Location: reports.php");
    exit;

}

$groupInfo = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| GET ALL SUBMISSIONS FOR THIS GROUP
|--------------------------------------------------------------------------
|
| Every report record is a resident submission.
|
| Therefore COUNT(reportID) on the Reports page
| includes duplicate submissions as part of the tally.
|
| Here we show the individual records that make up that tally.
|--------------------------------------------------------------------------
*/

$sql = "SELECT

            r.reportID,
            r.title,
            r.description,
            r.dateReported,
            r.timeline,

            u.userID,
            u.firstName,
            u.lastName,
            u.email,
            u.contactNo,

            c.categoryName,

            l.address,
            l.latitude,
            l.longitude,

            (
                SELECT ri.imagePath
                FROM reportImages ri
                WHERE ri.reportID = r.reportID
                ORDER BY ri.uploadedAt ASC
                LIMIT 1
            ) AS imagePath

        FROM reports r

        INNER JOIN users u
            ON r.userID = u.userID

        INNER JOIN categories c
            ON r.categoryID = c.categoryID

        INNER JOIN location l
            ON r.locationID = l.locationID

        WHERE r.categoryID = ?
        AND r.locationID = ?
        AND r.barangayID = ?

        ORDER BY r.dateReported DESC";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sss",
    $categoryID,
    $locationID,
    $barangayID
);

$stmt->execute();

$result = $stmt->get_result();

$submissions = [];

while ($row = $result->fetch_assoc()) {
    $submissions[] = $row;
}

$stmt->close();


$totalSubmissions = count($submissions);


/*
|--------------------------------------------------------------------------
| LATEST SUBMISSION
|--------------------------------------------------------------------------
*/

$latestSubmission = null;

if ($totalSubmissions > 0) {
    $latestSubmission = $submissions[0];
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        CitiSense - Duplicate Reports
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <link
        rel="stylesheet"
        href="../css/dashboard.css"
    >


    <link
        rel="stylesheet"
        href="../css/reports.css"
    >


    <link
        rel="stylesheet"
        href="../css/duplicate-reports.css"
    >

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">


    <div class="sidebar-brand">

        <div class="brand-icon">

            <i class="bi bi-buildings"></i>

        </div>


        <div class="brand-text">

            <strong>
                Citi<span>Sense</span>
            </strong>

            <small>
                ADMIN PORTAL
            </small>

        </div>

    </div>



    <nav class="sidebar-nav">


        <a
            href="dashboard.php"
            class="nav-item"
        >

            <i class="bi bi-house"></i>

            <span>
                Dashboard
            </span>

        </a>



        <a
            href="reports.php"
            class="nav-item active"
        >

            <i class="bi bi-file-earmark-text"></i>

            <span>
                Reports
            </span>

        </a>



        <a href="#" class="nav-item">

            <i class="bi bi-geo-alt"></i>

            <span>
                Heatmap
            </span>

        </a>



        <a href="#" class="nav-item">

            <i class="bi bi-graph-up-arrow"></i>

            <span>
                Analytics
            </span>

        </a>



        <a href="#" class="nav-item">

            <i class="bi bi-megaphone"></i>

            <span>
                Announcements
            </span>

        </a>



        <a href="#" class="nav-item">

            <i class="bi bi-people"></i>

            <span>
                Users
            </span>

        </a>



        <a href="#" class="nav-item">

            <i class="bi bi-gear"></i>

            <span>
                Settings
            </span>

        </a>


    </nav>



    <div class="help-card">

        <div class="help-icon">

            <i class="bi bi-question-circle"></i>

        </div>


        <div>

            <strong>
                Need Help?
            </strong>

            <p>
                We're here to help you anytime.
            </p>

            <button type="button">
                Contact Support
            </button>

        </div>

    </div>



    <a
        href="logout.php"
        class="logout-link"
    >

        <i class="bi bi-box-arrow-right"></i>

        <span>
            Logout
        </span>

    </a>


</aside>



<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">


    <header class="duplicate-header">


        <div>

            <a
                href="reports.php"
                class="back-link"
            >

                <i class="bi bi-arrow-left"></i>

                Back to Reports

            </a>


            <h1>
                Duplicate Reports
            </h1>


            <p>

                Consolidated submissions for
                <strong>

                    <?php
                    echo htmlspecialchars(
                        $groupInfo["categoryName"]
                    );
                    ?>

                </strong>

                at

                <strong>

                    <?php
                    echo htmlspecialchars(
                        $groupInfo["address"]
                    );
                    ?>

                </strong>

            </p>

        </div>


    </header>



    <!-- =====================================================
         SUMMARY
    ====================================================== -->

    <section class="duplicate-summary">


        <div class="summary-card">


            <div class="summary-icon">

                <i class="bi bi-files"></i>

            </div>


            <div>

                <span>
                    Total Reports
                </span>

                <strong>
                    <?php echo $totalSubmissions; ?>
                </strong>

            </div>


        </div>



        <div class="summary-card">


            <div class="summary-icon">

                <i class="bi bi-clock-history"></i>

            </div>


            <div>

                <span>
                    Latest Submission
                </span>


                <strong>

                    <?php if ($latestSubmission): ?>

                        <?php

                        echo date(
                            "M d, Y",
                            strtotime(
                                $latestSubmission["dateReported"]
                            )
                        );

                        ?>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </strong>


                <?php if ($latestSubmission): ?>

                    <small>

                        <?php

                        echo date(
                            "g:i A",
                            strtotime(
                                $latestSubmission["dateReported"]
                            )
                        );

                        ?>

                    </small>

                <?php endif; ?>


            </div>

        </div>


    </section>



    <!-- =====================================================
         SUBMISSIONS
    ====================================================== -->

    <section class="duplicate-list-card">


        <div class="duplicate-card-header">


            <div>

                <h2>
                    Submitted Reports
                </h2>


                <p>
                    Resident submissions included in this report group.
                </p>

            </div>


            <span class="submission-count">

                <?php echo $totalSubmissions; ?>

                submission<?php
                echo $totalSubmissions === 1
                    ? ""
                    : "s";
                ?>

            </span>


        </div>



        <?php if ($totalSubmissions > 0): ?>


            <div class="submission-list">


                <?php foreach ($submissions as $submission): ?>


                    <article class="submission-card">


                        <!-- IMAGE -->

                        <div class="submission-image">


                            <?php if (
                                !empty(
                                    $submission["imagePath"]
                                )
                            ): ?>


                                <img
                                    src="<?php
                                    echo htmlspecialchars(
                                        $submission["imagePath"]
                                    );
                                    ?>"
                                    alt="Report image"
                                >


                            <?php else: ?>


                                <div class="no-image">

                                    <i class="bi bi-image"></i>

                                    <span>
                                        No image
                                    </span>

                                </div>


                            <?php endif; ?>


                        </div>



                        <!-- DETAILS -->

                        <div class="submission-details">


                            <div class="submission-top">


                                <div>

                                    <h3>

                                        <?php

                                        echo htmlspecialchars(
                                            $submission["title"]
                                        );

                                        ?>

                                    </h3>


                                    <span class="report-id">

                                        Report ID:

                                        <?php

                                        echo htmlspecialchars(
                                            $submission["reportID"]
                                        );

                                        ?>

                                    </span>

                                </div>


                                <time>

                                    <?php

                                    echo date(
                                        "M d, Y • g:i A",
                                        strtotime(
                                            $submission["dateReported"]
                                        )
                                    );

                                    ?>

                                </time>


                            </div>



                            <!-- REPORTER -->

                            <div class="reporter-info">


                                <i class="bi bi-person-circle"></i>


                                <div>

                                    <strong>

                                        <?php

                                        echo htmlspecialchars(
                                            $submission["firstName"]
                                            . " "
                                            . $submission["lastName"]
                                        );

                                        ?>

                                    </strong>


                                    <span>

                                        <?php

                                        echo htmlspecialchars(
                                            $submission["email"]
                                        );

                                        ?>

                                    </span>


                                    <span>

                                        <?php

                                        echo htmlspecialchars(
                                            $submission["contactNo"]
                                        );

                                        ?>

                                    </span>

                                </div>


                            </div>



                            <!-- DESCRIPTION -->

                            <div class="submission-description">


                                <strong>
                                    Additional Details
                                </strong>


                                <p>

                                    <?php

                                    echo nl2br(
                                        htmlspecialchars(
                                            $submission["description"]
                                        )
                                    );

                                    ?>

                                </p>


                            </div>



                            <!-- LOCATION -->

                            <div class="submission-location">


                                <i class="bi bi-geo-alt"></i>


                                <span>

                                    <?php

                                    echo htmlspecialchars(
                                        $submission["address"]
                                    );

                                    ?>

                                </span>

                            </div>


                        </div>


                    </article>


                <?php endforeach; ?>


            </div>


        <?php else: ?>


            <div class="empty-duplicates">

                <i class="bi bi-inbox"></i>

                <strong>
                    No submissions found
                </strong>

                <p>
                    There are no resident submissions for this report.
                </p>

            </div>


        <?php endif; ?>


    </section>


    <?php include __DIR__ . "/partial/footer.php"; ?>


</main>


</body>

</html>