# Starting a flow from a date or from another system

Besides the object-change trigger, a flow can start in two more ways:

- **From a date** (`openregister.trigger-calendar`): a run starts when a date on a record comes due, optionally shifted by an offset.
- **From another system** (`openregister.trigger-webhook`): a run starts when another system posts a signed call to the flow's own address.

Both are entry nodes in the flow editor. Neither runs as the person who built the flow: each names the account its runs act as in `runAs`.

## Starting a flow from a date

| Setting | Meaning |
| --- | --- |
| `register`, `schema` | The records to watch. |
| `dateProperty` | A property of the schema with format `date` or `date-time`. |
| `offset` | An ISO 8601 duration from that date: `-P30D` is thirty days before, `P1W` a week after, `P0D` the date itself. |
| `workingCalendar` | Optional. When set, the offset is counted in that calendar's working time, so weekends and holidays are skipped. |
| `runAs` | The account the runs act as. |

How it behaves:

- Every record of the schema gets a due moment: its date plus the offset. Changing the date moves the moment; clearing the date or deleting the record removes it.
- A background job checks every five minutes and starts one run for each moment that has come due. A record starts at most one run per date value, also when the job runs on more than one server.
- When a trigger is switched on, moments that already lie in the past are skipped, not fired all at once. The number skipped is counted on the flow.

## Starting a flow from another system

Saving a flow with an incoming-webhook trigger gives the trigger:

- an **address**: `POST /index.php/apps/openregister/api/flows/hooks/{token}`, where `{token}` is random;
- a **signing secret**, stored encrypted outside the flow.

The save answer carries both **once**, under `webhookSecrets`, keyed by the node id:

```json
{
  "webhookSecrets": {
    "start": {
      "address": "/index.php/apps/openregister/api/flows/hooks/3f9c...e1",
      "secret": "c2b7...9a"
    }
  }
}
```

No later read of the flow returns the secret. Copy it into the calling system straight away. To replace it, tick **Rotate the signing secret on save** and save the flow: the new secret is shown once and calls signed with the old one are refused from then on.

### Calling the address

The caller sends a JSON body and signs the raw body bytes with HMAC-SHA256 using the secret:

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-OpenRegister-Signature` | The hex HMAC-SHA256 of the raw body, optionally prefixed `sha256=`. |
| `X-Delivery-Id` | Optional. A unique id per delivery; a repeat within 24 hours answers with the first run and starts nothing new. |

Answers:

| Status | When |
| --- | --- |
| `202` with `{"runId": "..."}` | The run is queued; the body is the run's input. |
| `400` | The body is not JSON. |
| `404` | Anything refused: an unknown address, a bad signature, a disabled flow or a run that could not start. The answer is the same in every case, so a caller cannot probe which one applied. |
| `413` | The body is larger than 1 MB. |

The size limit is checked first, then the signature, and only then is the body parsed. The address is rate limited for anonymous callers.

### Signing example

```bash
SECRET='c2b7...9a'
URL='https://cloud.example.com/index.php/apps/openregister/api/flows/hooks/3f9c...e1'
BODY='{"case":"2026-0042","status":"received"}'

SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')

curl -sS -X POST "$URL" \
  -H 'Content-Type: application/json' \
  -H "X-OpenRegister-Signature: sha256=$SIG" \
  -H 'X-Delivery-Id: 2026-0042-received' \
  --data-binary "$BODY"
```

The same in PHP:

```php
$body      = json_encode(['case' => '2026-0042', 'status' => 'received']);
$signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
```

Sign exactly the bytes you send: re-encoding the JSON after signing (spacing, key order) breaks the signature.
