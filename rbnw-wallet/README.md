# RBNW Wallet — Phase 1 (Sandbox)

Digital wallet for Rural Bank of New Washington. Built to match the
`RBNW-PROJECT.pdf` reference deck: same brand gradient (deep blue → green),
card-based mobile UI, bottom tab nav, and the Login → Dashboard → Send Money
flow shown in the deck's UI mockups.

**Stack:** PHP 8+, MySQL, vanilla HTML/CSS/JS (no framework, no build step —
matches your usual Hostinger shared-hosting setup).

## What's built (this phase)

| Module | Status |
|---|---|
| Login / Register (MPIN, hashed, lockout after 5 tries) | ✅ Working |
| Dashboard (balance card, quick services, recent tx) | ✅ Working |
| Send Money (6-step flow: recipient → amount → review → MPIN → sending → success) | ✅ Working, wallet-to-wallet, atomic ledger |
| Transaction history | ✅ Working |
| Notifications | ✅ Working |
| Profile / Logout | ✅ Working |
| Top Up, Pay Bills, Loans, Save Money, Request Money, Bank Wallet, Donations, Cash Out, Scan QR | 🔜 Branded "Coming Soon" stubs — DB schema already covers them, ready to wire up next |

This mirrors the phased roadmap on the deck's last slide (Sandbox → Staging →
Production → Beta). Everything here is Sandbox-phase: functional core wallet
+ send money, with the rest of the schema in place so the remaining modules
are mostly UI + one API file each, not new architecture.

## Deploy to Hostinger — rbnw.surgemobileaccess.com

1. In hPanel, create the subdomain `rbnw.surgemobileaccess.com` if not yet
   created, pointing its document root to its own folder (e.g.
   `public_html/rbnw/` or wherever Hostinger puts new subdomains by
   default) — **not** a subfolder of `surgemobileaccess.com`'s root, so it
   gets a clean document root of its own.
2. Upload everything inside `rbnw-wallet/` straight into that subdomain's
   document root (so `login.php` sits at the root, not inside another
   `rbnw-wallet/` folder — otherwise your URLs become
   `rbnw.surgemobileaccess.com/rbnw-wallet/login.php`).
3. Create the MySQL database + user in hPanel, then import the schema:
   ```
   mysql -u u718203951_rbnwuser -p u718203951_rbnwwallet < database/schema.sql
   ```
   (or paste `database/schema.sql` into phpMyAdmin's SQL tab)
4. **Secrets:** copy `config.local.example.php` to `config.local.php` and
   put the real DB password and PayMongo keys there. `config.local.php` is
   gitignored so live keys never land in GitHub. Upload it together with
   the rest of the files.
   `config.php` already has `APP_URL` set to
   `https://rbnw.surgemobileaccess.com`. Just fill in the real
   `DB_NAME` / `DB_USER` / `DB_PASS` from hPanel > Databases (the
   `u718203951_` prefix in there is a guess based on your other
   subdomains — confirm the exact values in hPanel).
5. Set `ini_set('display_errors', '1')` in `config.php` temporarily if you
   need to debug — flip back to `'0'` before going live.
6. Visit `/register.php` to create your first test account. A wallet row is
   auto-created with ₱0.00 balance; top it up manually via phpMyAdmin for
   testing send-money end to end (`UPDATE rbnw_wallets SET balance = 5000
   WHERE user_id = 1;`) until Top Up is wired to a real cash-in source.

## Updating an existing database

When a new module adds tables/columns, run its file from
`database/migrations/` once in phpMyAdmin (SQL tab), oldest first:

- `2026-10-18-loans.sql` — Loans (schedule table + loan columns)
- `2026-10-19-savings.sql` — Save Money (interest tracking, goal fields)
- `2026-10-19-bank-wallet.sql` — Bank Wallet (transfers table, new ledger types)
- `2026-10-19-donations.sql` — Donations (anonymous flag, indexes)
- `2026-10-19-kyc.sql` — KYC submissions
- `2026-10-19-biometrics.sql` — Biometric login (passkey credentials)

## Save Money

GSave / Maya Savings / MariBank-style savings: instant transfers between
the wallet and "My Savings" (Flexi Save plan), up to `SAVINGS_MAX_GOALS`
savings goals (Goal Saver plan) with target and progress, daily-compounded
interest credited net of the 20% final withholding tax, and activity
history. Interest rates are the `interest_rate` values in
`rbnw_savings_plans` — set them to RBNW's approved rates.

## Bank Wallet

Link up to `BANK_MAX_LINKED` bank / e-wallet accounts (RBNW, BDO, BPI,
Landbank, GCash, Maya, ...), pick a primary, and transfer from the wallet
via InstaPay (fee, up to ₱50k, minutes) or PESONet (next banking day);
RBNW accounts are free. The wallet is debited when the transfer is
submitted and the transfer waits as **Processing** until RBNW operations
send it through the bank's InstaPay/PESONet channel, then settle it:

```
php tools/settle-bank-transfer.php list
php tools/settle-bank-transfer.php complete BT2026...
php tools/settle-bank-transfer.php fail BT2026... "Account closed"   # auto refund
```

For sandbox testing only, `BANK_TRANSFER_AUTO_COMPLETE` (config.local.php)
marks transfers completed immediately.

## Donations

Causes come from `rbnw_donation_causes` (name, description, category =
area filter chip, goal, optional `image` path). Users donate from the
wallet with MPIN, optionally anonymous and with a message; the cause's
`raised_amount` updates in the same DB transaction, and each cause shows
its recent supporters (first name + last initial, or "Anonymous").

## Installable app (PWA)

`manifest.webmanifest` (name, R icons incl. maskable, standalone display,
shortcuts) + `sw.js` + `offline.html`. The service worker caches only
static files (CSS/JS/images/fonts); pages and `/api/` always come from
the network, so balances and personal data are never stored on the
phone — offline you get a branded "You're offline" screen. An "Install
RBNW Wallet" card shows on the login page and dashboard (dismiss = hidden
for 7 days), and in Profile; on iPhone it opens Safari's "Add to Home
Screen" steps. Requires https. When you change `offline.html` or the
precache list, bump `VERSION` in `sw.js`. `.htaccess` serves the manifest
with the right type and makes browsers re-check `sw.js`.

