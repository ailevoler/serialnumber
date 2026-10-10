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

## Structure
```
pcec-app/
├── config/config.php        DB + app settings
├── database/schema.sql      tables + sample data
├── includes/                bootstrap, db, auth, helpers, i18n, layouts, partials
├── api/                     JSON endpoints (like/save/share, follow, pray, messages)
├── assets/css|js|img        stylesheet, app.js, SVG illustrations
├── uploads/                 user uploads (posts, avatars, events, resources)
└── *.php                    pages
```

## Notes
- **Google / Facebook login:** the buttons are placeholders (`social.php`). Add OAuth credentials in `config/config.php` and implement the callback to enable them.
- **Password reset email:** this uses PHP `mail()`. On localhost without a mail server, the reset link is shown on screen instead.
- **Upload limit:** 20 MB (`MAX_UPLOAD_BYTES`). Also raise `upload_max_filesize` and `post_max_size` in `php.ini` if needed.
