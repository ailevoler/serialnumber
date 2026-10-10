# URide — passenger booking + Driver App / Portal

URide now runs end to end: passengers book and pay, nearby drivers receive the request and accept it, the trip is tracked live, and money moves through the driver wallet and the general ledger.

## Deploy (Hostinger)
1. Back up the database and site files.
2. Import `database/ALL_UPDATES_2026-09.sql` in phpMyAdmin (safe to import again — it skips what is already applied). It adds the driver, wallet, top-up and payout tables, extends `uride_requests`, seeds a starting fare matrix, and closes old "requested" rows saved before dispatch existed.
3. Upload the app (keep `config/config.php`, `config/app_secret.php` and `storage/`). New folder: `/driver/`. Make sure `storage/` is writable (driver documents go to `storage/driver_docs/`, blocked from the web).
4. PayMongo webhook: keep **payment.paid** and **checkout_session.payment.paid** subscribed — driver top-ups (reference `DT-…`) use the same webhook as Buy Credits.
5. Admin › Settings › **URide fares & commission**: set your approved fare matrix, commission, broadcast radius and wallet rules. The seeded fares are placeholders.
6. Admin › Settings › Google Maps: a browser key is needed for place search and the live trip map (see "Google Maps" below).

## Links
| Who | URL |
|---|---|
| Passengers | App › URide (`/uride.php`) |
| Drivers — sign up | `/driver/register.php` |
| Drivers — app | `/driver/` (email or mobile + password; add to home screen) |
| Admin | Admin › URide › Drivers · Rides · Driver payouts |

## How a ride works
1. **Passenger** pins pick-up and drop-off. The server quotes an **upfront fare** for each vehicle from the fare matrix; it does not change during the trip.
2. Pays with **Credits** (fare is put on hold at booking) or **cash** (paid to the driver).
3. The request is **broadcast** to approved, online drivers of that vehicle type within the broadcast radius (default 3 km) whose GPS updated in the last 2 minutes. Drivers hear a beep and see fare, distance, pick-up, drop-off and their earnings.
4. **First to accept wins** (database row locks — tested with simultaneous accepts). A driver can Skip a request, or give an accepted ride back before pick-up (it goes back to other drivers).
5. Driver taps **Arrived → Start trip → Complete**. The passenger's page shows the driver's photo, name, vehicle, plate, a call button, ETA and the live position.
6. Requests nobody accepts within the timeout (default 5 minutes) close automatically and held Credits return to the passenger. Passengers can cancel until the trip starts; admins can cancel any active ride.
7. Passenger rates the driver (1–5 stars).

## Money (all ledgered, centavo-exact)
| Event | Passenger | Driver wallet | Ledger |
|---|---|---|---|
| Book with Credits | − fare (held) | — | user_credits → uride_escrow |
| Complete, Credits | — | + fare − commission | uride_escrow → driver_wallet + fee_revenue:uride |
| Complete, cash | pays driver cash | − commission | driver_wallet → fee_revenue:uride |
| Cancel / expire (Credits) | + fare returned | — | uride_escrow → user_credits |
| Driver top-up · QR Ph | — | + amount | paymongo_clearing → driver_wallet (+ fee_revenue:driver_topup) |
| Driver top-up · MCTC (cash) | — | + amount | mctc_collections:AGENT → driver_wallet (+ fee) |
| Driver top-up · BCash | payer's BCash − amount | + amount | user_cash:PAYER → driver_wallet (no fee) |
| Payout requested → paid | — | − amount | driver_wallet → settlement_payable:driver_N → bank_clearing |
| Payout returned | — | + amount | back to driver_wallet |
| Admin adjustment | — | ± amount | adjustments ↔ driver_wallet |

- **Commission** is a percentage of the fare or a fixed amount per trip (never more than the fare), set in Settings.
- Drivers need at least the **minimum wallet** (default ₱50) to go online; for a cash ride the wallet must also cover that ride's commission.
- Admin › Finance shows driver wallets, fares on hold, payouts awaiting transfer, URide commission, and a check that every driver wallet matches the ledger.

