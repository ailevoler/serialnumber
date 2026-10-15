# XGEN LIFE Intl — MLM System

Member Dashboard + Administrator Backoffice para sa XGEN LIFE Intl.
**PHP 8+ · MySQL/MariaDB · HTML · CSS · JavaScript** (walang framework, responsive sa phone, tablet at desktop).

Theme: puting base, logo-green na accent, may konting gold.

## 6 Ways to Earn (+ Wallet)

| # | Income | Paano kinukuwenta | Saan iko-configure |
|---|--------|-------------------|--------------------|
| 1 | **Product Discount** | SRP − Member Price sa bawat order (savings / retail profit, hindi pumapasok sa wallet) | Admin → Products |
| 2 | **Direct Referral Bonus** | Fixed na bonus sa sponsor kapag may na-activate na bagong member | Admin → Packages |
| 3 | **Indirect Referral** | Fixed na halaga bawat level sa itaas ng sponsor (default `50,30,20,10,10`) | Admin → Settings |
| 4 | **Pass Up Bonus** | Australian pass-up: ang N-th recruit ng sponsor (default 1st) ay ipinapasa sa upline at kumikita ng pass-up bonus | Admin → Settings |
| 5 | **Pairing Bonus** | Binary: bawat `pair_pv` sa left + right = 1 pair × `pair_bonus`, may daily cap (sobra ay flush) | Admin → Packages (PV) + Settings |
| 6 | **Repeat Purchase Unilevel** | % ng PV ng order paakyat sa sponsor line (default `10,5,5,3,2,2,1,1,1,1`) | Admin → Products (PV) + Settings |
| + | **E-Wallet** | Lahat ng kita → wallet; payout request (may fee), pambili ng produkto, member-to-member transfer | Admin → Settings |

## Mga Feature

**Member Dashboard** — dashboard ng kita (6 ways), referral link, binary genealogy (may "+ Add" sa bakanteng slot),
direct referrals at pass-up status, pag-register ng bagong member gamit ang sariling activation code, My Codes (transfer),
shop / cart / orders, earnings history, e-wallet ledger at transfer, payout requests, profile at password.

**Administrator Backoffice** — dashboard at charts, reports (ayon sa petsa, may print), members (search, CSV, edit, status,
reset password, wallet adjustment), genealogy ng buong network, activation codes (generate paid/free, assign, block, CSV),
packages, products (may image upload), orders (status → paid ay nagre-release ng commission), payout approval/reject
(auto-refund), commissions report + CSV, wallet ledger, announcements, compensation settings, admin accounts at activity log.

**Security** — PDO prepared statements, `password_hash`, CSRF token sa lahat ng form, session regeneration,
login throttling, role-based access, output escaping, row locking sa wallet/codes, `.htaccess` na nagba-block sa
`includes/`, `config/`, `database/` at PHP sa `uploads/`.

## Installation

1. Gumawa ng database (utf8mb4) at i-import ang schema:
   ```bash
   mysql -u root -p -e "CREATE DATABASE xgenlife CHARACTER SET utf8mb4"
   mysql -u root -p xgenlife < database/schema.sql
   ```
2. Kopyahin ang `config/config.local.sample.php` → `config/config.local.php` at ilagay ang DB credentials.
3. I-upload ang buong folder sa web root (o sub-folder) ng Apache/LiteSpeed na may PHP 8+ (`pdo_mysql`, `gd`, `mbstring`).
   Siguraduhing writable ang `uploads/`.
4. Buksan ang site:
   - Member: `/login.php` · Activation: `/register.php?ref=USERNAME`
   - Admin: `/admin/login.php`

### Default accounts (PALITAN AGAD)

| Role | Username | Password |
|------|----------|----------|
| Administrator | `admin` | `Admin@123` |
| Company head (root ng genealogy) | `xgenlife` | `Xgen@123` |

### Unang hakbang ng admin
1. Settings → i-set ang final compensation plan values.
2. Packages / Products → ilagay ang tamang presyo, PV at bonus.
3. Activation Codes → mag-generate ng codes at i-assign sa `xgenlife` o sa mga distributor.

Local test: `php -S 127.0.0.1:8080` sa project folder.

## Structure

```
admin/        Administrator Backoffice pages
member/       Member Dashboard pages
includes/     bootstrap, db, auth, wallet, compensation engine, layout
config/       config.php (+ config.local.php, git-ignored)
database/     schema.sql (tables + seed data)
assets/       css, js, logo
uploads/      product images
```
