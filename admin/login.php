<?php

session_start();

if (isset($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CitiSense Admin Login</title>

    <link rel="stylesheet" href="../css/login.css">
</head>

<body>

    <div class="login-page">

        <div class="login-card">

            <div class="brand">
                <h1>Citi<span>Sense</span></h1>
                <p>Admin Portal</p>
            </div>

            <div class="login-header">
                <h2>Welcome Back</h2>
                <p>Sign in to manage your community reports.</p>
            </div>

            <?php if (isset($_GET["error"])): ?>

                <div class="error-message">

                    <?php if ($_GET["error"] === "empty"): ?>
                        Please enter your email and password.

                    <?php elseif ($_GET["error"] === "invalid"): ?>
                        Invalid email or password.

                    <?php elseif ($_GET["error"] === "notadmin"): ?>
                        This account does not have admin access.
                    <?php endif; ?>

                </div>

                <script>
                    const url = new URL(window.location.href);
                    url.searchParams.delete("error");
                    window.history.replaceState({}, document.title, url.pathname);
                </script>

            <?php endif; ?>

            <form action="../api/auth/login.php" method="POST">

                <div class="form-group">

                    <label for="email">Email</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                    >

                </div>


                <div class="forgot-password">
                    <a href="#">Forgot Password?</a>
                </div>


                <button type="submit" class="login-button">
                    Login
                </button>
            </form>
        </div>
    </div>
    
<script>
window.addEventListener("pageshow", function () {
    document.getElementById("email").value = "";
    document.getElementById("password").value = "";
});
</script>

</body>
</html>