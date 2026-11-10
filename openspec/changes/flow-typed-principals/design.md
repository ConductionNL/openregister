# Design

## The decision behind every other decision

A performer reference is **resolved late and re-resolved every time**, never
frozen at task creation.

The alternative — resolve once, store the uids, authorise against those — is
simpler, faster, and wrong for the domain. A municipal approval outlives the
roster it was raised against. A task assigned to the bezwaarcommissie in
March must be answerable by whoever sits on it in June, and a task assigned
to the *afdelingshoofd* must follow the post, not the person who held it. The
frozen design gets that backwards in the direction that hurts: it keeps
authorising someone who has left and stops authorising the person now
responsible.

The cost is a resolver call on every answer. That is one membership lookup on
a verb a human is performing by hand, which is not a budget worth optimising
against correctness.

The resolution IS recorded, as `flow-tasks` requires — but as evidence,
consulted by no guard. Confusing those two is how a "who was asked" audit
field quietly becomes an authorisation bypass.

## D-1 · Why an event and not an interface the apps implement directly

Flow nodes are already contributed through `RegisterFlowNodesEvent`, and
principals take the same path for the same three reasons: OpenRegister must
not name a consuming app (gate-27, ADR-022); a type whose app is not
installed must simply be absent rather than fatal; and the registration point
is already a place the fleet's developers know to look.

The resolver interface lives in OpenRegister and is implemented in the app
that owns the concept. decidiq knows what a position on a body is; hermiq
knows what a function is; dossiq knows what a case role is. None of that
knowledge can move into the engine without the engine growing opinions about
municipal organisation charts.

## D-2 · Why the type is refused at save and the resolution at run

These are two different kinds of wrongness and they are discovered by two
different people.

An **unknown type** is a defect in the document. The author is at the
keyboard, the field is on screen, and the fix is to pick a different type.
Refusing at save puts the error in front of the only person who can act on
it.

An **empty resolution** is a fact about the instance, and it changes. A
committee with no members today has members next week. Refusing to *save* a
flow because a group is momentarily empty would make the flow unauthorable
for a reason that has nothing to do with the flow. Refusing to *create the
task* is the right moment: that is when somebody actually needs to be found.

The failure mode this replaces is the one that was measured — a task created
for nobody, a run suspended, a heartbeat re-reading it every few minutes, and
no signal anywhere. Silence was the defect. A loud step failure, subject to
the flow's own error policy, is strictly better even when the author chooses
to continue past it.

## D-3 · Collapsing three candidate fields into one

`candidateUsers`, `candidateGroups` and `candidateRole` are three fields that
differ only in the kind of thing you type into them. That is a type system
implemented as field names, and it exists only because the field was a text
box.

With typed references, one `candidates` field expresses all three and more,
including a mix. The three keep working as input — they are read as
candidates of type `user`, `group` and `role` — so no stored flow changes
meaning and no migration is required for them.

The one thing lost is the ability to say "these users AND these groups" as
separate, separately-meaningful lists. Nothing in the routing strategies
reads them separately, so nothing loses a capability.

## D-4 · What the repair may and may not guess

The repair rewrites a stored string to a typed reference by resolving it
once. Three outcomes, and only one of them may be acted on:

| Resolves under | Action | Why |
|---|---|---|
| exactly one type | rewrite | unambiguous; the string meant that |
| more than one type | leave, report | guessing moves who may answer, silently |
| no type | leave, report | already broken; the repair says so, it does not become the breakage |

The ambiguous case is not hypothetical. A Nextcloud instance may hold a user
and a group of the same name, and the existing `mayAnswer` accepts BOTH — uid
equality first, then group membership — so today's behaviour is genuinely the
union. Any rewrite narrows it. That is a decision for a person.

🔴 The repair MUST NOT fail the upgrade over unresolvable strings. On the
measured instance that would have meant 27 failures on a routine `occ
upgrade`, for flows that were already broken before the repair existed.
A repair step that turns pre-existing breakage into an upgrade failure is
worse than the breakage.

## D-5 · Why `performerType` goes away

`Task.performerType` accepts `user | group | agent | worker` and the node
exposed it as a free-typed word. With every reference carrying its own type,
the field is a second, weaker copy of the same fact, and two sources of one
truth is how they drift.

The **column** stays, because it is on the task row and other things read it.
It becomes derived: written from the resolved reference's type, never
authored.

## Traps

**A bare string must keep meaning `user`.** Reading it as "try every type"
would be friendlier and would silently widen who may answer on every existing
flow. The current guard tries uid first, so `user` is the reading that
preserves behaviour.

**`mayAnswer` is called on a hot path and on a cold one.** It guards the
completion verb, and it is also consulted by inbox projections. A resolver
that is cheap for one group is expensive for a hundred rows. Inbox scoping
must keep predicating in the datastore rather than resolving row by row in
PHP; the resolver decides *authorisation*, not *listing*.

**An agent is a performer, and agents are not users.** The `agent` resolver
must return something the answer guard can compare against, which means an
agent turn completes as a real identity. Whatever that identity is, it must
not be a member of the groups a human performer belongs to, or an agent step
would be answerable by the humans and vice versa.

**Do not let the picker become the contract.** The `principal` field type
tells the editor how to draw a field. What is *valid* is decided by the
resolver registry on the server. An editor that offers only what it can search
must still accept a reference typed by an app whose search it does not know.
