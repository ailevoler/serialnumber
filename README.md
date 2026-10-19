# XGEN LIFE Intl — MLM System

Member Dashboard + Administrator Backoffice para sa XGEN LIFE Intl.
**PHP 8+ · MySQL/MariaDB · HTML · CSS · JavaScript** (walang framework, responsive sa phone, tablet at desktop).

Theme: puting base, logo-green na accent, may konting gold.

## Compensation Plan (XGEN LIFE official plan)

Lahat ay nababago sa **Admin → Compensation Plan**. Default values (galing sa complan materials):

| # | Income | XGEN plan |
|---|--------|-----------|
| 1 | **Direct Selling Discount** | Presyo = SRP less discount ng member: Silver 25%, Gold 30%, Platinum 35%, Diamond 40%; stockist tiers (ina-assign sa member): Mobile Stockist 43%, City Hub 47%, Mega Depot 52%. Hal. Nutriva 6-in-1 Coffee SRP ₱300 → Silver ₱225; Magnesium Spray SRP ₱900 → Silver ₱675 |
| 2 | **Direct Referral Bonus (Outright)** | Silver ₱140, Gold ₱550, Platinum ₱1,000, Diamond ₱1,700 — nasa Referral card lang; hindi wallet, hindi withdrawable, hindi kasama sa Total Earnings |
| 3 | **Coded Bonus (Pass-Up, 1-Up)** | Silver ₱500, Gold ₱2,000, Platinum ₱3,500, Diamond ₱6,000. Invite #1 mo → pasa sa coded upline mo; invite #2 pataas → sa iyo; ang invite #1 ng mga invite #2+ mo → pasa sa iyo |
| 4 | **Pairing / Matching Bonus (Product Wallet)** | Silver↔Silver ₱100, Gold↔Gold ₱400, Platinum↔Platinum ₱700, Diamond↔Diamond ₱1,200; maximum 7 pairs a day; pumapasok sa Product Wallet |
| 5 | **Unilevel** | ₱5 bawat bote / pouch, 10 levels |
| + | **Indirect Bonus** | Hanggang 5 levels (nababago / puwedeng i-off) |
| + | **E-Wallet / Product Wallet** | E-Wallet: withdrawable (payout, transfer, pambili). Product Wallet: pambili lang ng produkto |

> Ang **package prices** (Silver ₱1,500, Gold ₱5,000, Platinum ₱8,000, Diamond ₱12,000) ay pansamantala lang —
> palitan sa Compensation Plan ng tunay na presyo.

## PayMongo

**Admin → PayMongo Settings**: ilagay ang Public/Secret key (test o live), piliin ang payment methods
(default: **QR Ph lang**; puwede ring GCash, Maya, Card, GrabPay, Online Banking, BillEase), i-click ang *Test Connection* at *Register Webhook*
(kailangang HTTPS ang site). Kapag naka-enable:

- **Checkout ng produkto** — may "PayMongo" na payment option; pagkabayad, awtomatikong *paid* ang order at lumalabas ang commissions.
- **Buy Codes** — makakabili ang member ng activation codes gamit ang PayMongo o E-Wallet; pumapasok agad sa *My Codes*.
- Kinukumpirma ang bayad sa dalawang paraan: pagbalik ng member mula sa PayMongo at sa signed webhook (`paymongo_webhook.php`).
  Ang lahat ng transaksyon ay nasa **Admin → Online Payments** (may "Check PayMongo" at "Cancel").

## Corporate Website + CMS

**Website (`/home.php`)** — corporate, minimalist na landing page: header na may menu, hero, stats, About, Products
(kinukuha sa product catalog), Why XGEN LIFE, Business Opportunity (awtomatikong sumusunod sa naka-on na ways to earn sa
Compensation Plan), Testimonials, FAQ, call-to-action at Contact form. Ang mga mensahe sa Contact form ay pumapasok sa
**CRM** bilang Website lead, kasama ang mensahe sa activity timeline. Responsive sa phone, tablet at desktop.

**Admin → Website CMS**
- **Website Overview** — piliin kung ano ang makikita sa home page (`/`): *Corporate website*, *Coming Soon* o *Member login*;
  i-show/hide ang bawat section; preview ng website.
- **Page Sections** — i-edit ang texts, buttons, links at images ng bawat section (kasama ang SEO title/description,
  announcement bar, contact details at social links).
- **Lists & Testimonials** — stats, "Why XGEN" items (may icon), testimonials (may photo) at FAQ; may Draft/Published at ayos ng order.
- **Media Library** — upload at pamamahala ng mga larawan.
- **Content Managers** (admin lang) — gumawa, i-block o i-reset ang password ng **Content Manager** accounts.

**Content Manager account** — nagla-login sa `/admin/login.php` pero **Website CMS at sariling account lang** ang nakikita;
hindi nila mabubuksan ang members, wallets, payouts, orders, compensation, ERP, CRM o settings.

