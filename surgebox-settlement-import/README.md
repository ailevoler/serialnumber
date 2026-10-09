# SurgeBox V5.24–V5.27 — Nationlink Settlement Report Import + Client Fees (MDR / Fixed / Bracket)

Patch para sa SurgeBox (`public_html`) na nag-i-import ng daily **QR TRANSACTIONS REPORT (DTQR)** ng
Nationlink (PDF, Excel `.xlsx` o CSV) papunta sa transactions ng client, para lumabas sa client dashboard,
ledger at settlement.

## Paano i-install

1. I-upload ang laman ng `public_html/` sa server, papalitan ang mga file na may parehong pangalan
   (kasama ang kopya sa `public_html/portal/`).
2. phpMyAdmin → SQL: patakbuhin nang isang beses (ligtas kahit ulitin):
   - `database/migration_v5_24_settlement_import.sql` — import history
   - `database/migration_v5_25_fee_brackets.sql` — Bracket fee type
3. Admin → **Settlement Import** (bagong menu sa ilalim ng Settlement Report).

> **Admin lang ang puwedeng mag-import.** Ang client (Organization Portal, kahit Owner) ay walang menu,
> at 403 *Access denied* ang page at API sa kanila — pati sa `portal.surge-box.org` host. Nakikita lang ng
> client ang resulta: ang mga na-import na transaction sa kanilang Dashboard / Transactions / Settlement.

## Paano gamitin

1. Piliin ang report file (hal. `DTQR_20261008.pdf`). Sa **Client**, iwan sa *Auto-detect from MemberID*
   o piliin ang client.
2. **Upload & Preview** — walang nase-save dito. Makikita bawat linya:
   - **New** — wala pa sa ledger, ire-record.
   - **Already recorded** — naitala na (webhook o naunang import), lalaktawan.
   - **Amount mismatch** — naitala na pero iba ang amount; i-check nang mano-mano.
   - **Unmatched** — walang client/QR Ph na may ganoong MemberID.
   Sinusuri rin ang SUB-TOTAL at TOTAL ng report laban sa mga nabasang linya (✓ / ✗).
3. **Import N new transactions** — itatala lang ang mga *New*.

## Paano nagma-match sa database

| Report | Database |
| --- | --- |
| Group code `A10103 Donations` | `sb_nl_qr_attachments.member_id` → client + QR label |
| `BRANCH: A10100`, `MAIN ORG: A10000` | fallback kung walang QR na tugma |
| `TRACE NO.` | `sb_transactions.reference_no = 'NL-' + TRACE NO.` (pareho sa webhook, kaya walang doble) |
| `SEQ NO.` | `qr_ph_trace_no` |
| `SOURCE ACCOUNT NO.` | payer name sa payload |
| `TRAN AMOUNT` | `amount`; `fee` / `net_amount` = fee setting ng client sa Admin (Fixed / MDR % / Bracket) |
| `DISCOUNT` / `NET SETTLEMENT` | `provider_fee` / `provider_net` (Nationlink, para sa reconciliation) |
| `TIME STAMP` | `transaction_date` |

Naka-record bilang `Cash In`, provider `nationlink`, gamit ang Nationlink gateway ng client
(`sb_record_gateway_payment`), na may `recorded_via = settlement_import` sa payload.

## Mga format

- **PDF** — ang mismong DTQR PDF ng Nationlink (pure-PHP reader, walang Composer).
- **Excel (.xlsx)** at **CSV** — pareho ang layout ng report (naka-group sa MemberID), *o* isang simpleng
  table na may `MEMBER ID` column bukod sa mga column ng report.
- Ang lumang `.xls` (Excel 97-2003) ay i-*Save As* muna sa `.xlsx` o CSV.

## Mga binago / bagong file

- Bago: `includes/settlement-import.php`, `api/settlement_import.php`, `settlement-import.php`,
  `database/migration_v5_24_settlement_import.sql`, `database/migration_v5_25_fee_brackets.sql`
- Binago: `includes/gateways.php` (Bracket fee), `api/client_gateways.php`, `includes/org-credentials-card.php`
  (Admin fee form), `client-fees.php`, `api/client_fees.php`, `includes/header.php` (Admin menu), `V5_CHANGELOG.txt`

## Client Fees — MDR (1.5%), Fixed at Bracket (V5.25, Admin lang)

Admin → client page → **Payment Credentials** → Edit (hal. Nationlink) → **Fees / MDR**:

| Fee Type | Halimbawa |
| --- | --- |
| Fixed | ₱10.00 bawat transaction |
| Percentage (MDR) | 1.5% |
| Fixed + Percentage | ₱10.00 + 1.5% |
| **Bracket by amount** | ₱0.01–₱100: ₱5.00 · ₱100.01–₱1,000: ₱10.00 + 1.5% · ₱1,000.01 pataas: 1.5% |

- Sa Bracket, bawat hanay ay may **From / To / Fixed Fee / MDR %**. Iwang blangko ang *To* sa huli para "and above".
  Bawal mag-overlap ang brackets. Puwede pa ring lagyan ng Minimum / Maximum fee.
- May live na halimbawa (₱100 / ₱1,000 / ₱10,000) habang nag-e-edit.
- Ginagamit ito ng webhook, ng Settlement Import, at ng "Recalculate Fees" / "Apply this fee to existing transactions".
- Admin lang ang makakapag-set; net amount lang ang nakikita ng client.

## Fee Setup per Client (V5.26)

Iba-iba ang setup ng bawat client — naka-save ang fee **kada client at kada provider**, kaya ang pagbago
sa isang client ay hindi nakakaapekto sa iba.

- Admin → **Client Fees** → *Fee Setup per Client*: listahan ng lahat ng client at provider, ang fee setup nila,
  at ang fee sa ₱100 / ₱1,000 / ₱10,000. May search.
- **Edit Fee** → bubukas agad ang fee editor ng client na iyon (Fixed / MDR % / Fixed + MDR / Bracket).

## Magkaiba ang Nationlink at PayMongo sa bawat client (V5.27)

Sa Admin → **Client Fees** → *Fee Setup per Client*, isang row kada client na may **Nationlink** column at
**PayMongo** column. Hiwalay ang setup ng dalawa (hal. Nationlink = Bracket, PayMongo = ₱10 fixed billed separately).

- **Edit Nationlink fee** / **Edit PayMongo fee** — binabago lang ang provider na iyon ng client na iyon.
- **+ Set up PayMongo** (o Nationlink) — kung wala pang setup ang client para sa provider na iyon.
- Bawat bayad ay gumagamit ng fee ng provider na pinanggalingan nito (Nationlink webhook at Settlement Import →
  Nationlink fee ng client; PayMongo webhook → PayMongo fee ng client).

## Nationlink MDR = 1.5% (SQL, Nationlink lang)

`database/update_nationlink_mdr_1_5.sql` — patakbuhin sa phpMyAdmin (mag-backup muna):

- Lahat ng client: Nationlink fee → **Percentage 1.5%**, deducted (walang fixed, walang min/max). Hindi ginagalaw ang PayMongo.
- Nire-recalculate ang mga dating Nationlink collection: `fee = ROUND(amount × 1.5%, 2)`, `net = amount − fee`.
- Gumagawa ng backup tables (`bk_nl_mdr_gateways`, `bk_nl_mdr_transactions`); nasa dulo ng file ang Undo.
- Kung may Nationlink gateway na `credit_wallet = 1`, gamitin ang **Recalculate Fees** button para maitama rin ang balances.
