# Store plane: install operations other than writing an object

## Why

`GenericStoreInstaller` can do exactly one thing: write a component's object
into an allowed schema. That is the whole of what dossiq and pipelinq install,
and it is why they migrate cleanly.

It is not the whole of what the other three do. integriq's catalogue has two
kinds of item and only one of them is an object: a **flag-gated** item installs
by flipping an `IAppConfig` boolean, and a **source-backed** one writes a
`source` object. An engine that can only write objects can express half of
integriq's store.

## What changes

A component MAY declare an `op`. Absent, it is `writeObject`, which is exactly
today's behaviour, so every existing item installs unchanged.

```jsonc
{
  "op": "setAppConfig",          // "writeObject" (default) | "setAppConfig"
  "key": "enableSoapAdapter",
  "value": true
}
```

`setAppConfig` writes a boolean into the **declaring app's own** config
namespace. It cannot address another app's config, and the key must be
allowlisted by the store block's new `configurable` list — the same shape, and
the same reasoning, as `installable` for schemas.

## 🔴 `configurable` is a second security boundary, not a convenience

`installable` stops a remote registry naming any schema the app owns.
`setAppConfig` needs the identical defence for config keys, and it needs it
more: an app's config namespace holds registry URLs, tokens and feature flags,
so an unallowlisted write is a remote actor toggling whatever it names.

An empty `configurable` list therefore refuses every key, exactly as an empty
`installable` refuses every schema.

## What does NOT change

- Objects still install through the existing path, still identity-stripped,
  still allowlisted.
- A refusal still does not abort the install, and is still reported per
  component.
- Nothing here touches WHO may install.

## Out of scope, and honestly so

buildiq's install clones an Application, creates a per-app register, namespaces
companion schemas and rewrites a manifest. hermiq's imports a package through a
content scanner and lands it quarantined, then instantiates an Agent with
model-policy coercion. Neither is expressible as a declarative op without
inventing a language to describe them, and a vocabulary that covers them badly
would be worse than one that admits it does not. They are named here so the gap
is visible rather than discovered later.
