---
kind: code
---

## Why

OpenCatalogi's change `publication-tells-its-source` tells a source system when
its publication is complete through an OpenRegister webhook, not a message of
its own. The subscriber must hear about the one update that moves the
publication from `incomplete` to `complete`, and not about later edits.

A webhook filter compares one payload. The payload of `ObjectUpdatedEvent`
carries only the object after the update, so a filter can match a state
(`object.completeness` is `complete`) but never a change. Every edit after
completion matches again.

## What changes

The payload of `ObjectUpdatedEvent` gains two keys:

- `newObject`: the object after the update, the same value as `object`.
- `oldObject`: the object before the update, or null when the event has none.

`object` and every other key stay as they were, so existing subscriptions and
mappings see the same values. A subscription can now filter on a change:

```json
{"oldObject.completeness": "incomplete", "newObject.completeness": "complete"}
```

## What is not done

The pre-save `ObjectUpdatingEvent` payload already carries both states and is
left alone. No new filter operators are added: equality on both states is
enough to match a change.
