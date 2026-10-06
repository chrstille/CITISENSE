<?php

$currentPage = basename($_SERVER["PHP_SELF"]);

?>

<link rel="stylesheet" href="../css/sidebar.css">

<div class="dashboard-container">


    <!-- ==========================================
         SIDEBAR
    =========================================== -->

    <aside class="sidebar">


        <!-- BRAND -->

        <div class="sidebar-brand">

            <div class="brand-icon">

                <i class="bi bi-buildings"></i>

            </div>


            <div class="brand-text">

                <h2>
                    Citi<span>Sense</span>
                </h2>

                <p>
                    SELF. REPORT. IMPROVE.
                </p>

            </div>

        </div>


        <!-- NAVIGATION -->

        <nav class="sidebar-nav">


            <a
                href="dashboard.php"
                class="nav-item"
            >

                <i class="bi bi-house-fill"></i>

                <span>
                    Dashboard
                </span>

            </a>


            <a
                href="reports.php"
                class="nav-item"
            >

                <i class="bi bi-file-earmark-text"></i>

                <span>
                    Reports
                </span>

            </a>


            <a
                href="heatmap.php"
                class="nav-item"
            >

                <i class="bi bi-geo-alt"></i>

                <span>
                    Heatmap
                </span>

            </a>


            <a
                href="analytics.php"
                class="nav-item"
            >

                <i class="bi bi-graph-up-arrow"></i>

                <span>
                    Analytics
                </span> 

            </a>


            <a
                href="announcements.php"
                class="nav-item"
            >

                <i class="bi bi-megaphone"></i>

                <span>
                    Announcements
                </span>

            </a>


            <a
                href="users.php"
                class="nav-item"
            >

                <i class="bi bi-people"></i>

                <span>
                    Users
                </span>

            </a>


            <a
                href="#"
                class="nav-item"
            >

                <i class="bi bi-gear"></i>

                <span>
                    Settings
                </span>

            </a>


        </nav>


        <!-- HELP CARD -->

        <div class="help-card">

            <div class="help-icon">

                <i class="bi bi-headset"></i>

            </div>


            <div class="help-content">

                <h4>
                    Need Help?
                </h4>

                <p>
                    We're here to help you anytime.
                </p>

                <button>
                    Contact Support
                </button>

            </div>

        </div>


        <!-- LOGOUT -->

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
