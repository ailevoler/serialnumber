<?php
declare(strict_types=1);

/**
 * Website CMS — editable content for the public landing page (home.php).
 * Section fields are defined here once; values live in `cms_content`, repeatable items in `cms_items`.
 */

/** Field types: text, textarea, image, url, toggle, lines (one item per line). */
function cms_sections(): array
{
    return [
        'general' => ['label' => 'Site & SEO', 'icon' => 'settings', 'fields' => [
            'site.meta_title'       => ['Browser title (SEO)', 'text', 'XGEN LIFE International — Better Health. Brighter Future. Together.'],
            'site.meta_description' => ['Meta description (SEO)', 'textarea', 'XGEN LIFE International offers premium wellness products and a rewarding business opportunity with 6 ways to earn.'],
            'site.og_image'         => ['Social share image', 'image', ''],
            'site.logo'             => ['Header logo', 'image', ''],
            'notice.enabled'        => ['Show announcement bar', 'toggle', '0'],
            'notice.text'           => ['Announcement text', 'text', 'Grand launch promo — register this month and get exclusive welcome gifts.'],
            'notice.link'           => ['Announcement link', 'url', '#contact'],
        ]],
        'hero' => ['label' => 'Hero (top banner)', 'icon' => 'home', 'fields' => [
            'hero.eyebrow'       => ['Small label', 'text', 'Wellness · Opportunity · Community'],
            'hero.title'         => ['Headline', 'text', 'Better health, brighter future — together.'],
            'hero.subtitle'      => ['Sub-headline', 'textarea', 'Premium wellness products and a transparent, rewarding business opportunity for every Filipino family.'],
            'hero.cta_label'     => ['Primary button text', 'text', 'Join XGEN LIFE'],
            'hero.cta_link'      => ['Primary button link', 'url', 'register.php'],
            'hero.cta2_label'    => ['Secondary button text', 'text', 'Explore products'],
            'hero.cta2_link'     => ['Secondary button link', 'url', '#products'],
            'hero.image'         => ['Hero image (leave empty to use the logo)', 'image', ''],
            'hero.trust'         => ['Trust points (one per line)', 'lines', "Quality-assured wellness products\nQR Ph & e-wallet payouts\nLive member support"],
        ]],
        'stats' => ['label' => 'Stats strip', 'icon' => 'chart', 'items' => 'stat', 'fields' => [
            'stats.enabled' => ['Show section', 'toggle', '1'],
        ]],
        'about' => ['label' => 'About us', 'icon' => 'user', 'fields' => [
            'about.enabled'  => ['Show section', 'toggle', '1'],
            'about.eyebrow'  => ['Small label', 'text', 'About XGEN LIFE'],
            'about.title'    => ['Title', 'text', 'A wellness company built on trust and shared success.'],
            'about.body'     => ['Text', 'textarea', "XGEN LIFE International brings together carefully selected wellness products and a fair, technology-driven business model. We help members take care of their health while building a sustainable income — with clear rules, fast payouts and real support.\n\nOur mission is simple: better health and a brighter future, together."],
            'about.points'   => ['Highlights (one per line)', 'lines', "Quality-assured wellness products\nTransparent compensation plan\nSecure digital platform and e-wallet\nTraining and community support"],
            'about.image'    => ['Image', 'image', ''],
        ]],
        'products' => ['label' => 'Products', 'icon' => 'box', 'fields' => [
            'products.enabled'  => ['Show section', 'toggle', '1'],
            'products.eyebrow'  => ['Small label', 'text', 'Our Products'],
            'products.title'    => ['Title', 'text', 'Wellness essentials for everyday life.'],
            'products.subtitle' => ['Text', 'textarea', 'Members enjoy exclusive member pricing and earn on every repeat purchase.'],
            'products.count'    => ['Number of products to show', 'text', '4'],
            'products.show_price' => ['Show SRP price', 'toggle', '1'],
        ]],
        'features' => ['label' => 'Why XGEN LIFE', 'icon' => 'shield', 'items' => 'feature', 'fields' => [
            'features.enabled'  => ['Show section', 'toggle', '1'],
            'features.eyebrow'  => ['Small label', 'text', 'Why XGEN LIFE'],
            'features.title'    => ['Title', 'text', 'Designed for people who want more from their wellness journey.'],
        ]],
        'opportunity' => ['label' => 'Business opportunity', 'icon' => 'trending', 'fields' => [
            'opportunity.enabled'   => ['Show section', 'toggle', '1'],
            'opportunity.eyebrow'   => ['Small label', 'text', 'Business Opportunity'],
            'opportunity.title'     => ['Title', 'text', '6 ways to earn with one simple plan.'],
            'opportunity.body'      => ['Text', 'textarea', 'Share products you believe in and grow a team. Every bonus is computed automatically and credited to your e-wallet.'],
            'opportunity.cta_label' => ['Button text', 'text', 'Start your business'],
            'opportunity.cta_link'  => ['Button link', 'url', 'register.php'],
        ]],
        'testimonials' => ['label' => 'Testimonials', 'icon' => 'message', 'items' => 'testimonial', 'fields' => [
            'testimonials.enabled' => ['Show section', 'toggle', '1'],
            'testimonials.eyebrow' => ['Small label', 'text', 'Member Stories'],
            'testimonials.title'   => ['Title', 'text', 'Real people. Real results.'],
        ]],
        'faq' => ['label' => 'FAQ', 'icon' => 'book', 'items' => 'faq', 'fields' => [
            'faq.enabled' => ['Show section', 'toggle', '1'],
            'faq.eyebrow' => ['Small label', 'text', 'FAQ'],
            'faq.title'   => ['Title', 'text', 'Frequently asked questions'],
        ]],
        'cta' => ['label' => 'Call-to-action band', 'icon' => 'megaphone', 'fields' => [
            'cta.enabled' => ['Show section', 'toggle', '1'],
            'cta.title'   => ['Title', 'text', 'Ready to start your XGEN LIFE journey?'],
            'cta.text'    => ['Text', 'textarea', 'Talk to a member near you or create your account today.'],
            'cta.label'   => ['Button text', 'text', 'Create my account'],
            'cta.link'    => ['Button link', 'url', 'register.php'],
        ]],
        'contact' => ['label' => 'Contact', 'icon' => 'phone', 'fields' => [
            'contact.enabled' => ['Show section', 'toggle', '1'],
            'contact.eyebrow' => ['Small label', 'text', 'Contact Us'],
            'contact.title'   => ['Title', 'text', "Let's talk."],
            'contact.text'    => ['Text', 'textarea', 'Questions about our products or the business? Send us a message and our team will get back to you within one business day.'],
            'contact.address' => ['Office address', 'textarea', 'Metro Manila, Philippines'],
            'contact.email'   => ['Email', 'text', 'support@xgenlife.com'],
            'contact.phone'   => ['Phone / mobile', 'text', ''],
            'contact.hours'   => ['Office hours', 'text', 'Mon–Sat, 8:00 AM – 6:00 PM'],
            'contact.map'     => ['Google Maps link', 'url', ''],
        ]],
        'footer' => ['label' => 'Footer', 'icon' => 'list', 'fields' => [
            'footer.about'     => ['Short company text', 'textarea', 'Premium wellness products and a rewarding business opportunity for every Filipino family.'],
            'footer.facebook'  => ['Facebook URL', 'url', ''],
            'footer.instagram' => ['Instagram URL', 'url', ''],
            'footer.tiktok'    => ['TikTok URL', 'url', ''],
            'footer.youtube'   => ['YouTube URL', 'url', ''],
            'footer.legal'     => ['Legal / registration note', 'text', 'XGEN LIFE International. All rights reserved.'],
        ]],
    ];
}

