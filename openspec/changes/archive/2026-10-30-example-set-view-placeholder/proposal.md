# Proposal: example-set-view-placeholder

## Why

Board DqFilterInMenu (dossiq) shows Pieter Jansen's saved view "Termijn deze week" in his menu on the left. A view is
put in the menu through a user preference (`pref_cn_saved_view_menu`, a list of `{ id, page, name }`), and that id is
the uuid OpenRegister gives the view when the example set writes it. A fragment can declare the view and the
preference, but the preference cannot name the view: no placeholder stands for a view, and preferences are written
before views.

## What changes

- `{{view:<key>.uuid}}` stands for the uuid of a saved view the same fragment declares.
- A fragment's user preferences are written after its views, so the placeholder resolves. The check accepts it with a
  stand-in before anything is written; a key the fragment does not declare refuses the set.

## Scope

ExampleSetValues and ExampleSetInstaller. No schema or API change.
