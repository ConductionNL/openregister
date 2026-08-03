## ADDED Requirements

### Requirement: Share-principal broker guard

The broker's access guard (Guard 1) SHALL gain a THIRD admit branch, evaluated
only after the existing personal-owner and organisation-member branches have
failed to admit: a call SHALL be admitted when the acting identity matches an
entry in the credential's `sharedWith[]` — either a `user` entry equal to the
acting user id, or a `group` entry naming a group the acting user belongs to.

This branch SHALL NOT change any existing verdict. A credential with no
`sharedWith[]` SHALL be admitted or denied exactly as it is today. The remaining
three guards (`allowedApps`, provider allow-rules, host-lock) SHALL apply
unchanged to every call admitted through this branch, and every denial SHALL
continue to funnel through the single static 403 with the reason logged
secret-free (ADR-004 Rule 4).

The branch SHALL fail closed: an unresolvable principal, an unauthenticated
caller, a malformed entry, or a store error SHALL deny.

The share branch SHALL be evaluated against the credential's own object data
inside the broker's guard chain, NOT delegated to the object RBAC evaluator. The
broker deliberately loads the credential with RBAC disabled and substitutes its
own stricter chain; routing credential access through schema-configurable RBAC
would make a security-critical verdict depend on admin-editable schema
configuration.

A share SHALL NOT admit across a tenant boundary: a principal outside the
credential's organisation SHALL be denied even when named in `sharedWith[]`.

#### Scenario: Shared user is admitted

- **WHEN** an authenticated user named in `sharedWith[]` triggers a broker call for an app listed in `allowedApps`
- **THEN** the call is admitted and the secret is injected server-side
- **AND** the allowedApps, allow-rule, and host-lock guards are still evaluated

#### Scenario: Member of a shared group is admitted

- **WHEN** an authenticated user belonging to a group named in `sharedWith[]` triggers a broker call
- **THEN** the call is admitted
- **AND** the group is used only as a permission principal, never as a tenant key

#### Scenario: Unshared user is denied

- **WHEN** an authenticated user who is neither the owner, an organisation member for an organisation-scoped credential, nor named in `sharedWith[]` triggers a broker call
- **THEN** the call is denied with the standard static 403
- **AND** the reason is logged with the credential UUID only

#### Scenario: The share branch never overrides a later guard

- **WHEN** a user named in `sharedWith[]` triggers a call for an app NOT listed in `allowedApps`
- **THEN** the call is denied by the allowedApps guard
- **AND** being a share recipient does not bypass it

#### Scenario: Existing verdicts are unchanged

- **WHEN** a credential carries no `sharedWith[]`
- **THEN** the personal-owner and organisation-member branches decide exactly as they did before this change

#### Scenario: A share cannot cross a tenant boundary

- **WHEN** a user outside the credential's organisation is named in `sharedWith[]` and triggers a broker call
- **THEN** the call is denied
