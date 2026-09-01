# Design: Resilient Per-Entity Config Import + No-User-Context Fallback

## Context
`ImportHandler::importFromApp()` wraps the whole `importFromJson()` body in a single try/catch whose `catch (Exception $e)` re-throws (`Failed to import configuration for app {appId}`). Any uncaught throw from an inner import phase therefore aborts the entire app's data-layer import. Per-schema import is already resilient; registers, the main object loop, and the seed-data invocation are not.

## Abort points found (line numbers as of the fixed branch base)
1. **Register loop** `importFromJson()` ~1649-1703 — `importRegister()` (which re-throws at ~734) is called with no surrounding try/catch. One bad register aborts the rest of the import.
2. **Main object loop** `importFromJson()` ~1810-1995 — `searchObjects()`/`saveObject()` per object, no try/catch. One name-missing/title-less seed fragment aborts the loop.
3. **Seed-data** `importFromJson()` ~2047 `importSeedData()` call is unwrapped; inside, the per-`$schemaSlug` preamble (~3610-3683, target register/schema resolution) runs before the existing inner per-object try/catch (~3834-3956).
4. **No-user-context** — `saveObject()` resolves the acting user from `IUserSession`; null under `occ` → `FolderAccessDeniedException` (`Access to folder NNNN is denied for the acting user`).

## Decisions

### D1 — Skip-and-continue, mirror the existing idiom
Each new wrapper mirrors the existing per-schema/per-mapping pattern: `catch (\Throwable $e)` (broaden from `Exception` so opis/json-schema `\Error`-shaped throws and `FolderAccessDeniedException` are also caught), `logger->warning` with the entity slug + `$e->getMessage()`, increment a counter, `continue`. `\Throwable` is used (not `Exception`) because the observed opis validation failure and folder-access denial are not all `\Exception` subclasses, and the goal is "one bad entity never aborts the app".

### D2 — Skip counters in `$result['skipped']`
Initialise `$result['skipped'] = ['registers'=>0,'objects'=>0,'mappings'=>0,'seedObjects'=>0]` alongside the existing result scaffold. Increment in each catch. This is additive to the result shape; existing keys are untouched, so callers that ignore `skipped` are unaffected.

### D3 — Fallback acting user via setter-injected resolvers
Add `setGroupManager(?IGroupManager)` and `setUserManager(?IUserManager)` setters (mirroring the existing optional `setFileService`/`setUserSession` idiom) and a private `resolveActingUser(): ?IUser` helper:
- If `userSession?->getUser()` is non-null → return it (real session wins, behaviour unchanged).
- Else try `groupManager->get('admin')->getUsers()` → first user.
- Else try `userManager->search('', 1)` → first enabled user.
- Else return null (caller logs + skips just that op).
The resolved user is passed as `currentUser:` to the main-loop `saveObject()` calls. Wiring is added in `Application.php`'s `$importHandlerFactory`, wrapped in try/catch like the other optional setters so a missing dependency never breaks import.

### D4 — Do not weaken validation
No validation is relaxed. A fully-valid configuration takes exactly the same code path as before; only the failure branches change from abort to skip-and-continue. The fallback user only activates when the session user is null.

## Risks
- Broadening `catch (Exception)` to `catch (\Throwable)` could mask programmer errors. Mitigated: every catch logs a descriptive WARNING with slug + message + (where useful) trace, so failures remain diagnosable; and the catches are scoped to single-entity bodies, never whole phases.
- `gate-27 no-phantom-cross-app-rpc`: the fallback-user resolution uses core `OCP\IGroupManager`/`OCP\IUserManager`, not a cross-app service, so no phantom cross-app RPC is introduced.