## Coming Soon Landing Page

Habang naka-on ang **Coming Soon** (Admin → General Settings → Coming Soon Page), ang home page (`/`) ay nagpapakita ng
landing page na may logo, countdown hanggang sa launch date, at "Notify Me" form. Ang bawat nag-sign up ay pumapasok sa
**CRM** bilang company lead (source: *Website*). Mababago ang headline, description, launch date, contact at social links.
Gumagana pa rin ang `/login.php`, `/register.php?ref=...` at `/admin/login.php`; laging bukas din ang `/coming-soon.php`.
I-off lang ang setting para bumalik ang home page sa member login.

## Live Chat Support

- **Member**: chat bubble sa lahat ng pages (may unread badge) at buong **Live Chat Support** page na may history ng conversations.
- **Admin → Live Chat Support**: inbox ng open/closed conversations na auto-refresh, quick replies, *Assign to me*, *Close / Reopen*,
  at **Member 360** sa gilid (package, wallet, contact, CRM notes).
- **Chat Settings**: on/off, welcome message, support hours at quick replies.
- Polling ang gamit (bawat 3–4 segundo kapag bukas ang chat), kaya gumagana sa kahit anong PHP hosting at walang kailangang WebSocket server.

## CRM

- **Admin → CRM Leads & Pipeline**: Kanban board (New → Contacted → Presented → Follow-up → Converted / Lost) o list view,
  filter ng company leads o prospects ng members, follow-ups due today, CSV export, at nae-edit na lead sources.
- **Lead page**: details, Call/SMS/Email buttons, activity timeline (note, call, SMS, email, meeting, task), next follow-up,
  *Mark as converted* na naka-link sa registered member.
- **Member 360**: CRM notes at activities sa bawat member profile at sa live chat.
- **Member → My Prospects**: personal CRM ng bawat distributor, may follow-up reminders at "Send invite (SMS)" kasama ang referral link.

## ERP Finance (SAP-style)

Double-entry general ledger na may 10-digit document numbers at document types:
`SA` G/L journal · `DR` sales · `CM` commission · `ZP` payout · `WA` wallet adjustment · `KR` expense · `AB` reversal.

**Awtomatikong posting** (puwedeng i-off at baguhin ang account mapping):

| Event | Debit | Credit |
|-------|-------|--------|
| Benta ng produkto / codes | Cash, Bank, GCash/Maya, PayMongo o Members' Wallet Payable | Product Sales / Package Sales |
| Commission (lahat ng 5 bonus) | Commission Expense (bawat uri) | Members' Wallet Payable |
| Payout request | Members' Wallet Payable | Payouts Payable |
| Payout released | Payouts Payable | Cash in Bank + Payout Fee Income |
| Payout rejected | Payouts Payable | Members' Wallet Payable |
| Wallet adjustment (credit / debit) | Wallet Adjustments / Wallet Payable | Wallet Payable / Other Income |
| Expense | Expense account | Cash / Bank / Accounts Payable |

Mga page sa **Admin → ERP Finance**: Finance Dashboard (cash position, revenue/expenses, net income, **reconciliation**
ng wallet at payouts), Journal Entries (manual entry na may balance check, reversal, CSV), General Ledger (running balance),
Financial Statements (Income Statement, Balance Sheet, Trial Balance, may print at CSV), Expenses, at Chart of Accounts
(32 default accounts, account mapping, period lock date).

Kapag na-generate ng admin ang codes na nabayaran na nang cash/bank/GCash, piliin ang *Payment received (ERP)* sa Code Generator
para mai-post ang benta.

## Pass-Up (Coded Bonus) — paano gumagana

- 1-Up: ang invite #1 ng bawat member (Admin → Compensation Plan → Coded Bonus, "1") ay naipapasa sa upline kung saan naka-code ang sponsor; doon napupunta ang Coded Bonus. Ang sponsor ay may Direct Referral pa rin.
- **Top of line:** kapag walang upline ang sponsor (hal. ang root account), siya ang kumikita ng pass-up niya.
- Ang invite #2 pataas ay naiiwan sa sponsor; may Coded Bonus ito kapag naka-on ang "kept bonus".
- Kapag ₱0 ang Coded Bonus ng isang package o hindi active ang tatanggap, hindi ito binabayaran at may tala sa activity log ("Coded bonus NOT paid…"), at may babala sa Compensation Plan.
- Sa Simulation, pwedeng palitan ang pass-up positions at "kept bonus" para sa isang run, at may hiwalay na Pass-Up section ang report.

## Package Upgrade (Member → Upgrade Package · Admin → Package Upgrades)

