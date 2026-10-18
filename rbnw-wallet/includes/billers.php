<?php
/**
 * Biller catalog for Pay Bills.
 *
 * Local Aklan/Boracay billers come from rbnw_billers (see schema seed).
 * The national list below is the searchable ECPay / Bayad directory shown
 * in the UI. Confirm each entry against the latest ECPay and Bayad biller
 * lists from your aggregator agreements before going live, and adjust the
 * 'via' flags if a biller is only on one network.
 *
 * Logos: drop assets/img/billers/<slug>.(svg|png|webp) — slug is the
 * lowercase, dash-separated biller name (see biller_slug()). Until a file
 * exists the tile shows the biller's initials.
 */

function national_billers(): array
{
    $e = 'ECPay';
    $b = 'Bayad';
    return [
        // Electricity
        ['Meralco', 'Electricity', [$e, $b]],
        ['Davao Light', 'Electricity', [$e, $b]],
        ['Visayan Electric (VECO)', 'Electricity', [$e, $b]],
        ['Iloilo Electric (MORE Power)', 'Electricity', [$e, $b]],
        // Water
        ['Maynilad', 'Water', [$e, $b]],
        ['Manila Water', 'Water', [$e, $b]],
        ['Metro Iloilo Water', 'Water', [$e, $b]],
        // Telco & internet
        ['PLDT', 'Telco & Internet', [$e, $b]],
        ['Smart Postpaid', 'Telco & Internet', [$e, $b]],
        ['Globe Postpaid', 'Telco & Internet', [$e, $b]],
        ['Globe At Home', 'Telco & Internet', [$e, $b]],
        ['Converge ICT', 'Telco & Internet', [$e, $b]],
        ['DITO Telecommunity', 'Telco & Internet', [$e]],
        // Cable & streaming
        ['Sky Cable', 'Cable TV', [$e, $b]],
        ['Cignal TV', 'Cable TV', [$e, $b]],
        ['SatLite', 'Cable TV', [$e, $b]],
        // Government
        ['SSS', 'Government', [$e, $b]],
        ['Pag-IBIG Fund', 'Government', [$e, $b]],
        ['PhilHealth', 'Government', [$e, $b]],
        ['NBI Clearance', 'Government', [$b]],
        ['DFA Passport', 'Government', [$b]],
        // Loans & credit
        ['Home Credit', 'Loans', [$e, $b]],
        ['Cashalo', 'Loans', [$e]],
        ['Tala', 'Loans', [$e]],
        ['BPI Credit Card', 'Credit Cards', [$e, $b]],
        ['Metrobank Credit Card', 'Credit Cards', [$e, $b]],
        ['RCBC Credit Card', 'Credit Cards', [$e, $b]],
        // Insurance
        ['Pru Life UK', 'Insurance', [$e, $b]],
        ['Sun Life', 'Insurance', [$e, $b]],
        ['Manulife', 'Insurance', [$e, $b]],
        ['AXA Philippines', 'Insurance', [$b]],
        // Travel & others
        ['Cebu Pacific', 'Travel', [$e, $b]],
        ['AirAsia Philippines', 'Travel', [$e]],
        ['2GO Travel', 'Travel', [$e, $b]],
        ['Lazada', 'Shopping', [$e]],
        ['Shopee', 'Shopping', [$e]],
    ];
}

function biller_slug(string $name): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
}

function biller_initials(string $name): string
{
    // "Aklan Electric Cooperative" -> "AE"; "PLDT" -> "PL"; "SSS" -> "SSS"
    $clean = trim(preg_replace('/\(.*?\)|[^A-Za-z0-9 ]/', ' ', $name));
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) === 1) {
        $w = $words[0];
        return strtoupper(ctype_upper($w) && strlen($w) <= 4 ? $w : substr($w, 0, 2));
    }
    return strtoupper($words[0][0] . $words[1][0]);
}

/** <span> with the biller's logo if a file exists, else its initials. */
function biller_logo_html(string $name, ?string $logoPath = null): string
{
    $candidates = [];
    if ($logoPath) {
        $candidates[] = ltrim($logoPath, '/');
    }
    foreach (['svg', 'png', 'webp', 'jpg'] as $ext) {
        $candidates[] = 'assets/img/billers/' . biller_slug($name) . '.' . $ext;
    }
    foreach ($candidates as $rel) {
        if (is_file(APP_ROOT . '/' . $rel)) {
            return '<span class="biller-logo has-img"><img src="' . htmlspecialchars(asset_url($rel)) . '" alt="" loading="lazy"></span>';
        }
    }
    return '<span class="biller-logo">' . htmlspecialchars(biller_initials($name)) . '</span>';
}
