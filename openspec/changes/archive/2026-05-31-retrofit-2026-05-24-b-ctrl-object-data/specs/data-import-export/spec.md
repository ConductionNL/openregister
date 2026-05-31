---
status: draft
---

# Data Import and Export

## Purpose

Extend the data-import-export capability with two controller surfaces that the
existing spec does not yet cover: (1) the configuration remote-portability flow
on `ConfigurationController` (publishing to and discovering/importing from
GitHub, GitLab, and arbitrary URLs), and (2) the bulk delete-by-scope surface on
`BulkController`. The existing REQs already cover OpenAPI 3.0.0 configuration
portability and bulk *import*; these added REQs cover the remote publishing and
the bulk *delete* halves. Reverse-specced from the existing implementation.

## ADDED Requirements

### Requirement: Configurations MUST be publishable to and discoverable from remote GitHub, GitLab, and URL sources

`ConfigurationController` MUST support a remote configuration-package
portability surface that complements the local OpenAPI 3.0.0 export/import:
discovering OpenRegister configurations hosted on GitHub or GitLab, listing
their branches, importing a configuration from a GitHub/GitLab repository or an
arbitrary URL, and publishing a local configuration to a GitHub repository.
Discovery MUST validate the `source` against `github`/`gitlab`. Import-from-source
MUST construct a `Configuration` entity and run it through the standard import
flow. Publishing MUST validate the configuration is publishable, prepare the
OpenAPI payload, detect an existing file SHA for updates, and update the local
configuration with the resulting GitHub source information.

> NOTE: several of these methods were mislabeled in the upstream coverage scan as
> "triaged DROP from chat-ai / actions / object-lifecycle / geo-metadata". That
> triage is incorrect — they are configuration-package GitHub/GitLab publishing
> and belong to this capability.

#### Scenario: Discover configurations on GitHub
- **GIVEN** a discover request with `source=github` and a `_search` term
- **WHEN** `ConfigurationController::discover()` runs
- **THEN** the GitHub handler's `searchConfigurations()` MUST be invoked and the results returned (HTTP 200)

#### Scenario: Reject an invalid discovery source
- **GIVEN** a discover request with `source` that is neither `github` nor `gitlab`
- **WHEN** `discover()` validates the source
- **THEN** the response MUST be HTTP 400 with `error: 'Invalid source. Must be "github" or "gitlab"'`

#### Scenario: List repository branches
- **GIVEN** a request supplying `owner` and `repo`
- **WHEN** `getGitHubBranches()` (or `getGitLabBranches()`) runs
- **THEN** the response MUST contain the branch list; a missing `owner` or `repo` MUST return HTTP 400

#### Scenario: Import a configuration from a remote source
- **GIVEN** an import-from-source request (GitHub, GitLab, or URL)
- **WHEN** `importFromGitHub()` / `importFromGitLab()` / `importFromUrl()` runs
- **THEN** a `Configuration` entity MUST be constructed from the fetched config and run through the standard import flow

#### Scenario: Publish a local configuration to GitHub
- **GIVEN** a publishable local configuration and valid GitHub publish parameters
- **WHEN** `publishToGitHub()` runs
- **THEN** the configuration MUST be exported, an existing file SHA detected for updates, the content published to the target repository, and the local configuration updated with the GitHub source info

#### Scenario: Reject publishing with missing parameters
- **GIVEN** a publish request missing required GitHub parameters
- **WHEN** `extractGitHubPublishParams()` returns an error
- **THEN** the response MUST be HTTP 400 with the error message

### Requirement: The system MUST support bulk delete of objects scoped by register and schema

`BulkController` MUST expose mass-delete operations scoped by register and/or
schema: `deleteSchema()` and `deleteSchemaObjects()` delete all objects for a
register+schema combination, and `deleteRegister()` deletes all objects for a
register. These endpoints MUST accept an optional `hardDelete` flag (soft delete
by default), resolve slug/numeric identifiers to numeric IDs, and return a
`{success, message, deleted_count, deleted_uuids, ...scope ids, hard_delete}`
envelope. Invalid (non-numeric where required) identifiers MUST return HTTP 400;
unresolvable register/schema MUST return HTTP 404; failures MUST return HTTP 500.

#### Scenario: Bulk delete all objects for a register+schema
- **GIVEN** a register and schema with objects and an optional `hardDelete` flag
- **WHEN** `BulkController::deleteSchemaObjects()` runs
- **THEN** the register/schema identifiers MUST be resolved to numeric IDs and `deleteObjectsBySchema()` invoked
- **AND** the response MUST include `success: true`, `deleted_count`, `deleted_uuids`, `register_id`, `schema_id`, and `hard_delete`

#### Scenario: Bulk delete rejects a non-numeric schema id where one is required
- **GIVEN** a `deleteSchema()` request with a non-numeric `schema`
- **WHEN** the controller validates the input
- **THEN** the response MUST be HTTP 400 with `error: "Invalid schema ID. Must be numeric."`

#### Scenario: Bulk delete all objects for a register
- **GIVEN** a numeric register id
- **WHEN** `deleteRegister()` runs
- **THEN** `deleteObjectsByRegister()` MUST be invoked and the response MUST include `deleted_count`, `deleted_uuids`, and `register_id`

#### Scenario: Unresolvable register/schema returns 404
- **GIVEN** a `deleteSchemaObjects()` request whose register or schema cannot be resolved
- **WHEN** `resolveRegisterSchemaIds()` throws
- **THEN** the response MUST be HTTP 404 with the error message
