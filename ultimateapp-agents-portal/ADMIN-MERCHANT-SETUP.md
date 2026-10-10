# Admin Panel, Merchant Portal, P2M and Google Maps (2026-09-29)

## Deploy (in this order)
1. Back up the database and the current site files.
2. phpMyAdmin > your database > Import, in this order (all are safe to run again):
   - `database/news_migration.sql` (if not imported yet)
   - `database/qr_payments_migration.sql` (if not imported yet)
   - `database/admin_platform_migration.sql`
3. Upload the ZIP contents. Keep your live `config/config.php` if it differs.
4. Make sure the `config/` folder is writable once (the app creates `config/app_secret.php`,
   the encryption key for API secrets) and that `storage/` can be created/written
   (merchant documents). Both folders are blocked from the web by `.htaccess`.
   Back up `config/app_secret.php` with your site: without it, saved secret keys must be re-entered.
5. Open `https://YOUR-DOMAIN/admin/` > first-time setup. Enter the database password
   (from config/config.php) and create the Super Admin. The setup page disables itself afterwards.

## Admin Panel (`/admin/`)
Roles: Super Admin (everything), Finance (payments, refunds, finance, ledger, settlements,
reports, Credits adjustments), Support (customers, merchant review, tickets, notes).
- CRM: customer 360 (balances, activity, P2M/P2P, top-ups, tickets, tags, notes, suspend,
  Credits adjustment), merchants list / onboarding pipeline / map, merchant 360
  (documents, approve/reject/needs info/suspend, payout, notes), support tickets.
- ERP: payments (P2M, P2P, top-ups, CSV, refunds), finance (liabilities, revenue,
  trial balance, reconciliation), general ledger (double-entry, CSV), settlements
  (payout batches), daily reports (CSV).
- Settings (Super Admin): PayMongo keys (encrypted; override config/paymongo.php) with
  a "Test connection" button, charges & limits, Google Maps.
- Admin users and a full audit log.

## Charges (Settings > Charges & limits)
Separate rules for paying merchants with Credits and with BCash:
percentage + fixed charge, and who pays it (customer = added on top; merchant = deducted,
like MDR). Each payment stores the charge it was made with. Buy Credits and MCTC fees
are also editable there.

## Merchant Portal (`/merchant-portal/`)
Pre-onboarding registration (business, owner/login, registration numbers, documents,
payout account, optional map pin) > Admin review > Approved. Merchants then get a
payment QR (printable, optional fixed amount), payments list + CSV, payouts, profile,
and support tickets. Merchant logins are separate from app logins.

## App users
- Scan QR: merchant QR (UM-...) = P2M, person QR (UA-...) = P2P.
- P2M: choose Credits or BCash; the charge and total are shown before paying.
- "Where to pay" map of approved merchants; Help & Support tickets.
- New sign-ups no longer receive 1,250 welcome Credits (existing balances unchanged).

## Google Maps (Settings > Google Maps)
Add a browser key with the Maps JavaScript API enabled, restricted by HTTP referrer to
your domain. Used by URide, merchant location pins, the admin merchant map and the app
"Where to pay" map. Without a key, pages fall back to lists and Google Maps links.

## Time zone
PHP and MySQL sessions now both use Philippine time (Asia/Manila / +08:00), so dates
in reports, filters and receipts match.

## Tests (CLI, on a COPY of the database)
    DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/p2m_test.php
    DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/qr_pay_test.php
