<?php

if (! defined('ABSPATH')) {
    exit;
}

class WUR_Exporter
{
    private WUR_Report_Service $reportService;

    public function __construct(WUR_Report_Service $reportService)
    {
        $this->reportService = $reportService;
    }

    public function register(): void
    {
        add_action('admin_post_wur_export_report', [$this, 'handle_export']);
    }

    public function handle_export(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to export reports.', 'woo-uni-reports'));
        }

        check_admin_referer('wur_export_report');

        $format = sanitize_text_field((string) ($_GET['format'] ?? 'csv'));
        $filters = [
            'period' => sanitize_text_field((string) ($_GET['period'] ?? 'last_30_days')),
            'date_from' => sanitize_text_field((string) ($_GET['date_from'] ?? '')),
            'date_to' => sanitize_text_field((string) ($_GET['date_to'] ?? '')),
            'source' => sanitize_text_field((string) ($_GET['source'] ?? '')),
            'source_group' => sanitize_text_field((string) ($_GET['source_group'] ?? '')),
        ];

        $report = $this->reportService->generate_report($filters);

        switch ($format) {
            case 'excel':
                $this->export_excel($report);
                break;
            case 'pdf':
                $this->export_pdf($report);
                break;
            case 'csv':
            default:
                $this->export_csv($report);
                break;
        }

        exit;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function export_csv(array $report): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wur-report-' . gmdate('Ymd-His') . '.csv');

        $output = fopen('php://output', 'w');
        if (! $output) {
            return;
        }

        fputcsv($output, ['Summary']);
        foreach ($report['summary'] as $key => $value) {
            fputcsv($output, [$key, $value]);
        }

        fputcsv($output, []);
        fputcsv($output, ['Sources']);
        fputcsv($output, ['source', 'group', 'orders', 'net_sales', 'profit']);
        foreach ($report['sources'] as $row) {
            fputcsv($output, [$row['source'], $row['group'], $row['orders'], $row['net_sales'], $row['profit']]);
        }

        fputcsv($output, []);
        fputcsv($output, ['Products']);
        fputcsv($output, ['product_id', 'product_name', 'qty', 'sales', 'cogs', 'profit']);
        foreach ($report['products'] as $row) {
            fputcsv($output, [$row['product_id'], $row['product_name'], $row['qty'], $row['sales'], $row['cogs'], $row['profit']]);
        }

        fclose($output);
    }

    /**
     * @param array<string,mixed> $report
     */
    private function export_excel(array $report): void
    {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=wur-report-' . gmdate('Ymd-His') . '.xls');

        echo "\xEF\xBB\xBF";
        echo "<table border='1'>";
        echo '<tr><th colspan="2">Summary</th></tr>';
        foreach ($report['summary'] as $key => $value) {
            echo '<tr><td>' . esc_html((string) $key) . '</td><td>' . esc_html((string) $value) . '</td></tr>';
        }

        echo '<tr><td colspan="6"></td></tr>';
        echo '<tr><th>source</th><th>group</th><th>orders</th><th>net_sales</th><th>profit</th></tr>';
        foreach ($report['sources'] as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['source']) . '</td>';
            echo '<td>' . esc_html((string) $row['group']) . '</td>';
            echo '<td>' . esc_html((string) $row['orders']) . '</td>';
            echo '<td>' . esc_html((string) $row['net_sales']) . '</td>';
            echo '<td>' . esc_html((string) $row['profit']) . '</td>';
            echo '</tr>';
        }

        echo '<tr><td colspan="6"></td></tr>';
        echo '<tr><th>product_id</th><th>product_name</th><th>qty</th><th>sales</th><th>cogs</th><th>profit</th></tr>';
        foreach ($report['products'] as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['product_id']) . '</td>';
            echo '<td>' . esc_html((string) $row['product_name']) . '</td>';
            echo '<td>' . esc_html((string) $row['qty']) . '</td>';
            echo '<td>' . esc_html((string) $row['sales']) . '</td>';
            echo '<td>' . esc_html((string) $row['cogs']) . '</td>';
            echo '<td>' . esc_html((string) $row['profit']) . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    /**
     * @param array<string,mixed> $report
     */
    private function export_pdf(array $report): void
    {
        $lines = [
            'Woo Uni Reports',
            'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            '',
            'Summary',
        ];

        foreach ($report['summary'] as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }

        $lines[] = '';
        $lines[] = 'Sources';
        foreach ($report['sources'] as $row) {
            $lines[] = sprintf(
                '%s (%s) - orders: %d | net sales: %0.2f | profit: %0.2f',
                $row['source'],
                $row['group'],
                $row['orders'],
                $row['net_sales'],
                $row['profit']
            );
        }

        $lines[] = '';
        $lines[] = 'Top products';
        $topProducts = array_slice($report['products'], 0, 30);
        foreach ($topProducts as $row) {
            $lines[] = sprintf(
                '#%d %s | qty: %d | sales: %0.2f | cogs: %0.2f | profit: %0.2f',
                $row['product_id'],
                $row['product_name'],
                $row['qty'],
                $row['sales'],
                $row['cogs'],
                $row['profit']
            );
        }

        $pdf = $this->build_simple_pdf($lines);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename=wur-report-' . gmdate('Ymd-His') . '.pdf');
        echo $pdf;
    }

    /**
     * Basic text PDF renderer without external library.
     *
     * @param string[] $lines
     */
    private function build_simple_pdf(array $lines): string
    {
        $safeLines = array_map(
            static function ($line): string {
                $line = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $line);
                $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                return (string) $line;
            },
            $lines
        );

        $text = "BT\n/F1 10 Tf\n50 780 Td\n";
        $first = true;
        foreach ($safeLines as $line) {
            if (! $first) {
                $text .= "0 -14 Td\n";
            }
            $text .= '(' . $line . ") Tj\n";
            $first = false;
        }
        $text .= "ET";

        $objects = [];
        $objects[] = '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj';
        $objects[] = '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj';
        $objects[] = '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>endobj';
        $objects[] = '4 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj';
        $objects[] = '5 0 obj<< /Length ' . strlen($text) . ' >>stream' . "\n" . $text . "\nendstream endobj";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n";
        $pdf .= '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }

        $pdf .= 'trailer<< /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n";
        $pdf .= $xrefOffset . "\n";
        $pdf .= '%%EOF';

        return $pdf;
    }
}
