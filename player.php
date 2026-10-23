<?php
declare(strict_types=1);
$deviceCode = isset($_GET['code']) ? htmlspecialchars(trim((string) $_GET['code']), ENT_QUOTES, 'UTF-8') : 'TV-LOBBY-01';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Signage Player - <?= $deviceCode ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html, body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background-color: #090d16;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #f8fafc;
            user-select: none;
        }
        #stage {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
        }
        .slide-media {
            width: 100%;
            height: 100%;
            object-fit: contain;
            opacity: 0;
            transform: translate3d(12%, 0, 0);
            will-change: transform, opacity;
            transition: transform 0.48s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.48s ease-out;
        }
        .slide-media.visible {
            opacity: 1;
            transform: translate3d(0, 0, 0);
        }
        /* Progress bar di bagian bawah layar */
        #progress-container {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: rgba(255, 255, 255, 0.12);
            z-index: 20;
        }
        #progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #38bdf8, #2dd4bf);
            transition: width 0.1s linear;
        }
        /* HUD Info (otomatis sembunyi jika kursor tidak bergerak) */
        #hud {
            position: fixed;
            top: 20px;
            left: 20px;
            right: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            pointer-events: none;
            z-index: 30;
            opacity: 1;
            transition: opacity 0.4s ease;
        }
        body.idle #hud {
            opacity: 0;
        }
        body.idle {
            cursor: none;
        }
        .hud-badge {
            pointer-events: auto;
            background: rgba(15, 23, 42, 0.82);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 10px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 10px #22c55e;
        }
        .status-dot.error {
            background: #ef4444;
            box-shadow: 0 0 10px #ef4444;
        }
        .hud-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .hud-btn:hover {
            background: #1d4ed8;
        }
        #status-screen {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 32px;
            background: radial-gradient(circle at center, #1e293b 0%, #090d16 100%);
            z-index: 10;
        }
        #status-screen.hidden {
            display: none;
        }
        #status-screen h1 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            color: #f8fafc;
        }
        #status-screen p {
            font-size: 1.1rem;
            color: #94a3b8;
            max-width: 520px;
        }
        .code-pill {
            margin-top: 16px;
            padding: 8px 16px;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            border-radius: 999px;
            font-family: monospace;
            font-size: 1rem;
        }
    </style>
