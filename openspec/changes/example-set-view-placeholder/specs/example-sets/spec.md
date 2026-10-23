# example-sets Delta: example-set-view-placeholder

## ADDED Requirements

### Requirement: A preference may quote a view of its fragment

A fragment's user preference value MAY contain `{{view:<key>.uuid}}`, where `<key>` is a saved view the same fragment
declares. The installer SHALL write the fragment's views before its user preferences and fill the placeholder with the
written view's uuid. The check SHALL accept the placeholder for a declared view before it is written, and SHALL refuse
the set when the key names no view of the fragment.

#### Scenario: A view in the menu

- **GIVEN** a fragment declares the view `pieter-termijn-deze-week` and Pieter's preference `pref_cn_saved_view_menu`
  with `[{"id":"{{view:pieter-termijn-deze-week.uuid}}","page":"Cases","name":"Termijn deze week"}]`
- **WHEN** the set is installed
- **THEN** the preference holds the view's uuid, and the view shows in Pieter's menu

#### Scenario: An unknown view

- **GIVEN** a preference quotes `{{view:nope.uuid}}` and the fragment declares no view `nope`
- **THEN** the check refuses the set with "cannot resolve"
