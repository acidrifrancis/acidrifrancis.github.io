-- Create a public bucket for advert thumbnails. Public access is read-only;
-- uploads are performed by the PHP backend using the service-role key.
insert into storage.buckets(id,name,public,file_size_limit,allowed_mime_types)
values ('advert-images','advert-images',true,5242880,array['image/jpeg','image/png','image/webp'])
on conflict(id) do update set public=true, file_size_limit=5242880;
