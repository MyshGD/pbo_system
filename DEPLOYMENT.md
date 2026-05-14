# Deployment (Hostinger Shared Hosting)

## Recommended layout
- Web root is the project root (public_html/minsu-pbo)
- /pages, /includes, /components, /database, /utilities -> app code
- /storage, /backups -> writable directories

## Deploy steps
1. Upload the entire project folder to your hosting account (example: public_html/minsu-pbo).
2. Point the domain/subdomain document root to that folder.
3. Set the site PHP version to PHP 8.1 or newer.
4. Create a MySQL database and database user in Hostinger.
5. Update database credentials in config.php:
   - DB_HOST is usually `localhost` on Hostinger shared hosting.
   - DB_NAME must match the full Hostinger database name.
   - DB_USER must match the full Hostinger database username.
   - DB_PASS must match the database user's password.
6. Visit `/login.php`. The app creates its required tables on first load.
7. Optional: import `database/database.sql` only after selecting the target database in phpMyAdmin.
   - If phpMyAdmin reports missing routine, trigger, or view privileges, skip the import and let the app initialize itself.
8. Ensure these folders are writable:
   - storage/sessions
   - backups
   - assets/images

## Hostinger notes
- Leave `DB_AUTO_CREATE` set to `false`. Hostinger database users normally cannot create databases.
- Leave `DB_ENABLE_PROGRAMMABILITY` set to `false` unless your database user has CREATE VIEW, CREATE ROUTINE, and TRIGGER privileges. The app uses direct fallback queries when these optional objects are unavailable.
- Do not open or deploy diagnostic seed scripts publicly. The `.htaccess` blocks the bundled debug and seed runner files.
- Default first-login accounts are created automatically if missing:
   - admin / Admin@123
   - staff / Staff@123
  Change these passwords immediately after first login.

## Post-deploy checks
- Visit /login.php and confirm login.
- Go to Settings and upload a logo to verify assets/images is writable.
- Create a sample record to confirm database access.
- Create a backup from Backup or Settings > Backup to verify backups is writable.

## Cloud Run (Docker)
Cloud Run requires a container. This repo includes a Dockerfile that runs Apache + PHP from the project root.

1. Build and deploy (example):
   - gcloud builds submit --tag gcr.io/PROJECT_ID/minsu-bpo
   - gcloud run deploy minsu-bpo --image gcr.io/PROJECT_ID/minsu-bpo --platform managed --region REGION --allow-unauthenticated
2. Set environment variables for DB access in Cloud Run:
   - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
   - Optional: DB_AUTO_CREATE=1 for local disposable databases
   - Optional: DB_SKIP_CREATE=1 for managed DBs that already exist
3. Ensure your database allows connections from Cloud Run (Cloud SQL or external host with public IP and firewall rules).
