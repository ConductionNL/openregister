# Store plane: resolve an action posture against the declaring app

## Why

The previous increment defined `installAuth: "action:<name>"` and then
**refused** it, because resolving an ADR-023 action needs the matrix that lives
in the leaf app. Refusing was the right holding position — downgrading to
`authenticated` would have installed on a weaker gate than the app asked for —
but it leaves integriq unable to migrate, since `catalog.instantiate` is
exactly its posture.

## What changes

`StoreActionAuthorizer` resolves `OCA\<Studly>\Service\ActionAuthService` from
the server container and calls its `can(IUser, string): bool`. The controller
uses it for the `action:` arm and nothing else.

## The one thing that matters here

🔴 **An unresolvable authorizer REFUSES. It never permits.**

This is a duck-typed lookup by convention, and the fleet has been bitten by
that shape repeatedly: `isInstalled('docudesk')`,
`class_exists('OCA\DocuDesk\…')` — a runtime lookup pointed at a name nothing
answers to becomes a **silent no-op** rather than an error. A no-op here is an
install that skipped its authorization check and reported success.

So every way of failing to decide is a refusal, and each is logged at ERROR
with the name that could not be resolved:

- the class is not in the container
- it resolves but has no `can()` method — the shape a rename produces, where a
  lookup that only checked existence would sail past
- `can()` throws — ADR-023's own `requireAction()` throws to DENY, so anything
  propagating out must not be read as consent

**An administrator does not bypass the matrix.** The point of the posture is
that the leaf app decides; letting admins through anyway would make the
declaration decorative and would stop an app gating an install MORE tightly
than instance admin.

## What does NOT change

`installable` is untouched. This decides who may ask; it does not widen what an
install may write.
