CREATE TABLE IF NOT EXISTS subscription_plans (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12, 2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    billing_interval VARCHAR(10) NOT NULL,
    interval_count INT NOT NULL DEFAULT 1,
    trial_days INT NOT NULL DEFAULT 0,
    store_id VARCHAR(36) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    plan_id VARCHAR(36) NOT NULL,
    pending_plan_id VARCHAR(36) NULL,
    status VARCHAR(20) NOT NULL,
    store_id VARCHAR(36) NULL,
    current_period_start DATETIME NOT NULL,
    current_period_end DATETIME NOT NULL,
    trial_ends_at DATETIME NULL,
    cancel_at_period_end TINYINT(1) DEFAULT 0,
    canceled_at DATETIME NULL,
    paused_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

CREATE TABLE IF NOT EXISTS subscription_invoices (
    id VARCHAR(36) PRIMARY KEY,
    subscription_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    plan_id VARCHAR(36) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    status VARCHAR(20) NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    due_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    payment_reference VARCHAR(100) NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_subs_user_store ON subscriptions(user_id, store_id);
CREATE INDEX IF NOT EXISTS idx_subs_renewal ON subscriptions(status, current_period_end);
CREATE INDEX IF NOT EXISTS idx_inv_subscription ON subscription_invoices(subscription_id);
CREATE INDEX IF NOT EXISTS idx_inv_overdue ON subscription_invoices(status, due_at);
CREATE INDEX IF NOT EXISTS idx_plans_active ON subscription_plans(is_active, store_id);
