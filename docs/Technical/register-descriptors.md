# Register descriptors

An app declares its registers in a descriptor — an OpenAPI document under
`lib/Settings/` carrying `components.registers`. OpenRegister imports it through
`ConfigurationService::importFromApp()`, and per
[ADR-005](../../openspec/architecture/adr-005-register-import-via-repair-steps.md)
the import is delivered by an idempotent Repair step.

That decision settled **who** seeds a register. It did not give anyone a way to
re-run the seeding, or to see whether it worked. This page is about the gap and
the panel that closes it.

## Why a register can be missing on a healthy-looking instance

Two properties combine badly.

**Repair steps only run on install and `occ upgrade`.** As soon as an app's
`installed_version` matches its `info.xml`, `occ upgrade` reports *"No upgrade
required"* and skips the repair steps entirely. There is no supported way to run
one again short of `occ maintenance:repair`, which fires **every** app's steps,
or an app disable/enable cycle.

**A failed import is only logged.** From `ImportFlowRegister`'s own docblock:

> Never throws — a failure logs a warning and leaves the instance otherwise
> healthy.

That is a defensible trade at boot, where the alternative is an app that will
not install. Its cost is an instance that looks fine and is missing a register,
with the only evidence in a log nobody is reading.

The consequence is not hypothetical. On a dev instance an `occ upgrade` that
reported complete success left **8 of 15 declared registers absent**, including
`flows`. Two e2e suites died in `beforeAll` on the missing register, and
establishing why took an account listing, a register dump, and a read of the
Repair step's source.

## The panel

**Admin settings → Register descriptors** lists every register any installed app
declares, in one of three states:

| state | meaning | what it costs you |
|---|---|---|
| `current` | present at the version the app ships | nothing |
| `behind` | present, but older than the shipped descriptor | code runs against an older contract |
| `absent` | the app declares it and it does not exist | the code paths that need it are dead |

`absent` and `behind` are deliberately **not** collapsed into one "needs
attention": they call for different actions and carry different risk.

Each row offers an import. The outcome is shown to the administrator who
triggered it — a button that reports nothing is indistinguishable from one that
did nothing, which is the failure being repaired.

## The import is always forced

The re-import passes `force: true`, and that is not a convenience.
`ImportHandler` short-circuits on:

```php
$force === false && version_compare($data['version'], $existingVersion, '<=')
```

The situation an administrator presses this button in is exactly one where the
version counter is not to be trusted — the register is absent, or the write
failed, while the counter says current. An unforced re-import would report
success and do nothing in every case that motivates the action.

## Is a forced re-import safe for customised schemas?

**Yes.** A schema that extends one shipped by a descriptor refers to its base
rather than copying it — `Schema::getAllOf()` returns *"Array of schema IDs,
UUIDs, or slugs"*. Re-importing the base updates the base row; the extension
keeps its own properties and resolves against the new base. It is impervious to
the base moving.

That is a property of the implementation rather than a law, so it is pinned by a
test (`register-descriptors.spec.ts`) rather than assumed: an extension
materialised as a copy at import time would silently revert somebody's
customisation, and it would do so through the button offered as a repair.

## Without a browser

The condition this diagnoses is most likely on an instance mid-setup or
mid-repair, where the admin UI may itself depend on the broken thing:

```bash
occ openregister:descriptors:list                     # the full inventory
occ openregister:descriptors:list --problems-only     # absent and behind only
occ openregister:descriptors:list --app=openregister --import=flows
```

The command formats the same service method the panel reads, so the two surfaces
cannot drift into disagreeing about one instance.

## For app authors

If your app ships a register and it never appears:

1. Run `occ openregister:descriptors:list` and find your app's row. If it is not
   listed at all, your descriptor is not being recognised — it must be a JSON
   document under `lib/Settings/` with a non-empty `components.registers`.
   Recognition is by **shape**, not filename.
2. If it is listed as `absent`, the descriptor is valid and the import never
   landed. Import it from the panel or the command, then check your Repair step:
   per ADR-005 Rule 1, shipping the JSON alone does nothing at runtime.
