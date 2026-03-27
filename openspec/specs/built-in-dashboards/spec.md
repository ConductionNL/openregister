# built-in-dashboards Specification

## Purpose
Implement a drag-and-drop dashboard builder that creates visual analytics from register data without external BI tools. Dashboards MUST support chart types (bar, line, pie, time series), metric panels, data tables, and auto-refresh. This complements the rapportage-bi-export spec by providing lightweight built-in visualization for quick data insights.

**Source**: Gap identified in cross-platform analysis; four platforms offer built-in dashboard builders.

## ADDED Requirements

### Requirement: Users MUST be able to create custom dashboards
Each user MUST be able to create one or more personal dashboards composed of configurable widgets.

#### Scenario: Create a new dashboard
- GIVEN a user navigates to the Dashboards section
- WHEN they click "New Dashboard" and enter name `Meldingen Overzicht`
- THEN an empty dashboard canvas MUST be created
- AND the dashboard MUST be accessible from the user's dashboard list

#### Scenario: Share a dashboard
- GIVEN a dashboard `KPI Overzicht` created by user `manager`
- WHEN `manager` shares the dashboard with group `directie`
- THEN all users in `directie` MUST see the dashboard in their list
- AND shared users MUST have read-only access by default

### Requirement: Dashboards MUST support drag-and-drop widget placement
Widgets MUST be placeable on a responsive grid layout via drag-and-drop.

#### Scenario: Add a chart widget
- GIVEN the user is editing dashboard `Meldingen Overzicht`
- WHEN they drag a "Bar Chart" widget onto the canvas
- AND configure: data source = schema `meldingen`, group by = `status`, metric = count
- THEN a bar chart MUST render showing meldingen counts per status

#### Scenario: Add a metric panel widget
- GIVEN the user adds a "Metric" widget
- AND configures: data source = schema `meldingen`, filter = `status: nieuw`, metric = count, label = `Nieuwe meldingen`
- THEN a large number display MUST show the current count of new meldingen

#### Scenario: Resize and reposition widgets
- GIVEN a dashboard with 3 widgets
- WHEN the user drags a widget to a new position or resizes it
- THEN the widget MUST snap to the grid at the new position/size
- AND other widgets MUST reflow to avoid overlap

### Requirement: The system MUST support multiple chart types
The following chart types MUST be available as dashboard widgets.

#### Scenario: Bar chart
- GIVEN widget configured with group by `status` and metric count
- THEN a vertical bar chart MUST display one bar per status value with the count

#### Scenario: Line chart (time series)
- GIVEN widget configured with group by `created` at monthly interval and metric count
- THEN a line chart MUST display the trend of object creation over time

#### Scenario: Pie chart
- GIVEN widget configured with group by `categorie` and metric count
- THEN a pie chart MUST display the proportion of each category

#### Scenario: Data table widget
- GIVEN widget configured to show top 10 meldingen by creation date
- THEN a table MUST display the 10 most recent meldingen with key columns

### Requirement: Dashboard widgets MUST auto-refresh
Widgets MUST periodically refresh their data to show current information.

#### Scenario: Auto-refresh interval
- GIVEN a dashboard with auto-refresh set to 60 seconds
- WHEN 60 seconds elapse
- THEN all widgets MUST re-query their data sources and update their visualizations
- AND the refresh MUST be non-disruptive (no full page reload)

#### Scenario: Manual refresh
- GIVEN a dashboard widget
- WHEN the user clicks the refresh button on the widget
- THEN that widget MUST immediately re-query its data and update

### Requirement: Widget data sources MUST respect RBAC
Dashboard widgets MUST only display data the viewing user is authorized to access.

#### Scenario: Filtered widget for restricted user
- GIVEN a shared dashboard with a widget showing all meldingen
- AND user `medewerker-1` only has access to schema `meldingen` (not `vertrouwelijk`)
- WHEN `medewerker-1` views the dashboard
- THEN the widget MUST only display data from `meldingen`

### Requirement: Dashboards MUST support filters
Users MUST be able to apply dashboard-level filters that affect all widgets.

#### Scenario: Date range filter
- GIVEN a dashboard with 4 widgets showing meldingen data
- WHEN the user applies a date range filter: March 2026
- THEN all 4 widgets MUST update to show only data from March 2026

