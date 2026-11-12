# Proposal: example-set-typed-app-config

## Why

pipelinq numbers its tickets from a counter in its app config (`ticket_reference_counter`, read with `getValueInt`). The Zuiddrecht set names three tickets PQ-2026-0310 / -0377 / -0402, so the counter has to start above 402, or a later ticket could get the same number. The example-set `appConfig` part writes text only, and Nextcloud's typed app config refuses to read a text key as an integer, so the set could not seed the counter.

## What changes

An app config entry MAY name `type: "int"`: it is written with `setValueInt`, compared and restored as an integer, and a value that is no integer (or a placeholder) refuses the set. Text stays the default.
