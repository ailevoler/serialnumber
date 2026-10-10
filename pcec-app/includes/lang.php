<?php
// Minimal EN / Filipino translations for the main UI labels.
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fil'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

function lang(): string
{
    return $_SESSION['lang'] ?? 'en';
}

function t(string $key): string
{
    static $fil = [
        'Welcome Back!' => 'Maligayang Pagbabalik!',
        'Welcome Back,' => 'Maligayang Pagbabalik,',
        'Log in to your PCEC Community Platform' => 'Mag-log in sa iyong PCEC Community Platform',
        'Email Address or Username' => 'Email Address o Username',
        'Password' => 'Password',
        'Remember me' => 'Tandaan ako',
        'Forgot your password?' => 'Nakalimutan ang password?',
        'Log In' => 'Mag-log In',
        'OR CONTINUE WITH' => 'O MAGPATULOY GAMIT ANG',
        'Continue with Google' => 'Magpatuloy gamit ang Google',
        'Continue with Facebook' => 'Magpatuloy gamit ang Facebook',
        "Don't have an account?" => 'Wala pang account?',
        'Create an Account' => 'Gumawa ng Account',
        'Create Your Account' => 'Gumawa ng Iyong Account',
        'Join the PCEC Community Platform and be part of our growing network of churches and leaders.' => 'Sumali sa PCEC Community Platform at maging bahagi ng lumalaking network ng mga simbahan at lider.',
        'First Name' => 'Pangalan',
        'Last Name' => 'Apelyido',
        'Email Address' => 'Email Address',
        'Username' => 'Username',
        'Confirm Password' => 'Kumpirmahin ang Password',
        'Church / Organization (Optional)' => 'Simbahan / Organisasyon (Opsyonal)',
        'Create Account' => 'Gumawa ng Account',
        'OR SIGN UP WITH' => 'O MAG-SIGN UP GAMIT ANG',
        'Already have an account?' => 'May account na?',
        'Get Started' => 'Magsimula',
        'Skip' => 'Laktawan',
        'Next' => 'Susunod',
        'Home' => 'Home',
        'Events' => 'Mga Kaganapan',
        'Post' => 'Post',
        'Chat' => 'Chat',
        'More' => 'Iba pa',
        'Our Churches' => 'Mga Simbahan',
        'Members' => 'Mga Miyembro',
        'Resources' => 'Mga Resource',
        'Prayer Requests' => 'Mga Panalangin',
        'Posts' => 'Mga Post',
        'Follow' => 'Sundan',
        'Latest Posts' => 'Pinakabagong Post',
        'Upcoming Events' => 'Mga Paparating na Kaganapan',
        'See All' => 'Tingnan Lahat',
        "What's on your mind?" => 'Ano ang nasa isip mo?',
        'Photo' => 'Larawan',
        'Video' => 'Video',
        'Event' => 'Kaganapan',
        'File' => 'File',
        'Together in Christ for a Greater Philippines.' => 'Sama-sama kay Kristo para sa Mas Dakilang Pilipinas.',
        'Notifications' => 'Mga Abiso',
        'Profile' => 'Profile',
        'Log Out' => 'Mag-log Out',
        'Learn More' => 'Alamin Pa',
        'Good Morning,' => 'Magandang Umaga,',
        'Good Afternoon,' => 'Magandang Hapon,',
        'Good Evening,' => 'Magandang Gabi,',
        'Give' => 'Magbigay',
        'Donation & Giving' => 'Donasyon at Pagbibigay',
        'Featured Churches' => 'Mga Tampok na Simbahan',
        'Event Details' => 'Detalye ng Kaganapan',
        'My Giving' => 'Aking mga Kaloob',
    ];
    return lang() === 'fil' ? ($fil[$key] ?? $key) : $key;
}

function lang_switch(): string
{
    $cur = lang();
    $q = $_GET;
    $q['lang'] = $cur === 'en' ? 'fil' : 'en';
    return '<a class="lang-switch" href="?' . e(http_build_query($q)) . '" title="Switch language">'
        . strtoupper($cur === 'en' ? 'EN' : 'FIL') . ' ' . icon('chevron-down') . '</a>';
}
