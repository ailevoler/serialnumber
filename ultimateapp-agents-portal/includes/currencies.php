<?php
declare(strict_types=1);

/**
 * Active ISO 4217 currencies the exchange can offer: code => [name, decimals].
 * Decimals are the currency's minor unit (JPY 0, USD 2, KWD 3); wallets store whole minor units.
 * Precious metals, funds and crypto are not included. PHP is the home currency (BCash).
 */
function fx_currency_catalog(): array
{
    static $c = [
        'USD' => ['US Dollar', 2], 'EUR' => ['Euro', 2], 'JPY' => ['Japanese Yen', 0], 'GBP' => ['British Pound', 2],
        'AUD' => ['Australian Dollar', 2], 'CAD' => ['Canadian Dollar', 2], 'CHF' => ['Swiss Franc', 2], 'CNY' => ['Chinese Yuan', 2],
        'HKD' => ['Hong Kong Dollar', 2], 'SGD' => ['Singapore Dollar', 2], 'KRW' => ['South Korean Won', 0], 'TWD' => ['New Taiwan Dollar', 2],
        'NZD' => ['New Zealand Dollar', 2], 'AED' => ['UAE Dirham', 2], 'SAR' => ['Saudi Riyal', 2], 'QAR' => ['Qatari Riyal', 2],
        'KWD' => ['Kuwaiti Dinar', 3], 'BHD' => ['Bahraini Dinar', 3], 'OMR' => ['Omani Rial', 3], 'THB' => ['Thai Baht', 2],
        'MYR' => ['Malaysian Ringgit', 2], 'IDR' => ['Indonesian Rupiah', 2], 'VND' => ['Vietnamese Dong', 0], 'INR' => ['Indian Rupee', 2],
        'AFN' => ['Afghan Afghani', 2], 'ALL' => ['Albanian Lek', 2], 'AMD' => ['Armenian Dram', 2], 'ANG' => ['Netherlands Antillean Guilder', 2],
        'AOA' => ['Angolan Kwanza', 2], 'ARS' => ['Argentine Peso', 2], 'AWG' => ['Aruban Florin', 2], 'AZN' => ['Azerbaijani Manat', 2],
        'BAM' => ['Bosnia-Herzegovina Mark', 2], 'BBD' => ['Barbadian Dollar', 2], 'BDT' => ['Bangladeshi Taka', 2], 'BGN' => ['Bulgarian Lev', 2],
        'BIF' => ['Burundian Franc', 0], 'BMD' => ['Bermudian Dollar', 2], 'BND' => ['Brunei Dollar', 2], 'BOB' => ['Bolivian Boliviano', 2],
        'BRL' => ['Brazilian Real', 2], 'BSD' => ['Bahamian Dollar', 2], 'BTN' => ['Bhutanese Ngultrum', 2], 'BWP' => ['Botswana Pula', 2],
        'BYN' => ['Belarusian Ruble', 2], 'BZD' => ['Belize Dollar', 2], 'CDF' => ['Congolese Franc', 2], 'CLP' => ['Chilean Peso', 0],
        'COP' => ['Colombian Peso', 2], 'CRC' => ['Costa Rican Colon', 2], 'CUP' => ['Cuban Peso', 2], 'CVE' => ['Cape Verdean Escudo', 2],
        'CZK' => ['Czech Koruna', 2], 'DJF' => ['Djiboutian Franc', 0], 'DKK' => ['Danish Krone', 2], 'DOP' => ['Dominican Peso', 2],
        'DZD' => ['Algerian Dinar', 2], 'EGP' => ['Egyptian Pound', 2], 'ERN' => ['Eritrean Nakfa', 2], 'ETB' => ['Ethiopian Birr', 2],
        'FJD' => ['Fijian Dollar', 2], 'FKP' => ['Falkland Islands Pound', 2], 'GEL' => ['Georgian Lari', 2], 'GHS' => ['Ghanaian Cedi', 2],
        'GIP' => ['Gibraltar Pound', 2], 'GMD' => ['Gambian Dalasi', 2], 'GNF' => ['Guinean Franc', 0], 'GTQ' => ['Guatemalan Quetzal', 2],
        'GYD' => ['Guyanese Dollar', 2], 'HNL' => ['Honduran Lempira', 2], 'HTG' => ['Haitian Gourde', 2], 'HUF' => ['Hungarian Forint', 2],
        'ILS' => ['Israeli New Shekel', 2], 'IQD' => ['Iraqi Dinar', 3], 'IRR' => ['Iranian Rial', 2], 'ISK' => ['Icelandic Krona', 0],
        'JMD' => ['Jamaican Dollar', 2], 'JOD' => ['Jordanian Dinar', 3], 'KES' => ['Kenyan Shilling', 2], 'KGS' => ['Kyrgyzstani Som', 2],
        'KHR' => ['Cambodian Riel', 2], 'KMF' => ['Comorian Franc', 0], 'KYD' => ['Cayman Islands Dollar', 2], 'KZT' => ['Kazakhstani Tenge', 2],
        'LAK' => ['Lao Kip', 2], 'LBP' => ['Lebanese Pound', 2], 'LKR' => ['Sri Lankan Rupee', 2], 'LRD' => ['Liberian Dollar', 2],
        'LSL' => ['Lesotho Loti', 2], 'LYD' => ['Libyan Dinar', 3], 'MAD' => ['Moroccan Dirham', 2], 'MDL' => ['Moldovan Leu', 2],
        'MGA' => ['Malagasy Ariary', 2], 'MKD' => ['Macedonian Denar', 2], 'MMK' => ['Myanmar Kyat', 2], 'MNT' => ['Mongolian Tugrik', 2],
        'MOP' => ['Macanese Pataca', 2], 'MRU' => ['Mauritanian Ouguiya', 2], 'MUR' => ['Mauritian Rupee', 2], 'MVR' => ['Maldivian Rufiyaa', 2],
        'MWK' => ['Malawian Kwacha', 2], 'MXN' => ['Mexican Peso', 2], 'MZN' => ['Mozambican Metical', 2], 'NAD' => ['Namibian Dollar', 2],
        'NGN' => ['Nigerian Naira', 2], 'NIO' => ['Nicaraguan Cordoba', 2], 'NOK' => ['Norwegian Krone', 2], 'NPR' => ['Nepalese Rupee', 2],
        'PAB' => ['Panamanian Balboa', 2], 'PEN' => ['Peruvian Sol', 2], 'PGK' => ['Papua New Guinean Kina', 2], 'PKR' => ['Pakistani Rupee', 2],
        'PLN' => ['Polish Zloty', 2], 'PYG' => ['Paraguayan Guarani', 0], 'RON' => ['Romanian Leu', 2], 'RSD' => ['Serbian Dinar', 2],
        'RUB' => ['Russian Ruble', 2], 'RWF' => ['Rwandan Franc', 0], 'SBD' => ['Solomon Islands Dollar', 2], 'SCR' => ['Seychellois Rupee', 2],
        'SDG' => ['Sudanese Pound', 2], 'SEK' => ['Swedish Krona', 2], 'SHP' => ['Saint Helena Pound', 2], 'SLE' => ['Sierra Leonean Leone', 2],
        'SOS' => ['Somali Shilling', 2], 'SRD' => ['Surinamese Dollar', 2], 'SSP' => ['South Sudanese Pound', 2], 'STN' => ['Sao Tome and Principe Dobra', 2],
        'SYP' => ['Syrian Pound', 2], 'SZL' => ['Swazi Lilangeni', 2], 'TJS' => ['Tajikistani Somoni', 2], 'TMT' => ['Turkmenistani Manat', 2],
        'TND' => ['Tunisian Dinar', 3], 'TOP' => ['Tongan Paanga', 2], 'TRY' => ['Turkish Lira', 2], 'TTD' => ['Trinidad and Tobago Dollar', 2],
        'TZS' => ['Tanzanian Shilling', 2], 'UAH' => ['Ukrainian Hryvnia', 2], 'UGX' => ['Ugandan Shilling', 0], 'UYU' => ['Uruguayan Peso', 2],
        'UZS' => ['Uzbekistani Som', 2], 'VES' => ['Venezuelan Bolivar', 2], 'VUV' => ['Vanuatu Vatu', 0], 'WST' => ['Samoan Tala', 2],
        'XAF' => ['Central African CFA Franc', 0], 'XCD' => ['East Caribbean Dollar', 2], 'XOF' => ['West African CFA Franc', 0], 'XPF' => ['CFP Franc', 0],
        'YER' => ['Yemeni Rial', 2], 'ZAR' => ['South African Rand', 2], 'ZMW' => ['Zambian Kwacha', 2], 'ZWL' => ['Zimbabwean Dollar', 2],
    ];
    return $c;
}

/** The main pair first, then the currencies most used around Boracay (tourists and OFW remittance). */
const FX_MAIN = 'USD';
const FX_POPULAR = ['USD', 'EUR', 'JPY', 'GBP', 'AUD', 'CAD', 'KRW', 'CNY', 'SGD', 'HKD', 'TWD', 'AED', 'SAR', 'QAR', 'KWD', 'CHF', 'NZD', 'THB', 'MYR', 'IDR', 'INR'];

function fx_currency_name(string $code): string
{
    return fx_currency_catalog()[$code][0] ?? $code;
}

function fx_currency_decimals(string $code): int
{
    return fx_currency_catalog()[$code][1] ?? 2;
}

/** Flag emoji from the ISO country part of the code (EUR -> EU); empty for regional codes like XAF. */
function fx_currency_flag(string $code): string
{
    $cc = substr($code, 0, 2);
    if ($code === 'ANG' || $code[0] === 'X') return '';
    $a = 0x1F1E6 - ord('A');
    return mb_chr($a + ord($cc[0]), 'UTF-8') . mb_chr($a + ord($cc[1]), 'UTF-8');
}
