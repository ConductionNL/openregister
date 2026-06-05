# XWiki Integration

Link XWiki pages to OpenRegister objects. The integration is built on the
[pluggable integration registry](../integrations/README.md) and routes all
operations through the OpenConnector `xwiki` source — OpenRegister itself holds
no credentials.

## Prerequisites

| Prerequisite | Details |
|---|---|
| Nextcloud app | [OpenConnector](https://apps.nextcloud.com/apps/openconnector) must be installed |
| XWiki version | 10.x or later (REST API required); tested against LTS 17.10 |
| Auth method | Basic auth or OAuth 2.0 (configured on the OpenConnector source) |

## Setup

### 1. Install and configure OpenConnector

1. Install the **OpenConnector** app from the Nextcloud app store.
2. Navigate to **Administration › OpenConnector › Sources**.
3. Click **Import** and upload `docs/Integrations/xwiki-openconnector-source.yaml`.
4. Edit the imported source:
   - Set **Location** to your XWiki base URL (e.g. `https://wiki.example.org`).
   - Configure **Authentication** — Basic auth (username + password) or OAuth 2.0.
5. Click **Test connection** to confirm OpenConnector can reach XWiki.

### 2. Enable the integration in OpenRegister

The XWiki provider auto-registers when OpenConnector is installed and the `xwiki`
source is configured. Navigate to **Administration › OpenRegister › Integrations**
to confirm the row shows status **Enabled**.

### 3. Add `xwiki` to a schema's `linkedTypes`

Open the schema in **Administration › OpenRegister › Schemas**, add `xwiki` to the
`linkedTypes` array. The **Articles** tab appears automatically on the object detail
page for objects of that schema.

## Usage

### Linking a page

In the **Articles** tab on an object detail page, click **Link page** and paste either:
- A full XWiki URL (e.g. `https://wiki.example.org/xwiki/bin/view/Dept/Policy/Privacy`)
- A wiki page path (e.g. `Dept.Policy.Privacy`)

The OpenConnector source resolves both forms to the canonical `Space.Page` reference.

### Browsing linked pages

The tab lists linked pages with the full breadcrumb path (e.g. `Wiki / Dept / Policy`)
and the page title. Two pages with the same title in different spaces are unambiguous.

### Detail-page preview

When an object detail page opens, the **XWiki widget** shows a text preview of the
first linked page (up to ~500 characters of rendered plain text). XWiki macros are
stripped — they are never executed inside Nextcloud (AD-1).

### Unlinking a page

Click the **Unlink** action next to a linked page. This removes the association in
OpenRegister only — the XWiki page is not affected.

### Auth expiry

When the OpenConnector source's credentials expire (401/403), the Articles tab shows
a **Reconnect** banner. Click it to open the source configuration in OpenConnector.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Articles tab not visible | `xwiki` not in schema `linkedTypes`, or OpenConnector not installed | Add `xwiki` to `linkedTypes`; install OpenConnector |
| "Unavailable" banner | XWiki unreachable or source misconfigured | Check OpenConnector source connection; test from OpenConnector UI |
| Breadcrumb shows only space | XWiki version < 10 (no `hierarchy` field) | Upgrade XWiki, or accept coarse breadcrumb |
| Preview shows macro markup | Source `renderedContent` enrichment not enabled | Enable the `/bin/get/{Space}/{Page}?xpage=plain` enrichment step in the source config |

## Architecture notes

- **No credentials in OpenRegister**: all auth is delegated to OpenConnector.
- **AD-1 — Text-only preview**: the detail-page widget strips all HTML and never
  injects rendered content into the DOM. Macros are inert text in the preview.
- **AD-2 — Flexible link input**: URL or `Space.Page` path; resolved by the source.
- **AD-3 — Breadcrumb**: derived from `hierarchy.items[].label`; falls back to space name.
- **Provider**: `OCA\OpenRegister\Service\Integration\Providers\XwikiProvider`
- **Source template**: `docs/Integrations/xwiki-openconnector-source.yaml`
