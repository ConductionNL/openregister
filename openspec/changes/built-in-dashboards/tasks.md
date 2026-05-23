# Built-in Dashboards — Task Checklist

This is a specification redirect to root openspec. No implementation tasks are required in OpenRegister.

## Documentation Tasks

- [ ] 1.1 Verify that `rapportage-bi-export` spec clearly documents the aggregation data API that built-in dashboards consume
- [ ] 1.2 Update OpenRegister's developer docs to link to the canonical `built-in-dashboards` spec in root openspec for dashboard configuration requirements
- [ ] 1.3 Document in `rapportage-bi-export` the expected widget type vocabulary (e.g., `CnChartWidget`, `CnTableWidget`, `CnKpiGrid`) with references to the canonical spec

## Verification

- [ ] 2.1 Confirm that existing dashboard rendering in `ReportRenderService` works with widget definitions from the canonical spec
- [ ] 2.2 Verify that operators can successfully configure dashboards without local dashboard implementation
