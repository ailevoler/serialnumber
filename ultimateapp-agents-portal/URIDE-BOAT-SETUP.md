# URide Boat (point-to-point only)

Customers can book a **Boat** in URide › **Book a Ride**, from one pin to another (for example, a beach to a jetty or another island).
Boat is **not** offered in Rent a Ride: there is no hourly or whole-day price and no self-drive. In Rent a Ride the Boat card shows "Rides only".

## Fares
**Admin › Settings › URide › Fare matrix › Boat**

| | Default |
|---|---|
| Base fare (covers the first km) | ₱500 |
| Per km after | ₱100 |
| Minimum fare | ₱500 |

- The fare works the same way as other vehicles: straight-line distance × road factor, rounded up to the peso, fixed at booking.
- The night surcharge, driver commission, Credits/BCash/Cash payment, and cancellation fees all apply to boat rides, like any other ride.
- Set **Available = No** to hide Boat rides.

## Boat operators
- They sign up as URide drivers and choose **Vehicle type: Boat**.
  - In the license field, they enter their boat captain's (MARINA) license.
  - In the plate number field, they enter the boat's registration number.
- Only approved, online Boat operators receive Boat requests. Car, E-Trike and other drivers do not.
- Boats cannot be added as self-drive fleet units.

## Install
1. Upload the files.
2. In phpMyAdmin, import `database/uride_boat_migration.sql` once. It is safe to run again.
3. Test on a copy of the database:
   ```
   php tests/uride_boat_test.php
   ```
