# Store plane: a declarative discovery source

## Why

ADR-114 Decision 4 put a Store in every app's footer and ADR-080 Decision 4
governs what may carry the word: registry-backed, discovery in OpenRegister,
and an install that writes only what the app allowed. The generic plane already
does that for a store whose registry is **another OpenRegister**.

Five apps ship a bespoke store today, and the fleet decision is that they
migrate onto the generic plane **without regression**. Two of them cannot,
because they do not discover from an OpenRegister at all:

- **buildiq** searches the GitHub REST API for `topic:openbuild-app`.
- **hermiq** searches it for `topic:hermiq-agent-template` and
  `topic:hermiq-skill`, one call per kind, merged client-side.

Both cache aggressively, both degrade to a 200 with an empty card list rather
than a 5xx, and both distinguish *rate limited* from *unreachable* because the
remedy differs: one is "wait or add a credential", the other is "the network is
broken".

`GenericStoreService` can only ever GET
`{registry}/index.php/apps/openregister/api/objects/{register}/{schema}`. An app
whose registry is GitHub is reported as `not_configured`, which is not what is
wrong with it.

## What changes

The `store` block gains a **`source`** discriminator. Absent, it is
`openregister` and nothing about today's behaviour moves — dossiq and pipelinq
migrate on the existing shape.

```jsonc
"store": {
  "source": "github",          // "openregister" (default) | "github"
  "topics": ["openbuild-app"], // github only: what to search for
  "kinds": [ … ],              // unchanged
  "installable": [ … ]         // unchanged, still the security boundary
}
```

Discovery moves behind a `StoreSourceInterface` with two implementations. The
outcome vocabulary gains `rate_limited`, which the page already needs in order
to say the useful thing rather than the generic one.

## What does NOT change

- `installable` stays the security boundary, and an empty list still refuses
  everything.
- The SSRF guard, the redirect refusal and the Bearer-header rule stay exactly
  as they are for the `openregister` source. A `github` source has a
  **compile-time host** and therefore no URL for an app to influence at all,
  which is a stronger position, not a weaker one.
- An app still writes no controller.

## Out of scope, deliberately

Install shapes beyond writing objects (buildiq clones an app, hermiq
instantiates an agent), `installAuth`, and the approve/export/publish actions.
Those are the next increments; this one is discovery only, so it can be proven
against the two apps that already have a GitHub store to compare against.
