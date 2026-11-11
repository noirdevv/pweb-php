# Aplikasi Pendaftaran Siswa Baru (PSB) — SMK Coding

Pengembangan dari tutorial *petanikode/tutorial-php-mysql* (CRUD `calon_siswa`) menjadi aplikasi
**frontend + backend terpisah**: frontend statis (HTML/CSS/JS) memanggil **REST API PHP + MySQL**.

```
pendaftaran-siswa-baru/
├── database/schema.sql      # skema MySQL + data awal (jurusan, akun admin)
├── backend/
│   ├── config/config.php    # DB, keamanan, biaya, payment gateway
│   ├── includes/            # bootstrap (sesi, CSRF, helper), validasi
│   └── api/                 # auth, siswa, jurusan, pembayaran, dashboard, export
└── frontend/                # index, login, register, dashboard, admin, halaman pembayaran
```

## Cara menjalankan (XAMPP / Laragon)
1. Letakkan folder ini di `htdocs` (mis. `C:\xampp\htdocs\pendaftaran-siswa-baru`). Butuh **PHP 7.4+** (ekstensi `pdo_mysql`, `curl`, `mbstring`).
2. Jalankan Apache + MySQL, lalu impor `database/schema.sql` lewat phpMyAdmin.
3. Sesuaikan `backend/config/config.php` bila user/password MySQL berbeda (default XAMPP: `root` / kosong).
4. Buka `http://localhost/pendaftaran-siswa-baru/frontend/`.

**Akun admin awal:** `admin@smkcoding.sch.id` / `Admin#12345` — segera ganti (ubah hash di tabel `users`, dibuat dengan `password_hash()`).
Pendaftar membuat akun sendiri lewat halaman *Daftar*.

## Payment gateway
- `payment.mode = 'mock'` (default): halaman simulasi pembayaran, tanpa akun gateway — cocok untuk demo/tugas.
- `payment.mode = 'midtrans'`: Midtrans Snap (sandbox). Isi environment variable `MIDTRANS_SERVER_KEY`
  (atau `config.php`) dan atur **Payment Notification URL** di dashboard Midtrans ke
  `https://DOMAIN-ANDA/backend/api/pembayaran.php?action=notify` (harus URL publik; di localhost gunakan ngrok/cloudflared).
  Webhook diverifikasi dengan `signature_key` (SHA-512); status juga disinkronkan saat pendaftar kembali ke `pay-result.html`.

## Alur bisnis
`Daftar akun → isi formulir (no. pendaftaran otomatis) → bayar → menunggu verifikasi → diterima / ditolak`
- Data hanya bisa diubah pendaftar selama belum membayar.
- Admin tidak bisa memutuskan seleksi sebelum pembayaran lunas.
- Status *diterima* dicek terhadap kuota jurusan (transaksi + row lock, aman dari race condition).
- Validasi: usia 12–19 tahun, format HP Indonesia, periode pendaftaran, jurusan aktif.

## Keamanan yang diterapkan
Prepared statement PDO (anti SQL injection) · `password_hash` bcrypt · session cookie HttpOnly + SameSite + regenerasi ID saat login ·
token CSRF pada semua request tulis · pembatasan percobaan login (5x / 15 menit) · otorisasi berbasis peran di setiap endpoint ·
escape output di frontend (anti XSS) + Content-Security-Policy · header keamanan · ekspor CSV anti formula-injection · webhook bertanda tangan.

## Perbedaan dari kode tutorial
`mysqli` + query string mentah (rentan SQL injection) → PDO prepared statement; halaman PHP campur HTML → API JSON + frontend terpisah;
opsi agama “Atheis” diganti enam agama resmi; tambahan tabel `users`, `jurusan`, `pembayaran`.

## Catatan produksi
Gunakan HTTPS dan set `cookie_secure = true`; jangan commit kunci Midtrans; bila frontend di domain lain isi `allowed_origins`,
ubah `apiBase` di `frontend/js/config.js`, dan longgarkan `connect-src` pada meta CSP; atur `SameSite=None; Secure` untuk lintas-domain.
