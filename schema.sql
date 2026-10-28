CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE TABLE IF NOT EXISTS users (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(), email TEXT UNIQUE NOT NULL,
 name TEXT NOT NULL, phone TEXT, password_hash TEXT NOT NULL,
 role TEXT NOT NULL DEFAULT 'user', created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS email_verifications (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(), email TEXT NOT NULL,
 purpose TEXT NOT NULL CHECK(purpose IN ('signup','reset')), code_hash TEXT NOT NULL,
 expires_at TIMESTAMPTZ NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
 verified_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS email_verifications_lookup ON email_verifications(email,purpose,created_at DESC);
CREATE TABLE IF NOT EXISTS orders (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(), user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 package_name TEXT, project_type TEXT, description TEXT NOT NULL, budget TEXT, deadline TEXT,
 features JSONB NOT NULL DEFAULT '[]', contact_name TEXT, contact_phone TEXT,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','cancelled')),
 payment_status TEXT NOT NULL DEFAULT 'unpaid' CHECK(payment_status IN ('unpaid','pending','paid','failed','refunded')),
 amount BIGINT, items JSONB NOT NULL DEFAULT '[]', attachment_original_name TEXT,
 attachment_path TEXT, attachment_mime TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS contact_messages (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(), user_id UUID REFERENCES users(id) ON DELETE SET NULL,
 name TEXT NOT NULL, email TEXT NOT NULL, message TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS payments (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(), order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
 provider TEXT NOT NULL DEFAULT 'manual', authority TEXT, ref_id TEXT,
 status TEXT NOT NULL DEFAULT 'created', amount BIGINT, created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS agent_logs (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(), user_id UUID REFERENCES users(id) ON DELETE SET NULL,
 prompt TEXT NOT NULL, response TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