## Biometric login (fingerprint / Face ID)

Uses WebAuthn passkeys with the phone's built-in fingerprint / face
unlock (`includes/webauthn.php`, no extra libraries; ES256 + RS256,
attestation "none", user verification required, replay and clone checks).
After the first MPIN login on a capable phone the user is offered to turn
it on (`security.php?offer=1`); it can be managed or removed per phone in
Profile → Security. Biometric login is tied to the exact domain it was set
up on and needs https (works on rbnw.surgemobileaccess.com, not on an IP
address). The fingerprint / face never leaves the phone.

## KYC (Verify Your ID)

Details (ID type/number, birthdate, address) → valid ID front and back
(camera or upload; passports skip the back) → selfie liveness → review →
submit. Liveness runs on the phone with face-api (vendored in
`assets/vendor/face-api`, MIT): five prompts in random order — turn
left, turn right, look up, look down, blink — each shown as a red arc /
chip around the face oval that turns green when done, then the selfie
is captured automatically. Photos are re-encoded server-side (EXIF
stripped) and stored in `KYC_STORAGE_DIR` — **set it to a folder outside
public_html in config.local.php**. Staff review with:

```
php tools/kyc-review.php list
php tools/kyc-review.php approve <id> [wallet_level]   # default level 2
php tools/kyc-review.php reject <id> "Blurry ID photo"
```

The liveness check is a client-side signal (recorded with the
submission), not proof on its own: always compare the selfie with the ID
photo during review.

## Loans

GLoan-style instant cash loan: credit limit by wallet level, amount
slider, 3/6/9/12-month terms, purpose, live quote, Truth-in-Lending style
disclosure (finance charge, EIR), MPIN confirmation, instant disbursement
to the wallet, and repayment (next installment or full) from the wallet.
Rates, fees and limits are the `LOAN_*` settings in `config.php` —
placeholders until RBNW sets the approved values (override them in
`config.local.php`). Loans need a verified (KYC) account; set
`LOAN_REQUIRE_KYC` to false in `config.local.php` only for testing.

## Notes on conventions used (matches your other apps)

- Page links, CSS/JS and redirects use app-relative paths (`app_url()` /
  `asset_url()`), not `APP_URL`, so login/register keep working on
  http/https, www, a temporary domain, or a subfolder. `APP_URL` is only
  used for absolute shareable links.
- `filemtime()`-based cache-busting on `assets/css/style.css` and
  `assets/js/app.js` via `asset_url()`.
- `SET time_zone = '+08:00'` forced on the PDO session in `config.php`.
- Every `/api/*.php` endpoint wraps output in `json_out()`, which flushes any
  stray notice/warning output before encoding — same defensive pattern as
  TriLink/SurgeBiz.
- MPIN stored via `password_hash()`/`password_verify()`, never plaintext.
- All money movement goes through `post_wallet_transaction()` in
  `includes/functions.php`, which locks the wallet row (`FOR UPDATE`) and
  writes a single ledger table (`rbnw_wallet_transactions`) — so balance and
  history can never drift out of sync.

## Next steps (suggested order)

1. **Top Up** — wire to PayMongo QR Ph like your other wallets, credit
   `rbnw_wallets` via `post_wallet_transaction()`.
2. **Pay Bills** — `rbnw_billers` table is seeded with the Aklan/Boracay
   billers from the deck; just needs the UI + `api/pay-bills.php`.
3. **Scan QR** — generate/read `rbnw_qr_codes`, reuse the Send Money PIN step.
4. **Loans / Save Money / Request Money / Donations / Bank Wallet** — schema
   ready in `database/schema.sql`, same pattern as Send Money.

Sabihin mo lang kung alin sa mga natitirang module ang unahin — same
structure lang ang gagamitin, mabilis na dapat.
