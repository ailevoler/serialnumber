# Boracay services: Phase 1

The dashboard tiles open sample UStay, UPass, ULocal, UEat, UGo, UFly, and UMart pages. URide is unchanged.

## Deploy

1. Back up the existing MySQL database.
2. Import `database/service_requests_migration.sql` once before uploading the new PHP pages.
3. If deploying the included Buy Credits / Convert Credits updates from this package, also import `database/fees_migration.sql` and `database/conversion_migration.sql` after the existing PayMongo and MCTC migrations. Do not import an already-applied `ALTER TABLE` migration twice.
4. Upload all files, including `assets/images/boracay-listings.png`, `assets/css/services.css`, and the new PHP pages.

The new service catalog is stored in `includes/service-seeds.php` as illustrative seed data, not partner inventory. Names, prices, ratings, availability, directions, and photos are not verified partner listings. Replace them before public launch. The hotel rating UI shows five empty stars with no reviews; it does not invent ratings.
Service listing prices are displayed in Credits. PHP and USD appear only in the separate BCash wallet display.

## Request behavior

UStay, UPass, ULocal, UGo, and UMart buttons create a pending request in `service_requests` with a unique request key. Repeated submission does not duplicate an order. A receipt/reference page is shown to the same customer. This does not notify a provider, reserve availability, confirm a booking, process a purchase, or deduct Credits. UMart online orders are requests only.

UEat now has a separate restaurant menu, cart, and Credits checkout; see `UEAT-SETUP.md`. Its listings remain samples, and there is no automatic restaurant fulfillment. UMart directions open a map of the general Boracay area named on each sample card, not a verified storefront pin. UFly links to official airline websites; Ultimate App does not issue tickets. Airline logos load from Wikimedia Commons and need network access.

Before Phase 2, onboard real partners, get permission for their imagery and listings, confirm prices and addresses, build provider acceptance/fulfillment, and add payment and cancellation rules.
