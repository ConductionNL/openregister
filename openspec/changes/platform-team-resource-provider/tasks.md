# Tasks: platform-team-resource-provider

- [ ] 1.1 A team resource provider listing objects whose owning-team property names the team (D-1).
- [ ] 1.2 A paged, cursor-based listing with the reader's access applied (D-2).
- [ ] 1.3 A schema declaration opting its objects onto team pages, defaulting to out (D-3).
- [ ] 1.4 A per-object answer for whether it belongs to a team, read from the property.
- [ ] 2.1 `tests/e2e/ci/platform-team-resources.spec.ts`: a team page listing its cases, an opted-out schema absent.
- [ ] 2.2 Unit tests: the paging cursor, the access filter, the belongs-to answer.
- [ ] 2.3 `openspec validate platform-team-resource-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the schemas and the owning-team property, register no provider.
