-- Buat Database
CREATE DATABASE IF NOT EXISTS ap_digital_tv
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ap_digital_tv;

-- 1. Tabel devices: Mendaftarkan unit TV / Display
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

-- 2. Tabel media: Menyimpan aset gambar/video
CREATE TABLE IF NOT EXISTS media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    type ENUM('image', 'video') NOT NULL,
    duration INT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Durasi tampil dalam detik',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel playlists: Menghubungkan media ke device tertentu beserta urutan dan jadwal tayangnya
CREATE TABLE IF NOT EXISTS playlists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    media_id INT UNSIGNED NOT NULL,
    sequence_order INT UNSIGNED NOT NULL DEFAULT 1,
    start_date DATE NULL DEFAULT NULL COMMENT 'Tanggal mulai tayang (NULL = tanpa batas)',
    end_date DATE NULL DEFAULT NULL COMMENT 'Tanggal selesai tayang (NULL = tanpa batas)',
    start_time TIME NULL DEFAULT NULL COMMENT 'Jam mulai tayang harian (NULL = 24 jam)',
    end_time TIME NULL DEFAULT NULL COMMENT 'Jam selesai tayang harian (NULL = 24 jam)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_playlists_device
        FOREIGN KEY (device_id) REFERENCES devices(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_playlists_media
        FOREIGN KEY (media_id) REFERENCES media(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_device_sequence (device_id, sequence_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel users: Menyimpan akun administrator untuk login ke halaman utama
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akun Default Login (Username: admin | Password: admin123)
INSERT IGNORE INTO users (username, password_hash, full_name) VALUES
('admin', '$2y$10$axFWooVfwb.U2aN.IUxopeyuuSBLuM90QNyKZCK1halXpEmtywEyy', 'Administrator');

-- Contoh Data Dummy (Opsional untuk pengujian)
INSERT INTO devices (device_name, device_code, status) VALUES
('TV Lobby Utama', 'TV-LOBBY-01', 'active'),
('TV Ruang Tunggu', 'TV-WAIT-02', 'active');

INSERT INTO media (title, file_path, type, duration) VALUES
('Poster Selamat Datang', 'uploads/welcome_banner.svg', 'image', 10),
('Info Promo Oktober', 'uploads/promo_oct.svg', 'image', 10);

INSERT INTO playlists (device_id, media_id, sequence_order) VALUES
(1, 1, 1),
(1, 2, 2),
(2, 1, 1);
