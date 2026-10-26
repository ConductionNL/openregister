# Proposal: flow-update-changed-fields

## Why

A flow wired to `object.updated` starts on every save of the object. An app that wants to act when one field changes ("a resident gave a new address", "a complaint was closed") cannot tell, because the run carries no trace of what changed. pipelinq's journeys need exactly that.

## What changes

An update's run context carries `changed`: the sorted names of the top-level fields whose value differs between the object before and after the update. Fields starting with `@` are left out. A flow condition reads it as `{"in": ["address", {"var": "context.changed"}]}`. An update fired without the old object carries no `changed`, as before.
