<?php

if (! defined('ABSPATH')) {
    exit;
}

require_once WUR_PLUGIN_PATH . 'includes/class-wur-source-tracker.php';
require_once WUR_PLUGIN_PATH . 'includes/class-wur-report-service.php';
require_once WUR_PLUGIN_PATH . 'includes/class-wur-exporter.php';
require_once WUR_PLUGIN_PATH . 'includes/class-wur-admin-page.php';

class WUR_Plugin
{
    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'init']);
    }

    public static function init(): void
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [self::class, 'woocommerce_required_notice']);
            return;
        }

        $tracker = new WUR_Source_Tracker();
        $reportService = new WUR_Report_Service();
        $exporter = new WUR_Exporter($reportService);
        new WUR_Admin_Page($reportService, $exporter);

        $tracker->register();
    }

    public static function woocommerce_required_notice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Woo Uni Reports requires WooCommerce to be active.', 'woo-uni-reports')
        );
    }
}
