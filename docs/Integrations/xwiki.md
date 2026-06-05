# XWiki Integration

Link XWiki pages to OpenRegister objects. The integration surfaces linked pages in a
dedicated tab on the object detail page and in a widget on dashboard and detail views.

## Requirements

| Requirement | Details |
|---|---|
| Nextcloud app | `openconnector` (install from Nextcloud App Store) |
| XWiki version | 5.x or later; tested against `xwiki:lts` 17.10 |
| Auth | Basic auth **or** OAuth2 (depends on your XWiki setup) |

## Setup

### 1. Install OpenConnector

Go to **Nextcloud Apps → Search → OpenConnector** and install the app.

### 2. Import the source configuration

1. Open **OpenConnector → Settings → Sources**.
2. Click **Import** and upload `docs/Integrations/xwiki-openconnector-source.yaml`
   from this repository.
3. Edit the imported source:
   - Set **Base URL** to your XWiki instance (e.g. `https://wiki.example.com`).
   - Set credentials (basic auth: username + password/token; or switch to OAuth2
     and fill in client ID, client secret, and token URL).
4. Click **Save** and then **Test connection** to verify.

### 3. Verify the provider appears in OpenRegister

1. Open **OpenRegister → Settings → Integrations**.
2. The **XWiki — Articles** row should appear with status **Connected**.
   If it shows **Not configured**, check that the `xwiki` source is enabled in
   OpenConnector and that the credentials are valid.

## Using the integration

### Linking a page (Tab)

1. Open an object in OpenRegister and click the **Articles** tab.
2. Click **Link page**.
3. Paste a full XWiki URL (e.g. `https://wiki.example.com/xwiki/bin/view/Dept/Policy/Privacy`)
   **or** type a direct page path (e.g. `Dept.Policy.Privacy`).
   Both formats are accepted; XWiki resolves the URL to the canonical `Space.Page` reference.
4. Click **Link**. The page appears in the list with its title and full breadcrumb
   (e.g. `Dept / Policy / Privacy Policy`).

### Unlinking a page

Click the **Unlink** icon next to a page in the tab. This removes the OR link record only —
the page in XWiki is **never** deleted.

### Widget surfaces

| Surface | What is shown |
|---|---|
| `user-dashboard` | Recent linked pages (compact list) |
| `app-dashboard` | Same compact list, scoped to the current app |
| `detail-page` | Linked pages list + plain-text preview of the first page (~500 chars) + "Open in XWiki" link |
| `single-entity` | Title + breadcrumb chip (for `referenceType: 'xwiki'` schema properties) |

### Detail-page text preview

The preview renders the **plain-text body** of the linked page. XWiki macros are executed
server-side by XWiki and the result is received as rendered HTML; the widget strips all HTML
tags and truncates to the first ~500 characters. Macro output is included as text; no macro
execution happens inside Nextcloud. `<script>` and `<style>` blocks are stripped before
display.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Tab shows "XWiki unavailable" banner | Check that OpenConnector is installed and the `xwiki` source is enabled. |
| Tab shows "Reconnect" banner | Auth expired — update credentials in OpenConnector → Sources → xwiki. |
| Page breadcrumb shows `Space / Title` instead of the full path | The OpenConnector source version does not map `hierarchy`; upgrade the source template. |
| Preview shows raw macro markup | XWiki returned raw syntax instead of rendered HTML — check that the `get` endpoint uses `?xpage=plain`. |

## Architecture notes

- **Storage**: `external` — link records are stored in OR; page data is fetched live from XWiki via OpenConnector.
- **Auth**: configured entirely on the OpenConnector source; `XwikiProvider` carries no credentials.
- **Permissions**: the integration inherits the OR object's RBAC. XWiki's own ACLs apply transitively — a user with NC access but without XWiki access to the page sees a "No access" placeholder.
- **Spec**: `openspec/changes/integration-xwiki/`
