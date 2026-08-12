# Object Sharing and the `private` Scope

Until now, an object was visible to everyone in its organisation who satisfied
its schema's authorization rules. There was no way to say "this one is mine", and
no way to hand one object to one colleague.

This page describes both: the `private` scope, and per-object grants.

> **Nothing changes on upgrade.** An object that does not declare `private` is
> decided exactly as it was. The capability is opt-in throughout.

---

## The short version

| you want to | you do |
|---|---|
| make one object yours alone | set its scope to `private` |
| make a whole schema's objects private by default | set `scope` in the schema's `authorization` block |
| let one colleague see a private object | grant them on that object |
| let somebody without an account see it | create a link, or invite an email address |
| stop any of the above | revoke it — it takes effect on the next request |

---

## Scope: who an object answers to

An object's **scope** answers a different question from an authorization rule.
A rule says *who does this admit*. The scope says *does this object answer to the
schema's rules at all*.

There are two values.

**`organisation`** — the default, and what every object has always done. The
schema's rules decide.

**`private`** — the object answers to nobody but its owner, Nextcloud
administrators, and whoever is explicitly invited on it. The schema's group rules
are suppressed for that object; suppressing them is the entire point.

### Setting it

The scope is not an ordinary data field, and you cannot set it by saving the
object. That is deliberate: per-object access control must not be reachable
through a data write. It has its own endpoint.

```http
PUT /api/objects/{register}/{schema}/{id}/scope
{ "scope": "private" }
```

**The owner may do this to their own object**, as well as an administrator. That
is safe because the scope can only ever *narrow* — see the ceiling rule below.

A schema can also declare a default for its objects:

```json
{ "authorization": { "scope": "private", "read": ["authenticated"] } }
```

The object wins over the schema, **in both directions**. A schema default is a
default, not a ceiling: an owner may put their own object back to
`organisation`, exactly as a Files user may share a file that started out
private.

### Fail-closed on anything unrecognised

Only `organisation` is recognised as non-private. A typo, a boolean, a scope name
from a future version — all are treated as private. Hiding an object that should
have been visible is recoverable; leaking one is not.

An *absent* value is not the same as an unrecognised one. Absent falls through to
the level below, which is what keeps this opt-in.

### An owner cannot lock themselves out

The owner and administrator admits are evaluated **first** and are never
conditional on the scope, the schema block, or a match clause. Making your own
object private, with a malformed scope, with no invitations, still leaves you
able to read, update and delete it.

---

## Grants: inviting one principal to one object

A grant is a real Nextcloud share on the object's folder. Core owns the record —
token, expiry, password, mailer, federation handshake, revocation — and
OpenRegister owns only the authorization verdict.

```http
POST   /api/objects/{register}/{schema}/{id}/shares
{ "type": "user", "shareWith": "colleague", "permissions": 1 }

GET    /api/objects/{register}/{schema}/{id}/shares
DELETE /api/objects/{register}/{schema}/{id}/shares/{shareId}
```

`type` is one of `user`, `group`, `remote`, `remote_group`. A remote principal is
just one more principal — it is resolved by the same evaluator as a local one,
not by a separate federated path.

Only the owner or an administrator may grant or revoke. A recipient cannot
re-share onward, and cannot list who else the object was shared with.

### The permission gates the action

`permissions` is Nextcloud's bitmask: `1` read, `2` update, `4` create, `8`
delete, `16` share. A read-only grant admits reads and nothing else.

An action outside those five — a custom verb like ZGW's `besluit_nemen` — has no
bit, so a grant cannot carry it and the caller is refused. RBAC grants
visibility; an extension verb is enforced by the endpoint that performs it. See
[ADR-010](../../openspec/architecture/adr-010-permission-verb-extensions.md).

### The ceiling rule — a grant cannot widen

**The schema's rules are the ceiling.** `private` narrows access to the owner; a
grant re-opens it *within* what the schema would have allowed. A grant can never
admit somebody the schema refuses.

Concretely, if a schema grants `read` to the `finance` group and you invite
someone outside it, they are still refused. If you want them to have access, the
schema is what needs changing.

This is why a private-by-default schema still needs a read rule. With
`{"scope": "private", "read": ["authenticated"]}` the ceiling is "any logged-in
user", the scope narrows that to the owner, and a grant picks out one colleague.
With `{"scope": "private"}` alone the ceiling admits nobody, so a grant has
nothing to re-open.

### Revocation is immediate

Share records are read through from Nextcloud at decision time and never copied.
A revoked or expired grant is refused on the **next request**, with nothing to
invalidate — because there is no OpenRegister-side copy to go stale.

---

## Links and email invitations

For somebody who has no account, or no account here.

