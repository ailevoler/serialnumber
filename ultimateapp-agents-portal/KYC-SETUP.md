# KYC (identity verification)

Every account type can verify its identity: **customers, merchants, URide drivers and agents**. Each verification has three parts:

1. **Valid ID, front and back.** The person chooses the ID type (PhilSys, passport, driver's license, UMID, SSS, PRC, postal, voter's, PhilHealth, TIN, senior, PWD, or a foreign passport) and takes or uploads both sides.
2. **Selfie liveness check.** The front camera asks: **look straight → turn left → turn right → look up → look down → blink**. A photo is taken automatically at each step.
3. **Review and send.** The person sees all 8 photos, agrees, and sends them.

## Where people find it

| Account | Page |
|---|---|
| Customer | Profile › **Verify my identity** (`/kyc.php`) |
| Merchant | Merchant Portal › **Verify ID** (also a step in the onboarding checklist) |
| Driver | Driver app › Account › **Verify ID (KYC)** |
| Agent | Agents Portal › **Verify ID** (and a banner on the dashboard) |

## Deploy

1. Back up the database, then import `database/kyc_migration.sql` **once**.
2. Upload the files, including `assets/vendor/mediapipe/` (about 22 MB). This folder is the face-detection engine, which runs on the person's phone.
3. Make sure `storage/` is writable. Photos are saved in `storage/kyc/`, outside public access.
4. Open **Admin › KYC verification › Rules** and choose when a verified ID is required.

The site must be on **HTTPS**, because browsers only open the camera on secure pages.

## Rules (Admin › KYC verification › Rules)

| Rule | Default | What it blocks until the ID is verified |
|---|---|---|
| Merchants | On | Admin cannot approve the merchant |
| URide drivers | On | Admin cannot approve the driver |
| Agents | On | Admin cannot approve the agent; the agent cannot cash out |
| Customers | Off | Sending Credits to another person (scan to pay a person) and UCash In |

Accounts that are already approved keep working. The rules only apply to new approvals and to the money-out actions above.

## Reviewing (Admin › KYC verification)

- **To review** lists the queue, oldest first. Open one to see the ID front and back, the six liveness photos with timings, and the account it belongs to.
- **Check that:**
  - the same person appears in every photo and on the ID;
  - the head really turns (it is not a photo of a photo or a screen);
  - the eyes are closed in the **Blink** photo;
  - the name on the ID matches.
- **Decision:** **Approve**, **Ask to resubmit** (blurry, glare, wrong side…), or **Reject** (fake, or not the same person). The person sees your message and can try again.
- **"Manual"** means the phone could not run the automatic face check, so the person took the six photos by tapping a button. Look at those more carefully.
- **Who can do what:** Support and Super Admin can decide. Finance can view only.

## How the liveness check works (and its limits)

- The face check uses **MediaPipe Face Landmarker** (Google, Apache-2.0), **self-hosted** in `assets/vendor/mediapipe`. It runs entirely on the person's phone; no video is uploaded, only the six still photos.
- **Head turns** are measured from where the nose sits between the cheeks (left / right) and between the forehead and chin (up / down), compared with the person's own "look straight" pose. **Blink** uses the model's eye-blink scores.
- If someone stays stuck on a step for 20 seconds, a **"Having trouble? Take this photo"** button appears, and the submission is marked **Manual**.
- **This is not a guarantee.** Because the check runs on the phone, a determined person could tamper with it. That is why **every submission is reviewed by a person** before it counts as verified. For bank-grade checks (government database matching, deepfake detection), connect a KYC provider later; the review flow stays the same.

## Privacy

- Photos are re-encoded on the server. This removes EXIF and GPS data and anything that is not an image.
- Photos are stored in `storage/kyc/` with random file names, and are only shown to signed-in Admin users with KYC access.
- Each submission records the IP address and browser for audit.

## Test

```bash
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/kyc_test.php
```

## Files

New:
- `includes/kyc.php`, `includes/kyc_page.php`
- `assets/js/kyc.js`, `assets/css/kyc.css`, `assets/vendor/mediapipe/`
- `kyc.php`, `merchant-portal/kyc.php`, `driver/kyc.php`, `agent-portal/kyc.php`
- `admin/kyc.php`, `admin/kyc-file.php`
- `database/kyc_migration.sql`, `tests/kyc_test.php`

Changed:
- `includes/settings.php` (KYC rules)
- `includes/qr_pay.php` (sending Credits rule)
- `includes/suniway.php` (UCash In rule)
- `includes/agents.php` (agent cash-out rule)
- `profile.php`
- `merchant-portal/_bootstrap.php`, `merchant-portal/dashboard.php`
- `driver/account.php`
- `agent-portal/_bootstrap.php`, `agent-portal/dashboard.php`
- `admin/_bootstrap.php`, `admin/merchant.php`, `admin/driver.php`, `admin/agent.php`
- `tests/agents_test.php`
