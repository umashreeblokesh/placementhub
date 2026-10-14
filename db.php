<?php

// Database configuration.
// Environment variables can override these local XAMPP defaults.

$host = getenv("DB_HOST") ?: "localhost";
$username = getenv("DB_USER") ?: "root";
$password = getenv("DB_PASSWORD");
$password = ($password === false) ? "" : $password;
$database = getenv("DB_NAME") ?: "college_placement";
$port = getenv("DB_PORT");

mysqli_report(MYSQLI_REPORT_OFF);

if ($port !== false && $port !== "") {
    $conn = mysqli_connect(
        $host,
        $username,
        $password,
        $database,
        (int) $port
    );
} else {
    $conn = mysqli_connect(
        $host,
        $username,
        $password,
        $database
    );
}

if (!$conn) {
    error_log("PlacementHub database connection failed: " . mysqli_connect_error());

    if ((getenv("APP_ENV") ?: "local") === "production") {
        http_response_code(500);
        exit("A database connection error occurred. Please try again later.");
    }

    exit("Database connection failed. Check that MySQL is running and your database settings are correct.");
}

mysqli_set_charset($conn, "utf8mb4");

?>