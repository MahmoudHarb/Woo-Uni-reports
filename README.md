# Woo Uni Reports

WooCommerce extension that provides source-aware order analytics with advanced filtering and multi-format exports.

## Features

- Automatic order source tracking at checkout (`utm_source`, `utm_medium`, and referrer fallback).
- Source classification: direct, organic, social (aggregate), referral, plus per-platform source keys.
- Sales metrics with shipping deduction and refunds handling.
- Profit calculations with cost-of-goods support (`_wc_cog_item_cost`, `_wc_cog_cost`).
- Product performance table with sales, quantity, COGS, and profit.
- Flexible filters for period, custom date range, source group, and specific source.
- Export reports in CSV, Excel (`.xls`), and PDF.

## Installation

1. Copy plugin folder into `wp-content/plugins/woo-uni-reports`.
2. Activate **Woo Uni Reports** in WordPress admin.
3. Open **WooCommerce → Uni Reports**.

## Notes

- Recommended statuses included in reports: processing, completed, and on-hold.
- For reliable source attribution, keep UTM parameters in your campaign links.
- Profit uses COGS meta when available; missing COGS values are treated as `0`.
