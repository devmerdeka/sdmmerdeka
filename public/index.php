<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

verify_csrf();

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'logout') {
    session_destroy();
    redirect('?page=login');
}

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $stmt->execute([input('email')]);
        $user = $stmt->fetch();

        if ($user && password_verify(input('password'), $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            redirect('?page=dashboard');
        }

        flash('Email atau password salah.', 'danger');
    }

    render_header('Masuk');
    ?>
    <section class="login-panel">
        <div>
            <p class="eyebrow">Sistem SDM Perusahaan</p>
            <h1>Platform SDM untuk absensi, cuti, lembur, shift, dan tim lapangan.</h1>
        </div>
        <form method="post" class="card form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <label>Email <input type="email" name="email" required autofocus></label>
            <label>Password <input type="password" name="password" required></label>
            <button class="btn primary">Masuk</button>
        </form>
    </section>
    <?php
    render_footer();
    exit;
}

$user = require_login();

if ($page === 'photo') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT a.* FROM attendances a WHERE a.id = ?');
    $stmt->execute([$id]);
    $attendance = $stmt->fetch();

    if (!$attendance || ($user['role'] !== 'admin' && (int) $attendance['user_id'] !== (int) $user['id'])) {
        http_response_code(404);
        exit('Foto tidak ditemukan.');
    }

    $file = PHOTO_DIR . '/' . basename($attendance['photo_path']);
    if (!is_file($file)) {
        http_response_code(404);
        exit('File foto tidak ditemukan.');
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if ($page === 'dashboard') {
    if ($user['role'] !== 'admin') {
        $todayStmt = db()->prepare('SELECT * FROM attendances WHERE user_id = ? AND attendance_date = ? ORDER BY created_at DESC');
        $todayStmt->execute([$user['id'], date('Y-m-d')]);
        $todayRows = $todayStmt->fetchAll();
        $pendingLeave = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'pending'");
        $pendingLeave->execute([$user['id']]);
        $pendingLeaveCount = (int) $pendingLeave->fetchColumn();
        $pendingOvertime = db()->prepare("SELECT COUNT(*) FROM overtime_requests WHERE user_id = ? AND status = 'pending'");
        $pendingOvertime->execute([$user['id']]);
        $pendingOvertimeCount = (int) $pendingOvertime->fetchColumn();
        $todayPage = paginate_array($todayRows, 'today');

        render_header('Dashboard Karyawan', $user);
        ?>
        <section class="hero-panel employee-hero">
            <div>
                <span class="eyebrow">Dashboard Karyawan</span>
                <h1>Halo, <?= h($user['name']) ?></h1>
                <p>Kelola absensi, cuti, lembur, dan riwayat kerja dari satu tempat yang ringkas.</p>
            </div>
            <a class="btn primary" href="?page=attendance">Absen Sekarang</a>
        </section>
        <section class="stats dashboard-stats">
            <article class="stat-card green"><span>Absensi Hari Ini</span><strong><?= count($todayRows) ?></strong><small>Masuk/pulang yang tercatat hari ini</small></article>
            <article class="stat-card amber"><span>Cuti Pending</span><strong><?= $pendingLeaveCount ?></strong><small>Menunggu persetujuan admin</small></article>
            <article class="stat-card blue"><span>Lembur Pending</span><strong><?= $pendingOvertimeCount ?></strong><small>Pengajuan yang belum diputuskan</small></article>
            <article class="stat-card rose"><span>Status Lokasi</span><strong><?= (int) $user['field_employee'] === 1 ? 'Lapangan' : 'Kantor' ?></strong><small>Tipe lokasi absensi karyawan</small></article>
        </section>
        <section class="dashboard-grid">
            <div class="panel quick-panel">
                <h2>Aksi Cepat</h2>
                <div class="quick-actions">
                    <a href="?page=attendance">Absen</a>
                    <a href="?page=my-leaves">Ajukan Cuti</a>
                    <a href="?page=my-overtime">Ajukan Lembur</a>
                    <a href="?page=my-history">Riwayat</a>
                </div>
            </div>
            <div class="panel insight-panel">
                <h2>Prioritas Hari Ini</h2>
                <p class="big-value"><?= $pendingLeaveCount + $pendingOvertimeCount ?></p>
                <p class="muted">Pengajuan cuti dan lembur yang masih menunggu.</p>
            </div>
        </section>
        <section class="panel section-gap">
            <h2>Absensi Hari Ini</h2>
            <?php table_attendance($todayPage['rows'], false); ?>
            <?php pagination_controls($todayPage); ?>
        </section>
        <?php
        render_footer();
        exit;
    }

    $user = require_admin();
    $stats = [
        'employees' => db()->query("SELECT COUNT(*) FROM users WHERE role = 'employee'")->fetchColumn(),
        'companies' => db()->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
        'today' => (function (): int {
            $stmt = db()->prepare('SELECT COUNT(*) FROM attendances WHERE attendance_date = ?');
            $stmt->execute([date('Y-m-d')]);
            return (int) $stmt->fetchColumn();
        })(),
        'leaves' => db()->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn(),
        'overtime' => db()->query("SELECT COUNT(*) FROM overtime_requests WHERE status = 'pending'")->fetchColumn(),
    ];
    $rows = db()->query("SELECT a.*, u.name, c.name AS company_name FROM attendances a JOIN users u ON u.id = a.user_id JOIN companies c ON c.id = a.company_id ORDER BY a.created_at DESC")->fetchAll();
    $attendancePage = paginate_array($rows, 'dash');

    render_header('Dashboard', $user);
    ?>
    <section class="hero-panel admin-hero">
        <div>
            <span class="eyebrow">Dashboard Admin</span>
            <h1>Ringkasan SDM Perusahaan</h1>
            <p>Pantau karyawan, absensi, cuti, lembur, shift, dan laporan dari satu layar.</p>
        </div>
        <a class="btn primary" href="?page=employees">Tambah Karyawan</a>
    </section>
    <section class="stats dashboard-stats">
        <article class="stat-card green"><span>Karyawan</span><strong><?= (int) $stats['employees'] ?></strong><small>Aktif di seluruh perusahaan</small></article>
        <article class="stat-card blue"><span>Anak Perusahaan</span><strong><?= (int) $stats['companies'] ?></strong><small>Entitas terdaftar</small></article>
        <article class="stat-card amber"><span>Absen Hari Ini</span><strong><?= (int) $stats['today'] ?></strong><small>Aktivitas masuk/pulang</small></article>
        <article class="stat-card rose"><span>Cuti Pending</span><strong><?= (int) $stats['leaves'] ?></strong><small>Butuh persetujuan</small></article>
        <article class="stat-card violet"><span>Lembur Pending</span><strong><?= (int) $stats['overtime'] ?></strong><small>Butuh persetujuan</small></article>
    </section>
    <section class="dashboard-grid">
        <div class="panel quick-panel">
            <h2>Aksi Cepat</h2>
            <div class="quick-actions">
                <a href="?page=employees">Karyawan</a>
                <a href="?page=shifts">Shift</a>
                <a href="?page=overtime">Lembur</a>
                <a href="?page=leaves">Cuti</a>
                <a href="?page=reports">Laporan</a>
            </div>
        </div>
        <div class="panel insight-panel">
            <h2>Prioritas Hari Ini</h2>
            <p class="big-value"><?= (int) $stats['leaves'] + (int) $stats['overtime'] ?></p>
            <p class="muted">Pengajuan cuti dan lembur yang menunggu keputusan.</p>
        </div>
    </section>
    <section class="panel section-gap">
        <h2>Aktivitas Terbaru</h2>
        <?php table_attendance($attendancePage['rows']); ?>
        <?php pagination_controls($attendancePage); ?>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'attendance') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (input('latitude') === '' || input('longitude') === '') {
                throw new RuntimeException('Lokasi belum diambil.');
            }
            if (empty($_FILES['photo_file']['tmp_name']) && empty($_POST['photo'])) {
                throw new RuntimeException('Foto wajah belum diambil.');
            }

            $lat = (float) input('latitude');
            $lon = (float) input('longitude');
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                throw new RuntimeException('Koordinat lokasi tidak valid.');
            }
            $type = input('type');
            if (!in_array($type, ['check_in', 'check_out'], true)) {
                throw new RuntimeException('Jenis absensi tidak valid.');
            }

            $nearest = nearest_location((int) $user['company_id'], $lat, $lon);
            $isField = (int) $user['field_employee'] === 1;
            $locationStatus = 'field';
            $locationId = null;
            $distance = null;

            if (!$isField) {
                if (!$nearest) {
                    throw new RuntimeException('Lokasi kantor belum diatur.');
                }
                $distance = $nearest['distance_meters'];
                $locationId = (int) $nearest['id'];
                $locationStatus = $distance <= (int) $nearest['radius_meters'] ? 'inside_radius' : 'outside_radius';
                if ($locationStatus === 'outside_radius') {
                    throw new RuntimeException('Anda berada di luar radius lokasi absensi.');
                }
            } elseif ($nearest) {
                $distance = $nearest['distance_meters'];
                $locationId = (int) $nearest['id'];
            }

            $attendanceDate = date('Y-m-d');
            $attendanceTime = date('H:i:s');
            $schedule = attendance_schedule((int) $user['id'], (int) $user['company_id'], $attendanceDate);
            $lateMinutes = 0;
            $earlyLeaveMinutes = 0;
            if ($schedule) {
                if ($type === 'check_in') {
                    $lateMinutes = max(0, time_diff_minutes($schedule['start_time'], $attendanceTime));
                } else {
                    $earlyLeaveMinutes = max(0, time_diff_minutes($attendanceTime, $schedule['end_time']));
                }
            }

            $photoPath = !empty($_POST['photo'])
                ? save_photo((string) $_POST['photo'], (int) $user['id'])
                : save_uploaded_photo($_FILES['photo_file'], (int) $user['id']);
            $stmt = db()->prepare('INSERT INTO attendances (user_id, company_id, type, attendance_date, attendance_time, latitude, longitude, location_id, distance_meters, location_status, late_minutes, early_leave_minutes, photo_path, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $user['id'],
                $user['company_id'],
                $type,
                $attendanceDate,
                $attendanceTime,
                $lat,
                $lon,
                $locationId,
                $distance,
                $locationStatus,
                $lateMinutes,
                $earlyLeaveMinutes,
                $photoPath,
                input('notes'),
                now(),
            ]);
            flash('Absensi berhasil disimpan.');
            redirect('?page=attendance');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'danger');
        }
    }

    $today = db()->prepare('SELECT * FROM attendances WHERE user_id = ? AND attendance_date = ? ORDER BY created_at DESC');
    $today->execute([$user['id'], date('Y-m-d')]);
    $todayPage = paginate_array($today->fetchAll(), 'today');
    render_header('Absen', $user);
    ?>
    <h1>Absen Mobile</h1>
    <section class="attendance-grid">
        <form method="post" enctype="multipart/form-data" class="panel capture-form attendance-card">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="latitude" id="latitude" required>
            <input type="hidden" name="longitude" id="longitude" required>
            <input type="hidden" name="photo" id="photo">
            <canvas id="snapshot" hidden></canvas>
            <div class="attendance-head">
                <span class="eyebrow">Absensi Karyawan</span>
                <strong><?= h(date('d M Y')) ?></strong>
                <span><?= h(date('H:i')) ?> WIB</span>
            </div>
            <label class="photo-picker" for="photoFile">
                <input class="photo-source" type="file" id="photoFile" name="photo_file" accept="image/jpeg,image/png,image/webp,image/*" capture="user" required>
                <span class="photo-frame">
                    <img id="photoPreview" alt="" hidden>
                    <strong id="photoPrompt">Tap untuk ambil foto wajah</strong>
                    <small>Gunakan kamera depan HP</small>
                </span>
            </label>
            <div class="status-pills">
                <span id="photoState">Foto belum ada</span>
                <span id="locationState">Lokasi belum ada</span>
            </div>
            <div class="toolbar attendance-actions">
                <button type="button" class="btn" id="locateBtn">Ambil Lokasi</button>
            </div>
            <p class="muted" id="captureStatus">Izinkan kamera dan lokasi dari browser HP.</p>
            <label>Jenis Absen
                <select name="type">
                    <option value="check_in">Masuk</option>
                    <option value="check_out">Pulang</option>
                </select>
            </label>
            <label>Catatan lapangan <textarea name="notes" rows="3" placeholder="Opsional untuk karyawan lapangan"></textarea></label>
            <button class="btn primary">Kirim Absensi</button>
        </form>
        <section class="panel">
            <h2>Absen Hari Ini</h2>
            <?php table_attendance($todayPage['rows'], false); ?>
            <?php pagination_controls($todayPage); ?>
        </section>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'companies') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $code = strtoupper(input('code'));
            if (input('action') !== 'delete' && $code === '') {
                throw new RuntimeException('Kode perusahaan wajib diisi.');
            }

            if (input('action') === 'update') {
                $duplicate = db()->prepare('SELECT COUNT(*) FROM companies WHERE code = ? AND id != ?');
                $duplicate->execute([$code, (int) input('id')]);
                if ((int) $duplicate->fetchColumn() > 0) {
                    throw new RuntimeException('Kode perusahaan sudah digunakan.');
                }
                db()->prepare('UPDATE companies SET name = ?, code = ? WHERE id = ?')->execute([input('name'), $code, (int) input('id')]);
                flash('Anak perusahaan diperbarui.');
            } elseif (input('action') === 'delete') {
                db()->prepare('DELETE FROM companies WHERE id = ?')->execute([(int) input('id')]);
                flash('Anak perusahaan dihapus.');
            } else {
                $duplicate = db()->prepare('SELECT COUNT(*) FROM companies WHERE code = ?');
                $duplicate->execute([$code]);
                if ((int) $duplicate->fetchColumn() > 0) {
                    throw new RuntimeException('Kode perusahaan sudah digunakan.');
                }
                db()->prepare('INSERT INTO companies (name, code, created_at) VALUES (?, ?, ?)')->execute([input('name'), $code, now()]);
                seed_default_work_hours(db());
                seed_default_leave_policies(db());
                flash('Anak perusahaan ditambahkan.');
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'danger');
        }
        redirect('?page=companies');
    }
    $rows = db()->query('SELECT id, name, code, created_at FROM companies ORDER BY name')->fetchAll();
    render_header('Anak Perusahaan', $user);
    ?>
    <h1>Anak Perusahaan</h1>
    <section class="two-col">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <label>Nama <input name="name" required></label>
            <label>Kode <input name="code" required></label>
            <button class="btn primary">Simpan</button>
        </form>
        <section class="panel"><?php table_companies($rows); ?></section>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'employees') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (input('action') === 'update') {
            $params = [(int) input('company_id'), input('name'), input('email'), input('employee_code'), input('position'), isset($_POST['field_employee']) ? 1 : 0, (int) input('id')];
            db()->prepare('UPDATE users SET company_id = ?, name = ?, email = ?, employee_code = ?, position = ?, field_employee = ? WHERE id = ? AND role = "employee"')->execute($params);
            if (input('password') !== '') {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = "employee"')->execute([password_hash(input('password'), PASSWORD_DEFAULT), (int) input('id')]);
            }
            flash('Data karyawan diperbarui.');
        } elseif (input('action') === 'delete') {
            db()->prepare('UPDATE users SET active = 0 WHERE id = ? AND role = "employee"')->execute([(int) input('id')]);
            flash('Karyawan dinonaktifkan.');
        } else {
            db()->prepare('INSERT INTO users (company_id, name, email, password_hash, role, employee_code, position, field_employee, created_at) VALUES (?, ?, ?, ?, "employee", ?, ?, ?, ?)')
                ->execute([(int) input('company_id'), input('name'), input('email'), password_hash(input('password', 'password'), PASSWORD_DEFAULT), input('employee_code'), input('position'), isset($_POST['field_employee']) ? 1 : 0, now()]);
            flash('Karyawan ditambahkan.');
        }
        redirect('?page=employees');
    }
    $employees = db()->query("SELECT u.*, c.name AS company_name FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.role = 'employee' AND u.active = 1 ORDER BY u.name")->fetchAll();
    render_header('Karyawan', $user);
    ?>
    <h1>Karyawan</h1>
    <section class="two-col">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <label>Perusahaan <select name="company_id"><?php options_companies(); ?></select></label>
            <label>Nama <input name="name" required></label>
            <label>Email <input type="email" name="email" required></label>
            <label>Password awal <input name="password" value="password" required></label>
            <label>NIK/Kode <input name="employee_code" required></label>
            <label>Jabatan <input name="position"></label>
            <label class="check"><input type="checkbox" name="field_employee"> Karyawan lapangan</label>
            <button class="btn primary">Simpan</button>
        </form>
        <section class="panel"><?php table_employees($employees); ?></section>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'settings') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (input('action') === 'export_database') {
                export_database();
            }
            if (input('action') === 'import_database') {
                if (empty($_FILES['database_file']['tmp_name'])) {
                    throw new RuntimeException('Pilih file database backup terlebih dahulu.');
                }
                import_database($_FILES['database_file']['tmp_name']);
                flash('Database berhasil diimport.');
            }
            if (input('action') === 'clear_old_data') {
                $result = clear_old_data();
                flash(sprintf('Data lama berhasil dibersihkan: %d absensi, %d lembur, %d cuti, %d penugasan shift.', $result['attendances'], $result['overtime'], $result['leaves'], $result['shifts']));
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'danger');
        }
        redirect('?page=settings');
    }

    $threshold = date('Y-m-d', strtotime('-3 months'));
    $dbConfig = db_config();
    render_header('Pengaturan', $user);
    ?>
    <h1>Pengaturan</h1>
    <section class="settings-grid">
        <a class="setting-card" href="?page=locations"><strong>Lokasi Absensi</strong><span>Atur titik kantor, koordinat, radius, dan status lokasi.</span></a>
        <a class="setting-card" href="?page=work-hours"><strong>Jam Kerja</strong><span>Atur hari kerja aktif dan jam kerja per anak perusahaan.</span></a>
        <a class="setting-card" href="?page=shifts"><strong>Shift Kerja</strong><span>Atur shift dan penugasan shift karyawan per tanggal.</span></a>
        <a class="setting-card" href="?page=leave-policies"><strong>Aturan Cuti</strong><span>Atur batas Cuti Tahunan, Sakit, dan Izin per perusahaan.</span></a>
    </section>
    <section class="two-col section-gap">
        <div class="panel">
            <h2>Backup Database</h2>
            <p class="muted">Database aktif: <?= h($dbConfig['database']) ?> di <?= h($dbConfig['host']) ?>. Export menghasilkan file SQL.</p>
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="export_database">
                <button class="btn primary">Export Database</button>
            </form>
        </div>
        <form method="post" enctype="multipart/form-data" class="panel form">
            <h2>Import Database</h2>
            <p class="muted">Import menjalankan file backup SQL ke database aktif. Gunakan file dari export aplikasi ini atau phpMyAdmin.</p>
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="import_database">
            <label>File backup <input type="file" name="database_file" accept=".sql,.txt,application/sql,text/plain,application/octet-stream" required></label>
            <button class="btn">Import Database</button>
        </form>
    </section>
    <section class="panel section-gap">
        <h2>Bersihkan Data Lama</h2>
        <p class="muted">Menghapus data transaksi lebih lama dari <?= h($threshold) ?>: absensi, foto absensi, lembur, cuti, dan penugasan shift. Master perusahaan, karyawan, lokasi, jam kerja, shift, dan aturan cuti tidak dihapus.</p>
        <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="clear_old_data">
            <button class="btn danger">Clear Data Lebih dari 3 Bulan</button>
        </form>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'locations') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (input('action') === 'update') {
            db()->prepare('UPDATE locations SET company_id = ?, name = ?, latitude = ?, longitude = ?, radius_meters = ?, active = ? WHERE id = ?')
                ->execute([(int) input('company_id'), input('name'), (float) input('latitude'), (float) input('longitude'), (int) input('radius_meters', '150'), isset($_POST['active']) ? 1 : 0, (int) input('id')]);
            flash('Lokasi absensi diperbarui.');
        } elseif (input('action') === 'delete') {
            db()->prepare('DELETE FROM locations WHERE id = ?')->execute([(int) input('id')]);
            flash('Lokasi absensi dihapus.');
        } else {
            db()->prepare('INSERT INTO locations (company_id, name, latitude, longitude, radius_meters) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int) input('company_id'), input('name'), (float) input('latitude'), (float) input('longitude'), (int) input('radius_meters', '150')]);
            flash('Lokasi absensi ditambahkan.');
        }
        redirect('?page=locations');
    }
    $rows = db()->query('SELECT l.*, c.name AS company_name FROM locations l JOIN companies c ON c.id = l.company_id ORDER BY c.name, l.name')->fetchAll();
    render_header('Lokasi', $user);
    ?>
    <h1>Lokasi Absensi</h1>
    <section class="two-col">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <label>Perusahaan <select name="company_id"><?php options_companies(); ?></select></label>
            <label>Nama lokasi <input name="name" required></label>
            <label>Latitude <input name="latitude" required placeholder="-6.200000"></label>
            <label>Longitude <input name="longitude" required placeholder="106.816666"></label>
            <label>Radius meter <input type="number" name="radius_meters" value="150" min="10"></label>
            <button class="btn primary">Simpan</button>
        </form>
        <section class="panel"><?php table_locations($rows); ?></section>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'work-hours') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (input('action') === 'delete') {
            db()->prepare('DELETE FROM work_hours WHERE id = ?')->execute([(int) input('id')]);
            flash('Jam kerja dihapus.');
        } else {
            $active = isset($_POST['active']) ? 1 : 0;
            db()->prepare('INSERT INTO work_hours (company_id, day_of_week, start_time, end_time, active) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), active = VALUES(active)')
                ->execute([(int) input('company_id'), (int) input('day_of_week'), input('start_time'), input('end_time'), $active]);
            flash('Jam kerja diperbarui.');
        }
        redirect('?page=work-hours');
    }
    $rows = db()->query('SELECT w.*, c.name AS company_name FROM work_hours w JOIN companies c ON c.id = w.company_id ORDER BY c.name, w.day_of_week')->fetchAll();
    render_header('Jam Kerja', $user);
    ?>
    <h1>Manajemen Jam Kerja</h1>
    <section class="two-col">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <label>Perusahaan <select name="company_id"><?php options_companies(); ?></select></label>
            <label>Hari
                <select name="day_of_week">
                    <?php for ($day = 1; $day <= 6; $day++): ?>
                        <option value="<?= $day ?>"><?= h(day_name($day)) ?></option>
                    <?php endfor; ?>
                    <option value="0">Minggu</option>
                </select>
            </label>
            <label>Jam mulai <input type="time" name="start_time" value="08:00" required></label>
            <label>Jam selesai <input type="time" name="end_time" value="17:00" required></label>
            <label class="check"><input type="checkbox" name="active" checked> Hari kerja aktif</label>
            <button class="btn primary">Simpan</button>
        </form>
        <section class="panel"><?php table_work_hours($rows); ?></section>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'shifts') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (input('action') === 'assign') {
            db()->prepare('INSERT INTO employee_shifts (user_id, shift_id, work_date) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)')
                ->execute([(int) input('user_id'), (int) input('shift_id'), input('work_date')]);
            flash('Shift karyawan ditugaskan.');
        } elseif (input('action') === 'delete_assignment') {
            db()->prepare('DELETE FROM employee_shifts WHERE id = ?')->execute([(int) input('id')]);
            flash('Penugasan shift dihapus.');
        } elseif (input('action') === 'update') {
            db()->prepare('UPDATE shifts SET company_id = ?, name = ?, start_time = ?, end_time = ?, late_tolerance_minutes = ? WHERE id = ?')
                ->execute([(int) input('company_id'), input('name'), input('start_time'), input('end_time'), (int) input('late_tolerance_minutes', '10'), (int) input('id')]);
            flash('Shift diperbarui.');
        } elseif (input('action') === 'delete') {
            db()->prepare('DELETE FROM shifts WHERE id = ?')->execute([(int) input('id')]);
            flash('Shift dihapus.');
        } else {
            db()->prepare('INSERT INTO shifts (company_id, name, start_time, end_time, late_tolerance_minutes) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int) input('company_id'), input('name'), input('start_time'), input('end_time'), (int) input('late_tolerance_minutes', '10')]);
            flash('Shift disimpan.');
        }
        redirect('?page=shifts');
    }
    $rows = db()->query('SELECT s.*, c.name AS company_name FROM shifts s JOIN companies c ON c.id = s.company_id ORDER BY c.name, s.start_time')->fetchAll();
    $employees = db()->query("SELECT id, name, employee_code FROM users WHERE role = 'employee' AND active = 1 ORDER BY name")->fetchAll();
    $assignments = db()->query("SELECT es.id, es.work_date, u.name, u.employee_code, s.name AS shift_name, s.start_time, s.end_time FROM employee_shifts es JOIN users u ON u.id = es.user_id JOIN shifts s ON s.id = es.shift_id ORDER BY es.work_date DESC, u.name LIMIT 20")->fetchAll();
    render_header('Shift Kerja', $user);
    ?>
    <h1>Manajemen Shift Kerja</h1>
    <section class="two-col">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <label>Perusahaan <select name="company_id"><?php options_companies(); ?></select></label>
            <label>Nama shift <input name="name" required></label>
            <label>Jam masuk <input type="time" name="start_time" required></label>
            <label>Jam pulang <input type="time" name="end_time" required></label>
            <label>Toleransi terlambat menit <input type="number" name="late_tolerance_minutes" value="10" min="0"></label>
            <button class="btn primary">Simpan</button>
        </form>
        <section class="panel"><?php table_shifts($rows); ?></section>
    </section>
    <section class="two-col section-gap">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="assign">
            <label>Karyawan
                <select name="user_id">
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>"><?= h($employee['name'] . ' - ' . $employee['employee_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Shift
                <select name="shift_id">
                    <?php foreach ($rows as $shift): ?>
                        <option value="<?= (int) $shift['id'] ?>"><?= h($shift['company_name'] . ' / ' . $shift['name'] . ' (' . $shift['start_time'] . '-' . $shift['end_time'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Tanggal kerja <input type="date" name="work_date" value="<?= date('Y-m-d') ?>" required></label>
            <button class="btn primary">Tugaskan Shift</button>
        </form>
        <section class="panel">
            <h2>Penugasan Terbaru</h2>
            <?php table_shift_assignments($assignments); ?>
        </section>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'overtime' || $page === 'my-overtime') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'my-overtime') {
        $durationMinutes = overtime_minutes(input('start_time'), input('end_time'));
        if (input('action') === 'update') {
            db()->prepare("UPDATE overtime_requests SET overtime_date = ?, start_time = ?, end_time = ?, duration_minutes = ?, reason = ?, updated_at = ? WHERE id = ? AND user_id = ? AND status = 'pending'")
                ->execute([input('overtime_date'), input('start_time'), input('end_time'), $durationMinutes, input('reason'), now(), (int) input('id'), $user['id']]);
            flash('Pengajuan lembur diperbarui.');
        } elseif (input('action') === 'delete') {
            db()->prepare("DELETE FROM overtime_requests WHERE id = ? AND user_id = ? AND status = 'pending'")->execute([(int) input('id'), $user['id']]);
            flash('Pengajuan lembur dibatalkan.');
        } else {
            db()->prepare('INSERT INTO overtime_requests (user_id, company_id, overtime_date, start_time, end_time, duration_minutes, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user['id'], $user['company_id'], input('overtime_date'), input('start_time'), input('end_time'), $durationMinutes, input('reason'), now()]);
            flash('Pengajuan lembur terkirim.');
        }
        redirect('?page=my-overtime');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'overtime') {
        require_admin();
        if (input('action') === 'delete') {
            db()->prepare('DELETE FROM overtime_requests WHERE id = ?')->execute([(int) input('id')]);
            flash('Pengajuan lembur dihapus.');
        } else {
            $row = db()->prepare('SELECT start_time, end_time FROM overtime_requests WHERE id = ?');
            $row->execute([(int) input('id')]);
            $overtime = $row->fetch();
            $durationMinutes = $overtime ? overtime_minutes($overtime['start_time'], $overtime['end_time']) : 0;
            db()->prepare('UPDATE overtime_requests SET status = ?, duration_minutes = ?, admin_note = ?, updated_at = ? WHERE id = ?')
                ->execute([input('status'), input('status') === 'approved' ? $durationMinutes : 0, input('admin_note'), now(), (int) input('id')]);
            flash('Status lembur diperbarui.');
        }
        redirect('?page=overtime');
    }

    $isAdmin = $user['role'] === 'admin' && $page === 'overtime';
    if ($isAdmin && in_array(input('export'), ['excel', 'pdf'], true)) {
        $report = overtime_report_data(input('start'), input('end'), input('company_id'));
        if (input('export') === 'excel') {
            export_excel($report['title'], $report['headers'], $report['rows']);
        }
        export_pdf($report['title'], $report['headers'], $report['rows']);
    }
    $stmt = $isAdmin
        ? db()->query('SELECT o.*, u.name, u.employee_code, c.name AS company_name FROM overtime_requests o JOIN users u ON u.id = o.user_id JOIN companies c ON c.id = o.company_id ORDER BY o.created_at DESC')
        : db()->prepare('SELECT o.*, u.name, u.employee_code, c.name AS company_name FROM overtime_requests o JOIN users u ON u.id = o.user_id JOIN companies c ON c.id = o.company_id WHERE o.user_id = ? ORDER BY o.created_at DESC');
    if (!$isAdmin) {
        $stmt->execute([$user['id']]);
    }
    $overtimePage = paginate_array($stmt->fetchAll(), 'overtime');

    render_header($isAdmin ? 'Manajemen Lembur' : 'Lembur Saya', $user);
    ?>
    <h1><?= $isAdmin ? 'Manajemen Lembur' : 'Lembur Saya' ?></h1>
    <?php if ($isAdmin): ?>
        <form class="filters panel" method="get">
            <input type="hidden" name="page" value="overtime">
            <label>Perusahaan <select name="company_id"><option value="">Semua</option><?php options_companies(input('company_id')); ?></select></label>
            <label>Dari <input type="date" name="start" value="<?= h(input('start')) ?>"></label>
            <label>Sampai <input type="date" name="end" value="<?= h(input('end')) ?>"></label>
            <button class="btn">Filter</button>
            <button class="btn" name="export" value="excel">Excel</button>
            <button class="btn" name="export" value="pdf">PDF</button>
        </form>
        <?php $summary = overtime_summary(input('start'), input('end'), input('company_id')); ?>
        <section class="stats">
            <article><strong><?= (int) $summary['approved_count'] ?></strong><span>Lembur disetujui</span></article>
            <article><strong><?= h(format_minutes((int) $summary['approved_minutes'])) ?></strong><span>Total jam lembur</span></article>
        </section>
    <?php endif; ?>
    <?php if (!$isAdmin): ?>
        <form method="post" class="panel form compact">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <label>Tanggal <input type="date" name="overtime_date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Mulai <input type="time" name="start_time" required></label>
            <label>Selesai <input type="time" name="end_time" required></label>
            <label>Alasan <textarea name="reason" rows="2" required></textarea></label>
            <button class="btn primary">Ajukan</button>
        </form>
    <?php endif; ?>
    <section class="panel">
        <?php table_overtime($overtimePage['rows'], $isAdmin); ?>
        <?php pagination_controls($overtimePage); ?>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'leave-policies') {
    $user = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (input('action') === 'delete') {
            db()->prepare('DELETE FROM leave_policies WHERE id = ?')->execute([(int) input('id')]);
            flash('Aturan cuti dihapus.');
        } else {
            db()->prepare('INSERT INTO leave_policies (company_id, leave_type, annual_limit_days, active) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE annual_limit_days = VALUES(annual_limit_days), active = VALUES(active)')
                ->execute([(int) input('company_id'), input('leave_type'), (int) input('annual_limit_days'), isset($_POST['active']) ? 1 : 0]);
            flash('Aturan cuti disimpan.');
        }
        redirect('?page=leave-policies');
    }

    $rows = db()->query('SELECT p.*, c.name AS company_name FROM leave_policies p JOIN companies c ON c.id = p.company_id ORDER BY c.name, p.leave_type')->fetchAll();
    render_header('Aturan Cuti', $user);
    ?>
    <h1>Aturan Batas Cuti</h1>
    <section class="two-col">
        <form method="post" class="panel form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <label>Perusahaan <select name="company_id"><?php options_companies(); ?></select></label>
            <label>Jenis cuti
                <select name="leave_type">
                    <option>Cuti Tahunan</option>
                    <option>Sakit</option>
                    <option>Izin</option>
                </select>
            </label>
            <label>Batas hari per tahun <input type="number" name="annual_limit_days" value="12" min="0" required></label>
            <label class="check"><input type="checkbox" name="active" checked> Aktif</label>
            <button class="btn primary">Simpan Aturan</button>
        </form>
        <section class="panel"><?php table_leave_policies($rows); ?></section>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'leaves' || $page === 'my-leaves') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'my-leaves') {
        try {
            if (input('action') === 'update') {
                ensure_leave_quota($user, input('leave_type'), input('start_date'), input('end_date'), (int) input('id'));
                db()->prepare("UPDATE leave_requests SET leave_type = ?, start_date = ?, end_date = ?, reason = ?, updated_at = ? WHERE id = ? AND user_id = ? AND status = 'pending'")
                    ->execute([input('leave_type'), input('start_date'), input('end_date'), input('reason'), now(), (int) input('id'), $user['id']]);
                flash('Pengajuan cuti diperbarui.');
            } elseif (input('action') === 'delete') {
                db()->prepare("DELETE FROM leave_requests WHERE id = ? AND user_id = ? AND status = 'pending'")->execute([(int) input('id'), $user['id']]);
                flash('Pengajuan cuti dibatalkan.');
            } else {
                ensure_leave_quota($user, input('leave_type'), input('start_date'), input('end_date'));
                db()->prepare('INSERT INTO leave_requests (user_id, company_id, leave_type, start_date, end_date, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$user['id'], $user['company_id'], input('leave_type'), input('start_date'), input('end_date'), input('reason'), now()]);
                flash('Pengajuan cuti terkirim.');
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'danger');
        }
        redirect('?page=my-leaves');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'leaves') {
        require_admin();
        if (input('action') === 'delete') {
            db()->prepare('DELETE FROM leave_requests WHERE id = ?')->execute([(int) input('id')]);
            flash('Pengajuan cuti dihapus.');
        } else {
            db()->prepare('UPDATE leave_requests SET status = ?, admin_note = ?, updated_at = ? WHERE id = ?')
                ->execute([input('status'), input('admin_note'), now(), (int) input('id')]);
            flash('Status cuti diperbarui.');
        }
        redirect('?page=leaves');
    }
    $isAdmin = $user['role'] === 'admin' && $page === 'leaves';
    $stmt = $isAdmin
        ? db()->query('SELECT l.*, u.name, c.name AS company_name FROM leave_requests l JOIN users u ON u.id = l.user_id JOIN companies c ON c.id = l.company_id ORDER BY l.created_at DESC')
        : db()->prepare('SELECT l.*, u.name, c.name AS company_name FROM leave_requests l JOIN users u ON u.id = l.user_id JOIN companies c ON c.id = l.company_id WHERE l.user_id = ? ORDER BY l.created_at DESC');
    if (!$isAdmin) {
        $stmt->execute([$user['id']]);
    }
    $leavePage = paginate_array($stmt->fetchAll(), 'leave');
    render_header($isAdmin ? 'Pengajuan Cuti' : 'Cuti Saya', $user);
    ?>
    <h1><?= $isAdmin ? 'Pengajuan Cuti' : 'Cuti Saya' ?></h1>
    <?php if (!$isAdmin): ?>
        <?php table_leave_quota($user); ?>
        <form method="post" class="panel form compact">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <label>Jenis <select name="leave_type"><option>Cuti Tahunan</option><option>Sakit</option><option>Izin</option></select></label>
            <label>Mulai <input type="date" name="start_date" required></label>
            <label>Selesai <input type="date" name="end_date" required></label>
            <label>Alasan <textarea name="reason" required></textarea></label>
            <button class="btn primary">Ajukan</button>
        </form>
    <?php endif; ?>
    <section class="panel">
        <?php table_leaves($leavePage['rows'], $isAdmin); ?>
        <?php pagination_controls($leavePage); ?>
    </section>
    <?php render_footer(); exit;
}

