<?php
/** Mobile numbers with a country picker, stored in E.164 format (e.g. +639171234567). */

function phone_countries(): array
{
    // iso => [name, dial code, flag]
    return [
        'PH' => ['Philippines', '+63', '🇵🇭'], 'US' => ['United States', '+1', '🇺🇸'], 'CA' => ['Canada', '+1', '🇨🇦'],
        'SG' => ['Singapore', '+65', '🇸🇬'], 'MY' => ['Malaysia', '+60', '🇲🇾'], 'HK' => ['Hong Kong', '+852', '🇭🇰'],
        'JP' => ['Japan', '+81', '🇯🇵'], 'KR' => ['South Korea', '+82', '🇰🇷'], 'TW' => ['Taiwan', '+886', '🇹🇼'],
        'CN' => ['China', '+86', '🇨🇳'], 'ID' => ['Indonesia', '+62', '🇮🇩'], 'TH' => ['Thailand', '+66', '🇹🇭'],
        'VN' => ['Vietnam', '+84', '🇻🇳'], 'AE' => ['United Arab Emirates', '+971', '🇦🇪'], 'SA' => ['Saudi Arabia', '+966', '🇸🇦'],
        'QA' => ['Qatar', '+974', '🇶🇦'], 'KW' => ['Kuwait', '+965', '🇰🇼'], 'AU' => ['Australia', '+61', '🇦🇺'],
        'NZ' => ['New Zealand', '+64', '🇳🇿'], 'GB' => ['United Kingdom', '+44', '🇬🇧'], 'IT' => ['Italy', '+39', '🇮🇹'],
    ];
}

/** Returns [true, '+63917...', ''] or [false, '', 'error message']. */
function normalize_phone(string $iso, string $input): array
{
    $countries = phone_countries();
    if (!isset($countries[$iso])) return [false, '', 'Please choose a country.'];
    $digits = preg_replace('/\D+/', '', $input);
    $dial = substr($countries[$iso][1], 1);
    if ($digits === '') return [false, '', 'Please enter your mobile number.'];
    if (str_starts_with($input, '+') || str_starts_with($digits, '00')) {
        $digits = ltrim($digits, '0');
        if (!str_starts_with($digits, $dial)) return [false, '', 'That number does not match the selected country.'];
        $digits = substr($digits, strlen($dial));
    }
    $digits = ltrim($digits, '0'); // national trunk prefix, e.g. 0917… in the Philippines
    if ($iso === 'PH' && !preg_match('/^9\d{9}$/', $digits)) {
        return [false, '', 'Enter a Philippine mobile number, e.g. 0917 123 4567.'];
    }
    if (strlen($digits) < 6 || strlen($dial . $digits) > 15) return [false, '', 'Please enter a valid mobile number.'];
    return [true, '+' . $dial . $digits, ''];
}

function format_phone(?string $e164, string $iso = 'PH'): string
{
    if (!$e164) return '';
    $c = phone_countries()[$iso] ?? null;
    if ($c && str_starts_with($e164, $c[1])) {
        $n = substr($e164, strlen($c[1]));
        if ($iso === 'PH' && strlen($n) === 10) return '0' . substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6);
        return $c[1] . ' ' . $n;
    }
    return $e164;
}

/** Country select + number input (the "phone-input" component). */
function phone_field(string $iso = 'PH', string $value = '', string $error = ''): string
{
    $opts = '';
    foreach (phone_countries() as $code => [$name, $dial, $flag]) {
        $opts .= '<option value="' . $code . '"' . ($code === $iso ? ' selected' : '') . '>' . $flag . ' ' . e($name) . ' (' . $dial . ')</option>';
    }
    return '<div class="phone-field' . ($error ? ' has-error' : '') . '">'
        . '<label class="field phone-country">' . icon('globe') . '<select name="phone_country" aria-label="Country">' . $opts . '</select>' . icon('chevron-down', 'select-caret') . '</label>'
        . '<label class="field">' . icon('phone') . '<input type="tel" name="phone" inputmode="tel" autocomplete="tel-national" placeholder="Mobile number, e.g. 0917 123 4567" value="' . e($value) . '" maxlength="20" required></label>'
        . ($error ? '<p class="field-error">' . e($error) . '</p>' : '')
        . '</div>';
}
