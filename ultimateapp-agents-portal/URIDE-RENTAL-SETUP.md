# URide: Rent a Ride

When customers open **URide**, they now choose:

- **Book a Ride:** point to point, as before.
- **Rent a Ride:** hire a vehicle **with its driver** by the hour or for the whole day.

## How renting works (customer)

1. Tap **Rent a Ride** (or open `uride.php?mode=rent`).
2. Set the **pick-up**: search a place, use precise GPS, or tap the map.
3. Choose **How long**: 1, 2, 3, 4, 6, 8, 10 or 12 hours, or **Whole day**.
4. Choose **Start**: **Now**, or **Schedule** a date and time (at least 30 minutes ahead, up to 7 days).
5. Choose the vehicle and payment (Credits or cash), and add the plan in the note (e.g. "Puka Beach, Mt. Luho, then D'Mall").
6. The price is shown upfront and does not change.
   - Credits are held when booking.
   - They are returned if the customer cancels or no driver accepts.

The driver picks the customer up, drives them around for the time chosen and brings them back to the pick-up point.

## Drivers

- **Now** rentals go to nearby drivers like rides. They stay open for 10 minutes (twice the ride timeout).
- **Scheduled** rentals stay hidden until **60 minutes before the start**. They then appear in the driver feed as "4-hour rental · starts 10:00 AM" (with a blue edge).
  - They stay open until 15 minutes after the start time.
  - A driver cannot accept one early.
- On the trip screen, the driver sees:
  - the rental length and when it ends (start time + hours);
  - a Navigate button that points to the start point.
- On completion, money moves the same way as a ride:
  - Credits rentals pay the driver's wallet, less commission;
  - Cash rentals deduct the commission from the wallet.

## Price (Admin › Settings › URide › Rent a Ride)

- Each vehicle has:
  - **Rentable** (Yes/No);
  - **Per hour**;
  - **Minimum hours**;
  - **Whole day** price.
- Price = per hour × hours (at least the minimum). An hourly package up to a whole day never costs more than the whole-day price.
- Global settings:
  - Rent a Ride on/off;
  - Hours in a whole day (default 8);
  - Longest hourly package (default 12);
  - How many days ahead customers can book (default 7; 0 = only Now).

**Starter prices are placeholders. Set your real rates before going live.**

| Vehicle | Per hour | Minimum hours | Whole day |
|---|---|---|---|
| E-Trike | ₱500 | 1 | ₱3,000 |
| Motorcycle | ₱300 | 1 | ₱1,800 |
| Car | ₱800 | 2 | ₱5,000 |

## Rules

- A customer can have one active ride or rental now, plus one upcoming scheduled rental.
- An upcoming rental does not stop them booking a normal ride today.
- Extending a running rental is not built in yet. The customer books another rental, or pays the driver directly.

## Deploy

1. Back up, then import `database/uride_rental_migration.sql` once.
2. Upload the files.
3. Set the rental prices in Admin.

## Test

```bash
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/uride_rental_test.php
```
