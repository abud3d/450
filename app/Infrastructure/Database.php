<?php
declare(strict_types=1);

namespace Baloot\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $configPath = '/home/arbim/baloot450-config.php';
        $config = is_file($configPath) ? require $configPath : [];
        if (!is_array($config)) $config = [];

        $dsn = trim((string) ($config['dsn'] ?? getenv('BALOUT_DB_DSN')));
        $user = (string) ($config['user'] ?? getenv('BALOUT_DB_USER'));
        $pass = (string) ($config['pass'] ?? getenv('BALOUT_DB_PASSWORD'));
        if ($dsn === '' || !str_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('قاعدة بيانات MySQL غير مهيأة.');
        }

        self::$connection = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        self::$connection->exec("SET time_zone = '+00:00'");
        return self::$connection;
    }

    /**
     * Run a short atomic operation. The callback must not perform network or file I/O.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $attempt = 0;
        while (true) {
            try {
                $pdo->beginTransaction();
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $retryable = in_array((string) $e->getCode(), ['40001', '1213', '1205'], true);
                if (!$retryable || ++$attempt >= 3) {
                    throw $e;
                }
                usleep(random_int(20000, 80000) * $attempt);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    private function __construct() {}
}
