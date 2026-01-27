<?php
// db_connect.php - Database Connection

$host = 'localhost';
$dbname = 'virtual_airline_cms';
$username = '22gc_com_br';
$password = 'J0N$#~SEuz(up\w+'; // Default XAMPP password is empty

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage() .
        "<br>Please ensure you have created the database 'virtual_airline_cms' using the provided database.sql script.");
}

function getSystemSettings($pdo)
{
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        // Defaults
        return array_merge([
            'va_name' => 'SkyCrew Virtual Airline',
            'va_callsign' => 'SKY',
            'va_logo_url' => '',
            'daily_idle_cost' => '150.00',
            'currency_symbol' => '$'
        ], $settings ?: []);
    } catch (Exception $e) {
        return [
            'va_name' => 'SkyCrew Virtual Airline',
            'va_callsign' => 'SKY',
            'currency_symbol' => '$'
        ];
    }
}
?>