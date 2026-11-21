# Instance hardening

Read every security control on one page, with the line you drew for it beside
it. A change that would take a control below that line is refused, and both the
change and the refusal land on the audit trail.

A chief information security officer asks four questions before an instance
goes live. How long may a session last? How many times may somebody guess? Who
may read this from another website? How large a file may somebody push in?
Today each answer lives somewhere else: two in Nextcloud's configuration, one
in a PHP directive, one in a constant. The hardening report puts all four on
one page, so "are we compliant?" is a list of failing control ids and not an
afternoon.

## What is not here, on purpose

OpenRegister does not keep a password policy. Nextcloud's `password_policy` app
owns the minimum length, the common-password refusal and the breach-database
check, and a second copy would give you two answers to one question. The report
reads Nextcloud's numbers and judges them. You fix a failing one where it
actually lives.

The same goes for the session lifetime, the remembered login, the enforced
second factor and the platform throttler. The report names them, their source
reads `platform`, and the write path refuses to touch them.

## Read the report

```bash
curl -H 'OCS-APIRequest: true' -u admin:pass \
  https://your-instance/index.php/apps/openregister/api/hardening/report
```

Every row carries the value in force, who enforces it, the floor, and whether
the two agree:

```json
{
  "meetsAllFloors": false,
  "failing": ["password.minimumLength", "session.lifetimeSeconds"],
  "controls": [
    {
      "id": "session.lifetimeSeconds",
      "title": "A session lasts no longer than this",
      "category": "session",
      "source": "platform",
      "unit": "seconds",
      "value": 604800,
      "floor": 86400,
      "comparator": "atMost",
      "state": "on",
      "meetsFloor": false
    }
  ],
  "observed": {
    "bruteForce": { "throttlerEnabled": 1, "delayMilliseconds": 0, "attempts": 0 },
    "allowedOrigins": [],
    "reflectsAnyOrigin": true,
    "passwordPolicyRuns": false
  }
}
```

**A control that cannot be read fails its floor.** An instance running without
`password_policy` has no minimum length at all, so `value` is `null`, `state` is
`unknown`, and the control is listed as failing. A report that printed a shipped
default there would tell you it enforces ten characters on a system that accepts
one.

## The comparator, and why you must read it

Half the controls get stronger as the number goes up, and half get stronger as
it goes down. A longer lockout is stronger. A longer session is weaker. Every
row says which it is, in `comparator`:

| Comparator | Reads as | Example |
| --- | --- | --- |
| `atLeast` | the value must be this high or higher | the lockout, the password length |
| `atMost` | the value must be this low or lower | the session lifetime, the upload ceiling |

A consumer that assumes higher is stronger gets the session lifetime and the
upload ceiling exactly backwards.

## Declare a floor

The shipped baseline is the weakest any floor may be. Raise one:

```bash
curl -H 'OCS-APIRequest: true' -u admin:pass -X PUT \
  https://your-instance/index.php/apps/openregister/api/hardening/floors \
  -H 'Content-Type: application/json' \
  -d '{"floors": {"auth.rateLimit.lockoutSeconds": 1800}}'
```

Lowering a floor past the baseline is refused with a 409. Lowering the floor is
the cheapest way to make a failing control pass, and an instance that can lower
its own floor has no floor.

## Change a control, or be refused

Four numbers and one list are administered here. Everything else is read only.

| Control | Direction | Baseline |
| --- | --- | --- |
| `auth.rateLimit.attemptsPerIdentity` | `atMost` | 20 |
| `auth.rateLimit.attemptsPerAddress` | `atMost` | 100 |
| `auth.rateLimit.windowSeconds` | `atLeast` | 900 |
| `auth.rateLimit.lockoutSeconds` | `atLeast` | 900 |
| `origins.allowlistEntries` | `atLeast` | 0 |

```bash
curl -H 'OCS-APIRequest: true' -u admin:pass -X PUT \
  https://your-instance/index.php/apps/openregister/api/hardening/controls \
  -H 'Content-Type: application/json' \
  -d '{"controls": {"auth.rateLimit.lockoutSeconds": 3600}}'
```

A change that crosses the floor answers 409 and names the control, the floor and
what you asked for:

```json
{
  "error": "This instance declared a floor of at least 1800 for auth.rateLimit.lockoutSeconds, and 60 is below it. Raise the floor first, or leave the control alone.",
  "control": "auth.rateLimit.lockoutSeconds",
  "floor": 1800,
  "proposed": 60,
  "comparator": "atLeast"
}
```

The number you set is the number that locks somebody out, and the number
`/api/capabilities` publishes. There is one resolution and three readers.

## Bind the public surface to named websites

Until you set an allowlist, a browser on any website may read a public answer
from this instance, which is how it behaved before this control existed. Name
the websites and the rest get nothing:

```bash
curl -H 'OCS-APIRequest: true' -u admin:pass -X PUT \
  https://your-instance/index.php/apps/openregister/api/hardening/controls \
  -H 'Content-Type: application/json' \
  -d '{"allowedOrigins": ["https://www.gemeente.nl", "https://portaal.gemeente.nl"]}'
```

A request from anywhere else gets no allow-origin header, the browser refuses
the read, and the response names neither the allowlist nor anything on it.

Declare a floor of 1 on `origins.allowlistEntries` and emptying the list is
refused from then on.

## What a consuming app reads

`GET /api/hardening/floors` answers `floors`, `baselines`, `comparators` and
`declared`. That is the contract for an app that draws its own link to this
page, such as dossiq's admin settings: read `floors` for the line, `comparators`
for the direction, and `GET /api/hardening/report` for `meetsAllFloors` and
`failing` to decide whether to show a warning.

