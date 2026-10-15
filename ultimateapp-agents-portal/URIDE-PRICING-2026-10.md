# URide pricing (October 2026)

| What | New value | Where to change it |
|---|---|---|
| Motorcycle and E-Trike fare | **₱50 flag-down for the first 2 km**, then **₱25 per km** | Admin › Settings › URide › Fare matrix |
| Trip fee deducted from the driver | **₱10 per completed trip** (fixed) | Admin › Settings › URide › Commission |
| Minimum driver top-up | **₱50** | Admin › Settings › URide › Driver wallet |
| Wallet needed to go online | **₱50** | Admin › Settings › URide › Driver wallet |

## Fare examples (Motorcycle and E-Trike)

| Distance | Fare |
|---|---|
| Up to 2 km | ₱50 |
| 3 km | ₱75 |
| **4 km** | **₱100** |
| 5 km | ₱125 |
| 6 km | ₱150 |

- Distance is the road distance from the map (straight line × road factor 1.3), rounded up to the whole peso. For example, 2.5 km = ₱63.
- Car, Van and Boat keep their own rates.
- The ₱10 trip fee applies to every completed ride and rental:
  - Credits/BCash: the driver wallet receives the fare minus ₱10.
  - Cash: the driver collects the fare, and ₱10 is deducted from the wallet.

## Install
1. Upload the files.
2. In phpMyAdmin, import `database/uride_pricing_2026_10_migration.sql`.
   - It **sets** these values, replacing what is in Admin now.
   - Importing it again resets them to these values.
3. Test on a copy of the database:
   ```
   php tests/uride_pricing_test.php
   ```
