<?php
declare(strict_types=1);

session_start();

// Jika sudah login, langsung arahkan ke halaman utama (index.php)
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db.php';

$db = (new Database())->getConnection();

// Pastikan tabel users tersedia beserta akun default (admin / admin123)
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$stmtCount = $db->query("SELECT COUNT(*) FROM users");
if ((int) $stmtCount->fetchColumn() === 0) {
    $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmtSeed = $db->prepare("INSERT INTO users (username, password_hash, full_name) VALUES (:u, :p, :f)");
    $stmtSeed->execute([
        ':u' => 'admin',
        ':p' => $defaultHash,
        ':f' => 'Administrator',
    ]);
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errorMessage = 'Username dan Password wajib diisi.';
    } else {
        $stmt = $db->prepare("SELECT id, username, password_hash, full_name FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = (int) $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_name'] = $user['full_name'];

            header('Location: index.php');
            exit;
        }

        $errorMessage = 'Username atau Password yang Anda masukkan salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin — AP Digital Signage</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --border: #334155;
            --text: #f8fafc;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at top, #1e293b 0%, #0f172a 70%);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 32px 28px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
        }
        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, #3b82f6, #2dd4bf);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.05rem;
            color: #0f172a;
        }
        .brand h1 {
            font-size: 1.25rem;
            color: #fff;
            line-height: 1.2;
        }
        .brand span {
            font-size: 0.82rem;
            color: var(--muted);
            font-weight: 400;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 0.88rem;
            margin-bottom: 18px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        input {
            width: 100%;
            padding: 11px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #0f172a;
            color: var(--text);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.15s;
        }
        input:focus {
            border-color: #38bdf8;
        }
        .btn-login {
            width: 100%;
            margin-top: 6px;
            padding: 12px;
            border-radius: 8px;
            background: var(--primary);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-login:hover {
            background: var(--primary-hover);
        }
        .demo-info {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            font-size: 0.82rem;
            color: var(--muted);
            text-align: center;
        }
        .demo-info code {
            background: #0f172a;
            padding: 2px 6px;
            border-radius: 4px;
            color: #38bdf8;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <div class="brand-icon">TV</div>
            <div>
                <h1>AP Digital Signage</h1>
                <span>Silakan login untuk mengelola konten</span>
            </div>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert-error">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Masukkan password" required>
            </div>
            <button type="submit" class="btn-login">Masuk ke Menu Konten</button>
        </form>

        <div class="demo-info">
            Akun Default: Username <code>admin</code> &bull; Password <code>admin123</code>
        </div>
    </div>
</body>
</html>
