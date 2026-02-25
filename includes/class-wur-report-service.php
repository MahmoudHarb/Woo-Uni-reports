<?php

if (! defined('ABSPATH')) {
    exit;
}

class WUR_Report_Service
{
    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function generate_report(array $filters): array
    {
        $orders = $this->fetch_orders($filters);

        $summary = [
            'order_count' => 0,
            'gross_sales' => 0.0,
            'shipping_total' => 0.0,
            'refunds' => 0.0,
            'net_sales' => 0.0,
            'cogs' => 0.0,
            'profit' => 0.0,
            'margin' => 0.0,
        ];

        $sources = [];
        $products = [];

        foreach ($orders as $order) {
            /** @var WC_Order $order */
            $summary['order_count']++;

            $orderTotal = (float) $order->get_total();
            $shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
            $refunds = (float) $order->get_total_refunded();
            $netSales = max(0, $orderTotal - $shipping - $refunds);
            $orderCogs = $this->get_order_cogs($order, $products);
            $profit = $netSales - $orderCogs;

            $summary['gross_sales'] += $orderTotal;
            $summary['shipping_total'] += $shipping;
            $summary['refunds'] += $refunds;
            $summary['net_sales'] += $netSales;
            $summary['cogs'] += $orderCogs;
            $summary['profit'] += $profit;

            $source = (string) $order->get_meta('_wur_source');
            $group = (string) $order->get_meta('_wur_source_group');

            if ('' === $source) {
                $source = 'unknown';
            }
            if ('' === $group) {
                $group = 'unknown';
            }

            if (! isset($sources[$source])) {
                $sources[$source] = [
                    'source' => $source,
                    'group' => $group,
                    'orders' => 0,
                    'net_sales' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $sources[$source]['orders']++;
            $sources[$source]['net_sales'] += $netSales;
            $sources[$source]['profit'] += $profit;
        }

        if ($summary['net_sales'] > 0) {
            $summary['margin'] = ($summary['profit'] / $summary['net_sales']) * 100;
        }

        return [
            'summary' => $this->round_array_values($summary),
            'sources' => array_values($this->round_nested_values($sources)),
            'products' => array_values($this->round_nested_values($products)),
            'filters' => $filters,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return WC_Order[]
     */
    public function fetch_orders(array $filters): array
    {
        $query = [
            'type' => 'shop_order',
            'status' => $filters['statuses'] ?? ['wc-processing', 'wc-completed', 'wc-on-hold'],
            'limit' => -1,
            'return' => 'objects',
            'date_created' => $this->build_date_query($filters),
        ];

        if (! empty($filters['source'])) {
            $query['meta_key'] = '_wur_source';
            $query['meta_value'] = sanitize_text_field((string) $filters['source']);
        }

        if (! empty($filters['source_group'])) {
            $query['meta_query'] = [
                [
                    'key' => '_wur_source_group',
                    'value' => sanitize_text_field((string) $filters['source_group']),
                    'compare' => '=',
                ],
            ];
        }

        return wc_get_orders($query);
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function build_date_query(array $filters): string
    {
        $period = $filters['period'] ?? 'last_30_days';

        if ('custom' === $period) {
            $start = sanitize_text_field((string) ($filters['date_from'] ?? ''));
            $end = sanitize_text_field((string) ($filters['date_to'] ?? ''));

            if ($start && $end) {
                return sprintf('%s...%s', $start . ' 00:00:00', $end . ' 23:59:59');
            }
        }

        $today = gmdate('Y-m-d');

        switch ($period) {
            case 'today':
                return sprintf('%s...%s', $today . ' 00:00:00', $today . ' 23:59:59');
            case 'this_month':
                return gmdate('Y-m-01 00:00:00') . '...' . gmdate('Y-m-t 23:59:59');
            case 'last_90_days':
                return gmdate('Y-m-d 00:00:00', strtotime('-90 days')) . '...' . gmdate('Y-m-d 23:59:59');
            case 'last_365_days':
                return gmdate('Y-m-d 00:00:00', strtotime('-365 days')) . '...' . gmdate('Y-m-d 23:59:59');
            case 'last_30_days':
            default:
                return gmdate('Y-m-d 00:00:00', strtotime('-30 days')) . '...' . gmdate('Y-m-d 23:59:59');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $products
     */
    private function get_order_cogs(WC_Order $order, array &$products): float
    {
        $totalCogs = 0.0;

        foreach ($order->get_items('line_item') as $itemId => $item) {
            /** @var WC_Order_Item_Product $item */
            $qty = (int) $item->get_quantity();
            $lineTotal = (float) $item->get_total();
            $product = $item->get_product();
            $productId = $product ? $product->get_id() : 0;
            $productName = $product ? $product->get_name() : $item->get_name();

            $itemCost = (float) wc_get_order_item_meta($itemId, '_wc_cog_item_cost', true);
            if (! $itemCost && $productId > 0) {
                $itemCost = (float) get_post_meta($productId, '_wc_cog_cost', true);
            }

            $lineCogs = $itemCost * $qty;
            $lineProfit = $lineTotal - $lineCogs;

            $totalCogs += $lineCogs;

            if (! isset($products[$productId])) {
                $products[$productId] = [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'qty' => 0,
                    'sales' => 0.0,
                    'cogs' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $products[$productId]['qty'] += $qty;
            $products[$productId]['sales'] += $lineTotal;
            $products[$productId]['cogs'] += $lineCogs;
            $products[$productId]['profit'] += $lineProfit;
        }

        return $totalCogs;
    }

    /**
     * @param array<string,float|int> $data
     * @return array<string,float|int>
     */
    private function round_array_values(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_float($value)) {
                $data[$key] = round($value, 2);
            }
        }

        return $data;
    }

    /**
     * @param array<array-key,array<string,mixed>> $rows
     * @return array<array-key,array<string,mixed>>
     */
    private function round_nested_values(array $rows): array
    {
        foreach ($rows as $rowKey => $row) {
            foreach ($row as $key => $value) {
                if (is_float($value)) {
                    $rows[$rowKey][$key] = round($value, 2);
                }
            }
        }

        return $rows;
    }
}
