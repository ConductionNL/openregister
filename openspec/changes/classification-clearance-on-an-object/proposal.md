# Classification clearance on an object

## Why

dossiq's `Service/InformatieobjectAccessGuard` decides whether a user may read a
document by comparing two ordinals. One comes off the object's
`vertrouwelijkheidaanduiding`. The other comes off the caller's group
membership through the `dossier_clearance_group_map` app-config key. Read is
allowed when the caller's ordinal is at or above the document's. It also caps
public publication at `vertrouwelijk`, refuses a write that lowers a
classification below its type's default, and filters a list down to what the
caller is cleared for.

Nothing about that mechanism is a document, a case, or a ZGW record. It is an
ordered label on the object, an ordered label on the principal, and a
comparison. decidiq has confidential decisions, keepiq has restricted tickets,
humaniq has personnel records, filinq has classified files. Each will write the
same class.

OpenRegister already half knows this and has the worse half.
`Service/CaseTypeAuthorizationService` carries the canonical ZGW ordinal
ordering and maps a `maxVertrouwelijkheidaanduiding` onto an existing `$in`
conditional-match clause. It has zero callers outside its own file. So the
platform holds the vocabulary in a class nothing runs, while the app holds the
enforcement in a class nothing shares.

The gap the app-side guard cannot close on its own is the list path. dossiq
filters in PHP after the rows come back, so a page of twenty rows can arrive as
a page of four, paging is wrong, and any count is wrong. A comparison
OpenRegister makes in SQL does not have that problem.

## What changes

- **A schema declares an ordered classification vocabulary** and which property
  on the object carries the label. The order is the schema's, not
  OpenRegister's, so ZGW's eight levels are one configured instance rather than
  a built-in.
- **A principal's clearance is resolved from declared group mappings**, highest
  wins, with a declared default for a principal no mapping names.
- **Read is refused above the caller's clearance on both layers.** The
  comparison compiles into the list query, so paging and counts stay true.
- **An unknown label is the most restrictive**, and an unresolvable clearance is
  the declared default. Both are logged.
- **A ceiling on a scope.** A schema may say that at or above a named level an
  object cannot be reached by a given scope, which is how "never publish
  `vertrouwelijk` on a share link" is said without naming publishing.
- **A write may raise a classification and not lower it** below the declared
  floor for that object's type.
- **`CaseTypeAuthorizationService` becomes the ZGW adapter for this**, or is
  deleted. It does not stay a dead class holding the vocabulary.

## Capabilities

### New capabilities

- `classification-clearance`: the ordered label, the principal's clearance,
  the comparison, the scope ceiling and the no-downgrade rule.

## Impact

- Affected specs: `classification-clearance` (new).
- Affected code: `Service/Object/PermissionHandler`,
  `Db/MagicMapper/MagicRbacHandler`, `Service/ConditionMatcher`,
  `Service/CaseTypeAuthorizationService`, schema-save validation.
- Consuming apps: dossiq deletes `InformatieobjectAccessGuard` and declares the
  ZGW vocabulary on the informatieobject schema. Its list filtering goes with
  it, which is the paging bug closing as a side effect.
- Depends on `an-app-declares-object-access-rather-than-guarding-it` for the
  undecidable posture. The two land in order.
- Backwards compatible: a schema declaring no vocabulary behaves as today.

## Next step

Read `design.md` for the ordering and lattice decisions, then work `tasks.md`.
