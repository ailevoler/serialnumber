# TriLink Pay — Wallet Top-Up (Production)

Lahat ng top-up (pasahero at driver) dumadaan na dito. **Walang auto-credit.**

| Channel | Paano | Kailan papasok sa wallet |
|---|---|---|
| **QR Ph** | PayMongo QR Ph — scan gamit ang GCash, Maya o kahit anong bank app | Pag kinumpirma ng PayMongo ang bayad (webhook + auto-check) |
| **Teller** | Cash sa TriLink Load Center / teller | Pag na-scan at na-confirm ng teller sa Load Center app |

Tinanggal na ang lumang GCash / Maya / Cash options na diretsong nagki-credit sa wallet.

## Files

- `TopupService.php` – lahat ng logic (create, PayMongo, credit nang isang beses lang, cancel, expiry, webhook)
- `webhook.php` – PayMongo webhook endpoint (ito lang ang bukas sa web; naka-block ang iba sa `.htaccess`)
- `views/topup_status.php` – screen na may QR / teller code, countdown, at auto-refresh
- `migration_topups.sql` – gagawa ng `tl_topups` table at ililipat ang lumang Load Center requests
- `config.php` – settings; ang keys ay nasa `config.local.php` (hindi kasama sa repo)

## Go-live steps

1. **Database** – sa phpMyAdmin, i-run ang `migration_topups.sql` (pwedeng ulitin, walang masisira).
2. **PayMongo account** – kailangan naka-activate ang account at naka-enable ang **QR Ph**
   (Dashboard → Settings → Payment methods). Kung hindi pa, i-request sa PayMongo support.
3. **Keys** – kopyahin ang `config.local.sample.php` papuntang `config.local.php` dito sa folder na ito at ilagay ang:
   - `PAYMONGO_SECRET_KEY` = `sk_live_...` (Developers → API keys)
   - `PAYMONGO_WEBHOOK_SECRET` = `whsk_...` (makukuha sa step 4)
4. **Webhook** – sa PayMongo Dashboard → Developers → Webhooks → Create:
   - URL: `https://app.trilink.site/trilink-pay/webhook.php`
   - Events: `payment.paid`, `payment.failed`, `qrph.expired`
   - Kopyahin ang signing secret papunta sa `PAYMONGO_WEBHOOK_SECRET`.
5. **Test muna** gamit ang `sk_test_...` key at test webhook (gagamitin ng code ang `te` signature
   kapag test key, `li` kapag live key). Mag-top up ng ₱50, bayaran sa test checkout, tingnan kung pumasok.
6. Palitan ng live key, at subukan ulit ng maliit na totoong bayad.

## Settings (`tl_settings`)

| Key | Default | Ibig sabihin |
|---|---|---|
| `wallet_topup_min` | 50 | Minimum top-up (hindi bababa sa ₱20, minimum ng PayMongo) |
| `wallet_topup_max` | 50000 | Maximum top-up |
| `topup_teller_expiry_hours` | 24 | Ilang oras valid ang teller code |
| `topup_max_pending` | 3 | Max na sabay-sabay na pending top-up bawat wallet |

## Safety rules sa code

- Ang wallet ay kinekredit lang sa `Topup::markPaid()`, sa loob ng isang DB transaction na naka-lock
  ang top-up row — kaya kahit dumating nang dalawang beses ang webhook, isang beses lang papasok.
- Hindi pinagkakatiwalaan ang laman ng webhook: laging kinukuha ulit ang Payment Intent sa PayMongo
  at tinitingnan na `succeeded` at tama ang amount bago mag-credit.
- Kung nakabayad na ang customer sa QR Ph kahit na-cancel o na-expire na sa app, kinekredit pa rin.
- Teller: dapat i-type ng teller ang halagang natanggap at dapat tugma; hindi pwedeng i-confirm ang
  expired, cancelled o QR Ph na request; dapat active ang Load Center account.
- Admin → **Wallet Top-Ups**: listahan ng lahat ng top-up, at **Re-check** button para tanungin ulit ang
  PayMongo kung may nagsabing "nagbayad ako pero hindi pumasok".
