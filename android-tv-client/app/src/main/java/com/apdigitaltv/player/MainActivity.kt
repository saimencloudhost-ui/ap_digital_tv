package com.apdigitaltv.player

import android.app.AlertDialog
import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.Uri
import android.os.Bundle
import android.view.KeyEvent
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.view.animation.DecelerateInterpolator
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.AspectRatioFrameLayout
import androidx.media3.ui.PlayerView
import coil.ImageLoader
import coil.decode.SvgDecoder
import coil.load
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

data class PlaylistItem(
    val playlistId: Int,
    val sequenceOrder: Int,
    val mediaId: Int,
    val title: String,
    val type: String, // "image" atau "video"
    val duration: Int,
    val fileUrl: String,
    val localFile: File,
    val startDate: String? = null, // Format: "yyyy-MM-dd" atau null (Setiap Hari)
    val endDate: String? = null,   // Format: "yyyy-MM-dd" atau null (Tanpa Batas)
    val startTime: String? = null, // Format: "HH:mm:ss" atau null (24 Jam)
    val endTime: String? = null    // Format: "HH:mm:ss" atau null (24 Jam)
)

@UnstableApi
class MainActivity : AppCompatActivity() {

    private lateinit var rootLayout: FrameLayout
    private lateinit var rotatableStage: FrameLayout
    private lateinit var imageSlideView: ImageView
    private lateinit var videoPlayerView: PlayerView
    private lateinit var statusContainer: LinearLayout
    private lateinit var tvStatusTitle: TextView
    private lateinit var tvStatusMessage: TextView
    private lateinit var tvDeviceBadge: TextView
    private lateinit var btnOpenSettings: Button

    // HUD Kontrol Cepat Rotasi & Skala
    private lateinit var hudOverlay: LinearLayout
    private lateinit var btnQuickRotate: Button
    private lateinit var btnQuickScale: Button
    private lateinit var btnHudSettings: Button

    private var exoPlayer: ExoPlayer? = null
    private lateinit var imageLoader: ImageLoader

    // Seluruh daftar playlist yang sudah di-precache di memori TV (termasuk yang jadwalnya baru mulai nanti)
    private var allCachedPlaylist: List<PlaylistItem> = emptyList()

    // Daftar playlist yang jadwalnya SEDANG AKTIF pada jam & tanggal saat ini
    private var playlist: List<PlaylistItem> = emptyList()
    private var currentPlaylistSignature: String = ""
    private var currentIndex: Int = 0
    private var isPlaying: Boolean = false
    private var isOfflineMode: Boolean = false

    private var slideJob: Job? = null
    private var syncJob: Job? = null
    private var scheduleWatchdogJob: Job? = null
    private var hudHideJob: Job? = null
    private val syncMutex = Mutex()

    private var connectivityManager: ConnectivityManager? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private val slideInterpolator = DecelerateInterpolator(1.6f)

