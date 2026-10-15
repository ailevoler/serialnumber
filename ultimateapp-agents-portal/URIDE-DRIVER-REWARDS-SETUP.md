# URide driver trip rewards

Drivers get a bonus in their wallet when they reach a number of completed trips.

| Completed trips | Bonus (default) |
|---|---|
| 50 | ₱100 |
| 100 | ₱200 |
| 200 | ₱500 |
| 500 | ₱1,500 |
| 1,000 | ₱3,000 |

## How it works
- The bonus is added the moment the milestone trip is completed (rides and rentals), and is paid by Ultimate App.
- **Driver App › Drive** shows a Trip rewards card with:
  - progress, e.g. "42 / 50 trips · ₱100 bonus";
  - how many trips are left to the next bonus;
  - every milestone, with the ones already reached marked.
- The wallet history shows the bonus as **Trip reward**.
- Each milestone is paid **once** per driver.
- A milestone is paid only when the driver's trip count reaches it exactly. A driver who already has 120 trips when rewards start aims for 200; the 50 and 100 bonuses are not paid back. The same rule applies to a milestone Admin adds later below a driver's count.
- Ledger: debit `driver_reward_expense` (Driver trip rewards), credit `driver_wallet:ID`, group `RW-<driver>-<milestone>`.

## Admin
**Admin › Settings › URide › Driver commission › Trip rewards**
- On/off switch.
- **Milestones (trips:PHP):** for example, `50:100, 100:200, 200:500, 500:1500, 1000:3000`.
  - Up to 20 milestones.
  - Each milestone can be listed once.

## Install
1. Upload the files.
2. In phpMyAdmin, import `database/uride_rewards_migration.sql` once. It is safe to run again.
3. Test on a copy of the database:
   ```
   php tests/uride_rewards_test.php
   ```
