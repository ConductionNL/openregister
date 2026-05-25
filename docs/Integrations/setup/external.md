---
title: External integrations
sidebar_position: 2
description: Set up the OpenConnector-routed external leaves (xWiki, OpenProject) — configure a source first, then link and create through it.
keywords:
  - Open Register
  - Integrations
  - OpenConnector
  - xWiki
  - OpenProject
  - External
---

# External integrations

Two leaves link to services that live outside Nextcloud: **[xWiki](../xwiki.md)** (Articles) and **[OpenProject](../openproject.md)** (Projects). They use the `external` storage strategy — nothing is stored locally; every list / get / link / create is routed through an [OpenConnector](https://github.com/ConductionNL/openconnector) source. Credentials live in one place and one source can feed multiple apps.

## 1. Install OpenConnector

Both external leaves require the `openconnector` Nextcloud app. Until it is installed and enabled, the leaf is hidden (the admin row appears once OpenConnector is present).

## 2. Configure the source

Open **OpenConnector → Sources → New source** and create a source whose slug matches the integration id (`xwiki` or `openproject`). Set the base URL, the auth block (basic, token, or OAuth2 — keep exactly one), then **Test connection**.

- xWiki ships an import template: [`xwiki-openconnector-source.yaml`](../xwiki-openconnector-source.yaml). Full walkthrough on the [xWiki page](../xwiki.md#setup).
- For production use a dedicated service user, never a personal token. OpenConnector encrypts tokens at rest.

## 3. The unconfigured state — "Configure connection" CTA

Before a source exists, the leaf does not crash. The tab and the admin row render a **"Configure connection"** call-to-action that deep-links into OpenConnector's source page. The sub-resource endpoint returns the documented degraded-source response (HTTP 503 with a structured cause such as `OpenConnector source "xwiki" for integration "xwiki" is missing or unreadable`), never a broken tab. Once the source is saved and the connection tests green, the picker and create paths work like any other Tier-2 leaf.

## 4. Link and create

With a working source, the leaf behaves like a [Tier-2/3 leaf](../tiers.md):

- **Link** — pick an existing upstream page / work package and bind it to the object.
- **Create** — create a new upstream thing through OpenConnector and link it.

Both paths route every call through the source, so auth and rate limits are governed centrally by OpenConnector.

## Via MCP

Because external leaves are registered in the same registry, an AI agent reaches them through the same `openregister.integrations` tool — `list-integrations` shows `xwiki` / `openproject` with `storageStrategy: external`, and `list`/`get`/`link`/`create` route through the configured source. See [AI-agent / MCP setup](./mcp.md). If the source is unconfigured, the agent receives the same degraded-source error envelope rather than a fatal.

## Related

- [xWiki leaf](../xwiki.md) — full source setup, auth, troubleshooting.
- [OpenProject leaf](../openproject.md) — work-package linking.
- [Pluggable integration registry](../pluggable-integration-registry.md) — the `external` strategy and degraded-source contract.