if ($page === 'reports' || $page === 'my-history') {
    $reportType = $user['role'] === 'admin' ? input('report_type', 'attendance') : 'attendance';
    $report = report_data($reportType, $user, input('start'), input('end'), input('company_id'));

    if (input('export') === 'excel') {
        export_excel($report['title'], $report['headers'], $report['rows']);
    }
    if (input('export') === 'pdf') {
        export_pdf($report['title'], $report['headers'], $report['rows']);
    }
    $reportPage = paginate_array($report['rows'], 'report');

    render_header($user['role'] === 'admin' ? 'Laporan Rekap' : 'Riwayat', $user);
    ?>
    <h1><?= $user['role'] === 'admin' ? 'Laporan Rekap Absen' : 'Riwayat Absensi' ?></h1>
    <form class="filters panel" method="get">
        <input type="hidden" name="page" value="<?= $user['role'] === 'admin' ? 'reports' : 'my-history' ?>">
        <?php if ($user['role'] === 'admin'): ?>
            <label>Jenis laporan
                <select name="report_type">
                    <option value="attendance" <?= $reportType === 'attendance' ? 'selected' : '' ?>>Absensi</option>
                    <option value="overtime" <?= $reportType === 'overtime' ? 'selected' : '' ?>>Lembur</option>
                    <option value="leave" <?= $reportType === 'leave' ? 'selected' : '' ?>>Cuti</option>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?><label>Perusahaan <select name="company_id"><option value="">Semua</option><?php options_companies(input('company_id')); ?></select></label><?php endif; ?>
        <label>Dari <input type="date" name="start" value="<?= h(input('start')) ?>"></label>
        <label>Sampai <input type="date" name="end" value="<?= h(input('end')) ?>"></label>
        <button class="btn">Filter</button>
        <button class="btn" name="export" value="excel">Excel</button>
        <button class="btn" name="export" value="pdf">PDF</button>
    </form>
    <section class="panel">
        <?php table_report($report['headers'], $reportPage['rows']); ?>
        <?php pagination_controls($reportPage); ?>
    </section>
    <?php render_footer(); exit;
}

