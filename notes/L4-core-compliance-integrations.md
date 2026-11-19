# L4-core-compliance-integrations: resume point (paused 2026-11-18, round 2)

Round 1 (#10658, #10659, #10660) is merged. Round 2 has three branches, all committed locally and none pushed yet.
Clone: `~/or-lanes/L4-core-compliance-integrations/openregister` (on the webhook branch). Worktree:
`~/or-lanes/L4-core-compliance-integrations/wt` (on the configuration branch). Remove it with
`cd ~/or-lanes/L4-core-compliance-integrations/openregister && git worktree remove ../wt` before handing the clone back.
Draft PR bodies: `pr/r2-webhook.md`, `pr/r2-audit.md`, `pr/r2-config.md`. Each has a RUN_* placeholder to fill in with what ran.

## 1. refactor/L4-core-compliance-integrations-webhook-request-event (08711497dff)
- **The change:**
  - ObjectsController dispatches the new Core event `Event/ObjectWriteRequestEvent` through `ObjectTransfer->eventDispatcher`.
  - The new Integrations listener `Listener/WebhookRequestInterceptionListener` answers it with `WebhookService::interceptRequest()`. It is registered in `IntegrationWiring::register()`.
  - The Webhook* cluster (7 files) is re-rowed to Integrations.
  - 32 ObjectsController tests and the factory are retargeted to an IEventDispatcher double. There are new event and listener tests, and IntegrationRegistrarTest is updated.
- **Verified:**
  - Ratchet: 23 references gone (1546 to 1523). One file is exposed on purpose (the event). deptrac shows one relabelled pair (OwnedWebhookView -> OpenRegisterActionAuthService) and 18 gone.
  - phpstan on the changed files: 0 errors. psalm on them: no errors.
  - The new event, listener and registrar tests pass.
- **Unverified:**
  - The full touched-test run (filter `ObjectsController|ObjectTransfer|IntegrationRegistrar|IntegrationWiring|RegisterStatisticsSchemaCounts|RegistersController|BuiltinIntegrationProvider|Webhook|ObjectWriteRequestEvent`). It got to the same 2126 tests on the pre-merge code with only the registrar failure, which is now fixed. The post-merge reruns were stopped (the presentation pause).
  - tests/Unit/Architecture: the run was stopped at 51% (killed, not failed).
  - diff-check.
- **Next:** rerun those three, then push and open the PR with body `pr/r2-webhook.md`.

## 2. refactor/L4-core-compliance-integrations-audit-entry-port (d36e1fd645a)
- **The change:**
  - Core port `Kernel/Contracts/Core/AuditEntryWriterInterface` and the value object `Kernel/Contracts/Core/AuditEntry`.
  - The Compliance adapter `Service/Audit/AuditEntryWriter`, bound in ContractBindings.
  - First slice: OrganisationLifecycleAuditor writes through the port. Its three tests now go through the real writer. There is a new AuditEntryWriterTest, and Adr011ValueObjectRulesTest pins AuditEntry.
- **Verified:**
  - Ratchet: 1 reference gone. Exposed on purpose: AuditEntry, the interface, and AuditEntryWriter (via ContractBindings).
  - Slice tests pass (filter `Adr011|AuditEntryWriter|OrganisationLifecycle|TenantLifecycle`, plus OrganisationController, F04OrganisationAuditor and ContractBindings: 253 tests OK).
  - phpstan on the changed files: 0 errors. psalm: the one error, ContractBindings::BINDINGS InvalidConstantAssignmentValue, is inherited from main (checked against main).
- **Unverified:** tests/Unit/Architecture, deptrac and diff-check.
- **Next:** run those, then push and open the PR with body `pr/r2-audit.md` (next slices are listed there).

## 3. refactor/L4-core-compliance-integrations-configuration-controller-bags (ebe4c655d8a, in wt)
- **The change:** csv only. Five bags are re-rowed to Configuration: Applications, EdepotSettings with its Domain, ConnectionReporting, and ApiTokenSettings.
- **Verified:**
  - Ratchet: 7 references gone, `--check` passes.
  - diff-check: only the inherited gate-67 and gate-102.
- **Unverified:** deptrac (it needs vendor-bin in the main clone) and tests/Unit/Architecture/ComponentDefinitionTest.
- **Next:** run those in the main clone, then push and open the PR with body `pr/r2-config.md`.

## Notes
- Long phpunit runs were being SIGTERMed during the pause, which was deliberate. Don't retry them until resumed.
- `tools/pu-l4` is a symlink to phpunit and can be deleted.
- Not started: the Processes and Platform controller bags (CalendarEvents, TaskEvents, Agents, ChatStreamTurn), one area per PR.
