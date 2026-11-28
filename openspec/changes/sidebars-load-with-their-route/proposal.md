# Sidebars load with their route

## What was wrong

`src/sidebars/SideBars.vue` shows one of eight route sidebars (dashboard,
search, register, registers, deleted, entities, audit trail, search trail),
picked by the route path. All eight were imported statically, so every
OpenRegister page downloaded and evaluated all of them before it rendered,
and their styles (inserted when a component module runs) were added to every
page, including pages that show none of them.

## What changes

- The search, deleted, entities, audit trail and search trail sidebars load as
  their own chunk the first time their route shows them.
- The dashboard, registers and register sidebars stay in the main chunk: they
  show with the first render of the most used pages, and as a chunk they
  appeared, and sent their reads, a task later (the Registers page measured
  ~90 ms slower that way).
- The choice of sidebar per route does not change.
