# Rapportage en BI Export

## Problem
Provide a comprehensive reporting and business intelligence export layer for OpenRegister that enables government organisations to generate management reports, perform data aggregation queries, connect external BI tools, and satisfy Dutch public accountability requirements (WOO, jaarverslag, verantwoording). The system MUST expose a general-purpose aggregation API (count, sum, avg, min, max, group by) on top of the existing `MagicMapper` and `MagicStatisticsHandler` infrastructure, support scheduled report generation via Nextcloud background jobs, produce exports in CSV, Excel, PDF, and ODS formats through the existing `ExportService`/`ExportHandler` pipeline, and provide OData v4 and ODBC-compatible endpoints for integration with Power BI, Tableau, and other external BI platforms.

## Proposed Solution
Provide a comprehensive reporting and business intelligence export layer for OpenRegister that enables government organisations to generate management reports, perform data aggregation queries, connect external BI tools, and satisfy Dutch public accountability requirements (WOO, jaarverslag, verantwoording). The system MUST expose a general-purpose aggregation API (count, sum, avg, min, max, group by) on top of the existing `MagicMapper` and `MagicStatisticsHandler` infrastructure, support sched
