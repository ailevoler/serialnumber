# URide: place search and precise pins

## What changed

**Pick-up and Drop-off now always show suggestions.** As the customer types (or just taps the field), they see:

- **Their recent places.** Named places from their last rides; they only ever see their own.
- **Boracay landmarks.** Managed in **Admin › URide › URide places**.
- **Merchants.** Approved merchants that have a map pin within 40 km of Boracay.
- **Google places.** Shown when the Google key allows Places API (New), with the classic Places service as a fallback. Results near Boracay come first.

If Google refuses the key, booking still works: choose a suggestion and the pins and fare are set.

**Precise location:**

- **Use my precise location** (pin button): watches GPS for up to 20 seconds and keeps the most accurate fix. It stops as soon as accuracy is ±15 m or better.
  - It draws an accuracy circle.
  - It tells the customer the accuracy, e.g. "accurate to about ±8 m".
  - If the fix is worse than ±25 m, it asks them to drag the pin.
- **Pins are draggable.** Drag the green **P** (pick-up) or red **D** (drop-off) to the exact gate or door. The fare updates.
- **Satellite** button: switch to a satellite view to see buildings and paths. The map zooms in to street level (up to 21).
- **Address names:**
  - Tapped and GPS pins get a street address when the Geocoding API is enabled.
  - Otherwise they are named after the nearest URide place within 400 m (e.g. "Near D'Mall Boracay").
- Coordinates are saved with 7 decimals (about 1 cm).

## Admin

- **Admin › URide › URide places:**
  - Add, edit, hide or remove landmarks.
  - Each has a name, an area, the other names people type (e.g. "dmall, d mall") and a map pin (with satellite view).
  - The 13 starter landmarks have **approximate** pins: open each one, drag the pin to the exact spot, tick **Pin checked** and save.
- **Admin › Settings › Maps › Check place search:**
  - Tests your key in your browser: map, Places API (New) and Geocoding API.
  - It shows Google's exact error and how to fix it.

## Why suggestions did not show before

URide used only Google Places API (New). If the key does not have **Places API (New)** enabled, or does not allow it in its API restrictions, or your domain is not in its Website restrictions, Google returns an error and no suggestions appear. Run **Check place search** on your live site to see which one it is.

## Google Cloud checklist (same key)

1. **APIs & Services › Library:**
   - **Maps JavaScript API** (needed for the map);
   - **Places API (New)** (needed for Google suggestions);
   - **Geocoding API** (optional, for street addresses).
2. **Credentials › your key:**
   - Website restrictions: `https://yourdomain.com/*` (and `https://www.yourdomain.com/*`).
   - API restrictions: tick the three APIs above.
3. Billing must be active on the Google Cloud project.

## Deploy

1. Import `database/uride_places_migration.sql` once.
2. Upload the files.
3. Check the landmark pins in **Admin › URide › URide places**.

## Data to fix

The merchant **Access388** (address "DMall, Station 2") has its map pin in Metro Manila (14.7425, 121.0486). It is hidden from URide suggestions until its pin is moved to Boracay (Admin › Merchants › Access388 › Location).

## Test

```bash
DB_HOST=... DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/uride_places_test.php
```
