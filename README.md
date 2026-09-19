# Bright Hair Studio Booking System

PHP/MySQL booking system for customers, stylists, and administrators.

## Setup
1. Copy `Project/.env.example` to `Project/.env` and configure database and SMTP credentials.
2. Generate *new* admin and stylist password hashes with `php -r "echo password_hash('NEW_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"` and set the hash values in `.env`.
3. Set `APP_URL` to the actual HTTPS URL of the `Project` directory.
4. Provision the existing MySQL database/schema separately; no customer data or database dump is included.
5. Configure the web server to deny HTTP access to `.env` and other dotfiles, and serve the `Project` directory.

## Security notes
- Do not commit `.env`, real database dumps, customer data, or SMTP app passwords.
- Rotate the database password and SMTP app password that were embedded in the original source before deployment.
- Existing stylist login uses a shared password hash and stylist ID; migrate to per-stylist password hashes before production use.
- Review authorization, CSRF, and other application security before using this as a public production service.
- `seed_db.php`, `migrate_services_promotions.php`, and `test_schema.php` are excluded because they are unprotected maintenance/debug endpoints; run migrations offline if needed.
- Confirm you have permission to redistribute images and bundled PHPMailer code.
