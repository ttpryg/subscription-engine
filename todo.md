# TODO: Penyesuaian Struktur

Hasil review struktur terhadap `.agents/rules/module-architecture.md`, `.agents/rules/coding-standards.md`, `.agents/rules/database-migrations.md`, dan `.agents/rules/psr.md`.

## High

- [x] Pisahkan layer Domain dan Application sesuai konvensi.
  - Struktur saat ini datar: `src/Entities/`, `src/ValueObjects/`, `src/Services/`, `src/Events/`, `src/Exceptions/`, `src/Enums/`.
  - Target: `src/Domain/{Entity,ValueObject,Repository,Service,Exception}` dan `src/Application/{UseCase,DTO,Service}`.
  - Dampak: `README.md` mengklaim Clean Architecture/DDD, tetapi pemisahan layer belum ada.
  - Selesai: dipindahkan ke `src/Domain/{Entity,ValueObject,Repository,Exception,Enum,Event}` dan `src/Application/Service`; namespace, import, test, README, dan CHANGELOG diperbarui.

- [x] Pindahkan implementasi infrastruktur database keluar dari module core.
  - `src/Repositories/Pdo*.php`
  - `src/Repositories/Concerns/InteractsWithPdo.php`
  - `src/Database/DatabaseMigrator.php`
  - `src/Database/MigrationRunner.php`
  - Port di `src/Domain/Repository/` sudah bebas PDO; adapter dipindahkan ke `infrastructure/`.
  - Selesai: PDO + in-memory repositories ke `infrastructure/Persistence/{Pdo,InMemory}`, migrator ke `infrastructure/Database`, namespace `Ttpryg\SubscriptionEngine\Infrastructure\*`, autoload Composer ditambahkan.

- [ ] Ganti ketergantungan event dispatcher ke kontrak PSR-14.
  - `src/Services/SubscriptionService.php:9`
  - `src/Services/PlanService.php:7`
  - Saat ini memakai `Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface`.
  - Gunakan `Psr\EventDispatcher\EventDispatcherInterface` yang sudah ada di `composer.json`.
  - Evaluasi apakah `ttpryg/event-dispatcher` masih perlu di `require`.

## Medium

- [ ] Gunakan PSR-20 untuk clock.
  - `src/Contracts/ClockInterface.php` masih interface buatan sendiri.
  - Tambahkan `psr/clock` ke `composer.json` dan sesuaikan `SystemClock`/`FixedClock`.

- [ ] Pecah `SubscriptionService` dan tambahkan UseCase/DTO.
  - `src/Services/SubscriptionService.php` (422 baris) mencampur orkestrasi, pembuatan invoice, kalkulasi prorasi (`:208-244`), generator UUID, dan transaction manager.
  - Pindahkan prorasi ke Domain Service/ValueObject.
  - Buat UseCase untuk alur utama (`subscribe`, `changePlan`, `recordPayment`, `processRenewals`, dll.).
  - Buat DTO agar `subscribe()`/`createPlan()` tidak memakai banyak parameter primitif.

- [ ] Rapikan struktur dan tooling migrasi.
  - `database/migrations/001_add_subscription_indexes.sql` memakai format SQL + nomor `001`; konvensi mencontohkan `202610190001_*.php`.
  - `MigrationRunner` hanya mendukung `.sql` (`src/Database/MigrationRunner.php:131`) dan tidak punya `down()`/rollback.
  - Hindari duplikasi definisi indeks antara `schema.sql` dan migrasi.

- [ ] Selaraskan klaim dukungan database dengan schema.
  - `README.md:16` dan `README.md:56` mengklaim MySQL/MariaDB dan PostgreSQL.
  - `database/schema.sql:11` memakai `TINYINT(1)` (MySQL-specific).
  - `database/schema.sql:53` memakai `CREATE INDEX IF NOT EXISTS` yang tidak didukung MySQL.
  - Test integrasi hanya memakai SQLite; tambahkan test untuk driver yang diklaim atau perbaiki klaim.

## Low

- [ ] Samakan penamaan direktori dengan konvensi.
  - `Entities` -> `Entity`
  - `ValueObjects` -> `ValueObject`
  - `Exceptions` -> `Exception`
  - `Repositories` -> `Repository`
  - Posisikan `Events/`, `Clock/`, dan `Enums/` di dalam `Domain/`.

- [ ] Lengkapi cakupan struktur test.
  - Tambahkan `tests/Unit/Events`.
  - Tambahkan `tests/Unit/Clock`.
  - Tambahkan `tests/Unit/Repositories` untuk `Memory*Repository`.
  - Tambahkan test langsung untuk `MigrationRunner`.

- [ ] Hapus scaffolding aplikasi yang tidak relevan untuk library.
  - `docker-compose.yml`
  - `.docker/php/Dockerfile`
  - `.docker/nginx/default.conf`
  - Nginx melayani `index.php` yang tidak ada di repo.

- [ ] Lengkapi tooling static analysis.
  - `vendor/bin/phpstan` tersedia, tetapi belum ada `phpstan.neon`.
  - Belum ada script `composer analyse`.
  - `.github/workflows/tests.yml:3-7` belum menjalankan static analysis.
  - Evaluasi trigger CI yang hanya berjalan pada branch `develop`.

## Catatan Verifikasi

- `composer fix:check`: belum dijalankan (`composer` tidak tersedia).
- `composer test`: belum dijalankan (`php`/`composer` tidak tersedia).
- Inspeksi struktur, dependency, dan konfigurasi: selesai.
