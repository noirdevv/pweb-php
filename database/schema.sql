-- Pendaftaran Siswa Baru (PSB) - SMK Coding
-- Import via phpMyAdmin atau: mysql -u root -p < database/schema.sql
CREATE DATABASE IF NOT EXISTS pendaftaran_siswa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pendaftaran_siswa;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts, pembayaran, calon_siswa, jurusan, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(64) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','pendaftar') NOT NULL DEFAULT 'pendaftar',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE jurusan (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(10) NOT NULL UNIQUE,
  nama VARCHAR(80) NOT NULL,
  kuota INT UNSIGNED NOT NULL DEFAULT 36,
  aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel inti dari tutorial (calon_siswa), diperluas
CREATE TABLE calon_siswa (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  no_pendaftaran VARCHAR(20) NULL UNIQUE,
  nama VARCHAR(64) NOT NULL,
  tempat_lahir VARCHAR(50) NOT NULL,
  tanggal_lahir DATE NOT NULL,
  alamat VARCHAR(255) NOT NULL,
  jenis_kelamin ENUM('laki-laki','perempuan') NOT NULL,
  agama VARCHAR(16) NOT NULL,
  sekolah_asal VARCHAR(64) NOT NULL,
  no_hp VARCHAR(20) NOT NULL,
  nama_wali VARCHAR(64) NOT NULL,
  jurusan_id INT UNSIGNED NOT NULL,
  status ENUM('menunggu_pembayaran','menunggu_verifikasi','diterima','ditolak') NOT NULL DEFAULT 'menunggu_pembayaran',
  catatan_admin VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_siswa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_siswa_jurusan FOREIGN KEY (jurusan_id) REFERENCES jurusan(id),
  INDEX idx_status (status),
  INDEX idx_jurusan (jurusan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pembayaran (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  siswa_id INT UNSIGNED NOT NULL,
  order_id VARCHAR(50) NOT NULL UNIQUE,
  jumlah INT UNSIGNED NOT NULL,
  status ENUM('pending','paid','failed','expired') NOT NULL DEFAULT 'pending',
  gateway VARCHAR(20) NOT NULL,
  metode VARCHAR(40) NULL,
  snap_token VARCHAR(255) NULL,
  redirect_url VARCHAR(500) NULL,
  paid_at DATETIME NULL,
  raw_response TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bayar_siswa FOREIGN KEY (siswa_id) REFERENCES calon_siswa(id) ON DELETE CASCADE,
  INDEX idx_bayar_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX idx_attempt (email, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO jurusan (kode, nama, kuota) VALUES
 ('RPL','Rekayasa Perangkat Lunak',72),
 ('TJKT','Teknik Jaringan Komputer dan Telekomunikasi',72),
 ('DKV','Desain Komunikasi Visual',36),
 ('ANM','Animasi',36);

-- Akun admin awal. Email: admin@smkcoding.sch.id | Kata sandi: Admin#12345
-- WAJIB diganti setelah login pertama.
INSERT INTO users (nama, email, password_hash, role) VALUES
 ('Administrator','admin@smkcoding.sch.id','$2y$10$1MyRcdT3Vl4Yo7H0KoMqz.bsMaiOUtZDTg.Dgpi5drYr2nm5XbFRK','admin');
