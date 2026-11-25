# Moving records between organisations

One instance can hold several legal entities, each as its own organisation. When
a case changes hands (a municipality passes it to the regional environment
service, for example), the case and what belongs to it move to the other
organisation. Each kind of record that the case reaches gets a policy, and
an administrator sees and approves the result before anything changes.

## The policy per kind of record

Every record that a relation reaches from the case (in either direction, up to
`depth` steps, at most 3) takes the policy named for its schema:

| Policy | What happens |
| --- | --- |
| `move` | The record changes organisation. |
| `copy` | The target organisation gets its own copy. The original stays where it is. |
| `drop` | The record is not carried. It stays in its organisation and is not deleted. |

The case itself always moves. Nothing is assumed. The preview is refused in
these cases:

- A schema the case reaches has no policy. The refusal names the schemas.
- A record held by a third organisation is set to `move`, for example a shared
  party. Moving it would take it away from that organisation, so copy or drop it.
- The relation walk stopped early, or it reached a record you may not read. A
  transfer of part of the graph would look like a whole one.

## Preview, then approve

```
POST /apps/openregister/api/objects/{register}/{schema}/{id}/transfer/preview
{"targetOrganisation": "<uuid>", "policies": {"10": "move", "11": "drop", "12": "copy"}, "depth": 1}
```

The answer lists every record with its organisation and the policy that applies to
it, plus a `digest` of exactly that plan.

```
POST /apps/openregister/api/objects/{register}/{schema}/{id}/transfer
{"targetOrganisation": "<uuid>", "policies": {...}, "depth": 1, "approval": "<digest>"}
```

The transfer calculates the plan again. If the plan is not the one you approved
(a record was added, or the policies changed), the transfer answers 409 and
writes nothing. Without an approval it also answers 409.

The transfer runs in one transaction. It writes the copies first and then the
moves. A failure rolls everything back. Both routes are for administrators only.

## On both audit trails

A completed transfer writes one entry in each organisation's audit trail:
`organisation.transfer.out` in the organisation the case leaves and
`organisation.transfer.in` in the one it arrives in. Both entries carry the
same `correlation`, and both name what moved, what was copied (original and
copy) and what was dropped. Neither side then sees a record appear or vanish
without a cause.
