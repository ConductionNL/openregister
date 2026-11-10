# Proposal: example-set-task-placeholder

## Why

Dossiq's end-of-day board shows a note that Pieter left on a task: "Hoorzitting inplannen", "Zaal en commissie vastgelegd voor 21 oktober." A fragment can declare the task and can declare notes, but a note can only point at an object. A task is a row in the task engine, and its uuid exists only after the install has written it.

## What changes

- A new placeholder, `{{task:<key>.uuid}}`, gives the uuid of a task the same fragment declares. It works in the parts written after the tasks: watchers, parties, notes, read states and flows.
- During the check it resolves to a stand-in, so a declared task passes. A key that names no task of the fragment is refused with "cannot resolve".
