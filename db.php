<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

/**
 * Konfigurasi dan Koneksi Database menggunakan PDO
 */
class Database
{
    private string $host;
    private string $port;
    private string $dbName;
    private string $username;
    private string $password;
    private string $charset = 'utf8mb4';
    private ?PDO $pdo = null;

    public function __construct()
    {
        // Mendukung Environment Variables untuk deployment di Coolify / Docker,
        // termasuk dukungan format URL langsung (DATABASE_URL / MYSQL_URL) dari Coolify
        $dbUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: '';
        if ($dbUrl !== '' && strpos($dbUrl, 'mysql://') === 0) {
            $parsed = parse_url($dbUrl);
            $this->host     = $parsed['host'] ?? '127.0.0.1';
            $this->port     = isset($parsed['port']) ? (string) $parsed['port'] : '3306';
            $this->username = isset($parsed['user']) ? urldecode($parsed['user']) : 'root';
            $this->password = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
            $this->dbName   = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'ap_digital_tv';
        } else {
            // Mengambil dari Environment Variables Coolify, atau fallback ke lokal jika kosong
            $this->host     = getenv('DB_HOST') ?: '127.0.0.1';
            $this->port     = getenv('DB_PORT') ?: '3306';
            $this->dbName   = getenv('DB_NAME') ?: 'ap_digital_tv';
            $this->username = getenv('DB_USER') ?: 'root';
            $this->password = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        }
    }

    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbName};charset={$this->charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
                $this->ensureCoreTablesExist();
                $this->ensureScheduleColumnsExist();
            } catch (PDOException $e) {
                // Jika database belum ada (Error 1049: Unknown database), buat database secara otomatis
                if ((int) $e->getCode() === 1049 || strpos($e->getMessage(), '1049') !== false) {
                    try {
                        $rootDsn = "mysql:host={$this->host};port={$this->port};charset={$this->charset}";
                        $tempPdo = new PDO($rootDsn, $this->username, $this->password, $options);
                        $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $this->dbName);
                        $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $tempPdo = null;

                        $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
                        $this->ensureCoreTablesExist();
                        $this->ensureScheduleColumnsExist();
                        return $this->pdo;
                    } catch (PDOException $innerEx) {
                        $e = $innerEx;
                    }
                }

                error_log('Database Connection Error: ' . $e->getMessage());
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Internal server error: Gagal terhubung ke database.'
                ]);
                exit;
            }
        }

        return $this->pdo;
    }

    /**
     * Otomatis membuat tabel inti jika database di VPS / Coolify masih kosong (baru dibuat)
     */
    private function ensureCoreTablesExist(): void
    {
        if ($this->pdo === null) {
            return;
        }
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS devices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_name VARCHAR(100) NOT NULL,
                device_code VARCHAR(50) NOT NULL UNIQUE,
                status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active',
                last_seen DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_device_code (device_code),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS media (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                type ENUM('image', 'video') NOT NULL,
                duration INT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Durasi tampil dalam detik',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS playlists (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id INT UNSIGNED NOT NULL,
                media_id INT UNSIGNED NOT NULL,
                sequence_order INT UNSIGNED NOT NULL DEFAULT 1,
                start_date DATE NULL DEFAULT NULL,
                end_date DATE NULL DEFAULT NULL,
                start_time TIME NULL DEFAULT NULL,
                end_time TIME NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_playlists_device
                    FOREIGN KEY (device_id) REFERENCES devices(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_playlists_media
                    FOREIGN KEY (media_id) REFERENCES media(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                INDEX idx_device_sequence (device_id, sequence_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * Memastikan kolom jadwal tayang (start_date, end_date, start_time, end_time) tersedia pada tabel playlists
     */
    private function ensureScheduleColumnsExist(): void
    {
        if ($this->pdo === null) {
            return;
        }
        $stmt = $this->pdo->query("SHOW COLUMNS FROM playlists LIKE 'start_date'");
        if ($stmt && $stmt->fetch() === false) {
            $this->pdo->exec("
                ALTER TABLE playlists
                ADD COLUMN start_date DATE NULL DEFAULT NULL AFTER sequence_order,
                ADD COLUMN end_date DATE NULL DEFAULT NULL AFTER start_date,
                ADD COLUMN start_time TIME NULL DEFAULT NULL AFTER end_date,
                ADD COLUMN end_time TIME NULL DEFAULT NULL AFTER start_time
            ");
        }
    }
}