redirect($user['role'] === 'admin' ? '?page=dashboard' : '?page=attendance');

function options_companies(string $selected = ''): void
{
    foreach (companies() as $company) {
        $isSelected = (string) $company['id'] === $selected ? 'selected' : '';
        echo '<option value="' . (int) $company['id'] . '" ' . $isSelected . '>' . h($company['name']) . '</option>';
    }
}

function paginate_array(array $rows, string $key = 'data'): array
{
    $allowed = [10, 50, 100];
    $perPage = (int) ($_GET[$key . '_per_page'] ?? 10);
    if (!in_array($perPage, $allowed, true)) {
        $perPage = 10;
    }

    $total = count($rows);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min((int) ($_GET[$key . '_page'] ?? 1), $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'key' => $key,
        'rows' => array_slice($rows, $offset, $perPage),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to' => min($offset + $perPage, $total),
    ];
}

function pagination_controls(array $pagination): void
{
    $key = $pagination['key'];
    $query = $_GET;
    $query[$key . '_page'] = 1;

    echo '<div class="pagination">';
    echo '<span>Menampilkan ' . (int) $pagination['from'] . '-' . (int) $pagination['to'] . ' dari ' . (int) $pagination['total'] . ' data</span>';
    echo '<form method="get" class="pagination-form">';
    foreach ($_GET as $name => $value) {
        if ($name === $key . '_per_page' || $name === $key . '_page' || $name === 'export') {
            continue;
        }
        echo '<input type="hidden" name="' . h($name) . '" value="' . h((string) $value) . '">';
    }
    echo '<input type="hidden" name="' . h($key . '_page') . '" value="1">';
    echo '<label>Tampil <select name="' . h($key . '_per_page') . '" onchange="this.form.submit()">';
    foreach ([10, 50, 100] as $size) {
        $selected = (int) $pagination['per_page'] === $size ? 'selected' : '';
        echo '<option value="' . $size . '" ' . $selected . '>' . $size . '</option>';
    }
    echo '</select></label>';
    echo '</form>';

    if ($pagination['total_pages'] > 1) {
        $prevQuery = $_GET;
        $prevQuery[$key . '_page'] = max(1, (int) $pagination['page'] - 1);
        $prevQuery[$key . '_per_page'] = $pagination['per_page'];
        unset($prevQuery['export']);
        $nextQuery = $_GET;
        $nextQuery[$key . '_page'] = min((int) $pagination['total_pages'], (int) $pagination['page'] + 1);
        $nextQuery[$key . '_per_page'] = $pagination['per_page'];
        unset($nextQuery['export']);
        echo '<div class="pagination-buttons">';
        echo '<a class="btn small" href="?' . h(http_build_query($prevQuery)) . '">Sebelumnya</a>';
        echo '<span>Halaman ' . (int) $pagination['page'] . ' / ' . (int) $pagination['total_pages'] . '</span>';
        echo '<a class="btn small" href="?' . h(http_build_query($nextQuery)) . '">Berikutnya</a>';
        echo '</div>';
    }
    echo '</div>';
}

