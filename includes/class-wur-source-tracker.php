<?php

if (! defined('ABSPATH')) {
    exit;
}

class WUR_Source_Tracker
{
    /**
     * @var string[]
     */
    private array $socialHosts = [
        'facebook.com' => 'facebook',
        'm.facebook.com' => 'facebook',
        'instagram.com' => 'instagram',
        'l.instagram.com' => 'instagram',
        't.co' => 'x',
        'twitter.com' => 'x',
        'x.com' => 'x',
        'linkedin.com' => 'linkedin',
        'lnkd.in' => 'linkedin',
        'youtube.com' => 'youtube',
        'youtu.be' => 'youtube',
        'pinterest.com' => 'pinterest',
        'reddit.com' => 'reddit',
        'tiktok.com' => 'tiktok',
        'snapchat.com' => 'snapchat',
    ];

    public function register(): void
    {
        add_action('woocommerce_checkout_create_order', [$this, 'capture_order_source'], 10, 2);
    }

    public function capture_order_source(WC_Order $order): void
    {
        $detected = $this->detect_source();

        $order->update_meta_data('_wur_source', $detected['source']);
        $order->update_meta_data('_wur_source_group', $detected['group']);
        $order->update_meta_data('_wur_source_details', wp_json_encode($detected));
    }

    /**
     * @return array{source:string, group:string, utm_source:string, utm_medium:string, referrer:string}
     */
    private function detect_source(): array
    {
        $utmSource = $this->sanitize_text_value($_REQUEST['utm_source'] ?? '');
        $utmMedium = $this->sanitize_text_value($_REQUEST['utm_medium'] ?? '');
        $referrer = $this->sanitize_url_value($_SERVER['HTTP_REFERER'] ?? '');

        if (! empty($utmSource)) {
            $source = strtolower($utmSource);
            $group = $this->group_from_source($source, strtolower($utmMedium));

            return [
                'source' => $source,
                'group' => $group,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'referrer' => $referrer,
            ];
        }

        if (! empty($referrer)) {
            $host = strtolower((string) wp_parse_url($referrer, PHP_URL_HOST));
            $source = $this->map_referrer_to_source($host);
            $group = $this->group_from_source($source, '');

            return [
                'source' => $source,
                'group' => $group,
                'utm_source' => '',
                'utm_medium' => '',
                'referrer' => $referrer,
            ];
        }

        return [
            'source' => 'direct',
            'group' => 'direct',
            'utm_source' => '',
            'utm_medium' => '',
            'referrer' => '',
        ];
    }

    private function sanitize_text_value(string $value): string
    {
        return sanitize_text_field(wp_unslash($value));
    }

    private function sanitize_url_value(string $value): string
    {
        return esc_url_raw(wp_unslash($value));
    }

    private function map_referrer_to_source(string $host): string
    {
        if (empty($host)) {
            return 'direct';
        }

        foreach ($this->socialHosts as $domain => $source) {
            if ($this->string_ends_with($host, $domain)) {
                return $source;
            }
        }

        if (false !== strpos($host, 'google.')) {
            return 'google';
        }

        return $host;
    }


    private function string_ends_with(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        if (0 === $length) {
            return true;
        }

        return substr($haystack, -$length) === $needle;
    }

    private function group_from_source(string $source, string $medium): string
    {
        $socialSources = array_values($this->socialHosts);

        if (in_array($source, $socialSources, true) || in_array($medium, ['social', 'social-media', 'social_media'], true)) {
            return 'social';
        }

        if (in_array($source, ['direct', '(direct)'], true)) {
            return 'direct';
        }

        if (in_array($medium, ['organic', 'seo'], true) || $source === 'google') {
            return 'organic';
        }

        return 'referral';
    }
}
