---
title: Property Authorization
sidebar_position: 8
description: Fine-grained, field-level access control with conditional rules, operators, and dynamic variables.
keywords:
  - Open Register
  - Property Authorization
  - Field-Level RBAC
  - Scheduled Publication
  - Conditional Access
---

# Property Authorization

Property Authorization (property-level RBAC) gives fine-grained control over **individual properties** inside an object. While schema- and object-level RBAC decide whether a user sees an object at all, property authorization decides which **fields** of that object are readable or updatable — and can evaluate conditions against the object itself, the current user, their organisation, and the current time.

## When to Use It

Use property authorization when an object is broadly readable but parts of its data must be restricted or conditionally exposed. Typical cases:

- **Scheduled publication** — a `publishedAt` field should only be visible after a given moment.
- **Organisation-scoped fields** — internal notes (`interneAantekening`) on an otherwise public object are only visible to members of the owning organisation.
- **Role-restricted edits** — a public-read property can only be changed by specific groups.
- **Audit / system columns** — expose read access to a `status` field but forbid updates from outside a workflow.

If a field should *never* be visible to some users, property authorization is the right tool. If the whole object should be hidden, use the simpler schema-level authorization described in [Access Control](./access-control.md).

## Schema Structure

Property authorization lives on a property definition inside a schema, under the `authorization` key:

```json
{
  "properties": {
    "interneAantekening": {
      "type": "string",
      "authorization": {
        "read": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ],
        "update": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ]
      }
    }
  }
}
```

`authorization` accepts two action keys:

| Key | Applies to |
| --- | --- |
| `read` | Controls whether the property is rendered on outgoing data. A denied read causes the field to be stripped from the response. |
| `update` | Controls whether the property can be written. A denied update is rejected by the validation layer (strict mode — no silent drop). |

Both are **arrays of rules**. If any rule in the array grants access, access is granted. An empty (or missing) `read`/`update` means "no property-level restriction — fall back to object-level rules".

## Rule Shape

Each rule combines a group check with an optional condition:

```json
{
  "group": "editors",
  "match": { "_organisation": "$organisation" }
}
```

| Field | Meaning |
| --- | --- |
| `group` | A Nextcloud group the current user must belong to. The literal value `"public"` matches **any authenticated user** (including when no other group matches). |
| `match` | Optional map of conditions evaluated against the object. All conditions must be true for the rule to grant access. Omit `match` for an unconditional rule. |

A rule is satisfied when **both** the group check and every `match` condition pass.

## Match Conditions

Each entry in `match` pairs a property name (or metadata key) with either a literal value or an operator object.

### Literal equality

```json
{ "match": { "status": "published" } }
```

Matches when the object's `status` property equals `"published"`.

### Operator form

```json
{ "match": { "publishedAt": { "$lte": "$now" } } }
```

Matches when the object's `publishedAt` is less than or equal to the current datetime.

### Supported operators

| Operator | Meaning |
| --- | --- |
| `$eq` | Equal to |
| `$ne` | Not equal to |
| `$gt` | Greater than |
| `$gte` | Greater than or equal to |
| `$lt` | Less than |
| `$lte` | Less than or equal to |
| `$in` | Value is in the given array |
| `$nin` | Value is not in the given array |
| `$exists` | Field is present (`true`) or absent (`false`) |

Multiple operators can be combined on a single field (evaluated as AND):

```json
{ "match": { "publishedAt": { "$gte": "2026-01-01T00:00:00Z", "$lte": "$now" } } }
```

## Dynamic Variables

Any string value inside `match` starting with `$` is resolved at evaluation time.

| Variable | Resolves to |
| --- | --- |
| `$organisation` / `$activeOrganisation` | UUID of the current user's active organisation. |
| `$userId` / `$user` | The current user's Nextcloud UID. |
| `$now` | The current datetime in ISO 8601 (`c`) format. |

Dynamic variables work both as direct values (`"_organisation": "$organisation"`) and as operator operands (`{ "$lte": "$now" }`).

If a variable cannot be resolved (e.g. `$organisation` for an unauthenticated visitor), the condition evaluates to `false` and the rule is skipped.

