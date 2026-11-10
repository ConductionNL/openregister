# Proposal: or-mcp-registration-event

## Summary

Discover MCP tool providers the way Nextcloud discovers everything else — a
typed registration event — and retire the bespoke scan.

## Why

Two mechanisms in one app did the same job, and the older one was the more
complex.

MCP discovery probes every installed app's `info.xml`, builds candidate
container aliases (`OCA\OpenRegister\Mcp\IMcpToolProvider::<appId>`), resolves
each through the container catching autoloader misses, and caches the
resolution map with **two** invalidation mechanisms — an app-list hash, plus a
clamped TTL because an app upgrade can add a provider without changing the app
list. Roughly 86 lines of probing plus the cache, to discover nine providers.

That complexity exists because it scans for something apps never announce.
Apps do announce a listener.

## Nextcloud has two idioms, and the split is the argument

- **Core** owns a registry → `IRegistrationContext::registerXProvider(class)`.
  Thirty-odd of these exist. Only core can add them, because only core can add
  methods to that interface.
- **An app** owns a registry → a typed registration event. `workflowengine`
  does exactly this for operations, checks and entities.

OpenRegister is an app, so the event is the idiomatic choice for anything it
owns. That is already why the flow node registry uses one (#2074). Core ships
no MCP at all, so there is nothing upstream to conform to beyond the idiom.

## What Changes

- `RegisterMcpToolProvidersEvent`, mirroring `RegisterFlowNodesEvent`.
- `collectAnnouncedMcpProviders()` runs FIRST; the alias scan still runs after
  it for one release so the fleet is never broken mid-migration.
- A provider whose appId is already present is skipped, so a migrated app is
  never collected twice.
- Discovery failure is logged, never fatal: an app with a broken listener costs
  its own tools, not everyone else's.

## Migration

Five apps outside OpenRegister register by alias today: hermiq, openbuild,
opencatalogi, decidesk, and `nextcloud-app-template`. Each needs a listener.
The template goes first — it is what new apps copy.

The alias scan and its cache are removed in a follow-up, once the fleet has
migrated. Removing them in the same change would break every app that has not.

## Out of scope

`#[McpTool]` attribute scanning and `IMcpScannableServices` solve a different
problem (which classes to reflect over) and are untouched.