    companion object {
        private const val PREFS_NAME = "ap_digital_tv_prefs"
        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_DEVICE_CODE = "device_code"
        private const val KEY_ROTATION = "screen_rotation" // 0, 90, 180, 270
        private const val KEY_SCALE_MODE = "scale_mode"    // "fit", "fill", "zoom"
        private const val KEY_SERVER_TIME_OFFSET = "server_time_offset_ms"
        private const val DEFAULT_SERVER_URL = "http://192.168.0.37:81/ApDigitalTV"
        private const val DEFAULT_DEVICE_CODE = "TV-LOBBY-01"
        private const val SYNC_INTERVAL_MS = 15_000L // Cek perubahan konten ke server tiap 15 detik
        private const val SCHEDULE_CHECK_INTERVAL_MS = 5_000L // Evaluasi jadwal tayang lokal tiap 5 detik
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Jaga layar TV tetap menyala (tidak masuk mode sleep/screensaver)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContentView(R.layout.activity_main)
        enableImmersiveFullscreen()

        // Inisialisasi UI
        rootLayout = findViewById(R.id.rootLayout)
        rotatableStage = findViewById(R.id.rotatableStage)
        imageSlideView = findViewById(R.id.imageSlideView)
        videoPlayerView = findViewById(R.id.videoPlayerView)
        statusContainer = findViewById(R.id.statusContainer)
        tvStatusTitle = findViewById(R.id.tvStatusTitle)
        tvStatusMessage = findViewById(R.id.tvStatusMessage)
        tvDeviceBadge = findViewById(R.id.tvDeviceBadge)
        btnOpenSettings = findViewById(R.id.btnOpenSettings)

        hudOverlay = findViewById(R.id.hudOverlay)
        btnQuickRotate = findViewById(R.id.btnQuickRotate)
        btnQuickScale = findViewById(R.id.btnQuickScale)
        btnHudSettings = findViewById(R.id.btnHudSettings)

        // ImageLoader dengan dukungan SVG & Gambar Resolusi Tinggi
        imageLoader = ImageLoader.Builder(this)
            .components { add(SvgDecoder.Factory()) }
            .build()

        // Inisialisasi ExoPlayer untuk pemutaran video
        exoPlayer = ExoPlayer.Builder(this).build().also { player ->
            videoPlayerView.player = player
            player.addListener(object : Player.Listener {
                override fun onRenderedFirstFrame() {
                    // Jalankan animasi Slide-In tepat saat frame pertama video siap tampil
                    animateSlideTransition(videoPlayerView)
                }

                override fun onPlaybackStateChanged(playbackState: Int) {
                    if (playbackState == Player.STATE_ENDED) {
                        // Otomatis pindah ke urutan berikutnya begitu video selesai diputar
                        nextSlide()
                    }
                }

                override fun onPlayerError(error: PlaybackException) {
                    // Jika file video gagal diputar, lompati ke slide berikutnya agar TV tidak macet
                    nextSlide()
                }
            })
        }

        // Klik di mana saja pada layar akan memunculkan panel kontrol cepat (Rotasi, Skala, Pengaturan)
        rootLayout.setOnClickListener {
            showQuickHudTemporarily()
        }

        btnOpenSettings.setOnClickListener {
            showSettingsDialog()
        }

        btnHudSettings.setOnClickListener {
            showSettingsDialog()
        }

        // Tombol Putar Rotasi Manual (0° -> 90° -> 180° -> 270°)
        btnQuickRotate.setOnClickListener {
            cycleRotation()
            showQuickHudTemporarily()
        }

        // Tombol Ubah Mode Skala (Fit -> Fill Fullscreen -> Zoom)
        btnQuickScale.setOnClickListener {
            cycleScaleMode()
            showQuickHudTemporarily()
        }

        // Terapkan rotasi & skala tersimpan begitu ukuran layar siap
        rootLayout.post {
            applyRotationAndScale()
        }
        rootLayout.addOnLayoutChangeListener { _, _, _, _, _, _, _, _, _ ->
            rootLayout.post { applyRotationAndScale() }
        }

        updateBadgeDisplay()

        // 1. LANGKAH PERTAMA: Muat playlist dari Cache Lokal terlebih dahulu!
        //    Dengan ini, meskipun TV baru dinyalakan saat internet/server mati,
        //    konten yang sudah ter-cache langsung berputar sesuai jadwal tayangnya.
        lifecycleScope.launch {
            loadPlaylistFromLocalCache()
        }

        // 2. Daftarkan pendeteksi koneksi jaringan (Auto-Reconnect Listener)
        //    Begitu Wi-Fi / LAN kembali terhubung, aplikasi langsung tarik update dari server!
        registerNetworkReconnectMonitor()

        // Jika belum pernah dikonfigurasi sama sekali, tampilkan dialog pengaturan saat pertama buka
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        if (!prefs.contains(KEY_SERVER_URL) || !prefs.contains(KEY_DEVICE_CODE)) {
            showSettingsDialog()
        }

        // 3. Jalankan loop sinkronisasi server & watchdog evaluasi jadwal tayang (Schedule Play)
        startPeriodicSync()
        startScheduleWatchdog()
    }

    /**
     * Memantau status jaringan Android TV secara real-time.
     * Ketika jaringan terputus lalu kembali terkoneksi (onAvailable),
     * langsung memicu syncPlaylistFromServer() seketika tanpa menunggu timer!
     */
    private fun registerNetworkReconnectMonitor() {
        connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                super.onAvailable(network)
                // Koneksi kembali aktif! Langsung sinkronkan konten terbaru dari server
                lifecycleScope.launch {
                    if (isOfflineMode) {
                        withContext(Dispatchers.Main) {
                            Toast.makeText(
                                this@MainActivity,
                                "Koneksi terhubung kembali. Memperbarui konten...",
                                Toast.LENGTH_SHORT
                            ).show()
                        }
                    }
                    syncPlaylistFromServer(forceImmediateUpdate = true)
                }
            }