/** Repeatable item types: [label, which fields are used]. */
function cms_item_types(): array
{
    return [
        'stat'        => ['label' => 'Stats',         'section' => 'stats',        'fields' => ['title' => 'Value (e.g. 6)', 'subtitle' => 'Label']],
        'feature'     => ['label' => 'Why XGEN items', 'section' => 'features',    'fields' => ['icon' => 'Icon', 'title' => 'Title', 'body' => 'Description']],
        'testimonial' => ['label' => 'Testimonials',  'section' => 'testimonials', 'fields' => ['title' => 'Name', 'subtitle' => 'Role / location', 'body' => 'Quote', 'image' => 'Photo']],
        'faq'         => ['label' => 'FAQ',           'section' => 'faq',          'fields' => ['title' => 'Question', 'body' => 'Answer']],
    ];
}

function cms_icon_choices(): array
{
    return ['shield', 'trending', 'wallet', 'users', 'box', 'tag', 'message', 'git-merge', 'repeat', 'user-plus', 'chart', 'layers', 'calendar', 'phone', 'card'];
}

/** All field defaults keyed by content key. */
function cms_defaults(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        foreach (cms_sections() as $s) {
            foreach ($s['fields'] as $k => $f) {
                $d[$k] = $f[2];
            }
        }
    }
    return $d;
}

