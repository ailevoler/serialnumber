# UGo, ULocal and UEat for merchants

## What's new

### UGo: activities and tours
Examples: land tours, island hopping, water sports, diving, sunset sail and packages.

Customers can:
- browse by category and search;
- see the photo, duration, what's included, meeting point and free-cancellation policy;
- book a date and start time, and choose guests (the price is updated live);
- pay with Credits now, or pay on the day.

Start times have slots: each time can only take as many guests as the merchant allows.

### ULocal: local services
Examples: massage and spa, hair and beauty, photography, laundry, tour guides, cleaning, repairs, babysitting and errands.

It uses the same booking flow as UGo, plus "Where?" (hotel, room or address).

### UEat: food delivery, redesigned like Grab Food
- **Home page:**
  - "Deliver to" bar and search ("What are you craving?");
  - promo banner;
  - round cuisine buttons;
  - "Popular near you" row;
  - restaurant cards with prep time, delivery fee, and whether the restaurant is open or closed.
- **Restaurant page:**
  - cover photo and info card (time, delivery fee, minimum order, promo, hours);
  - menu-section tabs that stick to the top and follow the scroll;
  - "+" button that adds a dish without leaving the page;
  - green "Basket" bar at the bottom.
- **Basket:**
  - change quantities;
  - choose Delivery or Pick-up;
  - enter the address and mobile number;
  - see the subtotal, delivery fee and total, and pay with Credits.
- **Tracking:** Placed → Accepted → Preparing → On the way / Ready for pick-up → Delivered. The page refreshes by itself, and every step shows up in Notifications.

### Merchant Portal
- **Listings:**
  - UGo / ULocal / UEat tabs;
  - add or edit listings with a photo, price, start times, slots, cancellation policy and instant confirmation;
  - Pause / Resume ("Sold out" for dishes).
- **UEat restaurant profile:** cuisine, cover photo, prep time, delivery fee, minimum order, hours, delivery and/or pick-up, promo banner, and an "Open for orders / Close for now" switch.
- **Orders:**
  - UEat order cards: Accept → Start preparing → Out for delivery / Ready → Delivered, or Decline with a reason;
  - booking requests: Confirm / Decline / Mark completed;
  - History tab;
  - the page refreshes by itself, and a red count shows on the menu.
- **Dashboard:** a "Sell on Ultimate App" card with open orders, bookings to confirm and live listings.

### Admin › UGo · ULocal · UEat
- **To review:** approve or reject (with a note) merchants' new or edited listings and restaurants.
- **Listings:** add or edit any listing, either for a merchant or as Ultimate App. Ultimate App listings are request-only and paid on the day. You can also mark listings as Featured.
- **UEat restaurants:** set up or edit a restaurant for any approved merchant.
- **Bookings / Food orders:** confirm, complete, or cancel with an automatic refund.
- **Settings:**
  - review merchant listings (on/off);
  - pay with Credits (on/off).

## Money
- **Paying with Credits** works like a QR payment to the merchant.
  - The money goes to the merchant's balance and is paid out in **Settlements**.
  - The QR payment charge (Settings › Payments) applies, and is shown to the customer when the customer pays it.
- **Full refunds**, including the charge, happen automatically when:
  - the merchant declines;
  - the customer cancels a booking before the free-cancellation cut-off;
  - the customer cancels a food order before the restaurant accepts it;
  - Admin cancels.
- **Referral agents** earn on UGo / ULocal bookings (approved when completed) and UEat orders, using the existing agent rates.

## Install
1. Upload the files.
2. In phpMyAdmin, import **`database/marketplace_migration.sql`** once (safe to run again).
   - It adds the tables and new UEat order columns.
   - It adds 8 starter UGo / ULocal listings run by Ultimate App. Edit or remove them in Admin.
3. Make sure **`storage/market/`** can be written by PHP. Listing photos are saved there and served through `market-photo.php`. Back up this folder with the database.
4. Test on a copy of the database:
   ```
   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/marketplace_test.php
   ```

## Good to know
- UEat starts with no restaurants. Restaurant merchants set up their profile and menu in Merchant Portal › Listings › UEat, and Admin approves them.
  - The old sample restaurants are no longer shown.
  - Old sample orders still open from My orders.
- **Delivery is done by the restaurant itself.** There is no rider dispatch yet.
- **Restaurant hours use Philippine time.** A restaurant outside its hours, or switched to "Close for now", shows as Closed. Customers can still look at the menu but cannot order.
