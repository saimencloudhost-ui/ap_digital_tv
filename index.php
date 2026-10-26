<?php
declare(strict_types=1);

session_start();

// Wajib login terlebih dahulu sebelum mengakses halaman utama (index.php)
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

/**
 * Helper fallback di sisi server untuk membaca durasi file MP4 dari atom 'mvhd'
 * jika diperlukan ketika unggah video.
 */
function getMp4DurationSeconds(string $filePath): ?int
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return null;
    }
    $fp = @fopen($filePath, 'rb');
    if (!$fp) {
        return null;
    }
    // Baca hingga 2MB pertama/terakhir untuk mencari atom 'mvhd'
    $data = @fread($fp, 2 * 1024 * 1024);
    @fclose($fp);
    if ($data === false) {
        return null;
    }
    $pos = strpos($data, 'mvhd');
    if ($pos === false || ($pos + 24) > strlen($data)) {
        return null;
    }
    $version = ord($data[$pos + 4]);
    if ($version === 1 && ($pos + 36) <= strlen($data)) {
        $timeScale = unpack('N', substr($data, $pos + 24, 4))[1] ?? 0;
        $durationHigh = unpack('N', substr($data, $pos + 28, 4))[1] ?? 0;
        $durationLow = unpack('N', substr($data, $pos + 32, 4))[1] ?? 0;
        $duration = ($durationHigh * 4294967296.0) + $durationLow;
    } else {
        $timeScale = unpack('N', substr($data, $pos + 16, 4))[1] ?? 0;
        $duration = unpack('N', substr($data, $pos + 20, 4))[1] ?? 0;
    }
    if ($timeScale > 0 && $duration > 0) {
        return max(1, (int) ceil($duration / $timeScale));
    }
    return null;
}

$db = (new Database())->getConnection();
$flashMessage = '';
$flashType = 'success';

// Tangani form submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Tambah Device Baru
        if ($action === 'add_device') {
            $name = trim((string) ($_POST['device_name'] ?? ''));
            $code = strtoupper(trim((string) ($_POST['device_code'] ?? '')));
            $status = $_POST['status'] ?? 'active';

            if ($name === '' || $code === '') {
                throw new RuntimeException('Nama device dan kode device wajib diisi.');
            }

            $stmt = $db->prepare("INSERT INTO devices (device_name, device_code, status) VALUES (:name, :code, :status)");
            $stmt->execute([
                ':name'   => $name,
                ':code'   => $code,
                ':status' => in_array($status, ['active', 'inactive', 'maintenance'], true) ? $status : 'active',
            ]);
            $flashMessage = "Device '{$name}' ({$code}) berhasil ditambahkan.";
        }

        // 2. Update Status Device
        elseif ($action === 'update_device_status') {
            $id = (int) ($_POST['device_id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            if ($id > 0 && in_array($status, ['active', 'inactive', 'maintenance'], true)) {
                $stmt = $db->prepare("UPDATE devices SET status = :status WHERE id = :id");
                $stmt->execute([':status' => $status, ':id' => $id]);
                $flashMessage = 'Status device berhasil diperbarui.';
            }
        }

        // 3. Hapus Device
        elseif ($action === 'delete_device') {
            $id = (int) ($_POST['device_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM devices WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $flashMessage = 'Device berhasil dihapus.';
            }
        }

        // 4. Upload / Tambah Media Baru
        elseif ($action === 'add_media') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $type = $_POST['type'] ?? 'image';
            $duration = max(1, (int) ($_POST['duration'] ?? 10));

            if ($title === '') {
                throw new RuntimeException('Judul media wajib diisi.');
            }

            if (!isset($_FILES['media_file']) || $_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Silakan pilih file gambar/video yang valid untuk diunggah.');
            }

            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $originalName = basename((string) $_FILES['media_file']['name']);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            $allowedVideo = ['mp4', 'webm', 'ogg'];

            // Otomatis sesuaikan tipe jika ekstensi file adalah video
            if (in_array($ext, $allowedVideo, true)) {
                $type = 'video';
            }

            if ($type === 'image' && !in_array($ext, $allowedImage, true)) {
                throw new RuntimeException('Format gambar tidak didukung. Gunakan JPG, PNG, WEBP, GIF, atau SVG.');
            }
            if ($type === 'video' && !in_array($ext, $allowedVideo, true)) {
                throw new RuntimeException('Format video tidak didukung. Gunakan MP4, WEBM, atau OGG.');
            }

            $safeFilename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $targetPath = $uploadDir . $safeFilename;

            if (!move_uploaded_file($_FILES['media_file']['tmp_name'], $targetPath)) {
                throw new RuntimeException('Gagal menyimpan file ke folder uploads/.');
            }

            // Jika video dan durasi belum terdeteksi dari metadata browser, coba baca dari header MP4
            if ($type === 'video') {
                $detectedSec = (int) ($_POST['video_detected_duration'] ?? 0);
                if ($detectedSec > 0) {
                    $duration = $detectedSec;
                } else {
                    $mp4Sec = getMp4DurationSeconds($targetPath);
                    if ($mp4Sec !== null) {
                        $duration = $mp4Sec;
                    }
                }
            }

            $relativePath = 'uploads/' . $safeFilename;
            $stmt = $db->prepare("INSERT INTO media (title, file_path, type, duration) VALUES (:title, :path, :type, :duration)");
            $stmt->execute([
                ':title'    => $title,
                ':path'     => $relativePath,
                ':type'     => $type,
                ':duration' => $duration,
            ]);
            $flashMessage = $type === 'video'
                ? "Video '{$title}' berhasil diunggah dengan durasi otomatis ({$duration} detik)."
                : "Gambar '{$title}' berhasil diunggah ({$duration} detik).";
        }

        // 5. Hapus Media beserta File Fisiknya
        elseif ($action === 'delete_media') {
            $id = (int) ($_POST['media_id'] ?? 0);
            if ($id > 0) {
                // Ambil informasi file_path terlebih dahulu sebelum baris dihapus
                $stmtSelect = $db->prepare("SELECT title, file_path FROM media WHERE id = :id LIMIT 1");
                $stmtSelect->execute([':id' => $id]);
                $mediaRow = $stmtSelect->fetch();

                if ($mediaRow) {
                    // Hapus data dari tabel media (otomatis menghapus relasi di playlists karena ON DELETE CASCADE)
                    $stmtDelete = $db->prepare("DELETE FROM media WHERE id = :id");
                    $stmtDelete->execute([':id' => $id]);

                    // Hapus file fisik di dalam direktori uploads/
                    $relativeFilePath = ltrim((string) $mediaRow['file_path'], '/\\');
                    $fullFilePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeFilePath);
                    $uploadsBaseDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
                    $realTargetFile = is_file($fullFilePath) ? realpath($fullFilePath) : false;

                    // Pastikan file benar-benar berada di dalam folder uploads/ demi keamanan
                    if ($realTargetFile !== false && $uploadsBaseDir !== false && strpos($realTargetFile, $uploadsBaseDir) === 0) {
                        @unlink($realTargetFile);
                    }

                    $flashMessage = "Media '{$mediaRow['title']}' beserta file fisiknya berhasil dihapus.";
                }
            }
        }

        // 6. Tambah Media ke Playlist Device beserta Jadwal Tayang (Schedule Play)
        elseif ($action === 'add_playlist') {
            $deviceId = (int) ($_POST['device_id'] ?? 0);
            $mediaId = (int) ($_POST['media_id'] ?? 0);
            $seqOrder = max(1, (int) ($_POST['sequence_order'] ?? 1));

            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $startTime = trim((string) ($_POST['start_time'] ?? ''));
            $endTime = trim((string) ($_POST['end_time'] ?? ''));

            $startDateVal = $startDate !== '' ? $startDate : null;
            $endDateVal = $endDate !== '' ? $endDate : null;
            $startTimeVal = $startTime !== '' ? $startTime : null;
            $endTimeVal = $endTime !== '' ? $endTime : null;

            if ($deviceId <= 0 || $mediaId <= 0) {
                throw new RuntimeException('Pilih Device dan Media terlebih dahulu.');
            }

            if ($startDateVal !== null && $endDateVal !== null && $startDateVal > $endDateVal) {
                throw new RuntimeException('Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai.');
            }

            $stmt = $db->prepare("
                INSERT INTO playlists (device_id, media_id, sequence_order, start_date, end_date, start_time, end_time)
                VALUES (:device_id, :media_id, :seq, :start_date, :end_date, :start_time, :end_time)
            ");
            $stmt->execute([
                ':device_id'  => $deviceId,
                ':media_id'   => $mediaId,
                ':seq'        => $seqOrder,
                ':start_date' => $startDateVal,
                ':end_date'   => $endDateVal,
                ':start_time' => $startTimeVal,
                ':end_time'   => $endTimeVal,
            ]);
            $flashMessage = 'Konten beserta jadwal tayangnya berhasil ditambahkan ke playlist TV.';
        }

        // 7. Hapus Item dari Playlist
        elseif ($action === 'delete_playlist') {
            $id = (int) ($_POST['playlist_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM playlists WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $flashMessage = 'Item berhasil dihapus dari playlist.';
            }
        }

        // 8. Tambah User Login Baru
        elseif ($action === 'add_user') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');

            if ($fullName === '' || $username === '' || $password === '') {
                throw new RuntimeException('Nama Lengkap, Username, dan Password wajib diisi.');
            }
            if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $username)) {
                throw new RuntimeException('Username hanya boleh berisi huruf kecil, angka, titik, strip, atau garis bawah (3-50 karakter).');
            }
            if (strlen($password) < 4) {
                throw new RuntimeException('Password minimal harus terdiri dari 4 karakter.');
            }

            // Cek apakah username sudah terdaftar
            $stmtCheck = $db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
            $stmtCheck->execute([':u' => $username]);
            if ($stmtCheck->fetch()) {
                throw new RuntimeException("Username '{$username}' sudah digunakan. Silakan pilih username lain.");
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmtInsertUser = $db->prepare("INSERT INTO users (username, password_hash, full_name) VALUES (:u, :p, :f)");
            $stmtInsertUser->execute([
                ':u' => $username,
                ':p' => $passwordHash,
                ':f' => $fullName,
            ]);
            $flashMessage = "User login '{$username}' ({$fullName}) berhasil ditambahkan.";
        }

        // 9. Hapus User Login
        elseif ($action === 'delete_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);

            if ($userId <= 0) {
                throw new RuntimeException('ID user tidak valid.');
            }
            if ($userId === $currentAdminId) {
                throw new RuntimeException('Anda tidak dapat menghapus akun yang sedang Anda gunakan untuk login saat ini.');
            }

            $totalUsers = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($totalUsers <= 1) {
                throw new RuntimeException('Tidak dapat menghapus user terakhir di dalam sistem.');
            }

            $stmtDelUser = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmtDelUser->execute([':id' => $userId]);
            $flashMessage = 'User login berhasil dihapus.';
        }
    } catch (Throwable $e) {
        $flashMessage = $e->getMessage();
        $flashType = 'error';
    }
}

