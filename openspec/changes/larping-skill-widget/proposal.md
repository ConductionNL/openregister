## Why

This change creates a redirect stub to preserve the `larping-skill-widget` specification slug in the local codebase. The canonical specification for the larping skill widget is maintained by LarpingApp at `larpingapp/openspec/specs/larping-skill-widget/spec.md`.

The stub ensures that:
1. Local documentation references can point to a consistent slug
2. The specification remains discoverable in this project's spec registry
3. Implementers are directed to the authoritative source (LarpingApp) rather than deriving requirements from outdated copies

## Context

LarpingApp owns and maintains the specification for the larping skill widget. This is a cross-project ownership pattern: the feature originates in LarpingApp's domain, and the spec lives in their OpenSpec repository. OpenRegister maintains a local redirect to avoid spec duplication and ensure single source of truth.

## Capabilities

**None — this change does not add new capabilities to OpenRegister.** The larping skill widget is implemented and specified in LarpingApp. This change only provides a local spec placeholder.

## Impact

- **No code changes** — this change is specification-only
- **No OpenRegister changes** — LarpingApp continues to own and maintain the feature
- **Documentation consistency** — local references use the consistent slug path
- **Cross-project discovery** — developers can find the authoritative spec location
