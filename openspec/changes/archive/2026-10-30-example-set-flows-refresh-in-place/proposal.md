# A refresh updates a held flow to its declaration

## Why
An example set wrote a flow once and never again: a held flow was skipped, also by `--refresh`, because flows were not among the refreshed parts and the installer told the flows step about a refresh only after it had run. A changed declaration (pipelinq's acknowledgement flow drawn as its PqFlow board: the steps' places, names and branches) never reached an instance that already held the flow. Writing the flow again would have given it a new uuid and broken every page and spec that opens it.

## What changes
- `flows` joins the parts a refresh updates (`ExampleSetEngineRows::REFRESHED`).
- `ExampleSetFlows` updates a held flow where it is (`updatesInPlace()`, `update()`): the declared fields are set, and the flow is published again only when one of them changed. It stays switched on or off as it is and keeps its runs. A held flow that is gone is written again.
- The installer passes the refresh to the flows step, which runs before the changes.
