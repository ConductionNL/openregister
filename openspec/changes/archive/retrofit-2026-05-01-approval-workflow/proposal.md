# Retrofit — approval-workflow

Describes observed behavior of 9 methods across `ApprovalController` and `ApprovalService` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/ApprovalController.php::index()`
- `lib/Controller/ApprovalController.php::show(int $id)`
- `lib/Controller/ApprovalController.php::create()`
- `lib/Controller/ApprovalController.php::update(int $id)`
- `lib/Controller/ApprovalController.php::destroy(int $id)`
- `lib/Controller/ApprovalController.php::objects(int $id)`
- `lib/Controller/ApprovalController.php::steps()`
- `lib/Controller/ApprovalController.php::approve(int $id)`
- `lib/Controller/ApprovalController.php::reject(int $id)`
- `lib/Service/ApprovalService.php::initializeChain(ApprovalChain $chain, string $objectUuid)`
- `lib/Service/ApprovalService.php::approveStep(int $stepId, string $userId, string $comment)`
- `lib/Service/ApprovalService.php::rejectStep(int $stepId, string $userId, string $comment)`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed behavior with caveats (statusOnApprove/statusOnReject fields present but not acted on by controller)

## Capabilities

### New Capabilities

- `approval-workflow` — multi-step, role-gated approval chains for OpenRegister objects

Source: `openspec/coverage-report.md` generated 2026-05-01. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
