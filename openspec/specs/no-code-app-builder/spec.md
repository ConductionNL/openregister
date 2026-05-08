---
status: draft
---

# no-code-app-builder Specification

## Purpose
Enable building web applications from register data without coding. Administrators MUST be able to create custom pages with drag-and-drop components (tables, forms, detail views, charts) that read from and write to register schemas. Applications MUST support custom layouts, navigation, and access control for both internal users and public visitors.

**Source**: Gap identified in cross-platform analysis; two platforms offer no-code application builders.

## ADDED Requirements

### Requirement: The system MUST support creating application definitions
Administrators MUST be able to define applications that bundle pages, data sources, and navigation into a cohesive experience.

#### Scenario: Create a simple application
- GIVEN a register `meldingen-register` with schema `meldingen`
- WHEN the admin creates an application:
  - Name: `Meldingen Portaal`
  - Slug: `meldingen-portaal`
  - Pages: list page + detail page
  - Data source: `meldingen-register/meldingen`
- THEN the application MUST be accessible at `/apps/openregister/app/meldingen-portaal`
- AND the application MUST display the configured pages

#### Scenario: Multi-page application with navigation
- GIVEN an application with pages: `Overzicht`, `Nieuw`, `Statistieken`
- WHEN the application is loaded
- THEN a navigation sidebar or top bar MUST display all page links
- AND clicking a page MUST load the corresponding view

### Requirement: Pages MUST support drag-and-drop component placement
Each page MUST be composed of components placed on a grid layout via a visual editor.

#### Scenario: Add a data table component
- GIVEN the admin is editing page `Overzicht`
- WHEN they drag a "Data Table" component onto the canvas
- AND configure it to display schema `meldingen` with columns: title, status, date
- THEN the page MUST render a table showing meldingen objects with those columns

#### Scenario: Add a form component
- GIVEN the admin is editing page `Nieuw`
- WHEN they drag a "Form" component onto the canvas
- AND configure it to create objects in schema `meldingen` with fields: title, description, location
- THEN the page MUST render a form that creates new meldingen objects on submit

#### Scenario: Add a chart component
- GIVEN the admin is editing page `Statistieken`
- WHEN they drag a "Chart" component onto the canvas
- AND configure it as a bar chart grouping meldingen by status
- THEN the page MUST render a bar chart showing meldingen counts per status

### Requirement: Applications MUST support access control
Each application MUST define who can access it: internal users, specific groups, or public (unauthenticated).

#### Scenario: Internal application
- GIVEN application `Meldingen Beheer` with access restricted to group `behandelaars`
- WHEN a user not in `behandelaars` tries to access the application
- THEN the system MUST return HTTP 403

#### Scenario: Public application
- GIVEN application `Meldingen Portaal` with public access enabled
- WHEN an unauthenticated visitor accesses the application URL
- THEN the application MUST load with read-only data from the configured schema
- AND write operations MUST require authentication

### Requirement: Components MUST support data binding and actions
Components MUST be able to read from and write to register data, and trigger actions on user interaction.

#### Scenario: Table row click navigates to detail
- GIVEN a data table component on the list page
- WHEN the user clicks a row for `melding-1`
- THEN the application MUST navigate to the detail page with `melding-1` loaded

#### Scenario: Form submit creates object
- GIVEN a form component bound to schema `meldingen`
- WHEN the user fills in the form and clicks submit
- THEN a new object MUST be created in the register
- AND the user MUST be redirected to the list page with a success message

### Requirement: Applications MUST support custom domains
Applications MUST optionally be accessible via a custom URL path or subdomain.

#### Scenario: Custom path
- GIVEN application `Meldingen Portaal` with custom path `/meldingen`
- WHEN a user navigates to `https://gemeente.nl/meldingen`
- THEN the application MUST be served at that path