function simple_table(string $sql): void
{
    simple_rows(db()->query($sql)->fetchAll());
}

function table_companies(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada anak perusahaan.</p>';
        return;
    }
    echo '<table><thead><tr><th>Nama</th><th>Kode</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td data-label="Nama"><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><input name="name" value="' . h($row['name']) . '" required></td><td data-label="Kode"><input name="code" value="' . h($row['code']) . '" required></td><td data-label="Dibuat">' . h($row['created_at']) . '</td><td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function table_employees(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada karyawan aktif.</p>';
        return;
    }
    echo '<table><thead><tr><th>Nama</th><th>Perusahaan</th><th>Email</th><th>Kode</th><th>Jabatan</th><th>Tipe</th><th>Password</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><form method="post"><td data-label="Nama"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><input name="name" value="' . h($row['name']) . '" required></td><td data-label="Perusahaan"><select name="company_id">';
        foreach (companies() as $company) {
            $selected = (int) $company['id'] === (int) $row['company_id'] ? 'selected' : '';
            echo '<option value="' . (int) $company['id'] . '" ' . $selected . '>' . h($company['name']) . '</option>';
        }
        echo '</select></td><td data-label="Email"><input type="email" name="email" value="' . h($row['email']) . '" required></td><td data-label="Kode"><input name="employee_code" value="' . h($row['employee_code']) . '" required></td><td data-label="Jabatan"><input name="position" value="' . h($row['position']) . '"></td><td data-label="Tipe"><label class="check"><input type="checkbox" name="field_employee" ' . ((int) $row['field_employee'] === 1 ? 'checked' : '') . '> Lapangan</label></td><td data-label="Password"><input name="password" placeholder="Kosongkan bila tetap"></td><td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Nonaktifkan</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function simple_rows(array $rows, array $labels = []): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada data.</p>';
        return;
    }
    $keys = array_keys($labels ?: $rows[0]);
    echo '<table><thead><tr>';
    foreach ($keys as $key) {
        echo '<th>' . h($labels[$key] ?? ucwords(str_replace('_', ' ', $key))) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($keys as $key) {
            echo '<td data-label="' . h($labels[$key] ?? ucwords(str_replace('_', ' ', $key))) . '">' . h((string) ($row[$key] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function table_locations(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada lokasi.</p>';
        return;
    }
    echo '<table><thead><tr><th>Perusahaan</th><th>Lokasi</th><th>Latitude</th><th>Longitude</th><th>Radius</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><form method="post"><td data-label="Perusahaan"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><select name="company_id">';
        foreach (companies() as $company) {
            $selected = (int) $company['id'] === (int) $row['company_id'] ? 'selected' : '';
            echo '<option value="' . (int) $company['id'] . '" ' . $selected . '>' . h($company['name']) . '</option>';
        }
        echo '</select></td><td data-label="Lokasi"><input name="name" value="' . h($row['name']) . '" required></td><td data-label="Latitude"><input name="latitude" value="' . h((string) $row['latitude']) . '" required></td><td data-label="Longitude"><input name="longitude" value="' . h((string) $row['longitude']) . '" required></td><td data-label="Radius"><input type="number" name="radius_meters" value="' . (int) $row['radius_meters'] . '" min="10"></td><td data-label="Status"><label class="check"><input type="checkbox" name="active" ' . ((int) $row['active'] === 1 ? 'checked' : '') . '> Aktif</label></td><td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function table_work_hours(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada jam kerja.</p>';
        return;
    }
    echo '<table><thead><tr><th>Perusahaan</th><th>Hari</th><th>Jam</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td data-label="Perusahaan">' . h($row['company_name']) . '</td><td data-label="Hari">' . h(day_name((int) $row['day_of_week'])) . '</td><td data-label="Jam">' . h($row['start_time'] . ' - ' . $row['end_time']) . '</td><td data-label="Status">' . h((int) $row['active'] === 1 ? 'Aktif' : 'Libur') . '</td><td data-label="Aksi"><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function table_shifts(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada shift.</p>';
        return;
    }
    echo '<table><thead><tr><th>Perusahaan</th><th>Shift</th><th>Masuk</th><th>Pulang</th><th>Toleransi</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><form method="post"><td data-label="Perusahaan"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><select name="company_id">';
        foreach (companies() as $company) {
            $selected = (int) $company['id'] === (int) $row['company_id'] ? 'selected' : '';
            echo '<option value="' . (int) $company['id'] . '" ' . $selected . '>' . h($company['name']) . '</option>';
        }
        echo '</select></td><td data-label="Shift"><input name="name" value="' . h($row['name']) . '" required></td><td data-label="Masuk"><input type="time" name="start_time" value="' . h($row['start_time']) . '" required></td><td data-label="Pulang"><input type="time" name="end_time" value="' . h($row['end_time']) . '" required></td><td data-label="Toleransi"><input type="number" name="late_tolerance_minutes" value="' . (int) $row['late_tolerance_minutes'] . '" min="0"></td><td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function table_shift_assignments(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada penugasan shift.</p>';
        return;
    }
    echo '<table><thead><tr><th>Tanggal</th><th>Karyawan</th><th>Kode</th><th>Shift</th><th>Jam</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td data-label="Tanggal">' . h($row['work_date']) . '</td><td data-label="Karyawan">' . h($row['name']) . '</td><td data-label="Kode">' . h($row['employee_code']) . '</td><td data-label="Shift">' . h($row['shift_name']) . '</td><td data-label="Jam">' . h($row['start_time'] . ' - ' . $row['end_time']) . '</td><td data-label="Aksi"><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete_assignment"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function table_attendance(array $rows, bool $showEmployee = true): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada absensi.</p>';
        return;
    }
    $viewer = current_user();
    $showTracking = $viewer && $viewer['role'] === 'admin';
    echo '<table><thead><tr>';
    if ($showEmployee) {
        echo '<th>Karyawan</th><th>Perusahaan</th>';
    }
    echo '<th>Tanggal</th><th>Jam</th><th>Jenis</th><th>Keterangan Jam</th><th>Status Lokasi</th><th>Jarak</th><th>Foto</th>';
    if ($showTracking) {
        echo '<th>Tracking</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        if ($showEmployee) {
            echo '<td data-label="Karyawan">' . h($row['name'] ?? '') . '</td><td data-label="Perusahaan">' . h($row['company_name'] ?? '') . '</td>';
        }
        $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string) $row['latitude'] . ',' . (string) $row['longitude']);
        echo '<td data-label="Tanggal">' . h($row['attendance_date']) . '</td><td data-label="Jam">' . h($row['attendance_time']) . '</td><td data-label="Jenis">' . h($row['type'] === 'check_in' ? 'Masuk' : 'Pulang') . '</td><td data-label="Keterangan Jam">' . h(attendance_status_note($row)) . '</td><td data-label="Status Lokasi">' . h($row['location_status']) . '</td><td data-label="Jarak">' . h($row['distance_meters'] === null ? '-' : number_format((float) $row['distance_meters'], 0) . ' m') . '</td><td data-label="Foto"><a href="?page=photo&id=' . (int) $row['id'] . '" target="_blank">Lihat</a></td>';
        if ($showTracking) {
            echo '<td data-label="Tracking"><a class="btn small" href="' . h($mapUrl) . '" target="_blank">Tracking</a></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function table_overtime(array $rows, bool $admin): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada pengajuan lembur.</p>';
        return;
    }
    echo '<table><thead><tr><th>Karyawan</th><th>Perusahaan</th><th>Tanggal</th><th>Jam</th><th>Durasi</th><th>Status</th><th>Alasan</th>';
    echo '<th>Aksi</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $minutes = (int) ($row['duration_minutes'] ?? 0);
        $duration = $minutes > 0 ? format_minutes($minutes) : overtime_duration($row['start_time'], $row['end_time']);
        echo '<tr><td data-label="Karyawan">' . h($row['name']) . '</td><td data-label="Perusahaan">' . h($row['company_name']) . '</td><td data-label="Tanggal">';
        if (!$admin && $row['status'] === 'pending') {
            echo '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><input type="date" name="overtime_date" value="' . h($row['overtime_date']) . '" required>';
        } else {
            echo h($row['overtime_date']);
        }
        echo '</td><td data-label="Jam">';
        if (!$admin && $row['status'] === 'pending') {
            echo '<input type="time" name="start_time" value="' . h($row['start_time']) . '" required><input type="time" name="end_time" value="' . h($row['end_time']) . '" required>';
        } else {
            echo h($row['start_time'] . ' - ' . $row['end_time']);
        }
        echo '</td><td data-label="Durasi">' . h($duration) . '</td><td data-label="Status"><span class="badge">' . h($row['status']) . '</span></td><td data-label="Alasan">';
        if (!$admin && $row['status'] === 'pending') {
            echo '<input name="reason" value="' . h($row['reason']) . '" required>';
        } else {
            echo h($row['reason']);
        }
        echo '</td>';
        if ($admin) {
            echo '<td data-label="Aksi"><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><select name="status"><option value="approved">Setujui</option><option value="rejected">Tolak</option><option value="pending">Pending</option></select><input name="admin_note" placeholder="Catatan"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td>';
        } elseif ($row['status'] === 'pending') {
            echo '<td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Batalkan</button></form></td>';
        } else {
            echo '<td data-label="Aksi">-</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function table_leave_policies(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada aturan cuti.</p>';
        return;
    }
    echo '<table><thead><tr><th>Perusahaan</th><th>Jenis</th><th>Batas Hari/Tahun</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><form method="post"><td data-label="Perusahaan"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="save"><select name="company_id">';
        foreach (companies() as $company) {
            $selected = (int) $company['id'] === (int) $row['company_id'] ? 'selected' : '';
            echo '<option value="' . (int) $company['id'] . '" ' . $selected . '>' . h($company['name']) . '</option>';
        }
        echo '</select></td><td data-label="Jenis"><select name="leave_type">';
        foreach (['Cuti Tahunan', 'Sakit', 'Izin'] as $type) {
            $selected = $type === $row['leave_type'] ? 'selected' : '';
            echo '<option ' . $selected . '>' . h($type) . '</option>';
        }
        echo '</select></td><td data-label="Batas"><input type="number" name="annual_limit_days" value="' . (int) $row['annual_limit_days'] . '" min="0"></td><td data-label="Status"><label class="check"><input type="checkbox" name="active" ' . ((int) $row['active'] === 1 ? 'checked' : '') . '> Aktif</label></td><td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function table_leave_quota(array $user): void
{
    $year = (int) date('Y');
    $stmt = db()->prepare('SELECT * FROM leave_policies WHERE company_id = ? AND active = 1 ORDER BY leave_type');
    $stmt->execute([$user['company_id']]);
    $policies = $stmt->fetchAll();
    if (!$policies) {
        echo '<section class="panel section-gap"><p class="muted">Kuota cuti belum diatur admin.</p></section>';
        return;
    }

    echo '<section class="stats quota-grid">';
    foreach ($policies as $policy) {
        $used = leave_used_days((int) $user['id'], $policy['leave_type'], $year);
        $limit = (int) $policy['annual_limit_days'];
        $remaining = max(0, $limit - $used);
        echo '<article><strong>' . $remaining . '</strong><span>' . h($policy['leave_type']) . ' tersisa dari ' . $limit . ' hari</span></article>';
    }
    echo '</section>';
}

