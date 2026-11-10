# apphost-settings-plane

## ADDED Requirements

### Requirement: An app declares feature toggles and the plane administers them

An app MAY declare `features` in its manifest, each with `key`, `label`,
`description` and `default`. The generic settings `index` SHALL return the
merged map of declared defaults and instance overrides, `update` SHALL
accept overrides for declared keys only and refuse an undeclared key with
422, and the app's Nextcloud admin page SHALL render a Features section from
the declaration.

#### Scenario: an administrator switches a feature off

- **GIVEN** an app declaring `features: [{key: "ai-summary", default: true}]`
- **WHEN** an administrator sets `ai-summary` to false on the admin page
- **THEN** `index` returns `features.ai-summary` false and the change is audited
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/feature-toggles.spec.ts when the section ships}

#### Scenario: an undeclared key is refused

- **GIVEN** the same app
- **WHEN** `update` is sent with `features.unknown: true`
- **THEN** the response is 422 naming `unknown`
- @e2e exclude {validator, covered by unit tests}

### Requirement: PHP and the manifest runtime read the same toggle

`FeatureToggleService::isEnabled(app, key)` SHALL answer the merged value,
cached per request and invalidated on `update`; the merged map SHALL reach
the client through initial state; and a manifest entry with
`visibleIf: {feature: key}` SHALL be hidden when the toggle is off.

#### Scenario: a page disappears when its feature is off

- **GIVEN** a manifest page with `visibleIf: {feature: "ai-summary"}` and the toggle off
- **WHEN** a user opens the app
- **THEN** the page is absent from navigation and its route answers the app's not-found page
- @e2e exclude {manifest runtime, covered by vitest on the runtime and the e2e spec of task 3.1}
