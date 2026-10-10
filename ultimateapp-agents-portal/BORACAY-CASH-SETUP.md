# BCash wallet

## Deploy

1. Back up the database.
2. Import `database/boracay_cash_migration.sql` once, after `database/schema.sql` and `database/paymongo_migration.sql`. Then import `database/boracay_cash_reverse_migration.sql` once. If the BCash tables already exist, run only the reverse migration. The tables must use InnoDB for atomic balance changes. Run migrations before uploading the new PHP code.
3. Upload the complete app, including `boracay-cash.php`, `boracay-cash-receipt.php`, `includes/boracay_cash.php`, `assets/js/boracay-cash.js`, and `assets/css/boracay-cash.css`.
4. Set `BORACAY_CASH_USD_PHP_RATE` on the server only after reviewing a current PHP-per-USD reference rate. Example format: `58.25`. There is intentionally no built-in default. Without a rate, the USD tab says it is unavailable.

## Behavior

- Swipe left on the dashboard Credits card to see the gray BCash card; dots are also buttons for switching wallets.
- Converting 100 Credits deducts exactly 100 Credits and adds PHP100.00 to BCash. This in-app transfer has no extra fee. MCTC cash-out and Buy Credits fees are separate flows.
- Converting PHP100.00 BCash deducts exactly PHP100.00 and adds 100 Credits. Existing conversion records default to the original Credits-to-Cash direction.
- The database stores BCash in integer PHP centavos. USD is only an indicative display calculated from the configured rate; it is not a second balance, an exchange transaction, or a payout.
- The server locks the user row and atomically updates both wallets, writes a unique directional conversion record, and adds a signed Credits transaction. Insufficient balance rolls back both changes. Repeated submissions with the same request key return the original receipt rather than converting twice.
- There is no BCash payment, withdrawal, refund, or transfer to banks/merchants in this release. Do not represent it as redeemable cash until those flows and their controls are implemented.
- Boracay service listings display prices in Credits, not PHP. Demo listing requests still do not charge either wallet.

## Before production

Run `php tests/boracay_cash_test.php`, exercise concurrent duplicate requests against a staging MySQL database, reconcile wallet totals with the conversion ledger, and test mobile swipe and currency tabs. Review consumer disclosures and applicable stored-value/e-money requirements with counsel before enabling real monetary use: https://www.bsp.gov.ph/Regulations/Published%20Issuances/Images/Circular_1166.pdf

## BCash name and theme

Boracay Cash is now called **BCash** everywhere in the app. Database tables, file names and settings keep their old names (`boracay_cash…`), so nothing needs to be migrated.

When a customer swipes the home wallet card to **BCash**, the home screen switches to the BCash blue theme: the BCash logo in the header, blue service tiles, a blue Scan button, blue accents and a blue phone status bar. Swiping back to **Credits** brings back the usual theme. The app remembers the last wallet on that phone, so the customer comes back to the same one. The BCash convert and receipt pages always use the blue theme.

Files: `assets/css/bcash.css`, `assets/images/bcash/`, `assets/js/boracay-cash.js`, `dashboard.php`, `includes/header.php`.
