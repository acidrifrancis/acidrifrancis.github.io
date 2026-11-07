-- Run in Supabase SQL Editor. All exposed tables have RLS enabled.
create extension if not exists pgcrypto;

create table if not exists public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  full_name text not null default 'Member',
  phone text,
  role text not null default 'user' check (role in ('user','admin','owner')),
  created_at timestamptz not null default now()
);
create unique index if not exists profiles_single_owner_idx on public.profiles ((role)) where role='owner';
create table if not exists public.categories (
  id uuid primary key default gen_random_uuid(),
  name text not null unique,
  active boolean not null default true,
  created_at timestamptz not null default now()
);
create table if not exists public.advertisement_durations (
  id uuid primary key default gen_random_uuid(),
  label text not null,
  days integer not null check (days > 0),
  price_ugx bigint not null check (price_ugx >= 0),
  active boolean not null default true,
  created_at timestamptz not null default now()
);
create table if not exists public.advertisements (
  id uuid primary key default gen_random_uuid(),
  seller_id uuid not null references public.profiles(id),
  category_id uuid not null references public.categories(id),
  duration_id uuid not null references public.advertisement_durations(id),
  title text not null,
  description text not null,
  price numeric(14,2),
  location text not null,
  district text,
  image_url text,
  status text not null default 'pending_payment' check (status in ('pending_payment','active','expired','rejected','suspended')),
  published_at timestamptz,
  expires_at timestamptz,
  created_at timestamptz not null default now()
);
create index if not exists advertisements_public_idx on public.advertisements(status, expires_at, created_at desc);
create index if not exists advertisements_seller_idx on public.advertisements(seller_id, created_at desc);

