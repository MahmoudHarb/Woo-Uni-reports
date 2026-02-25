# WooCommerce UTM Tracker – System Design & Implementation Plan

## 1) Requirements Analysis

### Business goals
- Track visitor acquisition sources and attribute WooCommerce revenue correctly.
- Support both first-touch and last-touch attribution for marketing teams.
- Provide actionable channel/campaign reports from within WordPress.

### Functional requirements
- Capture tracking data from URL params, referrer, and click identifiers.
- Persist session-level data (cookie/localStorage/sessionStorage).
- Bind attribution snapshot to order during checkout.
- Store normalized events in a dedicated table for analytics queries.
- Expose reporting and settings through secure REST API routes.
- Render an admin dashboard with filters and charts.

### Non-functional requirements
- Low client-side overhead and non-blocking execution.
- Strong input sanitization/validation and permission checks.
- Query performance through proper indexing and incremental aggregation.
- Extensible architecture with OOP and PSR-4 style namespaces.

---

## 2) High-Level Architecture

### Components
1. **Tracking Layer (Frontend JS)**
   - Reads UTM params + click IDs (`gclid`, `fbclid`, `ttclid`) and `document.referrer`.
   - Builds attribution payload and stores:
     - Cookie (shared cross-tab, server-readable)
     - localStorage (durable client-side copy)
     - sessionStorage (tab/session context)
2. **Server Intake Layer (PHP)**
   - Parses cookie/session payload during checkout.
   - Normalizes and sanitizes values.
   - Attaches order meta + writes event row(s) to custom table.
3. **Data Layer**
   - WooCommerce order meta for direct order inspection.
   - `wp_wc_attribution_events` for reporting queries.
4. **API Layer (WP REST API)**
   - Session lookup, channel/campaign/order reports, settings management.
5. **Dashboard Layer (React in wp-admin)**
   - Filter bar + KPI cards + channel/campaign charts + orders table.

---

## 3) Data Model

### Order Meta Keys
- `_utm_source`
- `_utm_medium`
- `_utm_campaign`
- `_utm_term`
- `_utm_content`
- `_attribution_model`
- `_referrer`
- `_landing_page`
- `_session_id`

### Custom Table
`wp_wc_attribution_events`

