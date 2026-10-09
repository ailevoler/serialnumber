# SurgeBox V5.24 — Nationlink Settlement Report Import

Patch para sa SurgeBox (`public_html`) na nag-i-import ng daily **QR TRANSACTIONS REPORT (DTQR)** ng
Nationlink (PDF, Excel `.xlsx` o CSV) papunta sa transactions ng client, para lumabas sa client dashboard,
ledger at settlement.

## Paano i-install

1. I-upload ang laman ng `public_html/` sa server, papalitan ang mga file na may parehong pangalan
   (kasama ang kopya sa `public_html/portal/`).
2. phpMyAdmin → SQL: patakbuhin nang isang beses ang `database/migration_v5_24_settlement_import.sql`
   (para sa import history; ligtas kahit patakbuhin ulit).
3. Admin → **Settlement Import** (bagong menu sa ilalim ng Settlement Report).

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
| `TRAN AMOUNT` / `NET SETTLEMENT` | `amount` / `net_amount`; `fee = TRAN AMOUNT − NET SETTLEMENT` |
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
  `database/migration_v5_24_settlement_import.sql`
- Binago: `includes/gateways.php` (optional na `fee` sa `sb_record_gateway_payment`),
  `includes/header.php` (Admin menu), `V5_CHANGELOG.txt` (V5.24)
