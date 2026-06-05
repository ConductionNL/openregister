---
title: XWiki Integration
sidebar_position: 10
description: Link XWiki pages to OpenRegister objects via the XWiki REST API and OpenConnector.
keywords:
  - Open Register
  - XWiki
  - Knowledge base
  - External integration
---

# XWiki Integration

OpenRegister can link XWiki pages to any register object. Linked pages appear in the
**Articles** tab on the object sidebar and on the object detail page with a text preview.

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| OpenConnector app | Install from Nextcloud App Store; carries the `xwiki` source and credentials |
| XWiki ≥ 10.x | REST API must be enabled (default in XWiki 10+) |
| Network access | Nextcloud server must reach the XWiki instance |

---

## Setup

### 1 — Install OpenConnector

```bash
php occ app:install openconnector
```

### 2 — Import the source template

In OpenConnector admin → **Sources** → **Import**, upload:

```
docs/Integrations/xwiki-openconnector-source.yaml
```

### 3 — Configure the source

After import, open the `xwiki` source and set:

| Field | Value |
|-------|-------|
| Base URL | `https://wiki.example.gov` (no trailing slash) |
| Auth type | `basic` or `oauth2` |
| Credentials | Username/password for Basic; OAuth 2.0 client credentials for OAuth |

### 4 — Test the connection

In the source editor click **Test**. A green response confirms the source is reachable and
authenticated.

---

## Linking pages

### From the sidebar (Articles tab)

1. Open any OpenRegister object.
2. Click the **Articles** tab.
3. Paste a full XWiki URL **or** a `Space.Page` path into the link form.
4. Click **Link**.

Both forms are accepted:

```
https://wiki.example.gov/xwiki/bin/view/Dept/Policy/Privacy
Dept.Policy.Privacy
```

The OpenConnector source resolves the URL to the canonical `Space.Page` reference server-side.

### Breadcrumb display

Tab rows show the full breadcrumb hierarchy (e.g. **Wiki / Dept / Policy / Privacy**),
not just the title — because two pages in different spaces can share the same title.

---

## Detail-page preview

On the object detail page the **Articles** widget shows:

- A compact list of linked pages on the dashboard surfaces.
- A **text preview** (first ≈ 500 chars) of the first linked page on the detail-page surface.
- An **"Open in XWiki"** link for each page.

> **Security note**: The preview strips all HTML tags and never executes XWiki macros
> (Velocity, Groovy, etc.) inside Nextcloud. Content is displayed as inert plain text only.

---

## Auth expiry

If XWiki credentials expire, the Articles tab shows a **reconnect banner** with a link to
the OpenConnector source editor. Refresh the credentials there and the tab recovers
automatically on the next page load.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Articles tab missing | OpenConnector not installed | `php occ app:install openconnector` |
| "Unavailable" banner | OpenConnector source missing or not tested | Import template, configure, test |
| "Reconnect" banner | Auth credentials expired | Update credentials in OpenConnector source |
| Empty breadcrumb | XWiki version < 10.x or hierarchy field not mapped | Upgrade XWiki or update source mapping |
| Slow preview | Large page — rendered body fetch takes time | Preview truncates to ~500 chars; full page loads in XWiki |

---

## Reference

- Source config template: `docs/Integrations/xwiki-openconnector-source.yaml`
- Provider class: `lib/Service/Integration/Providers/XwikiProvider.php`
- Architecture decision: `openspec/changes/integration-xwiki/design.md`
- ADR-019: Integration Registry Pattern
