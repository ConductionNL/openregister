# Tasks: Rapportage en BI Export

- [ ] Implement: The system MUST provide a general-purpose aggregation API
- [ ] Implement: The system MUST support configurable report templates
- [ ] Implement: The system MUST support scheduled report generation
- [ ] Implement: The system MUST support export in CSV, Excel, PDF, and ODS formats
      ODS DONE: `format=ods` on `GET /api/objects/{register}/{schema}/export` writes the Excel export's
      spreadsheet with PhpSpreadsheet's Ods writer (`application/vnd.oasis.opendocument.spreadsheet`), pinned in
      `ObjectsControllerTest::testExportReturnsOdsWithTheSameSheetAsExcel`. CSV, Excel and a PDF table existed;
      OPEN: the PDF report scenario (summary statistics, percentages, page numbers in the footer).
- [ ] Implement: The system MUST provide chart data API endpoints for frontend visualization
- [ ] Implement: The system MUST support cross-register reporting
- [ ] Implement: The system MUST support date range filtering and period-over-period comparison
- [ ] Implement: The system MUST support custom calculated metrics
- [ ] Implement: Reports and exports MUST enforce RBAC permissions
- [ ] Implement: The system MUST support report caching for performance
- [ ] Implement: The system MUST support WOO transparency reporting
- [ ] Implement: The system MUST support audit report generation
- [ ] Implement: The system MUST enforce multi-tenant reporting isolation
- [ ] Implement: The system MUST provide an OData v4 endpoint for external BI tool integration
- [ ] Implement: The system MUST support API access for external BI tools beyond OData
