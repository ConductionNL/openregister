# Design: object ownership and handover

## The pieces that already existed

OpenRegister did not need a new ownership mechanism. It needed the gaps closing in
the one it has.

- `ObjectEntity` carries `_owner`, and `SaveObject::applyOwnerAttribution()`
  already derives it from the session user.
- `ObjectScopeResolver` is the one definition of who is admitted unconditionally,
  and four enforcement paths call it.
- `AuditTrailMapper::createAuditTrail()` records a typed action with an actor and
  seals it into the hash chain.

So this change extends those three rather than adding a parallel mechanism.

## The decisions

**The owner is not writable through a save, and a save that tries is refused.**
The value used to be dropped in silence, which answered 2xx while storing a
different owner: the caller was told its request succeeded when the one thing it
asked for did not happen. An echo of the stored owner, an echo of the acting user,
an expanded owner object and a session-less write are all still accepted, because
each of those is a round trip or a restore rather than a claim.

**The owning group lives in the `_authorization` block, beside `scope`.** No
migration, and the same storage concept the scope already uses. It is read by the
same resolver, so the single-object verdict and both list emitters agree on it.

**A takeover is governed by the rules, not by this feature.** Anybody the rules
admit to EDIT a record may take it. The handover gives them nothing they did not
already have; it says out loud who is answerable. Giving a record away is an
administrator's action, because parking a record on a colleague's name makes them
answerable for something they never saw.

**A cascade moves a child only when the child shared the previous owner.** The
reference makes it a child; the shared owner makes moving it right. A child
somebody else owns is their record.

**One audit entry per record, never one per batch.** An entry covering fifty
records cannot be found from any of them.
