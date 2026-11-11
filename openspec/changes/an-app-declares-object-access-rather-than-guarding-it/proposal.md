# An app declares object access rather than guarding it

## Why

Ruben ruled on 2026-09-19 that additional access control on an object is an
abstract OpenRegister feature, not an application feature. He said it about a
case. It is general: more than one app needs it, so it lives here and the apps
consume it.

Measured against that ruling, the problem is not that OpenRegister lacks the
primitives. It is that nothing says an app may stop writing its own guard, so
three apps wrote one anyway.

What OpenRegister enforces today, by whom, at which layer:

- `Service/Object/PermissionHandler` decides a single object in PHP. It reads
  the schema and object `authorization` block, expands roles, applies deny
  entries through `Rbac/DenyResolver`, and resolves per-object grants through
  `Rbac/ObjectGrantResolver`.
- `Db/MagicMapper/MagicRbacHandler` decides the list path in SQL, with the
  same vocabulary compiled into predicates, so a list and a find agree.
- `Rbac/ObjectScopeResolver` carries `private` beside `organisation`, landed
  as #3966 on 2026-09-18.
- `Service/ConditionMatcher` resolves `$userId`, `$user.groups`,
  `$organisation` and `$now` inside a `match` clause, on both layers, and
  `$contains` tests array membership on both layers.
- `Rbac/TokenGrantValidator` refuses a grant that may not be issued. Nothing
  in `lib/` calls it. Its only caller is its own unit test.

So a rule of the shape "a user may read this object when a property of the
object names them" is already expressible, already enforced on both layers, and
already correct. What is missing is the sentence that says so, the posture to
take when the decision cannot be computed, and an audited path off the app-side
guards that exist because nobody wrote that sentence.

### What the consuming apps carry

dossiq, read from `ConductionNL/dossiq` on `development`:

| Class | Behaviour | Verdict |
|---|---|---|
| `Service/Sharing/CaseAccessPolicy` | `case.assignee == uid`, or `uid` in `case.assignees`, or the user once minted a share for the case. Fails OPEN when OpenRegister is absent, when the schema is unconfigured, and when the read throws. | Object access control wearing a case-shaped name. Every branch is generic. |
| `Service/CaseAccessGuard` | The same relationship, fail CLOSED, with an admin bypass, mutation on `assignee` only and read on `assignees` too. | The same, again, with the opposite posture. Two implementations of one rule in one app. |
| `Service/InformatieobjectAccessGuard` | An ordered confidentiality lattice: an ordinal off the object's `vertrouwelijkheidaanduiding`, an ordinal clearance off group membership via `dossier_clearance_group_map`, read allowed at or above, a publish ceiling, a no-downgrade rule on write, and list filtering. | The ZGW vocabulary is domain-specific. The lattice is not. It is out of scope here and belongs to `classification-clearance-on-an-object`. |

Genuinely case-specific across all three: the property names `assignee`,
`assignees` and `caseId`, the ZGW eight-level vocabulary, and the OCS exception
type thrown. That is all of it.

`CaseAccessPolicy`'s three fail-open branches are the finding that matters. An
unreachable register returns true, so the guard that is meant to narrow access
widens it exactly when the platform is unwell.

## What changes

- **An app declares per-object access on the schema and writes no guard.** The
  declaration form already exists. This change states it as a contract, names
  the two enforcement layers, and requires that an app-side guard be justified
  in writing or removed.
- **The fail posture is declared, not implied.** A schema says what an
  undecidable access question means: `closed` or `open`. There is no default
  `open`, and an undecidable question is logged with what was missing.
- **A grant's issuer keeps the access they issued from**, until the grant is
  revoked. That is `CaseAccessPolicy`'s share-minter rule, made general and
  made auditable instead of inferred from the presence of a row.
- **`TokenGrantValidator` is called or removed.** A validator nothing calls is
  the same shape as no validation.
- **An app asks rather than guesses.** One question, `may this principal do
  this to this object`, answered over the existing permissions surface, so a
  consuming app has something to call in the one case where it cannot express
  the rule declaratively.

## Capabilities

### New capabilities

- `object-access-contract`: what an app declares, which layer enforces it,
  what an undecidable decision means, and when an app-side guard is allowed.

## Impact

- Affected specs: `object-access-contract` (new).
- Affected code: `Service/Object/PermissionHandler`,
  `Db/MagicMapper/MagicRbacHandler`, `Service/Rbac/ObjectGrantResolver`,
  `Service/Rbac/TokenGrantValidator`, `Controller/ObjectPermissionsController`.
- Consuming apps: dossiq deletes `CaseAccessPolicy` and `CaseAccessGuard` and
  keeps the property names as schema configuration. zaakafhandelapp, decidiq
  and keepiq carry the same shape and adopt from here.
- Not backwards compatible in one respect, deliberately: a schema that today
  answers an undecidable question with access keeps doing so only while it says
  `open` out loud.

## Next step

Read `design.md` for the fork this change turns on, then work `tasks.md`.
