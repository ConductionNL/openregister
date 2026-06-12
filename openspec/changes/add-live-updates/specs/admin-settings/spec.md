---
status: proposed
---

# Admin Settings (delta — adds Push notifications section)

**OpenSpec changes**: `add-live-updates` (in-progress, adds Push notifications section)

**Cross-references**: [realtime-updates](../realtime-updates/spec.md), Nextcloud `OCP\Settings\ISettings`, `OCP\App\IAppManager`.

## Purpose of this delta

OpenRegister already exposes an admin settings page via `OCA\OpenRegister\Settings\OpenRegisterAdmin` (`lib/Settings/OpenRegisterAdmin.php`) registered as an `ISettings` provider. There is no main spec describing this capability today; this delta initialises the capability spec and adds the Push notifications section that the `add-live-updates` change introduces.

(After this change archives, the resulting `openspec/specs/admin-settings/spec.md` becomes the canonical home for OR admin-page requirements.)

---

## ADDED Requirements

### Requirement: The admin settings page MUST render in the Nextcloud admin panel

OpenRegister MUST register an `ISettings` implementation that renders within the Nextcloud administration panel under the OpenRegister section. The page MUST only be accessible to Nextcloud administrators.

#### Scenario: Admin settings page appears in Nextcloud admin panel
- **GIVEN** an administrator navigates to Nextcloud Settings → Administration
- **THEN** an "OpenRegister" section MUST appear in the left navigation
- **AND** clicking it MUST render the OpenRegister admin settings page

---

### Requirement: The admin settings page MUST include a "Push notifications" section

The admin settings page MUST include a section titled "Push notifications" that displays the current state of notify_push integration in three distinct states.

#### Scenario: notify_push not installed — shows installation guidance
- **GIVEN** the notify_push Nextcloud app is NOT installed
- **WHEN** an administrator views the OpenRegister admin settings page
- **THEN** the "Push notifications" section MUST display: "Realtime push not available — the notify_push app is not installed"
- **AND** a link to the Nextcloud App Store notify_push listing MUST be shown
- **AND** no error state MUST be displayed (not installed is a valid configuration)

#### Scenario: notify_push installed but not yet confirmed active — shows setup guidance
- **GIVEN** the notify_push app IS installed
- **AND** no successful push has been delivered since last configuration change
- **WHEN** an administrator views the admin settings page
- **THEN** the "Push notifications" section MUST display: "notify_push is installed but not yet active"
- **AND** a link to `https://github.com/nextcloud/notify_push#configuration` MUST be shown

#### Scenario: notify_push active — shows green status
- **GIVEN** the notify_push app is installed
- **AND** at least one push has been successfully delivered (confirmed by AppConfig key `openregister.push_available`)
- **WHEN** an administrator views the admin settings page
- **THEN** the "Push notifications" section MUST display: "Realtime push active"
- **AND** a green status indicator MUST be shown
- **AND** no action link MUST be shown

---

### Requirement: Push status detection MUST use `IAppManager` and AppConfig — never instantiate `IQueue`

Push status detection logic MUST use `OCP\App\IAppManager::isInstalled('notify_push')` for the install check and the AppConfig key `openregister.push_available` (set by `NotifyPushListener` on first successful push) for the active-state check. Status detection MUST NOT attempt to instantiate `IQueue` during the settings render path — the goal is to avoid exceptions in the admin UI when notify_push is partially installed.

#### Scenario: Status detection does not throw when notify_push is missing
- **GIVEN** notify_push is not installed and `IQueue` is not in the container
- **WHEN** the admin settings page is rendered
- **THEN** NO exception MUST be thrown
- **AND** the page MUST render to completion with the "not installed" status

---

### Requirement: Status indicators MUST use NL Design System tokens

UI elements on the admin settings page MUST use Nextcloud CSS variables and MUST NOT hardcode colours. (Per ADR-010.)

#### Scenario: Status indicator uses CSS variables
- **GIVEN** the "Realtime push active" state is shown
- **THEN** the green status indicator MUST use `var(--color-success)` or equivalent Nextcloud CSS variable
- **AND** MUST NOT use a hardcoded hex value

---

## Implementation notes (non-normative)

- **PHP class**: `OCA\OpenRegister\Settings\OpenRegisterAdmin` (extend; already registered as ISettings provider)
- **Dependencies to inject**: `OCP\App\IAppManager`, `OCP\IConfig` or `OCP\AppFramework\Services\IAppConfig`
- **Status method**: `getPushStatus(): string` returning `'not_installed' | 'unreachable' | 'active'`
- **AppConfig key**: `openregister.push_available` (set to `'1'` by `NotifyPushListener` on first successful push)
- **Template**: extend `templates/settings/admin.php` or the Vue component that renders it