create table if not exists public.advertisement_payments (
  id uuid primary key default gen_random_uuid(),
  advertisement_id uuid not null references public.advertisements(id),
  seller_id uuid not null references public.profiles(id),
  order_reference text not null unique,
  pesapal_order_tracking_id text,
  checkout_url text,
  advert_fee_ugx bigint not null,
  pesapal_fee_ugx bigint not null default 0,
  total_ugx bigint not null,
  currency text not null default 'UGX',
  status text not null default 'initiated',
  pesapal_status jsonb,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create table if not exists public.conversations (
  id uuid primary key default gen_random_uuid(),
  advertisement_id uuid not null references public.advertisements(id),
  buyer_id uuid not null references public.profiles(id),
  seller_id uuid not null references public.profiles(id),
  created_at timestamptz not null default now(),
  unique(advertisement_id,buyer_id,seller_id),
  check (buyer_id <> seller_id)
);
create table if not exists public.messages (
  id uuid primary key default gen_random_uuid(),
  conversation_id uuid not null references public.conversations(id) on delete cascade,
  sender_id uuid not null references public.profiles(id),
  body text not null check (length(body) between 1 and 5000),
  read_at timestamptz,
  created_at timestamptz not null default now()
);
create index if not exists messages_conversation_idx on public.messages(conversation_id,created_at);

-- RLS on all public-schema tables.
alter table public.profiles enable row level security;
alter table public.categories enable row level security;
alter table public.advertisement_durations enable row level security;
alter table public.advertisements enable row level security;
alter table public.advertisement_payments enable row level security;
alter table public.conversations enable row level security;
alter table public.messages enable row level security;

-- Browser-facing roles receive only narrow read permissions. The PHP backend uses
-- service-role credentials exclusively on the server and enforces participant checks.
grant select on public.categories to anon, authenticated;
grant select on public.advertisement_durations to anon, authenticated;
grant select on public.advertisements to anon, authenticated;
grant select on public.profiles to authenticated;
grant select on public.conversations, public.messages to authenticated;

create policy "public can read active categories" on public.categories for select to anon, authenticated using (active=true);
create policy "public can read active pricing" on public.advertisement_durations for select to anon, authenticated using (active=true);
create policy "public can read active adverts" on public.advertisements for select to anon, authenticated using (status='active' and expires_at > now());
create policy "users read own profile" on public.profiles for select to authenticated using ((select auth.uid())=id);
create policy "participants read conversations" on public.conversations for select to authenticated using ((select auth.uid())=buyer_id or (select auth.uid())=seller_id);
create policy "participants read messages" on public.messages for select to authenticated using (
  exists(select 1 from public.conversations c where c.id=conversation_id and ((select auth.uid())=c.buyer_id or (select auth.uid())=c.seller_id))
);

-- Seed categories and sample advert-duration prices. Change prices in Admin dashboard.
insert into public.categories(name) values
('Agriculture & Farm Produce'),('Electronics'),('Vehicles & Motorcycles'),('Real Estate & Land'),
('Houses for Rent'),('Construction Materials'),('Clothing & Fashion'),('Furniture'),
('Food & Beverages'),('Livestock'),('Phones & Accessories'),('Machinery & Equipment'),
('Professional Services'),('Transport & Logistics'),('Education & Training'),('Jobs & Opportunities'),('Other')
on conflict(name) do nothing;

insert into public.advertisement_durations(label,days,price_ugx) values
('7 days',7,5000),('14 days',14,9000),('30 days',30,15000),('60 days',60,25000),('90 days',90,35000)
on conflict do nothing;

-- Create profile rows for newly registered Supabase Auth users.
create or replace function public.handle_new_user()
returns trigger language plpgsql security definer set search_path=public
as $$
begin
  insert into public.profiles(id,full_name,phone)
  values(new.id,coalesce(new.raw_user_meta_data->>'full_name','Member'),new.raw_user_meta_data->>'phone')
  on conflict(id) do nothing;
  return new;
end;
$$;
drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created after insert on auth.users
for each row execute procedure public.handle_new_user();

revoke all on function public.handle_new_user() from public, anon, authenticated;


-- User-submitted advert reports and moderation workflow.
create table if not exists public.advertisement_reports (
  id uuid primary key default gen_random_uuid(),
  advertisement_id uuid not null references public.advertisements(id) on delete cascade,
  reporter_id uuid not null references public.profiles(id),
  reason text not null,
  details text,
  status text not null default 'open' check (status in ('open','reviewed','dismissed')),
  reviewed_by uuid references public.profiles(id),
  reviewed_at timestamptz,
  created_at timestamptz not null default now()
);
create index if not exists advertisement_reports_status_idx on public.advertisement_reports(status,created_at desc);
alter table public.advertisement_reports enable row level security;
grant select,insert on public.advertisement_reports to authenticated;
create policy "users can submit reports" on public.advertisement_reports for insert to authenticated
  with check ((select auth.uid())=reporter_id);
create policy "users can read their own reports" on public.advertisement_reports for select to authenticated
  using ((select auth.uid())=reporter_id);

-- Safe to run on an existing project as an upgrade.
alter table public.messages add column if not exists read_at timestamptz;
alter table public.advertisements drop constraint if exists advertisements_status_check;
alter table public.advertisements add constraint advertisements_status_check
  check (status in ('pending_payment','pending_review','active','expired','rejected','suspended'));

-- Storage bucket used for advert cover photos.
insert into storage.buckets(id,name,public,file_size_limit,allowed_mime_types)
values ('advert-images','advert-images',true,5242880,array['image/jpeg','image/png','image/webp'])
on conflict(id) do update set public=true,file_size_limit=5242880,allowed_mime_types=array['image/jpeg','image/png','image/webp'];

-- IMPORTANT: promote the first trusted administrator manually after registering:
-- update public.profiles set role='admin' where id=(select id from auth.users where email='YOUR_ADMIN_EMAIL');

-- Server-only marketplace configuration. Never expose the Supabase service key to browsers.
create table if not exists public.platform_settings (
  key text primary key,
  value text not null,
  updated_at timestamptz not null default now()
);
alter table public.platform_settings enable row level security;
insert into public.platform_settings(key,value) values
('payment_fee_mode','none'),('payment_fee_fixed_ugx','0'),('payment_fee_percent','0')
on conflict(key) do nothing;
