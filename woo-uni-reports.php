<?php
/**
 * Plugin Name: Woo Uni Reports
 * Description: Advanced WooCommerce order source, sales and product reporting with export tools.
 * Version: 1.0.0
 * Author: Woo Uni
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: woo-uni-reports
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WUR_PLUGIN_FILE', __FILE__);
define('WUR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WUR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WUR_PLUGIN_PATH . 'includes/class-wur-plugin.php';

WUR_Plugin::boot();