## Worked Examples

### 1. Scheduled publication

Expose `publishedAt` to everyone — but only after the moment it encodes. Before that moment, the field is stripped from the response.

```json
{
  "publishedAt": {
    "type": "string",
    "format": "date-time",
    "authorization": {
      "read": [
        { "group": "public", "match": { "publishedAt": { "$lte": "$now" } } }
      ]
    }
  }
}
```

Behavior:

- `publishedAt = 2026-05-01T09:00:00Z` and today is 2026-04-21 → the field is removed from the response.
- Same value on 2026-05-02 → the field is returned.

Combine this with a schema-level `read` that allows `public` and you get a self-publishing object: the object is always visible, the publication timestamp only appears once the embargo lifts.

### 2. Organisation-scoped internal notes

A public-read object with an internal notes field that should only be visible — and editable — to members of the owning organisation.

```json
{
  "interneAantekening": {
    "type": "string",
    "authorization": {
      "read": [
        { "group": "public", "match": { "_organisation": "$organisation" } }
      ],
      "update": [
        { "group": "editors", "match": { "_organisation": "$organisation" } }
      ]
    }
  }
}
```

Behavior:

- Anonymous visitor: `interneAantekening` is stripped from responses.
- User in a different organisation: field stripped.
- User whose active organisation matches the object's `_organisation`: field is rendered.
- Writing requires `editors` group membership **and** the matching organisation.

### 3. Role-restricted field update

Everyone can read `status`, but only members of `workflow-operators` can change it:

```json
{
  "status": {
    "type": "string",
    "authorization": {
      "update": [{ "group": "workflow-operators" }]
    }
  }
}
```

`read` is omitted, so reads fall back to object-level rules. A non-operator who attempts to PATCH `status` gets a validation error rather than a silent drop.

## Evaluation Semantics

**Reads.** On every render, each property carrying an `authorization.read` block is evaluated. If no rule grants access, the property is removed from the output before serialization. The object itself is not hidden — only the field.

**Updates.** On write, every property on the incoming payload is checked against `authorization.update`. Unauthorized properties cause a validation error (strict mode). This prevents a client from smuggling updates through by sending unknown fields.

**Create.** On create, organisation-based `match` conditions cannot be evaluated against the not-yet-existing object; they are skipped. All other conditions (group membership, `$now` comparisons, literal values on the incoming payload) apply normally.

**Admin bypass.** When RBAC's admin override is enabled and the current user is in the admin group, property authorization is short-circuited. Admins see and can update every property.

**Null handling.** Missing object fields evaluate as `null` during `match`. Use `$exists` explicitly if you need to distinguish "absent" from "present but null".

**Nested properties.** `match` keys walk the object with dot notation (e.g. `"address.country": "NL"`). Metadata keys that start with underscore (`_organisation`, `_owner`) read from the object's metadata envelope.

## Performance Notes

Schemas without any `authorization` blocks skip the property-RBAC pass entirely. Internally this is gated by a `hasPropertyAuthorization()` short-circuit on the schema, so the overhead for schemas that don't use the feature is one boolean check per request.

For schemas that do use it, the active organisation UUID is resolved once per `ConditionMatcher` instance and cached for the remainder of the request.

## Relationship to Object-Level RBAC

Property authorization runs **after** object-level RBAC has already decided the user is allowed to see or write the object:

```
┌─────────────────────────────────────────┐
│  Schema/object authorization            │  ← see ./access-control.md
│  "Can you see this object at all?"      │
└─────────────────────┬───────────────────┘
                      │ yes
                      ▼
┌─────────────────────────────────────────┐
│  Property authorization (this doc)      │
│  "Which fields can you see / write?"    │
└─────────────────────────────────────────┘
```

The two layers are composable: a property may be broadly readable (`public`) but only update-able by a specific group, while the object itself is only visible to members of that group's organisation.

## Related

- [Access Control](./access-control.md) — schema- and object-level RBAC, authorization exceptions, admin override.
- [Multi-Tenancy](./multi-tenancy.md) — how the `_organisation` metadata is populated and the `$organisation` variable resolved.
