# Rent a Ride commission (15%)

Ultimate App takes **15% of each successful (completed) Rent a Ride payment**. This covers rentals with a driver and self-drive rentals. Point-to-point rides keep the ₱10 trip fee.

| Rental | Paid | Commission (15%) | Driver / owner gets |
|---|---|---|---|
| E-Trike, 4 h with driver | ₱2,000 | ₱300 | ₱1,700 |
| Car, whole day | ₱5,000 | ₱750 | ₱4,250 |
| Self-drive late-return fee | ₱700 | ₱105 | ₱595 |

- **Credits / BCash:** the rental is held when booked. On completion the driver wallet gets the price minus 15%.
- **Cash:** the driver collects the cash, and 15% is deducted from the driver wallet. To accept a cash rental, the wallet must hold at least that 15% (and the ₱50 online minimum).
- Cancelled, declined or expired rentals pay **no** commission.
- The commission is fixed when the driver accepts. The Driver App shows "Commission (15%)" and "Your earnings" on the trip.
- **Change it:** Admin › Settings › URide › Rent a Ride › **Rent a Ride commission (%)**, from 0 to 50%.

## Install
1. Upload the files.
2. In phpMyAdmin, import `database/uride_rental_commission_migration.sql`. It sets 15%.
3. Test on a copy of the database:
   ```
   php tests/uride_rental_test.php
   php tests/uride_selfdrive_test.php
   php tests/uride_pricing_test.php
   ```
