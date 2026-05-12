# XWiki Integration ("Articles")

Link XWiki pages to OpenRegister objects. Appears as the **Articles** tab in the object sidebar, as an `xwiki` widget on dashboards and detail pages, and as a single-entity reference renderer for schema properties that declare `referenceType: 'xwiki'`.

This is an **external** integration (storage strategy `external`, group `external`) — it is the worked example for the [pluggable integration registry](pluggable-integration-registry.md). It carries no HTTP client and no credentials of its own: all CRUD is delegated to an OpenConnector source named `xwiki`.

## Setup

1. **Install OpenConnector** (the `openconnector` Nextcloud app). The Articles integration is hidden until it's installed and enabled.
2. **Create the `xwiki` source in OpenConnector** — import the template at [`xwiki-openconnector-source.yaml`](xwiki-openconnector-source.yaml), then fill in:
   - `location` — your XWiki REST base URL, e.g. `https://wiki.example.org/rest/wikis/xwiki`
   - the `auth` block — HTTP Basic (works on every XWiki version) **or** OAuth2 (xwiki-platform-oidc; newer instances). Keep exactly one.
3. **Verify** in OpenRegister → Administration → OpenRegister → Integrations: the `xwiki` row should show storage `external`, required app `openconnector`, and an auth/health status. The "Configure" link deep-links into OpenConnector's source page; "Test connection" probes the source.

## Using it

- **Sidebar (Articles tab)** — shows linked pages with their full breadcrumb ("Wiki / Department / Subspace / Page"), since two pages can share a title in different spaces. Link a page by pasting its **URL** (parsed to a canonical `Space.Page` reference) or by typing the **path** directly. Unlink removes the pairing only — it never deletes the page in XWiki. An "authentication expired" banner appears if the source's credentials need re-connecting.
- **Detail-page widget** — linked pages list plus a **text preview** (the first ~500 characters of the page's rendered content) and a link to the full page. **XWiki macros are not executed** in the preview (no Velocity templates / scripts run inside Nextcloud) — click through to XWiki for full rendering.
- **Dashboard widget** — recent linked pages (user dashboard) or app-scoped (app dashboard).
- **Reference property** — a schema property with `referenceType: 'xwiki'` renders the linked page's title + breadcrumb chip in `CnFormDialog` / `CnDetailGrid`.

## Notes

- **Permissions** — the integration inherits access from the underlying object's RBAC plus OpenConnector's own. If a user has Nextcloud access to the object but not XWiki access to a page, they see a "No access to page" placeholder, not an internal error.
- **XWiki versions** — 5.x / 10.x+ / 14.x+ are all supported; the OpenConnector adapter normalises REST field-name drift, so the provider stays version-agnostic.
- **Failure modes** — if OpenConnector is missing/disabled, the source is missing, or the remote XWiki is down, the tab degrades to an empty state with a clear message rather than a broken tab (AD-23).

See [pluggable-integration-registry.md](pluggable-integration-registry.md) for how this integration is wired into the registry, and `openspec/changes/integration-xwiki/` for the change spec.
