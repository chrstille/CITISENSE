<?php

session_start();

require_once __DIR__ . "/../config/database.php";


/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}


$barangayID = $_SESSION["barangay_id"] ?? "";

if ($barangayID === "") {
    die("Barangay ID is missing from the session.");
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
|
| The search works for:
| - User ID
| - First Name
| - Last Name
|
*/

$search = trim($_GET["search"] ?? "");


/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$limit = 8;

$page = isset($_GET["page"])
    ? max(1, (int) $_GET["page"])
    : 1;


/*
|--------------------------------------------------------------------------
| COUNT RESIDENTS
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE barangayID = ?
      AND role = 'resident'
";

$countParams = [$barangayID];
$countTypes = "s";


/*
|--------------------------------------------------------------------------
| SEARCH FILTER FOR COUNT
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $searchPattern = "%" . $search . "%";

    $countSql .= "
        AND (
            CAST(userID AS CHAR) LIKE ?
            OR firstName LIKE ?
            OR lastName LIKE ?
        )
    ";

    $countParams[] = $searchPattern;
    $countParams[] = $searchPattern;
    $countParams[] = $searchPattern;

    $countTypes .= "sss";
}


/*
|--------------------------------------------------------------------------
| EXECUTE COUNT
|--------------------------------------------------------------------------
*/

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die("Count query failed: " . $conn->error);
}

$countStmt->bind_param(
    $countTypes,
    ...$countParams
);

$countStmt->execute();

$countResult = $countStmt->get_result();

$countRow = $countResult->fetch_assoc();

$totalUsers = (int) ($countRow["total"] ?? 0);

$countStmt->close();


/*
|--------------------------------------------------------------------------
| TOTAL PAGES
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil($totalUsers / $limit)
);


/*
|--------------------------------------------------------------------------
| FIX PAGE IF OUT OF RANGE
|--------------------------------------------------------------------------
*/

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;


/*
|--------------------------------------------------------------------------
| GET RESIDENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        userID,
        firstName,
        lastName,
        email,
        contactNo,
        profilePic,
        dateJoined
    FROM users
    WHERE barangayID = ?
      AND role = 'resident'
";


$params = [$barangayID];
$types = "s";


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $searchPattern = "%" . $search . "%";

    $sql .= "
        AND (
            CAST(userID AS CHAR) LIKE ?
            OR firstName LIKE ?
            OR lastName LIKE ?
        )
    ";

    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;

    $types .= "sss";
}


/*
|--------------------------------------------------------------------------
| SORT + PAGINATION
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY dateJoined DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$types .= "ii";


/*
|--------------------------------------------------------------------------
| EXECUTE USER QUERY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("User query preparation failed: " . $conn->error);
}


$stmt->bind_param(
    $types,
    ...$params
);


$stmt->execute();


$result = $stmt->get_result();


/*
|--------------------------------------------------------------------------
| STORE USERS
|--------------------------------------------------------------------------
*/

$users = [];

