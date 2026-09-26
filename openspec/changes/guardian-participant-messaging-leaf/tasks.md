## 1. Schema-level opt-in

- [x] 1.1 Register `x-openregister-talk-participants` in `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php`) with a comment following the file's own established warning convention; verify a schema saved with the key round-trips (covered by `tests/Unit/Service/TalkLinkServiceTest.php`'s opt-in-required tests, since a dropped key would make every one of them fail).

## 2. Service primitive

- [x] 2.1 Add `SchemaMapper` as a new constructor dependency on `TalkLinkService` (`lib/Service/TalkLinkService.php`); update the DI factory in `lib/AppInfo/Application.php` and the existing `tests/Unit/Service/TalkLinkServiceTest.php` constructor call; verify the full existing `TalkLinkServiceTest` suite still passes unchanged (regression).
- [x] 2.2 Add `TalkLinkService::schemaAllowsExternalParticipants(int $schemaId): bool`, resolving the schema via `SchemaMapper::find()` and reading `x-openregister-talk-participants` from its configuration, failing closed (`false`) on any resolution error; verify with a unit test asserting a non-opted-in schema returns `false`.
- [x] 2.3 Add `TalkLinkService::inviteExternalParticipant(string $objectUuid, string $roomToken, string $email, ?string $displayName): array`, refusing (throwing `Exception` with the matching HTTP code) in order: room not linked to the object (404), schema does not opt in (403), malformed email via `filter_var(..., FILTER_VALIDATE_EMAIL)` (400), no logged-in user; verify each refusal with its own unit test.
- [x] 2.4 Implement the Talk call itself: resolve the Manager/room/`ParticipantService` via the class's existing `resolveManager()`/`findRoom()`/`resolveParticipantService()` helpers, then call `addUsers($room, [['actorType' => 'emails', 'actorId' => $email, 'displayName' => $displayName ?? $email]])`; degrade to `{invited: false, unavailable: true, cause}` (never throw) when Talk/the room/the participant service is unavailable, mirroring `createAndLinkRoom()`'s existing degrade style; verify with a unit test asserting the degrade shape when Talk is unavailable (the only Talk-availability state reachable in this unit-test environment, per the file's own documented `@group requires-app-spreed` convention for the rest).

## 3. Documentation and spec sync

- [x] 3.1 Confirm `openspec validate guardian-participant-messaging-leaf --strict` passes with zero errors.
- [ ] 3.2 Run the diff-scoped gates (`php -l`, phpcs, phpstan, phpunit --filter) on every touched file and record exit codes in the PR body; report any inherited (pre-existing, non-touched-line) finding in one sentence rather than fixing it.
