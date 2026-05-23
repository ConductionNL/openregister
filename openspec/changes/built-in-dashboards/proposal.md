## Why

Built-in dashboards are a cross-app pattern defined in root openspec. This change preserves the spec slug locally in OpenRegister to document that the app integrates with built-in dashboard infrastructure.

## What Changes

This change is a specification redirect. OpenRegister consumers (like Procest, Pipelinq, OpenCatalogi) expect to use built-in dashboards powered by the rapportage-bi-export spec's chart data API. This change documents that built-in dashboards are available to register operators via the standard Vue dashboard widgets.

No new capabilities are added to OpenRegister itself — this change is a documentation placeholder linking to the canonical root openspec spec.

## Capabilities

### Referenced Capabilities (not implemented here)
- `built-in-dashboards`: Canvas for rendering operator-configured dashboard widgets (owned by root openspec)

### Dependencies
- **rapportage-bi-export**: Provides the chart data API that powers dashboard widgets
- **data-aggregation**: Supplies aggregation queries for KPI and metric widgets

## Impact

- **Code**: No implementation required — this spec is a redirect
- **APIs**: None — consumers consume the built-in-dashboards API via root openspec
- **Dependencies**: Apps using dashboards already depend on rapportage-bi-export
- **Specification**: Preserves the `built-in-dashboards` spec slug locally; canonical requirements live in root openspec

## Status

**Redirect to root openspec cross-app pattern.** Implementers MUST consult the canonical `built-in-dashboards` specification instead of treating this stub as authoritative.
