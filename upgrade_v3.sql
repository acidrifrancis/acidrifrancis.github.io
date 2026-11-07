-- v3 marketplace completion migration. Safe to re-run.
begin;

alter table public.profiles add column if not exists account_status text not null default 'active';
alter table public.profiles drop constraint if exists profiles_account_status_check;
alter table public.profiles add constraint profiles_account_status_check check (account_status in ('active','suspended','closed'));

create table if not exists public.promotion_campaigns (
  id uuid primary key default gen_random_uuid(),
  title text not null check (length(title) between 1 and 120),
  description text,
  image_url text,
  target_url text not null default '/marketplace',
  active boolean not null default true,
  starts_at timestamptz not null default now(),
  ends_at timestamptz,
  created_by uuid references public.profiles(id),
  created_at timestamptz not null default now(),
  check (ends_at is null or ends_at > starts_at)
);
create index if not exists promotion_campaigns_live_idx on public.promotion_campaigns(active,starts_at,ends_at);

create table if not exists public.promotion_bids (
  id uuid primary key default gen_random_uuid(),
  advertisement_id uuid not null references public.advertisements(id) on delete cascade,
  seller_id uuid not null references public.profiles(id) on delete cascade,
  bid_ugx bigint not null check (bid_ugx between 1000 and 100000000),
  status text not null default 'pending_payment' check (status in ('pending_payment','pending','approved','rejected','paused')),
  starts_at timestamptz,
  ends_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique(advertisement_id,seller_id),
  check (ends_at is null or starts_at is null or ends_at > starts_at)
);
create index if not exists promotion_bids_live_idx on public.promotion_bids(status,starts_at,ends_at,bid_ugx desc);
create index if not exists promotion_bids_seller_idx on public.promotion_bids(seller_id,created_at desc);

create table if not exists public.promotion_bid_payments (
  id uuid primary key default gen_random_uuid(),
  promotion_bid_id uuid not null references public.promotion_bids(id) on delete cascade,
  seller_id uuid not null references public.profiles(id),
  order_reference text not null unique,
  pesapal_order_tracking_id text,
  checkout_url text,
  amount_ugx bigint not null check (amount_ugx > 0),
  currency text not null default 'UGX',
  status text not null default 'initiated',
  pesapal_status jsonb,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index if not exists promotion_bid_payments_status_idx on public.promotion_bid_payments(status,created_at desc);
create index if not exists promotion_bid_payments_bid_idx on public.promotion_bid_payments(promotion_bid_id,created_at desc);

create table if not exists public.admin_audit_logs (
  id bigint generated always as identity primary key,
  actor_id uuid references public.profiles(id),
  action text not null,
  target_type text not null,
  target_id text,
  details jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);
create index if not exists admin_audit_logs_created_idx on public.admin_audit_logs(created_at desc);
create index if not exists admin_audit_logs_actor_idx on public.admin_audit_logs(actor_id,created_at desc);

create table if not exists public.rate_limit_events (
  id bigint generated always as identity primary key,
  bucket text not null,
  subject_hash text not null,
  created_at timestamptz not null default now()
);
create index if not exists rate_limit_events_lookup_idx on public.rate_limit_events(bucket,subject_hash,created_at desc);

create table if not exists public.support_tickets (
  id uuid primary key default gen_random_uuid(),
  requester_id uuid not null references public.profiles(id),
  subject text not null check (length(subject) between 3 and 160),
  category text not null default 'general' check (category in ('general','account','payment','advert','safety','dispute')),
  status text not null default 'open' check (status in ('open','in_progress','waiting_customer','resolved','closed')),
  priority text not null default 'normal' check (priority in ('low','normal','high','urgent')),
  assigned_to uuid references public.profiles(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index if not exists support_tickets_status_idx on public.support_tickets(status,priority,updated_at desc);

create table if not exists public.support_messages (
  id uuid primary key default gen_random_uuid(),
  ticket_id uuid not null references public.support_tickets(id) on delete cascade,
  sender_id uuid not null references public.profiles(id),
  body text not null check (length(body) between 1 and 5000),
  internal_note boolean not null default false,
  created_at timestamptz not null default now()
);
create index if not exists support_messages_ticket_idx on public.support_messages(ticket_id,created_at);

create table if not exists public.dispute_cases (
  id uuid primary key default gen_random_uuid(),
  ticket_id uuid references public.support_tickets(id),
  opened_by uuid not null references public.profiles(id),
  advertisement_id uuid references public.advertisements(id),
  payment_order_reference text,
  reason text not null check (length(reason) between 3 and 160),
  description text not null check (length(description) between 1 and 5000),
  status text not null default 'open' check (status in ('open','investigating','resolved_buyer','resolved_seller','closed')),
  resolution text,
  resolved_by uuid references public.profiles(id),
  resolved_at timestamptz,
  created_at timestamptz not null default now()
);
create index if not exists dispute_cases_status_idx on public.dispute_cases(status,created_at desc);

create table if not exists public.legal_documents (
  id uuid primary key default gen_random_uuid(),
  slug text not null,
  version text not null,
  title text not null,
  body_markdown text not null,
  published_at timestamptz not null default now(),
  active boolean not null default true,
  unique(slug,version)
);
create table if not exists public.legal_acceptances (
  id bigint generated always as identity primary key,
  user_id uuid not null references public.profiles(id) on delete cascade,
  document_id uuid not null references public.legal_documents(id),
  accepted_at timestamptz not null default now(),
  unique(user_id,document_id)
);

alter table public.promotion_campaigns enable row level security;
alter table public.promotion_bids enable row level security;
alter table public.promotion_bid_payments enable row level security;
alter table public.admin_audit_logs enable row level security;
alter table public.rate_limit_events enable row level security;
alter table public.support_tickets enable row level security;
alter table public.support_messages enable row level security;
alter table public.dispute_cases enable row level security;
alter table public.legal_documents enable row level security;
alter table public.legal_acceptances enable row level security;

-- All writes are performed by the PHP server with the service-role key. No browser role
-- receives write access to payments, bids, support, audit, or rate-limit tables.
grant select on public.promotion_campaigns to anon, authenticated;
grant select on public.promotion_bids to anon, authenticated;
grant select on public.legal_documents to anon, authenticated;
grant all on public.promotion_campaigns, public.promotion_bids, public.promotion_bid_payments,
  public.admin_audit_logs, public.rate_limit_events, public.support_tickets, public.support_messages,
  public.dispute_cases, public.legal_documents, public.legal_acceptances to service_role;
grant usage, select on sequence public.admin_audit_logs_id_seq, public.rate_limit_events_id_seq,
  public.legal_acceptances_id_seq to service_role;
create policy "public reads live campaigns" on public.promotion_campaigns for select to anon,authenticated using (active and starts_at <= now() and (ends_at is null or ends_at > now()));
create policy "public reads approved live bids" on public.promotion_bids for select to anon,authenticated using (status='approved' and starts_at <= now() and (ends_at is null or ends_at > now()));
create policy "public reads active legal documents" on public.legal_documents for select to anon,authenticated using (active);

-- Retain a short-lived abuse-event window only; scheduled cleanup removes old rows.
commit;