```http
POST /api/objects/{register}/{schema}/{id}/links
{ "permissions": 1, "expiration": "2026-12-31", "password": "optional" }

POST /api/objects/{register}/{schema}/{id}/invitations
{ "email": "colleague@example.org", "permissions": 1 }
```

Both return a token. The token is redeemed on a public endpoint:

```http
GET /api/shared/{token}
```

A link is a **capability**, not a grant. It admits whoever holds the token, so it
is decided from the token rather than by the RBAC filter — an anonymous caller
presents no principal for the filter to resolve. Every check is Nextcloud's:
validity, revocation, expiry and password.

**Creating a link does not publish the object.** The RBAC verdict for every
logged-in principal is unchanged; only the token-holder gains anything. This is
what reconciles links with [ADR-006](../../openspec/architecture/adr-006-publish-is-rbac-scope.md),
which says publication is a schema-level RBAC change and not a per-object flag.
It is enforced by a test, not just asserted.

An email invitation carries no object data in the message. The recipient follows
it to reach the object, so revoking still works after the mail has been sent.

Links and invitations appear in the same list as the principal grants, and are
revoked the same way — `GET .../shares` reports them, and `DELETE
.../shares/{shareId}` withdraws them. They are listed but **not** grantable:
`POST .../shares` with `type: "link"` is refused, because a link is created by
the link endpoint above, with the rules that belong to it.

That split matters. While the listing covered principals only, a public link
could be minted and never seen again — the panel showed nothing, so there was no
revoke control, and withdrawing it meant raw SQL or core's Files UI. A capability
you cannot see is a capability you cannot revoke.

---

## What sharing an object also shares

An object grant is a share on the object's **folder**, and that folder holds the
object's attachments. **Granting the object therefore also reaches its files.**

For most objects that is the wanted, Files-like behaviour. Where it is not, it
needs saying out loud in the interface rather than being discovered.

File shares themselves remain a separate thing: sharing one document inside an
object's folder does *not* grant the object. Sharing a container and inviting a
person to a record are different acts.

---

## How the decision is made

The verdict is reached in four places — the single-object read, the relation
path, and both list-emitting query paths. They are required to agree, because a
principal honoured by some and not others is an access-control bug in both
directions: over-filtering shows an empty page, under-filtering leaks a row.

The vocabulary, the PHP verdict and the SQL predicate are therefore defined
exactly once, in `ObjectScopeResolver`, and every path is a caller. Agreement is
demonstrated against a live database with a positive control — the proof fails if
any one implementation is disabled.

The whole rule, as one line:

```
owner OR admin OR ((not private OR granted to me) AND the schema's rules)
```

---

## Upgrading: flows became private

One shipped schema changed behaviour when this landed, so an existing instance
sees a difference on `occ upgrade` without anybody editing anything.

The `flow` schema previously carried **no** authorization block. Under
OpenRegister's RBAC an absent block means "no schema-level restriction", so every
authenticated user in the tenant could list, read, edit and delete every other
user's flows. It now declares `scope: private`, plus the four action rules that
keep the capability exactly as it was — any authenticated user may still author
flows. What narrowed is *which* flows those verbs see: your own, plus anything
granted to you.

**What breaks.** Anything that relied on tenant-wide flow visibility. A user who
did not create a flow no longer sees it in the list or through
`GET /api/objects/{flowRegister}/{flowSchema}`, and gets a **404**, not a 403 —
deliberately, because a distinguishable 403 is an id-enumeration oracle. Team
flows that were shared only *by being visible to everyone* are the case to look
for; they were never actually shared.

**What to do.** Grant them, once, with the primitive documented above:

```
POST /api/objects/{register}/{schema}/{flowId}/shares
     { "type": "group", "shareWith": "flow-authors", "permissions": 1 }
```

`permissions: 1` (read) restores list and read visibility; add `update` /
`delete` bits for a principal that should also maintain the flow. The
[Shares tab](#grants-inviting-one-principal-to-one-object) on the flow's detail
page does the same thing without a request.

**How it lands.** The existing `ImportFlowRegister` repair step applies it on
`occ upgrade` — no manual import, no `--force`. The import gate is
content-authoritative rather than version-gated: it compares `properties`,
`required` *and* `authorization`, so the block applies even on an instance whose
stored schema version already matches the descriptor's.

To see the effect before upgrading, list the flows whose owner is not the person
who needs them; that set is exactly what will need a grant afterwards. The full
entry, including the reasoning, is in `CHANGELOG.md` under **Breaking Changes**.

---

## Related

- [Access Control](./access-control.md) — schema and property-level RBAC
- [Federation](./federation.md) — sharing with an organisation on another instance
- [Credential Broker](./credential-broker.md) — the worked example of a verb
  (`use`) enforced at the acting endpoint rather than by RBAC
