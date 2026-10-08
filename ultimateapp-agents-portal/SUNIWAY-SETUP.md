# UBills, ULoad and UCash In (SUNIWAY Partner API)

These are three new home screen services. Customers pay with **Credits**.

| Tile | What it does | SUNIWAY group |
|---|---|---|
| **UBills** | Bills payment: electricity (AKELCO), water, internet, cable, SSS, Pag-IBIG, PhilHealth, etc. | `bills` |
| **ULoad** | Prepaid load for Globe, TM, Smart, TNT and DITO | `eload` |
| **UCash In** | Sends Credits to the customer's GCash, Maya, ShopeePay and other e-wallets | `ecCash` |

UCash In is **not** Buy Credits. Buy Credits brings money into the app, and UCash In sends it out to another e-wallet.

The integration follows the SUNIWAY flow used in the SUKLI Partner Store: `GET providers` → `POST transactions/preview` → `POST transactions` → `GET transactions/{id}`.

## Deploy

1. Back up the database.
2. Import `database/suniway_migration.sql` **once**.
3. Upload the new and changed files.
4. Go to **Admin › Bills & Load (SUNIWAY) › Settings**:
   - Enter the API base URL from your SUNIWAY dashboard. The default is `https://api-sunikiosk.suniway.ph/api/partner-api`.
   - Paste the **Partner API key**. It is stored encrypted and is never shown to customers.
   - Click **Test connection**. You should see how many billers, load products and e-wallets were found.
   - Optionally set a **convenience fee** per service. It is added on top and kept as Ultimate App revenue.
   - Tick **Accept UBills, ULoad and UCash In payments** and save.

The three tiles are always on the home screen. Until payments are turned on, or if the migration has not been imported yet, their pages show **Coming soon**.

The server needs PHP cURL, which Hostinger has.

## How a payment works

1. The customer picks a biller, network or e-wallet (popular ones appear as shortcuts), then enters the account or mobile number and the amount.
2. **Review**: SUNIWAY's preview shows the provider fee. The customer sees the full total in Credits before paying.
3. **Pay**: the Credits are deducted first, then the transaction is sent to SUNIWAY. A double tap never charges twice.
4. Results:
   - **Successful**: done. The receipt shows the SUNIWAY reference.
   - **Processing**: the receipt checks SUNIWAY again automatically. If it later fails, the Credits are refunded automatically.
   - **Rejected** by SUNIWAY, or **Failed**: Credits are refunded automatically.
   - **Checking** (SUNIWAY did not reply in time): Credits stay on hold, because the payment may have gone through. Look up the transaction in the SUNIWAY dashboard. Then, in Admin › Bills & Load › *Needs checking*, choose **Refund** if it is not there, or **Mark successful** if it is.

## Books (general ledger)

- Payment: debit `user_credits:<id>`, credit `suniway_payable` (amount + SUNIWAY fees), credit `fee_revenue:<service>` (your convenience fee).
- Refund: the exact reverse.
- Settle `suniway_payable` with SUNIWAY according to your partner agreement (prepaid wallet or invoice).

## Roles

- Finance and Support can view transactions. Finance can also refund or confirm them (`payments.refund`).
- Only a Super Admin can change the settings and the API key.

## Test (no real money)

A fake SUNIWAY server is included for testing on a copy of the database:

```bash
php -S 127.0.0.1:8098 tests/suniway_fake_api.php &
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/suniway_test.php
```

Never run the test against the real SUNIWAY API.

## Files

New:
- `ubills.php`, `uload.php`, `ucashin.php`, `suniway-receipt.php`
- `includes/suniway.php`, `includes/suniway_page.php`
- `admin/suniway.php`
- `assets/css/upay.css`, `assets/js/upay.js`
- `assets/images/services/ubills.png`, `uload.png`, `ucashin.png`
- `database/suniway_migration.sql`
- `tests/suniway_test.php`, `tests/suniway_fake_api.php`

Changed:
- `includes/functions.php` (tiles)
- `includes/settings.php` (SUNIWAY settings and encrypted key)
- `includes/ledger.php` (account name)
- `includes/header.php` (stylesheet)
- `admin/_bootstrap.php` (menu)
