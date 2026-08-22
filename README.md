# SDM Perusahaan

Aplikasi SDM berbasis PHP untuk multi anak perusahaan, absensi foto wajah + lokasi, karyawan lapangan, dashboard admin, laporan rekap, lembur, cuti, jam kerja, shift, dan ekspor laporan.

## Fitur

- Login admin dan karyawan.
- Multi anak perusahaan.
- Master karyawan dengan penanda karyawan kantor atau lapangan.
- Master lokasi absensi dengan radius koordinat.
- Manajemen jam kerja per hari dan perusahaan.
- Manajemen shift kerja dan penugasan shift karyawan per tanggal.
- CRUD admin untuk anak perusahaan, karyawan, lokasi, jam kerja, shift, penugasan shift, lembur, cuti, dan aturan cuti.
- Menu Pengaturan untuk lokasi absensi, jam kerja, shift kerja, aturan cuti, backup/restore database, dan pembersihan data lama.
- Absensi mobile dengan kamera selfie, geolocation, tipe masuk/pulang, dan catatan lapangan.
- Keterangan telat otomatis untuk absen masuk dan pulang cepat untuk absen pulang berdasarkan shift/jam kerja.
- Validasi radius lokasi untuk karyawan kantor.
- Karyawan lapangan boleh absen dari lokasi kerja aktual dengan catatan dan foto.
- Manajemen lembur karyawan dengan approval admin.
- Laporan lembur di menu Lembur dengan export PDF/Excel dan total jam lembur disetujui.
- Durasi lembur dihitung otomatis saat pengajuan dibuat/diubah dan dikunci saat disetujui admin.
- Pengajuan cuti karyawan dan persetujuan admin.
- Batas cuti tahunan, sakit, dan izin per perusahaan diatur oleh admin.
- Karyawan bisa mengubah atau membatalkan pengajuan cuti/lembur selama status masih pending.
- Dashboard admin dan laporan rekap absensi/lembur/cuti.
- Download laporan dalam format PDF dan Excel-compatible `.xls`.
- Tombol tracking lokasi absensi untuk membuka koordinat absen di Google Maps.
- Export/import database MySQL dalam format `.sql` untuk backup admin.
- Clear data transaksi lebih lama dari 3 bulan dengan backup otomatis sebelum pembersihan.

## Konfigurasi Database MySQL

Salin `config.example.php` menjadi `config.php`, lalu isi sesuai database MySQL/MariaDB dari hosting.

```php
<?php
return [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'nama_database',
    'username' => 'username_database',
    'password' => 'password_database',
    'charset' => 'utf8mb4',
];
```

Di Hostinger, nilai database, username, dan password bisa dibuat dari menu MySQL Databases.

Untuk upload ke Hostinger:

- Upload semua file aplikasi kecuali folder `tools`.
- Upload `config.php` yang sudah berisi kredensial database hosting.
- Arahkan document root domain/subdomain ke folder `public`.
- Pastikan folder `storage/photos`, `storage/tmp`, dan `storage/data/backups` writable.
- Aktifkan PHP 8.x dan ekstensi `pdo_mysql`.
- Buka domain sekali; tabel MySQL akan dibuat otomatis jika user database punya izin `CREATE TABLE`.

## Menjalankan Lokal

Pastikan PHP dengan ekstensi `pdo_mysql` aktif dan database MySQL/MariaDB sudah dibuat.

```bash
php -S localhost:8000 -t public
```

Bila memakai PHP portable yang dipasang di folder proyek ini:

```powershell
.\tools\php\php.exe -S localhost:8000 -t public
```

Buka `http://localhost:8000`.

## Akun Demo

- Admin: `admin@mgi.test` / `password`
- Karyawan lapangan: `lapangan@mgi.test` / `password`

## Catatan Implementasi

Tabel database MySQL dibuat otomatis saat aplikasi pertama kali dibuka. Foto absensi disimpan di `storage/photos`.

Pengembangan fitur baru memakai migrasi additive seperti `CREATE TABLE IF NOT EXISTS` dan penambahan kolom bila diperlukan. Deploy aplikasi tidak menghapus database trial yang sudah berjalan selama database tidak dihapus/import ulang secara manual.

MVP ini mengambil foto wajah sebagai bukti absensi. Untuk verifikasi biometrik otomatis, integrasi berikutnya bisa memakai face embedding di sisi browser atau layanan face recognition internal, lalu menyimpan template wajah karyawan dan skor kecocokan per absensi.
