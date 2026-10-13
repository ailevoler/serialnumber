# PCEC Connect

Responsive web app for the **Philippine Council of Evangelical Churches**. Built with PHP 8, MySQL, HTML, CSS and vanilla JavaScript, with no frameworks or Composer packages. It follows the **PCEC Connect** app flow, keeps our design, and uses **QR Ph through PayMongo** for payments.

- **Mobile:** app-style layout with a hero header, a 5-tab bottom navigation (Home · Events · About · Donate · Profile), and bottom-sheet modals.
- **Desktop (≥1024px):** left sidebar, centered content, and a right rail (≥1320px) with upcoming events and the latest news.

## Features
| Module | What it does |
|---|---|
| Sign up | First and last name, email, mobile number with country picker (PH format checked), password, and consent to the Terms, Community Rules and Privacy Notice |
| Email confirmation | New accounts stay *unconfirmed* until the link in the confirmation email is clicked (valid for 48 hours, with a resend button). Confirming logs the member in. |
| Login | Email + password, Remember me, Forgot / Reset password. Unconfirmed accounts can resend the email; suspended accounts are blocked. |
| Home | Greeting hero, Quick Actions (Donate, Events, Media), Latest News, Upcoming Event, Community Feed preview, Membership Renewal banner |
| Community Feed | Text and photo posts, reactions (❤️ Love / 🙏 Pray / 🙌 Amen), comments, report, block member, delete. Admins can hide or unhide posts. Limit of 5 posts per 10 minutes. |
| News | Announcements posted by the PCEC office (list + detail) |
| Events | ALL / UPCOMING / PAST tabs, free or paid events, Register / Cancel, QR Ph payment for paid events, Add to Calendar (.ics), event details with speakers and schedule |
| About | Mission, History, Core Values, official website (editable in Admin) |
| Donate | Preset or custom amount, processing fee added on top, QR Ph with an inline QR code, receipts and My Giving history |
| Profile | Photo, Edit Profile, My Posts, Membership & NDC (member ID, renewal date, NDC news), Media, My Giving, Chat, Members, Contact Us, Settings, Delete account |
| Chat & Members | Member directory with search, and 1:1 messages (polling, unread badges, online dot) |
| Notifications | Reactions, comments, messages, events, news and renewal reminders, grouped by Today / Yesterday / Earlier |
| Legal | Terms of Service / Community Rules and Privacy Notice pages |
| Admin | Dashboard, Members (New / All / Suspended / Unconfirmed; let in, mark reviewed, suspend, restore, resend email, renewal date, admin role), Inbox (Contact Us messages), Community moderation (reported / hidden posts), News, Events, NDC, Donations, QR Ph & Giving setup, Event registrations, About page, Email (SMTP) settings |

**New members:** a member who confirmed their email can use the app right away and shows a *New Member* badge for 30 days. Admins can mark them as reviewed or suspend fake accounts in **Admin → Members**.

Security: PDO prepared statements, CSRF tokens on every form and AJAX call, `password_hash`, session fixation protection, a login throttle, uploads checked by real MIME type with random filenames, PHP execution disabled in `uploads/`, and all output escaped.

## Setup (XAMPP / Laragon / LAMP / Hostinger)
1. Copy the `pcec-app` folder into `htdocs` (or your web root).
2. Import the database:
   ```bash
   mysql -u root -p pcec_app < database/schema.sql
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

**Upgrading an existing installation:** just upload the new files and keep your `config/config.php` and `config/app.key`. The database upgrades itself on the first page load (`includes/migrate.php`): new tables and columns are added, existing members are marked as confirmed, and old likes become ❤️ reactions. No data is deleted.

### Demo accounts (password: `password123`)
| Email | Role |
|---|---|
| `john@pcec.test` | admin |
| `daniel@pcec.test`, `maria@pcec.test`, `joseph@pcec.test` | member |
| `grace@pcec.test` | new member (not yet reviewed) |
| `paolo@pcec.test` | unconfirmed (email not confirmed) |

## Email (confirmation & password reset)
Open **Admin → Email** and enter your SMTP details. For Hostinger email: host `smtp.hostinger.com`, port `465`, encryption **SSL**, and your full email address and password. Use **Send test email** to check it. Without SMTP the app falls back to PHP `mail()`. On localhost, if sending fails, the confirmation or reset link is shown on screen.

## Install as an app (PWA)
Share **`https://your-domain/install.php`**. It is a separate public page that shows the right steps for Android, iPhone/iPad and computers, and has an **Install App** button where the browser supports it.
- **Files:** `manifest.json` (name, colors, icons, shortcuts), `sw.js` (service worker), `offline.html` and `assets/icons/`.
- **Caching:** the service worker caches styles, scripts and images only. Pages with personal data are never cached. When there is no connection, an offline page is shown.
- **HTTPS required:** the site must be on HTTPS (localhost is fine for testing).
- **Updates:** after changing `sw.js`, bump `VERSION` inside it so installed apps pick up the change.

## QR Ph & PayMongo
1. Log in as an admin and open **Admin → QR Ph & Giving**.
2. Paste your keys from **dashboard.paymongo.com → Developers → API Keys**. Start in **Test mode** with `pk_test_…` / `sk_test_…`. Secret keys are stored encrypted with `APP_KEY` (or an auto-generated `config/app.key`, so keep that file when you move servers).
3. Keep **QR Ph** ticked and make sure QR Ph is activated on your PayMongo account. QR Ph is the only payment method offered.
4. On your live HTTPS domain, click **Register Webhook**. Webhooks confirm payments instantly at `https://your-domain/webhook/paymongo.php`. Without a webhook, payments are still confirmed while the payment page is open.

How it works:
- **QR Ph:** the app creates a Payment Intent, attaches a QR Ph payment method, and shows the returned QR (valid for 30 minutes). Used for donations and paid event registrations.
- **Amounts:** stored in centavos. The minimum charge is ₱20.
- **Processing fee (MDR):** by default the fee is added on top so PCEC receives the full gift: `total = (gift + fixed fee) ÷ (1 − rate)`. Set the rate (default 1.5%), and choose *always / donor's choice / off* in **Admin → QR Ph & Giving → Processing Fee**. Reports show the gift without the fee.

## Structure
```
pcec-app/
├── config/config.php        DB + app settings
├── database/schema.sql      tables + sample data
├── includes/                bootstrap, db, migrate, auth, mailer, phone, helpers, layouts, partials
├── api/                     JSON endpoints (reactions, messages, payment status)
├── admin/                   admin panel
├── webhook/paymongo.php     PayMongo webhook receiver (signature-verified)
├── assets/css|js|img|icons  stylesheet, app.js, illustrations, app icons
├── uploads/                 user uploads (posts, avatars, events, media)
└── *.php                    pages
```

## Notes
- **Removed in v3** (to follow the PCEC Connect flow): onboarding slides, Google/Facebook buttons, Prayer Requests, Our Churches directory, Resources, follow/save, recurring gifts and giving projects. Old data in those tables is left untouched.
- **Upload limit:** 20 MB for posts (`MAX_UPLOAD_BYTES`), 5 MB for Media photos. Also raise `upload_max_filesize` and `post_max_size` in `php.ini` if needed.
