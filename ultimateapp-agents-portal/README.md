# Ultimate App

A mobile-first PHP 8+ and MySQL platform inspired by the supplied Ultimate App UI references.

## Features

- Login, registration, logout, password hashing, CSRF protection
- MySQL-backed users, transactions, rewards, QR codes, and credit balances
- Dashboard with service shortcuts, credit card, updates, and bottom navigation
- Buy Credits flow with real balance and transaction updates
- QR scan and My QR screens
- Transactions, rewards, profile, service, and convert-credit pages
- Responsive black-and-white interface matching the provided visual direction
- Boracay Phase 1 sample service pages; see `PHASE-1-SERVICES.md` for setup and limitations
- UEat restaurant menus, cart, Credits checkout, order history, and pending-order refunds; see `UEAT-SETUP.md`
- Separate BCash wallet with two-way Credits conversion; see `BORACAY-CASH-SETUP.md`
- Hamburger menu, Boracay community directory, and notifications; see `MENU-COMMUNITY-SETUP.md`

## Setup

1. Create the database:

```sql
SOURCE database/schema.sql;
```

Or import `database/schema.sql` in phpMyAdmin.

2. Configure database credentials with environment variables or edit `config/config.php`:

```bash
DB_HOST=127.0.0.1
DB_NAME=ultimate_app
DB_USER=root
DB_PASS=
```

3. Run locally from this folder:

```bash
php -S localhost:8000
```

4. Open:

```text
http://localhost:8000
```

## Suggested First Test Account

Create a new account from the Sign Up screen. New users start with 0 Credits (no welcome credits since 2026-09-29); they add Credits via Buy Credits.

## Social Login Setup

Import `database/oauth_migration.sql` if you already created the database before this update.

Set these environment variables on the server:

```bash
APP_URL=https://your-domain.com
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
FACEBOOK_CLIENT_ID=your-facebook-app-id
FACEBOOK_CLIENT_SECRET=your-facebook-app-secret
APPLE_CLIENT_ID=your-apple-service-id
APPLE_TEAM_ID=your-apple-team-id
APPLE_KEY_ID=your-apple-key-id
APPLE_PRIVATE_KEY_PATH=/absolute/path/to/AuthKey_KEYID.p8
```

Use these OAuth callback URLs in the provider dashboards:

```text
Google:   https://your-domain.com/oauth.php?provider=google&callback=1
Facebook: https://your-domain.com/oauth.php?provider=facebook&callback=1
Apple:    https://your-domain.com/oauth.php?provider=apple&callback=1
```

Apple Sign In requires HTTPS and a Services ID configured with the same callback URL.
