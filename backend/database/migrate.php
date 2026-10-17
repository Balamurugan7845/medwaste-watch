<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Config/Database.php';

use App\Config\Database;

echo "=== MedWaste Watch Database Migration ===\n";

try {
    $pdo = Database::getConnection();
    echo "Connected to MySQL server.\n";

    // Ensure database exists and select it
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `medwaste_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `medwaste_db`");

    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found at: " . $schemaFile);
    }

    $sql = file_get_contents($schemaFile);
    echo "Executing schema.sql...\n";

    // Use PDO direct exec for the entire script or statement by statement
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $pdo->exec($sql);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    echo "Schema created successfully! Verifying tables...\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($tables) . " tables in medwaste_db: " . implode(', ', $tables) . "\n";

    // Now run seed.php
    echo "Seeding initial clinical data...\n";
    require_once __DIR__ . '/seed.php';

    echo "=== Migration and Seeding Complete! ===\n";
} catch (Throwable $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