function table_leaves(array $rows, bool $admin): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada pengajuan cuti.</p>';
        return;
    }
    echo '<table><thead><tr><th>Karyawan</th><th>Perusahaan</th><th>Jenis</th><th>Periode</th><th>Status</th><th>Alasan</th>';
    echo '<th>Aksi</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td data-label="Karyawan">' . h($row['name']) . '</td><td data-label="Perusahaan">' . h($row['company_name']) . '</td><td data-label="Jenis">';
        if (!$admin && $row['status'] === 'pending') {
            echo '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><select name="leave_type">';
            foreach (['Cuti Tahunan', 'Sakit', 'Izin'] as $type) {
                $selected = $type === $row['leave_type'] ? 'selected' : '';
                echo '<option ' . $selected . '>' . h($type) . '</option>';
            }
            echo '</select>';
        } else {
            echo h($row['leave_type']);
        }
        echo '</td><td data-label="Periode">';
        if (!$admin && $row['status'] === 'pending') {
            echo '<input type="date" name="start_date" value="' . h($row['start_date']) . '" required><input type="date" name="end_date" value="' . h($row['end_date']) . '" required>';
        } else {
            echo h($row['start_date'] . ' s/d ' . $row['end_date']);
        }
        echo '</td><td data-label="Status"><span class="badge">' . h($row['status']) . '</span></td><td data-label="Alasan">';
        if (!$admin && $row['status'] === 'pending') {
            echo '<input name="reason" value="' . h($row['reason']) . '" required>';
        } else {
            echo h($row['reason']);
        }
        echo '</td>';
        if ($admin) {
            echo '<td data-label="Aksi"><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><select name="status"><option value="approved">Setujui</option><option value="rejected">Tolak</option><option value="pending">Pending</option></select><input name="admin_note" placeholder="Catatan"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Hapus</button></form></td>';
        } elseif ($row['status'] === 'pending') {
            echo '<td data-label="Aksi"><button class="btn small">Simpan</button></form><form method="post" class="inline"><input type="hidden" name="csrf" value="' . csrf_token() . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn small danger">Batalkan</button></form></td>';
        } else {
            echo '<td data-label="Aksi">-</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function overtime_duration(string $start, string $end): string
{
    return format_minutes(overtime_minutes($start, $end));
}

function overtime_minutes(string $start, string $end): int
{
    $startTime = strtotime('2000-01-01 ' . $start);
    $endTime = strtotime('2000-01-01 ' . $end);
    if ($endTime <= $startTime) {
        $endTime += 86400;
    }
    return max(0, (int) (($endTime - $startTime) / 60));
}

function format_minutes(int $minutes): string
{
    return sprintf('%d jam %d menit', intdiv($minutes, 60), $minutes % 60);
}

function attendance_schedule(int $userId, int $companyId, string $date): ?array
{
    $shift = db()->prepare('SELECT s.start_time, s.end_time, s.name FROM employee_shifts es JOIN shifts s ON s.id = es.shift_id WHERE es.user_id = ? AND es.work_date = ?');
    $shift->execute([$userId, $date]);
    $schedule = $shift->fetch();
    if ($schedule) {
        return $schedule;
    }

    $day = (int) date('w', strtotime($date));
    $workHour = db()->prepare('SELECT start_time, end_time, "Jam Kerja" AS name FROM work_hours WHERE company_id = ? AND day_of_week = ? AND active = 1');
    $workHour->execute([$companyId, $day]);
    $schedule = $workHour->fetch();

    return $schedule ?: null;
}

function time_diff_minutes(string $fromTime, string $toTime): int
{
    $from = strtotime('2000-01-01 ' . $fromTime);
    $to = strtotime('2000-01-01 ' . $toTime);

    return (int) floor(($to - $from) / 60);
}

function attendance_status_note(array $row): string
{
    $late = (int) ($row['late_minutes'] ?? 0);
    $early = (int) ($row['early_leave_minutes'] ?? 0);
    if ($late > 0) {
        return 'Telat ' . format_minutes($late);
    }
    if ($early > 0) {
        return 'Pulang cepat ' . format_minutes($early);
    }

    return 'Tepat waktu';
}

function overtime_summary(string $start = '', string $end = '', string $companyId = ''): array
{
    $where = ["o.status = 'approved'"];
    $params = [];
    if ($start !== '') {
        $where[] = 'o.overtime_date >= ?';
        $params[] = $start;
    }
    if ($end !== '') {
        $where[] = 'o.overtime_date <= ?';
        $params[] = $end;
    }
    if ($companyId !== '') {
        $where[] = 'o.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = db()->prepare('SELECT COUNT(*) AS approved_count, COALESCE(SUM(o.duration_minutes), 0) AS approved_minutes FROM overtime_requests o WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $summary = $stmt->fetch();

    return $summary ?: ['approved_count' => 0, 'approved_minutes' => 0];
}

function overtime_report_data(string $start = '', string $end = '', string $companyId = ''): array
{
    $where = [];
    $params = [];
    if ($start !== '') {
        $where[] = 'o.overtime_date >= ?';
        $params[] = $start;
    }
    if ($end !== '') {
        $where[] = 'o.overtime_date <= ?';
        $params[] = $end;
    }
    if ($companyId !== '') {
        $where[] = 'o.company_id = ?';
        $params[] = $companyId;
    }
    $sql = 'SELECT u.name, u.employee_code, c.name AS company_name, o.overtime_date, o.start_time, o.end_time, o.duration_minutes, o.status, o.reason FROM overtime_requests o JOIN users u ON u.id = o.user_id JOIN companies c ON c.id = o.company_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.overtime_date DESC, u.name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return [
        'title' => 'Laporan Lembur',
        'headers' => ['Karyawan', 'Kode', 'Perusahaan', 'Tanggal', 'Mulai', 'Selesai', 'Durasi', 'Status', 'Alasan'],
        'rows' => array_map(fn ($row) => [$row['name'], $row['employee_code'], $row['company_name'], $row['overtime_date'], $row['start_time'], $row['end_time'], format_minutes((int) $row['duration_minutes']), $row['status'], $row['reason']], $stmt->fetchAll()),
    ];
}

function report_data(string $type, array $user, string $start = '', string $end = '', string $companyId = ''): array
{
    if (!in_array($type, ['attendance', 'overtime', 'leave'], true)) {
        $type = 'attendance';
    }

    if ($type === 'overtime') {
        return overtime_report_data($start, $end, $companyId);
    }

    if ($type === 'leave') {
        $where = [];
        $params = [];
        if ($start !== '') {
            $where[] = 'l.start_date >= ?';
            $params[] = $start;
        }
        if ($end !== '') {
            $where[] = 'l.end_date <= ?';
            $params[] = $end;
        }
        if ($companyId !== '') {
            $where[] = 'l.company_id = ?';
            $params[] = $companyId;
        }
        $sql = 'SELECT u.name, u.employee_code, c.name AS company_name, l.leave_type, l.start_date, l.end_date, l.status, l.reason FROM leave_requests l JOIN users u ON u.id = l.user_id JOIN companies c ON c.id = l.company_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.start_date DESC, u.name';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return [
            'title' => 'Laporan Cuti',
            'headers' => ['Karyawan', 'Kode', 'Perusahaan', 'Jenis', 'Mulai', 'Selesai', 'Status', 'Alasan'],
            'rows' => array_map(fn ($row) => [$row['name'], $row['employee_code'], $row['company_name'], $row['leave_type'], $row['start_date'], $row['end_date'], $row['status'], $row['reason']], $stmt->fetchAll()),
        ];
    }

    $where = [];
    $params = [];
    if ($user['role'] !== 'admin') {
        $where[] = 'a.user_id = ?';
        $params[] = $user['id'];
    }
    if ($start !== '') {
        $where[] = 'a.attendance_date >= ?';
        $params[] = $start;
    }
    if ($end !== '') {
        $where[] = 'a.attendance_date <= ?';
        $params[] = $end;
    }
    if ($companyId !== '') {
        $where[] = 'a.company_id = ?';
        $params[] = $companyId;
    }
    $sql = 'SELECT a.*, u.name, u.employee_code, c.name AS company_name FROM attendances a JOIN users u ON u.id = a.user_id JOIN companies c ON c.id = a.company_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY a.attendance_date DESC, a.attendance_time DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return [
        'title' => 'Laporan Absensi',
        'headers' => ['Karyawan', 'Kode', 'Perusahaan', 'Tanggal', 'Jam', 'Jenis', 'Keterangan Jam', 'Telat Menit', 'Pulang Cepat Menit', 'Status Lokasi', 'Jarak Meter', 'Catatan'],
        'rows' => array_map(fn ($row) => [$row['name'], $row['employee_code'], $row['company_name'], $row['attendance_date'], $row['attendance_time'], $row['type'] === 'check_in' ? 'Masuk' : 'Pulang', attendance_status_note($row), (int) ($row['late_minutes'] ?? 0), (int) ($row['early_leave_minutes'] ?? 0), $row['location_status'], $row['distance_meters'] === null ? '-' : number_format((float) $row['distance_meters'], 0), $row['notes']], $stmt->fetchAll()),
    ];
}

