# Design — integration-mock-mode

## Where the short-circuit lives

The mock flag is detected in `ExternalIntegrationRouter`, after `loadSource()`
resolves the OpenConnector source and before any CallService call:

```php
$source = $this->loadSource(sourceId: $sourceId, providerId: $provider->getId());
$config = $this->readSourceConfiguration(source: $source);
if (($config['mock'] ?? false) === true) {
    return $this->resolveMockBody(config: $config, sourceId: $sourceId);   // call()
    // callWithMeta(): return ['body' => resolveMockBody(...), 'meta' => mockMeta($config)];
}
```

Putting it in the router (not each provider) means **every** external leaf gets
mock mode for free, and the canned body flows through each leaf's existing
extractor (`extractRows` / `extractPersonen` / the dispatch `response`
pass-through) unchanged — so the leaf and the consuming app's mappers never know
a real call was skipped.

## Reading the source configuration

OpenConnector sources are OpenRegister `ObjectEntity` objects whose payload is
`$source->getObject()` (an array with `isEnabled`, `location`, `configuration`,
…). `readSourceConfiguration()` reads the `configuration` sub-array across every
shape the SourceMapper might hand back (ObjectEntity, plain array,
`jsonSerialize`, or a `getConfiguration()` accessor) and returns `[]` when none
exists. It reads **transport configuration only** — never a credential — so the
mock detection is foundation-safe.

## The fixture lives on the source

`configuration.mockResponse` carries the canned body, shaped exactly like the
real upstream so the leaf's extractor consumes it unchanged:

| Leaf            | Source slug(s)                            | mockResponse shape |
|-----------------|-------------------------------------------|--------------------|
| KvK             | `kvk`                                     | `{ resultaten: [ {kvkNummer, naam, adres…} ] }` |
| OpenCorporates  | `opencorporates`                          | `{ results: { companies: [ { company: {name, company_number, jurisdiction_code:'nl'…} } ] } }` |
| BRP/HaalCentraal| `brp-haalcentraal`                         | `{ personen: [ { burgerservicenummer, naam, geboorte… } ] }` (fake test BSN `999990019`) + `mockMeta` |
| SMS             | `cmcom-sms` / `messagebird-sms` / `twilio-sms` | vendor-specific success with a `MOCK-SMS-…` id |
| WhatsApp        | `whatsapp-cloud-api` / `whatsapp-bsp`     | `{ messaging_product, messages: [ { id: 'wamid.MOCK…' } ] }` |

A source flagged `mock:true` but lacking `mockResponse` returns an empty `{}`
body — the leaf's extractor then yields an empty result set rather than fataling.

## Meta synthesis (BRP)

`callWithMeta()` (used by the BRP leaf for the Wet-BRP audit record) returns a
synthesized `meta` so the consumer always has `correlationId` / `durationMs` /
`status`. Defaults are `status:200`, `durationMs:12`, and a fresh random
`MOCK-CID-…` correlation id; a source may pin any of them via
`configuration.mockMeta`. No BSN or body field is ever placed in `meta`.

## Going live

Mock is opt-in and reversible: an operator sets the real credential on the
source and removes `configuration.mock` (the production `location` is already
set on each fragment). The real CallService path is byte-for-byte unchanged for
non-mock sources.