## Driver wallet top-up methods
Drivers choose the method in Driver App › Wallet (Admin can switch each one on/off in Settings › URide):
- **QR Ph** — PayMongo QR shown in the app (or hosted checkout fallback); credited by the webhook or the page's status check.
- **MCTC** — the app creates a request QR (valid 24 hours). The driver pays cash at an MCTC top-up center — a Merchant Portal merchant with MCTC enabled — who scans it in **Merchant Portal › MCTC Top-up**, enters the receipt number and confirms. The cash is added to that merchant's MCTC cash due and deducted from their next payout (see MERCHANT-PORTAL-SETUP.md). Finance can also Approve a request in Admin when the cash was paid at the office. Old app-account MCTC agents still work (ledger `mctc_collections:ID`).
- **BCash** — the app creates a request QR/link (valid 30 minutes). Anyone signed in to Ultimate App (the driver's own passenger account or someone else's) opens it — via **Scan QR**, the phone camera, or the "Pay with my Ultimate App account" button — and confirms. No fee.
The service fee setting applies to QR Ph and MCTC. Drivers can have up to 3 open MCTC/BCash requests and can cancel unpaid ones.

## Admin: top-ups and MCTC agents
- **URide › Driver top-ups** lists every request (QR Ph, MCTC, BCash) with status (Pending, Paid, Expired, Cancelled), who paid (MCTC agent + receipt, or BCash payer), CSV export, and **cash held by each MCTC agent** to remit. Pending MCTC requests show the approval link.
- A pending top-up does not change the wallet, so it appears in the driver's **Top-up requests** card (Admin › Drivers › driver) and only in the Wallet statement once paid.
- **MCTC agents** are Ultimate App accounts with the MCTC role. A Super Admin gives or removes it in CRM › Customers › open the customer › **Make MCTC agent** (filter the list with "MCTC agents"). The agent then sees MCTC Scanner in Profile.

## Uploaded files (selfies, documents)
Uploads are stored on disk in `storage/` (not in the database). Admin › System › **Files & storage** shows the folder, runs a write test, and lists records whose files are missing. Files go missing when the app folder is deleted and re-extracted, or when two domains share one database but not one folder. To prevent it, copy `config/storage.php.example` to `config/storage.php` with a folder outside `public_html`, move the existing `storage/` contents there, and use the same path on every domain that shares the database.

## Driver onboarding
Registration asks for personal details, a **camera selfie** (shown to passengers), license number/expiry, vehicle type/plate/model/color, franchise/TODA, and documents (license front and OR/CR required; license back, franchise/TODA ID, NBI/Police clearance and vehicle photo optional). Status: Submitted → Under review / Needs info → Approved (or Rejected / Suspended). Support can move drivers to Under review / Needs info; only a Super Admin can approve, reject or suspend. Approval is blocked if the license has expired. Drivers with "Needs info" update details/documents and resubmit from Account.

## Admin roles
| Permission | Super Admin | Finance | Support |
|---|---|---|---|
| View drivers & rides | ✓ | ✓ | ✓ |
| Review drivers (under review / needs info) | ✓ | | ✓ |
| Approve / reject / suspend, reset password | ✓ | | |
| Cancel rides | ✓ | | ✓ |
| Driver wallet adjustments, driver payouts | ✓ | ✓ | |
| URide settings | ✓ | | |

## Google Maps
Enable **Maps JavaScript API** and **Places API (New)** on the browser key (HTTP-referrer restricted to your domain), with billing on. Without a key: passengers cannot pin places (booking needs pins), the trip pages show text instead of a map, and drivers can still use **Navigate**, which opens Google Maps directions.

Distances are straight-line × road factor (default 1.3), not a routed distance — adjust the factor to match real trips.

## Before going live
- Confirm the fare matrix, night surcharge, commission and cancellation rules with the LGU / TODA.
- Test on real phones: the Driver App must stay open with location allowed to receive requests (browsers pause background GPS). Use HTTPS.
- Decide payout schedule and who transfers (Finance marks each payout paid with the transfer reference).

## Tests
`php tests/uride_test.php` (with DB_* env vars and `PM_FAKE_API=1` on a copy of the database) runs 55 checks: fare maths, night surcharge, commission, top-up via QR Ph webhook + replay, MCTC approval (agent-only, receipt reuse, expiry), BCash payment (replay, insufficient, cancelled), broadcast radius and stale GPS, first-accept-wins, release, cancel/expiry refunds, cash vs Credits settlement, payouts, and ledger/wallet/escrow reconciliation.