```sql
CREATE TABLE wp_wc_attribution_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(64) NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  source VARCHAR(100) NULL,
  medium VARCHAR(100) NULL,
  campaign VARCHAR(191) NULL,
  referrer VARCHAR(255) NULL,
  is_first_touch TINYINT(1) NOT NULL DEFAULT 0,
  is_last_touch TINYINT(1) NOT NULL DEFAULT 1,
  timestamp DATETIME NOT NULL,
  json_payload LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY idx_session_id (session_id),
  KEY idx_order_id (order_id),
  KEY idx_timestamp (timestamp),
  KEY idx_source_medium_campaign (source, medium, campaign),
  KEY idx_first_last (is_first_touch, is_last_touch),
  KEY idx_referrer (referrer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Indexing strategy
- `idx_timestamp` for date-range dashboards.
- `idx_source_medium_campaign` for grouped attribution reports.
- `idx_order_id` for quick order drill-down.
- `idx_session_id` to trace full journey.
- Optional monthly partitioning for large stores.

---

## 4) Attribution Logic

### Supported models
1. **First Touch**: attribute order to earliest known source in session history.
2. **Last Touch**: attribute order to most recent source before checkout.
3. **Dual Attribution**: store both rows/flags for first+last touch.

### Fallback classification (when UTM absent)
- If click IDs exist (`gclid/fbclid/ttclid`) ⇒ `medium=paid`, channel by ID source.
- Referrer domain mapping:
  - Google/Bing/Yahoo ⇒ `organic`
  - Facebook/Instagram/TikTok/Snapchat ⇒ `social`
  - Other external domains ⇒ `referral`
- Empty referrer + no UTM ⇒ `direct`.

---

## 5) Tracking Payload Format

```json
{
  "session_id": "9f4c6c5e9d184f90",
  "first_touch": {
    "timestamp": "2026-02-25T10:12:11Z",
    "source": "google",
    "medium": "organic",
    "campaign": null,
    "term": null,
    "content": null,
    "landing_page": "/shop/hoodie",
    "referrer": "https://www.google.com/"
  },
  "last_touch": {
    "timestamp": "2026-02-25T11:00:10Z",
    "source": "facebook",
    "medium": "paid_social",
    "campaign": "retargeting_q1",
    "term": null,
    "content": "carousel_a"
  },
  "click_ids": {
    "gclid": null,
    "fbclid": "abc123",
    "ttclid": null
  },
  "version": 1
}
```

Cookie name suggestion: `wc_utm_attr` (HTTPOnly=false because JS updates it; secure+SameSite=Lax).

---

## 6) REST API Design

Base namespace: `/wp-json/utm-tracker/v1/`

### Endpoints
- `GET /sessions/{id}`
  - Returns full session timeline + linked order(s).
- `GET /reports/channels?from=&to=&country=&product=&channel=`
  - Aggregated revenue/orders by channel.
- `GET /reports/campaigns?from=&to=&source=`
  - Campaign performance summary.
- `GET /reports/orders?from=&to=&page=&per_page=`
  - Order-level attribution rows.
- `GET|POST /settings` (admin only)
  - Reads/updates model mode, lookback window, domain mappings.

### Security
- `permission_callback` with capabilities:
  - read reports: `manage_woocommerce` (or custom capability)
  - settings write: `manage_options`
- Nonce header (`X-WP-Nonce`) for wp-admin requests.
- Strict schema validation with `register_rest_route` args.

---

## 7) Plugin Code Structure (PSR-4 Style)

```text
woo-utm-tracker/
├─ woo-utm-tracker.php
├─ composer.json
├─ src/
│  ├─ Core/
│  │  ├─ Plugin.php
│  │  ├─ ServiceProvider.php
│  │  └─ Assets.php
│  ├─ Tracking/
│  │  ├─ TrackerScript.php
│  │  ├─ AttributionClassifier.php
│  │  └─ SessionRepository.php
│  ├─ Woo/
│  │  ├─ CheckoutBinder.php
│  │  └─ OrderMetaWriter.php
│  ├─ DB/
│  │  ├─ Migration.php
│  │  └─ AttributionEventRepository.php
│  ├─ API/
│  │  ├─ SessionsController.php
│  │  ├─ ReportsController.php
│  │  └─ SettingsController.php
│  └─ Admin/
│     └─ DashboardPage.php
├─ assets/
│  ├─ js/tracker.js
│  └─ admin/dashboard-app.js
└─ templates/
   └─ admin-dashboard.php