### Current Implementation Status
- **Not implemented — application definitions**: No `Application` entity in the context of no-code app building exists. The existing `lib/Db/Application.php` is unrelated (it handles OpenRegister's own app-level entities like configurations, not user-built applications).
- **Not implemented — drag-and-drop page editor**: No visual page builder, canvas, or component placement system exists in the frontend codebase.
- **Not implemented — component library**: No data table, form, chart, or detail view components are available as drag-and-drop widgets.
- **Not implemented — custom domains or paths**: No routing mechanism for user-defined application slugs or custom domain mapping exists.
- **Tangentially related — Views system**: `ViewsController` (`lib/Controller/ViewsController.php`) and the `ViewHandler` (`lib/Service/Handler/ViewHandler.php`) provide saved view configurations for register data, which could serve as a foundation for read-only data display components.
- **Tangentially related — Dashboard service**: `DashboardService` (`lib/Service/DashboardService.php`) and `DashboardController` (`lib/Controller/DashboardController.php`) provide aggregate metrics, which could feed chart components.

### Standards & References
- WCAG 2.1 AA for accessibility of the visual editor and generated applications
- NL Design System for consistent Dutch government theming
- JSON Schema for data binding configuration
- Nextcloud App Framework for authentication and access control integration

### Specificity Assessment
- **Moderately specific but very large scope**: The spec covers application definitions, drag-and-drop editors, component libraries, access control, data binding, and custom domains -- each of which is a major feature on its own.
- **Missing details**:
  - Data model for application definitions (what entity stores pages, components, layout?)
  - Component rendering engine (Vue components? Server-side rendering?)
  - Layout system specifics (CSS Grid? Flexbox? Fixed grid?)
  - How data sources are configured and bound to components
  - State management between pages (URL parameters? Shared store?)
  - Versioning/publishing workflow for applications
- **Open questions**:
  - Should this be a separate Nextcloud app rather than part of OpenRegister?
  - How does this relate to the existing Procest/Pipelinq apps that already build custom UIs on top of OpenRegister?
  - What is the minimum viable component set for an initial release?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No visual app builder, drag-and-drop page editor, or component library exists. The Views system and Dashboard service provide tangential foundations for data display.

**Nextcloud Core Interfaces**:
- `IDeclarativeSettingsForm` patterns: Use Nextcloud's declarative settings/form patterns as inspiration for schema-driven form generation. Each form component reads its field definitions from the OpenRegister schema's JSON Schema properties, auto-generating input fields with appropriate types, validation, and labels.
- `INavigationManager` (`OCP\INavigationManager`): Register each user-created application as a navigation entry in Nextcloud's app menu. Applications with `public: true` are accessible without authentication; internal applications require Nextcloud login and group membership checks.
- `routes.php` / dynamic routing: Register a catch-all route (`/app/{slug}/{path+}`) that resolves user-created applications by slug. The controller loads the application definition and renders the appropriate page with its configured components.
- `IGroupManager` (`OCP\IGroupManager`): Enforce access control on applications by checking the requesting user's group membership against the application's configured access groups.

**Implementation Approach**:
- Create an `Application` entity (distinct from OpenRegister's existing `Application.php`) that stores: name, slug, pages (JSON array), data sources (register/schema references), navigation configuration, and access control settings. Store application definitions as OpenRegister objects in a system register, making them self-hosting.
- Build a `PageEditor.vue` component using a grid layout system (CSS Grid). The editor provides a component palette (data table, form, detail view, chart, text block) that can be dragged onto the canvas. Each placed component stores its configuration (data source, columns, fields, chart type) as a JSON definition.
- Implement a component rendering engine in Vue that dynamically instantiates the correct component based on the stored definition. Use Vue's `<component :is="...">` pattern for dynamic component loading. Components read data from OpenRegister's REST API using the configured register/schema.
- Data binding between components uses URL parameters and a page-level state object. Table row clicks set `{selectedObjectId}` in the URL, which the detail view component reads to load the object. Form submissions call `ObjectService` via the REST API and redirect on success.
- Deep link registry integration: Register each application's pages in the `DeepLinkRegistryService` so that unified search results link to the correct application page.

**Dependencies on Existing OpenRegister Features**:
- `ObjectService` — CRUD API for data reading and writing from components.
- `SchemaService` — schema property definitions drive form field generation and table column configuration.
- `ViewsController` / `ViewHandler` — saved view configurations as foundation for read-only display components.
- `DashboardService` — aggregate metrics for chart component data.
- `DeepLinkRegistryService` — register application page URLs for search integration.
