# URide Rent a Ride: Self-Drive

**Rent a Ride** has two choices: **With driver** (as before) and **Self-drive**. With Self-drive, the customer drives the E-Trike, Motorcycle or Car themselves.

## Who supplies the vehicle

- Approved URide drivers turn on **Account › Self-drive rentals › Offer my vehicle for self-drive**.
- Only they get self-drive requests for their vehicle type.
- The owner:
  1. brings the vehicle to the customer's pick-up;
  2. checks the physical license and ID;
  3. hands the vehicle over;
  4. checks it when it comes back.

## What the customer needs

1. A **verified ID (KYC)** (Admin can turn this rule off; on is recommended).
2. A **driver's license on file**: URide › Rent a Ride › Self-drive › **Add my license**.
   - License number, expiry, what it allows, and a photo of the front.
   - E-Trike and Motorcycle need code **A or A1**. Car needs code **B**.
   - The license must not expire before the rental ends.
   - It must not have been rejected by Admin.
3. **Credits** for the price **plus the refundable deposit**. Self-drive is Credits only.
4. Tick the **self-drive rules**:
   - drive personally with a valid license;
   - wear a helmet on an e-trike or motorcycle;
   - no drinking;
   - return on time to the pick-up point;
   - late fees and damage can come from the deposit.

## Flow

1. **Book.** The price and deposit are held. If the customer cancels, or no owner accepts, both are returned.
2. **Owner accepts.** The owner sees "4-hour self-drive · you hand over the vehicle · deposit ₱2,000" and can open the customer's license photo.
3. **Hand-over.** The owner ticks "I checked the customer's physical driver's license and ID…" and can note the condition, fuel or battery. **The rental clock starts here.**
4. **Return.** The owner presses **Vehicle returned**:
   - **Good condition:** the owner gets the price less the URide commission. The deposit is returned to the customer.
   - **Late:** for each started hour after the grace minutes (default 15), the self-drive hourly rate is taken from the deposit and paid to the owner, less commission. The rest is returned.
   - **Damage:** the owner enters an amount (up to what is left of the deposit) and what happened. The deposit stays on hold.
5. **Damage claims (Admin › URide › Self-drive licenses & claims › Damage claims).** Open the rental, ask both sides for photos, and enter how much to pay the owner (0 up to the claim). The rest goes back to the customer straight away.

## Admin

- **Settings › URide › Self-drive:**
  - On/off;
  - Needs verified ID;
  - Late-return grace;
  - Per vehicle: Self-drive (Yes/No), Per hour, Minimum hours, Whole day, Deposit.
- **URide › Self-drive licenses & claims:**
  - Review license photos (approve or reject with a reason). A rejected license blocks self-drive until the customer uploads a new one.
  - Open damage claims.
- **Rides › (rental):** shows the license, the deposit, the hand-over note, the return time, the late fee, the claim, and the claim decision form.

**Starter prices are placeholders. Set your real rates.**

| Vehicle | Per hour | Minimum | Whole day | Deposit |
|---|---|---|---|---|
| E-Trike | ₱350 | 2 h | ₱2,000 | ₱2,000 |
| Motorcycle | ₱200 | 2 h | ₱1,200 | ₱3,000 |
| Car | ₱600 | 4 h | ₱3,500 | ₱5,000 |

## Privacy

- License photos are re-encoded (EXIF removed) and stored privately in `storage/licenses/`.
- Only Admin can see them, plus the owner assigned to the rental, from acceptance until one day after the return.

## Before going live

- Check with the LGU and LTO rules in Malay/Boracay which vehicles may be rented out for self-drive. Some vehicle types may need a permit, or may not be allowed for tourists. Turn those off per vehicle in Admin.
- Check with your insurer, and add any required rental agreement to the rules text.

## Deploy

1. Back up, then import `database/uride_selfdrive_migration.sql` once (after `uride_rental_migration.sql`).
2. Upload the files.
3. Set the prices and deposits.
4. Ask owners to turn on self-drive in their Account.

## Test

```bash
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/uride_selfdrive_test.php
```
