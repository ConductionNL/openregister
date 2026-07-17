## ADDED Requirements

### Requirement: No shipped view renders a silent-empty stub

A view registered in the app navigation SHALL NOT ship with its data fetch
stubbed out to always render empty while swallowing errors. It SHALL either fetch
real data or present an explicit "coming soon"/unavailable state.

#### Scenario: Templates view shows real data or an explicit empty state

- **WHEN** a user opens the Templates view
- **THEN** it either lists templates from the backend
- **OR** shows an explicit "coming soon" state
- **AND** it does not silently render an empty list with error handling disabled

### Requirement: UI colors use NL Design System CSS variables

User-facing components SHALL use Nextcloud CSS custom properties for colors, not
hardcoded hex values, so theming and dark mode work and WCAG AA contrast holds.

#### Scenario: Recolored views respect the theme

- **WHEN** a user views a recolored component in dark mode
- **THEN** text and borders use theme variables and remain legible (WCAG AA)

### Requirement: User-facing strings are translatable

User-facing strings SHALL be wrapped in `t('openregister', …)`/`n(...)` with
English source keys.

#### Scenario: Cache management strings are translatable

- **WHEN** the Cache Management settings section renders
- **THEN** its headings, labels, and dialog text resolve through the i18n layer

### Requirement: Dialogs are isolated in their own files

`NcDialog`/`NcModal` markup SHALL live in a dedicated file under `src/dialogs/`
(or `src/modals/`), not inline in a parent view.

#### Scenario: Chat and cache dialogs are extracted

- **WHEN** the chat and cache-management dialogs are defined
- **THEN** each lives in its own file under `src/dialogs/`, imported by the parent
