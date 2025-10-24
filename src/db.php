<?php
$db_file = '/app/data/db.sqlite'; 

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

} catch (PDOException $e) {
    echo "Connection error: " . $e->getMessage();
    exit();
}