# configuration-deployment

## ADDED Requirements

### Requirement: A configuration change is a draft until it is deployed (REQ-CAD-001)

A configuration write MAY be made as a draft against the live value. The
live value SHALL be unchanged until a deployment applies the draft. A
draft set SHALL carry an author, and an instance MAY require an approval by
a principal other than the author before the set can be deployed.

#### Scenario: an edit does not take effect yet

- **GIVEN** a live setting and a draft changing it
- **WHEN** the instance reads the setting
- **THEN** the live value is returned

#### Scenario: four eyes are required

- **GIVEN** an instance requiring an approver other than the author
- **WHEN** the author tries to deploy their own draft set
- **THEN** it is refused, naming the requirement
- @e2e exclude {the requirement is the instance flag configuration_four_eyes, which is deliberately reserved so it cannot travel inside a deployment and therefore has no HTTP door to switch on; asserted in tests/Unit/Service/ConfigurationDeployment/ConfigurationDraftServiceTest.php::testFourEyesAreRequired and tests/Unit/Service/ConfigurationDeployment/DeploymentServiceTest.php::testUnderFourEyesAnAuthorCannotDeployTheirOwnSet}

### Requirement: A deployment applies a draft set as one named unit, and a rollback is a deployment (REQ-CAD-002)

A deployment SHALL apply a draft set in one act, carrying a name, an
author, an approver, a time and the list of values it changed. A
deployment that cannot apply every value SHALL apply none and SHALL name
the value that refused. A rollback SHALL restore the values of an earlier
deployment as a new deployment naming what it restores, and history SHALL
stay append-only.

#### Scenario: a broken weekend is undone in one act

- **GIVEN** a deployment that changed nine values
- **WHEN** an administrator rolls it back
- **THEN** the nine earlier values are live and a new deployment records what it restored

#### Scenario: a partial deployment does not happen

- **GIVEN** a draft set where one value would fail validation
- **WHEN** it is deployed
- **THEN** nothing is applied and the refusing value is named

### Requirement: The instance explains its effective configuration (REQ-CAD-003)

For any setting the system SHALL answer which value is in effect, which
layer set it, and which deployment last changed it. Layers SHALL be
ordered instance, register, bundle, subject, and a value that predates the
first deployment SHALL be answered as such rather than as unknown.

#### Scenario: why does this instance behave like this

- **GIVEN** a setting overridden at register level
- **WHEN** the explainer is asked
- **THEN** it names the effective value, the register layer and the deployment that set it

#### Scenario: an older value is named honestly

- **GIVEN** a value never changed by a deployment
- **WHEN** the explainer is asked
- **THEN** it names the value and says it predates the first deployment
- @e2e exclude {over HTTP every value this suite can reach is one it created, so the assertion would be about its own fixture rather than about an older instance; asserted in tests/Unit/Service/ConfigurationDeployment/ConfigurationExplainerTest.php::testAnOlderValueIsNamedHonestly}

### Requirement: A configuration bundle binds one set to many subjects (REQ-CAD-004)

A named bundle SHALL carry permissions, notification rules and lifecycle
settings, and SHALL be bindable to many schemas at once. Changing the
bundle SHALL change every bound subject. A subject MAY override one value
of its bundle, and the override SHALL be recorded and readable as an
exception.

#### Scenario: two hundred case types stay in step

- **GIVEN** forty schemas bound to one bundle
- **WHEN** a notification rule in the bundle changes and is deployed
- **THEN** all forty use the new rule
- @e2e tests/e2e/ci/configuration-deployment.spec.ts

#### Scenario: an exception is visible as an exception

- **GIVEN** one of those schemas overriding a single value
- **WHEN** the bundle's bindings are listed
- **THEN** that schema is listed as overriding, naming the value
- @e2e tests/e2e/ci/configuration-deployment.spec.ts

### Requirement: Inherited integration configuration and copied matrices land as drafts (REQ-CAD-005)

An integration configuration SHALL be settable at the instance or the
register and inherited below, overridable at a lower level, with the
effective explainer naming the level that set it. A transition or
permission matrix SHALL be copyable from one role or schema to another,
and the copy SHALL land as a draft rather than live.

#### Scenario: one mail relay, not one per schema

- **GIVEN** an integration configured at the instance
- **WHEN** a schema that sets none is read
- **THEN** the instance value applies and the explainer names the instance
- @e2e exclude {the e2e suite may not write an instance setting, because every instance key it could set is one a person or another suite depends on; the same inheritance is asserted over HTTP one layer lower in the bundle scenarios, and at the instance layer in tests/Unit/Service/ConfigurationDeployment/ConfigurationBundleServiceTest.php::testOneMailRelayAtTheInstanceAppliesToASchemaThatSetsNone}

#### Scenario: a copied matrix is reviewed before it is live

- **GIVEN** a permission matrix on one role
- **WHEN** it is copied onto another role
- **THEN** it exists as a draft and the live matrix is unchanged
- @e2e tests/e2e/ci/configuration-deployment.spec.ts
