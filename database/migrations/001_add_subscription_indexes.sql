-- 001: Tambahkan indeks performa pada tabel subscription-engine.
--
-- Tujuan: mempercepat lookup langganan per user/store, penjadwalan renewal,
-- penagihan invoice, deteksi invoice overdue, dan filter plan aktif.
-- Jalankan hanya pada database yang dibuat sebelum indeks diimplementasikan.
-- Fresh install via DatabaseMigrator::migrate() sudah mencakup indeks ini di
-- schema.sql dan menandai migrasi ini sebagai baseline secara otomatis.
CREATE INDEX IF NOT EXISTS idx_subs_user_store ON subscriptions(user_id, store_id);
CREATE INDEX IF NOT EXISTS idx_subs_renewal ON subscriptions(status, current_period_end);
CREATE INDEX IF NOT EXISTS idx_inv_subscription ON subscription_invoices(subscription_id);
CREATE INDEX IF NOT EXISTS idx_inv_overdue ON subscription_invoices(status, due_at);
CREATE INDEX IF NOT EXISTS idx_plans_active ON subscription_plans(is_active, store_id);
