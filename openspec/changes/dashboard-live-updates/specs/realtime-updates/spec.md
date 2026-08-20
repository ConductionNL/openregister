## ADDED Requirements

### Requirement: The dashboard MUST consume live update events for its sidebar-scoped register+schema

The dashboard page MUST, while scoped to a register+schema via the sidebar
filter, hold a live collection subscription
(`or-collection-{register-slug}-{schema-slug}`) through the shared
`@conduction/nextcloud-vue` live-updates layer, and MUST treat received
events as refetch hints for its object-CRUD-derived widgets — refetching
through the existing dashboard store actions and NEVER applying event
payloads as data.

- The subscription MUST re-scope when the sidebar filter changes and MUST be
  released on unmount; an in-flight subscribe MUST be deduplicated per scope
  and a resolution landing after a release MUST be unsubscribed instead of
  leaked (same guard set as the object list view).
- Refetches MUST be coalesced with a trailing debounce so a bulk-import event
  burst results in a single refetch after the burst settles.
- Widgets whose data is NOT signalled by the push event dialect
  (register/schema CRUD counts, search-trail activity) and the unscoped
  ("all registers") dashboard MUST keep their existing manual/one-shot
  refresh behaviour — no wildcard or register-level event key exists.
- A failed subscription attempt MUST NOT break the dashboard — it degrades to
  the pre-existing manual-refresh behaviour with a console warning.

#### Scenario: Scoped dashboard refreshes when another session changes the collection
- **WHEN** the dashboard is scoped to register `zaken` / schema `meldingen` and another session creates or deletes a `meldingen` object
- **THEN** the client receives the `or-collection-zaken-meldingen` event and, after the trailing debounce, refetches the register totals, the objects-by-* chart data, and the audit-trail statistics, so the Objects KPI, Events KPI, charts, and sidebar totals reflect the change without a manual refresh

#### Scenario: Bulk import burst coalesces into one refetch
- **WHEN** a bulk import flushes a burst of collection events for the subscribed scope
- **THEN** the dashboard performs a single refetch of its widget data after the burst settles instead of one refetch per event

#### Scenario: Subscription follows the sidebar scope
- **WHEN** the user switches the sidebar filter to a different register/schema, clears it, or leaves the dashboard
- **THEN** the previous subscription is released (and any pending debounced refetch cancelled) before a new one is established for the new scope, and no subscription is held while the dashboard is unscoped

#### Scenario: Unscoped dashboard keeps one-shot behaviour
- **WHEN** the dashboard shows the default "all registers" view (no register+schema selected)
- **THEN** no live subscription is held and the widgets refresh only via mount fetches or the manual refresh action

@e2e exclude Live-updates transport requires a notify_push-enabled instance and a second mutating session; covered by nc-vue unit tests on the shared plugin and manual verification.
