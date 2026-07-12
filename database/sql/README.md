# SQL Database Artifacts

The **canonical, authoritative schema** is the set of Laravel migrations in
`database/migrations/` — they are engine-portable and run on both MySQL
(production/XAMPP) and SQLite (development/CI).

## Files here
- `lms_schema_and_seed.sql` — a reference dump (SQLite dialect) of the full schema
  plus the baseline seed data (roles, permissions, leave types, holidays, settings).
  Useful for inspecting the structure or importing into a SQLite viewer.

## Producing the production (MySQL) database
On the deployment server (see `docs/Deployment.md`):
```bash
php artisan migrate --force          # create all tables in MySQL
php artisan db:seed --force          # roles, permissions, CSC leave types, holidays, settings, super admin
php artisan db:seed --class=DemoDataSeeder   # OPTIONAL demo employees/accounts
```

## Producing a MySQL SQL dump (for backup or handover)
```bash
php artisan schema:dump              # writes database/schema/mysql-schema.sql
# or a full data dump:
mysqldump -u lms_app -p lms_alicia > lms_alicia_backup.sql
# or use the built-in backup command:
php artisan lms:backup               # zips DB dump + uploaded documents
```
