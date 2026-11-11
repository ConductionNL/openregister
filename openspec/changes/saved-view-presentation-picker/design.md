# Design: saved-view-presentation-picker

No design board draws OpenRegister's Tables page; OpenRegister is not one of the canvas apps. The layout follows the existing save form and edit modal.

## D-1: the picker is a nextcloud-vue component

`saved-search-views` REQ-VIEW-PRES-05 already puts the renderers in nextcloud-vue. The editor belongs with them, because leaf apps save views through `CnSaveViewDialog` and must offer the same three types with the same validation. nextcloud-vue ships `CnViewPresentationPicker`: props `schema` (the JSON schema of the view's schema) and `value` (the presentation object), emits `input` with the presentation object. OpenRegister places it in `EditView.vue` and in the save form of `SearchSideBar.vue`, and passes the schema it already has loaded.

If the nextcloud-vue component is late, OpenRegister does not build a local copy. The tasks wait.

## D-2: which properties a picker offers

| Role | Offered properties |
|---|---|
| `groupByField` | a string property with an `enum`, a property that a schema lifecycle (`x-openregister-lifecycle`) names as its state, or a relation to one object |
| `cardFields` | any scalar property, at most four |
| `columnOrder` | the values of the chosen `groupByField` enum, in a drag list |
| `dateField`, `endDateField` | a property with `format` `date` or `date-time` |

A type whose role has no candidate on the schema is shown disabled with the reason ("This schema has no date field"). The backend validation stays the authority; the filter only keeps a user from picking what will be refused.

## D-3: switching the open view

The Tables page header shows a segmented control with the presentations the view's schema supports. For the view's owner, a switch saves the view (`PUT /api/views/{id}` with the new `presentation`) and redraws. For a user who may read but not edit the view, the switch redraws for this session only and offers "Save as new view". The control is hidden when no view is open.

## D-4: errors

A 400 from the view save names the field. The form puts the message under that picker and keeps the user's input. No toast-only error.
