# Sidebars load with their route

## What was wrong

`src/sidebars/SideBars.vue` shows one of eight route sidebars (dashboard,
search, register, registers, deleted, entities, audit trail, search trail),
picked by the route path. All eight were imported statically, so every
OpenRegister page downloaded and evaluated all of them before it rendered,
and their styles (inserted when a component module runs) were added to every
page, including pages that show none of them.

## What changes

- Each route sidebar loads as its own chunk the first time its route shows it.
  The choice of sidebar per route does not change.
