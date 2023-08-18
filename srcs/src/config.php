<?php

function getDatabaseConnection() {
    try {
        // establish a connection to the SQLite database at the specified path
        $db = new PDO('sqlite:/var/www/html/data/db.sqlite');

        // set error reporting to throw exceptions for database errors
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // return the database connection object
        return $db;
    } catch (PDOException $e) {
        // if there's an error connecting to the database, terminate the script and display the error
        die("Database connection error: " . $e->getMessage());
    }
}
