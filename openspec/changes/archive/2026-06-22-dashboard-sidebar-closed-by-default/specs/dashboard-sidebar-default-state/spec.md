## ADDED Requirements

### Requirement: Dashboard right sidebar is collapsed on initial load

When the user navigates to the dashboard view, the right-hand app sidebar SHALL render in its collapsed (closed) state on initial load. The collapsed default applies to the dashboard view only and SHALL NOT alter the default open/closed state of any other view's sidebar.

#### Scenario: Landing on the dashboard

- **WHEN** the user navigates to the dashboard view (route `/`)
- **THEN** the right-hand app sidebar renders collapsed, leaving the dashboard's primary content unobstructed

#### Scenario: Other views are unaffected

- **WHEN** the user navigates to a non-dashboard view (for example registers, schemas, search, chat, deleted, entities, or audit/search trails)
- **THEN** that view's sidebar retains its existing default open/closed state, unchanged by this requirement

### Requirement: User can open the dashboard sidebar manually

The dashboard sidebar SHALL remain fully functional after being collapsed by default. The user SHALL be able to open it using the standard Nextcloud sidebar toggle, and all sidebar content (filters, totals, orphaned items) SHALL behave exactly as before once opened.

#### Scenario: Opening the sidebar via the toggle

- **WHEN** the user activates the standard Nextcloud sidebar toggle on the dashboard
- **THEN** the right-hand app sidebar opens and displays its overview tab with filters and statistics

#### Scenario: Sidebar content unchanged once open

- **WHEN** the dashboard sidebar is open
- **THEN** the register/schema filters, system totals, and orphaned-items sections render and operate identically to the prior behaviour
