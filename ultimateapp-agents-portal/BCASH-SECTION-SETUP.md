# BCash section (Quick Action Menu, Send / Receive, BCash QR)

When a customer swipes the home wallet card to **BCash**, the Quick Action Menu changes from Services to the BCash actions:

| Tile | Opens | Status |
|---|---|---|
| Send | Scan a BCash QR and send BCash (`bcash-qr.php`) | Working |
| Receive | Create a QR for a set amount (`bcash-qr.php?tab=receive`) | Working |
| My QR | Personal BCash QR to show, save or share (`bcash-qr.php?tab=myqr`) | Working |
| BCT | BCash Token page (`bcash-token.php?view=bct`) | Coming soon page |
| Buy BCT | (`bcash-token.php?view=buy`) | Coming soon page, nothing is sold |
| Markets | (`bcash-token.php?view=markets`) | Coming soon page, no prices shown |
| Convert | Credits ⇄ BCash (`boracay-cash.php`) | Working |
| Community | Emergency, LGU Malay, UBarangay, barangays, UNews, UHelp (`community.php`) | Working |

The BCash card buttons are now **Send, Receive, Convert, History**.

## BCash QR (separate from the Credits QR)

- The BCash QR has the **BCash logo in the middle** and opens **Send BCash**. The Credits QR (Scan QR › My QR) still opens **Pay QR** with Credits.
- Whichever page scans it (Scan QR or BCash Send), a BCash QR always sends BCash and a Credits QR always pays Credits.
- Typing a bare QR ID (UA-…) on the BCash Send tab sends BCash.
- The QR uses high error correction, so it still scans with the logo. Tested by scanning the saved image.

## Send rules

- PHP 1 to 10,000 per transfer, no fee, instant. Each transfer is booked in the ledger (`user_cash` sender to receiver).
- Double tap and repeated submits never send twice.
- Max 20 sends per hour per account.
- When **Admin › KYC › Customers** is on, an unverified customer cannot send BCash (the same rule as sending Credits).
- Transfers appear in **Transactions** and under **Transactions › BCash**.

## Deploy

1. Back up the database, then import `database/bcash_transfers_migration.sql` once.
2. Upload the files from the update zip.

Until the SQL is imported, Send shows "BCash Send is not set up yet" and nothing else breaks.

## Test

```bash
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/bcash_transfer_test.php
```
