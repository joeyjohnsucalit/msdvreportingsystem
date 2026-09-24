<?php
// Reads settings from environment variables (set these in Render).
// Falls back to local XAMPP values when they are not set.
$host    = getenv('DB_HOST') ?: 'localhost';
$port    = getenv('DB_PORT') ?: '3306';
$dbname  = getenv('DB_NAME') ?: 'msdvreportingsystem';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASSWORD') ?: '';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// TiDB Cloud requires a secure (TLS) connection
if (getenv('DB_HOST')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8",
        $db_user,
        $db_pass,
        $options
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    die('Database connection failed.');
}
?>