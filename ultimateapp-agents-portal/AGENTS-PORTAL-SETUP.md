# Agents Portal (referral link and code commissions)

Agents sign up at `/agent-portal/`. Once approved, each agent gets a referral link (`/register.php?ref=AGXXXXXX`), a code, and a QR code. Customers who create an account with the link or code are tagged to that agent. The agent then earns a commission on that customer's URide, UPass, UGo, ULocal and UEat activity.

## Deploy

1. Back up the database.
2. Import `database/agents_migration.sql` **once** in phpMyAdmin. It adds the `agents`, `agent_commissions` and `agent_payouts` tables, adds `users.referred_by_agent_id` / `referred_at`, adds the `completed` status to `service_requests`, and loads the default rates.
3. Upload the new and changed files (see "Files" below).
4. In Admin > Agents > Commission rates, review the rates. Only a Super Admin can change them.
5. Share `https://your-domain/agent-portal/register.php` with prospective agents. Approve them in Admin > Agents > (agent) > Decision.

## How agents earn

| Service | Default rate | Commission is recorded | Becomes available (earned) | Reversed |
|---|---|---|---|---|
| URide | 2% of the fare | Trip completed | Immediately | — |
| UEat | 3% of the order total | Credits order paid | After the holding period (default 3 days) if the order is not cancelled | Order cancelled / refunded |
| UPass, UGo, ULocal | 5% of listed price × quantity | Request sent | When Admin marks the request **Completed** (Admin > Agents > UPass / UGo / ULocal) | Request cancelled |

- Ultimate App pays the commission as an expense. The customer, driver and merchant pay nothing extra. The driver's URide commission is unchanged.
- Each rate can be a percentage, a fixed peso amount, or both. A commission is never more than the activity amount.
- An agent earns from each customer for `agents.earn_days` days after sign-up (default 365; 0 = always).
- A customer is tagged only once, at sign-up, and only to an **approved** agent. Agents cannot refer their own email or mobile number.
- The link is remembered in the browser for 30 days, so a customer can click today and sign up later, including with Google or Facebook sign-in.
- Customers can also type the code in the new **Agent referral code** box on the Sign Up screen. An unknown code shows an error, and the box can be left blank.
- If an agent is suspended, their link stops tagging new customers and no new commissions are recorded. They keep their balance and can still cash out.
- Admin can manually approve or reverse any commission on the agent's page.

## Master Agents and Sub-Agents

Import `database/agents_team_migration.sql` **once**, after `agents_migration.sql`.

- Every approved agent who is not in someone's team is a **Master Agent**. The **My team** page shows a team invite link (`/agent-portal/register.php?master=CODE`) and the Master Agent code.
- A **Sub-Agent** signs up with that link, or enters the Master Agent code on the sign-up form. They choose their own email and password. They wait for Admin approval, unless Admin ticks *Approve Sub-Agents automatically*.
- **One level only.** A Sub-Agent cannot add their own Sub-Agents.
- Admin sets three rates per service in **Admin › Agents › Commission rates**:

| Column | Who earns | On which customers |
|---|---|---|
| Master Agent | Master (or independent) agent | Their own customers |
| Sub-Agent | Sub-Agent | Their own customers |
| Master override | Master Agent, **on top** | Their Sub-Agents' customers (% of the activity amount) |

- The override is paid by Ultimate App. It never reduces what the Sub-Agent earns.
- The Sub-Agent's commission and the Master's override move together: earned together, and reversed together when an order is cancelled.
- If the Master Agent is suspended, the Sub-Agent still earns, but no override is recorded.
- Admin can move an agent into a team, or out of one, on the agent's page (**Set Master Agent**).
- Default overrides: 0.5% for URide and UEat; 1% for UPass, UGo and ULocal. Default Sub-Agent rates are the same as the agent rates.

Test: `php tests/agents_team_test.php` (on a copy of the database).

## Payouts

Agents add a GCash/Maya/bank account in Profile and request a payout from their available balance (minimum ₱500 by default). Finance processes it in Admin > Agents > Agent payouts: **Mark paid** with the transfer reference, or **Return** with a reason, which puts the money back in the agent's balance.

## Books (general ledger)

- Earned: debit `agent_commission_expense`, credit `agent_payable:<agent id>`
- Reversed after it was earned: the opposite entry
- Payout requested: debit `agent_payable`, credit `settlement_payable:agent_<id>`
- Payout paid: debit `settlement_payable:agent_<id>`, credit `bank_clearing`

## Admin roles

- Super Admin: everything, including rates.
- Support: view agents, approve/suspend agents, complete or cancel UPass/UGo/ULocal requests.
- Finance: view agents, approve/reverse commissions, process agent payouts.

## Test

On a **copy** of the database (after importing the migration):

```bash
DB_HOST=localhost DB_NAME=copy_db DB_USER=... DB_PASS=... php tests/agents_test.php
```

## Files

New: `agent-portal/` (whole folder), `admin/agents.php`, `admin/agent.php`, `admin/agent-payouts.php`, `admin/service-requests.php`, `includes/agents.php`, `database/agents_migration.sql`, `tests/agents_test.php`, `AGENTS-PORTAL-SETUP.md`.

Changed: `admin/_bootstrap.php` (menu, roles), `includes/settings.php` (rate settings), `includes/ledger.php` (account names), `includes/uride.php` (commission when a trip is completed), `includes/ueat.php` (commission on paid orders, reversed on cancel), `service-request.php` (commission on requests), `register.php` (referral code box), `index.php` and `login.php` (remember `?ref=`), `oauth.php` (tag new social sign-ups).
