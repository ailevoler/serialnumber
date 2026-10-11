# Payment methods: Pay Cash on/off, Credits and BCash everywhere

## Admin switch
**Admin › Settings › Charges & limits › Payment methods**

| Method | What it covers |
|---|---|
| **Credits** | Always on: URide, UPass, UGo, ULocal, UEat, UBills, ULoad, UCash In and QR payments. |
| **BCash** (on/off) | Pay with BCash in URide (rides, rentals, self-drive), UPass, UGo, ULocal and UEat. |
| **Pay Cash** (on/off) | Cash to the URide driver, and "pay cash on the day" for UPass, UGo and ULocal. |

- **When Pay Cash is off:**
  - customers only see Credits and BCash;
  - every payment is held in the app;
  - the payment is refunded automatically when a booking is cancelled, declined, or not accepted.
- Bookings already made keep the payment method they were made with.
- **URide:** Admin › Settings › URide also has its own switches for Credits, Cash and BCash.
  - URide cash shows only when both the app-wide Pay Cash switch and the URide cash switch are on.
  - The same rule applies to BCash.

## How BCash payments work
- **URide:**
  - the fare (and the self-drive deposit) is held from BCash when the customer books;
  - the driver's wallet is paid on completion;
  - on a cancel or expiry, the money goes back to BCash.
- **UPass, UGo, ULocal and UEat with a merchant:** works like a BCash QR payment.
  - The money goes to the merchant's balance.
  - The charge under "Paying merchants with BCash" applies.
  - Refunds go back to BCash.
- **Listings run by Ultimate App:** the money goes to Ultimate App sales, with no charge, and refunds go back to BCash.
- **In the ledger:** every BCash payment is booked on the customer's BCash account (`user_cash`); Credits payments stay on `user_credits`.

## Install
1. Upload the files.
2. In phpMyAdmin, import `database/pay_methods_migration.sql` once. It is safe to run again.
3. Test on a copy of the database:
   ```
   php tests/pay_methods_test.php
   ```