/**
 * Helper untuk mengevaluasi status jadwal tayang playlist saat ini
 */
function evaluatePlaylistSchedule(array $p): array
{
    $startDate = $p['start_date'] ?? null;
    $endDate   = $p['end_date'] ?? null;
    $startTime = $p['start_time'] ?? null;
    $endTime   = $p['end_time'] ?? null;

    if ($startDate === null && $endDate === null && $startTime === null && $endTime === null) {
        return [
            'status'      => 'always',
            'badge_label' => 'Tayang Selalu (24 Jam)',
            'badge_class' => 'badge-active',
            'date_label'  => 'Setiap Hari',
            'time_label'  => '00:00 - 23:59',
        ];
    }

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    // Format label tanggal & jam
    if ($startDate !== null && $endDate !== null) {
        $dateLabel = "{$startDate} s/d {$endDate}";
    } elseif ($startDate !== null) {
        $dateLabel = "Mulai {$startDate}";
    } elseif ($endDate !== null) {
        $dateLabel = "Sampai {$endDate}";
    } else {
        $dateLabel = 'Setiap Hari';
    }

    $fmtStart = $startTime !== null ? substr($startTime, 0, 5) : '00:00';
    $fmtEnd   = $endTime !== null ? substr($endTime, 0, 5) : '23:59';
    $timeLabel = ($startTime !== null || $endTime !== null) ? "{$fmtStart} - {$fmtEnd} WIB" : '24 Jam';

    // Evaluasi apakah sudah lewat tanggal selesai
    if ($endDate !== null && $today > $endDate) {
        return [
            'status'      => 'expired',
            'badge_label' => 'Jadwal Berakhir',
            'badge_class' => 'badge-expired',
            'date_label'  => $dateLabel,
            'time_label'  => $timeLabel,
        ];
    }

    // Evaluasi apakah belum masuk tanggal mulai
    if ($startDate !== null && $today < $startDate) {
        return [
            'status'      => 'scheduled',
            'badge_label' => 'Terjadwal (Menunggu)',
            'badge_class' => 'badge-scheduled',
            'date_label'  => $dateLabel,
            'time_label'  => $timeLabel,
        ];
    }

    // Evaluasi jam harian
    $inTimeWindow = true;
    if ($startTime !== null && $endTime !== null) {
        if ($startTime <= $endTime) {
            $inTimeWindow = ($nowTime >= $startTime && $nowTime <= $endTime);
        } else {
            // Lintas tengah malam (misal 22:00 s/d 06:00)
            $inTimeWindow = ($nowTime >= $startTime || $nowTime <= $endTime);
        }
    } elseif ($startTime !== null) {
        $inTimeWindow = ($nowTime >= $startTime);
    } elseif ($endTime !== null) {
        $inTimeWindow = ($nowTime <= $endTime);
    }

    if ($inTimeWindow) {
        return [
            'status'      => 'playing',
            'badge_label' => 'Sedang Tayang (Aktif)',
            'badge_class' => 'badge-active',
            'date_label'  => $dateLabel,
            'time_label'  => $timeLabel,
        ];
    }

    return [
        'status'      => 'scheduled',
        'badge_label' => 'Di Luar Jam Tayang',
        'badge_class' => 'badge-scheduled',
        'date_label'  => $dateLabel,
        'time_label'  => $timeLabel,
    ];
}