function cms_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach (q_all('SELECT ckey, cvalue FROM cms_content') as $r) {
                $cache[$r['ckey']] = $r['cvalue'];
            }
        } catch (Throwable $e) {
            $cache = []; // table not installed yet: fall back to defaults
        }
    }
    return $cache;
}

function cms(string $key): string
{
    $all = cms_all();
    return (string)($all[$key] ?? cms_defaults()[$key] ?? '');
}

function cms_on(string $section): bool
{
    return cms($section . '.enabled') !== '0';
}

function cms_lines(string $key): array
{
    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', cms($key))))));
}

function cms_save(string $key, string $value, ?int $userId): void
{
    q('INSERT INTO cms_content (ckey, cvalue, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), updated_by = VALUES(updated_by), updated_at = NOW()',
        [$key, $value, $userId]);
}

function cms_items(string $type, bool $publishedOnly = true): array
{
    try {
        return q_all('SELECT * FROM cms_items WHERE type = ?' . ($publishedOnly ? " AND status = 'published'" : '') . ' ORDER BY sort_order, id', [$type]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Public URL for a CMS image value (uploads path or absolute URL), or the fallback. */
function cms_img(string $value, string $fallback = ''): string
{
    if ($value === '') {
        return $fallback;
    }
    return preg_match('#^(https?:)?//#', $value) ? $value : url('uploads/' . ltrim($value, '/'));
}

/** Link value: anchors and absolute URLs as-is, otherwise relative to the site. */
function cms_link(string $value): string
{
    if ($value === '' || $value[0] === '#' || preg_match('#^(https?:|mailto:|tel:)#i', $value)) {
        return $value === '' ? '#' : $value;
    }
    return url($value);
}

/** Which page the site root shows: landing | coming_soon | login. */
function home_mode(): string
{
    $m = (string)setting('home_mode', '');
    if (in_array($m, ['landing', 'coming_soon', 'login'], true)) {
        return $m;
    }
    return setting('coming_soon_enabled', '0') === '1' ? 'coming_soon' : 'login';
}

/** Save an uploaded image from a nested file input (e.g. name="img[hero.image]") and register it in the media library. */
function cms_upload(string $input, string $key, ?int $userId): ?string
{
    $f = $_FILES[$input] ?? null;
    if (!$f || !isset($f['error'][$key]) || $f['error'][$key] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $_FILES['__cms_upload'] = ['name' => $f['name'][$key], 'type' => $f['type'][$key], 'tmp_name' => $f['tmp_name'][$key], 'error' => $f['error'][$key], 'size' => $f['size'][$key]];
    $path = upload_image('__cms_upload', 'cms');
    unset($_FILES['__cms_upload']);
    if ($path) {
        q('INSERT INTO cms_media (path, alt, uploaded_by) VALUES (?,?,?)', [$path, $key, $userId]);
    }
    return $path;
}

/** Image picker field: preview, upload, choose from library, or clear. */
function cms_image_field(string $name, string $key, string $value): string
{
    static $media = null;
    $media ??= q_all('SELECT path, alt FROM cms_media ORDER BY id DESC LIMIT 60');
    $h = '<div class="img-field">';
    $h .= '<div class="img-preview">' . ($value !== '' ? '<img src="' . e(cms_img($value)) . '" alt="">' : '<span>No image</span>') . '</div><div class="img-controls">';
    $h .= '<input class="input" type="file" name="img[' . e($key) . ']" accept="image/*">';
    $h .= '<select class="input" name="' . e($name) . '"><option value="' . e($value) . '">' . ($value !== '' ? 'Keep current image' : '— No image —') . '</option>';
    if ($value !== '') {
        $h .= '<option value="">Remove image</option>';
    }
    foreach ($media as $m) {
        if ($m['path'] !== $value) {
            $h .= '<option value="' . e($m['path']) . '">Library: ' . e(basename($m['path'])) . ($m['alt'] ? ' (' . e($m['alt']) . ')' : '') . '</option>';
        }
    }
    return $h . '</select><div class="help">Upload a new image (JPG/PNG/WEBP, max 3MB) or pick one from the Media Library.</div></div></div>';
}
