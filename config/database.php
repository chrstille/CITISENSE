<?php

$host = "localhost";
$port = 3306;
$username = "root";
$password = "admin123"; 
$database = "citisense";

$conn = new mysqli($host, $username, $password, $database, $port);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

?>