while ($row = $result->fetch_assoc()) {

    $users[] = $row;

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

    <title>
        Users | CitiSense
    </title>


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


    <!-- Users CSS -->

    <link
        rel="stylesheet"
        href="../css/users.css"
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
             PAGE HEADER
        ================================================== -->

        <div class="users-page-header">

            <div>

                <h1>
                    Users
                </h1>

                <p>
                    View and search residents registered in CitiSense.
                </p>

            </div>

        </div>



        <!-- =================================================
             USERS CARD
        ================================================== -->

        <section class="users-card">


            <!-- =================================================
                 SEARCH
            ================================================== -->

            <div class="users-toolbar">

                <form
                    method="GET"
                    action="users.php"
                    class="search-form"
                >

                    <div class="search-box">

                        <i
                            class="bi bi-search search-icon"
                        ></i>


                        <input
                            type="text"
                            name="search"
                            placeholder="Search"
                            value="<?= htmlspecialchars($search) ?>"
                            autocomplete="off"
                        >


                        <?php if ($search !== ""): ?>

                            <a
                                href="users.php"
                                class="clear-search"
                                title="Clear search"
                            >
                                &times;
                            </a>

                        <?php endif; ?>

                    </div>


                    <button
                        type="submit"
                        class="search-button"
                    >

                        <i class="bi bi-search"></i>

                        Search

                    </button>

                </form>

            </div>



            <!-- =================================================
                 TABLE
            ================================================== -->

            <div class="table-wrapper">

                <table class="users-table">

                    <thead>

                        <tr>

                            <th>
                                User ID
                            </th>

                            <th>
                                Profile
                            </th>

                            <th>
                                First Name
                            </th>

                            <th>
                                Last Name
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Contact No.
                            </th>

                            <th>
                                Date Joined
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (count($users) > 0): ?>


                        <?php foreach ($users as $user): ?>


                            <tr>


                                <!-- USER ID -->

                                <td class="user-id">

                                    <?= htmlspecialchars(
                                        $user["userID"]
                                    ) ?>

                                </td>



                                <!-- PROFILE -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $user["profilePic"]
                                        )
                                    ): ?>

                                        <img
                                            src="<?= htmlspecialchars(
                                                $user["profilePic"]
                                            ) ?>"
                                            alt="Profile"
                                            class="profile-image"
                                        >

                                    <?php else: ?>

                                        <div
                                            class="profile-placeholder"
                                        >

                                            <?= htmlspecialchars(
                                                strtoupper(
                                                    substr(
                                                        $user["firstName"],
                                                        0,
                                                        1
                                                    )
                                                )
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>



                                <!-- FIRST NAME -->

                                <td>

                                    <?= htmlspecialchars(
                                        $user["firstName"]
                                    ) ?>

                                </td>



                                <!-- LAST NAME -->

                                <td>

                                    <?= htmlspecialchars(
                                        $user["lastName"]
                                    ) ?>

                                </td>



                                <!-- EMAIL -->

                                <td class="email-cell">

                                    <?= htmlspecialchars(
                                        $user["email"]
                                    ) ?>

                                </td>



                                <!-- CONTACT -->

                                <td>

                                    <?= htmlspecialchars(
                                        $user["contactNo"]
                                    ) ?>

                                </td>



                                <!-- DATE JOINED -->

                                <td>

                                    <?php

                                    if (
                                        !empty(
                                            $user["dateJoined"]
                                        )
                                    ) {

                                        echo htmlspecialchars(
                                            date(
                                                "M d, Y",
                                                strtotime(
                                                    $user["dateJoined"]
                                                )
                                            )
                                        );

                                    } else {

                                        echo "—";

                                    }

                                    ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="7"
                                class="no-users"
                            >

                                <div class="empty-state">

                                    <div class="empty-icon">

                                        <i
                                            class="bi bi-people"
                                        ></i>

                                    </div>


                                    <h3>
                                        No users found
                                    </h3>


                                    <p>

                                        <?php if (
                                            $search !== ""
                                        ): ?>

                                            No residents matched
                                            "<strong><?= htmlspecialchars($search) ?></strong>".

                                        <?php else: ?>

                                            No residents are
                                            currently registered.

                                        <?php endif; ?>

                                    </p>

                                </div>

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>



            <!-- =================================================
                 TABLE FOOTER
            ================================================== -->

            <div class="table-footer">


                <!-- RESULT COUNT -->

                <div class="results-count">

                    <?php

                    if ($totalUsers > 0) {

                        $start = $offset + 1;

                        $end = min(
                            $offset + $limit,
                            $totalUsers
                        );

                        echo
                            "Showing {$start} to {$end} of {$totalUsers} users";

                    } else {

                        echo "Showing 0 users";

                    }

                    ?>

                </div>



                <!-- PAGINATION -->

                <?php if ($totalPages > 1): ?>

                    <div class="pagination">


                        <!-- PREVIOUS -->

                        <?php if ($page > 1): ?>

                            <a
                                href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>"
                                class="page-button"
                            >

                                <i
                                    class="bi bi-chevron-left"
                                ></i>

                            </a>

                        <?php else: ?>

                            <span
                                class="page-button disabled"
                            >

                                <i
                                    class="bi bi-chevron-left"
                                ></i>

                            </span>

                        <?php endif; ?>



                        <!-- PAGE NUMBERS -->

                        <?php

                        $startPage =
                            max(
                                1,
                                $page - 2
                            );

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );


                        for (
                            $i = $startPage;
                            $i <= $endPage;
                            $i++
                        ):

                        ?>

                            <a
                                href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                                class="
                                    page-button
                                    <?= $i === $page
                                        ? "active"
                                        : ""
                                    ?>
                                "
                            >

                                <?= $i ?>

                            </a>

                        <?php endfor; ?>



                        <!-- NEXT -->

                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>"
                                class="page-button"
                            >

                                <i
                                    class="bi bi-chevron-right"
                                ></i>

                            </a>

                        <?php else: ?>

                            <span
                                class="page-button disabled"
                            >

                                <i
                                    class="bi bi-chevron-right"
                                ></i>

                            </span>

                        <?php endif; ?>


                    </div>

                <?php endif; ?>


            </div>


        </section>



        <!-- =================================================
             FOOTER
        ================================================== -->



    </main>


</div>


</body>

</html>


<?php

$conn->close();

?>