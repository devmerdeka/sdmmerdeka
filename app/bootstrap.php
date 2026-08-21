<?php
declare(strict_types=1);

if (!is_dir(__DIR__ . '/../storage/sessions')) {
    mkdir(__DIR__ . '/../storage/sessions', 0775, true);
}
session_save_path(__DIR__ . '/../storage/sessions');
session_start();

const APP_NAME = 'SDM Perusahaan';
const PHOTO_DIR = __DIR__ . '/../storage/photos';
const BACKUP_DIR = __DIR__ . '/../storage/data/backups';
const MAX_PHOTO_BYTES = 500 * 1024;

date_default_timezone_set('Asia/Jakarta');

function db_config(): array
{
    $configFile = __DIR__ . '/../config.php';
    $fileConfig = is_file($configFile) ? require $configFile : [];

    return [
        'host' => getenv('DB_HOST') ?: ($fileConfig['host'] ?? '127.0.0.1'),
        'port' => getenv('DB_PORT') ?: ($fileConfig['port'] ?? '3306'),
        'database' => getenv('DB_DATABASE') ?: ($fileConfig['database'] ?? 'absensi_mgi'),
        'username' => getenv('DB_USERNAME') ?: ($fileConfig['username'] ?? 'root'),
        'password' => getenv('DB_PASSWORD') ?: ($fileConfig['password'] ?? ''),
        'charset' => getenv('DB_CHARSET') ?: ($fileConfig['charset'] ?? 'utf8mb4'),
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = db_config();
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
    $pdo = new PDO($dsn, $config['username'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    migrate($pdo);
    seed($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL,
    employee_code VARCHAR(80),
    position VARCHAR(120),
    field_employee TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    radius_meters INT NOT NULL DEFAULT 150,
    active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    late_tolerance_minutes INT NOT NULL DEFAULT 10,
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_hours (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE(company_id, day_of_week),
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    UNIQUE(user_id, work_date),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(shift_id) REFERENCES shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    type ENUM('check_in', 'check_out') NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_time TIME NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    location_id INT UNSIGNED NULL,
    distance_meters DECIMAL(10,2),
    location_status VARCHAR(40) NOT NULL,
    late_minutes INT NOT NULL DEFAULT 0,
    early_leave_minutes INT NOT NULL DEFAULT 0,
    photo_path VARCHAR(255) NOT NULL,
    notes TEXT,
    created_at DATETIME NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS overtime_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    overtime_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_policies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    annual_limit_days INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE(company_id, leave_type),
    FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SQL);
    ensure_column($pdo, 'overtime_requests', 'duration_minutes', 'INT NOT NULL DEFAULT 0');
    ensure_column($pdo, 'attendances', 'late_minutes', 'INT NOT NULL DEFAULT 0');
    ensure_column($pdo, 'attendances', 'early_leave_minutes', 'INT NOT NULL DEFAULT 0');
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
    $stmt->execute([$column]);
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
}

function seed(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        seed_default_work_hours($pdo);
        seed_default_leave_policies($pdo);
        return;
    }

    $now = now();
    $pdo->prepare('INSERT INTO companies (name, code, created_at) VALUES (?, ?, ?)')
        ->execute(['MGI Holding', 'MGI', $now]);
    $companyId = (int) $pdo->lastInsertId();

    $user = $pdo->prepare('INSERT INTO users (company_id, name, email, password_hash, role, employee_code, position, field_employee, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $user->execute([$companyId, 'Admin MGI', 'admin@mgi.test', password_hash('password', PASSWORD_DEFAULT), 'admin', 'ADM001', 'HR Admin', 0, $now]);
    $user->execute([$companyId, 'Karyawan Lapangan', 'lapangan@mgi.test', password_hash('password', PASSWORD_DEFAULT), 'employee', 'FLD001', 'Field Officer', 1, $now]);

    $pdo->prepare('INSERT INTO locations (company_id, name, latitude, longitude, radius_meters) VALUES (?, ?, ?, ?, ?)')
        ->execute([$companyId, 'Kantor Pusat', -6.200000, 106.816666, 200]);
    $pdo->prepare('INSERT INTO shifts (company_id, name, start_time, end_time, late_tolerance_minutes) VALUES (?, ?, ?, ?, ?)')
        ->execute([$companyId, 'Normal', '08:00', '17:00', 15]);

    seed_default_work_hours($pdo);
    seed_default_leave_policies($pdo);
}

function seed_default_work_hours(PDO $pdo): void
{
    $companyIds = $pdo->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_COLUMN);
    $workHour = $pdo->prepare('INSERT IGNORE INTO work_hours (company_id, day_of_week, start_time, end_time, active) VALUES (?, ?, ?, ?, ?)');
    foreach ($companyIds as $companyId) {
        for ($day = 1; $day <= 5; $day++) {
            $workHour->execute([(int) $companyId, $day, '08:00', '17:00', 1]);
        }
    }
}

function seed_default_leave_policies(PDO $pdo): void
{
    $defaults = [
        'Cuti Tahunan' => 12,
        'Sakit' => 14,
        'Izin' => 6,
    ];
    $companyIds = $pdo->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_COLUMN);
    $policy = $pdo->prepare('INSERT IGNORE INTO leave_policies (company_id, leave_type, annual_limit_days, active) VALUES (?, ?, ?, 1)');
    foreach ($companyIds as $companyId) {
        foreach ($defaults as $type => $limit) {
            $policy->execute([(int) $companyId, $type, $limit]);
        }
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT users.*, companies.name AS company_name FROM users LEFT JOIN companies ON companies.id = users.company_id WHERE users.id = ? AND users.active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('?page=login');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        redirect('?page=attendance');
    }

    return $user;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

function distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function nearest_location(int $companyId, float $lat, float $lon): ?array
{
    $stmt = db()->prepare('SELECT * FROM locations WHERE company_id = ? AND active = 1');
    $stmt->execute([$companyId]);
    $nearest = null;

    foreach ($stmt->fetchAll() as $location) {
        $distance = distance_meters($lat, $lon, (float) $location['latitude'], (float) $location['longitude']);
        $location['distance_meters'] = $distance;
        if ($nearest === null || $distance < $nearest['distance_meters']) {
            $nearest = $location;
        }
    }

    return $nearest;
}

function save_photo(string $dataUrl, int $userId): string
{
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $dataUrl, $matches)) {
        throw new RuntimeException('Foto wajah tidak valid.');
    }

    $encoded = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $binary = base64_decode($encoded, true);
    if ($binary === false || strlen($binary) < 5000) {
        throw new RuntimeException('Foto wajah terlalu kecil atau rusak.');
    }
    if (strlen($binary) > MAX_PHOTO_BYTES) {
        throw new RuntimeException('Foto wajah terlalu besar. Maksimal 500 KB.');
    }

    if (!is_dir(PHOTO_DIR)) {
        mkdir(PHOTO_DIR, 0775, true);
    }

    $filename = sprintf('user-%d-%s.jpg', $userId, date('YmdHis'));
    $path = PHOTO_DIR . '/' . $filename;
    save_watermarked_photo($binary, 'image/' . ($matches[1] === 'jpg' ? 'jpeg' : $matches[1]), $path);

    return $filename;
}

function save_uploaded_photo(array $file, int $userId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Foto wajah belum berhasil diupload.');
    }

    if (($file['size'] ?? 0) < 5000) {
        throw new RuntimeException('Foto wajah terlalu kecil atau rusak.');
    }

    if (($file['size'] ?? 0) > MAX_PHOTO_BYTES) {
        throw new RuntimeException('Foto wajah terlalu besar. Maksimal 500 KB.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmpPath);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('File harus berupa foto JPG, PNG, atau WEBP.');
    }

    if (!is_dir(PHOTO_DIR)) {
        mkdir(PHOTO_DIR, 0775, true);
    }

    $filename = sprintf('user-%d-%s.jpg', $userId, date('YmdHis'));
    $path = PHOTO_DIR . '/' . $filename;
    $imageData = file_get_contents($tmpPath);
    if ($imageData === false) {
        throw new RuntimeException('Foto wajah gagal disimpan.');
    }
    save_watermarked_photo($imageData, (string) $info['mime'], $path);

    return $filename;
}

function save_watermarked_photo(string $binary, string $mime, string $path): void
{
    $watermark = 'Waktu absen: ' . date('d-m-Y H:i:s') . ' WIB';

    if (class_exists('Imagick')) {
        save_watermarked_photo_imagick($binary, $watermark, $path);
        return;
    }

    if (function_exists('imagecreatefromstring')) {
        save_watermarked_photo_gd($binary, $watermark, $path);
        return;
    }

    throw new RuntimeException('Server belum mendukung watermark foto. Aktifkan extension Imagick atau GD.');
}

function save_watermarked_photo_imagick(string $binary, string $watermark, string $path): void
{
    $image = new Imagick();
    $image->readImageBlob($binary);
    $image->autoOrient();
    $image->setImageFormat('jpeg');
    $image->setImageBackgroundColor('white');
    $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

    if ($image->getImageWidth() > 1280) {
        $image->thumbnailImage(1280, 0);
    }

    $fontSize = max(18, (int) round($image->getImageWidth() / 35));
    $padding = max(14, (int) round($fontSize * 0.75));
    $barHeight = $fontSize + ($padding * 2);

    $bar = new ImagickDraw();
    $bar->setFillColor(new ImagickPixel('rgba(0,0,0,0.58)'));
    $bar->rectangle(0, $image->getImageHeight() - $barHeight, $image->getImageWidth(), $image->getImageHeight());
    $image->drawImage($bar);

    $text = new ImagickDraw();
    $text->setFillColor(new ImagickPixel('white'));
    $text->setFontSize($fontSize);
    $text->setFontWeight(700);
    $image->annotateImage($text, $padding, $image->getImageHeight() - $padding, 0, $watermark);

    for ($quality = 82; $quality >= 35; $quality -= 7) {
        $image->setImageCompressionQuality($quality);
        $blob = $image->getImagesBlob();
        if (strlen($blob) <= MAX_PHOTO_BYTES || $quality <= 35) {
            if (strlen($blob) > MAX_PHOTO_BYTES) {
                throw new RuntimeException('Foto wajah terlalu besar setelah watermark. Coba ambil foto ulang lebih dekat atau lebih rendah resolusinya.');
            }
            if (file_put_contents($path, $blob) === false) {
                throw new RuntimeException('Foto wajah gagal disimpan.');
            }
            return;
        }
    }
}

function save_watermarked_photo_gd(string $binary, string $watermark, string $path): void
{
    $source = @imagecreatefromstring($binary);
    if (!$source) {
        throw new RuntimeException('Foto wajah tidak dapat diproses.');
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width > 1280) {
        $newWidth = 1280;
        $newHeight = (int) round($height * ($newWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);
        $source = $resized;
        $width = $newWidth;
        $height = $newHeight;
    }

    $fontSize = max(4, min(5, (int) round($width / 280)));
    $textWidth = imagefontwidth($fontSize) * strlen($watermark);
    while ($fontSize > 2 && $textWidth > ($width - 24)) {
        $fontSize--;
        $textWidth = imagefontwidth($fontSize) * strlen($watermark);
    }

    $padding = 12;
    $barHeight = imagefontheight($fontSize) + ($padding * 2);
    $black = imagecolorallocatealpha($source, 0, 0, 0, 45);
    $white = imagecolorallocate($source, 255, 255, 255);
    imagefilledrectangle($source, 0, $height - $barHeight, $width, $height, $black);
    imagestring($source, $fontSize, $padding, $height - $barHeight + $padding, $watermark, $white);

    for ($quality = 82; $quality >= 35; $quality -= 7) {
        ob_start();
        imagejpeg($source, null, $quality);
        $blob = (string) ob_get_clean();
        if (strlen($blob) <= MAX_PHOTO_BYTES || $quality <= 35) {
            imagedestroy($source);
            if (strlen($blob) > MAX_PHOTO_BYTES) {
                throw new RuntimeException('Foto wajah terlalu besar setelah watermark. Coba ambil foto ulang lebih dekat atau lebih rendah resolusinya.');
            }
            if (file_put_contents($path, $blob) === false) {
                throw new RuntimeException('Foto wajah gagal disimpan.');
            }
            return;
        }
    }
}

function companies(): array
{
    return db()->query('SELECT * FROM companies ORDER BY name')->fetchAll();
}

function day_name(int $day): string
{
    return [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ][$day] ?? '-';
}

function leave_days(string $start, string $end): int
{
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    if ($endDate < $startDate) {
        return 0;
    }

    return (int) $startDate->diff($endDate)->days + 1;
}

function leave_used_days(int $userId, string $leaveType, int $year, ?int $exceptId = null): int
{
    $sql = "SELECT id, start_date, end_date FROM leave_requests WHERE user_id = ? AND leave_type = ? AND status IN ('pending', 'approved') AND YEAR(start_date) = ?";
    $params = [$userId, $leaveType, (string) $year];
    if ($exceptId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $exceptId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $days = 0;
    foreach ($stmt->fetchAll() as $row) {
        $days += leave_days($row['start_date'], $row['end_date']);
    }

    return $days;
}

function leave_policy_limit(int $companyId, string $leaveType): int
{
    $stmt = db()->prepare('SELECT annual_limit_days FROM leave_policies WHERE company_id = ? AND leave_type = ? AND active = 1');
    $stmt->execute([$companyId, $leaveType]);
    $limit = $stmt->fetchColumn();

    return $limit === false ? 0 : (int) $limit;
}

function ensure_leave_quota(array $user, string $leaveType, string $start, string $end, ?int $exceptId = null): void
{
    $requested = leave_days($start, $end);
    if ($requested <= 0) {
        throw new RuntimeException('Tanggal cuti tidak valid.');
    }

    $year = (int) substr($start, 0, 4);
    $limit = leave_policy_limit((int) $user['company_id'], $leaveType);
    $used = leave_used_days((int) $user['id'], $leaveType, $year, $exceptId);
    if ($limit > 0 && ($used + $requested) > $limit) {
        throw new RuntimeException(sprintf('Kuota %s tersisa %d hari.', $leaveType, max(0, $limit - $used)));
    }
}

function render_header(string $title, ?array $user = null): void
{
    $flash = flash();
    $styleVersion = is_file(__DIR__ . '/../public/assets/style.css') ? (string) filemtime(__DIR__ . '/../public/assets/style.css') : (string) time();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - <?= APP_NAME ?></title>
        <link rel="stylesheet" href="assets/style.css?v=<?= h($styleVersion) ?>">
    </head>
    <body>
    <div class="shell">
        <aside class="sidebar">
            <a class="brand" href="?"><?= APP_NAME ?></a>
            <?php if ($user): ?>
                <div class="identity">
                    <strong><?= h($user['name']) ?></strong>
                    <span><?= h($user['company_name'] ?? 'Tanpa perusahaan') ?></span>
                </div>
                <nav>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a href="?page=dashboard">Dashboard</a>
                        <a href="?page=employees">Karyawan</a>
                        <a href="?page=companies">Anak Perusahaan</a>
                        <a href="?page=overtime">Lembur</a>
                        <a href="?page=leaves">Pengajuan Cuti</a>
                        <a href="?page=reports">Laporan Rekap</a>
                        <a href="?page=settings">Pengaturan</a>
                    <?php else: ?>
                        <a href="?page=dashboard">Dashboard</a>
                        <a href="?page=attendance">Absen</a>
                        <a href="?page=my-overtime">Lembur Saya</a>
                        <a href="?page=my-leaves">Cuti Saya</a>
                        <a href="?page=my-history">Riwayat</a>
                    <?php endif; ?>
                    <a href="?page=logout">Keluar</a>
                </nav>
            <?php endif; ?>
        </aside>
        <main class="content">
            <?php if ($flash): ?>
                <div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endif; ?>
    <?php
}

function render_footer(): void
{
    $assetVersion = is_file(__DIR__ . '/../public/assets/app.js') ? (string) filemtime(__DIR__ . '/../public/assets/app.js') : (string) time();
    ?>
        </main>
    </div>
    <script src="assets/app.js?v=<?= h($assetVersion) ?>"></script>
    </body>
    </html>
    <?php
}

function input(string $name, string $default = ''): string
{
    return trim((string) ($_POST[$name] ?? $_GET[$name] ?? $default));
}
