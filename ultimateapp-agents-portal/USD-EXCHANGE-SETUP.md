# USD ⇄ PHP exchange

Customers buy USD with BCash and sell USD back to BCash at a live rate. You set the fees in Admin.

## Where customers find it

- **BCash card › USD ⇄ PHP** button. The **USD** toggle on the card now shows the customer's USD wallet and today's rate.
- **Convert** (BCash page and Convert & Transfer page) › **USD ⇄ PHP**.
- **BCash › Markets** shows the live USD/PHP rate with an **Exchange** button.

## Rate source (free, open source, no API key)

| Source | What it is |
|---|---|
| **Frankfurter** (api.frankfurter.dev) | Open-source API of European Central Bank reference rates. Published once each business day. |
| **currency-api** by fawazahmed0 (jsDelivr CDN, mirror on pages.dev) | Open-source daily rates. Used when Frankfurter is down. |
| **Manual** | A rate you type in Admin. |

- **Automatic** (default) tries Frankfurter first, then currency-api.
- The rate is checked again every 60 minutes (you can change this).
- If the rate is older than 24 hours, exchanges **pause** until it updates.
- A new automatic rate outside PHP 20–200, or more than 10% away from the last one, is refused. This means one bad reply cannot reprice every exchange. If the market really moves that much, use a **Manual** rate.
- These are mid-market reference rates, not bank buy/sell rates. Your fee is the spread.

## Fees (Admin › ERP › USD ⇄ PHP exchange › Rate & fees)

- **Buy USD fee** is added on top. Example: 0.50% on USD 10 at 58.40 means PHP 584.00 + 2.92 = **PHP 586.92**.
- **Sell USD fee** is deducted. Example: 1.00% on USD 10 at 58.40 means PHP 584.00 − 5.84 = **PHP 578.16**.
- Fees can be set from 0 to 5%. The default is 0.50% for both.
- The customer sees the exact fee and total before confirming.
- The amount is locked to the rate shown. If the rate changes before they confirm, nothing moves and they see the new amount.
- Also in Rate & fees:
  - Minimum and maximum per exchange (default USD 1 to 1,000);
  - Refresh interval;
  - Pause age;
  - **Refresh now** button;
  - The exchange on/off switch.

## Books

- Ledger is in PHP.
- **Buy:**
  - Customer BCash is debited.
  - **Customer USD (PHP value at trade rate)** is credited at the mid rate.
  - **USD exchange fees** is credited the fee.
- **Sell:** the reverse.
- The **Exchanges** tab shows:
  - USD held by customers;
  - Fees today and all time;
  - Every exchange, with CSV export.
- If the KYC customer rule is on, unverified customers cannot exchange.
- Rate limit: 30 exchanges per hour per customer.

## Deploy

1. Back up, then import `database/fx_migration.sql` once. It turns the exchange on with 0.50% fees.
2. Upload the files from the update zip.
3. Open **Admin › USD ⇄ PHP exchange › Rate & fees**, set your fees, and press **Refresh now**.
4. Recommended: add an hourly cron in Hostinger (Advanced › Cron Jobs):
   `php /home/<user>/public_html/cron/fx-refresh.php`
5. The server must be able to reach `api.frankfurter.dev` and `cdn.jsdelivr.net` over HTTPS. Hostinger allows this by default.

## Important

Buying and selling foreign currency for customers is usually regulated in the Philippines (BSP registration as a money service business / foreign exchange dealer). Check with your compliance adviser before opening it to the public.

## Test

```bash
php -S 127.0.0.1:8097 tests/fx_fake_api.php &
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/fx_test.php
```