- Ang member ay makaka-upgrade (hal. Silver → Gold → Platinum → Diamond) at **difference lang ng presyo** ang babayaran.
- Paraan ng bayad: **E-Wallet**, **QR Ph (PayMongo)** o **Upgrade Code** (gawa sa Code Generator, type "Upgrade"). Pwede ring i-upgrade ng admin sa Members → Manage (Cash, Bank, GCash, E-Wallet, record later o Free).
- **Bonus difference:** ang kumita ng orihinal na Direct Referral at Coded Bonus ang kikita ng difference (hal. Silver → Gold: DR ₱550 − ₱140 = ₱410, Coded ₱2,000 − ₱500 = ₱1,500). Kung free code ang ginamit sa registration, buong bonus ng bagong package ang ibibigay.
- **PV difference** ay papasok sa binary ng upline (hal. Silver 100 → Gold 400 = +300 PV) at dadaan sa pairing at daily cap. Hindi nadadagdagan ang bilang ng members sa legs.
- Agad lilipat sa discount ng bagong package.
- Settings sa Compensation Plan → Package Upgrade: on/off, pwedeng tumalon ng level, may/walang bonus, may/walang PV, E-Wallet on/off.
- Sa Simulation, may field na "% of members who upgrade" para makita ang epekto ng upgrades sa kita.

## Simulation, Reset at Backup (Admin → System → Simulation & Reset)

- **Admin Action PIN** — 4–8 digits, kailangan ang admin password para i-set o palitan. Hinihingi ito bago ang Simulation, Reset at pag-delete ng backup. Kapag 5 beses mali sa loob ng 15 minuto, naka-lock muna.
- **Plan Simulation (actuarial test)** — awtomatikong nagre-register ng test members (`sim{run}_{n}`) gamit ang totoong compensation engine. Ilalagay mo kung ilang members, ilang levels, ilang invites bawat member, max pairs per day, ilang araw, Left/Right placement, package mix, repeat purchase units, at mga cost (COGS, package cost, opex). Ang report ay may Profit & Loss, commissions per type, cost per package, member outcomes, kita vs komisyon bawat araw, top earners, at resulta na **KUMIKITA** o **NALULUGI**. Hindi nababawasan ang totoong stock ng products.
- **Reset System** — binubura ang lahat ng members (maliban sa root account), codes, orders, earnings, wallet history, payouts, payments, chat, CRM, journal entries, expenses at logs. Naiiwan ang admin at content manager accounts, settings, plan, packages, products, CMS at chart of accounts. Kailangang i-type ang `RESET` at ang PIN.
- **Auto Backup** — bago ang bawat Simulation at Reset, gumagawa ng buong database backup (`.sql.gz`) sa `backups/` at awtomatiko itong nada-download. May "Backup Now" button din. I-restore gamit ang phpMyAdmin → Import o `gunzip < file.sql.gz | mysql dbname`.
- Naka-block ang `backups/` gamit ang `.htaccess` (Apache). Sa Nginx, idagdag ang `location /backups/ { deny all; }`.
- Gamitin ang Simulation sa test o staging copy, o mag-Reset pagkatapos, dahil totoong records ang nalilikha nito.

## Mga Feature

**Member Dashboard** — dashboard ng kita (6 ways), referral link, binary genealogy (may "+ Add" sa bakanteng slot),
direct referrals at pass-up status, pag-register ng bagong member gamit ang sariling activation code, My Codes (transfer),
shop / cart / orders, earnings history, e-wallet ledger at transfer, payout requests, profile at password.

**Administrator Backoffice** — dashboard at charts, **Register Member** (may "+ Add" sa bawat bakanteng Left/Right slot sa Genealogy; puwedeng gumawa ng bagong activation code o gumamit ng dati), reports (ayon sa petsa, may print), members (search, CSV, edit, status,
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
   Kung naka-install na ang lumang bersyon, patakbuhin nang sunod-sunod ang `database/upgrade_v2.sql`, `database/upgrade_v3.sql`, `database/upgrade_v4.sql` (QR Ph bilang PayMongo method), `database/upgrade_v5.sql` (Coming Soon page) at `database/upgrade_v6.sql` (Website CMS) at `database/upgrade_v7.sql` (Product Wallet at Outright Direct Referral) at `database/upgrade_v8.sql` (XGEN official compensation plan) at `database/upgrade_v9.sql` (Simulation & Reset) at `database/upgrade_v10.sql` (Package Upgrade) at `database/upgrade_v11.sql` (Coded Bonus 1-Up).
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
5. Website CMS → palitan ang texts, images at contact details; magdagdag ng totoong testimonials.
6. ERP Finance → Chart of Accounts → i-check ang account mapping; mag-post ng opening balances (hal. Owner's Capital) sa Journal Entries.

Local test: `php -S 127.0.0.1:8080` sa project folder.

## Structure

```
admin/        Administrator Backoffice pages (incl. support, crm, erp_*)
api/          JSON endpoint for live chat
member/       Member Dashboard pages
includes/     bootstrap, db, auth, wallet, compensation engine, layout
config/       config.php (+ config.local.php, git-ignored)
database/     schema.sql (tables + seed data)
assets/       css, js, logo
uploads/      product images
```