            override fun onLost(network: Network) {
                super.onLost(network)
                isOfflineMode = true
            }
        }

        networkCallback = callback
        try {
            connectivityManager?.registerNetworkCallback(request, callback)
        } catch (_: Exception) {
        }
    }

    /**
     * Pintasan Remote TV:
     * - Tombol OK / Enter / Menu: Munculkan panel kontrol cepat (Rotasi / Skala / Pengaturan)
     * - Tombol R pada keyboard: Putar rotasi layar secara instan
     */
    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        when (keyCode) {
            KeyEvent.KEYCODE_MENU, KeyEvent.KEYCODE_SETTINGS -> {
                showSettingsDialog()
                return true
            }
            KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER -> {
                if (hudOverlay.visibility != View.VISIBLE) {
                    showQuickHudTemporarily()
                    return true
                }
            }
            KeyEvent.KEYCODE_R -> {
                cycleRotation()
                showQuickHudTemporarily()
                return true
            }
        }
        return super.onKeyDown(keyCode, event)
    }

    private fun showQuickHudTemporarily() {
        hudOverlay.visibility = View.VISIBLE
        btnQuickRotate.requestFocus()
        hudHideJob?.cancel()
        hudHideJob = lifecycleScope.launch {
            delay(6000)
            hudOverlay.visibility = View.GONE
        }
    }

    /**
     * Memutar sudut rotasi layar secara berurutan: 0° -> 90° -> 180° -> 270°
     */
    private fun cycleRotation() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val currentRotation = prefs.getInt(KEY_ROTATION, 0)
        val nextRotation = (currentRotation + 90) % 360
        prefs.edit().putInt(KEY_ROTATION, nextRotation).apply()
        applyRotationAndScale()
        Toast.makeText(this, "Rotasi diubah ke ${nextRotation}°", Toast.LENGTH_SHORT).show()
    }

    /**
     * Mengubah mode skala tampilan: Fit (Proporsional) -> Fill (Penuh Layar) -> Zoom (Crop Penuh)
     */
    private fun cycleScaleMode() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val currentMode = prefs.getString(KEY_SCALE_MODE, "fit") ?: "fit"
        val nextMode = when (currentMode) {
            "fit" -> "fill"
            "fill" -> "zoom"
            else -> "fit"
        }
        prefs.edit().putString(KEY_SCALE_MODE, nextMode).apply()
        applyRotationAndScale()
    }

    /**
     * Menerapkan rotasi (0°, 90°, 180°, 270°) dan penyesuaian dimensi container serta skala gambar/video
     */
    private fun applyRotationAndScale() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val rotationDeg = prefs.getInt(KEY_ROTATION, 0)
        val scaleMode = prefs.getString(KEY_SCALE_MODE, "fit") ?: "fit"

        val parentW = rootLayout.width
        val parentH = rootLayout.height

        if (parentW > 0 && parentH > 0) {
            val lp = rotatableStage.layoutParams
            // Jika rotasi 90° atau 270°, tukar lebar dan tinggi container agar pas memenuhi layar
            if (rotationDeg == 90 || rotationDeg == 270) {
                lp.width = parentH
                lp.height = parentW
            } else {
                lp.width = parentW
                lp.height = parentH
            }
            rotatableStage.layoutParams = lp
            rotatableStage.rotation = rotationDeg.toFloat()
        }

        // Terapkan mode skala pada ImageView dan ExoPlayer PlayerView
        when (scaleMode) {
            "fill" -> {
                imageSlideView.scaleType = ImageView.ScaleType.FIT_XY
                videoPlayerView.resizeMode = AspectRatioFrameLayout.RESIZE_MODE_FILL
                btnQuickScale.text = "Skala: Fill (Penuh)"
            }
            "zoom" -> {
                imageSlideView.scaleType = ImageView.ScaleType.CENTER_CROP
                videoPlayerView.resizeMode = AspectRatioFrameLayout.RESIZE_MODE_ZOOM
                btnQuickScale.text = "Skala: Zoom"
            }
            else -> {
                imageSlideView.scaleType = ImageView.ScaleType.FIT_CENTER
                videoPlayerView.resizeMode = AspectRatioFrameLayout.RESIZE_MODE_FIT
                btnQuickScale.text = "Skala: Fit (Normal)"
            }
        }

        val labelOrientasi = if (rotationDeg == 0 || rotationDeg == 180) "Landscape" else "Portrait"
        btnQuickRotate.text = "Rotasi: ${rotationDeg}° ($labelOrientasi)"
        updateBadgeDisplay()
    }

    /**
     * Dialog Pengaturan URL Server Backoffice, Kode Perangkat TV, dan Rotasi Manual
     */
    private fun showSettingsDialog() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val currentUrl = prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL) ?: DEFAULT_SERVER_URL
        val currentCode = prefs.getString(KEY_DEVICE_CODE, DEFAULT_DEVICE_CODE) ?: DEFAULT_DEVICE_CODE
        val currentRotation = prefs.getInt(KEY_ROTATION, 0)
        val currentScale = prefs.getString(KEY_SCALE_MODE, "fit") ?: "fit"

        val container = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 32, 48, 16)
        }

        val inputUrl = EditText(this).apply {
            hint = "Contoh: http://192.168.0.37:81/ApDigitalTV"
            setText(currentUrl)
            setSingleLine()
        }

        val inputCode = EditText(this).apply {
            hint = "Contoh: TV-LOBBY-01"
            setText(currentCode)
            setSingleLine()
        }

        val rotationOptions = arrayOf(
            "0° — Landscape Normal",
            "90° — Putar Kanan (Portrait / Sesuaikan Layar)",
            "180° — Landscape Terbalik",
            "270° — Putar Kiri (Portrait / Sesuaikan Layar)"
        )
        val rotationValues = intArrayOf(0, 90, 180, 270)
        val spinnerRotation = Spinner(this).apply {
            adapter = ArrayAdapter(this@MainActivity, android.R.layout.simple_spinner_dropdown_item, rotationOptions)
            val idx = rotationValues.indexOf(currentRotation).let { if (it >= 0) it else 0 }
            setSelection(idx)
        }

        val scaleOptions = arrayOf(
            "Fit — Proporsional Sesuai Asli",
            "Fill — Penuh Layar (Stretch Fullscreen)",
            "Zoom — Potong Tepi Agar Penuh (Crop)"
        )
        val scaleValues = arrayOf("fit", "fill", "zoom")
        val spinnerScale = Spinner(this).apply {
            adapter = ArrayAdapter(this@MainActivity, android.R.layout.simple_spinner_dropdown_item, scaleOptions)
            val idx = scaleValues.indexOf(currentScale).let { if (it >= 0) it else 0 }
            setSelection(idx)
        }

        container.addView(TextView(this).apply { text = "Base URL Server Backoffice:" })
        container.addView(inputUrl)
        container.addView(TextView(this).apply {
            text = "Kode Perangkat TV (device_code):"
            setPadding(0, 20, 0, 0)
        })
        container.addView(inputCode)
        container.addView(TextView(this).apply {
            text = "Rotasi Tampilan Manual:"
            setPadding(0, 20, 0, 0)
        })
        container.addView(spinnerRotation)
        container.addView(TextView(this).apply {
            text = "Mode Skala Konten:"
            setPadding(0, 20, 0, 0)
        })
        container.addView(spinnerScale)

        AlertDialog.Builder(this)
            .setTitle("Pengaturan Koneksi & Rotasi Layar")
            .setView(container)
            .setPositiveButton("Simpan & Terapkan") { _, _ ->
                val cleanUrl = inputUrl.text.toString().trim().trimEnd('/')
                val cleanCode = inputCode.text.toString().trim().uppercase()
                val selectedRotation = rotationValues[spinnerRotation.selectedItemPosition]
                val selectedScale = scaleValues[spinnerScale.selectedItemPosition]

                if (cleanUrl.isNotEmpty() && cleanCode.isNotEmpty()) {
                    prefs.edit()
                        .putString(KEY_SERVER_URL, cleanUrl)
                        .putString(KEY_DEVICE_CODE, cleanCode)
                        .putInt(KEY_ROTATION, selectedRotation)
                        .putString(KEY_SCALE_MODE, selectedScale)
                        .apply()

                    applyRotationAndScale()
                    currentPlaylistSignature = ""
                    Toast.makeText(this, "Pengaturan disimpan (${selectedRotation}°)", Toast.LENGTH_SHORT).show()
                    lifecycleScope.launch {
                        loadPlaylistFromLocalCache()
                        syncPlaylistFromServer(forceImmediateUpdate = true)
                    }
                }
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun updateBadgeDisplay() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val url = prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL)
        val code = prefs.getString(KEY_DEVICE_CODE, DEFAULT_DEVICE_CODE)
        val rot = prefs.getInt(KEY_ROTATION, 0)
        tvDeviceBadge.text = "CODE: $code  |  ROTASI: ${rot}°  |  SERVER: $url"
    }

    /**
     * Mengambil waktu saat ini yang sudah diselaraskan dengan jam Server Backoffice (Asia/Jakarta).
     * Dengan menyimpan selisih (offset) waktu server di SharedPreferences, jadwal tayang di TV
     * tetap akurat mengikuti jam server meskipun TV sedang dalam keadaan Offline!
     */
    private fun getCurrentSyncedDate(): Date {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val offsetMs = prefs.getLong(KEY_SERVER_TIME_OFFSET, 0L)
        return Date(System.currentTimeMillis() + offsetMs)
    }

    /**
     * Mengevaluasi apakah suatu item playlist sedang berada dalam jadwal tayang aktif (Schedule Play)
     * berdasarkan tanggal (start_date s/d end_date) dan jam harian (start_time s/d end_time).
     */
    private fun isItemWithinSchedule(item: PlaylistItem): Boolean {
        val now = getCurrentSyncedDate()
        val tz = TimeZone.getTimeZone("Asia/Jakarta")
        val dateFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply { timeZone = tz }
        val timeFormat = SimpleDateFormat("HH:mm:ss", Locale.US).apply { timeZone = tz }

        val currentDate = dateFormat.format(now)
        val currentTime = timeFormat.format(now)

        val startDate = item.startDate?.takeIf { it.isNotBlank() && it != "null" }
        val endDate = item.endDate?.takeIf { it.isNotBlank() && it != "null" }
        val startTime = item.startTime?.takeIf { it.isNotBlank() && it != "null" }
        val endTime = item.endTime?.takeIf { it.isNotBlank() && it != "null" }

        // 1. Cek rentang tanggal (start_date s/d end_date)
        if (startDate != null && currentDate < startDate) return false
        if (endDate != null && currentDate > endDate) return false

        // 2. Cek rentang jam harian (start_time s/d end_time)
        if (startTime != null && endTime != null) {
            if (startTime <= endTime) {
                // Jam normal di hari yang sama (contoh: 08:00:00 s/d 17:00:00)
                if (currentTime < startTime || currentTime > endTime) return false
            } else {
                // Jam lintas tengah malam (contoh: 22:00:00 s/d 02:00:00)
                if (currentTime < startTime && currentTime > endTime) return false
            }
        } else if (startTime != null) {
            if (currentTime < startTime) return false
        } else if (endTime != null) {
            if (currentTime > endTime) return false
        }

        return true
    }

    /**
     * Loop ringan setiap 5 detik untuk mengecek apakah ada jadwal konten yang baru saja
     * masuk jam tayang atau baru saja berakhir jam tayangnya (bekerja penuh meskipun Offline).
     */
    private fun startScheduleWatchdog() {
        scheduleWatchdogJob?.cancel()
        scheduleWatchdogJob = lifecycleScope.launch {
            while (isActive) {
                delay(SCHEDULE_CHECK_INTERVAL_MS)
                if (allCachedPlaylist.isNotEmpty()) {
                    refreshActiveSchedulePlayback(showToastOnUpdate = false)
                }
            }
        }
    }

    /**
     * Menyaring allCachedPlaylist berdasarkan jadwal yang aktif detik ini,
     * dan memperbarui pemutaran layar secara otomatis jika daftar konten yang aktif berubah.
     */
    private fun refreshActiveSchedulePlayback(showToastOnUpdate: Boolean = false) {
        if (allCachedPlaylist.isEmpty()) return

        val activeItems = allCachedPlaylist.filter { isItemWithinSchedule(it) }
        val newSignature = buildSignature(activeItems)

        if (activeItems.isEmpty()) {
            // Ada konten yang terdaftar untuk TV ini, tetapi semuanya sedang di luar jam/tanggal tayang
            if (isPlaying || currentPlaylistSignature != "STANDBY_SCHEDULE") {
                slideJob?.cancel()
                isPlaying = false
                playlist = emptyList()
                currentPlaylistSignature = "STANDBY_SCHEDULE"
                exoPlayer?.stop()

                imageSlideView.visibility = View.GONE
                videoPlayerView.visibility = View.GONE
                statusContainer.visibility = View.VISIBLE

                val tz = TimeZone.getTimeZone("Asia/Jakarta")
                val clockStr = SimpleDateFormat("HH:mm", Locale.US).apply { timeZone = tz }.format(getCurrentSyncedDate())
                tvStatusTitle.text = "Di Luar Jam Tayang (Standby)"
                tvStatusMessage.text = "Tersimpan ${allCachedPlaylist.size} konten terjadwal di perangkat ini.\nTayangan akan otomatis dimulai saat memasuki jadwal aktif (Waktu TV: $clockStr WIB)."
            }
            return
        }

        // Jika daftar konten aktif berubah (karena jam tayang baru dimulai/berakhir atau ada update dari server)
        if (newSignature != currentPlaylistSignature) {
            val hadPreviousPlaylist = currentPlaylistSignature.isNotEmpty() && currentPlaylistSignature != "STANDBY_SCHEDULE"
            currentPlaylistSignature = newSignature
            playlist = activeItems
            currentIndex = 0
            isPlaying = true

            playCurrentSlide()

            if (showToastOnUpdate && hadPreviousPlaylist) {
                Toast.makeText(
                    this@MainActivity,
                    "Playlist diperbarui (${activeItems.size} konten aktif)",
                    Toast.LENGTH_SHORT
                ).show()
            }
        } else if (!isPlaying) {
            playlist = activeItems
            isPlaying = true
            playCurrentSlide()
        }
    }

    /**
     * File JSON lokal untuk menyimpan metadata playlist terakhir dari server
     */
    private fun getCachedPlaylistJsonFile(deviceCode: String): File {
        val cacheDir = File(filesDir, "media_cache").apply { if (!exists()) mkdirs() }
        val safeCode = deviceCode.replace(Regex("[^a-zA-Z0-9_-]"), "_")
        return File(cacheDir, "playlist_${safeCode}.json")
    }

    /**
     * Membaca objek JSON item playlist beserta informasi schedule-nya
     */
    private fun parsePlaylistItemFromJson(obj: JSONObject, localFile: File): PlaylistItem {
        val schedObj = obj.optJSONObject("schedule")
        return PlaylistItem(
            playlistId = obj.getInt("playlist_id"),
            sequenceOrder = obj.getInt("sequence_order"),
            mediaId = obj.getInt("media_id"),
            title = obj.getString("title"),
            type = obj.getString("type"),
            duration = obj.optInt("duration", 10),
            fileUrl = obj.getString("file_url"),
            localFile = localFile,
            startDate = schedObj?.optString("start_date", "")?.takeIf { it.isNotEmpty() && it != "null" },
            endDate = schedObj?.optString("end_date", "")?.takeIf { it.isNotEmpty() && it != "null" },
            startTime = schedObj?.optString("start_time", "")?.takeIf { it.isNotEmpty() && it != "null" },
            endTime = schedObj?.optString("end_time", "")?.takeIf { it.isNotEmpty() && it != "null" }
        )
    }

    /**
     * Memuat daftar playlist beserta file gambar/video & jadwalnya dari memori internal TV (Offline Cache).
     * Dipanggil saat aplikasi baru dibuka atau saat jaringan terputus.
     */
    private suspend fun loadPlaylistFromLocalCache(): Boolean {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val deviceCode = prefs.getString(KEY_DEVICE_CODE, DEFAULT_DEVICE_CODE) ?: DEFAULT_DEVICE_CODE
        val jsonFile = getCachedPlaylistJsonFile(deviceCode)
        val cacheDir = File(filesDir, "media_cache")

        if (!jsonFile.exists()) return false

        val cachedItems = withContext(Dispatchers.IO) {
            try {
                val rawJson = jsonFile.readText()
                val playlistArray = JSONArray(rawJson)
                val list = mutableListOf<PlaylistItem>()

                for (i in 0 until playlistArray.length()) {
                    val obj = playlistArray.getJSONObject(i)
                    val mediaId = obj.getInt("media_id")
                    val fileUrl = obj.getString("file_url")
                    val ext = fileUrl.substringAfterLast('.', "bin").substringBefore('?')
                    val localFileName = "media_${mediaId}.${ext}"
                    val localFile = File(cacheDir, localFileName)

                    // Hanya masukkan jika file fisik medianya sudah terunduh di storage TV
                    if (localFile.exists() && localFile.length() > 0L) {
                        list.add(parsePlaylistItemFromJson(obj, localFile))
                    }
                }
                list
            } catch (e: Exception) {
                emptyList()
            }
        }

        if (cachedItems.isNotEmpty() && allCachedPlaylist.isEmpty()) {
            allCachedPlaylist = cachedItems
            refreshActiveSchedulePlayback(showToastOnUpdate = false)
            return true
        }
        return cachedItems.isNotEmpty()
    }

    private fun buildSignature(items: List<PlaylistItem>): String {
        return items.joinToString("|") {
            "${it.playlistId}:${it.mediaId}:${it.sequenceOrder}:${it.duration}:${it.fileUrl}:${it.startDate}:${it.endDate}:${it.startTime}:${it.endTime}"
        }
    }

    /**
     * Menjalankan loop sinkronisasi ke api.php setiap 15 detik di background
     */
    private fun startPeriodicSync() {
        syncJob?.cancel()
        syncJob = lifecycleScope.launch {
            while (isActive) {
                syncPlaylistFromServer(forceImmediateUpdate = false)
                delay(SYNC_INTERVAL_MS)
            }
        }
    }

    /**
     * Mengambil data JSON dari api.php?code=DEVICE_CODE sekaligus mengunduh (pre-cache) file media ke storage TV.
     * - Mendukung Pre-Cache: Konten yang jadwalnya baru mulai nanti tetap diunduh lebih awal.
     * - Jika koneksi terputus: tetap memutar & mengevaluasi jadwal konten dari cache lokal tanpa gangguan!
     */
    private suspend fun syncPlaylistFromServer(forceImmediateUpdate: Boolean = false) {
        if (syncMutex.isLocked && !forceImmediateUpdate) return

        syncMutex.withLock {
            val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            val baseUrl = (prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL) ?: DEFAULT_SERVER_URL).trimEnd('/')
            val deviceCode = prefs.getString(KEY_DEVICE_CODE, DEFAULT_DEVICE_CODE) ?: DEFAULT_DEVICE_CODE
            val endpoint = "$baseUrl/api.php?code=$deviceCode"

            try {
                val result = withContext(Dispatchers.IO) {
                    val url = URL(endpoint)
                    val conn = (url.openConnection() as HttpURLConnection).apply {
                        requestMethod = "GET"
                        connectTimeout = 6000
                        readTimeout = 8000
                        useCaches = false
                    }

                    val responseCode = conn.responseCode
                    val stream = if (responseCode in 200..299) conn.inputStream else conn.errorStream
                    val body = stream?.bufferedReader()?.use { it.readText() } ?: "{}"
                    Pair(responseCode, JSONObject(body))
                }

                val (httpCode, json) = result
                val status = json.optString("status", "error")

                // Jika server merespons bahwa device dinonaktifkan / tidak ditemukan (403 / 404)
                if (httpCode != 200 || status != "success") {
                    val msg = json.optString("message", "Gagal memuat playlist dari server.")
                    allCachedPlaylist = emptyList()
                    stopPlaybackAndShowStatus(
                        if (httpCode == 403) "Perangkat Nonaktif / Maintenance" else "Perangkat Tidak Terdaftar",
                        msg
                    )
                    return
                }

                // Sinkronkan jam internal aplikasi dengan server_timestamp dari Backoffice
                val serverTimestamp = json.optLong("server_timestamp", 0L)
                if (serverTimestamp > 0L) {
                    val offsetMs = serverTimestamp - System.currentTimeMillis()
                    prefs.edit().putLong(KEY_SERVER_TIME_OFFSET, offsetMs).apply()
                }

                val playlistArray = json.optJSONArray("playlist")
                if (playlistArray == null || playlistArray.length() == 0) {
                    // Kosongkan cache JSON jika admin memang menghapus semua jadwal playlist untuk TV ini
                    withContext(Dispatchers.IO) {
                        getCachedPlaylistJsonFile(deviceCode).delete()
                    }
                    allCachedPlaylist = emptyList()
                    stopPlaybackAndShowStatus(
                        "Playlist Kosong",
                        "Belum ada konten yang dijadwalkan untuk TV ($deviceCode) di Menu Konten Backoffice."
                    )
                    return
                }

                // Unduh semua file gambar/video terjadwal ke penyimpanan internal TV (Pre-Caching)
                val cacheDir = File(filesDir, "media_cache").apply { if (!exists()) mkdirs() }
                val activeFileNames = mutableSetOf<String>()
                val downloadedItems = mutableListOf<PlaylistItem>()
                val jsonCacheFile = getCachedPlaylistJsonFile(deviceCode)
                activeFileNames.add(jsonCacheFile.name)

                withContext(Dispatchers.IO) {
                    for (i in 0 until playlistArray.length()) {
                        val obj = playlistArray.getJSONObject(i)
                        val mediaId = obj.getInt("media_id")
                        val fileUrl = obj.getString("file_url")
                        val ext = fileUrl.substringAfterLast('.', "bin").substringBefore('?')
                        val localFileName = "media_${mediaId}.${ext}"
                        val localFile = File(cacheDir, localFileName)

                        activeFileNames.add(localFileName)

                        // Jika file belum ada di memori TV, unduh dari server
                        if (!localFile.exists() || localFile.length() == 0L) {
                            downloadFile(fileUrl, localFile)
                        }

                        if (localFile.exists() && localFile.length() > 0L) {
                            downloadedItems.add(parsePlaylistItemFromJson(obj, localFile))
                        }
                    }

                    // Simpan daftar JSON playlist lengkap (beserta jadwalnya) ke disk lokal
                    if (downloadedItems.isNotEmpty()) {
                        jsonCacheFile.writeText(playlistArray.toString())
                    }

                    // Bersihkan file cache lama yang sudah tidak ada di daftar playlist TV ini
                    cacheDir.listFiles()?.forEach { file ->
                        if (file.name !in activeFileNames && !file.name.startsWith("playlist_")) {
                            file.delete()
                        }
                    }
                }

                if (downloadedItems.isEmpty()) {
                    if (allCachedPlaylist.isEmpty()) {
                        stopPlaybackAndShowStatus("Gagal Mengunduh Media", "Pastikan file media dapat diakses dari jaringan TV.")
                    }
                    return
                }

                val wasOffline = isOfflineMode
                isOfflineMode = false
                allCachedPlaylist = downloadedItems

                // Evaluasi jadwal tayang (Schedule Play) dan putar hanya konten yang aktif saat ini
                refreshActiveSchedulePlayback(showToastOnUpdate = true || wasOffline)

            } catch (e: Exception) {
                // KONEKSI TERPUTUS / SERVER OFFLINE:
                isOfflineMode = true

                // Jika daftar cache belum dimuat di RAM, muat dari Cache Lokal TV
                if (allCachedPlaylist.isEmpty()) {
                    val loadedFromCache = loadPlaylistFromLocalCache()
                    if (!loadedFromCache) {
                        stopPlaybackAndShowStatus(
                            "Menunggu Koneksi Server...",
                            "Tidak dapat menghubungi $endpoint\nBelum ada cache lokal tersimpan untuk perangkat ini."
                        )
                    }
                } else {
                    // Tetap evaluasi jadwal secara lokal walaupun sedang offline
                    refreshActiveSchedulePlayback(showToastOnUpdate = false)
                }
            }
        }
    }

    private fun downloadFile(fileUrl: String, destination: File) {
        val tempFile = File(destination.parentFile, "${destination.name}.tmp")
        try {
            val url = URL(fileUrl)
            val conn = (url.openConnection() as HttpURLConnection).apply {
                connectTimeout = 15000
                readTimeout = 30000
            }
            if (conn.responseCode in 200..299) {
                conn.inputStream.use { input ->
                    FileOutputStream(tempFile).use { output ->
                        input.copyTo(output)
                    }
                }
                tempFile.renameTo(destination)
            }
        } catch (e: Exception) {
            tempFile.delete()
        }
    }

    /**
     * Animasi Slide-In + Fade ringan berbasis GPU (RenderThread)
     * Tidak membebani CPU maupun RAM karena hanya menganimasikan properti komposisi GPU (translationX & alpha)
     */
    private fun animateSlideTransition(targetView: View) {
        targetView.animate().cancel()
        val stageWidth = rotatableStage.width.takeIf { it > 0 } ?: 1920
        val startOffsetPx = stageWidth * 0.16f // Geser halus sebesar 16% dari sisi kanan

        targetView.alpha = 0f
        targetView.translationX = startOffsetPx
        targetView.animate()
            .alpha(1f)
            .translationX(0f)
            .setDuration(480L)
            .setInterpolator(slideInterpolator)
            .withLayer()
            .start()
    }

    /**
     * Memutar item playlist saat ini (Gambar atau Video) langsung dari file cache lokal TV
     */
    private fun playCurrentSlide() {
        slideJob?.cancel()

        // Pastikan daftar playlist yang sedang aktif disegarkan sebelum memutar slide
        if (allCachedPlaylist.isNotEmpty()) {
            val activeNow = allCachedPlaylist.filter { isItemWithinSchedule(it) }
            if (activeNow.isEmpty()) {
                refreshActiveSchedulePlayback(showToastOnUpdate = false)
                return
            }
            if (buildSignature(activeNow) != currentPlaylistSignature) {
                refreshActiveSchedulePlayback(showToastOnUpdate = false)
                return
            }
        }

        if (playlist.isEmpty()) return

        statusContainer.visibility = View.GONE
        if (currentIndex >= playlist.size) {
            currentIndex = 0
        }
        val item = playlist[currentIndex]

        // Jika karena sesuatu hal file fisik tidak ditemukan, lompati ke slide berikutnya
        if (!item.localFile.exists()) {
            nextSlide()
            return
        }

        if (item.type == "video") {
            imageSlideView.animate().cancel()
            imageSlideView.visibility = View.GONE

            videoPlayerView.alpha = 0f
            videoPlayerView.visibility = View.VISIBLE

            exoPlayer?.apply {
                stop()
                clearMediaItems()
                setMediaItem(MediaItem.fromUri(Uri.fromFile(item.localFile)))
                prepare()
                playWhenReady = true
            }
        } else {
            // Mode Gambar (Image)
            exoPlayer?.stop()
            videoPlayerView.animate().cancel()
            videoPlayerView.visibility = View.GONE

            imageSlideView.alpha = 0f
            imageSlideView.visibility = View.VISIBLE

            imageSlideView.load(item.localFile, imageLoader) {
                listener(
                    onSuccess = { _, _ ->
                        animateSlideTransition(imageSlideView)
                    },
                    onError = { _, _ ->
                        nextSlide()
                    }
                )
            }

            val durationMs = (item.duration.coerceAtLeast(1)) * 1000L
            slideJob = lifecycleScope.launch {
                delay(durationMs)
                nextSlide()
            }
        }
    }

    private fun nextSlide() {
        if (playlist.isEmpty()) return
        currentIndex = (currentIndex + 1) % playlist.size
        playCurrentSlide()
    }

    private fun stopPlaybackAndShowStatus(title: String, message: String) {
        slideJob?.cancel()
        isPlaying = false
        playlist = emptyList()
        currentPlaylistSignature = ""
        exoPlayer?.stop()

        imageSlideView.visibility = View.GONE
        videoPlayerView.visibility = View.GONE
        statusContainer.visibility = View.VISIBLE

        tvStatusTitle.text = title
        tvStatusMessage.text = message
    }

    private fun enableImmersiveFullscreen() {
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.R) {
            window.insetsController?.let { controller ->
                controller.hide(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
                controller.systemBarsBehavior = WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = (
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    or View.SYSTEM_UI_FLAG_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                )
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        slideJob?.cancel()
        syncJob?.cancel()
        scheduleWatchdogJob?.cancel()
        hudHideJob?.cancel()
        networkCallback?.let { cb ->
            try {
                connectivityManager?.unregisterNetworkCallback(cb)
            } catch (_: Exception) {
            }
        }
        exoPlayer?.release()
        exoPlayer = null
    }
}
