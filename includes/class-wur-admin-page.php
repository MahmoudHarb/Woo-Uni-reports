<?php

if (! defined('ABSPATH')) {
    exit;
}

class WUR_Admin_Page
{
    private WUR_Report_Service $reportService;
    private WUR_Exporter $exporter;

    public function __construct(WUR_Report_Service $reportService, WUR_Exporter $exporter)
    {
        $this->reportService = $reportService;
        $this->exporter = $exporter;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);

        $this->exporter->register();
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Woo Uni Reports', 'woo-uni-reports'),
            __('Uni Reports', 'woo-uni-reports'),
            'manage_woocommerce',
            'woo-uni-reports',
            [$this, 'render_page']
        );
    }

    public function enqueue_styles(string $hook): void
    {
        if ('woocommerce_page_woo-uni-reports' !== $hook) {
            return;
        }

        $css = '.wur-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0 20px}.wur-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px}.wur-table-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;padding:12px;margin-top:10px}.wur-filters{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.wur-filters label{display:flex;flex-direction:column;gap:4px;font-weight:600}';

        wp_register_style('wur-inline-style', false, [], '1.0.0');
        wp_enqueue_style('wur-inline-style');
        wp_add_inline_style('wur-inline-style', $css);
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $filters = $this->read_filters();
        $report = $this->reportService->generate_report($filters);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Woo Uni Reports', 'woo-uni-reports') . '</h1>';
        $this->render_filters($filters);
        $this->render_export_buttons($filters);
        $this->render_summary($report['summary']);
        $this->render_sources_table($report['sources']);
        $this->render_products_table($report['products']);
        echo '</div>';
    }

    /**
     * @return array<string,mixed>
     */
    private function read_filters(): array
    {
        return [
            'period' => sanitize_text_field((string) ($_GET['period'] ?? 'last_30_days')),
            'date_from' => sanitize_text_field((string) ($_GET['date_from'] ?? '')),
            'date_to' => sanitize_text_field((string) ($_GET['date_to'] ?? '')),
            'source' => sanitize_text_field((string) ($_GET['source'] ?? '')),
            'source_group' => sanitize_text_field((string) ($_GET['source_group'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function render_filters(array $filters): void
    {
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="woo-uni-reports"/>';
        echo '<div class="wur-filters">';

        echo '<label>' . esc_html__('Period', 'woo-uni-reports');
        echo '<select name="period">';
        foreach ($this->period_options() as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($filters['period'], $value, false),
                esc_html($label)
            );
        }
        echo '</select></label>';

        echo '<label>' . esc_html__('Date from', 'woo-uni-reports');
        printf('<input type="date" name="date_from" value="%s"/>', esc_attr((string) $filters['date_from']));
        echo '</label>';

        echo '<label>' . esc_html__('Date to', 'woo-uni-reports');
        printf('<input type="date" name="date_to" value="%s"/>', esc_attr((string) $filters['date_to']));
        echo '</label>';

        echo '<label>' . esc_html__('Source group', 'woo-uni-reports');
        echo '<select name="source_group">';
        echo '<option value="">' . esc_html__('All', 'woo-uni-reports') . '</option>';
        foreach (['social', 'organic', 'direct', 'referral'] as $group) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($group),
                selected($filters['source_group'], $group, false),
                esc_html(ucfirst($group))
            );
        }
        echo '</select></label>';

        echo '<label>' . esc_html__('Specific source', 'woo-uni-reports');
        printf('<input type="text" placeholder="facebook, google..." name="source" value="%s"/>', esc_attr((string) $filters['source']));
        echo '</label>';

        submit_button(__('Apply filters', 'woo-uni-reports'), 'primary', '', false);
        echo '</div>';
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function render_export_buttons(array $filters): void
    {
        $baseUrl = wp_nonce_url(
            admin_url('admin-post.php?action=wur_export_report'),
            'wur_export_report'
        );

        $commonParams = [
            'period' => $filters['period'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'source' => $filters['source'],
            'source_group' => $filters['source_group'],
        ];

        echo '<p><strong>' . esc_html__('Export:', 'woo-uni-reports') . '</strong> ';
        foreach (['csv' => 'CSV', 'excel' => 'Excel (.xls)', 'pdf' => 'PDF'] as $format => $label) {
            $url = add_query_arg(array_merge($commonParams, ['format' => $format]), $baseUrl);
            printf('<a class="button button-secondary" style="margin-right:8px" href="%s">%s</a>', esc_url($url), esc_html($label));
        }
        echo '</p>';
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_summary(array $summary): void
    {
        echo '<h2>' . esc_html__('Sales Summary', 'woo-uni-reports') . '</h2>';
        echo '<div class="wur-grid">';
        foreach ($summary as $key => $value) {
            echo '<div class="wur-card">';
            echo '<strong>' . esc_html(ucwords(str_replace('_', ' ', (string) $key))) . '</strong><br/>';

            if ('order_count' === $key) {
                echo esc_html((string) $value);
            } elseif ('margin' === $key) {
                echo esc_html(number_format((float) $value, 2)) . '%';
            } elseif (is_numeric($value)) {
                echo wp_kses_post(wc_price((float) $value));
            } else {
                echo esc_html((string) $value);
            }

            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    private function render_sources_table(array $sources): void
    {
        echo '<h2>' . esc_html__('Order Sources', 'woo-uni-reports') . '</h2>';
        echo '<div class="wur-table-wrap"><table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Source', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Group', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Orders', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Net Sales', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Profit', 'woo-uni-reports') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($sources)) {
            echo '<tr><td colspan="5">' . esc_html__('No source data for current filters.', 'woo-uni-reports') . '</td></tr>';
        } else {
            foreach ($sources as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['source']) . '</td>';
                echo '<td>' . esc_html((string) $row['group']) . '</td>';
                echo '<td>' . esc_html((string) $row['orders']) . '</td>';
                echo '<td>' . wp_kses_post(wc_price((float) $row['net_sales'])) . '</td>';
                echo '<td>' . wp_kses_post(wc_price((float) $row['profit'])) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $products
     */
    private function render_products_table(array $products): void
    {
        echo '<h2>' . esc_html__('Product Performance', 'woo-uni-reports') . '</h2>';
        echo '<div class="wur-table-wrap"><table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Product', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Quantity', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Sales', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('COGS', 'woo-uni-reports') . '</th>';
        echo '<th>' . esc_html__('Profit', 'woo-uni-reports') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($products)) {
            echo '<tr><td colspan="5">' . esc_html__('No product data for current filters.', 'woo-uni-reports') . '</td></tr>';
        } else {
            usort(
                $products,
                static function (array $left, array $right): int {
                    return (float) $right['sales'] <=> (float) $left['sales'];
                }
            );

            foreach ($products as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['product_name']) . '</td>';
                echo '<td>' . esc_html((string) $row['qty']) . '</td>';
                echo '<td>' . wp_kses_post(wc_price((float) $row['sales'])) . '</td>';
                echo '<td>' . wp_kses_post(wc_price((float) $row['cogs'])) . '</td>';
                echo '<td>' . wp_kses_post(wc_price((float) $row['profit'])) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
    }

    /**
     * @return array<string,string>
     */
    private function period_options(): array
    {
        return [
            'today' => __('Today', 'woo-uni-reports'),
            'last_30_days' => __('Last 30 days', 'woo-uni-reports'),
            'last_90_days' => __('Last 90 days', 'woo-uni-reports'),
            'this_month' => __('This month', 'woo-uni-reports'),
            'last_365_days' => __('Last 365 days', 'woo-uni-reports'),
            'custom' => __('Custom range', 'woo-uni-reports'),
        ];
    }
}