## Where the rows land

Every change and every refusal is a hash-chained audit entry: `hardening.changed`
or `hardening.refused`, carrying the control, the value before, the value asked
for and who asked. The refused ones are the interesting rows. Somebody tried to
take the lockout down to sixty seconds on a Friday afternoon, and a
success-only trail could never tell you.

## When a dependency stops answering

Open Register keeps a circuit breaker per dependency. While a dependency is
failing, it is not called: the caller gets an immediate refusal instead of a
request that hangs until its timeout, and the failing system is not buried
under retries.

| Breaker | Key | Guards |
|---|---|---|
| Outside service | `outbound:{host}`, or the connection a call names (for example `outbound:brp`) | Every outbound HTTP call |
| Outside database | `source:{id}` | Reads from a schema backed by an outside database |
| Language model | `llm` | Chat answers and embeddings |
| Webhook receiver | `webhook:{host}` | Webhook deliveries |

A breaker opens when, over the last 60 seconds, at least 10 calls were made, at
least 5 failed, and at least half failed. While open it refuses every call for
its cool-down: 30 seconds at first, doubling each time it opens again, up to 300
seconds. After the cool-down one call goes through as a probe. If it succeeds
the breaker closes; if it fails the breaker opens again.

What counts as a failure:

- outbound HTTP and webhooks: a connection error, a timeout, a 5xx, or a 429. A
  429's `Retry-After` becomes the cool-down when it is longer. Any other 4xx is
  the caller's problem and counts as a success;
- an outside database: a connection error or a query error;
- the language model: any error from the chat or embedding call.

A webhook to a receiver whose breaker is open is not sent. The delivery is put
on the retry job for after the cool-down, and its attempt count does not go up.
A webhook that runs before a write is skipped at once, and the write continues
as it does when that webhook fails.

With a distributed memcache (Redis) configured, every PHP worker shares the
breakers. Without one, each worker learns on its own. That still sheds most of
the load, and the operations console says which of the two you have.

## Heavy work waits while the database struggles

Open Register also watches its own database. It counts its API requests, the
ones that took longer than 2 seconds and the ones that ended in a database
error. The database is under pressure when, over the last 60 seconds, there were
at least 50 requests and either a fifth were slow or a tenth hit a database
error.

Under pressure, only the heavy routes are refused:

- `GET /api/objects/{register}/{schema}/export`;
- the four aggregation routes under `/api/objects/aggregations/`;
- `POST /api/graphql`;
- the bulk routes under `/api/bulk/` and `POST /api/bulk-jobs`;
- report runs: `POST /api/reports/{id}/render` and `GET /api/reports/{id}/preview`.

Reading and saving single records always goes through, so a caseworker keeps
working while an export waits. The routes are marked `#[Sheddable]` in the code,
and a unit test lists them, so the set changes only with a reviewed change.

## The 503 a caller gets

```http
HTTP/1.1 503 Service Unavailable
Retry-After: 27
Content-Type: application/problem+json

{"type": "about:blank", "title": "Dependency unavailable", "status": 503,
 "detail": "The outside database this schema reads from is not answering. Try again in 27 seconds.",
 "dependency": "source"}
```

`dependency` is one of `database`, `llm`, `source`, `outbound` or `webhook`. The
body never names a host, a port, a database or a credential, because the answer
can reach an anonymous visitor on a public route. A refusal for database
pressure always carries `Retry-After: 30`. A record on an outside database whose
breaker is open answers 503, never 404: it is not missing, it just cannot be
read right now.

## See and reset the breakers

As an administrator:

```bash
curl -u admin:pass https://cloud.example.org/apps/openregister/api/operations/dependencies
```

The answer lists every breaker that has state, with its class, key, state
(`closed`, `open` or `half-open`), calls and failures in the window, when it
opened, when it next probes, and whether the state is shared. Beside it is the
database pressure: the counts, whether shedding is on, and whether the database
is under pressure now.

When you have fixed the outage, close a breaker without waiting for the probe:

```bash
curl -X POST -u admin:pass -H 'OCS-APIRequest: true' \
  https://cloud.example.org/apps/openregister/api/operations/dependencies/llm/reset
```

The reset lands on the audit trail as `dependency.reset`, naming you. Both
endpoints are administrator-only; anybody else gets 403. A breaker on a declared
connection (`llm`, or a call site's named connection such as `brp`) reports
`unavailable` to the connection registry when it opens and `configured` when it
closes.

## Tune the thresholds

The thresholds are app settings in the `openregister` app, read once per
request. A value that cannot be read or is out of range falls back to its
default and is logged.

| Setting | Default |
|---|---|
| `load_shedding_breaker_min_calls` | 10 |
| `load_shedding_breaker_min_failures` | 5 |
| `load_shedding_breaker_failure_percent` | 50 |
| `load_shedding_breaker_cool_down` | 30 |
| `load_shedding_breaker_max_cool_down` | 300 |
| `load_shedding_pressure_enabled` | true |
| `load_shedding_pressure_min_requests` | 50 |
| `load_shedding_pressure_slow_ms` | 2000 |
| `load_shedding_pressure_slow_percent` | 20 |
| `load_shedding_pressure_error_percent` | 10 |

```bash
occ config:app:set openregister load_shedding_pressure_enabled --value=false --type=boolean
```

That switch turns database-pressure shedding off for an instance that prefers
slow answers to refusals. The dependency breakers cannot be switched off, only
tuned.

Next: [Access links](access-links.md) for sharing one record outside the
instance, and [API generation](api-generation.md) for the capabilities answer
that publishes the same ceilings.
