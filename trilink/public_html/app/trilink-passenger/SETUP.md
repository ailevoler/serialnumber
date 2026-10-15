# TriLink PHP Platform – Deployment Guide (Hostinger)

## Files Overview
```
trilink-php/
├── index.php               ← Auto-redirect (login/home)
├── config.php              ← DB + App config (EDIT THIS)
├── database.sql            ← Run this in phpMyAdmin
├── .htaccess               ← Apache rules
├── includes/
│   └── core.php            ← DB, Auth, Session, Helpers
├── auth/
│   ├── login.php           ← Phone + PIN login
│   ├── register.php        ← New account registration
│   └── logout.php          ← Session destroy
├── pages/
│   ├── home.php            ← Main passenger dashboard
│   ├── bookings.php        ← Booking history
│   └── tracking.php        ← Live ride tracking
├── api/
│   ├── booking.php         ← Ride booking API
│   ├── delivery.php        ← Delivery booking API
│   └── wallet.php          ← Wallet top-up, send, history
└── assets/
    ├── css/auth.css        ← Login/register styles
    ├── css/app.css         ← App styles
    ├── js/pin.js           ← PIN input handler
    └── js/app.js           ← App logic
```

---

## Step 1 – Create MySQL Database (Hostinger hPanel)
1. Go to hPanel → **Databases** → MySQL Databases
2. Create database: `u_trilink` (or your preferred name)
3. Create user and assign ALL PRIVILEGES
4. Go to **phpMyAdmin** → select your DB
5. Click **Import** → upload `database.sql` → Execute

---

## Step 2 – Edit config.php
```php
define('APP_URL', 'https://yourdomain.com/trilink');  // ← YOUR URL
define('DB_NAME', 'u_trilink');   // ← your DB name
define('DB_USER', 'u_trilink_user');  // ← your DB user
define('DB_PASS', 'yourpassword');    // ← your DB password
```

> ⚠️ **Hostinger Note**: Always hardcode APP_URL. Never use $_SERVER['HTTP_HOST'] auto-detection — it breaks on Hostinger shared hosting.

---

## Step 3 – Upload Files
1. Upload the entire `trilink-php/` folder to your Hostinger `public_html/trilink/` via File Manager or FTP
2. Make sure `uploads/avatars/` directory has **write permission** (755 or 777)

---

## Step 4 – Set PHP Version
In hPanel → **Advanced** → **PHP Configuration**
- Set to **PHP 8.1** or **PHP 8.2**
- Enable extensions: `pdo_mysql`, `mbstring`, `json`, `session`

---

## Step 5 – Test
- Visit: `https://yourdomain.com/trilink/`
- You'll be redirected to `/auth/login.php`
- Click **Sign up for free** to create your first account
- Login with phone + PIN

---

## Auth Flow
```
Register → Phone + Full Name + 6-digit PIN
Login    → Phone + PIN → Session created
Protected pages check Auth::requireLogin()
PIN locked after 5 failed attempts for 30 minutes
```

## Booking Flow
```
home.php → Book Ride form → POST /api/booking.php
         → Fare calculated server-side from tl_fare_matrix
         → Wallet debited if payment_method = 'wallet'
         → Redirect to /pages/tracking.php?code=TL250627-XXXXX
         → Auto-polls every 5s for status updates
```

## Wallet Top-up (Production)
Walang auto-credit. Every top-up is a pending request in `tl_topups`:
- **QR Ph** (GCash, Maya, any bank app) via PayMongo — credited only after PayMongo confirms the payment.
- **Teller** (cash at a TriLink Load Center) — credited only after the teller confirms in the Load Center app.

Setup and go-live steps: see `../trilink-pay/README.md`.

---

## Security Features
- CSRF tokens on all forms
- bcrypt PIN hashing (cost 12)
- PIN lockout after 5 failed attempts
- Session regeneration on login
- Parameterized PDO queries (no SQL injection)
- `.htaccess` blocks direct access to `includes/` and `config.php`
- `X-Frame-Options`, `X-XSS-Protection` headers

---

## ModSecurity (Hostinger WAF)
If you get 403 errors on form submissions with special characters,
add base64 encoding for those fields (known Hostinger WAF issue):
```php
// In config.php, add:
define('WAF_BYPASS', true); // encode sensitive POST data as base64

// In form:
<input type="hidden" name="pickup_b64" value="">
<script>document.querySelector('[name=pickup_b64]').value = btoa(pickupAddr);</script>

// In PHP:
$pickupAddr = base64_decode($_POST['pickup_b64'] ?? '');
```

---

## Default Admin Credentials
After import, reset the admin password:
```sql
UPDATE tl_admins SET password = '$2y$12$YOURHASH' WHERE username = 'superadmin';
```
Generate hash: `echo password_hash('YourNewPassword', PASSWORD_BCRYPT, ['cost'=>12]);`
