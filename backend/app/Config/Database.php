<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/Config.php';

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            Config::load();

            $host = (string) Config::get('DB_HOST', '127.0.0.1');
            $port = (string) Config::get('DB_PORT', '3306');
            $dbname = (string) Config::get('DB_DATABASE', 'medwaste_db');
            $charset = (string) Config::get('DB_CHARSET', 'utf8mb4');
            $username = (string) Config::get('DB_USERNAME', 'root');
            $password = (string) Config::get('DB_PASSWORD', '');
            $sslCa = (string) Config::get('DB_SSL_CA', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
            ];

            if ($sslCa !== '') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }

            try {
                self::$connection = new PDO($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                // If database doesn't exist yet, connect to MySQL server root to allow running migrations
                if ($e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                    $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
                    try {
                        $tempPdo = new PDO($serverDsn, $username, $password, $options);
                        $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
                        self::$connection = new PDO($dsn, $username, $password, $options);
                        return self::$connection;
                    } catch (PDOException $ex) {
                        throw new RuntimeException("Database connection failed: " . $ex->getMessage(), (int)$ex->getCode());
                    }
                }
                throw new RuntimeException("Database connection error: " . $e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$connection;
    }

    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    public static function rollBack(): bool
    {
        if (self::getConnection()->inTransaction()) {
            return self::getConnection()->rollBack();
        }
        return false;
    }
}
