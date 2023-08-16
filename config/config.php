<?php

function getDatabaseConnection() {
    try {
        $db = new PDO('sqlite:/var/www/html/data/db.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (PDOException $e) {
        die("Database connection error: " . $e->getMessage());
    }
}