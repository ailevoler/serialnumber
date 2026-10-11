# Credits everywhere, and URide rental fleets (E-Bike, Motorcycle, E-Trike, Car, Van)

## Credits can be used on every product and service

| Service | Paid with Credits |
|---|---|
| URide | Rides, Rent a Ride (with driver) and Self-drive. |
| UPass | **New:** passes are now real bookings, paid with Credits. |
| UGo | Paid with Credits. **New:** listings run by Ultimate App can be paid with Credits too, not only on the day. |
| ULocal | Same as UGo. |
| UEat | Paid with Credits. |
| UBills, ULoad, UCash In | Paid with Credits through SUNIWAY. |
| Merchants | Pay any merchant by scanning their QR. |

**Buy Credits** now shows a "Use your Credits on" row that links to each of these.

### UPass
- **Customer page:** a new UPass page (`upass.php`) with categories: Airport Transfer, Island Pass, Day Pass, Activity Bundle, Event Pass and Entrance Fee.
  - It works like UGo: date, start time, number of guests, and pay with Credits or on the day.
- **Who adds passes:**
  - Merchants add passes in Merchant Portal › Listings › UPass.
  - Admin adds them in Admin › UGo · ULocal · UEat › Listings › "+ UPass".
- **Starter passes:** Island Arrival Pass, Island Departure Pass, Island Day Pass and Explore Pass. Prices are samples, so edit them.

### Paying Ultimate App's own listings
- Credits for listings run by Ultimate App go to the **"Ultimate App sales"** ledger account (`platform_sales`). There is no merchant charge.
- Cancelled or declined bookings are refunded in full automatically.

## URide rentals: 5 vehicle types and units

| Vehicle | Rides | Rental with driver | Self-drive |
|---|---|---|---|
| E-Bike (1 rider) | – | shown in Rent a Ride; choosing it switches to Self-drive (Admin can turn on rentals with a driver) | yes. Valid ID (KYC) only; Admin can require a driver's license |
| Motorcycle | yes | yes | yes. License A/A1 |
| E-Trike | yes | yes | yes. License A/A1 |
| Car (4 seats) | yes | yes | yes. License B |
| **Van (10–12 seats)** | yes | yes | yes. License B/B1 |

- **Rates:** set them in Admin › Settings › URide. E-Bike and Van now appear there with sample prices.

### Units and "available" counts
- **For owners:** in the driver app, Account › **Self-drive rental fleet**, the owner enters how many units they have of each type (for example 6 E-Bikes and 2 Vans), plus the model.
  - An owner can rent out up to that many at the same time.
  - Once a vehicle is handed over, the owner is free to deliver the next unit.
  - Vehicles out now are listed on their dashboard with the return time.
- **For customers:** in Rent a Ride, every vehicle card shows **"N available"**, or **"None free now"** in red.
  - Self-drive: the free units of owners who are online.
  - With driver: drivers of that vehicle who are online and not on a trip.
  - For "Later", self-drive shows the fleet size, and booking checks that enough units are free at that time (overlapping rentals are counted).
- **Booking a rental:**
  - "Now" is refused when nothing is free. The customer is asked to schedule it or choose another vehicle.
  - "Later" with a driver is not limited, because drivers come online by then.
- **Admin:** the driver page shows each owner's fleet.

## Install
1. Upload the files.
2. In phpMyAdmin, import these once each, in this order (both are safe to run again):
   1. `database/uride_fleet_migration.sql`. It adds the E-Bike and Van types, the fleet table and the rates. Owners who already offered self-drive keep their vehicle as 1 unit.
   2. `database/upass_credits_migration.sql`. It adds UPass and the starter passes.
3. Tests, on a copy of the database:
   ```
   php tests/uride_fleet_test.php
   php tests/marketplace_test.php
   php tests/uride_rental_test.php
   php tests/uride_selfdrive_test.php
   ```
