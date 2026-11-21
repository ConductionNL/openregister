---
kind: docs
---

# Proposal: capability-rows-verified-b

## Summary

The 12 November verify round read every row of Open Register's capability matrix against the
scenarios of its recorded requirement. For rows 75 to 149, three rows record a requirement that
says only part of what the row claims, and no other requirement says the rest. This change
writes the missing requirements, so each row records a heading whose scenarios say the row.

## Rows this closes

| row | capability | what was missing |
|---|---|---|
| auto-flow-editor | Build an automation that runs steps when a record changes, in a visual editor | flow-engine says a trigger is a node, but no scenario builds a flow on the canvas |
| x-package-git | Import and publish those packages from a GitHub or GitLab repository | data-import-export publishes to GitHub only; publishing to GitLab has no scenario and no code |
| ai-provider | Choose which LLM provider is used, including one that runs locally | chat-ai reads and writes the settings, but no scenario picks a provider or one that runs locally |

## What changes

- ADDED requirement in `flow-engine`: an administrator builds a flow on the canvas, and it runs when a record changes.
- ADDED requirement in `data-import-export`: a configuration package is published to a GitLab repository.
- ADDED requirement in `chat-ai`: an administrator picks the language model provider, including Ollama on the organisation's own server.

## Impact

Specs only. The flow canvas and the provider choice are built; their tasks pin the scenarios in a
test. Publishing to GitLab is not built, so `x-package-git` moves from built to building until
its task lands.
