<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/db.php';

// Hanya izinkan method GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Method Not Allowed. Gunakan HTTP GET.'
    ]);
    exit;
}

// Ambil dan validasi parameter code
$deviceCode = isset($_GET['code']) ? trim((string) $_GET['code']) : '';

if ($deviceCode === '') {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Parameter "code" (device_code) wajib disertakan.'
    ]);
    exit;
}

try {
    $db = (new Database())->getConnection();

    // 1. Cari device berdasarkan device_code
    $stmtDevice = $db->prepare("
        SELECT id, device_name, device_code, status, last_seen
        FROM devices
        WHERE device_code = :code
        LIMIT 1
    ");
    $stmtDevice->execute([':code' => $deviceCode]);
    $device = $stmtDevice->fetch();

    if (!$device) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Device tidak ditemukan.'
        ]);
        exit;
    }

    // 2. Update heartbeat (last_seen) setiap kali TV melakukan request ke API
    $stmtHeartbeat = $db->prepare("
        UPDATE devices
        SET last_seen = NOW()
        WHERE id = :id
    ");
    $stmtHeartbeat->execute([':id' => $device['id']]);

    // Cek apakah status device aktif
    if ($device['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => "Device sedang dalam status '{$device['status']}'."
        ]);
        exit;
    }

    // 3. Ambil daftar playlist media untuk device tersebut (semua jadwal yang belum kedaluwarsa tanggalnya)
    //    agar Android TV dapat melakukan Pre-Cache file media untuk jadwal yang akan datang sekalipun.
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');

    $stmtPlaylist = $db->prepare("
        SELECT 
            p.id AS playlist_id,
            p.sequence_order,
            p.start_date,
            p.end_date,
            p.start_time,
            p.end_time,
            m.id AS media_id,
            m.title,
            m.file_path,
            m.type,
            m.duration
        FROM playlists p
        INNER JOIN media m ON p.media_id = m.id
        WHERE p.device_id = :device_id
          AND (p.end_date IS NULL OR p.end_date >= :cur_date)
        ORDER BY p.sequence_order ASC, p.id ASC
    ");
    $stmtPlaylist->execute([
        ':device_id' => $device['id'],
        ':cur_date'  => $currentDate,
    ]);
    $items = $stmtPlaylist->fetchAll();

    // Bangun Base URL dinamis (mendukung Reverse Proxy Traefik/Coolify via X-Forwarded-Proto)
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($forwardedProto) === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseDir = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');
    $baseUrl = "{$scheme}://{$host}{$baseDir}";

    $activeCount = 0;
    $formattedPlaylist = array_map(static function (array $row) use ($baseUrl, $currentDate, $currentTime, &$activeCount): array {
        $startDate = $row['start_date'];
        $endDate   = $row['end_date'];
        $startTime = $row['start_time'];
        $endTime   = $row['end_time'];

        // Evaluasi apakah item ini aktif pada tanggal & jam saat ini
        $dateActive = ($startDate === null || $currentDate >= $startDate)
                   && ($endDate === null || $currentDate <= $endDate);

        $timeActive = true;
        if ($startTime !== null && $endTime !== null) {
            if ($startTime <= $endTime) {
                $timeActive = ($currentTime >= $startTime && $currentTime <= $endTime);
            } else {
                // Jadwal lintas tengah malam (contoh: 22:00:00 s/d 02:00:00)
                $timeActive = ($currentTime >= $startTime || $currentTime <= $endTime);
            }
        } elseif ($startTime !== null) {
            $timeActive = ($currentTime >= $startTime);
        } elseif ($endTime !== null) {
            $timeActive = ($currentTime <= $endTime);
        }

        $isActiveNow = $dateActive && $timeActive;
        if ($isActiveNow) {
            $activeCount++;
        }

        return [
            'playlist_id'    => (int) $row['playlist_id'],
            'sequence_order' => (int) $row['sequence_order'],
            'media_id'       => (int) $row['media_id'],
            'title'          => $row['title'],
            'type'           => $row['type'],
            'duration'       => (int) $row['duration'],
            'file_path'      => $row['file_path'],
            'file_url'       => $baseUrl . '/' . ltrim($row['file_path'], '/'),
            'schedule'       => [
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'start_time'    => $startTime,
                'end_time'      => $endTime,
                'is_active_now' => $isActiveNow,
            ],
        ];
    }, $items);

    // 4. Kembalikan response JSON beserta waktu server (untuk sinkronisasi jam Android TV)
    http_response_code(200);
    echo json_encode([
        'status'           => 'success',
        'server_time'      => date('Y-m-d H:i:s'),
        'server_timestamp' => time() * 1000,
        'device'           => [
            'id'          => (int) $device['id'],
            'device_name' => $device['device_name'],
            'device_code' => $device['device_code'],
            'status'      => $device['status'],
            'last_seen'   => date('Y-m-d H:i:s'),
        ],
        'total_items'  => count($formattedPlaylist),
        'active_items' => $activeCount,
        'playlist'     => $formattedPlaylist,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    error_log('API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Terjadi kesalahan pada server.'
    ]);
}
