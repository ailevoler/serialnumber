# UBarangay (2026-09-29)

## Deploy
1. Back up the database.
2. phpMyAdmin > Import `database/ubarangay_migration.sql` (after `admin_platform_migration.sql`). Safe to re-run.
3. Upload the files. `storage/` must be writable (selfies and requirement files go to `storage/brgy/`, blocked from the web).
4. Admin > UBarangay > **Permit types & officials** (Super Admin):
   - Set each permit's fee to your barangay ordinance rate (the defaults are placeholders), validity, requirements and certificate text.
   - Enter the Punong Barangay, Barangay Secretary, hall address and contact for Balabag, Manoc-Manoc and Yapak. These print on certificates.
5. Admin > Admin users > add a **Barangay Officer** for each barangay. Officers only see UBarangay for their own barangay.

## Resident flow (app > UBarangay tile)
1. Choose barangay (once). Tabs: Permits, News, Announcements, Activities.
2. Permits > choose a permit > fill in the form > take a selfie (front camera, or upload a photo) > upload each requirement (photo or PDF) > certify.
3. Pay with Credits, BCash (instant) or QR Ph (PayMongo checkout; confirmed by webhook or on return). Free permits (e.g. Indigency) skip payment.
4. The Barangay Officer reviews: Approve (issues certificate number like BAL-2026-00001), Needs more info (resident updates and resubmits, no new payment) or Reject (Credits/BCash refunded automatically; QR Ph refunds are done in the PayMongo dashboard).
5. Approved: "View & print certificate" (A4, prints or saves as PDF) with the selfie, officials, validity and a verification QR.

## Verification
The QR on each certificate opens `/verify.php?t=...` (no login): shows Valid / Expired / Revoked with the certificate number and a masked name. Officers can revoke a certificate from the application page.

## Money
Fees are recorded in the ledger as "Owed to barangay" (Admin > Finance / General ledger), per barangay, so remittance to each barangay can be reconciled.

## Tests (CLI, on a COPY of the database)
    DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/ubarangay_test.php