function table_report(array $headers, array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Belum ada data laporan.</p>';
        return;
    }
    echo '<table><thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . h($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $index => $header) {
            echo '<td data-label="' . h($header) . '">' . h((string) ($row[$index] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function export_database(): never
{
    $filename = 'backup-absensi-mgi-' . date('Ymd-His') . '.sql';
    $dump = mysql_dump();
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dump));
    echo $dump;
    exit;
}

function import_database(string $uploadedPath): void
{
    $backupDir = BACKUP_DIR;
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }
    file_put_contents($backupDir . '/before-import-' . date('Ymd-His') . '.sql', mysql_dump());

    $sql = file_get_contents($uploadedPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('File backup kosong atau tidak bisa dibaca.');
    }
    foreach (app_tables() as $table) {
        if (stripos($sql, $table) === false) {
            throw new RuntimeException('File backup tidak valid. Tabel ' . $table . ' tidak ditemukan.');
        }
    }

    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (split_sql_statements($sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function clear_old_data(): array
{
    $threshold = date('Y-m-d', strtotime('-3 months'));
    $backupDir = BACKUP_DIR;
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }
    file_put_contents($backupDir . '/before-clear-' . date('Ymd-His') . '.sql', mysql_dump());

    $pdo = db();
    $photos = $pdo->prepare('SELECT photo_path FROM attendances WHERE attendance_date < ?');
    $photos->execute([$threshold]);
    $photoFiles = $photos->fetchAll(PDO::FETCH_COLUMN);

    $counts = [];
    $deleteAttendance = $pdo->prepare('DELETE FROM attendances WHERE attendance_date < ?');
    $deleteAttendance->execute([$threshold]);
    $counts['attendances'] = $deleteAttendance->rowCount();

    foreach ($photoFiles as $photo) {
        $file = PHOTO_DIR . '/' . basename((string) $photo);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    $deleteOvertime = $pdo->prepare('DELETE FROM overtime_requests WHERE overtime_date < ?');
    $deleteOvertime->execute([$threshold]);
    $counts['overtime'] = $deleteOvertime->rowCount();

    $deleteLeaves = $pdo->prepare('DELETE FROM leave_requests WHERE end_date < ?');
    $deleteLeaves->execute([$threshold]);
    $counts['leaves'] = $deleteLeaves->rowCount();

    $deleteShifts = $pdo->prepare('DELETE FROM employee_shifts WHERE work_date < ?');
    $deleteShifts->execute([$threshold]);
    $counts['shifts'] = $deleteShifts->rowCount();

    return $counts;
}

function app_tables(): array
{
    return ['companies', 'users', 'locations', 'shifts', 'work_hours', 'employee_shifts', 'attendances', 'leave_requests', 'overtime_requests', 'leave_policies'];
}

function mysql_dump(): string
{
    $pdo = db();
    $dump = "-- Backup " . APP_NAME . "\n";
    $dump .= "-- Generated at " . now() . "\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach (array_reverse(app_tables()) as $table) {
        $dump .= "DROP TABLE IF EXISTS `" . $table . "`;\n";
    }
    $dump .= "\n";

    foreach (app_tables() as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        $dump .= $create['Create Table'] . ";\n\n";

        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
        if (!$rows) {
            continue;
        }
        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(fn ($column) => '`' . str_replace('`', '``', $column) . '`', $columns));
        foreach ($rows as $row) {
            $values = array_map(fn ($column) => $row[$column] === null ? 'NULL' : $pdo->quote((string) $row[$column]), $columns);
            $dump .= 'INSERT INTO `' . $table . '` (' . $columnList . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        $dump .= "\n";
    }

    $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $dump;
}

function split_sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($quote === null && $char === '-' && $next === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($quote === null && $char === '/' && $next === '*') {
            $i += 2;
            while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i++;
            continue;
        }

        if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
            $quote = $quote === $char ? null : ($quote ?? $char);
        }

        if ($char === ';' && $quote === null) {
            $statements[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

function export_excel(string $title, array $headers, array $rows): never
{
    $filename = strtolower(str_replace(' ', '-', $title)) . '-' . date('Ymd-His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "<!doctype html><html><head><meta charset=\"utf-8\">";
    echo "<style>
        body { font-family: Arial, sans-serif; color: #172033; }
        table { border-collapse: collapse; width: 100%; }
        .title { font-size: 18px; font-weight: bold; background: #126b61; color: #ffffff; }
        .meta { color: #475467; background: #eef2f7; }
        th { background: #d9e8e5; color: #172033; font-weight: bold; text-align: center; }
        th, td { border: 1px solid #9aa7b8; padding: 8px; vertical-align: top; mso-number-format:'\\@'; }
        tr.alt td { background: #f7fafc; }
    </style></head><body>";
    $colspan = max(1, count($headers));
    echo '<table>';
    echo '<tr><td class="title" colspan="' . $colspan . '">' . h($title) . '</td></tr>';
    echo '<tr><td class="meta" colspan="' . $colspan . '">Dicetak: ' . h(now()) . ' | Total data: ' . count($rows) . '</td></tr>';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . h($header) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $index => $row) {
        echo '<tr class="' . ($index % 2 === 1 ? 'alt' : '') . '">';
        foreach ($headers as $cellIndex => $header) {
            echo '<td>' . h((string) ($row[$cellIndex] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="' . $colspan . '">Belum ada data.</td></tr>';
    }
    echo '</table></body></html>';
    exit;
}

function export_pdf(string $title, array $headers, array $rows): never
{
    $pdf = table_pdf($title, $headers, $rows);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '-', $title)) . '-' . date('Ymd-His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function table_pdf(string $title, array $headers, array $rows): string
{
    $pageWidth = 842;
    $pageHeight = 595;
    $margin = 28;
    $tableWidth = $pageWidth - ($margin * 2);
    $columnCount = max(1, count($headers));
    $columnWidth = $tableWidth / $columnCount;
    $rowHeight = 19;
    $headerHeight = 22;
    $firstRowY = 505;
    $rowsPerPage = 23;
    $chunks = array_chunk($rows ?: [[]], $rowsPerPage);
    $pageContents = [];

    foreach ($chunks as $pageIndex => $chunk) {
        $content = '';
        $content .= pdf_text($margin, 562, $title, 15, true);
        $content .= pdf_text($margin, 543, 'Dicetak: ' . now() . ' | Total data: ' . count($rows), 8);
        $content .= pdf_text($pageWidth - 115, 543, 'Halaman ' . ($pageIndex + 1) . ' / ' . count($chunks), 8);

        $x = $margin;
        foreach ($headers as $header) {
            $content .= "0.85 0.91 0.89 rg\n";
            $content .= sprintf('%.2F %.2F %.2F %.2F re f', $x, $firstRowY + 2, $columnWidth, $headerHeight) . "\n";
            $content .= "0 0 0 RG\n";
            $content .= sprintf('%.2F %.2F %.2F %.2F re S', $x, $firstRowY + 2, $columnWidth, $headerHeight) . "\n";
            $content .= pdf_text($x + 3, $firstRowY + 11, fit_pdf_text((string) $header, $columnWidth, 7), 7, true);
            $x += $columnWidth;
        }

        $y = $firstRowY - $rowHeight + 2;
        foreach ($chunk as $rowIndex => $row) {
            $x = $margin;
            $fill = $rowIndex % 2 === 1 ? '0.97 0.98 0.99' : '1 1 1';
            foreach ($headers as $cellIndex => $header) {
                $content .= $fill . " rg\n";
                $content .= sprintf('%.2F %.2F %.2F %.2F re f', $x, $y, $columnWidth, $rowHeight) . "\n";
                $content .= "0.45 0.50 0.56 RG\n";
                $content .= sprintf('%.2F %.2F %.2F %.2F re S', $x, $y, $columnWidth, $rowHeight) . "\n";
                $content .= pdf_text($x + 3, $y + 7, fit_pdf_text((string) ($row[$cellIndex] ?? ''), $columnWidth, 6.5), 6.5);
                $x += $columnWidth;
            }
            $y -= $rowHeight;
        }

        if (!$rows) {
            $content .= pdf_text($margin + 3, $firstRowY - 12, 'Belum ada data.', 8);
        }
        $pageContents[] = $content;
    }

    return build_pdf($pageContents, $pageWidth, $pageHeight);
}

function fit_pdf_text(string $text, float $width, float $fontSize): string
{
    $text = preg_replace('/\s+/', ' ', trim($text));
    $maxChars = max(4, (int) floor($width / ($fontSize * 0.48)));
    if (strlen($text) <= $maxChars) {
        return $text;
    }

    return substr($text, 0, max(1, $maxChars - 3)) . '...';
}

function pdf_text(float $x, float $y, string $text, float $size = 8, bool $bold = false): string
{
    $font = $bold ? 'F2' : 'F1';
    $safe = str_replace(["\\", '(', ')'], ["\\\\", '\\(', '\\)'], $text);
    return "0 0 0 rg\nBT /" . $font . ' ' . $size . ' Tf ' . sprintf('%.2F %.2F', $x, $y) . ' Td (' . $safe . ") Tj ET\n";
}

function build_pdf(array $pageContents, int $pageWidth, int $pageHeight): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];

    $kids = [];
    $objectId = 5;
    foreach ($pageContents as $content) {
        $pageId = $objectId++;
        $contentId = $objectId++;
        $kids[] = $pageId . ' 0 R';
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
