# Cancellation fees and reasons

## Admin settings
**Admin › Settings › Charges & limits › Cancellation fees**

| Setting | Default | Meaning |
|---|---|---|
| **URide (% of fare)** | 20% | Charged when the passenger cancels after the free minutes, or after the driver arrived. Goes to the driver's wallet. |
| **URide free minutes** | 2 | Minutes after the driver accepts during which cancelling is still free. Always free before a driver accepts. |
| **UPass / UGo / ULocal late cancellation (%)** | 25% | Charged when the customer cancels after the listing's free-cancellation cut-off and before the start. Goes to the provider (or to Ultimate App for its own listings). |
| **UEat after the restaurant accepts (%)** | 30% | Charged when the customer cancels while the order is accepted or preparing. Goes to the restaurant. |

- Percentages are 0–100 with up to 2 decimals; **0% = free**.
- The fee is a % of what the customer paid in the app (Credits or BCash). The rest goes back to the same wallet.
- Cash rides and "pay on the day" bookings have no fee.
- Declines by the driver, merchant or restaurant, and cancellations by Ultimate App, are always refunded in full.

## Reasons
- Customers must pick a reason every time they cancel (URide, bookings, UEat). Picking **Other** asks them to type it.
- The reason and the fee are shown to the customer, the driver or merchant (Orders › History), and in Admin (URide ride page, Marketplace bookings and orders).

## When customers can cancel
| Service | Free | With fee | Not allowed |
|---|---|---|---|
| URide | Searching, and the first free minutes after a driver accepts | After the free minutes, or once the driver arrived | After the trip started |
| UPass / UGo / ULocal | Pending, or confirmed before the cut-off | Between the cut-off and the start | After the start |
| UEat | Before the restaurant accepts | Accepted or preparing | Ready or on the way |

## Ledger
- URide: the escrow pays the refund to the passenger's wallet and the fee to `driver_wallet:ID` (group `RF-…`). The wallet history shows "Cancellation fee".
- Merchant bookings and UEat: full refund first, then the fee moves from the customer's wallet to `merchant_payable:ID` (group `CF-…`).
- Ultimate App listings: only the amount minus the fee is refunded; the fee stays in Ultimate App sales.

## Install
1. Upload the files.
2. In phpMyAdmin, import `database/cancellation_migration.sql` once. It is safe to run again.
3. Test on a copy of the database:
   ```
   php tests/cancellation_test.php
   php tests/marketplace_test.php
   ```
