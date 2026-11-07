-- Run once in Supabase SQL Editor to enable homepage promotions and seller bidding.
create table if not exists public.promotion_campaigns (
 id uuid primary key default gen_random_uuid(),
 title text not null,
 description text not null default '',
 image_url text,
 target_url text,
 active boolean not null default true,
 starts_at timestamptz not null default now(),
 ends_at timestamptz,
 created_by uuid references public.profiles(id),
 created_at timestamptz not null default now()
);
create index if not exists promotion_campaigns_live_idx on public.promotion_campaigns(active,starts_at,ends_at);
create table if not exists public.promotion_bids (
 id uuid primary key default gen_random_uuid(),
 advertisement_id uuid not null references public.advertisements(id) on delete cascade,
 seller_id uuid not null references public.profiles(id) on delete cascade,
 bid_ugx bigint not null check (bid_ugx >= 1000),
 status text not null default 'pending' check (status in ('pending','approved','rejected','paused')),
 starts_at timestamptz,
 ends_at timestamptz,
 created_at timestamptz not null default now(),
 updated_at timestamptz not null default now(),
 unique(advertisement_id,seller_id)
);
create index if not exists promotion_bids_live_idx on public.promotion_bids(status,bid_ugx desc,starts_at,ends_at);
alter table public.promotion_campaigns enable row level security;
alter table public.promotion_bids enable row level security;
-- All writes and access checks are performed by the PHP backend using the service role.
grant select on public.promotion_campaigns,public.promotion_bids to anon,authenticated;
