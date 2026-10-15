# XGEN LIFE Intl — MLM System

Member Dashboard + Administrator Backoffice para sa XGEN LIFE Intl.
**PHP 8+ · MySQL/MariaDB · HTML · CSS · JavaScript** (walang framework, responsive sa phone, tablet at desktop).

Theme: puting base, logo-green na accent, may konting gold.

## 6 Ways to Earn (+ Wallet)

Lahat ay naka-on/off at nababago sa **Admin → Compensation Plan** (puwedeng mag-add/remove ng levels at mag-set ng fixed o % na bonus).

| # | Income | Paano kinukuwenta |
|---|--------|-------------------|
| 1 | **Product Discount** | SRP − Member Price sa bawat order (savings / retail profit, hindi pumapasok sa wallet) |
| 2 | **Direct Referral Bonus** | Fixed bawat package, o % ng package price — para sa sponsor |
| 3 | **Indirect Bonus** | Bawat level sa itaas ng sponsor (default 5 levels: `50,30,20,10,10`), fixed o % ng package price; puwedeng magdagdag ng level |
| 4 | **Pass Up Bonus (Australian Pass Up)** | Ang N-th recruit ng sponsor (hal. `1` o `1,2` para 2-Up) ay ipinapasa sa pass-up upline na kumikita ng bonus (fixed o %). May option kung kikita pa rin ng Direct Referral ang sponsor |
| 5 | **Pairing / Matching Bonus (Binary)** | Bawat `PV per side` sa left + right = 1 pair × bonus, may daily cap (sobra ay flush), carry-forward ang natirang PV |
| 6 | **Unilevel Bonus (Repeat Purchase)** | % ng PV o ng halaga ng order bawat level paakyat sa sponsor line; puwedeng magdagdag ng level |
| + | **E-Wallet** | Available Balance, Total Earnings, breakdown ng bawat bonus at Withdrawal; payout (may fee), pambili ng produkto/codes, member-to-member transfer |

## PayMongo

**Admin → PayMongo Settings**: ilagay ang Public/Secret key (test o live), piliin ang payment methods
(GCash, Maya, Card, GrabPay, QR Ph, Online Banking, BillEase), i-click ang *Test Connection* at *Register Webhook*
(kailangang HTTPS ang site). Kapag naka-enable:

- **Checkout ng produkto** — may "PayMongo" na payment option; pagkabayad, awtomatikong *paid* ang order at lumalabas ang commissions.
- **Buy Codes** — makakabili ang member ng activation codes gamit ang PayMongo o E-Wallet; pumapasok agad sa *My Codes*.
- Kinukumpirma ang bayad sa dalawang paraan: pagbalik ng member mula sa PayMongo at sa signed webhook (`paymongo_webhook.php`).
  Ang lahat ng transaksyon ay nasa **Admin → Online Payments** (may "Check PayMongo" at "Cancel").

## Mga Feature

**Member Dashboard** — dashboard ng kita (6 ways), referral link, binary genealogy (may "+ Add" sa bakanteng slot),
direct referrals at pass-up status, pag-register ng bagong member gamit ang sariling activation code, My Codes (transfer),
shop / cart / orders, earnings history, e-wallet ledger at transfer, payout requests, profile at password.

**Administrator Backoffice** — dashboard at charts, reports (ayon sa petsa, may print), members (search, CSV, edit, status,
reset password, wallet adjustment), genealogy ng buong network, Code Generator (generate paid/free activation codes, assign, block, CSV),
packages, products (may image upload), orders (status → paid ay nagre-release ng commission), payout approval/reject
(auto-refund), commissions report + CSV, wallet ledger, announcements, Compensation Plan editor, PayMongo Settings, Online Payments, General Settings, admin accounts at activity log.

**Security** — PDO prepared statements, `password_hash`, CSRF token sa lahat ng form, session regeneration,
login throttling, role-based access, output escaping, row locking sa wallet/codes, `.htaccess` na nagba-block sa
`includes/`, `config/`, `database/` at PHP sa `uploads/`.

## Installation

1. Gumawa ng database (utf8mb4) at i-import ang schema:
   ```bash
   mysql -u root -p -e "CREATE DATABASE xgenlife CHARACTER SET utf8mb4"
   mysql -u root -p xgenlife < database/schema.sql
   ```
   Kung naka-install na ang lumang bersyon: `mysql -u root -p xgenlife < database/upgrade_v2.sql`
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
1. Compensation Plan → i-set ang final na bonuses, levels at commissions.
2. Packages / Products → ilagay ang tamang presyo, PV at bonus.
3. Code Generator → mag-generate ng codes at i-assign sa `xgenlife` o sa mga distributor.
4. PayMongo Settings → ilagay ang API keys kung tatanggap ng online payment.

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
