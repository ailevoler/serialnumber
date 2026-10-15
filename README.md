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
(default: **QR Ph lang**; puwede ring GCash, Maya, Card, GrabPay, Online Banking, BillEase), i-click ang *Test Connection* at *Register Webhook*
(kailangang HTTPS ang site). Kapag naka-enable:

- **Checkout ng produkto** — may "PayMongo" na payment option; pagkabayad, awtomatikong *paid* ang order at lumalabas ang commissions.
- **Buy Codes** — makakabili ang member ng activation codes gamit ang PayMongo o E-Wallet; pumapasok agad sa *My Codes*.
- Kinukumpirma ang bayad sa dalawang paraan: pagbalik ng member mula sa PayMongo at sa signed webhook (`paymongo_webhook.php`).
  Ang lahat ng transaksyon ay nasa **Admin → Online Payments** (may "Check PayMongo" at "Cancel").

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
   Kung naka-install na ang lumang bersyon, patakbuhin nang sunod-sunod ang `database/upgrade_v2.sql`, `database/upgrade_v3.sql` at `database/upgrade_v4.sql` (QR Ph bilang PayMongo method).
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
5. ERP Finance → Chart of Accounts → i-check ang account mapping; mag-post ng opening balances (hal. Owner's Capital) sa Journal Entries.

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
