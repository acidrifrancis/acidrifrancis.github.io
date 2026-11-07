-- NOTHERN UGANDA MARKET CONNECT v2 upgrade.
-- Run this in Supabase SQL Editor if you already ran the first version's schema.sql.
begin;

-- Add a distinct, protected website-owner role. Existing seller/admin accounts remain unchanged.
alter table public.profiles drop constraint if exists profiles_role_check;
alter table public.profiles add constraint profiles_role_check check (role in ('user','admin','owner'));
create unique index if not exists profiles_single_owner_idx on public.profiles ((role)) where role='owner';

alter table public.messages add column if not exists read_at timestamptz;

alter table public.advertisements drop constraint if exists advertisements_status_check;
alter table public.advertisements add constraint advertisements_status_check
  check (status in ('pending_payment','pending_review','active','expired','rejected','suspended'));

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

do $$ begin
  if not exists (select 1 from pg_policies where schemaname='public' and tablename='advertisement_reports' and policyname='users can submit reports') then
    create policy "users can submit reports" on public.advertisement_reports for insert to authenticated
      with check ((select auth.uid())=reporter_id);
  end if;
  if not exists (select 1 from pg_policies where schemaname='public' and tablename='advertisement_reports' and policyname='users can read their own reports') then
    create policy "users can read their own reports" on public.advertisement_reports for select to authenticated
      using ((select auth.uid())=reporter_id);
  end if;
end $$;

create table if not exists public.platform_settings (
  key text primary key,
  value text not null,
  updated_at timestamptz not null default now()
);
alter table public.platform_settings enable row level security;
insert into public.platform_settings(key,value) values
('payment_fee_mode','none'),('payment_fee_fixed_ugx','0'),('payment_fee_percent','0')
on conflict(key) do nothing;

insert into storage.buckets(id,name,public,file_size_limit,allowed_mime_types)
values ('advert-images','advert-images',true,5242880,array['image/jpeg','image/png','image/webp'])
on conflict(id) do update set public=true,file_size_limit=5242880,allowed_mime_types=array['image/jpeg','image/png','image/webp'];

commit;
