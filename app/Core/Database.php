<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class Database {
    private static ?PDO $connection = null;
    public static function connection(bool $selectDatabase = true): PDO {
        if ($selectDatabase && self::$connection) return self::$connection;
        $name = $_ENV['DB_DATABASE'] ?? 'penggajian';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new \RuntimeException('Invalid database name.');
        $dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '3306') . ($selectDatabase ? ';dbname=' . $name : '') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("SET time_zone = '+07:00'");
        if ($selectDatabase) self::$connection = $pdo;
        return $pdo;
    }
}
