# Design: records-bulk-transition

Read at openregister development 555af7212.

## Context

- The bulk job framework (`bulk-action-jobs`, routes `/api/bulk-actions` and
  `/api/bulk-jobs` at `appinfo/routes.php:1293-1298`) runs a registered
  `BulkActionInterface` per object with `apply(ObjectEntity, parameters,
  commit, ?IUser actor)` (`lib/BulkAction/BulkActionInterface.php:55-113`),
  returning `applied`, `skipped` or `failed`.
- `BulkActionRegistrationListener` registers five built-ins today:
  `SetPropertiesAction`, `AssignAction`, `ApplyRuleAction`,
  `ExportWholeSetAction` and `RestorePriorValuesAction`.
- A single move is `TransitionController::transition()`
  (`lib/Controller/TransitionController.php:79`) calling
  `TransitionEngine::transition($objectId, $action, $data)`
  (`lib/Service/Lifecycle/TransitionEngine.php:295`). The engine reads the
  actor from `IUserSession` (`:338`, `:443`), refuses without `update` with
  `NotAuthorizedException`, validates `inputs` with
  `InvalidTransitionInputException`, and lets listeners stop the save with
  `HookStoppedException`.

## D-1: the engine takes an explicit actor

A bulk job applies objects in a background worker, where the session may hold
no user or a different one. `transition()` gains an optional `?IUser $actor`;
when given, it is used for the permission check and the attribution instead of
the session user. The single-move controller passes nothing and keeps its
behaviour.

## D-2: one engine call per object, outcomes mapped

`TransitionAction::apply()`:

- `commit: false` asks the engine for the available actions of the object for
  the actor and answers `applied` when `action` is among them, else `failed`
  with "not available in state <state>".
- `commit: true` calls `transition(objectId, action, data, actor)`. An object
  already in the target state is `skipped`. `NotAuthorizedException`,
  `InvalidTransitionInputException`, `HookStoppedException` and a refused move
  map to `failed` with the exception's user-facing message, the same text the
  single move answers.

`validateParameters()` refuses an empty `action` and a `data` that is not an
object.

## D-3: guards

`getGuards()` returns the homogeneity guard, like `SetPropertiesAction`: all
selected objects must share one schema, because an action name means one
lifecycle.

## D-4: not reversible

The action does not implement `ReversibleBulkActionInterface`. The job page
says a transition job cannot be undone.