// Ambil seluruh data untuk ditampilkan di Halaman Utama
$devices = $db->query("SELECT * FROM devices ORDER BY id ASC")->fetchAll();
$mediaList = $db->query("SELECT * FROM media ORDER BY id DESC")->fetchAll();
$usersList = $db->query("SELECT id, username, full_name, created_at FROM users ORDER BY id ASC")->fetchAll();
$playlists = $db->query("
    SELECT 
        p.id,
        p.device_id,
        p.sequence_order,
        p.start_date,
        p.end_date,
        p.start_time,
        p.end_time,
        d.device_name,
        d.device_code,
        m.title AS media_title,
        m.type AS media_type,
        m.duration,
        m.file_path
    FROM playlists p
    INNER JOIN devices d ON p.device_id = d.id
    INNER JOIN media m ON p.media_id = m.id
    ORDER BY d.id ASC, p.sequence_order ASC, p.id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Konten — AP Digital Signage</title>
    <style>
        :root {
            --bg: #0f172a;
            --nav-bg: rgba(15, 23, 42, 0.92);
            --card: #1e293b;
            --border: #334155;
            --text: #f8fafc;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --danger: #ef4444;
            --success: #22c55e;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        html {
            scroll-behavior: smooth;
        }
        body {
            background-color: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
        }

        /* NAVBAR UTAMA */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--nav-bg);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0 28px;
        }
        .navbar-inner {
            max-width: 1280px;
            margin: 0 auto;
            height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
            font-weight: 700;
            font-size: 1.18rem;
        }
        .brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, #3b82f6, #2dd4bf);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.95rem;
            color: #0f172a;
        }
        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
        }
        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 600;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }
        .nav-link:hover {
            color: var(--text);
            background: rgba(255, 255, 255, 0.05);
        }
        .nav-link.active {
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
            border-color: rgba(56, 189, 248, 0.3);
        }
        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 28px;
        }
        .page-header {
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 1.55rem;
            color: #fff;
        }
        .page-header p {
            color: var(--muted);
            font-size: 0.92rem;
        }

        /* Sub-tab Filter Menu Konten */
        .subnav-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
            flex-wrap: wrap;
        }
        .tab-btn {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--border);
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        .tab-btn.active, .tab-btn:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
        }
        .alert.success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.4);
            color: #86efac;
        }
        .alert.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 960px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .navbar-inner {
                height: auto;
                padding: 12px 0;
                flex-wrap: wrap;
            }
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 24px;
        }
        .card h2 {
            font-size: 1.2rem;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .form-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .form-group {
            flex: 1;
            min-width: 160px;
        }
        label {
            display: block;
            font-size: 0.82rem;
            color: var(--muted);
            margin-bottom: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        input, select {
            width: 100%;
            padding: 9px 12px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: #0f172a;
            color: var(--text);
            font-size: 0.92rem;
        }
        input[readonly] {
            background: rgba(15, 23, 42, 0.5);
            color: #38bdf8;
            border-color: rgba(56, 189, 248, 0.4);
            cursor: not-allowed;
            font-weight: 600;
        }
        .helper-text {
            display: block;
            margin-top: 5px;
            font-size: 0.78rem;
            color: #38bdf8;
        }
        button, .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 16px;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn-danger {
            background: rgba(239, 68, 68, 0.18);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
            padding: 6px 11px;
            font-size: 0.8rem;
        }
        .btn-danger:hover {
            background: var(--danger);
            color: #fff;
        }
        .btn-outline {
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
            padding: 6px 11px;
            font-size: 0.8rem;
        }
        .btn-outline:hover {
            background: rgba(56, 189, 248, 0.25);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 0.9rem;
        }
        th, td {
            text-align: left;
            padding: 11px 12px;
            border-bottom: 1px solid var(--border);
        }
        th {
            color: var(--muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-type {
            background: rgba(148, 163, 184, 0.15);
            color: #cbd5e1;
        }
        .badge-active {
            background: rgba(34, 197, 94, 0.16);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.35);
        }
        .badge-scheduled {
            background: rgba(245, 158, 11, 0.16);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }
        .badge-expired {
            background: rgba(239, 68, 68, 0.16);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
        }
        .schedule-box {
            background: rgba(15, 23, 42, 0.55);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 16px;
        }
        .schedule-box-title {
            font-size: 0.84rem;
            font-weight: 600;
            color: #38bdf8;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        code {
            background: #0f172a;
            padding: 3px 7px;
            border-radius: 4px;
            color: #38bdf8;
            font-size: 0.85rem;
        }
        .hidden-section {
            display: none !important;
        }

        /* Toolbar Search & Filter Tabel */
        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .toolbar-search {
            flex: 1;
            min-width: 180px;
            position: relative;
        }
        .toolbar-search input {
            padding-left: 34px;
            font-size: 0.86rem;
            height: 36px;
        }
        .toolbar-search svg {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }
        .toolbar-filters {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .toolbar-filters select {
            width: auto;
            height: 36px;
            padding: 6px 10px;
            font-size: 0.84rem;
        }

        /* Pagination Footer */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
            padding-top: 12px;
            font-size: 0.84rem;
            color: var(--muted);
        }
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        .page-btn {
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 6px;
            background: #0f172a;
            border: 1px solid var(--border);
            color: var(--text);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .page-btn:hover:not(:disabled) {
            border-color: var(--primary);
            color: #38bdf8;
        }
        .page-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .empty-row td {
            text-align: center;
            padding: 24px 12px;
            color: var(--muted);
            font-style: italic;
        }

        /* Navbar Dynamic Player Picker */
        .navbar-player-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid var(--border);
            padding: 4px 6px;
            border-radius: 8px;
        }
        .navbar-player-group .sselect-wrapper {
            min-width: 215px;
        }
        .navbar-player-group .sselect-trigger {
            height: 34px;
            padding: 5px 28px 5px 10px;
            font-size: 0.83rem;
            border-color: rgba(56, 189, 248, 0.3);
        }

        /* Custom Searchable Dropdown (Dropdown Search) */
        .sselect-native-hidden {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0, 0, 0, 0) !important;
            border: 0 !important;
            pointer-events: none !important;
        }
        .sselect-wrapper {
            position: relative;
            width: 100%;
            display: inline-block;
            vertical-align: middle;
        }
        .toolbar-filters .sselect-wrapper {
            width: auto;
            min-width: 150px;
        }
        .toolbar-filters .sselect-wrapper.sselect-limit {
            min-width: 105px;
        }
        td .sselect-wrapper {
            width: auto;
            min-width: 120px;
        }
        .sselect-trigger {
            width: 100%;
            min-height: 39px;
            padding: 8px 32px 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: #0f172a;
            color: var(--text);
            font-size: 0.9rem;
            text-align: left;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .toolbar-filters .sselect-trigger {
            min-height: 36px;
            height: 36px;
            padding: 6px 28px 6px 10px;
            font-size: 0.84rem;
        }
        td .sselect-trigger {
            min-height: 30px;
            padding: 4px 26px 4px 9px;
            font-size: 0.8rem;
        }
        .sselect-trigger:hover,
        .sselect-wrapper.open .sselect-trigger {
            border-color: #38bdf8;
        }
        .sselect-wrapper.open .sselect-trigger {
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.18);
        }
        .sselect-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            width: 100%;
        }
        .sselect-arrow {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
            transition: transform 0.18s ease;
        }
        .sselect-wrapper.open .sselect-arrow {
            transform: translateY(-50%) rotate(180deg);
            color: #38bdf8;
        }
        .sselect-menu {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            min-width: 100%;
            width: max-content;
            max-width: 380px;
            background: #0f172a;
            border: 1px solid #38bdf8;
            border-radius: 8px;
            box-shadow: 0 14px 32px rgba(0, 0, 0, 0.65);
            z-index: 1200;
            display: none;
            overflow: hidden;
        }
        .navbar-player-group .sselect-menu,
        .toolbar-filters .sselect-menu {
            right: 0;
            left: auto;
        }
        .sselect-wrapper.open .sselect-menu {
            display: block;
        }
        .sselect-search-wrap {
            padding: 8px;
            border-bottom: 1px solid var(--border);
            background: #1e293b;
            position: relative;
        }
        .sselect-search-wrap svg {
            position: absolute;
            left: 17px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }
        .sselect-search-input {
            width: 100% !important;
            height: 32px !important;
            padding: 5px 10px 5px 30px !important;
            font-size: 0.82rem !important;
            border-radius: 5px !important;
            border: 1px solid var(--border) !important;
            background: #0f172a !important;
            color: var(--text) !important;
            outline: none !important;
        }
        .sselect-search-input:focus {
            border-color: #38bdf8 !important;
        }
        .sselect-options {
            max-height: 220px;
            overflow-y: auto;
            padding: 4px 0;
        }
        .sselect-option {
            padding: 8px 12px;
            font-size: 0.85rem;
            color: var(--text);
            cursor: pointer;
            transition: background 0.1s ease;
            white-space: normal;
            line-height: 1.35;
        }
        .sselect-option:hover,
        .sselect-option.highlighted {
            background: rgba(56, 189, 248, 0.16);
            color: #38bdf8;
        }
        .sselect-option.selected {
            background: rgba(59, 130, 246, 0.25);
            color: #fff;
            font-weight: 600;
        }
        .sselect-empty {
            padding: 10px 12px;
            font-size: 0.8rem;
            color: var(--muted);
            text-align: center;
            font-style: italic;
            display: none;
        }
    </style>
</head>
<body>

<!-- NAVBAR UTAMA -->
<nav class="navbar">
    <div class="navbar-inner">
        <a href="index.php" class="navbar-brand">
            <span class="brand-icon">TV</span>
            <span>AP Digital Signage</span>
        </a>

        <ul class="navbar-nav">
            <li>
                <a href="#menu-konten" class="nav-link active" onclick="filterSection('all', this)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    Menu Konten
                </a>
            </li>
        </ul>

        <?php
        $defaultPlayerCode = !empty($devices) ? (string) $devices[0]['device_code'] : 'TV-LOBBY-01';
        ?>
        <div class="navbar-actions">
            <span style="font-size:0.85rem; color:var(--muted); margin-right:4px;">
                Halo, <strong style="color:#fff;"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></strong>
            </span>

            <!-- Pilihan Perangkat TV Dinamis untuk Buka Player & JSON API -->
            <div class="navbar-player-group">
                <select id="navbar-device-select" onchange="updateNavbarPlayerLinks()" title="Pilih Perangkat TV yang ingin dibuka">
                    <?php if (!empty($devices)): ?>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= htmlspecialchars($d['device_code'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($d['device_name']) ?> (<?= htmlspecialchars($d['device_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="TV-LOBBY-01">TV-LOBBY-01 (Default)</option>
                    <?php endif; ?>
                </select>
                <a href="player.php?code=<?= urlencode($defaultPlayerCode) ?>" id="btn-navbar-player" target="_blank" class="btn btn-primary" style="padding:7px 13px; font-size:0.82rem; white-space:nowrap;">
                    ▶ Buka Player
                </a>
                <a href="api.php?code=<?= urlencode($defaultPlayerCode) ?>" id="btn-navbar-api" target="_blank" class="btn btn-outline" style="padding:7px 10px; font-size:0.8rem; white-space:nowrap;">
                    JSON API
                </a>
            </div>

            <a href="logout.php" class="btn btn-danger" onclick="return confirm('Keluar dari sesi Admin?');">Logout</a>
        </div>
    </div>
</nav>

<div class="container" id="menu-konten">
    <div class="page-header">
        <h1>Menu Konten Digital Signage</h1>
        <p>Kelola pustaka media (gambar/video), daftar perangkat TV, serta urutan tayangan playlist.</p>
    </div>

    <!-- Filter Sub-Bagian di dalam Menu Konten -->
    <div class="subnav-tabs">
        <button type="button" class="tab-btn active" onclick="switchTab('all', this)">Semua Bagian</button>
        <button type="button" class="tab-btn" onclick="switchTab('media', this)">Pustaka Media</button>
        <button type="button" class="tab-btn" onclick="switchTab('playlist', this)">Playlist Tayangan</button>
        <button type="button" class="tab-btn" onclick="switchTab('devices', this)">Perangkat TV</button>
        <button type="button" class="tab-btn" onclick="switchTab('users', this)">Manajemen User</button>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert <?= $flashType ?>">
            <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="grid-2" id="top-grid">
        <!-- 1. MANAJEMEN DEVICES -->
        <div class="card" id="section-devices">
            <h2>1. Daftar Perangkat TV (Devices)</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_device">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nama TV / Lokasi</label>
                        <input type="text" name="device_name" placeholder="Contoh: TV Kantin" required>
                    </div>
                    <div class="form-group">
                        <label>Kode Unik (device_code)</label>
                        <input type="text" name="device_code" placeholder="Contoh: TV-KANTIN-03" required>
                    </div>
                    <div class="form-group" style="max-width:140px;">
                        <label>Status</label>
                        <select name="status">
                            <option value="active">active</option>
                            <option value="inactive">inactive</option>
                            <option value="maintenance">maintenance</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">+ Daftarkan TV</button>
            </form>

            <!-- Toolbar Search & Limit Perangkat TV -->
            <div class="table-toolbar">
                <div class="toolbar-search">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="search-devices" placeholder="Cari nama TV atau kode unik...">
                </div>
                <div class="toolbar-filters">
                    <select id="filter-devices" title="Filter Status">
                        <option value="">Semua Status</option>
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                        <option value="maintenance">maintenance</option>
                    </select>
                    <select id="limit-devices" title="Jumlah baris per halaman">
                        <option value="5">5 / hal</option>
                        <option value="10">10 / hal</option>
                        <option value="25">25 / hal</option>
                        <option value="50">50 / hal</option>
                    </select>
                </div>
            </div>

            <table id="table-devices">
                <thead>
                    <tr>
                        <th>Nama & Kode</th>
                        <th>Status</th>
                        <th>Last Seen</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $d): ?>
                        <tr class="data-row"
                            data-search="<?= htmlspecialchars(strtolower($d['device_name'] . ' ' . $d['device_code'] . ' ' . ($d['last_seen'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                            data-filter="<?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <strong><?= htmlspecialchars($d['device_name']) ?></strong><br>
                                <code><?= htmlspecialchars($d['device_code']) ?></code>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="update_device_status">
                                    <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                                    <select name="status" onchange="this.form.submit()" style="width:auto; padding:4px 8px; font-size:0.8rem;">
                                        <?php foreach (['active', 'inactive', 'maintenance'] as $st): ?>
                                            <option value="<?= $st ?>" <?= $d['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td style="color:var(--muted); font-size:0.82rem;">
                                <?= $d['last_seen'] ? htmlspecialchars($d['last_seen']) : 'Belum pernah terkoneksi' ?>
                            </td>
                            <td style="display:flex; gap:6px; align-items:center;">
                                <a href="player.php?code=<?= urlencode($d['device_code']) ?>" target="_blank" class="btn btn-outline">Player</a>
                                <a href="api.php?code=<?= urlencode($d['device_code']) ?>" target="_blank" class="btn btn-outline">JSON</a>
                                <form method="POST" onsubmit="return confirm('Hapus device ini?');">
                                    <input type="hidden" name="action" value="delete_device">
                                    <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="pagination-bar">
                <div id="info-devices">Menampilkan 0 data</div>
                <div class="pagination-controls" id="pager-devices"></div>
            </div>
        </div>

        <!-- 2. MANAJEMEN MEDIA -->
        <div class="card" id="section-media">
            <h2>2. Pustaka Media (Gambar / Video)</h2>
            <form method="POST" enctype="multipart/form-data" id="form-upload-media">
                <input type="hidden" name="action" value="add_media">
                <input type="hidden" name="video_detected_duration" id="video_detected_duration" value="0">

                <div class="form-row">
                    <div class="form-group">
                        <label>Judul Konten</label>
                        <input type="text" name="title" placeholder="Contoh: Video Profil / Banner Promo" required>
                    </div>
                    <div class="form-group" style="max-width:130px;">
                        <label>Tipe</label>
                        <select name="type" id="media_type" onchange="handleMediaTypeChange()">
                            <option value="image">image</option>
                            <option value="video">video</option>
                        </select>
                    </div>
                    <div class="form-group" style="max-width:170px;" id="duration_group">
                        <label id="duration_label">Durasi (Detik)</label>
                        <input type="number" name="duration" id="media_duration" value="10" min="1" required>
                        <small id="duration_helper" class="helper-text" style="display:none;">Otomatis sesuai durasi video</small>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Pilih File (JPG, PNG, SVG, MP4, WEBM)</label>
                        <input type="file" name="media_file" id="media_file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.mp4,.webm,.ogg" onchange="handleFileSelect(this)" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" id="btn-submit-media">+ Unggah Media</button>
            </form>

            <!-- Toolbar Search & Limit Pustaka Media -->
            <div class="table-toolbar">
                <div class="toolbar-search">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="search-media" placeholder="Cari judul media atau nama file...">
                </div>
                <div class="toolbar-filters">
                    <select id="filter-media" title="Filter Tipe Media">
                        <option value="">Semua Tipe</option>
                        <option value="image">image</option>
                        <option value="video">video</option>
                    </select>
                    <select id="limit-media" title="Jumlah baris per halaman">
                        <option value="5">5 / hal</option>
                        <option value="10">10 / hal</option>
                        <option value="25">25 / hal</option>
                        <option value="50">50 / hal</option>
                    </select>
                </div>
            </div>

            <table id="table-media">
                <thead>
                    <tr>
                        <th>ID & Judul</th>
                        <th>Tipe</th>
                        <th>Durasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mediaList as $m): ?>
                        <tr class="data-row"
                            data-search="<?= htmlspecialchars(strtolower('#' . $m['id'] . ' ' . $m['title'] . ' ' . $m['file_path']), ENT_QUOTES, 'UTF-8') ?>"
                            data-filter="<?= htmlspecialchars($m['type'], ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <strong>#<?= (int) $m['id'] ?> — <?= htmlspecialchars($m['title']) ?></strong><br>
                                <a href="<?= htmlspecialchars($m['file_path']) ?>" target="_blank" style="color:#38bdf8; font-size:0.8rem;">
                                    <?= htmlspecialchars($m['file_path']) ?>
                                </a>
                            </td>
                            <td><span class="badge badge-type"><?= htmlspecialchars($m['type']) ?></span></td>
                            <td>
                                <?= (int) $m['duration'] ?> detik
                                <?php if ($m['type'] === 'video'): ?>
                                    <span style="color:#38bdf8; font-size:0.75rem;">(Auto)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Hapus media ini beserta file fisiknya dari server?');">
                                    <input type="hidden" name="action" value="delete_media">
                                    <input type="hidden" name="media_id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="pagination-bar">
                <div id="info-media">Menampilkan 0 data</div>
                <div class="pagination-controls" id="pager-media"></div>
            </div>
        </div>
    </div>

    <!-- 3. MANAJEMEN PLAYLIST & SCHEDULE PLAY -->
    <div class="card" id="section-playlist">
        <h2>
            <span>3. Pengaturan Playlist &amp; Jadwal Tayang per Device TV</span>
            <span style="font-size:0.8rem; font-weight:normal; color:var(--muted);">Waktu Server: <?= date('Y-m-d H:i') ?> WIB</span>
        </h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_playlist">
            <div class="form-row">
                <div class="form-group">
                    <label>Pilih Device TV</label>
                    <select name="device_id" required>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= (int) $d['id'] ?>">
                                <?= htmlspecialchars($d['device_name']) ?> (<?= htmlspecialchars($d['device_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pilih Konten Media</label>
                    <select name="media_id" required>
                        <?php foreach ($mediaList as $m): ?>
                            <option value="<?= (int) $m['id'] ?>">
                                [<?= strtoupper($m['type']) ?>] <?= htmlspecialchars($m['title']) ?> (<?= (int) $m['duration'] ?>s)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="max-width:160px;">
                    <label>Urutan (sequence_order)</label>
                    <input type="number" name="sequence_order" value="1" min="1" required>
                </div>
            </div>

            <!-- Pengaturan Schedule Play -->
            <div class="schedule-box">
                <div class="schedule-box-title">
                    <span>Jadwal Tayang / Schedule Play (Opsional — Kosongkan jika ingin tayang terus 24 Jam)</span>
                    <button type="button" class="btn btn-outline" style="padding:3px 10px; font-size:0.75rem;" onclick="clearScheduleFields()">Reset Jadwal (24 Jam)</button>
                </div>
                <div class="form-row" style="margin-bottom:0;">
                    <div class="form-group">
                        <label>Tanggal Mulai Tayang</label>
                        <input type="date" name="start_date" id="sched_start_date">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Selesai Tayang</label>
                        <input type="date" name="end_date" id="sched_end_date">
                    </div>
                    <div class="form-group">
                        <label>Jam Mulai Harian</label>
                        <input type="time" name="start_time" id="sched_start_time">
                    </div>
                    <div class="form-group">
                        <label>Jam Selesai Harian</label>
                        <input type="time" name="end_time" id="sched_end_time">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">+ Tambahkan ke Playlist</button>
        </form>

        <!-- Toolbar Search & Limit Playlist Tayangan -->
        <div class="table-toolbar">
            <div class="toolbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="search-playlist" placeholder="Cari nama TV, kode TV, judul konten, atau jadwal...">
            </div>
            <div class="toolbar-filters">
                <select id="filter-playlist" title="Filter berdasarkan Device TV">
                    <option value="">Semua Device TV</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= htmlspecialchars($d['device_code'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($d['device_name']) ?> (<?= htmlspecialchars($d['device_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="limit-playlist" title="Jumlah baris per halaman">
                    <option value="5">5 / hal</option>
                    <option value="10" selected>10 / hal</option>
                    <option value="25">25 / hal</option>
                    <option value="50">50 / hal</option>
                </select>
            </div>
        </div>

        <table id="table-playlist">
            <thead>
                <tr>
                    <th>Device TV</th>
                    <th>Urutan</th>
                    <th>Konten Media</th>
                    <th>Tipe &amp; Durasi</th>
                    <th>Jadwal Tayang (Schedule Play)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($playlists as $p): ?>
                    <?php $sched = evaluatePlaylistSchedule($p); ?>
                    <tr class="data-row"
                        data-search="<?= htmlspecialchars(strtolower($p['device_name'] . ' ' . $p['device_code'] . ' #' . $p['sequence_order'] . ' ' . $p['media_title'] . ' ' . $p['media_type'] . ' ' . $sched['badge_label'] . ' ' . $sched['date_label'] . ' ' . $sched['time_label']), ENT_QUOTES, 'UTF-8') ?>"
                        data-filter="<?= htmlspecialchars($p['device_code'], ENT_QUOTES, 'UTF-8') ?>">
                        <td>
                            <strong><?= htmlspecialchars($p['device_name']) ?></strong><br>
                            <code><?= htmlspecialchars($p['device_code']) ?></code>
                        </td>
                        <td><strong>#<?= (int) $p['sequence_order'] ?></strong></td>
                        <td>
                            <a href="<?= htmlspecialchars($p['file_path']) ?>" target="_blank" style="color:#f8fafc; font-weight:600;">
                                <?= htmlspecialchars($p['media_title']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-type"><?= htmlspecialchars($p['media_type']) ?></span>
                            <span style="font-size:0.82rem; color:var(--muted); margin-left:4px;"><?= (int) $p['duration'] ?>s</span>
                        </td>
                        <td>
                            <span class="badge <?= $sched['badge_class'] ?>"><?= htmlspecialchars($sched['badge_label']) ?></span>
                            <div style="font-size:0.78rem; color:var(--muted); margin-top:4px;">
                                📅 <?= htmlspecialchars($sched['date_label']) ?> &bull; ⏰ <?= htmlspecialchars($sched['time_label']) ?>
                            </div>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Hapus konten ini dari playlist?');">
                                <input type="hidden" name="action" value="delete_playlist">
                                <input type="hidden" name="playlist_id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination-bar">
            <div id="info-playlist">Menampilkan 0 data</div>
            <div class="pagination-controls" id="pager-playlist"></div>
        </div>
    </div>

    <!-- 4. MANAJEMEN USER LOGIN -->
    <div class="card" id="section-users">
        <h2>4. Manajemen User Login (Administrator Backoffice)</h2>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="add_user">
            <div class="form-row" style="align-items:flex-end;">
                <div class="form-group">
                    <label>Nama Lengkap</label>
                    <input type="text" name="full_name" placeholder="Contoh: Budi Santoso" required>
                </div>
                <div class="form-group">
                    <label>Username Login</label>
                    <input type="text" name="username" placeholder="Contoh: budi_admin" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Minimal 4 karakter" minlength="4" required>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">+ Tambah User Login</button>
                </div>
            </div>
        </form>

        <!-- Toolbar Search & Limit User Login -->
        <div class="table-toolbar">
            <div class="toolbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="search-users" placeholder="Cari nama lengkap atau username...">
            </div>
            <div class="toolbar-filters">
                <select id="limit-users" title="Jumlah baris per halaman">
                    <option value="5">5 / hal</option>
                    <option value="10" selected>10 / hal</option>
                    <option value="25">25 / hal</option>
                    <option value="50">50 / hal</option>
                </select>
            </div>
        </div>

        <table id="table-users">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Lengkap</th>
                    <th>Username</th>
                    <th>Terdaftar Pada</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usersList as $u): ?>
                    <?php $isCurrentUser = ((int) $u['id'] === (int) ($_SESSION['admin_id'] ?? 0)); ?>
                    <tr class="data-row"
                        data-search="<?= htmlspecialchars(strtolower('#' . $u['id'] . ' ' . $u['full_name'] . ' ' . $u['username']), ENT_QUOTES, 'UTF-8') ?>"
                        data-filter="">
                        <td><strong>#<?= (int) $u['id'] ?></strong></td>
                        <td>
                            <strong><?= htmlspecialchars($u['full_name']) ?></strong>
                            <?php if ($isCurrentUser): ?>
                                <span class="badge badge-active" style="margin-left:6px;">Sedang Login</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                        <td style="color:var(--muted); font-size:0.82rem;"><?= htmlspecialchars($u['created_at'] ?? '-') ?></td>
                        <td>
                            <?php if ($isCurrentUser): ?>
                                <span style="font-size:0.8rem; color:var(--muted);">Akun Aktif</span>
                            <?php else: ?>
                                <form method="POST" onsubmit="return confirm('Hapus user login ini?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Hapus User</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination-bar">
            <div id="info-users">Menampilkan 0 data</div>
            <div class="pagination-controls" id="pager-users"></div>
        </div>
    </div>
</div>

<script>
    const mediaTypeSelect = document.getElementById('media_type');
    const mediaDurationInput = document.getElementById('media_duration');
    const durationHelper = document.getElementById('duration_helper');
    const detectedDurationField = document.getElementById('video_detected_duration');
    const mediaFileInput = document.getElementById('media_file');
    const submitBtn = document.getElementById('btn-submit-media');

    // Mengatur tampilan kolom Durasi ketika tipe diubah (image vs video)
    function handleMediaTypeChange() {
        const isVideo = mediaTypeSelect.value === 'video';
        if (isVideo) {
            mediaDurationInput.readOnly = true;
            durationHelper.style.display = 'block';
            if (mediaFileInput.files && mediaFileInput.files[0]) {
                detectVideoDurationFromFile(mediaFileInput.files[0]);
            } else {
                durationHelper.textContent = 'Pilih file video untuk deteksi otomatis';
            }
        } else {
            mediaDurationInput.readOnly = false;
            durationHelper.style.display = 'none';
            detectedDurationField.value = '0';
            if (!mediaDurationInput.value || parseInt(mediaDurationInput.value, 10) <= 0) {
                mediaDurationInput.value = '10';
            }
        }
    }

    // Ketika user memilih file, otomatis sesuaikan tipe & baca durasi jika video
    function handleFileSelect(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        const videoExts = ['mp4', 'webm', 'ogg'];
        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

        if (videoExts.includes(ext)) {
            mediaTypeSelect.value = 'video';
            if (mediaTypeSelect._refreshSearchable) mediaTypeSelect._refreshSearchable();
            handleMediaTypeChange();
            detectVideoDurationFromFile(file);
        } else if (imageExts.includes(ext)) {
            mediaTypeSelect.value = 'image';
            if (mediaTypeSelect._refreshSearchable) mediaTypeSelect._refreshSearchable();
            handleMediaTypeChange();
        }
    }

    // Perbarui link tombol Buka Player & JSON API di Navbar sesuai perangkat TV yang dipilih
    function updateNavbarPlayerLinks() {
        const navSelect = document.getElementById('navbar-device-select');
        const btnPlayer = document.getElementById('btn-navbar-player');
        const btnApi = document.getElementById('btn-navbar-api');
        if (!navSelect || !btnPlayer || !btnApi) return;

        const code = navSelect.value || 'TV-LOBBY-01';
        btnPlayer.href = 'player.php?code=' + encodeURIComponent(code);
        btnApi.href = 'api.php?code=' + encodeURIComponent(code);
        sessionStorage.setItem('ap_navbar_device_code', code);
    }

    // Membaca durasi asli file video secara otomatis menggunakan HTML5 Video Metadata
    function detectVideoDurationFromFile(file) {
        durationHelper.style.display = 'block';
        durationHelper.textContent = 'Mendeteksi durasi video...';
        submitBtn.disabled = true;

        const tempVideo = document.createElement('video');
        tempVideo.preload = 'metadata';

        const objectUrl = URL.createObjectURL(file);
        tempVideo.src = objectUrl;

        tempVideo.onloadedmetadata = function () {
            URL.revokeObjectURL(objectUrl);
            submitBtn.disabled = false;
            if (isFinite(tempVideo.duration) && tempVideo.duration > 0) {
                const seconds = Math.max(1, Math.ceil(tempVideo.duration));
                mediaDurationInput.value = seconds;
                detectedDurationField.value = seconds;
                durationHelper.textContent = `Otomatis mengikuti video (${seconds} detik)`;
            } else {
                durationHelper.textContent = 'Durasi otomatis mengikuti video saat diputar';
            }
        };

        tempVideo.onerror = function () {
            URL.revokeObjectURL(objectUrl);
            submitBtn.disabled = false;
            durationHelper.textContent = 'Durasi otomatis mengikuti video';
        };
    }

    // Reset field jadwal tayang ke 24 jam (kosongkan semua field tanggal & jam)
    function clearScheduleFields() {
        const fields = [
            'sched_start_date', 'sched_end_date', 'sched_start_time', 'sched_end_time',
            'sched-start-date', 'sched-end-date', 'sched-start-time', 'sched-end-time'
        ];
        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    }

    // Navigasi Sub-Tab di dalam Menu Konten (Menyimpan status tab aktif di sessionStorage)
    function switchTab(section, btn) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        if (btn) {
            btn.classList.add('active');
        } else {
            const matchingBtn = document.querySelector(`.tab-btn[onclick*="'${section}'"]`);
            if (matchingBtn) matchingBtn.classList.add('active');
        }

        sessionStorage.setItem('ap_active_tab', section);

        const secDevices = document.getElementById('section-devices');
        const secMedia = document.getElementById('section-media');
        const secPlaylist = document.getElementById('section-playlist');
        const secUsers = document.getElementById('section-users');
        const topGrid = document.getElementById('top-grid');

        secDevices.classList.remove('hidden-section');
        secMedia.classList.remove('hidden-section');
        secPlaylist.classList.remove('hidden-section');
        if (secUsers) secUsers.classList.remove('hidden-section');
        topGrid.style.gridTemplateColumns = '';

        if (section === 'media') {
            secDevices.classList.add('hidden-section');
            secPlaylist.classList.add('hidden-section');
            if (secUsers) secUsers.classList.add('hidden-section');
            topGrid.style.gridTemplateColumns = '1fr';
        } else if (section === 'playlist') {
            secDevices.classList.add('hidden-section');
            secMedia.classList.add('hidden-section');
            if (secUsers) secUsers.classList.add('hidden-section');
        } else if (section === 'devices') {
            secMedia.classList.add('hidden-section');
            secPlaylist.classList.add('hidden-section');
            if (secUsers) secUsers.classList.add('hidden-section');
            topGrid.style.gridTemplateColumns = '1fr';
        } else if (section === 'users') {
            secDevices.classList.add('hidden-section');
            secMedia.classList.add('hidden-section');
            secPlaylist.classList.add('hidden-section');
        }
    }

    /**
     * Engine Search, Filter, & Pagination untuk masing-masing tabel
     */
    function initDataTable(config) {
        const table = document.getElementById(config.tableId);
        const tbody = table.querySelector('tbody');
        const allRows = Array.from(tbody.querySelectorAll('tr.data-row'));
        const searchInput = document.getElementById(config.searchId);
        const filterSelect = document.getElementById(config.filterId);
        const limitSelect = document.getElementById(config.limitId);
        const infoBox = document.getElementById(config.infoId);
        const pagerBox = document.getElementById(config.pagerId);
        const colSpan = table.querySelectorAll('thead th').length;

        let currentPage = 1;

        // Buat baris pesan kosong jika belum ada
        const emptyRow = document.createElement('tr');
        emptyRow.className = 'empty-row';
        emptyRow.style.display = 'none';
        emptyRow.innerHTML = `<td colspan="${colSpan}">Tidak ada data yang cocok dengan pencarian.</td>`;
        tbody.appendChild(emptyRow);

        function render() {
            const query = (searchInput.value || '').trim().toLowerCase();
            const filterVal = filterSelect ? filterSelect.value : '';
            const pageSize = parseInt(limitSelect.value, 10) || 5;

            // 1. Filter baris berdasarkan kata kunci pencarian & dropdown filter
            const matchedRows = allRows.filter(row => {
                const text = row.getAttribute('data-search') || '';
                const rowFilter = row.getAttribute('data-filter') || '';
                const matchSearch = query === '' || text.includes(query);
                const matchFilter = filterVal === '' || rowFilter === filterVal;
                return matchSearch && matchFilter;
            });

            const totalItems = matchedRows.length;
            const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIndex = (currentPage - 1) * pageSize;
            const endIndex = Math.min(startIndex + pageSize, totalItems);

            // 2. Sembunyikan semua baris lalu tampilkan hanya baris di halaman aktif
            allRows.forEach(r => r.style.display = 'none');

            if (totalItems === 0) {
                emptyRow.style.display = '';
                infoBox.textContent = 'Menampilkan 0 dari ' + allRows.length + ' total data';
            } else {
                emptyRow.style.display = 'none';
                for (let i = startIndex; i < endIndex; i++) {
                    matchedRows[i].style.display = '';
                }
                infoBox.textContent = `Menampilkan ${startIndex + 1}–${endIndex} dari ${totalItems} data` +
                    (totalItems < allRows.length ? ` (difilter dari ${allRows.length})` : '');
            }

            // 3. Bangun tombol-tombol Pagination
            pagerBox.innerHTML = '';

            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'page-btn';
            prevBtn.textContent = '‹ Prev';
            prevBtn.disabled = currentPage <= 1;
            prevBtn.onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    render();
                }
            };
            pagerBox.appendChild(prevBtn);

            // Tampilkan maksimal 5 nomor halaman di sekitar currentPage
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }

            for (let p = startPage; p <= endPage; p++) {
                const pageBtn = document.createElement('button');
                pageBtn.type = 'button';
                pageBtn.className = 'page-btn' + (p === currentPage ? ' active' : '');
                pageBtn.textContent = String(p);
                pageBtn.onclick = () => {
                    currentPage = p;
                    render();
                };
                pagerBox.appendChild(pageBtn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'page-btn';
            nextBtn.textContent = 'Next ›';
            nextBtn.disabled = currentPage >= totalPages;
            nextBtn.onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    render();
                }
            };
            pagerBox.appendChild(nextBtn);
        }

        searchInput.addEventListener('input', () => {
            currentPage = 1;
            render();
        });
        if (filterSelect) {
            filterSelect.addEventListener('change', () => {
                currentPage = 1;
                render();
            });
        }
        limitSelect.addEventListener('change', () => {
            currentPage = 1;
            render();
        });

        render();
    }

    /**
     * Engine Dropdown Search (Mengubah semua elemen <select> menjadi Searchable Dropdown)
     */
    function initSearchableDropdowns() {
        const allSelects = document.querySelectorAll('select');

        allSelects.forEach(select => {
            if (select.dataset.searchableInit === '1') return;
            select.dataset.searchableInit = '1';

            // Sembunyikan <select> asli secara aksesibel agar form POST & validasi tetap berfungsi
            select.classList.add('sselect-native-hidden');

            const wrapper = document.createElement('div');
            wrapper.className = 'sselect-wrapper';
            if (select.id && select.id.startsWith('limit-')) {
                wrapper.classList.add('sselect-limit');
            }

            // Tombol Trigger Dropdown
            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'sselect-trigger';
            if (select.title) trigger.title = select.title;

            const labelSpan = document.createElement('span');
            labelSpan.className = 'sselect-label';

            const arrowSpan = document.createElement('span');
            arrowSpan.className = 'sselect-arrow';
            arrowSpan.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';

            trigger.appendChild(labelSpan);
            trigger.appendChild(arrowSpan);

            // Panel Popover Menu + Input Search
            const menu = document.createElement('div');
            menu.className = 'sselect-menu';

            const searchWrap = document.createElement('div');
            searchWrap.className = 'sselect-search-wrap';
            searchWrap.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';

            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'sselect-search-input';
            searchInput.placeholder = 'Cari pilihan...';
            searchInput.autocomplete = 'off';
            searchWrap.appendChild(searchInput);

            const optionsList = document.createElement('div');
            optionsList.className = 'sselect-options';

            const emptyMsg = document.createElement('div');
            emptyMsg.className = 'sselect-empty';
            emptyMsg.textContent = 'Pilihan tidak ditemukan';

            menu.appendChild(searchWrap);
            menu.appendChild(optionsList);
            menu.appendChild(emptyMsg);

            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            wrapper.appendChild(trigger);
            wrapper.appendChild(menu);

            function updateTriggerLabel() {
                const selectedOpt = select.options[select.selectedIndex];
                labelSpan.textContent = selectedOpt ? selectedOpt.text.trim() : 'Pilih...';
            }

            function buildOptionItems(filterQuery = '') {
                optionsList.innerHTML = '';
                const q = filterQuery.trim().toLowerCase();
                let visibleCount = 0;

                Array.from(select.options).forEach((opt, idx) => {
                    const text = opt.text.trim();
                    if (q !== '' && !text.toLowerCase().includes(q) && !String(opt.value).toLowerCase().includes(q)) {
                        return;
                    }
                    visibleCount++;

                    const item = document.createElement('div');
                    item.className = 'sselect-option' + (idx === select.selectedIndex ? ' selected' : '');
                    item.textContent = text;
                    item.dataset.index = String(idx);

                    item.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const prevIndex = select.selectedIndex;
                        select.selectedIndex = idx;
                        updateTriggerLabel();
                        closeMenu();

                        if (prevIndex !== idx) {
                            // Picu event change asli (untuk filter tabel maupun inline onchange="...")
                            select.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });

                    optionsList.appendChild(item);
                });

                emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
            }

            function openMenu() {
                // Tutup semua dropdown lain yang sedang terbuka
                document.querySelectorAll('.sselect-wrapper.open').forEach(other => {
                    if (other !== wrapper) other.classList.remove('open');
                });
                wrapper.classList.add('open');
                searchInput.value = '';
                buildOptionItems('');
                setTimeout(() => searchInput.focus(), 15);
            }

            function closeMenu() {
                wrapper.classList.remove('open');
            }

            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                if (wrapper.classList.contains('open')) {
                    closeMenu();
                } else {
                    openMenu();
                }
            });

            searchInput.addEventListener('click', (e) => e.stopPropagation());

            searchInput.addEventListener('input', () => {
                buildOptionItems(searchInput.value);
            });

            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeMenu();
                    trigger.focus();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const firstVisible = optionsList.querySelector('.sselect-option');
                    if (firstVisible) {
                        firstVisible.click();
                    }
                }
            });

            select._refreshSearchable = () => {
                updateTriggerLabel();
                buildOptionItems(searchInput.value);
            };

            updateTriggerLabel();
        });

        // Klik di luar dropdown akan menutup panel dropdown yang terbuka
        document.addEventListener('click', () => {
            document.querySelectorAll('.sselect-wrapper.open').forEach(w => w.classList.remove('open'));
        });
    }

    // Inisialisasi Search & Paging untuk ke-4 Tabel serta Searchable Dropdown
    document.addEventListener('DOMContentLoaded', () => {
        // Pulihkan pilihan terakhir Device TV di Navbar jika ada
        const navSelect = document.getElementById('navbar-device-select');
        const savedNavCode = sessionStorage.getItem('ap_navbar_device_code');
        if (navSelect && savedNavCode) {
            const exists = Array.from(navSelect.options).some(o => o.value === savedNavCode);
            if (exists) {
                navSelect.value = savedNavCode;
            }
        }
        updateNavbarPlayerLinks();

        // Ubah seluruh <select> menjadi Dropdown Search
        initSearchableDropdowns();

        initDataTable({
            tableId: 'table-devices',
            searchId: 'search-devices',
            filterId: 'filter-devices',
            limitId: 'limit-devices',
            infoId: 'info-devices',
            pagerId: 'pager-devices'
        });

        initDataTable({
            tableId: 'table-media',
            searchId: 'search-media',
            filterId: 'filter-media',
            limitId: 'limit-media',
            infoId: 'info-media',
            pagerId: 'pager-media'
        });

        initDataTable({
            tableId: 'table-playlist',
            searchId: 'search-playlist',
            filterId: 'filter-playlist',
            limitId: 'limit-playlist',
            infoId: 'info-playlist',
            pagerId: 'pager-playlist'
        });

        initDataTable({
            tableId: 'table-users',
            searchId: 'search-users',
            filterId: null,
            limitId: 'limit-users',
            infoId: 'info-users',
            pagerId: 'pager-users'
        });

        // Pulihkan tab terakhir yang sedang dibuka user sebelum reload
        const savedTab = sessionStorage.getItem('ap_active_tab');
        if (savedTab && ['all', 'media', 'playlist', 'devices', 'users'].includes(savedTab)) {
            switchTab(savedTab, null);
        }
    });
</script>
</body>
</html>
