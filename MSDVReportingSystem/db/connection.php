<?php
// Reads settings from environment variables (set these in Render).
// Falls back to local XAMPP values when they are not set.
$host    = getenv('DB_HOST') ?: 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port    = getenv('DB_PORT') ?: '4000';
$dbname  = getenv('DB_NAME') ?: 'msdvreportingsystem';
$db_user = getenv('DB_USER') ?: 'tqzcLTEcYiBCmic.root';
$db_pass = getenv('DB_PASSWORD') ?: '1px0pXM2rqElrfPb';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', '')",
     PDO::ATTR_EMULATE_PREPARES   => true,  
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