#### Scenario: Schema filter
- GIVEN a dashboard showing data from register `zaken` (multiple schemas)
- WHEN the user filters to schema `vergunningen`
- THEN all widgets MUST show only vergunningen data

### Current Implementation Status
- **Partial:**
  - `DashboardController` (`lib/Controller/DashboardController.php`) exists with page rendering and data retrieval endpoints
  - `DashboardService` (`lib/Service/DashboardService.php`) provides `getStats()` method for register/schema aggregation and data size calculations
  - Frontend dashboard views exist at `src/views/dashboard/` with a dedicated Vue component
  - Dashboard page route is registered (`openregister.dashboard.page`)
  - SOLR dashboard statistics available via `SolrSettingsController` (`lib/Controller/Settings/SolrSettingsController.php`)
- **NOT implemented:**
  - Drag-and-drop widget placement or grid layout
  - Configurable chart widgets (bar, line, pie, data table)
  - Custom dashboard creation per user
  - Dashboard sharing between users/groups
  - Auto-refresh functionality on widgets
  - Dashboard-level filters (date range, schema filter)
  - RBAC-filtered widget data
  - Widget data source configuration (query builder for schemas)
- **Partial:**
  - The current dashboard shows system-level statistics (object counts, data sizes) but not user-configurable visual analytics
  - No chart rendering library is integrated in the frontend

### Standards & References
- **Nextcloud Dashboard API** — Nextcloud's built-in dashboard widget registration system (IWidget interface)
- **WCAG 2.1 AA** — Accessibility requirements for data visualizations
- **Chart.js or Apache ECharts** — Common chart libraries for Vue-based dashboards
- **vue-grid-layout** — Vue component for drag-and-drop grid layouts
- **W3C WAI-ARIA** — Accessibility for interactive widgets

### Specificity Assessment
- The spec clearly defines widget types and interaction patterns but lacks technical implementation details.
- Missing: which charting library to use; database schema for storing dashboard configurations and widget definitions; API endpoints for dashboard CRUD and widget data queries; how aggregation queries are built from schema definitions.
- Ambiguous: whether dashboards should use Nextcloud's native Dashboard API (IWidget) or be a standalone feature within OpenRegister; how complex aggregation queries (group by, time series) are executed across different storage modes (normal vs. MagicMapper).
- Open questions:
  - Should this integrate with Nextcloud's built-in dashboard or be a standalone OpenRegister feature?
  - What are the performance implications of real-time aggregation queries on large datasets?
  - Should dashboard definitions be exportable/importable between environments?
  - How do aggregation queries work across MagicMapper (JSON column) vs. normal (JSONB) storage?

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: DashboardController provides page rendering and data retrieval endpoints with a calculate() method for generating chart data. DashboardService offers getStats() for register/schema aggregation and data size calculations. Built-in chart types include audit-trail-actions, objects-by-register, objects-by-schema, and objects-by-size. Frontend dashboard views exist at src/views/dashboard/ with a dedicated Vue component. The dashboard page route is registered as openregister.dashboard.page. SolrSettingsController provides SOLR-specific dashboard statistics.

**Nextcloud Core Integration**: The dashboard is currently an internal OpenRegister page served within the app's navigation. Nextcloud provides a native Dashboard API (OCP\Dashboard\IWidget, OCP\Dashboard\IAPIWidget) that allows apps to register widgets on the Nextcloud home dashboard. Registering an IDashboardWidget would give users a quick overview of register statistics (total objects, recent changes, data sizes) directly on their Nextcloud home screen without navigating to the OpenRegister app. The existing DashboardService::getStats() data could be exposed through this widget interface. The frontend Vue component could use Nextcloud's @nextcloud/vue components for consistent styling.

**Recommendation**: The current internal dashboard with statistics and chart calculations provides useful operational insights. To better integrate with Nextcloud, register one or more IDashboardWidget implementations that surface key metrics (object counts, recent activity, data growth trends) on the Nextcloud home dashboard. The full drag-and-drop dashboard builder described in the spec is an ambitious feature that should remain within the OpenRegister app context rather than trying to fit into Nextcloud's simpler widget framework. For chart rendering, Chart.js or Apache ECharts integrate well with Vue 2 and the Nextcloud frontend stack. Aggregation queries should use MagicMapper's existing query infrastructure to respect RBAC, ensuring dashboard widgets only show data the viewing user is authorized to see.