</head>
<body>

    <div id="hud">
        <div class="hud-badge">
            <span id="status-dot" class="status-dot"></span>
            <div>
                <strong id="hud-device-name"><?= $deviceCode ?></strong>
                <span style="color:#94a3b8; margin-left:6px;" id="hud-slide-info">Memuat playlist...</span>
            </div>
        </div>
        <div class="hud-badge">
            <a href="index.php" class="hud-btn" style="background:#334155;">Menu Konten</a>
            <button class="hud-btn" id="btn-rotate" style="background:#0284c7;" onclick="cycleRotation()">Rotasi: 0° (Landscape)</button>
            <button class="hud-btn" id="btn-scale" style="background:#0d9488;" onclick="cycleScale()">Skala: Fit</button>
            <button class="hud-btn" onclick="toggleFullscreen()">Layar Penuh (F11)</button>
        </div>
    </div>

    <div id="stage">
        <div id="status-screen">
            <h1 id="status-title">Menghubungkan ke Server...</h1>
            <p id="status-message">Mengambil daftar konten untuk perangkat ini.</p>
            <div class="code-pill">CODE: <?= $deviceCode ?></div>
        </div>
    </div>

    <div id="progress-container">
        <div id="progress-bar"></div>
    </div>

    <script>
        const DEVICE_CODE = <?= json_encode($deviceCode) ?>;
        const API_URL = `api.php?code=${encodeURIComponent(DEVICE_CODE)}`;
        const SYNC_INTERVAL_MS = 30000; // Sinkronisasi playlist & heartbeat tiap 30 detik

        let playlist = [];
        let currentIndex = 0;
        let slideTimer = null;
        let progressInterval = null;
        let isPlaying = false;
        let currentPlaylistHash = '';
        let rotationDeg = parseInt(localStorage.getItem('ap_rotation_' + DEVICE_CODE) || '0', 10) || 0;
        let scaleMode = localStorage.getItem('ap_scale_' + DEVICE_CODE) || 'contain';

        const stage = document.getElementById('stage');
        const statusScreen = document.getElementById('status-screen');
        const statusTitle = document.getElementById('status-title');
        const statusMessage = document.getElementById('status-message');
        const statusDot = document.getElementById('status-dot');
        const hudDeviceName = document.getElementById('hud-device-name');
        const hudSlideInfo = document.getElementById('hud-slide-info');
        const progressBar = document.getElementById('progress-bar');
        const btnRotate = document.getElementById('btn-rotate');
        const btnScale = document.getElementById('btn-scale');

        // Auto-hide HUD ketika mouse idle 3 detik
        let idleTimeout;
        function resetIdleTimer() {
            document.body.classList.remove('idle');
            clearTimeout(idleTimeout);
            idleTimeout = setTimeout(() => document.body.classList.add('idle'), 3000);
        }
        window.addEventListener('mousemove', resetIdleTimer);
        window.addEventListener('keydown', (e) => {
            resetIdleTimer();
            if (e.key === 'r' || e.key === 'R') cycleRotation();
        });
        window.addEventListener('resize', applyStageRotation);
        resetIdleTimer();

        function cycleRotation() {
            rotationDeg = (rotationDeg + 90) % 360;
            localStorage.setItem('ap_rotation_' + DEVICE_CODE, String(rotationDeg));
            applyStageRotation();
        }

        function cycleScale() {
            scaleMode = scaleMode === 'contain' ? 'fill' : (scaleMode === 'fill' ? 'cover' : 'contain');
            localStorage.setItem('ap_scale_' + DEVICE_CODE, scaleMode);
            applyStageRotation();
        }

        function applyStageRotation() {
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            if (rotationDeg === 90 || rotationDeg === 270) {
                stage.style.width = vh + 'px';
                stage.style.height = vw + 'px';
                stage.style.position = 'absolute';
                stage.style.top = '50%';
                stage.style.left = '50%';
                stage.style.transform = `translate(-50%, -50%) rotate(${rotationDeg}deg)`;
            } else {
                stage.style.width = '100vw';
                stage.style.height = '100vh';
                stage.style.position = 'relative';
                stage.style.top = '0';
                stage.style.left = '0';
                stage.style.transform = `rotate(${rotationDeg}deg)`;
            }

            const orientLabel = (rotationDeg === 0 || rotationDeg === 180) ? 'Landscape' : 'Portrait';
            btnRotate.textContent = `Rotasi: ${rotationDeg}° (${orientLabel})`;

            const scaleLabel = scaleMode === 'fill' ? 'Fill (Penuh)' : (scaleMode === 'cover' ? 'Zoom' : 'Fit');
            btnScale.textContent = `Skala: ${scaleLabel}`;
            stage.querySelectorAll('.slide-media').forEach(el => {
                el.style.objectFit = scaleMode;
            });
        }
        applyStageRotation();

        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
            }
        }

        // Saat koneksi internet kembali terhubung (online), langsung tarik pembaruan dari server
        window.addEventListener('online', () => {
            fetchPlaylist();
        });

        // Evaluasi apakah item playlist sedang berada dalam jadwal tayang (Schedule Play)
        function isItemScheduleActive(item) {
            if (!item || !item.schedule) return true;
            const s = item.schedule;
            const now = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            const curDate = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
            const curTime = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;

            if (s.start_date && curDate < s.start_date) return false;
            if (s.end_date && curDate > s.end_date) return false;

            if (s.start_time && s.end_time) {
                if (s.start_time <= s.end_time) {
                    if (curTime < s.start_time || curTime > s.end_time) return false;
                } else {
                    if (curTime < s.start_time && curTime > s.end_time) return false;
                }
            } else if (s.start_time) {
                if (curTime < s.start_time) return false;
            } else if (s.end_time) {
                if (curTime > s.end_time) return false;
            }
            return true;
        }

        async function fetchPlaylist() {
            try {
                const res = await fetch(API_URL, { cache: 'no-store' });
                const data = await res.json();

                if (!res.ok || data.status !== 'success') {
                    showStatusScreen(
                        res.status === 403 ? 'Perangkat Nonaktif / Maintenance' : 'Perangkat Tidak Ditemukan',
                        data.message || 'Gagal memuat playlist dari server.',
                        true
                    );
                    playlist = [];
                    isPlaying = false;
                    clearTimers();
                    return;
                }

                statusDot.classList.remove('error');
                hudDeviceName.textContent = `${data.device.device_name} (${data.device.device_code})`;

                const allItems = Array.isArray(data.playlist) ? data.playlist : [];

                if (allItems.length === 0) {
                    playlist = [];
                    isPlaying = false;
                    localStorage.removeItem('ap_cached_playlist_' + DEVICE_CODE);
                    clearTimers();
                    showStatusScreen('Playlist Kosong', 'Belum ada konten media yang dijadwalkan untuk TV ini.', false);
                    hudSlideInfo.textContent = '0 konten';
                    return;
                }

                // Simpan daftar playlist lengkap ke localStorage untuk cadangan saat offline
                localStorage.setItem('ap_cached_playlist_' + DEVICE_CODE, JSON.stringify(allItems));

                // Filter hanya item yang jadwal tayangnya sedang aktif saat ini
                const activeItems = allItems.filter(isItemScheduleActive);
                const newHash = JSON.stringify(activeItems.map(i => `${i.playlist_id}:${i.media_id}:${i.sequence_order}:${i.duration}:${i.file_url}`));

                if (activeItems.length === 0) {
                    playlist = [];
                    currentPlaylistHash = '';
                    isPlaying = false;
                    clearTimers();
                    showStatusScreen(
                        'Di Luar Jam Tayang (Standby)',
                        `Terdapat ${allItems.length} konten terjadwal untuk TV ini, namun saat ini berada di luar jam/tanggal tayang aktif.`,
                        false
                    );
                    hudSlideInfo.textContent = `Standby (${allItems.length} terjadwal)`;
                    return;
                }

                statusScreen.classList.add('hidden');

                // Jika isi playlist aktif berubah dari sebelumnya, langsung perbarui tayangan!
                if (newHash !== currentPlaylistHash) {
                    currentPlaylistHash = newHash;
                    playlist = activeItems;
                    currentIndex = 0;
                    isPlaying = true;
                    playCurrentSlide();
                } else if (!isPlaying) {
                    isPlaying = true;
                    playCurrentSlide();
                }
            } catch (err) {
                statusDot.classList.add('error');
                // Jika koneksi terputus dan playlist belum jalan, muat dari cache localStorage & evaluasi jadwal
                if (playlist.length === 0) {
                    const cachedJson = localStorage.getItem('ap_cached_playlist_' + DEVICE_CODE);
                    if (cachedJson) {
                        try {
                            const cachedItems = JSON.parse(cachedJson);
                            if (Array.isArray(cachedItems) && cachedItems.length > 0) {
                                const activeCached = cachedItems.filter(isItemScheduleActive);
                                if (activeCached.length > 0) {
                                    statusScreen.classList.add('hidden');
                                    currentPlaylistHash = JSON.stringify(activeCached.map(i => `${i.playlist_id}:${i.media_id}:${i.sequence_order}:${i.duration}:${i.file_url}`));
                                    playlist = activeCached;
                                    currentIndex = 0;
                                    isPlaying = true;
                                    playCurrentSlide();
                                    return;
                                } else {
                                    showStatusScreen(
                                        'Di Luar Jam Tayang (Mode Offline)',
                                        `Tersimpan ${cachedItems.length} konten di cache, menunggu jam tayang aktif berikutnya...`,
                                        false
                                    );
                                    return;
                                }
                            }
                        } catch (_) {}
                    }
                    showStatusScreen('Koneksi Terputus', 'Menunggu koneksi ke server API Digital Signage...', true);
                }
            }
        }

        function showStatusScreen(title, message, isError) {
            // Bersihkan elemen media yang sedang tampil
            stage.querySelectorAll('.slide-media').forEach(el => el.remove());
            statusScreen.classList.remove('hidden');
            statusTitle.textContent = title;
            statusMessage.textContent = message;
            progressBar.style.width = '0%';
            if (isError) {
                statusDot.classList.add('error');
            }
        }

        function clearTimers() {
            if (slideTimer) clearTimeout(slideTimer);
            if (progressInterval) clearInterval(progressInterval);
        }

        function startProgressBar(durationSeconds) {
            clearInterval(progressInterval);
            const totalMs = durationSeconds * 1000;
            const startTime = Date.now();
            progressBar.style.width = '0%';

            progressInterval = setInterval(() => {
                const elapsed = Date.now() - startTime;
                const pct = Math.min(100, (elapsed / totalMs) * 100);
                progressBar.style.width = `${pct}%`;
                if (pct >= 100) clearInterval(progressInterval);
            }, 80);
        }

        function playCurrentSlide() {
            clearTimers();
            if (playlist.length === 0) return;

            const item = playlist[currentIndex];
            const durationSec = Math.max(1, parseInt(item.duration, 10) || 10);

            hudSlideInfo.textContent = `[${currentIndex + 1}/${playlist.length}] ${item.title} (${durationSec}s)`;

            // Hapus slide lama
            stage.querySelectorAll('.slide-media').forEach(el => el.remove());

            if (item.type === 'video') {
                const video = document.createElement('video');
                video.src = item.file_url;
                video.className = 'slide-media';
                video.style.objectFit = scaleMode;
                video.autoplay = true;
                video.muted = true; // Wajib muted agar browser mengizinkan autoplay tanpa interaksi
                video.playsInline = true;

                video.onloadedmetadata = () => {
                    const realDuration = (isFinite(video.duration) && video.duration > 0)
                        ? Math.ceil(video.duration)
                        : durationSec;
                    hudSlideInfo.textContent = `[${currentIndex + 1}/${playlist.length}] ${item.title} (${realDuration}s • Video)`;
                    startProgressBar(video.duration && isFinite(video.duration) ? video.duration : realDuration);
                };
                video.onloadeddata = () => video.classList.add('visible');
                video.onended = () => nextSlide();
                video.onerror = () => nextSlide();

                stage.appendChild(video);
            } else {
                const img = document.createElement('img');
                img.src = item.file_url;
                img.alt = item.title;
                img.className = 'slide-media';
                img.style.objectFit = scaleMode;

                img.onload = () => img.classList.add('visible');
                img.onerror = () => nextSlide();

                stage.appendChild(img);
                startProgressBar(durationSec);
                slideTimer = setTimeout(nextSlide, durationSec * 1000);
            }
        }

        function nextSlide() {
            if (playlist.length === 0) return;
            currentIndex = (currentIndex + 1) % playlist.length;
            playCurrentSlide();
        }

        // Jalankan pertama kali & jadwalkan sinkronisasi berkala
        fetchPlaylist();
        setInterval(fetchPlaylist, SYNC_INTERVAL_MS);
    </script>
</body>
</html>
