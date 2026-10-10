# PCEC Community Platform

Responsive web app for the **Philippine Council of Evangelical Churches**. Built with PHP 8, MySQL, HTML, CSS and vanilla JavaScript, with no frameworks or Composer packages.

- **Mobile:** app-style layout with a hero header, bottom navigation, and bottom-sheet modals.
- **Desktop (≥1024px):** left sidebar, centered feed, and a right rail (≥1320px) with upcoming events and people to follow. The login and register pages use a glass card over the scenic background.

## Features
| Module | What it does |
|---|---|
| Onboarding | 3-slide welcome (swipeable) with Skip / Get Started |
| Auth | Login with email or username, Remember me (secure token cookie), Register, Forgot / Reset password, Logout, EN/FIL language switch |
| Home | Welcome hero, post composer (Photo / Video / Event / File), quick-action tiles, banner, latest posts, upcoming events |
| Posts | Feed (All / Following / Saved / Mine), image/video/file uploads, like, comment, share, bookmark, delete |
| Events | List by tab and category, create with cover image, RSVP "I'm going", attendee list |
| Our Churches | Searchable directory filtered by region; leaders and admins can add churches |
| Members | Search, follow/unfollow, followers/following, message |
| Prayer Requests | Post (optionally anonymous), "I prayed" counter, mark answered |
| Resources | Share files or links by category, track opens |
| Chat | 1:1 messages with polling every 3 seconds, unread badges, online indicator |
| Notifications | Likes, comments, follows, RSVPs, prayers and messages, plus mark all as read |
| Profile | Edit photo, title, name, church and bio, plus change password |
| Giving | Donation & Giving page with One-Time / Recurring / Projects, preset or custom amounts, fund designation, and QR Ph payment with an inline QR code; receipts and My Giving history |
| Event Details | Cover hero, date/time/venue, Overview / Speakers / Schedule / Registration tabs, highlights, registration fee with QR Ph, Register Now (free or paid) |
| Admin | Dashboard, PayMongo & Giving setup, Payments (check status, CSV), Projects, Event registrations (CSV) |

Security: PDO prepared statements, CSRF tokens on every form and AJAX call, `password_hash`, session fixation protection, a login throttle, uploads checked by real MIME type with random filenames, PHP execution disabled in `uploads/`, and all output escaped.

## Setup (XAMPP / Laragon / LAMP)
1. Copy the `pcec-app` folder into `htdocs` (or your web root).
2. Import the database:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   (Or in phpMyAdmin, go to **Import** and choose `database/schema.sql`.)
3. Edit `config/config.php` with your DB credentials (or set the `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS` env vars).
   If the app lives in a sub-folder, set `BASE_URL` (e.g. `/pcec-app`).
4. Make sure `uploads/` is writable by the web server.
5. Open `http://localhost/pcec-app/`.

Quick start with PHP's built-in server:
```bash
cd pcec-app && php -S localhost:8080
```

### Demo accounts (password: `password123`)
| Username | Role |
|---|---|
| `johntan` | admin |
| `dsantos`, `mreyes`, `jlim` | leader |
| `gracev` | member |

## Install as an app (PWA)
Share **`https://your-domain/install.php`**. It is a separate public page that shows the right steps for Android, iPhone/iPad and computers, and has an **Install App** button where the browser supports it.
- **Files:** `manifest.json` (name, colors, icons, shortcuts), `sw.js` (service worker), `offline.html` and `assets/icons/`.
- **Caching:** the service worker caches styles, scripts and images only. Pages with personal data are never cached. When there is no connection, an offline page is shown.
- **HTTPS required:** the site must be on HTTPS (localhost is fine for testing).
- **Updates:** after changing `sw.js`, bump `VERSION` inside it so installed apps pick up the change.

## Giving & PayMongo
1. Log in as an admin and open **Admin → PayMongo & Giving**.
2. Paste your keys from **dashboard.paymongo.com → Developers → API Keys**. Start in **Test mode** with `pk_test_…` / `sk_test_…`. Secret keys are stored encrypted with `APP_KEY` (or an auto-generated `config/app.key`, so keep that file when you move servers).
3. Keep **QR Ph** ticked and make sure QR Ph is activated on your PayMongo account. QR Ph is currently the only payment method offered.
4. On your live HTTPS domain, click **Register Webhook**. Webhooks confirm payments instantly at `https://your-domain/webhook/paymongo.php`. Without a webhook, payments are still confirmed while the donor's payment page is open.
5. Optional: add a daily cron job for recurring-gift reminders:
   `15 8 * * * php /path/to/pcec-app/cron/giving_reminders.php`

How it works:
- **QR Ph:** the app creates a Payment Intent, attaches a QR Ph payment method, and shows the returned QR (valid for 30 minutes).
- **Other methods:** card, e-wallet and bank transfer are switched off for now. To bring them back, add `'card'`, `'ewallet'` and/or `'bank'` to `ALLOWED_METHODS` in `includes/payments.php`, then enable them in Admin.
- **Amounts:** stored in centavos. The minimum charge is ₱20.
- **Processing fee (MDR):** by default the fee is added on top so PCEC receives the full gift: `total = (gift + fixed fee) ÷ (1 − rate)`. Set the rate (default 1.5%), and choose *always / donor's choice / off* in **Admin → PayMongo & Giving → Processing Fee**. Reports show the gift without the fee. Older databases get the new `payments.fee_amount` column automatically on first page load.

**Upgrading an existing database** (installed before Giving was added): run
`mysql -u root -p pcec_app < database/upgrade_giving.sql` once.

## Structure
```
pcec-app/
├── config/config.php        DB + app settings
├── database/schema.sql      tables + sample data
├── includes/                bootstrap, db, auth, helpers, i18n, layouts, partials
├── api/                     JSON endpoints (like/save/share, follow, pray, messages, payment status)
├── admin/                   admin panel (dashboard, PayMongo setup, payments, projects, registrations)
├── webhook/paymongo.php     PayMongo webhook receiver (signature-verified)
├── cron/                    scheduled jobs (recurring gift reminders)
├── assets/css|js|img        stylesheet, app.js, SVG illustrations
├── uploads/                 user uploads (posts, avatars, events, resources)
└── *.php                    pages
```

## Notes
- **Google / Facebook login:** the buttons are placeholders (`social.php`). Add OAuth credentials in `config/config.php` and implement the callback to enable them.
- **Password reset email:** this uses PHP `mail()`. On localhost without a mail server, the reset link is shown on screen instead.
- **Upload limit:** 20 MB (`MAX_UPLOAD_BYTES`). Also raise `upload_max_filesize` and `post_max_size` in `php.ini` if needed.
