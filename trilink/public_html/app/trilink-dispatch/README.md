# TriLink Dispatching Portal — Terminal Pila

Kapag nagbu-book ng **Ride** ang pasahero, may tanong na **"Saan ka sasakay?"**:

| Option | Ano ang nangyayari |
|---|---|
| **Terminal** | Pipili ng terminal. Ang pick-up ay ang terminal mismo. **Hindi** ito makikita ng mga driver sa labas — ang **dispatcher** ng terminal ang mag-a-assign nito sa tricycle na nasa **unahan ng pila**. |
| **Not in Terminal** | Gaya ng dati: makikita ng mga malapit na driver at sila ang mag-a-accept. |

Lalabas lang ang tanong kapag may kahit isang active na terminal.

## Mga parte

- **Dispatching Portal** – `https://app.trilink.site/trilink-dispatch/`
  Login gamit ang mobile number + 6-digit PIN. Makikita ng dispatcher ang:
  - **Mga Pasaherong Naghihintay** – may malaking button na *"I-dispatch kay #1 …"*, pwede ring pumili ng ibang driver sa pila,
    *Release sa labas* (ibibigay sa mga driver na wala sa terminal), o *Cancel* (auto-refund kung wallet ang bayad).
  - **Pila ng Tricycle** – idagdag ang driver gamit ang driver code, phone o plate number; ilipat sa hulihan; o alisin.
  - **Na-dispatch Ngayon** – listahan ng mga na-assign ngayong araw.
  - Auto-refresh tuwing 4 segundo, may tunog/vibrate kapag may bagong pasahero.
- **Driver app** – may toggle sa ilalim ng Online switch: **Terminal** o **Not in Terminal**.
  - **Terminal**: piliin ang terminal → *Pumila*. Makikita ang posisyon (#1, #2 …). **Walang street requests**
    (ride, delivery, order) habang naka-Terminal — ang dispatcher lang ang magbibigay ng pasahero.
    Kapag na-dispatch, awtomatikong bubukas ang trip. Pagkatapos ng trip, naka-Terminal pa rin pero wala na sa pila
    → bumalik sa terminal at pindutin ang *Pumila ulit*.
  - **Not in Terminal**: aalis sa pila at tatanggap ng requests sa paligid, gaya ng dati.
- **Admin → Terminals & Dispatch** – gumawa ng terminal (pangalan, address, latitude/longitude, vehicle type) at ng dispatcher account.

## Mga patakaran

- Para makapila mula sa sariling app, kailangang nasa loob ng **500 m** ng terminal ang driver (GPS).
  Palitan sa `tl_settings.terminal_join_radius_m` (`0` = walang check). Walang ganitong check kapag ang dispatcher ang nagdagdag.
- Pareho sa normal na accept: isang trip lang sa isang pagkakataon, kailangang may minimum wallet balance
  (`driver_min_wallet_balance`), at ibinabawas ang commission (`driver_commission_per_trip`) pag na-dispatch.
- Lalabas sa pila ang driver kapag: na-dispatch, nag-offline, lumipat sa Not in Terminal, o inalis ng dispatcher.
  Kapag nag-offline, naka-Terminal mode pa rin siya — kailangan lang *Pumila ulit* pagbalik.
- Kapag dinagdag ng dispatcher ang driver sa pila, awtomatikong magiging Terminal mode ang driver.
- Kapag kinansela ng pasahero ang ride matapos ma-dispatch, **babalik ang driver sa dati niyang puwesto** sa pila.
- Kapag na-deactivate ng admin ang terminal: mabubura ang pila, babalik sa Not in Terminal ang mga driver nito,
  at ang mga naghihintay na pasahero ay ibibigay sa mga driver sa labas.
- Hindi pwedeng i-accept ng driver sa labas ang Terminal booking (sa dispatcher lang dumadaan).

## Go-live steps

1. Sa phpMyAdmin, i-run ang `migration_dispatch.sql` (pwedeng ulitin, walang masisira).
2. I-upload ang mga file. Siguraduhing tama ang `DB_PASS` sa `trilink-dispatch/config.php` (kapareho ng ibang TriLink apps).
3. Admin → **Terminals & Dispatch** → *New Terminal*. Para makuha ang lat/lng: sa Google Maps, i-right-click ang mismong terminal at kopyahin ang numbers.
4. *New Dispatcher* → ilagay ang mobile number, terminal, at 6-digit PIN.
5. Mag-login ang dispatcher sa `https://app.trilink.site/trilink-dispatch/`.
