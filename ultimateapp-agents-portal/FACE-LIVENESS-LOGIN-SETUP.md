# Guided Liveness, Face Match with ID, and Face Login

## What changed

### 1. Guided liveness selfie (KYC)
The steps are Look straight, Turn LEFT, Turn RIGHT, Look UP, Look DOWN and BLINK.

- The side of the oval to move to **flashes red**, with an arrow. For example, on "Turn LEFT" the left side flashes red.
- When the person does it right, the frame turns **green** and the photo is **taken automatically**.
- If they turn the wrong way, the frame flashes red, an error appears ("That is your RIGHT. Turn to your LEFT"), the phone vibrates, and **nothing is captured**.

How each check works:
- The head turn is measured against the person's own "look straight" position, so it works for every face shape.
- Each pose must be held for a few frames.
- Only one face is allowed, and the face must be inside the oval at the right distance.
- The number of wrong turns is saved and shown to Admin.

### 2. Face match with the ID
After the six photos, the phone compares the "Look straight" selfie with the face on the front of the ID. It uses face-api, which is open source (MIT) and stored on our own server.

- If the faces do not match, the person sees "Not verified (match X%)" and cannot send. They can retake the selfie or change the ID photo.
- The server checks the face numbers again. A selfie that does not match is refused even if someone bypasses the page.
- **Admin › KYC** shows the match % and the distance for every submission, plus how many wrong turns the person made. Admin still makes the final decision.

### 3. Face Login (customers)
This lets a customer log in with their face on their own phone. It uses the face from their approved KYC.

**Set up** (Profile › Face Login):
1. The person does a short liveness check: look straight, turn left, turn right, blink.
2. The live face must match their approved KYC face.
3. The phone gets a secret device key: a cookie that is HttpOnly and SameSite=Strict. Only its SHA-256 is saved on the server.

**Log in** (Log In › "Log in with Face"):
1. The server gives random steps and a one-time code that is valid for 2 minutes. The steps are look straight, then two random turns and a blink in random order, so a video recorded earlier will not follow them.
2. The server checks:
   - the code;
   - that the steps were done in that order, at a believable speed, with no manual photos;
   - the distance between the live face and the face set up on this phone.
3. After **5 wrong tries**, Face Login locks on that phone. Logging in with the password unlocks it.

**Turn off:** the person can turn off Face Login per phone, or on all phones (for example, if the phone is lost).

**Admin › KYC › Rules** has:
- the KYC max distance (default 0.60);
- the Face Login max distance (default 0.45, where lower is stricter);
- an on/off switch for Face Login;
- counts of phones and of face logins from the last 7 days.

## Install
1. Upload the files from the zip.
   - Keep `assets/vendor/face-api/` (about 14 MB).
   - Keep `assets/vendor/mediapipe/` from the KYC update.
2. In phpMyAdmin, import **`database/face_migration.sql`** once. It is safe to run again.
3. Optional: run the tests on a copy of the database.
   ```
   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/face_test.php
   ```

## New and changed files
- **New:**
  - `includes/face_login.php`, `face-login.php`, `face-login-setup.php`
  - `assets/js/face-engine.js`, `assets/js/face-login.js`
  - `assets/vendor/face-api/`
  - `database/face_migration.sql`, `tests/face_test.php`
- **Changed:**
  - `includes/kyc.php`, `includes/kyc_page.php`, `includes/settings.php`
  - `assets/js/kyc.js`, `assets/css/kyc.css`
  - `admin/kyc.php`, `login.php`, `profile.php`

## Good to know
- **Everything runs on the phone.** Photos are not uploaded for Face Login; only 128 numbers are sent.
  - Because the check runs on the phone, Face Login is a **convenience login for the person's own phone**, like a fingerprint unlock. It is not a bank-grade identity proof.
  - The password still works.
  - Keep the PIN and limits on sending money as they are.
- **The phone needs a modern browser:** Chrome or Safari from the last 3 years.
  - On phones where the automatic face check cannot run, KYC falls back to manual photos, which Admin reviews carefully.
  - Face Login is not offered on those phones.
- **Distance guide:** same person is usually 0.15–0.45, and different people are usually above 0.55.
  - Older ID photos can be up to about 0.55, which is why KYC uses 0.60 and Admin reviews every KYC.
- **Older approved KYCs** have no face numbers. When those customers set up Face Login, the phone reads the face from their own approved KYC selfie.
- The first time the camera opens, the face models download, which takes about 15 MB. After that they come from the phone's cache.
