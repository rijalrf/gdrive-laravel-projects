# Panduan Integrasi Backup Database ke Google Drive

Dokumen ini menjelaskan cara mengintegrasikan dan menggunakan fitur backup database MySQL ke Google Drive pada proyek ini.

## Prasyarat & Kebutuhan Sistem
1. Proyek ini berjalan menggunakan Docker Compose.
2. Container MySQL target (`mkas-mysql`) harus berjalan pada Docker network `mkas-laravel_default`.
3. Kredensial Google API Console dengan OAuth 2.0 (Client ID, Client Secret, dan Refresh Token) yang memiliki akses menulis ke Google Drive.

## Konfigurasi Environment (`.env`)
Pastikan variabel lingkungan berikut terkonfigurasi di file `.env` proyek `gdrive-laravel`:

### 1. Koneksi Database MySQL (`mkas-mysql`)
```env
DB_CONNECTION=mysql
DB_HOST=mkas-mysql
DB_PORT=3306
DB_DATABASE=mkas_db
DB_USERNAME=root
DB_PASSWORD=root
```

### 2. Kredensial Google Drive
```env
GOOGLE_DRIVE_CREDENTIALS_MODE=refresh_token
GOOGLE_DRIVE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_DRIVE_CLIENT_SECRET=your-client-secret
GOOGLE_DRIVE_REFRESH_TOKEN=your-refresh-token
GOOGLE_DRIVE_FOLDER_PATH="MKAS LARAVEL STORAGE"
```

---

## Cara Penggunaan

### 1. Menjalankan Backup Secara Manual
Untuk menjalankan proses backup database secara instan, Anda dapat memanggil perintah Artisan berikut dari dalam container:

```bash
docker compose run --rm gdrive-service php artisan db:backup
```

**Opsi Tambahan (Asynchronous Upload):**
Jika Anda ingin file backup diunggah di latar belakang menggunakan sistem Laravel Queue (Worker), gunakan opsi `--async`:
```bash
docker compose run --rm gdrive-service php artisan db:backup --async
```

### 2. Backup Otomatis (Task Scheduling)
Perintah backup telah dijadwalkan secara otomatis untuk berjalan **setiap hari (daily)** di dalam file `routes/console.php`:

```php
Schedule::command('db:backup')->daily();
```

Agar scheduler Laravel berjalan terus-menerus, Anda dapat mendaftarkan cron job berikut pada sistem operasi hosting Anda untuk berjalan setiap menit:
```bash
* * * * * cd /path/to/your/gdrive-laravel && docker compose exec -T gdrive-service php artisan schedule:run >> /dev/null 2>&1
```

---

## Mekanisme Kerja & Keamanan
1. **Dumping & Kompresi**: Perintah `db:backup` memicu perintah `mysqldump` pada container `mkas-mysql` dan mengompres hasilnya langsung menggunakan `gzip` (`.sql.gz`) demi efisiensi storage.
2. **Propagasi Error (Pipefail)**: Perintah dump dibungkus menggunakan `bash -o pipefail` sehingga jika koneksi atau otentikasi database gagal, command akan membatalkan proses upload dan mencatat log kegagalan.
3. **Penyimpanan Drive**: Berkas hasil kompresi diunggah ke Google Drive ke dalam folder `{GOOGLE_DRIVE_FOLDER_PATH}/BACKUPS/`.
4. **Pembersihan Otomatis**: Berkas temporary yang dibuat lokal akan langsung dihapus setelah proses unggah selesai (atau setelah dimasukkan ke queue jika menggunakan opsi `--async`).

---

## Integrasi dari Container Lain

Untuk memicu backup atau mengunggah berkas dari container lain di dalam docker network yang sama (misal dari container aplikasi utama Anda seperti `mkas-app`), Anda dapat menggunakan 2 metode berikut:

### Metode 1: Menggunakan HTTP API (Direkomendasikan)
Karena container `gdrive-service` berada di dalam network yang sama, Anda dapat mengirimkan HTTP request langsung ke hostname service-nya (`gdrive-service`).

#### A. Trigger Backup Database
- **URL**: `http://gdrive-service:8000/api/backup`
- **Method**: `POST`
- **Headers**: `Accept: application/json`
- **Body (JSON / Form Data)**:
  - `async` (boolean, opsional): Set `true` jika ingin proses backup didelegasikan ke queue worker.

*Contoh cURL dari container lain:*
```bash
curl -X POST http://gdrive-service:8000/api/backup \
     -H "Accept: application/json" \
     -d "async=false"
```

#### B. Upload Berkas / Gambar
- **URL**: `http://gdrive-service:8000/api/upload`
- **Method**: `POST`
- **Headers**: `Accept: application/json`
- **Body (Multipart Form Data)**:
  - `file` (file binary, required): File yang ingin diunggah.
  - `target_path` (string, opsional): Lokasi target di Google Drive (misal: `MKAS LARAVEL STORAGE/TRANSACTIONS/bukti.jpg`).
  - `async` (boolean, opsional): default `false`.
  - `compress` (boolean, opsional): default `true`.
  - `quality` (int, opsional): default `75`.

*Contoh cURL untuk upload dari container lain:*
```bash
curl -X POST http://gdrive-service:8000/api/upload \
     -H "Accept: application/json" \
     -F "file=@/path/to/local/file.jpg" \
     -F "target_path=MKAS LARAVEL STORAGE/TRANSACTIONS/bukti.jpg"
```

---

### Metode 2: Menggunakan Docker Execute (CLI)
Jika container lain memiliki akses ke socket Docker host, atau melalui script di server host:

```bash
docker exec -t gdrive-service php artisan db:backup
```
