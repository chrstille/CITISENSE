<?php

session_start();

require_once __DIR__ . "/../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../admin/login.php");
    exit;
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
    header("Location: ../../admin/login.php?error=empty");
    exit;
}

$sql = "SELECT userID, firstName, lastName, email, pass, barangayID, role
        FROM users
        WHERE email = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    header("Location: ../../admin/login.php?error=invalid");
    exit;
}

$user = $result->fetch_assoc();

if ($user["role"] !== "admin") {
    header("Location: ../../admin/login.php?error=notadmin");
    exit;
}

if (!password_verify($password, $user["pass"])) {
    header("Location: ../../admin/login.php?error=invalid");
    exit;
}

$_SESSION["admin_id"] = $user["userID"];
$_SESSION["admin_name"] = $user["firstName"] . " " . $user["lastName"];
$_SESSION["admin_email"] = $user["email"];
$_SESSION["barangay_id"] = $user["barangayID"];

header("Location: ../../admin/dashboard.php");
exit;