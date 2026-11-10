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
curl -u admin:pass \
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
curl -u admin:pass -X PUT \
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
curl -u admin:pass -X PUT \
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
curl -u admin:pass -X PUT \
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

Next: [Access links](access-links.md) for sharing one record outside the
instance, and [API generation](api-generation.md) for the capabilities answer
that publishes the same ceilings.
