# apphost-store-plane: delta for store-publish-route

## ADDED Requirements

### Requirement: A store page can publish through one route

`POST /api/store/publish` SHALL publish `item` to the app's configured registry. It SHALL use the app's declared `publish.fields` and `publish.groups`. An anonymous caller SHALL get 401. A user `canPublish()` does not admit SHALL get 403, and nothing SHALL be sent.

#### Scenario: a member of the publish group publishes

- GIVEN pipelinq declares `publish: {fields: [title, kind, version, components], groups: [pipelinq-publishers]}`
- AND Pieter is in `pipelinq-publishers`
- WHEN Pieter publishes `{slug: woo-intake, title: Woo-intake, kind: pipeline, version: 1.0.0}`
- THEN the registry receives the slug and the declared fields only
- AND the answer is `{outcome: ok, slug: woo-intake}`

#### Scenario: someone outside the group

- GIVEN a user who is in no publish group and not an administrator
- WHEN they publish
- THEN the answer is 403 and the registry receives nothing

### Requirement: Publishing to a local registry writes in-process

When `registry_url` is `local`, publish SHALL write the filtered body into the configured register through the object service. It SHALL update the object carrying the same slug, or create one, and it SHALL make no HTTP request.

#### Scenario: a new version of an item already in the local registry

- GIVEN the local registry holds `woo-intake` at version 1.0.0
- WHEN `woo-intake` is published at version 1.1.0
- THEN that same object now reads version 1.1.0