```

Namespace suggestion: `Company\WooUtmTracker\...`

---

## 8) Data Flow Diagram (Text)

1. User lands on site with URL/referrer.
2. `tracker.js` computes touch data and updates `wc_utm_attr`.
3. User adds products and proceeds to checkout.
4. On checkout create/update order:
   - read cookie payload
   - sanitize + normalize
   - write order meta keys
   - insert event(s) into `wp_wc_attribution_events`
5. Dashboard requests reports via REST API.
6. API queries custom table + Woo order data and returns aggregates.

### Sequence (simplified)
- Browser → TrackerJS: capture params/referrer
- TrackerJS → Storage: save first/last touch
- Browser → Woo Checkout: submit order
- CheckoutBinder → OrderMetaWriter: persist order attribution
- CheckoutBinder → AttributionEventRepository: insert events
- Admin Dashboard → REST ReportsController: fetch summaries
- ReportsController → DB: aggregate results

---

## 9) Example Code Snippets

### PHP: bind attribution during checkout

```php
add_action('woocommerce_checkout_create_order', function ($order) {
    $raw = $_COOKIE['wc_utm_attr'] ?? '';
    if (!$raw) {
        return;
    }

    $payload = json_decode(wp_unslash($raw), true);
    if (!is_array($payload)) {
        return;
    }

    $last = $payload['last_touch'] ?? [];
    $order->update_meta_data('_utm_source', sanitize_text_field($last['source'] ?? 'direct'));
    $order->update_meta_data('_utm_medium', sanitize_text_field($last['medium'] ?? 'none'));
    $order->update_meta_data('_utm_campaign', sanitize_text_field($last['campaign'] ?? ''));
    $order->update_meta_data('_utm_term', sanitize_text_field($last['term'] ?? ''));
    $order->update_meta_data('_utm_content', sanitize_text_field($last['content'] ?? ''));
    $order->update_meta_data('_attribution_model', 'dual');
    $order->update_meta_data('_referrer', esc_url_raw($last['referrer'] ?? ''));
    $order->update_meta_data('_landing_page', esc_url_raw($payload['first_touch']['landing_page'] ?? ''));
    $order->update_meta_data('_session_id', sanitize_text_field($payload['session_id'] ?? ''));
}, 20, 1);
```

### JS: lightweight tracker bootstrap

```js
(() => {
  const params = new URLSearchParams(window.location.search);
  const now = new Date().toISOString();
  const source = params.get('utm_source');
  const medium = params.get('utm_medium');

  const read = () => {
    try { return JSON.parse(localStorage.getItem('wc_utm_attr') || '{}'); }
    catch { return {}; }
  };

  const payload = read();
  payload.session_id = payload.session_id || crypto.randomUUID().replace(/-/g, '').slice(0, 16);

  if (!payload.first_touch || Object.keys(payload.first_touch).length === 0) {
    payload.first_touch = {
      timestamp: now,
      source: source || null,
      medium: medium || null,
      campaign: params.get('utm_campaign'),
      term: params.get('utm_term'),
      content: params.get('utm_content'),
      landing_page: location.pathname,
      referrer: document.referrer || null
    };
  }

  payload.last_touch = {
    timestamp: now,
    source: source || payload.last_touch?.source || null,
    medium: medium || payload.last_touch?.medium || null,
    campaign: params.get('utm_campaign') || payload.last_touch?.campaign || null,
    term: params.get('utm_term') || payload.last_touch?.term || null,
    content: params.get('utm_content') || payload.last_touch?.content || null,
    referrer: document.referrer || payload.last_touch?.referrer || null
  };

  const json = JSON.stringify(payload);
  localStorage.setItem('wc_utm_attr', json);
  sessionStorage.setItem('wc_utm_attr', json);
  document.cookie = `wc_utm_attr=${encodeURIComponent(json)}; path=/; max-age=${60 * 60 * 24 * 90}; SameSite=Lax`;
})();
```

### React: reports fetch hook

```jsx
import { useEffect, useState } from 'react';

export function useChannelReport(filters) {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const qs = new URLSearchParams(filters).toString();
    setLoading(true);
    fetch(`/wp-json/utm-tracker/v1/reports/channels?${qs}`, {
      headers: { 'X-WP-Nonce': window.wpApiSettings.nonce }
    })
      .then(r => r.json())
      .then(setData)
      .finally(() => setLoading(false));
  }, [JSON.stringify(filters)]);

  return { data, loading };
}
```

---

## 10) Performance and Security Checklist

- Load tracker script with `defer` and minified bundle.
- Keep payload compact and capped (avoid large historical arrays in cookie).
- Sanitize all values before writing meta/table rows.
- Validate REST query params (dates, enums, pagination bounds).
- Escape output in admin tables/charts labels.
- Add nonce + capability checks to all privileged endpoints.
- Add cron-based cleanup/archival for stale anonymous sessions.

---

## 11) Phased Development Plan

### Phase 1 — Foundation
- Bootstrap plugin skeleton, autoloader, activation migration.
- Add tracker JS and storage logic.
- Implement checkout binder + order meta writing.

### Phase 2 — Analytics API
- Add event repository + inserts.
- Implement reporting endpoints and filters.
- Add settings endpoint with admin permissions.

### Phase 3 — Dashboard
- Build React app with channel, campaign, orders views.
- Add filter state + URL sync.
- Integrate Chart.js or ECharts.

### Phase 4 — Hardening
- Add unit/integration tests for classifier and report queries.
- Optimize slow SQL paths with EXPLAIN-driven indexing.
- Add observability (error logs, optional debug panel).

---

## 12) Future Improvements

- Multi-touch weighted attribution (position-based, time-decay).
- Offline conversion import + CRM ID stitching.
- Cross-device identity resolution for logged-in users.
- Google Ads / Meta Ads cost ingestion for ROAS reporting.
- Sampling + pre-aggregated materialized tables for very large stores.
