## Context

OpenRegister's rapportage-bi-export spec defines a chart data API and dashboard widget infrastructure. Operator-configured dashboards consume this API via Vue components (`CnChartWidget`, `CnTableWidget`, `CnKpiGrid`, etc.) defined in the canonical `built-in-dashboards` spec.

This change documents that OpenRegister integrates with the canonical built-in-dashboards specification without implementing the core dashboard engine itself.

## Goals / Non-Goals

**Goals:**
- Preserve the `built-in-dashboards` spec slug locally in OpenRegister
- Document that OpenRegister's rapportage-bi-export provides data for built-in dashboards
- Signal to developers that dashboard requirements are owned by root openspec

**Non-Goals:**
- Implement a new dashboard renderer (already owned by root openspec)
- Define new widget types (covered by built-in-dashboards spec)
- Modify rapportage-bi-export's data API

## Integration Points

### With rapportage-bi-export
- The `ReportRenderService` renders dashboard objects by calling the aggregation API
- Dashboard `widgets[]` definitions reference widget types from the built-in-dashboards vocabulary

### With root openspec
- The canonical `built-in-dashboards` spec defines the widget vocabulary, rendering behavior, and configuration schema
- Operators configure dashboards using schemas and reports defined in `rapportage-bi-export`

## Decisions

### 1. Redirect vs. local implementation

**Decision**: This change is a redirect stub, not a full implementation.

**Rationale**: The dashboard UI layer and widget rendering engine are shared across multiple OpenRegister consumers (Procest, Pipelinq, OpenCatalogi). These components are maintained in root openspec to avoid duplication. OpenRegister's responsibility is to supply aggregation data via the existing `MagicStatisticsHandler` and `ReportRenderService`, which rapportage-bi-export already provides.

**Implication**: Developers implementing dashboard features MUST consult the canonical root openspec spec for UI requirements and widget definitions.

## Component References (External)

### root openspec `built-in-dashboards` spec
- Defines the widget vocabulary (chart types, table options, KPI display modes)
- Specifies operator UX for dashboard creation and configuration
- Maintains the Vue dashboard renderer components

### OpenRegister `rapportage-bi-export` spec
- Provides aggregation API endpoints that built-in dashboards query
- Manages dashboard object definitions and metadata
- Renders dashboards via `ReportRenderService` + widget component mapping
