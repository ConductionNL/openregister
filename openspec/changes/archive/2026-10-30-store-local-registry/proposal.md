# Proposal: store-local-registry

## Why

The store plane reads a registry over HTTP, and the SSRF guard refuses every non-public address. An instance that keeps its own catalogue therefore cannot point its store at itself. That includes a demo or development instance reached as `localhost`. Pipelinq's store board shows installed items and updates, and on such an instance it could only ever show the "no registry" fallback.

## What changes

- A store whose `registry_url` is `local` is read from this instance's own register, in-process, through the object service (`LocalStoreRegistry`). No HTTP request is made, so the guard has nothing to guard. The caller's RBAC applies. Organisation scoping does not, because a catalogue is shared by the whole instance.
- Search and resolve behave as they do for a remote registry. A missing register or schema answers `store_unreachable`, never an empty store. Resolve matches the slug itself.
- Publish to a `local` registry is not supported. Its URL fails the guard, as it does today.
